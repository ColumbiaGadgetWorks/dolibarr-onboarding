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
		$this->version = '0.13.1';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'members';
		// mail: email approval may hold any email Dolibarr sends (class/actions_onboarding.class.php).
		$this->module_parts = array('hooks' => array('mail'));
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

		// Home page box listing what needs attention (attention.php).
		$this->boxes = array(
			0 => array('file' => 'box_onboarding_attention.php@onboarding', 'note' => 'Needs attention', 'enabledbydefaulton' => 'Home'),
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
		// Top menu "CGW": the CGW home page.
		$this->menu[$r++] = array(
			'fk_menu' => '',
			'type' => 'top',
			'titre' => 'CGW',
			'prefix' => img_picto('', 'home', 'class="pictofixedwidth"'),
			'mainmenu' => 'cgw',
			'leftmenu' => '',
			'url' => '/onboarding/home.php?mainmenu=cgw',
			'langs' => 'onboarding@onboarding',
			'position' => 1,
			'enabled' => 'isModEnabled("onboarding")',
			'perms' => '1',
			'target' => '',
			'user' => 0,
		);
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
			'titre' => 'Needs attention',
			'mainmenu' => 'members',
			'leftmenu' => 'onboarding_attention',
			'url' => '/onboarding/attention.php',
			'langs' => 'onboarding@onboarding',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("onboarding")',
			'perms' => '$user->admin || $user->hasRight("onboarding", "applicant", "read") || $user->hasRight("onboarding", "training", "read") || $user->hasRight("projet", "lire")',
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
			'fk_menu' => 'fk_mainmenu=members,fk_leftmenu=onboarding',
			'type' => 'left',
			'titre' => 'Email approval',
			'mainmenu' => 'members',
			'leftmenu' => 'onboarding_mailgate',
			'url' => '/onboarding/mailgate.php',
			'langs' => 'onboarding@onboarding',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("onboarding")',
			'perms' => '$user->admin',
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

		// The CGW top menu's left side: the CGW home page, Dolibarr's own setup
		// links (as under Home), the member pages people use most, and every
		// onboarding page, which stay under CGW when opened from here.
		$cgw = array(
			// leftmenu, parent, title, url, perms, icon
			array('cgw_home', '', 'CGW home', '/onboarding/home.php?mainmenu=cgw', '1', '<i class="fas fa-chart-bar fa-fw paddingright pictofixedwidth"></i>'),
			array('cgw_setup', '', 'Setup', '/admin/index.php?mainmenu=home&leftmenu=setup', '$user->admin', '<i class="fa fa-tools fa-fw paddingright pictofixedwidth"></i>'),
			array('cgw_admintools', '', 'Admin Tools', '/admin/tools/index.php?mainmenu=home&leftmenu=admintools', '$user->admin', '<i class="fa fa-server fa-fw paddingright pictofixedwidth"></i>'),
			array('cgw_users', '', 'Users & Groups', '/user/home.php?mainmenu=home&leftmenu=users', '$user->hasRight("user", "user", "lire")', '<i class="fa fa-user fa-fw paddingright pictofixedwidth"></i>'),
			array('cgw_members', '', 'Members', '/adherents/index.php?mainmenu=members&leftmenu=members', '$user->hasRight("adherent", "lire")', '<i class="fa fa-user-friends fa-fw paddingright pictofixedwidth"></i>'),
			array('cgw_members_new', 'cgw_members', 'New member', '/adherents/card.php?mainmenu=members&leftmenu=members&action=create', '$user->hasRight("adherent", "creer")', ''),
			array('cgw_members_list', 'cgw_members', 'List', '/adherents/list.php?mainmenu=members&leftmenu=members&statut=1', '$user->hasRight("adherent", "lire")', ''),
			array('cgw_contributions', '', 'Contributions', '/adherents/subscription/list.php?mainmenu=members&leftmenu=members', '$user->hasRight("adherent", "cotisation", "lire")', '<i class="fa fa-money-check-alt fa-fw paddingright pictofixedwidth"></i>'),
			array('cgw_contributions_new', 'cgw_contributions', 'New membership', '/adherents/list.php?mainmenu=members&leftmenu=members&statut=-1,1', '$user->hasRight("adherent", "cotisation", "creer")', ''),
			array('cgw_contributions_list', 'cgw_contributions', 'List', '/adherents/subscription/list.php?mainmenu=members&leftmenu=members', '$user->hasRight("adherent", "cotisation", "lire")', ''),
			array('cgw_types', '', 'Members types', '/adherents/type.php?mainmenu=members&leftmenu=setup', '$user->hasRight("adherent", "configurer")', '<i class="fa fa-users fa-fw paddingright pictofixedwidth"></i>'),
			array('cgw_types_new', 'cgw_types', 'New', '/adherents/type.php?mainmenu=members&leftmenu=setup&action=create', '$user->hasRight("adherent", "configurer")', ''),
			array('cgw_types_list', 'cgw_types', 'List', '/adherents/type.php?mainmenu=members&leftmenu=setup', '$user->hasRight("adherent", "configurer")', ''),
			array('cgw_onboarding', '', 'Onboarding', '/onboarding/applicants.php?mainmenu=cgw&leftmenu=cgw_onboarding', '$user->hasRight("onboarding", "applicant", "read")', '<i class="fa fa-user-plus fa-fw paddingright pictofixedwidth"></i>'),
		);
		// Every page under Members, Onboarding, here too.
		foreach ($this->menu as $m) {
			if ($m['fk_menu'] == 'fk_mainmenu=members,fk_leftmenu=onboarding') {
				$sep = strpos($m['url'], '?') === false ? '?' : '&';
				$cgw[] = array('cgw_'.$m['leftmenu'], 'cgw_onboarding', $m['titre'], $m['url'].$sep.'mainmenu=cgw&leftmenu=cgw_onboarding', $m['perms'], '');
			}
		}
		$cgw[] = array('cgw_deadlines', 'cgw_onboarding', 'Deadline reminders', '/onboarding/deadlines.php?mainmenu=cgw&leftmenu=cgw_onboarding', 'isModEnabled("project") && $user->hasRight("projet", "lire")', '');
		$cgw[] = array('cgw_onboarding_setup', 'cgw_onboarding', 'Onboarding setup', '/onboarding/admin/setup.php?mainmenu=cgw&leftmenu=cgw_onboarding', '$user->admin', '');
		foreach ($cgw as $c) {
			$this->menu[$r++] = array(
				'fk_menu' => 'fk_mainmenu=cgw'.($c[1] !== '' ? ',fk_leftmenu='.$c[1] : ''),
				'type' => 'left',
				'titre' => $c[2],
				'prefix' => $c[5],
				'mainmenu' => 'cgw',
				'leftmenu' => $c[0],
				'url' => $c[3],
				'langs' => 'onboarding@onboarding',
				'position' => 2000 + $r,
				'enabled' => 'isModEnabled("onboarding")',
				'perms' => $c[4],
				'target' => '',
				'user' => 0,
			);
		}
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
