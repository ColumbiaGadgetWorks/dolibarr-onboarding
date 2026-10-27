<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Reconcile bank accounts: upload a statement export (or let PayPal be read),
 * the matching entries are reconciled, and what does not match is listed here
 * for a person.
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

dol_include_once('/onboarding/class/reconcile.class.php');
dol_include_once('/onboarding/class/paypal.class.php');

if (!isModEnabled('onboarding') || !$user->hasRight('banque', 'lire')) {
	accessforbidden();
}
$canwrite = $user->hasRight('banque', 'consolidate');
$rec = new OnboardingReconcile($db);

$action = GETPOST('action', 'aZ09');
if ($canwrite && $action !== '') {
	$msgs = array();
	if ($action == 'upload') {
		$acc = (int) GETPOST('account', 'int');
		if (!empty($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
			foreach ($_FILES['files']['name'] as $i => $name) {
				if ((string) $name === '') {
					continue;
				}
				$msgs[] = $_FILES['files']['error'][$i] === UPLOAD_ERR_OK ? $rec->upload($acc, (string) $_FILES['files']['tmp_name'][$i], (string) $name, $user) : 'Error: '.$name.' did not upload (too large?).';
			}
		}
		if (!$msgs) {
			$msgs[] = 'Error: choose a file first.';
		}
	} elseif ($action == 'paypal') {
		$msgs[] = $rec->paypal($user);
	} elseif ($action == 'again') {
		$n = 0;
		foreach ($rec->accounts() as $a) {
			$n += $rec->match((int) $a->rowid, $user);
		}
		$msgs[] = $n.' more entries reconciled.';
	} elseif (in_array($action, array('add', 'ignore', 'match'), true)) {
		$msgs[] = $rec->decide((int) GETPOST('id', 'int'), $action, (int) GETPOST('line', 'int'), $user);
	} elseif ($action == 'force') {
		$msgs[] = $rec->forceLine((int) GETPOST('line', 'int'), $user);
	}
	foreach ($msgs as $m) {
		setEventMessages($m, null, strpos($m, 'Error') === 0 || strpos($m, 'failed') !== false ? 'errors' : 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].(GETPOST('mainmenu', 'aZ09') ? '?mainmenu='.GETPOST('mainmenu', 'aZ09') : ''));
	exit;
}

llxHeader('', 'Reconcile bank accounts');
print load_fiche_titre('Reconcile bank accounts', '', 'bank_account');
print '<p class="opacitymedium">Reconciling means checking every entry in Dolibarr against the bank\'s own record, so the books are known to be right. Upload the account\'s statement PDF, or its transactions as downloaded from online banking (CSV, Excel, OFX or QFX; see the hints below): each statement line is matched to the Dolibarr entry with the same amount, up to '.OnboardingReconcile::window().' days apart, and those entries are marked reconciled with the month (YYYYMM) as statement number. PayPal is read from PayPal itself, every day and with the button. Anything that does not match is listed below for a person to look at.</p>';

$accounts = $rec->accounts();
$ppAcc = $rec->paypalAccount();
$token = '<input type="hidden" name="token" value="'.newToken().'">';

print '<table class="noborder centpercent"><tr class="liste_titre"><td>Account</td><td class="right">Not reconciled</td><td class="right">Late</td><td>Oldest</td><td>Last statement</td><td>To check</td><td></td></tr>';
foreach ($accounts as $a) {
	$files = $rec->files((int) $a->rowid);
	$last = $files ? $files[0] : null;
	$check = $rec->openCount((int) $a->rowid) + count($rec->missing((int) $a->rowid));
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/compta/bank/bankentries_list.php?id='.((int) $a->rowid).'&search_conciliated=0">'.dol_escape_htmltag($a->label).'</a></td>';
	print '<td class="right">'.((int) $a->todo).'</td><td class="right">'.((int) $a->late ? '<span class="error">'.((int) $a->late).'</span>' : '0').'</td>';
	print '<td>'.($a->oldest ? dol_print_date($db->jdate($a->oldest), 'day') : '').'</td>';
	print '<td class="small">'.($last ? dol_escape_htmltag($last->original).($last->date_to ? '<br><span class="opacitymedium">'.dol_print_date($db->jdate($last->date_from), 'day').' to '.dol_print_date($db->jdate($last->date_to), 'day').'</span>' : '') : '<span class="opacitymedium">none yet</span>').'</td>';
	print '<td>'.($check ? '<a href="#acct-'.((int) $a->rowid).'"><span class="badge badge-warning">'.$check.'</span></a>' : '').'</td><td class="right">';
	if ($canwrite && (int) $a->rowid === $ppAcc && OnboardingPaypal::configured()) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">'.$token.'<input type="hidden" name="action" value="paypal"><button type="submit" class="butAction smallpaddingimp">Reconcile with PayPal now</button></form>';
	}
	print '</td></tr>';
}
if (!$accounts) {
	print '<tr><td colspan="7" class="opacitymedium">No open bank accounts with reconciliation on. Cash accounts are never reconciled.</td></tr>';
}
print '</table><br>';

