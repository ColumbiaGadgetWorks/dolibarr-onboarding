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
 * Who approves: the zone lead, up to the lead limit and while the zone has the
 * money. Anything bigger, over budget, from a zone with no lead, or from the lead
 * themselves needs the treasurer and one other director. Nobody approves their own.
 */
class OnboardingPurchase
{
	const KINDS = array('reimburse' => 'Reimburse me', 'purchase' => 'Request for purchase');
	const PAY_METHODS = array('venmo' => 'Venmo', 'paypal' => 'PayPal', 'check' => 'Check');
	const MAX_FILES = 5;
	const MAX_FILE_BYTES = 5242880;
	const MAX_AMOUNT = 5000;
	const BAD_EMAIL_LIMIT = 5;
	const BAD_IP_LIMIT = 20;
	const BAD_ALL_LIMIT = 100;
	const DEFAULT_LEADS = "Woodworking = Peter Tipton\nMachining = Jeff Thiele\nMetalworking = Jim Fieser\nDigital Fab = Will Smith\nCrafting = Madison Pawlicsak\nElectronics = Isaiah Davis-Stober";

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

	/**
	 * @param string $list Comma, semicolon or newline separated addresses
	 * @return string[] Lower case addresses
	 */
	private static function emails($list)
	{
		$out = array();
		foreach (preg_split('/[\s,;]+/', (string) $list) as $e) {
			$e = OnboardingService::cleanEmail($e);
			if ($e !== '' && strpos($e, '@') !== false) {
				$out[$e] = $e;
			}
		}
		return array_values($out);
	}

	/**
	 * @return string Treasurer's email, lower case, or empty
	 */
	public function treasurer()
	{
		$e = self::emails(getDolGlobalString('ONBOARDING_PURCHASE_TREASURER_EMAIL'));
		return $e ? $e[0] : '';
	}

