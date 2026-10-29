<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Yearly filings: each fiscal year's IRS return and state report, the
 * statements the treasurer uploaded, Dolibarr's numbers, and what happened.
 * The treasurer works from the emails; this page is for watching and for
 * stepping in.
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

dol_include_once('/onboarding/class/filing.class.php');

if (!isModEnabled('onboarding') || !$user->hasRight('onboarding', 'filing', 'read')) {
	accessforbidden();
}
$canwrite = !empty($user->admin) || $user->hasRight('onboarding', 'filing', 'write');
$filing = new OnboardingFiling($db);
$who = trim($user->getFullName($langs)).' ('.$user->login.')';
$today = OnboardingFiling::today();
$latest = OnboardingFiling::latestEnded($today);

// One uploaded file.
$fileId = (int) GETPOST('file', 'int');
if ($fileId > 0) {
	$res = $db->query("SELECT fk_filing FROM ".$db->prefix()."onboarding_filing_doc WHERE rowid = ".$fileId);
	$o = $res ? $db->fetch_object($res) : null;
	$f = $o ? $filing->fetch((int) $o->fk_filing) : null;
	$d = $f ? $filing->doc($f, $fileId) : null;
	$path = $d ? OnboardingFiling::path($f, $d) : '';
	if ($path === '') {
		http_response_code(404);
		print 'File not found';
		exit;
	}
	$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
	$inline = in_array($ext, array('pdf', 'jpg', 'jpeg', 'png'), true);
	header('Content-Type: '.(isset(OnboardingFiling::TYPES[$ext]) ? OnboardingFiling::TYPES[$ext] : 'application/octet-stream'));
	header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.str_replace('"', '', $d->original).'"');
	header('Cache-Control: private, no-store');
	header('X-Content-Type-Options: nosniff');
	readfile($path);
	exit;
}

$action = GETPOST('action', 'aZ09');
$fy = (int) GETPOST('fy', 'int');
if ($canwrite && $action !== '') {
	$f = $fy > 0 ? $filing->fetchFy($fy) : null;
	$msg = '';
	if ($action == 'start' && $fy > 0 && !$f) {
		$f = $filing->start($fy, $who);
		$msg = $f && $filing->ask($f, false) ? 'FY'.$fy.' opened, and the treasurer was emailed.' : 'FY'.$fy.' opened, but no email went out: nobody with an email holds the treasurer or president tag.';
	} elseif ($action == 'record' && $fy > 0) {
		$amount = GETPOST('under', 'int') == 1 ? '' : trim(GETPOST('receipts', 'alphanohtml'));
		$msg = $filing->recordFiled($fy, $who, GETPOST('irs', 'alphanohtml'), GETPOST('state', 'alphanohtml'), $amount !== '' ? (float) price2num(str_replace(array('$', ','), '', $amount)) : null, GETPOST('note', 'alphanohtml'));
	} elseif ($f && $f->status != 'done') {
		if ($action == 'ask') {
			$msg = $filing->ask($f, (int) $f->asks > 0) ? 'The treasurer was emailed.' : 'No email went out: nobody with an email holds the treasurer or president tag.';
		} elseif ($action == 'docsdone') {
			$r = $filing->docsDone($f, $who, GETPOST('note', 'nohtml'));
			$msg = $r == 'note' ? 'Some items are still missing: say why in the note.' : 'Statements marked complete; the treasurer was sent the filing email.';
		} elseif ($action == 'irs') {
			$under = GETPOST('under', 'int') == 1;
			$amount = $under ? '' : trim(GETPOST('receipts', 'alphanohtml'));
			$filing->irsFiled($f, $who, GETPOST('day', 'alphanohtml'), $amount !== '' ? (float) price2num(str_replace(array('$', ','), '', $amount)) : null, $under ? 'under' : GETPOST('form', 'alphanohtml'));
			$msg = 'IRS return marked filed.';
		} elseif ($action == 'state') {
			$filing->stateFiled($f, $who, GETPOST('day', 'alphanohtml'));
			$msg = 'State report marked filed.';
		}
	}
	if ($msg !== '') {
		setEventMessages($msg, null, strpos($msg, 'No email') === 0 || strpos($msg, 'Some items') === 0 || strpos($msg, 'Error') === 0 ? 'warnings' : 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].($fy > 0 ? '?fy='.$fy : ''));
	exit;
}

