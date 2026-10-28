<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Needs attention: everything the module has flagged for a person, counted,
 * with a link to where it is dealt with. Shown on its own page and as a box on
 * Dolibarr's home page. Each line only for people who may act on it.
 */

dol_include_once('/onboarding/class/onboarding.class.php');

/**
 * Counts of what is waiting on someone.
 */
class OnboardingAttention
{
	/** @var DoliDB */
	public $db;

	/** What the alert keys mean, for the open problems line. */
	const ALERTS = array(
		'GIVEBUTTER_SYNC' => 'The hourly Givebutter sync is failing',
		'GIVEBUTTER_WEBHOOK' => 'Givebutter webhooks are being refused',
		'PAYPAL_SYNC' => 'The hourly PayPal sync is failing',
		'WEBSITE_CHECKIN' => 'The website has not reached Dolibarr lately',
		'WEBSITE_PUSH' => 'The website is not accepting updates',
		'WEBSITE_SYNC' => 'The website is out of sync',
		'CRON_BOOKINGS' => 'The training bookings job is failing',
		'CRON_VOTES' => 'The member votes job is failing',
		'VOTE_GITHUB_TOKEN' => 'The GitHub token for votes expires soon or is not working',
		'VOTE_OFFICERS' => 'A member vote needs the Secretary or President',
		'VOTE_TAG' => 'A vote\'s final text could not be tagged in GitHub',
		'CRON_FILINGS' => 'The yearly filings job is failing',
		'CRON_RECONCILE' => 'Reconciling bank accounts is failing',
		'PLAID_SYNC' => 'A bank feed (Plaid) is failing or wants the login again',
		'CRON_SECURITY' => 'Removing logins and old bank data is failing',
		'DEPROVISION_LAST_ADMIN' => 'The last administrator should be disabled',
	);

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param string $sql A SELECT COUNT(*) AS n query
	 * @return int
	 */
	private function count($sql)
	{
		$res = @$this->db->query($sql);
		$obj = $res ? $this->db->fetch_object($res) : null;
		return $obj ? (int) $obj->n : 0;
	}

