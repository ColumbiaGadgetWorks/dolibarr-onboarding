<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * One-time import of the old member spreadsheet. Paste, check, import.
 */

$res = 0;
foreach (array('../../main.inc.php', '../../../main.inc.php', '../../../../main.inc.php') as $path) {
	if (!$res && file_exists(__DIR__.'/'.$path)) {
		$res = @include __DIR__.'/'.$path;
	}
}
if (!$res) {
	die('Include of main fails');
}

dol_include_once('/onboarding/class/onboarding.class.php');

if (!$user->admin || !isModEnabled('onboarding')) {
	accessforbidden();
}

$svc = new OnboardingService($db);
$action = GETPOST('action', 'aZ09');
$text = GETPOST('sheet', 'none');
$report = array();
if (($action == 'check' || $action == 'import') && $text !== '') {
	$report = $svc->importLegacy($text, $action != 'import');
}

llxHeader('', 'Import existing members');
$linkback = '<a href="'.dol_buildpath('/onboarding/admin/setup.php', 1).'">Back to setup</a>';
print load_fiche_titre('Import existing members', $linkback, 'members');

print '<p class="opacitymedium">Copy the old member spreadsheet, header line included, and paste it here. Columns are matched by name: <code>name, email, access_code, discord, license, member_type, payment_channel, join_date, open_item, comment</code>. Anyone whose email is already here is skipped, so running it twice is safe. No emails are sent.</p>';
print '<ul class="opacitymedium"><li>A <strong>member_type</strong> of Onboarding becomes a non-member with a signup in progress. Anything else becomes an active member of that type; missing types are created.</li>';
print '<li><strong>payment_channel</strong>: only Givebutter payers get automatic dues tracking and reminders. PayPal, check and N/A members are left alone until you change "Pays dues by" on their member card.</li>';
print '<li><strong>access_code</strong> goes to Badge ID, with access switched on. <strong>license</strong> = yes ticks "ID photo uploaded".</li>';
print '<li>Waiver and agreement stay unticked. Use "Email link" on the onboarding list to ask someone to sign online.</li></ul>';

if ($report) {
	print '<div class="'.($action == 'import' ? 'ok' : 'info').'"><strong>'.($action == 'import' ? 'Imported' : 'Check only, nothing was changed').'</strong><br>';
	foreach ($report as $line) {
		print dol_escape_htmltag($line).'<br>';
	}
	print '</div>';
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<textarea name="sheet" rows="16" class="centpercent" spellcheck="false">'.dol_escape_htmltag($text).'</textarea><br><br>';
print '<button type="submit" name="action" value="check" class="button">Check</button> ';
print '<button type="submit" name="action" value="import" class="button button-save">Import</button>';
print '</form>';

llxFooter();
$db->close();
