<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Member votes, for the board: where each vote stands, turnout while it is open
 * and the result once it closes. The vote itself is run by the Secretary and the
 * President from the buttons in their emails (public/vote.php), not from here.
 * The voter roll is not shown: it would reveal who holds a hardship waiver.
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

dol_include_once('/onboarding/class/vote.class.php');

if (!isModEnabled('onboarding') || !$user->hasRight('onboarding', 'vote', 'read')) {
	accessforbidden();
}
$vt = new OnboardingVote($db);
$action = GETPOST('action', 'aZ09');
$now = OnboardingService::now();

if ($action == 'panel') {
	$v = $vt->get((int) GETPOST('id', 'int'));
	if ($v) {
		$n = $vt->tellOfficers($v, 'done', array('text' => 'Here is the link to the vote panel, as asked from Dolibarr by '.$user->getFullName($langs).'.'));
		setEventMessages($n ? 'Emailed the panel link to '.$n.' officer(s).' : 'Nobody holds the secretary or president tag.', null, $n ? 'mesgs' : 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'setup') {
	$v = null;
	$year = (int) OnboardingBooking::fmt($now, 'Y');
	$meeting = OnboardingVote::annualMeeting($year);
	if ($now > $meeting) {
		$meeting = OnboardingVote::annualMeeting($year + 1);
	}
	foreach ($vt->all() as $o) {
		$v = empty($o->test) && $o->meeting == $meeting ? $o : $v;
	}
	if (!$v) {
		$v = $vt->create($meeting, 'Annual meeting '.OnboardingBooking::fmt($meeting, 'Y'));
	}
	setEventMessages($v ? 'The vote for '.OnboardingBooking::fmt($meeting).' is set up; the Secretary and President have its link.' : 'Could not set it up.', null, $v ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'practice') {
	$v = $vt->create($now + 3600, 'Practice vote '.dol_print_date($now, 'day'), true);
	setEventMessages($v ? 'Practice vote created. The Secretary and President have its link; the board members are its voters, and the date rules are skipped.' : 'Could not create it.', null, $v ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', 'Member votes');
$token = newToken();
print load_fiche_titre('Member votes', '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=setup&token='.$token.'">Set up the next annual meeting\'s vote now</a> <a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?action=practice&token='.$token.'">Start a practice vote</a>', 'members');
print '<p class="opacitymedium">The vote for each annual meeting (second Thursday of April) is set up by itself 60 days before. The Secretary and the President run it from the buttons in their emails; nobody needs to log in. A practice vote goes to the board members only, skips the date rules, and is not posted on GitHub.</p>';

$officers = $vt->officers();
foreach (array('secretary' => 'Secretary', 'president' => 'President') as $role => $label) {
	$names = array_map(function ($m) {
		return trim($m->firstname.' '.$m->lastname).' <'.$m->email.'>';
	}, $officers[$role]);
	print '<p>'.$label.': '.($names ? dol_escape_htmltag(implode(', ', $names)) : '<span class="error">nobody holds the '.$role.' tag</span>').'</p>';
}
if (!OnboardingVote::fullTypes()) {
	print '<div class="error">No member types are set as full members, so nobody could vote. Set them on the Member onboarding setup page.</div>';
}
if (getDolGlobalString('ONBOARDING_VOTE_GITHUB_TOKEN') === '') {
	print '<div class="warning">No GitHub token is set, so the final text will not be tagged and results will not be posted on GitHub by themselves (Member onboarding setup, Member votes).</div>';
}

foreach ($vt->all() as $v) {
	print '<h3>'.dol_escape_htmltag($v->label).(!empty($v->test) ? ' (practice)' : '').'</h3><table class="border centpercent">';
	print '<tr><td class="titlefield">Meeting</td><td>'.dol_escape_htmltag(OnboardingBooking::fmt($v->meeting)).'</td></tr>';
	print '<tr><td>Status</td><td>'.dol_escape_htmltag(OnboardingVote::STATUS_LABELS[$v->status]).($v->notice_at ? ' &middot; notice sent '.dol_print_date($db->jdate($v->notice_at), 'dayhour') : '').'</td></tr>';
	if ($v->opened) {
		print '<tr><td>Voting</td><td>'.dol_escape_htmltag(OnboardingBooking::fmt($v->opened)).' to '.dol_escape_htmltag(OnboardingBooking::fmt($v->closes)).' &middot; '.((int) $v->ballots).' of '.((int) $v->voters).' voted (quorum '.OnboardingVote::quorum((int) $v->voters).')</td></tr>';
	}
	if ($v->pr_url) {
		print '<tr><td>Pull request</td><td><a href="'.dol_escape_htmltag($v->pr_url).'" target="_blank" rel="noopener">'.dol_escape_htmltag($v->pr_url).'</a>'.($v->tag ? ' &middot; tagged '.dol_escape_htmltag($v->tag) : '').'</td></tr>';
	}
	$qs = array();
	foreach ($vt->questions($v) as $q) {
		$names = $q->kind == 'seat' ? array_map(function ($c) {
			return $c->name;
		}, $vt->candidates($v, (int) $q->rowid)) : array();
		$qs[] = dol_escape_htmltag($q->title).($q->kind == 'seat' ? ': '.($names ? dol_escape_htmltag(implode(', ', $names)) : '<span class="opacitymedium">no candidate yet</span>') : '');
	}
	print '<tr><td>On the ballot</td><td>'.implode('<br>', $qs).'</td></tr>';
	if ($v->status == 'closed') {
		print '<tr><td>Result</td><td><pre style="white-space:pre-wrap">'.dol_escape_htmltag($v->result, 0, 1).'</pre>'.($v->posted_url ? 'Posted: <a href="'.dol_escape_htmltag($v->posted_url).'">'.dol_escape_htmltag($v->posted_url).'</a>' : ($v->post_error ? '<span class="error">Not posted: '.dol_escape_htmltag($v->post_error).'</span>' : '')).'</td></tr>';
	}
	print '</table><div class="tabsAction"><a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=panel&id='.((int) $v->rowid).'&token='.$token.'">Email the panel link to the Secretary and President</a></div>';
}
if (!$vt->all()) {
	print '<p class="opacitymedium">No votes yet.</p>';
}

llxFooter();
$db->close();
