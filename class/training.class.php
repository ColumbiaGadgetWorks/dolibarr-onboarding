<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');

/**
 * Paid trainings from the Givebutter training campaign.
 *
 * Each training fee is split: the trainer's share goes to the trainer's credit,
 * the rest to the zone's budget. Both are rows in llx_onboarding_ledger, so a
 * balance is always the sum of what made it. When a trainer's credit reaches
 * the threshold, a dues credit waits for someone to approve it; approving takes
 * the threshold off their credit, and staff refund that much of their latest
 * dues payment in Givebutter (the API cannot refund).
 *
 * The person who paid is marked trained on the tool: a row here (which the
 * website's lookup reads by email) and a "Trained: <tool>" tag on their member
 * and contact cards.
 */
class OnboardingTraining
{
	/** @var DoliDB */
	public $db;

	/** @var OnboardingService */
	public $svc;

	/**
	 * @param DoliDB $db Database handler
	 * @param OnboardingService|null $svc Shared service
	 */
	public function __construct($db, $svc = null)
	{
		$this->db = $db;
		$this->svc = $svc ? $svc : new OnboardingService($db);
	}

	/**
	 * @return bool A training campaign is configured
	 */
	public static function enabled()
	{
		return getDolGlobalString('ONBOARDING_TRAINING_CAMPAIGN_CODE') !== '';
	}

	/**
	 * @param string $campaign Campaign code on a transaction
	 * @return bool It is the training campaign
	 */
	public static function isTrainingCampaign($campaign)
	{
		$want = getDolGlobalString('ONBOARDING_TRAINING_CAMPAIGN_CODE');
		return $want !== '' && strcasecmp(trim((string) $campaign), $want) == 0;
	}

	/**
	 * @return float Credit at which a month of dues is refunded
	 */
	public static function threshold()
	{
		$t = (float) getDolGlobalString('ONBOARDING_TRAINING_CREDIT_THRESHOLD', '50');
		return $t > 0 ? $t : 50;
	}

	/**
	 * @return float Trainer's share of a fee, 0 to 1
	 */
	public static function trainerShare()
	{
		$pct = (float) getDolGlobalString('ONBOARDING_TRAINING_TRAINER_SHARE', '50');
		return max(0, min(100, $pct)) / 100;
	}

	/**
	 * Lowercase, punctuation to spaces, single spaces. "Jim  O'Neil" -> "jim o neil".
	 *
	 * @param string $s Text
	 * @return string
	 */
	public static function norm($s)
	{
		$s = strtolower(trim((string) $s));
		$s = preg_replace('/[^a-z0-9@.#_-]+/', ' ', $s);
		return trim(preg_replace('/\s+/', ' ', $s));
	}

	/**
	 * "Machining (Mill, Lathe)" -> "Machining".
	 *
	 * @param string $answer Answer text
	 * @return string
	 */
	public static function zoneName($answer)
	{
		$zone = trim(preg_replace('/\s*\(.*$/', '', (string) $answer));
		return $zone !== '' ? dol_trunc($zone, 100, 'right', 'UTF-8', 1) : 'Unassigned';
	}

