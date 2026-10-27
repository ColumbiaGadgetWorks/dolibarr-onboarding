<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');
dol_include_once('/onboarding/class/purchase.class.php');

/**
 * Yearly filings: the IRS 990-N (or 990-EZ) and the state annual registration,
 * run from the treasurer's inbox.
 *
 * 1. Some days after the fiscal year ends, the daily job opens the year and
 *    emails whoever holds the treasurer tag a link to a page (no login) listing
 *    what is needed: each money account's statements for the twelve months, and
 *    the yearly reports on the setup page (Givebutter's report, any 1099-K...).
 *    A month already reconciled in Dolibarr's bank module counts by itself.
 *    They upload, then press All uploaded. Reminded every week until then.
 * 2. Pressing it emails everything needed to file: the values to type into the
 *    IRS e-Postcard (EIN, tax year, name, address, principal officer, website),
 *    Dolibarr's estimate of gross receipts with where it comes from, the
 *    three-year average that decides 990-N or 990-EZ, the state report's
 *    officers, and the links. Buttons on the page: "990-N filed" and "state
 *    report filed". Reminded every week until both are pressed.
 * 3. The board is emailed (each with their own link) 30 and 7 days before the
 *    IRS due date and on it, while it is not filed; then everyone gets a short
 *    "done" summary.
 *
 * Every email goes through email approval like any other.
 */
class OnboardingFiling
{
	const IRS_URL = 'https://www.irs.gov/charities-non-profits/annual-electronic-filing-requirement-for-small-exempt-organizations-form-990-n-e-postcard';
	const IRS_EZ_URL = 'https://www.irs.gov/forms-pubs/about-form-990-ez';
	const IRS_CHECK_URL = 'https://apps.irs.gov/app/eos/';
	const MAX_FILE_BYTES = 26214400;
	// Statements and reports come as PDFs, bank downloads, spreadsheets or photos.
	const TYPES = array('pdf' => 'application/pdf', 'csv' => 'text/csv', 'qfx' => 'application/octet-stream', 'ofx' => 'application/octet-stream', 'qbo' => 'application/octet-stream', 'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'txt' => 'text/plain', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'heic' => 'application/octet-stream');
	const DEFAULT_YEARLY = "Givebutter: the transactions report for the year (in Givebutter: Transactions, filter the dates, Export)\n1099-K: any 1099-K or other tax form the organization received for the year (mark None if there were none)\nVenmo: the statements for the year\nCash box: the count sheets or deposit slips for the year";
	const STATUSES = array('docs' => 'Waiting for statements', 'file' => 'Ready to file', 'done' => 'Done');
	const LIMIT_N = 50000;
	const LIMIT_EZ = 200000;

	/** @var DoliDB */
	public $db;
	/** @var OnboardingService */
	public $svc;
	/** @var OnboardingPurchase */
	public $roles;

	/**
	 * @param DoliDB $db Database handler
	 * @param OnboardingService|null $svc Service
	 */
	public function __construct($db, $svc = null)
	{
		$this->db = $db;
		$this->svc = $svc ? $svc : new OnboardingService($db);
		$this->roles = new OnboardingPurchase($db, $this->svc);
	}

	// ---------------------------------------------------------------- the year

	/**
	 * @return int The month the fiscal year starts (Setup, Company, fiscal year start)
	 */
	public static function startMonth()
	{
		$m = getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1);
		return ($m >= 1 && $m <= 12) ? $m : 1;
	}

	/**
	 * @param int $fy Fiscal year, by the calendar year it ends in
	 * @return array{0:string,1:string} First and last day, Y-m-d
	 */
	public static function period($fy)
	{
		$m = self::startMonth();
		if ($m == 1) {
			return array($fy.'-01-01', $fy.'-12-31');
		}
		return array(sprintf('%04d-%02d-01', $fy - 1, $m), date('Y-m-d', mktime(12, 0, 0, $m, 0, $fy)));
	}

	/**
	 * @param int $fy Fiscal year
	 * @return string The IRS tax year: the calendar year the fiscal year starts in
	 */
	public static function taxYear($fy)
	{
		return substr(self::period($fy)[0], 0, 4);
	}

	/**
	 * @param int $fy Fiscal year
	 * @return string The 15th day of the 5th month after the year ends, Y-m-d
	 */
	public static function irsDue($fy)
	{
		$end = strtotime(self::period($fy)[1].' 12:00:00');
		return date('Y-m-d', mktime(12, 0, 0, (int) date('n', $end) + 5, 15, (int) date('Y', $end)));
	}

	/**
	 * @param int $fy Fiscal year
	 * @return string The first state due date (month-day on the setup page) after the year ends
	 */
	public static function stateDue($fy)
	{
		$end = self::period($fy)[1];
		$md = preg_match('/^(\d{1,2})-(\d{1,2})$/', getDolGlobalString('ONBOARDING_FILING_STATE_DUE', '10-27'), $m) ? array((int) $m[1], (int) $m[2]) : array(10, 27);
		$y = (int) substr($end, 0, 4);
		$due = date('Y-m-d', mktime(12, 0, 0, $md[0], $md[1], $y));
		return $due > $end ? $due : date('Y-m-d', mktime(12, 0, 0, $md[0], $md[1], $y + 1));
	}

	/**
	 * @param string $today Y-m-d
	 * @return int The most recent fiscal year that has ended
	 */
	public static function latestEnded($today)
	{
		$y = (int) substr($today, 0, 4);
		for ($fy = $y + 1; $fy > $y - 3; $fy--) {
			if (self::period($fy)[1] < $today) {
				return $fy;
			}
		}
		return $y - 1;
	}

	/**
	 * @param int $fy Fiscal year
	 * @return string The day the daily job opens it, Y-m-d
	 */
	public static function startsOn($fy)
	{
		$days = max(0, (int) getDolGlobalString('ONBOARDING_FILING_START_DAYS', '15'));
		return date('Y-m-d', strtotime(self::period($fy)[1].' 12:00:00') + $days * 86400);
	}

	/**
	 * @param int $fy Fiscal year
	 * @return string[] Its months, Y-m
	 */
	public static function months($fy)
	{
		$start = strtotime(self::period($fy)[0].' 12:00:00');
		$out = array();
		for ($i = 0; $i < 12; $i++) {
			$out[] = date('Y-m', mktime(12, 0, 0, (int) date('n', $start) + $i, 1, (int) date('Y', $start)));
		}
		return $out;
	}

	/**
	 * @param string $day Y-m-d
	 * @return string "15 Oct 2026"
	 */
	public static function show($day)
	{
		return $day ? date('j M Y', strtotime(substr($day, 0, 10).' 12:00:00')) : '';
	}

	/**
	 * @param string $ym Y-m
	 * @return string "Jun 2025"
	 */
	public static function showMonth($ym)
	{
		return date('M Y', strtotime($ym.'-01 12:00:00'));
	}

	/**
	 * @param int $fy Fiscal year
	 * @return string "1 Jun 2025 to 31 May 2026"
	 */
	public static function showPeriod($fy)
	{
		list($a, $b) = self::period($fy);
		return self::show($a).' to '.self::show($b);
	}

	/**
	 * @param float $n Amount
	 * @return string "$1,234.00"
	 */
	public static function money($n)
	{
		return ($n < 0 ? '-' : '').'$'.number_format(abs((float) $n), 2);
	}

	/**
	 * @return string Today on the module's clock, Y-m-d
	 */
	public static function today()
	{
		return date('Y-m-d', OnboardingService::now());
	}

	/**
	 * @param string $from Y-m-d
	 * @param string $to Y-m-d
	 * @return int Days from one to the other
	 */
	public static function days($from, $to)
	{
		return (int) round((strtotime($to.' 12:00:00') - strtotime($from.' 12:00:00')) / 86400);
	}

