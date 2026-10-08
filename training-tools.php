<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The training catalog: which tools can be paid for on the website, in which
 * zone, at what fee, and who may train on each. It lives in the website's
 * repository (data/training.yaml) and is edited there by pull request; this
 * page shows the published copy and checks each trainer's badge code against
 * the member cards.
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
$training = new OnboardingTraining($db);
$catalog = $training->catalog(true);
$edit = getDolGlobalString('ONBOARDING_TRAINING_CATALOG_EDIT_URL');

llxHeader('', 'Training tools');
print load_fiche_titre('Training tools', $edit ? '<a class="butAction" href="'.dol_escape_htmltag($edit).'" target="_blank" rel="noopener">Edit the catalog</a>' : '', 'members');
print '<p class="opacitymedium">These are the tools people can pay a training fee for on the website. To add a tool or a trainer, or change a fee, edit <code>data/training.yaml</code> in the website repository in a pull request; once merged, the website and this page pick it up (this page refreshes when opened, and daily).</p>';

if (!$catalog) {
	print '<div class="warning">The training catalog could not be read. Check the <strong>Training catalog</strong> address on the module setup page.</div>';
	llxFooter();
	$db->close();
	exit;
}
if (!empty($catalog['fetched'])) {
	print '<p class="opacitymedium small">Copy fetched '.dol_print_date($catalog['fetched'], 'dayhour').'.</p>';
}

$trainers = array();
foreach ($catalog['trainers'] as $t) {
	$trainers[(string) $t['id']] = $t;
}
$memberCell = function ($t) use ($training) {
	$id = $training->memberByCode(isset($t['member']) ? $t['member'] : '');
	if (!$id) {
		$id = $training->matchTrainer(isset($t['name']) ? $t['name'] : '');
	}
	$label = dol_escape_htmltag($t['name']).(!empty($t['member']) ? ' <span class="opacitymedium">'.dol_escape_htmltag($t['member']).'</span>' : '');
	if ($id) {
		return '<a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $id).'">'.$label.'</a>';
	}
	return $label.' <span class="badge badge-status8" title="No member card has this badge code or name, so their share of fees waits on the Trainings page to be matched by hand.">no member match</span>';
};

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Zone</td><td>Tool or equipment</td><td class="right">Fee</td><td>Trainers</td></tr>';
$n = 0;
foreach ($catalog['tools'] as $tool) {
	$n++;
	$names = array();
	foreach ((array) $tool['trainers'] as $tid) {
		$names[] = isset($trainers[$tid]) ? $memberCell($trainers[$tid]) : dol_escape_htmltag($tid);
	}
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($tool['zone']).'</td><td>'.dol_escape_htmltag($tool['name']).'</td>';
	print '<td class="right">'.price($tool['fee']).'</td><td>'.implode(', ', $names).'</td></tr>';
}
if (!$n) {
	print '<tr><td colspan="4"><span class="opacitymedium">No tools are open for training yet. Add them to the catalog.</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
