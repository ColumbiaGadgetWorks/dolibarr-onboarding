<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The tools people can be trained on, and requests to add one to the Givebutter
 * training form. Requests arrive from the /training request Discord command;
 * Givebutter's form can only be edited by hand, so whoever edits it marks each
 * request done here, and the person who asked is told.
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
if ($canwrite && in_array($action, array('active', 'declined', 'retired'))) {
	setEventMessages($training->decideTool((int) GETPOST('tool', 'int'), $action, $user, trim(GETPOST('reason', 'alphanohtml'))), null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($canwrite && $action == 'add') {
	$r = $training->requestTool(array(
		'tool' => GETPOST('label', 'alphanohtml'),
		'zone' => GETPOST('zone', 'alphanohtml'),
		'price' => GETPOST('price', 'alphanohtml'),
		'requested_by' => $user->getFullName($langs),
	), 'active', $user);
	$why = array('tool' => 'Give the tool a name.', 'zone' => 'Pick a zone.', 'price' => 'Pick a fee.', 'exists' => 'That tool is already listed for that zone.');
	setEventMessages($r['ok'] ? 'Added. Make sure it is on the Givebutter form too.' : (isset($why[$r['error']]) ? $why[$r['error']] : 'Not saved.'), null, $r['ok'] ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$toolButton = function ($id, $action, $label, $reason = false) {
	$out = '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="tool" value="'.((int) $id).'">';
	if ($reason) {
		$out .= '<input type="text" name="reason" placeholder="Reason (optional)" class="minwidth150"> ';
	}
	return $out.'<button type="submit" name="action" value="'.$action.'" class="button smallpaddingimp">'.$label.'</button></form> ';
};

llxHeader('', 'Training tools');
print load_fiche_titre('Training tools', '', 'members');
print '<p class="opacitymedium">Zone bosses ask for a new tool with <code>/training request</code> in Discord. Add it to the "Tool or equipment" answers on the Givebutter training campaign, then press <strong>Added to Givebutter</strong>; the person who asked is emailed when their Discord name matches a member.</p>';

$requests = $training->tools('requested');
print '<h3>Requests</h3><div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Asked</td><td>Tool or equipment</td><td>Zone</td><td class="right">Fee</td><td>Asked by</td><td>Note</td><td></td></tr>';
foreach ($requests as $t) {
	print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($t->datec), 'day').'</td><td><strong>'.dol_escape_htmltag($t->label).'</strong></td><td>'.dol_escape_htmltag($t->zone).'</td>';
	print '<td class="right">'.price($t->price).'</td><td>'.dol_escape_htmltag($t->requested_by).($t->requester_email ? '' : ' <span class="opacitymedium small">(no member match, will not be emailed)</span>').'</td>';
	print '<td>'.dol_escape_htmltag($t->note).'</td><td class="right nowraponall">';
	if ($canwrite) {
		print $toolButton($t->rowid, 'active', 'Added to Givebutter').$toolButton($t->rowid, 'declined', 'Decline', true);
	}
	print '</td></tr>';
}
if (!$requests) {
	print '<tr><td colspan="7"><span class="opacitymedium">No requests waiting.</span></td></tr>';
}
print '</table></div><br>';

$active = $training->tools('active');
print '<h3>On the training form</h3><div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Zone</td><td>Tool or equipment</td><td class="right">Fee</td><td>Since</td><td></td></tr>';
foreach ($active as $t) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($t->zone).'</td><td>'.dol_escape_htmltag($t->label).'</td><td class="right">'.price($t->price).'</td>';
	print '<td>'.dol_print_date($db->jdate($t->date_decided ? $t->date_decided : $t->datec), 'day').'</td><td class="right">'.($canwrite ? $toolButton($t->rowid, 'retired', 'Retire') : '').'</td></tr>';
}
if (!$active) {
	print '<tr><td colspan="5"><span class="opacitymedium">None listed yet. Add the tools already on the Givebutter form below so this list matches it.</span></td></tr>';
}
print '</table></div>';

if ($canwrite) {
	print '<br><form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="add">';
	print 'Add a tool already on the form: <input type="text" name="label" placeholder="Tool or equipment" class="minwidth200"> <select name="zone"><option value="">Zone…</option>';
	foreach ($training->zones() as $z) {
		print '<option value="'.dol_escape_htmltag($z).'">'.dol_escape_htmltag($z).'</option>';
	}
	print '</select> <select name="price"><option value="">Fee…</option>';
	foreach (OnboardingTraining::prices() as $pr) {
		print '<option value="'.$pr.'">'.price($pr).'</option>';
	}
	print '</select> <button type="submit" class="button smallpaddingimp">Add</button></form>';
}

if ($active) {
	print '<br><h3>Answers for the Givebutter form</h3><p class="opacitymedium">The "Tool or equipment" answers, one per line, in the same order as above.</p>';
	print '<textarea readonly rows="'.min(20, count($active) + 1).'" class="quatrevingtpercent">'.dol_escape_htmltag(implode("\n", array_map(function ($t) {
		return $t->label;
	}, $active))).'</textarea>';
}

llxFooter();
$db->close();
