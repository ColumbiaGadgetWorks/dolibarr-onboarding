<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');
dol_include_once('/onboarding/class/training.class.php');
dol_include_once('/onboarding/class/purchase.class.php');

/**
 * Training bookings: the tools that need training, who trains each and when, and
 * the people booked on a session, from the website's booking page to the
 * trainer saying they were trained.
 *
 * A trainee types their email on the website. Dolibarr says whether it is a
 * member (member price), a non-member, or someone new (made a non-member on the
 * spot), and what still needs signing: the waiver for everyone, the membership
 * agreement too for members. They pick a tool, a trainer and a time; the booking
 * holds the seat while they pay on Givebutter. Givebutter carries only an email
 * and an amount, so the payment is matched to the booking by those.
 *
 * Once paid, the trainer confirms by email (or the trainee is asked to pick a
 * new date). After the session the trainer says whether the person was trained;
 * only then is the training recorded and the fee, after Givebutter's fee, split
 * between the trainer's credit and the zone's budget.
 *
 * Every email link is signed for one booking and one person (see link), and the
 * page it opens asks for a button press before changing anything.
 */
class OnboardingBooking
{
	/** Statuses that hold a seat. A held booking holds it only until hold_until. */
	const SEATED = array('held', 'paid', 'confirmed');

	/** Statuses a person can still be moved to another date from. */
	const MOVABLE = array('paid', 'confirmed', 'needs_new_date');

	const STATUS_LABELS = array(
		'requested' => 'On the spot: waiting for the trainee',
		'held' => 'Paying now',
		'paid' => 'Paid, trainer to confirm',
		'confirmed' => 'Confirmed',
		'needs_new_date' => 'Needs a new date',
		'trained' => 'Trained',
		'no_show' => 'Did not show (twice)',
		'other' => 'Waiting on the secretary',
		'refund_requested' => 'Refund to make',
		'refunded' => 'Refunded',
		'cancelled' => 'Cancelled',
		'expired' => 'Not paid',
	);

	/**
	 * Hours an on-the-spot request stays open for the trainee to say yes, sign
	 * and pay; minutes the trainer's own link works; requests a trainer may have
	 * waiting at once.
	 */
	const SPOT_HOURS = 4;
	const SPOT_LINK_MINUTES = 120;
	const SPOT_MAX_OPEN = 3;

	const WEEKDAYS = array(1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday');

	/** Letters for class codes, without the ones people misread. */
	const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/** @var DoliDB */
	public $db;
	/** @var OnboardingService */
	public $svc;
	/** @var OnboardingTraining */
	public $training;

	/**
	 * @param DoliDB $db Database handler
	 * @param OnboardingService|null $svc Shared service
	 */
	public function __construct($db, $svc = null)
	{
		$this->db = $db;
		$this->svc = $svc ? $svc : new OnboardingService($db);
		$this->training = new OnboardingTraining($db, $this->svc);
	}

	// ---------------------------------------------------------------- settings

	/**
	 * @param string $name Setting without the ONBOARDING_ prefix
	 * @param float $default Default
	 * @return float
	 */
	public static function num($name, $default)
	{
		$v = getDolGlobalString('ONBOARDING_'.$name);
		return $v !== '' && is_numeric($v) ? (float) $v : (float) $default;
	}

	/**
	 * @return DateTimeZone The shop's time zone
	 */
	public static function tz()
	{
		try {
			return new DateTimeZone(getDolGlobalString('ONBOARDING_TRAINING_TZ', 'America/Chicago'));
		} catch (Exception $e) {
			return new DateTimeZone('America/Chicago');
		}
	}

	/**
	 * @param int $ts Timestamp
	 * @param string $format PHP date format
	 * @return string The time on the shop's clock
	 */
	public static function fmt($ts, $format = 'l j F, g:i A')
	{
		$d = new DateTime('@'.((int) $ts));
		$d->setTimezone(self::tz());
		return $d->format($format);
	}

	/**
	 * @param string $day Y-m-d
	 * @param string $time H:i
	 * @return int Timestamp of that moment on the shop's clock
	 */
	public static function at($day, $time)
	{
		$d = DateTime::createFromFormat('Y-m-d H:i', $day.' '.$time, self::tz());
		return $d ? $d->getTimestamp() : 0;
	}

	/**
	 * @return string Secret that signs the links in booking emails
	 */
	private function secret()
	{
		global $conf;
		$secret = getDolGlobalString('ONBOARDING_BOOKING_SECRET');
		if ($secret === '') {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			$secret = bin2hex(random_bytes(24));
			dolibarr_set_const($this->db, 'ONBOARDING_BOOKING_SECRET', $secret, 'chaine', 0, 'Signs the links in training booking emails', $conf->entity);
		}
		return $secret;
	}

	/**
	 * Install the read-only counters on the member card. They belong to this
	 * module (the CGWManagement scripts do not use them).
	 *
	 * @param DoliDB $db Database handler
	 * @return void
	 */
	public static function installFields($db)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($db);
		$extrafields->fetch_name_optionals_label('adherent', true);
		$have = isset($extrafields->attributes['adherent']['label']) ? $extrafields->attributes['adherent']['label'] : array();
		$fields = array(
			'trained_on' => array('Trained on', 'varchar', 255, 300),
			'trainings_given' => array('Trainings given', 'int', 6, 310),
		);
		foreach ($fields as $name => $def) {
			if (!isset($have[$name])) {
				// List visibility 5: shown on the card and in the list, never edited by hand.
				$extrafields->addExtraField($name, $def[0], $def[1], $def[3], $def[2], 'adherent', 0, 0, '', '', 1, '', '5');
			}
		}
	}

	/**
	 * The first tool, as an example: 3D printers, $10 ($5 for members), Digital
	 * Fab. Made only when there are no tools at all. Its trainer is added on the
	 * Training tools page.
	 *
	 * @return void
	 */
	public function installSample()
	{
		global $conf;
		$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_tool WHERE entity = ".((int) $conf->entity));
		if (!$res || (int) $this->db->fetch_object($res)->n > 0) {
			return;
		}
		$this->db->query("INSERT INTO ".$this->db->prefix()."onboarding_tool (entity, ref, label, zone, fee, member_discount, seats, minutes, covers, wiki_url, page_url, bookable, active, datec) VALUES (".((int) $conf->entity).", '3d-printers', '3D printers', 'Digital Fab', 10, 50, 1, 60, 'Bambu Lab X1 Carbon, Bambu Lab A1', 'https://wiki.comogadget.casa/index.php/3D_printers', '/tools/3d-printers/', 1, 1, '".$this->db->idate(OnboardingService::now())."')");
		$this->db->query("INSERT INTO ".$this->db->prefix()."onboarding_catalog_log (entity, note, datec) VALUES (".((int) $conf->entity).", 'Added the sample tool: 3D printers', '".$this->db->idate(OnboardingService::now())."')");
	}

	// ---------------------------------------------------------------- tools

