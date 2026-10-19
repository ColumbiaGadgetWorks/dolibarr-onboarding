<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The buttons in member-vote emails. No login: each link is signed for one vote,
 * one member and one use (see OnboardingVote::link):
 *
 *   w=panel   the Secretary's or President's page: questions, candidates, and the
 *             Send notice / Send reminder / Open voting / Remind non-voters buttons,
 *             then the result and any tiebreak. Works only while the member holds
 *             the secretary or president tag.
 *   w=run     a member running for a board seat, or withdrawing
 *   w=ballot  a member's ballot
 *
 *   GET  .../custom/onboarding/public/vote.php?v=VOTE&m=MEMBER&w=WHO&k=SIGNATURE
 *   POST the same, with do=ACTION and its fields
 *
 * Opening a link only shows the page (mail scanners open links on their own);
 * nothing changes until a button is pressed, except that opening the panel sends
 * a few of the emails waiting to go out. Emails link to the website's
 * /voting/link/, which passes the request here with the website key and shows
 * the answer inside the website.
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

dol_include_once('/onboarding/class/vote.class.php');

/**
 * @param string $title Heading
 * @param string $html Body, already escaped
 * @param int $code HTTP status
 * @param int $refresh Reload the page after this many seconds, 0 for never
 * @return never
 */
function onboarding_vote_page($title, $html, $code = 200, $refresh = 0)
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

/**
 * @param array<string,string> $hidden Fields that carry the link
 * @param string $do Action
 * @param string $label Button
 * @param string $extra More fields, already escaped
 * @param string $class Button class
 * @return string A form with one button
 */
function onboarding_vote_form($hidden, $do, $label, $extra = '', $class = '')
{
	$h = '<form method="post" action="">';
	foreach ($hidden as $k => $v) {
		$h .= '<input type="hidden" name="'.dol_escape_htmltag($k).'" value="'.dol_escape_htmltag($v).'">';
	}
	$h .= '<input type="hidden" name="do" value="'.dol_escape_htmltag($do).'">'.$extra;
	return $h.'<button type="submit"'.($class ? ' class="'.$class.'"' : '').'>'.dol_escape_htmltag($label).'</button></form>';
}

if (!isModEnabled('onboarding')) {
	onboarding_vote_page('Not available', '<p>Member votes are switched off.</p>', 503);
}

$vt = new OnboardingVote($db);
$voteId = (int) GETPOST('v', 'int');
$memberId = (int) GETPOST('m', 'int');
$who = GETPOST('w', 'aZ09');
$key = GETPOST('k', 'alphanohtml');
$do = GETPOST('do', 'aZ09');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$now = OnboardingService::now();

$v = $voteId > 0 ? $vt->get($voteId) : null;
if (!$v || !in_array($who, array('panel', 'run', 'ballot'), true) || !hash_equals($vt->signFor($voteId, $memberId, $who), (string) $key)) {
	onboarding_vote_page('Link not valid', '<p>This link is not valid. Use the newest email about the vote, or ask the Secretary.</p>', 403);
}
$m = $vt->member($memberId);
if (!$m) {
	onboarding_vote_page('Link not valid', '<p>This link is not valid any more. Ask the Secretary.</p>', 403);
}
$hidden = array('v' => (string) $voteId, 'm' => (string) $memberId, 'w' => $who, 'k' => $key);
$e = function ($t) {
	return dol_escape_htmltag((string) $t, 0, 1); // keep line breaks, for the result and the notice
};
$facts = '<div class="facts"><strong>'.$e($v->label).'</strong>'.(!empty($v->test) ? ' (practice vote)' : '').'<br>Meeting: '.$e(OnboardingBooking::fmt($v->meeting)).'<br>'.$e(OnboardingVote::STATUS_LABELS[$v->status]);
if ($v->status == 'open') {
	$facts .= ', closes '.$e(OnboardingBooking::fmt($v->closes));
}
$facts .= '</div>';