	/**
	 * Everything this user may act on, waiting or not.
	 *
	 * @param User $user Who is looking
	 * @return array<int,array{key:string,label:string,why:string,count:int,url:string,urgent:bool}>
	 */
	public function items($user)
	{
		global $conf;
		$p = $this->db->prefix();
		$e = (int) $conf->entity;
		$now = OnboardingService::now();
		$base = dol_buildpath('/onboarding/', 1);
		$out = array();
		$add = function ($key, $label, $why, $count, $url, $urgent = false) use (&$out) {
			$out[] = array('key' => $key, 'label' => $label, 'why' => $why, 'count' => (int) $count, 'url' => $url, 'urgent' => $urgent);
		};

		if (!empty($user->admin)) {
			$add('mailgate', 'Emails waiting for approval', 'Held until the approver approves or rejects them; sending failed for some.', $this->count("SELECT COUNT(*) AS n FROM ".$p."onboarding_mail_hold WHERE entity = ".$e." AND status IN ('pending', 'failed')"), $base.'mailgate.php', true);

			$alerts = array();
			// alert() keeps the time of its last email in ONBOARDING_ALERT_<KEY> until alertClear().
			$res = $this->db->query("SELECT name, value FROM ".$p."const WHERE name LIKE 'ONBOARDING_ALERT_%' AND entity IN (0, ".$e.")");
			while ($res && ($obj = $this->db->fetch_object($res))) {
				if ($obj->name == 'ONBOARDING_ALERT_EMAIL' || !ctype_digit((string) $obj->value) || (int) $obj->value <= 0) {
					continue;
				}
				$key = substr($obj->name, strlen('ONBOARDING_ALERT_'));
				$label = '';
				foreach (self::ALERTS as $k => $l) {
					if (strpos($key, $k) === 0) {
						$label = $l;
					}
				}
				$alerts[] = $label !== '' ? $label : ucfirst(strtolower(str_replace('_', ' ', $key)));
			}
			$add('alerts', 'Problems still open', $alerts ? implode('; ', array_unique($alerts)).'. Each was emailed to the alerts address and clears itself when fixed.' : 'Problems Dolibarr emailed about (website, Givebutter, votes) that have not cleared yet.', count($alerts), $base.'admin/setup.php', true);

			// Enabled jobs that failed last time, or are more than two hours late: the
			// scheduler is not running them.
			$late = $this->db->idate($now - 2 * 3600);
			$add('cron', 'Scheduled jobs failing or not running', 'An enabled job whose last run failed, or that is over two hours late (Dolibarr\'s scheduled jobs have stopped).', $this->count("SELECT COUNT(*) AS n FROM ".$p."cronjob WHERE entity IN (0, ".$e.") AND status = 1 AND ((lastresult IS NOT NULL AND lastresult <> '' AND lastresult <> '0') OR (datenextrun IS NOT NULL AND datenextrun < '".$late."'))"), DOL_URL_ROOT.'/cron/list.php', true);
		}

		if ($user->hasRight('onboarding', 'applicant', 'read')) {
			$add('unmatched', 'Unmatched dues payments', 'Givebutter and PayPal payments that matched no signup or member.', $this->count("SELECT COUNT(*) AS n FROM ".$p."onboarding_payment WHERE entity = ".$e." AND status = 'unmatched'") + $this->count("SELECT COUNT(*) AS n FROM ".$p."onboarding_paypal WHERE entity = ".$e." AND status = 'unmatched'"), $base.'payments.php', true);
			$add('problem', 'Dues payment problems', 'A failed or cancelled Givebutter plan; reminders are going out.', $this->count("SELECT COUNT(*) AS n FROM ".$p."onboarding_applicant WHERE entity = ".$e." AND payment_state IN ('past_due', 'cancelled')"), $base.'applicants.php?filter=problem');
			$ef = $p."adherent_extrafields";
			$add('nobadge', 'Paying, no active badge', 'Paying members whose badge is not turned on yet.', $this->count("SELECT COUNT(*) AS n FROM ".$p."onboarding_applicant AS a LEFT JOIN ".$ef." AS x ON x.fk_object = a.fk_adherent WHERE a.entity = ".$e." AND a.payment_state = 'active' AND (x.access_enabled IS NULL OR x.access_enabled = 0)"), $base.'applicants.php?filter=nobadge');
			$add('badgeon', 'Not paying, badge still active', 'Turn these badges off at the door.', $this->count("SELECT COUNT(*) AS n FROM ".$p."onboarding_applicant AS a LEFT JOIN ".$ef." AS x ON x.fk_object = a.fk_adherent WHERE a.entity = ".$e." AND a.payment_state IN ('lapsed', 'none') AND x.access_enabled = 1"), $base.'applicants.php?filter=badgeon', true);
		}

		if ($user->hasRight('onboarding', 'budget', 'write') || $user->hasRight('onboarding', 'training', 'read')) {
			$add('purchase_pending', 'Purchase requests waiting for a decision', 'Approvers were emailed; they decide from the email or the Purchase requests page.', $this->count("SELECT COUNT(*) AS n FROM ".$p."onboarding_purchase WHERE entity = ".$e." AND status = 'pending'"), $base.'purchases.php');
			$add('purchase_approved', 'Approved purchases to buy or pay', 'Approved, not yet marked paid.', $this->count("SELECT COUNT(*) AS n FROM ".$p."onboarding_purchase WHERE entity = ".$e." AND status = 'approved'"), $base.'purchases.php');
		}

		if ($user->hasRight('onboarding', 'training', 'read')) {
			$cut = $this->db->idate($now - 2 * 86400);
			$add('bookings', 'Training bookings that need someone', 'A comment from a trainer or trainee, a refund to make, a new date to pick, or a session the trainer has not answered for.', $this->count("SELECT COUNT(*) AS n FROM ".$p."onboarding_booking WHERE entity = ".$e." AND (status IN ('other', 'refund_requested', 'needs_new_date') OR (status = 'confirmed' AND answer IS NULL AND fk_class IS NULL AND session_start < '".$cut."'))"), $base.'trainings.php');
			$add('credits', 'Trainer dues credits to approve or refund', 'Trainers whose credit reached a month of dues.', $this->count("SELECT COUNT(*) AS n FROM ".$p."onboarding_credit WHERE entity = ".$e." AND status IN ('pending', 'approved')"), $base.'training-accounts.php');
		}

		if ((isModEnabled('banque') || isModEnabled('bank')) && $user->hasRight('banque', 'consolidate')) {
			dol_include_once('/onboarding/class/reconcile.class.php');
			$rec = new OnboardingReconcile($this->db);
			dol_include_once('/onboarding/class/plaid.class.php');
			if (OnboardingPlaid::configured()) {
				$add('plaid', 'Bank feeds to reconnect', 'A bank connected through Plaid wants its login again, or cannot be read.', (new OnboardingPlaid($this->db))->needing(), $base.'plaid.php', true);
			}
			$add('reconcile', 'Bank lines to check', 'Statement lines with no Dolibarr entry, and entries no statement shows, from Reconcile bank accounts.', $rec->openCount() + count($rec->missing()), $base.'reconcile.php');
		}

		if (!empty($user->admin)) {
			dol_include_once('/onboarding/class/security.class.php');
			$going = 0;
			foreach ((new OnboardingSecurity($this->db))->logins() as $o) {
				$going += $o->reason !== '' ? 1 : 0;
			}
			$open = (new OnboardingSecurity($this->db))->openReview();
			$add('review', 'Yearly access review', 'The security owner has been emailed the year\'s access review; it is not recorded as done yet.', $open !== null ? 1 : 0, $base.'security.php');
			$add('security', 'Logins to be disabled', 'People whose membership or officer role ended: their Dolibarr login goes a few days after a warning unless it is put right.', $going, $base.'security.php');
		}

		if ($user->hasRight('onboarding', 'filing', 'read')) {
			dol_include_once('/onboarding/class/filing.class.php');
			$tax = (new OnboardingFiling($this->db))->attention();
			$add('filing', $tax ? $tax['label'] : 'Yearly tax filings', $tax ? $tax['why'] : 'The IRS return and the state report, from the treasurer\'s statements.', $tax ? 1 : 0, $base.'filings.php', $tax ? $tax['urgent'] : false);
		}

		if (isModEnabled('project') && $user->hasRight('projet', 'lire')) {
			$today = $this->db->idate(dol_mktime(0, 0, 0, (int) dol_print_date($now, '%m'), (int) dol_print_date($now, '%d'), (int) dol_print_date($now, '%Y')));
			$add('deadlines', 'Overdue deadline tasks', 'Tasks with reminders set whose due date has passed and are not done.', $this->count("SELECT COUNT(*) AS n FROM ".$p."projet_task AS t INNER JOIN ".$p."projet AS pr ON pr.rowid = t.fk_projet LEFT JOIN ".$p."projet_task_extrafields AS x ON x.fk_object = t.rowid WHERE pr.entity IN (".getEntity('project').") AND pr.fk_statut <> 2 AND COALESCE(t.progress, 0) < 100 AND COALESCE(t.fk_statut, 1) NOT IN (3, 4, 9) AND t.datee IS NOT NULL AND t.datee < '".$today."' AND (COALESCE(x.remind_every, 0) > 0 OR x.escalate_group > 0 OR x.repeat_yearly = 1)"), $base.'deadlines.php', true);
		}
		return $out;
	}

	/**
	 * @param User $user Who is looking
	 * @return int Everything waiting, added up
	 */
	public function total($user)
	{
		$n = 0;
		foreach ($this->items($user) as $i) {
			$n += $i['count'];
		}
		return $n;
	}
}