	/**
	 * The answers to the checkout questions, found by their titles: the one
	 * mentioning "zone", the one mentioning "trainer", and the one mentioning
	 * "tool", "equipment" or "machine".
	 *
	 * @param array $t Transaction
	 * @return array{zone:string,trainer:string,tool:string}
	 */
	public static function answers($t)
	{
		$out = array('zone' => '', 'trainer' => '', 'tool' => '');
		$fields = isset($t['custom_fields']) && is_array($t['custom_fields']) ? $t['custom_fields'] : array();
		foreach ($fields as $f) {
			if (!is_array($f)) {
				continue;
			}
			$title = strtolower((string) (isset($f['title']) ? $f['title'] : (isset($f['name']) ? $f['name'] : '')));
			$value = isset($f['value']) ? $f['value'] : (isset($f['answer']) ? $f['answer'] : '');
			if (is_array($value)) {
				$value = implode(', ', $value);
			}
			$value = trim((string) $value);
			if ($value === '') {
				continue;
			}
			if (strpos($title, 'zone') !== false) {
				$key = 'zone';
			} elseif (strpos($title, 'trainer') !== false) {
				$key = 'trainer';
			} elseif (preg_match('/tool|equipment|machine/', $title)) {
				$key = 'tool';
			} else {
				continue;
			}
			if ($out[$key] === '') {
				$out[$key] = $value;
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------- recording

	/**
	 * Record a training-campaign transaction. Safe to call again for the same one.
	 *
	 * The tool, zone and trainer come from the registration made on the website
	 * just before paying, matched by email. Without one they come from the
	 * Givebutter checkout questions if the campaign still has them; failing that
	 * the payment waits on the Trainings page for someone to link it.
	 *
	 * @param array $t TransactionResource, already known to be succeeded
	 * @return string What was done, for the log
	 */
	public function process($t)
	{
		global $conf;

		$txid = isset($t['id']) ? (string) $t['id'] : '';
		$table = $this->db->prefix().'onboarding_training';
		$res = $this->db->query("SELECT rowid FROM ".$table." WHERE gb_transaction_id = '".$this->db->escape($txid)."' AND entity = ".((int) $conf->entity));
		if ($res && $this->db->fetch_object($res)) {
			return 'duplicate';
		}

		// The gift itself, not a fee the payer chose to cover.
		$amount = isset($t['donated']) && is_numeric($t['donated']) && (float) $t['donated'] > 0 ? (float) $t['donated'] : (float) (isset($t['amount']) ? $t['amount'] : 0);
		$email = OnboardingService::cleanEmail(isset($t['email']) ? $t['email'] : '');
		$when = !empty($t['transacted_at']) ? strtotime((string) $t['transacted_at']) : 0;
		$trunc = function ($k, $max) use ($t) {
			return dol_trunc((string) (isset($t[$k]) ? $t[$k] : ''), $max, 'right', 'UTF-8', 1);
		};

		$sql = "INSERT INTO ".$table." (entity, gb_transaction_id, email, firstname, lastname, fk_adherent, zone, tool, trainer_name, amount, zone_amount, trainer_amount, transacted_at, status, datec) VALUES (";
		$sql .= ((int) $conf->entity).", '".$this->db->escape($txid)."', '".$this->db->escape($email)."', '".$this->db->escape($trunc('first_name', 100))."', '".$this->db->escape($trunc('last_name', 100))."', ";
		$trainee = $this->memberByEmail($email);
		$sql .= ($trainee ? (int) $trainee : 'NULL').", '', '', '', ".price2num($amount).", 0, 0, ";
		$sql .= "'".$this->db->idate($when ? $when : OnboardingService::now())."', 'unregistered', '".$this->db->idate(OnboardingService::now())."')";
		if (!$this->db->query($sql)) {
			return 'duplicate'; // Lost a race with another delivery.
		}
		$id = (int) $this->db->last_insert_id($table);

		$reg = $this->findRegistration($email, $amount);
		if (!$reg) {
			$a = self::answers($t);
			if ($a['zone'] !== '' || $a['tool'] !== '') {
				$zone = self::zoneName($a['zone']);
				$reg = (object) array('rowid' => 0, 'tool' => $a['tool'] !== '' ? $a['tool'] : $zone, 'zone' => $zone, 'trainer_name' => $a['trainer'], 'trainer_member' => '');
			}
		}
		if (!$reg || !$this->apply($id, $reg)) {
			$this->svc->mail(
				getDolGlobalString('ONBOARDING_STAFF_EMAIL'),
				'Training payment without a registration',
				trim($trunc('first_name', 100).' '.$trunc('last_name', 100)).' <'.$email.'> paid '.price($amount)." for a training, but registered no tool on the website with that email.\n\nLink it to their registration under Members, Onboarding, Trainings.\n".dol_buildpath('/onboarding/trainings.php', 2)
			);
			return 'training '.$id.' paid without a registration';
		}
		$res = $this->db->query("SELECT status, trainer_name FROM ".$table." WHERE rowid = ".$id);
		$row = $res ? $this->db->fetch_object($res) : null;
		return 'training '.$id.' recorded'.($row && $row->status == 'trainer_unmatched' ? ', trainer "'.$row->trainer_name.'" not matched' : '');
	}

	/**
	 * Fill in a paid training from its registration (or the checkout answers),
	 * split the fee, tag and email the trainee.
	 *
	 * @param int $id Training row
	 * @param object $reg Registration row, or answers with rowid 0
	 * @return bool Applied (false if the registration was claimed by another payment)
	 */
	private function apply($id, $reg)
	{
		$table = $this->db->prefix().'onboarding_training';
		if ($reg->rowid) {
			$claim = $this->db->query("UPDATE ".$this->db->prefix()."onboarding_training_reg SET status = 'paid', fk_training = ".((int) $id)." WHERE rowid = ".((int) $reg->rowid)." AND status = 'waiting'");
			if (!$claim || $this->db->affected_rows($claim) < 1) {
				return false;
			}
		}
		// The trainee is the person who registered, even if someone else paid.
		if (!empty($reg->email)) {
			$member = $this->memberByEmail($reg->email);
			$this->db->query("UPDATE ".$table." SET email = '".$this->db->escape($reg->email)."', firstname = '".$this->db->escape((string) $reg->firstname)."', lastname = '".$this->db->escape((string) $reg->lastname)."', fk_adherent = ".($member ? (int) $member : 'NULL')." WHERE rowid = ".((int) $id));
		}
		$res = $this->db->query("SELECT * FROM ".$table." WHERE rowid = ".((int) $id));
		$tr = $res ? $this->db->fetch_object($res) : null;
		if (!$tr) {
			return false;
		}
		$trainer = $this->memberByCode($reg->trainer_member);
		if (!$trainer) {
			$trainer = $this->matchTrainer($reg->trainer_name);
		}
		$trainerAmount = round((float) $tr->amount * self::trainerShare(), 2);
		$zoneAmount = round((float) $tr->amount - $trainerAmount, 2);
		$tool = dol_trunc((string) $reg->tool, 150, 'right', 'UTF-8', 1);
		$zone = dol_trunc((string) $reg->zone, 100, 'right', 'UTF-8', 1);
		$trainerName = dol_trunc((string) $reg->trainer_name, 150, 'right', 'UTF-8', 1);
		$this->db->query("UPDATE ".$table." SET zone = '".$this->db->escape($zone)."', tool = '".$this->db->escape($tool)."', trainer_name = '".$this->db->escape($trainerName)."', fk_trainer = ".($trainer ? (int) $trainer : 'NULL').", zone_amount = ".price2num($zoneAmount).", trainer_amount = ".price2num($trainerAmount).", status = '".($trainer ? 'recorded' : 'trainer_unmatched')."' WHERE rowid = ".((int) $id));

		$this->post('zone', $zone, $zoneAmount, 'training', $id, 'Training fee, '.$tool);
		if ($trainer) {
			$this->postTrainerShare($id);
		}
		$this->markTrained($tr->email, (int) $tr->fk_adherent, $tool);

		$first = (string) $tr->firstname;
		if ($first === '' && !empty($reg->firstname)) {
			$first = (string) $reg->firstname;
		}
		$this->svc->mail(
			$tr->email,
			strtr(getDolGlobalString('ONBOARDING_MAIL_TRAINED_SUBJECT', 'You are trained on the {tool}'), array('{tool}' => $tool)),
			strtr(getDolGlobalString('ONBOARDING_MAIL_TRAINED_BODY', "Hi {firstname},\n\nThanks for your training fee. You are now on record as trained to use the {tool} ({zone}).\n\nYou can check what you are trained on at any time: {lookup_url}"), array(
				'{firstname}' => $first !== '' ? $first : 'there',
				'{tool}' => $tool,
				'{zone}' => $zone,
				'{trainer}' => $trainerName,
				'{lookup_url}' => getDolGlobalString('ONBOARDING_TRAINING_LOOKUP_URL', '(ask a member of staff)'),
				'{org}' => getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'the makerspace'),
			))
		);
		return true;
	}

	/**
	 * Add a ledger row. A training's share is posted once per account type.
	 *
	 * @param string $type zone or trainer
	 * @param string $key Zone name or member id
	 * @param float $amount Signed amount
	 * @param string $kind training, dues_credit, spend, adjust
	 * @param int $trainingId Training row, 0 for none
	 * @param string $note Note
	 * @param int $creditId Credit row, 0 for none
	 * @param int $userId Who did it, 0 for the module
	 * @return bool Row added
	 */
	public function post($type, $key, $amount, $kind, $trainingId = 0, $note = '', $creditId = 0, $userId = 0)
	{
		global $conf;
		if (abs((float) $amount) < 0.005 && $kind == 'training') {
			return false;
		}
		$sql = "INSERT INTO ".$this->db->prefix()."onboarding_ledger (entity, account_type, account_key, amount, kind, fk_training, fk_credit, note, fk_user, datec) VALUES (";
		$sql .= ((int) $conf->entity).", '".$this->db->escape($type)."', '".$this->db->escape((string) $key)."', ".price2num((float) $amount).", '".$this->db->escape($kind)."', ";
		$sql .= ($trainingId ? (int) $trainingId : 'NULL').", ".($creditId ? (int) $creditId : 'NULL').", '".$this->db->escape(dol_trunc($note, 255, 'right', 'UTF-8', 1))."', ".($userId ? (int) $userId : 'NULL').", '".$this->db->idate(OnboardingService::now())."')";
		return (bool) $this->db->query($sql);
	}

	/**
	 * @param string $type zone or trainer
	 * @param string $key Zone name or member id
	 * @return float Balance
	 */
	public function balance($type, $key)
	{
		global $conf;
		$res = $this->db->query("SELECT COALESCE(SUM(amount), 0) AS b FROM ".$this->db->prefix()."onboarding_ledger WHERE entity = ".((int) $conf->entity)." AND account_type = '".$this->db->escape($type)."' AND account_key = '".$this->db->escape((string) $key)."'");
		$obj = $res ? $this->db->fetch_object($res) : null;
		return $obj ? round((float) $obj->b, 2) : 0.0;
	}

	/**
	 * @param string $type zone or trainer
	 * @return array<string,float> Every account of that type with its balance
	 */
	public function balances($type)
	{
		global $conf;
		$out = array();
		$res = $this->db->query("SELECT account_key, SUM(amount) AS b FROM ".$this->db->prefix()."onboarding_ledger WHERE entity = ".((int) $conf->entity)." AND account_type = '".$this->db->escape($type)."' GROUP BY account_key ORDER BY account_key");
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$out[(string) $obj->account_key] = round((float) $obj->b, 2);
		}
		return $out;
	}

	/**
	 * Credit a training's trainer share, then see whether a dues credit is due.
	 *
	 * @param int $trainingId Training row with fk_trainer set
	 * @return void
	 */
	public function postTrainerShare($trainingId)
	{
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_training WHERE rowid = ".((int) $trainingId));
		$tr = $res ? $this->db->fetch_object($res) : null;
		if (!$tr || !$tr->fk_trainer) {
			return;
		}
		if ($this->post('trainer', (int) $tr->fk_trainer, (float) $tr->trainer_amount, 'training', (int) $tr->rowid, 'Trained '.trim($tr->firstname.' '.$tr->lastname).' on '.$tr->tool)) {
			$this->checkCredit((int) $tr->fk_trainer);
		}
	}

	// ---------------------------------------------------------------- people

	/**
	 * @param string $email Email
	 * @return int Member id for this address, 0 if none
	 */
	public function memberByEmail($email)
	{
		if ($email === '') {
			return 0;
		}
		$app = $this->svc->findByEmail($email);
		if ($app && $app->fk_adherent > 0) {
			return (int) $app->fk_adherent;
		}
		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."adherent WHERE LOWER(email) = '".$this->db->escape($email)."' AND entity IN (".getEntity('adherent').") ORDER BY statut DESC, rowid ASC LIMIT 1");
		$obj = $res ? $this->db->fetch_object($res) : null;
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * The member a typed trainer name refers to. A name matched by hand before
	 * is remembered; otherwise it must equal exactly one member's full name,
	 * Discord handle, badge code or email.
	 *
	 * @param string $typed Name typed at checkout
	 * @return int Member id, 0 if no single match
	 */
	public function matchTrainer($typed)
	{
		global $conf;
		$n = self::norm($typed);
		if ($n === '') {
			return 0;
		}
		$res = $this->db->query("SELECT trainer_name, fk_trainer FROM ".$this->db->prefix()."onboarding_training WHERE entity = ".((int) $conf->entity)." AND fk_trainer IS NOT NULL ORDER BY rowid DESC LIMIT 2000");
		while ($res && ($obj = $this->db->fetch_object($res))) {
			if (self::norm($obj->trainer_name) === $n) {
				return (int) $obj->fk_trainer;
			}
		}
		$found = array();
		foreach ($this->members() as $m) {
			$keys = array(self::norm($m->firstname.' '.$m->lastname), self::norm($m->email), self::norm($m->discord_handle), self::norm($m->member_code));
			if (in_array($n, array_filter($keys), true)) {
				$found[(int) $m->rowid] = true;
			}
		}
		return count($found) == 1 ? (int) key($found) : 0;
	}

	/**
	 * @return object[] Members who are not excluded, for matching and pickers
	 */
	public function members()
	{
		$out = array();
		$sql = "SELECT a.rowid, a.firstname, a.lastname, a.email, a.statut, ef.discord_handle, ef.member_code FROM ".$this->db->prefix()."adherent AS a";
		$sql .= " LEFT JOIN ".$this->db->prefix()."adherent_extrafields AS ef ON ef.fk_object = a.rowid";
		$sql .= " WHERE a.entity IN (".getEntity('adherent').") AND a.statut <> ".((int) Adherent::STATUS_EXCLUDED)." ORDER BY a.firstname, a.lastname";
		$res = $this->db->query($sql);
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$out[] = $obj;
		}
		return $out;
	}

