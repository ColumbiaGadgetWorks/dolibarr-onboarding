<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Import an old email updates list (the website's CSV export, an old site's
 * subscriber export). Paste, check, import.
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
$source = GETPOST('source', 'alphanohtml');
$report = array();
if (($action == 'check' || $action == 'import') && $text !== '') {
	$report = $svc->importEmails($text, $source, $action != 'import');
}

llxHeader('', 'Import email list');
$linkback = '<a href="'.dol_buildpath('/onboarding/admin/setup.php', 1).'">Back to setup</a>';
print load_fiche_titre('Import email list', $linkback, 'email');

$tag = getDolGlobalString('ONBOARDING_UPDATES_TAG', 'Email updates');
print '<p class="opacitymedium">Paste a list of people who asked for email updates, header line included: the website\'s subscriber CSV, or a subscriber export from an old site. Only an <code>email</code> column is required; a <code>name</code> (or first and last name) and a signup date column (<code>subscribed</code>, <code>date</code>, <code>created</code>...) are used when present.</p>';
print '<ul class="opacitymedium"><li>Each address becomes a contact tagged <strong>'.dol_escape_htmltag($tag).'</strong>, or an existing contact with that address gets the tag. Nobody is added twice, so running it again is safe.</li>';
print '<li>Addresses that unsubscribed from a Dolibarr emailing are skipped.</li>';
print '<li>Only import people who asked to hear from you. Contact form senders and donors did not. No emails are sent.</li></ul>';

if ($report) {
	print '<div class="'.($action == 'import' ? 'ok' : 'info').'"><strong>'.($action == 'import' ? 'Imported' : 'Check only, nothing was changed').'</strong><br>';
	foreach ($report as $line) {
		print dol_escape_htmltag($line).'<br>';
	}
	print '</div>';
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<p>Where this list came from (written on each new contact): <input type="text" name="source" class="minwidth300" value="'.dol_escape_htmltag($source !== '' ? $source : 'website signup list').'"></p>';
print '<textarea name="sheet" rows="16" class="centpercent" spellcheck="false">'.dol_escape_htmltag($text).'</textarea><br><br>';
print '<button type="submit" name="action" value="check" class="button">Check</button> ';
print '<button type="submit" name="action" value="import" class="button button-save">Import</button>';
print '</form>';

llxFooter();
$db->close();
