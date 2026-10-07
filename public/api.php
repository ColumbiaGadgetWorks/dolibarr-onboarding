<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The one endpoint the website Worker talks to. It is not Dolibarr's REST API:
 * the key it accepts can do nothing except drive a signup and deliver Givebutter
 * events, so a leaked key cannot read the member list.
 *
 *   POST .../custom/onboarding/public/api.php?action=<name>
 *   Header  X-Onboarding-Key: <key from the module setup page>
 *   Body    JSON
 *
 * Actions: docs, start, subscribe, status, sign, id, givebutter
 * (Givebutter itself calls public/givebutter.php, not this file.)
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

/**
 * @param array<string,mixed> $data Response
 * @return never
 */
function onboarding_reply($data)
{
	$code = 200;
	if (isset($data['http'])) {
		$code = (int) $data['http'];
		unset($data['http']);
	}
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode($data);
	exit;
}

if (!isModEnabled('onboarding')) {
	onboarding_reply(array('ok' => false, 'error' => 'disabled', 'http' => 503));
}

$expected = getDolGlobalString('ONBOARDING_API_KEY');
$given = isset($_SERVER['HTTP_X_ONBOARDING_KEY']) ? (string) $_SERVER['HTTP_X_ONBOARDING_KEY'] : '';
if ($expected === '' || !hash_equals($expected, $given)) {
	onboarding_reply(array('ok' => false, 'error' => 'unauthorized', 'http' => 401));
}

$action = isset($_GET['action']) ? preg_replace('/[^a-z]/', '', (string) $_GET['action']) : '';
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
	$in = array();
}

$svc = new OnboardingService($db);
$user = $svc->actor();

if ($action == 'docs') {
	onboarding_reply(array('ok' => true, 'docs' => $svc->docs()));
}

if ($action == 'start') {
	onboarding_reply($svc->start($in));
}

if ($action == 'subscribe') {
	onboarding_reply($svc->subscribe($in, true));
}

if ($action == 'givebutter') {
	$event = isset($in['event']) ? (string) $in['event'] : '';
	$result = $svc->handleEvent($event, isset($in['data']) ? $in['data'] : null);
	dol_syslog('Onboarding: Givebutter '.$event.' => '.$result);
	onboarding_reply(array('ok' => true, 'result' => $result));
}

// Everything below acts on one applicant, named by their token.
$app = $svc->findByToken(isset($in['token']) ? $in['token'] : '');
if (!$app) {
	onboarding_reply(array('ok' => false, 'error' => 'token', 'http' => 404));
}

if ($action == 'status') {
	onboarding_reply(array('ok' => true) + $svc->status($app));
}

if ($action == 'sign') {
	onboarding_reply($svc->sign(
		$app,
		isset($in['doc']) ? (string) $in['doc'] : '',
		isset($in['name']) ? (string) $in['name'] : '',
		isset($in['version']) ? (string) $in['version'] : '',
		isset($in['ip']) ? (string) $in['ip'] : '',
		(string) base64_decode(isset($in['signature']) ? (string) $in['signature'] : '', true)
	));
}

if ($action == 'id') {
	$bytes = base64_decode(isset($in['data']) ? (string) $in['data'] : '', true);
	if ($bytes === false) {
		onboarding_reply(array('ok' => false, 'error' => 'type', 'http' => 400));
	}
	onboarding_reply($svc->uploadId($app, $bytes));
}

onboarding_reply(array('ok' => false, 'error' => 'unknown action', 'http' => 404));