	/**
	 * Give a training (and every other unmatched one typed with the same name)
	 * its trainer, and credit them.
	 *
	 * @param int $trainingId Training row
	 * @param int $memberId Trainer's member id
	 * @return int Trainings matched
	 */
	public function assignTrainer($trainingId, $memberId)
	{
		global $conf;
		$table = $this->db->prefix().'onboarding_training';
		$res = $this->db->query("SELECT trainer_name FROM ".$table." WHERE rowid = ".((int) $trainingId)." AND entity = ".((int) $conf->entity));
		$tr = $res ? $this->db->fetch_object($res) : null;
		if (!$tr || $memberId <= 0) {
			return 0;
		}
		$n = self::norm($tr->trainer_name);
		$ids = array();
		$res = $this->db->query("SELECT rowid, trainer_name FROM ".$table." WHERE entity = ".((int) $conf->entity)." AND status = 'trainer_unmatched'");
		while ($res && ($obj = $this->db->fetch_object($res))) {
			if ((int) $obj->rowid == (int) $trainingId || ($n !== '' && self::norm($obj->trainer_name) === $n)) {
				$ids[] = (int) $obj->rowid;
			}
		}
		$done = 0;
		foreach ($ids as $id) {
			$up = $this->db->query("UPDATE ".$table." SET fk_trainer = ".((int) $memberId).", status = 'recorded' WHERE rowid = ".$id." AND status = 'trainer_unmatched'");
			if ($up && $this->db->affected_rows($up) > 0) {
				$this->postTrainerShare($id);
				$done++;
			}
		}
		return $done;
	}

