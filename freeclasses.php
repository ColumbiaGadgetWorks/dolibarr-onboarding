<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Free classes: make one (a date of the monthly City of Columbia series, or an
 * extra class at any time), change it, see who signed up and tick who came, or
 * cancel it. Saving writes the class onto its Google Calendar event; people sign
 * up on the website's /classes/ page.
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

dol_include_once('/onboarding/class/freeclass.class.php');

if (!isModEnabled('onboarding') || !($user->admin || $user->hasRight('onboarding', 'freeclass', 'write'))) {
	accessforbidden();
}
$fc = new OnboardingFreeClass($db);
$action = GETPOST('action', 'aZ09');
$id = (int) GETPOST('id', 'int');
$self = $_SERVER['PHP_SELF'];

if ($action == 'save') {
	$in = array(
		'kind' => GETPOST('kind', 'aZ09'),
		'date' => GETPOST('date', 'alphanohtml'),
		'day' => GETPOST('day', 'alphanohtml'),
		'time' => GETPOST('time', 'alphanohtml'),
		'minutes' => GETPOST('minutes', 'int'),
		'title' => GETPOST('title', 'alphanohtml'),
		'description' => GETPOST('description', 'restricthtml'),
		'seats' => GETPOST('seats', 'int'),
		'instructor' => GETPOST('instructor', 'int'),
	);
	$r = $fc->saveClass($in, $user, $id);
	if (!$r['ok']) {
		setEventMessages($r['error'], null, 'errors');
		$action = $id ? '' : 'create';
	} else {
		setEventMessages(!empty($r['warning']) ? $r['warning'] : 'Saved, and the calendar is updated.', null, !empty($r['warning']) ? 'warnings' : 'mesgs');
		header('Location: '.$self.'?id='.((int) $r['id']));
		exit;
	}
}
if ($action == 'cancelclass' && $id) {
	$msg = $fc->cancelClass($id, GETPOST('why', 'restricthtml'));
	setEventMessages($msg, null, strpos($msg, 'Error') === 0 ? 'errors' : 'mesgs');
	header('Location: '.$self.'?id='.$id);
	exit;
}
if ($action == 'calendar' && $id) {
	$c = $fc->fetch($id);
	$ok = $c && $c->status == 'open' && $fc->writeCalendar($c);
	setEventMessages($ok ? 'The calendar event is written again.' : 'The calendar could not be updated: '.($c ? $fc->fetch($id)->gcal_error : ''), null, $ok ? 'mesgs' : 'errors');
	header('Location: '.$self.'?id='.$id);
	exit;
}
if ($action == 'attended' && $id) {
	$came = GETPOST('came', 'array');
	foreach ($fc->signups($id) as $s) {
		$fc->setAttended((int) $s->rowid, in_array((string) $s->rowid, (array) $came, true));
	}
	setEventMessages('Saved who came.', null);
	header('Location: '.$self.'?id='.$id);
	exit;
}

/**
 * @return array<int,string> Members to choose an instructor from
 */
function onboarding_freeclass_members()
{
	global $db;
	$out = array();
	$res = $db->query("SELECT rowid, firstname, lastname FROM ".$db->prefix()."adherent WHERE entity IN (".getEntity('adherent').") AND statut = 1 ORDER BY lastname, firstname");
	while ($res && ($o = $db->fetch_object($res))) {
		$out[(int) $o->rowid] = trim($o->firstname.' '.$o->lastname);
	}
	return $out;
}

/**
 * The form to make or change a class.
 *
 * @param OnboardingFreeClass $fc Classes
 * @param object|null $c Class to change
 * @return string HTML
 */
