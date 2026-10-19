<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The one endpoint the website Worker talks to. It is not Dolibarr's REST API:
 * the key it accepts can do nothing except drive a signup, deliver Givebutter
 * events, say which tools one given email address is trained on, and register
 * a training before its payment, so a leaked key cannot read the member list.
 *
 *   POST .../custom/onboarding/public/api.php?action=<name>
 *   Header  X-Onboarding-Key: <key from the module setup page>
 *   Body    JSON
 *
 * Actions: docs, start, subscribe, status, sign, id, cancel, givebutter, trainings,
 * trainingstart, budget, purchase, purchasefile, purchasefinish,
 * catalog, slots, trainee, traineenew, signlink, book, bookingstatus, lookup,
 * closeddays, notifyback, websitereport, classinfo, classjoin
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

if ($action == 'trainings') {
	// What one email address is trained on: tool, zone and date only. The website
	// rate-limits this, since anyone can type any address.
	dol_include_once('/onboarding/class/training.class.php');
	$training = new OnboardingTraining($db, $svc);
	onboarding_reply(array('ok' => true, 'trainings' => $training->trainedOn(isset($in['email']) ? (string) $in['email'] : '')));
}

if (in_array($action, array('catalog', 'slots', 'trainee', 'traineenew', 'signlink', 'book', 'bookingstatus', 'lookup', 'closeddays', 'notifyback', 'websitereport', 'classinfo', 'classjoin'), true)) {
	// Training bookings (class/booking.class.php). Turnstile and per-IP limits are
	// the Worker's. Answers about a person say only what the booking page needs:
	// member or not, what is left to sign, a masked name for the lookup.
	dol_include_once('/onboarding/class/booking.class.php');
	$booking = new OnboardingBooking($db, $svc);
	if ($action == 'catalog') {
		// The Worker fetches this every 15 minutes, so from now it holds this version.
		$cat = $booking->catalog();
		$booking->websiteHas((int) $cat['version']);
		onboarding_reply(array('ok' => true, 'catalog' => $cat));
	}
	if ($action == 'slots') {
		$tool = isset($in['tool']) ? $booking->toolByRef((string) $in['tool']) : null;
		$rows = $booking->available($tool ? (int) $tool->rowid : 0);
		foreach ($rows as &$r) {
			unset($r['ts']);
		}
		unset($r);
		onboarding_reply(array('ok' => true, 'slots' => $rows));
	}
	if ($action == 'trainee') {
		onboarding_reply($booking->trainee(isset($in['email']) ? (string) $in['email'] : ''));
	}
	if ($action == 'traineenew') {
		onboarding_reply($booking->newTrainee($in));
	}
	if ($action == 'signlink') {
		onboarding_reply($booking->sendSignLink(isset($in['email']) ? (string) $in['email'] : ''));
	}
	if ($action == 'book') {
		onboarding_reply($booking->book($in));
	}
	if ($action == 'bookingstatus') {
		onboarding_reply($booking->status(isset($in['ref']) ? (string) $in['ref'] : '', isset($in['key']) ? (string) $in['key'] : ''));
	}
	if ($action == 'lookup') {
		onboarding_reply(array('ok' => true) + $booking->lookup(isset($in['email']) ? (string) $in['email'] : ''));
	}
	if ($action == 'closeddays') {
		onboarding_reply($booking->setCalendarClosures(isset($in['days']) && is_array($in['days']) ? $in['days'] : array()));
	}
	if ($action == 'notifyback') {
		// People who asked, while Dolibarr was down, to hear when booking reopens.
		$n = 0;
		foreach ((isset($in['emails']) && is_array($in['emails']) ? array_slice($in['emails'], 0, 200) : array()) as $e) {
			$e = OnboardingService::cleanEmail($e);
			if (isValidEmail($e) && $svc->mail($e, 'Training booking is open again', "Hi,\n\nYou asked us to tell you when training booking was working again. It is:\n\n".$booking->bookUrl()."\n\nSorry for the wait.")) {
				$n++;
			}
		}
		onboarding_reply(array('ok' => true, 'sent' => $n));
	}
	if ($action == 'websitereport') {
		onboarding_reply($booking->websiteReport($in));
	}
	if ($action == 'classinfo') {
		onboarding_reply($booking->classInfo(isset($in['code']) ? (string) $in['code'] : ''));
	}
	onboarding_reply($booking->classJoin($in));
}

