<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Module setup page.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/onboarding/class/onboarding.class.php');

if (!$user->admin) {
	accessforbidden();
}

$svc = new OnboardingService($db);
$docs = $svc->docs();

// name => array(label, kind, help). Kinds: text, secret, area.
$sections = array(
	'Website connection' => array(
		'ONBOARDING_API_KEY' => array('Key for the website', 'text', 'The website Worker sends this as its DOLIBARR_API_KEY secret. Changing it disconnects the website until the secret is updated.'),
		'ONBOARDING_JOIN_URL' => array('Join page address', 'text', 'For example https://columbiagadgetworks.org/membership/join/ . Used for the "pick up where you left off" email.'),
		'ONBOARDING_STAFF_EMAIL' => array('Membership team email', 'text', 'Told when someone becomes a paying member (needs a badge) or stops paying (badge off).'),
		'ONBOARDING_MAIL_FROM' => array('Send emails from', 'text', 'Leave empty to use Dolibarr\'s default sender.'),
		'ONBOARDING_UPDATES_TAG' => array('Email updates tag', 'text', 'Contacts who ask for email updates on the website get this tag. To send an update, create an emailing in Tools, EMailing and add recipients from Contacts filtered by this tag. If you rename it here, rename the tag in Tags/Categories too.'),
	),
	'Givebutter' => array(
		'ONBOARDING_GB_API_KEY' => array('Givebutter API key', 'secret', 'Settings, Integrations, API Keys in Givebutter. Used for the hourly sync and for the Connect button below.'),
		'ONBOARDING_PUBLIC_URL' => array('This Dolibarr\'s public address', 'text', 'The address Givebutter can reach, for example https://dolibarr.example.org . Leave empty if Dolibarr\'s own address setting is already the public one.'),
		'ONBOARDING_GB_CAMPAIGN_CODE' => array('Membership campaign code', 'text', 'Only payments to this campaign count as dues. It is the code at the end of the campaign link, for example kxk2FA. Leave empty to count every payment.'),
		'ONBOARDING_CHECKOUT_URL' => array('Dues payment page', 'text', 'Where the signup sends people to pay, for example https://givebutter.com/kxk2FA .'),
		'ONBOARDING_GB_API_BASE' => array('Givebutter API address', 'text', 'Only change this to point at the sandbox.'),
		'ONBOARDING_DUES_STANDARD' => array('Standard monthly dues', 'text', ''),
		'ONBOARDING_DUES_SUPPORTER' => array('Supporter monthly dues', 'text', 'A payment of at least this much makes the member a Supporter.'),
	),
	'Reminders' => array(
		'ONBOARDING_REMINDER_DAYS' => array('Send reminders after (days)', 'text', 'Comma separated days after a payment is cancelled or missed, for example 3,7,14.'),
		'ONBOARDING_GRACE_DAYS' => array('Mark non-paying after (days)', 'text', 'Counted from the same moment. A member who has already paid for a period keeps it until it runs out.'),
		'ONBOARDING_MAIL_REMINDER_SUBJECT' => array('Reminder subject', 'text', 'Leave any email field empty to use the built-in wording.'),
		'ONBOARDING_MAIL_REMINDER_BODY' => array('Reminder text', 'area', 'Placeholders: {firstname} {lastname} {org} {payment_url} {days_left}'),
		'ONBOARDING_MAIL_LAPSED_SUBJECT' => array('Membership ended subject', 'text', ''),
		'ONBOARDING_MAIL_LAPSED_BODY' => array('Membership ended text', 'area', 'Placeholders: {firstname} {lastname} {org} {payment_url}'),
		'ONBOARDING_MAIL_WELCOME_SUBJECT' => array('Welcome subject', 'text', ''),
		'ONBOARDING_MAIL_WELCOME_BODY' => array('Welcome text', 'area', 'Sent after the first dues payment.'),
		'ONBOARDING_MAIL_RESUME_SUBJECT' => array('Signup link subject', 'text', ''),
		'ONBOARDING_MAIL_RESUME_BODY' => array('Signup link text', 'area', 'Sent after step 1. Placeholders: {firstname} {org} {link}'),
	),
	'Documents' => array(
		'ONBOARDING_WAIVER_TEXT' => array('Liability waiver', 'area', 'Plain text. Shown as a placeholder until you save your own wording. Editing it creates a new version; existing signatures keep the text they signed. Current version: '.$docs['waiver']['version']),
		'ONBOARDING_AGREEMENT_TEXT' => array('Membership agreement', 'area', 'Current version: '.$docs['agreement']['version']),
	),
	'Housekeeping' => array(
		'ONBOARDING_DRAFT_DAYS' => array('Delete abandoned signups after (days)', 'text', '0 keeps them forever.'),
		'ONBOARDING_ID_RETENTION_DAYS' => array('Delete ID photos after (days)', 'text', 'Counted from the day signup completes. 0 keeps them. The "ID photo uploaded" tick stays either way.'),
	),
);