// ---------------------------------------------------------------- running for a seat
if ($who == 'run') {
	if ($post && $do == 'run') {
		$r = $vt->run($v, (int) GETPOST('seat', 'int'), $memberId, GETPOST('statement', 'restricthtml'), GETPOST('adult', 'int') == 1);
		$flash = '<p class="'.($r['ok'] ? 'ok' : 'err').'">'.$e($r['msg']).'</p>';
	} elseif ($post && $do == 'withdraw') {
		$flash = '<p class="ok">'.$e($vt->withdraw($v, $memberId)).'</p>';
	} else {
		$flash = '';
	}
	if (!in_array($v->status, array('draft', 'noticed'))) {
		onboarding_vote_page('Nominations are closed', $facts.$flash.'<p>Nominations closed when voting opened.</p>');
	}
	$why = $vt->cannotRun($m, true);
	if ($why !== '') {
		onboarding_vote_page('Run for a board seat', $facts.'<p>'.$e($why).'</p>');
	}
	$mine = null;
	foreach ($vt->candidates($v) as $c) {
		if ((int) $c->fk_adherent == $memberId) {
			$mine = $c;
		}
	}
	$h = $facts.$flash;
	$seats = array_filter($vt->questions($v), function ($q) {
		return $q->kind == 'seat';
	});
	if ($mine) {
		$seatName = '';
		foreach ($seats as $q) {
			$seatName = (int) $q->rowid == (int) $mine->fk_question ? $q->title : $seatName;
		}
		$h .= '<p>You are running for <strong>'.$e($seatName).'</strong>. Nominations close when voting opens at the meeting.</p>';
	}
	$h .= '<form method="post" action="">';
	foreach ($hidden as $k => $val) {
		$h .= '<input type="hidden" name="'.$e($k).'" value="'.$e($val).'">';
	}
	$h .= '<input type="hidden" name="do" value="run"><fieldset><legend>'.($mine ? 'Change seat or statement' : 'Pick one seat').'</legend>';
	foreach ($seats as $q) {
		$h .= '<label><input type="radio" name="seat" value="'.((int) $q->rowid).'" required'.($mine && (int) $mine->fk_question == (int) $q->rowid ? ' checked' : '').'> '.$e($q->title).'</label>';
	}
	$h .= '</fieldset><label>A short statement for the ballot (optional, up to 400 characters)<textarea name="statement" maxlength="400">'.($mine ? $e($mine->statement) : '').'</textarea></label>';
	if (empty($m->birth)) {
		$h .= '<label><input type="checkbox" name="adult" value="1" required> I am 18 or older</label>';
	} else {
		$h .= '<input type="hidden" name="adult" value="1">';
	}
	$h .= '<p class="meta">A candidate runs for one seat only. Your name and statement go on the ballot and in the emails to members.</p><button type="submit">'.($mine ? 'Save' : 'Run for this seat').'</button></form>';
	if ($mine) {
		$h .= onboarding_vote_form($hidden, 'withdraw', 'Withdraw: I am no longer running', '', 'warn');
	}
	onboarding_vote_page('Run for a board seat', $h);
}

