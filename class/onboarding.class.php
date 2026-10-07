<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 * All onboarding logic. The applicant table is the source of truth; the extra
 * fields on contacts and members mirror it so staff see it on the normal cards.
 */
class OnboardingService
{
	/** @var DoliDB */
	public $db;

	/** @var int|null Overrides the clock, for tests and the sandbox. */
	public static $now = null;

	/** Fields mirrored onto both the contact and the member card. */
	const MIRRORED = array(
		'onb_discord' => array('Discord username', 'varchar', 100),
		'onb_notify_events' => array('Notify: events', 'boolean', ''),
		'onb_notify_news' => array('Notify: news and updates', 'boolean', ''),
		'onb_waiver_signed' => array('Waiver signed', 'boolean', ''),
		'onb_agreement_signed' => array('Agreement signed', 'boolean', ''),
		'onb_id_uploaded' => array('ID photo uploaded', 'boolean', ''),
		'onb_payment_state' => array('Dues status', 'select', ''),
	);

	const PAYMENT_STATES = array(
		'none' => 'No payment yet',
		'active' => 'Paying',
		'past_due' => 'Payment missed',
		'cancelled' => 'Payment cancelled',
		'lapsed' => 'Non-paying',
	);

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @return int Current time, or the test override
	 */
	public static function now()
	{
		return self::$now !== null ? self::$now : dol_now();
	}

	// ---------------------------------------------------------------- install

	/**
	 * Create the extra fields on contacts and members. Safe to run again.
	 *
	 * @param DoliDB $db Database handler
	 * @return void
	 */
	public static function installExtraFields($db)
	{
		$extrafields = new ExtraFields($db);
		$pos = 500;
		foreach (array('socpeople', 'adherent') as $element) {
			$extrafields->fetch_name_optionals_label($element, true);
			$have = isset($extrafields->attributes[$element]['label']) ? $extrafields->attributes[$element]['label'] : array();
			$fields = self::MIRRORED;
			if ($element == 'adherent') {
				$fields['onb_badge_id'] = array('Badge ID', 'varchar', 64);
				$fields['onb_badge_access'] = array('Badge access active', 'boolean', '');
				$fields['onb_dues_waived_until'] = array('Dues waived until', 'date', '');
			}
			foreach ($fields as $name => $def) {
				$pos++;
				if (isset($have[$name])) {
					continue;
				}
				$param = '';
				if ($def[1] == 'select') {
					$param = array('options' => self::PAYMENT_STATES);
				}
				$extrafields->addExtraField($name, $def[0], $def[1], $pos, $def[2], $element, 0, 0, '', $param, 1, '', '1');
			}
		}
	}

