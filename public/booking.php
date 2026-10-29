<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The buttons in training booking emails. No login: each link is signed for one
 * booking and one person (trainer or trainee), or for one group class (see
 * OnboardingBooking::link). Opening a link only shows the booking and the
 * choices, because mail scanners open links on their own; nothing changes until
 * a button on the page is pressed.
 *
 *   GET  .../custom/onboarding/public/booking.php?b=BOOKING&w=trainer|trainee&k=SIGNATURE[&a=ACTION]
 *   GET  .../custom/onboarding/public/booking.php?c=CLASS&k=SIGNATURE
 *   GET  .../custom/onboarding/public/booking.php?t=TRAINER&x=UNTIL&k=SIGNATURE (on the spot)
 *   POST the same, with do=ACTION and the action's fields
 *
 * Emails link to the website's /training/link/ with the same query, which passes
 * the request here with the website key and shows the answer inside the website
 * (see onboarding_booking_page). Opening this file directly works too.
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

dol_include_once('/onboarding/class/booking.class.php');

/**
 * @param string $title Heading
 * @param string $html Body, already escaped
 * @param int $code HTTP status
 * @return never
 */
function onboarding_booking_page($title, $html, $code = 200)
{
	global $db;
	// Shown inside the website (its /training/link/ passes clicks through with
	// the website key): the website wraps the body in its own page.
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
	print '<style>body{font-family:system-ui,sans-serif;max-width:36rem;margin:2.5rem auto;padding:0 1rem;line-height:1.5;color:#222}';
	print 'h1{font-size:1.4rem}h2{font-size:1.1rem;margin-top:1.8rem}.meta{color:#555}.facts{background:#f4f6f5;border-radius:8px;padding:.8rem 1rem}';
	print 'button{font-size:1rem;padding:.6rem 1.2rem;border:0;border-radius:6px;background:#2d6a4f;color:#fff;cursor:pointer;margin:.3rem 0}';
	print 'button.alt{background:#fff;color:#2d6a4f;border:2px solid #2d6a4f}button.warn{background:#9b2c2c}';
	print 'textarea{width:100%;min-height:5rem;font:inherit;padding:.5rem;box-sizing:border-box}label{display:block;margin:.4rem 0}';
	print 'form{margin:.6rem 0 1.2rem}.ok{color:#2d6a4f;font-weight:600}.err{color:#9b2c2c;font-weight:600}ul.slots{list-style:none;padding:0}ul.slots li{margin:.3rem 0}</style></head><body>';
	print '<p class="meta">'.$org.'</p><h1>'.dol_escape_htmltag($title).'</h1>'.$html.'</body></html>';
	$db->close();
	exit;
}

/**
 * @param array<string,string> $hidden Fields that carry the link
 * @param string $do Action
 * @param string $label Button
 * @param string $extra More fields, already escaped
 * @param string $class Button class
 * @return string A one-button form
 */
function onboarding_booking_form($hidden, $do, $label, $extra = '', $class = '')
{
	// Posts back to the address it was opened at: this file, or the website's /training/link/.
	$h = '<form method="post" action="">';
	foreach ($hidden as $k => $v) {
		$h .= '<input type="hidden" name="'.dol_escape_htmltag($k).'" value="'.dol_escape_htmltag($v).'">';
	}
	$h .= '<input type="hidden" name="do" value="'.dol_escape_htmltag($do).'">'.$extra;
	return $h.'<button type="submit"'.($class ? ' class="'.$class.'"' : '').'>'.dol_escape_htmltag($label).'</button></form>';
}

if (!isModEnabled('onboarding')) {
	onboarding_booking_page('Not available', '<p>Training bookings are switched off.</p>', 503);
}

$bk = new OnboardingBooking($db);
$key = GETPOST('k', 'alphanohtml');
$do = GETPOST('do', 'aZ09');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$now = OnboardingService::now();