llxHeader('', 'Yearly filings');
print load_fiche_titre('Yearly filings', '', 'bill');
$facts = $filing->facts();
$treasurers = array();
foreach ($filing->treasurers() as $t) {
	$treasurers[] = dol_escape_htmltag($t['name'].' ('.$t['email'].')');
}
print '<p class="opacitymedium">The IRS return (Form 990-N while gross receipts stay under $50,000) is due on the 15th day of the fifth month after the fiscal year ends; the '.dol_escape_htmltag($facts['state']).' annual registration report by '.dol_escape_htmltag(date('j M', strtotime(OnboardingFiling::stateDue($latest)))).' each year. ';
print getDolGlobalString('ONBOARDING_FILING_AUTOSTART', '1') !== '0' ? ((int) getDolGlobalString('ONBOARDING_FILING_START_DAYS', '15')).' days after the year ends, the daily job opens it and emails ' : 'Opening a year emails ';
print ($treasurers ? implode(', ', $treasurers) : 'nobody (tag someone treasurer)').' a link to upload the statements, then everything needed to file, with reminders every '.((int) getDolGlobalString('ONBOARDING_FILING_REMIND_DAYS', '7')).' days and the board told as the due date nears. Settings: Member onboarding setup, Yearly filings.</p>';

// The latest year, when nobody has opened it.
if (!$filing->fetchFy($latest)) {
	print '<div class="warning">FY'.$latest.' ('.dol_escape_htmltag(OnboardingFiling::showPeriod($latest)).') is not tracked. The IRS return is due '.dol_escape_htmltag(OnboardingFiling::show(OnboardingFiling::irsDue($latest))).', the state report '.dol_escape_htmltag(OnboardingFiling::show(OnboardingFiling::stateDue($latest))).'.';
	if ($today < OnboardingFiling::startsOn($latest) && getDolGlobalString('ONBOARDING_FILING_AUTOSTART', '1') !== '0') {
		print ' It opens by itself on '.dol_escape_htmltag(OnboardingFiling::show(OnboardingFiling::startsOn($latest))).'.';
	}
	if ($canwrite) {
		print '<br><br><form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="start"><input type="hidden" name="fy" value="'.$latest.'"><button type="submit" class="butAction">Start FY'.$latest.' now (emails the treasurer)</button></form>';
		print '<br><form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="record"><input type="hidden" name="fy" value="'.$latest.'">';
		print 'Or, already filed outside Dolibarr: IRS on <input type="date" name="irs"> state on <input type="date" name="state"> <label><input type="checkbox" name="under" value="1" checked onchange="document.getElementById(\'rec-amt\').hidden=this.checked"> gross receipts $50,000 or less (990-N)</label> <span id="rec-amt" hidden>gross receipts <input type="text" name="receipts" size="10" placeholder="$"></span> note <input type="text" name="note" class="minwidth200"> <button type="submit" class="button smallpaddingimp">Record as filed</button></form>';
	}
	print '</div><br>';
}

$all = $filing->all();
$show = null;
foreach ($all as $f) {
	if (($fy > 0 && (int) $f->fy === $fy) || (!$fy && !$show && $f->status != 'done')) {
		$show = $f;
	}
}
if (!$show && !$fy && $all) {
	$show = $all[0];
}