// ---------------------------------------------------------------- the ballot
if ($who == 'ballot') {
	$flash = '';
	if ($post && $do == 'cast') {
		$answers = array();
		foreach ((array) GETPOST('q', 'array') as $qid => $oid) {
			$answers[(int) $qid] = (int) $oid;
		}
		if (GETPOST('final', 'int') != 1) {
			$r = array('ok' => false, 'msg' => 'Tick the box to confirm your ballot is final.');
		} else {
			$r = $vt->cast($v, $memberId, $answers);
		}
		if ($r['ok']) {
			onboarding_vote_page('Thank you for voting', $facts.'<p class="ok">'.$e($r['msg']).'</p><p>You will get an email confirming your ballot was counted, without your choices. Results go out when voting closes.</p>');
		}
		$flash = '<p class="err">'.$e($r['msg']).'</p>';
	}
	if (in_array($v->status, array('draft', 'noticed'))) {
		onboarding_vote_page('Your ballot', $facts.'<p>Voting opens during the meeting on '.$e(OnboardingBooking::fmt($v->meeting)).'. Your ballot will come by email then.</p>');
	}
	$row = $vt->rollRow($v, $memberId);
	if ($v->status == 'closed' || $now >= $v->closes) {
		onboarding_vote_page('Voting has closed', $facts.($v->status == 'closed' && $v->result ? '<pre>'.$e($v->result).'</pre>' : '<p>The result goes out shortly.</p>'));
	}
	if (!$row) {
		onboarding_vote_page('Your ballot', $facts.'<p>You are not on the roll for this vote. Full members in good standing who joined at least 30 days before the meeting can vote. If you think this is a mistake, write to the Secretary.</p>');
	}
	if ($row->voted_on) {
		onboarding_vote_page('You have voted', $facts.'<p class="ok">Your ballot was counted on '.$e(dol_print_date($db->jdate($row->voted_on), 'day')).'. Thank you.</p><p>If you did not vote yourself, tell the Secretary straight away.</p>');
	}
	$h = $facts.$flash.'<form method="post" action="">';
	foreach ($hidden as $k => $val) {
		$h .= '<input type="hidden" name="'.$e($k).'" value="'.$e($val).'">';
	}
	$h .= '<input type="hidden" name="do" value="cast">';
	if ($v->tag) {
		$repo = OnboardingVote::parsePr($v->pr_url);
		$h .= '<p>The bylaw text you are voting on is frozen as <a href="https://github.com/'.$e($repo ? $repo[0] : OnboardingVote::repo()).'/tree/'.$e($v->tag).'">'.$e($v->tag).'</a>.</p>';
	}
	$candidates = array();
	foreach ($vt->candidates($v) as $c) {
		$candidates[(int) $c->rowid] = $c;
	}
	$asked = 0;
	foreach ($vt->questions($v) as $q) {
		$opts = $vt->options((int) $q->rowid);
		if (!$opts) {
			continue;
		}
		$asked++;
		$h .= '<fieldset><legend>'.$e($q->kind == 'seat' ? $q->title.' (board seat)' : $q->title).'</legend>';
		if ($q->kind == 'bylaw') {
			$h .= '<p class="meta">'.($q->url ? '<a href="'.$e($q->url).'">Read the text</a>. ' : '').'Passes with '.($q->threshold == 'majority' ? 'a majority' : 'two-thirds').' of votes cast.</p>';
		}
		foreach ($opts as $o) {
			$h .= '<label><input type="radio" name="q['.((int) $q->rowid).']" value="'.((int) $o->rowid).'" required> '.$e($o->label).'</label>';
			if ($o->fk_candidate && isset($candidates[(int) $o->fk_candidate]) && $candidates[(int) $o->fk_candidate]->statement && $o->kind != 'no') {
				$h .= '<p class="meta" style="margin:0 0 .6rem 1.6rem">'.$e($candidates[(int) $o->fk_candidate]->statement).'</p>';
			}
		}
		$h .= '</fieldset>';
	}
	if (!$asked) {
		onboarding_vote_page('Your ballot', $facts.'<p>There is nothing on this ballot.</p>');
	}
	$h .= '<label><input type="checkbox" name="final" value="1" required> I understand my ballot is final once I submit it.</label>';
	$h .= '<p class="meta">Secret ballot: the system records that you voted, never how.</p><button type="submit">Submit my ballot</button></form>';
	onboarding_vote_page('Your ballot', $h);
}