// ---------------------------------------------------------------- a trainer starts a training on the spot
$trainerId = (int) GETPOST('t', 'int');
if ($trainerId > 0) {
	$until = (int) GETPOST('x', 'int');
	if (!$bk->spotLinkValid($trainerId, $until, $key)) {
		onboarding_booking_page('Link not valid', '<p>This link is not valid or has run out (it works for '.((int) (OnboardingBooking::SPOT_LINK_MINUTES / 60)).' hours). Ask for a new one on the training booking page: <a href="'.dol_escape_htmltag($bk->bookUrl()).'">'.dol_escape_htmltag($bk->bookUrl()).'</a></p>', 403);
	}
	$res = $db->query("SELECT email FROM ".$db->prefix()."adherent WHERE rowid = ".$trainerId);
	$o = $res ? $db->fetch_object($res) : null;
	$tools = $o ? $bk->spotTools($bk->memberRow((string) $o->email)) : array();
	$hidden = array('t' => (string) $trainerId, 'x' => (string) $until, 'k' => $key);
	$expires = OnboardingBooking::fmt($now + OnboardingBooking::SPOT_HOURS * 3600, 'g:i A');
	if (!$tools) {
		onboarding_booking_page('Not a trainer now', '<p>This member card has no tool to train on right now: a trainer needs a training time on the tool, a current membership, and to be trained on the tool themselves. Ask the secretary.</p>', 403);
	}
	$done = '';
	if ($post && $do == 'spot') {
		$r = $bk->spotRequest($trainerId, (int) GETPOST('tool', 'int'), GETPOST('email', 'alphanohtml'));
		if (!empty($r['ok'])) {
			$done = '<p class="ok">Sent. The trainee has an email asking whether they asked for this training ('.dol_escape_htmltag($r['ref']).'). If they say yes, they sign the waiver and pay online; you get an email once they have paid. The request expires at '.dol_escape_htmltag(OnboardingBooking::fmt($r['expires'], 'g:i A')).' if they have not paid by then.</p><h2>Train someone else</h2>';
		} else {
			$done = '<p class="err">'.dol_escape_htmltag($r['error']).'</p>';
		}
	}
	$opts = '';
	foreach ($tools as $t) {
		$opts .= '<option value="'.((int) $t->rowid).'">'.dol_escape_htmltag($t->label.' ($'.number_format(OnboardingBooking::memberPrice($t), 2).' members, $'.number_format((float) $t->fee, 2).' others)').'</option>';
	}
	$fields = '<label>Tool<br><select name="tool" required>'.$opts.'</select></label>';
	$fields .= '<label>Trainee\'s email<br><input type="email" name="email" required maxlength="200" style="width:100%;font:inherit;padding:.4rem;box-sizing:border-box"></label>';
	$html = $done.'<p>Type the email address of the person you are about to train. They get an email asking whether they asked for this training. If they say yes, they sign the waiver (and, for members, the membership agreement) and pay online, at the member price if they are a member. Once they have paid you get an email to record the training.</p>';
	$html .= '<p class="meta">The request expires in '.((int) OnboardingBooking::SPOT_HOURS).' hours (at '.dol_escape_htmltag($expires).' if sent now) if they have not paid by then. Someone already trained on the tool cannot be sent a request.</p>';
	$html .= onboarding_booking_form($hidden, 'spot', 'Send the request to the trainee', $fields);
	onboarding_booking_page('Train someone now', $html);
}

// ---------------------------------------------------------------- group class list
$classId = (int) GETPOST('c', 'int');
if ($classId > 0) {
	$c = $bk->klass($classId);
	if (!$c || !hash_equals($bk->sign('class:'.$classId), (string) $key)) {
		onboarding_booking_page('Link not valid', '<p>This link is not valid. Ask the secretary to confirm the class in Dolibarr.</p>', 403);
	}
	$hidden = array('c' => (string) $classId, 'k' => $key);
	$facts = '<div class="facts"><strong>'.dol_escape_htmltag($c->tool).'</strong> group class<br>'.dol_escape_htmltag(OnboardingBooking::fmt($db->jdate($c->session_start))).' &middot; code '.dol_escape_htmltag($c->code).'</div>';
	if ($post && $do == 'finish') {
		$ids = GETPOST('trained', 'array');
		$msg = $bk->classFinish($c, is_array($ids) ? $ids : array());
		onboarding_booking_page('Class list confirmed', $facts.'<p class="ok">Thank you. '.dol_escape_htmltag($msg).' Everyone trained gets an email.</p>');
	}
	$rows = $bk->bookingsWhere("fk_class = ".((int) $classId)." AND status IN ('confirmed', 'trained')");
	$waiting = array_filter($rows, function ($b) {
		return $b->status == 'confirmed';
	});
	if (!$waiting) {
		$list = '';
		foreach ($rows as $b) {
			$list .= '<li>'.dol_escape_htmltag(OnboardingBooking::shortName($b->firstname, $b->lastname)).' (trained)</li>';
		}
		onboarding_booking_page('Nothing to confirm', $facts.($list ? '<ul>'.$list.'</ul>' : '<p>Nobody signed in to this class.</p>').'<p>Nothing is waiting.</p>');
	}
	if ($now < $db->jdate($c->session_start)) {
		onboarding_booking_page('The class has not started', $facts.'<p>Come back after the class to confirm who was trained.</p>');
	}
	$boxes = '';
	foreach ($rows as $b) {
		if ($b->status == 'trained') {
			$boxes .= '<label><input type="checkbox" checked disabled> '.dol_escape_htmltag(OnboardingBooking::shortName($b->firstname, $b->lastname)).' (already recorded)</label>';
		} else {
			$boxes .= '<label><input type="checkbox" name="trained[]" value="'.((int) $b->rowid).'" checked> '.dol_escape_htmltag(OnboardingBooking::shortName($b->firstname, $b->lastname)).'</label>';
		}
	}
	onboarding_booking_page('Who was trained?', $facts.'<p>Untick anyone who left early or did not finish. Everyone ticked is recorded as trained.</p>'.onboarding_booking_form($hidden, 'finish', 'Confirm the list', $boxes));
}

