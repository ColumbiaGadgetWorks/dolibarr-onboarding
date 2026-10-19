<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The training catalog: which tools need training, in which zone, at what fee,
 * who trains each and when, and the days nobody can book. Every change is
 * numbered and sent to the website, which keeps its own copy (so its pages
 * work while Dolibarr is down) and reports which version it holds.
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
$canedit = $user->hasRight('onboarding', 'training', 'tools');
$bk = new OnboardingBooking($db);
$action = GETPOST('action', 'aZ09');
$id = (int) GETPOST('id', 'int');
$back = $_SERVER['PHP_SELF'].($id ? '?action=edit&id='.$id : '');

if ($canedit && $action == 'save') {
	$in = array();
	foreach (array('ref', 'label', 'zone', 'fee', 'member_discount', 'trainer_share', 'seats', 'minutes', 'covers', 'wiki_url', 'page_url') as $k) {
		$in[$k] = GETPOST($k, 'alphanohtml');
	}
	$in['bookable'] = GETPOST('bookable', 'int');
	$in['active'] = GETPOST('active', 'int');
	$r = $bk->saveTool($in, $user, $id);
	setEventMessages($r['ok'] ? 'Saved, and sent to the website.' : $r['error'], null, $r['ok'] ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF'].'?action=edit&id='.($r['ok'] ? $r['id'] : $id));
	exit;
}
if ($canedit && $action == 'addslot') {
	$r = $bk->addSlot($id, (int) GETPOST('trainer', 'int'), (int) GETPOST('weekday', 'int'), trim(GETPOST('time', 'alphanohtml')), (int) GETPOST('minutes', 'int'), (int) GETPOST('seats', 'int'), $user);
	setEventMessages($r['ok'] ? 'Trainer added.' : $r['error'], null, $r['ok'] ? 'mesgs' : 'errors');
	header('Location: '.$back);
	exit;
}
if ($canedit && $action == 'delslot') {
	$bk->removeSlot((int) GETPOST('slot', 'int'), $user);
	setEventMessages('Removed. Bookings already made for that time keep their date.', null);
	header('Location: '.$back);
	exit;
}
if ($canedit && $action == 'close') {
	setEventMessages($bk->closeDay(GETPOST('day', 'alphanohtml'), GETPOST('reason', 'alphanohtml'), $user), null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($canedit && $action == 'open') {
	$bk->openDay((int) GETPOST('closed', 'int'), $user);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($canedit && $action == 'push') {
	$ok = $bk->pushCatalog();
	$n = $ok ? $bk->pushLookups() : 0;
	setEventMessages($ok ? 'The website has the current catalog, and the lookup copy of '.$n.' people.' : 'The website did not accept it. Check the website address on the setup page and the website\'s DOLIBARR_API_KEY.', null, $ok ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$hidden = '<input type="hidden" name="token" value="'.newToken().'">';
llxHeader('', 'Training tools');
print load_fiche_titre('Training tools', $canedit ? '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=edit">New tool</a>' : '', 'members');
print '<p class="opacitymedium">Tools that need training before solo use. Each has a fee (members get the discount), a zone whose budget gets its share, and trainers with a weekly time people can book on the website. Every change goes to the website straight away.</p>';

// Website sync.
$version = $bk->version();
$has = getDolGlobalInt('ONBOARDING_WEBSITE_VERSION');
$seen = getDolGlobalInt('ONBOARDING_WEBSITE_SEEN');
$state = $seen ? ($has >= $version ? '<span class="badge badge-status4">up to date</span>' : '<span class="badge badge-status8">behind</span>') : '<span class="badge badge-status8">never checked in</span>';
print '<div class="info">Catalog version '.$version.'. Website has version '.($seen ? $has.', checked in '.dol_print_date($seen, 'dayhour') : '(none yet)').' '.$state;
if ($canedit) {
	print ' <form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">'.$hidden.'<button class="button smallpaddingimp" name="action" value="push">Send to the website now</button></form>';
}
print '</div>';

// Stats per tool.
$stats = array();
$p = $db->prefix();
$resql = $db->query("SELECT fk_tool, tool, COUNT(*) AS n, SUM(CASE WHEN source = 'paid' THEN amount ELSE 0 END) AS fees, SUM(zone_amount) AS zone FROM ".$p."onboarding_training WHERE entity = ".((int) $conf->entity)." AND status = 'recorded' GROUP BY fk_tool, tool");
while ($resql && ($o = $db->fetch_object($resql))) {
	$k = $o->fk_tool ? 'id'.$o->fk_tool : 'name'.$o->tool;
	$stats[$k] = $o;
}
$upcoming = array();
$resql = $db->query("SELECT fk_tool, COUNT(*) AS n FROM ".$p."onboarding_booking WHERE entity = ".((int) $conf->entity)." AND status IN ('paid', 'confirmed') AND session_start > '".$db->idate(OnboardingService::now())."' GROUP BY fk_tool");
while ($resql && ($o = $db->fetch_object($resql))) {
	$upcoming[(int) $o->fk_tool] = (int) $o->n;
}

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Zone</td><td>Tool</td><td class="right">Fee</td><td class="right">Members</td><td class="center">Seats</td><td>Trainers and times</td><td class="right">Trained</td><td class="right">Booked</td><td class="right">Fees</td><td></td></tr>';
$tools = $bk->tools();
foreach ($tools as $t) {
	$slots = array();
	foreach ($bk->slots((int) $t->rowid) as $s) {
		$slots[] = dol_escape_htmltag(trim($s->firstname.' '.$s->lastname)).' <span class="opacitymedium">'.OnboardingBooking::WEEKDAYS[(int) $s->weekday].' '.dol_escape_htmltag($s->start_time).'</span>';
	}
	$st = isset($stats['id'.$t->rowid]) ? $stats['id'.$t->rowid] : (isset($stats['name'.$t->label]) ? $stats['name'.$t->label] : null);
	print '<tr class="oddeven'.($t->active ? '' : ' opacitymedium').'"><td>'.dol_escape_htmltag($t->zone).'</td>';
	print '<td><strong>'.dol_escape_htmltag($t->label).'</strong> <span class="opacitymedium small">'.dol_escape_htmltag($t->ref).'</span>'.(!$t->active ? ' <span class="badge badge-status5">not in use</span>' : (!$t->bookable ? ' <span class="badge badge-status5">not bookable online</span>' : '')).'</td>';
	print '<td class="right">'.price($t->fee).'</td><td class="right">'.price(OnboardingBooking::memberPrice($t)).'</td><td class="center">'.((int) $t->seats).'</td>';
	print '<td>'.($slots ? implode('<br>', $slots) : '<span class="opacitymedium">no trainer yet</span>').'</td>';
	print '<td class="right">'.($st ? (int) $st->n : 0).'</td><td class="right">'.(isset($upcoming[(int) $t->rowid]) ? $upcoming[(int) $t->rowid] : 0).'</td><td class="right">'.price($st ? $st->fees : 0).'</td>';
	print '<td class="right"><a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=edit&id='.((int) $t->rowid).'">'.($canedit ? 'Edit' : 'Open').'</a></td></tr>';
}
if (!$tools) {
	print '<tr><td colspan="10"><span class="opacitymedium">No tools yet. Press New tool.</span></td></tr>';
}
print '</table></div>';

// The form for one tool.
if ($action == 'edit') {
	$t = $id ? $bk->tool($id) : null;
	$v = function ($k, $default = '') use ($t) {
		return dol_escape_htmltag($t ? (string) $t->$k : $default);
	};
	$zones = $bk->training->zones();
	print '<br>'.load_fiche_titre($t ? 'Edit: '.dol_escape_htmltag($t->label) : 'New tool', '', '');
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">'.$hidden.'<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="'.((int) $id).'">';
	print '<table class="border centpercent">';
	$row = function ($label, $field, $help = '') {
		print '<tr><td class="titlefield">'.$label.'</td><td>'.$field.($help !== '' ? '<br><span class="opacitymedium small">'.$help.'</span>' : '').'</td></tr>';
	};
	$ro = $canedit ? '' : ' disabled';
	$row('Name', '<input name="label" class="minwidth300" value="'.$v('label').'" required'.$ro.'>', 'As people see it, e.g. 3D printers.');
	$row('Id', '<input name="ref" class="minwidth200" value="'.$v('ref').'" required pattern="[a-z0-9][a-z0-9-]*"'.$ro.'>', 'Lowercase letters, digits and dashes, e.g. 3d-printers. The website uses it in links; never give it to another tool.');
	$zsel = '<select name="zone"'.$ro.'>';
	foreach ($zones as $z) {
		$zsel .= '<option'.($t && $t->zone == $z ? ' selected' : '').'>'.dol_escape_htmltag($z).'</option>';
	}
	$row('Zone', $zsel.'</select>', 'Its share of each fee goes to this zone\'s budget. Zones are listed on the module setup page.');
	$row('Fee', '<input name="fee" class="width75" value="'.($t ? price2num($t->fee) : '10').'"'.$ro.'>', 'What a non-member pays.');
	$row('Member discount (%)', '<input name="member_discount" class="width75" value="'.($t ? price2num($t->member_discount) : '50').'"'.$ro.'>', 'Paid-up members pay the fee less this. 50 means half price.');
	$row('Trainer\'s share (%)', '<input name="trainer_share" class="width75" value="'.($t && $t->trainer_share !== null ? price2num($t->trainer_share) : '').'"'.$ro.'>', 'Of what arrives after Givebutter\'s fee. Empty: the setup page\'s '.price2num(OnboardingTraining::trainerShare() * 100).'%.');
	$row('Seats per class', '<input name="seats" class="width75" value="'.($t ? (int) $t->seats : 1).'"'.$ro.'>', 'Most people booked on one session. A trainer\'s time can set its own.');
	$row('Length (minutes)', '<input name="minutes" class="width75" value="'.($t ? (int) $t->minutes : 60).'"'.$ro.'>');
	$row('Covers', '<input name="covers" class="minwidth400" value="'.$v('covers').'"'.$ro.'>', 'The machines one training covers, e.g. Bambu Lab X1 Carbon, Bambu Lab A1.');
	$row('Training documents', '<input name="wiki_url" class="minwidth400" value="'.$v('wiki_url').'"'.$ro.'>', 'The wiki page trainees read first.');
	$row('Website page', '<input name="page_url" class="minwidth400" value="'.$v('page_url').'"'.$ro.'>', 'e.g. /tools/3d-printers/ . The page\'s front matter needs <code>training: true</code> and <code>trainingTool: '.($t ? dol_escape_htmltag($t->ref) : '&lt;id&gt;').'</code>.');
	$row('Bookable online', '<input type="checkbox" name="bookable" value="1"'.(!$t || $t->bookable ? ' checked' : '').$ro.'>', 'Off: shown as needing training, but booked by asking.');
	$row('In use', '<input type="checkbox" name="active" value="1"'.(!$t || $t->active ? ' checked' : '').$ro.'>', 'Off hides it from the website and drops its Trainer tags. Records are kept.');
	print '</table>';
	if ($canedit) {
		print '<div class="center"><button class="button button-save" type="submit">Save</button> <a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">Close</a></div>';
	}
	print '</form>';

	if ($t) {
		print '<br><h3>Trainers and their times</h3>';
		print '<p class="opacitymedium">A trainer is a current member who is trained on this tool. Each weekly time is one session people can book. Adding someone gives them the "Trainer: '.dol_escape_htmltag($t->label).'" tag; removing their last time takes it away.</p>';
		print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre"><td>Trainer</td><td>Every</td><td>At</td><td class="right">Minutes</td><td class="right">Seats</td><td></td></tr>';
		$slots = $bk->slots($id);
		foreach ($slots as $s) {
			print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $s->fk_adherent).'">'.dol_escape_htmltag(trim($s->firstname.' '.$s->lastname)).'</a></td><td>'.OnboardingBooking::WEEKDAYS[(int) $s->weekday].'</td><td>'.dol_escape_htmltag($s->start_time).'</td>';
			print '<td class="right">'.((int) ($s->minutes ?: $t->minutes)).'</td><td class="right">'.((int) ($s->seats ?: $t->seats)).'</td><td class="right">';
			if ($canedit) {
				print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">'.$hidden.'<input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="slot" value="'.((int) $s->rowid).'"><button class="button smallpaddingimp" name="action" value="delslot">Remove</button></form>';
			}
			print '</td></tr>';
		}
		if (!$slots) {
			print '<tr><td colspan="6"><span class="opacitymedium">Nobody trains this tool yet, so it cannot be booked.</span></td></tr>';
		}
		print '</table></div>';
		if ($canedit) {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">'.$hidden.'<input type="hidden" name="action" value="addslot"><input type="hidden" name="id" value="'.$id.'">';
			print '<select name="trainer" class="minwidth200"><option value="0">Trainer…</option>';
			foreach ($bk->training->members() as $m) {
				if ((int) $m->statut == Adherent::STATUS_VALIDATED) {
					print '<option value="'.((int) $m->rowid).'">'.dol_escape_htmltag(trim($m->firstname.' '.$m->lastname)).($bk->isTrainedOn((int) $m->rowid, $t) ? '' : ' (not trained on it)').'</option>';
				}
			}
			print '</select> every <select name="weekday">';
			foreach (OnboardingBooking::WEEKDAYS as $n => $d) {
				print '<option value="'.$n.'"'.($n == 4 ? ' selected' : '').'>'.$d.'</option>';
			}
			print '</select> at <input name="time" class="width50" value="17:00"> for <input name="minutes" class="width50" placeholder="'.((int) $t->minutes).'"> minutes, <input name="seats" class="width50" placeholder="'.((int) $t->seats).'"> seats ';
			print '<button class="button smallpaddingimp" type="submit">Add</button></form>';
		}
	}
}

// Closed days.
print '<br><h3>Closed days</h3>';
print '<p class="opacitymedium">No training can be booked on these days. The website adds the days its calendar shows "Open Hack Night cancelled". Closing a day that has bookings asks those people to pick a new date, free.</p>';
print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre"><td>Day</td><td>Why</td><td>From</td><td></td></tr>';
$closed = $bk->upcomingClosed();
foreach ($closed as $c) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag(OnboardingBooking::fmt(OnboardingBooking::at(substr($c->day, 0, 10), '12:00'), 'l j F Y')).'</td><td>'.dol_escape_htmltag($c->reason).'</td><td>'.($c->source == 'calendar' ? 'the shop calendar' : 'by hand').'</td><td class="right">';
	if ($canedit && $c->source == 'hand') {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">'.$hidden.'<input type="hidden" name="closed" value="'.((int) $c->rowid).'"><button class="button smallpaddingimp" name="action" value="open">Reopen</button></form>';
	}
	print '</td></tr>';
}
if (!$closed) {
	print '<tr><td colspan="4"><span class="opacitymedium">None.</span></td></tr>';
}
print '</table></div>';
if ($canedit) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">'.$hidden.'<input type="hidden" name="action" value="close"><input type="date" name="day"> <input name="reason" class="minwidth300" placeholder="Why, e.g. Thanksgiving"> <button class="button smallpaddingimp" type="submit">Close this day</button></form>';
}

print '<br><h3>Changes</h3><div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre"><td class="right">Version</td><td>When</td><td>Who</td><td>What</td></tr>';
foreach ($bk->changes(15) as $c) {
	print '<tr class="oddeven"><td class="right">'.((int) $c->rowid).'</td><td>'.dol_print_date($db->jdate($c->datec), 'dayhour').'</td><td>'.dol_escape_htmltag($c->login ?: 'the module').'</td><td>'.dol_escape_htmltag($c->note).'</td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
