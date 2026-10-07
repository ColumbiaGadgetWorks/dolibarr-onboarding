<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Givebutter calls this directly when a payment succeeds or a recurring plan
 * changes. The webhook is created by the "Connect Givebutter" button on the
 * setup page, which also stores the signing secret checked here.
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

dol_include_once('/onboarding/class/onboarding.class.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$secret = getDolGlobalString('ONBOARDING_GB_WEBHOOK_SECRET');
if (!isModEnabled('onboarding') || $secret === '') {
	http_response_code(503);
	echo json_encode(array('ok' => false, 'error' => 'not connected'));
	exit;
}

$raw = (string) file_get_contents('php://input');
$given = isset($_SERVER['HTTP_SIGNATURE']) ? (string) $_SERVER['HTTP_SIGNATURE'] : '';
// Givebutter sends the signing secret itself; an HMAC of the body is accepted
// too in case they move to signing properly.
if (!hash_equals($secret, $given) && !hash_equals(hash_hmac('sha256', $raw, $secret), strtolower($given))) {
	http_response_code(401);
	echo json_encode(array('ok' => false, 'error' => 'bad signature'));
	exit;
}

$in = json_decode($raw, true);
$svc = new OnboardingService($db);
$user = $svc->actor();
$event = is_array($in) && isset($in['event']) ? (string) $in['event'] : '';
$result = $svc->handleEvent($event, is_array($in) && isset($in['data']) ? $in['data'] : null);
dol_syslog('Onboarding: Givebutter '.$event.' => '.$result);
echo json_encode(array('ok' => true, 'result' => $result));
