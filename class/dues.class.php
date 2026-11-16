<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');

/**
 * Dues reminders and lapses: how long a member has paid for, the reminder
 * clock when a payment fails or a plan is cancelled, and ending membership.
 * Reached through OnboardingService::dues().
 */
class OnboardingDues
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
	 * How long a member's Givebutter dues really cover: their end date, or a
	 * month after the newest Givebutter payment matched to them, whichever is
	 * later.
	 *
	 * @param object $app Applicant row
	 * @param int $datefin Member end date
	 * @return int
	 */
	public function paidThrough($app, $datefin)
	{
		$p = $this->db->prefix();
		$ends = array((int) $datefin);
		$res = $this->db->query("SELECT MAX(transacted_at) AS t FROM ".$p."onboarding_payment WHERE fk_applicant = ".((int) $app->rowid)." AND status = 'matched'");
		$obj = $res ? $this->db->fetch_object($res) : null;
		if ($obj && $obj->t) {
			$ends[] = dol_time_plus_duree(dol_time_plus_duree((int) $this->db->jdate($obj->t), 1, 'm'), -1, 'd');
		}
		if ($app->fk_adherent > 0) {
			// PayPal: the newest payment the PayPal sync matched to them.
			$res = @$this->db->query("SELECT MAX(transacted_at) AS t, MAX(amount) AS a FROM ".$p."onboarding_paypal WHERE fk_adherent = ".((int) $app->fk_adherent)." AND status = 'matched'");
			$obj = $res ? $this->db->fetch_object($res) : null;
			if ($obj && $obj->t) {
				dol_include_once('/onboarding/class/paypal.class.php');
				$ends[] = dol_time_plus_duree(dol_time_plus_duree((int) $this->db->jdate($obj->t), OnboardingPaypal::months((float) $obj->a), 'm'), -1, 'd');
			}
			// A period the dues import recorded as a calendar month: it really runs
			// from the day it was paid (its bank line) for as many months.
			$res = @$this->db->query("SELECT s.dateadh, s.datef, b.dateo FROM ".$p."subscription AS s INNER JOIN ".$p."bank AS b ON b.rowid = s.fk_bank WHERE s.fk_adherent = ".((int) $app->fk_adherent)." ORDER BY s.datef DESC LIMIT 1");
			$obj = $res ? $this->db->fetch_object($res) : null;
			if ($obj && $obj->dateo && $obj->datef) {
				$months = max(1, (int) round(((int) $this->db->jdate($obj->datef) - (int) $this->db->jdate($obj->dateadh)) / (30.44 * 86400)));
				$ends[] = dol_time_plus_duree(dol_time_plus_duree((int) $this->db->jdate($obj->dateo), $months, 'm'), -1, 'd');
			}
		}
		return max($ends);
	}

	/**
	 * Undo the old "held as paid" end dates (0.8.2 to 0.10.x held PayPal and
	 * Legacy members' end date at tomorrow while PayPal data was missing): a
	 * member whose end date is later than their newest subscription gets the
	 * subscription's end back. Now that PayPal payments come in by themselves,
	 * the end date is simply what was paid.
	 *
	 * @return int Members changed
	 */
	public function restoreEndDates()
	{
		$p = $this->db->prefix();
		$sql = "SELECT a.rowid, MAX(s.datef) AS paidto FROM ".$p."adherent AS a INNER JOIN ".$p."subscription AS s ON s.fk_adherent = a.rowid";
		$sql .= " LEFT JOIN ".$p."adherent_extrafields AS e ON e.fk_object = a.rowid LEFT JOIN ".$p."adherent_type AS t ON t.rowid = a.fk_adherent_type";
		$sql .= " WHERE a.entity IN (".getEntity('adherent').") AND (e.payment_channel = 'PayPal' OR LOWER(t.libelle) = 'legacy' OR COALESCE(e.payment_channel, '') = '')";
		$sql .= " GROUP BY a.rowid, a.datefin HAVING a.datefin > MAX(s.datef)";
		$res = $this->db->query($sql);
		$rows = array();
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$rows[] = $obj;
		}
		foreach ($rows as $obj) {
			$this->db->query("UPDATE ".$p."adherent SET datefin = '".$this->db->escape($obj->paidto)."' WHERE rowid = ".((int) $obj->rowid));
		}
		return count($rows);
	}

	/**
	 * PayPal members added before the signup form have no applicant row, which
	 * is what reminders and lapses work from. Make one for each.
	 *
	 * @return void
	 */
	public function ensurePaypalApplicants()
	{
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT a.rowid FROM ".$p."adherent AS a INNER JOIN ".$p."adherent_extrafields AS e ON e.fk_object = a.rowid LEFT JOIN ".$p."onboarding_applicant AS o ON o.fk_adherent = a.rowid WHERE a.entity IN (".getEntity('adherent').") AND a.statut = ".((int) Adherent::STATUS_VALIDATED)." AND e.payment_channel = 'PayPal' AND o.rowid IS NULL");
		$ids = array();
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$ids[] = (int) $obj->rowid;
		}
		foreach ($ids as $id) {
			$this->svc->applicantForMember($id);
		}
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
			$fields['problem_since'] = $this->db->idate($since ? $since : OnboardingService::now());
			$fields['reminders_sent'] = null;
		}
		$this->svc->save($app->rowid, $fields);
		$this->svc->refreshStage($app->rowid);
	}

	/**
	 * Send due reminders and lapse members past the grace period.
	 *
	 * @return string Summary
	 */
	public function dunning()
	{
		global $conf;

		$now = OnboardingService::now();
		$offsets = array();
		foreach (explode(',', getDolGlobalString('ONBOARDING_REMINDER_DAYS', '3,7,14')) as $o) {
			if (is_numeric(trim($o)) && (int) $o >= 0) {
				$offsets[] = (int) $o;
			}
		}
		sort($offsets);
		$grace = max(1, (int) getDolGlobalString('ONBOARDING_GRACE_DAYS', '30'));
		$sent = 0;
		$failed = 0;
		$lapsed = 0;
		$cleared = 0;
		$repaired = $this->svc->givebutter()->repairPeriods();
		$restored = $this->restoreEndDates();
		$this->ensurePaypalApplicants();
		// PayPal members are judged only while the PayPal sync is up to date: a
		// broken sync must not look like everyone stopped paying.
		$paypalFresh = getDolGlobalString('ONBOARDING_PAYPAL_CLIENT_ID') !== '' && getDolGlobalInt('ONBOARDING_PAYPAL_SYNCED_THROUGH') > $now - 86400;

		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."onboarding_applicant WHERE entity = ".((int) $conf->entity)." AND fk_adherent > 0 AND payment_state IN ('active', 'past_due', 'cancelled')");
		$ids = array();
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$ids[] = (int) $obj->rowid;
		}
		foreach ($ids as $id) {
			$app = $this->svc->fetch($id);
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
			if ($channel !== '' && $channel !== 'Givebutter' && $channel !== 'PayPal') {
				continue; // Check, cash and scholarship members are kept up by hand.
			}
			if ($channel === 'PayPal' && !$paypalFresh) {
				continue;
			}
			$paidUntil = $this->paidThrough($app, (int) $adh->datefin);

			// A "ran out" problem flagged before the dues really ran out (a period
			// recorded too short): not a problem. A failed or cancelled plan is
			// reported after the paid period, so it is never cleared here.
			if ($app->payment_state == 'past_due' && !empty($app->problem_since) && $this->db->jdate($app->problem_since) <= $paidUntil) {
				$this->svc->save($id, array('payment_state' => 'active', 'problem_since' => null, 'reminders_sent' => null));
				$cleared++;
				continue;
			}

			// Dues that simply ran out, with no cancellation ever reported.
			if ($app->payment_state == 'active') {
				if ($paidUntil > 0 && $paidUntil < $now - 2 * 86400) {
					$this->setProblem($app, 'past_due', $paidUntil);
					$app = $this->svc->fetch($id);
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
				if ($channel === 'PayPal') {
					$body = $this->svc->template('ONBOARDING_MAIL_REMINDER_PAYPAL_BODY', $app, array('days_left' => (string) $left, 'paid_until' => dol_print_date($paidUntil, 'day')));
				} else {
					$body = $this->svc->template('ONBOARDING_MAIL_REMINDER_BODY', $app, array('days_left' => (string) $left));
				}
				if (!$this->svc->mail($app->email, $this->svc->template('ONBOARDING_MAIL_REMINDER_SUBJECT', $app), $body)) {
					// Not marked as sent: tomorrow's run tries again.
					$failed++;
					continue;
				}
				// One email even if several offsets came due at once.
				$this->svc->save($id, array('reminders_sent' => implode(',', array_merge($done, $due))));
				$sent++;
			}
		}
		return $sent.' reminders sent, '.($failed ? $failed.' reminders failed to send, ' : '').$lapsed.' members marked non-paying, '.$cleared.' false payment problems cleared, '.$repaired.' dues periods lengthened to the next charge'.($restored ? ', '.$restored.' held end dates put back' : '');
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
		$actor = $this->svc->actor();
		if ($adh->statut == Adherent::STATUS_VALIDATED) {
			$adh->resiliate($actor);
		}
		$this->svc->save($app->rowid, array('payment_state' => 'lapsed', 'problem_since' => null));
		$app = $this->svc->refreshStage($app->rowid);
		$badge = isset($adh->array_options['options_member_code']) ? (string) $adh->array_options['options_member_code'] : '';
		if ($badge === '' && !empty($adh->array_options['options_credential_id'])) {
			$badge = (string) $adh->array_options['options_credential_id'];
		}
		$access = !empty($adh->array_options['options_access_enabled']);
		$this->svc->mail($app->email, $this->svc->template('ONBOARDING_MAIL_LAPSED_SUBJECT', $app), $this->svc->template('ONBOARDING_MAIL_LAPSED_BODY', $app));
		$this->svc->mail(getDolGlobalString('ONBOARDING_STAFF_EMAIL'), 'Membership ended: '.$app->firstname.' '.$app->lastname, $app->firstname.' '.$app->lastname.' <'.$app->email.'> is now non-paying.'.($badge !== '' ? ' Badge '.$badge.($access ? ' still has access and should be turned off.' : ' is already marked as having no access.') : ' No badge is recorded for them.'));
	}
}