	/**
	 * @return string[] The treasurer and the other directors who may approve, lower case
	 */
	public function directors()
	{
		$all = self::emails(getDolGlobalString('ONBOARDING_PURCHASE_DIRECTOR_EMAILS'));
		if ($this->treasurer() !== '') {
			array_unshift($all, $this->treasurer());
		}
		return array_values(array_unique($all));
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
	 * @param string $name First and last name, or an email address
	 * @return object|null
	 */
	public function memberByNameOrEmail($name)
	{
		$name = trim((string) $name);
		if (strpos($name, '@') !== false) {
			return $this->memberByEmail($name);
		}
		return $this->memberWhere("LOWER(CONCAT(a.firstname, ' ', a.lastname)) = '".$this->db->escape(strtolower(preg_replace('/\s+/', ' ', $name)))."'");
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
	 * @return array<string,object|null> Zone name => its lead's member row
	 */
	public function zoneLeads()
	{
		$text = getDolGlobalString('ONBOARDING_PURCHASE_ZONE_LEADS');
		if (trim($text) === '') {
			$text = self::DEFAULT_LEADS;
		}
		$out = array();
		foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
			if (strpos($line, '=') === false) {
				continue;
			}
			list($zone, $who) = array_map('trim', explode('=', $line, 2));
			if ($zone !== '' && $who !== '') {
				$out[$zone] = $this->memberByNameOrEmail($who);
			}
		}
		return $out;
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
	 * allocated = everything put in (budget added, training shares), spent = everything
	 * taken out, committed = requests not yet paid, available = what is left to ask for.
	 *
	 * @return array<int,array{zone:string,allocated:float,spent:float,committed:float,available:float}>
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
		$rows = array();
		foreach ($this->training->zones() as $z) {
			$a = isset($in[$z]) ? $in[$z] : 0.0;
			$s = isset($out[$z]) ? $out[$z] : 0.0;
			$c = $this->committed($z);
			$rows[] = array('zone' => $z, 'allocated' => round($a, 2), 'spent' => round($s, 2), 'committed' => $c, 'available' => round($a - $s - $c, 2));
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
		$method = $get('pay_method', 20);
		$handle = $get('pay_handle', 200);
		$date = $get('purchase_date', 10);
		$today = dol_print_date(OnboardingService::now(), '%Y-%m-%d');

		if (!isset(self::KINDS[$kind])) {
			return $bad('kind');
		}
		if (!in_array($zone, $this->training->zones(), true)) {
			return $bad('zone');
		}
		if ($amount <= 0 || $amount > self::MAX_AMOUNT) {
			return $bad('amount');
		}
		if (strlen($description) < 3) {
			return $bad('description');
		}
		if ($kind == 'purchase') {
			$parts = parse_url($link);
			if ($link === '' || !filter_var($link, FILTER_VALIDATE_URL) || !$parts || !in_array(strtolower(isset($parts['scheme']) ? $parts['scheme'] : ''), array('http', 'https'), true)) {
				return $bad('link');
			}
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

		$token = bin2hex(random_bytes(24));
		$sql = "INSERT INTO ".$this->db->prefix()."onboarding_purchase (entity, token, fk_adherent, zone, kind, amount, vendor, purchase_date, link, description, pay_method, pay_handle, status, need_board, ip, files, datec) VALUES (";
		$sql .= ((int) $conf->entity).", '".$token."', ".((int) $m->rowid).", '".$this->db->escape($zone)."', '".$this->db->escape($kind)."', ".price2num($amount).", '".$this->db->escape($vendor)."', '".$this->db->escape($date)."', '".$this->db->escape($link)."', '".$this->db->escape($description)."', '".$this->db->escape($method)."', '".$this->db->escape($handle)."', 'draft', 0, '".$this->db->escape($ip)."', '[]', '".$this->db->idate(OnboardingService::now())."')";
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
		$leads = $this->zoneLeads();
		$lead = isset($leads[$row->zone]) ? $leads[$row->zone] : null;
		$board = (float) $row->amount > self::leadLimit()
			|| (float) $row->amount > $this->available($row->zone)
			|| !$lead
			|| ($member && $lead && OnboardingService::cleanEmail($lead->email) === OnboardingService::cleanEmail($member->email));
		$this->save($row->rowid, array('status' => 'pending', 'need_board' => $board ? 1 : 0, 'approvals' => '[]', 'date_submitted' => $this->db->idate(OnboardingService::now())));
		$row = $this->fetch($row->rowid);

		$name = $member ? trim($member->firstname.' '.$member->lastname) : 'A member';
		$to = $member ? $member->email : '';
		$org = getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'the makerspace');
		$this->svc->mail($to, $row->ref.': we have your request', "Hi ".($member ? $member->firstname : 'there').",\n\nWe received your request ".$row->ref." for ".self::money($row->amount)." from the ".$row->zone." budget. ".($board ? "Because of its size or the zone, the treasurer and another director will look at it." : "The ".$row->zone." lead will look at it.")." You will get an email when it is decided.\n\n".$org);

		$sent = 0;
		foreach ($this->approvers($row) as $a) {
			if ($this->svc->mail($a['email'], 'Please decide: '.$row->ref.' '.self::money($row->amount).' from '.$row->zone, $this->approverBody($row, $a, $name))) {
				$sent++;
			}
		}
		if (!$sent) {
			$this->svc->mail(getDolGlobalString('ONBOARDING_STAFF_EMAIL'), $row->ref.' has nobody to approve it', "Request ".$row->ref." (".self::money($row->amount)." from ".$row->zone.") was filed by ".$name.", but no approver has an email address. Set the zone leads, treasurer and directors on the Member onboarding setup page.");
		}
		return array('ok' => true, 'ref' => $row->ref, 'needs' => $board ? 'board' : 'lead');
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
		$out = array();
		if (!$row->need_board) {
			$leads = $this->zoneLeads();
			$lead = isset($leads[$row->zone]) ? $leads[$row->zone] : null;
			if ($lead && $lead->email) {
				$out[] = array('email' => OnboardingService::cleanEmail($lead->email), 'name' => trim($lead->firstname.' '.$lead->lastname), 'role' => 'lead');
			}
		} else {
			foreach ($this->directors() as $e) {
				$out[] = array('email' => $e, 'name' => $e, 'role' => $e === $this->treasurer() ? 'treasurer' : 'director');
			}
		}
		return array_values(array_filter($out, function ($a) use ($own) {
			return $a['email'] !== $own;
		}));
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
		$b .= $row->kind == 'purchase' ? "They want the treasurer to buy it: ".$row->link."\n" : "Already bought at ".$row->vendor." on ".$row->purchase_date.". Receipts are attached to the request.\n";
		$b .= "What and why: ".$row->description."\n\n";
		$b .= $row->need_board ? "This one needs the treasurer and one other director to approve.\n\n" : "As the zone lead you can decide this one alone.\n\n";
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
	 * @return string done, approved (the last approval came in), rejected, already, closed, not-approver
	 */
	public function decide($id, $email, $action)
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
			$this->save($row->rowid, array('status' => 'rejected', 'decided_note' => 'Rejected by '.$mine['name']));
			$this->svc->mail($member ? $member->email : '', $row->ref.': not approved', "Hi,\n\nYour request ".$row->ref." for ".self::money($row->amount)." from the ".$row->zone." budget was not approved. Ask ".$mine['name']." or the treasurer if you would like to know why or want to try again.");
			return 'rejected';
		}
		$approvals = $this->approvalsOf($row);
		foreach ($approvals as $a) {
			if ($a['email'] === $email) {
				return 'already';
			}
		}
		$approvals[] = array('email' => $email, 'name' => $mine['name'], 'role' => $mine['role'], 'at' => OnboardingService::now());
		$done = true;
		if ($row->need_board) {
			// The treasurer and one other director. If the treasurer is the one asking,
			// any two other directors do.
			$treasurer = $this->treasurer();
			$own = $member ? OnboardingService::cleanEmail($member->email) : '';
			$has = array_map(function ($a) {
				return $a['email'];
			}, $approvals);
			$done = count($has) >= 2 && ($treasurer === '' || $treasurer === $own || in_array($treasurer, $has, true));
		}
		$this->save($row->rowid, array('approvals' => json_encode($approvals), 'status' => $done ? 'approved' : 'pending'));
		if ($done) {
			$this->svc->mail($member ? $member->email : '', $row->ref.': approved', "Good news. Your request ".$row->ref." for ".self::money($row->amount)." from the ".$row->zone." budget was approved. ".($row->kind == 'purchase' ? "The treasurer will order it." : "The treasurer will pay you back by ".(isset(self::PAY_METHODS[$row->pay_method]) ? self::PAY_METHODS[$row->pay_method] : 'the method you chose').'.'));
			$this->svc->mail($this->treasurer(), $row->ref.' is approved, ready to pay', "Request ".$row->ref." from ".$who.": ".self::money($row->amount)." from ".$row->zone." is approved. ".($row->kind == 'purchase' ? 'Buy it: '.$row->link : 'Pay '.(isset(self::PAY_METHODS[$row->pay_method]) ? self::PAY_METHODS[$row->pay_method] : '').' '.$row->pay_handle.'.')."\nThen press Mark paid on the Purchase requests page in Dolibarr.");
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
	public function markPaid($id, $accountId, $user)
	{
		$row = $this->fetch($id);
		if (!$row || $row->status != 'approved') {
			return 'Error: only an approved request can be marked paid.';
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
		$line = $acc->addline(OnboardingService::now(), $type, $label, -abs((float) $row->amount), '', 0, $user);
		if ($line <= 0) {
			return 'Error: the bank line could not be created: '.$acc->error;
		}
		if ($member) {
			$acc->add_url_line($line, (int) $member->rowid, DOL_URL_ROOT.'/adherents/card.php?rowid=', $who, 'member');
		}
		$this->training->post('zone', $row->zone, -abs((float) $row->amount), 'spend', 0, $row->ref.' '.($row->vendor !== '' ? $row->vendor : 'purchase').($who !== '' ? ' ('.$who.')' : ''), 0, (int) $user->id);
		$this->save($row->rowid, array('status' => 'paid', 'fk_bank_line' => (int) $line, 'paid_from' => $acc->label, 'paid_at' => $this->db->idate(OnboardingService::now()), 'fk_user_paid' => (int) $user->id));
		$this->svc->mail($member ? $member->email : '', $row->ref.': paid', "Your request ".$row->ref." for ".self::money($row->amount)." was paid from ".$acc->label.". Thank you.");
		return $row->ref.' marked paid: '.self::money($row->amount).' out of '.$acc->label.' and the '.$row->zone.' budget.';
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
