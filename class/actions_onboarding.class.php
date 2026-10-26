<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Hooks. "mail": every email Dolibarr sends passes through sendMail first, where
 * email approval may hold it (see mailgate.class.php).
 */

/**
 * Hook handler, found by Dolibarr by its name.
 */
class ActionsOnboarding
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();
	/** @var array */
	public $results = array();
	/** @var string */
	public $resprints = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Before an email is sent. Returning 1 tells Dolibarr it was taken care of
	 * (held for approval), so it is not sent now.
	 *
	 * @param array $parameters Hook parameters
	 * @param CMailFile $object The email
	 * @param string $action Action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0 send it, 1 held, -1 error
	 */
	public function sendMail($parameters, &$object, &$action, $hookmanager)
	{
		if (!isModEnabled('onboarding') || !is_object($object)) {
			return 0;
		}
		dol_include_once('/onboarding/class/mailgate.class.php');
		if (!getDolGlobalString('ONBOARDING_MAILGATE_APPROVER') || OnboardingMailGate::$passing) {
			return 0;
		}
		$gate = new OnboardingMailGate($this->db);
		$r = $gate->intercept($object);
		if ($r < 0) {
			$this->error = 'This email could not be held for approval, so it was not sent.';
			$this->errors[] = $this->error;
		}
		return $r;
	}
}