if ($show) {
	$f = $show;
	$y = (int) $f->fy;
	$checklist = $filing->checklist($f);
	print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">FY'.$y.': '.dol_escape_htmltag(OnboardingFiling::showPeriod($y)).' (IRS tax year '.OnboardingFiling::taxYear($y).')</td></tr>';
	print '<tr class="oddeven"><td class="titlefield">Status</td><td><strong>'.dol_escape_htmltag(OnboardingFiling::STATUSES[$f->status]).'</strong>'.($f->note ? ' <span class="opacitymedium">'.dol_escape_htmltag($f->note).'</span>' : '').'</td></tr>';
	print '<tr class="oddeven"><td>IRS return</td><td>'.($f->irs_filed_at ? 'Form '.dol_escape_htmltag($f->irs_form ?: '990-N').' filed '.dol_escape_htmltag(OnboardingFiling::show($f->irs_filed_at)).' by '.dol_escape_htmltag($f->irs_filed_by) : 'Due '.dol_escape_htmltag(OnboardingFiling::show(OnboardingFiling::irsDue($y)))).'</td></tr>';
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($facts['state']).' report</td><td>'.($f->state_filed_at ? 'Filed '.dol_escape_htmltag(OnboardingFiling::show($f->state_filed_at)).' by '.dol_escape_htmltag($f->state_filed_by) : 'Due '.dol_escape_htmltag(OnboardingFiling::show(OnboardingFiling::stateDue($y)))).'</td></tr>';
	print '<tr class="oddeven"><td>Treasurer emailed</td><td>'.((int) $f->asks).' time'.((int) $f->asks == 1 ? '' : 's').' for this step'.($f->last_ask ? ', last '.dol_print_date($db->jdate($f->last_ask), 'dayhour') : '').'</td></tr>';
	if ($f->docs_done_at) {
		print '<tr class="oddeven"><td>Statements complete</td><td>'.dol_print_date($db->jdate($f->docs_done_at), 'dayhour').' by '.dol_escape_htmltag($f->docs_done_by).($f->docs_note ? '<br><span class="opacitymedium">'.dol_escape_htmltag($f->docs_note).'</span>' : '').'</td></tr>';
	}
	if (OnboardingFiling::receiptsText($f) !== '') {
		print '<tr class="oddeven"><td>Gross receipts reported</td><td>'.dol_escape_htmltag(OnboardingFiling::receiptsText($f)).'</td></tr>';
	}
	print '</table>';

	if ($canwrite && $f->status != 'done') {
		print '<div class="tabsAction" style="text-align:left">';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="ask"><input type="hidden" name="fy" value="'.$y.'"><button type="submit" class="butAction">Email the treasurer now</button></form>';
		if (!empty($user->email)) {
			print '<a class="butAction" href="'.dol_escape_htmltag($filing->link($f->rowid, $user->email)).'" target="_blank" rel="noopener">Open the filing page (as you)</a>';
		}
		print '</div>';
		if ($f->status == 'docs') {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="docsdone"><input type="hidden" name="fy" value="'.$y.'">';
			print 'Statements complete without the treasurer pressing it; note (needed if something is missing): <input type="text" name="note" class="minwidth300"> <button type="submit" class="button smallpaddingimp">Mark statements complete</button></form><br>';
		} else {
			if (!$f->irs_filed_at) {
				$form = $filing->form($f);
				print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="irs"><input type="hidden" name="fy" value="'.$y.'">';
				$under = $form['form'] == '990-N';
				print 'IRS return filed on <input type="date" name="day" value="'.$today.'"> <label><input type="checkbox" name="under" value="1"'.($under ? ' checked' : '').' onchange="document.getElementById(\'irs-more\').hidden=this.checked"> gross receipts $50,000 or less (990-N)</label> <span id="irs-more"'.($under ? ' hidden' : '').'>form <select name="form">';
				foreach (array('990-N', '990-EZ', '990') as $x) {
					print '<option'.($x == $form['form'] ? ' selected' : '').'>'.$x.'</option>';
				}
				print '</select> gross receipts <input type="text" name="receipts" size="10" value="'.dol_escape_htmltag(number_format($form['this']['total'], 2, '.', '')).'"></span> <button type="submit" class="button smallpaddingimp">Mark IRS return filed</button></form>';
			}
			if (!$f->state_filed_at) {
				print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="state"><input type="hidden" name="fy" value="'.$y.'">';
				print 'State report filed on <input type="date" name="day" value="'.$today.'"> <button type="submit" class="button smallpaddingimp">Mark state report filed</button></form>';
			}
			print '<br>';
		}
	}

	// The checklist.
	$months = OnboardingFiling::months($y);
	print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre"><td>Statements and reports</td>';
	foreach ($months as $ym) {
		print '<td class="center">'.dol_escape_htmltag(date('M', strtotime($ym.'-01'))).'</td>';
	}
	print '<td>Files</td></tr>';
	$marks = array('file' => array('&#10003;', 'uploaded'), 'year' => array('&#10003;', 'whole-year file'), 'reconciled' => array('R', 'reconciled in Dolibarr'), 'none' => array('-', 'not used'), 'missing' => array('&#10007;', 'missing'));
	foreach ($checklist as $i) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($i['label']).'</td>';
		if ($i['type'] == 'account') {
			foreach ($i['months'] as $st) {
				print '<td class="center'.($st == 'missing' ? ' error' : '').'" title="'.$marks[$st][1].'">'.$marks[$st][0].'</td>';
			}
		} else {
			print '<td colspan="12" class="'.($i['state'] == 'missing' ? 'error' : '').'">'.($i['state'] == 'missing' ? 'missing' : ($i['state'] == 'none' ? 'none this year' : 'uploaded')).'</td>';
		}
		$links = array();
		foreach ($i['docs'] as $d) {
			$links[] = $d->kind == 'none' ? '<span class="opacitymedium">none: '.dol_escape_htmltag($d->who).'</span>' : '<a href="'.$_SERVER['PHP_SELF'].'?file='.((int) $d->rowid).'" target="_blank">'.dol_escape_htmltag($d->original).'</a>'.($i['type'] == 'account' ? ' <span class="opacitymedium small">'.dol_escape_htmltag($d->period == 'year' ? 'year' : ($d->period ? $d->period : 'month?')).'</span>' : '');
		}
		print '<td class="small">'.implode('<br>', $links).'</td></tr>';
	}
	$docs = $filing->docs($f->rowid);
	foreach (array('irs' => 'IRS confirmation', 'state' => 'State receipt') as $item => $label) {
		foreach (isset($docs[$item]) ? $docs[$item] : array() as $d) {
			print '<tr class="oddeven"><td>'.$label.'</td><td colspan="12"></td><td class="small"><a href="'.$_SERVER['PHP_SELF'].'?file='.((int) $d->rowid).'" target="_blank">'.dol_escape_htmltag($d->original).'</a></td></tr>';
		}
	}
	if (!$checklist) {
		print '<tr><td colspan="14" class="opacitymedium">No money accounts or yearly reports are set up.</td></tr>';
	}
	print '</table></div><span class="opacitymedium small">&#10003; uploaded, R every bank line that month is reconciled in Dolibarr, - account not used, &#10007; missing.</span><br><br>';

	// The numbers.
	$form = $filing->form($f);
	print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">Gross receipts, Dolibarr\'s estimate ('.($form['this']['source'] == 'books' ? 'from the books' : 'from bank lines and Givebutter').')</td></tr>';
	foreach ($form['this']['lines'] as $l) {
		print '<tr class="oddeven"><td class="titlefield">'.dol_escape_htmltag($l[0]).'</td><td>'.price($l[1]).'</td></tr>';
	}
	print '<tr class="oddeven"><td><strong>This year</strong></td><td><strong>'.price($form['this']['total']).'</strong> <span class="opacitymedium">money out '.price($form['this']['spent']).'</span></td></tr>';
	$years = array();
	foreach ($form['years'] as $yy) {
		$years[] = OnboardingFiling::yearText($yy);
	}
	print '<tr class="oddeven"><td>Three-year average</td><td>'.price($form['average']).' <span class="opacitymedium">('.dol_escape_htmltag(implode(', ', $years)).')</span>: Form <strong>'.dol_escape_htmltag($form['form']).'</strong></td></tr>';
	foreach ($form['this']['notes'] as $n) {
		print '<tr class="oddeven"><td></td><td class="opacitymedium small">'.dol_escape_htmltag($n).'</td></tr>';
	}
	print '</table><br>';

	print '<details><summary>History</summary><table class="noborder centpercent"><tr class="liste_titre"><td>When</td><td>What</td><td>Who</td><td>Detail</td></tr>';
	foreach ($filing->events($f->rowid) as $e) {
		print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($db->jdate($e->datec), 'dayhour').'</td><td>'.dol_escape_htmltag($e->kind).'</td><td>'.dol_escape_htmltag($e->who).'</td><td>'.dol_escape_htmltag($e->note).'</td></tr>';
	}
	print '</table></details><br>';
}

