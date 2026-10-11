<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Deadline reminders: every project task with a reminder, escalation or yearly
 * repeat set, and what the daily job will do about it next.
 */

$res = 0;
foreach (array('../main.inc.php', '../../main.inc.php', '../../../main.inc.php') as $path) {
	if (!$res && file_exists(__DIR__.'/'.$path)) {
		$res = @include __DIR__.'/'.$path;
	}
}
if (!$res) {
	die('Include of main fails');
}

dol_include_once('/onboarding/class/deadline.class.php');
require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';

if (!isModEnabled('onboarding') || !isModEnabled('project') || !$user->hasRight('projet', 'lire')) {
	accessforbidden();
}
$canrun = $user->hasRight('projet', 'creer');
$deadlines = new OnboardingDeadlines($db);

$action = GETPOST('action', 'aZ09');
if ($action == 'run' && $canrun) {
	setEventMessages($deadlines->run(), null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$showdone = GETPOST('done', 'int') ? 1 : 0;
$tasks = $deadlines->tasks(!$showdone);
$today = OnboardingDeadlines::today();
$groups = array();

llxHeader('', 'Deadline reminders');
$button = $canrun ? '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><button type="submit" name="action" value="run" class="butAction" title="Sends whatever reminders and escalations are due today. The daily scheduled job does the same.">Send due reminders now</button></form>' : '';
print load_fiche_titre('Deadline reminders', $button, 'project');
print '<p class="opacitymedium">Any project task can nag the people assigned to it until it is done. Open or create the task under Projects, assign people to it, set its start and due dates, and fill in <strong>Remind assignees every (days)</strong>. Optionally set <strong>Escalate this many days before due</strong> and an <strong>Escalation group</strong> (for example Board), and tick <strong>Repeat every year</strong> for annual deadlines. Reminders start on the start date and stop when the task reaches 100%, which people can set from the link in the email.</p>';
print '<p><a href="'.$_SERVER['PHP_SELF'].($showdone ? '' : '?done=1').'">'.($showdone ? 'Hide finished tasks' : 'Show finished tasks too').'</a></p>';

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Task</td><td>Assigned</td><td>Start</td><td>Due</td><td class="right">Done</td><td>Reminders</td><td>Escalation</td><td>Yearly</td></tr>';
foreach ($tasks as $t) {
	$done = OnboardingDeadlines::isDone($t);
	$names = array();
	foreach ($deadlines->assignees($t->rowid) as $u) {
		$names[] = dol_escape_htmltag(trim($u->firstname.' '.$u->lastname)).($u->email ? '' : ' <span class="badge badge-status8" title="No email address on their user card, so they get no reminders">no email</span>');
	}
	$due = $t->datee ? substr($t->datee, 0, 10) : '';
	$dueCell = $due ? dol_escape_htmltag(OnboardingDeadlines::show($due)) : '<span class="opacitymedium">none</span>';
	if ($due && !$done && $due < $today) {
		$dueCell .= ' <span class="badge badge-status8">overdue</span>';
	}

	$every = (int) $t->remind_every;
	if ($every > 0) {
		$last = $deadlines->lastNotice($t->rowid, 'nag');
		$rem = 'every '.$every.' day'.($every == 1 ? '' : 's');
		if ($last) {
			$rem .= '<br><span class="opacitymedium small">last '.dol_escape_htmltag(OnboardingDeadlines::show(substr($last->datec, 0, 10))).($last->fk_user ? '' : ': '.dol_escape_htmltag((string) $last->note)).'</span>';
		}
		$next = $deadlines->nextNag($t);
		if ($next) {
			$rem .= '<br><span class="opacitymedium small">next '.dol_escape_htmltag(OnboardingDeadlines::show($next)).'</span>';
		}
	} else {
		$rem = '<span class="opacitymedium">off</span>';
	}

	$on = OnboardingDeadlines::escalateOn($t);
	if ($on !== null) {
		$gid = (int) $t->escalate_group;
		if (!isset($groups[$gid])) {
			$g = new UserGroup($db);
			$groups[$gid] = $g->fetch($gid) > 0 ? $g->name : '#'.$gid;
		}
		$esc = dol_escape_htmltag($groups[$gid]).' on '.dol_escape_htmltag(OnboardingDeadlines::show($on));
		$sent = $deadlines->lastNotice($t->rowid, 'escalate');
		if ($sent) {
			$esc .= '<br><span class="opacitymedium small">sent '.dol_escape_htmltag(OnboardingDeadlines::show(substr($sent->datec, 0, 10))).'</span>';
		}
	} elseif ($t->escalate_days !== null && $t->escalate_days !== '') {
		$esc = '<span class="badge badge-status8" title="Escalation needs a due date and a group">incomplete</span>';
	} else {
		$esc = '<span class="opacitymedium">off</span>';
	}

	$yearly = '';
	if ($t->repeat_yearly) {
		$copy = $deadlines->lastNotice($t->rowid, 'repeat');
		$yearly = $copy ? '<a href="'.DOL_URL_ROOT.'/projet/tasks/task.php?id='.((int) $copy->note).'&withproject=1">next year\'s copy</a>' : 'yes';
	}

	$label = '<a href="'.DOL_URL_ROOT.'/projet/tasks/task.php?id='.((int) $t->rowid).'&withproject=1">'.dol_escape_htmltag($t->label).'</a>';
	$label .= '<br><span class="opacitymedium small">'.dol_escape_htmltag($t->project).'</span>';
	print '<tr class="oddeven"><td>'.$label.'</td>';
	print '<td>'.($names ? implode(', ', $names) : '<span class="badge badge-status8">nobody</span>').'</td>';
	print '<td>'.($t->dateo ? dol_escape_htmltag(OnboardingDeadlines::show(substr($t->dateo, 0, 10))) : '').'</td>';
	print '<td>'.$dueCell.'</td><td class="right">'.((int) $t->progress).'%</td>';
	print '<td>'.$rem.'</td><td>'.$esc.'</td><td>'.$yearly.'</td></tr>';
}
if (!$tasks) {
	print '<tr><td colspan="8"><span class="opacitymedium">No task has a reminder set yet.</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
