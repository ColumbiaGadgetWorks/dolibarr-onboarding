<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Member votes: the bylaw questions and board seats put to full members at the
 * annual meeting (bylaws section 4, policies/how-we-decide.md).
 *
 * Nobody logs in. The Secretary and the President work the vote from buttons in
 * emails, each a link signed for them that only works while they hold the
 * secretary or president tag; members get a link signed for them to run for a
 * seat and, once voting opens, to their ballot. All of them open
 * public/vote.php, shown inside the website at /voting/link/.
 *
 *   draft    made 60 days before the meeting; the Secretary adds the bylaw questions
 *   noticed  Send notice (14 to 60 days before): every future voter is emailed the
 *            questions and a Run for a board seat button
 *   open     Open voting, at the meeting: the roll is taken, ballots go out, and
 *            voting runs 8 days
 *   closed   counted, emailed to the Secretary and the President, posted on GitHub
 *
 * The ballot is secret: there is no table of ballots. Casting marks the member as
 * voted (with a date, not a time) and adds one to each chosen answer, in one
 * transaction, so nothing links a member to how they voted and nobody votes twice.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
dol_include_once('/onboarding/class/booking.class.php');
dol_include_once('/onboarding/class/purchase.class.php');

/**
 * Member votes.
 */
class OnboardingVote
{
	const DEFAULT_SEATS = 'President,Vice President,Secretary,Treasurer';
	const STATUS_LABELS = array('draft' => 'Being set up', 'noticed' => 'Notice sent', 'open' => 'Voting open', 'closed' => 'Closed');

