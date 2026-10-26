<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Needs attention: everything flagged for someone to check, in one list.
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

dol_include_once('/onboarding/class/attention.class.php');

if (!isModEnabled('onboarding')) {
	accessforbidden();
}
$attention = new OnboardingAttention($db);
$items = $attention->items($user);
if (!$items) {
	accessforbidden();
}
usort($items, function ($a, $b) {
	if (($a['count'] > 0) != ($b['count'] > 0)) {
		return $a['count'] > 0 ? -1 : 1;
	}
	return $a['urgent'] == $b['urgent'] ? 0 : ($a['urgent'] ? -1 : 1);
});

llxHeader('', 'Needs attention');
print load_fiche_titre('Needs attention', '', 'members');
print '<p class="opacitymedium">Everything Dolibarr has flagged for someone to check. Each line links to where it is dealt with; a line disappears from the top once its count is back to zero. The same list is a box on the home page (Home, the box picker, "Needs attention").</p>';

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>What</td><td class="right">Waiting</td><td>Why it is here</td></tr>';
$clear = 0;
foreach ($items as $i) {
	if ($i['count'] == 0) {
		$clear++;
	}
	$count = $i['count'] > 0 ? '<span class="badge '.($i['urgent'] ? 'badge-status8' : 'badge-status1').'">'.$i['count'].'</span>' : '<span class="opacitymedium">0</span>';
	print '<tr class="oddeven'.($i['count'] == 0 ? ' opacitymedium' : '').'"><td><a href="'.dol_escape_htmltag($i['url']).'">'.dol_escape_htmltag($i['label']).'</a></td>';
	print '<td class="right">'.$count.'</td><td class="small">'.dol_escape_htmltag($i['why']).'</td></tr>';
}
print '</table></div>';
if ($clear == count($items)) {
	print '<p class="ok">All clear: nothing is waiting.</p>';
}

llxFooter();
$db->close();
