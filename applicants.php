<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Everyone who has started a signup, and where they are in it.
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

$filter = GETPOST('filter', 'aZ09');
$filters = array(
	'' => 'Everyone',
	'open' => 'Signup not finished',
	'problem' => 'Payment problem',
	'nobadge' => 'Paying, no active badge',
	'badgeon' => 'Not paying, badge still active',
);
$canid = $user->hasRight('onboarding', 'idphoto', 'read');

$ef = $db->prefix()."adherent_extrafields";
$sql = "SELECT a.*, m.datefin, e.onb_badge_id, e.onb_badge_access";
$sql .= " FROM ".$db->prefix()."onboarding_applicant AS a";
$sql .= " LEFT JOIN ".$db->prefix()."adherent AS m ON m.rowid = a.fk_adherent";
$sql .= " LEFT JOIN ".$ef." AS e ON e.fk_object = a.fk_adherent";
$sql .= " WHERE a.entity = ".((int) $conf->entity);
if ($filter == 'open') {
	$sql .= " AND a.stage <> 'complete'";
} elseif ($filter == 'problem') {
	$sql .= " AND a.payment_state IN ('past_due', 'cancelled')";
} elseif ($filter == 'nobadge') {
	$sql .= " AND a.payment_state = 'active' AND (e.onb_badge_access IS NULL OR e.onb_badge_access = 0)";
} elseif ($filter == 'badgeon') {
	$sql .= " AND a.payment_state IN ('lapsed', 'none') AND e.onb_badge_access = 1";
}
$sql .= " ORDER BY a.datec DESC LIMIT 500";
$resql = $db->query($sql);

llxHeader('', 'Onboarding');
print load_fiche_titre('Onboarding', '', 'members');

print '<div class="tabsAction" style="text-align:left">';
foreach ($filters as $key => $label) {
	print '<a class="'.($filter == $key ? 'butActionRefused' : 'butAction').'" href="'.$_SERVER['PHP_SELF'].($key ? '?filter='.$key : '').'">'.dol_escape_htmltag($label).'</a> ';
}
print '</div>';

$stages = array('details' => 'Paperwork', 'payment' => 'Waiting for payment', 'complete' => 'Complete');
$yes = img_picto('Yes', 'tick');
$no = '<span class="opacitymedium">no</span>';

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Name</td><td>Email</td><td>Discord</td><td>Started</td><td>Stage</td><td class="center">Waiver</td><td class="center">Agreement</td><td class="center">ID</td><td>Dues</td><td>Paid until</td><td>Badge</td></tr>';
$n = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$n++;
	$doc = dol_buildpath('/onboarding/document.php', 1).'?id='.((int) $o->rowid).'&file=';
	$name = dol_escape_htmltag(trim($o->firstname.' '.$o->lastname));
	if ($o->fk_adherent > 0) {
		$name = '<a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $o->fk_adherent).'">'.$name.'</a> <span class="opacitymedium small">member</span>';
	} elseif ($o->fk_socpeople > 0) {
		$name = '<a href="'.DOL_URL_ROOT.'/contact/card.php?id='.((int) $o->fk_socpeople).'">'.$name.'</a> <span class="opacitymedium small">contact</span>';
	}
	print '<tr class="oddeven">';
	print '<td>'.$name.'</td>';
	print '<td>'.dol_escape_htmltag($o->email).'</td>';
	print '<td>'.dol_escape_htmltag($o->discord).'</td>';
	print '<td>'.dol_print_date($db->jdate($o->datec), 'day').'</td>';
	print '<td>'.(isset($stages[$o->stage]) ? $stages[$o->stage] : dol_escape_htmltag($o->stage)).'</td>';
	print '<td class="center">'.($o->waiver_signed_at ? '<a href="'.$doc.'waiver" target="_blank" rel="noopener">'.$yes.'</a>' : $no).'</td>';
	print '<td class="center">'.($o->agreement_signed_at ? '<a href="'.$doc.'agreement" target="_blank" rel="noopener">'.$yes.'</a>' : $no).'</td>';
	if (!$o->id_uploaded_at) {
		$idcell = $no;
	} elseif ($o->id_file && $canid) {
		$idcell = '<a href="'.$doc.'id" target="_blank" rel="noopener">'.$yes.'</a>';
	} else {
		$idcell = $yes;
	}
	print '<td class="center">'.$idcell.'</td>';
	$state = isset(OnboardingService::PAYMENT_STATES[$o->payment_state]) ? OnboardingService::PAYMENT_STATES[$o->payment_state] : $o->payment_state;
	if ($o->problem_since) {
		$state .= ' <span class="opacitymedium small">since '.dol_print_date($db->jdate($o->problem_since), 'day').'</span>';
	}
	print '<td>'.$state.'</td>';
	print '<td>'.($o->datefin ? dol_print_date($db->jdate($o->datefin), 'day') : '').'</td>';
	print '<td>'.dol_escape_htmltag((string) $o->onb_badge_id).($o->fk_adherent > 0 ? ' <span class="opacitymedium small">'.($o->onb_badge_access ? 'access on' : 'access off').'</span>' : '').'</td>';
	print '</tr>';
}
if (!$n) {
	print '<tr><td colspan="11"><span class="opacitymedium">Nobody here.</span></td></tr>';
}
print '</table></div>';
print '<p class="opacitymedium small">Badge ID, badge access and "dues waived until" are edited on the member card.</p>';

llxFooter();
$db->close();
