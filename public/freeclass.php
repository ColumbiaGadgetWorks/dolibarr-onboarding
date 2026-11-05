<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The "I can't come" link in free class emails. No login: each link is signed
 * for one sign-up (see OnboardingFreeClass::link).
 *
 *   GET  .../custom/onboarding/public/freeclass.php?s=SIGNUP&k=SIGNATURE
 *   POST the same, with do=cancel
 *
 * Opening the link only shows the class and a button (mail scanners open links on
 * their own); the seat is given up only when the button is pressed. Emails link to
 * the website's /classes/link/, which passes the request here with the website key
 * and shows the answer inside the website.
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

dol_include_once('/onboarding/class/freeclass.class.php');

/**
 * @param string $title Heading
 * @param string $html Body, already escaped
 * @param int $code HTTP status
 * @param int $refresh Reload the page after this many seconds, 0 for never
 * @return never
 */
function onboarding_freeclass_page($title, $html, $code = 200, $refresh = 0)
{
	global $db;
	if ($refresh > 0) {
		$html = '<meta http-equiv="refresh" content="'.((int) $refresh).'">'.$html;
	}
	// Shown inside the website: it wraps the body in its own page.
	$key = getDolGlobalString('ONBOARDING_API_KEY');
	$given = isset($_SERVER['HTTP_X_ONBOARDING_KEY']) ? (string) $_SERVER['HTTP_X_ONBOARDING_KEY'] : '';
	if ($key !== '' && hash_equals($key, $given)) {
		http_response_code(200);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		echo json_encode(array('ok' => true, 'status' => $code, 'title' => $title, 'html' => $html));
		$db->close();
		exit;
	}
	http_response_code($code);
	header('Content-Type: text/html; charset=utf-8');
	header('X-Robots-Tag: noindex');
	header('Referrer-Policy: no-referrer');
	header('Cache-Control: no-store');
	$org = dol_escape_htmltag(getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'Dolibarr'));
	print '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
	print '<meta name="robots" content="noindex"><title>'.dol_escape_htmltag($title).' - '.$org.'</title>';
	print '<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:2.5rem auto;padding:0 1rem;line-height:1.5;color:#222}';
	print 'h1{font-size:1.4rem}h2{font-size:1.1rem;margin-top:1.8rem}.meta{color:#555}.facts{background:#f4f6f5;border-radius:8px;padding:.8rem 1rem}';
	print 'button{font-size:1rem;padding:.6rem 1.2rem;border:0;border-radius:6px;background:#2d6a4f;color:#fff;cursor:pointer;margin:.3rem 0}';
	print 'button.alt{background:#fff;color:#2d6a4f;border:2px solid #2d6a4f}button.warn{background:#9b2c2c}input[type=text],input[type=email],input[type=url],select,textarea{font:inherit;padding:.4rem;max-width:100%;box-sizing:border-box}';
	print 'textarea{width:100%;min-height:4rem}label{display:block;margin:.4rem 0}fieldset{border:1px solid #ccd;border-radius:8px;margin:1rem 0;padding:.6rem 1rem}legend{font-weight:600}';
	print 'form{margin:.6rem 0 1.2rem}.ok{color:#2d6a4f;font-weight:600}.err{color:#9b2c2c;font-weight:600}pre{white-space:pre-wrap;background:#f4f6f5;padding:.8rem;border-radius:8px}</style></head><body>';
	print '<p class="meta">'.$org.'</p><h1>'.dol_escape_htmltag($title).'</h1>'.$html.'</body></html>';
	$db->close();
	exit;
}

if (!isModEnabled('onboarding')) {
	onboarding_freeclass_page('Not available', '<p>Class sign-ups are switched off.</p>', 503);
}

$fc = new OnboardingFreeClass($db);
$sid = (int) GETPOST('s', 'int');
$key = (string) GETPOST('k', 'alphanohtml');
$s = $fc->signupRow($sid);
if (!$s || !hash_equals($fc->key($s), $key)) {
	onboarding_freeclass_page('Link not valid', '<p>This link is not valid. Open it from your class email, or <a href="'.dol_escape_htmltag(OnboardingFreeClass::pageUrl()).'">see the classes</a>.</p>', 403);
}
$c = $fc->fetch((int) $s->fk_freeclass);
$facts = '<div class="facts"><strong>'.dol_escape_htmltag($c->title).'</strong><br>'.dol_escape_htmltag($fc->when($c)).'<br>'.dol_escape_htmltag(getDolGlobalString('ONBOARDING_CLASS_LOCATION', OnboardingFreeClass::LOCATION)).'</div>';
$more = '<p><a href="'.dol_escape_htmltag(OnboardingFreeClass::pageUrl()).'">See the other free classes</a></p>';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && GETPOST('do', 'aZ09') == 'cancel') {
	$r = $fc->cancelSignup($sid);
	if ($r == 'done') {
		onboarding_freeclass_page('Seat given up', $facts.'<p class="ok">Done. Your seat is free for someone else. Thank you for telling us.</p>'.$more);
	}
	if ($r == 'already') {
		onboarding_freeclass_page('Seat given up', $facts.'<p>You had already given up this seat. Nothing changed.</p>'.$more);
	}
	onboarding_freeclass_page('Class closed', $facts.'<p>This class is over or cancelled, so there is nothing to change.</p>'.$more);
}

if ($c->status == 'cancelled') {
	onboarding_freeclass_page('Class cancelled', $facts.'<p>This class is cancelled.</p>'.$more);
}
if ($c->status != 'open' || $c->start <= OnboardingService::now()) {
	onboarding_freeclass_page('Class over', $facts.'<p>This class has already happened.</p>'.$more);
}
if ($s->status != 'signed') {
	onboarding_freeclass_page('Seat given up', $facts.'<p>You gave up this seat. To come after all, <a href="'.dol_escape_htmltag(OnboardingFreeClass::pageUrl()).'#class-'.((int) $c->rowid).'">sign up again</a> while there is room.</p>');
}
$form = '<form method="post" action=""><input type="hidden" name="s" value="'.((int) $sid).'"><input type="hidden" name="k" value="'.dol_escape_htmltag($key).'"><input type="hidden" name="do" value="cancel"><button type="submit" class="warn">I can\'t come: give up my seat</button></form>';
onboarding_freeclass_page('Your seat', $facts.'<p>You have a seat'.(trim($s->firstname) !== '' ? ', '.dol_escape_htmltag($s->firstname) : '').'. If you cannot come, press the button so someone else can have it.</p>'.$form.$more);
