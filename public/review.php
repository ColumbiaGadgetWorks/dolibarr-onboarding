<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The "access review done" link in the yearly access review email. No login:
 * the link is signed for one year and one address (OnboardingSecurity::reviewLink).
 * Opening it only shows a button, because mail scanners open links on their own;
 * the review is recorded only when the button is pressed.
 *
 *   GET  .../custom/onboarding/public/review.php?y=YEAR&e=EMAIL&k=SIGNATURE
 *   POST the same, from the button
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}

$res = 0;
foreach (array('../../main.inc.php', '../../../main.inc.php', '../../../../main.inc.php') as $path) {
	if (!$res && file_exists(__DIR__.'/'.$path)) {
		$res = @include __DIR__.'/'.$path;
	}
}
if (!$res) {
	http_response_code(500);
	die('Include of main fails');
}

dol_include_once('/onboarding/class/security.class.php');

/**
 * @param string $title Heading
 * @param string $html Body, already escaped
 * @param int $code HTTP status
 * @return never
 */
function onboarding_review_page($title, $html, $code = 200)
{
	global $db;
	http_response_code($code);
	header('Content-Type: text/html; charset=utf-8');
	header('X-Robots-Tag: noindex');
	header('Referrer-Policy: no-referrer');
	$org = dol_escape_htmltag(getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'Dolibarr'));
	print '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
	print '<meta name="robots" content="noindex"><title>'.dol_escape_htmltag($title).' - '.$org.'</title>';
	print '<style>body{font-family:system-ui,sans-serif;max-width:34rem;margin:3rem auto;padding:0 1rem;line-height:1.5;color:#222}';
	print 'h1{font-size:1.4rem}.meta{color:#555}button{font-size:1.05rem;padding:.6rem 1.4rem;border:0;border-radius:6px;background:#2d6a4f;color:#fff;cursor:pointer}';
	print '.ok{color:#2d6a4f;font-weight:600}</style></head><body>';
	print '<p class="meta">'.$org.'</p><h1>'.dol_escape_htmltag($title).'</h1>'.$html.'</body></html>';
	$db->close();
	exit;
}

$year = (int) GETPOST('y', 'int');
$email = OnboardingService::cleanEmail(GETPOST('e', 'alphanohtml'));
$key = GETPOST('k', 'alphanohtml');
$sec = new OnboardingSecurity($db);

if (!isModEnabled('onboarding') || !$sec->reviewVerify($year, $email, $key)) {
	onboarding_review_page('Link not valid', '<p>This link is not valid. Use the Access review page in Dolibarr instead.</p>', 403);
}
$page = '<p class="meta"><a href="'.dol_escape_htmltag(dol_buildpath('/onboarding/security.php', 2)).'">The Access review page in Dolibarr</a></p>';
$all = $sec->reviews();
$r = isset($all[(string) $year]) ? $all[(string) $year] : array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if ($sec->reviewDone($year, $email) === 'already') {
		onboarding_review_page('Already done', '<p>The '.$year.' access review was already recorded as done. Nothing changed.</p>'.$page);
	}
	onboarding_review_page('Access review done', '<p class="ok">Thank you. The '.$year.' access review is recorded as done, by '.dol_escape_htmltag($email).', today. The reminders have stopped.</p><p>Remember to write it in the board minutes and attest it in the Plaid dashboard.</p>'.$page);
}

if (!empty($r['done'])) {
	onboarding_review_page('Already done', '<p>The '.$year.' access review was recorded as done on '.dol_escape_htmltag(dol_print_date((int) $r['done'], 'day')).($r['by'] ? ' by '.dol_escape_htmltag($r['by']) : '').'.</p>'.$page);
}
$form = '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
$form .= '<input type="hidden" name="y" value="'.((int) $year).'"><input type="hidden" name="e" value="'.dol_escape_htmltag($email).'"><input type="hidden" name="k" value="'.dol_escape_htmltag($key).'">';
$form .= '<button type="submit">Access review done</button></form>';
onboarding_review_page('Is the '.$year.' access review done?', '<p>Press the button once every step in the email is done: Dolibarr logins checked, every other account checked, old records deleted, the policy read again, and the result written in the minutes.</p>'.$form.$page);