if ($canwrite && $accounts) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data">'.$token.'<input type="hidden" name="action" value="upload">';
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>Upload a statement export</td></tr><tr class="oddeven"><td>';
	print 'Account <select name="account" class="minwidth200"><option value="0"></option>';
	foreach ($accounts as $a) {
		print '<option value="'.((int) $a->rowid).'">'.dol_escape_htmltag($a->label).'</option>';
	}
	print '</select> <input type="file" name="files[]" multiple accept=".pdf,.csv,.txt,.xlsx,.xls,.ofx,.qfx,.qbo"> <button type="submit" class="button smallpaddingimp">Upload and reconcile</button>';
	print ' <a class="butAction smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=again&token='.newToken().'">Match again</a>';
	print '<br><span class="opacitymedium small">Where to get it: <strong>Central Bank</strong>, the monthly statement PDF, or online banking, the checking account, Activity, set the dates, Export (CSV or Excel). <strong>US Bank card</strong>, Download transactions (CSV). <strong>Givebutter</strong>, Transactions, filter the dates, Export (and Payouts, Export). <strong>Venmo</strong>, Statements, the month, Download CSV. <strong>PayPal</strong> needs nothing, or its Activity download (CSV). Statement PDFs work when they are the bank\'s own (with text); a scanned paper statement does not. The same file uploaded twice is read once; months can overlap.</span>';
	print '</td></tr></table></form><br>';
}

// What a person needs to look at, per account.
foreach ($accounts as $a) {
	$aid = (int) $a->rowid;
	$open = $rec->open($aid);
	$missing = $rec->missing($aid);
	$files = $rec->files($aid);
	if (!$open && !$missing && !$files) {
		continue;
	}
	print '<div id="acct-'.$aid.'"></div><table class="noborder centpercent"><tr class="liste_titre"><td colspan="5">'.dol_escape_htmltag($a->label).'</td></tr>';
	if ($open) {
		print '<tr class="liste_titre"><td colspan="5">On the statement, not in Dolibarr ('.count($open).'): add it, say which entry it is, or leave it out</td></tr>';
		foreach ($open as $r) {
			print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($db->jdate($r->dateo), 'day').'</td><td class="right nowraponall">'.price($r->amount).'</td><td>'.dol_escape_htmltag($r->label).($r->kind == 'fee' ? ' <span class="opacitymedium">(the fee on a payment)</span>' : '').'</td><td class="nowraponall">';
			if ($canwrite) {
				print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">'.$token.'<input type="hidden" name="id" value="'.((int) $r->rowid).'"><input type="hidden" name="action" value="match"><select name="line" class="maxwidth300"><option value="">It is this entry...</option>';
				foreach ($rec->nearby($r) as $l) {
					print '<option value="'.((int) $l->rowid).'">'.dol_escape_htmltag(dol_print_date($db->jdate($l->dateo), 'day').' '.price($l->amount).' '.dol_trunc($l->label, 40)).'</option>';
				}
				print '</select> <button type="submit" class="button smallpaddingimp">Match</button></form>';
			}
			print '</td><td class="right nowraponall">';
			if ($canwrite) {
				print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">'.$token.'<input type="hidden" name="id" value="'.((int) $r->rowid).'"><button type="submit" name="action" value="add" class="button smallpaddingimp" title="Make a Dolibarr entry for it and reconcile it">Add to Dolibarr</button> <button type="submit" name="action" value="ignore" class="button smallpaddingimp" title="Not a real transaction, or already handled another way">Leave out</button></form>';
			}
			print '</td></tr>';
		}
	}
	if ($missing) {
		print '<tr class="liste_titre"><td colspan="5">In Dolibarr, not on any statement uploaded for those days ('.count($missing).'): fix or delete it, or reconcile it if you checked it</td></tr>';
		foreach ($missing as $l) {
			print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($db->jdate($l->dateo), 'day').'</td><td class="right nowraponall">'.price($l->amount).'</td><td>'.dol_escape_htmltag($l->label).'</td><td><a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.((int) $l->rowid).'">Open the entry</a></td><td class="right">';
			if ($canwrite) {
				print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">'.$token.'<input type="hidden" name="action" value="force"><input type="hidden" name="line" value="'.((int) $l->rowid).'"><button type="submit" class="button smallpaddingimp">Reconcile anyway</button></form>';
			}
			print '</td></tr>';
		}
	}
	if (!$open && !$missing) {
		print '<tr class="oddeven"><td colspan="5"><span class="ok">Nothing to check.</span></td></tr>';
	}
	// The balance each statement ends on, against Dolibarr's.
	foreach ($files as $f) {
		if ($f->end_balance === null || !$f->end_date) {
			continue;
		}
		$day = substr((string) $f->end_date, 0, 10);
		$ours = $rec->balanceAt($aid, $day);
		$ok = abs($ours - (float) $f->end_balance) < 0.005;
		print '<tr class="oddeven"><td colspan="5" class="small">'.dol_escape_htmltag($f->original).': the statement ends on '.dol_print_date($db->jdate($f->end_date), 'day').' at <strong>'.price($f->end_balance).'</strong>; Dolibarr has <strong>'.price($ours).'</strong> '.($ok ? '<span class="ok">&#10003; the same</span>' : '<span class="error">a difference of '.price(round($ours - (float) $f->end_balance, 2)).'</span> (usual causes: a transfer entered on one side only, a fee left out, something dated in the wrong month)').'</td></tr>';
	}
	print '</table><br>';
}

llxFooter();
$db->close();