print '<table class="noborder centpercent"><tr class="liste_titre"><td>Year</td><td>Period</td><td>Status</td><td>IRS return</td><td>State report</td><td class="right">Gross receipts</td></tr>';
foreach ($all as $f) {
	print '<tr class="oddeven"><td><a href="'.$_SERVER['PHP_SELF'].'?fy='.((int) $f->fy).'">FY'.((int) $f->fy).'</a></td><td>'.dol_escape_htmltag(OnboardingFiling::showPeriod((int) $f->fy)).'</td><td>'.dol_escape_htmltag(OnboardingFiling::STATUSES[$f->status]).'</td>';
	print '<td>'.($f->irs_filed_at ? dol_escape_htmltag(($f->irs_form ?: '').' '.OnboardingFiling::show($f->irs_filed_at)) : '<span class="opacitymedium">due '.dol_escape_htmltag(OnboardingFiling::show(OnboardingFiling::irsDue((int) $f->fy))).'</span>').'</td>';
	print '<td>'.($f->state_filed_at ? dol_escape_htmltag(OnboardingFiling::show($f->state_filed_at)) : '<span class="opacitymedium">due '.dol_escape_htmltag(OnboardingFiling::show(OnboardingFiling::stateDue((int) $f->fy))).'</span>').'</td>';
	print '<td class="right">'.dol_escape_htmltag(OnboardingFiling::receiptsText($f)).'</td></tr>';
}
if (!$all) {
	print '<tr><td colspan="6" class="opacitymedium">No years yet.</td></tr>';
}
print '</table>';

llxFooter();
$db->close();