function onboarding_freeclass_form($fc, $c)
{
	global $self;
	$v = function ($k, $default = '') use ($c) {
		$posted = GETPOST($k, $k == 'description' ? 'restricthtml' : 'alphanohtml');
		if ($posted !== '' && $posted !== null) {
			return (string) $posted;
		}
		return $c && isset($c->$k) ? (string) $c->$k : $default;
	};
	$kind = $c ? $c->kind : GETPOST('kind', 'aZ09');
	$h = '<form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
	if ($c) {
		$h .= '<input type="hidden" name="id" value="'.((int) $c->rowid).'">';
	}
	$h .= '<table class="border centpercent">';
	if (!$c) {
		$h .= '<tr><td class="titlefield fieldrequired">Which kind</td><td>';
		foreach (OnboardingFreeClass::KINDS as $k => $label) {
			$h .= '<label style="display:block"><input type="radio" name="kind" value="'.$k.'"'.($kind == $k ? ' checked' : '').' onchange="onbKind()"> '.dol_escape_htmltag($label).'</label>';
		}
		$h .= '</td></tr>';
		$dates = $fc->freeDates();
		$h .= '<tr class="onb-oca"><td class="fieldrequired">Date</td><td>';
		if ($dates === null) {
			$h .= '<span class="error">The monthly class dates cannot be read from the Google Calendar'.($fc->gcal->error !== '' ? ': '.dol_escape_htmltag($fc->gcal->error) : '').'. An administrator sets up Google Calendar and the monthly series on the setup page (Free classes).</span>';
		} elseif (!$dates) {
			$h .= '<span class="opacitymedium">Every coming date of the monthly series already has a class.</span>';
		} else {
			$h .= '<select name="date">';
			foreach ($dates as $d) {
				$h .= '<option value="'.dol_escape_htmltag($d['id']).'"'.(GETPOST('date', 'alphanohtml') === $d['id'] ? ' selected' : '').'>'.dol_escape_htmltag(OnboardingBooking::fmt($d['start']).' ('.$d['minutes'].' minutes)').'</option>';
			}
			$h .= '</select><br><span class="opacitymedium small">The dates of the monthly series on the calendar. Its time stays as the calendar has it; only that date\'s name and description change.</span>';
		}
		$h .= '</td></tr>';
	} else {
		$h .= '<tr><td class="titlefield">Kind</td><td>'.dol_escape_htmltag(OnboardingFreeClass::KINDS[$c->kind]).'</td></tr>';
		if ($c->kind == 'oca') {
			$h .= '<tr><td>Date</td><td>'.dol_escape_htmltag($fc->when($c)).' <span class="opacitymedium small">(set by the monthly series; to move it, cancel this class and pick another date)</span></td></tr>';
		}
	}
	$day = $c && $c->kind == 'extra' ? OnboardingBooking::fmt($c->start, 'Y-m-d') : '';
	$time = $c && $c->kind == 'extra' ? OnboardingBooking::fmt($c->start, 'H:i') : '18:00';
	$h .= '<tr class="onb-extra"><td class="fieldrequired">Date and time</td><td><input type="date" name="day" value="'.dol_escape_htmltag($v('day', $day)).'"> <input type="time" name="time" value="'.dol_escape_htmltag($v('time', $time)).'"> for <input type="number" name="minutes" min="15" max="720" step="15" value="'.dol_escape_htmltag($v('minutes', '120')).'" style="width:5em"> minutes</td></tr>';
	$h .= '<tr><td class="fieldrequired">Class name</td><td><input type="text" name="title" maxlength="200" class="minwidth400" value="'.dol_escape_htmltag($v('title')).'" placeholder="Intro to soldering"><br><span class="opacitymedium small">The name on the calendar and the website.</span></td></tr>';
	$h .= '<tr><td class="tdtop">Description</td><td><textarea name="description" rows="6" class="quatrevingtpercent" maxlength="4000">'.dol_escape_htmltag($v('description'), 0, 1).'</textarea><br><span class="opacitymedium small">What people will do and leave with, and anything to bring. The sign-up link'.($c && $c->kind == 'extra' ? '' : ' (and, for a monthly class, the City of Columbia credit)').' is added on the calendar by itself.</span></td></tr>';
	$h .= '<tr><td class="fieldrequired">Class size</td><td><input type="number" name="seats" min="1" max="'.OnboardingFreeClass::MAX_SEATS.'" value="'.dol_escape_htmltag($v('seats', '12')).'" style="width:5em"> seats <span class="opacitymedium small">Sign-ups close when it is full.</span></td></tr>';
	$h .= '<tr><td>Instructor</td><td><select name="instructor"><option value="0">(not set)</option>';
	$cur = (int) $v('fk_instructor', (string) GETPOST('instructor', 'int'));
	foreach (onboarding_freeclass_members() as $mid => $name) {
		$h .= '<option value="'.$mid.'"'.($cur == $mid ? ' selected' : '').'>'.dol_escape_htmltag($name).'</option>';
	}
	$h .= '</select> <span class="opacitymedium small">Gets the class list the day before, with whoever made the class.</span></td></tr>';
	$h .= '</table><div class="center" style="margin-top:1em"><button type="submit" class="button button-save">'.($c ? 'Save and update the calendar' : 'Make the class and put it on the calendar').'</button> <a class="button button-cancel" href="'.$self.($c ? '?id='.((int) $c->rowid) : '').'">Cancel</a></div></form>';
	$h .= '<script>function onbKind(){var k=document.querySelector(\'input[name=kind]:checked\');k=k?k.value:\''.($c ? $c->kind : '').'\';document.querySelectorAll(\'.onb-oca\').forEach(function(e){e.style.display=k===\'oca\'?\'\':\'none\';});document.querySelectorAll(\'.onb-extra\').forEach(function(e){e.style.display=k===\'extra\'?\'\':\'none\';});}onbKind();</script>';
	return $h;
}