if ($action == 'voteresend') {
	// "Send my ballot again" on the website's /voting/ page. The same answer every
	// time, so it cannot tell anyone who is on the roll; the link only goes to the
	// address on the member card.
	dol_include_once('/onboarding/class/vote.class.php');
	$vote = new OnboardingVote($db);
	$vote->resend(isset($in['email']) ? (string) $in['email'] : '', isset($in['pin']) ? (string) $in['pin'] : '');
	onboarding_reply(array('ok' => true));
}
if ($action == 'trainingstart') {
	// A training registered on the website's /training/pay/ page, just before the
	// trainee pays. Only adds a row that a later payment from the same email uses.
	dol_include_once('/onboarding/class/training.class.php');
	$training = new OnboardingTraining($db, $svc);
	onboarding_reply($training->register($in));
}

if (in_array($action, array('budget', 'purchase', 'purchasefile', 'purchasefinish'), true)) {
	// The website's /budget page. "budget" is totals per zone and nothing else. A request
	// is filed in three calls so receipts travel one at a time: purchase (checks the
	// member, returns a draft id and token), purchasefile for each receipt, then
	// purchasefinish, which sends it for approval. Turnstile and the per-IP limit are
	// the Worker's; the member check and the guessing limits are here.
	dol_include_once('/onboarding/class/purchase.class.php');
	$purchase = new OnboardingPurchase($db, $svc);
	if ($action == 'budget') {
		onboarding_reply(array('ok' => true, 'zones' => $purchase->budget()));
	}
	if ($action == 'purchase') {
		onboarding_reply($purchase->submit($in));
	}
	$pid = isset($in['id']) ? (int) $in['id'] : 0;
	$ptoken = isset($in['token']) ? (string) $in['token'] : '';
	if ($action == 'purchasefinish') {
		onboarding_reply($purchase->finish($pid, $ptoken));
	}
	$pbytes = base64_decode(isset($in['data']) ? (string) $in['data'] : '', true);
	if ($pbytes === false) {
		onboarding_reply(array('ok' => false, 'error' => 'type', 'http' => 400));
	}
	onboarding_reply($purchase->addFile($pid, $ptoken, $pbytes));
}

if ($action == 'givebutter') {
	$event = isset($in['event']) ? (string) $in['event'] : '';
	$result = $svc->handleEvent($event, isset($in['data']) ? $in['data'] : null);
	dol_syslog('Onboarding: Givebutter '.$event.' => '.$result);
	$svc->noteWebhook($event.': '.$result.' (through the website)');
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
	$extra = array();
	foreach (array('birthdate', 'guardian_name', 'guardian_relation', 'guardian_email') as $k) {
		if (isset($in[$k])) {
			$extra[$k] = (string) $in[$k];
		}
	}
	onboarding_reply($svc->sign(
		$app,
		isset($in['doc']) ? (string) $in['doc'] : '',
		isset($in['name']) ? (string) $in['name'] : '',
		isset($in['version']) ? (string) $in['version'] : '',
		isset($in['ip']) ? (string) $in['ip'] : '',
		(string) base64_decode(isset($in['signature']) ? (string) $in['signature'] : '', true),
		$extra
	));
}

if ($action == 'cancel') {
	onboarding_reply($svc->cancel($app));
}

if ($action == 'id') {
	$bytes = base64_decode(isset($in['data']) ? (string) $in['data'] : '', true);
	if ($bytes === false) {
		onboarding_reply(array('ok' => false, 'error' => 'type', 'http' => 400));
	}
	onboarding_reply($svc->uploadId($app, $bytes));
}

onboarding_reply(array('ok' => false, 'error' => 'unknown action', 'http' => 404));
