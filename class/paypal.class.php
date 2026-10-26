<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * PayPal dues. Every hour (with the Givebutter sync) PayPal's Transaction
 * search API is asked for new transactions. Each subscription payment is
 * matched to a member (an earlier payment from the same PayPal account, then
 * email, then a name only one member has) and recorded on their card as a
 * subscription for the month (or months) it pays, with a line and a fee line
 * on the bank account with ref PAYPAL. Payments already on the card, from the
 * CGWManagement dues import or the yearly statement, are not recorded again.
 * Payments that match nobody wait under Unmatched payments.
 *
 * Needs a PayPal REST app (developer.paypal.com, Live) with Transaction search
 * ticked; its Client ID and Secret go on the setup page.
 */

dol_include_once('/onboarding/class/onboarding.class.php');
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

/**
 * PayPal sync.
 */
class OnboardingPaypal
{
	/** @var DoliDB */
	public $db;
	/** @var OnboardingService */
	public $svc;
	/** @var string Last API error, for the summary */
	public $error = '';
	/** @var string */
	private $token = '';

	/**
	 * @param DoliDB $db Database handler
	 * @param OnboardingService|null $svc Service
	 */
	public function __construct($db, $svc = null)
	{
		$this->db = $db;
		$this->svc = $svc ? $svc : new OnboardingService($db);
	}

	/**
	 * @return bool Client ID and Secret are set
	 */
	public static function configured()
	{
		return getDolGlobalString('ONBOARDING_PAYPAL_CLIENT_ID') !== '' && getDolGlobalString('ONBOARDING_PAYPAL_SECRET') !== '';
	}

	/**
	 * @return string
	 */
	private static function base()
	{
		return rtrim(getDolGlobalString('ONBOARDING_PAYPAL_API_BASE', 'https://api-m.paypal.com'), '/');
	}

	/**
	 * @return string[] PayPal event codes that are dues (T0002 is a subscription payment)
	 */
	public static function duesCodes()
	{
		$codes = array_filter(array_map('trim', explode(',', strtoupper(getDolGlobalString('ONBOARDING_PAYPAL_DUES_CODES', 'T0002')))), 'strlen');
		return $codes ? $codes : array('T0002');
	}

	/**
	 * @return string Access token, '' on failure (see $this->error)
	 */
	private function token()
	{
		if ($this->token !== '') {
			return $this->token;
		}
		$auth = base64_encode(getDolGlobalString('ONBOARDING_PAYPAL_CLIENT_ID').':'.getDolGlobalString('ONBOARDING_PAYPAL_SECRET'));
		$r = getURLContent(self::base().'/v1/oauth2/token', 'POST', 'grant_type=client_credentials', 1, array('Authorization: Basic '.$auth, 'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'), array('http', 'https'), 2);
		$json = isset($r['content']) ? json_decode((string) $r['content'], true) : null;
		if (empty($r['http_code']) || $r['http_code'] != 200 || empty($json['access_token'])) {
			$this->error = 'PayPal refused the Client ID and Secret (HTTP '.(isset($r['http_code']) ? $r['http_code'] : '?').(isset($r['curl_error_msg']) && $r['curl_error_msg'] ? ', '.$r['curl_error_msg'] : '').'). Check them on the setup page, and that the app is a Live app.';
			return '';
		}
		$this->token = (string) $json['access_token'];
		return $this->token;
	}

	/**
	 * @param string $path Path with query
	 * @return array|null
	 */
	private function get($path)
	{
		$token = $this->token();
		if ($token === '') {
			return null;
		}
		$r = getURLContent(self::base().$path, 'GET', '', 1, array('Authorization: Bearer '.$token, 'Accept: application/json'), array('http', 'https'), 2);
		$code = isset($r['http_code']) ? (int) $r['http_code'] : 0;
		if ($code != 200) {
			$this->error = $code == 403
				? 'PayPal refused the transaction search (HTTP 403). Tick Transaction search on the app at developer.paypal.com; PayPal can take a few hours to switch it on.'
				: 'PayPal transaction search failed (HTTP '.($code ?: '?').(isset($r['curl_error_msg']) && $r['curl_error_msg'] ? ', '.$r['curl_error_msg'] : '').').';
			dol_syslog('Onboarding: PayPal GET '.$path.': '.$this->error.' '.(isset($r['content']) ? substr((string) $r['content'], 0, 300) : ''), LOG_WARNING);
			return null;
		}
		$json = json_decode((string) $r['content'], true);
		return is_array($json) ? $json : null;
	}