// ---------------------------------------------------------------- the officers' panel
$role = $vt->officerRole($memberId);
if ($role === '') {
	onboarding_vote_page('Link not valid', '<p>This page is for the Secretary and the President. It works only while you hold one of those tags in Dolibarr.</p>', 403);
}
$by = trim($m->firstname.' '.$m->lastname).' ('.($role == 'president' ? 'President' : 'Secretary').')';
$flash = '';
if ($post) {
	$msg = '';
	switch ($do) {
		case 'saveq':
			$msg = $vt->saveQuestion($v, array('id' => GETPOST('qid', 'int'), 'title' => GETPOST('title', 'restricthtml'), 'url' => GETPOST('url', 'alphanohtml'), 'threshold' => GETPOST('threshold', 'aZ09')));
			break;
		case 'delq':
			$msg = $vt->removeQuestion($v, (int) GETPOST('qid', 'int'));
			break;
		case 'settings':
			$msg = $vt->saveSettings($v, GETPOST('pr_url', 'alphanohtml'), GETPOST('ratify', 'int') == 1);
			break;
		case 'addcand':
			$c = $vt->memberByEmail(GETPOST('email', 'alphanohtml'));
			if (!$c) {
				$msg = 'No member has that email.';
			} else {
				$r = $vt->run($v, (int) GETPOST('seat', 'int'), (int) $c->rowid, GETPOST('statement', 'restricthtml'), GETPOST('adult', 'int') == 1, 'meeting');
				$msg = $r['ok'] ? trim($c->firstname.' '.$c->lastname).' added.' : $r['msg'];
			}
			break;
		case 'delcand':
			$c = $vt->candidate((int) GETPOST('cid', 'int'));
			$msg = $c && (int) $c->fk_vote == $voteId ? $vt->withdraw($v, (int) $c->fk_adherent) : 'Not found.';
			break;
		case 'notice':
			$msg = GETPOST('sure', 'int') == 1 ? $vt->sendNotice($v, $by) : 'Tick the box to confirm.';
			break;
		case 'discussion':
			$msg = $vt->sendDiscussion($v, $by);
			break;
		case 'open':
			$msg = GETPOST('sure', 'int') == 1 ? $vt->open($v, $by) : 'Tick the box to confirm.';
			break;
		case 'nonvoters':
			$msg = $vt->remindNonVoters($v, $by);
			break;
		case 'closetest':
			if (!empty($v->test) && $v->status == 'open') {
				$db->query("UPDATE ".$db->prefix()."onboarding_vote SET closes_at = '".$db->idate($now)."' WHERE rowid = ".$voteId);
				$msg = $vt->close($vt->get($voteId));
			}
			break;
		case 'repost':
			$msg = $v->status == 'closed' ? $vt->postResults($v, (string) $v->result) : '';
			break;
		case 'tie':
			$ties = $vt->tiesFor($v, $memberId);
			$qid = (int) GETPOST('qid', 'int');
			$msg = isset($ties[$qid]) ? $vt->decide($v, $qid, (int) GETPOST('oid', 'int'), $by) : 'You cannot break that tie.';
			break;
	}
	$flash = $msg !== '' ? '<p class="ok">'.$e($msg).'</p>' : '';
	$v = $vt->get($voteId);
	$facts = '<div class="facts"><strong>'.$e($v->label).'</strong>'.(!empty($v->test) ? ' (practice vote)' : '').'<br>Meeting: '.$e(OnboardingBooking::fmt($v->meeting)).'<br>'.$e(OnboardingVote::STATUS_LABELS[$v->status]).($v->status == 'open' ? ', closes '.$e(OnboardingBooking::fmt($v->closes)) : '').'</div>';
}

// Emails waiting to go out (notice, ballots) leave a few at a time while this page is open.
$vt->sendQueued(15);
$res = $db->query("SELECT COUNT(*) AS n FROM ".$db->prefix()."onboarding_vote_mail WHERE fk_vote = ".$voteId." AND sent_at IS NULL AND tries < 3");
$waiting = $res ? (int) $db->fetch_object($res)->n : 0;

$h = $facts.$flash;
if ($waiting) {
	$h .= '<p><strong>'.$waiting.' emails to members are still going out.</strong> Keep this page open to send them faster; otherwise they go within 15 minutes.</p>';
}
$editable = in_array($v->status, array('draft', 'noticed'));
$hasEmails = $v->notice_at ? ' The notice went out '.$e(dol_print_date($db->jdate($v->notice_at), 'dayhour')).'.' : '';