llxHeader('', 'Free classes');

if ($action == 'create') {
	print load_fiche_titre('New free class', '', 'calendar');
	print onboarding_freeclass_form($fc, null);
} elseif ($id && ($c = $fc->fetch($id))) {
	print load_fiche_titre(dol_escape_htmltag($c->title), '<a href="'.$self.'">All free classes</a>', 'calendar');
	$labels = array('open' => 'Open for sign-ups', 'done' => 'Over', 'cancelled' => 'Cancelled');
	print '<table class="border centpercent"><tr><td class="titlefield">When</td><td>'.dol_escape_htmltag($fc->when($c)).'</td></tr>';
	print '<tr><td>Kind</td><td>'.dol_escape_htmltag(OnboardingFreeClass::KINDS[$c->kind]).'</td></tr>';
	print '<tr><td>Status</td><td>'.dol_escape_htmltag($labels[$c->status]).((int) $c->signed >= (int) $c->seats && $c->status == 'open' ? ' <span class="badge badge-status4">Full</span>' : '').'</td></tr>';
	print '<tr><td>Seats</td><td>'.((int) $c->signed).' of '.((int) $c->seats).' taken</td></tr>';
	print '<tr><td>Calendar</td><td>'.($c->status == 'cancelled' ? '<span class="opacitymedium">Off the calendar</span>' : ($c->gcal_error ? '<span class="error">'.dol_escape_htmltag($c->gcal_error).'</span>' : ($c->gcal_written ? '<span class="ok">Written</span>' : '<span class="opacitymedium">Not written</span>')));
	if ($c->status == 'open') {
		print ' <form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="calendar"><input type="hidden" name="id" value="'.((int) $c->rowid).'"><button type="submit" class="button smallpaddingimp">Write it again</button></form>';
	}
	print '</td></tr><tr><td>Sign-up page</td><td><a href="'.dol_escape_htmltag(OnboardingFreeClass::pageUrl()).'#class-'.((int) $c->rowid).'" target="_blank" rel="noopener">'.dol_escape_htmltag(OnboardingFreeClass::pageUrl()).'</a></td></tr></table>';

	print '<h3>Who signed up</h3>';
	$people = $fc->signups((int) $c->rowid, '');
	print '<form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="attended"><input type="hidden" name="id" value="'.((int) $c->rowid).'">';
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>Name</td><td>Email</td><td>Record</td><td>Email updates</td><td>Signed up</td><td class="center">Came</td></tr>';
	$any = false;
	foreach ($people as $s) {
		$any = true;
		print '<tr class="oddeven'.($s->status != 'signed' ? ' opacitymedium' : '').'"><td>'.dol_escape_htmltag(trim($s->firstname.' '.$s->lastname)).($s->status != 'signed' ? ' (gave up the seat)' : '').'</td><td>'.dol_escape_htmltag($s->email).'</td><td>';
		if ((int) $s->fk_adherent > 0) {
			print '<a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $s->fk_adherent).'">Member card</a>';
		} elseif ((int) $s->fk_socpeople > 0) {
			print '<a href="'.DOL_URL_ROOT.'/contact/card.php?id='.((int) $s->fk_socpeople).'">Contact</a>';
		}
		print '</td><td>'.((int) $s->updates ? 'yes' : '').'</td><td>'.dol_print_date($db->jdate($s->datec), 'dayhour').'</td>';
		print '<td class="center">'.($s->status == 'signed' ? '<input type="checkbox" name="came[]" value="'.((int) $s->rowid).'"'.((int) $s->attended ? ' checked' : '').'>' : '').'</td></tr>';
	}
	if (!$any) {
		print '<tr><td colspan="6"><span class="opacitymedium">Nobody yet.</span></td></tr>';
	}
	print '</table>'.($any ? '<div class="right" style="margin-top:.5em"><button type="submit" class="button smallpaddingimp">Save who came</button></div>' : '').'</form>';

	if ($c->status == 'open') {
		print '<h3>Change it</h3>'.onboarding_freeclass_form($fc, $c);
		print '<h3>Cancel the class</h3><form method="POST" action="'.$self.'" onsubmit="return confirm(\'Cancel this class? It comes off the calendar and everyone signed up is emailed.\')"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="cancelclass"><input type="hidden" name="id" value="'.((int) $c->rowid).'">';
		print '<p class="opacitymedium">It comes off the Google Calendar (and so off the website and Discord), and everyone signed up is emailed.</p>';
		print '<textarea name="why" rows="2" class="quatrevingtpercent" placeholder="Why, or what next (optional, goes in the email)"></textarea><br><button type="submit" class="button">Cancel the class</button></form>';
	}
} else {
	print load_fiche_titre('Free classes', '<a class="butAction" href="'.$self.'?action=create">New free class</a>', 'calendar');
	print '<p class="opacitymedium">Free classes people sign up for on the website (<a href="'.dol_escape_htmltag(OnboardingFreeClass::pageUrl()).'" target="_blank" rel="noopener">'.dol_escape_htmltag(OnboardingFreeClass::pageUrl()).'</a>). A monthly OCA class is a date of the City of Columbia series already on the Google Calendar: saving it changes only that date\'s name and description. An extra class can be any date and time and gets its own calendar event. People get a reminder the day before, and the instructor and whoever made the class get the list.</p>';
	if (!OnboardingGcal::configured()) {
		print '<div class="warning">Google Calendar is not set up yet, so classes cannot be put on the calendar. An administrator does this once on the setup page, Free classes.</div>';
	}
	$labels = array('open' => 'Open', 'done' => 'Over', 'cancelled' => 'Cancelled');
	foreach (array('Coming' => "c.status = 'open'", 'Past and cancelled' => "c.status <> 'open'") as $head => $where) {
		print '<h3>'.$head.'</h3><table class="noborder centpercent"><tr class="liste_titre"><td>When</td><td>Class</td><td>Kind</td><td class="center">Seats taken</td><td>Status</td><td>Calendar</td></tr>';
		$rows = $fc->where($where, $head == 'Coming' ? 'c.session_start ASC' : 'c.session_start DESC LIMIT 30');
		foreach ($rows as $r) {
			print '<tr class="oddeven"><td>'.dol_escape_htmltag(OnboardingBooking::fmt($r->start, 'D j M Y, g:i A')).'</td><td><a href="'.$self.'?id='.((int) $r->rowid).'">'.dol_escape_htmltag($r->title).'</a></td><td>'.($r->kind == 'oca' ? 'Monthly OCA' : 'Extra').'</td>';
			print '<td class="center">'.((int) $r->signed).' / '.((int) $r->seats).'</td><td>'.dol_escape_htmltag($labels[$r->status]).((int) $r->signed >= (int) $r->seats && $r->status == 'open' ? ' <span class="badge badge-status4">Full</span>' : '').'</td>';
			print '<td>'.($r->status == 'cancelled' ? '<span class="opacitymedium">Off the calendar</span>' : ($r->gcal_error ? '<span class="error">'.dol_escape_htmltag(dol_trunc($r->gcal_error, 60)).'</span>' : ($r->gcal_written ? '<span class="ok">Written</span>' : ''))).'</td></tr>';
		}
		if (!$rows) {
			print '<tr><td colspan="6"><span class="opacitymedium">None.</span></td></tr>';
		}
		print '</table>';
	}
}

llxFooter();
$db->close();