// ---------------------------------------------------------------- one booking
$id = (int) GETPOST('b', 'int');
$who = GETPOST('w', 'aZ09');
$b = $id > 0 ? $bk->booking($id) : null;
if (!$b || !in_array($who, array('trainer', 'trainee'), true) || !hash_equals($bk->sign('booking:'.$id.':'.$who), (string) $key)) {
	onboarding_booking_page('Link not valid', '<p>This link is not valid. Reply to the email it came in and we will sort it out.</p>', 403);
}
$hidden = array('b' => (string) $id, 'w' => $who, 'k' => $key);
$start = $db->jdate($b->session_start);
$end = $start + 60 * (int) $b->minutes;
$label = OnboardingBooking::STATUS_LABELS[$b->status];
$facts = '<div class="facts"><strong>'.dol_escape_htmltag($b->tool).'</strong> training ('.dol_escape_htmltag($b->ref).')<br>';
$facts .= ($start ? dol_escape_htmltag(OnboardingBooking::fmt($start)).' with '.dol_escape_htmltag($b->trainer_first) : 'No date yet').'<br>';
$facts .= ($who == 'trainer' ? 'Trainee: '.dol_escape_htmltag(OnboardingBooking::shortName($b->firstname, $b->lastname)).'<br>' : '');
$facts .= '<span class="meta">'.dol_escape_htmltag($label).'</span></div>';
$comment = '<label>Comment<textarea name="comment" maxlength="2000"></textarea></label>';
$message = function ($r, $okText) use ($facts) {
	if (!empty($r['ok'])) {
		onboarding_booking_page('Done', $facts.'<p class="ok">'.dol_escape_htmltag($okText !== '' ? $okText : (isset($r['result']) ? $r['result'] : 'Done.')).'</p>');
	}
	onboarding_booking_page('Not done', $facts.'<p class="err">'.dol_escape_htmltag(isset($r['error']) ? $r['error'] : 'That did not work.').'</p>', 409);
};

if ($b->source == 'spot' && in_array($b->status, array('requested', 'held', 'expired'), true)) {
	$open = $b->status != 'expired' && $db->jdate($b->hold_until) >= $now;
	if ($who == 'trainer') {
		onboarding_booking_page('Waiting for the trainee', $facts.'<p>'.($open ? 'The trainee has not paid yet. The request expires at '.dol_escape_htmltag(OnboardingBooking::fmt($db->jdate($b->hold_until), 'g:i A')).'.' : 'This request has expired.').'</p>');
	}
	if ($post && ($do == 'spot_yes' || $do == 'spot_no')) {
		$r = $bk->spotAnswer($b, $do == 'spot_yes');
		if (empty($r['ok'])) {
			$message($r, '');
		}
		if ($do == 'spot_no') {
			onboarding_booking_page('Thank you', $facts.'<p class="ok">The request is cancelled and nothing was charged. We have told the secretary, who will look into it.</p>');
		}
		onboarding_booking_page('Sign and pay', $facts.'<p class="ok">Thank you. Next: sign the waiver if it is not on file yet, then pay, before '.dol_escape_htmltag(OnboardingBooking::fmt($db->jdate($b->hold_until), 'g:i A')).'.</p><p><a href="'.dol_escape_htmltag($r['url']).'" style="display:inline-block;padding:.6rem 1.2rem;border-radius:6px;background:#2d6a4f;color:#fff;text-decoration:none">Continue to sign and pay</a></p>');
	}
	if (!$open) {
		onboarding_booking_page('Request expired', $facts.'<p>This request was not paid within '.((int) OnboardingBooking::SPOT_HOURS).' hours, so it has expired and nothing was charged. Ask your trainer to send a new one.</p>');
	}
	$html = $facts.'<p><strong>'.dol_escape_htmltag(trim($b->trainer_first.' '.$b->trainer_last)).'</strong> says you are with them and would like to train you on the '.dol_escape_htmltag($b->tool).' now. Did you ask for this training?</p>';
	$html .= onboarding_booking_form($hidden, 'spot_yes', $b->status == 'held' ? 'Yes: continue to sign and pay' : 'Yes, that\'s me: sign and pay');
	if ($b->status == 'requested') {
		$html .= onboarding_booking_form($hidden, 'spot_no', 'No, I didn\'t ask for this', '', 'warn');
	}
	$html .= '<p class="meta">This request expires at '.dol_escape_htmltag(OnboardingBooking::fmt($db->jdate($b->hold_until), 'g:i A')).', '.((int) OnboardingBooking::SPOT_HOURS).' hours after it was sent.</p>';
	onboarding_booking_page('Did you ask for this training?', $html);
}