// Steps
$h .= '<h2>Next step</h2>';
if ($v->status == 'draft') {
	$why = $vt->noticeBlocked($v, $now);
	$h .= '<p>Check the questions and seats below, then send the notice. It goes to every full member who will be able to vote, with a button to run for a seat.</p>';
	$h .= $why !== '' ? '<p class="err">'.$e($why).'</p>' : onboarding_vote_form($hidden, 'notice', 'Send notice', '<label><input type="checkbox" name="sure" value="1" required> The questions and the pull request link are right</label>');
} elseif ($v->status == 'noticed') {
	$h .= '<p>'.trim($hasEmails).'</p>';
	$h .= onboarding_vote_form($hidden, 'discussion', empty($v->steps['discussion_sent']) ? 'Send reminder (discussion week)' : 'Send the reminder again', '', 'alt');
	$why = $vt->openBlocked($v, $now);
	$h .= '<p>At the meeting: after the discussion, update the pull request with the changes, add candidates nominated from the floor (below), then open voting.</p>';
	$h .= $why !== '' ? '<p class="err">'.$e($why).'</p>' : onboarding_vote_form($hidden, 'open', 'Open voting', '<label><input type="checkbox" name="sure" value="1" required> The pull request has the final text and the candidates are complete</label>');
} elseif ($v->status == 'open') {
	$h .= '<p><strong>'.((int) $v->ballots).'</strong> of '.((int) $v->voters).' voters have voted. Quorum is '.OnboardingVote::quorum((int) $v->voters).' ballots. Totals stay hidden until voting closes.</p>';
	$h .= onboarding_vote_form($hidden, 'nonvoters', empty($v->steps['nonvoters_sent']) ? 'Remind non-voters' : 'Remind non-voters again', '', 'alt');
	if (!empty($v->test)) {
		$h .= onboarding_vote_form($hidden, 'closetest', 'Close this practice vote now', '', 'warn');
	}
} else {
	$ties = $vt->tiesFor($v, $memberId);
	foreach ($ties as $qid => $row) {
		$h .= '<p class="err">Tie for '.$e($row['q']->title).'. You break it (bylaws 4.7):</p>';
		foreach ($row['tied'] as $o) {
			$h .= onboarding_vote_form($hidden + array('qid' => (string) $qid, 'oid' => (string) $o->rowid), 'tie', 'Elect '.$o->label);
		}
	}
	$h .= '<pre>'.$e($v->result).'</pre>';
	if ($v->posted_url) {
		$h .= '<p>Posted on GitHub: <a href="'.$e($v->posted_url).'">'.$e($v->posted_url).'</a></p>';
	} elseif ($v->post_error && empty($v->test)) {
		$h .= '<p class="err">Not posted on GitHub: '.$e($v->post_error).'</p>'.onboarding_vote_form($hidden, 'repost', 'Post on GitHub again');
	}
}