$action = GETPOST('action', 'aZ09');
if ($action == 'save') {
	$errors = 0;
	foreach ($sections as $fields) {
		foreach ($fields as $name => $def) {
			if ($def[1] == 'secret' && GETPOST($name, 'none') === '') {
				continue; // Left blank: keep the stored secret.
			}
			$value = $def[1] == 'area' ? GETPOST($name, 'nohtml') : trim(GETPOST($name, 'alphanohtml'));
			if ($name == 'ONBOARDING_API_KEY' && strlen($value) < 24) {
				setEventMessages('The website key must be at least 24 characters. It was not changed.', null, 'errors');
				$errors++;
				continue;
			}
			if (dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity) < 0) {
				$errors++;
			}
		}
	}
	if (!$errors) {
		setEventMessages('Saved', null);
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'runsync' || $action == 'rundaily' || $action == 'connect') {
	if ($action == 'connect') {
		$out = $svc->connectGivebutter();
	} else {
		$out = $action == 'runsync' ? $svc->reconcile() : $svc->dunning().'; '.$svc->cleanup();
	}
	setEventMessages($out, null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', 'Member onboarding setup');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre('Member onboarding setup', $linkback, 'title_setup');

$endpoint = dol_buildpath('/onboarding/public/api.php', 2);
print '<div class="info">The website talks to Dolibarr at <strong>'.dol_escape_htmltag($endpoint).'</strong>. In the website Worker, set the secret <code>DOLIBARR_URL</code> to <strong>'.dol_escape_htmltag(preg_replace('/\/custom\/onboarding\/public\/api\.php$/', '', $endpoint)).'</strong> and <code>DOLIBARR_API_KEY</code> to the key below.</div>';

$connected = getDolGlobalString('ONBOARDING_GB_WEBHOOK_ID') !== '';
print '<div class="'.($connected ? 'ok' : 'warning').'">';
print $connected ? 'Givebutter is connected: it reports payments and cancelled plans to <strong>'.dol_escape_htmltag($svc->webhookUrl()).'</strong>. ' : 'Givebutter is not connected yet, so payments are only noticed by the hourly sync. Save the API key below, then press Connect. ';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=connect&token='.newToken().'">'.($connected ? 'Reconnect' : 'Connect Givebutter').'</a>';
print ' <a class="butAction" href="'.dol_buildpath('/onboarding/admin/import.php', 1).'">Import existing members</a>';
print ' <a class="butAction" href="'.dol_buildpath('/onboarding/admin/import-emails.php', 1).'">Import email list</a>';
print '</div>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
foreach ($sections as $title => $fields) {
	print '<br><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="2">'.dol_escape_htmltag($title).'</td></tr>';
	foreach ($fields as $name => $def) {
		$value = getDolGlobalString($name);
		print '<tr class="oddeven"><td class="titlefield tdtop">'.dol_escape_htmltag($def[0]).'</td><td>';
		if ($def[1] == 'area') {
			$shown = $value;
			if ($name == 'ONBOARDING_WAIVER_TEXT' && $value === '') {
				$shown = $docs['waiver']['text'];
			}
			if ($name == 'ONBOARDING_AGREEMENT_TEXT' && $value === '') {
				$shown = $docs['agreement']['text'];
			}
			print '<textarea name="'.$name.'" rows="'.(strpos($name, '_TEXT') ? 14 : 6).'" class="quatrevingtpercent">'.dol_escape_htmltag($shown).'</textarea>';
		} elseif ($def[1] == 'secret') {
			print '<input type="password" name="'.$name.'" value="" class="minwidth400" autocomplete="new-password" placeholder="'.($value !== '' ? 'Saved. Type a new one to replace it.' : 'Not set').'">';
		} else {
			print '<input type="text" name="'.$name.'" value="'.dol_escape_htmltag($value).'" class="minwidth400">';
		}
		if ($def[2] !== '') {
			print '<br><span class="opacitymedium small">'.dol_escape_htmltag($def[2]).'</span>';
		}
		print '</td></tr>';
	}
	print '</table>';
}
print '<br><div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print '<br><div class="opacitymedium">The two scheduled jobs run on their own once Dolibarr\'s scheduled jobs are set up. To run them now: ';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=runsync&token='.newToken().'">Sync Givebutter now</a> ';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=rundaily&token='.newToken().'">Run reminders now</a></div>';

llxFooter();
$db->close();
