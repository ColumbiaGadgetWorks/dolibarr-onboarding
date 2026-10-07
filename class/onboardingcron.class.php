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
	 * Daily: reminders, lapses, cleanup.
	 *
	 * @return int 0 if OK
	 */
	public function daily()
	{
		$svc = new OnboardingService($this->db);
		$this->output = $svc->dunning().'; '.$svc->cleanup();
		return 0;
	}
}