// Questions
$h .= '<h2>Bylaw questions</h2>';
$qs = $vt->questions($v);
foreach ($qs as $q) {
	if ($q->kind != 'bylaw') {
		continue;
	}
	if ($editable) {
		$h .= '<form method="post" action="">';
		foreach ($hidden as $k => $val) {
			$h .= '<input type="hidden" name="'.$e($k).'" value="'.$e($val).'">';
		}
		$h .= '<input type="hidden" name="do" value="saveq"><input type="hidden" name="qid" value="'.((int) $q->rowid).'"><fieldset><label>Question <input type="text" name="title" value="'.$e($q->title).'" size="50" required></label>';
		$h .= '<label>Link to the text <input type="url" name="url" value="'.$e($q->url).'" size="50"></label><label>Passes with <select name="threshold"><option value="twothirds"'.($q->threshold != 'majority' ? ' selected' : '').'>two-thirds (bylaw changes)</option><option value="majority"'.($q->threshold == 'majority' ? ' selected' : '').'>a majority</option></select></label>';
		$h .= '<button type="submit" class="alt">Save</button></fieldset></form>'.onboarding_vote_form($hidden + array('qid' => (string) $q->rowid), 'delq', 'Remove this question', '', 'warn');
	} else {
		$h .= '<p>'.$e($q->title).($q->url ? ' (<a href="'.$e($q->url).'">text</a>)' : '').'</p>';
	}
}
if ($editable) {
	$h .= '<form method="post" action="">';
	foreach ($hidden as $k => $val) {
		$h .= '<input type="hidden" name="'.$e($k).'" value="'.$e($val).'">';
	}
	$h .= '<input type="hidden" name="do" value="saveq"><fieldset><legend>Add a bylaw question</legend><label>Question <input type="text" name="title" size="50" required placeholder="Do you approve the change to section 2.1 (dues)?"></label>';
	$h .= '<label>Link to the text <input type="url" name="url" size="50" placeholder="https://github.com/..."></label><label>Passes with <select name="threshold"><option value="twothirds">two-thirds (bylaw changes)</option><option value="majority">a majority</option></select></label><button type="submit" class="alt">Add</button></fieldset></form>';
	$h .= '<form method="post" action="">';
	foreach ($hidden as $k => $val) {
		$h .= '<input type="hidden" name="'.$e($k).'" value="'.$e($val).'">';
	}
	$h .= '<input type="hidden" name="do" value="settings"><fieldset><legend>Discussion and results</legend><label>Pull request with the proposed changes <input type="url" name="pr_url" value="'.$e($v->pr_url).'" size="50" placeholder="https://github.com/ColumbiaGadgetWorks/bylaws/pull/12"></label>';
	$h .= '<p class="meta">Members discuss it there; when voting opens its latest commit is tagged as the final text, and the result is posted on it.</p>';
	$h .= '<label><input type="checkbox" name="ratify" value="1"'.(!empty($v->ratify) ? ' checked' : '').'> First bylaws: the board election counts only if the first question passes</label><button type="submit" class="alt">Save</button></fieldset></form>';
} elseif ($v->pr_url) {
	$h .= '<p>Pull request: <a href="'.$e($v->pr_url).'">'.$e($v->pr_url).'</a>'.($v->tag ? ', final text tagged '.$e($v->tag) : '').'</p>';
}

// Seats
$h .= '<h2>Board seats</h2>';
foreach ($qs as $q) {
	if ($q->kind != 'seat') {
		continue;
	}
	$cands = $vt->candidates($v, (int) $q->rowid);
	$h .= '<p><strong>'.$e($q->title).'</strong>: '.($cands ? '' : 'nobody yet').'</p>';
	foreach ($cands as $c) {
		$h .= '<div style="margin-left:1rem">'.$e($c->name).($c->source == 'meeting' ? ' (nominated at the meeting)' : '').($c->statement ? '<br><span class="meta">'.$e($c->statement).'</span>' : '');
		$h .= $editable ? onboarding_vote_form($hidden + array('cid' => (string) $c->rowid), 'delcand', 'Remove', '', 'warn') : '';
		$h .= '</div>';
	}
}
if ($editable) {
	$h .= '<form method="post" action="">';
	foreach ($hidden as $k => $val) {
		$h .= '<input type="hidden" name="'.$e($k).'" value="'.$e($val).'">';
	}
	$h .= '<input type="hidden" name="do" value="addcand"><fieldset><legend>Add a candidate (nominated at the meeting)</legend><p class="meta">They must be there and agree. Members can also run themselves with the button in their notice email.</p>';
	$h .= '<label>Their email on file <input type="email" name="email" required size="40"></label><label>Seat <select name="seat">';
	foreach ($qs as $q) {
		if ($q->kind == 'seat') {
			$h .= '<option value="'.((int) $q->rowid).'">'.$e($q->title).'</option>';
		}
	}
	$h .= '</select></label><label>Statement (optional) <textarea name="statement" maxlength="400"></textarea></label><label><input type="checkbox" name="adult" value="1" required> They are 18 or older</label><button type="submit" class="alt">Add candidate</button></fieldset></form>';
}

if ($v->status != 'draft') {
	$h .= '<h2>The notice, to post on Discord</h2><pre>'.$e($vt->noticeText($v, null)).'</pre>';
}
onboarding_vote_page($v->label, $h, 200, $waiting ? 3 : 0);
