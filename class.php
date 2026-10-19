<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Group classes: several people trained at once, free. A board member or the
 * secretary creates one; attendees sign in on their phones with its code or QR
 * code (which work only around the class time), new people become non-members,
 * and the trainer confirms the list afterwards.
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

dol_include_once('/onboarding/class/booking.class.php');

if (!isModEnabled('onboarding') || !$user->hasRight('onboarding', 'training', 'read')) {
	accessforbidden();
}
$canfree = $user->hasRight('onboarding', 'training', 'free');
$canwrite = $canfree || $user->hasRight('onboarding', 'training', 'write');
$bk = new OnboardingBooking($db);
$action = GETPOST('action', 'aZ09');
$id = (int) GETPOST('id', 'int');

if ($canfree && $action == 'create') {
	$r = $bk->createClass(array(
		'tool' => (int) GETPOST('tool', 'int'), 'trainer' => (int) GETPOST('trainer', 'int'), 'day' => GETPOST('day', 'alphanohtml'), 'time' => GETPOST('time', 'alphanohtml'),
		'minutes' => (int) GETPOST('minutes', 'int'), 'seats' => (int) GETPOST('seats', 'int'), 'mode' => GETPOST('mode', 'aZ09'), 'note' => GETPOST('note', 'alphanohtml'),
	), $user);
	setEventMessages($r['ok'] ? 'Class created. Its code is '.$r['code'].'.' : $r['error'], null, $r['ok'] ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF'].($r['ok'] ? '?id='.$r['id'] : ''));
	exit;
}
$c = $id ? $bk->klass($id) : null;
if ($c && $canwrite && $action == 'add') {
	setEventMessages($bk->classAdd($c, array('email' => GETPOST('email', 'alphanohtml'), 'firstname' => GETPOST('firstname', 'alphanohtml'), 'lastname' => GETPOST('lastname', 'alphanohtml'), 'paper_waiver' => GETPOST('paper_waiver', 'int')), $user), null);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
	exit;
}
if ($c && $canwrite && $action == 'finish') {
	$ids = GETPOST('trained', 'array');
	setEventMessages($bk->classFinish($c, is_array($ids) ? $ids : array(), (int) $user->id), null);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
	exit;
}
if ($c && $canfree && ($action == 'cancel' || $action == 'reopen')) {
	$db->query("UPDATE ".$db->prefix()."onboarding_class SET status = '".($action == 'cancel' ? 'cancelled' : 'open')."' WHERE rowid = ".((int) $id));
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
	exit;
}

$hidden = '<input type="hidden" name="token" value="'.newToken().'">';
llxHeader('', 'Group classes');

if ($c) {
	$start = $db->jdate($c->session_start);
	print load_fiche_titre('Group class: '.dol_escape_htmltag($c->tool), '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'">All classes</a>', 'members');
	$url = $bk->classUrl($c);
	print '<div class="fichecenter"><div class="fichehalfleft"><table class="border centpercent">';
	print '<tr><td class="titlefield">When</td><td>'.dol_escape_htmltag(OnboardingBooking::fmt($start)).' ('.((int) $c->minutes).' minutes)</td></tr>';
	print '<tr><td>Trainer</td><td>'.dol_escape_htmltag(trim($c->trainer_first.' '.$c->trainer_last)).'</td></tr>';
	print '<tr><td>Seats</td><td>'.((int) $c->attendees).' of '.((int) $c->seats).' signed in</td></tr>';
	print '<tr><td>Marked trained</td><td>'.($c->mode == 'auto' ? 'As soon as they sign in' : 'When the trainer confirms the list after the class').'</td></tr>';
	print '<tr><td>Status</td><td>'.dol_escape_htmltag(array('open' => 'Open', 'done' => 'Done', 'cancelled' => 'Cancelled')[$c->status]).(OnboardingBooking::classOpen($c, OnboardingService::now()) ? ' <span class="badge badge-status4">code works now</span>' : '').'</td></tr>';
	print '<tr><td>Sign-in code</td><td><strong style="font-size:1.6em;letter-spacing:.08em">'.dol_escape_htmltag($c->code).'</strong></td></tr>';
	print '<tr><td>Sign-in page</td><td><a href="'.dol_escape_htmltag($url).'" target="_blank" rel="noopener">'.dol_escape_htmltag($url).'</a></td></tr>';
	if ($c->note) {
		print '<tr><td>Note</td><td>'.dol_escape_htmltag($c->note).'</td></tr>';
	}
	print '</table>';
	print '<p class="opacitymedium">The code works from '.((int) OnboardingBooking::num('TRAINING_CLASS_OPEN_BEFORE', 30)).' minutes before the start until '.((int) round(OnboardingBooking::num('TRAINING_CLASS_OPEN_AFTER', 180) / 60)).' hours after the end. Show the QR code on a screen or print this page.</p>';
	if ($canfree) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$id.'" class="inline-block">'.$hidden.($c->status == 'cancelled' ? '<button class="button" name="action" value="reopen">Reopen</button>' : '<button class="button" name="action" value="cancel">Cancel the class</button>').'</form>';
	}
	print '</div><div class="fichehalfright center">';
	$qr = '';
	try {
		require_once TCPDF_PATH.'tcpdf_barcodes_2d.php';
		$bar = new TCPDF2DBarcode($url, 'QRCODE,M');
		$qr = $bar->getBarcodeSVGcode(8, 8, 'black');
	} catch (Throwable $e) {
		$qr = '';
	}
	print $qr !== '' ? '<div style="display:inline-block;background:#fff;padding:12px">'.$qr.'</div><p><strong>'.dol_escape_htmltag($c->code).'</strong></p>' : '<p class="opacitymedium">QR code unavailable on this Dolibarr; use the link.</p>';
	print '</div></div><div class="clearboth"></div><br>';

	print '<h3>Who signed in</h3>';
	$rows = $bk->bookingsWhere("fk_class = ".((int) $id)." AND status IN ('confirmed', 'trained', 'cancelled')", 'b.rowid ASC');
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$id.'">'.$hidden.'<input type="hidden" name="action" value="finish">';
	print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre"><td></td><td>Name</td><td>Email</td><td>Signed in</td><td>Status</td></tr>';
	$open = 0;
	foreach ($rows as $b) {
		$box = $b->status == 'confirmed' ? '<input type="checkbox" name="trained[]" value="'.((int) $b->rowid).'" checked>' : '';
		$open += $b->status == 'confirmed' ? 1 : 0;
		print '<tr class="oddeven"><td>'.$box.'</td><td>'.dol_escape_htmltag(trim($b->firstname.' '.$b->lastname)).'</td><td>'.dol_escape_htmltag($b->email).'</td><td>'.dol_print_date($db->jdate($b->datec), 'dayhour').'</td>';
		print '<td>'.($b->status == 'cancelled' ? 'Left early' : dol_escape_htmltag(OnboardingBooking::STATUS_LABELS[$b->status])).'</td></tr>';
	}
	if (!$rows) {
		print '<tr><td colspan="5"><span class="opacitymedium">Nobody yet.</span></td></tr>';
	}
	print '</table></div>';
	if ($open && $canwrite) {
		print '<button class="button" type="submit">Mark the ticked people trained</button> <span class="opacitymedium">Unticked people are recorded as having left early. The trainer can do the same from their email.</span>';
	}
	print '</form>';
	if ($canwrite && $c->status == 'open') {
		print '<br><h3>Add someone by hand</h3><p class="opacitymedium">For someone without a phone, or from a paper sign-in sheet. Someone new needs a name. Without a waiver on file, tick "paper waiver signed" once they have signed a paper copy.</p>';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$id.'">'.$hidden.'<input type="hidden" name="action" value="add">';
		print '<input type="email" name="email" placeholder="Email" required class="minwidth200"> <input name="firstname" placeholder="First name"> <input name="lastname" placeholder="Last name"> ';
		print '<label><input type="checkbox" name="paper_waiver" value="1"> paper waiver signed</label> <button class="button smallpaddingimp" type="submit">Add</button></form>';
	}
	llxFooter();
	$db->close();
	exit;
}

print load_fiche_titre('Group classes', '', 'members');
print '<p class="opacitymedium">A free class for several people at once. Attendees sign themselves in on their phones with the class code, people new to Dolibarr become non-members, anyone without a waiver signs it on the spot, and the trainer confirms who was trained.</p>';
if ($canfree) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">'.$hidden.'<input type="hidden" name="action" value="create"><table class="border centpercent">';
	print '<tr><td class="titlefield">Tool</td><td><select name="tool">';
	foreach ($bk->tools(true) as $t) {
		print '<option value="'.((int) $t->rowid).'">'.dol_escape_htmltag($t->label).'</option>';
	}
	print '</select></td></tr><tr><td>Trainer</td><td><select name="trainer"><option value="0">Trainer…</option>';
	foreach ($bk->training->members() as $m) {
		if ((int) $m->statut == Adherent::STATUS_VALIDATED) {
			print '<option value="'.((int) $m->rowid).'">'.dol_escape_htmltag(trim($m->firstname.' '.$m->lastname)).'</option>';
		}
	}
	print '</select></td></tr>';
	print '<tr><td>When</td><td><input type="date" name="day" required> <input name="time" class="width50" value="17:00"> for <input name="minutes" class="width50" value="60"> minutes</td></tr>';
	print '<tr><td>Seats</td><td><input name="seats" class="width50" value="10"></td></tr>';
	print '<tr><td>Marked trained</td><td><label><input type="radio" name="mode" value="confirm" checked> when the trainer confirms the list afterwards (recommended)</label><br><label><input type="radio" name="mode" value="auto"> as soon as each person signs in</label></td></tr>';
	print '<tr><td>Note</td><td><input name="note" class="minwidth400" placeholder="Optional, e.g. City grant class"></td></tr>';
	print '</table><div class="center"><button class="button button-save" type="submit">Create the class</button></div></form><br>';
}
print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre"><td>When</td><td>Tool</td><td>Trainer</td><td>Code</td><td class="right">Signed in</td><td>Status</td></tr>';
$all = $bk->classesWhere('1 = 1');
foreach ($all as $k) {
	print '<tr class="oddeven"><td><a href="'.$_SERVER['PHP_SELF'].'?id='.((int) $k->rowid).'">'.dol_escape_htmltag(OnboardingBooking::fmt($db->jdate($k->session_start), 'D j M Y, g:i A')).'</a></td><td>'.dol_escape_htmltag($k->tool).'</td>';
	print '<td>'.dol_escape_htmltag(trim($k->trainer_first.' '.$k->trainer_last)).'</td><td>'.dol_escape_htmltag($k->code).'</td><td class="right">'.((int) $k->attendees).' / '.((int) $k->seats).'</td><td>'.dol_escape_htmltag($k->status).'</td></tr>';
}
if (!$all) {
	print '<tr><td colspan="6"><span class="opacitymedium">No classes yet.</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
