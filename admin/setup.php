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
dol_include_once('/onboarding/class/home.class.php');

if (!$user->admin) {
	accessforbidden();
}

$svc = new OnboardingService($db);
$docs = $svc->docs();

/**
 * @return string The page to go back to after an update: a path on this site, or ''
 */
function onboarding_back()
{
	$back = (string) GETPOST('backtopage', 'alphanohtml');
	return preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $back) ? $back : '';
}

// name => array(label, kind, help). Kinds: text, secret, area.
$sections = array(
	'Website connection' => array(
		'ONBOARDING_API_KEY' => array('Key for the website', 'text', 'The website Worker sends this as its DOLIBARR_API_KEY secret. Changing it disconnects the website until the secret is updated.'),
		'ONBOARDING_JOIN_URL' => array('Join page address', 'text', 'For example https://columbiagadgetworks.org/membership/join/ . Used for the "pick up where you left off" email.'),
		'ONBOARDING_STAFF_EMAIL' => array('Membership team email', 'text', 'Told when someone becomes a paying member (needs a badge) or stops paying (badge off).'),
		'ONBOARDING_MAIL_FROM' => array('Send emails from', 'text', 'Leave empty to use Dolibarr\'s default sender.'),
		'ONBOARDING_MAIL_FROM_NAME' => array('Sender name', 'text', 'Shown as who the email is from, e.g. Columbia Gadget Works. Empty: the company name in Dolibarr (Setup, Company).'),
		'ONBOARDING_UPDATES_TAG' => array('Email updates tag', 'text', 'Contacts who ask for email updates on the website get this tag. To send an update, create an emailing in Tools, EMailing and add recipients from Contacts filtered by this tag. If you rename it here, rename the tag in Tags/Categories too.'),
	),
	'Givebutter' => array(
		'ONBOARDING_GB_API_KEY' => array('Givebutter API key', 'secret', 'Settings, Integrations, API Keys in Givebutter. Used for the hourly sync and for the Connect button below.'),
		'ONBOARDING_PUBLIC_URL' => array('This Dolibarr\'s public address', 'text', 'The address the website and Givebutter reach this Dolibarr at, for example https://records.example.org (a tunnel hostname). Leave empty if Dolibarr\'s own address setting is already the public one.'),
		'ONBOARDING_GB_CAMPAIGN_CODE' => array('Membership campaign code', 'text', 'Only payments to this campaign count as dues. It is the code at the end of the campaign link, for example kxk2FA. Leave empty to count every payment.'),
		'ONBOARDING_CHECKOUT_URL' => array('Dues payment page', 'text', 'Where the signup sends people to pay, for example https://givebutter.com/kxk2FA .'),
		'ONBOARDING_GB_API_BASE' => array('Givebutter API address', 'text', 'Only change this to point at the sandbox.'),
		'ONBOARDING_DUES_STANDARD' => array('Standard monthly dues', 'text', ''),
		'ONBOARDING_DUES_SUPPORTER' => array('Supporter monthly dues', 'text', 'A payment of at least this much makes the member a Supporter.'),
	),
	'Email approval' => array(
		'ONBOARDING_MAILGATE_APPROVER' => array('Approver', 'text', 'When set, every email Dolibarr sends (this module\'s, password resets, mass emailings) waits until this address approves it. They get a copy of each with Approve, "Approve, and always allow this kind" and Reject buttons. Empty: emails go out straight away. Waiting emails: Members, Onboarding, Email approval.'),
		'ONBOARDING_MAILGATE_EXEMPT' => array('Never held for', 'text', 'Comma separated addresses whose emails go out without approval. The approver is always on it.'),
	),
	'PayPal sync' => array(
		'ONBOARDING_PAYPAL_CLIENT_ID' => array('PayPal Client ID', 'text', 'From a Live app at developer.paypal.com (Apps & Credentials) with Transaction search ticked. With it and the secret set, the hourly sync records PayPal dues on member cards and PayPal members are judged by PayPal\'s own data.'),
		'ONBOARDING_PAYPAL_SECRET' => array('PayPal Secret', 'secret', 'The same app\'s Secret.'),
		'ONBOARDING_PAYPAL_MONTHLY' => array('PayPal monthly dues', 'text', 'Default 40. A payment of three times this pays three months, and so on.'),
		'ONBOARDING_PAYPAL_DUES_CODES' => array('PayPal transaction codes that are dues', 'text', 'Default T0002 (subscription payments). Other PayPal payments are not dues and are left alone.'),
		'ONBOARDING_PAYPAL_API_BASE' => array('PayPal API address', 'text', 'Only change this to point at the sandbox.'),
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
		'ONBOARDING_LINKS_VIA_WEBSITE' => array('Email buttons on the website (1 or 0)', 'text', 'Default 1: the buttons in training emails open <website>/training/link/, which shows the page inside the website. 0: they open this Dolibarr directly. Links sent either way keep working.'),
		'ONBOARDING_ALERT_EMAIL' => array('Alerts go to', 'text', 'Problems between Dolibarr, the website and Givebutter, including the website not reaching Dolibarr. Empty: whoever holds the secretary tag.'),
		'ONBOARDING_TRAINING_CHECKIN_ALERT_MINUTES' => array('Alert when the website is silent for (minutes)', 'text', 'The website fetches the catalog every 15 minutes. Default 30.'),
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
	'Member votes' => array(
		'ONBOARDING_VOTE_TYPES' => array('Full member types', 'text', 'Member type ids whose members vote and may run for the board, comma separated. Empty: Standard and Supporter. Hardship and Legacy members do not vote.'),
		'ONBOARDING_VOTE_SEATS' => array('Board seats', 'text', 'In ballot order. Default President,Vice President,Secretary,Treasurer.'),
		'ONBOARDING_VOTE_MEETING_TIME' => array('Annual meeting starts at', 'text', 'The meeting is the second Thursday of April. Default 18:00, on the shop\'s clock.'),
		'ONBOARDING_VOTE_PLACE' => array('Meeting place', 'text', 'Default 1404 Grand Ave, Unit B, Columbia.'),
		'ONBOARDING_VOTE_DAYS' => array('Voting stays open (days)', 'text', 'Default 8 (bylaws 4.5).'),
		'ONBOARDING_VOTE_REPO' => array('Governance repository', 'text', 'owner/repo on GitHub. Results go on the vote\'s pull request, or as an issue here when it has none. Default ColumbiaGadgetWorks/bylaws.'),
		'ONBOARDING_VOTE_GITHUB_TOKEN' => array('GitHub token', 'secret', 'A fine-grained token for the repository above with Contents, Issues and Pull requests set to Read and write. Tags the final text when voting opens and posts the result. You are warned a month before it expires.'),
		'ONBOARDING_GITHUB_API' => array('GitHub API address', 'text', 'Only change this to point at the sandbox.'),
	),
	'Purchase requests' => array(
		'ONBOARDING_PURCHASE_LEAD_LIMIT' => array('Zone lead limit', 'text', 'Largest request a zone lead may approve alone, in dollars.'),
		'ONBOARDING_PURCHASE_RECEIPT_DAYS' => array('Receipt within (days)', 'text', 'A reimbursement must be filed within this many days of buying. Keeps reimbursements tax free for the member under the IRS accountable plan rules.'),
	),
	'Yearly filings' => array(
		'ONBOARDING_FILING_AUTOSTART' => array('Open each year by itself (1 or 0)', 'text', 'Default 1: the daily job opens the year and emails whoever holds the treasurer tag (else the president). 0: only the Start button on CGW, Yearly filings opens it. The fiscal year comes from Setup, Company (fiscal year start month).'),
		'ONBOARDING_FILING_START_DAYS' => array('Open it this many days after the year ends', 'text', 'Default 15, so the last month\'s statements are out.'),
		'ONBOARDING_FILING_REMIND_DAYS' => array('Remind the treasurer every (days)', 'text', 'Default 7, until the statements are in, then until both filings are marked done.'),
		'ONBOARDING_FILING_ESCALATE' => array('Tell the board (days before due)', 'text', 'Default 30,7,0: the rest of the board (president, vice president, secretary, directors) is emailed this many days before the IRS due date while it is not filed, then the same before the state due date.'),
		'ONBOARDING_FILING_ACCOUNTS' => array('Money accounts', 'text', 'Bank accounts (ref or label, comma separated) whose monthly statements are asked for. Empty: every open bank account in Dolibarr.'),
		'ONBOARDING_FILING_YEARLY' => array('Yearly reports to ask for', 'area', 'One per line, "Name: what to upload". A line whose name is part of a money account\'s name is skipped (that account\'s statements are already asked for). Empty: Givebutter report, 1099-K, Venmo, cash box.'),
		'ONBOARDING_FILING_EIN' => array('EIN', 'text', 'Empty: Setup, Company, professional ID 1.'),
		'ONBOARDING_FILING_LEGAL_NAME' => array('Legal name', 'text', 'Empty: the company name in Setup, Company.'),
		'ONBOARDING_FILING_ADDRESS' => array('Mailing address', 'text', 'Empty: the address in Setup, Company.'),
		'ONBOARDING_FILING_OFFICER' => array('Principal officer', 'text', 'Empty: whoever holds the president tag.'),
		'ONBOARDING_FILING_STATE' => array('State', 'text', 'Default Missouri.'),
		'ONBOARDING_FILING_STATE_ID' => array('State charter number', 'text', 'Empty: Setup, Company, professional ID 2.'),
		'ONBOARDING_FILING_STATE_DUE' => array('State report due (month-day)', 'text', 'Default 10-27, the incorporation anniversary.'),
		'ONBOARDING_FILING_STATE_URL' => array('Where the state report is filed', 'text', 'Default https://bsd.sos.mo.gov/ (Missouri Secretary of State, Business Services).'),
	),
	'Reconciling' => array(
		'ONBOARDING_RECONCILE_DAYS' => array('Days apart a statement line and its entry may be', 'text', 'Default 5. Reconcile bank accounts matches a statement line to the Dolibarr entry with the same amount at most this many days away (banks post a day or two after PayPal or Givebutter send).'),
		'ONBOARDING_RECONCILE_RULES' => array('Rules for lines nobody entered', 'area', 'A statement line that matches no entry and fits a rule is entered by itself (on the accounting account given, or as a transfer between our accounts), reconciled, and listed under "Entered by the rules" with an Undo. The comments at the top explain the format. Empty: the built-in rules.'),
	),
	'Reminders' => array(
		'ONBOARDING_REMINDER_DAYS' => array('Send reminders after (days)', 'text', 'Comma separated days after a payment is cancelled or missed, for example 3,7,14.'),
		'ONBOARDING_GRACE_DAYS' => array('Mark non-paying after (days)', 'text', 'Counted from the same moment. A member who has already paid for a period keeps it until it runs out.'),
		'ONBOARDING_MAIL_REMINDER_SUBJECT' => array('Reminder subject', 'text', 'Leave any email field empty to use the built-in wording.'),
		'ONBOARDING_MAIL_REMINDER_BODY' => array('Reminder text', 'area', 'Placeholders: {firstname} {lastname} {org} {payment_url} {days_left}'),
		'ONBOARDING_MAIL_REMINDER_PAYPAL_BODY' => array('Reminder text for PayPal members', 'area', 'Sent instead of the text above to members who pay by PayPal. Placeholders: {firstname} {lastname} {org} {payment_url} {days_left} {paid_until}'),
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
	'CGW home page' => array(
		'ONBOARDING_HOME_YAML' => array('Layout', 'area', 'What the CGW home page (top menu CGW) shows, in YAML. The comments at the top of the text explain every option. Reset layout (at the top of this page) goes back to the recommended one.'),
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
	// The home page layout: the default text saved unchanged is kept as "default",
	// so later improvements to the default reach this Dolibarr.
	$layout = getDolGlobalString('ONBOARDING_HOME_YAML');
	$default = (string) @file_get_contents(dirname(__DIR__).'/home.default.yaml');
	if (trim(str_replace("\r", '', $layout)) === trim($default)) {
		dolibarr_set_const($db, 'ONBOARDING_HOME_YAML', '', 'chaine', 0, '', $conf->entity);
	} elseif (trim($layout) !== '') {
		list($sections, $problems) = OnboardingHome::read($layout);
		if ($problems) {
			setEventMessages('The CGW home page layout was saved, but has problems: '.implode(' ', $problems), null, 'warnings');
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
	$section = (int) GETPOST('section', 'int');
	header('Location: '.$_SERVER['PHP_SELF'].($section > 0 ? '#section-'.$section : ''));
	exit;
}
if ($action == 'selfupdate' || $action == 'checkupdate') {
	dol_include_once('/onboarding/class/selfupdate.class.php');
	$su = new OnboardingSelfUpdate($db);
	if ($action == 'checkupdate') {
		$latest = $su->latest(true);
		setEventMessages($latest !== '' ? 'GitHub has version '.$latest.'; this Dolibarr has '.OnboardingSelfUpdate::installed().'.' : 'Could not reach GitHub.', null, $latest !== '' ? 'mesgs' : 'warnings');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	$r = $su->update();
	setEventMessages($r['message'], null, $r['ok'] ? 'mesgs' : 'errors');
	// The new code is only loaded by the next request: turn the module off and
	// on there, so it sets itself up with its own installer.
	$back = onboarding_back();
	if ($r['ok']) {
		header('Location: '.$_SERVER['PHP_SELF'].'?action=reenable&token='.newToken().($back !== '' ? '&backtopage='.urlencode($back) : ''));
	} else {
		header('Location: '.($back !== '' ? $back : $_SERVER['PHP_SELF']));
	}
	exit;
}
if ($action == 'reenable') {
	dol_include_once('/onboarding/class/selfupdate.class.php');
	$err = (new OnboardingSelfUpdate($db))->reenable();
	setEventMessages($err === '' ? 'Member onboarding was turned off and on again, so version '.OnboardingSelfUpdate::installed().' is set up. Your settings are kept.' : $err, null, $err === '' ? 'mesgs' : 'errors');
	// Back where the Update button was pressed (the CGW home page), else here.
	$back = onboarding_back();
	header('Location: '.($back !== '' ? $back : $_SERVER['PHP_SELF']));
	exit;
}
if (in_array($action, array('homeeveryone', 'homeoff', 'homereset'), true)) {
	if ($action == 'homeeveryone') {
		// Everyone lands on it after logging in, people who picked their own
		// landing page (user card, Display) included.
		dolibarr_set_const($db, 'MAIN_LANDING_PAGE', OnboardingHome::LANDING, 'chaine', 0, 'Set by Member onboarding: the CGW home page', $conf->entity);
		$db->query("DELETE FROM ".$db->prefix()."user_param WHERE param = 'MAIN_LANDING_PAGE' AND entity = ".((int) $conf->entity));
		setEventMessages('The CGW home page is now everyone\'s landing page. People see it the next time they log in.', null);
	} elseif ($action == 'homeoff') {
		if (getDolGlobalString('MAIN_LANDING_PAGE') === OnboardingHome::LANDING) {
			dolibarr_del_const($db, 'MAIN_LANDING_PAGE', $conf->entity);
		}
		setEventMessages('Logging in opens Dolibarr\'s own home page again. The CGW home page stays in the top menu.', null);
	} else {
		dolibarr_del_const($db, 'ONBOARDING_HOME_YAML', $conf->entity);
		setEventMessages('The CGW home page layout is back to the recommended one.', null);
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if (in_array($action, array('runsync', 'rundaily', 'connect', 'roletags', 'fetchdocs', 'runbookings', 'runpaypal'), true)) {
	if ($action == 'fetchdocs') {
		$out = $svc->fetchDocs();
	} elseif ($action == 'runpaypal') {
		dol_include_once('/onboarding/class/paypal.class.php');
		$out = (new OnboardingPaypal($db, $svc))->sync();
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

// Run now: the same jobs Dolibarr's scheduler runs on its own.
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Run now</td></tr>';
print '<tr class="oddeven"><td><span class="opacitymedium">These run on their own while Dolibarr\'s scheduled jobs are running (Givebutter and PayPal hourly, training bookings every 15 minutes, reminders daily). To run one now:</span><br>';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=runsync&token='.newToken().'">Sync Givebutter now</a> ';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=runpaypal&token='.newToken().'">Sync PayPal now</a> ';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=runbookings&token='.newToken().'">Run training bookings now</a> ';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=rundaily&token='.newToken().'">Run reminders now</a>';
print '</td></tr></table><br>';

// Module updates from GitHub.
dol_include_once('/onboarding/class/selfupdate.class.php');
$su = new OnboardingSelfUpdate($db);
$installed = OnboardingSelfUpdate::installed();
$latest = $su->latest();
$cannot = OnboardingSelfUpdate::cannotWrite();
print '<table class="noborder centpercent">';
print '<tr class="liste_titre" id="module-updates"><td>Module updates</td></tr>';
print '<tr class="oddeven"><td><span class="opacitymedium">This Dolibarr has version <strong>'.dol_escape_htmltag($installed).'</strong>. ';
if ($latest === '') {
	print 'GitHub could not be reached to check for a newer one.';
} elseif (version_compare($latest, $installed, '>')) {
	print '<strong>Version '.dol_escape_htmltag($latest).' is on GitHub.</strong>';
} else {
	print 'That is the newest on GitHub.';
}
print ' Update downloads the newest version from GitHub, keeps a backup of this one, puts it in place and turns the module off and on (settings are kept).</span><br>';
if ($cannot !== '') {
	print '<span class="warning">'.dol_escape_htmltag($cannot).'</span><br>';
} else {
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=selfupdate&token='.newToken().'" onclick="return confirm(\'Update Member onboarding from GitHub and turn it off and on?\')">Update from GitHub'.($latest !== '' && version_compare($latest, $installed, '>') ? ' to '.dol_escape_htmltag($latest) : '').'</a> ';
}
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=checkupdate&token='.newToken().'">Check again</a>';
print '</td></tr></table><br>';

// CGW home page: the landing page, and the layout.
$landing = getDolGlobalString('MAIN_LANDING_PAGE') === OnboardingHome::LANDING;
$custom = trim(getDolGlobalString('ONBOARDING_HOME_YAML')) !== '';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>CGW home page</td></tr>';
print '<tr class="oddeven"><td><span class="opacitymedium">'.($landing ? 'Everyone lands on the CGW home page after logging in.' : 'Logging in opens Dolibarr\'s own home page; the CGW home page is in the top menu (CGW).').' The layout is '.($custom ? 'your own (CGW home page, below).' : 'the recommended one.').'</span><br>';
print '<a class="butAction" href="'.dol_buildpath('/onboarding/home.php', 1).'?mainmenu=cgw">Open the CGW home page</a> ';
if ($landing) {
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=homeoff&token='.newToken().'">Stop opening it after login</a> ';
} else {
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=homeeveryone&token='.newToken().'">Make it everyone\'s home page</a> ';
}
if ($custom) {
	print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?action=homereset&token='.newToken().'" onclick="return confirm(\'Replace your layout with the recommended one?\')">Reset layout to recommended</a>';
}
print '</td></tr></table><br>';

$endpoint = OnboardingService::publicUrl('api.php');
print '<div class="info">The website talks to Dolibarr at <strong>'.dol_escape_htmltag($endpoint).'</strong>. In the website Worker, set the secret <code>DOLIBARR_URL</code> to <strong>'.dol_escape_htmltag(preg_replace('/\/custom\/onboarding\/public\/api\.php$/', '', $endpoint)).'</strong> and <code>DOLIBARR_API_KEY</code> to the key below.</div>';

$connected = getDolGlobalString('ONBOARDING_GB_WEBHOOK_ID') !== '';
print '<div class="'.($connected ? 'ok' : 'warning').'">';
print $connected ? 'Givebutter is connected: it reports payments and cancelled plans to <strong>'.dol_escape_htmltag($svc->webhookUrl()).'</strong>. ' : 'Givebutter is not connected yet, so payments are only noticed by the hourly sync. Save the API key below, then press Connect. ';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=connect&token='.newToken().'">'.($connected ? 'Reconnect' : 'Connect Givebutter').'</a>';
if ($connected) {
	list($when, $what) = OnboardingService::webhookNote('ONBOARDING_GB_WEBHOOK_LAST');
	list($rwhen, $rwhat) = OnboardingService::webhookNote('ONBOARDING_GB_WEBHOOK_REFUSED');
	print '<br><span class="small">Last webhook received: <strong>'.($when ? dol_print_date($when, 'dayhour').'</strong> ('.dol_escape_htmltag($what).')' : 'none yet</strong>').'.';
	if ($rwhen) {
		print ' Last refused: <strong>'.dol_print_date($rwhen, 'dayhour').'</strong> ('.dol_escape_htmltag($rwhat).').';
	}
	if (getDolGlobalInt('ONBOARDING_GB_MISSED') > 0) {
		print ' <span class="warning">'.getDolGlobalInt('ONBOARDING_GB_MISSED').' payment(s) since then were found by asking Givebutter instead.</span>';
	}
	if (preg_match('#^https?://(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|[^/]*\.local\b|[^/.:]+(:\d+)?/)#i', $svc->webhookUrl())) {
		print ' <span class="warning">That address looks internal, so Givebutter cannot reach it: set "This Dolibarr\'s public address" below and press Reconnect.</span>';
	}
	print ' If payments arrive late, check the delivery log in Givebutter (Settings, Developers, Webhooks).</span>';
}
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
$n = 0;
foreach ($sections as $title => $fields) {
	$n++;
	// Every section has its own Save; each saves the whole page and comes back here.
	print '<br><table class="noborder centpercent" id="section-'.$n.'">';
	print '<tr class="liste_titre"><td>'.dol_escape_htmltag($title).'</td><td class="right"><button type="submit" name="section" value="'.$n.'" class="button button-save smallpaddingimp">'.$langs->trans('Save').'</button></td></tr>';
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
			if ($name == 'ONBOARDING_RECONCILE_RULES' && $value === '') {
				dol_include_once('/onboarding/class/reconcile.class.php');
				$shown = OnboardingReconcile::DEFAULT_RULES;
			}
			if ($name == 'ONBOARDING_FILING_YEARLY' && $value === '') {
				dol_include_once('/onboarding/class/filing.class.php');
				$shown = OnboardingFiling::DEFAULT_YEARLY;
			}
			if ($name == 'ONBOARDING_HOME_YAML') {
				$shown = OnboardingHome::layoutText();
				print '<a id="home-layout"></a>';
			}
			print '<textarea name="'.$name.'" rows="'.(strpos($name, '_TEXT') ? 14 : ($name == 'ONBOARDING_HOME_YAML' ? 30 : ($name == 'ONBOARDING_RECONCILE_RULES' ? 20 : 6))).'" class="quatrevingtpercent"'.(in_array($name, array('ONBOARDING_HOME_YAML', 'ONBOARDING_RECONCILE_RULES'), true) ? ' style="font-family:monospace" spellcheck="false"' : '').'>'.dol_escape_htmltag($shown).'</textarea>';
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


llxFooter();
$db->close();