	/**
	 * Make sure a Standard and a Supporter member type exist and are configured.
	 *
	 * @param DoliDB $db Database handler
	 * @param User $user Acting user
	 * @return void
	 */
	public static function installMemberTypes($db, $user)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		foreach (array('ONBOARDING_TYPE_STANDARD' => 'Standard', 'ONBOARDING_TYPE_SUPPORTER' => 'Supporter') as $const => $label) {
			if (getDolGlobalInt($const) > 0) {
				continue;
			}
			$id = 0;
			$res = $db->query("SELECT rowid FROM ".$db->prefix()."adherent_type WHERE libelle = '".$db->escape($label)."' AND entity IN (".getEntity('member_type').")");
			if ($res && ($obj = $db->fetch_object($res))) {
				$id = (int) $obj->rowid;
			} else {
				$type = new AdherentType($db);
				$type->label = $label;
				$type->morphy = 'phy';
				$type->status = 1;
				$type->subscription = 1;
				$type->vote = 1;
				$id = $type->create($user);
			}
			if ($id > 0) {
				dolibarr_set_const($db, $const, $id, 'chaine', 0, '', $conf->entity);
			}
		}
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * @return User The user recorded as author of what the website does
	 */
	public function actor()
	{
		static $actor = null;
		if ($actor === null) {
			$actor = new User($this->db);
			$id = getDolGlobalInt('ONBOARDING_ACTOR_USER');
			if ($id <= 0) {
				$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."user WHERE admin = 1 AND statut = 1 ORDER BY rowid ASC LIMIT 1");
				if ($res && ($obj = $this->db->fetch_object($res))) {
					$id = (int) $obj->rowid;
				}
			}
			if ($id > 0) {
				$actor->fetch($id);
				$actor->getrights();
			}
		}
		return $actor;
	}

	/**
	 * The waiver and agreement as currently configured. The version is a hash of
	 * the text, so a signature always points at the exact words that were shown.
	 *
	 * @return array<string,array{title:string,text:string,version:string}>
	 */
	public function docs()
	{
		$out = array();
		$defaults = array(
			'waiver' => array('Liability waiver', "PLACEHOLDER WAIVER. Replace this text in the Onboarding module setup before going live.\n\nI understand that a makerspace contains tools that can injure me, and I accept that risk."),
			'agreement' => array('Membership agreement', "PLACEHOLDER AGREEMENT. Replace this text in the Onboarding module setup before going live.\n\nI agree to follow the shop rules, clean up after myself, and pay my dues."),
		);
		foreach ($defaults as $key => $def) {
			$text = getDolGlobalString('ONBOARDING_'.strtoupper($key).'_TEXT', $def[1]);
			$text = str_replace("\r\n", "\n", $text);
			$out[$key] = array('title' => $def[0], 'text' => $text, 'version' => substr(hash('sha256', $text), 0, 16));
		}
		return $out;
	}

	/**
	 * @param string $email Email
	 * @return string Normalised email
	 */
	public static function cleanEmail($email)
	{
		return strtolower(trim((string) $email));
	}

	/**
	 * @param string $where SQL condition
	 * @return object|null Applicant row
	 */
	private function one($where)
	{
		global $conf;
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_applicant WHERE entity = ".((int) $conf->entity)." AND ".$where." LIMIT 1");
		if ($res && ($obj = $this->db->fetch_object($res))) {
			return $obj;
		}
		return null;
	}

	/**
	 * @param int $id Applicant id
	 * @return object|null
	 */
	public function fetch($id)
	{
		return $this->one("rowid = ".((int) $id));
	}

	/**
	 * @param string $email Email
	 * @return object|null
	 */
	public function findByEmail($email)
	{
		$email = self::cleanEmail($email);
		return $email === '' ? null : $this->one("email = '".$this->db->escape($email)."'");
	}

	/**
	 * @param string $token Token given to the browser at signup
	 * @return object|null
	 */
	public function findByToken($token)
	{
		$token = (string) $token;
		if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
			return null;
		}
		return $this->one("token_hash = '".$this->db->escape(hash('sha256', $token))."'");
	}

	/**
	 * Update columns of an applicant row. Null clears a column.
	 *
	 * @param int $id Applicant id
	 * @param array<string,mixed> $fields Column => value
	 * @return bool
	 */
	public function save($id, $fields)
	{
		$set = array();
		foreach ($fields as $col => $val) {
			if (!preg_match('/^[a-z_]+$/', $col)) {
				continue;
			}
			$set[] = $col." = ".($val === null ? "NULL" : "'".$this->db->escape((string) $val)."'");
		}
		if (!$set) {
			return true;
		}
		return (bool) $this->db->query("UPDATE ".$this->db->prefix()."onboarding_applicant SET ".implode(', ', $set)." WHERE rowid = ".((int) $id));
	}

	/**
	 * @param object $app Applicant row
	 * @return string Folder holding this applicant's signed documents and ID photo
	 */
	public function dir($app)
	{
		$dir = DOL_DATA_ROOT.'/onboarding/applicant/'.((int) $app->rowid);
		dol_mkdir($dir);
		return $dir;
	}

	/**
	 * @param object $app Applicant row
	 * @return bool All three paperwork items done
	 */
	public static function paperworkDone($app)
	{
		return !empty($app->waiver_signed_at) && !empty($app->agreement_signed_at) && !empty($app->id_uploaded_at);
	}

	/**
	 * Recompute the stage after something changed.
	 *
	 * @param int $id Applicant id
	 * @return object Fresh applicant row
	 */
	public function refreshStage($id)
	{
		$app = $this->fetch($id);
		$paid = in_array($app->payment_state, array('active', 'past_due', 'cancelled'));
		$stage = 'details';
		if (self::paperworkDone($app)) {
			$stage = $paid ? 'complete' : 'payment';
		}
		$fields = array();
		if ($stage != $app->stage) {
			$fields['stage'] = $stage;
		}
		if ($stage == 'complete' && empty($app->completed_at)) {
			$fields['completed_at'] = $this->db->idate(self::now());
		}
		if ($fields) {
			$this->save($id, $fields);
			$app = $this->fetch($id);
		}
		$this->syncExtraFields($app);
		return $app;
	}

	/**
	 * Copy the applicant's state onto the contact and member cards.
	 *
	 * @param object $app Applicant row
	 * @return void
	 */
	public function syncExtraFields($app)
	{
		$values = array(
			'onb_discord' => (string) $app->discord,
			'onb_notify_events' => (int) $app->notify_events,
			'onb_notify_news' => (int) $app->notify_news,
			'onb_waiver_signed' => empty($app->waiver_signed_at) ? 0 : 1,
			'onb_agreement_signed' => empty($app->agreement_signed_at) ? 0 : 1,
			'onb_id_uploaded' => empty($app->id_uploaded_at) ? 0 : 1,
			'onb_payment_state' => (string) $app->payment_state,
		);
		$targets = array();
		if ($app->fk_socpeople > 0) {
			$targets[] = array(new Contact($this->db), (int) $app->fk_socpeople);
		}
		if ($app->fk_adherent > 0) {
			$targets[] = array(new Adherent($this->db), (int) $app->fk_adherent);
		}
		foreach ($targets as $t) {
			$obj = $t[0];
			if ($obj->fetch($t[1]) <= 0) {
				continue;
			}
			foreach ($values as $k => $v) {
				$obj->array_options['options_'.$k] = $v;
			}
			$obj->insertExtraFields();
		}
	}

	/**
	 * Send a plain text email. A failure is logged, never fatal.
	 *
	 * @param string $to Recipient
	 * @param string $subject Subject
	 * @param string $body Body
	 * @return bool
	 */
	public function mail($to, $subject, $body)
	{
		global $conf;
		if (empty($to)) {
			return false;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		$from = getDolGlobalString('ONBOARDING_MAIL_FROM', getDolGlobalString('MAIN_MAIL_EMAIL_FROM', 'noreply@localhost'));
		try {
			$mail = new CMailFile($subject, $to, $from, $body, array(), array(), array(), '', '', 0, 0, '', '', 'onboarding', '', 'standard');
			$ok = $mail->sendfile();
			if (!$ok) {
				dol_syslog('Onboarding: mail to '.$to.' failed: '.$mail->error, LOG_WARNING);
			}
			return (bool) $ok;
		} catch (Throwable $e) {
			dol_syslog('Onboarding: mail to '.$to.' failed: '.$e->getMessage(), LOG_WARNING);
			return false;
		}
	}

	/**
	 * Fill a template from the setup page.
	 *
	 * @param string $const Constant holding the template
	 * @param string $default Default text
	 * @param object $app Applicant row
	 * @param array<string,string> $extra Extra placeholders
	 * @return string
	 */
	public function template($const, $default, $app, $extra = array())
	{
		$text = getDolGlobalString($const, $default);
		$vars = array(
			'{firstname}' => (string) $app->firstname,
			'{lastname}' => (string) $app->lastname,
			'{payment_url}' => getDolGlobalString('ONBOARDING_CHECKOUT_URL'),
			'{org}' => getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'the makerspace'),
		);
		foreach ($extra as $k => $v) {
			$vars['{'.$k.'}'] = $v;
		}
		return strtr($text, $vars);
	}

	/**
	 * Issue a fresh token and email the applicant a link back into the signup.
	 *
	 * @param object $app Applicant row
	 * @param bool $send Send the email
	 * @return string The token
	 */
	public function issueToken($app, $send = true)
	{
		$token = bin2hex(random_bytes(24));
		$this->save($app->rowid, array('token_hash' => hash('sha256', $token)));
		$base = getDolGlobalString('ONBOARDING_JOIN_URL');
		if ($send && $base) {
			// The token rides in the fragment so it never reaches a server log.
			$link = $base.'#t='.$token;
			$body = $this->template('ONBOARDING_MAIL_RESUME_BODY', "Hi {firstname},\n\nThanks for starting your {org} membership. If you get interrupted, this link takes you back to where you left off:\n\n{link}\n\nIf you did not start a signup, you can ignore this email.", $app, array('link' => $link));
			$this->mail($app->email, $this->template('ONBOARDING_MAIL_RESUME_SUBJECT', 'Your {org} membership signup', $app), $body);
		}
		return $token;
	}

	// ---------------------------------------------------------------- signup steps

	/**
	 * Step 1. Create the applicant and their contact card.
	 *
	 * @param array<string,mixed> $in Form fields
	 * @return array<string,mixed> Response for the website
	 */
	public function start($in)
	{
		global $conf;

		$email = self::cleanEmail(isset($in['email']) ? $in['email'] : '');
		$first = trim(dol_string_nohtmltag((string) (isset($in['firstname']) ? $in['firstname'] : '')));
		$last = trim(dol_string_nohtmltag((string) (isset($in['lastname']) ? $in['lastname'] : '')));
		$discord = trim(dol_string_nohtmltag((string) (isset($in['discord']) ? $in['discord'] : '')));
		if (!isValidEmail($email) || $first === '' || $last === '') {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}

		// A known address never gets a token back in the browser: whoever typed it
		// may not own it. The link goes to the inbox instead.
		$existing = $this->findByEmail($email);
		if ($existing) {
			if ($existing->stage == 'complete') {
				$this->mail($existing->email, $this->template('ONBOARDING_MAIL_ALREADY_SUBJECT', 'You are already a {org} member', $existing), $this->template('ONBOARDING_MAIL_ALREADY_BODY', "Hi {firstname},\n\nSomeone started a membership signup with this email address, but you are already a member, so nothing was changed.", $existing));
			} else {
				$this->issueToken($existing, true);
			}
			return array('ok' => true, 'check_email' => true);
		}
		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."adherent WHERE email = '".$this->db->escape($email)."' AND entity IN (".getEntity('adherent').") LIMIT 1");
		if ($res && $this->db->fetch_object($res)) {
			return array('ok' => true, 'check_email' => true);
		}

		$actor = $this->actor();
		$contact = new Contact($this->db);
		$contact->firstname = $first;
		$contact->lastname = $last;
		$contact->email = $email;
		$contact->statut = 1;
		$contact->status = 1;
		$contact->note_private = 'Created by the website membership signup.';
		$cid = $contact->create($actor);
		if ($cid <= 0) {
			dol_syslog('Onboarding: contact create failed: '.$contact->error, LOG_ERR);
			return array('ok' => false, 'error' => 'server', 'http' => 500);
		}

		$sql = "INSERT INTO ".$this->db->prefix()."onboarding_applicant (entity, email, firstname, lastname, discord, notify_events, notify_news, fk_socpeople, ip, datec) VALUES (";
		$sql .= ((int) $conf->entity).", '".$this->db->escape($email)."', '".$this->db->escape($first)."', '".$this->db->escape($last)."', '".$this->db->escape(substr($discord, 0, 100))."', ";
		$sql .= (empty($in['notify_events']) ? 0 : 1).", ".(empty($in['notify_news']) ? 0 : 1).", ".((int) $cid).", '".$this->db->escape(substr((string) (isset($in['ip']) ? $in['ip'] : ''), 0, 64))."', '".$this->db->idate(self::now())."')";
		if (!$this->db->query($sql)) {
			dol_syslog('Onboarding: applicant insert failed: '.$this->db->lasterror(), LOG_ERR);
			return array('ok' => false, 'error' => 'server', 'http' => 500);
		}
		$app = $this->fetch($this->db->last_insert_id($this->db->prefix()."onboarding_applicant"));
		$token = $this->issueToken($app, true);
		$this->syncExtraFields($app);

		return array('ok' => true, 'token' => $token) + $this->status($this->fetch($app->rowid));
	}

	/**
	 * What the website needs to draw the right step.
	 *
	 * @param object $app Applicant row
	 * @return array<string,mixed>
	 */
	public function status($app)
	{
		$docs = $this->docs();
		return array(
			'stage' => $app->stage,
			'firstname' => $app->firstname,
			'email' => $app->email,
			'waiver' => !empty($app->waiver_signed_at) && $app->waiver_version == $docs['waiver']['version'],
			'agreement' => !empty($app->agreement_signed_at) && $app->agreement_version == $docs['agreement']['version'],
			'id' => !empty($app->id_uploaded_at),
			'paid' => $app->payment_state == 'active',
			'checkout' => array(
				'url' => getDolGlobalString('ONBOARDING_CHECKOUT_URL'),
				'standard' => (float) getDolGlobalString('ONBOARDING_DUES_STANDARD', '50'),
				'supporter' => (float) getDolGlobalString('ONBOARDING_DUES_SUPPORTER', '100'),
			),
		);
	}

	/**
	 * Step 2a/2b. Record a signature on the waiver or the agreement.
	 *
	 * @param object $app Applicant row
	 * @param string $doc 'waiver' or 'agreement'
	 * @param string $name Typed full name
	 * @param string $version Version the browser showed
	 * @param string $ip Signer's address
	 * @return array<string,mixed>
	 */
	public function sign($app, $doc, $name, $version, $ip)
	{
		$docs = $this->docs();
		$name = trim(dol_string_nohtmltag((string) $name));
		if (!isset($docs[$doc]) || strlen($name) < 3) {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		if ($version !== $docs[$doc]['version']) {
			// The text changed while the form was open. They must read the new one.
			return array('ok' => false, 'error' => 'stale', 'http' => 409);
		}
		$now = self::now();
		$this->save($app->rowid, array(
			$doc.'_signed_at' => $this->db->idate($now),
			$doc.'_name' => substr($name, 0, 200),
			$doc.'_version' => $version,
			$doc.'_ip' => substr((string) $ip, 0, 64),
		));

		$record = $docs[$doc]['title']."\n".str_repeat('=', 60)."\n\n".$docs[$doc]['text']."\n\n".str_repeat('-', 60)."\n";
		$record .= "Signed electronically by: ".$name."\n";
		$record .= "Account: ".$app->firstname.' '.$app->lastname.' <'.$app->email.">\n";
		$record .= "Date: ".dol_print_date($now, '%Y-%m-%d %H:%M:%S', 'gmt')." UTC\n";
		$record .= "IP address: ".$ip."\n";
		$record .= "Document version: ".$version."\n";
		$dir = $this->dir($app);
		file_put_contents($dir.'/'.$doc.'.txt', $record);
		$this->writePdf($dir.'/'.$doc.'.pdf', $docs[$doc]['title'], $record);

		$app = $this->refreshStage($app->rowid);
		return array('ok' => true) + $this->status($app);
	}

	/**
	 * Best effort PDF of a signed document. The .txt beside it is the fallback.
	 *
	 * @param string $path Target file
	 * @param string $title Title
	 * @param string $text Text
	 * @return void
	 */
	private function writePdf($path, $title, $text)
	{
		global $langs;
		try {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
			$pdf = pdf_getInstance();
			if (method_exists($pdf, 'setPrintHeader')) {
				$pdf->setPrintHeader(false);
				$pdf->setPrintFooter(false);
			}
			$pdf->SetTitle($title);
			$pdf->SetMargins(15, 15, 15);
			$pdf->SetAutoPageBreak(true, 15);
			$pdf->AddPage();
			$pdf->SetFont(pdf_getPDFFont($langs), '', 10);
			$pdf->MultiCell(0, 5, $text, 0, 'L');
			$pdf->Output($path, 'F');
		} catch (Throwable $e) {
			dol_syslog('Onboarding: PDF failed: '.$e->getMessage(), LOG_WARNING);
		}
	}

	/**
	 * Step 2c. Store the ID photo.
	 *
	 * @param object $app Applicant row
	 * @param string $bytes Raw image bytes
	 * @return array<string,mixed>
	 */
	public function uploadId($app, $bytes)
	{
		if (strlen($bytes) < 100 || strlen($bytes) > 8 * 1024 * 1024) {
			return array('ok' => false, 'error' => 'size', 'http' => 400);
		}
		$info = @getimagesizefromstring($bytes);
		$ext = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp');
		if (!$info || !isset($ext[$info[2]])) {
			return array('ok' => false, 'error' => 'type', 'http' => 400);
		}
		$dir = $this->dir($app);
		foreach (glob($dir.'/id.*') as $old) {
			@unlink($old);
		}
		$file = 'id.'.$ext[$info[2]];
		if (file_put_contents($dir.'/'.$file, $bytes) === false) {
			return array('ok' => false, 'error' => 'server', 'http' => 500);
		}
		$this->save($app->rowid, array('id_uploaded_at' => $this->db->idate(self::now()), 'id_file' => $file));
		$app = $this->refreshStage($app->rowid);
		return array('ok' => true) + $this->status($app);
	}

	// ---------------------------------------------------------------- Givebutter

	/**
	 * GET from the Givebutter API.
	 *
	 * @param string $url Full URL, or a path under the API base
	 * @return array|null Decoded JSON, null on failure
	 */
	public function gbGet($url)
	{
		$key = getDolGlobalString('ONBOARDING_GB_API_KEY');
		if (!$key) {
			return null;
		}
		if (strpos($url, 'http') !== 0) {
			$url = rtrim(getDolGlobalString('ONBOARDING_GB_API_BASE', 'https://api.givebutter.com/v1'), '/').$url;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
		$r = getURLContent($url, 'GET', '', 1, array('Authorization: Bearer '.$key, 'Accept: application/json'), array('http', 'https'), 2);
		if (empty($r['http_code']) || $r['http_code'] != 200) {
			dol_syslog('Onboarding: Givebutter GET '.$url.' returned '.(isset($r['http_code']) ? $r['http_code'] : '?').' '.(isset($r['curl_error_msg']) ? $r['curl_error_msg'] : ''), LOG_WARNING);
			return null;
		}
		$json = json_decode($r['content'], true);
		return is_array($json) ? $json : null;
	}

	/**
	 * Entry point for a Givebutter webhook.
	 *
	 * @param string $event Event name, e.g. transaction.succeeded
	 * @param array $data Event data
	 * @return string What was done, for the log
	 */
	public function handleEvent($event, $data)
	{
		if (!is_array($data)) {
			return 'ignored: no data';
		}
		if ($event == 'transaction.succeeded') {
			return $this->processTransaction($data);
		}
		if (strpos($event, 'plan.') === 0) {
			return $this->processPlan($data, $event);
		}
		return 'ignored: '.$event;
	}

	/**
	 * Find who a Givebutter record belongs to.
	 *
	 * @param string $contactId Givebutter contact id
	 * @param string $planId Givebutter plan id
	 * @param string $email Email typed at checkout
	 * @return object|null Applicant row
	 */
	public function match($contactId, $planId, $email)
	{
		$app = null;
		if ($planId !== '') {
			$app = $this->one("gb_plan_id = '".$this->db->escape($planId)."'");
		}
		if (!$app && $contactId !== '') {
			$app = $this->one("gb_contact_id = '".$this->db->escape($contactId)."'");
		}
		if (!$app) {
			$app = $this->findByEmail($email);
		}
		return $app;
	}

	/**
	 * Record a Givebutter transaction and, when it belongs to someone, apply it.
	 *
	 * @param array $t TransactionResource
	 * @return string
	 */
	public function processTransaction($t)
	{
		global $conf;

		$txid = isset($t['id']) ? (string) $t['id'] : '';
		if ($txid === '') {
			return 'ignored: no id';
		}
		if (isset($t['status']) && $t['status'] !== 'succeeded') {
			return 'ignored: status '.$t['status'];
		}
		$campaign = isset($t['campaign_code']) ? (string) $t['campaign_code'] : '';
		$want = getDolGlobalString('ONBOARDING_GB_CAMPAIGN_CODE');
		if ($want !== '' && strcasecmp($campaign, $want) != 0) {
			return 'ignored: campaign '.$campaign;
		}

		$planId = isset($t['plan_id']) ? (string) $t['plan_id'] : '';
		$contactId = isset($t['contact_id']) ? (string) $t['contact_id'] : '';
		$email = self::cleanEmail(isset($t['email']) ? $t['email'] : '');
		$amount = isset($t['amount']) ? (float) $t['amount'] : 0;
		$when = !empty($t['transacted_at']) ? strtotime((string) $t['transacted_at']) : 0;
		if (!$when) {
			$when = self::now();
		}

		$table = $this->db->prefix()."onboarding_payment";
		$res = $this->db->query("SELECT rowid, status FROM ".$table." WHERE gb_transaction_id = '".$this->db->escape($txid)."' AND entity = ".((int) $conf->entity));
		$row = $res ? $this->db->fetch_object($res) : null;
		if ($row && $row->status != 'unmatched') {
			return 'duplicate';
		}

		$app = $this->match($contactId, $planId, $email);
		if (!$row) {
			$sql = "INSERT INTO ".$table." (entity, gb_transaction_id, gb_plan_id, gb_contact_id, email, firstname, lastname, amount, campaign_code, transacted_at, status, datec) VALUES (";
			$sql .= ((int) $conf->entity).", '".$this->db->escape($txid)."', '".$this->db->escape($planId)."', '".$this->db->escape($contactId)."', '".$this->db->escape($email)."', ";
			$sql .= "'".$this->db->escape(substr((string) (isset($t['first_name']) ? $t['first_name'] : ''), 0, 100))."', '".$this->db->escape(substr((string) (isset($t['last_name']) ? $t['last_name'] : ''), 0, 100))."', ";
			$sql .= price2num($amount).", '".$this->db->escape($campaign)."', '".$this->db->idate($when)."', 'unmatched', '".$this->db->idate(self::now())."')";
			if (!$this->db->query($sql)) {
				// Lost a race with another delivery of the same event.
				return 'duplicate';
			}
			$rowid = $this->db->last_insert_id($table);
		} else {
			$rowid = $row->rowid;
		}
		if (!$app) {
			return 'unmatched';
		}
		return $this->applyPayment($app, $rowid);
	}

	/**
	 * Apply a recorded payment to an applicant: make them a member if they are
	 * not one yet, and record the dues period.
	 *
	 * @param object $app Applicant row
	 * @param int $paymentId Row in onboarding_payment
	 * @return string
	 */
	public function applyPayment($app, $paymentId)
	{
		global $user;

		$table = $this->db->prefix()."onboarding_payment";
		$res = $this->db->query("SELECT * FROM ".$table." WHERE rowid = ".((int) $paymentId));
		$pay = $res ? $this->db->fetch_object($res) : null;
		if (!$pay || $pay->status == 'matched') {
			return 'duplicate';
		}
		// Claim the row first so a second delivery cannot record the dues twice.
		$claim = $this->db->query("UPDATE ".$table." SET status = 'matched', fk_applicant = ".((int) $app->rowid)." WHERE rowid = ".((int) $paymentId)." AND status <> 'matched'");
		if (!$claim || $this->db->affected_rows($claim) < 1) {
			return 'duplicate';
		}

		$actor = $this->actor();
		$saved = $user;
		$user = $actor; // Adherent::subscription() reads the global.

		$amount = (float) $pay->amount;
		$when = $this->db->jdate($pay->transacted_at);
		$wasMember = $app->fk_adherent > 0;
		$supporter = $amount >= (float) getDolGlobalString('ONBOARDING_DUES_SUPPORTER', '100');
		$typeid = getDolGlobalInt($supporter ? 'ONBOARDING_TYPE_SUPPORTER' : 'ONBOARDING_TYPE_STANDARD');

		$adh = new Adherent($this->db);
		$ok = false;
		if ($wasMember) {
			$ok = $adh->fetch((int) $app->fk_adherent) > 0;
		}
		if (!$ok) {
			// Someone who was a member before this module existed.
			$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."adherent WHERE email = '".$this->db->escape($app->email)."' AND entity IN (".getEntity('adherent').") LIMIT 1");
			if ($res && ($obj = $this->db->fetch_object($res))) {
				$ok = $adh->fetch((int) $obj->rowid) > 0;
			}
		}
		if (!$ok) {
			$adh->firstname = $app->firstname;
			$adh->lastname = $app->lastname;
			$adh->email = $app->email;
			$adh->login = $app->email;
			$adh->morphy = 'phy';
			$adh->typeid = $typeid;
			$adh->public = 0;
			$adh->statut = Adherent::STATUS_DRAFT;
			$adh->status = Adherent::STATUS_DRAFT;
			if ($adh->create($actor) <= 0) {
				dol_syslog('Onboarding: member create failed: '.$adh->error, LOG_ERR);
				$this->db->query("UPDATE ".$table." SET status = 'unmatched' WHERE rowid = ".((int) $paymentId));
				$user = $saved;
				return 'error: member create failed: '.$adh->error;
			}
			$adh->fetch($adh->id);
		}
		if ($adh->statut != Adherent::STATUS_VALIDATED && $adh->statut != Adherent::STATUS_EXCLUDED) {
			$adh->validate($actor);
		}

		// Period paid for: one month unless the plan says otherwise.
		$months = 1;
		if ($pay->gb_plan_id) {
			$plan = $this->gbGet('/plans/'.rawurlencode($pay->gb_plan_id));
			if (isset($plan['data'])) {
				$plan = $plan['data'];
			}
			$freq = is_array($plan) && isset($plan['frequency']) ? strtolower((string) $plan['frequency']) : '';
			if (strpos($freq, 'year') !== false || $freq == 'annually') {
				$months = 12;
			} elseif (strpos($freq, 'quarter') !== false) {
				$months = 3;
			}
		}
		$end = dol_time_plus_duree(dol_time_plus_duree($when, $months, 'm'), -1, 'd');
		$subid = $adh->subscription($when, $amount, 0, '', 'Givebutter '.$pay->gb_transaction_id, '', '', '', $end);
		if ($subid <= 0) {
			dol_syslog('Onboarding: subscription failed: '.$adh->error, LOG_ERR);
		}
		$user = $saved;

		$fields = array(
			'fk_adherent' => $adh->id,
			'payment_state' => 'active',
			'problem_since' => null,
			'reminders_sent' => null,
		);
		if ($pay->gb_contact_id) {
			$fields['gb_contact_id'] = $pay->gb_contact_id;
		}
		if ($pay->gb_plan_id) {
			$fields['gb_plan_id'] = $pay->gb_plan_id;
		}
		$this->save($app->rowid, $fields);
		$app = $this->refreshStage($app->rowid);

		if (!$wasMember) {
			$missing = self::paperworkDone($app) ? '' : "\n\nNote: their waiver, agreement or ID photo is still missing.";
			$this->mail(getDolGlobalString('ONBOARDING_STAFF_EMAIL'), 'New paying member: '.$app->firstname.' '.$app->lastname, $app->firstname.' '.$app->lastname.' <'.$app->email.'> has set up dues ('.price($amount).') and needs a badge.'.$missing);
			$this->mail($app->email, $this->template('ONBOARDING_MAIL_WELCOME_SUBJECT', 'Welcome to {org}', $app), $this->template('ONBOARDING_MAIL_WELCOME_BODY', "Hi {firstname},\n\nYour dues payment came through and your membership is active. Someone from the membership team will be in touch about your badge.", $app));
		}
		return 'applied to applicant '.$app->rowid;
	}

	/**
	 * A recurring plan changed.
	 *
	 * @param array $p RecurringPlanResource
	 * @param string $event Webhook event name, or '' when called from the sync
	 * @return string
	 */
	public function processPlan($p, $event = '')
	{
		$planId = isset($p['id']) ? (string) $p['id'] : '';
		if ($planId === '') {
			return 'ignored: no id';
		}
		// Only plans that have already paid dues are tracked. A plan we have never
		// seen pay may be a recurring donation, which must not affect membership.
		$app = $this->one("gb_plan_id = '".$this->db->escape($planId)."'");
		if (!$app) {
			return 'ignored: unknown plan';
		}
		$status = strtolower(isset($p['status']) ? (string) $p['status'] : '');
		$state = '';
		if ($event == 'plan.canceled' || !empty($p['canceled_at']) || strpos($status, 'cancel') === 0) {
			$state = 'cancelled';
		} elseif ($event == 'plan.failed' || $event == 'plan.paused' || in_array($status, array('failed', 'paused', 'past_due', 'ended'))) {
			$state = 'past_due';
		} elseif ($event == 'plan.resumed' || $status == 'active') {
			$state = 'active';
		}
		if ($state == '' || $app->payment_state == 'lapsed') {
			return 'ignored: '.$status;
		}
		if ($state == 'active') {
			if ($app->payment_state != 'active') {
				$this->save($app->rowid, array('payment_state' => 'active', 'problem_since' => null, 'reminders_sent' => null));
				$this->refreshStage($app->rowid);
			}
			return 'plan active';
		}
		$this->setProblem($app, $state);
		return 'plan '.$state;
	}

	/**
	 * Start (or keep) the reminder clock for a member.
	 *
	 * @param object $app Applicant row
	 * @param string $state 'cancelled' or 'past_due'
	 * @param int $since When the problem began, default now
	 * @return void
	 */
	public function setProblem($app, $state, $since = 0)
	{
		$fields = array('payment_state' => $state);
		if (empty($app->problem_since)) {
			$fields['problem_since'] = $this->db->idate($since ? $since : self::now());
			$fields['reminders_sent'] = null;
		}
		$this->save($app->rowid, $fields);
		$this->refreshStage($app->rowid);
	}

	/**
	 * Pull recent transactions and plan statuses from Givebutter.
	 *
	 * @return string Summary
	 */
	public function reconcile()
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		if (!getDolGlobalString('ONBOARDING_GB_API_KEY')) {
			return 'no Givebutter API key set';
		}
		$started = self::now();
		$last = getDolGlobalInt('ONBOARDING_GB_LAST_SYNC');
		// Overlap a day; transactions are applied once however often they are seen.
		$after = $last > 0 ? $last - 86400 : $started - 35 * 86400;
		$url = '/transactions?updatedAfter='.rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $after));
		$seen = 0;
		$applied = 0;
		for ($page = 0; $page < 25 && $url; $page++) {
			$json = $this->gbGet($url);
			if ($json === null) {
				return 'Givebutter transactions request failed';
			}
			foreach ((isset($json['data']) && is_array($json['data']) ? $json['data'] : array()) as $t) {
				$seen++;
				if (strpos($this->processTransaction($t), 'applied') === 0) {
					$applied++;
				}
			}
			$url = !empty($json['links']['next']) ? $json['links']['next'] : '';
		}

		$plans = 0;
		$res = $this->db->query("SELECT rowid, gb_plan_id FROM ".$this->db->prefix()."onboarding_applicant WHERE entity = ".((int) $conf->entity)." AND gb_plan_id IS NOT NULL AND gb_plan_id <> '' AND payment_state IN ('active', 'past_due', 'cancelled')");
		$rows = array();
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$rows[] = $obj;
		}
		foreach ($rows as $obj) {
			$json = $this->gbGet('/plans/'.rawurlencode($obj->gb_plan_id));
			if ($json === null) {
				continue;
			}
			$this->processPlan(isset($json['data']) ? $json['data'] : $json);
			$plans++;
		}

		dolibarr_set_const($this->db, 'ONBOARDING_GB_LAST_SYNC', $started, 'chaine', 0, '', $conf->entity);
		return $seen.' transactions seen, '.$applied.' applied, '.$plans.' plans checked';
	}

	// ---------------------------------------------------------------- reminders

	/**
	 * Send due reminders and lapse members past the grace period.
	 *
	 * @return string Summary
	 */
	public function dunning()
	{
		global $conf;

		$now = self::now();
		$offsets = array();
		foreach (explode(',', getDolGlobalString('ONBOARDING_REMINDER_DAYS', '3,7,14')) as $o) {
			if (is_numeric(trim($o)) && (int) $o >= 0) {
				$offsets[] = (int) $o;
			}
		}
		sort($offsets);
		$grace = max(1, (int) getDolGlobalString('ONBOARDING_GRACE_DAYS', '30'));
		$sent = 0;
		$lapsed = 0;

		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."onboarding_applicant WHERE entity = ".((int) $conf->entity)." AND fk_adherent > 0 AND payment_state IN ('active', 'past_due', 'cancelled')");
		$ids = array();
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$ids[] = (int) $obj->rowid;
		}
		foreach ($ids as $id) {
			$app = $this->fetch($id);
			$adh = new Adherent($this->db);
			if ($adh->fetch((int) $app->fk_adherent) <= 0) {
				continue;
			}
			$waived = isset($adh->array_options['options_onb_dues_waived_until']) ? $adh->array_options['options_onb_dues_waived_until'] : '';
			if ($waived !== '' && $waived !== null) {
				$waivedTs = is_numeric($waived) ? (int) $waived : strtotime((string) $waived);
				if ($waivedTs && $waivedTs >= $now) {
					continue; // Scholarship or board-approved pause.
				}
			}
			$paidUntil = (int) $adh->datefin;

			// Dues that simply ran out, with no cancellation ever reported.
			if ($app->payment_state == 'active') {
				if ($paidUntil > 0 && $paidUntil < $now - 2 * 86400) {
					$this->setProblem($app, 'past_due', $paidUntil);
					$app = $this->fetch($id);
				} else {
					continue;
				}
			}
			if (empty($app->problem_since)) {
				continue;
			}
			$days = (int) floor(($now - $this->db->jdate($app->problem_since)) / 86400);

			if ($days >= $grace && $paidUntil < $now) {
				$this->lapse($app, $adh);
				$lapsed++;
				continue;
			}

			$done = array_filter(explode(',', (string) $app->reminders_sent), 'strlen');
			$due = array();
			foreach ($offsets as $o) {
				if ($o <= $days && $o < $grace && !in_array((string) $o, $done)) {
					$due[] = $o;
				}
			}
			if ($due) {
				$left = max(0, $grace - $days);
				$body = $this->template('ONBOARDING_MAIL_REMINDER_BODY', "Hi {firstname},\n\nYour {org} dues payment did not go through or was cancelled. To keep your membership and badge access, please set up dues again here:\n\n{payment_url}\n\nUse the same email address you joined with. Your membership will end in {days_left} days if we do not hear from you. If dues are a hardship, just reply to this email and we will work something out.", $app, array('days_left' => (string) $left));
				$this->mail($app->email, $this->template('ONBOARDING_MAIL_REMINDER_SUBJECT', 'Your {org} dues need attention', $app), $body);
				// One email even if several offsets came due at once.
				$this->save($id, array('reminders_sent' => implode(',', array_merge($done, $due))));
				$sent++;
			}
		}
		return $sent.' reminders sent, '.$lapsed.' members marked non-paying';
	}

	/**
	 * Mark a member non-paying.
	 *
	 * @param object $app Applicant row
	 * @param Adherent $adh Member
	 * @return void
	 */
	public function lapse($app, $adh)
	{
		$actor = $this->actor();
		if ($adh->statut == Adherent::STATUS_VALIDATED) {
			$adh->resiliate($actor);
		}
		$this->save($app->rowid, array('payment_state' => 'lapsed', 'problem_since' => null));
		$app = $this->refreshStage($app->rowid);
		$badge = isset($adh->array_options['options_onb_badge_id']) ? (string) $adh->array_options['options_onb_badge_id'] : '';
		$access = !empty($adh->array_options['options_onb_badge_access']);
		$this->mail($app->email, $this->template('ONBOARDING_MAIL_LAPSED_SUBJECT', 'Your {org} membership has ended', $app), $this->template('ONBOARDING_MAIL_LAPSED_BODY', "Hi {firstname},\n\nWe did not receive your dues, so your {org} membership has ended and your badge access will be turned off. You are welcome back any time:\n\n{payment_url}", $app));
		$this->mail(getDolGlobalString('ONBOARDING_STAFF_EMAIL'), 'Membership ended: '.$app->firstname.' '.$app->lastname, $app->firstname.' '.$app->lastname.' <'.$app->email.'> is now non-paying.'.($badge !== '' ? ' Badge '.$badge.($access ? ' still has access and should be turned off.' : ' is already marked as having no access.') : ' No badge is recorded for them.'));
	}

	/**
	 * Delete abandoned signups and expired ID photos.
	 *
	 * @return string Summary
	 */
	public function cleanup()
	{
		global $conf;

		$now = self::now();
		$deleted = 0;
		$photos = 0;
		$actor = $this->actor();

		$days = (int) getDolGlobalString('ONBOARDING_DRAFT_DAYS', '30');
		if ($days > 0) {
			$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."onboarding_applicant WHERE entity = ".((int) $conf->entity)." AND stage <> 'complete' AND payment_state = 'none' AND (fk_adherent IS NULL OR fk_adherent = 0) AND datec < '".$this->db->idate($now - $days * 86400)."'");
			$ids = array();
			while ($res && ($obj = $this->db->fetch_object($res))) {
				$ids[] = (int) $obj->rowid;
			}
			foreach ($ids as $id) {
				$app = $this->fetch($id);
				if ($app->fk_socpeople > 0) {
					$contact = new Contact($this->db);
					if ($contact->fetch((int) $app->fk_socpeople) > 0) {
						$contact->delete($actor);
					}
				}
				dol_delete_dir_recursive(DOL_DATA_ROOT.'/onboarding/applicant/'.$id);
				$this->db->query("DELETE FROM ".$this->db->prefix()."onboarding_applicant WHERE rowid = ".$id);
				$deleted++;
			}
		}

		$keep = (int) getDolGlobalString('ONBOARDING_ID_RETENTION_DAYS', '0');
		if ($keep > 0) {
			$res = $this->db->query("SELECT rowid, id_file FROM ".$this->db->prefix()."onboarding_applicant WHERE entity = ".((int) $conf->entity)." AND id_file IS NOT NULL AND id_file <> '' AND completed_at IS NOT NULL AND completed_at < '".$this->db->idate($now - $keep * 86400)."'");
			$rows = array();
			while ($res && ($obj = $this->db->fetch_object($res))) {
				$rows[] = $obj;
			}
			foreach ($rows as $obj) {
				@unlink(DOL_DATA_ROOT.'/onboarding/applicant/'.((int) $obj->rowid).'/'.basename($obj->id_file));
				// The "ID photo uploaded" tick stays: it records that the check was done.
				$this->save($obj->rowid, array('id_file' => null));
				$photos++;
			}
		}
		return $deleted.' abandoned signups deleted, '.$photos.' ID photos removed';
	}
}
