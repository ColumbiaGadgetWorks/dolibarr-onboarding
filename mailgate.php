<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Email approval: emails waiting for the approver, the kinds always allowed,
 * and what was decided lately. Admins only.
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

dol_include_once('/onboarding/class/mailgate.class.php');

if (!isModEnabled('onboarding') || empty($user->admin)) {
	accessforbidden();
}
$gate = new OnboardingMailGate($db);
$by = $user->login;

$action = GETPOST('action', 'aZ09');
$id = (int) GETPOST('id', 'int');
if (in_array($action, array('approve', 'always', 'reject'), true) && $id > 0) {
	setEventMessages($gate->decide($id, $action, $by), null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'retry' && $id > 0) {
	$row = $gate->fetch($id);
	$ok = $row && $row->status == 'failed' && $gate->release($row, $by, true);
	setEventMessages($ok ? 'Sent.' : 'Sending failed again: '.($row ? (string) $gate->fetch($id)->error : ''), null, $ok ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if (($action == 'allow' || $action == 'disallow') && GETPOST('kind', 'restricthtml') !== '') {
	$kind = (string) GETPOST('kind', 'restricthtml');
	$gate->setAllowed($kind, $action == 'allow', $by);
	setEventMessages($action == 'allow' ? 'Always allowed from now on. Emails of this kind already waiting still need a decision.' : 'This kind is held for approval again.', null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$buttonsFor = function ($id, $buttons, $kind = '') {
	$out = '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
	$out .= $id ? '<input type="hidden" name="id" value="'.((int) $id).'">' : '<input type="hidden" name="kind" value="'.dol_escape_htmltag($kind).'">';
	foreach ($buttons as $value => $label) {
		$out .= '<button type="submit" name="action" value="'.$value.'" class="button smallpaddingimp">'.dol_escape_htmltag($label).'</button> ';
	}
	return $out.'</form>';
};

llxHeader('', 'Email approval');
print load_fiche_titre('Email approval', '', 'email');

$approver = OnboardingMailGate::approver();
if ($approver === '') {
	print '<div class="warning">Email approval is off: every email goes out straight away. To turn it on, set <strong>Approver</strong> on the <a href="'.dol_buildpath('/onboarding/admin/setup.php', 1).'">Member onboarding setup page</a>.</div>';
} else {
	print '<p class="opacitymedium">Every email Dolibarr sends to anyone except '.dol_escape_htmltag(implode(', ', OnboardingMailGate::exempt())).' waits here until it is approved. '.dol_escape_htmltag($approver).' gets a copy of each with Approve and Reject buttons. A mass emailing asks once for all its recipients.</p>';
}

// One email in full.
$view = (int) GETPOST('view', 'int');
if ($view > 0 && ($row = $gate->fetch($view))) {
	print '<div class="fichecenter"><table class="border centpercent tableforfield">';
	foreach (array('Status' => $row->status, 'To' => $row->mail_to, 'Cc' => $row->mail_cc, 'Bcc' => $row->mail_bcc, 'From' => $row->mail_from, 'Reply to' => $row->reply_to, 'Subject' => $row->subject, 'Kind' => $row->kind, 'Error' => $row->error) as $k => $v) {
		if ((string) $v !== '') {
			print '<tr><td class="titlefield">'.$k.'</td><td>'.dol_escape_htmltag((string) $v).'</td></tr>';
		}
	}
	$names = array();
	foreach ($gate->files($row) as $f) {
		$names[] = $f['name'];
	}
	if ($names) {
		print '<tr><td>Attached</td><td>'.dol_escape_htmltag(implode(', ', $names)).'</td></tr>';
	}
	print '</table></div><br>';
	print (int) $row->ishtml ? '<iframe sandbox srcdoc="'.dol_escape_htmltag((string) $row->body, 0, 1).'" style="width:100%;min-height:24rem;border:1px solid #ccc;background:#fff"></iframe>' : '<div style="white-space:pre-wrap;border:1px solid #ccc;padding:1em;background:#fafafa">'.dol_escape_htmltag((string) $row->body, 0, 1).'</div>';
	if ($row->status == 'pending') {
		$mailing = strpos((string) $row->kind, 'emailing-') === 0;
		print '<br>'.$buttonsFor($row->rowid, $mailing ? array('approve' => 'Approve the mailing', 'reject' => 'Reject') : array('approve' => 'Approve', 'always' => 'Approve, and always allow this kind', 'reject' => 'Reject'));
	}
	print '<p><a href="'.$_SERVER['PHP_SELF'].'">Back to the list</a></p>';
	llxFooter();
	$db->close();
	exit;
}

$p = $db->prefix();
$heldList = function ($where, $limit) use ($db, $p, $conf) {
	$out = array();
	$res = $db->query("SELECT rowid, kind, status, mail_to, subject, datec, decided_by, decided_at, error FROM ".$p."onboarding_mail_hold WHERE entity = ".((int) $conf->entity)." AND ".$where." ORDER BY rowid DESC LIMIT ".((int) $limit));
	while ($res && ($obj = $db->fetch_object($res))) {
		$out[] = $obj;
	}
	return $out;
};

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Waiting since</td><td>To</td><td>Subject</td><td>Kind</td><td></td></tr>';
$rows = $heldList("status IN ('pending', 'failed')", 500);
foreach ($rows as $o) {
	$mailing = strpos((string) $o->kind, 'emailing-') === 0;
	print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($db->jdate($o->datec), 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag(dol_trunc($o->mail_to, 60)).'</td>';
	print '<td><a href="'.$_SERVER['PHP_SELF'].'?view='.((int) $o->rowid).'">'.dol_escape_htmltag($o->subject).'</a>'.($o->status == 'failed' ? '<br><span class="badge badge-status8">sending failed</span> <span class="small">'.dol_escape_htmltag((string) $o->error).'</span>' : '').'</td>';
	print '<td class="small opacitymedium">'.dol_escape_htmltag($o->kind).'</td><td class="right nowraponall">';
	if ($o->status == 'failed') {
		print $buttonsFor($o->rowid, array('retry' => 'Try again'));
	} else {
		print $buttonsFor($o->rowid, $mailing ? array('approve' => 'Approve the mailing', 'reject' => 'Reject') : array('approve' => 'Approve', 'always' => 'Always allow', 'reject' => 'Reject'));
	}
	print '</td></tr>';
}
if (!$rows) {
	print '<tr><td colspan="5"><span class="opacitymedium">Nothing waiting.</span></td></tr>';
}
print '</table></div><br>';

print load_fiche_titre('Kinds of email', '', '');
print '<p class="opacitymedium">Each kind is where an email comes from in Dolibarr plus the fixed start of its subject. Always allowed kinds are sent without asking.</p>';
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Kind</td><td>Example</td><td>Always allowed</td><td></td></tr>';
$kinds = $gate->kinds();
foreach ($kinds as $k) {
	print '<tr class="oddeven"><td class="small">'.dol_escape_htmltag($k->kind).'</td><td>'.dol_escape_htmltag((string) $k->example).'</td>';
	print '<td>'.((int) $k->allowed ? 'yes'.($k->allowed_by ? ' <span class="opacitymedium small">('.dol_escape_htmltag($k->allowed_by).($k->allowed_at ? ', '.dol_print_date($db->jdate($k->allowed_at), 'day') : '').')</span>' : '') : 'no').'</td>';
	print '<td class="right">'.$buttonsFor(0, (int) $k->allowed ? array('disallow' => 'Hold again') : array('allow' => 'Always allow'), $k->kind).'</td></tr>';
}
if (!$kinds) {
	print '<tr><td colspan="4"><span class="opacitymedium">No email has been held yet.</span></td></tr>';
}
print '</table></div><br>';

print load_fiche_titre('Decided lately', '', '');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Decided</td><td>By</td><td>Result</td><td>To</td><td>Subject</td></tr>';
$rows = $heldList("status IN ('sent', 'rejected', 'sending')", 50);
foreach ($rows as $o) {
	print '<tr class="oddeven"><td class="nowraponall">'.($o->decided_at ? dol_print_date($db->jdate($o->decided_at), 'dayhour') : '').'</td><td>'.dol_escape_htmltag((string) $o->decided_by).'</td>';
	print '<td>'.dol_escape_htmltag($o->status).'</td><td>'.dol_escape_htmltag(dol_trunc($o->mail_to, 60)).'</td>';
	print '<td><a href="'.$_SERVER['PHP_SELF'].'?view='.((int) $o->rowid).'">'.dol_escape_htmltag($o->subject).'</a></td></tr>';
}
if (!$rows) {
	print '<tr><td colspan="5"><span class="opacitymedium">Nothing yet.</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