	/**
	 * @param int $t Timestamp
	 * @return string The date format PayPal wants
	 */
	private static function stamp($t)
	{
		return gmdate('Y-m-d\TH:i:s', $t).'-0000';
	}

	/**
	 * Fetch new PayPal transactions and record the dues among them.
	 *
	 * @return string Summary; contains "failed" when PayPal could not be read
	 */
	public function sync()
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		if (!self::configured()) {
			return 'PayPal not connected';
		}
		$now = OnboardingService::now();
		$last = getDolGlobalInt('ONBOARDING_PAYPAL_LAST_SYNC');
		// PayPal can add a transaction to its search hours after it happened, so
		// look two days back every time; each one is recorded once.
		$from = $last > 0 ? min($last, $now) - 2 * 86400 : $now - 35 * 86400;
		$seen = 0;
		$recorded = 0;
		$refreshed = 0;
		// PayPal answers at most 31 days per question.
		for ($start = $from; $start < $now; $start += 30 * 86400) {
			$end = min($now, $start + 30 * 86400);
			for ($page = 1; $page <= 50; $page++) {
				$json = $this->get('/v1/reporting/transactions?start_date='.rawurlencode(self::stamp($start)).'&end_date='.rawurlencode(self::stamp($end)).'&fields=transaction_info,payer_info&page_size=500&page='.$page);
				if ($json === null) {
					return 'PayPal sync failed: '.$this->error;
				}
				if (!empty($json['last_refreshed_datetime']) && ($t = strtotime((string) $json['last_refreshed_datetime']))) {
					$refreshed = max($refreshed, min($t, $end));
				}
				foreach ((isset($json['transaction_details']) && is_array($json['transaction_details']) ? $json['transaction_details'] : array()) as $t) {
					$seen++;
					if (strpos($this->process($t), 'recorded') === 0) {
						$recorded++;
					}
				}
				if ($page >= (int) (isset($json['total_pages']) ? $json['total_pages'] : 1)) {
					break;
				}
			}
		}
		$recorded += $this->retryUnmatched();
		dolibarr_set_const($this->db, 'ONBOARDING_PAYPAL_LAST_SYNC', $now, 'chaine', 0, '', $conf->entity);
		// How far PayPal's data is known to be complete: what lapses are judged by.
		dolibarr_set_const($this->db, 'ONBOARDING_PAYPAL_SYNCED_THROUGH', $refreshed ? $refreshed : $now - 3 * 3600, 'chaine', 0, 'PayPal transactions are in Dolibarr up to this time', $conf->entity);
		$waiting = $this->count('unmatched');
		return 'PayPal: '.$seen.' transactions seen, '.$recorded.' dues recorded'.($waiting ? ', '.$waiting.' waiting under Unmatched payments' : '');
	}

	/**
	 * @param string $status Status
	 * @return int Rows with it
	 */
	public function count($status)
	{
		global $conf;
		$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_paypal WHERE entity = ".((int) $conf->entity)." AND status = '".$this->db->escape($status)."'");
		$obj = $res ? $this->db->fetch_object($res) : null;
		return $obj ? (int) $obj->n : 0;
	}

	/**
	 * Keep one PayPal transaction if it is dues, and record it when its member is known.
	 *
	 * @param array $t A transaction_details entry
	 * @return string What happened
	 */
	public function process($t)
	{
		global $conf;
		$ti = isset($t['transaction_info']) && is_array($t['transaction_info']) ? $t['transaction_info'] : array();
		$pi = isset($t['payer_info']) && is_array($t['payer_info']) ? $t['payer_info'] : array();
		$txid = isset($ti['transaction_id']) ? (string) $ti['transaction_id'] : '';
		$code = strtoupper(isset($ti['transaction_event_code']) ? (string) $ti['transaction_event_code'] : '');
		$amount = isset($ti['transaction_amount']['value']) ? (float) $ti['transaction_amount']['value'] : 0;
		if ($txid === '' || !in_array($code, self::duesCodes(), true)) {
			return 'ignored: not dues';
		}
		if ((isset($ti['transaction_status']) ? (string) $ti['transaction_status'] : '') !== 'S' || $amount <= 0) {
			return 'ignored: not a completed payment';
		}
		$when = !empty($ti['transaction_initiation_date']) ? strtotime((string) $ti['transaction_initiation_date']) : 0;
		$fee = isset($ti['fee_amount']['value']) ? abs((float) $ti['fee_amount']['value']) : 0;
		$email = OnboardingService::cleanEmail(isset($pi['email_address']) ? $pi['email_address'] : '');
		$first = isset($pi['payer_name']['given_name']) ? trim((string) $pi['payer_name']['given_name']) : '';
		$last = isset($pi['payer_name']['surname']) ? trim((string) $pi['payer_name']['surname']) : '';
		$name = trim($first.' '.$last);
		if ($name === '' && isset($pi['payer_name']['alternate_full_name'])) {
			$name = trim((string) $pi['payer_name']['alternate_full_name']);
		}
		$payer = isset($pi['account_id']) ? (string) $pi['account_id'] : '';

		$p = $this->db->prefix();
		$res = $this->db->query("SELECT rowid, status FROM ".$p."onboarding_paypal WHERE txid = '".$this->db->escape($txid)."' AND entity = ".((int) $conf->entity));
		$row = $res ? $this->db->fetch_object($res) : null;
		if ($row && $row->status != 'unmatched') {
			return 'duplicate';
		}
		if (!$row) {
			$sql = "INSERT INTO ".$p."onboarding_paypal (entity, txid, payer_id, email, name, amount, fee, event_code, transacted_at, status, datec) VALUES (";
			$sql .= ((int) $conf->entity).", '".$this->db->escape($txid)."', '".$this->db->escape($payer)."', '".$this->db->escape($email)."', '".$this->db->escape(dol_trunc($name, 190, 'right', 'UTF-8', 1))."', ";
			$sql .= price2num($amount).", ".price2num($fee).", '".$this->db->escape($code)."', '".$this->db->idate($when ? $when : OnboardingService::now())."', 'unmatched', '".$this->db->idate(OnboardingService::now())."')";
			if (!$this->db->query($sql)) {
				return 'duplicate';
			}
			$rowid = (int) $this->db->last_insert_id($p.'onboarding_paypal');
		} else {
			$rowid = (int) $row->rowid;
		}
		$adhId = $this->memberFor($payer, $email, $first, $last);
		if (!$adhId) {
			return 'unmatched';
		}
		return $this->record($rowid, $adhId);
	}

	/**
	 * The member who made a payment: whoever earlier payments from the same
	 * PayPal account went to, else by email, else by a name only one member has.
	 *
	 * @param string $payer PayPal account id
	 * @param string $email Email
	 * @param string $first First name
	 * @param string $last Last name
	 * @return int Member id, 0 when not sure
	 */
	public function memberFor($payer, $email, $first, $last)
	{
		if ($payer !== '') {
			$res = $this->db->query("SELECT fk_adherent FROM ".$this->db->prefix()."onboarding_paypal WHERE payer_id = '".$this->db->escape($payer)."' AND status = 'matched' AND fk_adherent > 0 ORDER BY transacted_at DESC LIMIT 1");
			$obj = $res ? $this->db->fetch_object($res) : null;
			if ($obj) {
				return (int) $obj->fk_adherent;
			}
		}
		return $this->svc->memberFor($email, $first, $last);
	}

	/**
	 * Try the unmatched ones again: a member may have been added, or the same
	 * PayPal account assigned by hand.
	 *
	 * @return int Recorded
	 */
	public function retryUnmatched()
	{
		global $conf;
		$res = $this->db->query("SELECT rowid, payer_id, email, name FROM ".$this->db->prefix()."onboarding_paypal WHERE entity = ".((int) $conf->entity)." AND status = 'unmatched' ORDER BY transacted_at, rowid");
		$rows = array();
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$rows[] = $obj;
		}
		$n = 0;
		foreach ($rows as $obj) {
			$parts = explode(' ', trim((string) $obj->name));
			$last = count($parts) > 1 ? array_pop($parts) : '';
			$adhId = $this->memberFor((string) $obj->payer_id, (string) $obj->email, implode(' ', $parts), $last);
			if ($adhId && strpos($this->record((int) $obj->rowid, $adhId), 'recorded') === 0) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * @param int $rowid Row
	 * @return object|null
	 */
	public function fetch($rowid)
	{
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_paypal WHERE rowid = ".((int) $rowid));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * Months a payment pays for: its amount over the monthly PayPal dues.
	 *
	 * @param float $amount Amount
	 * @return int 1 to 12
	 */
	public static function months($amount)
	{
		$monthly = (float) getDolGlobalString('ONBOARDING_PAYPAL_MONTHLY', '40');
		if ($monthly <= 0) {
			return 1;
		}
		return max(1, min(12, (int) round($amount / $monthly)));
	}

	/**
	 * Record a kept payment on a member's card, unless it is already there.
	 *
	 * @param int $rowid Row in onboarding_paypal
	 * @param int $adhId Member
	 * @return string "recorded ..." or why not
	 */
	public function record($rowid, $adhId)
	{
		global $user;
		$row = $this->fetch($rowid);
		if (!$row || $row->status == 'matched') {
			return 'duplicate';
		}
		$adh = new Adherent($this->db);
		if ($adh->fetch((int) $adhId) <= 0) {
			return 'error: no such member';
		}
		$p = $this->db->prefix();
		// Claim it first, so a second sync cannot record it twice.
		$claim = $this->db->query("UPDATE ".$p."onboarding_paypal SET status = 'matched', fk_adherent = ".((int) $adh->id)." WHERE status = 'unmatched' AND rowid = ".((int) $rowid));
		if (!$claim || $this->db->affected_rows($claim) < 1) {
			return 'duplicate';
		}
		$when = (int) $this->db->jdate($row->transacted_at);
		$amount = (float) $row->amount;
		$months = self::months($amount);
		$who = trim($adh->firstname.' '.$adh->lastname);

		// Already on the card: from the dues import or the yearly statement
		// ("PayPal <id>" or "PayPal 2026-10-09 Name"), or paid within 3 days for
		// the same amount.
		$recorded = false;
		$labels = array('PayPal '.$row->txid);
		foreach (array(-1, 0, 1) as $d) {
			$labels[] = 'PayPal '.date('Y-m-d', $when + $d * 86400);
		}
		$res = @$this->db->query("SELECT s.note, s.subscription, s.dateadh, b.dateo FROM ".$p."subscription AS s LEFT JOIN ".$p."bank AS b ON b.rowid = s.fk_bank WHERE s.fk_adherent = ".((int) $adh->id));
		if (!$res) {
			$res = $this->db->query("SELECT note, subscription, dateadh, NULL AS dateo FROM ".$p."subscription WHERE fk_adherent = ".((int) $adh->id));
		}
		while ($res && ($o = $this->db->fetch_object($res))) {
			foreach ($labels as $l) {
				if (stripos((string) $o->note, $l) !== false) {
					$recorded = true;
				}
			}
			$paid = $o->dateo ? (int) $this->db->jdate($o->dateo) : (int) $this->db->jdate($o->dateadh);
			if (abs($paid - $when) <= 3 * 86400 && abs((float) $o->subscription - $amount) < 0.01) {
				$recorded = true;
			}
		}

		$actor = $this->svc->actor();
		$saved = $user;
		$user = $actor; // Adherent::subscription() reads the global.
		$note = 'already on the card';
		if (!$recorded) {
			$end = dol_time_plus_duree(dol_time_plus_duree($when, $months, 'm'), -1, 'd');
			$label = 'PayPal '.$row->txid.' ('.date('Y-m-d', $when).' '.$who.')';
			$subid = $adh->subscription($when, $amount, 0, '', $label, '', '', '', $end);
			if ($subid <= 0) {
				$user = $saved;
				$this->db->query("UPDATE ".$p."onboarding_paypal SET status = 'unmatched', fk_adherent = NULL, note = '".$this->db->escape(dol_trunc('could not record: '.$adh->error, 250))."' WHERE rowid = ".((int) $rowid));
				return 'error: '.$adh->error;
			}
			$note = $this->bank($adh, $subid, $row, $when, $who) ? 'recorded with bank lines' : 'recorded';
		} else {
			$this->svc->extendToAnniversary((int) $adh->id, $when, $months);
		}
		// A lapsed member who pays again is a member again.
		$adh->fetch((int) $adh->id);
		if ((int) $adh->statut == Adherent::STATUS_RESILIATED) {
			$adh->validate($actor);
		}
		if (empty($adh->array_options['options_payment_channel']) || $adh->array_options['options_payment_channel'] == 'None') {
			$adh->array_options['options_payment_channel'] = 'PayPal';
			$adh->insertExtraFields();
		}
		$user = $saved;
		// Reminders and lapses work from the applicant row: a payment ends any
		// payment problem, as a Givebutter payment does.
		$app = $this->svc->applicantForMember((int) $adh->id, (string) $row->email);
		if ($app && $app->payment_state != 'active') {
			$this->svc->save($app->rowid, array('payment_state' => 'active', 'problem_since' => null, 'reminders_sent' => null));
			$this->svc->refreshStage($app->rowid);
		}
		$this->db->query("UPDATE ".$p."onboarding_paypal SET note = '".$this->db->escape($note)."' WHERE rowid = ".((int) $rowid));
		return ($recorded ? 'matched' : 'recorded').': '.$who.' '.price($amount).' '.date('Y-m-d', $when);
	}

	/**
	 * The payment and PayPal's fee on the bank account with ref PAYPAL, linked
	 * to the member and the subscription, as the dues import records them.
	 *
	 * @param Adherent $adh Member
	 * @param int $subid Subscription
	 * @param object $row Payment
	 * @param int $when When
	 * @param string $who Name
	 * @return bool Recorded on a bank account
	 */
	private function bank($adh, $subid, $row, $when, $who)
	{
		if (!isModEnabled('banque') && !isModEnabled('bank')) {
			return false;
		}
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$acc = new Account($this->db);
		if ($acc->fetch(0, 'PAYPAL') <= 0) {
			return false;
		}
		$actor = $this->svc->actor();
		$month = date('Y-m', $when);
		$line = $acc->addline($when, 'VIR', 'Dues '.$month.' '.$who, (float) $row->amount, '', 0, $actor);
		if ($line <= 0) {
			dol_syslog('Onboarding: PayPal bank line failed: '.$acc->error, LOG_WARNING);
			return false;
		}
		$acc->add_url_line($line, $adh->id, DOL_URL_ROOT.'/adherents/card.php?rowid=', $who, 'member');
		$this->db->query("UPDATE ".$this->db->prefix()."subscription SET fk_bank = ".((int) $line)." WHERE rowid = ".((int) $subid));
		if ((float) $row->fee > 0) {
			$acc->addline($when, 'VIR', 'PayPal fee, dues '.$month.' '.$who, -(float) $row->fee, '', 0, $actor);
		}
		return true;
	}
}
