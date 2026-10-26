<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');

/**
 * Scheduled jobs. Declared in modOnboarding, run by Dolibarr's cron module.
 */
class OnboardingCron
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $output = '';
	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hourly: pull payments and plan statuses from Givebutter.
	 *
	 * @return int 0 if OK
	 */
	public function reconcile()
	{
		$svc = new OnboardingService($this->db);
		$this->output = $svc->reconcile();
		if (strpos($this->output, 'failed') !== false) {
			$svc->alert('givebutter-sync', 'The hourly Givebutter sync is failing', 'The hourly sync with Givebutter said: '.$this->output."\n\nPayments still reach Dolibarr by webhook, but a missed one is not caught until this works. Check the Givebutter API key on the module setup page.");
		} elseif (getDolGlobalString('ONBOARDING_GB_API_KEY') !== '') {
			$svc->alertClear('givebutter-sync', 'The hourly Givebutter sync works again.');
		}
		dol_include_once('/onboarding/class/paypal.class.php');
		if (OnboardingPaypal::configured()) {
			$pp = (new OnboardingPaypal($this->db, $svc))->sync();
			$this->output .= '; '.$pp;
			if (strpos($pp, 'failed') !== false) {
				$svc->alert('paypal-sync', 'The hourly PayPal sync is failing', 'The hourly sync with PayPal said: '.$pp."\n\nPayPal dues are not recorded until this works, and PayPal members are judged by the last data that came in.");
			} else {
				$svc->alertClear('paypal-sync', 'The hourly PayPal sync works again.');
			}
		}
		return 0;
	}

	/**
	 * Daily: reminders, lapses, cleanup, expired training registrations, and a
	 * fresh copy of the website's training catalog.
	 *
	 * @return int 0 if OK
	 */
	public function daily()
	{
		$svc = new OnboardingService($this->db);
		dol_include_once('/onboarding/class/training.class.php');
		$training = new OnboardingTraining($this->db, $svc);
		dol_include_once('/onboarding/class/purchase.class.php');
		$purchase = new OnboardingPurchase($this->db, $svc);
		dol_include_once('/onboarding/class/mailgate.class.php');
		$gate = new OnboardingMailGate($this->db);
		$this->output = $svc->dunning().'; '.$svc->presumePaid().'; '.$svc->cleanup().'; '.$training->expireRegistrations().'; '.$purchase->purge().'; '.$svc->fetchDocs().'; '.$gate->daily();
		return 0;
	}

	/**
	 * Every 15 minutes: training bookings (holds, the trainer's confirmation,
	 * reminders, the questions after a session, group classes) and the check
	 * that the website has the current catalog.
	 *
	 * @return int 0 if OK
	 */
	public function bookings()
	{
		dol_include_once('/onboarding/class/booking.class.php');
		$booking = new OnboardingBooking($this->db);
		try {
			$this->output = $booking->run();
		} catch (Throwable $e) {
			$this->error = $e->getMessage();
			$booking->svc->alert('cron-bookings', 'The training booking job failed', 'The scheduled job "Onboarding: training bookings" stopped with: '.$e->getMessage()."\n\nReminders and confirmations are not going out until it runs cleanly. See Home, Admin tools, Scheduled jobs.");
			return 1;
		}
		$booking->svc->alertClear('cron-bookings', 'The training booking job runs cleanly again.');
		// Member votes share this job, so updating the module needs no new scheduled job.
		dol_include_once('/onboarding/class/vote.class.php');
		try {
			$vote = new OnboardingVote($this->db);
			$done = $vote->runJob();
			$this->output .= $done !== '' ? ($this->output !== '' ? '; ' : '').$done : '';
		} catch (Throwable $e) {
			$this->error = $e->getMessage();
			$booking->svc->alert('cron-votes', 'The member vote job failed', 'The member vote steps in the scheduled job "Onboarding: training bookings" stopped with: '.$e->getMessage()."\n\nVote reminders, ballots and the count are not going out until it runs cleanly.");
			return 1;
		}
		$booking->svc->alertClear('cron-votes', 'The member vote job runs cleanly again.');
		return 0;
	}

	/**
	 * Daily: reminders and escalations for project tasks with a deadline set.
	 *
	 * @return int 0 if OK
	 */
	public function deadlines()
	{
		dol_include_once('/onboarding/class/deadline.class.php');
		$deadlines = new OnboardingDeadlines($this->db);
		$this->output = $deadlines->run();
		return 0;
	}
}