	// ---------------------------------------------------------------- facts for the forms

	/**
	 * What the IRS and the state forms ask for, from the setup page and Dolibarr's
	 * company details (Setup, Company: professional ID 1 is the EIN, ID 2 the state
	 * charter number).
	 *
	 * @return array<string,string>
	 */
	public function facts()
	{
		$addr = trim(str_replace(array("\r\n", "\n", "\r"), ', ', getDolGlobalString('MAIN_INFO_SOCIETE_ADDRESS')));
		$town = trim(getDolGlobalString('MAIN_INFO_SOCIETE_TOWN'));
		$state = '';
		$sid = getDolGlobalInt('MAIN_INFO_SOCIETE_STATE');
		if ($sid > 0) {
			$res = $this->db->query("SELECT code_departement FROM ".$this->db->prefix()."c_departements WHERE rowid = ".((int) $sid));
			$o = $res ? $this->db->fetch_object($res) : null;
			$state = $o ? (string) $o->code_departement : '';
		}
		$zip = trim(getDolGlobalString('MAIN_INFO_SOCIETE_ZIP'));
		$address = trim(getDolGlobalString('ONBOARDING_FILING_ADDRESS'));
		if ($address === '') {
			$address = trim($addr.($town !== '' ? ', '.$town : '').($state !== '' ? ', '.$state : '').($zip !== '' ? ' '.$zip : ''), ', ');
		}
		$officer = trim(getDolGlobalString('ONBOARDING_FILING_OFFICER'));
		if ($officer === '') {
			foreach ($this->roles->roleMembers(array(OnboardingPurchase::ROLE_PRESIDENT)) as $m) {
				$officer = trim($m->firstname.' '.$m->lastname).', President';
				break;
			}
		}
		return array(
			'ein' => trim(getDolGlobalString('ONBOARDING_FILING_EIN', getDolGlobalString('MAIN_INFO_SIREN'))),
			'name' => trim(getDolGlobalString('ONBOARDING_FILING_LEGAL_NAME', getDolGlobalString('MAIN_INFO_SOCIETE_NOM'))),
			'address' => $address,
			'officer' => $officer,
			'website' => trim(getDolGlobalString('MAIN_INFO_SOCIETE_WEB', getDolGlobalString('ONBOARDING_WEBSITE_URL'))),
			'state' => trim(getDolGlobalString('ONBOARDING_FILING_STATE', 'Missouri')),
			'state_id' => trim(getDolGlobalString('ONBOARDING_FILING_STATE_ID', getDolGlobalString('MAIN_INFO_SIRET'))),
			'state_url' => trim(getDolGlobalString('ONBOARDING_FILING_STATE_URL', 'https://bsd.sos.mo.gov/')),
		);
	}

	/**
	 * @return string[] "Role: Name" for each officer and director, for the state report
	 */
	public function officers()
	{
		$out = array();
		foreach ($this->roles->roleMembers(OnboardingPurchase::BOARD_ROLES) as $m) {
			$roles = array();
			foreach (OnboardingPurchase::BOARD_ROLES as $r) {
				if (in_array($r, $m->roles, true)) {
					$roles[] = ucwords(str_replace('-', ' ', $r));
				}
			}
			$out[] = implode(', ', $roles).': '.trim($m->firstname.' '.$m->lastname);
		}
		return $out;
	}

	// ---------------------------------------------------------------- people

	/**
	 * @param object $m Member row
	 * @return array{email:string,first:string,name:string}|null
	 */
	private static function person($m)
	{
		$e = OnboardingService::cleanEmail($m->email);
		if ($e === '' || strpos($e, '@') === false) {
			return null;
		}
		return array('email' => $e, 'first' => (string) $m->firstname, 'name' => trim($m->firstname.' '.$m->lastname));
	}

	/**
	 * Who is asked: whoever holds the treasurer tag; else the president; else the
	 * alerts address.
	 *
	 * @return array<string,array{email:string,first:string,name:string}> By email
	 */
	public function treasurers()
	{
		$out = array();
		foreach (array(array(OnboardingPurchase::ROLE_TREASURER), array(OnboardingPurchase::ROLE_PRESIDENT)) as $roles) {
			foreach ($this->roles->roleMembers($roles) as $m) {
				$p = self::person($m);
				if ($p) {
					$out[$p['email']] = $p;
				}
			}
			if ($out) {
				return $out;
			}
		}
		foreach (array_filter(array_map('trim', explode(',', $this->svc->alertTo()))) as $e) {
			$out[strtolower($e)] = array('email' => strtolower($e), 'first' => 'there', 'name' => $e);
		}
		return $out;
	}

	/**
	 * @return array<string,array{email:string,first:string,name:string}> The rest of the board, by email
	 */
	public function board()
	{
		$out = array();
		$t = $this->treasurers();
		foreach ($this->roles->roleMembers(OnboardingPurchase::BOARD_ROLES) as $m) {
			$p = self::person($m);
			if ($p && !isset($t[$p['email']])) {
				$out[$p['email']] = $p;
			}
		}
		return $out;
	}

	/**
	 * @param string $email Address from a page link
	 * @return string "Tara Smith (tara@example.org)" for a treasurer or board member, else the address
	 */
	public function whoIs($email)
	{
		$all = $this->treasurers() + $this->board();
		$email = strtolower(trim((string) $email));
		return isset($all[$email]) && $all[$email]['name'] !== $email ? $all[$email]['name'].' ('.$email.')' : $email;
	}

	// ---------------------------------------------------------------- links

	/**
	 * @return string Secret that signs the page links
	 */
	private function secret()
	{
		global $conf;
		$secret = getDolGlobalString('ONBOARDING_FILING_SECRET');
		if ($secret === '') {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			$secret = bin2hex(random_bytes(24));
			dolibarr_set_const($this->db, 'ONBOARDING_FILING_SECRET', $secret, 'chaine', 0, 'Signs the links in yearly filing emails', $conf->entity);
		}
		return $secret;
	}

	/**
	 * @param int $id Filing
	 * @param string $email Who the link is for
	 * @return string Signature
	 */
	public function sign($id, $email)
	{
		return substr(hash_hmac('sha256', 'filing:'.((int) $id).':'.strtolower(trim((string) $email)), $this->secret()), 0, 32);
	}

	/**
	 * @param int $id Filing
	 * @param string $email Who the link is for
	 * @param string $key Signature
	 * @return bool
	 */
	public function verify($id, $email, $key)
	{
		return $id > 0 && $email !== '' && hash_equals($this->sign($id, $email), (string) $key);
	}

	/**
	 * @param int $id Filing
	 * @param string $email Who the link is for
	 * @return string The filing page for them, no login
	 */
	public function link($id, $email)
	{
		$email = strtolower(trim((string) $email));
		return OnboardingService::publicUrl('filing.php').'?f='.((int) $id).'&e='.rawurlencode($email).'&k='.$this->sign($id, $email);
	}

	/**
	 * @return string The staff page
	 */
	public static function staffUrl()
	{
		return dol_buildpath('/onboarding/filings.php', 2);
	}

	// ---------------------------------------------------------------- records

