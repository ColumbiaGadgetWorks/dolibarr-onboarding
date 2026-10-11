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
		$training->catalog(true);
		$this->output = $svc->dunning().'; '.$svc->cleanup().'; '.$training->expireRegistrations();
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
