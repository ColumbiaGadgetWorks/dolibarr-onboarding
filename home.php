<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * CGW home page: sections laid out by the YAML on the setup page (CGW home
 * page), else home.default.yaml. Top menu "CGW".
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

dol_include_once('/onboarding/class/home.class.php');

if (!isModEnabled('onboarding')) {
	accessforbidden();
}
$home = new OnboardingHome($db);
list($sections, $problems) = OnboardingHome::read(OnboardingHome::layoutText());

llxHeader('', 'CGW home');
$edit = !empty($user->admin) ? '<a href="'.dol_buildpath('/onboarding/admin/setup.php', 1).'#home-layout">Edit this page</a>' : '';
print load_fiche_titre(dol_escape_htmltag(getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'CGW')), $edit, 'home');

if ($problems && !empty($user->admin)) {
	print '<div class="warning">The home page layout has problems, so some sections may be missing:<br>'.implode('<br>', array_map('dol_escape_htmltag', $problems)).'</div>';
}

print '<div style="display:flex;flex-wrap:wrap;gap:1em;align-items:flex-start">';
foreach ($sections as $s) {
	$html = $home->render($s, $user);
	if ($html === '') {
		continue; // Nothing this person may see.
	}
	$full = isset($s['width']) && $s['width'] == 'full';
	print '<div style="flex:1 1 '.($full ? '100%' : 'calc(50% - 1em)').';min-width:300px;box-sizing:border-box">';
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.dol_escape_htmltag(isset($s['title']) ? (string) $s['title'] : '').'</td></tr>';
	print '<tr><td style="padding-top:.6em">'.$html.'</td></tr></table>';
	print '</div>';
}
print '</div>';

llxFooter();
$db->close();
