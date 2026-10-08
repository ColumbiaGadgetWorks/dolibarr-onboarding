<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Module descriptor. Install by placing this repository at htdocs/custom/onboarding.
 */
class modOnboarding extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 513470;
		$this->rights_class = 'onboarding';
		$this->family = 'hr';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Website signup, paperwork, Givebutter dues and badge tracking for members';
		$this->descriptionlong = $this->description;
		$this->editor_name = 'Columbia Gadget Works';
		$this->editor_url = 'https://columbiagadgetworks.org';
		$this->version = '0.3.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'members';
		$this->module_parts = array();
		$this->dirs = array('/onboarding/applicant');
		$this->config_page_url = array('setup.php@onboarding');
		$this->hidden = false;
		$this->depends = array('modAdherent', 'modSociete', 'modCategorie');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('onboarding@onboarding');
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(17, 0);

		// Defaults. Secrets (API key, Givebutter key) are set on the setup page, never here.
		$this->const = array(
			array('ONBOARDING_REMINDER_DAYS', 'chaine', '3,7,14', 'Days after a payment problem to send reminders', 0, 'current', 0),
			array('ONBOARDING_GRACE_DAYS', 'chaine', '30', 'Days after a payment problem before the member is marked non-paying', 0, 'current', 0),
			array('ONBOARDING_DRAFT_DAYS', 'chaine', '30', 'Days before an abandoned signup is deleted (0 = never)', 0, 'current', 0),
			array('ONBOARDING_ID_RETENTION_DAYS', 'chaine', '0', 'Days to keep the ID photo after signup completes (0 = keep)', 0, 'current', 0),
			array('ONBOARDING_DUES_STANDARD', 'chaine', '50', 'Standard monthly dues', 0, 'current', 0),
			array('ONBOARDING_DUES_SUPPORTER', 'chaine', '100', 'Supporter monthly dues', 0, 'current', 0),
			array('ONBOARDING_GB_API_BASE', 'chaine', 'https://api.givebutter.com/v1', 'Givebutter API base URL', 0, 'current', 0),
			array('ONBOARDING_UPDATES_TAG', 'chaine', 'Email updates', 'Contact tag for people who asked for email updates', 0, 'current', 0),
			array('ONBOARDING_TRAINING_TRAINER_SHARE', 'chaine', '50', 'Percent of a training fee credited to the trainer; the rest goes to the zone budget', 0, 'current', 0),
			array('ONBOARDING_TRAINING_CREDIT_THRESHOLD', 'chaine', '50', 'Trainer credit that earns a month of dues refunded', 0, 'current', 0),
			array('ONBOARDING_TRAINED_TAG', 'chaine', 'Trained', 'Parent tag for "Trained: <tool>" tags on members and contacts', 0, 'current', 0),
		);

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();

		$this->cronjobs = array(
			0 => array(
				'label' => 'Onboarding: sync Givebutter payments',
				'jobtype' => 'method',
				'class' => '/onboarding/class/onboardingcron.class.php',
				'objectname' => 'OnboardingCron',
				'method' => 'reconcile',
				'parameters' => '',
				'comment' => 'Pulls recent Givebutter transactions and plan statuses so a missed webhook cannot leave a member in the wrong state.',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'status' => 1,
				'test' => 'isModEnabled("onboarding")',
				'priority' => 50,
			),
			1 => array(
				'label' => 'Onboarding: payment reminders, lapses and cleanup',
				'jobtype' => 'method',
				'class' => '/onboarding/class/onboardingcron.class.php',
				'objectname' => 'OnboardingCron',
				'method' => 'daily',
				'parameters' => '',
				'comment' => 'Sends due payment reminders, marks members non-paying after the grace period, deletes abandoned signups.',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 1,
				'test' => 'isModEnabled("onboarding")',
				'priority' => 51,
			),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.'01';
		$this->rights[$r][1] = 'See onboarding applicants and their signed documents';
		$this->rights[$r][4] = 'applicant';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.'02';
		$this->rights[$r][1] = 'Assign unmatched payments and manage applicants';
		$this->rights[$r][4] = 'applicant';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero.'03';
		$this->rights[$r][1] = 'View uploaded ID photos';
		$this->rights[$r][4] = 'idphoto';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.'04';
		$this->rights[$r][1] = 'See trainings, zone budgets and trainer credit';
		$this->rights[$r][4] = 'training';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.'05';
		$this->rights[$r][1] = 'Match trainers, record zone spending, approve dues credits';
		$this->rights[$r][4] = 'training';
		$this->rights[$r][5] = 'write';
		$r++;

		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=members',
			'type' => 'left',
			'titre' => 'Onboarding',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'members',
			'leftmenu' => 'onboarding',
			'url' => '/onboarding/applicants.php',
			'langs' => 'onboarding@onboarding',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("onboarding")',
			'perms' => '$user->hasRight("onboarding", "applicant", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=members,fk_leftmenu=onboarding',
			'type' => 'left',
			'titre' => 'Unmatched payments',
			'mainmenu' => 'members',
			'leftmenu' => 'onboarding_payments',
			'url' => '/onboarding/payments.php',
			'langs' => 'onboarding@onboarding',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("onboarding")',
			'perms' => '$user->hasRight("onboarding", "applicant", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=members,fk_leftmenu=onboarding',
			'type' => 'left',
			'titre' => 'Trainings',
			'mainmenu' => 'members',
			'leftmenu' => 'onboarding_trainings',
			'url' => '/onboarding/trainings.php',
			'langs' => 'onboarding@onboarding',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("onboarding")',
			'perms' => '$user->hasRight("onboarding", "training", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=members,fk_leftmenu=onboarding',
			'type' => 'left',
			'titre' => 'Training accounts',
			'mainmenu' => 'members',
			'leftmenu' => 'onboarding_training_accounts',
			'url' => '/onboarding/training-accounts.php',
			'langs' => 'onboarding@onboarding',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("onboarding")',
			'perms' => '$user->hasRight("onboarding", "training", "read")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Called when the module is enabled.
	 *
	 * @param string $options Options
	 * @return int 1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $user;

		$result = $this->_load_tables('/onboarding/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		dol_include_once('/onboarding/class/onboarding.class.php');
		OnboardingService::installExtraFields($this->db);

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		if (!getDolGlobalString('ONBOARDING_API_KEY')) {
			dolibarr_set_const($this->db, 'ONBOARDING_API_KEY', bin2hex(random_bytes(24)), 'chaine', 0, 'Key the website Worker sends to the onboarding endpoint', $conf->entity);
		}
		// Members are created by the module with their email as login.
		OnboardingService::installMemberTypes($this->db, $user);

		return $this->_init(array(), $options);
	}

	/**
	 * Called when the module is disabled. Data and fields are kept.
	 *
	 * @param string $options Options
	 * @return int 1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
