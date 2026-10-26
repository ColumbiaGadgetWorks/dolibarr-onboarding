<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Dues payments that did not match anyone, usually because the payer used a
 * different email or name at Givebutter than on their member card.
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

dol_include_once('/onboarding/class/onboarding.class.php');

if (!isModEnabled('onboarding') || !$user->hasRight('onboarding', 'applicant', 'read')) {
	accessforbidden();
}
$canwrite = $user->hasRight('onboarding', 'applicant', 'write');
$svc = new OnboardingService($db);

$action = GETPOST('action', 'aZ09');
if ($action == 'assign' && $canwrite) {
	$payment = (int) GETPOST('payment', 'int');
	$memberId = (int) GETPOST('member', 'int');
	$typed = GETPOST('email', 'alphanohtml');
	$app = null;
	if ($memberId > 0) {
		$res = $db->query("SELECT email FROM ".$db->prefix()."onboarding_payment WHERE rowid = ".$payment);
		$pay = $res ? $db->fetch_object($res) : null;
		$app = $svc->applicantForMember($memberId, $pay ? (string) $pay->email : '');
		if (!$app) {
			setEventMessages('That member has no usable email address on their card. Add one, then assign again.', null, 'errors');
		}
	} elseif (trim((string) $typed) !== '') {
		$app = $svc->findByEmail($typed);
		if (!$app && ($mid = $svc->memberFor($typed)) > 0) {
			$app = $svc->applicantForMember($mid, $typed);
		}
		if (!$app) {
			setEventMessages('No signup or member has that email address.', null, 'errors');
		}
	} else {
		setEventMessages('Choose the member, or type their signup email.', null, 'errors');
	}
	if ($app) {
		setEventMessages('Payment: '.$svc->applyPayment($app, $payment), null);
		// Their other payments now match by their Givebutter contact.
		$more = $svc->retryUnmatched();
		if ($more) {
			setEventMessages($more.' more payment'.($more == 1 ? '' : 's').' matched.', null);
		}
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'retry' && $canwrite) {
	$n = $svc->retryUnmatched();
	setEventMessages($n ? $n.' payment'.($n == 1 ? '' : 's').' matched.' : 'Still no match for these. Choose the member for each one.', null, $n ? 'mesgs' : 'warnings');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if (($action == 'ppassign' || $action == 'ppignore') && $canwrite) {
	dol_include_once('/onboarding/class/paypal.class.php');
	$pp = new OnboardingPaypal($db, $svc);
	$row = (int) GETPOST('payment', 'int');
	if ($action == 'ppignore') {
		$db->query("UPDATE ".$db->prefix()."onboarding_paypal SET status = 'ignored' WHERE status = 'unmatched' AND rowid = ".$row);
	} elseif ((int) GETPOST('member', 'int') > 0) {
		setEventMessages('PayPal payment: '.$pp->record($row, (int) GETPOST('member', 'int')), null);
		// Their other payments from the same PayPal account now match on their own.
		$more = $pp->retryUnmatched();
		if ($more) {
			setEventMessages($more.' more PayPal payment'.($more == 1 ? '' : 's').' recorded.', null);
		}
	} else {
		setEventMessages('Choose the member.', null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'ignore' && $canwrite) {
	$db->query("UPDATE ".$db->prefix()."onboarding_payment SET status = 'ignored' WHERE status = 'unmatched' AND rowid = ".((int) GETPOST('payment', 'int')));
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Members to choose from, and a guess per payment: the one member whose last
// name appears in the payer's Givebutter name or email ("idavisstober" for
// Isaiah Davis-Stober).
$members = array();
$res = $db->query("SELECT rowid, firstname, lastname, email, statut FROM ".$db->prefix()."adherent WHERE entity IN (".getEntity('adherent').") AND statut <> -2 ORDER BY lastname, firstname");
while ($res && ($o = $db->fetch_object($res))) {
	$members[] = $o;
}
$squash = function ($text) {
	return preg_replace('/[^a-z]/', '', strtolower((string) $text));
};
$guess = function ($pay) use ($members, $squash) {
	$hay = $squash($pay->firstname.$pay->lastname).' '.$squash(strstr((string) $pay->email, '@', true));
	$hits = array();
	foreach ($members as $m) {
		$last = $squash($m->lastname);
		if (strlen($last) >= 4 && strpos($hay, $last) !== false) {
			$hits[] = (int) $m->rowid;
		}
	}
	return count($hits) == 1 ? $hits[0] : 0;
};

llxHeader('', 'Unmatched payments');
$retry = $canwrite ? '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><button type="submit" name="action" value="retry" class="butAction" title="Matches each payment again by Givebutter contact, email and name. The hourly sync does this too.">Match again</button></form>' : '';
print load_fiche_titre('Unmatched dues payments', $retry, 'members');
print '<p class="opacitymedium">These Givebutter payments did not match a signup or a member, by email or by name. Choose the member who paid (a likely one is picked for you when the name or email points to one), or ignore it if it is not dues. Once assigned, that person\'s later payments match on their own. A payment that is already on the member\'s card, for example from the dues import, is not recorded twice.</p>';

$resql = $db->query("SELECT * FROM ".$db->prefix()."onboarding_payment WHERE entity = ".((int) $conf->entity)." AND status = 'unmatched' ORDER BY transacted_at DESC LIMIT 500");
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Date</td><td>Paid by</td><td>Email at Givebutter</td><td class="right">Amount</td><td>Givebutter id</td><td></td></tr>';
$n = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$n++;
	print '<tr class="oddeven">';
	print '<td>'.dol_print_date($db->jdate($o->transacted_at), 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag(trim($o->firstname.' '.$o->lastname)).'</td>';
	print '<td>'.dol_escape_htmltag($o->email).'</td>';
	print '<td class="right">'.price($o->amount).'</td>';
	print '<td>'.dol_escape_htmltag($o->gb_transaction_id).'</td>';
	print '<td class="right">';
	if ($canwrite) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="payment" value="'.((int) $o->rowid).'">';
		$pick = $guess($o);
		print '<select name="member" class="minwidth200 maxwidth300"><option value="">Choose the member&hellip;</option>';
		foreach ($members as $m) {
			$label = trim($m->lastname.', '.$m->firstname, ', ').($m->email ? ' ('.$m->email.')' : '').((int) $m->statut == 1 ? '' : ' - not active');
			print '<option value="'.((int) $m->rowid).'"'.($pick == (int) $m->rowid ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
		}
		print '</select> ';
		print '<input type="email" name="email" placeholder="or signup email" class="maxwidth150"> ';
		print '<button type="submit" name="action" value="assign" class="button smallpaddingimp">Assign</button> ';
		print '<button type="submit" name="action" value="ignore" class="button smallpaddingimp">Ignore</button>';
		print '</form>';
	}
	print '</td></tr>';
}
if (!$n) {
	print '<tr><td colspan="6"><span class="opacitymedium">Nothing waiting.</span></td></tr>';
}
print '</table></div>';

// PayPal dues from the hourly PayPal sync that matched nobody.
$resql = $db->query("SELECT * FROM ".$db->prefix()."onboarding_paypal WHERE entity = ".((int) $conf->entity)." AND status = 'unmatched' ORDER BY transacted_at DESC LIMIT 500");
if ($resql && $db->num_rows($resql) > 0) {
	print '<br>'.load_fiche_titre('Unmatched PayPal payments', '', '');
	print '<p class="opacitymedium">PayPal dues payments that matched no member by PayPal account, email or name. Choose the member who paid; later payments from the same PayPal account then match on their own. A payment already on their card is not recorded twice.</p>';
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>Date</td><td>Paid by</td><td>Email at PayPal</td><td class="right">Amount</td><td>PayPal id</td><td></td></tr>';
	while ($o = $db->fetch_object($resql)) {
		$parts = explode(' ', trim((string) $o->name));
		$fake = (object) array('lastname' => count($parts) > 1 ? array_pop($parts) : '', 'firstname' => implode(' ', $parts), 'email' => $o->email);
		print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($o->transacted_at), 'dayhour').'</td><td>'.dol_escape_htmltag($o->name).'</td><td>'.dol_escape_htmltag($o->email).'</td>';
		print '<td class="right">'.price($o->amount).'</td><td>'.dol_escape_htmltag($o->txid).($o->note ? '<br><span class="small opacitymedium">'.dol_escape_htmltag($o->note).'</span>' : '').'</td><td class="right">';
		if ($canwrite) {
			$pick = $guess($fake);
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="payment" value="'.((int) $o->rowid).'">';
			print '<select name="member" class="minwidth200 maxwidth300"><option value="">Choose the member&hellip;</option>';
			foreach ($members as $m) {
				$label = trim($m->lastname.', '.$m->firstname, ', ').($m->email ? ' ('.$m->email.')' : '').((int) $m->statut == 1 ? '' : ' - not active');
				print '<option value="'.((int) $m->rowid).'"'.($pick == (int) $m->rowid ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
			}
			print '</select> <button type="submit" name="action" value="ppassign" class="button smallpaddingimp">Assign</button> <button type="submit" name="action" value="ppignore" class="button smallpaddingimp">Ignore</button></form>';
		}
		print '</td></tr>';
	}
	print '</table></div>';
}

llxFooter();
$db->close();
