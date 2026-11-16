<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/booking.class.php');

/**
 * On-the-spot trainings: a trainer at the space asks a trainee to say yes,
 * sign and pay within a few hours. Reached through OnboardingBooking::spots().
 */
class OnboardingSpots
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

	// ---------------------------------------------------------------- on the spot

	/**
	 * The tools a member may train someone on right now: a current member with a
	 * time on the tool (the "Trainer: <tool>" tag) who is trained on it themselves.
	 *
	 * @param object|null $m Member row (memberRow())
	 * @return object[] Tools
	 */
	public function spotTools($m)
	{
		if (!$this->bk->isPaying($m)) {
			return array();
		}
		$out = array();
		foreach ($this->bk->schedule()->slots(0, true) as $s) {
			if ((int) $s->fk_adherent != (int) $m->rowid || isset($out[(int) $s->fk_tool])) {
				continue;
			}
			$tool = $this->bk->schedule()->tool((int) $s->fk_tool);
			if ($tool && $tool->active && $this->bk->schedule()->isTrainedOn((int) $m->rowid, $tool)) {
				$out[(int) $tool->rowid] = $tool;
			}
		}
		return array_values($out);
	}

	/**
	 * @param int $memberId Trainer
	 * @param int $until When the link stops working
	 * @return string The trainer's link to start an on-the-spot training
	 */
	public function spotLink($memberId, $until)
	{
		return $this->bk->linkBase().'?t='.((int) $memberId).'&x='.((int) $until).'&k='.$this->bk->sign('spot:'.((int) $memberId).':'.((int) $until));
	}

	/**
	 * @param int $memberId Trainer, from the link
	 * @param int $until Expiry, from the link
	 * @param string $key Signature, from the link
	 * @return bool
	 */
	public function spotLinkValid($memberId, $until, $key)
	{
		return $memberId > 0 && $until >= OnboardingService::now() && hash_equals($this->bk->sign('spot:'.((int) $memberId).':'.((int) $until)), (string) $key);
	}

	/**
	 * "I'm a trainer: train someone now" on the website: email the trainer their
	 * link, if the address belongs to someone who may train on at least one tool.
	 * The answer is the same either way, so the page tells nobody who is a trainer.
	 *
	 * @param string $email What was typed
	 * @return array<string,mixed>
	 */
	public function spotStart($email)
	{
		global $conf;
		$email = OnboardingService::cleanEmail($email);
		if (!isValidEmail($email)) {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		$m = $this->bk->memberRow($email);
		$tools = $this->spotTools($m);
		if ($tools) {
			// At most one link a minute per trainer, whatever the website lets through.
			$last = 'ONBOARDING_SPOT_LINK_'.((int) $m->rowid);
			$now = OnboardingService::now();
			if ($now - getDolGlobalInt($last) >= 60) {
				require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
				dolibarr_set_const($this->db, $last, $now, 'chaine', 0, 'Last on-the-spot training link sent', $conf->entity);
				$until = $now + OnboardingBooking::SPOT_LINK_MINUTES * 60;
				$this->bk->mailTpl('spot_link', $email, array(
					'trainer' => (string) $m->firstname,
					'tools' => implode(', ', array_map(function ($t) {
						return $t->label;
					}, $tools)),
					'link' => $this->spotLink((int) $m->rowid, $until),
					'until' => OnboardingBooking::fmt($until, 'g:i A'),
					'hours' => (string) OnboardingBooking::SPOT_HOURS,
				));
			}
		}
		return array('ok' => true);
	}

	/**
	 * The trainer pressed "Send the request": check it, make the booking, and
	 * email the trainee to say yes or no.
	 *
	 * @param int $memberId Trainer (from their signed link)
	 * @param int $toolId Tool
	 * @param string $email Trainee's email
	 * @return array{ok:bool,error?:string,ref?:string,expires?:int}
	 */
	public function spotRequest($memberId, $toolId, $email)
	{
		global $conf;
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT email FROM ".$p."adherent WHERE rowid = ".((int) $memberId));
		$o = $res ? $this->db->fetch_object($res) : null;
		$m = $o ? $this->bk->memberRow((string) $o->email) : null;
		if ($m && (int) $m->rowid != (int) $memberId) {
			$m = null;
		}
		$tool = null;
		foreach ($this->spotTools($m) as $t) {
			if ((int) $t->rowid == (int) $toolId) {
				$tool = $t;
			}
		}
		if (!$tool) {
			return array('ok' => false, 'error' => 'You can only start a training on a tool you are a trainer for, and are trained on yourself.');
		}
		$email = OnboardingService::cleanEmail($email);
		if (!isValidEmail($email)) {
			return array('ok' => false, 'error' => 'Check the trainee\'s email address.');
		}
		if ($email === OnboardingService::cleanEmail((string) $m->email)) {
			return array('ok' => false, 'error' => 'You cannot train yourself. Type the trainee\'s own email address.');
		}
		$person = $this->bk->person($email);
		if ($this->bk->trainedEmail($email, $tool) || ($person['member'] && $this->bk->schedule()->isTrainedOn((int) $person['member']->rowid, $tool))) {
			return array('ok' => false, 'error' => 'That person is already marked as trained on the '.$tool->label.'. Nothing was sent.');
		}
		$now = OnboardingService::now();
		$nowSql = $this->db->idate($now);
		if ($this->bk->bookingsWhere("email = '".$this->db->escape($email)."' AND fk_tool = ".((int) $tool->rowid)." AND (status IN ('paid', 'confirmed', 'needs_new_date', 'other') OR (status IN ('held', 'requested') AND hold_until > '".$nowSql."'))")) {
			return array('ok' => false, 'error' => 'That person already has a booking or a request open for the '.$tool->label.'. Nothing was sent.');
		}
		$open = $this->bk->bookingsWhere("source = 'spot' AND fk_trainer = ".((int) $m->rowid)." AND status IN ('requested', 'held') AND hold_until > '".$nowSql."'");
		if (count($open) >= OnboardingBooking::SPOT_MAX_OPEN) {
			return array('ok' => false, 'error' => 'You already have '.count($open).' requests waiting for the trainee. Wait for one to be paid or to expire, then try again.');
		}
		$member = $person['kind'] == 'member';
		$price = $member ? OnboardingBooking::memberPrice($tool) : round((float) $tool->fee, 2);
		$until = $now + OnboardingBooking::SPOT_HOURS * 3600;
		$token = bin2hex(random_bytes(24));
		$id = OnboardingSql::insert($this->db, 'onboarding_booking', array(
			'entity' => (int) $conf->entity,
			'token' => $token,
			'fk_tool' => (int) $tool->rowid,
			'fk_trainer' => (int) $m->rowid,
			'fk_adherent' => $person['member'] ? (int) $person['member']->rowid : null,
			'fk_socpeople' => $person['contact'] ? (int) $person['contact'] : null,
			'fk_applicant' => $person['applicant'] ? (int) $person['applicant']->rowid : null,
			'email' => $email,
			'firstname' => dol_trunc($person['firstname'], 100, 'right', 'UTF-8', 1),
			'lastname' => dol_trunc($person['lastname'], 100, 'right', 'UTF-8', 1),
			'session_start' => $nowSql,
			'minutes' => (int) $tool->minutes,
			'price' => (float) price2num($price),
			'price_type' => $member ? 'member' : 'nonmember',
			'status' => 'requested',
			'hold_until' => $this->db->idate($until),
			'source' => 'spot',
			'datec' => $nowSql,
		));
		if (!$id) {
			return array('ok' => false, 'error' => 'The request could not be saved. Try again in a minute.');
		}
		$ref = 'TR-'.sprintf('%04d', $id);
		$this->bk->saveBooking($id, array('ref' => $ref));
		$b = $this->bk->booking($id);
		$this->bk->mailTpl('spot_request', $email, $this->bk->vars($b));
		return array('ok' => true, 'ref' => $ref, 'expires' => $until);
	}

	/**
	 * The trainee answers the request: "No" cancels it and tells the secretary
	 * and the trainer; "Yes" prices it (member or not) and opens it for signing
	 * and paying on the website.
	 *
	 * @param object $b Booking
	 * @param bool $yes Their answer
	 * @return array{ok:bool,error?:string,url?:string}
	 */
	public function spotAnswer($b, $yes)
	{
		$now = OnboardingService::now();
		if ($b->status == 'held' && $yes) {
			return array('ok' => true, 'url' => $this->spotPayUrl($b));
		}
		if ($b->status != 'requested' || $this->db->jdate($b->hold_until) < $now) {
			return array('ok' => false, 'error' => 'This request is no longer open ('.OnboardingBooking::STATUS_LABELS[$b->status == 'requested' ? 'expired' : $b->status].'). Ask your trainer to send a new one.');
		}
		if (!$yes) {
			if (!$this->bk->saveBooking((int) $b->rowid, array('status' => 'cancelled', 'answer' => 'declined', 'answered_at' => $this->db->idate($now)), "status = 'requested'")) {
				return array('ok' => false, 'error' => 'Already answered.');
			}
			$this->bk->mailTpl('staff_spot_declined', $this->bk->staffEmails(), $this->bk->vars($b, 'staff'));
			$this->bk->mailTpl('spot_declined', $b->trainer_email, $this->bk->vars($b, 'trainer'));
			return array('ok' => true);
		}
		if (!$this->bk->saveBooking((int) $b->rowid, array('status' => 'held'), "status = 'requested'")) {
			return array('ok' => false, 'error' => 'Already answered.');
		}
		$this->spotPrice($this->bk->booking((int) $b->rowid));
		return array('ok' => true, 'url' => $this->spotPayUrl($b));
	}

	/**
	 * @param object $b Booking
	 * @return string The website's booking page, opened on this request's signing and paying
	 */
	public function spotPayUrl($b)
	{
		return $this->bk->bookUrl().'#spot='.rawurlencode((string) $b->ref).'.'.$b->token;
	}

	/**
	 * Bring an on-the-spot booking up to date with who the trainee is now: their
	 * name, member card and price (they may have just signed up or signed).
	 *
	 * @param object $b Booking
	 * @return array Person, from person()
	 */
	public function spotPrice($b)
	{
		$person = $this->bk->person((string) $b->email);
		$tool = $this->bk->schedule()->tool((int) $b->fk_tool);
		$member = $person['kind'] == 'member';
		$this->bk->saveBooking((int) $b->rowid, array(
			'price' => $member ? OnboardingBooking::memberPrice($tool) : round((float) $tool->fee, 2),
			'price_type' => $member ? 'member' : 'nonmember',
			'fk_adherent' => $person['member'] ? (int) $person['member']->rowid : null,
			'fk_socpeople' => $person['contact'] ? (int) $person['contact'] : null,
			'fk_applicant' => $person['applicant'] ? (int) $person['applicant']->rowid : null,
			'firstname' => dol_trunc($person['firstname'], 100, 'right', 'UTF-8', 1),
			'lastname' => dol_trunc($person['lastname'], 100, 'right', 'UTF-8', 1),
		), "status = 'held' AND gb_transaction_id IS NULL");
		return $person;
	}

	/**
	 * @param string $ref Booking ref, from the page address
	 * @param string $key Its token, from the page address
	 * @return object|null The on-the-spot booking
	 */
	public function spotByKey($ref, $key)
	{
		$rows = $this->bk->bookingsWhere("ref = '".$this->db->escape((string) $ref)."' AND source = 'spot'");
		return $rows && hash_equals((string) $rows[0]->token, (string) $key) ? $rows[0] : null;
	}

	/**
	 * For the website's booking page, opened from "Yes, that's me": what is
	 * left before paying (a name for someone new, signing) and what to pay.
	 *
	 * @param string $ref Booking ref
	 * @param string $key Its token
	 * @return array<string,mixed>
	 */
	public function spotInfo($ref, $key)
	{
		$b = $this->spotByKey($ref, $key);
		if (!$b) {
			return array('ok' => false, 'error' => 'invalid', 'http' => 404);
		}
		if (in_array($b->status, array('paid', 'confirmed', 'trained'), true)) {
			return array('ok' => true, 'status' => 'paid', 'tool' => (string) $b->tool, 'trainer' => (string) $b->trainer_first, 'ref' => (string) $b->ref);
		}
		$until = $this->db->jdate($b->hold_until);
		if ($b->status != 'held' || $until < OnboardingService::now()) {
			return array('ok' => false, 'error' => $b->status == 'requested' ? 'answer' : 'expired', 'http' => 410);
		}
		$person = $this->spotPrice($b);
		$b = $this->bk->booking((int) $b->rowid);
		$out = array(
			'ok' => true, 'status' => 'held', 'ref' => (string) $b->ref, 'key' => (string) $b->token, 'email' => (string) $b->email,
			'kind' => $person['kind'], 'needs' => $person['needs'], 'firstname' => $person['firstname'], 'lastname' => $person['lastname'],
			'amount' => round((float) $b->price, 2), 'price_type' => (string) $b->price_type, 'tool' => (string) $b->tool, 'zone' => (string) $b->zone,
			'trainer' => (string) $b->trainer_first, 'when' => 'now', 'wiki' => (string) $b->wiki_url,
			'expires' => gmdate('Y-m-d\TH:i:s\Z', $until), 'expires_label' => OnboardingBooking::fmt($until, 'g:i A'),
		);
		if ($person['kind'] != 'new' && $person['needs']) {
			$app = $this->bk->applicantFor($person);
			$out['sign'] = $app ? $this->svc->signup()->issueToken($app, false) : '';
		}
		if ($person['kind'] != 'new' && !$person['needs'] && (float) $b->price <= 0 && $this->bk->saveBooking((int) $b->rowid, array('status' => 'paid', 'paid_at' => $this->db->idate(OnboardingService::now())), "status = 'held'")) {
			$this->bk->onPaid($this->bk->booking((int) $b->rowid));
			$out['status'] = 'paid';
		}
		return $out;
	}

	/**
	 * Someone new, from the booking page: their name, then the same as spotInfo().
	 *
	 * @param array<string,mixed> $in ref, key, firstname, lastname, discord, notify_events, notify_news, ip
	 * @return array<string,mixed>
	 */
	public function spotNew($in)
	{
		$b = $this->spotByKey(isset($in['ref']) ? (string) $in['ref'] : '', isset($in['key']) ? (string) $in['key'] : '');
		if (!$b || $b->status != 'held') {
			return array('ok' => false, 'error' => 'invalid', 'http' => 404);
		}
		$r = $this->bk->newTrainee(array('email' => (string) $b->email) + $in, 'Created by an on-the-spot training ('.$b->ref.').');
		if (empty($r['ok'])) {
			return $r;
		}
		return $this->spotInfo((string) $b->ref, (string) $b->token);
	}

	/**
	 * Paid: the trainee hears so, and the trainer gets the buttons to say it was done.
	 *
	 * @param object $b Booking
	 * @return void
	 */
	public function spotPaid($b)
	{
		$now = $this->db->idate(OnboardingService::now());
		if (!$this->bk->saveBooking((int) $b->rowid, array('status' => 'confirmed', 'confirmed_at' => $now, 'session_start' => $now, 'trainer_sent' => 'after'), "status = 'paid'")) {
			return;
		}
		$b = $this->bk->booking((int) $b->rowid);
		$this->bk->mailTpl('spot_paid', $b->email, $this->bk->vars($b));
		$this->bk->mailTpl('spot_trainer_paid', $b->trainer_email, $this->bk->vars($b, 'trainer'));
	}

	/**
	 * Requests nobody paid for in time: closed, and the trainer told.
	 *
	 * @param int $now Now
	 * @return int Requests closed
	 */
	public function expireSpot($now)
	{
		$n = 0;
		foreach ($this->bk->bookingsWhere("source = 'spot' AND status IN ('requested', 'held') AND hold_until < '".$this->db->idate($now)."'") as $b) {
			if ($this->bk->saveBooking((int) $b->rowid, array('status' => 'expired'), "status IN ('requested', 'held')")) {
				$this->bk->mailTpl('spot_expired', $b->trainer_email, $this->bk->vars($b, 'trainer'));
				$n++;
			}
		}
		return $n;
	}
}
