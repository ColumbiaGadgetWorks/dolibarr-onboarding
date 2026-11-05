<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');
dol_include_once('/onboarding/class/booking.class.php');
dol_include_once('/onboarding/class/gcal.class.php');

/**
 * Free classes: made in Dolibarr (Members, Free classes), signed up for on the
 * website's /classes/ page.
 *
 * A class is either a date of the monthly City of Columbia series (kind oca), whose
 * repeating event is already on the Google Calendar, or an extra class at any date
 * and time (kind extra). Saving a class writes its name, description and sign-up
 * link onto its calendar event: for an oca class only that one date of the series
 * changes, for an extra class an event of its own is made. The website calendar
 * and Discord read the calendar, so they follow. The daily job puts back what
 * someone changes on the calendar by hand (editing the whole series in Google can
 * wipe the edits to single dates). A cancelled class is taken off the calendar
 * and everyone signed up is emailed.
 *
 * Signing up takes a name and an email, and a tick for email updates. The email
 * is matched to a member card or contact; someone new becomes a contact. The page
 * never says whether someone is known. A full class takes nobody more.
 */
class OnboardingFreeClass
{
	const KINDS = array('oca' => 'Monthly OCA class (sponsored by the City of Columbia)', 'extra' => 'Extra class');
	const OCA_CREDIT = 'Financial assistance for this program has been provided by the City of Columbia Office of Cultural Affairs.';
	const LOCATION = 'Columbia Gadget Works, 1404 Grand Ave, Columbia, MO 65203';
	const REMIND_HOURS = 24;
	const MAX_SEATS = 200;
	const LIST_DAYS = 180;

