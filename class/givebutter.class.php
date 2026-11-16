<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');

/**
 * Givebutter: the API client, webhooks and the hourly sync, and turning a
 * dues payment into a member subscription.
 * Reached through OnboardingService::givebutter().
 */
class OnboardingGivebutter
{
	/** @var DoliDB */
	public $db;
	/** @var OnboardingService */
	public $svc;

	/**
	 * @param OnboardingService $svc The shared service
	 */
	public function __construct($svc)
	{
		$this->svc = $svc;
		$this->db = $svc->db;
	}
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
	 * A Givebutter event that came through the website rather than straight from
	 * Givebutter, so with no signature to check. Only the event's id is taken
	 * from it: the transaction or plan itself is read back from Givebutter, and
	 * that copy is what gets applied. A made-up event finds nothing.
	 *
	 * @param string $event Event name
	 * @param array $data Event data, as the website passed it on
	 * @return array{0:bool,1:string} Whether it was checked (false: refused or Givebutter unreachable), and what was done
	 */
	public function handleVerifiedEvent($event, $data)
	{
		if (getDolGlobalString('ONBOARDING_GB_API_KEY') === '') {
			return array(false, 'refused: no Givebutter API key to check the event with');
		}
		$id = is_array($data) && isset($data['id']) && is_scalar($data['id']) ? (string) $data['id'] : '';
		if ($id === '') {
			return array(true, 'ignored: no id');
		}
		if ($event == 'transaction.succeeded') {
			$path = '/transactions/';
		} elseif (strpos($event, 'plan.') === 0) {
			$path = '/plans/';
		} else {
			return array(true, 'ignored: '.$event);
		}
		$json = $this->gbGet($path.rawurlencode($id));
		if (isset($json['data']) && is_array($json['data'])) {
			$json = $json['data'];
		}
		if (!is_array($json) || !isset($json['id']) || (string) $json['id'] !== $id) {
			return array(false, 'refused: '.$event.' '.$id.' could not be read back from Givebutter');
		}
		if ($path == '/transactions/') {
			return array(true, $this->processTransaction($json));
		}
		// The plan's own status decides, not the event name the website sent.
		return array(true, $this->processPlan($json));
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
			$app = $this->svc->one("gb_plan_id = '".$this->db->escape($planId)."'");
		}
		if (!$app && $contactId !== '') {
			$app = $this->svc->one("gb_contact_id = '".$this->db->escape($contactId)."'");
		}
		if (!$app) {
			$app = $this->svc->findByEmail($email);
		}
		return $app;
	}

	/**
	 * Who a payment belongs to: a signup (by plan, contact or email), else a
	 * member who was here before the signup form, by email or by a name only
	 * one member has. A member found that way gets an applicant row, so the
	 * next payment matches by its Givebutter contact.
	 *
	 * @param string $contactId Givebutter contact id
	 * @param string $planId Givebutter plan id
	 * @param string $email Email typed at checkout
	 * @param string $first First name at Givebutter
	 * @param string $last Last name at Givebutter
	 * @return object|null Applicant row
	 */
	public function payer($contactId, $planId, $email, $first = '', $last = '')
	{
		$app = $this->match($contactId, $planId, $email);
		if (!$app) {
			$mid = $this->svc->memberFor($email, $first, $last);
			if ($mid > 0) {
				$app = $this->svc->applicantForMember($mid, $email);
			}
		}
		return $app;
	}

	/**
	 * Try again to match the payments nobody claimed, oldest first: a member
	 * may have been added, or a payment assigned by hand, since they came in.
	 *
	 * @return int Payments applied
	 */
	public function retryUnmatched()
	{
		global $conf;
		$res = $this->db->query("SELECT rowid, gb_contact_id, gb_plan_id, email, firstname, lastname FROM ".$this->db->prefix()."onboarding_payment WHERE entity = ".((int) $conf->entity)." AND status = 'unmatched' ORDER BY transacted_at, rowid");
		$rows = array();
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$rows[] = $obj;
		}
		$applied = 0;
		foreach ($rows as $obj) {
			$app = $this->payer((string) $obj->gb_contact_id, (string) $obj->gb_plan_id, (string) $obj->email, (string) $obj->firstname, (string) $obj->lastname);
			if ($app && strpos($this->applyPayment($app, $obj->rowid), 'applied') === 0) {
				$applied++;
			}
		}
		return $applied;
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
			$training = new OnboardingTraining($this->db, $this->svc);
			return $training->process($t);
		}
		$want = getDolGlobalString('ONBOARDING_GB_CAMPAIGN_CODE');
		if ($want !== '' && strcasecmp($campaign, $want) != 0) {
			return 'ignored: campaign '.$campaign;
		}

		$planId = isset($t['plan_id']) ? (string) $t['plan_id'] : '';
		$contactId = isset($t['contact_id']) ? (string) $t['contact_id'] : '';
		$email = OnboardingService::cleanEmail(isset($t['email']) ? $t['email'] : '');
		$amount = isset($t['amount']) ? (float) $t['amount'] : 0;
		$when = !empty($t['transacted_at']) ? strtotime((string) $t['transacted_at']) : 0;
		if (!$when) {
			$when = OnboardingService::now();
		}

		$table = $this->db->prefix()."onboarding_payment";
		$res = $this->db->query("SELECT rowid, status FROM ".$table." WHERE gb_transaction_id = '".$this->db->escape($txid)."' AND entity = ".((int) $conf->entity));
		$row = $res ? $this->db->fetch_object($res) : null;
		if ($row && $row->status != 'unmatched') {
			return 'duplicate';
		}

		$first = substr((string) (isset($t['first_name']) ? $t['first_name'] : ''), 0, 100);
		$last = substr((string) (isset($t['last_name']) ? $t['last_name'] : ''), 0, 100);
		$app = $this->payer($contactId, $planId, $email, $first, $last);
		if (!$row) {
			$rowid = OnboardingSql::insert($this->db, 'onboarding_payment', array(
				'entity' => (int) $conf->entity,
				'gb_transaction_id' => $txid,
				'gb_plan_id' => $planId,
				'gb_contact_id' => $contactId,
				'email' => $email,
				'firstname' => $first,
				'lastname' => $last,
				'amount' => (float) price2num($amount),
				'campaign_code' => $campaign,
				'transacted_at' => $this->db->idate($when),
				'status' => 'unmatched',
				'datec' => $this->db->idate(OnboardingService::now()),
			));
			if (!$rowid) {
				// Lost a race with another delivery of the same event.
				return 'duplicate';
			}
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

		$amount = (float) $pay->amount;
		$when = $this->db->jdate($pay->transacted_at);

		// Period paid for. A payment with a plan needs the plan's length, both for
		// the period and for the tier; if Givebutter cannot say, guessing one month
		// would record a yearly payment as a month and make anyone paying the
		// Standard rate yearly a Supporter. Leave it for the hourly sync instead.
		$months = 1;
		if ($pay->gb_plan_id) {
			$months = $this->planMonths($pay->gb_plan_id);
			if (!$months) {
				return $this->unclaim($pay, $app, 'the plan length could not be read from Givebutter');
			}
		}
		// The Supporter rate is a monthly amount.
		$supporter = $amount / $months >= (float) getDolGlobalString('ONBOARDING_DUES_SUPPORTER', '100') - 0.005;
		$typeid = getDolGlobalInt($supporter ? 'ONBOARDING_TYPE_SUPPORTER' : 'ONBOARDING_TYPE_STANDARD');

		$actor = $this->svc->actor();
		$saved = $user;
		$user = $actor; // Adherent::subscription() reads the global.

		$adh = $this->svc->ensureMember($app, $typeid);
		if (!$adh) {
			$user = $saved;
			return $this->unclaim($pay, $app, 'the member record could not be created');
		}
		// A Standard member who starts paying the Supporter rate, or the reverse.
		$managed = array(getDolGlobalInt('ONBOARDING_TYPE_STANDARD'), getDolGlobalInt('ONBOARDING_TYPE_SUPPORTER'));
		if ($typeid > 0 && $adh->typeid != $typeid && in_array((int) $adh->typeid, $managed)) {
			$this->db->query("UPDATE ".$this->db->prefix()."adherent SET fk_adherent_type = ".((int) $typeid)." WHERE rowid = ".((int) $adh->id));
		}
		// Dues already on the card for this payment: recorded by import_dues.py
		// from an export ("Givebutter 2026-09-18 Name"), or by an earlier run.
		$subs = 0;
		$recorded = false;
		$dates = array();
		foreach (array(-1, 0, 1) as $d) {
			$dates[] = 'Givebutter '.date('Y-m-d', $when + $d * 86400);
		}
		$res = $this->db->query("SELECT dateadh, subscription, note FROM ".$this->db->prefix()."subscription WHERE fk_adherent = ".((int) $adh->id));
		while ($res && ($o = $this->db->fetch_object($res))) {
			$subs++;
			$note = (string) $o->note;
			$start = $this->db->jdate($o->dateadh);
			if (($pay->gb_transaction_id !== '' && strpos($note, $pay->gb_transaction_id) !== false)
				|| (abs($start - $when) <= 3 * 86400 && abs((float) $o->subscription - $amount) < 0.01)) {
				$recorded = true;
			}
			foreach ($dates as $label) {
				if (strpos($note, $label) !== false) {
					$recorded = true;
				}
			}
		}
		// Welcome emails only for someone whose first dues these are.
		$first = $app->payment_state == 'none' && $subs == 0;

		$adh->array_options['options_payment_channel'] = 'Givebutter';
		$adh->insertExtraFields();
		if ($adh->statut != Adherent::STATUS_VALIDATED && $adh->statut != Adherent::STATUS_EXCLUDED) {
			$adh->validate($actor);
		}

		if (!$recorded) {
			$end = dol_time_plus_duree(dol_time_plus_duree($when, $months, 'm'), -1, 'd');
			$subid = $adh->subscription($when, $amount, 0, '', 'Givebutter '.$pay->gb_transaction_id, '', '', '', $end);
			if ($subid <= 0) {
				$user = $saved;
				return $this->unclaim($pay, $app, 'Dolibarr refused the subscription: '.$adh->error);
			}
		} else {
			$this->extendToAnniversary((int) $adh->id, $when, $months);
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
		$this->svc->save($app->rowid, $fields);
		$app = $this->svc->refreshStage($app->rowid);

		if ($first) {
			$missing = OnboardingService::paperworkDone($app) ? '' : "\n\nNote: their waiver, agreement or ID photo is still missing.";
			$this->svc->mail(getDolGlobalString('ONBOARDING_STAFF_EMAIL'), 'New paying member: '.$app->firstname.' '.$app->lastname, $app->firstname.' '.$app->lastname.' <'.$app->email.'> has set up dues ('.price($amount).') and needs a badge.'.$missing);
			$this->svc->mail($app->email, $this->svc->template('ONBOARDING_MAIL_WELCOME_SUBJECT', $app), $this->svc->template('ONBOARDING_MAIL_WELCOME_BODY', $app));
		}
		return 'applied to applicant '.$app->rowid.($recorded ? ' (dues were already recorded)' : '');
	}

	/**
	 * How many months one payment on a recurring plan pays for.
	 *
	 * @param string $planId Givebutter plan id
	 * @return int 1, 3 or 12; 0 if Givebutter could not be asked or gave no frequency
	 */
	public function planMonths($planId)
	{
		$plan = $this->gbGet('/plans/'.rawurlencode($planId));
		if (isset($plan['data'])) {
			$plan = $plan['data'];
		}
		$freq = is_array($plan) && isset($plan['frequency']) ? strtolower((string) $plan['frequency']) : '';
		if ($freq === '') {
			return 0;
		}
		if (strpos($freq, 'year') !== false || $freq == 'annually') {
			return 12;
		}
		if (strpos($freq, 'quarter') !== false) {
			return 3;
		}
		return 1;
	}

	/**
	 * Give back a payment applyPayment() claimed but could not record, so the
	 * hourly sync tries it again, and tell staff.
	 *
	 * @param object $pay Row in onboarding_payment
	 * @param object $app Applicant row
	 * @param string $why What went wrong
	 * @return string For the log
	 */
	private function unclaim($pay, $app, $why)
	{
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_payment SET status = 'unmatched' WHERE rowid = ".((int) $pay->rowid));
		dol_syslog('Onboarding: payment '.$pay->gb_transaction_id.' not applied: '.$why, LOG_ERR);
		$this->svc->alert(
			'givebutter-payment',
			'A dues payment could not be recorded',
			'The Givebutter payment '.$pay->gb_transaction_id.' ('.price($pay->amount).' from '.trim($app->firstname.' '.$app->lastname).' <'.$app->email.'>) was not recorded: '.$why.".\n\nIt is left unmatched and the hourly sync tries it again. If this keeps happening, check the Givebutter API key on the module setup page, or record the dues by hand on the member card."
		);
		return 'error: '.$why;
	}

	/**
	 * The dues import records a payment as the calendar month it falls in
	 * (paid 28 September: 1 to 30 September), but Givebutter charges again on
	 * the same day next month, so the member looked expired for four weeks.
	 * Make the subscription that holds a payment run until the day before the
	 * next charge, and the member's end date with it. Only ever lengthens.
	 *
	 * @param int $adhId Member
	 * @param int $when When the payment was made
	 * @param int $months Period it pays for
	 * @return bool Something was changed
	 */
	public function extendToAnniversary($adhId, $when, $months = 1)
	{
		$p = $this->db->prefix();
		$target = dol_time_plus_duree(dol_time_plus_duree($when, max(1, (int) $months), 'm'), -1, 'd');
		$res = $this->db->query("SELECT rowid, datef FROM ".$p."subscription WHERE fk_adherent = ".((int) $adhId)." AND dateadh <= '".$this->db->idate($when + 86400)."' AND datef >= '".$this->db->idate($when - 86400)."' ORDER BY datef DESC LIMIT 1");
		$sub = $res ? $this->db->fetch_object($res) : null;
		if (!$sub || $this->db->jdate($sub->datef) >= $target - 3600) {
			return false;
		}
		$this->db->query("UPDATE ".$p."subscription SET datef = '".$this->db->idate($target)."' WHERE rowid = ".((int) $sub->rowid));
		$this->db->query("UPDATE ".$p."adherent SET datefin = '".$this->db->idate($target)."' WHERE rowid = ".((int) $adhId)." AND (datefin IS NULL OR datefin < '".$this->db->idate($target)."')");
		return true;
	}

	/**
	 * Fix periods recorded as calendar months for Givebutter payments already
	 * matched (see extendToAnniversary). Run daily; does nothing once fixed.
	 *
	 * @return int Members changed
	 */
	public function repairPeriods()
	{
		global $conf;
		$res = $this->db->query("SELECT a.fk_adherent, p.transacted_at FROM ".$this->db->prefix()."onboarding_payment AS p INNER JOIN ".$this->db->prefix()."onboarding_applicant AS a ON a.rowid = p.fk_applicant WHERE p.entity = ".((int) $conf->entity)." AND p.status = 'matched' AND a.fk_adherent > 0 AND p.transacted_at > '".$this->db->idate(OnboardingService::now() - 400 * 86400)."' ORDER BY p.transacted_at");
		$rows = array();
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$rows[] = $obj;
		}
		$changed = array();
		foreach ($rows as $obj) {
			if ($this->extendToAnniversary((int) $obj->fk_adherent, (int) $this->db->jdate($obj->transacted_at))) {
				$changed[(int) $obj->fk_adherent] = true;
			}
		}
		return count($changed);
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
		$app = $this->svc->one("gb_plan_id = '".$this->db->escape($planId)."'");
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
				$this->svc->save($app->rowid, array('payment_state' => 'active', 'problem_since' => null, 'reminders_sent' => null));
				$this->svc->refreshStage($app->rowid);
			}
			return 'plan active';
		}
		// A cancelled plan has still paid up to some date: the reminders and the
		// grace period count from then, not from the day it was cancelled.
		$since = 0;
		if ($state == 'cancelled') {
			$datefin = 0;
			if ($app->fk_adherent > 0) {
				$res = $this->db->query("SELECT datefin FROM ".$this->db->prefix()."adherent WHERE rowid = ".((int) $app->fk_adherent));
				$o = $res ? $this->db->fetch_object($res) : null;
				$datefin = $o && $o->datefin ? (int) $this->db->jdate($o->datefin) : 0;
			}
			$since = max(OnboardingService::now(), $this->svc->dues()->paidThrough($app, $datefin));
		}
		$this->svc->dues()->setProblem($app, $state, $since);
		return 'plan '.$state;
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
		$started = OnboardingService::now();
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

		$applied += $this->retryUnmatched();

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

		// Move the start of the next sync on only when every page was read; with
		// pages left over, the next run reads from the same point again.
		if ($url === '') {
			dolibarr_set_const($this->db, 'ONBOARDING_GB_LAST_SYNC', $started, 'chaine', 0, '', $conf->entity);
		}
		return $seen.' transactions seen, '.$applied.' applied, '.$plans.' plans checked'.($url !== '' ? ' (stopped at '.$page.' pages; the next sync starts from the same point)' : '');
	}

	/**
	 * Ask Givebutter for the payments of the last hour and apply any not seen
	 * yet. Called while someone waits on the booking page and by the 15-minute
	 * job when a booking is waiting for payment, so a lost webhook delays a
	 * confirmation by seconds, not an hour. At most once every 20 seconds.
	 *
	 * @return int Payments applied by this call
	 */
	public function quickSync()
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		if (!getDolGlobalString('ONBOARDING_GB_API_KEY')) {
			return 0;
		}
		$now = OnboardingService::now();
		$last = getDolGlobalInt('ONBOARDING_GB_QUICK_SYNC');
		if ($last > 0 && $last <= $now && $now - $last < 20) {
			return 0;
		}
		dolibarr_set_const($this->db, 'ONBOARDING_GB_QUICK_SYNC', $now, 'chaine', 0, '', $conf->entity);
		$json = $this->gbGet('/transactions?updatedAfter='.rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $now - 3600)));
		$applied = 0;
		foreach ((isset($json['data']) && is_array($json['data']) ? $json['data'] : array()) as $t) {
			$r = $this->processTransaction($t);
			if (strpos($r, 'applied') === 0 || preg_match('/^booking .* paid/', $r) || preg_match('/recorded/', $r)) {
				$applied++;
				$this->noteMissedWebhook();
			}
		}
		return $applied;
	}

	/**
	 * Remember a Givebutter call, for the setup page and the missed-webhook alert.
	 *
	 * @param string $result What was done, or why it was refused
	 * @param bool $refused The call was refused (bad signature, not connected)
	 * @return void
	 */
	public function noteWebhook($result, $refused = false)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$name = $refused ? 'ONBOARDING_GB_WEBHOOK_REFUSED' : 'ONBOARDING_GB_WEBHOOK_LAST';
		dolibarr_set_const($this->db, $name, OnboardingService::now().' '.dol_trunc((string) $result, 200, 'right', 'UTF-8', 1), 'chaine', 0, '', $conf->entity);
		if (!$refused) {
			dolibarr_del_const($this->db, 'ONBOARDING_GB_MISSED', $conf->entity);
			$this->svc->alertClear('givebutter-webhook', 'Givebutter webhooks reach Dolibarr again.');
		}
	}

	/**
	 * @param string $name ONBOARDING_GB_WEBHOOK_LAST or ONBOARDING_GB_WEBHOOK_REFUSED
	 * @return array{0:int,1:string} When, and what happened
	 */
	public static function webhookNote($name)
	{
		$v = getDolGlobalString($name);
		$sp = strpos($v, ' ');
		return $v === '' ? array(0, '') : array((int) ($sp === false ? $v : substr($v, 0, $sp)), $sp === false ? '' : substr($v, $sp + 1));
	}

	/**
	 * A payment was picked up by asking Givebutter: its webhook did not arrive.
	 * Three in a row with no webhook in between emails the alerts address.
	 *
	 * @return void
	 */
	public function noteMissedWebhook()
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$n = getDolGlobalInt('ONBOARDING_GB_MISSED') + 1;
		dolibarr_set_const($this->db, 'ONBOARDING_GB_MISSED', $n, 'chaine', 0, '', $conf->entity);
		if ($n >= 3 && getDolGlobalString('ONBOARDING_GB_WEBHOOK_ID') !== '') {
			list($when, $what) = self::webhookNote('ONBOARDING_GB_WEBHOOK_LAST');
			list($rwhen, $rwhat) = self::webhookNote('ONBOARDING_GB_WEBHOOK_REFUSED');
			$this->svc->alert('givebutter-webhook', 'Givebutter webhooks are not reaching Dolibarr', 'The last '.$n.' Givebutter payments were found by asking Givebutter, not reported by its webhook. Confirmations still go out, but later than they should.'
				."\n\nThe webhook points at: ".$this->webhookUrl()
				."\nLast webhook received: ".($when ? dol_print_date($when, 'dayhour').' ('.$what.')' : 'never')
				."\nLast webhook refused: ".($rwhen ? dol_print_date($rwhen, 'dayhour').' ('.$rwhat.')' : 'none')
				."\n\nCheck Givebutter's webhook delivery log (Settings, Developers, Webhooks). If that address is internal or behind a login or a Cloudflare challenge, set \"This Dolibarr's public address\" on the setup page and press Reconnect, or point the webhook at the website (https://columbiagadgetworks.org/api/givebutter-webhook), which passes it on.");
		}
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
		return OnboardingService::publicUrl('givebutter.php');
	}
}
