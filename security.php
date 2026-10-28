<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Access review: every working Dolibarr login, its member card and officer tags,
 * and whether the policy says it should go; records past their retention time.
 * For the yearly access review, and to see what the daily job will do.
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

dol_include_once('/onboarding/class/security.class.php');

if (!isModEnabled('onboarding') || empty($user->admin)) {
	accessforbidden();
}
$sec = new OnboardingSecurity($db);
$action = GETPOST('action', 'aZ09');
if ($action == 'reviewstart' || $action == 'reviewdone') {
	$year = (int) date('Y', OnboardingService::now());
	if ($action == 'reviewstart') {
		setEventMessages($sec->review(true) === 'access review: '.$year.' sent' ? 'The '.$year.' access review was emailed to the security owner.' : 'The '.$year.' access review was already sent.', null);
	} else {
		$open = $sec->openReview();
		$sec->reviewDone($open !== null ? $open : $year, trim($user->getFullName($langs).' ('.$user->login.')'));
		setEventMessages('The '.($open !== null ? $open : $year).' access review is recorded as done.', null);
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', 'Access review');
print load_fiche_titre('Access review', '', 'user');
print '<p class="opacitymedium">Everyone who can log in to Dolibarr. Under the information security and records policy, a login linked to a member card is disabled by the daily job when the membership ends, and an administrator\'s when their member card loses its officer tag: the person and the security owner are warned first, and the login goes '.OnboardingSecurity::GRACE_DAYS.' days later unless it is put right. Logins not linked to a member card are reviewed by hand. Each April, go through this list with the Secretary and disable anyone who no longer needs access (the user card, Disable).</p>';

// The yearly review: where this year's stands, and its history.
$reviews = $sec->reviews();
$thisYear = (string) date('Y', OnboardingService::now());
$open = $sec->openReview();
$months = array(1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
print '<div class="'.($open !== null ? 'warning' : 'info').'"><strong>Yearly access review.</strong> ';
if ($open !== null) {
	print 'The '.$open.' review was emailed to the security owner on '.dol_print_date((int) $reviews[(string) $open]['sent'], 'day').' and is not done yet; they are reminded every '.OnboardingSecurity::REVIEW_EVERY.' days.';
} elseif (!empty($reviews[$thisYear]['done'])) {
	print 'This year\'s review was done on '.dol_print_date((int) $reviews[$thisYear]['done'], 'day').(!empty($reviews[$thisYear]['by']) ? ' by '.dol_escape_htmltag($reviews[$thisYear]['by']) : '').'.';
} else {
	print 'The '.$thisYear.' review is emailed to the security owner on 1 '.$months[OnboardingSecurity::reviewMonth()].', with the steps and a button to press when it is done.';
}
$done = array();
foreach ($reviews as $y => $r) {
	if (!empty($r['done'])) {
		$done[] = $y.': '.dol_print_date((int) $r['done'], 'day').(!empty($r['by']) ? ', '.dol_escape_htmltag($r['by']) : '');
	}
}
if ($done) {
	print '<br><span class="opacitymedium">Done: '.implode('; ', $done).'</span>';
}
print '<br>';
if ($open !== null) {
	print '<a class="butAction smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=reviewdone&token='.newToken().'" onclick="return confirm(\'Record the '.$open.' access review as done? Only once every step in the email is done.\')">Access review done</a>';
} elseif (empty($reviews[$thisYear]['sent'])) {
	print '<a class="butAction smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=reviewstart&token='.newToken().'">Send this year\'s review now</a>';
}
print '</div>';

if (!OnboardingSecurity::enabled()) {
	print '<div class="warning">Removing logins automatically is off (setup page, Security). Logins are listed but nothing is disabled.</div>';
}

print '<table class="noborder centpercent"><tr class="liste_titre"><td>Login</td><td>Name</td><td>Administrator</td><td>Member card</td><td>Officer tags</td><td>Last login</td><td>Policy</td></tr>';
foreach ($sec->logins() as $o) {
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/user/card.php?id='.((int) $o->rowid).'">'.dol_escape_htmltag($o->login).'</a></td>';
	print '<td>'.dol_escape_htmltag($o->name).'</td>';
	print '<td>'.((int) $o->admin ? 'yes' : '').'</td>';
	print '<td>'.((int) $o->fk_member > 0 ? '<a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $o->fk_member).'">'.dol_escape_htmltag($o->member !== '' ? $o->member : '#'.$o->fk_member).'</a>'.((int) $o->member_status != 1 ? ' <span class="opacitymedium">(not a member now)</span>' : '') : '<span class="opacitymedium">none</span>').'</td>';
	print '<td>'.dol_escape_htmltag(implode(', ', $o->roles)).'</td>';
	print '<td>'.($o->datelastlogin ? dol_print_date($db->jdate($o->datelastlogin), 'day') : '<span class="opacitymedium">never</span>').'</td><td>';
	if ($o->reason !== '') {
		print '<span class="error">To be disabled'.($o->disable_on ? ' on '.dol_print_date($o->disable_on, 'day') : ' (warned at the next daily run)').'</span>: '.dol_escape_htmltag($o->reason);
	} elseif ((int) $o->fk_member <= 0) {
		print '<span class="opacitymedium">Not linked to a member card: reviewed by hand'.((int) $o->admin ? ' (link it to its officer\'s member card if it is one)' : '').'</span>';
	} else {
		print '<span class="ok">Stays</span>';
	}
	print '</td></tr>';
}
print '</table>';

print '<h3>Records past their retention time</h3><table class="noborder"><tr class="liste_titre"><td>Record</td><td class="right">How many</td></tr>';
foreach ($sec->pastRetention() as $what => $n) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($what).'</td><td class="right">'.($n ? '<span class="error">'.$n.'</span>' : '<span class="ok">0</span>').'</td></tr>';
}
print '</table>';
$years = OnboardingSecurity::bankYears();
print '<p class="opacitymedium">Bank transactions read from the banks are deleted by the daily job after '.($years ? $years.' years' : '(never: setup page, Security)').'. Former members past 7 years are deleted by hand at the yearly review (Members, the member, Delete). Security owner: '.dol_escape_htmltag(implode(', ', $sec->owners()) ?: 'nobody: set an address on the setup page, Security').'.</p>';

llxFooter();
$db->close();
