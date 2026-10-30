<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');
dol_include_once('/onboarding/class/training.class.php');

/**
 * Purchase requests: a member asks, on the website, to spend from a zone's budget.
 *
 * Two kinds. "reimburse": already bought, a receipt is attached. "purchase": the
 * treasurer buys it, so the request carries a web link and the total price.
 *
 * The member proves who they are with the email on their member card plus the two
 * digits of their member_code (CGW-05, 05 or 140405 all mean 05). That is a weak
 * secret, so failures are counted per email, per address and for the whole form,
 * and a locked email stays locked for a day. Nothing is paid without a person
 * approving it and the treasurer pressing Mark paid.
 *
 * Who may file: anyone on the board for any zone or the General fund, a zone boss for
 * any zone, nobody else (see allowedZones). The money comes out of the zone named on
 * the request, and a reimbursement goes to whoever filed it.
 *
 * Who approves (see needs): the treasurer, so it can be bought or paid back. Over the
 * limit ($100) or over the zone's budget, the president too. Filed for a zone by
 * someone who is not its boss, that zone's boss too. Nobody approves their own: when
 * the treasurer or the president asks, another director takes their place.
 */
class OnboardingPurchase
{
	const KINDS = array('reimburse' => 'Reimburse me', 'purchase' => 'Request for purchase');
	const PAY_METHODS = array('venmo' => 'Venmo', 'check' => 'Check');
	const MAX_FILES = 5;
	const MAX_FILE_BYTES = 5242880;
	const MAX_AMOUNT = 5000;
	const MAX_ITEMS = 10;
	const BAD_EMAIL_LIMIT = 5;
	const BAD_IP_LIMIT = 20;
	const BAD_ALL_LIMIT = 100;
	// Not a zone: money the organization keeps for itself. Anyone allowed to file for
	// any zone may file for it, it has no budget to check, and it always needs the board.
	const GENERAL = 'General fund';
	// Roles are tags on a member card (Members, the member, Tags). The tag is the role's name
	// in lower case; a zone boss's tag is the zone in lower case with hyphens, plus -boss
	// (woodworking-boss, digital-fab-boss). Whoever holds the tag today is the person, with
	// their email and member code taken from their card, so moving a tag moves the role.
	const ROLE_PRESIDENT = 'president';
	const ROLE_TREASURER = 'treasurer';
	const BOARD_ROLES = array('president', 'vice-president', 'secretary', 'treasurer', 'director');
	const ROLES_PARENT = 'Roles';
	/** Roles that are not board seats. */
	const OTHER_ROLES = array('security');

	/** @var DoliDB */
	public $db;
	/** @var OnboardingService */
	public $svc;
	/** @var OnboardingTraining */
	public $training;

	/**
	 * @param DoliDB $db Database handler
	 * @param OnboardingService|null $svc Service, created when missing
	 */
	public function __construct($db, $svc = null)
	{
		$this->db = $db;
		$this->svc = $svc ? $svc : new OnboardingService($db);
		$this->training = new OnboardingTraining($db, $this->svc);
	}

	// ---------------------------------------------------------------- settings

	/**
	 * @return float Largest request a zone lead may approve alone
	 */
	public static function leadLimit()
	{
		return (float) price2num(getDolGlobalString('ONBOARDING_PURCHASE_LEAD_LIMIT', '100'));
	}

	/**
	 * @return int Days after buying that a receipt is still accepted
	 */
	public static function receiptDays()
	{
		return max(1, (int) getDolGlobalString('ONBOARDING_PURCHASE_RECEIPT_DAYS', '60'));
	}

	/**
	 * @return string Secret that signs the approve and reject links
	 */
	private function secret()
	{
		global $conf;
		$secret = getDolGlobalString('ONBOARDING_PURCHASE_SECRET');
		if ($secret === '') {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			$secret = bin2hex(random_bytes(24));
			dolibarr_set_const($this->db, 'ONBOARDING_PURCHASE_SECRET', $secret, 'chaine', 0, 'Signs the approve and reject links in purchase request emails', $conf->entity);
		}
		return $secret;
	}

	// ---------------------------------------------------------------- roles