	/** @var DoliDB */
	public $db;
	/** @var OnboardingService */
	public $svc;
	/** @var OnboardingBooking */
	public $booking;
	/** @var OnboardingGcal */
	public $gcal;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->booking = new OnboardingBooking($db);
		$this->svc = $this->booking->svc;
		$this->gcal = new OnboardingGcal();
	}

	// ---------------------------------------------------------------- reading

	/**
	 * @param string $where Condition on llx_onboarding_freeclass
	 * @param string $order ORDER BY
	 * @return object[] Classes, each with signed (seats taken)
	 */
	public function where($where, $order = 'c.session_start ASC')
	{
		global $conf;
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT c.*, (SELECT COUNT(*) FROM ".$p."onboarding_freeclass_signup AS s WHERE s.fk_freeclass = c.rowid AND s.status = 'signed') AS signed FROM ".$p."onboarding_freeclass AS c WHERE c.entity = ".((int) $conf->entity)." AND (".$where.") ORDER BY ".$order);
		$out = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$o->start = (int) $this->db->jdate($o->session_start);
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param int $id Class
	 * @return object|null
	 */
	public function fetch($id)
	{
		$rows = $this->where("c.rowid = ".((int) $id));
		return $rows ? $rows[0] : null;
	}

	/**
	 * @param int $id Class
	 * @param string $status Only these, or '' for all
	 * @return object[] Sign-ups, oldest first
	 */
	public function signups($id, $status = 'signed')
	{
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_freeclass_signup WHERE fk_freeclass = ".((int) $id).($status !== '' ? " AND status = '".$this->db->escape($status)."'" : '')." ORDER BY datec ASC, rowid ASC");
		$out = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param int $id Sign-up
	 * @return object|null
	 */
	public function signupRow($id)
	{
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_freeclass_signup WHERE rowid = ".((int) $id));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * What the website lists: open classes still to come, and nothing personal.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function publicList()
	{
		$now = OnboardingService::now();
		$out = array();
		foreach ($this->where("c.status = 'open' AND c.session_start > '".$this->db->idate($now)."' AND c.session_start < '".$this->db->idate($now + self::LIST_DAYS * 86400)."'") as $c) {
			$left = max(0, (int) $c->seats - (int) $c->signed);
			$out[] = array(
				'id' => (int) $c->rowid,
				'kind' => $c->kind,
				'title' => (string) $c->title,
				'description' => (string) $c->description,
				'start' => gmdate('Y-m-d\TH:i:s\Z', $c->start),
				'when' => $this->when($c),
				'minutes' => (int) $c->minutes,
				'seats' => (int) $c->seats,
				'left' => $left,
				'full' => $left <= 0,
				'sponsor' => $c->kind == 'oca' ? self::OCA_CREDIT : '',
			);
		}
		return $out;
	}

	/**
	 * @param object $c Class
	 * @return string "Tuesday 1 December, 6:00 PM to 8:00 PM"
	 */
	public function when($c)
	{
		return OnboardingBooking::fmt($c->start).' to '.OnboardingBooking::fmt($c->start + 60 * (int) $c->minutes, 'g:i A');
	}

	/**
	 * @return string The website's page of classes
	 */
	public static function pageUrl()
	{
		return rtrim(getDolGlobalString('ONBOARDING_WEBSITE_URL', 'https://columbiagadgetworks.org'), '/').'/classes/';
	}

	/**
	 * @param object $s Sign-up
	 * @return string The signed "I can't come" link in their emails
	 */
	public function link($s)
	{
		$site = rtrim(getDolGlobalString('ONBOARDING_WEBSITE_URL'), '/');
		$base = $site !== '' && getDolGlobalString('ONBOARDING_LINKS_VIA_WEBSITE', '1') !== '0' ? $site.'/classes/link/' : dol_buildpath('/onboarding/public/freeclass.php', 2);
		return $base.'?s='.((int) $s->rowid).'&k='.$this->key($s);
	}

	/**
	 * @param object $s Sign-up
	 * @return string Signature of the link
	 */
	public function key($s)
	{
		return $this->booking->sign('freeclass:'.((int) $s->rowid).':'.OnboardingService::cleanEmail($s->email));
	}

	// ---------------------------------------------------------------- the calendar

	/**
	 * What a class puts on its calendar event.
	 *
	 * @param object $c Class
	 * @return array<string,mixed>
	 */
	public function eventFields($c)
	{
		$d = trim((string) $c->description);
		$d .= ($d !== '' ? "\n\n" : '').'Free. '.((int) $c->seats).' seats; sign up at '.self::pageUrl().'#class-'.((int) $c->rowid);
		if ($c->kind == 'oca') {
			$d .= "\n\n".self::OCA_CREDIT;
		}
		$f = array('summary' => (string) $c->title, 'description' => $d, 'location' => getDolGlobalString('ONBOARDING_CLASS_LOCATION', self::LOCATION));
		if ($c->kind == 'extra') {
			$f['start'] = OnboardingGcal::at($c->start);
			$f['end'] = OnboardingGcal::at($c->start + 60 * (int) $c->minutes);
		}
		return $f;
	}

	/**
	 * @param array<string,mixed> $f From eventFields(), or an event read back
	 * @return string What is compared to see whether the event was changed by hand
	 */
	private static function hash($f)
	{
		$start = isset($f['start']['dateTime']) ? strtotime($f['start']['dateTime']) : 0;
		$end = isset($f['end']['dateTime']) ? strtotime($f['end']['dateTime']) : 0;
		return md5(json_encode(array(trim((string) (isset($f['summary']) ? $f['summary'] : '')), trim(str_replace("\r", '', (string) (isset($f['description']) ? $f['description'] : ''))), $start, $end)));
	}

	/**
	 * Write a class onto its calendar event: that one date of the series, or its own event.
	 *
	 * @param object $c Class
	 * @return bool Written
	 */
	public function writeCalendar($c)
	{
		if (!OnboardingGcal::configured()) {
			$this->save((int) $c->rowid, array('gcal_error' => 'Google Calendar is not set up (setup page, Free classes).'));
			return false;
		}
		$f = $this->eventFields($c);
		if ($c->kind == 'extra' && empty($c->gcal_event_id)) {
			$r = $this->gcal->insert($f);
			if ($r && !empty($r['id'])) {
				$this->save((int) $c->rowid, array('gcal_event_id' => (string) $r['id']));
			}
		} else {
			$f['status'] = 'confirmed';
			$r = $this->gcal->patch((string) $c->gcal_event_id, $f);
		}
		if (!$r) {
			$this->save((int) $c->rowid, array('gcal_error' => dol_trunc($this->gcal->error, 250, 'right', 'UTF-8', 1)));
			return false;
		}
		if ($c->kind == 'oca') {
			// The series sets the time: keep the hash to what the event now says.
			$f['start'] = isset($r['start']) ? $r['start'] : null;
			$f['end'] = isset($r['end']) ? $r['end'] : null;
		}
		$this->save((int) $c->rowid, array('gcal_written' => self::hash($f), 'gcal_error' => null));
		return true;
	}

	/**
	 * Daily: put back any class whose calendar event was changed or removed by hand.
	 *
	 * @return string Summary
	 */
	public function checkCalendar()
	{
		if (!OnboardingGcal::configured()) {
			return 'free classes: calendar not set up';
		}
		$fixed = 0;
		$failed = 0;
		foreach ($this->where("c.status = 'open' AND c.session_start > '".$this->db->idate(OnboardingService::now())."'") as $c) {
			$e = !empty($c->gcal_event_id) ? $this->gcal->get((string) $c->gcal_event_id) : null;
			$want = $this->eventFields($c);
			if ($c->kind == 'oca' && $e) {
				$want['start'] = isset($e['start']) ? $e['start'] : null;
				$want['end'] = isset($e['end']) ? $e['end'] : null;
			}
			if ($e && (!isset($e['status']) || $e['status'] != 'cancelled') && self::hash($e) === self::hash($want)) {
				continue;
			}
			if ($c->kind == 'extra' && !$e) {
				$this->save((int) $c->rowid, array('gcal_event_id' => null));
				$c->gcal_event_id = '';
			}
			if ($this->writeCalendar($c)) {
				$fixed++;
			} else {
				$failed++;
			}
		}
		return 'free classes: '.$fixed.' calendar events put back'.($failed ? ', '.$failed.' failed' : '');
	}

	// ---------------------------------------------------------------- making classes

	/**
	 * @param int $id Class
	 * @param array<string,mixed> $fields Column => value (null clears it)
	 * @return void
	 */
	private function save($id, $fields)
	{
		$set = array();
		foreach ($fields as $k => $v) {
			$set[] = $k.' = '.($v === null ? 'NULL' : (is_int($v) ? $v : "'".$this->db->escape((string) $v)."'"));
		}
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_freeclass SET ".implode(', ', $set)." WHERE rowid = ".((int) $id));
	}

	/**
	 * The coming dates of the monthly series that have no class yet.
	 *
	 * @param int $keep A class whose date stays in the list
	 * @return array<int,array{id:string,start:int,minutes:int,summary:string}>|null Null when the calendar cannot be read
	 */
	public function freeDates($keep = 0)
	{
		$series = getDolGlobalString('ONBOARDING_GCAL_SERIES');
		if ($series === '' || !OnboardingGcal::configured()) {
			return null;
		}
		$dates = $this->gcal->dates($series);
		if ($dates === null) {
			return null;
		}
		$used = array();
		foreach ($this->where("c.kind = 'oca' AND c.status <> 'cancelled' AND c.rowid <> ".((int) $keep)) as $c) {
			$used[(string) $c->gcal_event_id] = true;
		}
		return array_values(array_filter($dates, function ($d) use ($used) {
			return !isset($used[$d['id']]);
		}));
	}

	/**
	 * Make or change a class, then write it onto the calendar.
	 *
	 * @param array<string,mixed> $in kind, date (oca: the series date's id), day and time (extra: Y-m-d, H:i), minutes, title, description, seats, instructor
	 * @param User $by Who
	 * @param int $id Class to change, 0 for a new one
	 * @return array{ok:bool,id?:int,error?:string,warning?:string}
	 */
	public function saveClass($in, $by, $id = 0)
	{
		global $conf;
		$now = OnboardingService::now();
		$old = $id ? $this->fetch($id) : null;
		if ($id && (!$old || $old->status != 'open')) {
			return array('ok' => false, 'error' => 'Only an open class can be changed.');
		}
		$kind = $old ? $old->kind : (isset($in['kind']) ? (string) $in['kind'] : '');
		if (!isset(self::KINDS[$kind])) {
			return array('ok' => false, 'error' => 'Choose a monthly OCA class or an extra class.');
		}
		$title = trim(dol_trunc(dol_string_nohtmltag((string) (isset($in['title']) ? $in['title'] : '')), 200, 'right', 'UTF-8', 1));
		$desc = trim(dol_trunc(str_replace("\r", '', dol_string_nohtmltag((string) (isset($in['description']) ? $in['description'] : ''), 0)), 4000, 'right', 'UTF-8', 1));
		$seats = (int) (isset($in['seats']) ? $in['seats'] : 0);
		$instructor = (int) (isset($in['instructor']) ? $in['instructor'] : 0);
		if (strlen($title) < 3) {
			return array('ok' => false, 'error' => 'Give the class a name.');
		}
		if ($seats < 1 || $seats > self::MAX_SEATS) {
			return array('ok' => false, 'error' => 'The class size must be between 1 and '.self::MAX_SEATS.'.');
		}
		$fields = array('title' => $title, 'description' => $desc, 'seats' => $seats, 'fk_instructor' => $instructor > 0 ? $instructor : null);
		if ($kind == 'oca') {
			if (!$old) {
				$dates = $this->freeDates();
				if ($dates === null) {
					return array('ok' => false, 'error' => 'The monthly class dates could not be read from the Google Calendar: '.($this->gcal->error !== '' ? $this->gcal->error : 'set up Google Calendar and the monthly series on the setup page (Free classes).'));
				}
				$pick = null;
				foreach ($dates as $d) {
					if ($d['id'] === (string) (isset($in['date']) ? $in['date'] : '')) {
						$pick = $d;
					}
				}
				if (!$pick) {
					return array('ok' => false, 'error' => 'Choose one of the monthly class dates.');
				}
				$fields += array('session_start' => $this->db->idate($pick['start']), 'minutes' => max(15, $pick['minutes']), 'gcal_event_id' => $pick['id']);
			}
		} else {
			$start = OnboardingBooking::at((string) (isset($in['day']) ? $in['day'] : ''), (string) (isset($in['time']) ? $in['time'] : ''));
			$minutes = (int) (isset($in['minutes']) ? $in['minutes'] : 0);
			if (!$start || $start <= $now) {
				return array('ok' => false, 'error' => 'Choose a date and time still to come.');
			}
			if ($minutes < 15 || $minutes > 720) {
				return array('ok' => false, 'error' => 'How long it runs must be between 15 minutes and 12 hours.');
			}
			$fields += array('session_start' => $this->db->idate($start), 'minutes' => $minutes);
		}
		if ($old) {
			$this->save($id, $fields);
		} else {
			$cols = array('entity' => (int) $conf->entity, 'kind' => $kind, 'status' => 'open', 'fk_user' => (int) $by->id, 'datec' => $this->db->idate($now)) + $fields;
			$vals = array();
			foreach ($cols as $v) {
				$vals[] = $v === null ? 'NULL' : (is_int($v) ? $v : "'".$this->db->escape((string) $v)."'");
			}
			if (!$this->db->query("INSERT INTO ".$this->db->prefix()."onboarding_freeclass (".implode(', ', array_keys($cols)).") VALUES (".implode(', ', $vals).")")) {
				return array('ok' => false, 'error' => 'The class could not be saved: '.$this->db->lasterror());
			}
			$id = (int) $this->db->last_insert_id($this->db->prefix().'onboarding_freeclass');
		}
		$c = $this->fetch($id);
		$moved = $old && $old->start != $c->start;
		$out = array('ok' => true, 'id' => $id);
		if (!$this->writeCalendar($c)) {
			$out['warning'] = 'Saved, but the calendar was not updated: '.$this->fetch($id)->gcal_error.' The daily job tries again.';
		}
		if ($moved) {
			foreach ($this->signups($id) as $s) {
				$this->svc->mail($s->email, 'New time: '.$c->title, "Hi ".($s->firstname !== '' ? $s->firstname : 'there').",\n\n".$c->title." has moved. It is now on ".$this->when($c).", at ".getDolGlobalString('ONBOARDING_CLASS_LOCATION', self::LOCATION).".\n\nYour seat is kept. If you cannot come now, let us know so someone else can have it:\n".$this->link($s)."\n\n".$this->org());
			}
		}
		return $out;
	}

	/**
	 * Cancel a class: off the calendar, and everyone signed up is emailed.
	 *
	 * @param int $id Class
	 * @param string $why Said in the email, may be empty
	 * @return string Message for the screen, starting with "Error" on failure
	 */
	public function cancelClass($id, $why = '')
	{
		$c = $this->fetch($id);
		if (!$c || $c->status != 'open') {
			return 'Error: only an open class can be cancelled.';
		}
		$this->save((int) $c->rowid, array('status' => 'cancelled'));
		$off = true;
		if (!empty($c->gcal_event_id) && OnboardingGcal::configured()) {
			$off = $this->gcal->delete((string) $c->gcal_event_id);
		}
		$why = trim(dol_trunc(dol_string_nohtmltag((string) $why, 0), 500, 'right', 'UTF-8', 1));
		$n = 0;
		foreach ($this->signups((int) $c->rowid) as $s) {
			$n++;
			$this->svc->mail($s->email, 'Cancelled: '.$c->title, "Hi ".($s->firstname !== '' ? $s->firstname : 'there').",\n\nSorry: ".$c->title." on ".$this->when($c)." is cancelled.".($why !== '' ? "\n\n".$why : '')."\n\nOther free classes: ".self::pageUrl()."\n\n".$this->org());
		}
		return $c->title.' cancelled; '.$n.' people emailed'.($off ? ', and it is off the calendar.' : '. It could not be taken off the calendar: '.$this->gcal->error.' Remove it by hand.');
	}

	/**
	 * @param int $id Sign-up
	 * @param bool $attended Came or not
	 * @return void
	 */
	public function setAttended($id, $attended)
	{
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_freeclass_signup SET attended = ".($attended ? 1 : 0)." WHERE rowid = ".((int) $id));
	}

	// ---------------------------------------------------------------- signing up

	/**
	 * Someone signs up on the website.
	 *
	 * @param array<string,mixed> $in id (class), name, email, updates, ip
	 * @return array<string,mixed> ok, result (signed or already), left; or ok false, error
	 */
	public function signUp($in)
	{
		global $conf;
		$email = OnboardingService::cleanEmail(isset($in['email']) ? $in['email'] : '');
		$name = trim(preg_replace('/\s+/', ' ', dol_string_nohtmltag((string) (isset($in['name']) ? $in['name'] : ''))));
		$updates = !empty($in['updates']);
		if (!isValidEmail($email)) {
			return array('ok' => false, 'error' => 'invalid', 'field' => 'email', 'http' => 400);
		}
		if (dol_strlen($name) < 2 || dol_strlen($name) > 100) {
			return array('ok' => false, 'error' => 'invalid', 'field' => 'name', 'http' => 400);
		}
		$pos = strrpos($name, ' ');
		$first = dol_trunc($pos === false ? $name : substr($name, 0, $pos), 50, 'right', 'UTF-8', 1);
		$last = dol_trunc($pos === false ? '' : substr($name, $pos + 1), 50, 'right', 'UTF-8', 1);
		$id = (int) (isset($in['id']) ? $in['id'] : 0);
		$now = OnboardingService::now();
		$p = $this->db->prefix();

		// One seat at a time: the class row is locked while the seats are counted.
		$this->db->begin();
		$res = $this->db->query("SELECT rowid, seats, status, session_start FROM ".$p."onboarding_freeclass WHERE rowid = ".$id." AND entity = ".((int) $conf->entity)." FOR UPDATE");
		$c = $res ? $this->db->fetch_object($res) : null;
		if (!$c || $c->status != 'open' || $this->db->jdate($c->session_start) <= $now) {
			$this->db->rollback();
			return array('ok' => false, 'error' => 'closed', 'http' => 409);
		}
		$res = $this->db->query("SELECT rowid, status FROM ".$p."onboarding_freeclass_signup WHERE fk_freeclass = ".$id." AND email = '".$this->db->escape($email)."'");
		$mine = $res ? $this->db->fetch_object($res) : null;
		$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$p."onboarding_freeclass_signup WHERE fk_freeclass = ".$id." AND status = 'signed'");
		$taken = $res ? (int) $this->db->fetch_object($res)->n : 0;
		$result = 'signed';
		if ($mine && $mine->status == 'signed') {
			$result = 'already';
			$sid = (int) $mine->rowid;
		} elseif ($taken >= (int) $c->seats) {
			$this->db->rollback();
			return array('ok' => false, 'error' => 'full', 'http' => 409);
		} elseif ($mine) {
			$sid = (int) $mine->rowid;
			$this->db->query("UPDATE ".$p."onboarding_freeclass_signup SET status = 'signed', firstname = '".$this->db->escape($first)."', lastname = '".$this->db->escape($last)."', updates = ".($updates ? 1 : 0).", datec = '".$this->db->idate($now)."', date_cancel = NULL WHERE rowid = ".$sid);
			$taken++;
		} else {
			$ok = $this->db->query("INSERT INTO ".$p."onboarding_freeclass_signup (entity, fk_freeclass, email, firstname, lastname, updates, status, ip, datec) VALUES (".((int) $conf->entity).", ".$id.", '".$this->db->escape($email)."', '".$this->db->escape($first)."', '".$this->db->escape($last)."', ".($updates ? 1 : 0).", 'signed', '".$this->db->escape(substr((string) (isset($in['ip']) ? $in['ip'] : ''), 0, 64))."', '".$this->db->idate($now)."')");
			if (!$ok) {
				$this->db->rollback();
				return array('ok' => false, 'error' => 'server', 'http' => 500);
			}
			$sid = (int) $this->db->last_insert_id($p.'onboarding_freeclass_signup');
			$taken++;
		}
		$this->db->commit();

		$class = $this->fetch($id);
		if ($result == 'signed') {
			$this->linkPerson($sid, $email, $name, $updates, $class);
		}
		$s = $this->signupRow($sid);
		$this->svc->mail($email, ($result == 'already' ? 'You are signed up: ' : 'Signed up: ').$class->title, $this->confirmation($class, $s));
		return array('ok' => true, 'result' => $result, 'left' => max(0, (int) $class->seats - $taken));
	}

	/**
	 * Match a sign-up to the member card or contact with that email, or make a
	 * contact for someone new, and put them on email updates when they asked.
	 *
	 * @param int $sid Sign-up
	 * @param string $email Email
	 * @param string $name Name as typed
	 * @param bool $updates Asked for email updates
	 * @param object $class Class
	 * @return void
	 */
	private function linkPerson($sid, $email, $name, $updates, $class)
	{
		$who = $this->booking->person($email);
		$memberId = $who['member'] ? (int) $who['member']->rowid : 0;
		if ($updates) {
			$this->svc->subscribe(array('email' => $email, 'name' => $name, 'source' => 'a free class sign-up', 'page' => $class->title));
		} elseif (!$memberId && !$who['contact']) {
			$pos = strrpos($name, ' ');
			$contact = new Contact($this->db);
			$contact->firstname = dol_trunc($pos === false ? '' : substr($name, 0, $pos), 50, 'right', 'UTF-8', 1);
			$contact->lastname = dol_trunc($pos === false ? $name : substr($name, $pos + 1), 50, 'right', 'UTF-8', 1);
			$contact->email = $email;
			$contact->statut = 1;
			$contact->status = 1;
			$contact->note_private = 'Signed up for the free class '.$class->title.' ('.dol_print_date($class->start, 'day').') on the website.';
			if ($contact->create($this->svc->actor()) <= 0) {
				dol_syslog('Onboarding: contact create failed: '.$contact->error, LOG_ERR);
			}
		}
		$contactId = (int) $this->svc->contactByEmail($email);
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_freeclass_signup SET fk_adherent = ".($memberId ?: 'NULL').", fk_socpeople = ".($contactId ?: 'NULL')." WHERE rowid = ".((int) $sid));
	}

	/**
	 * @param object $c Class
	 * @param object $s Sign-up
	 * @return string The confirmation email
	 */
	private function confirmation($c, $s)
	{
		$b = "Hi ".($s->firstname !== '' ? $s->firstname : 'there').",\n\nYou have a seat at ".$c->title.".\n\n";
		$b .= "When: ".$this->when($c)."\nWhere: ".getDolGlobalString('ONBOARDING_CLASS_LOCATION', self::LOCATION)."\nCost: free\n";
		if (trim((string) $c->description) !== '') {
			$b .= "\n".trim((string) $c->description)."\n";
		}
		$b .= "\nWe will remind you the day before. If you cannot come, please let us know so someone else can have your seat:\n".$this->link($s)."\n";
		if ($c->kind == 'oca') {
			$b .= "\n".self::OCA_CREDIT."\n";
		}
		return $b."\n".$this->org();
	}

	/**
	 * @return string The sign-off
	 */
	private function org()
	{
		return getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'Columbia Gadget Works');
	}

	/**
	 * Someone used the link in their email to give up their seat.
	 *
	 * @param int $sid Sign-up
	 * @return string done, already, closed
	 */
	public function cancelSignup($sid)
	{
		$s = $this->signupRow($sid);
		$c = $s ? $this->fetch((int) $s->fk_freeclass) : null;
		if (!$s || !$c || $c->status != 'open' || $c->start <= OnboardingService::now()) {
			return 'closed';
		}
		if ($s->status != 'signed') {
			return 'already';
		}
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_freeclass_signup SET status = 'cancelled', date_cancel = '".$this->db->idate(OnboardingService::now())."' WHERE rowid = ".((int) $sid)." AND status = 'signed'");
		return 'done';
	}

	// ---------------------------------------------------------------- the job

	/**
	 * Every 15 minutes: the day-before reminders, the list for the instructor and
	 * the person who made the class, and classes that are over.
	 *
	 * @param int $now Unix time
	 * @return string Summary
	 */
	public function run($now = 0)
	{
		$now = $now ?: OnboardingService::now();
		$reminded = 0;
		$done = 0;
		foreach ($this->where("c.status = 'open'") as $c) {
			if ($c->start + 60 * (int) $c->minutes < $now) {
				$this->save((int) $c->rowid, array('status' => 'done'));
				$done++;
				continue;
			}
			if ($c->start > $now && $c->start - $now <= self::REMIND_HOURS * 3600 && !(int) $c->reminders_sent) {
				$this->save((int) $c->rowid, array('reminders_sent' => 1, 'roster_sent' => 1));
				$people = $this->signups((int) $c->rowid);
				foreach ($people as $s) {
					$this->svc->mail($s->email, 'Tomorrow: '.$c->title, "Hi ".($s->firstname !== '' ? $s->firstname : 'there').",\n\nA reminder that ".$c->title." is on ".$this->when($c).", at ".getDolGlobalString('ONBOARDING_CLASS_LOCATION', self::LOCATION).".\n\nIf you cannot come, please let us know so someone else can have your seat:\n".$this->link($s)."\n\n".$this->org());
					$reminded++;
				}
				$to = $this->rosterTo($c);
				if ($to) {
					$list = '';
					foreach ($people as $i => $s) {
						$list .= ($i + 1).'. '.trim($s->firstname.' '.$s->lastname).' <'.$s->email.'>'.($s->fk_adherent ? ' (member)' : '')."\n";
					}
					$this->svc->mail(implode(',', $to), 'Class list: '.$c->title, $c->title." is on ".$this->when($c).".\n\n".count($people)." of ".((int) $c->seats)." seats are taken:\n\n".($list !== '' ? $list : "(nobody yet)\n")."\nAfterwards, tick who came on the class's page in Dolibarr (Members, Free classes).");
				}
			}
		}
		return 'free classes: '.$reminded.' reminders, '.$done.' done';
	}

	/**
	 * @param object $c Class
	 * @return string[] The instructor and whoever made the class
	 */
	private function rosterTo($c)
	{
		$to = array();
		if ((int) $c->fk_instructor > 0) {
			$a = new Adherent($this->db);
			if ($a->fetch((int) $c->fk_instructor) > 0 && isValidEmail($a->email)) {
				$to[] = OnboardingService::cleanEmail($a->email);
			}
		}
		if ((int) $c->fk_user > 0) {
			$u = new User($this->db);
			if ($u->fetch((int) $c->fk_user) > 0 && isValidEmail($u->email)) {
				$to[] = OnboardingService::cleanEmail($u->email);
			}
		}
		return array_values(array_unique($to));
	}
}