	/** @var DoliDB */
	public $db;
	/** @var OnboardingService */
	public $svc;
	/** @var OnboardingBooking */
	public $bk;
	/** @var OnboardingPurchase */
	public $roles;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->bk = new OnboardingBooking($db);
		$this->svc = $this->bk->svc;
		$this->roles = new OnboardingPurchase($db, $this->svc);
	}

	// ---------------------------------------------------------------- settings and dates

	/**
	 * @return int Days voting stays open
	 */
	public static function days()
	{
		return max(7, (int) OnboardingBooking::num('VOTE_DAYS', 8));
	}

	/**
	 * @return string[] The board seats, in ballot order
	 */
	public static function seats()
	{
		return array_values(array_filter(array_map('trim', explode(',', getDolGlobalString('ONBOARDING_VOTE_SEATS', self::DEFAULT_SEATS))), 'strlen'));
	}

	/**
	 * @return int[] Member types whose members are full members (vote and may run)
	 */
	public static function fullTypes()
	{
		$set = getDolGlobalString('ONBOARDING_VOTE_TYPES');
		$ids = $set !== '' ? explode(',', $set) : array(getDolGlobalInt('ONBOARDING_TYPE_STANDARD'), getDolGlobalInt('ONBOARDING_TYPE_SUPPORTER'));
		return array_values(array_filter(array_map('intval', $ids)));
	}

	/**
	 * @param int $year Year
	 * @return int The annual meeting that year: the second Thursday of April, at the meeting time
	 */
	public static function annualMeeting($year)
	{
		$d = new DateTime(((int) $year).'-04-01', OnboardingBooking::tz());
		while ($d->format('N') != 4) {
			$d->modify('+1 day');
		}
		$d->modify('+7 days');
		$time = getDolGlobalString('ONBOARDING_VOTE_MEETING_TIME', '18:00');
		return OnboardingBooking::at($d->format('Y-m-d'), preg_match('/^\d{1,2}:\d{2}$/', $time) ? $time : '18:00');
	}

	/**
	 * @param int $ts Timestamp
	 * @return string Y-m-d on the shop's clock
	 */
	public static function day($ts)
	{
		return OnboardingBooking::fmt($ts, 'Y-m-d');
	}

	/**
	 * @param int $from Timestamp
	 * @param int $to Timestamp
	 * @return int Calendar days between the two on the shop's clock
	 */
	public static function daysBetween($from, $to)
	{
		$a = new DateTime(self::day($from), OnboardingBooking::tz());
		$b = new DateTime(self::day($to), OnboardingBooking::tz());
		return (int) $a->diff($b)->format('%r%a');
	}

	/**
	 * @param int $meeting Meeting time
	 * @param int $days Days from the meeting, may be negative
	 * @param string $time H:i
	 * @return int That day at that time on the shop's clock
	 */
	public static function around($meeting, $days, $time)
	{
		$d = new DateTime(self::day($meeting), OnboardingBooking::tz());
		$d->modify(($days >= 0 ? '+' : '').$days.' days');
		return OnboardingBooking::at($d->format('Y-m-d'), $time);
	}

	/**
	 * @param int $voters Size of the roll
	 * @return int Ballots needed for the vote to count: 10%, and at least 5
	 */
	public static function quorum($voters)
	{
		return max(5, (int) ceil($voters * 0.10));
	}

	// ---------------------------------------------------------------- reading

	/**
	 * @param int $id Vote id
	 * @return object|null
	 */
	public function get($id)
	{
		global $conf;
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_vote WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));
		$v = $res ? $this->db->fetch_object($res) : null;
		if ($v) {
			$v->meeting = $this->db->jdate($v->meeting_at);
			$v->opened = $v->opened_at ? $this->db->jdate($v->opened_at) : 0;
			$v->closes = $v->closes_at ? $this->db->jdate($v->closes_at) : 0;
			$v->steps = $v->done ? (array) json_decode($v->done, true) : array();
		}
		return $v ?: null;
	}

	/**
	 * @return object[] Every vote, newest first
	 */
	public function all()
	{
		global $conf;
		$out = array();
		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."onboarding_vote WHERE entity = ".((int) $conf->entity)." ORDER BY meeting_at DESC");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $this->get((int) $o->rowid);
		}
		return $out;
	}

	/**
	 * @param object $v Vote
	 * @return object[] Its questions, bylaw questions first, then the seats
	 */
	public function questions($v)
	{
		$out = array();
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_vote_question WHERE fk_vote = ".((int) $v->rowid)." ORDER BY kind = 'seat', position ASC, rowid ASC");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param object $v Vote
	 * @param int $questionId Only this seat, or 0 for all
	 * @return object[] Candidates, with the member's email
	 */
	public function candidates($v, $questionId = 0)
	{
		$out = array();
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT c.*, a.email FROM ".$p."onboarding_vote_candidate AS c LEFT JOIN ".$p."adherent AS a ON a.rowid = c.fk_adherent WHERE c.fk_vote = ".((int) $v->rowid).($questionId ? " AND c.fk_question = ".((int) $questionId) : '')." ORDER BY c.name ASC");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param int $questionId Question
	 * @return object[] Its answers on the ballot, with totals
	 */
	public function options($questionId)
	{
		$out = array();
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_vote_option WHERE fk_question = ".((int) $questionId)." ORDER BY position ASC, rowid ASC");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param int $id Member id
	 * @return object|null Member row with member_code, birth and the date they joined
	 */
	public function member($id)
	{
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT a.rowid, a.firstname, a.lastname, a.email, a.statut, a.fk_adherent_type, a.birth, e.member_code, ".$this->sinceSql()." AS since FROM ".$p."adherent AS a LEFT JOIN ".$p."adherent_extrafields AS e ON e.fk_object = a.rowid WHERE a.rowid = ".((int) $id)." AND a.entity IN (".getEntity('adherent').")");
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @return string SQL for when member a joined: their first subscription, else when validated, else created
	 */
	private function sinceSql()
	{
		return "COALESCE((SELECT MIN(s.dateadh) FROM ".$this->db->prefix()."subscription AS s WHERE s.fk_adherent = a.rowid), a.datevalid, a.datec)";
	}

	/**
	 * Full members in good standing who joined at least 30 days before $on: the
	 * people who can vote if voting opens then (bylaws 4.4).
	 *
	 * @param int $on When voting opens
	 * @return object[] rowid, firstname, lastname, email, member_code
	 */
	public function voters($on, $v = null)
	{
		if ($v && !empty($v->test)) {
			// A practice vote goes to the board only.
			return array_values($this->roles->roleMembers(OnboardingPurchase::BOARD_ROLES));
		}
		$types = self::fullTypes();
		if (!$types) {
			return array();
		}
		$p = $this->db->prefix();
		$cut = $this->db->idate($on - 30 * 86400);
		$res = $this->db->query("SELECT a.rowid, a.firstname, a.lastname, a.email, e.member_code FROM ".$p."adherent AS a LEFT JOIN ".$p."adherent_extrafields AS e ON e.fk_object = a.rowid WHERE a.entity IN (".getEntity('adherent').") AND a.statut = ".((int) Adherent::STATUS_VALIDATED)." AND a.fk_adherent_type IN (".implode(',', $types).") AND a.email IS NOT NULL AND a.email <> '' AND ".$this->sinceSql()." <= '".$this->db->escape($cut)."' ORDER BY a.rowid ASC");
		$out = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param object $m Member row
	 * @return string The two digits used to ask for a lost link: from the member code, else the member id
	 */
	public static function pin($m)
	{
		$d = OnboardingPurchase::codeDigits(isset($m->member_code) ? $m->member_code : '');
		return $d !== '' ? $d : str_pad((string) (((int) $m->rowid) % 100), 2, '0', STR_PAD_LEFT);
	}

	/**
	 * @return array<string,object[]> Who holds the president and secretary tags now
	 */
	public function officers()
	{
		$out = array('president' => array(), 'secretary' => array());
		foreach ($this->roles->roleMembers(array('president', 'secretary')) as $m) {
			foreach ($m->roles as $r) {
				if (isset($out[$r])) {
					$out[$r][] = $m;
				}
			}
		}
		return $out;
	}

	/**
	 * @param int $memberId Member
	 * @return string president, secretary, or '' when they hold neither tag
	 */
	public function officerRole($memberId)
	{
		foreach ($this->officers() as $role => $people) {
			foreach ($people as $m) {
				if ((int) $m->rowid == (int) $memberId) {
					return $role;
				}
			}
		}
		return '';
	}

	// ---------------------------------------------------------------- links

	/**
	 * @param object $v Vote
	 * @param int $memberId Member
	 * @param string $who panel (an officer), run (running for a seat) or ballot
	 * @return string Signed link
	 */
	public function link($v, $memberId, $who)
	{
		$site = rtrim(getDolGlobalString('ONBOARDING_WEBSITE_URL'), '/');
		$base = $site !== '' && getDolGlobalString('ONBOARDING_LINKS_VIA_WEBSITE', '1') !== '0' ? $site.'/voting/link/' : dol_buildpath('/onboarding/public/vote.php', 2);
		return $base.'?v='.((int) $v->rowid).'&m='.((int) $memberId).'&w='.$who.'&k='.$this->signFor($v->rowid, $memberId, $who);
	}

	/**
	 * @param int $voteId Vote
	 * @param int $memberId Member
	 * @param string $who panel, run or ballot
	 * @return string Signature
	 */
	public function signFor($voteId, $memberId, $who)
	{
		return $this->bk->sign('vote:'.((int) $voteId).':'.((int) $memberId).':'.$who);
	}

	/**
	 * @return string The website's page for asking for a lost ballot link
	 */
	public static function resendUrl()
	{
		$site = rtrim(getDolGlobalString('ONBOARDING_WEBSITE_URL'), '/');
		return $site !== '' ? $site.'/voting/' : '';
	}

	// ---------------------------------------------------------------- setting up

	/**
	 * The vote for the coming annual meeting, made 60 days before it with one
	 * question per seat. The officers are emailed to add the bylaw questions.
	 *
	 * @param int $now Now
	 * @return object|null The vote, or null when the meeting is further off
	 */
	public function ensureVote($now)
	{
		global $conf;
		$year = (int) OnboardingBooking::fmt($now, 'Y');
		$meeting = self::annualMeeting($year);
		if ($now > $meeting + (self::days() + 2) * 86400) {
			$meeting = self::annualMeeting($year + 1);
		}
		if ($now < $meeting - 60 * 86400) {
			return null;
		}
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT rowid FROM ".$p."onboarding_vote WHERE entity = ".((int) $conf->entity)." AND test = 0 AND meeting_at = '".$this->db->idate($meeting)."'");
		if ($res && ($o = $this->db->fetch_object($res))) {
			return $this->get((int) $o->rowid);
		}
		return $this->create($meeting, 'Annual meeting '.OnboardingBooking::fmt($meeting, 'Y'));
	}

	/**
	 * @param int $meeting Meeting time
	 * @param string $label Name
	 * @param bool $test A practice vote for the board
	 * @return object|null The new vote
	 */
	public function create($meeting, $label, $test = false)
	{
		global $conf;
		$p = $this->db->prefix();
		$first = !$test && !array_filter($this->all(), function ($o) {
			return empty($o->test);
		});
		$ok = $this->db->query("INSERT INTO ".$p."onboarding_vote (entity, label, meeting_at, status, ratify, test, datec) VALUES (".((int) $conf->entity).", '".$this->db->escape($label)."', '".$this->db->idate($meeting)."', 'draft', ".($first ? 1 : 0).", ".($test ? 1 : 0).", '".$this->db->idate(OnboardingService::now())."')");
		if (!$ok) {
			return null;
		}
		$id = (int) $this->db->last_insert_id($p.'onboarding_vote');
		$i = 0;
		foreach (self::seats() as $seat) {
			$this->db->query("INSERT INTO ".$p."onboarding_vote_question (fk_vote, position, kind, title, threshold) VALUES (".$id.", ".(100 + $i++).", 'seat', '".$this->db->escape($seat)."', 'majority')");
		}
		if ($first || $test) {
			$this->db->query("INSERT INTO ".$p."onboarding_vote_question (fk_vote, position, kind, title, url, threshold) VALUES (".$id.", 1, 'bylaw', '".($test ? 'Practice question: do you like this voting system?' : 'Do you ratify the bylaws?')."', 'https://github.com/".$this->db->escape(self::repo())."/blob/main/bylaws.md', 'twothirds')");
		}
		$v = $this->get($id);
		$this->tellOfficers($v, 'setup');
		return $v;
	}

	/**
	 * @param object $v Vote
	 * @return string Why it cannot be changed now, or ''
	 */
	private function editable($v)
	{
		return in_array($v->status, array('draft', 'noticed')) ? '' : 'Voting has opened, so the questions can no longer change.';
	}

	/**
	 * @param object $v Vote
	 * @param array<string,string> $in title, url, threshold (twothirds or majority), id to change one
	 * @return string What happened
	 */
	public function saveQuestion($v, $in)
	{
		if (($why = $this->editable($v)) !== '') {
			return $why;
		}
		$title = trim(dol_string_nohtmltag((string) $in['title']));
		$url = trim((string) $in['url']);
		if ($title === '') {
			return 'A question needs its wording.';
		}
		if ($url !== '' && !preg_match('#^https://#', $url)) {
			return 'The link must start with https://.';
		}
		$threshold = $in['threshold'] == 'majority' ? 'majority' : 'twothirds';
		$p = $this->db->prefix();
		$id = isset($in['id']) ? (int) $in['id'] : 0;
		if ($id > 0) {
			$this->db->query("UPDATE ".$p."onboarding_vote_question SET title = '".$this->db->escape(dol_trunc($title, 250, 'right', 'UTF-8', 1))."', url = ".($url !== '' ? "'".$this->db->escape($url)."'" : 'NULL').", threshold = '".$threshold."' WHERE rowid = ".$id." AND fk_vote = ".((int) $v->rowid)." AND kind = 'bylaw'");
			return 'Question changed.';
		}
		$res = $this->db->query("SELECT COALESCE(MAX(position), 0) + 1 AS n FROM ".$p."onboarding_vote_question WHERE fk_vote = ".((int) $v->rowid)." AND kind = 'bylaw'");
		$pos = $res ? (int) $this->db->fetch_object($res)->n : 1;
		$this->db->query("INSERT INTO ".$p."onboarding_vote_question (fk_vote, position, kind, title, url, threshold) VALUES (".((int) $v->rowid).", ".$pos.", 'bylaw', '".$this->db->escape(dol_trunc($title, 250, 'right', 'UTF-8', 1))."', ".($url !== '' ? "'".$this->db->escape($url)."'" : 'NULL').", '".$threshold."')");
		return 'Question added.';
	}

	/**
	 * @param object $v Vote
	 * @param int $id Bylaw question
	 * @return string What happened
	 */
	public function removeQuestion($v, $id)
	{
		if (($why = $this->editable($v)) !== '') {
			return $why;
		}
		$this->db->query("DELETE FROM ".$this->db->prefix()."onboarding_vote_question WHERE rowid = ".((int) $id)." AND fk_vote = ".((int) $v->rowid)." AND kind = 'bylaw'");
		return 'Question removed.';
	}

	/**
	 * @param object $v Vote
	 * @param string $url Pull request with the bylaw changes, or ''
	 * @param bool $ratify Whether the elections count only if the first bylaw question passes
	 * @return string What happened
	 */
	public function saveSettings($v, $url, $ratify)
	{
		if (($why = $this->editable($v)) !== '') {
			return $why;
		}
		$url = trim((string) $url);
		if ($url !== '' && !self::parsePr($url)) {
			return 'That is not a GitHub pull request link (https://github.com/OWNER/REPO/pull/NUMBER).';
		}
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_vote SET pr_url = ".($url !== '' ? "'".$this->db->escape($url)."'" : 'NULL').", ratify = ".($ratify ? 1 : 0)." WHERE rowid = ".((int) $v->rowid));
		return 'Saved.';
	}

	// ---------------------------------------------------------------- candidates

	/**
	 * @param object $m Member row from member()
	 * @param bool $adult They say they are 18 or older (asked only when no birth date is on file)
	 * @return string Why they cannot run, or ''
	 */
	public function cannotRun($m, $adult)
	{
		if (!$m || (int) $m->statut != Adherent::STATUS_VALIDATED || !in_array((int) $m->fk_adherent_type, self::fullTypes())) {
			return 'Only full members in good standing can run for the board.';
		}
		if (!empty($m->birth)) {
			if (OnboardingBooking::age($this->db->jdate($m->birth), OnboardingService::now()) < 18) {
				return 'Board members must be 18 or older.';
			}
		} elseif (!$adult) {
			return 'Board members must be 18 or older: tick the box to confirm you are.';
		}
		return '';
	}

	/**
	 * @param object $v Vote
	 * @param int $questionId Seat
	 * @param int $memberId Member
	 * @param string $statement Shown on the ballot, optional
	 * @param bool $adult Says they are 18 or older
	 * @param string $source button or meeting
	 * @return array{ok:bool,msg:string}
	 */
	public function run($v, $questionId, $memberId, $statement, $adult, $source = 'button')
	{
		if (($why = $this->editable($v)) !== '') {
			return array('ok' => false, 'msg' => 'Nominations closed when voting opened.');
		}
		$q = null;
		foreach ($this->questions($v) as $o) {
			if ((int) $o->rowid == (int) $questionId && $o->kind == 'seat') {
				$q = $o;
			}
		}
		if (!$q) {
			return array('ok' => false, 'msg' => 'Pick a seat.');
		}
		$m = $this->member($memberId);
		if (($why = $this->cannotRun($m, $adult)) !== '') {
			return array('ok' => false, 'msg' => $why);
		}
		$p = $this->db->prefix();
		$name = dol_trunc(trim($m->firstname.' '.$m->lastname), 120, 'right', 'UTF-8', 1);
		$statement = dol_trunc(trim(dol_string_nohtmltag((string) $statement)), 400, 'right', 'UTF-8', 1);
		foreach ($this->candidates($v) as $c) {
			if ((int) $c->fk_adherent == (int) $memberId) {
				// One seat only (bylaws 4.7): running again moves them.
				$this->db->query("UPDATE ".$p."onboarding_vote_candidate SET fk_question = ".((int) $q->rowid).", statement = ".($statement !== '' ? "'".$this->db->escape($statement)."'" : 'NULL')." WHERE rowid = ".((int) $c->rowid));
				$this->tellOfficers($v, 'candidate', array('text' => $name.' is now running for '.$q->title.' (was '.$this->seatName($v, $c->fk_question).').'));
				return array('ok' => true, 'msg' => 'You are now running for '.$q->title.'. A candidate runs for one seat only, so you are no longer running for '.$this->seatName($v, $c->fk_question).'.');
			}
		}
		$this->db->query("INSERT INTO ".$p."onboarding_vote_candidate (fk_vote, fk_question, fk_adherent, name, statement, source, datec) VALUES (".((int) $v->rowid).", ".((int) $q->rowid).", ".((int) $memberId).", '".$this->db->escape($name)."', ".($statement !== '' ? "'".$this->db->escape($statement)."'" : 'NULL').", '".($source == 'meeting' ? 'meeting' : 'button')."', '".$this->db->idate(OnboardingService::now())."')");
		$this->tellOfficers($v, 'candidate', array('text' => $name.' is running for '.$q->title.($source == 'meeting' ? ' (added at the meeting)' : '').'.'));
		if ($source != 'meeting' && $m->email) {
			$this->mailTo($m->email, 'You are running for '.$q->title, "Hi ".$m->firstname.",\n\nYou are on the ballot for ".$q->title." at the ".$v->label.". Nominations close when voting opens at the meeting on ".OnboardingBooking::fmt($v->meeting).".\n\nTo change your statement, switch seats, or withdraw:\n".$this->link($v, $m->rowid, 'run'));
		}
		return array('ok' => true, 'msg' => 'You are running for '.$q->title.'.');
	}

	/**
	 * @param object $v Vote
	 * @param int $memberId Member who withdraws
	 * @return string What happened
	 */
	public function withdraw($v, $memberId)
	{
		if (($why = $this->editable($v)) !== '') {
			return 'Nominations closed when voting opened.';
		}
		foreach ($this->candidates($v) as $c) {
			if ((int) $c->fk_adherent == (int) $memberId) {
				$this->db->query("DELETE FROM ".$this->db->prefix()."onboarding_vote_candidate WHERE rowid = ".((int) $c->rowid));
				$this->tellOfficers($v, 'candidate', array('text' => $c->name.' withdrew from '.$this->seatName($v, $c->fk_question).'.'));
				return 'You are no longer running.';
			}
		}
		return 'You were not running.';
	}

	/**
	 * @param object $v Vote
	 * @param int $questionId Seat
	 * @return string Its name
	 */
	private function seatName($v, $questionId)
	{
		foreach ($this->questions($v) as $q) {
			if ((int) $q->rowid == (int) $questionId) {
				return (string) $q->title;
			}
		}
		return '?';
	}

	/**
	 * @param string $email Email on a member card
	 * @return object|null That member
	 */
	public function memberByEmail($email)
	{
		$m = $this->roles->memberByEmail($email);
		return $m ? $this->member((int) $m->rowid) : null;
	}

	// ---------------------------------------------------------------- the steps

	/**
	 * @param object $v Vote
	 * @param int $now Now
	 * @return string Why the notice cannot go out now, or ''
	 */
	public function noticeBlocked($v, $now)
	{
		if ($v->status != 'draft') {
			return 'The notice has already gone out.';
		}
		if (!empty($v->test)) {
			return '';
		}
		$days = self::daysBetween($now, $v->meeting);
		if ($days > 60) {
			return 'The notice can go out from '.OnboardingBooking::fmt(self::around($v->meeting, -60, '00:00'), 'l j F').' (60 days before the meeting).';
		}
		if ($days < 14) {
			return 'The bylaws need the notice at least 14 days before voting opens, and the meeting is '.$days.' days away. Move the meeting at least 14 days out, or the vote cannot open at it.';
		}
		if (!self::fullTypes()) {
			return 'No member types are set as full members (Member onboarding setup, Member votes).';
		}
		return '';
	}

	/**
	 * Send notice (bylaws 4.3): every future voter is emailed.
	 *
	 * @param object $v Vote
	 * @param string $by Who pressed it
	 * @return string What happened
	 */
	public function sendNotice($v, $by)
	{
		$now = OnboardingService::now();
		if (($why = $this->noticeBlocked($v, $now)) !== '') {
			return $why;
		}
		$voters = $this->voters($v->meeting, $v);
		if (!$voters) {
			return 'Nobody would get it: no full member in good standing joined 30 days before the meeting.';
		}
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_vote SET status = 'noticed', notice_at = '".$this->db->idate($now)."' WHERE rowid = ".((int) $v->rowid)." AND status = 'draft'");
		$n = $this->queue($v, 'notice', $voters);
		$this->tellOfficers($v, 'done', array('text' => $by.' sent the notice to '.$n.' members. Post it on Discord too (bylaws 4.3):'."\n\n".$this->noticeText($v, null)));
		return 'The notice is going out to '.$n.' members. Post it on Discord too; the text is below.';
	}

	/**
	 * Send reminder: the week before the meeting, the candidates so far.
	 *
	 * @param object $v Vote
	 * @param string $by Who pressed it
	 * @return string What happened
	 */
	public function sendDiscussion($v, $by)
	{
		if ($v->status != 'noticed') {
			return $v->status == 'draft' ? 'Send the notice first.' : 'Voting has opened.';
		}
		$n = $this->queue($v, 'discussion', $this->voters($v->meeting, $v));
		$this->mark($v, 'discussion_sent');
		$this->tellOfficers($v, 'done', array('text' => $by.' sent the discussion reminder to '.$n.' members.'));
		return 'The reminder is going out to '.$n.' members.';
	}

	/**
	 * @param object $v Vote
	 * @param int $now Now
	 * @return string Why voting cannot open now, or ''
	 */
	public function openBlocked($v, $now)
	{
		if ($v->status == 'draft') {
			return 'Send the notice first.';
		}
		if ($v->status != 'noticed') {
			return 'Voting has already opened.';
		}
		if (empty($v->test) && self::day($now) != self::day($v->meeting)) {
			return 'Voting opens during the meeting, on '.OnboardingBooking::fmt($v->meeting, 'l j F').'.';
		}
		$bylaws = array_filter($this->questions($v), function ($q) {
			return $q->kind == 'bylaw';
		});
		$seated = 0;
		foreach ($this->questions($v) as $q) {
			$seated += $q->kind == 'seat' && $this->candidates($v, (int) $q->rowid) ? 1 : 0;
		}
		if (!$bylaws && !$seated) {
			return 'There is nothing to vote on: no bylaw questions and no candidates.';
		}
		return '';
	}

	/**
	 * Open voting (bylaws 4.5): tag the final text, take the roll, build the ballot, send it.
	 *
	 * @param object $v Vote
	 * @param string $by Who pressed it
	 * @return string What happened
	 */
	public function open($v, $by)
	{
		$now = OnboardingService::now();
		if (($why = $this->openBlocked($v, $now)) !== '') {
			return $why;
		}
		$p = $this->db->prefix();
		$roll = $this->voters($now, $v);
		$this->db->begin();
		if (!$this->db->query("UPDATE ".$p."onboarding_vote SET status = 'open', opened_at = '".$this->db->idate($now)."', closes_at = '".$this->db->idate($now + self::days() * 86400)."', voters = ".count($roll)." WHERE rowid = ".((int) $v->rowid)." AND status = 'noticed'") || $this->db->affected_rows(null) < 1) {
			$this->db->rollback();
			return 'Voting has already opened.';
		}
		foreach ($roll as $m) {
			$this->db->query("INSERT INTO ".$p."onboarding_vote_roll (fk_vote, fk_adherent, email) VALUES (".((int) $v->rowid).", ".((int) $m->rowid).", '".$this->db->escape(OnboardingService::cleanEmail($m->email))."')");
		}
		foreach ($this->questions($v) as $q) {
			$opts = array();
			if ($q->kind == 'bylaw') {
				$opts = array(array('yes', null, 'Yes'), array('no', null, 'No'), array('abstain', null, 'Abstain'));
			} else {
				$cands = $this->candidates($v, (int) $q->rowid);
				if (count($cands) == 1) {
					$opts = array(array('yes', (int) $cands[0]->rowid, 'Yes, elect '.$cands[0]->name), array('no', (int) $cands[0]->rowid, 'No'), array('abstain', null, 'Abstain'));
				} elseif ($cands) {
					foreach ($cands as $c) {
						$opts[] = array('candidate', (int) $c->rowid, $c->name);
					}
					$opts[] = array('abstain', null, 'Abstain');
				}
			}
			foreach ($opts as $i => $o) {
				$this->db->query("INSERT INTO ".$p."onboarding_vote_option (fk_question, position, kind, fk_candidate, label) VALUES (".((int) $q->rowid).", ".$i.", '".$o[0]."', ".($o[1] ? (int) $o[1] : 'NULL').", '".$this->db->escape($o[2])."')");
			}
		}
		$this->db->commit();
		$v = $this->get((int) $v->rowid);
		$tag = $this->tagBallot($v);
		$n = $this->queue($v, 'ballot', $roll);
		$this->tellOfficers($v, 'done', array('text' => $by.' opened voting. Ballots are going to '.$n.' members; voting closes '.OnboardingBooking::fmt($v->closes).'.'.($tag !== '' ? "\n\n".$tag : '')));
		return 'Voting is open. Ballots are going to '.$n.' members by email now. It closes '.OnboardingBooking::fmt($v->closes).'.'.($tag !== '' ? ' '.$tag : '');
	}

	/**
	 * @param object $v Vote
	 * @param string $by Who pressed it
	 * @return string What happened
	 */
	public function remindNonVoters($v, $by)
	{
		if ($v->status != 'open') {
			return 'Voting is not open.';
		}
		$p = $this->db->prefix();
		$rows = array();
		$res = $this->db->query("SELECT r.fk_adherent AS rowid, r.email FROM ".$p."onboarding_vote_roll AS r WHERE r.fk_vote = ".((int) $v->rowid)." AND r.voted_on IS NULL");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$rows[] = $o;
		}
		$n = $this->queue($v, 'nonvoter', $rows);
		$this->mark($v, 'nonvoters_sent');
		$this->tellOfficers($v, 'done', array('text' => $by.' reminded the '.$n.' members who have not voted yet.'));
		return 'Reminding the '.$n.' members who have not voted yet.';
	}

	/**
	 * Cast a ballot. Every question with answers needs exactly one of them.
	 *
	 * @param object $v Vote
	 * @param int $memberId Voter
	 * @param array<int,int> $answers question id => option id
	 * @return array{ok:bool,msg:string}
	 */
	public function cast($v, $memberId, $answers)
	{
		$now = OnboardingService::now();
		if ($v->status != 'open' || $now >= $v->closes) {
			return array('ok' => false, 'msg' => 'Voting is closed.');
		}
		$chosen = array();
		foreach ($this->questions($v) as $q) {
			$opts = $this->options((int) $q->rowid);
			if (!$opts) {
				continue;
			}
			$pick = isset($answers[(int) $q->rowid]) ? (int) $answers[(int) $q->rowid] : 0;
			$ids = array_map(function ($o) {
				return (int) $o->rowid;
			}, $opts);
			if (!in_array($pick, $ids, true)) {
				return array('ok' => false, 'msg' => 'Answer every question (Abstain is an answer): '.$q->title.'.');
			}
			$chosen[] = $pick;
		}
		$p = $this->db->prefix();
		$this->db->begin();
		$ok = $this->db->query("UPDATE ".$p."onboarding_vote_roll SET voted_on = '".$this->db->escape(self::day($now))."' WHERE fk_vote = ".((int) $v->rowid)." AND fk_adherent = ".((int) $memberId)." AND voted_on IS NULL");
		if (!$ok || $this->db->affected_rows(null) < 1) {
			$this->db->rollback();
			return array('ok' => false, 'msg' => 'You have already voted, or you are not on the roll for this vote.');
		}
		if ($chosen) {
			$ok = $this->db->query("UPDATE ".$p."onboarding_vote_option SET votes = votes + 1 WHERE rowid IN (".implode(',', $chosen).")");
		}
		$ok = $ok && $this->db->query("UPDATE ".$p."onboarding_vote SET ballots = ballots + 1 WHERE rowid = ".((int) $v->rowid));
		if (!$ok) {
			$this->db->rollback();
			return array('ok' => false, 'msg' => 'Your ballot could not be recorded. Nothing was counted; please try again.');
		}
		$this->db->commit();
		$m = $this->member($memberId);
		if ($m && $m->email) {
			$this->mailTo($m->email, 'Your ballot is in: '.$v->label, "Hi ".$m->firstname.",\n\nYour ballot in the ".$v->label." vote was counted. How you voted is not recorded anywhere.\n\nResults go out when voting closes, ".OnboardingBooking::fmt($v->closes).".");
		}
		return array('ok' => true, 'msg' => 'Your ballot was counted. How you voted is not recorded anywhere.');
	}

	/**
	 * @param object $v Vote
	 * @param int $memberId Member
	 * @return object|null Their roll row
	 */
	public function rollRow($v, $memberId)
	{
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_vote_roll WHERE fk_vote = ".((int) $v->rowid)." AND fk_adherent = ".((int) $memberId));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * Close voting, count, email the officers, post on GitHub, ask for tiebreaks.
	 *
	 * @param object $v Vote
	 * @return string What happened
	 */
	public function close($v)
	{
		$now = OnboardingService::now();
		if ($v->status != 'open') {
			return 'Voting is not open.';
		}
		$p = $this->db->prefix();
		$this->db->query("UPDATE ".$p."onboarding_vote SET status = 'closed', closed_at = '".$this->db->idate($now)."' WHERE rowid = ".((int) $v->rowid)." AND status = 'open'");
		$v = $this->get((int) $v->rowid);
		$text = $this->resultText($v);
		$this->db->query("UPDATE ".$p."onboarding_vote SET result = '".$this->db->escape($text)."' WHERE rowid = ".((int) $v->rowid));
		$posted = $this->postResults($v, $text);
		$this->tellOfficers($v, 'results', array('text' => $text, 'posted' => $posted));
		$this->askTiebreaks($v);
		return 'Closed and counted.';
	}

	/**
	 * @param object $v Vote
	 * @param string $key Step name
	 * @return void
	 */
	private function mark($v, $key)
	{
		$v->steps[$key] = OnboardingService::now();
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_vote SET done = '".$this->db->escape(json_encode($v->steps))."' WHERE rowid = ".((int) $v->rowid));
	}

	// ---------------------------------------------------------------- results

	/**
	 * @param object $v Vote
	 * @return array{voters:int,ballots:int,quorum:int,met:bool,questions:array}
	 */
	public function results($v)
	{
		$quorum = self::quorum((int) $v->voters);
		$met = (int) $v->ballots >= $quorum;
		$out = array('voters' => (int) $v->voters, 'ballots' => (int) $v->ballots, 'quorum' => $quorum, 'met' => $met, 'questions' => array());
		$ratified = true;
		$firstBylaw = true;
		foreach ($this->questions($v) as $q) {
			$opts = $this->options((int) $q->rowid);
			$count = array('yes' => 0, 'no' => 0, 'abstain' => 0);
			$cands = array();
			foreach ($opts as $o) {
				if ($o->kind == 'candidate') {
					$cands[] = $o;
				} else {
					$count[$o->kind] += (int) $o->votes;
				}
			}
			$row = array('q' => $q, 'options' => $opts, 'outcome' => '', 'passed' => false, 'tied' => array());
			$cast = $count['yes'] + $count['no'];
			if ($q->kind == 'bylaw') {
				$need = $q->threshold == 'majority' ? 'a majority' : 'two-thirds';
				$pass = $q->threshold == 'majority' ? $count['yes'] * 2 > $cast : ($cast > 0 && $count['yes'] * 3 >= $cast * 2);
				$row['passed'] = $met && $pass;
				$row['outcome'] = !$met ? 'No result: quorum not met' : ($pass ? 'Passed' : 'Failed').' ('.$need.' of votes cast needed)';
				if ($firstBylaw && !empty($v->ratify)) {
					$ratified = $row['passed'];
				}
				$firstBylaw = false;
			} elseif (!$opts) {
				$row['outcome'] = 'No candidate: vacant, filled by the board';
			} elseif (!$cands) {
				$name = '';
				foreach ($opts as $o) {
					if ($o->kind == 'yes') {
						$name = preg_replace('/^Yes, elect /', '', $o->label);
					}
				}
				$row['passed'] = $met && $count['yes'] * 2 > $cast;
				$row['outcome'] = !$met ? 'No result: quorum not met' : ($row['passed'] ? 'Elected: '.$name : $name.' not elected: vacant, filled by the board');
			} else {
				$top = 0;
				foreach ($cands as $o) {
					$top = max($top, (int) $o->votes);
				}
				$best = array_values(array_filter($cands, function ($o) use ($top) {
					return (int) $o->votes == $top;
				}));
				if (!$met) {
					$row['outcome'] = 'No result: quorum not met';
				} elseif (count($best) == 1) {
					$row['passed'] = true;
					$row['outcome'] = 'Elected: '.$best[0]->label;
				} elseif ($q->fk_decided) {
					$row['passed'] = true;
					foreach ($cands as $o) {
						if ((int) $o->rowid == (int) $q->fk_decided) {
							$row['outcome'] = 'Elected: '.$o->label.' (tie broken by '.$q->decided_by.')';
						}
					}
				} else {
					$row['tied'] = $best;
					$row['outcome'] = 'Tie between '.implode(' and ', array_map(function ($o) {
						return $o->label;
					}, $best)).': waiting for the tiebreak';
				}
			}
			if ($q->kind == 'seat' && !empty($v->ratify) && !$ratified && $met) {
				$row['passed'] = false;
				$row['tied'] = array();
				$row['outcome'] = 'Does not count: the bylaws were not ratified (bylaws 4.9)';
			}
			$row['count'] = $count;
			$out['questions'][] = $row;
		}
		return $out;
	}

	/**
	 * @param object $v Vote
	 * @return string The result, as Markdown for GitHub and plain enough for email
	 */
	public function resultText($v)
	{
		$r = $this->results($v);
		$t = '## Result: '.$v->label."\n\n";
		$t .= 'Voting opened '.OnboardingBooking::fmt($v->opened).' and closed '.OnboardingBooking::fmt($v->closes).".\n\n";
		$t .= '- Eligible voters: '.$r['voters']."\n- Ballots cast: ".$r['ballots']."\n- Quorum (10%, at least 5): ".$r['quorum'].' ballots, '.($r['met'] ? 'met' : '**not met**')."\n";
		if ($v->tag) {
			$t .= '- Text voted on: tag `'.$v->tag.'`'.($v->tag_sha ? ' ('.substr($v->tag_sha, 0, 7).')' : '')."\n";
		}
		foreach ($r['questions'] as $row) {
			$t .= "\n### ".$row['q']->title.($row['q']->kind == 'seat' ? ' (seat)' : '')."\n\n";
			foreach ($row['options'] as $o) {
				$t .= '- '.$o->label.': '.((int) $o->votes)."\n";
			}
			$t .= "\n**".$row['outcome']."**\n";
		}
		if (!$r['met']) {
			$t .= "\nWithout quorum the directors stay in office and the vote is held again within 60 days (bylaws 4.6).\n";
		}
		return $t;
	}

	/**
	 * Email whoever breaks each tie: the President, the Secretary when the President
	 * is tied, and when both are (or nobody holds the tags), it is drawn by lot.
	 *
	 * @param object $v Vote
	 * @return void
	 */
	public function askTiebreaks($v)
	{
		$officers = $this->officers();
		foreach ($this->results($v)['questions'] as $row) {
			if (!$row['tied']) {
				continue;
			}
			$tiedMembers = array();
			foreach ($row['tied'] as $o) {
				$c = $this->candidate((int) $o->fk_candidate);
				$tiedMembers[] = $c ? (int) $c->fk_adherent : 0;
			}
			$breaker = null;
			foreach (array('president', 'secretary') as $role) {
				foreach ($officers[$role] as $m) {
					if (!$breaker && !in_array((int) $m->rowid, $tiedMembers, true)) {
						$breaker = array($role, $m);
					}
				}
			}
			if (!$breaker) {
				$pick = $row['tied'][random_int(0, count($row['tied']) - 1)];
				$this->decide($v, (int) $row['q']->rowid, (int) $pick->rowid, 'lot');
				continue;
			}
			$key = 'tie_'.((int) $row['q']->rowid).'_'.self::day(OnboardingService::now());
			if (!empty($v->steps[$key])) {
				continue;
			}
			$this->mark($v, $key);
			$names = implode(' and ', array_map(function ($o) {
				return $o->label;
			}, $row['tied']));
			$this->mailTo($breaker[1]->email, 'Break a tie: '.$row['q']->title, "Hi ".$breaker[1]->firstname.",\n\nThe vote for ".$row['q']->title." is a tie between ".$names.". As ".($breaker[0] == 'president' ? 'President' : 'Secretary').", you break it (bylaws 4.7).\n\nPick the winner here:\n".$this->link($v, (int) $breaker[1]->rowid, 'panel'));
		}
	}

	/**
	 * @param int $id Candidate id
	 * @return object|null
	 */
	public function candidate($id)
	{
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_vote_candidate WHERE rowid = ".((int) $id));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param object $v Vote
	 * @param int $memberId The tiebreaker
	 * @return array<int,array> The ties this member may break: question id => results row
	 */
	public function tiesFor($v, $memberId)
	{
		$out = array();
		if ($v->status != 'closed') {
			return $out;
		}
		$role = $this->officerRole($memberId);
		if ($role === '') {
			return $out;
		}
		$officers = $this->officers();
		foreach ($this->results($v)['questions'] as $row) {
			if (!$row['tied']) {
				continue;
			}
			$tiedMembers = array();
			foreach ($row['tied'] as $o) {
				$c = $this->candidate((int) $o->fk_candidate);
				$tiedMembers[] = $c ? (int) $c->fk_adherent : 0;
			}
			$presidentFree = false;
			foreach ($officers['president'] as $m) {
				$presidentFree = $presidentFree || !in_array((int) $m->rowid, $tiedMembers, true);
			}
			$mayBreak = !in_array((int) $memberId, $tiedMembers, true) && ($role == 'president' || !$presidentFree);
			if ($mayBreak) {
				$out[(int) $row['q']->rowid] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param object $v Vote
	 * @param int $questionId Tied seat
	 * @param int $optionId Winner
	 * @param string $by Who broke the tie, or lot
	 * @return string What happened
	 */
	public function decide($v, $questionId, $optionId, $by)
	{
		$row = null;
		foreach ($this->results($v)['questions'] as $r) {
			if ((int) $r['q']->rowid == (int) $questionId && $r['tied']) {
				$row = $r;
			}
		}
		if (!$row) {
			return 'That seat is not waiting for a tiebreak.';
		}
		$ok = false;
		foreach ($row['tied'] as $o) {
			$ok = $ok || (int) $o->rowid == (int) $optionId;
		}
		if (!$ok) {
			return 'Pick one of the tied candidates.';
		}
		$p = $this->db->prefix();
		$this->db->query("UPDATE ".$p."onboarding_vote_question SET fk_decided = ".((int) $optionId).", decided_by = '".$this->db->escape($by == 'lot' ? 'lot' : $by)."' WHERE rowid = ".((int) $questionId)." AND fk_decided IS NULL");
		$v = $this->get((int) $v->rowid);
		$text = $this->resultText($v);
		$this->db->query("UPDATE ".$p."onboarding_vote SET result = '".$this->db->escape($text)."' WHERE rowid = ".((int) $v->rowid));
		$posted = $this->postResults($v, "Tie for ".$row['q']->title." broken by ".($by == 'lot' ? 'lot' : $by)." (bylaws 4.7). Updated result:\n\n".$text);
		$this->tellOfficers($v, 'results', array('text' => $text, 'posted' => $posted, 'subject' => 'Tie broken: '.$row['q']->title));
		return 'Tie broken. The updated result has been emailed and posted.';
	}

	// ---------------------------------------------------------------- GitHub

	/**
	 * @return string owner/repo where results go when the vote has no pull request
	 */
	public static function repo()
	{
		return getDolGlobalString('ONBOARDING_VOTE_REPO', 'ColumbiaGadgetWorks/bylaws');
	}

	/**
	 * @param string $url Pull request link
	 * @return array{0:string,1:int}|null owner/repo and number
	 */
	public static function parsePr($url)
	{
		return preg_match('#^https://github\.com/([\w.-]+/[\w.-]+)/pull/(\d+)#', (string) $url, $m) ? array($m[1], (int) $m[2]) : null;
	}

	/**
	 * @param string $method GET or POST
	 * @param string $path API path, e.g. /repos/o/r/pulls/3
	 * @param array|null $body JSON body
	 * @return array{code:int,json:mixed,error:string}
	 */
	public function github($method, $path, $body = null)
	{
		global $conf;
		$token = getDolGlobalString('ONBOARDING_VOTE_GITHUB_TOKEN');
		if ($token === '') {
			return array('code' => 0, 'json' => null, 'error' => 'no GitHub token is set (Member onboarding setup, Member votes)');
		}
		$base = rtrim(getDolGlobalString('ONBOARDING_GITHUB_API', 'https://api.github.com'), '/');
		$headers = array('Authorization: Bearer '.$token, 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: CGW-Dolibarr-onboarding');
		// curl itself rather than getURLContent, which only returns response
		// headers from Dolibarr 20 on, and the token's expiry is in a header.
		$ch = curl_init($base.$path);
		$head = '';
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTPHEADER => $body !== null ? array_merge($headers, array('Content-Type: application/json')) : $headers,
			CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$head) {
				$head .= $line;
				return strlen($line);
			},
		));
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		}
		$content = curl_exec($ch);
		$r = array('content' => is_string($content) ? $content : '', 'http_code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'header' => $head, 'curl_error_msg' => curl_error($ch));
		curl_close($ch);
		$code = $r['http_code'];
		// GitHub says when a fine-grained token runs out; warn a month ahead.
		if (preg_match('/^github-authentication-token-expiration:\s*(.+)$/im', $head, $m)) {
			$exp = strtotime(trim($m[1]));
			if ($exp) {
				dolibarr_set_const($this->db, 'ONBOARDING_VOTE_GH_EXPIRES', $exp, 'chaine', 0, 'When the GitHub token for member votes expires', $conf->entity);
			}
		}
		$json = isset($r['content']) ? json_decode((string) $r['content'], true) : null;
		$error = $code >= 200 && $code < 300 ? '' : 'GitHub answered '.$code.(is_array($json) && isset($json['message']) ? ': '.$json['message'] : (!empty($r['curl_error_msg']) ? ': '.$r['curl_error_msg'] : ''));
		return array('code' => $code, 'json' => $json, 'error' => $error);
	}

	/**
	 * Freeze the text: tag the pull request's latest commit ballot/<meeting day>.
	 *
	 * @param object $v Vote
	 * @return string A line for the officers, or '' when there is no pull request
	 */
	public function tagBallot($v)
	{
		$pr = self::parsePr($v->pr_url);
		if (!$pr || !empty($v->test)) {
			return '';
		}
		$tag = 'ballot/'.self::day($v->meeting);
		$r = $this->github('GET', '/repos/'.$pr[0].'/pulls/'.$pr[1]);
		$sha = $r['error'] === '' && isset($r['json']['head']['sha']) ? (string) $r['json']['head']['sha'] : '';
		if ($sha === '') {
			$this->svc->alert('vote-tag', 'The ballot text could not be tagged on GitHub', 'Voting opened, but the latest commit of '.$v->pr_url.' could not be read: '.$r['error'].'. Tag it '.$tag.' by hand.');
			return 'The text could not be tagged on GitHub ('.$r['error'].'); tag the pull request\'s latest commit '.$tag.' by hand.';
		}
		$t = $this->github('POST', '/repos/'.$pr[0].'/git/refs', array('ref' => 'refs/tags/'.$tag, 'sha' => $sha));
		if ($t['error'] !== '' && $t['code'] != 422) {
			$this->svc->alert('vote-tag', 'The ballot text could not be tagged on GitHub', 'Tagging '.$sha.' as '.$tag.' failed: '.$t['error'].'. Tag it by hand.');
			return 'The text could not be tagged on GitHub ('.$t['error'].'); tag commit '.substr($sha, 0, 7).' '.$tag.' by hand.';
		}
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_vote SET tag = '".$this->db->escape($tag)."', tag_sha = '".$this->db->escape($sha)."' WHERE rowid = ".((int) $v->rowid));
		return 'The final text is tagged '.$tag.' (commit '.substr($sha, 0, 7).').';
	}

	/**
	 * Post the result on the pull request, or as an issue in the governance
	 * repository when the vote has none (bylaws 4.5).
	 *
	 * @param object $v Vote
	 * @param string $text Markdown
	 * @return string Where it was posted, or why not
	 */
	public function postResults($v, $text)
	{
		if (!empty($v->test)) {
			return 'This was a practice vote, so nothing was posted on GitHub.';
		}
		$pr = self::parsePr($v->pr_url);
		$body = array('body' => $text."\n\n---\n_Posted by Dolibarr when voting closed._");
		if ($pr) {
			$r = $this->github('POST', '/repos/'.$pr[0].'/issues/'.$pr[1].'/comments', $body);
		} else {
			$body['title'] = 'Result: '.$v->label;
			$r = $this->github('POST', '/repos/'.self::repo().'/issues', $body);
		}
		$p = $this->db->prefix();
		if ($r['error'] !== '') {
			$this->db->query("UPDATE ".$p."onboarding_vote SET post_error = '".$this->db->escape(dol_trunc($r['error'], 250, 'right', 'UTF-8', 1))."' WHERE rowid = ".((int) $v->rowid));
			return 'It could NOT be posted on GitHub ('.$r['error'].'). Post the text above on '.($pr ? $v->pr_url : 'https://github.com/'.self::repo().'/issues').' yourself, or press Post again in the panel.';
		}
		$url = isset($r['json']['html_url']) ? (string) $r['json']['html_url'] : '';
		$this->db->query("UPDATE ".$p."onboarding_vote SET posted_url = '".$this->db->escape($url)."', post_error = NULL WHERE rowid = ".((int) $v->rowid));
		return 'It is posted on GitHub: '.$url;
	}

	// ---------------------------------------------------------------- emails

	/**
	 * @param string $to Address
	 * @param string $subject Subject
	 * @param string $body Text
	 * @return bool
	 */
	private function mailTo($to, $subject, $body)
	{
		$org = getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'Columbia Gadget Works');
		return $this->svc->mail($to, $subject, $body."\n\n".$org);
	}

	/**
	 * Queue one email of a kind for each member; they go out after the page answers
	 * and from the 15-minute job.
	 *
	 * @param object $v Vote
	 * @param string $kind notice, discussion, ballot or nonvoter
	 * @param object[] $members rowid and email
	 * @return int How many were queued
	 */
	private function queue($v, $kind, $members)
	{
		$p = $this->db->prefix();
		$n = 0;
		foreach ($members as $m) {
			$email = OnboardingService::cleanEmail($m->email);
			if ($email === '') {
				continue;
			}
			if ($this->db->query("INSERT INTO ".$p."onboarding_vote_mail (fk_vote, kind, fk_adherent, email) VALUES (".((int) $v->rowid).", '".$this->db->escape($kind)."', ".((int) $m->rowid).", '".$this->db->escape($email)."')")) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Send queued emails.
	 *
	 * @param int $limit At most this many
	 * @return int Sent
	 */
	public function sendQueued($limit = 200)
	{
		$p = $this->db->prefix();
		$rows = array();
		$res = $this->db->query("SELECT * FROM ".$p."onboarding_vote_mail WHERE sent_at IS NULL AND tries < 3 ORDER BY rowid ASC LIMIT ".((int) $limit));
		while ($res && ($o = $this->db->fetch_object($res))) {
			$rows[] = $o;
		}
		$sent = 0;
		$votes = array();
		foreach ($rows as $row) {
			// Claim the row first so two runs at once do not both send it.
			$this->db->query("UPDATE ".$p."onboarding_vote_mail SET tries = tries + 1 WHERE rowid = ".((int) $row->rowid)." AND tries = ".((int) $row->tries));
			if ($this->db->affected_rows(null) < 1) {
				continue;
			}
			if (!isset($votes[$row->fk_vote])) {
				$votes[$row->fk_vote] = $this->get((int) $row->fk_vote);
			}
			$v = $votes[$row->fk_vote];
			$m = $this->member((int) $row->fk_adherent);
			if (!$v || !$m) {
				continue;
			}
			// A ballot reminder is pointless once they have voted.
			if ($row->kind == 'nonvoter') {
				$r = $this->rollRow($v, (int) $m->rowid);
				if (!$r || $r->voted_on) {
					$this->db->query("UPDATE ".$p."onboarding_vote_mail SET sent_at = '".$this->db->idate(OnboardingService::now())."' WHERE rowid = ".((int) $row->rowid));
					continue;
				}
			}
			list($subject, $body) = $this->memberMail($v, $row->kind, $m);
			if ($this->mailTo($row->email, $subject, $body)) {
				$this->db->query("UPDATE ".$p."onboarding_vote_mail SET sent_at = '".$this->db->idate(OnboardingService::now())."' WHERE rowid = ".((int) $row->rowid));
				$sent++;
			}
		}
		return $sent;
	}

	/**
	 * @param object $v Vote
	 * @param object|null $m Member, or null for the copy posted on Discord
	 * @return string The notice (bylaws 4.3)
	 */
	public function noticeText($v, $m)
	{
		$t = "The ".$v->label." is on ".OnboardingBooking::fmt($v->meeting).", at ".getDolGlobalString('ONBOARDING_VOTE_PLACE', '1404 Grand Ave, Unit B, Columbia').". There is no Open Hack Night that evening.\n\n";
		$t .= "At the meeting we discuss the proposed bylaw changes and meet the candidates, and the members there may still change the proposed text. Voting opens during the meeting: every voter gets a personal ballot by email, and voting stays open for ".self::days()." days.\n\n";
		$t .= "On the ballot:\n";
		$seats = array();
		foreach ($this->questions($v) as $q) {
			if ($q->kind == 'bylaw') {
				$t .= "- ".$q->title.($q->url ? " (proposed text: ".$q->url.")" : '')." Needs ".($q->threshold == 'majority' ? 'a majority' : 'two-thirds')." of votes cast.\n";
			} else {
				$seats[] = $q->title;
			}
		}
		if ($seats) {
			$t .= "- The board: ".implode(', ', $seats).". Most votes wins; a lone candidate needs a majority of votes cast.\n";
		}
		if ($v->pr_url) {
			$t .= "\nRead and discuss the proposed changes: ".$v->pr_url."\n";
		}
		$t .= "\nThe vote counts if at least 10% of voters, and no fewer than 5, cast a ballot. Full members (Standard or Supporter) in good standing who joined at least 30 days before the meeting can vote.\n";
		if (!empty($v->ratify)) {
			$t .= "\nThis is the vote on CGW's first bylaws: the board election counts only if the bylaws are ratified.\n";
		}
		if ($m) {
			$t .= "\nWant to run for a board seat? Any full member 18 or older can run for one seat. Press here before voting opens:\n".$this->link($v, (int) $m->rowid, 'run')."\n";
			$t .= "\nYour member number ends in ".self::pin($m).". If you lose your ballot email, ask for it again at ".(self::resendUrl() ?: 'the website')." with your email and those two digits.";
		} else {
			$t .= "\nTo run for a board seat, use the button in your notice email, or be nominated at the meeting.";
		}
		return $t;
	}

	/**
	 * @param object $v Vote
	 * @return string Candidates so far, one per line
	 */
	private function candidateList($v)
	{
		$t = '';
		foreach ($this->questions($v) as $q) {
			if ($q->kind != 'seat') {
				continue;
			}
			$names = array_map(function ($c) {
				return $c->name;
			}, $this->candidates($v, (int) $q->rowid));
			$t .= "- ".$q->title.": ".($names ? implode(', ', $names) : 'nobody yet')."\n";
		}
		return $t;
	}

	/**
	 * @param object $v Vote
	 * @param string $kind notice, discussion, ballot or nonvoter
	 * @param object $m Member
	 * @return array{0:string,1:string} Subject and text
	 */
	private function memberMail($v, $kind, $m)
	{
		$hi = "Hi ".($m->firstname ? $m->firstname : 'there').",\n\n";
		$pin = "\n\nLost this email? Ask for your ballot again at ".(self::resendUrl() ?: 'the website')." with your email and the last two digits of your member number (".self::pin($m).").";
		switch ($kind) {
			case 'notice':
				return array('Notice: '.$v->label.' and member vote, '.OnboardingBooking::fmt($v->meeting, 'l j F'), $hi.$this->noticeText($v, $m));
			case 'discussion':
				return array('Next week: '.$v->label.', '.OnboardingBooking::fmt($v->meeting, 'l j F'), $hi."The ".$v->label." is next week, ".OnboardingBooking::fmt($v->meeting).". This is the week to read and discuss the proposals".($v->pr_url ? ":\n".$v->pr_url : '.')."\n\nRunning so far:\n".$this->candidateList($v)."\nWant to run? Any full member 18 or older can run for one seat, until voting opens at the meeting:\n".$this->link($v, (int) $m->rowid, 'run'));
			case 'ballot':
				return array('Your ballot: '.$v->label.' (open until '.OnboardingBooking::fmt($v->closes, 'l j F').')', $hi."Voting is open. Your personal ballot is here:\n\n".$this->link($v, (int) $m->rowid, 'ballot')."\n\nIt is secret: the system records that you voted, never how. Voting closes ".OnboardingBooking::fmt($v->closes).". Do not forward this email: the link is your ballot.".$pin);
			default:
				return array('Reminder: vote by '.OnboardingBooking::fmt($v->closes, 'l j F'), $hi."You have not voted yet in the ".$v->label." vote. It closes ".OnboardingBooking::fmt($v->closes).".\n\nYour ballot:\n".$this->link($v, (int) $m->rowid, 'ballot').$pin);
		}
	}

	/**
	 * Email the Secretary and the President, each with their own panel link.
	 *
	 * @param object $v Vote
	 * @param string $what setup, notice, discussion, open, nonvoters, candidate, done, results
	 * @param array<string,string> $extra text, posted, subject
	 * @return int How many were emailed
	 */
	public function tellOfficers($v, $what, $extra = array())
	{
		$when = OnboardingBooking::fmt($v->meeting);
		$text = isset($extra['text']) ? (string) $extra['text'] : '';
		$subjects = array(
			'setup' => 'Set up the vote: '.$v->label.', '.$when,
			'notice' => 'Send the notice by '.OnboardingBooking::fmt(self::around($v->meeting, -14, '00:00'), 'l j F').': '.$v->label,
			'discussion' => 'Send the discussion reminder: '.$v->label,
			'open' => 'Open voting at the meeting tonight',
			'nonvoters' => 'Remind members who have not voted',
			'candidate' => 'Candidate: '.$text,
			'done' => $v->label.': '.strtok($text, '.').'.',
			'results' => isset($extra['subject']) ? $extra['subject'] : 'Result: '.$v->label,
		);
		$bodies = array(
			'setup' => "The ".$v->label." is on ".$when.". The vote is set up with the board seats".(!empty($v->ratify) ? " and the question to ratify the bylaws" : '').". Add any bylaw questions, and the pull request members discuss them on, here:\n\n{panel}\n\nThe notice must go out between 60 and 14 days before the meeting. You will be reminded.",
			'notice' => "The notice of the ".$v->label." (".$when.") must go out by ".OnboardingBooking::fmt(self::around($v->meeting, -14, '00:00'), 'l j F').", 14 days before voting opens (bylaws 4.3). Check the questions, then press Send notice:\n\n{panel}",
			'discussion' => "The meeting is in a week. Press Send reminder to email every voter the candidates so far and a link to the discussion:\n\n{panel}",
			'open' => "The ".$v->label." is today, ".$when.". After the discussion, update the pull request with any changes, add candidates nominated from the floor, and press Open voting before the meeting ends:\n\n{panel}",
			'nonvoters' => "Voting closes ".OnboardingBooking::fmt($v->closes).". Press Remind non-voters to nudge everyone who has not voted:\n\n{panel}",
			'candidate' => $text."\n\nThe vote: {panel}",
			'done' => $text."\n\nThe vote: {panel}",
			'results' => "Voting has closed. ".(isset($extra['posted']) ? $extra['posted'] : '')."\n\n".$text."\n\nThe vote: {panel}",
		);
		$n = 0;
		$seen = array();
		foreach ($this->officers() as $people) {
			foreach ($people as $m) {
				$email = OnboardingService::cleanEmail($m->email);
				if ($email === '' || isset($seen[$email])) {
					continue;
				}
				$seen[$email] = 1;
				$body = "Hi ".$m->firstname.",\n\n".str_replace('{panel}', $this->link($v, (int) $m->rowid, 'panel'), $bodies[$what])."\n\nThe Secretary runs the vote; the President gets the same emails and can press the buttons if the Secretary cannot.";
				$n += $this->mailTo($email, $subjects[$what], $body) ? 1 : 0;
			}
		}
		if (!$n && in_array($what, array('setup', 'notice', 'open', 'results'))) {
			$this->svc->alert('vote-officers', 'Nobody holds the secretary or president tag', 'The member vote needs a Secretary or President to press its buttons, but no validated member holds either tag. Tag them under Members.');
		}
		return $n;
	}

	// ---------------------------------------------------------------- the 15-minute job

	/**
	 * @return string What was done
	 */
	public function runJob()
	{
		global $conf;
		$now = OnboardingService::now();
		$out = array();
		$this->ensureVote($now);
		foreach ($this->all() as $v) {
			if ($v->status == 'closed') {
				continue;
			}
			$steps = array();
			if (!empty($v->test)) {
				// A practice vote has no schedule; it only closes.
			} elseif ($v->status == 'draft') {
				foreach (array(21, 16, 14) as $d) {
					$steps['notice'.$d] = array(self::around($v->meeting, -$d, '09:00'), 'notice');
				}
			}
			if ($v->status == 'noticed' && empty($v->test)) {
				if (empty($v->steps['discussion_sent'])) {
					$steps['discussion'] = array(self::around($v->meeting, -7, '09:00'), 'discussion');
				}
				$steps['open'] = array(self::around($v->meeting, 0, '17:00'), 'open');
			}
			if ($v->status == 'open') {
				if (empty($v->steps['nonvoters_sent']) && empty($v->test)) {
					$steps['nonvoters'] = array(self::around($v->opened, self::days() - 2, '09:00'), 'nonvoters');
				}
				if ($now >= $v->closes) {
					$out[] = $v->label.': '.$this->close($v);
					continue;
				}
			}
			foreach ($steps as $key => $s) {
				// Only on the day it is due, so a late start does not send the whole series.
				if ($now >= $s[0] && empty($v->steps[$key]) && self::day($now) == self::day($s[0])) {
					$this->mark($v, $key);
					$this->tellOfficers($v, $s[1]);
					$out[] = $v->label.': asked the officers to '.$s[1];
				}
			}
		}
		foreach ($this->all() as $o) {
			if ($o->status == 'closed') {
				$this->askTiebreaks($o);
			}
		}
		$sent = $this->sendQueued(200);
		if ($sent) {
			$out[] = $sent.' vote emails sent';
		}
		$exp = getDolGlobalInt('ONBOARDING_VOTE_GH_EXPIRES');
		if ($exp > 0 && $exp - $now < 30 * 86400) {
			$this->svc->alert('vote-github-token', 'The GitHub token for member votes expires '.dol_print_date($exp, 'day'), 'Dolibarr posts vote results and tags the ballot text with a GitHub token that expires '.dol_print_date($exp, 'day').'. Make a new fine-grained token (Contents: read and write, Issues and Pull requests: read and write, on '.self::repo().') and paste it in Member onboarding setup, Member votes.');
		}
		return implode('; ', $out);
	}

	/**
	 * The website's "send my ballot again". Always the same answer, so it cannot
	 * be used to find out who is on the roll.
	 *
	 * @param string $email Email
	 * @param string $pin Last two digits of their member number
	 * @return void
	 */
	public function resend($email, $pin)
	{
		$email = OnboardingService::cleanEmail($email);
		$pin = str_pad(preg_replace('/\D/', '', (string) $pin), 2, '0', STR_PAD_LEFT);
		$m = $email !== '' ? $this->memberByEmail($email) : null;
		if (!$m || !hash_equals(self::pin($m), substr($pin, -2))) {
			return;
		}
		$now = OnboardingService::now();
		$p = $this->db->prefix();
		foreach ($this->all() as $v) {
			if ($v->status != 'open' || $now >= $v->closes) {
				continue;
			}
			$r = $this->rollRow($v, (int) $m->rowid);
			if (!$r || $r->voted_on || ($r->resent_at && $now - $this->db->jdate($r->resent_at) < 600)) {
				continue;
			}
			$this->db->query("UPDATE ".$p."onboarding_vote_roll SET resent_at = '".$this->db->idate($now)."' WHERE rowid = ".((int) $r->rowid));
			list($subject, $body) = $this->memberMail($v, 'ballot', $m);
			$this->mailTo($r->email, $subject, $body);
		}
	}
}
