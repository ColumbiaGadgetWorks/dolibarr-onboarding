<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Dues payments that did not match anyone, usually because the payer used a
 * different email at Givebutter than on the signup form.
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
	$app = $svc->findByEmail(GETPOST('email', 'alphanohtml'));
	if (!$app) {
		setEventMessages('Nobody has signed up with that email address.', null, 'errors');
	} else {
		setEventMessages('Payment: '.$svc->applyPayment($app, $payment), null);
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'ignore' && $canwrite) {
	$db->query("UPDATE ".$db->prefix()."onboarding_payment SET status = 'ignored' WHERE status = 'unmatched' AND rowid = ".((int) GETPOST('payment', 'int')));
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', 'Unmatched payments');
print load_fiche_titre('Unmatched dues payments', '', 'members');
print '<p class="opacitymedium">These Givebutter payments did not match anyone who signed up. Type the email the person used on the signup form to give them the payment, or ignore it if it is not dues.</p>';

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
		print '<input type="email" name="email" placeholder="Signup email" class="minwidth200"> ';
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

llxFooter();
$db->close();
