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
	} elseif ($action == 'decide') {
		// One line's own button ("add:12"), or the ticked lines with a bulk button.
		$one = GETPOST('one', 'alphanohtml');
		$picks = (array) GETPOST('match', 'array');
		if (preg_match('/^(add|ignore|match):(\d+)$/', $one, $m)) {
			$msgs[] = $rec->decide((int) $m[2], $m[1], isset($picks[$m[2]]) ? (int) $picks[$m[2]] : 0, $user);
		} else {
			$what = GETPOST('bulk', 'aZ09');
			$n = 0;
			foreach ((array) GETPOST('ids', 'array') as $id) {
				if (in_array($what, array('add', 'ignore'), true) && strpos($rec->decide((int) $id, $what, 0, $user), 'Error') !== 0) {
					$n++;
				}
			}
			$msgs[] = $n ? ($what == 'add' ? $n.' lines added to Dolibarr and reconciled. Give them categories (or link them) from the account\'s entries.' : $n.' lines left out.') : 'Error: tick some lines first.';
		}
	} elseif ($action == 'force' && GETPOST('twin', 'int')) {
		$msgs[] = $rec->fixTwin((int) GETPOST('twin', 'int'), $user);
	} elseif ($action == 'force') {
		$n = 0;
		foreach ((array) GETPOST('lines', 'array') as $l) {
			$rec->forceLine((int) $l, $user);
			$n++;
		}
		$one = (int) GETPOST('line', 'int');
		if ($one) {
			$rec->forceLine($one, $user);
			$n++;
		}
		$msgs[] = $n ? $n.' entries reconciled.' : 'Error: tick some entries first.';
	} elseif ($action == 'undo') {
		$msgs[] = $rec->undo((int) GETPOST('file', 'int'));
	} elseif ($action == 'unrule') {
		$msgs[] = $rec->undoRule((int) GETPOST('id', 'int'), $user);
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
print '<script>function onbTick(box, name, form){document.querySelectorAll(\'input[name="\'+name+\'"][form="\'+form+\'"]\').forEach(function(c){c.checked=box.checked;});}</script>';
foreach ($accounts as $a) {
	$aid = (int) $a->rowid;
	$open = $rec->open($aid);
	$missing = $rec->missing($aid);
	$files = $rec->files($aid);
	$aside = $rec->setAside($aid);
	if (!$open && !$missing && !$files) {
		continue;
	}
	// A form cannot hold table rows, so the rows' fields name their form.
	$fd = 'dec-'.$aid;
	$ff = 'frc-'.$aid;
	if ($canwrite) {
		print '<form id="'.$fd.'" method="POST" action="'.$_SERVER['PHP_SELF'].'#acct-'.$aid.'">'.$token.'<input type="hidden" name="action" value="decide"></form>';
		print '<form id="'.$ff.'" method="POST" action="'.$_SERVER['PHP_SELF'].'#acct-'.$aid.'">'.$token.'<input type="hidden" name="action" value="force"></form>';
	}
	print '<div id="acct-'.$aid.'"></div><table class="noborder centpercent"><tr class="liste_titre"><td colspan="6">'.dol_escape_htmltag($a->label).'</td></tr>';
	unset($aside['norule']);
	if ($aside) {
		$start = $rec->booksStart($aid);
		$said = array();
		if (!empty($aside['before'])) {
			$said[] = $aside['before'].' from before this account\'s first entry in Dolibarr'.($start ? ' ('.dol_print_date($db->jdate($start), 'day').')' : '');
		}
		if (!empty($aside['duplicate'])) {
			$said[] = $aside['duplicate'].' that another upload already had';
		}
		if (!empty($aside['already'])) {
			$said[] = $aside['already'].' matched to entries reconciled earlier by hand';
		}
		if (!empty($aside['lump'])) {
			$said[] = $aside['lump'].' matched to entries typed as one total for several of them';
		}
		if (!empty($aside['rule'])) {
			$said[] = $aside['rule'].' entered by the rules (listed below)';
		}
		print '<tr class="oddeven"><td colspan="6" class="opacitymedium small">Set aside without asking: '.dol_escape_htmltag(implode('; ', $said)).'.</td></tr>';
	}
	if ($open) {
		$key = 'ids-'.$aid;
		print '<tr class="liste_titre"><td colspan="6">On the statement, not in Dolibarr ('.count($open).')</td></tr>';
		if ($canwrite) {
			print '<tr class="oddeven"><td colspan="6"><label><input type="checkbox" onclick="onbTick(this, \'ids[]\', \''.$fd.'\')"> all</label> &nbsp; ';
			print '<button type="submit" form="'.$fd.'" name="bulk" value="add" class="button smallpaddingimp" onclick="return confirm(\'Make a Dolibarr entry for each ticked line and reconcile it?\')">Add ticked to Dolibarr</button> ';
			print '<button type="submit" form="'.$fd.'" name="bulk" value="ignore" class="button smallpaddingimp">Leave ticked out</button>';
			print ' <span class="opacitymedium small">Add when the line is real and nobody entered it (a fee, a service charge, a sale). Leave out when it is not money of this account.</span></td></tr>';
		}
		foreach ($open as $r) {
			print '<tr class="oddeven"><td class="nowraponall">'.($canwrite ? '<input type="checkbox" form="'.$fd.'" name="ids[]" value="'.((int) $r->rowid).'"> ' : '').dol_print_date($db->jdate($r->dateo), 'day').'</td><td class="right nowraponall">'.price($r->amount).'</td>';
			print '<td>'.dol_escape_htmltag($r->label).($r->kind == 'fee' ? ' <span class="opacitymedium">(the fee on a payment)</span>' : '').'<br><span class="opacitymedium small">'.dol_escape_htmltag($rec->why($r)).'</span></td><td class="nowraponall">';
			if ($canwrite) {
				print '<select form="'.$fd.'" name="match['.((int) $r->rowid).']" class="maxwidth300"><option value="">It is this entry...</option>';
				foreach ($rec->nearby($r) as $l) {
					print '<option value="'.((int) $l->rowid).'">'.dol_escape_htmltag(dol_print_date($db->jdate($l->dateo), 'day').' '.price($l->amount).' '.dol_trunc($l->label, 40)).'</option>';
				}
				print '</select> <button type="submit" form="'.$fd.'" name="one" value="match:'.((int) $r->rowid).'" class="button smallpaddingimp">Match</button>';
			}
			print '</td><td class="right nowraponall" colspan="2">';
			if ($canwrite) {
				print '<button type="submit" form="'.$fd.'" name="one" value="add:'.((int) $r->rowid).'" class="button smallpaddingimp" title="Make a Dolibarr entry for it and reconcile it">Add</button> <button type="submit" form="'.$fd.'" name="one" value="ignore:'.((int) $r->rowid).'" class="button smallpaddingimp" title="Not a real transaction of this account, or already handled another way">Leave out</button>';
			}
			print '</td></tr>';
		}
	}
	if ($missing) {
		print '<tr class="liste_titre"><td colspan="6">In Dolibarr, not on any statement uploaded for those days ('.count($missing).'): fix or delete it, or reconcile it if you checked it</td></tr>';
		if ($canwrite) {
			print '<tr class="oddeven"><td colspan="6"><label><input type="checkbox" onclick="onbTick(this, \'lines[]\', \''.$ff.'\')"> all</label> &nbsp; <button type="submit" form="'.$ff.'" class="button smallpaddingimp">Reconcile ticked anyway</button></td></tr>';
		}
		foreach ($missing as $l) {
			// Say what it probably is: a second copy of a matched entry, or half of a transfer.
			$twin = $rec->twin($l);
			$other = $twin ? '' : $rec->transferOf($l);
			$hint = $twin ? 'Looks like a second copy of "'.$twin->label.'" ('.dol_print_date($db->jdate($twin->dateo), 'day').'), which the statement matched.' : ($other !== '' ? 'Half of a transfer with '.$other.': reconciled by itself once that side is, so upload '.$other.'\'s statement.' : '');
			print '<tr class="oddeven"><td class="nowraponall">'.($canwrite ? '<input type="checkbox" form="'.$ff.'" name="lines[]" value="'.((int) $l->rowid).'"> ' : '').dol_print_date($db->jdate($l->dateo), 'day').'</td><td class="right nowraponall">'.price($l->amount).'</td><td colspan="3">'.dol_escape_htmltag($l->label).($hint !== '' ? '<br><span class="opacitymedium small">'.dol_escape_htmltag($hint).'</span>' : '').'</td><td class="right nowraponall">';
			if ($twin && $canwrite) {
				print '<button type="submit" form="'.$ff.'" name="twin" value="'.((int) $l->rowid).'" class="button smallpaddingimp" title="Keep the copy linked to the member (or payment), delete the other">Fix the double entry</button> ';
			}
			print '<a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.((int) $l->rowid).'">Open the entry</a></td></tr>';
		}
	}
	if (!$open && !$missing) {
		print '<tr class="oddeven"><td colspan="6"><span class="ok">Nothing to check.</span></td></tr>';
	}
	// Each statement against Dolibarr: where a balance difference comes from.
	$cmp = array();
	foreach ($files as $f) {
		if (($c = $rec->compare($f))) {
			$cmp[] = array($f, $c);
		}
	}
	if ($cmp) {
		print '<tr class="liste_titre"><td>Statement</td><td></td><td class="right">Starts at</td><td class="right">Money in</td><td class="right">Money out</td><td class="right">Ends at</td></tr>';
		foreach ($cmp as $fc) {
			list($f, $c) = $fc;
			$cell = function ($k) use ($c) {
				$diff = round($c['doli'][$k] - $c['stmt'][$k], 2);
				return price($c['stmt'][$k]).'<br>'.price($c['doli'][$k]).'<br>'.(abs($diff) < 0.005 ? '<span class="ok">&#10003;</span>' : '<span class="error">'.($diff > 0 ? '+' : '').price($diff).'</span>');
			};
			print '<tr class="oddeven"><td class="small">'.dol_escape_htmltag($f->original).'<br>'.dol_print_date($db->jdate($f->begin_date), 'day').' to '.dol_print_date($db->jdate($f->end_date), 'day');
			print '</td><td class="small opacitymedium">statement<br>Dolibarr<br>difference</td><td class="right">'.$cell('begin').'</td><td class="right">'.$cell('in').'</td><td class="right">'.$cell('out').'</td><td class="right">'.$cell('end').'</td></tr>';
			$why = array();
			$db0 = round($c['doli']['begin'] - $c['stmt']['begin'], 2);
			if (abs($db0) >= 0.005) {
				$why[] = 'Dolibarr was already '.price(abs($db0)).' '.($db0 > 0 ? 'higher' : 'lower').' when this statement starts: the opening balance, or an earlier month, is off';
			}
			$di = round($c['doli']['in'] - $c['stmt']['in'], 2);
			if (abs($di) >= 0.005) {
				$why[] = 'money in: Dolibarr has '.price(abs($di)).' '.($di > 0 ? 'more (an entry twice, a transfer counted as income, or something dated in this month that the bank put in another)' : 'less (deposits not entered: see the lines above)');
			}
			$do = round($c['doli']['out'] - $c['stmt']['out'], 2);
			if (abs($do) >= 0.005) {
				$why[] = 'money out: Dolibarr has '.price(abs($do)).' '.($do > 0 ? 'more (an entry twice, or dated in the wrong month)' : 'less (checks, fees or payments not entered: see the lines above)');
			}
			if ($why) {
				print '<tr class="oddeven"><td></td><td colspan="5" class="small">'.dol_escape_htmltag(ucfirst(implode('; ', $why))).'.</td></tr>';
			}
		}
	}
	// What the rules entered, for a person to look over.
	$ruled = $rec->byRules($aid);
	if ($ruled) {
		print '<tr class="liste_titre"><td colspan="6">Entered by the rules ('.count($ruled).', last 120 days): look them over; Undo takes one back</td></tr>';
		foreach ($ruled as $r) {
			print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($db->jdate($r->dateo), 'day').'</td><td class="right nowraponall">'.price($r->amount).'</td><td colspan="2">'.dol_escape_htmltag($r->label).'</td><td class="small">'.($r->accountancy_code ? 'account '.dol_escape_htmltag($r->accountancy_code) : 'transfer').'</td><td class="right">';
			if ($canwrite) {
				print '<a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=unrule&id='.((int) $r->rowid).'&token='.newToken().'#acct-'.$aid.'">Undo</a>';
			}
			print '</td></tr>';
		}
	}
	// Every upload, with Undo (a wrong file, the wrong account, a bad reading).
	$ups = array();
	foreach ($files as $f) {
		$ups[] = dol_escape_htmltag($f->original).($f->date_from ? ' <span class="opacitymedium">('.dol_print_date($db->jdate($f->date_from), 'day').' to '.dol_print_date($db->jdate($f->date_to), 'day').')</span>' : '').($canwrite && $f->source == 'upload' ? ' <a class="opacitymedium" href="'.$_SERVER['PHP_SELF'].'?action=undo&file='.((int) $f->rowid).'&token='.newToken().'#acct-'.$aid.'" onclick="return confirm(\'Undo this upload? Entries it reconciled go back to unreconciled.\')">undo</a>' : '');
	}
	if ($ups) {
		print '<tr class="oddeven"><td colspan="6" class="small">Uploads: '.implode('; ', $ups).'</td></tr>';
	}
	print '</table><br>';
}

llxFooter();
$db->close();