	/**
	 * @param int $id Filing
	 * @return object|null
	 */
	public function fetch($id)
	{
		global $conf;
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_filing WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param int $fy Fiscal year
	 * @return object|null
	 */
	public function fetchFy($fy)
	{
		global $conf;
		$res = @$this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_filing WHERE fy = ".((int) $fy)." AND entity = ".((int) $conf->entity));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @return object[] Every year, newest first
	 */
	public function all()
	{
		global $conf;
		$out = array();
		$res = @$this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_filing WHERE entity = ".((int) $conf->entity)." ORDER BY fy DESC");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param int $id Filing
	 * @param array<string,mixed> $fields Column => value (null for NULL)
	 * @return bool
	 */
	private function save($id, $fields)
	{
		$set = array();
		foreach ($fields as $k => $v) {
			$set[] = $k.' = '.($v === null ? 'NULL' : (is_int($v) || is_float($v) ? price2num($v) : "'".$this->db->escape((string) $v)."'"));
		}
		return (bool) $this->db->query("UPDATE ".$this->db->prefix()."onboarding_filing SET ".implode(', ', $set)." WHERE rowid = ".((int) $id));
	}

	/**
	 * @param int $id Filing
	 * @param string $kind What happened
	 * @param string $who Who
	 * @param string $note Detail
	 * @return void
	 */
	public function log($id, $kind, $who = '', $note = '')
	{
		global $conf;
		$this->db->query("INSERT INTO ".$this->db->prefix()."onboarding_filing_event (entity, fk_filing, kind, who, note, datec) VALUES (".((int) $conf->entity).", ".((int) $id).", '".$this->db->escape($kind)."', ".($who !== '' ? "'".$this->db->escape(dol_trunc($who, 250, 'right', 'UTF-8', 1))."'" : 'NULL').", ".($note !== '' ? "'".$this->db->escape(dol_trunc($note, 495, 'right', 'UTF-8', 1))."'" : 'NULL').", '".$this->db->idate(OnboardingService::now())."')");
	}

	/**
	 * @param int $id Filing
	 * @return object[] Its history, oldest first
	 */
	public function events($id)
	{
		$out = array();
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_filing_event WHERE fk_filing = ".((int) $id)." ORDER BY datec, rowid");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * Open a year. Safe to call again.
	 *
	 * @param int $fy Fiscal year
	 * @param string $who Who opened it
	 * @return object|null The filing
	 */
	public function start($fy, $who)
	{
		global $conf;
		$f = $this->fetchFy($fy);
		if ($f) {
			return $f;
		}
		$this->db->query("INSERT INTO ".$this->db->prefix()."onboarding_filing (entity, fy, status, asks, datec) VALUES (".((int) $conf->entity).", ".((int) $fy).", 'docs', 0, '".$this->db->idate(OnboardingService::now())."')");
		$f = $this->fetchFy($fy);
		if ($f) {
			$this->log($f->rowid, 'start', $who, 'FY'.$fy.' opened');
		}
		return $f;
	}

	/**
	 * Record a year as filed without the steps (filed before Dolibarr tracked it).
	 *
	 * @param int $fy Fiscal year
	 * @param string $who Who
	 * @param string $irs Day the IRS return was filed, Y-m-d, or ''
	 * @param string $state Day the state report was filed, Y-m-d, or ''
	 * @param float|null $receipts Gross receipts reported, if known
	 * @param string $note Note
	 * @return string What happened
	 */
	public function recordFiled($fy, $who, $irs, $state, $receipts, $note)
	{
		$f = $this->start($fy, $who);
		if (!$f) {
			return 'Error: the year could not be recorded.';
		}
		$this->save($f->rowid, array(
			'status' => 'done',
			'irs_filed_at' => self::isDay($irs) ? $irs : null,
			'irs_filed_by' => self::isDay($irs) ? $who : null,
			'state_filed_at' => self::isDay($state) ? $state : null,
			'state_filed_by' => self::isDay($state) ? $who : null,
			'receipts' => $receipts !== null ? (float) $receipts : null,
			'note' => $note !== '' ? $note : 'Recorded as filed outside Dolibarr',
		));
		$this->log($f->rowid, 'recorded', $who, trim('Recorded as filed outside Dolibarr. '.$note));
		return 'FY'.$fy.' is recorded as filed.';
	}

	/**
	 * @param string $d Text
	 * @return bool It is a real Y-m-d day
	 */
	public static function isDay($d)
	{
		return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
	}

	// ---------------------------------------------------------------- what is needed

	/**
	 * Money accounts whose statements are asked for: those on the setup page by
	 * ref or label, else every open bank account.
	 *
	 * @return array<int,object> rowid, ref, label
	 */
	public function accounts()
	{
		if (!isModEnabled('banque') && !isModEnabled('bank')) {
			return array();
		}
		$want = array_filter(array_map('trim', explode(',', strtolower(getDolGlobalString('ONBOARDING_FILING_ACCOUNTS')))), 'strlen');
		$out = array();
		$res = $this->db->query("SELECT rowid, ref, label, clos FROM ".$this->db->prefix()."bank_account WHERE entity IN (".getEntity('bank_account').") ORDER BY rowid");
		while ($res && ($o = $this->db->fetch_object($res))) {
			if ($want ? (in_array(strtolower((string) $o->ref), $want, true) || in_array(strtolower((string) $o->label), $want, true)) : (int) $o->clos === 0) {
				$out[(int) $o->rowid] = $o;
			}
		}
		return $out;
	}

	/**
	 * The yearly reports on the setup page, "Name: what to upload" one per line.
	 * A line whose name is in a money account's label is left out: that account's
	 * statements are already asked for.
	 *
	 * @return array<string,array{key:string,label:string,help:string}>
	 */
	public function yearlyItems()
	{
		$labels = array();
		foreach ($this->accounts() as $a) {
			$labels[] = strtolower($a->label.' '.$a->ref);
		}
		$out = array();
		$text = getDolGlobalString('ONBOARDING_FILING_YEARLY', self::DEFAULT_YEARLY);
		foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
			$line = trim($line, " \t-*");
			if ($line === '') {
				continue;
			}
			$parts = explode(':', $line, 2);
			$name = trim($parts[0]);
			$help = isset($parts[1]) ? trim($parts[1]) : '';
			$skip = false;
			foreach ($labels as $l) {
				if ($name !== '' && strpos($l, strtolower($name)) !== false) {
					$skip = true;
				}
			}
			$key = 'y:'.substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'), 0, 40);
			if (!$skip && $key !== 'y:' && !isset($out[$key])) {
				$out[$key] = array('key' => $key, 'label' => $name, 'help' => $help);
			}
		}
		return $out;
	}

	/**
	 * @param int $id Filing
	 * @return array<string,object[]> Uploads and "none" marks, by item
	 */
	public function docs($id)
	{
		$out = array();
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_filing_doc WHERE fk_filing = ".((int) $id)." ORDER BY period, rowid");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[$o->item][] = $o;
		}
		return $out;
	}

	/**
	 * @param int $accountId Bank account
	 * @param int $fy Fiscal year
	 * @return array<string,bool> Months whose bank lines are all reconciled, Y-m => true
	 */
	public function reconciled($accountId, $fy)
	{
		list($a, $b) = self::period($fy);
		$seen = array();
		$res = $this->db->query("SELECT dateo, rappro FROM ".$this->db->prefix()."bank WHERE fk_account = ".((int) $accountId)." AND dateo >= '".$a."' AND dateo <= '".$b." 23:59:59'");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$ym = substr((string) $o->dateo, 0, 7);
			$seen[$ym] = (isset($seen[$ym]) ? $seen[$ym] : true) && (int) $o->rappro === 1;
		}
		return array_filter($seen);
	}

	/**
	 * Everything asked for, and where each stands.
	 *
	 * @param object $f Filing
	 * @return array<int,array{key:string,label:string,help:string,type:string,months:array<string,string>,state:string,docs:object[],missing:string[]}>
	 *   months: Y-m => file, year, reconciled, none or missing (accounts only)
	 *   state:  done, none or missing
	 */
	public function checklist($f)
	{
		$docs = $this->docs($f->rowid);
		$months = self::months((int) $f->fy);
		$out = array();
		foreach ($this->accounts() as $a) {
			$key = 'acct:'.$a->rowid;
			$rows = isset($docs[$key]) ? $docs[$key] : array();
			$none = $year = false;
			$have = array();
			foreach ($rows as $d) {
				if ($d->kind == 'none') {
					$none = true;
				} elseif ($d->period == 'year') {
					$year = true;
				} elseif ($d->period) {
					$have[$d->period] = true;
				}
			}
			$rec = $this->reconciled($a->rowid, (int) $f->fy);
			$state = array();
			$missing = array();
			foreach ($months as $ym) {
				$state[$ym] = $none ? 'none' : ($year ? 'year' : (isset($have[$ym]) ? 'file' : (isset($rec[$ym]) ? 'reconciled' : 'missing')));
				if ($state[$ym] == 'missing') {
					$missing[] = $ym;
				}
			}
			$out[] = array('key' => $key, 'label' => (string) $a->label, 'help' => '', 'type' => 'account', 'months' => $state, 'state' => $none ? 'none' : ($missing ? 'missing' : 'done'), 'docs' => $rows, 'missing' => $missing);
		}
		foreach ($this->yearlyItems() as $key => $y) {
			$rows = isset($docs[$key]) ? $docs[$key] : array();
			$state = 'missing';
			foreach ($rows as $d) {
				$state = $d->kind == 'none' ? ($state == 'done' ? 'done' : 'none') : 'done';
			}
			$out[] = array('key' => $key, 'label' => $y['label'], 'help' => $y['help'], 'type' => 'yearly', 'months' => array(), 'state' => $state, 'docs' => $rows, 'missing' => $state == 'missing' ? array('year') : array());
		}
		return $out;
	}

	/**
	 * @param object $f Filing
	 * @return string[] One line per item still missing
	 */
	public function missing($f)
	{
		$out = array();
		foreach ($this->checklist($f) as $i) {
			if ($i['state'] != 'missing') {
				continue;
			}
			if ($i['type'] == 'account') {
				$names = array_map(array('OnboardingFiling', 'showMonth'), $i['missing']);
				$out[] = $i['label'].': '.(count($names) == 12 ? 'statements for all 12 months (or one file for the whole year)' : 'statements for '.implode(', ', $names));
			} else {
				$out[] = $i['label'].($i['help'] !== '' ? ': '.$i['help'] : '');
			}
		}
		return $out;
	}

	/**
	 * @param object $f Filing
	 * @return int Months and reports still missing
	 */
	public function missingCount($f)
	{
		$n = 0;
		foreach ($this->checklist($f) as $i) {
			$n += $i['state'] == 'missing' ? count($i['missing']) : 0;
		}
		return $n;
	}

	// ---------------------------------------------------------------- uploads

	/**
	 * The statement month in a file name: 2025-06, 202506, 20250630, 06-2025,
	 * Jun 2025, June_2025, 2025 June.
	 *
	 * @param string $name File name
	 * @param string[] $months Months that may match, Y-m
	 * @return string Y-m, or '' when none of them is in the name
	 */
	public static function monthFromName($name, $months)
	{
		$n = strtolower((string) $name);
		$abbr = array('jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec');
		$found = array();
		if (preg_match_all('/(?<!\d)(20\d\d)[-_. ]?(0[1-9]|1[0-2])(?:[-_. ]?(?:0[1-9]|[12]\d|3[01]))?(?!\d)/', $n, $m, PREG_SET_ORDER)) {
			foreach ($m as $x) {
				$found[] = $x[1].'-'.$x[2];
			}
		}
		if (preg_match_all('/(?<!\d)(0?[1-9]|1[0-2])[-_. ](20\d\d)(?!\d)/', $n, $m, PREG_SET_ORDER)) {
			foreach ($m as $x) {
				$found[] = $x[2].'-'.sprintf('%02d', (int) $x[1]);
			}
		}
		$mon = '('.implode('|', $abbr).')[a-z]*';
		if (preg_match_all('/'.$mon.'[-_. ,]*(20\d\d|\d\d)(?!\d)/', $n, $m, PREG_SET_ORDER)) {
			foreach ($m as $x) {
				$found[] = (strlen($x[2]) == 2 ? '20'.$x[2] : $x[2]).'-'.sprintf('%02d', array_search($x[1], $abbr) + 1);
			}
		}
		if (preg_match_all('/(?<!\d)(20\d\d)[-_. ,]*'.$mon.'/', $n, $m, PREG_SET_ORDER)) {
			foreach ($m as $x) {
				$found[] = $x[1].'-'.sprintf('%02d', array_search($x[2], $abbr) + 1);
			}
		}
		foreach ($found as $ym) {
			if (in_array($ym, $months, true)) {
				return $ym;
			}
		}
		return '';
	}

	/**
	 * @param object $f Filing
	 * @return string Its folder in Dolibarr's documents
	 */
	public static function dir($f)
	{
		return DOL_DATA_ROOT.'/onboarding/filing/'.((int) $f->fy);
	}

	/**
	 * Keep an uploaded statement or report.
	 *
	 * @param object $f Filing
	 * @param string $item Checklist item, irs or state
	 * @param string $period Y-m, year, auto (from the file name) or ''
	 * @param string $original The file's name
	 * @param string $tmp Where PHP put it
	 * @param string $who Who
	 * @return string '' when kept, else why not
	 */
	public function addFile($f, $item, $period, $original, $tmp, $who)
	{
		global $conf;
		$items = array('irs' => 'confirmation', 'state' => 'confirmation');
		foreach ($this->checklist($f) as $i) {
			$items[$i['key']] = $i['type'];
		}
		if (!isset($items[$item])) {
			return 'That item is not on the list.';
		}
		$original = dol_sanitizeFileName(basename((string) $original));
		$ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
		if (!isset(self::TYPES[$ext])) {
			return $original.': not a statement file (PDF, CSV, QFX, OFX, QBO, Excel, text or a photo).';
		}
		$size = (int) @filesize($tmp);
		if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
			return $original.': '.($size <= 0 ? 'empty' : 'larger than 25 MB').'.';
		}
		if ($items[$item] == 'account') {
			$months = self::months((int) $f->fy);
			if ($period == 'auto' || $period === '') {
				$period = self::monthFromName($original, $months);
			} elseif ($period != 'year' && !in_array($period, $months, true)) {
				$period = '';
			}
		} else {
			$period = 'year';
		}
		$dir = self::dir($f);
		dol_mkdir($dir);
		$this->db->query("INSERT INTO ".$this->db->prefix()."onboarding_filing_doc (entity, fk_filing, item, period, kind, original, size, who, datec) VALUES (".((int) $conf->entity).", ".((int) $f->rowid).", '".$this->db->escape($item)."', '".$this->db->escape($period)."', 'file', '".$this->db->escape(dol_trunc($original, 250, 'right', 'UTF-8', 1))."', ".$size.", '".$this->db->escape(dol_trunc($who, 250, 'right', 'UTF-8', 1))."', '".$this->db->idate(OnboardingService::now())."')");
		$id = (int) $this->db->last_insert_id($this->db->prefix().'onboarding_filing_doc');
		$name = $id.'.'.$ext;
		if ($id <= 0 || !(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dir.'/'.$name) : @copy($tmp, $dir.'/'.$name))) {
			$this->db->query("DELETE FROM ".$this->db->prefix()."onboarding_filing_doc WHERE rowid = ".$id);
			return $original.': could not be saved.';
		}
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_filing_doc SET filename = '".$this->db->escape($name)."' WHERE rowid = ".$id);
		$this->log($f->rowid, 'upload', $who, $original.' for '.$item.($period !== 'year' ? ' ('.($period !== '' ? $period : 'month not known').')' : ''));
		return '';
	}

	/**
	 * Mark an item as not needed this year (an account not used, no 1099-K).
	 *
	 * @param object $f Filing
	 * @param string $item Checklist item
	 * @param string $who Who
	 * @param string $note Why
	 * @return void
	 */
	public function markNone($f, $item, $who, $note = '')
	{
		global $conf;
		foreach ($this->checklist($f) as $i) {
			if ($i['key'] === $item && $i['state'] == 'missing') {
				$this->db->query("INSERT INTO ".$this->db->prefix()."onboarding_filing_doc (entity, fk_filing, item, period, kind, note, who, datec) VALUES (".((int) $conf->entity).", ".((int) $f->rowid).", '".$this->db->escape($item)."', 'year', 'none', ".($note !== '' ? "'".$this->db->escape(dol_trunc($note, 250, 'right', 'UTF-8', 1))."'" : 'NULL').", '".$this->db->escape($who)."', '".$this->db->idate(OnboardingService::now())."')");
				$this->log($f->rowid, 'none', $who, $i['label'].' marked not needed'.($note !== '' ? ': '.$note : ''));
			}
		}
	}

	/**
	 * @param object $f Filing
	 * @param int $docId Upload or mark
	 * @return object|null It, when it belongs to this filing
	 */
	public function doc($f, $docId)
	{
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_filing_doc WHERE rowid = ".((int) $docId)." AND fk_filing = ".((int) $f->rowid));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param object $f Filing
	 * @param object $d Upload
	 * @return string Its path, '' when the file is gone
	 */
	public static function path($f, $d)
	{
		$p = self::dir($f).'/'.basename((string) $d->filename);
		return ($d->kind == 'file' && $d->filename && is_file($p)) ? $p : '';
	}

	/**
	 * Take back an upload or a "none" mark.
	 *
	 * @param object $f Filing
	 * @param int $docId Upload or mark
	 * @param string $who Who
	 * @return void
	 */
	public function remove($f, $docId, $who)
	{
		$d = $this->doc($f, $docId);
		if (!$d || $f->status == 'done') {
			return;
		}
		$p = self::path($f, $d);
		if ($p !== '') {
			@unlink($p);
		}
		$this->db->query("DELETE FROM ".$this->db->prefix()."onboarding_filing_doc WHERE rowid = ".((int) $d->rowid));
		$this->log($f->rowid, 'remove', $who, ($d->kind == 'none' ? 'Not needed mark' : $d->original).' taken back from '.$d->item);
	}

	/**
	 * Say which month a statement is for, when its name did not.
	 *
	 * @param object $f Filing
	 * @param int $docId Upload
	 * @param string $period Y-m or year
	 * @return void
	 */
	public function assign($f, $docId, $period)
	{
		$d = $this->doc($f, $docId);
		if ($d && strpos($d->item, 'acct:') === 0 && ($period == 'year' || in_array($period, self::months((int) $f->fy), true))) {
			$this->db->query("UPDATE ".$this->db->prefix()."onboarding_filing_doc SET period = '".$this->db->escape($period)."' WHERE rowid = ".((int) $d->rowid));
		}
	}

	// ---------------------------------------------------------------- the numbers

	/**
	 * Dolibarr's estimate of gross receipts (everything that came in, before costs).
	 * From the books when Accounting has entries for the year (income accounts,
	 * 4xxx); otherwise money in on the accounts asked for, plus Givebutter dues and
	 * training fees, leaving out transfers between the organization's own accounts
	 * and payouts of money already counted.
	 *
	 * @param int $fy Fiscal year
	 * @return array{total:float,spent:float,lines:array<int,array{0:string,1:float}>,notes:string[],source:string}
	 */
	public function receipts($fy)
	{
		global $conf;
		list($a, $b) = self::period($fy);
		$p = $this->db->prefix();
		$to = $b.' 23:59:59';
		if (isModEnabled('accounting')) {
			$res = @$this->db->query("SELECT SUM(CASE WHEN numero_compte LIKE '4%' THEN credit - debit ELSE 0 END) AS income, SUM(CASE WHEN numero_compte LIKE '5%' OR numero_compte LIKE '6%' THEN debit - credit ELSE 0 END) AS spent, COUNT(*) AS n FROM ".$p."accounting_bookkeeping WHERE entity = ".((int) $conf->entity)." AND doc_date >= '".$a."' AND doc_date <= '".$to."'");
			$o = $res ? $this->db->fetch_object($res) : null;
			if ($o && (int) $o->n > 0) {
				return array('total' => round((float) $o->income, 2), 'spent' => round((float) $o->spent, 2), 'lines' => array(array('Income accounts (4xxx) in the books', round((float) $o->income, 2))), 'notes' => array(), 'source' => 'books');
			}
		}
		$lines = array();
		$notes = array();
		$total = $spent = 0.0;
		$gb = $train = $ppUnmatched = 0.0;
		$res = @$this->db->query("SELECT SUM(amount) AS s FROM ".$p."onboarding_payment WHERE entity = ".((int) $conf->entity)." AND transacted_at >= '".$a."' AND transacted_at <= '".$to."'");
		$gb = ($res && ($o = $this->db->fetch_object($res))) ? (float) $o->s : 0;
		$res = @$this->db->query("SELECT SUM(amount) AS s FROM ".$p."onboarding_training WHERE entity = ".((int) $conf->entity)." AND status <> 'ignored' AND transacted_at >= '".$a."' AND transacted_at <= '".$to."'");
		$train = ($res && ($o = $this->db->fetch_object($res))) ? (float) $o->s : 0;
		$accounts = $this->accounts();
		$paypal = false;
		foreach ($accounts as $acc) {
			$paypal = $paypal || strtoupper((string) $acc->ref) === 'PAYPAL';
		}
		$left = 0.0;
		foreach ($accounts as $acc) {
			$in = $out = 0.0;
			$sql = "SELECT b.amount, b.label, (SELECT COUNT(*) FROM ".$p."bank_url AS u WHERE u.fk_bank = b.rowid AND u.type = 'banktransfert') AS tr";
			$sql .= " FROM ".$p."bank AS b WHERE b.fk_account = ".((int) $acc->rowid)." AND b.dateo >= '".$a."' AND b.dateo <= '".$to."'";
			$res = $this->db->query($sql);
			while ($res && ($o = $this->db->fetch_object($res))) {
				$amt = (float) $o->amount;
				if ((int) $o->tr > 0) {
					continue; // Between the organization's own accounts.
				}
				$label = strtolower((string) $o->label);
				if ($amt > 0 && (($gb + $train > 0 && strpos($label, 'givebutter') !== false) || ($paypal && strtoupper((string) $acc->ref) !== 'PAYPAL' && strpos($label, 'paypal') !== false))) {
					$left += $amt; // A payout of money counted where it was paid.
					continue;
				}
				if ($amt > 0) {
					$in += $amt;
				} else {
					$out -= $amt;
				}
			}
			$lines[] = array((string) $acc->label.': money in', round($in, 2));
			$total += $in;
			$spent += $out;
		}
		if ($gb > 0) {
			$lines[] = array('Givebutter: dues', round($gb, 2));
			$total += $gb;
		}
		if ($train > 0) {
			$lines[] = array('Givebutter: training fees', round($train, 2));
			$total += $train;
		}
		$res = @$this->db->query("SELECT SUM(amount) AS s FROM ".$p."onboarding_paypal WHERE entity = ".((int) $conf->entity)." AND status = 'unmatched' AND transacted_at >= '".$a."' AND transacted_at <= '".$to."'");
		$ppUnmatched = ($res && ($o = $this->db->fetch_object($res))) ? (float) $o->s : 0;
		if ($ppUnmatched > 0) {
			$lines[] = array('PayPal dues not matched to a member yet', round($ppUnmatched, 2));
			$total += $ppUnmatched;
		}
		if ($left > 0) {
			$notes[] = self::money($left).' of Givebutter and PayPal payouts into the bank are left out: that money is counted where it was paid.';
		}
		if ($paypal) {
			$notes[] = 'PayPal: only dues the sync recorded. Other PayPal income (donations, sales) is in the PayPal statement, not here.';
		}
		if ($gb + $train > 0) {
			$notes[] = 'Givebutter: dues and training fees only. Donations made on Givebutter are in its report, not here.';
		}
		return array('total' => round($total, 2), 'spent' => round($spent, 2), 'lines' => $lines, 'notes' => $notes, 'source' => 'bank');
	}

	/**
	 * Which IRS form, from the three-year average (this year and the two before;
	 * the gross receipts reported then, else Dolibarr's estimate; a year with
	 * neither is left out of the average).
	 *
	 * @param object $f Filing
	 * @return array{form:string,average:float,years:array<int,array{fy:int,amount:float,reported:bool,data:bool}>,this:array}
	 */
	public function form($f)
	{
		$this1 = $this->receipts((int) $f->fy);
		$years = array();
		$sum = 0.0;
		$known = 0;
		for ($fy = (int) $f->fy - 2; $fy <= (int) $f->fy; $fy++) {
			$row = $fy == (int) $f->fy ? $f : $this->fetchFy($fy);
			$reported = $row && $row->receipts !== null && $row->receipts !== '';
			$amt = $reported ? (float) $row->receipts : ($fy == (int) $f->fy ? $this1['total'] : $this->receipts($fy)['total']);
			// A year before Dolibarr had any of its money is not a year of nothing.
			$data = $reported || $amt > 0 || $fy == (int) $f->fy;
			$years[] = array('fy' => $fy, 'amount' => $amt, 'reported' => $reported, 'data' => $data);
			if ($data) {
				$sum += $amt;
				$known++;
			}
		}
		$avg = round($sum / max(1, $known), 2);
		$this_year = $years[2]['amount'];
		$form = $avg <= self::LIMIT_N ? '990-N' : ($this_year < self::LIMIT_EZ ? '990-EZ' : '990');
		return array('form' => $form, 'average' => $avg, 'years' => $years, 'this' => $this1);
	}

	// ---------------------------------------------------------------- steps

	/**
	 * "All uploaded": go on to filing.
	 *
	 * @param object $f Filing
	 * @param string $who Who
	 * @param string $note Why anything still missing is missing
	 * @return string done, already, or note (something is missing and no note was given)
	 */
	public function docsDone($f, $who, $note)
	{
		if ($f->status != 'docs') {
			return 'already';
		}
		$missing = $this->missing($f);
		$note = trim((string) $note);
		if ($missing && $note === '') {
			return 'note';
		}
		$this->save($f->rowid, array('status' => 'file', 'docs_done_at' => $this->db->idate(OnboardingService::now()), 'docs_done_by' => $who, 'docs_note' => $note !== '' ? dol_trunc($note, 495, 'right', 'UTF-8', 1) : null, 'asks' => 0, 'last_ask' => null));
		$this->log($f->rowid, 'docs_done', $who, ($missing ? count($missing).' item(s) still missing: '.$note : 'Everything uploaded'));
		$this->ask($this->fetch($f->rowid), false);
		return 'done';
	}

	/**
	 * @param object $f Filing
	 * @param string $who Who
	 * @param string $day Y-m-d
	 * @param float|null $receipts Gross receipts reported
	 * @param string $form 990-N, 990-EZ or 990
	 * @return string done or already
	 */
	public function irsFiled($f, $who, $day, $receipts, $form)
	{
		if ($f->irs_filed_at) {
			return 'already';
		}
		$day = self::isDay($day) && $day <= self::today() ? $day : self::today();
		$form = in_array($form, array('990-N', '990-EZ', '990'), true) ? $form : '990-N';
		$this->save($f->rowid, array('irs_filed_at' => $day, 'irs_filed_by' => $who, 'irs_form' => $form, 'receipts' => $receipts !== null ? (float) $receipts : null, 'status' => $f->status == 'docs' ? 'file' : $f->status));
		$this->log($f->rowid, 'irs_filed', $who, 'Form '.$form.' filed '.$day.($receipts !== null ? ', gross receipts '.self::money($receipts) : ''));
		$this->finishIfDone($this->fetch($f->rowid));
		return 'done';
	}

	/**
	 * @param object $f Filing
	 * @param string $who Who
	 * @param string $day Y-m-d
	 * @return string done or already
	 */
	public function stateFiled($f, $who, $day)
	{
		if ($f->state_filed_at) {
			return 'already';
		}
		$day = self::isDay($day) && $day <= self::today() ? $day : self::today();
		$this->save($f->rowid, array('state_filed_at' => $day, 'state_filed_by' => $who, 'status' => $f->status == 'docs' ? 'file' : $f->status));
		$this->log($f->rowid, 'state_filed', $who, $this->facts()['state'].' report filed '.$day);
		$this->finishIfDone($this->fetch($f->rowid));
		return 'done';
	}

	/**
	 * Both filed: close the year and tell the treasurer and the board.
	 *
	 * @param object $f Filing
	 * @return void
	 */
	private function finishIfDone($f)
	{
		if (!$f || $f->status == 'done' || !$f->irs_filed_at || !$f->state_filed_at) {
			return;
		}
		$this->save($f->rowid, array('status' => 'done'));
		$this->log($f->rowid, 'done', '', 'Both filings done');
		$facts = $this->facts();
		$org = $facts['name'] !== '' ? $facts['name'] : 'the organization';
		$body = $org."'s filings for ".self::showPeriod((int) $f->fy)." (IRS tax year ".self::taxYear((int) $f->fy).") are done:\n\n";
		$body .= "- IRS Form ".($f->irs_form ? $f->irs_form : '990-N').": filed ".self::show($f->irs_filed_at)." by ".$f->irs_filed_by.($f->receipts !== null ? ", gross receipts ".self::money($f->receipts) : '')."\n";
		$body .= "- ".$facts['state']." annual registration report: filed ".self::show($f->state_filed_at)." by ".$f->state_filed_by."\n\n";
		$body .= "The statements and confirmations are kept in Dolibarr (CGW, Yearly filings): ".self::staffUrl()."\n\n";
		$body .= "In a few weeks the return should show on the IRS Tax Exempt Organization Search (".self::IRS_CHECK_URL.") under EIN ".$facts['ein'].".\n\nThank you.\n".$org;
		$to = array_keys($this->treasurers() + $this->board());
		$this->svc->mail(implode(', ', $to), 'FY'.$f->fy.' tax filings are done', $body);
	}

	/**
	 * A question from the page: to the president and the alerts address.
	 *
	 * @param object $f Filing
	 * @param string $who Who asked
	 * @param string $text Question
	 * @param string $replyTo Their address, for the reply
	 * @return bool Sent
	 */
	public function help($f, $who, $text, $replyTo = '')
	{
		$text = trim((string) $text);
		if ($text === '') {
			return false;
		}
		$to = $this->roles->roleEmails(array(OnboardingPurchase::ROLE_PRESIDENT));
		foreach (array_filter(array_map('trim', explode(',', $this->svc->alertTo()))) as $e) {
			$to[] = strtolower($e);
		}
		$to = array_values(array_unique($to));
		$this->log($f->rowid, 'help', $who, $text);
		return $this->svc->mail(implode(', ', $to), 'FY'.$f->fy.' taxes: a question from '.$who, $who." asked, from the FY".$f->fy." filing page:\n\n".$text."\n\nWhere it stands: ".self::STATUSES[$f->status].".\nThe filing in Dolibarr: ".self::staffUrl().($replyTo !== '' ? "\n\nReplying to this email answers ".$replyTo."." : ''), '', $replyTo);
	}

	// ---------------------------------------------------------------- emails

	/**
	 * Email the treasurer the step they are on: the statements, or filing.
	 *
	 * @param object $f Filing
	 * @param bool $reminder Say it is a reminder
	 * @return int Emails sent
	 */
	public function ask($f, $reminder)
	{
		if (!$f || $f->status == 'done') {
			return 0;
		}
		$sent = 0;
		$names = array();
		foreach ($this->treasurers() as $p) {
			list($subject, $body) = $f->status == 'docs' ? $this->docsEmail($f, $p, $reminder) : $this->fileEmail($f, $p, $reminder);
			if ($this->svc->mail($p['email'], $subject, $body)) {
				$sent++;
				$names[] = $p['email'];
			}
		}
		$this->save($f->rowid, array('asks' => (int) $f->asks + 1, 'last_ask' => $this->db->idate(OnboardingService::now())));
		$this->log($f->rowid, $reminder ? 'remind' : 'ask', '', ($f->status == 'docs' ? 'Statements' : 'Ready to file').' email to '.($names ? implode(', ', $names) : 'nobody (no treasurer with an email)'));
		return $sent;
	}

	/**
	 * @param object $f Filing
	 * @param array{email:string,first:string,name:string} $p Treasurer
	 * @param bool $reminder Reminder
	 * @return array{0:string,1:string} Subject and text
	 */
	public function docsEmail($f, $p, $reminder)
	{
		$facts = $this->facts();
		$org = $facts['name'] !== '' ? $facts['name'] : 'the organization';
		$missing = $this->missing($f);
		$fy = (int) $f->fy;
		$subject = ($reminder ? 'Reminder: ' : '').'FY'.$fy.' taxes: please upload the year\'s statements';
		$b = "Hi ".$p['first'].",\n\n";
		$b .= $reminder ? "This is a reminder: some of the year's records are still missing.\n\n" : "It is time to collect ".$org."'s records for the year ".self::showPeriod($fy)." (IRS tax year ".self::taxYear($fy).").\n\n";
		$b .= "They are needed for the IRS Form 990-N, due ".self::show(self::irsDue($fy)).", and the ".$facts['state']." annual registration report, due ".self::show(self::stateDue($fy)).".\n\n";
		if ($missing) {
			$b .= "Still needed:\n- ".implode("\n- ", $missing)."\n\n";
		} else {
			$b .= "Everything is in. Press \"All uploaded\" on the page below to go on.\n\n";
		}
		$b .= "Upload them here, then press \"All uploaded\":\n".$this->link($f->rowid, $p['email'])."\n\n";
		$b .= "No login is needed; the link is yours alone, so please do not forward it. A month already reconciled in Dolibarr's bank module counts without a statement. An account that was not used, or a report there is none of, can be marked \"none\" on the page.\n\n";
		$b .= "After you press \"All uploaded\" you will get a second email with everything needed to file.\n\n".$org;
		return array($subject, $b);
	}

	/**
	 * @param object $f Filing
	 * @param array{email:string,first:string,name:string} $p Treasurer
	 * @param bool $reminder Reminder
	 * @return array{0:string,1:string} Subject and text
	 */
	public function fileEmail($f, $p, $reminder)
	{
		$facts = $this->facts();
		$org = $facts['name'] !== '' ? $facts['name'] : 'the organization';
		$fy = (int) $f->fy;
		$form = $this->form($f);
		$left = array();
		if (!$f->irs_filed_at) {
			$left[] = 'the IRS Form '.$form['form'];
		}
		if (!$f->state_filed_at) {
			$left[] = 'the '.$facts['state'].' report';
		}
		$subject = ($reminder ? 'Reminder: ' : '').'FY'.$fy.' taxes: ready to file '.implode(' and ', $left);
		$b = "Hi ".$p['first'].",\n\n";
		$b .= $reminder ? "This is a reminder: ".implode(' and ', $left)." ".(count($left) > 1 ? 'are' : 'is')." not marked filed yet.\n\n" : "The records for ".self::showPeriod($fy)." are in. Here is everything needed to file.\n\n";
		$n = 0;
		if (!$f->irs_filed_at) {
			$b .= ++$n.". IRS Form ".$form['form'].", due ".self::show(self::irsDue($fy))."\n\n";
			$b .= $this->irsText($f, $form, $facts);
			$b .= "\n   When it is accepted, press \"IRS return filed\" on the page below (attach the confirmation if you like).\n\n";
		}
		if (!$f->state_filed_at) {
			$b .= ++$n.". ".$facts['state']." annual registration report, due ".self::show(self::stateDue($fy))."\n\n";
			$b .= "   File it at ".$facts['state_url'].($facts['state_id'] !== '' ? " (charter number ".$facts['state_id'].")" : '').".\n";
			$officers = $this->officers();
			if ($officers) {
				$b .= "   It asks for the officers and directors. Dolibarr's role tags say:\n   - ".implode("\n   - ", $officers)."\n";
			}
			$b .= "   Then press \"State report filed\" on the page below.\n\n";
		}
		$b .= "The page, with every statement uploaded for the year and the buttons:\n".$this->link($f->rowid, $p['email'])."\n\n";
		$b .= "Something does not look right? The page has a box to ask.\n\n".$org;
		return array($subject, $b);
	}

	/**
	 * What to type into the IRS form, and the numbers behind it.
	 *
	 * @param object $f Filing
	 * @param array $form From form()
	 * @param array<string,string> $facts From facts()
	 * @return string Indented text
	 */
	public function irsText($f, $form, $facts)
	{
		list($a, $b) = self::period((int) $f->fy);
		$t = '';
		if ($form['form'] == '990-N') {
			$t .= "   File it at ".self::IRS_URL."\n   (sign in with an IRS account through Login.gov or ID.me). Enter:\n";
			$t .= "   - EIN: ".($facts['ein'] !== '' ? $facts['ein'] : '(set it in Setup, Company, professional ID 1)')."\n";
			$t .= "   - Tax year: ".self::taxYear((int) $f->fy).", beginning ".date('m/d/Y', strtotime($a))." and ending ".date('m/d/Y', strtotime($b))."\n";
			$t .= "   - Legal name: ".($facts['name'] !== '' ? $facts['name'] : '(set it in Setup, Company)')."\n";
			$t .= "   - Mailing address: ".($facts['address'] !== '' ? $facts['address'] : '(set it in Setup, Company)')."\n";
			$t .= "   - Principal officer: ".($facts['officer'] !== '' ? $facts['officer'] : '(nobody holds the president tag)').", same address\n";
			$t .= "   - Website: ".($facts['website'] !== '' ? $facts['website'] : 'none')."\n";
			$t .= "   - Gross receipts normally \$50,000 or less: Yes\n";
			$t .= "   - Has the organization terminated or gone out of business: No\n";
		} else {
			$t .= "   The three-year average is over \$50,000, so the IRS needs Form ".$form['form']." instead of the 990-N postcard.\n";
			$t .= "   It is a longer return; see ".self::IRS_EZ_URL." or ask an accountant. It is still due on the same day.\n";
			$t .= "   Money in this year: ".self::money($form['this']['total']).". Money out: ".self::money($form['this']['spent']).".\n";
		}
		$t .= "\n   Gross receipts (everything that came in, before costs), Dolibarr's estimate for this year: ".self::money($form['this']['total'])."\n";
		foreach ($form['this']['lines'] as $l) {
			$t .= "     ".$l[0].": ".self::money($l[1])."\n";
		}
		foreach ($form['this']['notes'] as $note) {
			$t .= "     (".$note.")\n";
		}
		$avg = array();
		foreach ($form['years'] as $y) {
			$avg[] = self::yearText($y);
		}
		$t .= "   Three-year average: ".self::money($form['average'])." (".implode(', ', $avg)."). The 990-N is allowed up to \$50,000.\n";
		if ($form['form'] == '990-N' && max($form['this']['total'], $form['average']) > 40000) {
			$t .= "   That is close to the \$50,000 line: check the total against the statements before filing.\n";
		}
		$t .= "   Check the estimate against the statements; press \"IRS return filed\" with the figure you used, so next year's average is right.\n";
		return $t;
	}

	/**
	 * @param array{fy:int,amount:float,reported:bool,data:bool} $y A year of the average
	 * @return string "FY2026 $12,000.00 reported"
	 */
	public static function yearText($y)
	{
		return 'FY'.$y['fy'].' '.($y['data'] ? self::money($y['amount']).($y['reported'] ? ' reported' : ' estimated') : 'no data, left out');
	}

	/**
	 * Tell the rest of the board the IRS (or state) deadline is close and it is not filed.
	 *
	 * @param object $f Filing
	 * @param string $what irs or state
	 * @param string $due Y-m-d
	 * @return int Emails sent
	 */
	public function escalate($f, $what, $due)
	{
		$facts = $this->facts();
		$org = $facts['name'] !== '' ? $facts['name'] : 'the organization';
		$left = self::days(self::today(), $due);
		$name = $what == 'irs' ? 'IRS Form 990-N' : $facts['state'].' annual registration report';
		$when = $left > 0 ? 'is due in '.$left.' day'.($left == 1 ? '' : 's').' ('.self::show($due).')' : ($left == 0 ? 'is due today' : 'was due '.self::show($due).', '.(-$left).' day'.($left == -1 ? '' : 's').' ago');
		$where = $f->status == 'docs' ? 'Waiting for the treasurer to upload the year\'s statements ('.$this->missingCount($f).' still missing).' : 'Ready to file since '.self::show($f->docs_done_at).'.';
		$treasurers = array();
		foreach ($this->treasurers() as $t) {
			$treasurers[] = $t['name'];
		}
		$sent = 0;
		$board = $this->board();
		foreach ($board as $p) {
			$b = "Hi ".$p['first'].",\n\n".$org."'s ".$name." for ".self::showPeriod((int) $f->fy)." ".$when." and is not marked filed yet.\n\n";
			$b .= "Where it stands: ".$where."\nThe treasurer (".implode(', ', $treasurers).") has been emailed ".((int) $f->asks)." time".((int) $f->asks == 1 ? '' : 's').".\n\n";
			if ($what == 'irs') {
				$b .= "Missing the IRS return three years in a row takes away the 501(c)(3) status automatically; ".$org." has had it reinstated once before, so please help make sure it gets done.\n\n";
			}
			$b .= "Your own link to the filing page (you can upload statements and mark it filed there too):\n".$this->link($f->rowid, $p['email'])."\n\n".$org;
			if ($this->svc->mail($p['email'], 'FY'.$f->fy.' taxes: the '.$name.' '.($left >= 0 ? 'is due '.($left == 0 ? 'today' : 'in '.$left.' days') : 'is overdue'), $b)) {
				$sent++;
			}
		}
		$this->log($f->rowid, 'escalate', '', $name.' '.$when.': board emailed ('.($board ? implode(', ', array_keys($board)) : 'nobody else on the board has an email').')');
		return $sent;
	}

	// ---------------------------------------------------------------- daily

	/**
	 * Daily: open the year when it is time, remind, escalate.
	 *
	 * @return string Summary
	 */
	public function run()
	{
		$today = self::today();
		$out = array();
		$fy = self::latestEnded($today);
		if (getDolGlobalString('ONBOARDING_FILING_AUTOSTART', '1') !== '0' && !$this->fetchFy($fy) && $today >= self::startsOn($fy) && $today <= self::irsDue($fy)) {
			$f = $this->start($fy, 'the daily job');
			if ($f) {
				$this->ask($f, false);
				$out[] = 'FY'.$fy.' filing opened, treasurer asked';
			}
		}
		$every = max(1, (int) getDolGlobalString('ONBOARDING_FILING_REMIND_DAYS', '7'));
		$steps = array();
		foreach (explode(',', getDolGlobalString('ONBOARDING_FILING_ESCALATE', '30,7,0')) as $s) {
			if (trim($s) !== '' && is_numeric(trim($s))) {
				$steps[] = (int) trim($s);
			}
		}
		rsort($steps);
		foreach ($this->all() as $f) {
			if ($f->status == 'done') {
				continue;
			}
			$last = $f->last_ask ? $this->db->jdate($f->last_ask) : 0;
			// An hour's slack so a job that runs a little earlier each day still reminds.
			if (!$last || OnboardingService::now() - $last >= $every * 86400 - 3600) {
				$this->ask($f, (bool) $last);
				$out[] = 'FY'.$f->fy.' treasurer reminded';
				$f = $this->fetch($f->rowid);
			}
			$what = !$f->irs_filed_at ? 'irs' : (!$f->state_filed_at ? 'state' : '');
			if ($what === '' || !$steps) {
				continue;
			}
			$due = $what == 'irs' ? self::irsDue((int) $f->fy) : self::stateDue((int) $f->fy);
			$left = self::days($today, $due);
			$done = (string) $f->escalated;
			$hit = array();
			foreach ($steps as $s) {
				if ($left <= $s && strpos($done, ','.$what.$s.',') === false) {
					$hit[] = $what.$s;
				}
			}
			if ($hit) {
				// Several steps passed at once (the job was not running): one email.
				$this->escalate($f, $what, $due);
				$this->save($f->rowid, array('escalated' => ($done !== '' ? $done : ',').implode(',', $hit).','));
				$out[] = 'FY'.$f->fy.' board told';
			}
		}
		return $out ? implode('; ', $out) : 'filings: nothing to do';
	}

	/**
	 * For Needs attention.
	 *
	 * @return array{label:string,why:string,urgent:bool}|null What is waiting, if anything
	 */
	public function attention()
	{
		$today = self::today();
		foreach ($this->all() as $f) {
			if ($f->status == 'done') {
				continue;
			}
			$due = !$f->irs_filed_at ? self::irsDue((int) $f->fy) : self::stateDue((int) $f->fy);
			$left = self::days($today, $due);
			$when = $left >= 0 ? 'due '.self::show($due) : 'overdue since '.self::show($due);
			if ($f->status == 'docs') {
				return array('label' => 'FY'.$f->fy.' taxes: statements missing', 'why' => $this->missingCount($f).' statements or reports not uploaded yet; the treasurer is reminded weekly. '.ucfirst($when).'.', 'urgent' => $left <= 14);
			}
			return array('label' => 'FY'.$f->fy.' taxes: ready to file', 'why' => (!$f->irs_filed_at ? 'The IRS return' : 'The state report').' is not marked filed; '.$when.'.', 'urgent' => $left <= 14);
		}
		$fy = self::latestEnded($today);
		if (!$this->fetchFy($fy) && $today >= self::startsOn($fy)) {
			return array('label' => 'FY'.$fy.' taxes: not tracked', 'why' => 'Start the year on Yearly filings, or record it as already filed.', 'urgent' => false);
		}
		return null;
	}
}
