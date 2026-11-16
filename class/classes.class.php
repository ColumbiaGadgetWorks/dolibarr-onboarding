<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/booking.class.php');

/**
 * Group classes: a trainer teaches several people at once, who join with a
 * code. Reached through OnboardingBooking::classes().
 */
class OnboardingClasses
{
	/** @var OnboardingBooking */
	public $bk;
	/** @var DoliDB */
	public $db;
	/** @var OnboardingService */
	public $svc;

	/**
	 * @param OnboardingBooking $bk The booking service this is part of
	 */
	public function __construct($bk)
	{
		$this->bk = $bk;
		$this->db = $bk->db;
		$this->svc = $bk->svc;
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
		$tool = $this->bk->schedule()->tool(isset($in['tool']) ? (int) $in['tool'] : 0);
		$trainer = isset($in['trainer']) ? (int) $in['trainer'] : 0;
		$start = OnboardingBooking::at(isset($in['day']) ? (string) $in['day'] : '', isset($in['time']) ? (string) $in['time'] : '');
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
				$rand .= substr(OnboardingBooking::CODE_CHARS, random_int(0, strlen(OnboardingBooking::CODE_CHARS) - 1), 1);
			}
			$code = ($prefix !== '' ? $prefix : 'CLASS').'-'.$rand;
			$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."onboarding_class WHERE code = '".$code."'");
			if (!$res || !$this->db->fetch_object($res)) {
				break;
			}
		}
		$mode = isset($in['mode']) && $in['mode'] == 'auto' ? 'auto' : 'confirm';
		$id = OnboardingSql::insert($this->db, 'onboarding_class', array(
			'entity' => (int) $conf->entity,
			'code' => $code,
			'fk_tool' => (int) $tool->rowid,
			'fk_trainer' => (int) $trainer,
			'session_start' => $this->db->idate($start),
			'minutes' => max(15, (int) (isset($in['minutes']) && $in['minutes'] ? $in['minutes'] : $tool->minutes)),
			'seats' => max(1, (int) (isset($in['seats']) && $in['seats'] ? $in['seats'] : 10)),
			'price' => 0,
			'mode' => $mode,
			'status' => 'open',
			'note' => dol_trunc(isset($in['note']) ? (string) $in['note'] : '', 255, 'right', 'UTF-8', 1),
			'fk_user' => (int) $by->id,
			'datec' => $this->db->idate(OnboardingService::now()),
		));
		if (!$id) {
			return array('ok' => false, 'error' => $this->db->lasterror());
		}
		$c = $this->klass($id);
		$v = $this->classVars($c);
		$this->bk->mailTpl('class_created', array_unique(array_filter(array((string) $c->trainer_email, (string) $by->email))), $v);
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
		if (!$c || !OnboardingBooking::classOpen($c, OnboardingService::now())) {
			return array('ok' => false, 'error' => 'closed', 'http' => 404);
		}
		return array('ok' => true, 'code' => $c->code, 'tool' => $c->tool, 'zone' => $c->zone, 'trainer' => $c->trainer_first, 'when' => OnboardingBooking::fmt($this->db->jdate($c->session_start)), 'seats_left' => max(0, (int) $c->seats - (int) $c->attendees), 'wiki' => (string) $c->wiki_url);
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
		if (!$c || !OnboardingBooking::classOpen($c, $now)) {
			return array('ok' => false, 'error' => 'closed', 'http' => 404);
		}
		$email = OnboardingService::cleanEmail(isset($in['email']) ? $in['email'] : '');
		if (!isValidEmail($email)) {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		$mine = $this->bk->bookingsWhere("fk_class = ".((int) $c->rowid)." AND email = '".$this->db->escape($email)."' AND status IN ('confirmed', 'trained')");
		if ($mine) {
			return array('ok' => true, 'done' => true, 'trained' => $mine[0]->status == 'trained');
		}
		if ((int) $c->attendees >= (int) $c->seats) {
			return array('ok' => false, 'error' => 'full', 'http' => 409);
		}
		$p = $this->bk->person($email);
		if ($p['kind'] == 'new') {
			$r = $this->bk->newTrainee($in, 'Created at a group class sign-in ('.$c->code.').');
			if (!$r['ok']) {
				return $r;
			}
			$p = $this->bk->person($email);
		}
		if ($p['needs']) {
			$app = $this->bk->applicantFor($p);
			if (!$app) {
				return array('ok' => false, 'error' => 'server', 'http' => 500);
			}
			return array('ok' => true, 'done' => false, 'needs' => $p['needs'], 'token' => $this->svc->signup()->issueToken($app, false));
		}
		$token = bin2hex(random_bytes(24));
		$id = OnboardingSql::insert($this->db, 'onboarding_booking', array(
			'entity' => (int) $conf->entity,
			'token' => $token,
			'fk_tool' => (int) $c->fk_tool,
			'fk_class' => (int) $c->rowid,
			'fk_trainer' => (int) $c->fk_trainer,
			'fk_adherent' => $p['member'] ? (int) $p['member']->rowid : null,
			'fk_socpeople' => $p['contact'] ? (int) $p['contact'] : null,
			'fk_applicant' => $p['applicant'] ? (int) $p['applicant']->rowid : null,
			'email' => $email,
			'firstname' => dol_trunc($p['firstname'], 100, 'right', 'UTF-8', 1),
			'lastname' => dol_trunc($p['lastname'], 100, 'right', 'UTF-8', 1),
			'session_start' => (string) $c->session_start,
			'minutes' => (int) $c->minutes,
			'price' => 0,
			'price_type' => 'free',
			'status' => 'confirmed',
			'confirmed_at' => $this->db->idate($now),
			'source' => 'class',
			'ip' => substr((string) (isset($in['ip']) ? $in['ip'] : ''), 0, 64),
			'datec' => $this->db->idate($now),
		));
		if (!$id) {
			return array('ok' => false, 'error' => 'server', 'http' => 500);
		}
		$this->bk->saveBooking($id, array('ref' => 'TR-'.sprintf('%04d', $id)));
		$trained = false;
		if ($c->mode == 'auto') {
			$trained = $this->bk->answer($this->bk->booking($id), 'trained', '', (int) $c->fk_user)['ok'];
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
		$p = $this->bk->person($email);
		if ($p['kind'] == 'new') {
			$r = $this->bk->newTrainee($in, 'Added to group class '.$c->code.' by '.$by->login.'.');
			if (!$r['ok']) {
				return 'A first and last name are needed for someone new.';
			}
			$p = $this->bk->person($email);
		}
		if ($p['needs'] && !empty($in['paper_waiver'])) {
			$this->paperWaiver($p, in_array('agreement', $p['needs'], true));
			$p = $this->bk->person($email);
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
		$today = dol_mktime(12, 0, 0, (int) OnboardingBooking::fmt(OnboardingService::now(), 'n'), (int) OnboardingBooking::fmt(OnboardingService::now(), 'j'), (int) OnboardingBooking::fmt(OnboardingService::now(), 'Y'));
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
		foreach ($this->bk->bookingsWhere("fk_class = ".((int) $c->rowid)." AND status = 'confirmed'") as $b) {
			if (in_array((int) $b->rowid, $trained, true)) {
				if ($this->bk->answer($b, 'trained', '', $userId ? $userId : (int) $c->fk_user)['ok']) {
					$yes++;
				}
			} else {
				$this->bk->saveBooking((int) $b->rowid, array('status' => 'cancelled', 'answer' => 'left', 'answered_at' => $this->db->idate(OnboardingService::now())), "status = 'confirmed'");
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
	public function runClasses($now)
	{
		$sent = 0;
		foreach ($this->classesWhere("c.status = 'open'") as $c) {
			$end = $this->db->jdate($c->session_start) + 60 * (int) $c->minutes;
			if ($now < $end) {
				continue;
			}
			$waiting = $this->bk->bookingsWhere("fk_class = ".((int) $c->rowid)." AND status = 'confirmed'");
			if (!$waiting) {
				if ($now > $end + (int) round(OnboardingBooking::num('TRAINING_CLASS_OPEN_AFTER', 180) * 60)) {
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
				$this->bk->mailTpl('class_roster', $c->trainer_email, $v);
			} else {
				$this->bk->mailTpl('staff_class_unanswered', $this->bk->staffEmails(), $v);
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
		foreach ($this->bk->bookingsWhere("fk_class = ".((int) $c->rowid)." AND status IN ('confirmed', 'trained')") as $b) {
			$names[] = '- '.OnboardingBooking::shortName($b->firstname, $b->lastname).($b->status == 'trained' ? ' (trained)' : '');
		}
		return array(
			'tool' => (string) $c->tool,
			'zone' => (string) $c->zone,
			'when' => OnboardingBooking::fmt($this->db->jdate($c->session_start)),
			'code' => (string) $c->code,
			'class_url' => $this->classUrl($c),
			'trainer' => (string) $c->trainer_first,
			'seats' => (string) $c->seats,
			'roster' => $names ? implode("\n", $names) : '(nobody yet)',
			'link_class' => $this->bk->classLink($c),
			'card' => dol_buildpath('/onboarding/class.php', 2).'?id='.((int) $c->rowid),
			'org' => getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'the makerspace'),
		);
	}
}
