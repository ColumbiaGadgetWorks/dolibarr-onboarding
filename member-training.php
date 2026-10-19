<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The Training tab on member and contact cards: what this person is trained
 * on, what they may train, the trainings they gave and the credit they earned,
 * and their bookings.
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/member.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/contact.lib.php';

if (!isModEnabled('onboarding') || !$user->hasRight('onboarding', 'training', 'read')) {
	accessforbidden();
}
$bk = new OnboardingBooking($db);
$p = $db->prefix();
$memberId = (int) GETPOST('id', 'int');
$contactId = (int) GETPOST('contact', 'int');

llxHeader('', 'Training');
$email = '';
if ($contactId > 0) {
	$object = new Contact($db);
	if ($object->fetch($contactId) <= 0) {
		accessforbidden();
	}
	print dol_get_fiche_head(contact_prepare_head($object), 'onbtraining', 'Contact', -1, 'contact');
	$email = OnboardingService::cleanEmail($object->email);
	$m = $bk->memberRow($email);
	$memberId = $m ? (int) $m->rowid : 0;
} else {
	$object = new Adherent($db);
	if ($object->fetch($memberId) <= 0) {
		accessforbidden();
	}
	print dol_get_fiche_head(member_prepare_head($object), 'onbtraining', 'Member', -1, 'user');
	$email = OnboardingService::cleanEmail($object->email);
}
print '<p><strong>'.dol_escape_htmltag(trim($object->firstname.' '.$object->lastname)).'</strong> <span class="opacitymedium">'.dol_escape_htmltag($email).'</span></p>';

$person = $bk->person($email);
$app = $person['applicant'];
print '<p>'.($person['kind'] == 'member' ? 'Member (member price)' : 'Non-member (full price)').' &middot; waiver '.($person['waiver'] ? 'on file' : '<strong>not signed</strong>').($person['kind'] == 'member' ? ' &middot; agreement '.($person['agreement'] ? 'on file' : '<strong>not signed</strong>') : '');
if ($app && !empty($app->birthdate)) {
	$age = OnboardingBooking::age($db->jdate($app->birthdate), OnboardingService::now());
	print ' &middot; '.($age < 18 ? '<strong>under 18</strong>'.($app->guardian_name ? ', waiver signed by '.dol_escape_htmltag($app->guardian_name).' ('.dol_escape_htmltag($app->guardian_relation).')' : '') : 'adult');
}
if ($app && !empty($app->training_only)) {
	print ' &middot; <span class="opacitymedium">came for training</span>';
}
print '</p>';

$where = "LOWER(t.email) = '".$db->escape($email)."'".($memberId ? " OR t.fk_adherent = ".$memberId : '');
$res = $db->query("SELECT t.* FROM ".$p."onboarding_training AS t WHERE t.entity = ".((int) $conf->entity)." AND (".$where.") ORDER BY t.transacted_at DESC");
print '<h3>Trained on</h3><table class="noborder centpercent"><tr class="liste_titre"><td>Date</td><td>Tool</td><td>Zone</td><td>Trainer</td><td class="right">Paid</td><td>How</td></tr>';
$n = 0;
while ($res && ($o = $db->fetch_object($res))) {
	$n++;
	$how = array('paid' => 'paid', 'free' => 'no fee: '.$o->free_reason, 'import' => 'imported', 'class' => 'group class');
	$state = $o->status == 'ignored' ? ' <span class="badge badge-status8">voided</span>' : ($o->status == 'no_show' ? ' <span class="badge badge-status8">no-show, not trained</span>' : '');
	print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($o->transacted_at), 'day').'</td><td>'.dol_escape_htmltag($o->tool).$state.'</td><td>'.dol_escape_htmltag($o->zone).'</td><td>'.dol_escape_htmltag($o->trainer_name).'</td><td class="right">'.price($o->amount).'</td><td>'.dol_escape_htmltag(isset($how[$o->source]) ? $how[$o->source] : $o->source).'</td></tr>';
}
if (!$n) {
	print '<tr><td colspan="6"><span class="opacitymedium">Not trained on anything yet.</span></td></tr>';
}
print '</table>';

if ($memberId) {
	$slots = $bk->slots(0);
	$mine = array_filter($slots, function ($s) use ($memberId) {
		return (int) $s->fk_adherent == $memberId;
	});
	print '<h3>Trains</h3>';
	if ($mine) {
		print '<ul>';
		foreach ($mine as $s) {
			$t = $bk->tool((int) $s->fk_tool);
			print '<li>'.dol_escape_htmltag($t ? $t->label : '?').': '.OnboardingBooking::WEEKDAYS[(int) $s->weekday].'s at '.dol_escape_htmltag($s->start_time).'</li>';
		}
		print '</ul>';
	} else {
		print '<p class="opacitymedium">Not a trainer. Trainers are added under Training tools.</p>';
	}
	$res = $db->query("SELECT COUNT(*) AS n, COALESCE(SUM(trainer_amount), 0) AS earned FROM ".$p."onboarding_training WHERE entity = ".((int) $conf->entity)." AND fk_trainer = ".$memberId." AND status = 'recorded'");
	$given = $res ? $db->fetch_object($res) : null;
	print '<p>Trainings given: <strong>'.($given ? (int) $given->n : 0).'</strong> &middot; credit earned '.price($given ? $given->earned : 0).' &middot; credit now <strong>'.price($bk->training->balance('trainer', $memberId)).'</strong> (a month of dues at '.price(OnboardingTraining::threshold()).')</p>';
}

$rows = $bk->bookingsWhere("email = '".$db->escape($email)."'", 'b.rowid DESC');
print '<h3>Bookings</h3><table class="noborder centpercent"><tr class="liste_titre"><td>Booking</td><td>When</td><td>Tool</td><td>Trainer</td><td class="right">Price</td><td>Status</td></tr>';
foreach ($rows as $b) {
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/onboarding/trainings.php', 1).'?booking='.((int) $b->rowid).'">'.dol_escape_htmltag($b->ref).'</a></td><td>'.($b->session_start ? dol_escape_htmltag(OnboardingBooking::fmt($db->jdate($b->session_start), 'D j M Y, g:i A')) : '').'</td>';
	print '<td>'.dol_escape_htmltag($b->tool).'</td><td>'.dol_escape_htmltag($b->trainer_first).'</td><td class="right">'.price($b->price).'</td><td>'.dol_escape_htmltag(OnboardingBooking::STATUS_LABELS[$b->status]).'</td></tr>';
}
if (!$rows) {
	print '<tr><td colspan="6"><span class="opacitymedium">No bookings.</span></td></tr>';
}
print '</table>';

print dol_get_fiche_end();
llxFooter();
$db->close();
