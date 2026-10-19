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
 *   php tick.php reinit          disable and enable the module (new tables, fields, jobs)
 *   php tick.php bookings MINUTES   run the training booking job as if it were MINUTES from now
 *   php tick.php booking tool JSON | slot TOOL MEMBER WEEKDAY TIME [SEATS] | show REF | link REF trainer|trainee | answer REF trained|noshow|other MINUTES
 *                        | free JSON | class JSON | classfinish ID | close DAY | calendar JSON | lookup EMAIL | person EMAIL | sign EMAIL
 *   php tick.php docs            read the waiver and agreement from their addresses now
 *   php tick.php purchase const NAME VALUE | roletags | tag EMAIL ROLE | untag EMAIL ROLE | member EMAIL CODE FIRST LAST | budget ZONE AMOUNT | bank LABEL | pay ID ACCOUNT | link ID EMAIL ACTION | show ID
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
} elseif ($job == 'purchase') {
	// Fixtures and actions for the purchase request test.
	dol_include_once('/onboarding/class/purchase.class.php');
	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	$pu = new OnboardingPurchase($db, $svc);
	$what = isset($argv[2]) ? $argv[2] : '';
	if ($what == 'const') {
		echo dolibarr_set_const($db, $argv[3], isset($argv[4]) ? $argv[4] : '', 'chaine', 0, '', $conf->entity)."\n";
	} elseif ($what == 'member') {
		// member EMAIL CODE FIRST LAST: a validated member paid up for a year, with a member code
		$adh = new Adherent($db);
		$adh->firstname = $argv[5];
		$adh->lastname = $argv[6];
		$adh->email = $argv[3];
		$adh->login = $argv[3];
		$adh->morphy = 'phy';
		$adh->typeid = getDolGlobalInt('ONBOARDING_TYPE_STANDARD');
		$adh->public = 0;
		$adh->statut = Adherent::STATUS_DRAFT;
		$adh->status = Adherent::STATUS_DRAFT;
		$adh->array_options = array('options_member_code' => $argv[4]);
		$id = $adh->create($user);
		if ($id <= 0) {
			fwrite(STDERR, 'member create failed: '.$adh->error."\n");
			exit(1);
		}
		$adh->fetch($id);
		$adh->validate($user);
		$db->query("UPDATE ".$db->prefix()."adherent SET datefin = '".$db->idate(dol_now() + 365 * 86400)."' WHERE rowid = ".((int) $id));
		echo $id."\n";
	} elseif ($what == 'roletags') {
		echo $pu->installRoleTags()."\n";
	} elseif ($what == 'tag' || $what == 'untag') {
		// tag|untag EMAIL ROLE: give a member a role, or take it away
		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
		$pu->installRoleTags();
		$m = $pu->memberByEmail($argv[3]);
		$cat = new Categorie($db);
		if (!$m || $cat->fetch(0, $argv[4], Categorie::TYPE_MEMBER) <= 0) {
			fwrite(STDERR, 'no such member or role: '.$argv[3].' '.$argv[4]."\n");
			exit(1);
		}
		$adh = new Adherent($db);
		$adh->fetch((int) $m->rowid);
		echo ($what == 'tag' ? $cat->add_type($adh, Categorie::TYPE_MEMBER) : $cat->del_type($adh, Categorie::TYPE_MEMBER))."\n";
	} elseif ($what == 'budget') {
		echo ($pu->training->post('zone', $argv[3], (float) $argv[4], 'allocation', 0, 'test budget', 0, (int) $user->id) ? 'ok' : 'failed')."\n";
	} elseif ($what == 'bank') {
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$a = new Account($db);
		$a->ref = 'T'.mt_rand(100000, 999999); // the column is short
		$a->label = $argv[3];
		$a->courant = 1;
		$a->clos = 0;
		$a->currency_code = 'USD';
		$a->country_id = 1;
		$a->date_solde = dol_now();
		$a->solde = 0;
		$made = $a->create($user);
		if ($made <= 0) {
			fwrite(STDERR, 'bank create failed: '.$a->error.' '.implode('; ', (array) $a->errors)."\n");
		}
		echo $made."\n";
	} elseif ($what == 'pay') {
		echo $pu->markPaid((int) $argv[3], (int) $argv[4], $user)."\n";
	} elseif ($what == 'link') {
		echo $pu->link((int) $argv[3], $argv[4], $argv[5])."\n";
	} elseif ($what == 'show') {
		$row = $pu->fetch((int) $argv[3]);
		if ($row) {
			unset($row->token);
		}
		echo json_encode($row)."\n";
	}
} elseif ($job == 'reinit') {
	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	// Enabling again runs the module's init: new tables, columns, fields, jobs.
	$user = new User($db);
	$user->fetch(0, getenv('DOLI_ADMIN_LOGIN') ?: 'admin');
	$user->getrights();
	$r = activateModule('modOnboarding');
	// activateModule skips init when the module is already on; run it anyway.
	dol_include_once('/onboarding/core/modules/modOnboarding.class.php');
	$mod = new modOnboarding($db);
	$ok = $mod->init('') > 0;
	echo empty($r['errors']) && $ok ? "ok\n" : trim(implode('; ', $r['errors']).' '.$mod->error)."\n";
} elseif ($job == 'docs') {
	echo $svc->fetchDocs()."\n";
} elseif ($job == 'bookings') {
	dol_include_once('/onboarding/class/booking.class.php');
	OnboardingService::$now = dol_now() + ((int) (isset($argv[2]) ? $argv[2] : 0)) * 60;
	echo (new OnboardingBooking($db, $svc))->run()."\n";
} elseif ($job == 'booking') {
	dol_include_once('/onboarding/class/booking.class.php');
	$bk = new OnboardingBooking($db, $svc);
	$what = isset($argv[2]) ? $argv[2] : '';
	$byRef = function ($ref) use ($bk, $db) {
		$rows = $bk->bookingsWhere("ref = '".$db->escape($ref)."'");
		return $rows ? $rows[0] : null;
	};
	if ($what == 'tool') {
		$in = json_decode($argv[3], true);
		$t = $bk->toolByRef($in['ref']);
		echo json_encode($bk->saveTool($in, $user, $t ? (int) $t->rowid : 0))."\n";
	} elseif ($what == 'slot') {
		$t = $bk->toolByRef($argv[3]);
		echo json_encode($bk->addSlot($t ? (int) $t->rowid : 0, (int) $argv[4], (int) $argv[5], $argv[6], 0, isset($argv[7]) ? (int) $argv[7] : 0, $user))."\n";
	} elseif ($what == 'show') {
		echo json_encode($byRef($argv[3]))."\n";
	} elseif ($what == 'link') {
		echo $bk->link($byRef($argv[3]), $argv[4])."\n";
	} elseif ($what == 'answer') {
		OnboardingService::$now = dol_now() + ((int) (isset($argv[5]) ? $argv[5] : 0)) * 60;
		echo json_encode($bk->answer($byRef($argv[3]), $argv[4], 'from the test'))."\n";
	} elseif ($what == 'free') {
		echo json_encode($bk->free(json_decode($argv[3], true), $user))."\n";
	} elseif ($what == 'class') {
		echo json_encode($bk->createClass(json_decode($argv[3], true), $user))."\n";
	} elseif ($what == 'classfinish') {
		$c = $bk->klass((int) $argv[3]);
		$ids = array();
		foreach ($bk->bookingsWhere("fk_class = ".((int) $argv[3])." AND status = 'confirmed'") as $b) {
			$ids[] = (int) $b->rowid;
		}
		echo $bk->classFinish($c, $ids, (int) $user->id)."\n";
	} elseif ($what == 'close') {
		echo $bk->closeDay($argv[3], 'test', $user)."\n";
	} elseif ($what == 'calendar') {
		echo json_encode($bk->setCalendarClosures(json_decode($argv[3], true)))."\n";
	} elseif ($what == 'lookup') {
		echo json_encode($bk->lookup($argv[3]))."\n";
	} elseif ($what == 'person') {
		$p = $bk->person($argv[3]);
		echo json_encode(array('kind' => $p['kind'], 'needs' => $p['needs'], 'member' => $p['member'] ? (int) $p['member']->rowid : 0, 'contact' => $p['contact'], 'applicant' => $p['applicant']))."\n";
	} elseif ($what == 'sign') {
		// The signing token, as the emailed link would carry it.
		$p = $bk->person($argv[3]);
		$app = $bk->applicantFor($p);
		echo ($app ? $svc->issueToken($app, false) : '')."\n";
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
} elseif ($job == 'vote') {
	// Member votes. vote create DAYS LABEL [test] | run MINUTES | meeting ID DAYS |
	// joined EMAIL DAYS | link ID EMAIL WHO | show ID | tables
	dol_include_once('/onboarding/class/vote.class.php');
	$vt = new OnboardingVote($db);
	$what = isset($argv[2]) ? $argv[2] : '';
	$memberId = function ($email) use ($vt) {
		$m = $vt->memberByEmail($email);
		return $m ? (int) $m->rowid : 0;
	};
	if ($what == 'create') {
		$v = $vt->create(dol_now() + (int) round(((float) $argv[3]) * 86400), $argv[4], !empty($argv[5]));
		echo ($v ? $v->rowid : 0)."\n";
	} elseif ($what == 'run') {
		OnboardingService::$now = dol_now() + ((int) (isset($argv[3]) ? $argv[3] : 0)) * 60;
		echo $vt->runJob()."\n";
	} elseif ($what == 'meeting') {
		$db->query("UPDATE ".$db->prefix()."onboarding_vote SET meeting_at = '".$db->idate(dol_now() + (int) round(((float) $argv[4]) * 86400))."' WHERE rowid = ".((int) $argv[3]));
		echo "ok\n";
	} elseif ($what == 'joined') {
		// Pretend the member joined DAYS ago.
		$db->query("UPDATE ".$db->prefix()."adherent SET datevalid = '".$db->idate(dol_now() - ((int) $argv[4]) * 86400)."' WHERE rowid = ".$memberId($argv[3]));
		$db->query("DELETE FROM ".$db->prefix()."subscription WHERE fk_adherent = ".$memberId($argv[3]));
		echo "ok\n";
	} elseif ($what == 'birth') {
		$db->query("UPDATE ".$db->prefix()."adherent SET birth = '".$db->escape($argv[4])."' WHERE rowid = ".$memberId($argv[3]));
		echo "ok\n";
	} elseif ($what == 'link') {
		echo $vt->link($vt->get((int) $argv[3]), $memberId($argv[4]), $argv[5])."\n";
	} elseif ($what == 'show') {
		$v = $vt->get((int) $argv[3]);
		$r = $vt->results($v);
		$qs = array();
		foreach ($r['questions'] as $row) {
			$qs[] = array('title' => $row['q']->title, 'outcome' => $row['outcome'], 'passed' => $row['passed'], 'options' => array_map(function ($o) {
				return array('label' => $o->label, 'votes' => (int) $o->votes);
			}, $row['options']), 'candidates' => array_map(function ($c) {
				return $c->name;
			}, $vt->candidates($v, (int) $row['q']->rowid)));
		}
		echo json_encode(array('status' => $v->status, 'voters' => (int) $v->voters, 'ballots' => (int) $v->ballots, 'quorum' => $r['quorum'], 'met' => $r['met'], 'tag' => $v->tag, 'posted' => $v->posted_url, 'post_error' => $v->post_error, 'questions' => $qs, 'result' => $v->result))."\n";
	} elseif ($what == 'tables') {
		// The columns of the roll and the answers: proof that nothing ties a voter to a choice.
		$out = array();
		foreach (array('onboarding_vote_roll', 'onboarding_vote_option') as $t) {
			$res = $db->query("SHOW COLUMNS FROM ".$db->prefix().$t);
			while ($res && ($o = $db->fetch_object($res))) {
				$out[$t][] = $o->Field.' '.$o->Type;
			}
		}
		echo json_encode($out)."\n";
	}
} else {
	fwrite(STDERR, "Usage: php tick.php daily [days] | sync | dump EMAIL | connect [URL] | import [go] < sheet | emails [go] < list | contact EMAIL | unsubscribe EMAIL | training ...\n");
	exit(1);
}
