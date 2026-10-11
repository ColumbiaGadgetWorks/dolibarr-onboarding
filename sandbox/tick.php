<?php
/* Sandbox only. Runs a scheduled job right now, optionally pretending it is
 * some days in the future, so reminders and lapses can be watched without waiting.
 *
 *   php tick.php daily 8      run reminders as if it were 8 days from now
 *   php tick.php sync         pull from the fake Givebutter
 *   php tick.php dump EMAIL   print what Dolibarr holds for one person
 *   php tick.php contact EMAIL   the email updates list's view of one address
 *   php tick.php training match ID MEMBER | void ID | approve ID | reject ID | refunded ID
 *   php tick.php training balance zone|trainer KEY
 *   php tick.php training link TRAINING REGISTRATION | expire
 *   php tick.php deadlines 8     run deadline reminders as if it were 8 days from now
 *   php tick.php deadline setup STAMP | task JSON | link TASK USER | show TASK | find LABEL
 */

if (php_sapi_name() !== 'cli') {
	die('CLI only');
}
define('NOSESSION', '1');
require '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/onboarding/class/onboarding.class.php');

$svc = new OnboardingService($db);
$user = $svc->actor();
$job = isset($argv[1]) ? $argv[1] : '';

if ($job == 'daily') {
	OnboardingService::$now = dol_now() + ((int) (isset($argv[2]) ? $argv[2] : 0)) * 86400;
	echo $svc->dunning().'; '.$svc->cleanup()."\n";
} elseif ($job == 'sync') {
	echo $svc->reconcile()."\n";
} elseif ($job == 'connect') {
	echo $svc->connectGivebutter(isset($argv[2]) ? $argv[2] : '')."\n";
} elseif ($job == 'import') {
	echo implode("\n", $svc->importLegacy((string) file_get_contents('php://stdin'), !(isset($argv[2]) && $argv[2] == 'go')))."\n";
} elseif ($job == 'emails') {
	echo implode("\n", $svc->importEmails((string) file_get_contents('php://stdin'), 'sandbox import', !(isset($argv[2]) && $argv[2] == 'go')))."\n";
} elseif ($job == 'contact') {
	// What the email updates list holds for one address.
	$id = $svc->contactByEmail(isset($argv[2]) ? $argv[2] : '');
	$out = array('id' => $id, 'tagged' => false, 'unsubscribed' => $svc->isUnsubscribed(isset($argv[2]) ? $argv[2] : ''), 'contacts' => 0);
	if ($id) {
		$c = new Contact($db);
		$c->fetch($id);
		$res = $db->query("SELECT COUNT(*) AS n FROM ".$db->prefix()."socpeople WHERE LOWER(email) = '".$db->escape(OnboardingService::cleanEmail($argv[2]))."'");
		$out = array_merge($out, array('tagged' => $svc->isTaggedForUpdates($id), 'firstname' => $c->firstname, 'lastname' => $c->lastname, 'note' => $c->note_private, 'fields' => $c->array_options, 'contacts' => (int) $db->fetch_object($res)->n));
	}
	echo json_encode($out)."\n";
} elseif ($job == 'unsubscribe') {
	$c = new Contact($db);
	$c->email = OnboardingService::cleanEmail(isset($argv[2]) ? $argv[2] : '');
	echo $c->setNoEmail(1)."\n";
} elseif ($job == 'training') {
	dol_include_once('/onboarding/class/training.class.php');
	$t = new OnboardingTraining($db, $svc);
	$what = isset($argv[2]) ? $argv[2] : '';
	$id = (int) (isset($argv[3]) ? $argv[3] : 0);
	if ($what == 'match') {
		echo $t->assignTrainer($id, (int) $argv[4])."\n";
	} elseif ($what == 'void') {
		echo ($t->voidTraining($id, $user) ? 'voided' : 'not voided')."\n";
	} elseif ($what == 'approve') {
		echo $t->approveCredit($id, $user)."\n";
	} elseif ($what == 'reject') {
		echo $t->rejectCredit($id, $user)."\n";
	} elseif ($what == 'refunded') {
		echo $t->markRefunded($id)."\n";
	} elseif ($what == 'link') {
		echo $t->linkRegistration($id, (int) $argv[4])."\n";
	} elseif ($what == 'expire') {
		echo $t->expireRegistrations()."\n";
	} elseif ($what == 'balance') {
		echo $t->balance($argv[3], $argv[4])."\n";
	}
} elseif ($job == 'deadlines') {
	dol_include_once('/onboarding/class/deadline.class.php');
	OnboardingService::$now = dol_now() + ((int) (isset($argv[2]) ? $argv[2] : 0)) * 86400;
	echo (new OnboardingDeadlines($db, $svc))->run()."\n";
} elseif ($job == 'deadline') {
	// Fixtures for the deadline reminder test: people, a group, a project, tasks.
	dol_include_once('/onboarding/class/deadline.class.php');
	require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';
	require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
	require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
	$deadlines = new OnboardingDeadlines($db, $svc);
	$what = isset($argv[2]) ? $argv[2] : '';
	if ($what == 'setup') {
		$stamp = preg_replace('/\D/', '', $argv[3]);
		$ids = array();
		foreach (array('treasurer' => 'Tess', 'president' => 'Pres', 'secretary' => 'Sec', 'quiet' => 'Quinn') as $role => $first) {
			$u = new User($db);
			$u->login = $role.$stamp;
			$u->firstname = $first;
			$u->lastname = 'Board'.$stamp;
			$u->email = $role !== 'quiet' ? $role.'-'.$stamp.'@example.test' : '';
			$ids[$role] = $u->create($user);
		}
		$g = new UserGroup($db);
		$g->name = 'Board '.$stamp;
		$ids['group'] = $g->create($user);
		foreach (array('treasurer', 'president', 'secretary', 'quiet') as $role) {
			$u = new User($db);
			$u->fetch($ids[$role]);
			$u->SetInGroup($ids['group'], $conf->entity);
		}
		$p = new Project($db);
		$p->ref = 'PJ'.$stamp;
		$p->title = 'Board deadlines '.$stamp;
		$p->socid = 0;
		$p->date_start = dol_now();
		$ids['project'] = $p->create($user);
		$p->setValid($user);
		echo json_encode($ids)."\n";
	} elseif ($what == 'task') {
		$in = json_decode($argv[3], true);
		$t = new Task($db);
		$t->fk_project = (int) $in['project'];
		$t->ref = 'TK'.preg_replace('/\D/', '', microtime(true));
		$t->label = $in['label'];
		$t->description = isset($in['description']) ? $in['description'] : '';
		$t->date_start = isset($in['start']) ? dol_now() + $in['start'] * 86400 : '';
		$t->date_end = isset($in['due']) ? dol_now() + $in['due'] * 86400 : '';
		$t->progress = 0;
		foreach (array('remind_every', 'escalate_days', 'escalate_group', 'repeat_yearly') as $f) {
			if (isset($in[$f])) {
				$t->array_options['options_'.$f] = $in[$f];
			}
		}
		$id = $t->create($user);
		if ($id <= 0) {
			fwrite(STDERR, 'task create failed: '.$t->error."\n");
			exit(1);
		}
		foreach ((array) (isset($in['assign']) ? $in['assign'] : array()) as $uid) {
			$t->add_contact((int) $uid, 'TASKEXECUTIVE', 'internal');
		}
		echo $id."\n";
	} elseif ($what == 'link') {
		echo $deadlines->link((int) $argv[3], (int) $argv[4])."\n";
	} elseif ($what == 'show' || $what == 'find') {
		$where = $what == 'show' ? "t.rowid = ".((int) $argv[3]) : "t.label = '".$db->escape($argv[3])."'";
		$res = $db->query("SELECT t.rowid, t.dateo, t.datee, t.progress, t.fk_statut, ef.remind_every, ef.escalate_days, ef.escalate_group, ef.repeat_yearly FROM ".$db->prefix()."projet_task AS t LEFT JOIN ".$db->prefix()."projet_task_extrafields AS ef ON ef.fk_object = t.rowid WHERE ".$where." ORDER BY t.rowid");
		$out = array();
		while ($res && ($o = $db->fetch_object($res))) {
			$o->assigned = array_keys($deadlines->assignees($o->rowid));
			$out[] = $o;
		}
		echo json_encode($out)."\n";
	}
} elseif ($job == 'dump') {
	$app = $svc->findByEmail(isset($argv[2]) ? $argv[2] : '');
	$out = array('applicant' => $app, 'member' => null, 'contact_fields' => null);
	if ($app && $app->fk_adherent > 0) {
		$adh = new Adherent($db);
		$adh->fetch((int) $app->fk_adherent);
		$res = $db->query("SELECT COUNT(*) AS n FROM ".$db->prefix()."subscription WHERE fk_adherent = ".((int) $adh->id));
		$n = $res ? $db->fetch_object($res) : null;
		$out['member'] = array('id' => $adh->id, 'status' => (int) $adh->statut, 'typeid' => (int) $adh->typeid, 'paid_until' => $adh->datefin ? gmdate('Y-m-d', $adh->datefin) : null, 'subscriptions' => $n ? (int) $n->n : 0, 'fields' => $adh->array_options);
	}
	if ($app && $app->fk_socpeople > 0) {
		$c = new Contact($db);
		if ($c->fetch((int) $app->fk_socpeople) > 0) {
			$out['contact_fields'] = $c->array_options;
		}
	}
	if ($app) {
		unset($out['applicant']->token_hash);
		$out['files'] = array_map('basename', glob(DOL_DATA_ROOT.'/onboarding/applicant/'.((int) $app->rowid).'/*') ?: array());
	}
	echo json_encode($out)."\n";
} else {
	fwrite(STDERR, "Usage: php tick.php daily [days] | sync | dump EMAIL | connect [URL] | import [go] < sheet | emails [go] < list | contact EMAIL | unsubscribe EMAIL | training ...\n");
	exit(1);
}