if ($who == 'trainer') {
	$before = $now < $start;
	if ($post) {
		if ($do == 'confirm' && $before && in_array($b->status, array('paid', 'confirmed'), true)) {
			$bk->confirm($b);
			$message(array('ok' => true), 'Thank you. The session is confirmed; you will get a reminder on the day.');
		}
		if ($do == 'cant' && $before && in_array($b->status, array('paid', 'confirmed'), true)) {
			$n = $bk->trainerCant($b, 'trainer');
			$message(array('ok' => true), 'Thank you for saying so. '.$n.' trainee'.($n == 1 ? ' was' : 's were').' emailed to reschedule, ask for a refund, or tell us something else; you were copied, and you can reply to talk to them.');
		}
		if (in_array($do, array('trained', 'noshow', 'other'), true)) {
			$message($bk->answer($b, $do, GETPOST('comment', 'restricthtml')), '');
		}
		$message(array('ok' => false, 'error' => 'This booking has moved on since the email was sent ('.$label.'). Nothing changed.'), '');
	}
	if ($before && in_array($b->status, array('paid', 'confirmed'), true)) {
		$html = $facts.'<h2>Will you be there?</h2>';
		$html .= onboarding_booking_form($hidden, 'confirm', $b->status == 'confirmed' ? 'Yes, still coming' : 'I\'ll be there');
		$html .= onboarding_booking_form($hidden, 'cant', 'I can\'t make it', '', 'warn');
		$html .= '<p class="meta">"I can\'t make it" cancels this session: everyone on it is asked to pick a new date at no cost, or to ask for a refund.</p>';
		onboarding_booking_page('Training booked', $html);
	}
	if (!$before && in_array($b->status, array('confirmed', 'paid'), true)) {
		$n = OnboardingBooking::shortName($b->firstname, $b->lastname);
		$html = $facts.'<h2>How did it go?</h2>';
		$html .= onboarding_booking_form($hidden, 'trained', $n.' showed up, and I performed the training');
		$html .= onboarding_booking_form($hidden, 'noshow', $n.' did not show up, do not mark them as trained', '', 'alt');
		$html .= '<h2>Other</h2><p>Partly trained, needs another session, anything else: your comment goes to the secretary.</p>';
		$html .= onboarding_booking_form($hidden, 'other', 'Send to the secretary', $comment, 'alt');
		onboarding_booking_page('How did the training go?', $html);
	}
	onboarding_booking_page('Nothing to do', $facts.'<p>This booking needs nothing from you now.</p>');
}

// The trainee.
$cancelBy = $start - (int) round(OnboardingBooking::num('TRAINING_CANCEL_HOURS', 2) * 3600);
$freeRebook = $b->status == 'needs_new_date' && (int) $b->no_shows > (int) $b->rebooks;
$rebookUntil = $freeRebook && $b->answered_at ? $db->jdate($b->answered_at) + (int) round(OnboardingBooking::num('TRAINING_REBOOK_DAYS', 60) * 86400) : 0;
$canChange = (in_array($b->status, array('paid', 'confirmed'), true) && $now < $cancelBy) || ($b->status == 'needs_new_date' && (!$rebookUntil || $now <= $rebookUntil));
$noRefund = $freeRebook;
$action = $post ? $do : GETPOST('a', 'aZ09');

