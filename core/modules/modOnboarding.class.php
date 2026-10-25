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
		$this->description = 'Website signup, paperwork, Givebutter dues and badge tracking for members; deadline reminders on project tasks';
		$this->descriptionlong = $this->description;
		$this->editor_name = 'Columbia Gadget Works';
		$this->editor_url = 'https://columbiagadgetworks.org';
		$this->version = '0.8.2';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'members';
		$this->module_parts = array();
		$this->dirs = array('/onboarding/applicant');
		$this->config_page_url = array('setup.php@onboarding');
		$this->hidden = false;
		$this->depends = array('modAdherent', 'modSociete', 'modCategorie', 'modProjet');
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
			array('ONBOARDING_TRAINING_ZONES', 'chaine', 'Digital Fab,Electronics,Woodworking,Machining,Metalworking,Crafting', 'Zones with a budget, comma separated, as on the training form', 0, 'current', 0),
			array('ONBOARDING_WEBSITE_URL', 'chaine', 'https://columbiagadgetworks.org', 'The website, which gets the training catalog and the lookup copy from Dolibarr', 0, 'current', 0),
			array('ONBOARDING_TRAINING_TZ', 'chaine', 'America/Chicago', 'Time zone of the shop', 0, 'current', 0),
			array('ONBOARDING_PURCHASE_LEAD_LIMIT', 'chaine', '100', 'Largest purchase request a zone lead may approve alone; bigger ones need the treasurer and another director', 0, 'current', 0),
			array('ONBOARDING_PURCHASE_RECEIPT_DAYS', 'chaine', '60', 'Days after buying that a reimbursement receipt is still accepted', 0, 'current', 0),
		);

		$this->tabs = array(
			'member:+onbtraining:Training:onboarding@onboarding:$user->hasRight("onboarding", "training", "read"):/onboarding/member-training.php?id=__ID__',
			'contact:+onbtraining:Training:onboarding@onboarding:$user->hasRight("onboarding", "training", "read"):/onboarding/member-training.php?contact=__ID__',
		);
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
				'comment' => 'Sends due payment reminders, marks members non-paying after the grace period, deletes abandoned signups, expires unpaid training registrations, copies new versions of the waiver and agreement from GitHub.',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 1,
				'test' => 'isModEnabled("onboarding")',
				'priority' => 51,
			),
			2 => array(
				'label' => 'Onboarding: deadline reminders',
				'jobtype' => 'method',
				'class' => '/onboarding/class/onboardingcron.class.php',
				'objectname' => 'OnboardingCron',
				'method' => 'deadlines',
				'parameters' => '',
				'comment' => 'Emails the people assigned to project tasks that have a reminder set, until the task is done; escalates to the chosen group before the due date; copies finished yearly tasks to next year.',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 1,
				'test' => 'isModEnabled("onboarding") && isModEnabled("project")',
				'priority' => 52,
			),
			3 => array(
				'label' => 'Onboarding: training bookings',
				'jobtype' => 'method',
				'class' => '/onboarding/class/onboardingcron.class.php',
				'objectname' => 'OnboardingCron',
				'method' => 'bookings',
				'parameters' => '',
				'comment' => 'Lets go of unpaid seats, asks trainers to confirm and cancels unconfirmed sessions, sends day-of reminders and the questions after each training, sends group class lists, checks the website has the current training catalog, and runs member votes (reminders to the Secretary and President, ballot emails, closing and counting).',
				'frequency' => 15,
				'unitfrequency' => 60,
				'status' => 1,
				'test' => 'isModEnabled("onboarding")',
				'priority' => 53,
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
		$this->rights[$r][1] = 'Match trainers, link training payments, approve dues credits';
		$this->rights[$r][4] = 'training';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero.'06';
		$this->rights[$r][1] = 'Add zone budgets and record purchases against them';
		$this->rights[$r][4] = 'budget';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero.'07';
		$this->rights[$r][1] = 'Record free trainings and create group classes (give it to the board)';
		$this->rights[$r][4] = 'training';
		$this->rights[$r][5] = 'free';
		$r++;
		$this->rights[$r][0] = $this->numero.'08';
		$this->rights[$r][1] = 'Edit training tools, trainers, their times and closed days';
		$this->rights[$r][4] = 'training';
		$this->rights[$r][5] = 'tools';
		$r++;
		$this->rights[$r][0] = $this->numero.'09';
		$this->rights[$r][1] = 'See member votes: turnout and results, and start a practice vote (give it to the board)';
		$this->rights[$r][4] = 'vote';
		$this->rights[$r][5] = 'read';
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
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=members,fk_leftmenu=onboarding',
			'type' => 'left',
			'titre' => 'Purchase requests',
			'mainmenu' => 'members',
			'leftmenu' => 'onboarding_purchases',
			'url' => '/onboarding/purchases.php',
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
			'titre' => 'Training tools',
			'mainmenu' => 'members',
			'leftmenu' => 'onboarding_training_tools',
			'url' => '/onboarding/training-tools.php',
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
			'titre' => 'Group classes',
			'mainmenu' => 'members',
			'leftmenu' => 'onboarding_classes',
			'url' => '/onboarding/class.php',
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
			'titre' => 'Member votes',
			'mainmenu' => 'members',
			'leftmenu' => 'onboarding_votes',
			'url' => '/onboarding/votes.php',
			'langs' => 'onboarding@onboarding',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("onboarding")',
			'perms' => '$user->hasRight("onboarding", "vote", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=project',
			'type' => 'left',
			'titre' => 'Deadline reminders',
			'mainmenu' => 'project',
			'leftmenu' => 'onboarding_deadlines',
			'url' => '/onboarding/deadlines.php',
			'langs' => 'onboarding@onboarding',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("onboarding") && isModEnabled("project")',
			'perms' => '$user->hasRight("projet", "lire")',
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
		dol_include_once('/onboarding/class/deadline.class.php');
		OnboardingDeadlines::installFields($this->db);

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		if (!getDolGlobalString('ONBOARDING_API_KEY')) {
			dolibarr_set_const($this->db, 'ONBOARDING_API_KEY', bin2hex(random_bytes(24)), 'chaine', 0, 'Key the website Worker sends to the onboarding endpoint', $conf->entity);
		}
		// Members are created by the module with their email as login.
		OnboardingService::installMemberTypes($this->db, $user);
		// The role tags (president, treasurer, woodworking-boss and so on) that purchase requests read.
		dol_include_once('/onboarding/class/purchase.class.php');
		$purchase = new OnboardingPurchase($this->db);
		$purchase->installRoleTags();
		// Training bookings: the member list's counters and the sample tool.
		dol_include_once('/onboarding/class/booking.class.php');
		OnboardingBooking::installFields($this->db);
		$booking = new OnboardingBooking($this->db);
		$booking->installSample();

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
