<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Trainings: bookings that need someone, upcoming sessions, free trainings,
 * and every training recorded (who was trained on what, by whom). Payments
 * that matched no booking are linked here.
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
$canwrite = $user->hasRight('onboarding', 'training', 'write');
$canfree = $user->hasRight('onboarding', 'training', 'free');
$bk = new OnboardingBooking($db);
$training = $bk->training;

$action = GETPOST('action', 'aZ09');
$bid = (int) GETPOST('booking', 'int');
$focus = $bid ? '?booking='.$bid : '';
if ($canwrite && $bid && in_array($action, array('bk_trained', 'bk_noshow', 'bk_newdate', 'bk_refunded', 'bk_cancel', 'bk_refund'), true)) {
	$b = $bk->booking($bid);
	$msg = 'That booking no longer exists.';
	$ok = false;
	if ($b) {
		if ($action == 'bk_trained' || $action == 'bk_noshow') {
			$r = $bk->answer($b, $action == 'bk_trained' ? 'trained' : 'noshow', '', (int) $user->id);
			$ok = $r['ok'];
			$msg = $ok ? $r['result'] : $r['error'];
		} elseif ($action == 'bk_newdate') {
			$ok = $bk->offerNewDate($b);
			$msg = $ok ? 'Sent '.$b->email.' a link to pick a new date.' : 'This booking cannot be moved.';
		} elseif ($action == 'bk_refund') {
			$r = $bk->requestRefund($b, 'Asked by '.$user->login);
			$ok = $r['ok'];
			$msg = $ok ? 'Marked as a refund to make; the treasurer was emailed.' : $r['error'];
		} elseif ($action == 'bk_refunded') {
			$ok = $bk->markRefunded($b);
			$msg = $ok ? 'Marked refunded; they were told.' : 'This booking is not waiting for a refund.';
		} elseif ($action == 'bk_cancel') {
			$ok = $bk->cancelBooking($b);
			$msg = $ok ? 'Cancelled, with no refund.' : 'This booking is already closed.';
		}
	}
	setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF'].$focus);
	exit;
}
if ($canfree && $action == 'free') {
	$in = array();
	foreach (array('email', 'firstname', 'lastname', 'day', 'reason') as $k) {
		$in[$k] = GETPOST($k, 'alphanohtml');
	}
	$in['tool'] = (int) GETPOST('tool', 'int');
	$in['trainer'] = (int) GETPOST('trainer', 'int');
	$r = $bk->free($in, $user);
	setEventMessages($r['ok'] ? 'Recorded as trained, with no fee. They were emailed.' : $r['error'], null, $r['ok'] ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
$importOut = array();
if ($canfree && ($action == 'importcheck' || $action == 'importgo')) {
	$importOut = $bk->importTrained(GETPOST('list', 'restricthtml'), (int) GETPOST('tool', 'int'), $user, $action == 'importcheck');
}
if ($action == 'linkbooking' && $canwrite) {
	setEventMessages($training->linkBooking((int) GETPOST('training', 'int'), (int) GETPOST('to', 'int')), null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'match' && $canwrite) {
	$n = $training->assignTrainer((int) GETPOST('training', 'int'), (int) GETPOST('trainer', 'int'));
	setEventMessages($n ? $n.' training'.($n > 1 ? 's' : '').' matched and credited.' : 'Pick a member to match.', null, $n ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'link' && $canwrite) {
	setEventMessages($training->linkRegistration((int) GETPOST('training', 'int'), (int) GETPOST('registration', 'int')), null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'void' && $canwrite) {
	$ok = $training->voidTraining((int) GETPOST('training', 'int'), $user);
	setEventMessages($ok ? 'Voided. Its shares were taken back out.' : 'That training was already voided.', null, $ok ? 'mesgs' : 'warnings');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$search = trim(GETPOST('search', 'alphanohtml'));

llxHeader('', 'Trainings');
print load_fiche_titre('Trainings', '', 'members');
if (!OnboardingTraining::enabled()) {
	print '<div class="warning">No training campaign is set, so training fees are not being recorded. Set the training campaign code on the module setup page.</div>';
}

$members = $training->members();
$names = array();
foreach ($members as $m) {
	$names[(int) $m->rowid] = trim($m->firstname.' '.$m->lastname).($m->email ? ' ('.$m->email.')' : '');
}

$p = $db->prefix();
$unpaid = $bk->bookingsWhere("status IN ('held', 'expired') AND gb_transaction_id IS NULL AND price > 0 AND datec > '".$db->idate(OnboardingService::now() - 30 * 86400)."'", 'b.rowid DESC');
$btn = function ($b, $act, $label, $title = '') {
	return '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="booking" value="'.((int) $b->rowid).'"><button class="button smallpaddingimp" name="action" value="'.$act.'" title="'.dol_escape_htmltag($title).'">'.$label.'</button></form> ';
};
$bookingRow = function ($b, $actions) use ($db, $btn, $canwrite) {
	$start = $db->jdate($b->session_start);
	$h = '<tr class="oddeven" id="b'.((int) $b->rowid).'"><td>'.dol_escape_htmltag($b->ref).'</td><td>'.($start ? dol_escape_htmltag(OnboardingBooking::fmt($start, 'D j M, g:i A')) : '').'</td>';
	$h .= '<td>'.dol_escape_htmltag(trim($b->firstname.' '.$b->lastname)).'<br><span class="opacitymedium small">'.dol_escape_htmltag($b->email).'</span></td>';
	$h .= '<td>'.dol_escape_htmltag($b->tool).($b->fk_class ? ' <span class="opacitymedium small">class</span>' : '').($b->source == 'spot' ? ' <span class="badge badge-status1">on the spot</span>' : '').'</td><td>'.dol_escape_htmltag(trim($b->trainer_first.' '.$b->trainer_last)).'</td>';
	$h .= '<td class="right">'.price($b->amount_paid ?: $b->price).' <span class="opacitymedium small">'.dol_escape_htmltag($b->price_type).'</span></td>';
	$h .= '<td>'.dol_escape_htmltag(OnboardingBooking::STATUS_LABELS[$b->status]).($b->no_shows ? ' <span class="opacitymedium small">'.((int) $b->no_shows).' no-show</span>' : '').($b->comment !== null && $b->comment !== '' ? '<br><span class="small">&ldquo;'.dol_escape_htmltag(dol_trunc($b->comment, 200)).'&rdquo;</span>' : '').'</td><td class="right nowraponall">';
	if ($canwrite) {
		foreach ($actions as $a) {
			$h .= $btn($b, $a[0], $a[1], isset($a[2]) ? $a[2] : '');
		}
	}
	return $h.'</td></tr>';
};
$bookingHead = '<tr class="liste_titre"><td>Booking</td><td>When</td><td>Trainee</td><td>Tool</td><td>Trainer</td><td class="right">Price</td><td>Status</td><td></td></tr>';
$settle = array(array('bk_trained', 'Mark trained'), array('bk_noshow', 'Mark no-show', 'The first no-show gets one free new date'), array('bk_newdate', 'Send a new date link'), array('bk_refund', 'Refund', 'Emails the treasurer to refund it in Givebutter'), array('bk_cancel', 'Cancel', 'No refund'));

if ($bid) {
	$one = $bk->booking($bid);
	if ($one) {
		print '<h3>Booking '.dol_escape_htmltag($one->ref).'</h3><div class="div-table-responsive"><table class="noborder centpercent">'.$bookingHead;
		$acts = $one->status == 'refund_requested' ? array(array('bk_refunded', 'Mark refunded')) : (in_array($one->status, array('paid', 'confirmed', 'needs_new_date', 'other'), true) ? $settle : array());
		print $bookingRow($one, $acts).'</table></div>';
		print '<p class="opacitymedium small">Paid '.($one->paid_at ? dol_print_date($db->jdate($one->paid_at), 'dayhour') : '-').($one->gb_transaction_id ? ', Givebutter '.dol_escape_htmltag($one->gb_transaction_id).' ('.price($one->amount_paid).' paid, '.price($one->amount_net).' after Givebutter\'s fee)' : '').'. Booked '.dol_print_date($db->jdate($one->datec), 'dayhour').'. <a href="'.$_SERVER['PHP_SELF'].'">All trainings</a></p><br>';
	}
}

$attention = $bk->bookingsWhere("status IN ('other', 'refund_requested') OR (status = 'needs_new_date') OR (status = 'confirmed' AND answer IS NULL AND fk_class IS NULL AND session_start < '".$db->idate(OnboardingService::now() - 2 * 86400)."')", 'b.session_start DESC');
if ($attention) {
	print '<h3>Bookings that need someone</h3>';
	print '<p class="opacitymedium">A comment from a trainer or trainee, a refund to make in Givebutter, someone who still has to pick a new date, or a session the trainer has not answered for.</p>';
	print '<div class="div-table-responsive"><table class="noborder centpercent">'.$bookingHead;
	foreach ($attention as $b) {
		print $bookingRow($b, $b->status == 'refund_requested' ? array(array('bk_refunded', 'Mark refunded', 'After refunding it in Givebutter')) : $settle);
	}
	print '</table></div><br>';
}

$spot = $bk->bookingsWhere("source = 'spot' AND (status IN ('requested', 'held') OR datec > '".$db->idate(OnboardingService::now() - 30 * 86400)."')", 'b.rowid DESC');
if ($spot) {
	print '<h3>On the spot</h3>';
	print '<p class="opacitymedium">Trainings a trainer started right away from the website, newest first, the last 30 days and any still open. The trainee says yes, signs and pays within '.((int) OnboardingBooking::SPOT_HOURS).' hours; a "no" goes to the secretary.</p>';
	print '<div class="div-table-responsive"><table class="noborder centpercent">'.$bookingHead;
	foreach ($spot as $b) {
		print $bookingRow($b, in_array($b->status, array('confirmed', 'paid', 'needs_new_date', 'other'), true) ? $settle : array());
	}
	print '</table></div><br>';
}

$coming = $bk->bookingsWhere("status IN ('paid', 'confirmed') AND session_start >= '".$db->idate(OnboardingService::now() - 86400)."'");
print '<h3>Upcoming</h3><div class="div-table-responsive"><table class="noborder centpercent">'.$bookingHead;
foreach ($coming as $b) {
	print $bookingRow($b, $db->jdate($b->session_start) < OnboardingService::now() ? $settle : array(array('bk_newdate', 'Move'), array('bk_refund', 'Refund')));
}
if (!$coming) {
	print '<tr><td colspan="8"><span class="opacitymedium">Nobody is booked.</span></td></tr>';
}
print '</table></div><br>';

if ($canfree) {
	$toolOpts = '';
	foreach ($bk->tools(true) as $t) {
		$toolOpts .= '<option value="'.((int) $t->rowid).'">'.dol_escape_htmltag($t->label).'</option>';
	}
	print '<h3>Mark trained (no fee)</h3>';
	print '<p class="opacitymedium">Board-approved trainings, a trainer\'s own sign-off, someone trained before training was required. No money moves. Someone not in Dolibarr becomes a non-member; type their name too.</p>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="free">';
	print '<input name="email" type="email" class="minwidth200" placeholder="Email" required> <input name="firstname" placeholder="First name"> <input name="lastname" placeholder="Last name"> ';
	print '<select name="tool">'.$toolOpts.'</select> <select name="trainer"><option value="0">Trainer…</option>';
	foreach ($training->members() as $m) {
		if ((int) $m->statut == Adherent::STATUS_VALIDATED) {
			print '<option value="'.((int) $m->rowid).'">'.dol_escape_htmltag(trim($m->firstname.' '.$m->lastname)).'</option>';
		}
	}
	print '</select> <input type="date" name="day" value="'.dol_escape_htmltag(OnboardingBooking::fmt(OnboardingService::now(), 'Y-m-d')).'"> <input name="reason" class="minwidth200" placeholder="Why no fee" required> ';
	print '<button class="button smallpaddingimp" type="submit">Mark trained</button></form>';

	print '<details'.($importOut ? ' open' : '').'><summary><strong>Import a list of people already trained</strong></summary>';
	print '<p class="opacitymedium">One per line: an email, or email, first name, last name (tab, comma or semicolon). Each is recorded as trained on the tool with no fee. Press Check first.</p>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><select name="tool">'.$toolOpts.'</select><br>';
	print '<textarea name="list" rows="6" class="quatrevingtpercent">'.dol_escape_htmltag(GETPOST('list', 'restricthtml')).'</textarea><br>';
	print '<button class="button smallpaddingimp" name="action" value="importcheck">Check</button> <button class="button smallpaddingimp" name="action" value="importgo">Import</button></form>';
	if ($importOut) {
		print '<div class="info">'.implode('<br>', array_map('dol_escape_htmltag', $importOut)).'</div>';
	}
	print '</details><br>';
}

// Payments that found no registration, and registrations still waiting.
$waiting = array();
$resql = $db->query("SELECT * FROM ".$p."onboarding_training_reg WHERE entity = ".((int) $conf->entity)." AND status = 'waiting' ORDER BY datec DESC LIMIT 200");
while ($resql && ($o = $db->fetch_object($resql))) {
	$waiting[] = $o;
}
$resql = $db->query("SELECT * FROM ".$p."onboarding_training WHERE entity = ".((int) $conf->entity)." AND status = 'unregistered' ORDER BY transacted_at DESC LIMIT 200");
$orphans = array();
while ($resql && ($o = $db->fetch_object($resql))) {
	$orphans[] = $o;
}
if ($orphans) {
	print '<h3>Paid without a booking</h3>';
	print '<p class="opacitymedium">These payments came in on the training campaign with no booking waiting for that email and price, usually because the payer typed a different email at Givebutter, or paid after their seat was let go. Pick the booking it belongs to. If there is none, void it and refund it in Givebutter.</p>';
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>Date</td><td>Paid by</td><td class="right">Amount</td><td>Givebutter id</td><td></td></tr>';
	foreach ($orphans as $o) {
		print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($o->transacted_at), 'day').'</td>';
		print '<td>'.dol_escape_htmltag(trim($o->firstname.' '.$o->lastname)).'<br><span class="opacitymedium small">'.dol_escape_htmltag($o->email).'</span></td>';
		print '<td class="right">'.price($o->amount).'</td><td>'.dol_escape_htmltag($o->gb_transaction_id).'</td><td class="right">';
		if ($canwrite) {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="training" value="'.((int) $o->rowid).'">';
			print '<select name="to" class="minwidth300"><option value="0">Booking…</option>';
			foreach ($unpaid as $w) {
				print '<option value="'.((int) $w->rowid).'">'.dol_escape_htmltag($w->ref.': '.trim($w->firstname.' '.$w->lastname).' <'.$w->email.'>, '.$w->tool.', '.price($w->price).', '.dol_print_date($db->jdate($w->datec), 'day')).'</option>';
			}
			print '</select> <button type="submit" name="action" value="linkbooking" class="button smallpaddingimp">Link</button> ';
			if ($waiting) {
				print '<select name="registration" class="minwidth200"><option value="0">or an old registration…</option>';
				foreach ($waiting as $w) {
					print '<option value="'.((int) $w->rowid).'">'.dol_escape_htmltag(trim($w->firstname.' '.$w->lastname).' <'.$w->email.'>, '.$w->tool.', '.price($w->fee)).'</option>';
				}
				print '</select> <button type="submit" name="action" value="link" class="button smallpaddingimp">Link</button> ';
			}
			print '<button type="submit" name="action" value="void" class="button smallpaddingimp">Void</button></form>';
		}
		print '</td></tr>';
	}
	print '</table></div><br>';
}

$resql = $db->query("SELECT * FROM ".$p."onboarding_training WHERE entity = ".((int) $conf->entity)." AND status = 'trainer_unmatched' ORDER BY transacted_at DESC LIMIT 200");
$unmatched = array();
while ($resql && ($o = $db->fetch_object($resql))) {
	$unmatched[] = $o;
}
if ($unmatched) {
	print '<h3>Trainer not matched</h3>';
	print '<p class="opacitymedium">The trainer name typed at checkout matched no member, or more than one. Pick the member; every other training typed with the same name is matched too, and future ones are matched automatically.</p>';
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>Date</td><td>Trainee</td><td>Tool</td><td>Trainer as typed</td><td class="right">Trainer\'s share</td><td></td></tr>';
	foreach ($unmatched as $o) {
		print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($o->transacted_at), 'day').'</td>';
		print '<td>'.dol_escape_htmltag(trim($o->firstname.' '.$o->lastname)).'<br><span class="opacitymedium small">'.dol_escape_htmltag($o->email).'</span></td>';
		print '<td>'.dol_escape_htmltag($o->tool).'</td><td><strong>'.dol_escape_htmltag($o->trainer_name !== '' ? $o->trainer_name : '(blank)').'</strong></td>';
		print '<td class="right">'.price($o->trainer_amount).'</td><td class="right">';
		if ($canwrite) {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="training" value="'.((int) $o->rowid).'">';
			print '<select name="trainer" class="minwidth200"><option value="0">Member…</option>';
			foreach ($names as $id => $label) {
				print '<option value="'.$id.'">'.dol_escape_htmltag($label).'</option>';
			}
			print '</select> <button type="submit" name="action" value="match" class="button smallpaddingimp">Match</button>';
			print '</form>';
		}
		print '</td></tr>';
	}
	print '</table></div><br>';
}

if ($waiting) {
	print '<h3>Registered, not paid yet</h3>';
	print '<p class="opacitymedium">Filled in on the website\'s payment page. Each is used by the next training payment from the same email, and expires after 14 days.</p>';
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>Registered</td><td>Trainee</td><td>Zone</td><td>Tool</td><td>Trainer</td><td class="right">Fee</td></tr>';
	foreach ($waiting as $w) {
		print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($w->datec), 'dayhour').'</td>';
		print '<td>'.dol_escape_htmltag(trim($w->firstname.' '.$w->lastname)).'<br><span class="opacitymedium small">'.dol_escape_htmltag($w->email).'</span></td>';
		print '<td>'.dol_escape_htmltag($w->zone).'</td><td>'.dol_escape_htmltag($w->tool).'</td><td>'.dol_escape_htmltag($w->trainer_name).'</td><td class="right">'.price($w->fee).'</td></tr>';
	}
	print '</table></div><br>';
}

print '<h3>Recorded trainings</h3><form method="GET" action="'.$_SERVER['PHP_SELF'].'"><input type="text" name="search" value="'.dol_escape_htmltag($search).'" placeholder="Email, name or tool" class="minwidth300"> <button type="submit" class="button smallpaddingimp">Search</button></form><br>';

$where = "t.entity = ".((int) $conf->entity);
if ($search !== '') {
	$like = $db->escape($db->escapeforlike(strtolower($search)));
	$where .= " AND (LOWER(t.email) LIKE '%".$like."%' OR LOWER(CONCAT(t.firstname, ' ', t.lastname)) LIKE '%".$like."%' OR LOWER(t.tool) LIKE '%".$like."%' OR LOWER(t.zone) LIKE '%".$like."%')";
}
$resql = $db->query("SELECT t.* FROM ".$p."onboarding_training AS t WHERE ".$where." ORDER BY t.transacted_at DESC LIMIT 500");
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Date</td><td>Trainee</td><td>Zone</td><td>Tool</td><td>Trainer</td><td class="right">Paid</td><td class="right">Zone share</td><td class="right">Trainer share</td><td>How</td><td></td></tr>';
$n = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$n++;
	$void = $o->status == 'ignored';
	print '<tr class="oddeven'.($void ? ' opacitymedium' : '').'">';
	print '<td>'.dol_print_date($db->jdate($o->transacted_at), 'day').'</td>';
	print '<td>'.dol_escape_htmltag(trim($o->firstname.' '.$o->lastname)).'<br><span class="opacitymedium small">'.dol_escape_htmltag($o->email).'</span></td>';
	print '<td>'.dol_escape_htmltag($o->zone).'</td><td>'.($o->status == 'unregistered' ? '<span class="opacitymedium">no registration</span>' : dol_escape_htmltag($o->tool)).($void ? ' <span class="badge badge-status8">voided</span>' : '').'</td>';
	print '<td>'.($o->fk_trainer && isset($names[(int) $o->fk_trainer]) ? dol_escape_htmltag(preg_replace('/ \(.*$/', '', $names[(int) $o->fk_trainer])) : '<span class="opacitymedium">'.dol_escape_htmltag($o->trainer_name).' (not matched)</span>').'</td>';
	print '<td class="right">'.price($o->amount).'</td><td class="right">'.price($o->zone_amount).'</td><td class="right">'.price($o->trainer_amount).'</td>';
	$how = array('paid' => 'Givebutter '.$o->gb_transaction_id, 'free' => 'No fee: '.$o->free_reason, 'import' => 'Imported', 'class' => 'Group class');
	print '<td class="small">'.dol_escape_htmltag(isset($how[$o->source]) ? $how[$o->source] : $o->gb_transaction_id).($o->status == 'no_show' ? ' <span class="badge badge-status8">no-show, fee kept</span>' : '').'</td><td class="right">';
	if ($canwrite && !$void) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="training" value="'.((int) $o->rowid).'">';
		print '<button type="submit" name="action" value="void" class="button smallpaddingimp" title="Refunded or entered by mistake: takes its shares back out">Void</button></form>';
	}
	print '</td></tr>';
}
if (!$n) {
	print '<tr><td colspan="10"><span class="opacitymedium">'.($search !== '' ? 'No training matches that search.' : 'No trainings yet. They appear here when someone pays on the training campaign.').'</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
