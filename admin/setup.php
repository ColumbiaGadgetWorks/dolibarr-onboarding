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
	'Training fees' => array(
		'ONBOARDING_TRAINING_CAMPAIGN_CODE' => array('Training campaign code', 'text', 'The Givebutter campaign people pay training fees on (through the widget on the website\'s /training/book/ page), for example the code at the end of its link. Give it no checkout questions: the booking carries the tool, zone, trainer and time. Leave empty to turn training tracking off.'),
		'ONBOARDING_TRAINING_TRAINER_SHARE' => array('Trainer\'s share (percent)', 'text', 'Of what arrives after Givebutter\'s fee, credited to the trainer. The rest goes to the zone\'s budget. A tool can set its own.'),
		'ONBOARDING_TRAINING_CREDIT_THRESHOLD' => array('Credit for a month of dues', 'text', 'When a trainer\'s credit reaches this, a dues refund waits for approval under Training accounts, and the membership team is emailed.'),
		'ONBOARDING_TRAINING_ZONES' => array('Zones', 'text', 'Comma separated, e.g. Digital Fab,Electronics,Woodworking,Machining,Metalworking,Crafting. Each has a budget under Training accounts. Zones of the training tools are added to these.'),
		'ONBOARDING_TRAINED_TAG' => array('Trained tag', 'text', 'Trainees get a tag "<this>: <tool>" on their member and contact cards.'),
		'ONBOARDING_TRAINING_LOOKUP_URL' => array('Training lookup page', 'text', 'The website page where people check what they are trained on, for example https://columbiagadgetworks.org/training/ . Put in the email sent after a training.'),
		'ONBOARDING_MAIL_TRAINED_SUBJECT' => array('Trained email subject', 'text', 'Placeholders: {tool}'),
		'ONBOARDING_MAIL_TRAINED_BODY' => array('Trained email text', 'area', 'Sent to the person who paid. Placeholders: {firstname} {tool} {zone} {trainer} {lookup_url} {org}'),
	),
	'Training bookings' => array(
		'ONBOARDING_WEBSITE_URL' => array('Website', 'text', 'e.g. https://columbiagadgetworks.org . Dolibarr sends it the training catalog and the lookup copy whenever they change. Empty: nothing is sent and the website only picks changes up every 15 minutes.'),
		'ONBOARDING_TRAINING_BOOK_URL' => array('Booking page', 'text', 'Empty: <website>/training/book/ . The signing link in emails opens it.'),
		'ONBOARDING_ALERT_EMAIL' => array('Alerts go to', 'text', 'Problems between Dolibarr, the website and Givebutter. Empty: whoever holds the secretary tag.'),
		'ONBOARDING_TRAINING_TZ' => array('Time zone', 'text', 'Trainers\' times are on this clock, e.g. America/Chicago.'),
		'ONBOARDING_TRAINING_WEEKS_AHEAD' => array('Bookable weeks ahead', 'text', 'Default 6.'),
		'ONBOARDING_TRAINING_CLOSE_HOURS' => array('Booking closes (hours before)', 'text', 'Default 48, so the trainer has a day to confirm.'),
		'ONBOARDING_TRAINING_HOLD_MINUTES' => array('Seat held while paying (minutes)', 'text', 'Default 30. A payment that arrives up to a day later still finds the booking if the seat is free.'),
		'ONBOARDING_TRAINING_CONFIRM_HOURS' => array('Trainer must confirm (hours before)', 'text', 'Default 24. Not confirmed by then: the session is cancelled and the trainee is asked to reschedule, ask for a refund, or tell us something else.'),
		'ONBOARDING_TRAINING_CONFIRM_REMIND_HOURS' => array('Remind the trainer to confirm after (hours)', 'text', 'Default 24 after the payment.'),
		'ONBOARDING_TRAINING_CANCEL_HOURS' => array('Trainee can cancel until (hours before)', 'text', 'Default 2. Cancelling in time means a full refund or a new date.'),
		'ONBOARDING_TRAINING_FREE_REBOOKS' => array('Free new dates after a no-show', 'text', 'Default 1. After that the fee is kept and split as usual.'),
		'ONBOARDING_TRAINING_REBOOK_DAYS' => array('Free new date must be picked within (days)', 'text', 'Default 60.'),
		'ONBOARDING_TRAINING_DAYOF_HOUR' => array('Day-of reminder at (hour)', 'text', 'Default 9, on the shop\'s clock.'),
		'ONBOARDING_TRAINING_AFTER_REMINDERS' => array('Ask the trainer again after (hours)', 'text', 'After a session the trainer is asked whether it happened; again this many hours after the end. Default 1,24.'),
		'ONBOARDING_TRAINING_TRAINEE_FOLLOWUP_HOURS' => array('Ask the trainee after (hours)', 'text', 'Trainer still silent this long after the end: the trainee gets "My trainer didn\'t show up" and the secretary is told. Default 48.'),
		'ONBOARDING_TRAINING_CLASS_OPEN_BEFORE' => array('Class code works from (minutes before)', 'text', 'Default 30.'),
		'ONBOARDING_TRAINING_CLASS_OPEN_AFTER' => array('Class code works until (minutes after the end)', 'text', 'Default 180.'),
		'ONBOARDING_TRAINING_MIN_AGE' => array('Youngest trainee', 'text', 'Default 12. Under 18, a parent or guardian signs the waiver.'),
		'ONBOARDING_TRAINING_GUARDIAN_PRESENT' => array('Guardian must come with a minor (1 or 0)', 'text', 'Default 1. Said in the trainer\'s and trainee\'s emails.'),
		'ONBOARDING_TRAINER_TAG' => array('Trainer tag', 'text', 'Trainers get a tag "<this>: <tool>". Default Trainer.'),
	),
	'Purchase requests' => array(
		'ONBOARDING_PURCHASE_LEAD_LIMIT' => array('Zone lead limit', 'text', 'Largest request a zone lead may approve alone, in dollars.'),
		'ONBOARDING_PURCHASE_RECEIPT_DAYS' => array('Receipt within (days)', 'text', 'A reimbursement must be filed within this many days of buying. Keeps reimbursements tax free for the member under the IRS accountable plan rules.'),
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
		'ONBOARDING_WAIVER_URL' => array('Liability waiver from', 'text', 'The raw file in GitHub, e.g. https://raw.githubusercontent.com/ColumbiaGadgetWorks/website/main/content/legal/waiver.md . Read nightly and by "Check GitHub now"; each new wording is kept as a new version. Put your current wording in that file BEFORE setting this, or the file\'s wording becomes the one people sign.'),
		'ONBOARDING_AGREEMENT_URL' => array('Membership agreement from', 'text', 'e.g. https://raw.githubusercontent.com/ColumbiaGadgetWorks/website/main/content/legal/agreement.md'),
		'ONBOARDING_WAIVER_TEXT' => array('Liability waiver (no GitHub)', 'area', 'Used only when the address above is empty. Saving new wording keeps it as a new version; existing signatures keep the text they signed.'),
		'ONBOARDING_AGREEMENT_TEXT' => array('Membership agreement (no GitHub)', 'area', 'Used only when the address above is empty.'),
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
	// Wording typed here (no GitHub address) becomes a version like a GitHub one.
	foreach (OnboardingService::DOCS as $doc => $title) {
		$text = getDolGlobalString('ONBOARDING_'.strtoupper($doc).'_TEXT');
		if (getDolGlobalString('ONBOARDING_'.strtoupper($doc).'_URL') === '' && $text !== '') {
			$svc->addDocVersion($doc, $title, $text, 'setup page');
		}
	}
	if (!$errors) {
		setEventMessages('Saved', null);
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if (in_array($action, array('runsync', 'rundaily', 'connect', 'roletags', 'fetchdocs', 'runbookings'), true)) {
	if ($action == 'fetchdocs') {
		$out = $svc->fetchDocs();
	} elseif ($action == 'runbookings') {
		dol_include_once('/onboarding/class/booking.class.php');
		$out = (new OnboardingBooking($db, $svc))->run();
	} elseif ($action == 'connect') {
		$out = $svc->connectGivebutter();
	} elseif ($action == 'roletags') {
		dol_include_once('/onboarding/class/purchase.class.php');
		$out = (new OnboardingPurchase($db, $svc))->installRoleTags();
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

dol_include_once('/onboarding/class/purchase.class.php');
$purchase = new OnboardingPurchase($db, $svc);
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="3">Purchase request roles</td></tr>';
print '<tr class="oddeven"><td colspan="3">Give someone a role by tagging their member card: open the member, go to the Tags/Categories tab and tick the role. The role follows the tag, so its email and member code come from that card, and moving the tag when the job changes hands moves everything. Zones come from the Zones setting below; a zone with no boss sends its requests to the board. ';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=roletags&token='.newToken().'">Create the role tags</a></td></tr>';
print '<tr class="liste_titre"><td>Tag</td><td>What it does</td><td>Held by now</td></tr>';
foreach ($purchase->roleTable() as $r) {
	$who = array();
	foreach ($r['holders'] as $h) {
		$who[] = dol_escape_htmltag($h['name']).' <span class="opacitymedium">('.dol_escape_htmltag($h['email']).')</span>'.($h['code'] ? '' : ' <span class="warning">no member code, cannot file</span>');
	}
	print '<tr class="oddeven"><td><code>'.dol_escape_htmltag($r['role']).'</code></td><td>'.dol_escape_htmltag($r['what']).'</td><td>'.($who ? implode('<br>', $who) : '<span class="opacitymedium">nobody</span>').'</td></tr>';
}
print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4">Waiver and agreement versions <a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=fetchdocs&token='.newToken().'">Check GitHub now</a></td></tr>';
print '<tr class="oddeven"><td colspan="4">People sign the newest version below. Each signature records its version, and the words of every version are kept here, so a change in GitHub never changes what someone already signed. Before booking a training, someone who signed an older version is not asked again.</td></tr>';
print '<tr class="liste_titre"><td>Document</td><td>Version</td><td>Since</td><td>From</td></tr>';
foreach (OnboardingService::DOCS as $doc => $title) {
	$versions = $svc->docVersions($doc);
	if (!$versions) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($title).'</td><td><code>'.dol_escape_htmltag($docs[$doc]['version']).'</code></td><td></td><td>'.dol_escape_htmltag($docs[$doc]['source']).'</td></tr>';
	}
	foreach ($versions as $i => $v) {
		print '<tr class="oddeven"><td>'.($i ? '' : dol_escape_htmltag($title)).'</td><td><code>'.dol_escape_htmltag($v->version).'</code>'.($i ? '' : ' <span class="badge badge-status4">signed now</span>').'</td><td>'.dol_print_date($db->jdate($v->datec), 'dayhour').'</td><td class="small">'.dol_escape_htmltag($v->source).'</td></tr>';
	}
}
print '</table>';

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

print '<br><div class="opacitymedium">The scheduled jobs run on their own once Dolibarr\'s scheduled jobs are set up. To run them now: ';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=runsync&token='.newToken().'">Sync Givebutter now</a> ';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=runbookings&token='.newToken().'">Run training bookings now</a> ';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=rundaily&token='.newToken().'">Run reminders now</a></div>';

llxFooter();
$db->close();
