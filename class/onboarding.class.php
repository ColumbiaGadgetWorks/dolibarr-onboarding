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
dol_include_once('/onboarding/class/mails.class.php');
dol_include_once('/onboarding/class/sql.class.php');

/**
 * Core onboarding logic: applicants, members, signed documents, mail and
 * alerts. The applicant table is the source of truth; the extra fields on
 * contacts and members mirror it so staff see it on the normal cards.
 *
 * The rest lives in parts reached from here: givebutter() (payments),
 * signup() (the signup steps), dues() (reminders and lapses) and updates()
 * (the email-updates list).
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

	/** @var array<string,object> The parts below, made on first use */
	private $parts = array();

	/**
	 * @param string $file File in class/, without .class.php
	 * @param string $class Class name
	 * @return object
	 */
	private function part($file, $class)
	{
		if (!isset($this->parts[$class])) {
			dol_include_once('/onboarding/class/'.$file.'.class.php');
			$this->parts[$class] = new $class($this);
		}
		return $this->parts[$class];
	}

	/**
	 * @return OnboardingGivebutter Givebutter client, webhooks, sync and dues payments
	 */
	public function givebutter()
	{
		return $this->part('givebutter', 'OnboardingGivebutter');
	}

	/**
	 * @return OnboardingSignup Signup steps: details, signatures, ID photo
	 */
	public function signup()
	{
		return $this->part('signup', 'OnboardingSignup');
	}

	/**
	 * @return OnboardingDues Dues reminders and lapses
	 */
	public function dues()
	{
		return $this->part('dues', 'OnboardingDues');
	}

	/**
	 * @return OnboardingUpdates The email-updates list
	 */
	public function updates()
	{
		return $this->part('updates', 'OnboardingUpdates');
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
	 * The setup page's many-line settings. Up to 0.22.1 the page showed their line
	 * breaks as the two characters \n, so pressing Save stored each one as a single
	 * line (a broken CGW home page layout, reconcile rules, yearly filings list).
	 */
	const AREA_SETTINGS = array('ONBOARDING_MAIL_TRAINED_BODY', 'ONBOARDING_FILING_YEARLY', 'ONBOARDING_RECONCILE_RULES', 'ONBOARDING_MAIL_REMINDER_BODY', 'ONBOARDING_MAIL_REMINDER_PAYPAL_BODY', 'ONBOARDING_MAIL_LAPSED_BODY', 'ONBOARDING_MAIL_WELCOME_BODY', 'ONBOARDING_MAIL_RESUME_BODY', 'ONBOARDING_WAIVER_TEXT', 'ONBOARDING_AGREEMENT_TEXT', 'ONBOARDING_HOME_YAML', 'ONBOARDING_SECURITY_CHECKLIST');

	/**
	 * Put back the line breaks of settings saved as a single line, and drop the ones
	 * that are just the built-in default (so later improvements to it arrive).
	 * Run when the module is enabled or updated.
	 *
	 * @param DoliDB $db Database handler
	 * @return string[] The settings repaired
	 */
	public static function repairAreaSettings($db)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dol_include_once('/onboarding/class/reconcile.class.php');
		dol_include_once('/onboarding/class/filing.class.php');
		$defaults = array(
			'ONBOARDING_HOME_YAML' => (string) @file_get_contents(dirname(__DIR__).'/home.default.yaml'),
			'ONBOARDING_RECONCILE_RULES' => OnboardingReconcile::DEFAULT_RULES,
			'ONBOARDING_FILING_YEARLY' => OnboardingFiling::DEFAULT_YEARLY,
		);
		$fixed = array();
		foreach (self::AREA_SETTINGS as $name) {
			$v = getDolGlobalString($name);
			if ($v === '' || strpos($v, "\n") !== false || strpos($v, '\n') === false) {
				continue;
			}
			$v = str_replace(array('\r\n', '\n', '\r'), array("\n", "\n", ''), $v);
			// The same page also squeezed runs of spaces, so the default is recognised
			// whatever its spacing.
			$squeeze = function ($t) {
				return trim((string) preg_replace('/\s+/', ' ', $t));
			};
			if (isset($defaults[$name]) && $squeeze($v) === $squeeze($defaults[$name])) {
				$v = '';
			}
			dolibarr_set_const($db, $name, $v, 'chaine', 0, '', $conf->entity);
			$fixed[] = $name;
		}
		return $fixed;
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

	/** The two documents people sign, with the title used when the source has none. */
	/** Names of the copies on the member card, without the extension. */
	const MEMBER_FILES = array('waiver' => 'onboarding-signed-waiver', 'agreement' => 'onboarding-signed-agreement', 'id' => 'onboarding-id-photo');
	const DOCS = array('waiver' => 'Liability waiver', 'agreement' => 'Membership agreement');

	/**
	 * The waiver and agreement as offered for signing now: the newest version
	 * copied from GitHub (see fetchDocs), else the text on the setup page, else
	 * placeholder wording. The version is a hash of the text, so a signature
	 * always points at the exact words that were shown.
	 *
	 * @return array<string,array{title:string,text:string,version:string,source:string,since:string}>
	 */
	public function docs()
	{
		global $conf;
		$out = array();
		$defaults = array(
			'waiver' => "I understand that Columbia Gadget Works is a shared workshop with tools and machines that can cause serious injury. I choose to use the space and its equipment at my own risk.\n\nI will not use a tool I have not been trained on, I will follow posted safety rules, and I will wear appropriate protective equipment.\n\nTo the extent the law allows, I release Columbia Gadget Works, its volunteers, board and members from liability for injury or property damage arising from my use of the space.",
			'agreement' => "As a member of Columbia Gadget Works I agree to:\n\n- Follow the shop rules and the code of conduct.\n- Clean up after myself and leave tools and benches ready for the next person.\n- Report damage or unsafe conditions right away.\n- Be responsible for any guests I bring.\n- Keep my dues current. I can cancel at any time.\n\nI understand that membership and badge access can be suspended for unsafe behavior or unpaid dues.",
		);
		foreach (self::DOCS as $key => $title) {
			$res = $this->db->query("SELECT title, text, version, source, datec FROM ".$this->db->prefix()."onboarding_doc_version WHERE entity = ".((int) $conf->entity)." AND doc = '".$key."' ORDER BY rowid DESC LIMIT 1");
			$row = $res ? $this->db->fetch_object($res) : null;
			if ($row) {
				$out[$key] = array('title' => (string) ($row->title ?: $title), 'text' => (string) $row->text, 'version' => (string) $row->version, 'source' => (string) $row->source, 'since' => dol_print_date($this->db->jdate($row->datec), '%Y-%m-%d'));
				continue;
			}
			$text = str_replace("\r\n", "\n", getDolGlobalString('ONBOARDING_'.strtoupper($key).'_TEXT', $defaults[$key]));
			$out[$key] = array('title' => $title, 'text' => $text, 'version' => self::docVersion($text), 'source' => getDolGlobalString('ONBOARDING_'.strtoupper($key).'_TEXT') !== '' ? 'setup page' : 'placeholder', 'since' => '');
		}
		return $out;
	}

	/**
	 * @param string $text Document text
	 * @return string Its version: the first 16 hex of its SHA-256
	 */
	public static function docVersion($text)
	{
		return substr(hash('sha256', (string) $text), 0, 16);
	}

	/**
	 * @param string $doc waiver or agreement
	 * @param string $version Version a signature names
	 * @return object|null That version's title and text, if Dolibarr has it
	 */
	public function docAt($doc, $version)
	{
		global $conf;
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_doc_version WHERE entity = ".((int) $conf->entity)." AND doc = '".$this->db->escape($doc)."' AND version = '".$this->db->escape($version)."' LIMIT 1");
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param string $doc waiver or agreement
	 * @return object[] Every version, newest first
	 */
	public function docVersions($doc)
	{
		global $conf;
		$out = array();
		$res = $this->db->query("SELECT rowid, version, title, source, datec, LENGTH(text) AS size FROM ".$this->db->prefix()."onboarding_doc_version WHERE entity = ".((int) $conf->entity)." AND doc = '".$this->db->escape($doc)."' ORDER BY rowid DESC");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * Keep a new version of a document, unless it is the newest one already.
	 *
	 * @param string $doc waiver or agreement
	 * @param string $title Title
	 * @param string $text Text
	 * @param string $source Where it came from
	 * @return bool A new version was added
	 */
	public function addDocVersion($doc, $title, $text, $source)
	{
		global $conf;
		$text = trim(str_replace("\r\n", "\n", (string) $text));
		if (!isset(self::DOCS[$doc]) || $text === '') {
			return false;
		}
		$version = self::docVersion($text);
		$docs = $this->docs();
		if ($docs[$doc]['version'] === $version && $docs[$doc]['source'] !== 'placeholder' && $docs[$doc]['source'] !== 'setup page') {
			return false;
		}
		if ($this->docAt($doc, $version)) {
			// Going back to an older wording: it becomes the newest again. Signatures
			// name the version, not the row, so its text stays the same.
			$this->db->query("DELETE FROM ".$this->db->prefix()."onboarding_doc_version WHERE entity = ".((int) $conf->entity)." AND doc = '".$doc."' AND version = '".$version."'");
		}
		$sql = "INSERT INTO ".$this->db->prefix()."onboarding_doc_version (entity, doc, version, title, text, source, datec) VALUES (".((int) $conf->entity).", '".$doc."', '".$version."', '".$this->db->escape(dol_trunc($title !== '' ? $title : self::DOCS[$doc], 200, 'right', 'UTF-8', 1))."', '".$this->db->escape($text)."', '".$this->db->escape(dol_trunc($source, 255, 'right', 'UTF-8', 1))."', '".$this->db->idate(self::now())."')";
		return (bool) $this->db->query($sql);
	}

	/**
	 * Copy the waiver and agreement from GitHub (the setup page's document
	 * addresses), keeping a new version whenever the words changed. A Markdown
	 * file may start with front matter; its title is used and the rest dropped.
	 *
	 * @return string Summary
	 */
	public function fetchDocs()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
		$out = array();
		foreach (self::DOCS as $doc => $title) {
			$url = getDolGlobalString('ONBOARDING_'.strtoupper($doc).'_URL');
			if ($url === '') {
				$out[] = $doc.': no address set';
				continue;
			}
			$r = getURLContent($url, 'GET', '', 1, array('Accept: text/plain'), array('http', 'https'), 2);
			if (empty($r['http_code']) || $r['http_code'] != 200 || trim((string) $r['content']) === '') {
				$out[] = $doc.': could not read '.$url.' (HTTP '.(isset($r['http_code']) ? $r['http_code'] : '?').')';
				$this->alert('docs-'.$doc, 'Dolibarr cannot read the '.strtolower($title).' from GitHub', 'Dolibarr tried to read the '.strtolower($title).' from '.$url.' and got HTTP '.(isset($r['http_code']) ? $r['http_code'] : '?').'. People still sign the last version Dolibarr has. Check the address on the module setup page.');
				continue;
			}
			$this->alertClear('docs-'.$doc, 'Dolibarr can read the '.strtolower($title).' from GitHub again.');
			list($t, $text) = self::splitFrontMatter((string) $r['content']);
			$added = $this->addDocVersion($doc, $t !== '' ? $t : $title, $text, $url);
			$docs = $this->docs();
			$out[] = $doc.': '.($added ? 'new version '.$docs[$doc]['version'] : 'unchanged ('.$docs[$doc]['version'].')');
		}
		return implode('; ', $out);
	}

	/**
	 * @param string $raw File contents
	 * @return array{0:string,1:string} Title from the front matter, and the text after it
	 */
	public static function splitFrontMatter($raw)
	{
		$raw = str_replace("\r\n", "\n", ltrim((string) $raw, "\xEF\xBB\xBF"));
		$title = '';
		if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $raw, $m)) {
			if (preg_match('/^title:\s*["\']?(.*?)["\']?\s*$/m', $m[1], $t)) {
				$title = trim($t[1]);
			}
			$raw = $m[2];
		}
		return array($title, trim($raw));
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
	public function one($where)
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
		// A row made only to hold training paperwork (a non-member who came to
		// train, or an existing member signing before a booking) has no signup
		// stage to move through; only the signature dates go to the cards.
		if (!empty($app->training_only)) {
			$this->syncExtraFields($app);
			return $app;
		}
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
		$this->copyToMember($app);
		return $app;
	}

	/**
	 * The member card's folder for attached files (Members, the member, Attached
	 * files and documents).
	 *
	 * @param int $adhId Member id
	 * @return string Folder, '' when the member is gone
	 */
	public function memberDir($adhId)
	{
		global $conf;
		$adh = new Adherent($this->db);
		if ($adhId <= 0 || $adh->fetch((int) $adhId) <= 0) {
			return '';
		}
		return $conf->adherent->dir_output.'/'.get_exdir(0, 0, 0, 1, $adh, 'member');
	}

	/**
	 * File name for a signed document. The version is part of the name, so a
	 * newer version signed later sits beside the older one instead of replacing it.
	 *
	 * @param string $doc waiver or agreement (or a longer name prefix)
	 * @param string $version Document version signed; '' gives the old unversioned name
	 * @param string $suffix .pdf, .txt or -signature.png
	 * @return string
	 */
	public static function signedName($doc, $version, $suffix)
	{
		$v = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $version), '-.');
		return $doc.($v !== '' ? '-'.$v : '').$suffix;
	}

	/**
	 * The signed waiver or agreement on file for an applicant: the version they
	 * signed last, else a file from before names carried the version.
	 *
	 * @param object $app Applicant row
	 * @param string $doc waiver or agreement
	 * @return string Full path, '' if there is none
	 */
	public function signedFile($app, $doc)
	{
		$dir = DOL_DATA_ROOT.'/onboarding/applicant/'.((int) $app->rowid);
		$version = isset($app->{$doc.'_version'}) ? (string) $app->{$doc.'_version'} : '';
		foreach (array(self::signedName($doc, $version, ''), $doc) as $base) {
			foreach (array('.pdf', '.txt') as $ext) {
				if (is_readable($dir.'/'.$base.$ext)) {
					return $dir.'/'.$base.$ext;
				}
			}
		}
		return '';
	}

	/**
	 * Copy the signed waiver and agreement and the ID photo onto the member card, so
	 * they show under its Attached files and documents. A file is copied again only
	 * when it changes (signed again, a new photo), so one deleted from the member card
	 * stays deleted. The originals stay in the onboarding folder either way.
	 *
	 * @param object $app Applicant row
	 * @return int Files copied
	 */
	public function copyToMember($app)
	{
		if (!$app || !($app->fk_adherent > 0)) {
			return 0;
		}
		$src = DOL_DATA_ROOT.'/onboarding/applicant/'.((int) $app->rowid);
		$files = array();
		foreach (array('waiver', 'agreement') as $doc) {
			$path = $this->signedFile($app, $doc);
			if ($path !== '') {
				$files[$doc] = basename($path);
			}
		}
		if (!empty($app->id_file) && is_readable($src.'/'.basename($app->id_file))) {
			$files['id'] = basename($app->id_file);
		}
		if (!$files) {
			return 0;
		}
		// What was copied where, so a file is not copied twice.
		$marker = $src.'/member-copies.json';
		$done = is_readable($marker) ? json_decode((string) file_get_contents($marker), true) : array();
		$done = is_array($done) ? $done : array();
		$dest = '';
		$copied = 0;
		foreach ($files as $what => $file) {
			$sum = md5_file($src.'/'.$file);
			if (isset($done[$what]) && $done[$what]['member'] == $app->fk_adherent && $done[$what]['md5'] === $sum) {
				continue;
			}
			if ($dest === '') {
				$dest = $this->memberDir((int) $app->fk_adherent);
				if ($dest === '' || dol_mkdir($dest) < 0) {
					return $copied;
				}
			}
			$ext = '.'.pathinfo($file, PATHINFO_EXTENSION);
			$name = $what == 'id' ? self::MEMBER_FILES[$what].$ext : self::signedName(self::MEMBER_FILES[$what], (string) $app->{$what.'_version'}, $ext);
			$isNew = !file_exists($dest.'/'.$name);
			if (!@copy($src.'/'.$file, $dest.'/'.$name)) {
				dol_syslog('Onboarding: could not copy '.$file.' to member '.$app->fk_adherent, LOG_WARNING);
				continue;
			}
			if ($isNew && function_exists('addFileIntoDatabaseIndex')) {
				$adh = new Adherent($this->db);
				$adh->fetch((int) $app->fk_adherent);
				addFileIntoDatabaseIndex($dest, $name, '', 'uploaded', 0, $adh);
			}
			$done[$what] = array('member' => (int) $app->fk_adherent, 'md5' => $sum, 'name' => $name);
			$copied++;
		}
		if ($copied) {
			file_put_contents($marker, json_encode($done));
		}
		return $copied;
	}

	/**
	 * Daily: copy anything not yet on its member card (members linked by hand, and
	 * everyone who signed up before files were copied).
	 *
	 * @return string Summary
	 */
	public function copyAllToMembers()
	{
		global $conf;
		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."onboarding_applicant WHERE entity = ".((int) $conf->entity)." AND fk_adherent > 0");
		$ids = array();
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$ids[] = (int) $obj->rowid;
		}
		$n = 0;
		foreach ($ids as $id) {
			$n += $this->copyToMember($this->fetch($id));
		}
		return $n.' files copied to member cards';
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
		if (!empty($app->training_only)) {
			$values = array_intersect_key($values, array_flip(array('waiver_date', 'agreement_date')));
		}
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
	 * @param string $to Recipient(s), comma separated
	 * @param string $subject Subject
	 * @param string $body Body
	 * @param string $cc Copy to, comma separated
	 * @param string $replyTo Reply-to, comma separated
	 * @return bool
	 */
	public function mail($to, $subject, $body, $cc = '', $replyTo = '')
	{
		global $conf;
		if (empty($to)) {
			return false;
		}
		// Dolibarr refuses an email with more than ten recipients; send several.
		$all = array_values(array_filter(array_map('trim', explode(',', (string) $to)), 'strlen'));
		if (count($all) > 8) {
			$ok = true;
			foreach (array_chunk($all, 8) as $chunk) {
				$ok = $this->mail(implode(', ', $chunk), $subject, $body, $cc, $replyTo) && $ok;
			}
			return $ok;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		$from = trim(getDolGlobalString('ONBOARDING_MAIL_FROM', getDolGlobalString('MAIN_MAIL_EMAIL_FROM', 'noreply@localhost')));
		// A bare address shows as its first half ("mail"); show the organization's name.
		$name = trim(str_replace(array('"', '<', '>', "\r", "\n"), '', getDolGlobalString('ONBOARDING_MAIL_FROM_NAME', getDolGlobalString('MAIN_INFO_SOCIETE_NOM'))));
		if ($name !== '' && strpos($from, '<') === false) {
			$from = '"'.$name.'" <'.$from.'>';
		}
		try {
			$mail = new CMailFile($subject, $to, $from, $body, array(), array(), array(), $cc, '', 0, 0, '', '', 'onboarding', '', 'standard', $replyTo);
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
	 * Who hears about problems between Dolibarr, the website and Givebutter:
	 * the alerts address on the setup page, else whoever holds the secretary
	 * tag, else the membership team.
	 *
	 * @return string Comma separated addresses
	 */
	public function alertTo()
	{
		$to = getDolGlobalString('ONBOARDING_ALERT_EMAIL');
		if ($to === '') {
			dol_include_once('/onboarding/class/purchase.class.php');
			$p = new OnboardingPurchase($this->db, $this);
			$to = implode(', ', $p->roleEmails(array('secretary')));
		}
		return $to !== '' ? $to : getDolGlobalString('ONBOARDING_STAFF_EMAIL');
	}

	/**
	 * Email the alerts address about a problem: at most once an hour for the
	 * same problem, and once more when it clears (alertClear).
	 *
	 * @param string $key Short name of the problem
	 * @param string $subject Subject
	 * @param string $body What is wrong and what to check
	 * @return void
	 */
	public function alert($key, $subject, $body)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$name = 'ONBOARDING_ALERT_'.strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $key));
		$last = getDolGlobalInt($name);
		$now = self::now();
		dol_syslog('Onboarding alert '.$key.': '.$subject, LOG_WARNING);
		if ($last > 0 && $now - $last < 3600) {
			return;
		}
		dolibarr_set_const($this->db, $name, $now, 'chaine', 0, 'Last alert email about '.$key, $conf->entity);
		$this->mail($this->alertTo(), '[Alert] '.$subject, $body."

You get at most one email an hour about this, and one more when it is fixed.");
	}

	/**
	 * A problem reported by alert() has gone: say so once.
	 *
	 * @param string $key Short name of the problem
	 * @param string $message What is working again
	 * @return void
	 */
	public function alertClear($key, $message)
	{
		global $conf;
		$name = 'ONBOARDING_ALERT_'.strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $key));
		if (getDolGlobalInt($name) <= 0) {
			return;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_del_const($this->db, $name, $conf->entity);
		$this->mail($this->alertTo(), '[Fixed] '.$message, $message);
	}

	/**
	 * Fill a template from the setup page.
	 *
	 * @param string $const Setting holding the template; its built-in text is in OnboardingMails::MEMBER
	 * @param object $app Applicant row
	 * @param array<string,string> $extra Extra placeholders
	 * @return string
	 */
	public function template($const, $app, $extra = array())
	{
		$text = OnboardingMails::text($const);
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
			$newId = OnboardingSql::insert($this->db, 'onboarding_applicant', array(
				'entity' => (int) $conf->entity,
				'email' => $email,
				'firstname' => $first,
				'lastname' => $last,
				'discord' => substr($get('discord'), 0, 100),
				'notify_events' => 0,
				'notify_news' => 0,
				'fk_socpeople' => (int) $cid,
				'payment_state' => $isMember ? 'active' : 'none',
				'id_uploaded_at' => $hasId ? $when : null,
				'datec' => $when,
			));
			if (!$newId) {
				$report[] = $label.': FAILED: '.$this->db->lasterror();
				continue;
			}
			$app = $this->fetch($newId);
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

	// ---------------------------------------------------------------- people found by email or payment

	/**
	 * A Dolibarr member, by email, else by a first and last name that exactly
	 * one member has. Excluded members are never matched.
	 *
	 * @param string $email Email
	 * @param string $first First name
	 * @param string $last Last name
	 * @return int Member id, 0 when none or not sure
	 */
	public function memberFor($email, $first = '', $last = '')
	{
		$where = "entity IN (".getEntity('adherent').") AND statut <> ".((int) Adherent::STATUS_EXCLUDED);
		$email = self::cleanEmail($email);
		if ($email !== '') {
			$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."adherent WHERE ".$where." AND LOWER(TRIM(email)) = '".$this->db->escape($email)."' ORDER BY (statut = 1) DESC, rowid LIMIT 1");
			if ($res && ($obj = $this->db->fetch_object($res))) {
				return (int) $obj->rowid;
			}
		}
		$first = strtolower(trim((string) $first));
		$last = strtolower(trim((string) $last));
		if ($first === '' || $last === '') {
			return 0;
		}
		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."adherent WHERE ".$where." AND LOWER(TRIM(firstname)) = '".$this->db->escape($first)."' AND LOWER(TRIM(lastname)) = '".$this->db->escape($last)."' LIMIT 2");
		if ($res && $this->db->num_rows($res) == 1 && ($obj = $this->db->fetch_object($res))) {
			return (int) $obj->rowid;
		}
		return 0;
	}

	/**
	 * The applicant row for a member, made when the member was added before the
	 * signup form existed (by the import scripts or by hand) and has none.
	 *
	 * @param int $adhId Member id
	 * @param string $email Fallback email when the member card has none
	 * @return object|null Applicant row
	 */
	public function applicantForMember($adhId, $email = '')
	{
		global $conf;
		$app = $this->one("fk_adherent = ".((int) $adhId));
		if ($app) {
			return $app;
		}
		$adh = new Adherent($this->db);
		if ($adh->fetch((int) $adhId) <= 0) {
			return null;
		}
		$addr = self::cleanEmail($adh->email);
		if (!isValidEmail($addr)) {
			$addr = self::cleanEmail($email);
		}
		if (!isValidEmail($addr)) {
			return null;
		}
		$app = $this->findByEmail($addr);
		if ($app) {
			if ($app->fk_adherent > 0) {
				return null; // That address belongs to another member's record.
			}
			$this->save($app->rowid, array('fk_adherent' => (int) $adhId));
			return $this->fetch($app->rowid);
		}
		$cid = $this->contactByEmail($addr);
		// Like the rows the booking page makes: it holds a member's Givebutter link
		// and signatures, with no signup stages to walk through.
		$newId = OnboardingSql::insert($this->db, 'onboarding_applicant', array(
			'entity' => (int) $conf->entity,
			'email' => $addr,
			'firstname' => dol_trunc((string) $adh->firstname, 100, 'right', 'UTF-8', 1),
			'lastname' => dol_trunc((string) $adh->lastname, 100, 'right', 'UTF-8', 1),
			'fk_socpeople' => $cid > 0 ? (int) $cid : null,
			'fk_adherent' => (int) $adhId,
			'training_only' => 1,
			'stage' => 'complete',
			'payment_state' => 'active',
			'datec' => $this->db->idate(self::now()),
		));
		if (!$newId) {
			dol_syslog('Onboarding: applicant for member '.$adhId.' failed: '.$this->db->lasterror(), LOG_ERR);
			return null;
		}
		return $this->fetch($newId);
	}

	/**
	 * @param string $file File in the module's public folder
	 * @return string Its address from outside: on "This Dolibarr's public address" when set
	 */
	public static function publicUrl($file)
	{
		$public = rtrim(getDolGlobalString('ONBOARDING_PUBLIC_URL'), '/');
		if ($public !== '') {
			return $public.'/custom/onboarding/public/'.$file;
		}
		return dol_buildpath('/onboarding/public/'.$file, 2);
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
}
