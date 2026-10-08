<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Paid trainings from the Givebutter training campaign: who was trained on
 * what, by whom. Trainer names that matched no member are matched here.
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

dol_include_once('/onboarding/class/training.class.php');

if (!isModEnabled('onboarding') || !$user->hasRight('onboarding', 'training', 'read')) {
	accessforbidden();
}
$canwrite = $user->hasRight('onboarding', 'training', 'write');
$training = new OnboardingTraining($db);

$action = GETPOST('action', 'aZ09');
if ($action == 'match' && $canwrite) {
	$n = $training->assignTrainer((int) GETPOST('training', 'int'), (int) GETPOST('trainer', 'int'));
	setEventMessages($n ? $n.' training'.($n > 1 ? 's' : '').' matched and credited.' : 'Pick a member to match.', null, $n ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'void' && $canwrite) {
	$ok = $training->voidTraining((int) GETPOST('training', 'int'), $user);
	setEventMessages($ok ? 'Voided. Its shares were taken back out.' : 'That training was already voided.', null, $ok ? 'mesgs' : 'warnings');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$search = trim(GETPOST('search', 'alphanohtml'));

llxHeader('', 'Trainings');
print load_fiche_titre('Trainings', '', 'members');
if (!OnboardingTraining::enabled()) {
	print '<div class="warning">No training campaign is set, so training fees are not being recorded. Set the training campaign code on the module setup page.</div>';
}

$members = $training->members();
$names = array();
foreach ($members as $m) {
	$names[(int) $m->rowid] = trim($m->firstname.' '.$m->lastname).($m->email ? ' ('.$m->email.')' : '');
}

$p = $db->prefix();
$resql = $db->query("SELECT * FROM ".$p."onboarding_training WHERE entity = ".((int) $conf->entity)." AND status = 'trainer_unmatched' ORDER BY transacted_at DESC LIMIT 200");
$unmatched = array();
while ($resql && ($o = $db->fetch_object($resql))) {
	$unmatched[] = $o;
}
if ($unmatched) {
	print '<h3>Trainer not matched</h3>';
	print '<p class="opacitymedium">The trainer name typed at checkout matched no member, or more than one. Pick the member; every other training typed with the same name is matched too, and future ones are matched automatically.</p>';
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>Date</td><td>Trainee</td><td>Tool</td><td>Trainer as typed</td><td class="right">Trainer\'s share</td><td></td></tr>';
	foreach ($unmatched as $o) {
		print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($o->transacted_at), 'day').'</td>';
		print '<td>'.dol_escape_htmltag(trim($o->firstname.' '.$o->lastname)).'<br><span class="opacitymedium small">'.dol_escape_htmltag($o->email).'</span></td>';
		print '<td>'.dol_escape_htmltag($o->tool).'</td><td><strong>'.dol_escape_htmltag($o->trainer_name !== '' ? $o->trainer_name : '(blank)').'</strong></td>';
		print '<td class="right">'.price($o->trainer_amount).'</td><td class="right">';
		if ($canwrite) {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="training" value="'.((int) $o->rowid).'">';
			print '<select name="trainer" class="minwidth200"><option value="0">Member…</option>';
			foreach ($names as $id => $label) {
				print '<option value="'.$id.'">'.dol_escape_htmltag($label).'</option>';
			}
			print '</select> <button type="submit" name="action" value="match" class="button smallpaddingimp">Match</button>';
			print '</form>';
		}
		print '</td></tr>';
	}
	print '</table></div><br>';
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'"><input type="text" name="search" value="'.dol_escape_htmltag($search).'" placeholder="Email, name or tool" class="minwidth300"> <button type="submit" class="button smallpaddingimp">Search</button></form><br>';

$where = "t.entity = ".((int) $conf->entity);
if ($search !== '') {
	$like = $db->escape($db->escapeforlike(strtolower($search)));
	$where .= " AND (LOWER(t.email) LIKE '%".$like."%' OR LOWER(CONCAT(t.firstname, ' ', t.lastname)) LIKE '%".$like."%' OR LOWER(t.tool) LIKE '%".$like."%' OR LOWER(t.zone) LIKE '%".$like."%')";
}
$resql = $db->query("SELECT t.* FROM ".$p."onboarding_training AS t WHERE ".$where." ORDER BY t.transacted_at DESC LIMIT 500");
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Date</td><td>Trainee</td><td>Zone</td><td>Tool</td><td>Trainer</td><td class="right">Fee</td><td class="right">Zone share</td><td class="right">Trainer share</td><td>Givebutter id</td><td></td></tr>';
$n = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$n++;
	$void = $o->status == 'ignored';
	print '<tr class="oddeven'.($void ? ' opacitymedium' : '').'">';
	print '<td>'.dol_print_date($db->jdate($o->transacted_at), 'day').'</td>';
	print '<td>'.dol_escape_htmltag(trim($o->firstname.' '.$o->lastname)).'<br><span class="opacitymedium small">'.dol_escape_htmltag($o->email).'</span></td>';
	print '<td>'.dol_escape_htmltag($o->zone).'</td><td>'.dol_escape_htmltag($o->tool).($void ? ' <span class="badge badge-status8">voided</span>' : '').'</td>';
	print '<td>'.($o->fk_trainer && isset($names[(int) $o->fk_trainer]) ? dol_escape_htmltag(preg_replace('/ \(.*$/', '', $names[(int) $o->fk_trainer])) : '<span class="opacitymedium">'.dol_escape_htmltag($o->trainer_name).' (not matched)</span>').'</td>';
	print '<td class="right">'.price($o->amount).'</td><td class="right">'.price($o->zone_amount).'</td><td class="right">'.price($o->trainer_amount).'</td>';
	print '<td>'.dol_escape_htmltag($o->gb_transaction_id).'</td><td class="right">';
	if ($canwrite && !$void) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="training" value="'.((int) $o->rowid).'">';
		print '<button type="submit" name="action" value="void" class="button smallpaddingimp" title="Refunded or entered by mistake: takes its shares back out">Void</button></form>';
	}
	print '</td></tr>';
}
if (!$n) {
	print '<tr><td colspan="10"><span class="opacitymedium">'.($search !== '' ? 'No training matches that search.' : 'No trainings yet. They appear here when someone pays on the training campaign.').'</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