	/**
	 * @param bool $activeOnly Only tools in use
	 * @return object[] Tools, by zone and name
	 */
	public function tools($activeOnly = false)
	{
		global $conf;
		$out = array();
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_tool WHERE entity = ".((int) $conf->entity).($activeOnly ? " AND active = 1" : "")." ORDER BY zone, label");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param int $id Tool
	 * @return object|null
	 */
	public function tool($id)
	{
		global $conf;
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_tool WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param string $ref Tool id as the website knows it, e.g. 3d-printers
	 * @return object|null
	 */
	public function toolByRef($ref)
	{
		global $conf;
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_tool WHERE ref = '".$this->db->escape((string) $ref)."' AND entity = ".((int) $conf->entity));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param object $tool Tool
	 * @return float What a paid-up member pays
	 */
	public static function memberPrice($tool)
	{
		return round((float) $tool->fee * (100 - max(0, min(100, (float) $tool->member_discount))) / 100, 2);
	}

	/**
	 * @param object $tool Tool
	 * @return float Trainer's share of what arrives, 0 to 1
	 */
	public static function shareOf($tool)
	{
		if ($tool && $tool->trainer_share !== null && $tool->trainer_share !== '') {
			return max(0, min(100, (float) $tool->trainer_share)) / 100;
		}
		return OnboardingTraining::trainerShare();
	}

	/**
	 * Add or change a tool.
	 *
	 * @param array<string,mixed> $in ref, label, zone, fee, member_discount, trainer_share, seats, minutes, covers, wiki_url, page_url, bookable, active
	 * @param User $by Who did it
	 * @param int $id Tool to change, 0 for a new one
	 * @return array{ok:bool,id?:int,error?:string}
	 */
	public function saveTool($in, $by, $id = 0)
	{
		global $conf;
		$get = function ($k, $max = 255) use ($in) {
			return dol_trunc(trim((string) (isset($in[$k]) ? $in[$k] : '')), $max, 'right', 'UTF-8', 1);
		};
		$ref = strtolower($get('ref', 64));
		$label = $get('label', 150);
		$zone = $get('zone', 100);
		if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $ref)) {
			return array('ok' => false, 'error' => 'The id must be lowercase letters, digits and dashes, for example 3d-printers.');
		}
		if ($label === '' || $zone === '') {
			return array('ok' => false, 'error' => 'A tool needs a name and a zone.');
		}
		$same = $this->toolByRef($ref);
		if ($same && (int) $same->rowid != (int) $id) {
			return array('ok' => false, 'error' => 'Another tool already uses the id '.$ref.'.');
		}
		$fee = (float) price2num($get('fee', 20));
		$discount = $get('member_discount', 10) === '' ? 50 : (float) price2num($get('member_discount', 10));
		$share = $get('trainer_share', 10) === '' ? 'NULL' : price2num(max(0, min(100, (float) price2num($get('trainer_share', 10)))));
		$cols = array(
			'ref' => "'".$this->db->escape($ref)."'",
			'label' => "'".$this->db->escape($label)."'",
			'zone' => "'".$this->db->escape($zone)."'",
			'fee' => price2num(max(0, $fee)),
			'member_discount' => price2num(max(0, min(100, $discount))),
			'trainer_share' => $share,
			'seats' => max(1, (int) $get('seats', 6)),
			'minutes' => max(15, (int) ($get('minutes', 6) ?: 60)),
			'covers' => "'".$this->db->escape($get('covers'))."'",
			'wiki_url' => "'".$this->db->escape($get('wiki_url'))."'",
			'page_url' => "'".$this->db->escape($get('page_url'))."'",
			'bookable' => empty($in['bookable']) ? 0 : 1,
			'active' => isset($in['active']) && empty($in['active']) ? 0 : 1,
		);
		$table = $this->db->prefix().'onboarding_tool';
		if ($id > 0) {
			$set = array();
			foreach ($cols as $k => $v) {
				$set[] = $k.' = '.$v;
			}
			if (!$this->db->query("UPDATE ".$table." SET ".implode(', ', $set)." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity))) {
				return array('ok' => false, 'error' => $this->db->lasterror());
			}
		} else {
			if (!$this->db->query("INSERT INTO ".$table." (entity, ".implode(', ', array_keys($cols)).", datec) VALUES (".((int) $conf->entity).", ".implode(', ', $cols).", '".$this->db->idate(OnboardingService::now())."')")) {
				return array('ok' => false, 'error' => $this->db->lasterror());
			}
			$id = (int) $this->db->last_insert_id($table);
		}
		$this->syncTrainerTags($id);
		$this->changed(($same ? 'Changed ' : 'Saved ').$label, $by);
		return array('ok' => true, 'id' => (int) $id);
	}

	/**
	 * @param int $toolId Tool, 0 for every tool
	 * @param bool $activeOnly Only slots in use
	 * @return object[] Trainer times, with the trainer's name
	 */
	public function slots($toolId = 0, $activeOnly = true)
	{
		global $conf;
		$p = $this->db->prefix();
		$sql = "SELECT s.*, a.firstname, a.lastname, a.email, a.statut FROM ".$p."onboarding_trainer_slot AS s LEFT JOIN ".$p."adherent AS a ON a.rowid = s.fk_adherent WHERE s.entity = ".((int) $conf->entity);
		if ($toolId > 0) {
			$sql .= " AND s.fk_tool = ".((int) $toolId);
		}
		if ($activeOnly) {
			$sql .= " AND s.active = 1";
		}
		$res = $this->db->query($sql." ORDER BY s.weekday, s.start_time, a.firstname");
		$out = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param int $id Slot
	 * @return object|null
	 */
	public function slot($id)
	{
		global $conf;
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT s.*, a.firstname, a.lastname, a.email FROM ".$p."onboarding_trainer_slot AS s LEFT JOIN ".$p."adherent AS a ON a.rowid = s.fk_adherent WHERE s.rowid = ".((int) $id)." AND s.entity = ".((int) $conf->entity));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * Give a trainer a weekly time on a tool. A trainer must be a member who is
	 * trained on the tool themselves, unless nobody is trained on it yet (the
	 * first trainer of a new tool).
	 *
	 * @param int $toolId Tool
	 * @param int $memberId Trainer
	 * @param int $weekday 1 Monday .. 7 Sunday
	 * @param string $time HH:MM
	 * @param int $minutes Length, 0 for the tool's
	 * @param int $seats Seats, 0 for the tool's
	 * @param User $by Who did it
	 * @return array{ok:bool,error?:string}
	 */
	public function addSlot($toolId, $memberId, $weekday, $time, $minutes, $seats, $by)
	{
		global $conf;
		$tool = $this->tool($toolId);
		if (!$tool || $memberId <= 0 || $weekday < 1 || $weekday > 7 || !preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time)) {
			return array('ok' => false, 'error' => 'Pick a trainer, a day and a time like 17:00.');
		}
		$time = str_pad($time, 5, '0', STR_PAD_LEFT);
		$adh = new Adherent($this->db);
		if ($adh->fetch($memberId) <= 0 || (int) $adh->statut != Adherent::STATUS_VALIDATED) {
			return array('ok' => false, 'error' => 'A trainer must be a current member.');
		}
		$anyone = $this->db->query("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_training WHERE entity = ".((int) $conf->entity)." AND status = 'recorded' AND (fk_tool = ".((int) $toolId)." OR tool = '".$this->db->escape($tool->label)."')");
		$trainedCount = $anyone ? (int) $this->db->fetch_object($anyone)->n : 0;
		if ($trainedCount > 0 && !$this->isTrainedOn($memberId, $tool)) {
			return array('ok' => false, 'error' => trim($adh->firstname.' '.$adh->lastname).' is not trained on the '.$tool->label.' yet. Record their training first (Mark trained, no fee).');
		}
		$sql = "INSERT INTO ".$this->db->prefix()."onboarding_trainer_slot (entity, fk_tool, fk_adherent, weekday, start_time, minutes, seats, active, datec) VALUES (";
		$sql .= ((int) $conf->entity).", ".((int) $toolId).", ".((int) $memberId).", ".((int) $weekday).", '".$this->db->escape($time)."', ".($minutes > 0 ? (int) $minutes : 'NULL').", ".($seats > 0 ? (int) $seats : 'NULL').", 1, '".$this->db->idate(OnboardingService::now())."')";
		if (!$this->db->query($sql)) {
			return array('ok' => false, 'error' => $this->db->lasterror());
		}
		$this->syncTrainerTags($toolId);
		$this->changed('Added '.trim($adh->firstname.' '.$adh->lastname).' on '.self::WEEKDAYS[$weekday].'s at '.$time.' for the '.$tool->label, $by);
		return array('ok' => true);
	}

	/**
	 * Stop a trainer's weekly time. Bookings already made on it keep their date.
	 *
	 * @param int $slotId Slot
	 * @param User $by Who did it
	 * @return bool
	 */
	public function removeSlot($slotId, $by)
	{
		$slot = $this->slot($slotId);
		if (!$slot) {
			return false;
		}
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_trainer_slot SET active = 0 WHERE rowid = ".((int) $slotId));
		$this->syncTrainerTags((int) $slot->fk_tool);
		$tool = $this->tool((int) $slot->fk_tool);
		$this->changed('Removed '.trim($slot->firstname.' '.$slot->lastname).' on '.self::WEEKDAYS[(int) $slot->weekday].'s at '.$slot->start_time.($tool ? ' for the '.$tool->label : ''), $by);
		return true;
	}

	/**
	 * @param int $memberId Member
	 * @param object $tool Tool
	 * @return bool A recorded training on this tool
	 */
	public function isTrainedOn($memberId, $tool)
	{
		global $conf;
		$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_training WHERE entity = ".((int) $conf->entity)." AND fk_adherent = ".((int) $memberId)." AND status = 'recorded' AND (fk_tool = ".((int) $tool->rowid)." OR tool = '".$this->db->escape($tool->label)."')");
		return $res && (int) $this->db->fetch_object($res)->n > 0;
	}

	/**
	 * The "Trainer: <tool>" tag goes on exactly the members with a time on the tool.
	 *
	 * @param int $toolId Tool
	 * @return void
	 */
	public function syncTrainerTags($toolId)
	{
		$tool = $this->tool($toolId);
		if (!$tool || !isModEnabled('categorie')) {
			return;
		}
		$tag = $this->trainerTag($tool->label);
		if (!$tag) {
			return;
		}
		$want = array();
		if ($tool->active) {
			foreach ($this->slots($toolId) as $s) {
				$want[(int) $s->fk_adherent] = true;
			}
		}
		$res = $this->db->query("SELECT fk_member FROM ".$this->db->prefix()."categorie_member WHERE fk_categorie = ".((int) $tag->id));
		$have = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$have[(int) $o->fk_member] = true;
		}
		foreach (array_diff_key($want, $have) as $id => $x) {
			$adh = new Adherent($this->db);
			if ($adh->fetch($id) > 0) {
				$tag->add_type($adh, Categorie::TYPE_MEMBER);
			}
		}
		foreach (array_diff_key($have, $want) as $id => $x) {
			$adh = new Adherent($this->db);
			if ($adh->fetch($id) > 0) {
				$tag->del_type($adh, Categorie::TYPE_MEMBER);
			}
		}
	}

	/**
	 * @param string $label Tool name
	 * @return Categorie|null The "Trainer: <tool>" member tag, under "Trainer"
	 */
	public function trainerTag($label)
	{
		$root = getDolGlobalString('ONBOARDING_TRAINER_TAG', 'Trainer');
		$parent = new Categorie($this->db);
		if ($parent->fetch(0, $root, Categorie::TYPE_MEMBER) <= 0) {
			$parent = new Categorie($this->db);
			$parent->label = $root;
			$parent->type = Categorie::TYPE_MEMBER;
			$parent->description = 'People who may run a training. Set on Members, Onboarding, Training tools, not by hand.';
			$parent->visible = 0;
			if ($parent->create($this->svc->actor()) <= 0) {
				return null;
			}
		}
		$name = dol_trunc($root.': '.$label, 180, 'right', 'UTF-8', 1);
		$tag = new Categorie($this->db);
		if ($tag->fetch(0, $name, Categorie::TYPE_MEMBER) > 0) {
			return $tag;
		}
		$tag = new Categorie($this->db);
		$tag->label = $name;
		$tag->type = Categorie::TYPE_MEMBER;
		$tag->fk_parent = $parent->id;
		$tag->description = 'May run the training for the '.$label.'.';
		$tag->visible = 0;
		return $tag->create($this->svc->actor()) > 0 ? $tag : null;
	}

	// ---------------------------------------------------------------- closed days

	/**
	 * @param string $from Y-m-d
	 * @param string $to Y-m-d
	 * @return array<string,string> Closed day => reason
	 */
	public function closedDays($from, $to)
	{
		global $conf;
		$out = array();
		$res = $this->db->query("SELECT day, reason FROM ".$this->db->prefix()."onboarding_closed_date WHERE entity = ".((int) $conf->entity)." AND day >= '".$this->db->escape($from)."' AND day <= '".$this->db->escape($to)."'");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[substr((string) $o->day, 0, 10)] = (string) $o->reason;
		}
		return $out;
	}

	/**
	 * @return object[] Closed days from today on
	 */
	public function upcomingClosed()
	{
		global $conf;
		$out = array();
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_closed_date WHERE entity = ".((int) $conf->entity)." AND day >= '".$this->db->escape(self::fmt(OnboardingService::now(), 'Y-m-d'))."' ORDER BY day");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * Close a day by hand. Anyone booked that day is asked to pick a new date.
	 *
	 * @param string $day Y-m-d
	 * @param string $reason Why
	 * @param User $by Who did it
	 * @return string Result for the page
	 */
	public function closeDay($day, $reason, $by)
	{
		global $conf;
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
			return 'Pick a date.';
		}
		$this->db->query("INSERT INTO ".$this->db->prefix()."onboarding_closed_date (entity, day, reason, source, datec) VALUES (".((int) $conf->entity).", '".$this->db->escape($day)."', '".$this->db->escape(dol_trunc($reason, 255, 'right', 'UTF-8', 1))."', 'hand', '".$this->db->idate(OnboardingService::now())."')");
		$this->changed('Closed '.$day.($reason !== '' ? ' ('.$reason.')' : ''), $by);
		$n = $this->moveOffClosedDays();
		return 'Closed '.$day.'.'.($n ? ' '.$n.' booking'.($n > 1 ? 's were' : ' was').' cancelled and asked to pick a new date.' : '');
	}

	/**
	 * @param int $id Closed day row
	 * @param User $by Who did it
	 * @return void
	 */
	public function openDay($id, $by)
	{
		$this->db->query("DELETE FROM ".$this->db->prefix()."onboarding_closed_date WHERE rowid = ".((int) $id)." AND source = 'hand'");
		$this->changed('Reopened a closed day', $by);
	}

	/**
	 * The website found these days on the shop calendar with Open Hack Night
	 * cancelled. They replace the calendar's earlier list from today on.
	 *
	 * @param array<int,array{day:string,reason?:string}> $days Days
	 * @return array<string,mixed>
	 */
	public function setCalendarClosures($days)
	{
		global $conf;
		$today = self::fmt(OnboardingService::now(), 'Y-m-d');
		$want = array();
		foreach ((array) $days as $d) {
			$day = is_array($d) && isset($d['day']) ? (string) $d['day'] : (string) $d;
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && $day >= $today) {
				$want[$day] = is_array($d) && isset($d['reason']) ? dol_trunc((string) $d['reason'], 255, 'right', 'UTF-8', 1) : 'Open Hack Night cancelled';
			}
		}
		$table = $this->db->prefix().'onboarding_closed_date';
		$have = array();
		$res = $this->db->query("SELECT rowid, day FROM ".$table." WHERE entity = ".((int) $conf->entity)." AND source = 'calendar' AND day >= '".$today."'");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$have[substr((string) $o->day, 0, 10)] = (int) $o->rowid;
		}
		$added = 0;
		foreach ($want as $day => $reason) {
			if (!isset($have[$day])) {
				$this->db->query("INSERT INTO ".$table." (entity, day, reason, source, datec) VALUES (".((int) $conf->entity).", '".$day."', '".$this->db->escape($reason)."', 'calendar', '".$this->db->idate(OnboardingService::now())."')");
				$added++;
			}
		}
		$removed = 0;
		foreach ($have as $day => $rowid) {
			if (!isset($want[$day])) {
				$this->db->query("DELETE FROM ".$table." WHERE rowid = ".$rowid);
				$removed++;
			}
		}
		if ($added || $removed) {
			$this->changed('Shop calendar: '.$added.' day(s) closed, '.$removed.' reopened', null);
		}
		$moved = $this->moveOffClosedDays();
		return array('ok' => true, 'added' => $added, 'removed' => $removed, 'moved' => $moved);
	}

	/**
	 * Bookings on a day that has since been closed lose their date.
	 *
	 * @return int Bookings moved off
	 */
	public function moveOffClosedDays()
	{
		$n = 0;
		$now = OnboardingService::now();
		$closed = $this->closedDays(self::fmt($now, 'Y-m-d'), self::fmt($now + 400 * 86400, 'Y-m-d'));
		if (!$closed) {
			return 0;
		}
		foreach ($this->bookingsWhere("status IN ('paid', 'confirmed') AND fk_class IS NULL AND session_start > '".$this->db->idate($now)."'") as $b) {
			$day = self::fmt($this->db->jdate($b->session_start), 'Y-m-d');
			if (isset($closed[$day])) {
				$this->loseDate($b, 'closed', $closed[$day] !== '' ? $closed[$day] : 'The shop is closed that day.');
				$n++;
			}
		}
		return $n;
	}

	// ---------------------------------------------------------------- catalog and the website

	/**
	 * Note a change to the catalog and send the website its new copy.
	 *
	 * @param string $note What changed
	 * @param User|null $by Who did it
	 * @return void
	 */
	public function changed($note, $by)
	{
		global $conf;
		$this->db->query("INSERT INTO ".$this->db->prefix()."onboarding_catalog_log (entity, note, fk_user, datec) VALUES (".((int) $conf->entity).", '".$this->db->escape(dol_trunc($note, 255, 'right', 'UTF-8', 1))."', ".($by && $by->id ? (int) $by->id : 'NULL').", '".$this->db->idate(OnboardingService::now())."')");
		$this->pushCatalog();
	}

	/**
	 * @return int Catalog version: the number of changes so far
	 */
	public function version()
	{
		global $conf;
		$res = $this->db->query("SELECT MAX(rowid) AS v FROM ".$this->db->prefix()."onboarding_catalog_log WHERE entity = ".((int) $conf->entity));
		return $res ? (int) $this->db->fetch_object($res)->v : 0;
	}

	/**
	 * @param int $limit Rows
	 * @return object[] Latest catalog changes
	 */
	public function changes($limit = 20)
	{
		global $conf;
		$out = array();
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT l.*, u.login FROM ".$p."onboarding_catalog_log AS l LEFT JOIN ".$p."user AS u ON u.rowid = l.fk_user WHERE l.entity = ".((int) $conf->entity)." ORDER BY l.rowid DESC LIMIT ".((int) $limit));
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * What the website shows: tools, prices, trainers' first names and weekly
	 * times, closed days. Nothing private.
	 *
	 * @return array<string,mixed>
	 */
	public function catalog()
	{
		$tools = array();
		$zones = array();
		$now = OnboardingService::now();
		foreach ($this->tools(true) as $t) {
			$zones[$t->zone] = true;
			$slots = array();
			foreach ($this->slots((int) $t->rowid) as $s) {
				if ((int) $s->statut != Adherent::STATUS_VALIDATED) {
					continue;
				}
				$slots[] = array(
					'id' => (int) $s->rowid,
					'trainer' => (string) $s->firstname,
					'weekday' => (int) $s->weekday,
					'day' => self::WEEKDAYS[(int) $s->weekday],
					'time' => (string) $s->start_time,
					'label' => self::WEEKDAYS[(int) $s->weekday].'s at '.self::fmt(self::at(self::fmt($now, 'Y-m-d'), $s->start_time), 'g:i A'),
					'minutes' => (int) ($s->minutes ?: $t->minutes),
					'seats' => (int) ($s->seats ?: $t->seats),
				);
			}
			$tools[] = array(
				'id' => (string) $t->ref,
				'name' => (string) $t->label,
				'zone' => (string) $t->zone,
				'fee' => (float) $t->fee,
				'member_fee' => self::memberPrice($t),
				'seats' => (int) $t->seats,
				'minutes' => (int) $t->minutes,
				'covers' => (string) $t->covers,
				'wiki' => (string) $t->wiki_url,
				'page' => (string) $t->page_url,
				'bookable' => (bool) $t->bookable && count($slots) > 0,
				'slots' => $slots,
			);
		}
		$closed = array();
		foreach ($this->upcomingClosed() as $c) {
			$closed[] = array('day' => substr((string) $c->day, 0, 10), 'reason' => (string) $c->reason);
		}
		return array(
			'version' => $this->version(),
			'generated' => gmdate('Y-m-d\TH:i:s\Z', $now),
			'timezone' => self::tz()->getName(),
			'zones' => array_keys($zones),
			'tools' => $tools,
			'closed' => $closed,
		);
	}

	/**
	 * The website fetched the catalog: remember which version it holds now.
	 *
	 * @param int $version Version the website reports after this fetch
	 * @return void
	 */
	public function websiteHas($version)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_set_const($this->db, 'ONBOARDING_WEBSITE_VERSION', (int) $version, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'ONBOARDING_WEBSITE_SEEN', OnboardingService::now(), 'chaine', 0, '', $conf->entity);
	}

	/**
	 * POST to the website Worker's sync endpoint.
	 *
	 * @param array<string,mixed> $payload catalog and/or lookups
	 * @return bool Accepted
	 */
	public function pushToWebsite($payload)
	{
		$base = rtrim(getDolGlobalString('ONBOARDING_WEBSITE_URL'), '/');
		$key = getDolGlobalString('ONBOARDING_API_KEY');
		if ($base === '' || $key === '') {
			return false;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
		$r = getURLContent($base.'/api/training/sync', 'POSTALREADYFORMATED', json_encode($payload), 1, array('Content-Type: application/json', 'X-Onboarding-Key: '.$key), array('http', 'https'), 2);
		$code = isset($r['http_code']) ? (int) $r['http_code'] : 0;
		$json = isset($r['content']) ? json_decode((string) $r['content'], true) : null;
		if ($code == 200 && is_array($json) && !empty($json['ok'])) {
			if (isset($json['version'])) {
				$this->websiteHas((int) $json['version']);
			}
			$this->svc->alertClear('website-push', 'The website accepts updates from Dolibarr again.');
			return true;
		}
		dol_syslog('Onboarding: website sync returned '.$code.' '.dol_trunc(isset($r['content']) ? (string) $r['content'] : (isset($r['curl_error_msg']) ? $r['curl_error_msg'] : ''), 200), LOG_WARNING);
		$this->svc->alert('website-push', 'Dolibarr cannot update the website', "Dolibarr tried to send the training catalog or a training record to the website and got HTTP ".$code." from ".$base."/api/training/sync.\n\nCheck that the website's DOLIBARR_API_KEY matches the \"Key for the website\" on the module setup page, and that the website address on that page is right. The website keeps its last copy and picks up changes every 15 minutes, so nothing is lost.");
		return false;
	}

	/**
	 * @return bool Catalog accepted by the website
	 */
	public function pushCatalog()
	{
		return $this->pushToWebsite(array('catalog' => $this->catalog()));
	}

	/**
	 * Send the website the masked lookup answer for these people, so "What am I
	 * trained on?" works while Dolibarr is down. The website stores it under a
	 * keyed hash of the email, never the email.
	 *
	 * @param string[] $emails Emails, empty for everyone trained
	 * @return int People sent
	 */
	public function pushLookups($emails = array())
	{
		global $conf;
		if (!$emails) {
			$res = $this->db->query("SELECT DISTINCT LOWER(email) AS e FROM ".$this->db->prefix()."onboarding_training WHERE entity = ".((int) $conf->entity)." AND email <> ''");
			while ($res && ($o = $this->db->fetch_object($res))) {
				$emails[] = (string) $o->e;
			}
		}
		$sent = 0;
		foreach (array_chunk(array_unique(array_filter($emails)), 100) as $chunk) {
			$rows = array();
			foreach ($chunk as $e) {
				$l = $this->lookup($e);
				$rows[] = array('email' => OnboardingService::cleanEmail($e), 'found' => $l['found'], 'email_masked' => $l['email'], 'name' => $l['name'], 'trainings' => $l['trainings']);
			}
			if ($this->pushToWebsite(array('lookups' => $rows))) {
				$sent += count($rows);
			}
		}
		return $sent;
	}

	// ---------------------------------------------------------------- availability

	/**
	 * The starts of a weekly slot between two moments.
	 *
	 * @param object $slot Slot
	 * @param int $from Timestamp
	 * @param int $to Timestamp
	 * @return int[]
	 */
	public static function occurrences($slot, $from, $to)
	{
		$out = array();
		$day = new DateTime('@'.((int) $from));
		$day->setTimezone(self::tz());
		$day->setTime(0, 0);
		for ($i = 0; $i < 800 && $day->getTimestamp() <= $to; $i++) {
			if ((int) $day->format('N') == (int) $slot->weekday) {
				$ts = self::at($day->format('Y-m-d'), $slot->start_time);
				if ($ts >= $from && $ts <= $to) {
					$out[] = $ts;
				}
			}
			$day->modify('+1 day');
		}
		return $out;
	}

	/**
	 * Seats taken on a trainer's session, and whether another tool has it.
	 *
	 * @param int $trainerId Trainer
	 * @param int $start Session start
	 * @param int $toolId Tool asked for
	 * @param int $exceptBooking A booking not to count (the one being moved)
	 * @return array{taken:int,other_tool:bool}
	 */
	public function seatsTaken($trainerId, $start, $toolId, $exceptBooking = 0)
	{
		global $conf;
		$now = OnboardingService::now();
		$sql = "SELECT fk_tool, COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_booking WHERE entity = ".((int) $conf->entity)." AND fk_trainer = ".((int) $trainerId)." AND session_start = '".$this->db->idate($start)."'";
		$sql .= " AND (status IN ('paid', 'confirmed') OR (status = 'held' AND hold_until > '".$this->db->idate($now)."'))";
		if ($exceptBooking) {
			$sql .= " AND rowid <> ".((int) $exceptBooking);
		}
		$res = $this->db->query($sql." GROUP BY fk_tool");
		$out = array('taken' => 0, 'other_tool' => false);
		while ($res && ($o = $this->db->fetch_object($res))) {
			if ((int) $o->fk_tool == (int) $toolId) {
				$out['taken'] += (int) $o->n;
			} elseif ((int) $o->n > 0) {
				$out['other_tool'] = true;
			}
		}
		return $out;
	}

	/**
	 * Sessions that can be booked: each trainer's weekly time from the booking
	 * cutoff to the booking horizon, less closed days, full sessions and
	 * sessions the trainer already gives on another tool.
	 *
	 * @param int $toolId Tool, 0 for all
	 * @param int $exceptBooking Booking being moved (its own seat is free)
	 * @return array<int,array<string,mixed>>
	 */
	public function available($toolId = 0, $exceptBooking = 0)
	{
		$now = OnboardingService::now();
		$from = $now + (int) round(self::num('TRAINING_CLOSE_HOURS', 48) * 3600);
		$to = $now + (int) round(self::num('TRAINING_WEEKS_AHEAD', 6) * 7 * 86400);
		$closed = $this->closedDays(self::fmt($from, 'Y-m-d'), self::fmt($to, 'Y-m-d'));
		$out = array();
		foreach ($this->tools(true) as $tool) {
			if (($toolId && (int) $tool->rowid != (int) $toolId) || !$tool->bookable) {
				continue;
			}
			foreach ($this->slots((int) $tool->rowid) as $slot) {
				if ((int) $slot->statut != Adherent::STATUS_VALIDATED) {
					continue;
				}
				$seats = (int) ($slot->seats ?: $tool->seats);
				foreach (self::occurrences($slot, $from, $to) as $start) {
					if (isset($closed[self::fmt($start, 'Y-m-d')])) {
						continue;
					}
					$t = $this->seatsTaken((int) $slot->fk_adherent, $start, (int) $tool->rowid, $exceptBooking);
					if ($t['other_tool'] || $t['taken'] >= $seats) {
						continue;
					}
					$out[] = array(
						'tool' => (string) $tool->ref,
						'tool_name' => (string) $tool->label,
						'slot' => (int) $slot->rowid,
						'trainer' => (string) $slot->firstname,
						'start' => gmdate('Y-m-d\TH:i:s\Z', $start),
						'ts' => $start,
						'label' => self::fmt($start),
						'minutes' => (int) ($slot->minutes ?: $tool->minutes),
						'seats_left' => $seats - $t['taken'],
						'seats' => $seats,
					);
				}
			}
		}
		usort($out, function ($a, $b) {
			return $a['ts'] - $b['ts'];
		});
		return $out;
	}

	/**
	 * @param int $slotId Slot
	 * @param int $start Start asked for
	 * @param int $toolId Tool
	 * @param int $exceptBooking Booking being moved
	 * @return array<string,mixed>|null That session, if it can still be booked
	 */
	public function findAvailable($slotId, $start, $toolId, $exceptBooking = 0)
	{
		foreach ($this->available($toolId, $exceptBooking) as $a) {
			if ($a['slot'] == (int) $slotId && $a['ts'] == (int) $start) {
				return $a;
			}
		}
		return null;
	}

	// ---------------------------------------------------------------- people

	/**
	 * @param string $email Email
	 * @return object|null Member row with dues and paperwork fields
	 */
	public function memberRow($email)
	{
		$email = OnboardingService::cleanEmail($email);
		if ($email === '') {
			return null;
		}
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT a.rowid, a.firstname, a.lastname, a.email, a.statut, a.datefin, a.birth, e.dues_waived_until, e.waiver_date, e.agreement_date FROM ".$p."adherent AS a LEFT JOIN ".$p."adherent_extrafields AS e ON e.fk_object = a.rowid WHERE LOWER(a.email) = '".$this->db->escape($email)."' AND a.entity IN (".getEntity('adherent').") AND a.statut <> ".((int) Adherent::STATUS_EXCLUDED)." ORDER BY a.statut DESC, a.rowid ASC LIMIT 1");
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * A current member, who gets the member price: a validated member card.
	 * Dues are not checked here: members paying by hand or imported without a
	 * paid-until date are members too, and Dolibarr terminates a member card
	 * when dues lapse past the grace period.
	 *
	 * @param object|null $m Member row
	 * @return bool
	 */
	public function isPaying($m)
	{
		return $m && (int) $m->statut == Adherent::STATUS_VALIDATED;
	}

	/**
	 * Who an email belongs to, for the booking page.
	 *
	 * @param string $email Email
	 * @return array{kind:string,member:object|null,contact:int,applicant:object|null,firstname:string,lastname:string,waiver:bool,agreement:bool,needs:string[]}
	 */
	public function person($email)
	{
		$email = OnboardingService::cleanEmail($email);
		$m = $this->memberRow($email);
		$app = $this->svc->findByEmail($email);
		$contact = $this->svc->contactByEmail($email);
		$paying = $this->isPaying($m);
		$kind = $paying ? 'member' : ($m || $app || $contact ? 'nonmember' : 'new');

		$waiver = $app && !empty($app->waiver_signed_at);
		$agreement = $app && !empty($app->agreement_signed_at);
		if ($m) {
			$waiver = $waiver || !empty($m->waiver_date);
			$agreement = $agreement || !empty($m->agreement_date);
		}
		if ((!$waiver || !$agreement) && $contact) {
			$c = new Contact($this->db);
			if ($c->fetch($contact) > 0) {
				$waiver = $waiver || !empty($c->array_options['options_waiver_date']);
				$agreement = $agreement || !empty($c->array_options['options_agreement_date']);
			}
		}
		$needs = array();
		if (!$waiver) {
			$needs[] = 'waiver';
		}
		if ($kind == 'member' && !$agreement) {
			$needs[] = 'agreement';
		}
		$first = $m ? (string) $m->firstname : ($app ? (string) $app->firstname : '');
		$last = $m ? (string) $m->lastname : ($app ? (string) $app->lastname : '');
		if ($first === '' && $last === '' && $contact) {
			$c = new Contact($this->db);
			if ($c->fetch($contact) > 0) {
				$first = (string) $c->firstname;
				$last = (string) $c->lastname;
			}
		}
		return array('kind' => $kind, 'member' => $m, 'contact' => $contact, 'applicant' => $app, 'firstname' => $first, 'lastname' => $last, 'waiver' => $waiver, 'agreement' => $agreement, 'needs' => $needs, 'email' => $email);
	}

	/**
	 * The booking page's first question: who is this, and what must they sign.
	 * Says nothing else about them.
	 *
	 * @param string $email Email
	 * @return array<string,mixed>
	 */
	public function trainee($email)
	{
		$email = OnboardingService::cleanEmail($email);
		if (!isValidEmail($email)) {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		$p = $this->person($email);
		return array('ok' => true, 'kind' => $p['kind'], 'needs' => $p['needs']);
	}

	/**
	 * Someone new books a training: they become a non-member (a contact and a
	 * draft member card, marked training only). Someone already known is left
	 * as they are.
	 *
	 * @param array<string,mixed> $in email, firstname, lastname, discord, notify_events, notify_news, ip
	 * @param string $note How they arrived, for the cards
	 * @return array<string,mixed>
	 */
	public function newTrainee($in, $note = 'Created by the website training booking.')
	{
		global $conf;
		$email = OnboardingService::cleanEmail(isset($in['email']) ? $in['email'] : '');
		$first = dol_trunc(trim(dol_string_nohtmltag((string) (isset($in['firstname']) ? $in['firstname'] : ''))), 50, 'right', 'UTF-8', 1);
		$last = dol_trunc(trim(dol_string_nohtmltag((string) (isset($in['lastname']) ? $in['lastname'] : ''))), 50, 'right', 'UTF-8', 1);
		if (!isValidEmail($email) || $first === '' || $last === '') {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		$p = $this->person($email);
		if ($p['kind'] == 'new') {
			$contact = new Contact($this->db);
			$contact->firstname = $first;
			$contact->lastname = $last;
			$contact->email = $email;
			$contact->statut = 1;
			$contact->status = 1;
			$contact->note_private = $note;
			$cid = $contact->create($this->svc->actor());
			if ($cid <= 0) {
				dol_syslog('Onboarding: contact create failed: '.$contact->error, LOG_ERR);
				return array('ok' => false, 'error' => 'server', 'http' => 500);
			}
			$discord = trim(dol_string_nohtmltag((string) (isset($in['discord']) ? $in['discord'] : '')));
			$sql = "INSERT INTO ".$this->db->prefix()."onboarding_applicant (entity, email, firstname, lastname, discord, notify_events, notify_news, fk_socpeople, ip, training_only, datec) VALUES (";
			$sql .= ((int) $conf->entity).", '".$this->db->escape($email)."', '".$this->db->escape($first)."', '".$this->db->escape($last)."', '".$this->db->escape(substr($discord, 0, 100))."', ";
			$sql .= (empty($in['notify_events']) ? 0 : 1).", ".(empty($in['notify_news']) ? 0 : 1).", ".((int) $cid).", '".$this->db->escape(substr((string) (isset($in['ip']) ? $in['ip'] : ''), 0, 64))."', 1, '".$this->db->idate(OnboardingService::now())."')";
			if (!$this->db->query($sql)) {
				return array('ok' => false, 'error' => 'server', 'http' => 500);
			}
			$app = $this->svc->fetch((int) $this->db->last_insert_id($this->db->prefix().'onboarding_applicant'));
			$this->svc->ensureMember($app, 0, 0, $note);
			$this->svc->syncExtraFields($this->svc->fetch((int) $app->rowid));
			if (!empty($in['notify_events']) || !empty($in['notify_news'])) {
				$c = new Contact($this->db);
				if ($c->fetch((int) $cid) > 0) {
					$this->svc->tagForUpdates($c, false);
				}
			}
			$p = $this->person($email);
		}
		return array('ok' => true, 'kind' => $p['kind'], 'needs' => $p['needs']);
	}

	/**
	 * The applicant row that holds someone's signatures. Members added by the
	 * import scripts and plain contacts may not have one yet.
	 *
	 * @param array $p From person()
	 * @return object|null
	 */
	public function applicantFor($p)
	{
		global $conf;
		if ($p['applicant']) {
			return $p['applicant'];
		}
		if (!$p['member'] && !$p['contact']) {
			return null;
		}
		$sql = "INSERT INTO ".$this->db->prefix()."onboarding_applicant (entity, email, firstname, lastname, fk_socpeople, fk_adherent, training_only, stage, datec) VALUES (";
		$sql .= ((int) $conf->entity).", '".$this->db->escape($p['email'])."', '".$this->db->escape(dol_trunc($p['firstname'], 100, 'right', 'UTF-8', 1))."', '".$this->db->escape(dol_trunc($p['lastname'], 100, 'right', 'UTF-8', 1))."', ";
		$sql .= ($p['contact'] ? (int) $p['contact'] : 'NULL').", ".($p['member'] ? (int) $p['member']->rowid : 'NULL').", 1, '".($p['member'] && (int) $p['member']->statut == 1 ? 'complete' : 'details')."', '".$this->db->idate(OnboardingService::now())."')";
		if (!$this->db->query($sql)) {
			return null;
		}
		return $this->svc->fetch((int) $this->db->last_insert_id($this->db->prefix().'onboarding_applicant'));
	}

	/**
	 * Email the signing link: the booking page, opened on the signing step.
	 * Sent to the inbox, never shown in the browser, so only the owner of the
	 * address can sign for it.
	 *
	 * @param string $email Email
	 * @return array<string,mixed>
	 */
	public function sendSignLink($email)
	{
		$p = $this->person($email);
		if ($p['kind'] == 'new') {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		if (!$p['needs']) {
			return array('ok' => true, 'needs' => array());
		}
		$app = $this->applicantFor($p);
		if (!$app) {
			return array('ok' => false, 'error' => 'server', 'http' => 500);
		}
		$token = $this->svc->issueToken($app, false);
		$this->mailTpl('sign', $p['email'], array(
			'firstname' => $p['firstname'] !== '' ? $p['firstname'] : 'there',
			'what' => in_array('agreement', $p['needs']) ? (in_array('waiver', $p['needs']) ? 'the liability waiver and the membership agreement' : 'the membership agreement') : 'the liability waiver',
			'link' => $this->bookUrl().'#s='.$token,
		));
		return array('ok' => true, 'needs' => $p['needs'], 'sent' => true);
	}

	/**
	 * @return string The website's booking page
	 */
	public function bookUrl()
	{
		$url = getDolGlobalString('ONBOARDING_TRAINING_BOOK_URL');
		if ($url === '') {
			$base = rtrim(getDolGlobalString('ONBOARDING_WEBSITE_URL', 'https://columbiagadgetworks.org'), '/');
			$url = $base.'/training/book/';
		}
		return $url;
	}

	// ---------------------------------------------------------------- bookings

	/**
	 * @param string $where SQL condition on llx_onboarding_booking
	 * @param string $order ORDER BY
	 * @return object[] Bookings with their tool and trainer
	 */
	public function bookingsWhere($where, $order = 'b.session_start ASC, b.rowid ASC')
	{
		global $conf;
		$p = $this->db->prefix();
		$sql = "SELECT b.*, t.ref AS tool_ref, t.label AS tool, t.zone, t.wiki_url, t.trainer_share, a.firstname AS trainer_first, a.lastname AS trainer_last, a.email AS trainer_email";
		$sql .= " FROM ".$p."onboarding_booking AS b LEFT JOIN ".$p."onboarding_tool AS t ON t.rowid = b.fk_tool LEFT JOIN ".$p."adherent AS a ON a.rowid = b.fk_trainer";
		$sql .= " WHERE b.entity = ".((int) $conf->entity)." AND (".preg_replace('/\b(status|session_start|fk_class|fk_trainer|fk_tool|email|rowid|answer|hold_until|paid_at|ref|fk_adherent|gb_transaction_id|datec|source)\b/', 'b.$1', $where).") ORDER BY ".$order;
		$res = $this->db->query($sql);
		$out = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param int $id Booking
	 * @return object|null
	 */
	public function booking($id)
	{
		$rows = $this->bookingsWhere("rowid = ".((int) $id));
		return $rows ? $rows[0] : null;
	}

	/**
	 * @param int $id Booking
	 * @param array<string,mixed> $fields Column => value; null clears, an int/float is written bare
	 * @param string $onlyIf Extra condition, for claiming a state change once
	 * @return bool Changed
	 */
	public function saveBooking($id, $fields, $onlyIf = '')
	{
		$set = array();
		foreach ($fields as $col => $val) {
			if (!preg_match('/^[a-z_]+$/', $col)) {
				continue;
			}
			if ($val === null) {
				$set[] = $col.' = NULL';
			} elseif (is_int($val) || is_float($val)) {
				$set[] = $col.' = '.price2num($val);
			} else {
				$set[] = $col." = '".$this->db->escape((string) $val)."'";
			}
		}
		$res = $this->db->query("UPDATE ".$this->db->prefix()."onboarding_booking SET ".implode(', ', $set)." WHERE rowid = ".((int) $id).($onlyIf !== '' ? " AND (".$onlyIf.")" : ''));
		return $res && $this->db->affected_rows($res) > 0;
	}

	/**
	 * @param object $b Booking
	 * @param string $list trainer_sent or trainee_sent
	 * @param string $name Email name
	 * @return bool That email went out for the current date already
	 */
	public static function sent($b, $list, $name)
	{
		return in_array($name, array_filter(explode(',', (string) $b->$list), 'strlen'), true);
	}

	/**
	 * Remember that an email went out, so it never goes twice.
	 *
	 * @param object $b Booking (updated in place)
	 * @param string $list trainer_sent or trainee_sent
	 * @param string $name Email name
	 * @return bool This call claimed it (false: another run already did)
	 */
	public function markSent($b, $list, $name)
	{
		$old = (string) $b->$list;
		$new = implode(',', array_merge(array_filter(explode(',', $old), 'strlen'), array($name)));
		$ok = $this->saveBooking((int) $b->rowid, array($list => $new), $list.($old === '' ? " IS NULL OR ".$list." = ''" : " = '".$this->db->escape($old)."'"));
		if ($ok) {
			$b->$list = $new;
		}
		return $ok;
	}

	/**
	 * Hold a seat while the trainee pays.
	 *
	 * @param array<string,mixed> $in email, tool (ref), slot, start (ISO 8601), ip
	 * @return array<string,mixed>
	 */
	public function book($in)
	{
		global $conf;
		$email = OnboardingService::cleanEmail(isset($in['email']) ? $in['email'] : '');
		$tool = $this->toolByRef(isset($in['tool']) ? (string) $in['tool'] : '');
		$start = isset($in['start']) ? strtotime((string) $in['start']) : 0;
		if (!isValidEmail($email) || !$tool || !$tool->active || !$start) {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		$p = $this->person($email);
		if ($p['kind'] == 'new') {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		if ($p['needs']) {
			return array('ok' => false, 'error' => 'paperwork', 'needs' => $p['needs'], 'http' => 409);
		}
		$open = $this->bookingsWhere("email = '".$this->db->escape($email)."' AND fk_tool = ".((int) $tool->rowid)." AND (status IN ('paid', 'confirmed', 'needs_new_date', 'other') OR (status = 'held' AND hold_until > '".$this->db->idate(OnboardingService::now())."'))");
		if ($open) {
			return array('ok' => false, 'error' => 'already', 'ref' => $open[0]->ref, 'http' => 409);
		}
		$slot = $this->slot(isset($in['slot']) ? (int) $in['slot'] : 0);
		$session = $slot && (int) $slot->fk_tool == (int) $tool->rowid ? $this->findAvailable((int) $slot->rowid, $start, (int) $tool->rowid) : null;
		if (!$session) {
			return array('ok' => false, 'error' => 'full', 'http' => 409);
		}
		$member = $p['kind'] == 'member';
		$price = $member ? self::memberPrice($tool) : round((float) $tool->fee, 2);
		$app = $p['applicant'];
		$now = OnboardingService::now();
		$hold = $now + (int) round(self::num('TRAINING_HOLD_MINUTES', 30) * 60);
		$token = bin2hex(random_bytes(24));
		$table = $this->db->prefix().'onboarding_booking';
		$sql = "INSERT INTO ".$table." (entity, token, fk_tool, fk_slot, fk_trainer, fk_adherent, fk_socpeople, fk_applicant, email, firstname, lastname, session_start, minutes, price, price_type, status, hold_until, source, ip, datec) VALUES (";
		$sql .= ((int) $conf->entity).", '".$token."', ".((int) $tool->rowid).", ".((int) $slot->rowid).", ".((int) $slot->fk_adherent).", ";
		$sql .= ($p['member'] ? (int) $p['member']->rowid : 'NULL').", ".($p['contact'] ? (int) $p['contact'] : 'NULL').", ".($app ? (int) $app->rowid : 'NULL').", ";
		$sql .= "'".$this->db->escape($email)."', '".$this->db->escape(dol_trunc($p['firstname'], 100, 'right', 'UTF-8', 1))."', '".$this->db->escape(dol_trunc($p['lastname'], 100, 'right', 'UTF-8', 1))."', ";
		$sql .= "'".$this->db->idate($start)."', ".((int) $session['minutes']).", ".price2num($price).", '".($member ? 'member' : 'nonmember')."', ";
		$sql .= ($price > 0 ? "'held', '".$this->db->idate($hold)."'" : "'paid', NULL").", 'web', '".$this->db->escape(substr((string) (isset($in['ip']) ? $in['ip'] : ''), 0, 64))."', '".$this->db->idate($now)."')";
		if (!$this->db->query($sql)) {
			return array('ok' => false, 'error' => 'server', 'http' => 500);
		}
		$id = (int) $this->db->last_insert_id($table);
		$ref = 'TR-'.sprintf('%04d', $id);
		$this->saveBooking($id, array('ref' => $ref));
		// Two people pressing at the same moment for the last seat: the later one lets go.
		$t = $this->seatsTaken((int) $slot->fk_adherent, $start, (int) $tool->rowid);
		if ($t['other_tool'] || $t['taken'] > $session['seats']) {
			$first = $this->bookingsWhere("fk_trainer = ".((int) $slot->fk_adherent)." AND session_start = '".$this->db->idate($start)."' AND (status IN ('paid', 'confirmed') OR (status = 'held' AND hold_until > '".$this->db->idate($now)."'))", 'b.rowid ASC');
			$keep = array_slice(array_map(function ($o) {
				return (int) $o->rowid;
			}, $first), 0, $session['seats']);
			if ($t['other_tool'] || !in_array($id, $keep, true)) {
				$this->saveBooking($id, array('status' => 'expired'));
				return array('ok' => false, 'error' => 'full', 'http' => 409);
			}
		}
		if ($price <= 0) {
			$this->onPaid($this->booking($id));
		}
		return array(
			'ok' => true, 'ref' => $ref, 'key' => $token, 'amount' => $price, 'price_type' => $member ? 'member' : 'nonmember',
			'tool' => $tool->label, 'zone' => $tool->zone, 'trainer' => (string) $slot->firstname, 'when' => self::fmt($start),
			'start' => gmdate('Y-m-d\TH:i:s\Z', $start), 'hold_until' => gmdate('Y-m-d\TH:i:s\Z', $hold),
			'firstname' => $p['firstname'], 'lastname' => $p['lastname'], 'email' => $email,
		);
	}

	/**
	 * For the booking page, polling after payment.
	 *
	 * @param string $ref Booking ref
	 * @param string $key The key the page got from book()
	 * @return array<string,mixed>
	 */
	public function status($ref, $key)
	{
		$rows = $this->bookingsWhere("ref = '".$this->db->escape((string) $ref)."'");
		if (!$rows || !hash_equals((string) $rows[0]->token, (string) $key)) {
			return array('ok' => false, 'error' => 'invalid', 'http' => 404);
		}
		$b = $rows[0];
		// The trainee is waiting on the page: if the webhook has not brought the
		// payment yet, ask Givebutter (at most every 20 seconds, for everyone).
		if (in_array($b->status, array('held', 'expired'), true) && $this->svc->quickSync()) {
			$b = $this->booking((int) $b->rowid);
		}
		return array('ok' => true, 'status' => (string) $b->status, 'paid' => in_array($b->status, array('paid', 'confirmed', 'trained'), true));
	}

	/**
	 * A training-campaign payment: find the booking it pays for. Same email,
	 * waiting for payment (or let go of in the last day), price equal to what
	 * was given.
	 *
	 * @param array $t TransactionResource
	 * @return string What was done, '' when no booking matched
	 */
	public function matchPayment($t)
	{
		$txid = isset($t['id']) ? (string) $t['id'] : '';
		$email = OnboardingService::cleanEmail(isset($t['email']) ? $t['email'] : '');
		$given = isset($t['donated']) && is_numeric($t['donated']) && (float) $t['donated'] > 0 ? (float) $t['donated'] : (float) (isset($t['amount']) ? $t['amount'] : 0);
		if ($txid === '' || $email === '') {
			return '';
		}
		$since = $this->db->idate(OnboardingService::now() - 86400);
		$rows = $this->bookingsWhere("email = '".$this->db->escape($email)."' AND gb_transaction_id IS NULL AND ABS(price - ".price2num($given).") < 0.005 AND (status = 'held' OR (status = 'expired' AND hold_until >= '".$since."'))", 'b.rowid DESC');
		foreach ($rows as $b) {
			$r = $this->applyPayment($b, $t);
			if ($r !== '') {
				return $r;
			}
		}
		return '';
	}

	/**
	 * Mark a booking paid by a transaction.
	 *
	 * @param object $b Booking
	 * @param array $t TransactionResource (id, amount, fee)
	 * @return string What was done, '' when another payment got there first
	 */
	public function applyPayment($b, $t)
	{
		$amount = (float) (isset($t['amount']) ? $t['amount'] : $b->price);
		$fee = isset($t['fee']) && is_numeric($t['fee']) ? (float) $t['fee'] : 0;
		$net = round(max(0, $amount - $fee), 2);
		$when = !empty($t['transacted_at']) ? strtotime((string) $t['transacted_at']) : 0;
		$claimed = $this->saveBooking((int) $b->rowid, array(
			'gb_transaction_id' => (string) $t['id'],
			'amount_paid' => $amount,
			'amount_net' => $net,
			'paid_at' => $this->db->idate($when ? $when : OnboardingService::now()),
			'status' => 'paid',
		), "gb_transaction_id IS NULL AND status IN ('held', 'expired')");
		if (!$claimed) {
			return '';
		}
		$b = $this->booking((int) $b->rowid);
		if ($b->source == 'spot') {
			$this->onPaid($b);
			return 'booking '.$b->ref.' paid (on the spot)';
		}
		// Let go of while paying, and the seat has gone since: keep the money, ask for a new date.
		$seats = $this->seatsFor($b);
		$t2 = $this->seatsTaken((int) $b->fk_trainer, $this->db->jdate($b->session_start), (int) $b->fk_tool);
		if ($t2['other_tool'] || $t2['taken'] > $seats) {
			$this->loseDate($b, 'full', 'The session filled up while the payment was on its way.');
			return 'booking '.$b->ref.' paid, but its session filled up; asked for a new date';
		}
		$this->onPaid($b);
		return 'booking '.$b->ref.' paid';
	}

	/**
	 * @param object $b Booking
	 * @return int Seats on its session
	 */
	public function seatsFor($b)
	{
		$slot = $b->fk_slot ? $this->slot((int) $b->fk_slot) : null;
		$tool = $this->tool((int) $b->fk_tool);
		return (int) ($slot && $slot->seats ? $slot->seats : ($tool ? $tool->seats : 1));
	}

	/**
	 * A booking is paid (or moved to a new date): tell the trainee, and ask the
	 * trainer to confirm, unless they already confirmed this session.
	 *
	 * @param object $b Booking
	 * @param bool $moved It was moved, not newly paid
	 * @return void
	 */
	public function onPaid($b, $moved = false)
	{
		$b = $this->booking((int) $b->rowid);
		if ($b->source == 'spot' && !$moved) {
			$this->spotPaid($b);
			return;
		}
		$start = $this->db->jdate($b->session_start);
		$this->mailTpl($moved ? 'moved' : 'booked', $b->email, $this->vars($b), '', '', (string) $b->rowid);
		$already = $this->bookingsWhere("fk_trainer = ".((int) $b->fk_trainer)." AND session_start = '".$this->db->idate($start)."' AND status = 'confirmed' AND rowid <> ".((int) $b->rowid));
		if ($already) {
			$this->saveBooking((int) $b->rowid, array('status' => 'confirmed', 'confirmed_at' => $this->db->idate(OnboardingService::now())), "status = 'paid'");
			$this->mailTpl('trainer_added', $b->trainer_email, $this->vars($b, 'trainer'));
			return;
		}
		if ($this->markSent($b, 'trainer_sent', 'request')) {
			$this->mailTpl('trainer_request', $b->trainer_email, $this->vars($b, 'trainer'));
		}
	}

	// ---------------------------------------------------------------- on the spot

	/**
	 * The tools a member may train someone on right now: a current member with a
	 * time on the tool (the "Trainer: <tool>" tag) who is trained on it themselves.
	 *
	 * @param object|null $m Member row (memberRow())
	 * @return object[] Tools
	 */
	public function spotTools($m)
	{
		if (!$this->isPaying($m)) {
			return array();
		}
		$out = array();
		foreach ($this->slots(0, true) as $s) {
			if ((int) $s->fk_adherent != (int) $m->rowid || isset($out[(int) $s->fk_tool])) {
				continue;
			}
			$tool = $this->tool((int) $s->fk_tool);
			if ($tool && $tool->active && $this->isTrainedOn((int) $m->rowid, $tool)) {
				$out[(int) $tool->rowid] = $tool;
			}
		}
		return array_values($out);
	}

	/**
	 * @param int $memberId Trainer
	 * @param int $until When the link stops working
	 * @return string The trainer's link to start an on-the-spot training
	 */
	public function spotLink($memberId, $until)
	{
		return $this->linkBase().'?t='.((int) $memberId).'&x='.((int) $until).'&k='.$this->sign('spot:'.((int) $memberId).':'.((int) $until));
	}

	/**
	 * @param int $memberId Trainer, from the link
	 * @param int $until Expiry, from the link
	 * @param string $key Signature, from the link
	 * @return bool
	 */
	public function spotLinkValid($memberId, $until, $key)
	{
		return $memberId > 0 && $until >= OnboardingService::now() && hash_equals($this->sign('spot:'.((int) $memberId).':'.((int) $until)), (string) $key);
	}

	/**
	 * "I'm a trainer: train someone now" on the website: email the trainer their
	 * link, if the address belongs to someone who may train on at least one tool.
	 * The answer is the same either way, so the page tells nobody who is a trainer.
	 *
	 * @param string $email What was typed
	 * @return array<string,mixed>
	 */
	public function spotStart($email)
	{
		global $conf;
		$email = OnboardingService::cleanEmail($email);
		if (!isValidEmail($email)) {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		$m = $this->memberRow($email);
		$tools = $this->spotTools($m);
		if ($tools) {
			// At most one link a minute per trainer, whatever the website lets through.
			$last = 'ONBOARDING_SPOT_LINK_'.((int) $m->rowid);
			$now = OnboardingService::now();
			if ($now - getDolGlobalInt($last) >= 60) {
				require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
				dolibarr_set_const($this->db, $last, $now, 'chaine', 0, 'Last on-the-spot training link sent', $conf->entity);
				$until = $now + self::SPOT_LINK_MINUTES * 60;
				$this->mailTpl('spot_link', $email, array(
					'trainer' => (string) $m->firstname,
					'tools' => implode(', ', array_map(function ($t) {
						return $t->label;
					}, $tools)),
					'link' => $this->spotLink((int) $m->rowid, $until),
					'until' => self::fmt($until, 'g:i A'),
					'hours' => (string) self::SPOT_HOURS,
				));
			}
		}
		return array('ok' => true);
	}

	/**
	 * The trainer pressed "Send the request": check it, make the booking, and
	 * email the trainee to say yes or no.
	 *
	 * @param int $memberId Trainer (from their signed link)
	 * @param int $toolId Tool
	 * @param string $email Trainee's email
	 * @return array{ok:bool,error?:string,ref?:string,expires?:int}
	 */
	public function spotRequest($memberId, $toolId, $email)
	{
		global $conf;
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT email FROM ".$p."adherent WHERE rowid = ".((int) $memberId));
		$o = $res ? $this->db->fetch_object($res) : null;
		$m = $o ? $this->memberRow((string) $o->email) : null;
		if ($m && (int) $m->rowid != (int) $memberId) {
			$m = null;
		}
		$tool = null;
		foreach ($this->spotTools($m) as $t) {
			if ((int) $t->rowid == (int) $toolId) {
				$tool = $t;
			}
		}
		if (!$tool) {
			return array('ok' => false, 'error' => 'You can only start a training on a tool you are a trainer for, and are trained on yourself.');
		}
		$email = OnboardingService::cleanEmail($email);
		if (!isValidEmail($email)) {
			return array('ok' => false, 'error' => 'Check the trainee\'s email address.');
		}
		if ($email === OnboardingService::cleanEmail((string) $m->email)) {
			return array('ok' => false, 'error' => 'You cannot train yourself. Type the trainee\'s own email address.');
		}
		$person = $this->person($email);
		if ($this->trainedEmail($email, $tool) || ($person['member'] && $this->isTrainedOn((int) $person['member']->rowid, $tool))) {
			return array('ok' => false, 'error' => 'That person is already marked as trained on the '.$tool->label.'. Nothing was sent.');
		}
		$now = OnboardingService::now();
		$nowSql = $this->db->idate($now);
		if ($this->bookingsWhere("email = '".$this->db->escape($email)."' AND fk_tool = ".((int) $tool->rowid)." AND (status IN ('paid', 'confirmed', 'needs_new_date', 'other') OR (status IN ('held', 'requested') AND hold_until > '".$nowSql."'))")) {
			return array('ok' => false, 'error' => 'That person already has a booking or a request open for the '.$tool->label.'. Nothing was sent.');
		}
		$open = $this->bookingsWhere("source = 'spot' AND fk_trainer = ".((int) $m->rowid)." AND status IN ('requested', 'held') AND hold_until > '".$nowSql."'");
		if (count($open) >= self::SPOT_MAX_OPEN) {
			return array('ok' => false, 'error' => 'You already have '.count($open).' requests waiting for the trainee. Wait for one to be paid or to expire, then try again.');
		}
		$member = $person['kind'] == 'member';
		$price = $member ? self::memberPrice($tool) : round((float) $tool->fee, 2);
		$until = $now + self::SPOT_HOURS * 3600;
		$token = bin2hex(random_bytes(24));
		$table = $p.'onboarding_booking';
		$sql = "INSERT INTO ".$table." (entity, token, fk_tool, fk_trainer, fk_adherent, fk_socpeople, fk_applicant, email, firstname, lastname, session_start, minutes, price, price_type, status, hold_until, source, datec) VALUES (";
		$sql .= ((int) $conf->entity).", '".$token."', ".((int) $tool->rowid).", ".((int) $m->rowid).", ";
		$sql .= ($person['member'] ? (int) $person['member']->rowid : 'NULL').", ".($person['contact'] ? (int) $person['contact'] : 'NULL').", ".($person['applicant'] ? (int) $person['applicant']->rowid : 'NULL').", ";
		$sql .= "'".$this->db->escape($email)."', '".$this->db->escape(dol_trunc($person['firstname'], 100, 'right', 'UTF-8', 1))."', '".$this->db->escape(dol_trunc($person['lastname'], 100, 'right', 'UTF-8', 1))."', ";
		$sql .= "'".$nowSql."', ".((int) $tool->minutes).", ".price2num($price).", '".($member ? 'member' : 'nonmember')."', 'requested', '".$this->db->idate($until)."', 'spot', '".$nowSql."')";
		if (!$this->db->query($sql)) {
			return array('ok' => false, 'error' => 'The request could not be saved. Try again in a minute.');
		}
		$id = (int) $this->db->last_insert_id($table);
		$ref = 'TR-'.sprintf('%04d', $id);
		$this->saveBooking($id, array('ref' => $ref));
		$b = $this->booking($id);
		$this->mailTpl('spot_request', $email, $this->vars($b));
		return array('ok' => true, 'ref' => $ref, 'expires' => $until);
	}

	/**
	 * The trainee answers the request: "No" cancels it and tells the secretary
	 * and the trainer; "Yes" prices it (member or not) and opens it for signing
	 * and paying on the website.
	 *
	 * @param object $b Booking
	 * @param bool $yes Their answer
	 * @return array{ok:bool,error?:string,url?:string}
	 */
	public function spotAnswer($b, $yes)
	{
		$now = OnboardingService::now();
		if ($b->status == 'held' && $yes) {
			return array('ok' => true, 'url' => $this->spotPayUrl($b));
		}
		if ($b->status != 'requested' || $this->db->jdate($b->hold_until) < $now) {
			return array('ok' => false, 'error' => 'This request is no longer open ('.self::STATUS_LABELS[$b->status == 'requested' ? 'expired' : $b->status].'). Ask your trainer to send a new one.');
		}
		if (!$yes) {
			if (!$this->saveBooking((int) $b->rowid, array('status' => 'cancelled', 'answer' => 'declined', 'answered_at' => $this->db->idate($now)), "status = 'requested'")) {
				return array('ok' => false, 'error' => 'Already answered.');
			}
			$this->mailTpl('staff_spot_declined', $this->staffEmails(), $this->vars($b, 'staff'));
			$this->mailTpl('spot_declined', $b->trainer_email, $this->vars($b, 'trainer'));
			return array('ok' => true);
		}
		if (!$this->saveBooking((int) $b->rowid, array('status' => 'held'), "status = 'requested'")) {
			return array('ok' => false, 'error' => 'Already answered.');
		}
		$this->spotPrice($this->booking((int) $b->rowid));
		return array('ok' => true, 'url' => $this->spotPayUrl($b));
	}

	/**
	 * @param object $b Booking
	 * @return string The website's booking page, opened on this request's signing and paying
	 */
	public function spotPayUrl($b)
	{
		return $this->bookUrl().'#spot='.rawurlencode((string) $b->ref).'.'.$b->token;
	}

	/**
	 * Bring an on-the-spot booking up to date with who the trainee is now: their
	 * name, member card and price (they may have just signed up or signed).
	 *
	 * @param object $b Booking
	 * @return array Person, from person()
	 */
	private function spotPrice($b)
	{
		$person = $this->person((string) $b->email);
		$tool = $this->tool((int) $b->fk_tool);
		$member = $person['kind'] == 'member';
		$this->saveBooking((int) $b->rowid, array(
			'price' => $member ? self::memberPrice($tool) : round((float) $tool->fee, 2),
			'price_type' => $member ? 'member' : 'nonmember',
			'fk_adherent' => $person['member'] ? (int) $person['member']->rowid : null,
			'fk_socpeople' => $person['contact'] ? (int) $person['contact'] : null,
			'fk_applicant' => $person['applicant'] ? (int) $person['applicant']->rowid : null,
			'firstname' => dol_trunc($person['firstname'], 100, 'right', 'UTF-8', 1),
			'lastname' => dol_trunc($person['lastname'], 100, 'right', 'UTF-8', 1),
		), "status = 'held' AND gb_transaction_id IS NULL");
		return $person;
	}

	/**
	 * @param string $ref Booking ref, from the page address
	 * @param string $key Its token, from the page address
	 * @return object|null The on-the-spot booking
	 */
	private function spotByKey($ref, $key)
	{
		$rows = $this->bookingsWhere("ref = '".$this->db->escape((string) $ref)."' AND source = 'spot'");
		return $rows && hash_equals((string) $rows[0]->token, (string) $key) ? $rows[0] : null;
	}

	/**
	 * For the website's booking page, opened from "Yes, that's me": what is
	 * left before paying (a name for someone new, signing) and what to pay.
	 *
	 * @param string $ref Booking ref
	 * @param string $key Its token
	 * @return array<string,mixed>
	 */
	public function spotInfo($ref, $key)
	{
		$b = $this->spotByKey($ref, $key);
		if (!$b) {
			return array('ok' => false, 'error' => 'invalid', 'http' => 404);
		}
		if (in_array($b->status, array('paid', 'confirmed', 'trained'), true)) {
			return array('ok' => true, 'status' => 'paid', 'tool' => (string) $b->tool, 'trainer' => (string) $b->trainer_first, 'ref' => (string) $b->ref);
		}
		$until = $this->db->jdate($b->hold_until);
		if ($b->status != 'held' || $until < OnboardingService::now()) {
			return array('ok' => false, 'error' => $b->status == 'requested' ? 'answer' : 'expired', 'http' => 410);
		}
		$person = $this->spotPrice($b);
		$b = $this->booking((int) $b->rowid);
		$out = array(
			'ok' => true, 'status' => 'held', 'ref' => (string) $b->ref, 'key' => (string) $b->token, 'email' => (string) $b->email,
			'kind' => $person['kind'], 'needs' => $person['needs'], 'firstname' => $person['firstname'], 'lastname' => $person['lastname'],
			'amount' => round((float) $b->price, 2), 'price_type' => (string) $b->price_type, 'tool' => (string) $b->tool, 'zone' => (string) $b->zone,
			'trainer' => (string) $b->trainer_first, 'when' => 'now', 'wiki' => (string) $b->wiki_url,
			'expires' => gmdate('Y-m-d\TH:i:s\Z', $until), 'expires_label' => self::fmt($until, 'g:i A'),
		);
		if ($person['kind'] != 'new' && $person['needs']) {
			$app = $this->applicantFor($person);
			$out['sign'] = $app ? $this->svc->issueToken($app, false) : '';
		}
		if ($person['kind'] != 'new' && !$person['needs'] && (float) $b->price <= 0 && $this->saveBooking((int) $b->rowid, array('status' => 'paid', 'paid_at' => $this->db->idate(OnboardingService::now())), "status = 'held'")) {
			$this->onPaid($this->booking((int) $b->rowid));
			$out['status'] = 'paid';
		}
		return $out;
	}

	/**
	 * Someone new, from the booking page: their name, then the same as spotInfo().
	 *
	 * @param array<string,mixed> $in ref, key, firstname, lastname, discord, notify_events, notify_news, ip
	 * @return array<string,mixed>
	 */
	public function spotNew($in)
	{
		$b = $this->spotByKey(isset($in['ref']) ? (string) $in['ref'] : '', isset($in['key']) ? (string) $in['key'] : '');
		if (!$b || $b->status != 'held') {
			return array('ok' => false, 'error' => 'invalid', 'http' => 404);
		}
		$r = $this->newTrainee(array('email' => (string) $b->email) + $in, 'Created by an on-the-spot training ('.$b->ref.').');
		if (empty($r['ok'])) {
			return $r;
		}
		return $this->spotInfo((string) $b->ref, (string) $b->token);
	}

	/**
	 * Paid: the trainee hears so, and the trainer gets the buttons to say it was done.
	 *
	 * @param object $b Booking
	 * @return void
	 */
	private function spotPaid($b)
	{
		$now = $this->db->idate(OnboardingService::now());
		if (!$this->saveBooking((int) $b->rowid, array('status' => 'confirmed', 'confirmed_at' => $now, 'session_start' => $now, 'trainer_sent' => 'after'), "status = 'paid'")) {
			return;
		}
		$b = $this->booking((int) $b->rowid);
		$this->mailTpl('spot_paid', $b->email, $this->vars($b));
		$this->mailTpl('spot_trainer_paid', $b->trainer_email, $this->vars($b, 'trainer'));
	}

	/**
	 * Requests nobody paid for in time: closed, and the trainer told.
	 *
	 * @param int $now Now
	 * @return int Requests closed
	 */
	private function expireSpot($now)
	{
		$n = 0;
		foreach ($this->bookingsWhere("source = 'spot' AND status IN ('requested', 'held') AND hold_until < '".$this->db->idate($now)."'") as $b) {
			if ($this->saveBooking((int) $b->rowid, array('status' => 'expired'), "status IN ('requested', 'held')")) {
				$this->mailTpl('spot_expired', $b->trainer_email, $this->vars($b, 'trainer'));
				$n++;
			}
		}
		return $n;
	}

	// ---------------------------------------------------------------- what happens to a booking

	/**
	 * The trainer will be there: confirms everyone paid on that session.
	 *
	 * @param object $b Booking
	 * @return int Bookings confirmed
	 */
	public function confirm($b)
	{
		$n = 0;
		foreach ($this->sessionOf($b, array('paid')) as $o) {
			if ($this->saveBooking((int) $o->rowid, array('status' => 'confirmed', 'confirmed_at' => $this->db->idate(OnboardingService::now())), "status = 'paid'")) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * @param object $b Booking
	 * @param string[] $statuses Statuses to include
	 * @return object[] Every booking on the same trainer's session
	 */
	public function sessionOf($b, $statuses)
	{
		$in = array();
		foreach ($statuses as $s) {
			$in[] = "'".$this->db->escape($s)."'";
		}
		if ($b->fk_class) {
			return $this->bookingsWhere("fk_class = ".((int) $b->fk_class)." AND status IN (".implode(',', $in).")");
		}
		return $this->bookingsWhere("fk_trainer = ".((int) $b->fk_trainer)." AND session_start = '".$this->db->idate($this->db->jdate($b->session_start))."' AND fk_tool = ".((int) $b->fk_tool)." AND status IN (".implode(',', $in).")");
	}

	/**
	 * The trainer can't make it: everyone on the session loses the date.
	 *
	 * @param object $b Booking
	 * @param string $why trainer or noanswer
	 * @return int Bookings affected
	 */
	public function trainerCant($b, $why = 'trainer')
	{
		$n = 0;
		foreach ($this->sessionOf($b, array('paid', 'confirmed')) as $o) {
			$this->loseDate($o, $why, '');
			$n++;
		}
		return $n;
	}

	/**
	 * A booking loses its date through no fault of the trainee. They get one
	 * email: reschedule (free), refund, or other.
	 *
	 * @param object $b Booking
	 * @param string $why trainer, noanswer, closed, full
	 * @param string $detail Extra words for the email
	 * @return void
	 */
	public function loseDate($b, $why, $detail)
	{
		if (!$this->saveBooking((int) $b->rowid, array('status' => 'needs_new_date', 'confirmed_at' => null, 'trainer_sent' => null), "status IN ('paid', 'confirmed', 'held', 'expired')")) {
			return;
		}
		$reasons = array(
			'trainer' => '{trainer} can no longer make it.',
			'noanswer' => '{trainer} did not confirm the session in time.',
			'closed' => 'The shop is closed that day.',
			'full' => 'The session filled up while your payment was on its way.',
			'spot' => '{trainer} says the training did not happen.',
		);
		$v = $this->vars($b);
		$v['reason'] = trim(strtr(isset($reasons[$why]) ? $reasons[$why] : '', array('{trainer}' => $v['trainer'])).' '.$detail);
		$this->mailTpl('lost_date', $b->email, $v, $why == 'trainer' || $why == 'noanswer' ? (string) $b->trainer_email : '', $why == 'trainer' ? (string) $b->trainer_email : '');
		if ($why == 'noanswer') {
			$this->mailTpl('staff_noanswer', $this->staffEmails(), $this->vars($b, 'staff'));
		}
	}

	/**
	 * Move a booking to another session.
	 *
	 * @param object $b Booking
	 * @param int $slotId Slot
	 * @param int $start Session start
	 * @return array{ok:bool,error?:string}
	 */
	public function move($b, $slotId, $start)
	{
		if (!in_array($b->status, self::MOVABLE, true)) {
			return array('ok' => false, 'error' => 'This booking can no longer be moved.');
		}
		$session = $this->findAvailable($slotId, $start, (int) $b->fk_tool, (int) $b->rowid);
		$slot = $this->slot($slotId);
		if (!$session || !$slot) {
			return array('ok' => false, 'error' => 'That time is no longer free. Pick another.');
		}
		$fields = array(
			'fk_slot' => (int) $slot->rowid,
			'fk_trainer' => (int) $slot->fk_adherent,
			'session_start' => $this->db->idate($start),
			'minutes' => (int) $session['minutes'],
			'status' => 'paid',
			'confirmed_at' => null,
			'trainer_sent' => null,
			'trainee_sent' => null,
			'answer' => null,
			'answered_at' => null,
		);
		$wasNoShow = $b->status == 'needs_new_date' && (int) $b->no_shows > 0 && (int) $b->rebooks < (int) $b->no_shows;
		if ($wasNoShow) {
			$fields['rebooks'] = (int) $b->rebooks + 1;
		}
		$oldTrainer = (string) $b->trainer_email;
		$wasSeated = in_array($b->status, array('paid', 'confirmed'), true);
		$oldWhen = $this->db->jdate($b->session_start);
		if (!$this->saveBooking((int) $b->rowid, $fields, "status = '".$this->db->escape($b->status)."'")) {
			return array('ok' => false, 'error' => 'This booking changed while the page was open. Open the link again.');
		}
		if ($wasSeated && $oldTrainer !== '') {
			$v = $this->vars($b, 'trainer');
			$v['when'] = self::fmt($oldWhen);
			$this->mailTpl('trainer_freed', $oldTrainer, $v);
		}
		$this->onPaid($this->booking((int) $b->rowid), true);
		return array('ok' => true);
	}

	/**
	 * The trainee cancels: moving is offered separately; this asks for a refund.
	 *
	 * @param object $b Booking
	 * @param string $comment Optional words from them
	 * @return array{ok:bool,error?:string}
	 */
	public function requestRefund($b, $comment = '')
	{
		$was = (string) $b->status;
		if (!in_array($was, self::MOVABLE, true)) {
			return array('ok' => false, 'error' => 'This booking can no longer be refunded here. Reply to your confirmation email.');
		}
		if ($b->price_type == 'free' || (float) $b->amount_paid <= 0) {
			$this->saveBooking((int) $b->rowid, array('status' => 'cancelled'));
		} else {
			$this->saveBooking((int) $b->rowid, array('status' => 'refund_requested', 'comment' => dol_trunc($comment, 2000, 'right', 'UTF-8', 1)));
			$v = $this->vars($b, 'staff');
			$v['comment'] = $comment !== '' ? $comment : '(none)';
			$this->mailTpl('staff_refund', $this->treasurerEmails(), $v);
		}
		if (in_array($was, array('paid', 'confirmed'), true)) {
			$this->mailTpl('trainer_freed', $b->trainer_email, $this->vars($b, 'trainer'));
		}
		$this->mailTpl('refund_asked', $b->email, $this->vars($b));
		return array('ok' => true);
	}

	/**
	 * The trainee picks "other" or "my trainer didn't show up": the secretary
	 * hears it with their words.
	 *
	 * @param object $b Booking
	 * @param string $kind other or no_trainer
	 * @param string $comment Their words
	 * @return array{ok:bool}
	 */
	public function traineeSays($b, $kind, $comment)
	{
		$comment = dol_trunc(trim((string) $comment), 2000, 'right', 'UTF-8', 1);
		$this->saveBooking((int) $b->rowid, array('status' => 'other', 'answer' => $kind == 'no_trainer' ? 'no_trainer' : 'trainee_other', 'comment' => $comment, 'answered_at' => $this->db->idate(OnboardingService::now())), "status IN ('paid', 'confirmed', 'needs_new_date')");
		$v = $this->vars($b, 'staff');
		$v['comment'] = $comment !== '' ? $comment : '(no comment)';
		$this->mailTpl($kind == 'no_trainer' ? 'staff_no_trainer' : 'staff_trainee_other', $this->staffEmails(), $v);
		return array('ok' => true);
	}

	/**
	 * The trainer says how it went.
	 *
	 * @param object $b Booking
	 * @param string $answer trained, noshow or other
	 * @param string $comment For "other"
	 * @param int $userId Staff user doing it in Dolibarr, 0 from the email link
	 * @return array{ok:bool,error?:string,result?:string}
	 */
	public function answer($b, $answer, $comment = '', $userId = 0)
	{
		if (!in_array($b->status, array('confirmed', 'paid', 'other'), true)) {
			return array('ok' => false, 'error' => 'This booking was already settled ('.self::STATUS_LABELS[$b->status].').');
		}
		if (!$userId && $this->db->jdate($b->session_start) > OnboardingService::now()) {
			return array('ok' => false, 'error' => 'The training has not happened yet.');
		}
		$now = $this->db->idate(OnboardingService::now());
		$from = "status = '".$this->db->escape($b->status)."'";
		if ($answer == 'trained') {
			if (!$this->saveBooking((int) $b->rowid, array('status' => 'trained', 'answer' => 'trained', 'answered_at' => $now), $from)) {
				return array('ok' => false, 'error' => 'Already answered.');
			}
			$tid = $this->record($this->booking((int) $b->rowid), $userId);
			return array('ok' => true, 'result' => 'Recorded as trained'.($tid ? '' : ' (the training record could not be written; tell the secretary)').'.');
		}
		if ($answer == 'noshow' && $b->source == 'spot') {
			if (!$this->saveBooking((int) $b->rowid, array('answer' => 'not_done', 'answered_at' => $now), $from)) {
				return array('ok' => false, 'error' => 'Already answered.');
			}
			$this->loseDate($this->booking((int) $b->rowid), 'spot', '');
			return array('ok' => true, 'result' => 'Not marked as trained. They were asked to pick a date for the training, ask for a refund, or tell the secretary.');
		}
		if ($answer == 'noshow') {
			$shows = (int) $b->no_shows + 1;
			$free = (int) $b->rebooks < (int) round(self::num('TRAINING_FREE_REBOOKS', 1));
			if ($free) {
				if (!$this->saveBooking((int) $b->rowid, array('status' => 'needs_new_date', 'no_shows' => $shows, 'answer' => 'noshow', 'answered_at' => $now, 'trainee_sent' => null), $from)) {
					return array('ok' => false, 'error' => 'Already answered.');
				}
				$this->mailTpl('missed_free', $b->email, $this->vars($b));
				return array('ok' => true, 'result' => 'Marked as not shown. They were offered one free new date.');
			}
			if (!$this->saveBooking((int) $b->rowid, array('status' => 'no_show', 'no_shows' => $shows, 'answer' => 'noshow', 'answered_at' => $now), $from)) {
				return array('ok' => false, 'error' => 'Already answered.');
			}
			$this->record($this->booking((int) $b->rowid), $userId, 'no_show');
			$this->mailTpl('missed_final', $b->email, $this->vars($b));
			return array('ok' => true, 'result' => 'Marked as not shown again. The fee is kept and they were told to book again.');
		}
		if ($answer == 'other') {
			$comment = dol_trunc(trim((string) $comment), 2000, 'right', 'UTF-8', 1);
			if (!$this->saveBooking((int) $b->rowid, array('status' => 'other', 'answer' => 'trainer_other', 'comment' => $comment, 'answered_at' => $now), $from)) {
				return array('ok' => false, 'error' => 'Already answered.');
			}
			$v = $this->vars($b, 'staff');
			$v['comment'] = $comment !== '' ? $comment : '(no comment)';
			$this->mailTpl('staff_trainer_other', $this->staffEmails(), $v);
			return array('ok' => true, 'result' => 'Sent to the secretary, who will settle it.');
		}
		return array('ok' => false, 'error' => 'Pick an answer.');
	}

	/**
	 * Record a training from a booking: the training row, the trained tag, the
	 * split of what arrived after Givebutter's fee, the email. A second no-show
	 * ($status no_show) keeps the fee and splits it, but nobody is trained.
	 *
	 * @param object $b Booking
	 * @param int $userId Who recorded it, 0 for the trainer's link
	 * @param string $status recorded or no_show
	 * @return int Training row, 0 on failure
	 */
	public function record($b, $userId = 0, $status = 'recorded')
	{
		$source = $b->fk_class ? 'class' : ($b->price_type == 'free' ? 'free' : 'paid');
		$tool = $this->tool((int) $b->fk_tool);
		$net = $b->amount_net !== null ? (float) $b->amount_net : (float) $b->amount_paid;
		$trainerAmount = round($net * self::shareOf($tool), 2);
		$id = $this->training->insertRecord(array(
			'tx' => $b->gb_transaction_id ? (string) $b->gb_transaction_id : 'booking-'.((int) $b->rowid),
			'email' => (string) $b->email,
			'firstname' => (string) $b->firstname,
			'lastname' => (string) $b->lastname,
			'fk_adherent' => (int) $b->fk_adherent,
			'zone' => (string) $b->zone,
			'tool' => (string) $b->tool,
			'fk_tool' => (int) $b->fk_tool,
			'trainer_name' => trim($b->trainer_first.' '.$b->trainer_last),
			'fk_trainer' => (int) $b->fk_trainer,
			'amount' => (float) $b->amount_paid,
			'trainer_amount' => $trainerAmount,
			'zone_amount' => round($net - $trainerAmount, 2),
			'when' => $this->db->jdate($b->session_start),
			'status' => $status,
			'source' => $source,
			'fk_booking' => (int) $b->rowid,
			'fk_user' => $userId,
		));
		if (!$id) {
			return 0;
		}
		$this->saveBooking((int) $b->rowid, array('fk_training' => $id));
		if ($status == 'recorded') {
			$this->training->markTrained((string) $b->email, (int) $b->fk_adherent, (string) $b->tool);
			$this->mailTpl('trained', $b->email, $this->vars($b));
			$this->afterRecord((string) $b->email, (int) $b->fk_adherent, (int) $b->fk_trainer);
		} else {
			$this->refreshStats((int) $b->fk_trainer);
		}
		return $id;
	}

	/**
	 * Keep the member list's counters and the website's lookup copy current.
	 *
	 * @param string $email Trainee
	 * @param int $memberId Trainee's member card
	 * @param int $trainerId Trainer
	 * @return void
	 */
	public function afterRecord($email, $memberId, $trainerId)
	{
		$this->refreshStats($memberId);
		$this->refreshStats($trainerId);
		$this->pushLookups(array($email));
	}

	/**
	 * Recount a member's "Trained on" and "Trainings given" fields.
	 *
	 * @param int $memberId Member
	 * @return void
	 */
	public function refreshStats($memberId)
	{
		global $conf;
		if ($memberId <= 0) {
			return;
		}
		$t = $this->db->prefix().'onboarding_training';
		$tools = array();
		$res = $this->db->query("SELECT DISTINCT tool FROM ".$t." WHERE entity = ".((int) $conf->entity)." AND fk_adherent = ".((int) $memberId)." AND status IN ('recorded', 'trainer_unmatched') AND tool <> '' ORDER BY tool");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$tools[] = (string) $o->tool;
		}
		$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$t." WHERE entity = ".((int) $conf->entity)." AND fk_trainer = ".((int) $memberId)." AND status = 'recorded'");
		$given = $res ? (int) $this->db->fetch_object($res)->n : 0;
		$adh = new Adherent($this->db);
		if ($adh->fetch($memberId) > 0) {
			$adh->array_options['options_trained_on'] = dol_trunc(implode(', ', $tools), 255, 'right', 'UTF-8', 1);
			$adh->array_options['options_trainings_given'] = $given;
			$adh->insertExtraFields();
		}
	}

	/**
	 * Staff marked a refund as made in Givebutter.
	 *
	 * @param object $b Booking
	 * @return bool
	 */
	public function markRefunded($b)
	{
		if (!$this->saveBooking((int) $b->rowid, array('status' => 'refunded'), "status IN ('refund_requested', 'other', 'needs_new_date', 'paid', 'confirmed')")) {
			return false;
		}
		$this->mailTpl('refunded', $b->email, $this->vars($b));
		return true;
	}

	/**
	 * Staff cancel a booking without a refund (entered by mistake, a duplicate).
	 *
	 * @param object $b Booking
	 * @return bool
	 */
	public function cancelBooking($b)
	{
		return $this->saveBooking((int) $b->rowid, array('status' => 'cancelled'), "status NOT IN ('trained', 'no_show', 'refunded', 'cancelled')");
	}

	/**
	 * Staff send someone the link to pick a new date.
	 *
	 * @param object $b Booking
	 * @return bool
	 */
	public function offerNewDate($b)
	{
		if (!in_array($b->status, array('needs_new_date', 'other', 'paid', 'confirmed'), true)) {
			return false;
		}
		if ($b->status != 'needs_new_date') {
			$this->saveBooking((int) $b->rowid, array('status' => 'needs_new_date', 'confirmed_at' => null, 'trainer_sent' => null));
		}
		$this->mailTpl('pick_date', $b->email, $this->vars($this->booking((int) $b->rowid)));
		return true;
	}

	// ---------------------------------------------------------------- scheduled work

	/**
	 * Every few minutes: holds, confirmations, reminders, the after-training
	 * questions, classes and the website check.
	 *
	 * @return string Summary
	 */
	public function run()
	{
		$now = OnboardingService::now();
		$n = array('expired' => 0, 'reminded' => 0, 'unconfirmed' => 0, 'dayof' => 0, 'after' => 0, 'followup' => 0, 'classes' => 0);

		// Someone paying, or let go of in the last day: pick up a payment the webhook missed.
		if ($this->bookingsWhere("gb_transaction_id IS NULL AND (status = 'held' OR (status = 'expired' AND hold_until >= '".$this->db->idate($now - 86400)."'))")) {
			$this->svc->quickSync();
		}
		$n['expired'] = $this->expireSpot($now);
		$res = $this->db->query("UPDATE ".$this->db->prefix()."onboarding_booking SET status = 'expired' WHERE status = 'held' AND hold_until < '".$this->db->idate($now)."'");
		$n['expired'] += $res ? $this->db->affected_rows($res) : 0;

		$confirmBy = (int) round(self::num('TRAINING_CONFIRM_HOURS', 24) * 3600);
		$remindAfter = (int) round(self::num('TRAINING_CONFIRM_REMIND_HOURS', 24) * 3600);
		foreach ($this->bookingsWhere("status = 'paid' AND fk_class IS NULL") as $b) {
			$start = $this->db->jdate($b->session_start);
			if ($now >= $start - $confirmBy) {
				$this->loseDate($b, 'noanswer', '');
				$n['unconfirmed']++;
			} elseif ($b->paid_at && $now >= $this->db->jdate($b->paid_at) + $remindAfter && !self::sent($b, 'trainer_sent', 'remind') && $this->markSent($b, 'trainer_sent', 'remind')) {
				$this->mailTpl('trainer_request', $b->trainer_email, array_merge($this->vars($b, 'trainer'), array('again' => 'Reminder: ')));
				$n['reminded']++;
			}
		}

		$hour = (int) self::num('TRAINING_DAYOF_HOUR', 9);
		$today = self::fmt($now, 'Y-m-d');
		$sessions = array();
		foreach ($this->bookingsWhere("status = 'confirmed' AND fk_class IS NULL") as $b) {
			$start = $this->db->jdate($b->session_start);
			$end = $start + 60 * (int) $b->minutes;
			if (self::fmt($start, 'Y-m-d') == $today && $now >= self::at($today, sprintf('%02d:00', $hour)) && $now < $start && !self::sent($b, 'trainee_sent', 'dayof') && $this->markSent($b, 'trainee_sent', 'dayof')) {
				$this->mailTpl('dayof', $b->email, $this->vars($b));
				$sessions[$b->fk_trainer.'|'.$b->session_start] = $b;
				$n['dayof']++;
			}
			if ($now >= $end) {
				$n['after'] += $this->afterSession($b, $end, $now);
			}
		}
		foreach ($sessions as $b) {
			if ($this->markSent($b, 'trainer_sent', 'dayof')) {
				$this->mailTpl('trainer_dayof', $b->trainer_email, $this->vars($b, 'trainer'));
			}
		}

		// No answer from the trainer two days on: ask the trainee.
		$follow = (int) round(self::num('TRAINING_TRAINEE_FOLLOWUP_HOURS', 48) * 3600);
		foreach ($this->bookingsWhere("status = 'confirmed' AND answer IS NULL AND fk_class IS NULL AND session_start < '".$this->db->idate($now - $follow)."'") as $b) {
			$end = $this->db->jdate($b->session_start) + 60 * (int) $b->minutes;
			if ($now >= $end + $follow && !self::sent($b, 'trainee_sent', 'followup') && $this->markSent($b, 'trainee_sent', 'followup')) {
				$this->mailTpl('trainee_followup', $b->email, $this->vars($b));
				$this->mailTpl('staff_unanswered', $this->staffEmails(), $this->vars($b, 'staff'));
				$n['followup']++;
			}
		}

		// A free rebooking nobody used.
		$days = (int) round(self::num('TRAINING_REBOOK_DAYS', 60) * 86400);
		foreach ($this->bookingsWhere("status = 'needs_new_date' AND answered_at IS NOT NULL AND answered_at < '".$this->db->idate($now - $days)."'") as $b) {
			if (!self::sent($b, 'trainee_sent', 'lapsed') && $this->markSent($b, 'trainee_sent', 'lapsed')) {
				$this->mailTpl('staff_rebook_unused', $this->staffEmails(), $this->vars($b, 'staff'));
			}
		}

		$n['classes'] = $this->runClasses($now);
		$this->checkWebsite($now);
		$parts = array();
		foreach ($n as $k => $v) {
			$parts[] = $v.' '.$k;
		}
		return 'Bookings: '.implode(', ', $parts);
	}

	/**
	 * The questions to the trainer after a session: at the end, then one hour
	 * and one day later.
	 *
	 * @param object $b Booking
	 * @param int $end Session end
	 * @param int $now Now
	 * @return int Emails sent
	 */
	private function afterSession($b, $end, $now)
	{
		$sent = 0;
		if ($b->answer) {
			return 0;
		}
		$steps = array('after' => 0);
		foreach (explode(',', getDolGlobalString('ONBOARDING_TRAINING_AFTER_REMINDERS', '1,24')) as $h) {
			if (is_numeric(trim($h)) && (float) $h > 0) {
				$steps['after'.((float) $h)] = (int) round((float) $h * 3600);
			}
		}
		$due = '';
		foreach ($steps as $name => $offset) {
			if ($now >= $end + $offset && !self::sent($b, 'trainer_sent', $name)) {
				$due = $name;
			}
		}
		if ($due === '') {
			return 0;
		}
		// One email even when several came due at once (the job did not run for a while).
		foreach ($steps as $name => $offset) {
			if ($now >= $end + $offset && !self::sent($b, 'trainer_sent', $name)) {
				$this->markSent($b, 'trainer_sent', $name);
			}
		}
		$this->mailTpl('trainer_after', $b->trainer_email, array_merge($this->vars($b, 'trainer'), array('again' => $due == 'after' ? '' : 'Reminder: ')));
		return 1;
	}

	/**
	 * Dolibarr's side of the website check. The website fetches the catalog
	 * every 15 minutes; no fetch for half an hour means it cannot reach this
	 * Dolibarr (down, a tunnel or DNS problem, a changed key) or its job stopped.
	 * A fetch that leaves it on an old version for an hour means pushes fail.
	 *
	 * @param int $now Now
	 * @return void
	 */
	private function checkWebsite($now)
	{
		if (getDolGlobalString('ONBOARDING_WEBSITE_URL') === '') {
			return;
		}
		$seen = getDolGlobalInt('ONBOARDING_WEBSITE_SEEN');
		$quiet = (int) round(self::num('TRAINING_CHECKIN_ALERT_MINUTES', 30) * 60);
		if ($seen && $now - $seen > $quiet) {
			$this->svc->alert('website-checkin', 'The website has not checked in with Dolibarr', 'The website last fetched the training catalog '.dol_print_date($seen, 'dayhour').' ('.round(($now - $seen) / 60).' minutes ago). It normally does every 15 minutes, so it probably cannot reach this Dolibarr from the internet: check that Dolibarr\'s public address (the website\'s DOLIBARR_URL) answers from outside, that the tunnel or DNS is up, and that the website\'s DOLIBARR_API_KEY matches the "Key for the website" on the module setup page.'."\n\nWhile this lasts, booking and signup on the website are paused; tool pages and the lookup still work from the website's copy.");
		} elseif ($seen) {
			$this->svc->alertClear('website-checkin', 'The website checks in with Dolibarr again.');
		}
		$has = getDolGlobalInt('ONBOARDING_WEBSITE_VERSION');
		$version = $this->version();
		$late = (int) round(self::num('TRAINING_SYNC_ALERT_MINUTES', 60) * 60);
		$last = $this->changes(1);
		$changedAt = $last ? $this->db->jdate($last[0]->datec) : 0;
		if ($has >= $version || ($changedAt && $now - $changedAt < $late)) {
			$this->svc->alertClear('website-sync', 'The website has the current training catalog again.');
			return;
		}
		$this->svc->alert('website-sync', 'The website has an old training catalog', 'Dolibarr is on catalog version '.$version.' but the website last reported version '.$has.($seen ? ' ('.dol_print_date($seen, 'dayhour').')' : '').'. Press "Send to the website now" under Members, Onboarding, Training tools, and check the website Worker\'s DOLIBARR_URL and DOLIBARR_API_KEY.');
	}

	/**
	 * The website reached Dolibarr again after failing to: say so, with how long.
	 *
	 * @param array<string,mixed> $in down_since, back (ISO 8601), minutes, waiting
	 * @return array<string,mixed>
	 */
	public function websiteReport($in)
	{
		$minutes = isset($in['minutes']) ? (int) $in['minutes'] : 0;
		$from = isset($in['down_since']) ? strtotime((string) $in['down_since']) : 0;
		$back = isset($in['back']) ? strtotime((string) $in['back']) : 0;
		if ($minutes < (int) self::num('TRAINING_OUTAGE_REPORT_MINUTES', 10)) {
			return array('ok' => true, 'reported' => false);
		}
		$waiting = isset($in['waiting']) ? (int) $in['waiting'] : 0;
		$this->svc->mail($this->svc->alertTo(), '[Fixed] The website could not reach Dolibarr for '.$minutes.' minutes', 'The website could not reach Dolibarr'.($from ? ' from '.self::fmt($from, 'D j M, g:i A') : '').($back ? ' until '.self::fmt($back, 'D j M, g:i A') : '').' ('.$minutes.' minutes). It reaches it again now.'."\n\nDuring that time booking and signup on the website were paused; tool pages and the lookup worked from the website's copy.".($waiting ? ' '.$waiting.' people asked to hear when booking was back, and are being emailed now.' : '')."\n\nIf this happens often, check what Dolibarr's public address runs through (tunnel, DNS, the server).");
		return array('ok' => true, 'reported' => true);
	}

	// ---------------------------------------------------------------- group classes

	/**
	 * @param int $id Class
	 * @return object|null Class with its tool and trainer
	 */
	public function klass($id)
	{
		$rows = $this->classesWhere("c.rowid = ".((int) $id));
		return $rows ? $rows[0] : null;
	}

	/**
	 * @param string $where Condition on c (class), t (tool), a (trainer)
	 * @return object[]
	 */
	public function classesWhere($where)
	{
		global $conf;
		$p = $this->db->prefix();
		$sql = "SELECT c.*, t.ref AS tool_ref, t.label AS tool, t.zone, t.wiki_url, a.firstname AS trainer_first, a.lastname AS trainer_last, a.email AS trainer_email,";
		$sql .= " (SELECT COUNT(*) FROM ".$p."onboarding_booking AS b WHERE b.fk_class = c.rowid AND b.status IN ('confirmed', 'trained')) AS attendees";
		$sql .= " FROM ".$p."onboarding_class AS c LEFT JOIN ".$p."onboarding_tool AS t ON t.rowid = c.fk_tool LEFT JOIN ".$p."adherent AS a ON a.rowid = c.fk_trainer";
		$sql .= " WHERE c.entity = ".((int) $conf->entity)." AND (".$where.") ORDER BY c.session_start DESC";
		$res = $this->db->query($sql);
		$out = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * Create a group class and its sign-in code.
	 *
	 * @param array<string,mixed> $in tool (id), trainer (member id), day (Y-m-d), time (H:i), minutes, seats, mode, note
	 * @param User $by Who did it
	 * @return array{ok:bool,id?:int,code?:string,error?:string}
	 */
	public function createClass($in, $by)
	{
		global $conf;
		$tool = $this->tool(isset($in['tool']) ? (int) $in['tool'] : 0);
		$trainer = isset($in['trainer']) ? (int) $in['trainer'] : 0;
		$start = self::at(isset($in['day']) ? (string) $in['day'] : '', isset($in['time']) ? (string) $in['time'] : '');
		if (!$tool || $trainer <= 0 || !$start) {
			return array('ok' => false, 'error' => 'Pick a tool, a trainer, a date and a time.');
		}
		// The longest word of the tool's name: "3D printers" gives PRINT-7K2Q.
		$word = '';
		foreach (preg_split('/[^a-z]+/', strtolower($tool->label)) as $w) {
			if (strlen($w) > strlen($word)) {
				$word = $w;
			}
		}
		$prefix = strtoupper(substr($word, 0, 5));
		$code = '';
		for ($try = 0; $try < 10; $try++) {
			$rand = '';
			for ($i = 0; $i < 4; $i++) {
				$rand .= substr(self::CODE_CHARS, random_int(0, strlen(self::CODE_CHARS) - 1), 1);
			}
			$code = ($prefix !== '' ? $prefix : 'CLASS').'-'.$rand;
			$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."onboarding_class WHERE code = '".$code."'");
			if (!$res || !$this->db->fetch_object($res)) {
				break;
			}
		}
		$mode = isset($in['mode']) && $in['mode'] == 'auto' ? 'auto' : 'confirm';
		$sql = "INSERT INTO ".$this->db->prefix()."onboarding_class (entity, code, fk_tool, fk_trainer, session_start, minutes, seats, price, mode, status, note, fk_user, datec) VALUES (";
		$sql .= ((int) $conf->entity).", '".$code."', ".((int) $tool->rowid).", ".$trainer.", '".$this->db->idate($start)."', ".max(15, (int) (isset($in['minutes']) && $in['minutes'] ? $in['minutes'] : $tool->minutes)).", ";
		$sql .= max(1, (int) (isset($in['seats']) && $in['seats'] ? $in['seats'] : 10)).", 0, '".$mode."', 'open', '".$this->db->escape(dol_trunc(isset($in['note']) ? (string) $in['note'] : '', 255, 'right', 'UTF-8', 1))."', ".((int) $by->id).", '".$this->db->idate(OnboardingService::now())."')";
		if (!$this->db->query($sql)) {
			return array('ok' => false, 'error' => $this->db->lasterror());
		}
		$id = (int) $this->db->last_insert_id($this->db->prefix().'onboarding_class');
		$c = $this->klass($id);
		$v = $this->classVars($c);
		$this->mailTpl('class_created', array_unique(array_filter(array((string) $c->trainer_email, (string) $by->email))), $v);
		return array('ok' => true, 'id' => $id, 'code' => $code);
	}

	/**
	 * @param object $c Class
	 * @return string Its sign-in page
	 */
	public function classUrl($c)
	{
		$base = rtrim(getDolGlobalString('ONBOARDING_WEBSITE_URL', 'https://columbiagadgetworks.org'), '/');
		return $base.'/training/class/?c='.rawurlencode($c->code);
	}

	/**
	 * @param object $c Class
	 * @param int $now Now
	 * @return bool Its code works right now
	 */
	public static function classOpen($c, $now)
	{
		global $db;
		$start = $db->jdate($c->session_start);
		$before = (int) round(self::num('TRAINING_CLASS_OPEN_BEFORE', 30) * 60);
		$after = (int) round(self::num('TRAINING_CLASS_OPEN_AFTER', 180) * 60);
		return $c->status == 'open' && $now >= $start - $before && $now <= $start + 60 * (int) $c->minutes + $after;
	}

	/**
	 * @param string $code Code typed or in the link
	 * @return object|null The class with that code
	 */
	public function classByCode($code)
	{
		$code = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) $code));
		if ($code === '') {
			return null;
		}
		$rows = $this->classesWhere("c.code = '".$this->db->escape($code)."'");
		return $rows ? $rows[0] : null;
	}

	/**
	 * The class sign-in page asks what a code is for. Outside its window the
	 * code is "not open", without saying anything else.
	 *
	 * @param string $code Code
	 * @return array<string,mixed>
	 */
	public function classInfo($code)
	{
		$c = $this->classByCode($code);
		if (!$c || !self::classOpen($c, OnboardingService::now())) {
			return array('ok' => false, 'error' => 'closed', 'http' => 404);
		}
		return array('ok' => true, 'code' => $c->code, 'tool' => $c->tool, 'zone' => $c->zone, 'trainer' => $c->trainer_first, 'when' => self::fmt($this->db->jdate($c->session_start)), 'seats_left' => max(0, (int) $c->seats - (int) $c->attendees), 'wiki' => (string) $c->wiki_url);
	}

	/**
	 * Sign in to a group class. Someone new becomes a non-member. Anyone without
	 * a waiver gets a signing token back, to sign right there on the page (they
	 * are standing in front of the trainer), then signs in again.
	 *
	 * @param array<string,mixed> $in code, email, firstname, lastname, ip
	 * @return array<string,mixed>
	 */
	public function classJoin($in)
	{
		global $conf;
		$c = $this->classByCode(isset($in['code']) ? (string) $in['code'] : '');
		$now = OnboardingService::now();
		if (!$c || !self::classOpen($c, $now)) {
			return array('ok' => false, 'error' => 'closed', 'http' => 404);
		}
		$email = OnboardingService::cleanEmail(isset($in['email']) ? $in['email'] : '');
		if (!isValidEmail($email)) {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		$mine = $this->bookingsWhere("fk_class = ".((int) $c->rowid)." AND email = '".$this->db->escape($email)."' AND status IN ('confirmed', 'trained')");
		if ($mine) {
			return array('ok' => true, 'done' => true, 'trained' => $mine[0]->status == 'trained');
		}
		if ((int) $c->attendees >= (int) $c->seats) {
			return array('ok' => false, 'error' => 'full', 'http' => 409);
		}
		$p = $this->person($email);
		if ($p['kind'] == 'new') {
			$r = $this->newTrainee($in, 'Created at a group class sign-in ('.$c->code.').');
			if (!$r['ok']) {
				return $r;
			}
			$p = $this->person($email);
		}
		if ($p['needs']) {
			$app = $this->applicantFor($p);
			if (!$app) {
				return array('ok' => false, 'error' => 'server', 'http' => 500);
			}
			return array('ok' => true, 'done' => false, 'needs' => $p['needs'], 'token' => $this->svc->issueToken($app, false));
		}
		$token = bin2hex(random_bytes(24));
		$table = $this->db->prefix().'onboarding_booking';
		$sql = "INSERT INTO ".$table." (entity, token, fk_tool, fk_class, fk_trainer, fk_adherent, fk_socpeople, fk_applicant, email, firstname, lastname, session_start, minutes, price, price_type, status, confirmed_at, source, ip, datec) VALUES (";
		$sql .= ((int) $conf->entity).", '".$token."', ".((int) $c->fk_tool).", ".((int) $c->rowid).", ".((int) $c->fk_trainer).", ";
		$sql .= ($p['member'] ? (int) $p['member']->rowid : 'NULL').", ".($p['contact'] ? (int) $p['contact'] : 'NULL').", ".($p['applicant'] ? (int) $p['applicant']->rowid : 'NULL').", ";
		$sql .= "'".$this->db->escape($email)."', '".$this->db->escape(dol_trunc($p['firstname'], 100, 'right', 'UTF-8', 1))."', '".$this->db->escape(dol_trunc($p['lastname'], 100, 'right', 'UTF-8', 1))."', ";
		$sql .= "'".$this->db->escape($c->session_start)."', ".((int) $c->minutes).", 0, 'free', 'confirmed', '".$this->db->idate($now)."', 'class', '".$this->db->escape(substr((string) (isset($in['ip']) ? $in['ip'] : ''), 0, 64))."', '".$this->db->idate($now)."')";
		if (!$this->db->query($sql)) {
			return array('ok' => false, 'error' => 'server', 'http' => 500);
		}
		$id = (int) $this->db->last_insert_id($table);
		$this->saveBooking($id, array('ref' => 'TR-'.sprintf('%04d', $id)));
		$trained = false;
		if ($c->mode == 'auto') {
			$trained = $this->answer($this->booking($id), 'trained', '', (int) $c->fk_user)['ok'];
		}
		return array('ok' => true, 'done' => true, 'trained' => $trained);
	}

	/**
	 * Staff add someone to a class from the class page (no phone, paper sign-in).
	 *
	 * @param object $c Class
	 * @param array<string,mixed> $in email, firstname, lastname, paper_waiver
	 * @param User $by Who did it
	 * @return string Result for the page
	 */
	public function classAdd($c, $in, $by)
	{
		$email = OnboardingService::cleanEmail(isset($in['email']) ? $in['email'] : '');
		if (!isValidEmail($email)) {
			return 'Type a valid email.';
		}
		$p = $this->person($email);
		if ($p['kind'] == 'new') {
			$r = $this->newTrainee($in, 'Added to group class '.$c->code.' by '.$by->login.'.');
			if (!$r['ok']) {
				return 'A first and last name are needed for someone new.';
			}
			$p = $this->person($email);
		}
		if ($p['needs'] && !empty($in['paper_waiver'])) {
			$this->paperWaiver($p, in_array('agreement', $p['needs'], true));
			$p = $this->person($email);
		}
		if ($p['needs']) {
			return $email.' has no waiver on file. Tick "paper waiver signed" once they sign a paper copy, or have them sign on the class page.';
		}
		$saved = $c->status;
		$c->status = 'open';
		// Staff may add people outside the code's time window.
		$was = OnboardingService::$now;
		OnboardingService::$now = $this->db->jdate($c->session_start);
		$r = $this->classJoin(array('code' => $c->code, 'email' => $email, 'firstname' => $p['firstname'], 'lastname' => $p['lastname']));
		OnboardingService::$now = $was;
		$c->status = $saved;
		if (!$r['ok']) {
			return $r['error'] == 'full' ? 'The class is full. Raise its seats first.' : 'Could not add them ('.$r['error'].').';
		}
		return 'Added '.$email.'.';
	}

	/**
	 * A paper waiver (and agreement) is on file: put today's date on their cards.
	 *
	 * @param array $p From person()
	 * @param bool $agreement The agreement too
	 * @return void
	 */
	public function paperWaiver($p, $agreement)
	{
		$today = dol_mktime(12, 0, 0, (int) self::fmt(OnboardingService::now(), 'n'), (int) self::fmt(OnboardingService::now(), 'j'), (int) self::fmt(OnboardingService::now(), 'Y'));
		$targets = array();
		if ($p['member']) {
			$targets[] = array(new Adherent($this->db), (int) $p['member']->rowid);
		}
		if ($p['contact']) {
			$targets[] = array(new Contact($this->db), (int) $p['contact']);
		}
		foreach ($targets as $t) {
			if ($t[0]->fetch($t[1]) > 0) {
				$t[0]->array_options['options_waiver_date'] = $today;
				if ($agreement) {
					$t[0]->array_options['options_agreement_date'] = $today;
				}
				$t[0]->insertExtraFields();
			}
		}
	}

	/**
	 * The trainer (or staff) confirms the list after the class.
	 *
	 * @param object $c Class
	 * @param int[] $trained Bookings that were trained; the rest of the list left early
	 * @param int $userId Staff user, 0 for the trainer's link
	 * @return string Result
	 */
	public function classFinish($c, $trained, $userId = 0)
	{
		$yes = 0;
		$no = 0;
		$trained = array_map('intval', (array) $trained);
		foreach ($this->bookingsWhere("fk_class = ".((int) $c->rowid)." AND status = 'confirmed'") as $b) {
			if (in_array((int) $b->rowid, $trained, true)) {
				if ($this->answer($b, 'trained', '', $userId ? $userId : (int) $c->fk_user)['ok']) {
					$yes++;
				}
			} else {
				$this->saveBooking((int) $b->rowid, array('status' => 'cancelled', 'answer' => 'left', 'answered_at' => $this->db->idate(OnboardingService::now())), "status = 'confirmed'");
				$no++;
			}
		}
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_class SET status = 'done' WHERE rowid = ".((int) $c->rowid));
		return $yes.' marked trained'.($no ? ', '.$no.' not' : '').'.';
	}

	/**
	 * After a class ends: the list to the trainer, a reminder a day later, and
	 * the secretary after two days.
	 *
	 * @param int $now Now
	 * @return int Emails sent
	 */
	private function runClasses($now)
	{
		$sent = 0;
		foreach ($this->classesWhere("c.status = 'open'") as $c) {
			$end = $this->db->jdate($c->session_start) + 60 * (int) $c->minutes;
			if ($now < $end) {
				continue;
			}
			$waiting = $this->bookingsWhere("fk_class = ".((int) $c->rowid)." AND status = 'confirmed'");
			if (!$waiting) {
				if ($now > $end + (int) round(self::num('TRAINING_CLASS_OPEN_AFTER', 180) * 60)) {
					$this->db->query("UPDATE ".$this->db->prefix()."onboarding_class SET status = 'done' WHERE rowid = ".((int) $c->rowid));
				}
				continue;
			}
			$step = (int) $c->roster_sent;
			$due = $step == 0 ? $end : ($step == 1 ? $end + 86400 : ($step == 2 ? $end + 2 * 86400 : 0));
			if (!$due || $now < $due) {
				continue;
			}
			$res = $this->db->query("UPDATE ".$this->db->prefix()."onboarding_class SET roster_sent = ".($step + 1)." WHERE rowid = ".((int) $c->rowid)." AND roster_sent = ".$step);
			if (!$res || $this->db->affected_rows($res) < 1) {
				continue;
			}
			$v = $this->classVars($c);
			$v['count'] = (string) count($waiting);
			$v['again'] = $step ? 'Reminder: ' : '';
			if ($step < 2) {
				$this->mailTpl('class_roster', $c->trainer_email, $v);
			} else {
				$this->mailTpl('staff_class_unanswered', $this->staffEmails(), $v);
			}
			$sent++;
		}
		return $sent;
	}

	/**
	 * @param object $c Class
	 * @return array<string,string> Email placeholders
	 */
	public function classVars($c)
	{
		$names = array();
		foreach ($this->bookingsWhere("fk_class = ".((int) $c->rowid)." AND status IN ('confirmed', 'trained')") as $b) {
			$names[] = '- '.self::shortName($b->firstname, $b->lastname).($b->status == 'trained' ? ' (trained)' : '');
		}
		return array(
			'tool' => (string) $c->tool,
			'zone' => (string) $c->zone,
			'when' => self::fmt($this->db->jdate($c->session_start)),
			'code' => (string) $c->code,
			'class_url' => $this->classUrl($c),
			'trainer' => (string) $c->trainer_first,
			'seats' => (string) $c->seats,
			'roster' => $names ? implode("\n", $names) : '(nobody yet)',
			'link_class' => $this->classLink($c),
			'card' => dol_buildpath('/onboarding/class.php', 2).'?id='.((int) $c->rowid),
			'org' => getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'the makerspace'),
		);
	}

	// ---------------------------------------------------------------- free trainings

	/**
	 * Record a training with no fee: board-approved, a trainer's own, someone
	 * trained before training was required. Someone new becomes a non-member.
	 *
	 * @param array<string,mixed> $in email, firstname, lastname, tool (id), trainer (member id), day (Y-m-d), reason
	 * @param User $by Who did it
	 * @return array{ok:bool,error?:string}
	 */
	public function free($in, $by)
	{
		$email = OnboardingService::cleanEmail(isset($in['email']) ? $in['email'] : '');
		$tool = $this->tool(isset($in['tool']) ? (int) $in['tool'] : 0);
		if (!isValidEmail($email) || !$tool) {
			return array('ok' => false, 'error' => 'Type an email and pick a tool.');
		}
		$p = $this->person($email);
		if ($p['kind'] == 'new') {
			$r = $this->newTrainee($in, 'Created when '.$by->login.' recorded a free training.');
			if (!$r['ok']) {
				return array('ok' => false, 'error' => $email.' is not in Dolibarr yet: type their first and last name too.');
			}
			$p = $this->person($email);
		}
		if ($this->isTrainedOn($p['member'] ? (int) $p['member']->rowid : 0, $tool) || $this->trainedEmail($email, $tool)) {
			return array('ok' => false, 'error' => $email.' is already trained on the '.$tool->label.'.');
		}
		$day = isset($in['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $in['day']) ? (string) $in['day'] : self::fmt(OnboardingService::now(), 'Y-m-d');
		$trainer = isset($in['trainer']) ? (int) $in['trainer'] : 0;
		$tname = '';
		if ($trainer > 0) {
			$adh = new Adherent($this->db);
			$tname = $adh->fetch($trainer) > 0 ? trim($adh->firstname.' '.$adh->lastname) : '';
		}
		$reason = dol_trunc(trim((string) (isset($in['reason']) ? $in['reason'] : '')), 255, 'right', 'UTF-8', 1);
		$id = $this->training->insertRecord(array(
			'tx' => 'free-'.bin2hex(random_bytes(8)),
			'email' => $email,
			'firstname' => $p['firstname'],
			'lastname' => $p['lastname'],
			'fk_adherent' => $p['member'] ? (int) $p['member']->rowid : 0,
			'zone' => (string) $tool->zone,
			'tool' => (string) $tool->label,
			'fk_tool' => (int) $tool->rowid,
			'trainer_name' => $tname,
			'fk_trainer' => $trainer,
			'amount' => 0,
			'trainer_amount' => 0,
			'zone_amount' => 0,
			'when' => self::at($day, '12:00'),
			'status' => 'recorded',
			'source' => !empty($in['import']) ? 'import' : 'free',
			'free_reason' => $reason !== '' ? $reason : 'No fee',
			'fk_user' => (int) $by->id,
		));
		if (!$id) {
			return array('ok' => false, 'error' => 'The training could not be saved.');
		}
		$this->training->markTrained($email, $p['member'] ? (int) $p['member']->rowid : 0, (string) $tool->label);
		if (empty($in['quiet'])) {
			$this->mailTpl('trained', $email, array(
				'firstname' => $p['firstname'] !== '' ? $p['firstname'] : 'there', 'tool' => $tool->label, 'zone' => $tool->zone,
				'trainer' => $tname, 'lookup_url' => getDolGlobalString('ONBOARDING_TRAINING_LOOKUP_URL', '(ask a member of staff)'),
				'org' => getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'the makerspace'),
			));
		}
		$this->afterRecord($email, $p['member'] ? (int) $p['member']->rowid : 0, $trainer);
		return array('ok' => true);
	}

	/**
	 * @param string $email Email
	 * @param object $tool Tool
	 * @return bool A recorded training on the tool under this email
	 */
	private function trainedEmail($email, $tool)
	{
		global $conf;
		$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_training WHERE entity = ".((int) $conf->entity)." AND LOWER(email) = '".$this->db->escape($email)."' AND status = 'recorded' AND (fk_tool = ".((int) $tool->rowid)." OR tool = '".$this->db->escape($tool->label)."')");
		return $res && (int) $this->db->fetch_object($res)->n > 0;
	}

	/**
	 * People already using a tool before training was required: one per line,
	 * "email" or "email, first name, last name" (tab, comma or semicolon).
	 *
	 * @param string $text Pasted list
	 * @param int $toolId Tool
	 * @param User $by Who did it
	 * @param bool $dry Only say what would happen
	 * @return string[] Summary first, then one line per problem
	 */
	public function importTrained($text, $toolId, $by, $dry = true)
	{
		$tool = $this->tool($toolId);
		if (!$tool) {
			return array('Pick a tool.');
		}
		$done = 0;
		$skip = array();
		foreach (preg_split('/\r\n|\r|\n/', trim((string) $text)) as $n => $line) {
			$cells = array_map('trim', preg_split('/[\t,;]/', $line));
			$email = OnboardingService::cleanEmail($cells[0]);
			if ($email === '' || $email == 'email') {
				continue;
			}
			if (!isValidEmail($email)) {
				$skip[] = 'Line '.($n + 1).': "'.$cells[0].'" is not an email.';
				continue;
			}
			$p = $this->person($email);
			if ($p['kind'] == 'new' && (empty($cells[1]) || empty($cells[2]))) {
				$skip[] = 'Line '.($n + 1).': '.$email.' is not in Dolibarr; add their first and last name after the email.';
				continue;
			}
			if ($this->trainedEmail($email, $tool) || ($p['member'] && $this->isTrainedOn((int) $p['member']->rowid, $tool))) {
				$skip[] = 'Line '.($n + 1).': '.$email.' is already trained.';
				continue;
			}
			if (!$dry) {
				$r = $this->free(array('email' => $email, 'firstname' => isset($cells[1]) ? $cells[1] : '', 'lastname' => isset($cells[2]) ? $cells[2] : '', 'tool' => $toolId, 'reason' => 'Already trained before training was required', 'import' => 1, 'quiet' => 1), $by);
				if (!$r['ok']) {
					$skip[] = 'Line '.($n + 1).': '.$r['error'];
					continue;
				}
			}
			$done++;
		}
		return array_merge(array($done.' '.($dry ? 'would be' : 'were').' recorded as trained on the '.$tool->label.', '.count($skip).' skipped.'), $skip);
	}

	// ---------------------------------------------------------------- lookup

	/**
	 * "a.person4@gmail.com" -> "a******4@gmail.com". The stars are always six, so
	 * the length is not given away.
	 *
	 * @param string $email Email
	 * @return string
	 */
	public static function maskEmail($email)
	{
		$email = OnboardingService::cleanEmail($email);
		$at = strrpos($email, '@');
		if ($at === false) {
			return '******';
		}
		$local = substr($email, 0, $at);
		$first = function_exists('mb_substr') ? mb_substr($local, 0, 1, 'UTF-8') : substr($local, 0, 1);
		$last = function_exists('mb_substr') ? mb_substr($local, -1, 1, 'UTF-8') : substr($local, -1);
		return $first.'******'.(strlen($local) > 1 ? $last : '').substr($email, $at);
	}

	/**
	 * "Ada Lovelace" -> "A*** L***".
	 *
	 * @param string $first First name
	 * @param string $last Last name
	 * @return string
	 */
	public static function maskName($first, $last)
	{
		$out = array();
		foreach (preg_split('/\s+/', trim($first.' '.$last)) as $part) {
			if ($part !== '') {
				$out[] = (function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($part, 0, 1))).'***';
			}
		}
		return implode(' ', $out);
	}

	/**
	 * @param string $first First name
	 * @param string $last Last name
	 * @return string "Jamie R."
	 */
	public static function shortName($first, $last)
	{
		$last = trim((string) $last);
		return trim(trim((string) $first).($last !== '' ? ' '.(function_exists('mb_substr') ? mb_substr($last, 0, 1, 'UTF-8') : substr($last, 0, 1)).'.' : ''));
	}

	/**
	 * "What am I trained on?" Someone unknown and someone with no trainings get
	 * the same answer.
	 *
	 * @param string $email Email
	 * @return array{found:bool,email:string,name:string,trainings:array}
	 */
	public function lookup($email)
	{
		$trainings = $this->training->trainedOn($email);
		if (!$trainings) {
			return array('found' => false, 'email' => '', 'name' => '', 'trainings' => array());
		}
		$p = $this->person($email);
		return array('found' => true, 'email' => self::maskEmail($email), 'name' => self::maskName($p['firstname'], $p['lastname']), 'trainings' => $trainings);
	}

	// ---------------------------------------------------------------- links and emails

	/**
	 * @param string $what booking:ID:WHO or class:ID
	 * @return string Signature
	 */
	public function sign($what)
	{
		return substr(hash_hmac('sha256', $what, $this->secret()), 0, 32);
	}

	/**
	 * @param object $b Booking
	 * @param string $who trainer or trainee
	 * @param string $action What the button does, shown first on the page
	 * @return string Link to public/booking.php
	 */
	public function link($b, $who, $action = '')
	{
		return $this->linkBase().'?b='.((int) $b->rowid).'&w='.$who.'&k='.$this->sign('booking:'.((int) $b->rowid).':'.$who).($action !== '' ? '&a='.$action : '');
	}

	/**
	 * Where the email buttons point: the website's /training/link/, which shows
	 * public/booking.php inside the website, or public/booking.php itself when no
	 * website is set or ONBOARDING_LINKS_VIA_WEBSITE is 0. Both stay valid, so
	 * links already sent keep working whichever is chosen.
	 *
	 * @return string
	 */
	public function linkBase()
	{
		$site = rtrim(getDolGlobalString('ONBOARDING_WEBSITE_URL'), '/');
		if ($site !== '' && getDolGlobalString('ONBOARDING_LINKS_VIA_WEBSITE', '1') !== '0') {
			return $site.'/training/link/';
		}
		return dol_buildpath('/onboarding/public/booking.php', 2);
	}

	/**
	 * @param object $c Class
	 * @return string The trainer's link to confirm the class list
	 */
	public function classLink($c)
	{
		return $this->linkBase().'?c='.((int) $c->rowid).'&k='.$this->sign('class:'.((int) $c->rowid));
	}

	/**
	 * @param object $b Booking
	 * @param string $for trainee, trainer or staff
	 * @return array<string,string> Email placeholders
	 */
	public function vars($b, $for = 'trainee')
	{
		$start = $this->db->jdate($b->session_start);
		$who = $for == 'trainer' ? 'trainer' : 'trainee';
		$p = $this->svc->findByEmail($b->email);
		$minor = $p && !empty($p->birthdate) && self::age($this->db->jdate($p->birthdate), $start ? $start : OnboardingService::now()) < 18;
		$v = array(
			'firstname' => trim((string) $b->firstname) !== '' ? (string) $b->firstname : 'there',
			'trainee' => self::shortName($b->firstname, $b->lastname),
			'trainee_full' => trim($b->firstname.' '.$b->lastname).' <'.$b->email.'>',
			'kind' => ($b->price_type == 'member' ? 'member' : 'non-member').($minor ? ', under 18'.(!empty($p->guardian_name) ? ' (guardian: '.$p->guardian_name.')' : '') : '').', waiver signed',
			'minor_note' => $minor ? "\n\nThey are under 18. A parent or guardian signed their waiver".(getDolGlobalString('ONBOARDING_TRAINING_GUARDIAN_PRESENT', '1') ? ' and must come with them' : '').'.' : '',
			'tool' => (string) $b->tool,
			'zone' => (string) $b->zone,
			'trainer' => (string) $b->trainer_first,
			'trainer_full' => trim($b->trainer_first.' '.$b->trainer_last),
			'when' => $start ? self::fmt($start) : '(no date yet)',
			'minutes' => (string) $b->minutes,
			'ref' => (string) $b->ref,
			'price' => '$'.number_format((float) $b->price, 2),
			'paid' => (float) $b->amount_paid > 0 ? '$'.number_format((float) $b->amount_paid, 2).($b->gb_transaction_id ? ' (Givebutter '.$b->gb_transaction_id.')' : '') : 'nothing (free)',
			'wiki' => (string) $b->wiki_url !== '' ? (string) $b->wiki_url : '(ask your trainer)',
			'cancel_hours' => (string) self::num('TRAINING_CANCEL_HOURS', 2),
			'rebook_days' => (string) self::num('TRAINING_REBOOK_DAYS', 60),
			'lookup_url' => getDolGlobalString('ONBOARDING_TRAINING_LOOKUP_URL', '(ask a member of staff)'),
			'org' => getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'the makerspace'),
			'card' => dol_buildpath('/onboarding/trainings.php', 2).'?booking='.((int) $b->rowid),
			'comment' => (string) $b->comment,
			'roster' => '',
			'again' => '',
			'email' => (string) $b->email,
			'expires' => $b->hold_until ? self::fmt($this->db->jdate($b->hold_until), 'g:i A') : '',
			'hours' => (string) self::SPOT_HOURS,
		);
		$tool = $b->source == 'spot' ? $this->tool((int) $b->fk_tool) : null;
		if ($tool) {
			$v['prices'] = '$'.number_format(self::memberPrice($tool), 2).' for members, $'.number_format((float) $tool->fee, 2).' for everyone else';
		}
		if ($who == 'trainee') {
			$v['link'] = $this->link($b, 'trainee');
			$v['link_cancel'] = $this->link($b, 'trainee', 'cancel');
			$v['link_move'] = $this->link($b, 'trainee', 'move');
			$v['link_refund'] = $this->link($b, 'trainee', 'refund');
			$v['link_other'] = $this->link($b, 'trainee', 'other');
			$v['link_no_trainer'] = $this->link($b, 'trainee', 'no_trainer');
			$v['link_yes'] = $this->link($b, 'trainee', 'spot_yes');
			$v['link_no'] = $this->link($b, 'trainee', 'spot_no');
		}
		if ($who == 'trainer') {
			$v['link_confirm'] = $this->link($b, 'trainer', 'confirm');
			$v['link_cant'] = $this->link($b, 'trainer', 'cant');
			$v['link_trained'] = $this->link($b, 'trainer', 'trained');
			$v['link_noshow'] = $this->link($b, 'trainer', 'noshow');
			$v['link_other'] = $this->link($b, 'trainer', 'other');
			$rows = array();
			foreach ($this->sessionOf($b, array('paid', 'confirmed')) as $o) {
				$rows[] = '- '.self::shortName($o->firstname, $o->lastname).' ('.$this->vars($o, 'staff')['kind'].')';
			}
			$v['roster'] = $rows ? implode("\n", $rows) : '- '.$v['trainee'];
			$v['seats'] = count($rows).' of '.$this->seatsFor($b);
		}
		return $v;
	}

	/**
	 * @param int $birth Birth date
	 * @param int $on The day it matters
	 * @return int Age in whole years
	 */
	public static function age($birth, $on)
	{
		$b = new DateTime('@'.((int) $birth));
		$o = new DateTime('@'.((int) $on));
		return (int) $b->diff($o)->y;
	}

	/**
	 * @return string[] Who settles problems: the secretary, else the membership team
	 */
	public function staffEmails()
	{
		$p = new OnboardingPurchase($this->db, $this->svc);
		$out = $p->roleEmails(array('secretary'));
		if (!$out && getDolGlobalString('ONBOARDING_STAFF_EMAIL')) {
			$out = array(getDolGlobalString('ONBOARDING_STAFF_EMAIL'));
		}
		return $out;
	}

	/**
	 * @return string[] Who makes refunds: the treasurer, with the secretary copied
	 */
	public function treasurerEmails()
	{
		$p = new OnboardingPurchase($this->db, $this->svc);
		return array_values(array_unique(array_merge($p->treasurers(), $this->staffEmails())));
	}

	/**
	 * The emails, by name: subject and text. Each can be replaced from the setup
	 * page through ONBOARDING_MAIL_BK_<NAME>_SUBJECT and _BODY.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function mails()
	{
		return array(
			'sign' => array('Sign to book your {org} training', "Hi {firstname},\n\nBefore you can book a training we need {what} on file. It takes two minutes, on your phone or computer:\n\n{link}\n\nAfter signing you come straight back to the booking page.\n\nIf you did not ask for this, you can ignore this email."),
			'booked' => array('You are booked: {tool} training, {when} ({ref})', "Hi {firstname},\n\nYou are booked on the {tool} training with {trainer} on {when} at the shop. Paid: {paid}.\n\nBefore you come, read the guide: {wiki}\n\nYour trainer will confirm the session; you will hear from us if anything changes.\n\nNeed to cancel training? Click here (works until {cancel_hours} hours before):\n{link_cancel}\n\nCancelling at least {cancel_hours} hours before gets you a full refund or a new date."),
			'moved' => array('New date: {tool} training, {when} ({ref})', "Hi {firstname},\n\nYour {tool} training is now with {trainer} on {when}. Your trainer will confirm it.\n\nBefore you come, read the guide: {wiki}\n\nNeed to cancel training? Click here (works until {cancel_hours} hours before):\n{link_cancel}"),
			'lost_date' => array('Your {tool} training on {when} is cancelled ({ref})', "Hi {firstname},\n\nSorry: your {tool} training on {when} is not going ahead. {reason}\n\nYou have three choices:\n\nReschedule (pick a new date, no new fee):\n{link_move}\n\nRefund (your money back):\n{link_refund}\n\nOther (tell us what you need):\n{link_other}"),
			'refund_asked' => array('Refund on its way: {tool} training ({ref})', "Hi {firstname},\n\nYour {tool} training booking is cancelled and the treasurer has been asked to refund {paid}. Refunds go back to the card you paid with and can take a few days to show."),
			'refunded' => array('Refunded: {tool} training ({ref})', "Hi {firstname},\n\nWe have refunded your {tool} training fee ({paid}). It can take a few days to show on your statement."),
			'dayof' => array('Today: {tool} training at {when}', "Hi {firstname},\n\nSee you today: {tool} training with {trainer}, {when}.{minor_note}\n\nIf you have not yet, read the guide: {wiki}\n\nNeed to cancel training? Click here (works until {cancel_hours} hours before):\n{link_cancel}"),
			'trained' => array('You are trained on the {tool}', "Hi {firstname},\n\nYou are now on record as trained to use the {tool} ({zone}).\n\nYou can check what you are trained on at any time: {lookup_url}"),
			'missed_free' => array('We missed you at your {tool} training ({ref})', "Hi {firstname},\n\n{trainer} says you did not make it to the {tool} training on {when}. You can pick a new date once, at no charge, within {rebook_days} days:\n\n{link_move}\n\nSomething else? {link_other}"),
			'missed_final' => array('We missed you again at your {tool} training ({ref})', "Hi {firstname},\n\n{trainer} says you did not make it to the {tool} training on {when}. That was your free new date, so the fee is kept. You are welcome to book again on the website."),
			'trainee_followup' => array('Did your {tool} training happen? ({ref})', "Hi {firstname},\n\nYou were booked on the {tool} training with {trainer} on {when}, and we have not heard from your trainer yet.\n\nIf you were trained, there is nothing to do: you will get an email as soon as {trainer} records it.\n\nIf your trainer didn't show up, tell us here and we will sort out a new date or a refund:\n{link_no_trainer}"),
			'trainer_request' => array('{again}Training booked: {tool}, {when} ({ref})', "Hi {trainer},\n\n{trainee} ({kind}) booked the {tool} training with you on {when} and paid {price}.{minor_note}\n\nOn this session ({seats} seats taken):\n{roster}\n\nWill you be there?\n\nI'll be there:\n{link_confirm}\n\nI can't make it (they will be asked to pick a new date):\n{link_cant}\n\nIf you have not answered 24 hours before the session, it is cancelled and they are asked to pick a new date."),
			'trainer_added' => array('Another booking: {tool}, {when} ({ref})', "Hi {trainer},\n\n{trainee} ({kind}) joined your {tool} training on {when}. You already confirmed this session.{minor_note}\n\nOn this session ({seats} seats taken):\n{roster}\n\nIf you can no longer make it:\n{link_cant}"),
			'trainer_freed' => array('A seat is free: {tool}, {when}', "Hi {trainer},\n\n{trainee} is no longer coming to your {tool} training on {when}. Nothing for you to do."),
			'trainer_dayof' => array('Today: {tool} training at {when}', "Hi {trainer},\n\nYou are training today: {tool}, {when}.\n\nComing:\n{roster}\n\nIf you can no longer make it:\n{link_cant}"),
			'trainer_after' => array('{again}How did the {tool} training go? ({trainee}, {ref})', "Hi {trainer},\n\nThe {tool} training on {when} is over. For {trainee}, choose one:\n\n{trainee} showed up, and I performed the training:\n{link_trained}\n\n{trainee} did not show up, do not mark them as trained:\n{link_noshow}\n\nOther (leave a comment for the secretary):\n{link_other}"),
			'staff_noanswer' => array('Trainer did not confirm: {tool}, {when} ({ref})', "{trainer_full} did not confirm the {tool} training on {when} in time, so {trainee_full} was asked to pick a new date or ask for a refund.\n\n{card}"),
			'staff_refund' => array('Refund to make: {tool} training ({ref})', "{trainee_full} asked for a refund of their {tool} training on {when}. Paid: {paid}.\n\nComment: {comment}\n\nRefund it in Givebutter (Transactions, find the payment, Refund), then press Mark refunded on the booking in Dolibarr:\n{card}"),
			'staff_trainer_other' => array('Trainer comment on a training: {tool} ({ref})', "{trainer_full} left a comment on the {tool} training of {trainee_full} on {when}:\n\n{comment}\n\nSettle it on the booking in Dolibarr (Mark trained, Mark no-show, Send a new date link, Refund):\n{card}"),
			'staff_trainee_other' => array('Trainee needs something: {tool} training ({ref})', "{trainee_full} wrote about their {tool} training on {when}:\n\n{comment}\n\nThe booking in Dolibarr:\n{card}"),
			'staff_no_trainer' => array('Trainer did not show up: {tool} training ({ref})', "{trainee_full} says {trainer_full} did not show up for the {tool} training on {when}.\n\nComment: {comment}\n\nOffer them a new date or a refund from the booking in Dolibarr:\n{card}"),
			'staff_unanswered' => array('No answer from the trainer: {tool} training ({ref})', "{trainer_full} has not said whether {trainee_full} was trained on the {tool} on {when}. The trainee has been asked whether it happened.\n\nThe booking in Dolibarr:\n{card}"),
			'staff_rebook_unused' => array('Free rebooking not used: {tool} training ({ref})', "{trainee_full} has not picked a new date for their {tool} training in {rebook_days} days.\n\nDecide what to do (send the link again, refund, or cancel):\n{card}"),
			'class_created' => array('Group class: {tool}, {when} (code {code})', "A group class is set up: {tool} with {trainer}, {when}, {seats} seats.\n\nAttendees sign in on their phones at\n{class_url}\n\nor at the class page with the code {code}. The code works from 30 minutes before the start to 3 hours after the end. Print the QR code from the class page in Dolibarr:\n{card}"),
			'class_roster' => array('{again}Group class list to confirm: {tool}, {when}', "Hi {trainer},\n\n{count} people signed in to the {tool} class on {when}:\n\n{roster}\n\nConfirm who was trained (untick anyone who left early):\n{link_class}"),
			'staff_class_unanswered' => array('Group class list not confirmed: {tool}, {when}', "{trainer} has not confirmed who was trained at the {tool} class on {when} (code {code}).\n\n{roster}\n\nConfirm it on the class page in Dolibarr:\n{card}"),
			'staff_unmatched' => array('Training payment without a booking', "{who} paid {paid} on the training campaign (Givebutter {tx}), but no booking waiting for payment has that email and price. Usually they typed another email at Givebutter, or paid after their seat was let go.\n\nLink it to their booking under Members, Onboarding, Trainings, or refund it in Givebutter:\n{card}"),
			'wrong_amount' => array('Your training payment did not match your booking ({ref})', "Hi {firstname},\n\nWe received {paid_now} from you on Givebutter, but your {tool} training on {when} costs {price}, so the payment could not be matched to your booking and your seat is not confirmed yet.\n\nThe secretary has been told and will refund it or sort out the difference with you. You do not need to pay again."),
			'staff_wrong_amount' => array('Training paid with the wrong amount: {ref}', "{trainee_full} paid {paid_now} on Givebutter (transaction {tx}) for booking {ref}, {tool} on {when}, which costs {price}. The booking is not marked paid.\n\nEither refund the payment in Givebutter and ask them to book again, or, if you accept the amount, link the payment to the booking under Members, Onboarding, Trainings (Paid without a booking):\n{card}"),
			'spot_link' => array('Train someone now: your link', "Hi {trainer},\n\nHere is your link to start an on-the-spot training ({tools}). It works until {until}:\n\n{link}\n\nPick the tool and type the trainee's email address. They get an email asking whether they asked for the training; if they say yes, they sign the waiver and pay online, and you get an email to record the training once it is done. A request they have not paid within {hours} hours expires.\n\nIf you did not ask for this link, ignore it."),
			'spot_request' => array('{trainer_full} wants to train you on the {tool} now ({ref})', "Hi {firstname},\n\n{trainer_full} says you are with them at {org} and would like to train you on the {tool} now.\n\nDid you ask for this training?\n\nYes, that's me (sign the waiver if needed, then pay: {prices}):\n{link_yes}\n\nNo, I didn't ask for this:\n{link_no}\n\nThis request expires at {expires}, {hours} hours after it was sent. After that, ask your trainer to send a new one."),
			'spot_declined' => array('Training request declined: {tool} ({ref})', "Hi {trainer},\n\nThe person at {email} answered that they did not ask for a {tool} training, so the request is cancelled and nothing was charged. The secretary has been told.\n\nIf the email was mistyped, send a new request with the right address."),
			'staff_spot_declined' => array('On-the-spot training declined: {tool} ({ref})', "{trainer_full} started an on-the-spot {tool} training for {email}, and that person answered that they did not ask for it. The request is cancelled; nothing was charged.\n\nCheck with the trainer what happened:\n{card}"),
			'spot_expired' => array('Training request expired: {tool} ({ref})', "Hi {trainer},\n\nYour on-the-spot {tool} training request for {email} was not paid within {hours} hours, so it has expired. Nothing was charged.\n\nIf they still want the training, send a new request."),
			'spot_paid' => array('Paid: {tool} training with {trainer} ({ref})', "Hi {firstname},\n\nThank you: your payment of {paid} for the {tool} training with {trainer_full} arrived. Once the training is done, {trainer} records it and you get an email saying you are trained.\n\nThe guide for the {tool}: {wiki}\n\nIf something is wrong, tell us here:\n{link_other}"),
			'spot_trainer_paid' => array('Paid: {tool} training for {trainee} ({ref})', "Hi {trainer},\n\n{trainee} ({kind}) has paid {paid} for the {tool} training.{minor_note}\n\nWhen you have finished, choose one:\n\n{trainee} was trained:\n{link_trained}\n\nThe training did not happen, do not mark them as trained (they can pick a date or ask for a refund):\n{link_noshow}\n\nOther (leave a comment for the secretary):\n{link_other}"),
			'pick_date' => array('Pick a new date for your {tool} training ({ref})', "Hi {firstname},\n\nPick a new date for your {tool} training here:\n\n{link_move}"),
		);
	}

	/**
	 * Send one of the emails above.
	 *
	 * @param string $name Email name
	 * @param string|string[] $to Recipient(s)
	 * @param array<string,string> $vars Placeholders
	 * @param string $cc Copy to
	 * @param string $replyTo Extra reply-to address (the person's own is always first)
	 * @return bool
	 */
	public function mailTpl($name, $to, $vars, $cc = '', $replyTo = '')
	{
		$all = self::mails();
		if (!isset($all[$name])) {
			return false;
		}
		$key = 'ONBOARDING_MAIL_BK_'.strtoupper($name);
		$subject = getDolGlobalString($key.'_SUBJECT', $all[$name][0]);
		$body = getDolGlobalString($key.'_BODY', $all[$name][1]);
		$vars += array('org' => getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'the makerspace'));
		$map = array();
		foreach ($vars as $k => $v) {
			$map['{'.$k.'}'] = (string) $v;
		}
		$to = implode(', ', array_filter((array) $to));
		if ($to === '') {
			return false;
		}
		$reply = '';
		if ($replyTo !== '') {
			$reply = $to.', '.$replyTo;
		}
		return $this->svc->mail($to, strtr($subject, $map), strtr($body, $map), $cc, $reply);
	}
}
