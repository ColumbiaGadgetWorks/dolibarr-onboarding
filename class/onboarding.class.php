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
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';

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

	/**
	 * Extra fields on the member card. The same schema is defined in
	 * CGWManagement/dolibarr/scripts/bootstrap.py (MEMBER_EXTRAFIELDS); keep the
	 * two identical. Fields the applicant table mirrors are also put on the
	 * contact card (see CONTACT_FIELDS). Format: label, type, size, position, unique.
	 */
	const MEMBER_FIELDS = array(
		'member_code' => array('Member code (badge label, e.g. CGW-13)', 'varchar', 16, 110, 1),
		'credential_id' => array('Credential ID (fob serial, keypad slot, old key)', 'varchar', 64, 120, 0),
		'access_enabled' => array('Door access enabled', 'boolean', '', 130, 0),
		'waiver_date' => array('Waiver signed', 'date', '', 140, 0),
		'agreement_date' => array('Membership agreement signed', 'date', '', 145, 0),
		'id_verified' => array('Photo ID on file', 'boolean', '', 150, 0),
		'discord_handle' => array('Discord handle', 'varchar', 64, 170, 0),
		'payment_channel' => array('Recurring payment channel', 'select', '', 190, 0),
		'payment_state' => array('Dues status', 'select', '', 200, 0),
		'dues_waived_until' => array('Dues waived until', 'date', '', 210, 0),
		'notify_events' => array('Notify: events', 'boolean', '', 220, 0),
		'notify_news' => array('Notify: news and updates', 'boolean', '', 230, 0),
	);

	/** Fields mirrored from the applicant row onto both the contact and the member card. */
	const CONTACT_FIELDS = array('discord_handle', 'waiver_date', 'agreement_date', 'id_verified', 'payment_state', 'notify_events', 'notify_news');

	/** Field names used before the schema merge (October 2026), old => new. */
	const RENAMED = array(
		'onb_discord' => 'discord_handle',
		'onb_notify_events' => 'notify_events',
		'onb_notify_news' => 'notify_news',
		'onb_waiver_signed' => 'waiver_date',
		'onb_agreement_signed' => 'agreement_date',
		'onb_id_uploaded' => 'id_verified',
		'onb_payment_state' => 'payment_state',
		'onb_badge_id' => 'member_code',
		'onb_badge_access' => 'access_enabled',
		'onb_dues_waived_until' => 'dues_waived_until',
		'onb_payment_channel' => 'payment_channel',
	);

	const PAYMENT_STATES = array(
		'none' => 'No payment yet',
		'active' => 'Paying',
		'past_due' => 'Payment missed',
		'cancelled' => 'Payment cancelled',
		'lapsed' => 'Non-paying',
	);

	/** Only Givebutter dues are tracked automatically. The others are kept up by hand. */
	const PAYMENT_CHANNELS = array(
		'Givebutter' => 'Givebutter',
		'PayPal' => 'PayPal',
		'Check' => 'Check',
		'Cash' => 'Cash',
		'Venmo' => 'Venmo',
		'None' => 'None (scholarship or waived)',
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
		foreach (array('socpeople', 'adherent') as $element) {
			$extrafields->fetch_name_optionals_label($element, true);
			$have = isset($extrafields->attributes[$element]['label']) ? $extrafields->attributes[$element]['label'] : array();
			$fields = self::MEMBER_FIELDS;
			if ($element == 'socpeople') {
				$fields = array_intersect_key($fields, array_flip(self::CONTACT_FIELDS));
			}
			foreach ($fields as $name => $def) {
				$param = '';
				if ($def[1] == 'select') {
					$param = array('options' => $name == 'payment_channel' ? self::PAYMENT_CHANNELS : self::PAYMENT_STATES);
				}
				if (isset($have[$name])) {
					// Keep the choice lists current (the channel list grew in October 2026).
					$current = isset($extrafields->attributes[$element]['param'][$name]['options']) ? $extrafields->attributes[$element]['param'][$name]['options'] : null;
					if ($param !== '' && $current !== $param['options']) {
						$extrafields->update($name, $def[0], $def[1], $def[2], $element, $def[4], 0, $def[3], $param, 1, '', '1');
					}
					continue;
				}
				$extrafields->addExtraField($name, $def[0], $def[1], $def[3], $def[2], $element, $def[4], 0, '', $param, 1, '', '1');
			}
			self::migrateExtraFields($db, $extrafields, $element, $have);
		}
	}

	/**
	 * Move data out of the onb_* fields an earlier version created, then drop them.
	 * Runs once per install; a second run finds nothing to do.
	 *
	 * @param DoliDB $db Database handler
	 * @param ExtraFields $extrafields Loaded for $element
	 * @param string $element 'socpeople' or 'adherent'
	 * @param array $have Field labels present before this install
	 * @return void
	 */
	private static function migrateExtraFields($db, $extrafields, $element, $have)
	{
		$table = $db->prefix().$element."_extrafields";
		$old = array_intersect_key(self::RENAMED, $have);
		if (!$old) {
			return;
		}
		foreach ($old as $from => $to) {
			switch ($from) {
				case 'onb_waiver_signed':
				case 'onb_agreement_signed':
					// Yes/no became a date; the applicant table has the date (resynced below).
					break;
				case 'onb_payment_channel':
					$case = "CASE ".$from;
					foreach (self::PAYMENT_CHANNELS as $key => $label) {
						$case .= " WHEN '".$db->escape(strtolower($key))."' THEN '".$db->escape($key)."'";
					}
					$case .= " ELSE NULL END";
					$db->query("UPDATE ".$table." SET ".$to." = ".$case." WHERE ".$to." IS NULL AND ".$from." IS NOT NULL");
					break;
				case 'onb_badge_id':
					// member_code is unique: copy one at a time, skip a code already taken.
					$res = $db->query("SELECT fk_object, ".$from." AS v FROM ".$table." WHERE ".$to." IS NULL AND ".$from." IS NOT NULL AND ".$from." <> '' ORDER BY fk_object");
					while ($res && ($obj = $db->fetch_object($res))) {
						$taken = $db->query("SELECT fk_object FROM ".$table." WHERE ".$to." = '".$db->escape($obj->v)."'");
						if ($taken && $db->num_rows($taken) > 0) {
							dol_syslog('Onboarding: badge '.$obj->v.' of member '.$obj->fk_object.' already used as member_code; not copied', LOG_WARNING);
							continue;
						}
						$db->query("UPDATE ".$table." SET ".$to." = '".$db->escape($obj->v)."' WHERE fk_object = ".((int) $obj->fk_object));
					}
					break;
				default:
					$db->query("UPDATE ".$table." SET ".$to." = ".$from." WHERE ".$to." IS NULL AND ".$from." IS NOT NULL");
			}
			$extrafields->delete($from, $element);
		}
		if ($element == 'adherent') {
			$svc = new self($db);
			$res = $db->query("SELECT rowid FROM ".$db->prefix()."onboarding_applicant");
			while ($res && ($obj = $db->fetch_object($res))) {
				$svc->syncExtraFields($svc->fetch((int) $obj->rowid));
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
			'waiver' => array('Liability waiver', "I understand that Columbia Gadget Works is a shared workshop with tools and machines that can cause serious injury. I choose to use the space and its equipment at my own risk.\n\nI will not use a tool I have not been trained on, I will follow posted safety rules, and I will wear appropriate protective equipment.\n\nTo the extent the law allows, I release Columbia Gadget Works, its volunteers, board and members from liability for injury or property damage arising from my use of the space."),
			'agreement' => array('Membership agreement', "As a member of Columbia Gadget Works I agree to:\n\n- Follow the shop rules and the code of conduct.\n- Clean up after myself and leave tools and benches ready for the next person.\n- Report damage or unsafe conditions right away.\n- Be responsible for any guests I bring.\n- Keep my dues current. I can cancel at any time.\n\nI understand that membership and badge access can be suspended for unsafe behavior or unpaid dues."),
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
		// Ready to pay: from here they are a non-member (a draft member in Dolibarr)
		// rather than only a contact.
		if ($stage == 'payment' && !($app->fk_adherent > 0)) {
			$this->ensureMember($app);
			$app = $this->fetch($id);
		}
		$this->syncExtraFields($app);
		return $app;
	}

	/**
	 * Find or create a member type by its label.
	 *
	 * @param string $label Label, e.g. Legacy
	 * @return int Type id, 0 on failure
	 */
	public function memberType($label)
	{
		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."adherent_type WHERE libelle = '".$this->db->escape($label)."' AND entity IN (".getEntity('member_type').") LIMIT 1");
		if ($res && ($obj = $this->db->fetch_object($res))) {
			return (int) $obj->rowid;
		}
		$type = new AdherentType($this->db);
		$type->label = $label;
		$type->morphy = 'phy';
		$type->status = 1;
		$type->subscription = 1;
		$type->vote = 1;
		$id = $type->create($this->actor());
		return $id > 0 ? (int) $id : 0;
	}

	/**
	 * The member record for an applicant. Created as a draft, which is what
	 * Dolibarr shows for a non-member, when there is none yet.
	 *
	 * @param object $app Applicant row
	 * @param int $typeid Member type, default Standard
	 * @param int $datec Creation date, default now
	 * @param string $note Private note
	 * @return Adherent|null
	 */
	public function ensureMember($app, $typeid = 0, $datec = 0, $note = '')
	{
		$actor = $this->actor();
		$adh = new Adherent($this->db);
		if ($app->fk_adherent > 0 && $adh->fetch((int) $app->fk_adherent) > 0) {
			return $adh;
		}
		$adh = new Adherent($this->db);
		$found = false;
		// Someone who was already a member before this module existed.
		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."adherent WHERE email = '".$this->db->escape($app->email)."' AND entity IN (".getEntity('adherent').") LIMIT 1");
		if ($res && ($obj = $this->db->fetch_object($res))) {
			$found = $adh->fetch((int) $obj->rowid) > 0;
		}
		if (!$found) {
			$adh->firstname = $app->firstname;
			$adh->lastname = $app->lastname;
			$adh->email = $app->email;
			$adh->login = $app->email;
			$adh->morphy = 'phy';
			$adh->typeid = $typeid > 0 ? $typeid : getDolGlobalInt('ONBOARDING_TYPE_STANDARD');
			$adh->public = 0;
			$adh->statut = Adherent::STATUS_DRAFT;
			$adh->status = Adherent::STATUS_DRAFT;
			if ($datec) {
				$adh->datec = $datec;
			}
			if ($note !== '') {
				$adh->note_private = $note;
			}
			if ($adh->create($actor) <= 0) {
				dol_syslog('Onboarding: member create failed: '.$adh->error, LOG_ERR);
				return null;
			}
			$adh->fetch($adh->id);
		}
		$this->save($app->rowid, array('fk_adherent' => $adh->id));
		return $adh;
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
			'discord_handle' => substr((string) $app->discord, 0, 64),
			'notify_events' => (int) $app->notify_events,
			'notify_news' => (int) $app->notify_news,
			'waiver_date' => empty($app->waiver_signed_at) ? '' : $this->db->jdate($app->waiver_signed_at),
			'agreement_date' => empty($app->agreement_signed_at) ? '' : $this->db->jdate($app->agreement_signed_at),
			'id_verified' => empty($app->id_uploaded_at) ? 0 : 1,
			'payment_state' => (string) $app->payment_state,
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
				// A date or handle entered by hand on the card (paper waiver, legacy
				// member) is kept until the applicant row has its own value.
				if (($v === '' || $v === 0) && in_array($k, array('discord_handle', 'waiver_date', 'agreement_date', 'id_verified')) && !empty($obj->array_options['options_'.$k])) {
					continue;
				}
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
		// Either box ticked puts them on the email updates list too. The two
		// notify fields keep exactly what was ticked.
		if (!empty($in['notify_events']) || !empty($in['notify_news'])) {
			$tagged = new Contact($this->db);
			if ($tagged->fetch((int) $cid) > 0) {
				$this->tagForUpdates($tagged, false);
			}
		}

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
			'lastname' => $app->lastname,
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
	 * @param string $signature The drawn signature, PNG bytes
	 * @return array<string,mixed>
	 */
	public function sign($app, $doc, $name, $version, $ip, $signature = '')
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
		$info = strlen($signature) > 200 && strlen($signature) < 1024 * 1024 ? @getimagesizefromstring($signature) : false;
		if (!$info || $info[2] != IMAGETYPE_PNG || $info[0] > 2400 || $info[1] > 1200) {
			return array('ok' => false, 'error' => 'signature', 'http' => 400);
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
		file_put_contents($dir.'/'.$doc.'-signature.png', $signature);
		$this->writePdf($dir.'/'.$doc.'.pdf', $docs[$doc]['title'], $record, $dir.'/'.$doc.'-signature.png');

		$app = $this->refreshStage($app->rowid);
		return array('ok' => true) + $this->status($app);
	}

	/**
	 * Best effort PDF of a signed document. The .txt beside it is the fallback.
	 *
	 * @param string $path Target file
	 * @param string $title Title
	 * @param string $text Text
	 * @param string $image Drawn signature to place under the text
	 * @return void
	 */
	private function writePdf($path, $title, $text, $image = '')
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
			if ($image && is_readable($image)) {
				if ($pdf->GetY() > 240) {
					$pdf->AddPage();
				}
				$pdf->Image($image, 15, $pdf->GetY() + 3, 80);
			}
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
		// Training fees have their own campaign and their own bookkeeping.
		dol_include_once('/onboarding/class/training.class.php');
		if (OnboardingTraining::isTrainingCampaign($campaign)) {
			$training = new OnboardingTraining($this->db, $this);
			return $training->process($t);
		}
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
		$first = $app->payment_state == 'none';
		$supporter = $amount >= (float) getDolGlobalString('ONBOARDING_DUES_SUPPORTER', '100');
		$typeid = getDolGlobalInt($supporter ? 'ONBOARDING_TYPE_SUPPORTER' : 'ONBOARDING_TYPE_STANDARD');

		$adh = $this->ensureMember($app, $typeid);
		if (!$adh) {
			$this->db->query("UPDATE ".$table." SET status = 'unmatched' WHERE rowid = ".((int) $paymentId));
			$user = $saved;
			return 'error: member create failed';
		}
		// A Standard member who starts paying the Supporter rate, or the reverse.
		$managed = array(getDolGlobalInt('ONBOARDING_TYPE_STANDARD'), getDolGlobalInt('ONBOARDING_TYPE_SUPPORTER'));
		if ($typeid > 0 && $adh->typeid != $typeid && in_array((int) $adh->typeid, $managed)) {
			$this->db->query("UPDATE ".$this->db->prefix()."adherent SET fk_adherent_type = ".((int) $typeid)." WHERE rowid = ".((int) $adh->id));
		}
		$adh->array_options['options_payment_channel'] = 'Givebutter';
		$adh->insertExtraFields();
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

		if ($first) {
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

	/**
	 * Create the webhook in Givebutter so it reports payments straight to this
	 * Dolibarr. Replaces one created earlier.
	 *
	 * @param string $target Override of the address Givebutter should call
	 * @return string What happened
	 */
	public function connectGivebutter($target = '')
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

		$key = getDolGlobalString('ONBOARDING_GB_API_KEY');
		if (!$key) {
			return 'Set the Givebutter API key first.';
		}
		if ($target === '') {
			$target = $this->webhookUrl();
		}
		$base = rtrim(getDolGlobalString('ONBOARDING_GB_API_BASE', 'https://api.givebutter.com/v1'), '/');
		$headers = array('Authorization: Bearer '.$key, 'Accept: application/json', 'Content-Type: application/json');
		$old = getDolGlobalString('ONBOARDING_GB_WEBHOOK_ID');
		if ($old !== '') {
			getURLContent($base.'/webhooks/'.rawurlencode($old), 'DELETE', '', 1, $headers, array('http', 'https'), 2);
		}
		$body = json_encode(array(
			'name' => 'Dolibarr member onboarding',
			'url' => $target,
			'events' => array('transaction.succeeded', 'plan.canceled', 'plan.failed', 'plan.paused', 'plan.resumed'),
			'enabled' => true,
		));
		$r = getURLContent($base.'/webhooks', 'POSTALREADYFORMATED', $body, 1, $headers, array('http', 'https'), 2);
		$json = isset($r['content']) ? json_decode($r['content'], true) : null;
		if (isset($json['data']) && is_array($json['data'])) {
			$json = $json['data'];
		}
		if (empty($r['http_code']) || $r['http_code'] >= 300 || empty($json['id'])) {
			return 'Givebutter refused: HTTP '.(isset($r['http_code']) ? $r['http_code'] : '?').' '.dol_trunc(isset($r['content']) ? (string) $r['content'] : '', 200);
		}
		dolibarr_set_const($this->db, 'ONBOARDING_GB_WEBHOOK_ID', (string) $json['id'], 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'ONBOARDING_GB_WEBHOOK_SECRET', isset($json['signature']) ? (string) $json['signature'] : '', 'chaine', 0, '', $conf->entity);
		return 'Connected. Givebutter will report payments to '.$target;
	}

	/**
	 * @return string Address Givebutter calls
	 */
	public function webhookUrl()
	{
		$public = rtrim(getDolGlobalString('ONBOARDING_PUBLIC_URL'), '/');
		if ($public !== '') {
			return $public.'/custom/onboarding/public/givebutter.php';
		}
		return dol_buildpath('/onboarding/public/givebutter.php', 2);
	}

	// ---------------------------------------------------------------- legacy import

	/**
	 * Import the old member spreadsheet. Tab or comma separated, first line is
	 * the header. Columns used: name, email, access_code, discord, license,
	 * member_type, payment_channel, join_date, open_item, comment.
	 * People already known by email are skipped, so it is safe to run twice.
	 *
	 * @param string $text Pasted spreadsheet
	 * @param bool $dry Only report what would happen
	 * @return string[] One line per row
	 */
	public function importLegacy($text, $dry = true)
	{
		global $conf;

		$report = array();
		$lines = preg_split('/\\r\\n|\\r|\\n/', trim((string) $text));
		if (count($lines) < 2) {
			return array('Nothing to import: paste the header line and at least one row.');
		}
		$sep = strpos($lines[0], "\t") !== false ? "\t" : ',';
		$head = array_map(function ($h) {
			return strtolower(trim($h));
		}, str_getcsv(array_shift($lines), $sep, '"', '\\'));
		if (!in_array('email', $head) || !in_array('name', $head)) {
			return array('The header line needs at least "name" and "email" columns.');
		}
		$actor = $this->actor();
		$seenBadges = array();

		foreach ($lines as $n => $line) {
			if (trim($line) === '') {
				continue;
			}
			$cells = str_getcsv($line, $sep, '"', '\\');
			$row = array();
			foreach ($head as $i => $h) {
				$v = isset($cells[$i]) ? trim($cells[$i]) : '';
				$row[$h] = strtolower($v) == 'null' ? '' : $v;
			}
			$get = function ($k) use ($row) {
				return isset($row[$k]) ? $row[$k] : '';
			};
			$name = trim(dol_string_nohtmltag($get('name')));
			$email = self::cleanEmail($get('email'));
			$label = 'Row '.($n + 2).' ('.$name.')';
			if (!isValidEmail($email) || $name === '') {
				$report[] = $label.': SKIPPED, needs a name and a valid email.';
				continue;
			}
			if ($this->findByEmail($email)) {
				$report[] = $label.': already here, skipped.';
				continue;
			}
			$pos = strrpos($name, ' ');
			$first = $pos === false ? $name : substr($name, 0, $pos);
			$last = $pos === false ? '-' : substr($name, $pos + 1);

			$type = ucfirst(strtolower($get('member_type')));
			$isMember = $type !== '' && $type != 'Onboarding';
			$chanRaw = strtolower($get('payment_channel'));
			$channel = 'None';
			foreach (array_keys(self::PAYMENT_CHANNELS) as $c) {
				if (strpos($chanRaw, strtolower($c)) !== false) {
					$channel = $c;
				}
			}
			$joined = 0;
			if (preg_match('/^(\\d{4})-(\\d{1,2})(?:-(\\d{1,2}))?/', $get('join_date'), $m)) {
				$joined = dol_mktime(12, 0, 0, (int) $m[2], isset($m[3]) ? (int) $m[3] : 1, (int) $m[1]);
			}
			// "CGW-13" is a badge. A bare number is one of the old physical keys, and
			// "has card from Dan" in the comment is a credential too; neither is a code.
			$badge = strtoupper($get('access_code'));
			$credential = '';
			if (ctype_digit($badge)) {
				$credential = 'old key '.$badge;
				$badge = '';
			} elseif ($badge !== '' && !preg_match('/^CGW-\\d+$/', $badge)) {
				$credential = $get('access_code');
				$badge = '';
			}
			if ($credential === '' && preg_match('/\\b(key|card|fob)\\b/i', $get('comment'))) {
				$credential = $get('comment');
			}
			$hasId = in_array(strtolower($get('license')), array('yes', 'y', '1', 'true'));
			$notes = array();
			foreach (array('payment_channel' => 'Pays by', 'open_item' => 'Open item', 'comment' => 'Comment') as $k => $t) {
				if ($get($k) !== '') {
					$notes[] = $t.': '.$get($k);
				}
			}
			$warn = '';
			if ($badge !== '') {
				if (isset($seenBadges[$badge])) {
					$warn = ' WARNING: badge '.$badge.' is also on '.$seenBadges[$badge].'; not written here.';
					$badge = '';
				} else {
					$seenBadges[$badge] = $name;
				}
			}
			$what = ($isMember ? 'member ('.$type.', pays by '.self::PAYMENT_CHANNELS[$channel].')' : 'non-member, signup in progress').($badge !== '' ? ', badge '.$badge : ($credential !== '' ? ', '.$credential : ', no badge')).($hasId ? ', ID on file' : '');
			if ($dry) {
				$report[] = $label.': would add as '.$what.'.'.$warn;
				continue;
			}

			$contact = new Contact($this->db);
			$contact->firstname = $first;
			$contact->lastname = $last;
			$contact->email = $email;
			$contact->statut = 1;
			$contact->status = 1;
			$contact->note_private = 'Imported from the legacy member list.';
			$cid = $contact->create($actor);
			if ($cid <= 0) {
				$report[] = $label.': FAILED to create the contact: '.$contact->error;
				continue;
			}
			$when = $this->db->idate($joined ? $joined : self::now());
			$sql = "INSERT INTO ".$this->db->prefix()."onboarding_applicant (entity, email, firstname, lastname, discord, notify_events, notify_news, fk_socpeople, payment_state, id_uploaded_at, datec) VALUES (";
			$sql .= ((int) $conf->entity).", '".$this->db->escape($email)."', '".$this->db->escape($first)."', '".$this->db->escape($last)."', '".$this->db->escape(substr($get('discord'), 0, 100))."', 0, 0, ".((int) $cid).", ";
			$sql .= "'".($isMember ? 'active' : 'none')."', ".($hasId ? "'".$when."'" : "NULL").", '".$when."')";
			if (!$this->db->query($sql)) {
				$report[] = $label.': FAILED: '.$this->db->lasterror();
				continue;
			}
			$app = $this->fetch($this->db->last_insert_id($this->db->prefix()."onboarding_applicant"));
			$adh = $this->ensureMember($app, $isMember ? $this->memberType($type) : 0, $joined, implode("\n", $notes));
			if (!$adh) {
				$report[] = $label.': contact added, but the member record FAILED.';
				continue;
			}
			if ($isMember && $adh->statut != Adherent::STATUS_VALIDATED) {
				$adh->validate($actor);
			}
			// A member record that existed before (imported from the ledger by the
			// CGWManagement scripts) keeps what is already on its card.
			$existing = $adh->array_options;
			if ($badge !== '' && empty($existing['options_member_code'])) {
				$taken = $this->db->query("SELECT fk_object FROM ".$this->db->prefix()."adherent_extrafields WHERE member_code = '".$this->db->escape($badge)."' AND fk_object <> ".((int) $adh->id));
				if ($taken && $this->db->num_rows($taken) > 0) {
					$warn .= ' WARNING: badge '.$badge.' is already on another member; not written.';
				} else {
					$adh->array_options['options_member_code'] = $badge;
				}
			}
			if ($credential !== '' && empty($existing['options_credential_id'])) {
				$adh->array_options['options_credential_id'] = $credential;
			}
			$hasCredential = !empty($adh->array_options['options_member_code']) || !empty($adh->array_options['options_credential_id']);
			$adh->array_options['options_access_enabled'] = ($hasCredential && $isMember) ? 1 : 0;
			if (empty($existing['options_payment_channel'])) {
				$adh->array_options['options_payment_channel'] = $channel;
			}
			if ($hasId) {
				$adh->array_options['options_id_verified'] = 1;
			}
			$adh->insertExtraFields();
			$this->refreshStage($app->rowid);
			$report[] = $label.': added as '.$what.'.'.$warn;
		}
		return $report;
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
			$waived = isset($adh->array_options['options_dues_waived_until']) ? $adh->array_options['options_dues_waived_until'] : '';
			if ($waived !== '' && $waived !== null) {
				$waivedTs = is_numeric($waived) ? (int) $waived : strtotime((string) $waived);
				if ($waivedTs && $waivedTs >= $now) {
					continue; // Scholarship or board-approved pause.
				}
			}
			$channel = isset($adh->array_options['options_payment_channel']) ? (string) $adh->array_options['options_payment_channel'] : '';
			if ($channel !== '' && $channel !== 'Givebutter') {
				continue; // PayPal, check and scholarship members are kept up by hand.
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
		$badge = isset($adh->array_options['options_member_code']) ? (string) $adh->array_options['options_member_code'] : '';
		if ($badge === '' && !empty($adh->array_options['options_credential_id'])) {
			$badge = (string) $adh->array_options['options_credential_id'];
		}
		$access = !empty($adh->array_options['options_access_enabled']);
		$this->mail($app->email, $this->template('ONBOARDING_MAIL_LAPSED_SUBJECT', 'Your {org} membership has ended', $app), $this->template('ONBOARDING_MAIL_LAPSED_BODY', "Hi {firstname},\n\nWe did not receive your dues, so your {org} membership has ended and your badge access will be turned off. You are welcome back any time:\n\n{payment_url}", $app));
		$this->mail(getDolGlobalString('ONBOARDING_STAFF_EMAIL'), 'Membership ended: '.$app->firstname.' '.$app->lastname, $app->firstname.' '.$app->lastname.' <'.$app->email.'> is now non-paying.'.($badge !== '' ? ' Badge '.$badge.($access ? ' still has access and should be turned off.' : ' is already marked as having no access.') : ' No badge is recorded for them.'));
	}

	// ---------------------------------------------------------------- email updates

	/**
	 * The contact tag that marks "send me email updates". Mass emailings pick
	 * their recipients by it (Emailing, Contacts, filter by tag).
	 *
	 * @param bool $create Create the tag if it does not exist yet
	 * @return Categorie|null
	 */
	public function updatesTag($create = true)
	{
		static $tag = null;
		if ($tag !== null) {
			return $tag;
		}
		if (!isModEnabled('categorie')) {
			dol_syslog('Onboarding: the Tags/Categories module is off, so email signups are not tagged', LOG_WARNING);
			return null;
		}
		$label = getDolGlobalString('ONBOARDING_UPDATES_TAG', 'Email updates');
		$cat = new Categorie($this->db);
		if ($cat->fetch(0, $label, Categorie::TYPE_CONTACT) > 0) {
			return $tag = $cat;
		}
		if (!$create) {
			return null;
		}
		$cat = new Categorie($this->db);
		$cat->label = $label;
		$cat->type = Categorie::TYPE_CONTACT;
		$cat->description = 'People who asked for email updates on the website. Use this tag to pick recipients for a mass emailing.';
		$cat->visible = 0;
		if ($cat->create($this->actor()) <= 0) {
			dol_syslog('Onboarding: could not create the email updates tag: '.$cat->error, LOG_ERR);
			return null;
		}
		return $tag = $cat;
	}

	/**
	 * @param int $contactId Contact id
	 * @return bool Contact carries the email updates tag
	 */
	public function isTaggedForUpdates($contactId)
	{
		$tag = $this->updatesTag(false);
		return $tag ? (bool) $tag->containsObject(Categorie::TYPE_CONTACT, (int) $contactId) : false;
	}

	/**
	 * Put a contact on the email updates list: tag it and tick both notify fields.
	 *
	 * @param Contact $contact Fetched contact (its extra fields are rewritten)
	 * @param bool $fields Also tick both notify fields
	 * @return void
	 */
	public function tagForUpdates($contact, $fields = true)
	{
		$tag = $this->updatesTag();
		if ($tag && !$tag->containsObject(Categorie::TYPE_CONTACT, $contact->id)) {
			$tag->add_type($contact, Categorie::TYPE_CONTACT);
		}
		if ($fields && (empty($contact->array_options['options_notify_events']) || empty($contact->array_options['options_notify_news']))) {
			$contact->array_options['options_notify_events'] = 1;
			$contact->array_options['options_notify_news'] = 1;
			$contact->insertExtraFields();
		}
	}

	/**
	 * @param string $email Email
	 * @return int Id of the first contact with this address, 0 if none
	 */
	public function contactByEmail($email)
	{
		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."socpeople WHERE LOWER(email) = '".$this->db->escape(self::cleanEmail($email))."' AND entity IN (".getEntity('contact').") ORDER BY rowid ASC LIMIT 1");
		if ($res && ($obj = $this->db->fetch_object($res))) {
			return (int) $obj->rowid;
		}
		return 0;
	}

	/**
	 * @param string $email Email
	 * @return bool The address is on Dolibarr's unsubscribe list
	 */
	public function isUnsubscribed($email)
	{
		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."mailing_unsubscribe WHERE LOWER(email) = '".$this->db->escape(self::cleanEmail($email))."' AND entity IN (".getEntity('mailing', 0).") LIMIT 1");
		return $res && $this->db->fetch_object($res);
	}

	/**
	 * Someone asked for email updates. Finds or creates their contact and puts
	 * it on the list.
	 *
	 * A signup on the website is a fresh request, so it also takes the address
	 * off Dolibarr's unsubscribe list. An import ($optin false) never does: an
	 * old list must not undo an unsubscribe.
	 *
	 * @param array<string,mixed> $in email, and optionally name, page, date (Y-m-d), source
	 * @param bool $optin The person is asking right now
	 * @param bool $dry Only report what would happen
	 * @return array<string,mixed> ok, result (added, tagged, already, unsubscribed) or error
	 */
	public function subscribe($in, $optin = true, $dry = false)
	{
		$email = self::cleanEmail(isset($in['email']) ? $in['email'] : '');
		if (!isValidEmail($email)) {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		if (!$optin && $this->isUnsubscribed($email)) {
			return array('ok' => true, 'result' => 'unsubscribed');
		}
		$name = trim(dol_string_nohtmltag((string) (isset($in['name']) ? $in['name'] : '')));
		$source = trim(dol_string_nohtmltag((string) (isset($in['source']) ? $in['source'] : 'the website')));
		$page = trim(dol_string_nohtmltag((string) (isset($in['page']) ? $in['page'] : '')));
		$date = isset($in['date']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $in['date']) ? substr((string) $in['date'], 0, 10) : dol_print_date(self::now(), '%Y-%m-%d');
		$line = 'Asked for email updates on '.$date.' ('.$source.($page !== '' ? ', '.$page : '').').';

		$contact = new Contact($this->db);
		$id = $this->contactByEmail($email);
		if ($id > 0) {
			if ($contact->fetch($id) <= 0) {
				return array('ok' => false, 'error' => 'server', 'http' => 500);
			}
			if ($this->isTaggedForUpdates($id) && !($optin && $this->isUnsubscribed($email))) {
				return array('ok' => true, 'result' => 'already');
			}
			if ($dry) {
				return array('ok' => true, 'result' => 'tagged');
			}
			$contact->update_note(trim($contact->note_private."\n".$line), '_private');
			$result = 'tagged';
		} else {
			if ($dry) {
				return array('ok' => true, 'result' => 'added');
			}
			$pos = strrpos($name, ' ');
			// Both name columns hold 50 characters; a longer value makes the insert fail.
			$contact->firstname = dol_substr($pos === false ? '' : substr($name, 0, $pos), 0, 50);
			// A contact needs a last name. With no name given, the address stands in.
			$contact->lastname = dol_substr($name === '' ? $email : ($pos === false ? $name : substr($name, $pos + 1)), 0, 50);
			$contact->email = $email;
			$contact->statut = 1;
			$contact->status = 1;
			$contact->note_private = $line;
			if ($contact->create($this->actor()) <= 0) {
				$why = trim($contact->error.' '.implode(' ', (array) $contact->errors));
				dol_syslog('Onboarding: contact create failed: '.$why, LOG_ERR);
				return array('ok' => false, 'error' => 'server', 'detail' => $why, 'http' => 500);
			}
			$contact->fetch($contact->id);
			$result = 'added';
		}
		$this->tagForUpdates($contact);
		if ($optin) {
			$contact->setNoEmail(0);
		}
		return array('ok' => true, 'result' => $result);
	}

	/**
	 * Import an old email list. Paste, check, import. Columns are matched by
	 * name: email is required; name (or first and last name) and a signup date
	 * are used when present. Addresses on the unsubscribe list are skipped.
	 *
	 * @param string $text Pasted spreadsheet or CSV
	 * @param string $source Where the list came from, written on each contact
	 * @param bool $dry Only report what would happen
	 * @return string[] Summary first, then one line per row that needs a look
	 */
	public function importEmails($text, $source, $dry = true)
	{
		$lines = preg_split('/\r\n|\r|\n/', trim((string) $text));
		if (count($lines) < 2) {
			return array('Nothing to import: paste the header line and at least one row.');
		}
		$sep = strpos($lines[0], "\t") !== false ? "\t" : (substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',');
		$head = array_map(function ($h) {
			return preg_replace('/[^a-z]/', '', strtolower($h));
		}, str_getcsv(array_shift($lines), $sep, '"', '\\'));
		$find = function ($names) use ($head) {
			foreach ($names as $n) {
				$i = array_search($n, $head, true);
				if ($i !== false) {
					return $i;
				}
			}
			return -1;
		};
		$ce = $find(array('email', 'emailaddress', 'mail', 'subscriber', 'subscriberemail'));
		if ($ce < 0) {
			return array('The header line needs an "email" column.');
		}
		$cn = $find(array('name', 'fullname', 'displayname'));
		$cf = $find(array('firstname', 'first', 'givenname'));
		$cl = $find(array('lastname', 'last', 'surname', 'familyname'));
		$cd = $find(array('subscribed', 'date', 'datesubscribed', 'subscriptiondate', 'created', 'createdat', 'signupdate', 'optintime', 'confirmtime'));
		$source = trim((string) $source) === '' ? 'imported list' : trim((string) $source);

		$count = array('added' => 0, 'tagged' => 0, 'already' => 0, 'unsubscribed' => 0, 'invalid' => 0, 'duplicate' => 0, 'failed' => 0);
		$notes = array();
		$seen = array();
		foreach ($lines as $n => $line) {
			if (trim($line) === '') {
				continue;
			}
			$cells = str_getcsv($line, $sep, '"', '\\');
			$get = function ($i) use ($cells) {
				return $i >= 0 && isset($cells[$i]) ? trim($cells[$i]) : '';
			};
			$email = self::cleanEmail($get($ce));
			if (isset($seen[$email])) {
				$count['duplicate']++;
				continue;
			}
			$seen[$email] = 1;
			$name = $cn >= 0 ? $get($cn) : trim($get($cf).' '.$get($cl));
			$date = '';
			$raw = $get($cd);
			if ($raw !== '' && ($t = strtotime($raw)) !== false) {
				$date = gmdate('Y-m-d', $t);
			}
			$r = $this->subscribe(array('email' => $email, 'name' => $name, 'date' => $date, 'source' => $source), false, $dry);
			if (!$r['ok'] && $r['error'] == 'invalid') {
				$count['invalid']++;
				$notes[] = 'Row '.($n + 2).': "'.$get($ce).'" is not a valid email address, skipped.';
				continue;
			}
			if (!$r['ok']) {
				$count['failed']++;
				$notes[] = 'Row '.($n + 2).' ('.$email.'): Dolibarr could not save the contact'.(empty($r['detail']) ? '' : ': '.$r['detail']).'.';
				continue;
			}
			$count[$r['result']]++;
		}
		$verb = $dry ? 'would be' : 'were';
		$summary = $count['added'].' new contacts '.$verb.' added, '.$count['tagged'].' existing contacts '.$verb.' added to the list, '
			.$count['already'].' were already on it, '.$count['unsubscribed'].' skipped because they unsubscribed, '
			.$count['invalid'].' invalid'.($count['duplicate'] ? ', '.$count['duplicate'].' repeated in the paste' : '')
			.($count['failed'] ? ', '.$count['failed'].' COULD NOT BE SAVED (see below)' : '').'.';
		return array_merge(array($summary), $notes);
	}

	/**
	 * Can this signup still be thrown away? Not once it is complete or any
	 * dues have been paid: from then on it is a member's record.
	 *
	 * @param object $app Applicant row
	 * @return bool
	 */
	public function canDiscard($app)
	{
		return $app && $app->stage != 'complete' && $app->payment_state == 'none';
	}

	/**
	 * Delete a signup that never became a membership: the applicant row, its
	 * signed documents and ID photo, its contact, and the draft member made when
	 * the paperwork was done. A contact on the email updates list is kept, and so
	 * is any member record that is more than an unpaid draft.
	 *
	 * @param object $app Applicant row
	 * @return void
	 */
	public function discard($app)
	{
		$actor = $this->actor();
		if ($app->fk_adherent > 0) {
			$adh = new Adherent($this->db);
			if ($adh->fetch((int) $app->fk_adherent) > 0 && (int) $adh->statut == Adherent::STATUS_DRAFT) {
				$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."subscription WHERE fk_adherent = ".((int) $adh->id));
				$obj = $res ? $this->db->fetch_object($res) : null;
				if ($obj && (int) $obj->n == 0) {
					// Dolibarr 20 dropped the leading $rowid argument.
					$params = (new ReflectionMethod($adh, 'delete'))->getParameters();
					if ($params && $params[0]->getName() == 'rowid') {
						$adh->delete($adh->id, $actor);
					} else {
						$adh->delete($actor);
					}
				}
			}
		}
		if ($app->fk_socpeople > 0) {
			$contact = new Contact($this->db);
			// Someone who asked for email updates stays on the list even though
			// they never finished joining.
			if ($contact->fetch((int) $app->fk_socpeople) > 0 && !$this->isTaggedForUpdates($contact->id)) {
				$contact->delete($actor);
			}
		}
		dol_delete_dir_recursive(DOL_DATA_ROOT.'/onboarding/applicant/'.((int) $app->rowid));
		$this->db->query("DELETE FROM ".$this->db->prefix()."onboarding_applicant WHERE rowid = ".((int) $app->rowid));
	}

	/**
	 * The applicant pressed "Start over" on the join page.
	 *
	 * @param object $app Applicant row
	 * @return array<string,mixed> Response for the website
	 */
	public function cancel($app)
	{
		if (!$this->canDiscard($app)) {
			return array('ok' => false, 'error' => 'paid', 'http' => 409);
		}
		$this->discard($app);
		return array('ok' => true);
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
				$this->discard($this->fetch($id));
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