	/**
	 * Undo a training recorded by mistake or refunded: reverse its shares and
	 * drop the trained tag unless another training still covers that tool.
	 *
	 * @param int $trainingId Training row
	 * @param User $by Who did it
	 * @return bool Voided
	 */
	public function voidTraining($trainingId, $by)
	{
		global $conf;
		$table = $this->db->prefix().'onboarding_training';
		$res = $this->db->query("SELECT * FROM ".$table." WHERE rowid = ".((int) $trainingId)." AND entity = ".((int) $conf->entity));
		$tr = $res ? $this->db->fetch_object($res) : null;
		if (!$tr || $tr->status == 'ignored') {
			return false;
		}
		$up = $this->db->query("UPDATE ".$table." SET status = 'ignored' WHERE rowid = ".((int) $tr->rowid)." AND status <> 'ignored'");
		if (!$up || $this->db->affected_rows($up) < 1) {
			return false;
		}
		$res = $this->db->query("SELECT account_type, account_key, amount FROM ".$this->db->prefix()."onboarding_ledger WHERE fk_training = ".((int) $tr->rowid));
		$rows = array();
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$rows[] = $obj;
		}
		foreach ($rows as $obj) {
			$this->post($obj->account_type, $obj->account_key, -(float) $obj->amount, 'adjust', 0, 'Training '.$tr->rowid.' voided ('.$tr->tool.')', 0, (int) $by->id);
		}
		$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$table." WHERE entity = ".((int) $conf->entity)." AND status <> 'ignored' AND tool = '".$this->db->escape($tr->tool)."' AND (email = '".$this->db->escape($tr->email)."'".($tr->fk_adherent ? " OR fk_adherent = ".((int) $tr->fk_adherent) : '').")");
		$left = $res ? (int) $this->db->fetch_object($res)->n : 1;
		if (!$left && $tr->tool !== '') {
			$targets = array();
			if ($tr->fk_adherent) {
				$targets[] = array(Categorie::TYPE_MEMBER, new Adherent($this->db), (int) $tr->fk_adherent);
			}
			$contactId = $this->svc->contactByEmail($tr->email);
			if ($contactId) {
				$targets[] = array(Categorie::TYPE_CONTACT, new Contact($this->db), $contactId);
			}
			foreach ($targets as $t) {
				$tag = $this->trainedTag($tr->tool, $t[0]);
				if ($tag && $t[1]->fetch($t[2]) > 0 && $tag->containsObject($t[0], $t[2])) {
					$tag->del_type($t[1], $t[0]);
				}
			}
		}
		return true;
	}

	// ---------------------------------------------------------------- trained tags

	/**
	 * The "Trained: <tool>" tag of a kind, under a parent tag "Trained".
	 *
	 * @param string $tool Tool
	 * @param string $type Categorie::TYPE_MEMBER or TYPE_CONTACT
	 * @return Categorie|null
	 */
	public function trainedTag($tool, $type)
	{
		if (!isModEnabled('categorie')) {
			return null;
		}
		$root = getDolGlobalString('ONBOARDING_TRAINED_TAG', 'Trained');
		$parent = $this->tag($root, $type, 0, 'People trained on a tool, recorded from paid trainings.');
		return $parent ? $this->tag($root.': '.$tool, $type, $parent->id, 'Trained to use the '.$tool.'.') : null;
	}

	/**
	 * @param string $label Label
	 * @param string $type Category type
	 * @param int $parentId Parent tag
	 * @param string $description Description for a new tag
	 * @return Categorie|null Found or created tag
	 */
	private function tag($label, $type, $parentId, $description)
	{
		$label = dol_trunc($label, 180, 'right', 'UTF-8', 1);
		$cat = new Categorie($this->db);
		if ($cat->fetch(0, $label, $type) > 0) {
			return $cat;
		}
		$cat = new Categorie($this->db);
		$cat->label = $label;
		$cat->type = $type;
		$cat->fk_parent = $parentId;
		$cat->description = $description;
		$cat->visible = 0;
		if ($cat->create($this->svc->actor()) <= 0) {
			dol_syslog('Onboarding: could not create tag '.$label.': '.$cat->error, LOG_ERR);
			return null;
		}
		return $cat;
	}

	/**
	 * Tag the trainee's member and contact cards as trained on the tool.
	 *
	 * @param string $email Trainee email
	 * @param int $memberId Trainee member id, 0 if none
	 * @param string $tool Tool
	 * @return void
	 */
	public function markTrained($email, $memberId, $tool)
	{
		if ($memberId > 0) {
			$tag = $this->trainedTag($tool, Categorie::TYPE_MEMBER);
			$adh = new Adherent($this->db);
			if ($tag && $adh->fetch($memberId) > 0 && !$tag->containsObject(Categorie::TYPE_MEMBER, $memberId)) {
				$tag->add_type($adh, Categorie::TYPE_MEMBER);
			}
		}
		$contactId = $this->svc->contactByEmail($email);
		if ($contactId > 0) {
			$tag = $this->trainedTag($tool, Categorie::TYPE_CONTACT);
			$c = new Contact($this->db);
			if ($tag && $c->fetch($contactId) > 0 && !$tag->containsObject(Categorie::TYPE_CONTACT, $contactId)) {
				$tag->add_type($c, Categorie::TYPE_CONTACT);
			}
		}
	}

	/**
	 * What someone is trained on, for the website lookup. Matches the email they
	 * paid with, or the email on their member card.
	 *
	 * @param string $email Email
	 * @return array<int,array{tool:string,zone:string,date:string}>
	 */
	public function trainedOn($email)
	{
		global $conf;
		$email = OnboardingService::cleanEmail($email);
		if ($email === '' || strpos($email, '@') === false) {
			return array();
		}
		$member = $this->memberByEmail($email);
		$where = "email = '".$this->db->escape($email)."'".($member ? " OR fk_adherent = ".((int) $member) : '');
		$res = $this->db->query("SELECT tool, zone, MIN(transacted_at) AS first FROM ".$this->db->prefix()."onboarding_training WHERE entity = ".((int) $conf->entity)." AND status IN ('recorded', 'trainer_unmatched') AND (".$where.") GROUP BY tool, zone ORDER BY zone, tool");
		$out = array();
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$out[] = array('tool' => (string) $obj->tool, 'zone' => (string) $obj->zone, 'date' => dol_print_date($this->db->jdate($obj->first), '%Y-%m-%d'));
		}
		return $out;
	}

	// ---------------------------------------------------------------- zones and the catalog

	/**
	 * Every zone: the setting's list first, then zones in the website's training
	 * catalog, then any other zone with ledger entries.
	 *
	 * @return string[]
	 */
	public function zones()
	{
		global $conf;
		$out = array();
		$catalog = $this->catalog(false);
		$lists = array(explode(',', getDolGlobalString('ONBOARDING_TRAINING_ZONES', 'Digital Fab,Electronics,Woodworking,Machining,Metalworking,Crafting')), $catalog ? $catalog['zones'] : array());
		foreach ($lists as $list) {
			foreach ($list as $z) {
				$z = trim((string) $z);
				if ($z !== '') {
					$out[$z] = true;
				}
			}
		}
		$res = $this->db->query("SELECT DISTINCT account_key AS z FROM ".$this->db->prefix()."onboarding_ledger WHERE entity = ".((int) $conf->entity)." AND account_type = 'zone' ORDER BY account_key");
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$out[(string) $obj->z] = true;
		}
		return array_keys($out);
	}

	/**
	 * The website's training catalog (data/training.yaml in the website repo, as
	 * published at ONBOARDING_TRAINING_CATALOG_URL): zones, fees, trainers, tools.
	 * The last copy fetched is kept, so pages work while the site is unreachable.
	 *
	 * @param bool $fetch Fetch a fresh copy first
	 * @return array{zones:string[],fees:float[],trainers:array,tools:array,fetched:int}|null
	 */
	public function catalog($fetch = true)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$url = getDolGlobalString('ONBOARDING_TRAINING_CATALOG_URL');
		if ($fetch && $url !== '') {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
			$r = getURLContent($url, 'GET', '', 1, array('Accept: application/json'), array('http', 'https'), 2);
			$json = !empty($r['http_code']) && $r['http_code'] == 200 ? json_decode($r['content'], true) : null;
			if (is_array($json) && isset($json['tools']) && is_array($json['tools'])) {
				$json['fetched'] = OnboardingService::now();
				dolibarr_set_const($this->db, 'ONBOARDING_TRAINING_CATALOG_CACHE', json_encode($json), 'chaine', 0, '', $conf->entity);
				return $json + array('zones' => array(), 'fees' => array(), 'trainers' => array());
			}
			dol_syslog('Onboarding: training catalog '.$url.' returned '.(isset($r['http_code']) ? $r['http_code'] : '?'), LOG_WARNING);
		}
		$cached = json_decode(getDolGlobalString('ONBOARDING_TRAINING_CATALOG_CACHE'), true);
		return is_array($cached) ? $cached + array('zones' => array(), 'fees' => array(), 'trainers' => array(), 'tools' => array(), 'fetched' => 0) : null;
	}

	/**
	 * @param string $code Badge code (member_code)
	 * @return int Member with that badge code, 0 if none
	 */
	public function memberByCode($code)
	{
		$code = trim((string) $code);
		if ($code === '') {
			return 0;
		}
		$res = $this->db->query("SELECT fk_object FROM ".$this->db->prefix()."adherent_extrafields WHERE member_code = '".$this->db->escape($code)."' LIMIT 1");
		$obj = $res ? $this->db->fetch_object($res) : null;
		return $obj ? (int) $obj->fk_object : 0;
	}

	// ---------------------------------------------------------------- registrations

	/**
	 * A training registered on the website before payment (action=trainingstart).
	 * The website has already checked the tool and trainer against the catalog.
	 *
	 * @param array<string,mixed> $in email, firstname, lastname, tool_id, tool, zone, fee, trainer_id, trainer_name, trainer_member, ip
	 * @return array<string,mixed>
	 */
	public function register($in)
	{
		global $conf;
		$get = function ($k, $max) use ($in) {
			return dol_trunc(trim((string) (isset($in[$k]) ? $in[$k] : '')), $max, 'right', 'UTF-8', 1);
		};
		$email = OnboardingService::cleanEmail($get('email', 255));
		if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) || $get('tool', 150) === '' || $get('zone', 100) === '' || $get('trainer_name', 150) === '') {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		$fee = isset($in['fee']) && is_numeric($in['fee']) ? (float) $in['fee'] : 0;
		$table = $this->db->prefix().'onboarding_training_reg';
		$cols = array('email' => $email);
		foreach (array('firstname' => 100, 'lastname' => 100, 'tool_id' => 100, 'tool' => 150, 'zone' => 100, 'trainer_id' => 100, 'trainer_name' => 150, 'trainer_member' => 64, 'ip' => 64) as $k => $max) {
			$cols[$k] = $get($k, $max);
		}
		$sql = "INSERT INTO ".$table." (entity, ".implode(', ', array_keys($cols)).", fee, status, datec) VALUES (".((int) $conf->entity);
		foreach ($cols as $v) {
			$sql .= ", '".$this->db->escape($v)."'";
		}
		$sql .= ", ".price2num($fee).", 'waiting', '".$this->db->idate(OnboardingService::now())."')";
		if (!$this->db->query($sql)) {
			return array('ok' => false, 'error' => 'save', 'http' => 500);
		}
		return array('ok' => true, 'id' => (int) $this->db->last_insert_id($table));
	}

	/**
	 * The waiting registration a payment belongs to: same email, from the last
	 * 14 days, preferring one whose fee equals the amount paid, then the newest.
	 *
	 * @param string $email Email used at checkout
	 * @param float $amount Amount paid
	 * @return object|null
	 */
	public function findRegistration($email, $amount)
	{
		global $conf;
		if ($email === '') {
			return null;
		}
		$since = $this->db->idate(OnboardingService::now() - 14 * 86400);
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_training_reg WHERE entity = ".((int) $conf->entity)." AND status = 'waiting' AND email = '".$this->db->escape($email)."' AND datec >= '".$since."' ORDER BY (ABS(fee - ".price2num((float) $amount).") < 0.005) DESC, datec DESC LIMIT 1");
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param int $id Registration
	 * @return object|null
	 */
	public function registration($id)
	{
		global $conf;
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_training_reg WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * Give a payment that arrived without a registration the registration it
	 * belongs to (the payer used another email, for instance).
	 *
	 * @param int $trainingId Training row with status unregistered
	 * @param int $regId Waiting registration
	 * @return string Result for the page
	 */
	public function linkRegistration($trainingId, $regId)
	{
		global $conf;
		$reg = $this->registration($regId);
		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."onboarding_training WHERE rowid = ".((int) $trainingId)." AND entity = ".((int) $conf->entity)." AND status = 'unregistered'");
		if (!$reg || $reg->status != 'waiting' || !$res || !$this->db->fetch_object($res)) {
			return 'Pick a payment without a registration and a registration that is still waiting.';
		}
		return $this->apply((int) $trainingId, $reg) ? 'Linked: '.$reg->tool.' for '.trim($reg->firstname.' '.$reg->lastname).'.' : 'That registration was just used by another payment.';
	}

	/**
	 * Mark registrations nobody paid for within 14 days as expired.
	 *
	 * @return string Summary
	 */
	public function expireRegistrations()
	{
		global $conf;
		$res = $this->db->query("UPDATE ".$this->db->prefix()."onboarding_training_reg SET status = 'expired' WHERE entity = ".((int) $conf->entity)." AND status = 'waiting' AND datec < '".$this->db->idate(OnboardingService::now() - 14 * 86400)."'");
		return ($res ? $this->db->affected_rows($res) : 0).' unpaid training registrations expired';
	}

	// ---------------------------------------------------------------- dues credit

	/**
	 * Open a dues credit for a trainer whose credit has reached the threshold,
	 * unless one is already waiting.
	 *
	 * @param int $memberId Trainer
	 * @return int New credit id, 0 if none opened
	 */
	public function checkCredit($memberId)
	{
		global $conf;
		$threshold = self::threshold();
		if ($this->balance('trainer', $memberId) + 0.001 < $threshold) {
			return 0;
		}
		$table = $this->db->prefix().'onboarding_credit';
		$res = $this->db->query("SELECT rowid FROM ".$table." WHERE entity = ".((int) $conf->entity)." AND fk_adherent = ".((int) $memberId)." AND status IN ('pending', 'approved') LIMIT 1");
		if ($res && $this->db->fetch_object($res)) {
			return 0;
		}
		$this->db->query("INSERT INTO ".$table." (entity, fk_adherent, amount, status, datec) VALUES (".((int) $conf->entity).", ".((int) $memberId).", ".price2num($threshold).", 'pending', '".$this->db->idate(OnboardingService::now())."')");
		$id = (int) $this->db->last_insert_id($table);
		$adh = new Adherent($this->db);
		$name = $adh->fetch($memberId) > 0 ? trim($adh->firstname.' '.$adh->lastname) : 'member '.$memberId;
		$this->svc->mail(
			getDolGlobalString('ONBOARDING_STAFF_EMAIL'),
			'Dues credit to approve: '.$name,
			$name.' has '.price($this->balance('trainer', $memberId)).' of training credit, enough for a month of dues ('.price($threshold).").\n\nApprove or reject it in Dolibarr: Members, Onboarding, Training accounts.\n".dol_buildpath('/onboarding/training-accounts.php', 2)
		);
		return $id;
	}

	/**
	 * @param int $creditId Credit row
	 * @return object|null
	 */
	public function credit($creditId)
	{
		global $conf;
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_credit WHERE rowid = ".((int) $creditId)." AND entity = ".((int) $conf->entity));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * The Givebutter dues payment to refund for a member: their latest one.
	 *
	 * @param int $memberId Member
	 * @return object|null Row of onboarding_payment
	 */
	public function latestDues($memberId)
	{
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT p.gb_transaction_id, p.amount, p.transacted_at FROM ".$p."onboarding_payment AS p JOIN ".$p."onboarding_applicant AS a ON a.rowid = p.fk_applicant WHERE a.fk_adherent = ".((int) $memberId)." AND p.status = 'matched' ORDER BY p.transacted_at DESC LIMIT 1");
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * Approve a pending dues credit: take it off the trainer's credit and note
	 * which dues payment to refund.
	 *
	 * @param int $creditId Credit row
	 * @param User $by Approver
	 * @return string Result for the page
	 */
	public function approveCredit($creditId, $by)
	{
		$c = $this->credit($creditId);
		if (!$c || $c->status != 'pending') {
			return 'That credit is not waiting for approval.';
		}
		$dues = $this->latestDues((int) $c->fk_adherent);
		$up = $this->db->query("UPDATE ".$this->db->prefix()."onboarding_credit SET status = 'approved', gb_transaction_id = ".($dues ? "'".$this->db->escape($dues->gb_transaction_id)."'" : 'NULL').", fk_user_decided = ".((int) $by->id).", date_decided = '".$this->db->idate(OnboardingService::now())."' WHERE rowid = ".((int) $c->rowid)." AND status = 'pending'");
		if (!$up || $this->db->affected_rows($up) < 1) {
			return 'That credit is not waiting for approval.';
		}
		$this->post('trainer', (int) $c->fk_adherent, -(float) $c->amount, 'dues_credit', 0, 'Month of dues refunded', (int) $c->rowid, (int) $by->id);
		$msg = 'Approved. ';
		$msg .= $dues ? 'Refund '.price($c->amount).' of Givebutter transaction '.$dues->gb_transaction_id.' (dues of '.price($dues->amount).' on '.dol_print_date($this->db->jdate($dues->transacted_at), 'day').'), then press Mark refunded.' : 'They have no Givebutter dues on record; credit them by hand, then press Mark refunded.';
		$this->checkCredit((int) $c->fk_adherent);
		return $msg;
	}

	/**
	 * @param int $creditId Credit row
	 * @param User $by Who decided
	 * @return string Result for the page
	 */
	public function rejectCredit($creditId, $by)
	{
		$up = $this->db->query("UPDATE ".$this->db->prefix()."onboarding_credit SET status = 'rejected', fk_user_decided = ".((int) $by->id).", date_decided = '".$this->db->idate(OnboardingService::now())."' WHERE rowid = ".((int) $creditId)." AND status = 'pending'");
		return $up && $this->db->affected_rows($up) > 0 ? 'Rejected. Their credit is unchanged; the next training they give asks again.' : 'That credit is not waiting for approval.';
	}

	/**
	 * The refund has been made in Givebutter. Tells the trainer.
	 *
	 * @param int $creditId Credit row
	 * @return string Result for the page
	 */
	public function markRefunded($creditId)
	{
		$c = $this->credit($creditId);
		$up = $c ? $this->db->query("UPDATE ".$this->db->prefix()."onboarding_credit SET status = 'refunded' WHERE rowid = ".((int) $c->rowid)." AND status = 'approved'") : null;
		if (!$up || $this->db->affected_rows($up) < 1) {
			return 'That credit is not approved yet.';
		}
		$adh = new Adherent($this->db);
		if ($adh->fetch((int) $c->fk_adherent) > 0 && $adh->email) {
			$this->svc->mail(
				$adh->email,
				'A month of dues refunded for your training',
				"Hi ".$adh->firstname.",\n\nThe trainings you have given earned ".price($c->amount)." of credit, so we have refunded a month of your dues. Thank you for training people.\n\nCredit left over: ".price($this->balance('trainer', (int) $c->fk_adherent)).'.'
			);
		}
		return 'Marked refunded and the trainer was told.';
	}
}
