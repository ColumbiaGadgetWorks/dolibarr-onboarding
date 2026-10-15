<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Purchase requests filed on the website: who asked, how much, from which zone,
 * who approved it, and the treasurer's Mark paid, which records the bank line and
 * spends the zone's budget.
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

dol_include_once('/onboarding/class/purchase.class.php');

if (!isModEnabled('onboarding') || !$user->hasRight('onboarding', 'training', 'read')) {
	accessforbidden();
}
$canpay = $user->hasRight('onboarding', 'budget', 'write');
$purchase = new OnboardingPurchase($db);

// A receipt, shown to staff who may read purchase requests.
$file = GETPOST('file', 'alphanohtml');
if ($file !== '' && preg_match('/^(\d+)\.(\d)$/', $file, $m)) {
	$row = $purchase->fetch((int) $m[1]);
	$path = $row ? $purchase->filePath($row, (int) $m[2]) : '';
	if ($path === '') {
		http_response_code(404);
		print 'File not found';
		exit;
	}
	$types = array('pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'application/octet-stream');
	$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
	header('Content-Type: '.(isset($types[$ext]) ? $types[$ext] : 'application/octet-stream'));
	header('Content-Disposition: inline; filename="'.$row->ref.'-receipt-'.((int) $m[2]).'.'.$ext.'"');
	header('Cache-Control: private, no-store');
	header('X-Content-Type-Options: nosniff');
	readfile($path);
	exit;
}

$action = GETPOST('action', 'aZ09');
$id = (int) GETPOST('id', 'int');
if ($canpay && in_array($action, array('paid', 'cancel'), true)) {
	if ($action == 'paid') {
		$msg = $purchase->markPaid($id, (int) GETPOST('account', 'int'), $user);
	} else {
		$msg = $purchase->cancel($id, $user);
	}
	setEventMessages($msg, null, strpos($msg, 'Error') === 0 ? 'errors' : 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', 'Purchase requests');
print load_fiche_titre('Purchase requests', '', 'members');
print '<p class="opacitymedium">Filed on the website by members. The zone lead decides up to '.dol_escape_htmltag(OnboardingPurchase::money(OnboardingPurchase::leadLimit())).' while the zone has the money; anything else needs the treasurer and one other director. Approvers decide from the link in their email. Mark paid takes the money out of the account you choose and out of the zone\'s budget.</p>';

$accounts = array();
if (isModEnabled('banque')) {
	$res = $db->query("SELECT rowid, label FROM ".$db->prefix()."bank_account WHERE entity IN (".getEntity('bank_account').") AND clos = 0 ORDER BY label");
	while ($res && ($o = $db->fetch_object($res))) {
		$accounts[(int) $o->rowid] = $o->label;
	}
}

$labels = array('pending' => 'Waiting for approval', 'approved' => 'Approved, ready to pay', 'paid' => 'Paid', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Request</td><td>From</td><td>Zone</td><td>What</td><td>Receipts</td><td>Approvals</td><td>Status</td><td class="right">Amount</td><td></td></tr>';
$n = 0;
foreach ($purchase->all() as $r) {
	$n++;
	$m = $purchase->memberById((int) $r->fk_adherent);
	print '<tr class="oddeven"><td><strong>'.dol_escape_htmltag($r->ref).'</strong><br><span class="opacitymedium small">'.dol_print_date($db->jdate($r->date_submitted), 'dayhour').'</span></td>';
	print '<td>'.($m ? '<a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $m->rowid).'">'.dol_escape_htmltag(trim($m->firstname.' '.$m->lastname)).'</a>' : '').'</td>';
	print '<td>'.dol_escape_htmltag($r->zone).'</td><td>';
	print '<span class="opacitymedium small">'.dol_escape_htmltag(OnboardingPurchase::KINDS[$r->kind]).'</span><br>';
	if ($r->kind == 'purchase') {
		print '<a href="'.dol_escape_htmltag($r->link).'" target="_blank" rel="noopener noreferrer">'.dol_escape_htmltag(dol_trunc($r->link, 50)).'</a>';
	} else {
		print dol_escape_htmltag($r->vendor).' on '.dol_escape_htmltag($r->purchase_date).'<br><span class="opacitymedium small">Pay by '.dol_escape_htmltag(isset(OnboardingPurchase::PAY_METHODS[$r->pay_method]) ? OnboardingPurchase::PAY_METHODS[$r->pay_method] : $r->pay_method).' '.dol_escape_htmltag($r->pay_handle).'</span>';
	}
	print '<br>'.dol_escape_htmltag($r->description).'</td><td>';
	foreach ($purchase->files($r) as $i => $name) {
		print '<a href="'.$_SERVER['PHP_SELF'].'?file='.((int) $r->rowid).'.'.($i + 1).'" target="_blank">'.($i + 1).'</a> ';
	}
	print '</td><td>';
	$names = array();
	foreach ($purchase->approvalsOf($r) as $a) {
		$names[] = dol_escape_htmltag($a['name'].' ('.$a['role'].')');
	}
	print ($names ? implode('<br>', $names) : '<span class="opacitymedium">none yet</span>').'<br><span class="opacitymedium small">needs '.($r->need_board ? 'treasurer and one other director' : 'the zone lead').'</span>';
	print '</td><td>'.dol_escape_htmltag(isset($labels[$r->status]) ? $labels[$r->status] : $r->status);
	if ($r->status == 'paid') {
		print '<br><span class="opacitymedium small">from '.dol_escape_htmltag($r->paid_from).'</span>';
	} elseif ($r->decided_note) {
		print '<br><span class="opacitymedium small">'.dol_escape_htmltag($r->decided_note).'</span>';
	}
	print '</td><td class="right">'.price($r->amount).'</td><td class="right nowraponall">';
	if ($canpay && $r->status == 'approved') {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="paid"><input type="hidden" name="id" value="'.((int) $r->rowid).'">';
		print '<select name="account"><option value="0">Paid from...</option>';
		foreach ($accounts as $aid => $label) {
			print '<option value="'.$aid.'">'.dol_escape_htmltag($label).'</option>';
		}
		print '</select> <button type="submit" class="button smallpaddingimp">Mark paid</button></form> ';
	}
	if ($canpay && in_array($r->status, array('pending', 'approved'), true)) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="'.((int) $r->rowid).'"><button type="submit" class="button smallpaddingimp">Cancel</button></form>';
	}
	print '</td></tr>';
}
if (!$n) {
	print '<tr><td colspan="9"><span class="opacitymedium">No requests yet. Members file them on the website\'s /budget page.</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
