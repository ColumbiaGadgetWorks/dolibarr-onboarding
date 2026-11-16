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

	/** @var array<string,object> Parts made so far, by class */
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
	 * @return OnboardingSchedule Tools, slots, closed days, the catalog, free times
	 */
	public function schedule()
	{
		return $this->part('schedule', 'OnboardingSchedule');
	}

	/**
	 * @return OnboardingSpots On-the-spot trainings
	 */
	public function spots()
	{
		return $this->part('spot', 'OnboardingSpots');
	}

	/**
	 * @return OnboardingClasses Group classes
	 */
	public function classes()
	{
		return $this->part('classes', 'OnboardingClasses');
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
			$newId = OnboardingSql::insert($this->db, 'onboarding_applicant', array(
				'entity' => (int) $conf->entity,
				'email' => $email,
				'firstname' => $first,
				'lastname' => $last,
				'discord' => substr($discord, 0, 100),
				'notify_events' => !empty($in['notify_events']),
				'notify_news' => !empty($in['notify_news']),
				'fk_socpeople' => (int) $cid,
				'ip' => substr((string) (isset($in['ip']) ? $in['ip'] : ''), 0, 64),
				'training_only' => 1,
				'datec' => $this->db->idate(OnboardingService::now()),
			));
			if (!$newId) {
				return array('ok' => false, 'error' => 'server', 'http' => 500);
			}
			$app = $this->svc->fetch($newId);
			$this->svc->ensureMember($app, 0, 0, $note);
			$this->svc->syncExtraFields($this->svc->fetch((int) $app->rowid));
			if (!empty($in['notify_events']) || !empty($in['notify_news'])) {
				$c = new Contact($this->db);
				if ($c->fetch((int) $cid) > 0) {
					$this->svc->updates()->tagForUpdates($c, false);
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
		$newId = OnboardingSql::insert($this->db, 'onboarding_applicant', array(
			'entity' => (int) $conf->entity,
			'email' => $p['email'],
			'firstname' => dol_trunc($p['firstname'], 100, 'right', 'UTF-8', 1),
			'lastname' => dol_trunc($p['lastname'], 100, 'right', 'UTF-8', 1),
			'fk_socpeople' => $p['contact'] ? (int) $p['contact'] : null,
			'fk_adherent' => $p['member'] ? (int) $p['member']->rowid : null,
			'training_only' => 1,
			'stage' => $p['member'] && (int) $p['member']->statut == 1 ? 'complete' : 'details',
			'datec' => $this->db->idate(OnboardingService::now()),
		));
		return $newId ? $this->svc->fetch($newId) : null;
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
		$token = $this->svc->signup()->issueToken($app, false);
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
		$tool = $this->schedule()->toolByRef(isset($in['tool']) ? (string) $in['tool'] : '');
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
		$slot = $this->schedule()->slot(isset($in['slot']) ? (int) $in['slot'] : 0);
		$session = $slot && (int) $slot->fk_tool == (int) $tool->rowid ? $this->schedule()->findAvailable((int) $slot->rowid, $start, (int) $tool->rowid) : null;
		if (!$session) {
			return array('ok' => false, 'error' => 'full', 'http' => 409);
		}
		$member = $p['kind'] == 'member';
		$price = $member ? self::memberPrice($tool) : round((float) $tool->fee, 2);
		$app = $p['applicant'];
		$now = OnboardingService::now();
		$hold = $now + (int) round(self::num('TRAINING_HOLD_MINUTES', 30) * 60);
		$token = bin2hex(random_bytes(24));
		$id = OnboardingSql::insert($this->db, 'onboarding_booking', array(
			'entity' => (int) $conf->entity,
			'token' => $token,
			'fk_tool' => (int) $tool->rowid,
			'fk_slot' => (int) $slot->rowid,
			'fk_trainer' => (int) $slot->fk_adherent,
			'fk_adherent' => $p['member'] ? (int) $p['member']->rowid : null,
			'fk_socpeople' => $p['contact'] ? (int) $p['contact'] : null,
			'fk_applicant' => $app ? (int) $app->rowid : null,
			'email' => $email,
			'firstname' => dol_trunc($p['firstname'], 100, 'right', 'UTF-8', 1),
			'lastname' => dol_trunc($p['lastname'], 100, 'right', 'UTF-8', 1),
			'session_start' => $this->db->idate($start),
			'minutes' => (int) $session['minutes'],
			'price' => (float) price2num($price),
			'price_type' => $member ? 'member' : 'nonmember',
			'status' => $price > 0 ? 'held' : 'paid',
			'hold_until' => $price > 0 ? $this->db->idate($hold) : null,
			'source' => 'web',
			'ip' => substr((string) (isset($in['ip']) ? $in['ip'] : ''), 0, 64),
			'datec' => $this->db->idate($now),
		));
		if (!$id) {
			return array('ok' => false, 'error' => 'server', 'http' => 500);
		}
		$ref = 'TR-'.sprintf('%04d', $id);
		$this->saveBooking($id, array('ref' => $ref));
		// Two people pressing at the same moment for the last seat: the later one lets go.
		$t = $this->schedule()->seatsTaken((int) $slot->fk_adherent, $start, (int) $tool->rowid);
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
		if (in_array($b->status, array('held', 'expired'), true) && $this->svc->givebutter()->quickSync()) {
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
		$t2 = $this->schedule()->seatsTaken((int) $b->fk_trainer, $this->db->jdate($b->session_start), (int) $b->fk_tool);
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
		$slot = $b->fk_slot ? $this->schedule()->slot((int) $b->fk_slot) : null;
		$tool = $this->schedule()->tool((int) $b->fk_tool);
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
			$this->spots()->spotPaid($b);
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
		$session = $this->schedule()->findAvailable($slotId, $start, (int) $b->fk_tool, (int) $b->rowid);
		$slot = $this->schedule()->slot($slotId);
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
		$tool = $this->schedule()->tool((int) $b->fk_tool);
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
		$this->schedule()->pushLookups(array($email));
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
			$this->svc->givebutter()->quickSync();
		}
		$n['expired'] = $this->spots()->expireSpot($now);
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

		$n['classes'] = $this->classes()->runClasses($now);
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
		$version = $this->schedule()->version();
		$late = (int) round(self::num('TRAINING_SYNC_ALERT_MINUTES', 60) * 60);
		$last = $this->schedule()->changes(1);
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
		$tool = $this->schedule()->tool(isset($in['tool']) ? (int) $in['tool'] : 0);
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
		if ($this->schedule()->isTrainedOn($p['member'] ? (int) $p['member']->rowid : 0, $tool) || $this->trainedEmail($email, $tool)) {
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
	public function trainedEmail($email, $tool)
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
		$tool = $this->schedule()->tool($toolId);
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
			if ($this->trainedEmail($email, $tool) || ($p['member'] && $this->schedule()->isTrainedOn((int) $p['member']->rowid, $tool))) {
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
		$tool = $b->source == 'spot' ? $this->schedule()->tool((int) $b->fk_tool) : null;
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
	 * Send one of the booking emails in OnboardingMails::BOOKING.
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
		$all = OnboardingMails::BOOKING;
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
