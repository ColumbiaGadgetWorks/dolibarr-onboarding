<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Where training fees went: zone budgets, trainer credit, and the dues credits
 * waiting for approval. Every balance is the sum of the entries listed at the
 * foot of the page.
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

dol_include_once('/onboarding/class/training.class.php');

if (!isModEnabled('onboarding') || !$user->hasRight('onboarding', 'training', 'read')) {
	accessforbidden();
}
$canwrite = $user->hasRight('onboarding', 'training', 'write');
$canbudget = $user->hasRight('onboarding', 'budget', 'write');
$training = new OnboardingTraining($db);

$action = GETPOST('action', 'aZ09');
$credit = (int) GETPOST('credit', 'int');
if ($canwrite && in_array($action, array('approve', 'reject', 'refunded'))) {
	if ($action == 'approve') {
		$msg = $training->approveCredit($credit, $user);
	} elseif ($action == 'reject') {
		$msg = $training->rejectCredit($credit, $user);
	} else {
		$msg = $training->markRefunded($credit);
	}
	setEventMessages($msg, null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($canbudget && $action == 'zoneentry') {
	$zone = trim(GETPOST('zone', 'alphanohtml'));
	$amount = (float) price2num(GETPOST('amount', 'alphanohtml'));
	$note = trim(GETPOST('note', 'alphanohtml'));
	$kind = GETPOST('kind', 'aZ09');
	if (!in_array($kind, array('spend', 'adjust', 'allocation'))) {
		$kind = 'spend';
	}
	if ($note === '' && $kind == 'allocation') {
		$note = 'Budget added';
	}
	$from = $kind == 'allocation' ? trim(GETPOST('from', 'alphanohtml')) : '';
	if ($from !== '') {
		$note = trim($note.' (from '.$from.')');
	}
	$targets = $zone === '*' ? $training->zones() : array($zone);
	if ($zone === '' || $amount <= 0 || $note === '') {
		setEventMessages('Give an amount above zero and a note saying what it was for.', null, 'errors');
	} elseif ($zone === '*' && $kind != 'allocation') {
		setEventMessages('Only a budget can be added to every zone at once. Record spending against one zone.', null, 'errors');
	} else {
		// Spending takes money out, a budget puts it in, an adjustment goes either way.
		$signed = $kind == 'spend' ? -$amount : ($kind == 'adjust' && GETPOST('direction', 'aZ09') == 'out' ? -$amount : $amount);
		foreach ($targets as $z) {
			$training->post('zone', $z, $signed, $kind, 0, $note, 0, (int) $user->id);
		}
		$what = array('spend' => 'Spending', 'adjust' => 'Adjustment', 'allocation' => 'Budget');
		setEventMessages($what[$kind].' of '.price($amount).' recorded for '.(count($targets) > 1 ? count($targets).' zones' : $targets[0].'. Balance now '.price($training->balance('zone', $targets[0]))).'.', null);
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', 'Training accounts');
print load_fiche_titre('Training accounts', '', 'members');
print '<p class="opacitymedium">Each training fee is split: '.((int) round(OnboardingTraining::trainerShare() * 100)).'% to the trainer\'s credit, the rest to the zone\'s budget. Zone budgets also hold the money moved in from the organization accounts. These are allocations, not separate bank accounts. A trainer whose credit reaches '.price(OnboardingTraining::threshold()).' gets a month of dues refunded once approved here.</p>';

$p = $db->prefix();
$names = array();
$emails = array();
foreach ($training->members() as $m) {
	$names[(int) $m->rowid] = trim($m->firstname.' '.$m->lastname);
	$emails[(int) $m->rowid] = $m->email;
}
$who = function ($id) use ($names) {
	return isset($names[(int) $id]) && $names[(int) $id] !== '' ? $names[(int) $id] : 'Member '.((int) $id);
};
$button = function ($credit, $action, $label) {
	return '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="credit" value="'.((int) $credit).'"><button type="submit" name="action" value="'.$action.'" class="button smallpaddingimp">'.$label.'</button></form> ';
};

// Dues credits.
$resql = $db->query("SELECT * FROM ".$p."onboarding_credit WHERE entity = ".((int) $conf->entity)." AND status IN ('pending', 'approved') ORDER BY status DESC, datec ASC");
print '<h3>Dues credits</h3>';
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Trainer</td><td>Since</td><td class="right">Credit now</td><td class="right">Refund</td><td>Status</td><td></td></tr>';
$n = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$n++;
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($who($o->fk_adherent)).'</td>';
	print '<td>'.dol_print_date($db->jdate($o->datec), 'day').'</td>';
	print '<td class="right">'.price($training->balance('trainer', (int) $o->fk_adherent)).'</td><td class="right">'.price($o->amount).'</td><td>';
	if ($o->status == 'pending') {
		print 'Waiting for approval';
	} else {
		print 'Approved. Refund '.price($o->amount).' of '.($o->gb_transaction_id ? 'Givebutter transaction <strong>'.dol_escape_htmltag($o->gb_transaction_id).'</strong>' : 'their dues by hand (no Givebutter dues on record)').' in Givebutter.';
	}
	print '</td><td class="right">';
	if ($canwrite) {
		if ($o->status == 'pending') {
			print $button($o->rowid, 'approve', 'Approve').$button($o->rowid, 'reject', 'Reject');
		} else {
			print $button($o->rowid, 'refunded', 'Mark refunded');
		}
	}
	print '</td></tr>';
}
if (!$n) {
	print '<tr><td colspan="6"><span class="opacitymedium">Nothing waiting. A trainer appears here when their credit reaches '.price(OnboardingTraining::threshold()).'.</span></td></tr>';
}
print '</table></div><br>';

// Zone budgets.
$balances = $training->balances('zone');
$zones = array();
foreach ($training->zones() as $z) {
	$zones[$z] = isset($balances[$z]) ? $balances[$z] : 0.0;
}
print '<h3>Zone budgets</h3>';
if ($canbudget) {
	// Where a budget's money came from: a Dolibarr bank account, when the Banks module is on.
	$accounts = array();
	if (isModEnabled('banque')) {
		$res = $db->query("SELECT label FROM ".$p."bank_account WHERE entity IN (".getEntity('bank_account').") AND clos = 0 ORDER BY label");
		while ($res && ($o = $db->fetch_object($res))) {
			$accounts[] = $o->label;
		}
	}
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="marginbottomonly">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="zoneentry"><input type="hidden" name="kind" value="allocation">';
	print 'Add budget: <input type="text" name="amount" placeholder="Amount" size="6"> to <select name="zone"><option value="*">every zone ('.count($zones).')</option>';
	foreach (array_keys($zones) as $z) {
		print '<option value="'.dol_escape_htmltag($z).'">'.dol_escape_htmltag($z).'</option>';
	}
	print '</select> from ';
	if ($accounts) {
		print '<select name="from"><option value="">(no account)</option>';
		foreach ($accounts as $a) {
			print '<option value="'.dol_escape_htmltag($a).'">'.dol_escape_htmltag($a).'</option>';
		}
		print '</select>';
	} else {
		print '<input type="text" name="from" placeholder="Account" class="minwidth150">';
	}
	print ' <input type="text" name="note" placeholder="Note (optional)" class="minwidth200"> <button type="submit" class="button smallpaddingimp">Add budget</button></form>';
}
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Zone</td><td class="right">Balance</td><td></td></tr>';
foreach ($zones as $zone => $bal) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($zone).'</td><td class="right"><strong>'.price($bal).'</strong></td><td class="right">';
	if ($canbudget) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="zoneentry"><input type="hidden" name="zone" value="'.dol_escape_htmltag($zone).'">';
		print '<select name="kind"><option value="spend">Purchase</option><option value="adjust">Adjust</option></select> ';
		print '<select name="direction" title="For an adjustment only"><option value="out">out</option><option value="in">in</option></select> ';
		print '<input type="text" name="amount" placeholder="Amount" size="6"> <input type="text" name="note" placeholder="What was bought" class="minwidth200"> ';
		print '<button type="submit" class="button smallpaddingimp">Record</button></form>';
	}
	print '</td></tr>';
}
if (!$zones) {
	print '<tr><td colspan="3"><span class="opacitymedium">No zones yet. List them under Zones on the module setup page, or add a budget above.</span></td></tr>';
}
print '</table></div><br>';

// Trainer credit.
$trainers = $training->balances('trainer');
uksort($trainers, function ($a, $b) use ($who) {
	return strcasecmp($who($a), $who($b));
});
print '<h3>Trainer credit</h3>';
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Trainer</td><td>Email</td><td class="right">Credit</td><td class="right">Toward next month</td></tr>';
foreach ($trainers as $id => $bal) {
	$pct = min(100, (int) floor(100 * $bal / OnboardingTraining::threshold()));
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $id).'">'.dol_escape_htmltag($who($id)).'</a></td>';
	print '<td>'.dol_escape_htmltag(isset($emails[(int) $id]) ? $emails[(int) $id] : '').'</td><td class="right"><strong>'.price($bal).'</strong></td><td class="right">'.$pct.'%</td></tr>';
}
if (!$trainers) {
	print '<tr><td colspan="4"><span class="opacitymedium">No trainer has been credited yet.</span></td></tr>';
}
print '</table></div><br>';