	/**
	 * @param string $zone Zone name, for example Digital Fab
	 * @return string The tag that makes someone that zone's boss, for example digital-fab-boss
	 */
	public static function bossRole($zone)
	{
		return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $zone)), '-').'-boss';
	}

	/**
	 * Members holding any of these roles, read from their tags every time, so moving a tag
	 * to someone else moves the role. Only validated members count.
	 *
	 * @param string[] $roles Tag names, lower case
	 * @return object[] One row per member, with a roles list
	 */
	public function roleMembers($roles)
	{
		if (!isModEnabled('categorie')) {
			return array();
		}
		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
		$in = array();
		foreach ($roles as $r) {
			$in[] = "'".$this->db->escape(strtolower((string) $r))."'";
		}
		if (!$in) {
			return array();
		}
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT a.rowid, a.firstname, a.lastname, a.email, a.statut, a.datefin, e.member_code, e.dues_waived_until, LOWER(c.label) AS rolename FROM ".$p."categorie AS c INNER JOIN ".$p."categorie_member AS cm ON cm.fk_categorie = c.rowid INNER JOIN ".$p."adherent AS a ON a.rowid = cm.fk_member LEFT JOIN ".$p."adherent_extrafields AS e ON e.fk_object = a.rowid WHERE LOWER(c.label) IN (".implode(',', $in).") AND c.entity IN (".getEntity('category').") AND a.entity IN (".getEntity('adherent').") AND a.statut = 1 ORDER BY a.rowid ASC");
		$out = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			if (!isset($out[(int) $o->rowid])) {
				$o->roles = array();
				$out[(int) $o->rowid] = $o;
			}
			$out[(int) $o->rowid]->roles[] = (string) $o->rolename;
		}
		return array_values($out);
	}

	/**
	 * @param string[] $roles Tag names
	 * @return string[] Their holders' emails, lower case, each once
	 */
	public function roleEmails($roles)
	{
		$out = array();
		foreach ($this->roleMembers($roles) as $m) {
			$e = OnboardingService::cleanEmail($m->email);
			if ($e !== '' && strpos($e, '@') !== false) {
				$out[$e] = $e;
			}
		}
		return array_values($out);
	}

	/**
	 * @return string[] Treasurers' emails (normally one)
	 */
	public function treasurers()
	{
		return $this->roleEmails(array(self::ROLE_TREASURER));
	}

	/**
	 * @return string[] Everyone on the board who may approve a big request, treasurer included
	 */
	public function directors()
	{
		return $this->roleEmails(self::BOARD_ROLES);
	}

	/**
	 * @param string $zone Zone
	 * @return object[] Its bosses
	 */
	public function leadsOf($zone)
	{
		return $this->roleMembers(array(self::bossRole($zone)));
	}

	/**
	 * Make the role tags, under a Roles tag, so they only have to be given out.
	 *
	 * @return string Summary
	 */
	public function installRoleTags()
	{
		if (!isModEnabled('categorie')) {
			return 'Tags (the Categories module) are turned off, so roles cannot be tagged.';
		}
		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
		$parent = $this->ensureTag(self::ROLES_PARENT, 0, 'Who holds which role. Tag a member with a role to give it to them; remove the tag to take it away.');
		if (!$parent) {
			return 'The Roles tag could not be created.';
		}
		$labels = array_merge(self::BOARD_ROLES, self::OTHER_ROLES);
		foreach ($this->training->zones() as $z) {
			$labels[] = self::bossRole($z);
		}
		$made = 0;
		foreach ($labels as $label) {
			$cat = new Categorie($this->db);
			if ($cat->fetch(0, $label, Categorie::TYPE_MEMBER) <= 0) {
				if ($this->ensureTag($label, (int) $parent->id, 'Role: '.$label)) {
					$made++;
				}
			}
		}
		return $made.' role tags created, '.(count($labels) - $made).' already there.';
	}

	/**
	 * @param string $label Tag label
	 * @param int $parentId Parent tag, 0 for none
	 * @param string $description Description for a new tag
	 * @return Categorie|null Found or created member tag
	 */
	private function ensureTag($label, $parentId, $description)
	{
		$cat = new Categorie($this->db);
		if ($cat->fetch(0, $label, Categorie::TYPE_MEMBER) > 0) {
			return $cat;
		}
		$cat = new Categorie($this->db);
		$cat->label = $label;
		$cat->type = Categorie::TYPE_MEMBER;
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
	 * For the setup page: every role, what it does, and who holds it right now.
	 *
	 * @return array<int,array{role:string,what:string,holders:array<int,array{name:string,email:string,code:bool}>}>
	 */
	public function roleTable()
	{
		$what = array(
			'president' => 'Files for any zone or the General fund. Approves requests over the limit or over a zone\'s budget. Principal officer on the IRS 990-N; gets questions from the tax filing page.',
			'vice-president' => 'Files for any zone or the General fund. Approves in place of the treasurer or the president when they file.',
			'secretary' => 'Files for any zone or the General fund. Approves in place of the treasurer or the president when they file.',
			'treasurer' => 'Files for any zone or the General fund. Approves every request, then orders it or pays the member back. Gets the yearly tax filing emails.',
			'director' => 'Files for any zone or the General fund. Approves in place of the treasurer or the president when they file.',
			'security' => 'The security owner: warned before a Dolibarr login is disabled, and sent the list of other accounts to remove the person from. Nobody tagged: the secretary.',
		);
		$roles = array();
		foreach (array_merge(self::BOARD_ROLES, self::OTHER_ROLES) as $r) {
			$roles[$r] = $what[$r];
		}
		foreach ($this->training->zones() as $z) {
			$roles[self::bossRole($z)] = 'Files for '.$z.' (or another zone, which that zone\'s boss then approves), and approves requests others file for '.$z.'.';
		}
		$held = array();
		foreach ($this->roleMembers(array_keys($roles)) as $m) {
			foreach ($m->roles as $r) {
				$held[$r][] = array('name' => trim($m->firstname.' '.$m->lastname), 'email' => (string) $m->email, 'code' => self::codeDigits($m->member_code) !== '');
			}
		}
		$out = array();
		foreach ($roles as $role => $text) {
			$out[] = array('role' => $role, 'what' => $text, 'holders' => isset($held[$role]) ? $held[$role] : array());
		}
		return $out;
	}

	// ---------------------------------------------------------------- who is asking

	/**
	 * Reduce what a member typed as their code to its two digits.
	 *
	 * @param string $in "05", "CGW-05", "cgw 5" or "140405"
	 * @return string Two digits, or empty when it is none of those
	 */
	public static function normalizeCode($in)
	{
		$in = trim((string) $in);
		if (preg_match('/^1404(\d{2})$/', $in, $m)) {
			return $m[1];
		}
		if (preg_match('/^\d{2}$/', $in)) {
			return $in;
		}
		if (preg_match('/^cgw[\s-]?(\d{1,2})$/i', $in, $m)) {
			return str_pad($m[1], 2, '0', STR_PAD_LEFT);
		}
		return '';
	}

	/**
	 * @param string $memberCode Stored member_code, for example CGW-05
	 * @return string Its trailing two digits, or empty
	 */
	public static function codeDigits($memberCode)
	{
		return preg_match('/(\d{1,2})$/', (string) $memberCode, $m) ? str_pad($m[1], 2, '0', STR_PAD_LEFT) : '';
	}

	/**
	 * @param string $where SQL condition on table a (adherent)
	 * @return object|null Member row with member_code and dues_waived_until
	 */
	private function memberWhere($where)
	{
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT a.rowid, a.firstname, a.lastname, a.email, a.statut, a.datefin, e.member_code, e.dues_waived_until FROM ".$p."adherent AS a LEFT JOIN ".$p."adherent_extrafields AS e ON e.fk_object = a.rowid WHERE ".$where." AND a.entity IN (".getEntity('adherent').") ORDER BY a.rowid ASC LIMIT 1");
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param string $email Email on the member card
	 * @return object|null
	 */
	public function memberByEmail($email)
	{
		return $this->memberWhere("LOWER(a.email) = '".$this->db->escape(OnboardingService::cleanEmail($email))."'");
	}

	/**
	 * @param int $id Member id
	 * @return object|null
	 */
	public function memberById($id)
	{
		return $this->memberWhere('a.rowid = '.((int) $id));
	}

	/**
	 * @param float|string $n Amount
	 * @return string For example $12.50, plain text for emails
	 */
	public static function money($n)
	{
		return '$'.number_format((float) $n, 2);
	}

	/**
	 * Active and paid up: validated, and either paid through today or waived through today.
	 *
	 * @param object $m Member row from memberWhere
	 * @return bool
	 */
	public function isPayingMember($m)
	{
		if (!$m || (int) $m->statut != 1) {
			return false;
		}
		$today = dol_mktime(0, 0, 0, (int) dol_print_date(OnboardingService::now(), '%m'), (int) dol_print_date(OnboardingService::now(), '%d'), (int) dol_print_date(OnboardingService::now(), '%Y'));
		if (!empty($m->datefin) && $this->db->jdate($m->datefin) >= $today) {
			return true;
		}
		return !empty($m->dues_waived_until) && $this->db->jdate($m->dues_waived_until) >= $today;
	}

	// ---------------------------------------------------------------- guessing limits

	/**
	 * @param string $kind email or ip
	 * @param string $value The email or address
	 * @return string Bucket name
	 */
	private static function bucket($kind, $value)
	{
		return $kind == 'all' ? 'all' : substr($kind[0].':'.sha1(strtolower((string) $value)), 0, 64);
	}

	/**
	 * @param string $bucket Bucket
	 * @param int $seconds Window
	 * @return int Failures in the window
	 */
	private function failures($bucket, $seconds)
	{
		$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_attempt WHERE bucket = '".$this->db->escape($bucket)."' AND datec > '".$this->db->idate(OnboardingService::now() - $seconds)."'");
		$obj = $res ? $this->db->fetch_object($res) : null;
		return $obj ? (int) $obj->n : 0;
	}

	/**
	 * @param string $email Email being tried
	 * @param string $ip Address it came from
	 * @return bool True when this email, this address or the whole form has had too many failures
	 */
	public function locked($email, $ip)
	{
		if ($this->failures(self::bucket('email', $email), 86400) >= self::BAD_EMAIL_LIMIT) {
			return true;
		}
		if ($ip !== '' && $this->failures(self::bucket('ip', $ip), 3600) >= self::BAD_IP_LIMIT) {
			return true;
		}
		return $this->failures(self::bucket('all', ''), 3600) >= self::BAD_ALL_LIMIT;
	}

	/**
	 * @param string $email Email that failed
	 * @param string $ip Address it came from
	 * @return void
	 */
	private function recordFailure($email, $ip)
	{
		$buckets = array(self::bucket('email', $email), self::bucket('all', ''));
		if ($ip !== '') {
			$buckets[] = self::bucket('ip', $ip);
		}
		foreach ($buckets as $b) {
			$this->db->query("INSERT INTO ".$this->db->prefix()."onboarding_attempt (bucket, datec) VALUES ('".$this->db->escape($b)."', '".$this->db->idate(OnboardingService::now())."')");
		}
	}

	// ---------------------------------------------------------------- zones and money

	/**
	 * @return string[] Every zone, then the General fund: what a request can name
	 */
	public function zoneNames()
	{
		return array_merge($this->training->zones(), array(self::GENERAL));
	}

	/**
	 * @return int[] Members who may file for any zone or the General fund: the board
	 */
	public function anyZoneMembers()
	{
		$ids = array();
		foreach ($this->roleMembers(self::BOARD_ROLES) as $m) {
			$ids[] = (int) $m->rowid;
		}
		return $ids;
	}

	/**
	 * @param object|null $m Member row
	 * @return string[] Role tags this member holds now
	 */
	public function rolesOf($m)
	{
		if (!$m) {
			return array();
		}
		$roles = array_merge(self::BOARD_ROLES, self::OTHER_ROLES);
		foreach ($this->training->zones() as $z) {
			$roles[] = self::bossRole($z);
		}
		foreach ($this->roleMembers($roles) as $r) {
			if ((int) $r->rowid === (int) $m->rowid) {
				return $r->roles;
			}
		}
		return array();
	}

	/**
	 * What this member may file for: the board, any zone and the General fund; a zone
	 * boss, any zone (another zone's boss then approves it too); everyone else, nothing.
	 *
	 * @param object $m Member row
	 * @return string[] Zone names
	 */
	public function allowedZones($m)
	{
		if (in_array((int) $m->rowid, $this->anyZoneMembers(), true)) {
			return $this->zoneNames();
		}
		foreach ($this->rolesOf($m) as $r) {
			if (substr($r, -5) === '-boss') {
				return $this->training->zones();
			}
		}
		return array();
	}

	/**
	 * Who has to approve a request: each entry is one person's approval. treasurer,
	 * president, director (anyone else on the board) or boss (of the request's zone).
	 * Worked out when it is filed and kept with it.
	 *
	 * @param object $row Request
	 * @param object|null $member Who filed it
	 * @return string[]
	 */
	public function needs($row, $member = null)
	{
		$kept = json_decode((string) (isset($row->needs) ? $row->needs : ''), true);
		if (is_array($kept) && $kept) {
			return $kept;
		}
		if ($row->status != 'draft') {
			// Filed before the rules changed.
			return $row->need_board ? array('treasurer', 'director') : array('boss');
		}
		$roles = $this->rolesOf($member ? $member : $this->memberById((int) $row->fk_adherent));
		$big = (float) $row->amount > self::leadLimit() || ($row->zone !== self::GENERAL && (float) $row->amount > $this->available($row->zone));
		// Nobody holds the tag, or they are the one asking: another director instead.
		$out = array(in_array(self::ROLE_TREASURER, $roles, true) || !$this->treasurers() ? 'director' : 'treasurer');
		if ($big) {
			$out[] = in_array(self::ROLE_PRESIDENT, $roles, true) || !$this->roleEmails(array(self::ROLE_PRESIDENT)) ? 'director' : 'president';
		}
		if ($row->zone !== self::GENERAL && !in_array(self::bossRole($row->zone), $roles, true) && $this->leadsOf($row->zone)) {
			$out[] = 'boss';
		}
		return $out;
	}

	/**
	 * @param object $row Request
	 * @param string[]|null $needs Defaults to the request's own
	 * @return string "the treasurer, the president and the Woodworking boss"
	 */
	public function needsText($row, $needs = null)
	{
		$names = array('treasurer' => 'the treasurer', 'president' => 'the president', 'director' => 'another director', 'boss' => 'the '.$row->zone.' boss');
		$out = array();
		foreach ($needs === null ? $this->needs($row) : $needs as $n) {
			$out[] = isset($names[$n]) ? $names[$n] : $n;
		}
		$last = array_pop($out);
		return $out ? implode(', ', $out).' and '.$last : (string) $last;
	}

	/**
	 * Are the approvals enough? Each board approval (treasurer, president, director)
	 * must be a different person; the zone boss may also be one of them.
	 *
	 * @param string[] $needs From needs()
	 * @param array<int,array{email:string,seats?:string[]}> $approvals So far
	 * @return bool
	 */
	public static function enough($needs, $approvals)
	{
		foreach ($approvals as $i => $a) {
			if (!isset($a['seats'])) {
				// Approved before the rules changed.
				$r = isset($a['role']) ? $a['role'] : '';
				$approvals[$i]['seats'] = $r == 'lead' ? array('boss') : ($r == 'treasurer' ? array('treasurer', 'director') : array('director'));
			}
		}
		$board = array_values(array_diff($needs, array('boss')));
		if (in_array('boss', $needs, true)) {
			$boss = false;
			foreach ($approvals as $a) {
				$boss = $boss || in_array('boss', isset($a['seats']) ? $a['seats'] : array(), true);
			}
			if (!$boss) {
				return false;
			}
		}
		$fill = function ($i, $used) use (&$fill, $board, $approvals) {
			if ($i >= count($board)) {
				return true;
			}
			foreach ($approvals as $a) {
				if (!in_array($a['email'], $used, true) && in_array($board[$i], isset($a['seats']) ? $a['seats'] : array(), true)) {
					if ($fill($i + 1, array_merge($used, array($a['email'])))) {
						return true;
					}
				}
			}
			return false;
		};
		return $fill(0, array());
	}

	/**
	 * @param string $zone Zone
	 * @return float Requests approved or waiting, not yet paid
	 */
	public function committed($zone)
	{
		global $conf;
		$res = $this->db->query("SELECT COALESCE(SUM(amount), 0) AS s FROM ".$this->db->prefix()."onboarding_purchase WHERE entity = ".((int) $conf->entity)." AND zone = '".$this->db->escape($zone)."' AND status IN ('pending', 'approved')");
		$obj = $res ? $this->db->fetch_object($res) : null;
		return $obj ? round((float) $obj->s, 2) : 0.0;
	}

	/**
	 * What /budget shows: totals per zone, never names or transactions.
	 * allocated = everything put in (budget added, training shares), training = the part
	 * of it raised by training fees (voided trainings left out), spent = everything taken
	 * out, committed = requests not yet paid, available = what is left to ask for.
	 *
	 * @return array<int,array{zone:string,allocated:float,training:float,spent:float,committed:float,available:float}>
	 */
	public function budget()
	{
		global $conf;
		$in = array();
		$out = array();
		$res = $this->db->query("SELECT account_key, SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS pos, SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) AS neg FROM ".$this->db->prefix()."onboarding_ledger WHERE entity = ".((int) $conf->entity)." AND account_type = 'zone' GROUP BY account_key");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$in[(string) $o->account_key] = (float) $o->pos;
			$out[(string) $o->account_key] = (float) $o->neg;
		}
		$fees = array();
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT l.account_key, SUM(l.amount) AS t FROM ".$p."onboarding_ledger AS l LEFT JOIN ".$p."onboarding_training AS t ON t.rowid = l.fk_training WHERE l.entity = ".((int) $conf->entity)." AND l.account_type = 'zone' AND l.kind = 'training' AND (t.rowid IS NULL OR t.status <> 'ignored') GROUP BY l.account_key");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$fees[(string) $o->account_key] = (float) $o->t;
		}
		$rows = array();
		foreach ($this->training->zones() as $z) {
			$a = isset($in[$z]) ? $in[$z] : 0.0;
			$s = isset($out[$z]) ? $out[$z] : 0.0;
			$c = $this->committed($z);
			$rows[] = array('zone' => $z, 'allocated' => round($a, 2), 'training' => round(isset($fees[$z]) ? $fees[$z] : 0.0, 2), 'spent' => round($s, 2), 'committed' => $c, 'available' => round($a - $s - $c, 2));
		}
		return $rows;
	}

	/**
	 * @param string $zone Zone
	 * @return float What is left to ask for
	 */
	public function available($zone)
	{
		foreach ($this->budget() as $b) {
			if ($b['zone'] === $zone) {
				return $b['available'];
			}
		}
		return 0.0;
	}

	// ---------------------------------------------------------------- filing a request

	/**
	 * @param int $id Request
	 * @return object|null
	 */
	public function fetch($id)
	{
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_purchase WHERE rowid = ".((int) $id));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param int $id Request
	 * @param array<string,mixed> $fields Column => value (null clears it)
	 * @return void
	 */
	private function save($id, $fields)
	{
		$set = array();
		foreach ($fields as $k => $v) {
			$set[] = $k.' = '.($v === null ? 'NULL' : (is_int($v) || is_float($v) ? price2num($v) : "'".$this->db->escape((string) $v)."'"));
		}
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_purchase SET ".implode(', ', $set)." WHERE rowid = ".((int) $id));
	}

	/**
	 * @param array<string,mixed> $in email, pin, zone, kind, amount, vendor, purchase_date, link, description, pay_method, pay_handle, ack_tax, ip
	 * @return array<string,mixed> ok, id, token, ref; or ok false, error, field
	 */
	public function submit($in)
	{
		global $conf;
		$get = function ($k, $max) use ($in) {
			return isset($in[$k]) && is_scalar($in[$k]) ? trim(dol_trunc((string) $in[$k], $max, 'right', 'UTF-8', 1)) : '';
		};
		$bad = function ($field) {
			return array('ok' => false, 'error' => 'invalid', 'field' => $field, 'http' => 400);
		};
		$email = OnboardingService::cleanEmail($get('email', 200));
		$ip = $get('ip', 64);
		$kind = $get('kind', 12);
		$zone = $get('zone', 100);
		$amount = round((float) price2num($get('amount', 20)), 2);
		$vendor = $get('vendor', 200);
		$description = $get('description', 600);
		$link = $get('link', 500);
		$items = array();
		$method = $get('pay_method', 20);
		$handle = $get('pay_handle', 200);
		$date = $get('purchase_date', 10);
		$today = dol_print_date(OnboardingService::now(), '%Y-%m-%d');

		if (!isset(self::KINDS[$kind])) {
			return $bad('kind');
		}
		if (!in_array($zone, $this->zoneNames(), true)) {
			return $bad('zone');
		}
		if ($kind == 'purchase') {
			// Line items, each a link and its price; one link and an amount still work.
			$list = isset($in['items']) && is_array($in['items']) ? array_values($in['items']) : array(array('link' => $link, 'price' => $get('amount', 20)));
			if (!$list || count($list) > self::MAX_ITEMS) {
				return $bad('link');
			}
			foreach ($list as $it) {
				$l = is_array($it) && isset($it['link']) && is_scalar($it['link']) ? trim(dol_trunc((string) $it['link'], 500, 'right', 'UTF-8', 1)) : '';
				$p = is_array($it) && isset($it['price']) && is_scalar($it['price']) ? round((float) price2num((string) $it['price']), 2) : 0;
				$parts = parse_url($l);
				if ($l === '' || !filter_var($l, FILTER_VALIDATE_URL) || !$parts || !in_array(strtolower(isset($parts['scheme']) ? $parts['scheme'] : ''), array('http', 'https'), true)) {
					return $bad('link');
				}
				if ($p <= 0) {
					return $bad('amount');
				}
				$items[] = array('link' => $l, 'price' => $p);
			}
			$link = $items[0]['link'];
			$amount = round(array_sum(array_column($items, 'price')), 2);
		}
		if ($amount <= 0 || $amount > self::MAX_AMOUNT) {
			return $bad('amount');
		}
		if (strlen($description) < 3) {
			return $bad('description');
		}
		if ($kind == 'purchase') {
			$date = $today;
			$method = '';
			$handle = '';
		} else {
			if ($vendor === '') {
				return $bad('vendor');
			}
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today) {
				return $bad('purchase_date');
			}
			if ($date < dol_print_date(OnboardingService::now() - self::receiptDays() * 86400, '%Y-%m-%d')) {
				return array('ok' => false, 'error' => 'late', 'field' => 'purchase_date', 'days' => self::receiptDays(), 'http' => 400);
			}
			if (!isset(self::PAY_METHODS[$method]) || ($method != 'check' && $handle === '')) {
				return $bad('pay_method');
			}
			if (empty($in['ack_tax'])) {
				return $bad('ack_tax');
			}
			if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) {
				$link = '';
			}
		}
		if ($email === '' || strpos($email, '@') === false) {
			return $bad('email');
		}

		if ($this->locked($email, $ip)) {
			return array('ok' => false, 'error' => 'slow', 'http' => 429);
		}
		$m = $this->memberByEmail($email);
		$typed = self::normalizeCode($get('pin', 20));
		$stored = $m ? self::codeDigits($m->member_code) : '';
		$right = $m && $typed !== '' && $stored !== '' && hash_equals($stored, $typed) && $this->isPayingMember($m);
		if (!$right) {
			$this->recordFailure($email, $ip);
			// One answer for every reason, so the form cannot be used to find out who is a member.
			return array('ok' => false, 'error' => 'member', 'http' => 403);
		}
		// Proved who they are. Now: may they file for this zone? Only now is it safe to say
		// which zones they may use, because they are who they say.
		$allowed = $this->allowedZones($m);
		if (!in_array($zone, $allowed, true)) {
			return array('ok' => false, 'error' => 'notallowed', 'allowed' => $allowed, 'http' => 403);
		}

		$token = bin2hex(random_bytes(24));
		$sql = "INSERT INTO ".$this->db->prefix()."onboarding_purchase (entity, token, fk_adherent, zone, kind, amount, items, vendor, purchase_date, link, description, pay_method, pay_handle, status, need_board, ip, files, datec) VALUES (";
		$sql .= ((int) $conf->entity).", '".$token."', ".((int) $m->rowid).", '".$this->db->escape($zone)."', '".$this->db->escape($kind)."', ".price2num($amount).", ".($items ? "'".$this->db->escape(json_encode($items))."'" : 'NULL').", '".$this->db->escape($vendor)."', '".$this->db->escape($date)."', '".$this->db->escape($link)."', '".$this->db->escape($description)."', '".$this->db->escape($method)."', '".$this->db->escape($handle)."', 'draft', 0, '".$this->db->escape($ip)."', '[]', '".$this->db->idate(OnboardingService::now())."')";
		if (!$this->db->query($sql)) {
			return array('ok' => false, 'error' => 'server', 'http' => 500);
		}
		$id = (int) $this->db->last_insert_id($this->db->prefix().'onboarding_purchase');
		$ref = 'PR-'.sprintf('%04d', $id);
		$this->save($id, array('ref' => $ref));
		return array('ok' => true, 'id' => $id, 'token' => $token, 'ref' => $ref);
	}

	/**
	 * @param int $id Request
	 * @param string $token Token returned by submit
	 * @return object|null The draft, when the token is right
	 */
	private function draft($id, $token)
	{
		$row = $this->fetch($id);
		return $row && $row->status == 'draft' && hash_equals((string) $row->token, (string) $token) ? $row : null;
	}

	/**
	 * Attach one receipt to a draft. The type is decided by the bytes, never by the name.
	 *
	 * @param int $id Request
	 * @param string $token Token returned by submit
	 * @param string $bytes File contents
	 * @return array<string,mixed>
	 */
	public function addFile($id, $token, $bytes)
	{
		$row = $this->draft($id, $token);
		if (!$row) {
			return array('ok' => false, 'error' => 'token', 'http' => 404);
		}
		$files = json_decode((string) $row->files, true);
		$files = is_array($files) ? $files : array();
		if (count($files) >= self::MAX_FILES) {
			return array('ok' => false, 'error' => 'files', 'http' => 400);
		}
		$stored = $this->storeFile($row, $bytes);
		if (!$stored['ok']) {
			return $stored;
		}
		return array('ok' => true, 'files' => $stored['files']);
	}

	/**
	 * Keep one receipt with a request. The type is decided by the bytes, never by the name.
	 *
	 * @param object $row Request
	 * @param string $bytes File contents
	 * @return array<string,mixed> ok, files (how many now); or ok false, error
	 */
	private function storeFile($row, $bytes)
	{
		$files = $this->files($row);
		if (strlen($bytes) < 100 || strlen($bytes) > self::MAX_FILE_BYTES) {
			return array('ok' => false, 'error' => 'size', 'http' => 400);
		}
		$ext = '';
		if (substr($bytes, 0, 5) === '%PDF-') {
			$ext = 'pdf';
		} elseif (substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), array('heic', 'heix', 'mif1', 'msf1', 'heim', 'heis'), true)) {
			$ext = 'heic';
		} else {
			$info = @getimagesizefromstring($bytes);
			$types = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp');
			if ($info && isset($types[$info[2]])) {
				$ext = $types[$info[2]];
			}
		}
		if ($ext === '') {
			return array('ok' => false, 'error' => 'type', 'http' => 400);
		}
		$dir = DOL_DATA_ROOT.'/onboarding/purchase/'.((int) $row->rowid);
		dol_mkdir($dir);
		$name = 'receipt-'.(count($files) + 1).'.'.$ext;
		if (file_put_contents($dir.'/'.$name, $bytes) === false) {
			return array('ok' => false, 'error' => 'server', 'http' => 500);
		}
		$files[] = $name;
		$this->save($row->rowid, array('files' => json_encode($files)));
		return array('ok' => true, 'files' => count($files));
	}

	/**
	 * @param object $row Request
	 * @return array<int,array{link:string,price:float}> What a request for purchase asks for
	 */
	public function items($row)
	{
		$items = json_decode((string) (isset($row->items) ? $row->items : ''), true);
		if (is_array($items) && $items) {
			return $items;
		}
		return $row->kind == 'purchase' ? array(array('link' => (string) $row->link, 'price' => (float) $row->amount)) : array();
	}

	/**
	 * @param object $row Request
	 * @return string One line per item, for emails
	 */
	public function itemsText($row)
	{
		$t = '';
		foreach ($this->items($row) as $i => $it) {
			$t .= ($i + 1).'. '.self::money($it['price']).'  '.$it['link']."\n";
		}
		return $t;
	}

	/**
	 * Submit a draft for approval and email the people who decide.
	 *
	 * @param int $id Request
	 * @param string $token Token returned by submit
	 * @return array<string,mixed>
	 */
	public function finish($id, $token)
	{
		$row = $this->draft($id, $token);
		if (!$row) {
			return array('ok' => false, 'error' => 'token', 'http' => 404);
		}
		$files = json_decode((string) $row->files, true);
		if ($row->kind == 'reimburse' && (!is_array($files) || !$files)) {
			return array('ok' => false, 'error' => 'receipt', 'http' => 400);
		}
		$member = $this->memberById((int) $row->fk_adherent);
		$needs = $this->needs($row, $member);
		$board = in_array('president', $needs, true) || count(array_diff($needs, array('boss'))) > 1;
		$this->save($row->rowid, array('status' => 'pending', 'needs' => json_encode($needs), 'need_board' => $board ? 1 : 0, 'approvals' => '[]', 'date_submitted' => $this->db->idate(OnboardingService::now())));
		$row = $this->fetch($row->rowid);
		$who = $this->needsText($row);

		$name = $member ? trim($member->firstname.' '.$member->lastname) : 'A member';
		$to = $member ? $member->email : '';
		$org = getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'the makerspace');
		$this->svc->mail($to, $row->ref.': we have your request', "Hi ".($member ? $member->firstname : 'there').",\n\nWe received your request ".$row->ref." for ".self::money($row->amount)." from the ".$row->zone." budget. ".ucfirst($who)." will look at it. You will get an email when it is decided.\n\n".$org);

		$sent = 0;
		foreach ($this->approvers($row) as $a) {
			if ($this->svc->mail($a['email'], 'Please decide: '.$row->ref.' '.self::money($row->amount).' from '.$row->zone, $this->approverBody($row, $a, $name))) {
				$sent++;
			}
		}
		if (!$sent) {
			$this->svc->mail(getDolGlobalString('ONBOARDING_STAFF_EMAIL'), $row->ref.' has nobody to approve it', "Request ".$row->ref." (".self::money($row->amount)." from ".$row->zone.") was filed by ".$name.", but no approver has an email address. Tag the treasurer, the president and the zone bosses on their member cards (Members, the member, Tags).");
		}
		return array('ok' => true, 'ref' => $row->ref, 'needs' => $board ? 'board' : 'lead', 'who' => $who);
	}

	/**
	 * Who may decide this request.
	 *
	 * @param object $row Request
	 * @return array<int,array{email:string,name:string,role:string}>
	 */
	public function approvers($row)
	{
		$member = $this->memberById((int) $row->fk_adherent);
		$own = $member ? OnboardingService::cleanEmail($member->email) : '';
		$tags = array('treasurer' => array(self::ROLE_TREASURER), 'president' => array(self::ROLE_PRESIDENT), 'director' => self::BOARD_ROLES, 'boss' => array(self::bossRole($row->zone)));
		$out = array();
		foreach (array_unique($this->needs($row)) as $seat) {
			foreach ($this->roleMembers(isset($tags[$seat]) ? $tags[$seat] : array()) as $m) {
				$e = OnboardingService::cleanEmail($m->email);
				if ($e === '' || $e === $own) {
					continue;
				}
				if (!isset($out[$e])) {
					$out[$e] = array('email' => $e, 'name' => trim($m->firstname.' '.$m->lastname), 'role' => '', 'seats' => array());
				}
				$out[$e]['seats'][] = $seat;
			}
		}
		$labels = array('treasurer' => 'treasurer', 'president' => 'president', 'director' => 'director', 'boss' => $row->zone.' boss');
		foreach ($out as $e => $a) {
			$out[$e]['role'] = implode(', ', array_map(function ($s) use ($labels) {
				return $labels[$s];
			}, $a['seats']));
		}
		return array_values($out);
	}

	/**
	 * @param object $row Request
	 * @param array{email:string,name:string,role:string} $a Approver
	 * @param string $who Requester's name
	 * @return string
	 */
	private function approverBody($row, $a, $who)
	{
		$b = "Request ".$row->ref." from ".$who.": ".self::money($row->amount)." from the ".$row->zone." budget.\n";
		$b .= $row->kind == 'purchase' ? "They want the treasurer to buy it (prices are estimates; tax, shipping and fees may be added):\n".$this->itemsText($row) : "Already bought at ".$row->vendor." on ".$row->purchase_date.". Receipts are attached to the request.\n";
		$b .= "What and why: ".$row->description."\n\n";
		$b .= "It needs ".$this->needsText($row)." to approve; you are asked as ".$a['role'].".\n\n";
		$b .= "Look at it, then approve or reject (nothing changes until you press the button on that page):\n".$this->link($row->rowid, $a['email'], 'approve')."\n\n";
		$b .= "Or reject it:\n".$this->link($row->rowid, $a['email'], 'reject')."\n";
		return $b;
	}

	// ---------------------------------------------------------------- signed links

	/**
	 * @param int $id Request
	 * @param string $email Approver
	 * @param string $action approve, reject or view
	 * @param int $n Receipt number, for view
	 * @return string Signature
	 */
	public function sign($id, $email, $action, $n = 0)
	{
		return substr(hash_hmac('sha256', 'purchase:'.((int) $id).':'.OnboardingService::cleanEmail($email).':'.$action.':'.((int) $n), $this->secret()), 0, 32);
	}

	/**
	 * @param int $id Request
	 * @param string $email Approver
	 * @param string $action approve, reject or view
	 * @param int $n Receipt number, for view
	 * @return string Full link, no login needed
	 */
	public function link($id, $email, $action, $n = 0)
	{
		return dol_buildpath('/onboarding/public/purchase.php', 2).'?p='.((int) $id).'&e='.rawurlencode(OnboardingService::cleanEmail($email)).'&a='.$action.($n ? '&n='.((int) $n) : '').'&k='.$this->sign($id, $email, $action, $n);
	}

	/**
	 * @param int $id Request
	 * @param string $email Approver
	 * @param string $action approve, reject or view
	 * @param string $key Signature from the link
	 * @param int $n Receipt number
	 * @return bool
	 */
	public function verify($id, $email, $action, $key, $n = 0)
	{
		return in_array($action, array('approve', 'reject', 'view'), true) && hash_equals($this->sign($id, $email, $action, $n), (string) $key);
	}

	// ---------------------------------------------------------------- deciding

	/**
	 * @param object $row Request
	 * @return array<int,array{email:string,name:string,role:string,at:int}> Approvals so far
	 */
	public function approvalsOf($row)
	{
		$a = json_decode((string) $row->approvals, true);
		return is_array($a) ? $a : array();
	}

	/**
	 * Record an approval or a rejection from one approver. The link was verified by the caller.
	 *
	 * @param int $id Request
	 * @param string $email Approver
	 * @param string $action approve or reject
	 * @param string $reason For a rejection: why, sent to the member
	 * @return string done, approved (the last approval came in), rejected, already, closed, not-approver
	 */
	public function decide($id, $email, $action, $reason = '')
	{
		$row = $this->fetch($id);
		$email = OnboardingService::cleanEmail($email);
		if (!$row || $row->status != 'pending') {
			return 'closed';
		}
		$mine = null;
		foreach ($this->approvers($row) as $a) {
			if ($a['email'] === $email) {
				$mine = $a;
			}
		}
		if (!$mine) {
			return 'not-approver';
		}
		$member = $this->memberById((int) $row->fk_adherent);
		$who = $member ? trim($member->firstname.' '.$member->lastname) : 'the member';
		if ($action == 'reject') {
			$reason = trim(dol_trunc((string) $reason, 1000, 'right', 'UTF-8', 1));
			$this->save($row->rowid, array('status' => 'rejected', 'decided_note' => dol_trunc('Rejected by '.$mine['name'].($reason !== '' ? ': '.$reason : ''), 250, 'right', 'UTF-8', 1)));
			$this->svc->mail($member ? $member->email : '', $row->ref.': not approved', "Hi,\n\nYour request ".$row->ref." for ".self::money($row->amount)." from the ".$row->zone." budget was not approved by ".$mine['name'].".\n\n".($reason !== '' ? "Why: ".$reason."\n\nAsk ".$mine['name']." or the treasurer if you have questions or want to try again." : "Ask ".$mine['name']." or the treasurer if you would like to know why or want to try again."));
			return 'rejected';
		}
		$approvals = $this->approvalsOf($row);
		foreach ($approvals as $a) {
			if ($a['email'] === $email) {
				return 'already';
			}
		}
		$approvals[] = array('email' => $email, 'name' => $mine['name'], 'role' => $mine['role'], 'seats' => $mine['seats'], 'at' => OnboardingService::now());
		$done = self::enough($this->needs($row), $approvals);
		$this->save($row->rowid, array('approvals' => json_encode($approvals), 'status' => $done ? 'approved' : 'pending'));
		if ($done) {
			$this->svc->mail($member ? $member->email : '', $row->ref.': approved', "Good news. Your request ".$row->ref." for ".self::money($row->amount)." from the ".$row->zone." budget was approved. ".($row->kind == 'purchase' ? "The treasurer will order it." : "The treasurer will pay you back by ".(isset(self::PAY_METHODS[$row->pay_method]) ? self::PAY_METHODS[$row->pay_method] : 'the method you chose').'.'));
			foreach ($this->treasurers() as $tmail) {
				$body = "Request ".$row->ref." from ".$who.": ".self::money($row->amount)." from the ".$row->zone." budget is approved.\nWhat and why: ".$row->description."\n\n";
				if ($row->kind == 'purchase') {
					$body .= "Order it:\n".$this->itemsText($row)."\nThen, on the Purchase requests page in Dolibarr, attach the order receipt, type the final amount (tax, shipping and fees included) and press Mark ordered. The final amount is what comes out of the budget.";
				} else {
					$body .= "Check the receipts:\n";
					foreach ($this->files($row) as $i => $f) {
						$body .= $this->link($row->rowid, $tmail, 'view', $i + 1)."\n";
					}
					$body .= "\nPay ".$who." back by ".(isset(self::PAY_METHODS[$row->pay_method]) ? self::PAY_METHODS[$row->pay_method] : $row->pay_method)." ".$row->pay_handle.", then press Mark reimbursed on the Purchase requests page in Dolibarr.";
				}
				$this->svc->mail($tmail, $row->ref.' is approved, ready to '.($row->kind == 'purchase' ? 'order' : 'reimburse'), $body);
			}
		}
		return $done ? 'approved' : 'done';
	}

	// ---------------------------------------------------------------- paying

	/**
	 * The treasurer paid it. Records a bank line on the account used and spends the
	 * zone's budget, both pointing at this request.
	 *
	 * @param int $id Request
	 * @param int $accountId Dolibarr bank account it was paid from
	 * @param User $user The treasurer
	 * @return string Message for the screen, starting with "Error" on failure
	 */
	public function markPaid($id, $accountId, $user, $final = 0.0, $receipt = '')
	{
		$row = $this->fetch($id);
		if (!$row || $row->status != 'approved') {
			return 'Error: only an approved request can be marked reimbursed or ordered.';
		}
		$amount = (float) $row->amount;
		if ($row->kind == 'purchase') {
			// The prices were an estimate: the order receipt says what it really cost.
			$final = round((float) $final, 2);
			if ($final <= 0 || $final > self::MAX_AMOUNT * 2) {
				return 'Error: type the final amount from the order receipt, with tax, shipping and fees.';
			}
			if ($receipt === '') {
				return 'Error: attach the order receipt.';
			}
		}
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$acc = new Account($this->db);
		if ($acc->fetch((int) $accountId) <= 0) {
			return 'Error: choose the account it was paid from.';
		}
		$member = $this->memberById((int) $row->fk_adherent);
		$who = $member ? trim($member->firstname.' '.$member->lastname) : '';
		$type = $row->pay_method == 'check' ? 'CHQ' : ($row->kind == 'purchase' ? 'CB' : 'VIR');
		$label = dol_trunc($row->ref.' '.$row->zone.': '.($row->vendor !== '' ? $row->vendor : 'purchase').($who !== '' ? ' ('.$who.')' : ''), 100, 'right', 'UTF-8', 1);
		if ($row->kind == 'purchase') {
			$stored = $this->storeFile($row, $receipt);
			if (!$stored['ok']) {
				return 'Error: '.($stored['error'] == 'type' ? 'the receipt must be a PDF or a picture.' : ($stored['error'] == 'size' ? 'the receipt is too big (5 MB).' : 'the receipt could not be kept.'));
			}
			$amount = $final;
			$this->save($row->rowid, array('amount_final' => $final));
		}
		$line = $acc->addline(OnboardingService::now(), $type, $label, -abs($amount), '', 0, $user);
		if ($line <= 0) {
			return 'Error: the bank line could not be created: '.$acc->error;
		}
		if ($member) {
			$acc->add_url_line($line, (int) $member->rowid, DOL_URL_ROOT.'/adherents/card.php?rowid=', $who, 'member');
		}
		if ($row->zone !== self::GENERAL) {
			$this->training->post('zone', $row->zone, -abs($amount), 'spend', 0, $row->ref.' '.($row->vendor !== '' ? $row->vendor : 'purchase').($who !== '' ? ' ('.$who.')' : ''), 0, (int) $user->id);
		}
		$this->save($row->rowid, array('status' => 'paid', 'fk_bank_line' => (int) $line, 'paid_from' => $acc->label, 'paid_at' => $this->db->idate(OnboardingService::now()), 'fk_user_paid' => (int) $user->id));
		if ($row->kind == 'purchase') {
			$this->svc->mail($member ? $member->email : '', $row->ref.': ordered', "Your request ".$row->ref." has been ordered by the treasurer. It came to ".self::money($amount)." with tax, shipping and fees (estimated ".self::money($row->amount)."), which is what comes out of the ".$row->zone." budget. Thank you.");
			return $row->ref.' marked ordered: '.self::money($amount).' (estimated '.self::money($row->amount).') out of '.$acc->label.' and the '.$row->zone.' budget.';
		}
		$this->svc->mail($member ? $member->email : '', $row->ref.': reimbursed', "Your request ".$row->ref." for ".self::money($row->amount)." was paid back to you from ".$acc->label.". Thank you.");
		return $row->ref.' marked reimbursed: '.self::money($row->amount).' out of '.$acc->label.' and the '.$row->zone.' budget.';
	}

	/**
	 * Withdraw a request that has not been paid.
	 *
	 * @param int $id Request
	 * @param User $user Who
	 * @return string Message
	 */
	public function cancel($id, $user)
	{
		$row = $this->fetch($id);
		if (!$row || !in_array($row->status, array('pending', 'approved'), true)) {
			return 'Error: only a request that has not been paid can be cancelled.';
		}
		$this->save($row->rowid, array('status' => 'cancelled', 'decided_note' => 'Cancelled by '.($user->login ? $user->login : 'staff')));
		return $row->ref.' cancelled. Its amount is available to the zone again.';
	}

	/**
	 * @param string $status Only this status, or empty for all
	 * @return object[] Newest first, drafts left out
	 */
	public function all($status = '')
	{
		global $conf;
		$out = array();
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_purchase WHERE entity = ".((int) $conf->entity)." AND status <> 'draft'".($status !== '' ? " AND status = '".$this->db->escape($status)."'" : '')." ORDER BY rowid DESC LIMIT 300");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param object $row Request
	 * @return string[] Stored receipt file names
	 */
	public function files($row)
	{
		$f = json_decode((string) $row->files, true);
		return is_array($f) ? array_values($f) : array();
	}

	/**
	 * @param object $row Request
	 * @param int $n Receipt number from 1
	 * @return string Path of the stored file, empty when there is none
	 */
	public function filePath($row, $n)
	{
		$files = $this->files($row);
		if ($n < 1 || !isset($files[$n - 1])) {
			return '';
		}
		$path = DOL_DATA_ROOT.'/onboarding/purchase/'.((int) $row->rowid).'/'.basename($files[$n - 1]);
		return is_readable($path) ? $path : '';
	}

	/**
	 * Drop drafts nobody finished and old failed-attempt records. Run daily.
	 *
	 * @return string Summary
	 */
	public function purge()
	{
		global $conf;
		$cut = $this->db->idate(OnboardingService::now() - 2 * 86400);
		$p = $this->db->prefix();
		$ids = array();
		$res = $this->db->query("SELECT rowid FROM ".$p."onboarding_purchase WHERE entity = ".((int) $conf->entity)." AND status = 'draft' AND datec < '".$cut."'");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$ids[] = (int) $o->rowid;
		}
		foreach ($ids as $id) {
			foreach (glob(DOL_DATA_ROOT.'/onboarding/purchase/'.$id.'/*') ?: array() as $f) {
				@unlink($f);
			}
			@rmdir(DOL_DATA_ROOT.'/onboarding/purchase/'.$id);
			$this->db->query("DELETE FROM ".$p."onboarding_purchase WHERE rowid = ".$id);
		}
		$this->db->query("DELETE FROM ".$p."onboarding_attempt WHERE datec < '".$cut."'");
		return count($ids).' unfinished purchase requests removed';
	}
}