if ($post && $do == 'pick') {
	if (!$canChange) {
		$message(array('ok' => false, 'error' => 'This booking can no longer be moved here. Reply to your booking email.'), '');
	}
	$r = $bk->move($b, (int) GETPOST('slot', 'int'), (int) GETPOST('start', 'int'));
	$message($r, 'Moved. A new confirmation is on its way to your inbox, and your trainer has been asked to confirm.');
}
if ($post && $do == 'refund') {
	if (!$canChange || $noRefund) {
		$message(array('ok' => false, 'error' => $noRefund ? 'After a missed training the fee is not refunded, but you can pick a new date.' : 'It is too late to cancel online. Reply to your booking email.'), '');
	}
	$message($bk->requestRefund($b, GETPOST('comment', 'restricthtml')), 'Cancelled. The treasurer has been asked to refund you, and you will get an email when it is done.');
}
if ($post && ($do == 'other' || $do == 'no_trainer')) {
	if (in_array($b->status, array('trained', 'no_show', 'refunded', 'cancelled', 'expired', 'refund_requested'), true)) {
		$message(array('ok' => false, 'error' => 'This booking is closed ('.$label.'). Reply to your booking email.'), '');
	}
	$message($bk->traineeSays($b, $do, GETPOST('comment', 'restricthtml')), 'Thank you. The secretary has your message and will be in touch.');
}

if ($action == 'no_trainer' && in_array($b->status, array('confirmed', 'paid', 'other'), true) && $now >= $start) {
	$html = $facts.'<h2>My trainer didn\'t show up</h2><p>Sorry about that. Tell us what happened and the secretary will offer you a new date or a refund.</p>';
	$html .= onboarding_booking_form($hidden, 'no_trainer', 'Tell the secretary', $comment);
	onboarding_booking_page('Did your training happen?', $html);
}
if (!$canChange) {
	if (in_array($b->status, array('paid', 'confirmed'), true) && $now >= $cancelBy) {
		$html = $facts.'<p>The training is less than '.dol_escape_htmltag((string) OnboardingBooking::num('TRAINING_CANCEL_HOURS', 2)).' hours away, so it can no longer be cancelled or moved online. If something has come up, tell the secretary:</p>';
		$html .= onboarding_booking_form($hidden, 'other', 'Send to the secretary', $comment, 'alt');
		onboarding_booking_page('Too late to change online', $html);
	}
	onboarding_booking_page('Nothing to change', $facts.'<p>This booking cannot be changed here ('.dol_escape_htmltag($label).'). Reply to your booking email if you need something.</p>');
}

$html = $facts;
if ($action == 'refund' && !$noRefund) {
	$html .= '<h2>Cancel and get a refund</h2><p>Your seat is given up and the treasurer refunds you in full.</p>';
	$html .= onboarding_booking_form($hidden, 'refund', 'Cancel and refund me', '<label>Anything we should know? (optional)<textarea name="comment" maxlength="2000"></textarea></label>', 'warn');
	$html .= '<p><a href="'.dol_escape_htmltag($bk->link($b, 'trainee', 'move')).'">Pick a new date instead</a></p>';
	onboarding_booking_page('Cancel your training?', $html);
}
if ($action == 'other') {
	$html .= '<h2>Something else</h2><p>Tell us what you need and the secretary will get back to you.</p>';
	$html .= onboarding_booking_form($hidden, 'other', 'Send to the secretary', $comment);
	onboarding_booking_page('Tell us what you need', $html);
}

$slots = $bk->available((int) $b->fk_tool, (int) $b->rowid);
$list = '';
foreach (array_slice($slots, 0, 20) as $s) {
	$list .= '<li>'.onboarding_booking_form($hidden + array('slot' => (string) $s['slot'], 'start' => (string) $s['ts']), 'pick', $s['label'].' with '.$s['trainer'], '', 'alt').'</li>';
}
$html .= '<h2>'.($b->status == 'needs_new_date' ? 'Pick a new date' : 'Move to another date').'</h2>';
$html .= $list ? '<ul class="slots">'.$list.'</ul>' : '<p>No other times are open right now. Check again in a few days, or tell us below.</p>';
if ($rebookUntil) {
	$html .= '<p class="meta">Your free new date can be picked until '.dol_escape_htmltag(OnboardingBooking::fmt($rebookUntil, 'l j F')).'.</p>';
}
if (!$noRefund) {
	$html .= '<h2>Cancel instead</h2>'.onboarding_booking_form($hidden, 'refund', 'Cancel and refund me', '', 'warn');
}
$html .= '<h2>Something else</h2>'.onboarding_booking_form($hidden, 'other', 'Send to the secretary', $comment, 'alt');
onboarding_booking_page($b->status == 'needs_new_date' ? 'Pick a new date' : 'Need to cancel training?', $html);