// Entries.
$resql = $db->query("SELECT * FROM ".$p."onboarding_ledger WHERE entity = ".((int) $conf->entity)." ORDER BY rowid DESC LIMIT 100");
print '<h3>Latest entries</h3>';
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Date</td><td>Account</td><td>What</td><td>Note</td><td>Entered by</td><td class="right">Amount</td></tr>';
$kinds = array('training' => 'Training fee', 'dues_credit' => 'Dues refunded', 'spend' => 'Purchase', 'adjust' => 'Adjustment', 'allocation' => 'Budget added');
$users = array();
$res = $db->query("SELECT rowid, firstname, lastname, login FROM ".$p."user");
while ($res && ($o = $db->fetch_object($res))) {
	$users[(int) $o->rowid] = trim($o->firstname.' '.$o->lastname) !== '' ? trim($o->firstname.' '.$o->lastname) : $o->login;
}
$n = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$n++;
	$account = $o->account_type == 'zone' ? 'Zone: '.$o->account_key : 'Trainer: '.$who($o->account_key);
	print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($o->datec), 'dayhour').'</td><td>'.dol_escape_htmltag($account).'</td>';
	print '<td>'.dol_escape_htmltag(isset($kinds[$o->kind]) ? $kinds[$o->kind] : $o->kind).'</td><td>'.dol_escape_htmltag($o->note).'</td>';
	print '<td>'.($o->fk_user && isset($users[(int) $o->fk_user]) ? dol_escape_htmltag($users[(int) $o->fk_user]) : '<span class="opacitymedium">automatic</span>').'</td>';
	print '<td class="right">'.price($o->amount).'</td></tr>';
}
if (!$n) {
	print '<tr><td colspan="6"><span class="opacitymedium">No entries yet.</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
