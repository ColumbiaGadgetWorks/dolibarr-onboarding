<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The yearly filing page from the treasurer's emails (and the board's). No
 * login: the link is signed for one year and one address (see
 * OnboardingFiling::link). Opening it changes nothing; uploads and the buttons
 * are POSTs, so mail scanners that open links cannot press anything.
 *
 *   GET  .../custom/onboarding/public/filing.php?f=FILING&e=EMAIL&k=SIGNATURE
 *   GET  ...&file=DOC                one uploaded file
 *   POST the same, do=save|docsdone|irs|state|help
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}

$res = 0;
foreach (array('../../main.inc.php', '../../../main.inc.php', '../../../../main.inc.php') as $path) {
	if (!$res && file_exists(__DIR__.'/'.$path)) {
		$res = @include __DIR__.'/'.$path;
	}
}
if (!$res) {
	http_response_code(500);
	die('Include of main fails');
}

dol_include_once('/onboarding/class/filing.class.php');

/**
 * @param string $title Heading
 * @param string $html Body, already escaped
 * @param int $code HTTP status
 * @return never
 */
function onboarding_filing_page($title, $html, $code = 200)
{
	global $db;
	http_response_code($code);
	header('Content-Type: text/html; charset=utf-8');
	header('X-Robots-Tag: noindex');
	header('Referrer-Policy: no-referrer');
	$org = dol_escape_htmltag(getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'Dolibarr'));
	print '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
	print '<meta name="robots" content="noindex"><title>'.dol_escape_htmltag($title).' - '.$org.'</title>';
	print '<style>body{font-family:system-ui,sans-serif;max-width:46rem;margin:2rem auto;padding:0 1rem;line-height:1.5;color:#222}';
	print 'h1{font-size:1.5rem;margin-bottom:.2rem}h2{font-size:1.2rem;margin-top:2rem;border-bottom:2px solid #2d6a4f;padding-bottom:.2rem}h3{font-size:1rem;margin:0 0 .3rem}';
	print '.meta{color:#555}.small{font-size:.9rem}.msg{background:#e9f5ee;border-left:4px solid #2d6a4f;padding:.6rem .8rem}.err{background:#fbeaea;border-left:4px solid #9b2226;padding:.6rem .8rem}';
	print 'ol.steps{padding-left:1.3rem}ol.steps li.now{font-weight:600}ol.steps li.done{color:#2d6a4f}';
	print '.item{border:1px solid #ddd;border-radius:8px;padding:.8rem;margin:.8rem 0}.item.ok{border-color:#b7dcc6;background:#f6fbf8}';
	print '.months{display:flex;flex-wrap:wrap;gap:.25rem;margin:.3rem 0}.m{font-size:.8rem;padding:.1rem .4rem;border-radius:4px;background:#fbeaea;color:#9b2226}.m.file,.m.year,.m.reconciled{background:#e9f5ee;color:#2d6a4f}.m.none{background:#eee;color:#555}';
	print 'pre{white-space:pre-wrap;background:#f6f6f6;padding:.8rem;border-radius:6px;font-size:.9rem}';
	print 'button{font-size:1.05rem;padding:.55rem 1.3rem;border:0;border-radius:6px;background:#2d6a4f;color:#fff;cursor:pointer}button.second{background:#555}';
	print 'label{display:block;margin:.3rem 0}textarea{width:100%;min-height:4rem}input[type=text],input[type=date],select{font-size:1rem;padding:.2rem}';
	print '.ok{color:#2d6a4f;font-weight:600}ul.files{margin:.2rem 0 .4rem;padding-left:1.2rem}</style></head><body>';
	print '<p class="meta">'.$org.'</p><h1>'.dol_escape_htmltag($title).'</h1>'.$html.'</body></html>';
	$db->close();
	exit;
}

$id = (int) GETPOST('f', 'int');
$email = strtolower(trim(GETPOST('e', 'alphanohtml')));
$key = GETPOST('k', 'alphanohtml');
$filing = new OnboardingFiling($db);

if (!isModEnabled('onboarding') || !$filing->verify($id, $email, $key)) {
	onboarding_filing_page('Link not valid', '<p>This link is not valid. Use the newest email about the taxes, or ask the president to send it again.</p>', 403);
}
$f = $filing->fetch($id);
if (!$f) {
	onboarding_filing_page('Not found', '<p>This year\'s filing no longer exists.</p>', 404);
}
$fy = (int) $f->fy;
$self = $filing->link($id, $email);
$hidden = '<input type="hidden" name="f" value="'.$id.'"><input type="hidden" name="e" value="'.dol_escape_htmltag($email).'"><input type="hidden" name="k" value="'.dol_escape_htmltag($key).'">';

// One uploaded file, for the people the page is for.
$fileId = (int) GETPOST('file', 'int');
if ($fileId > 0) {
	$d = $filing->doc($f, $fileId);
	$path = $d ? OnboardingFiling::path($f, $d) : '';
	if ($path === '') {
		onboarding_filing_page('Not found', '<p>That file is not there.</p>', 404);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) (isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : 0) > 0) {
	// Over PHP's post_max_size: PHP drops the whole request.
	header('Location: '.$self.'&err='.rawurlencode('The files were too large for this server all at once. Upload fewer at a time.'), true, 303);
	exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$do = GETPOST('do', 'aZ09');
	$who = $filing->whoIs($email);
	$msgs = array();
	$errs = array();
	if ($do == 'save' && $f->status != 'done') {
		foreach ((array) GETPOST('rm', 'array') as $docId => $on) {
			if ($on) {
				$filing->remove($f, (int) $docId, $who);
			}
		}
		foreach ((array) GETPOST('assign', 'array') as $docId => $period) {
			if ((string) $period !== '') {
				$filing->assign($f, (int) $docId, (string) $period);
			}
		}
		$periods = (array) GETPOST('period', 'array');
		$kept = 0;
		if (!empty($_FILES['up']['name']) && is_array($_FILES['up']['name'])) {
			foreach ($_FILES['up']['name'] as $item => $names) {
				foreach ((array) $names as $i => $name) {
					if ((string) $name === '') {
						continue;
					}
					$err = (int) $_FILES['up']['error'][$item][$i];
					if ($err !== UPLOAD_ERR_OK) {
						$errs[] = $name.': '.($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE ? 'too large for this server' : 'the upload did not finish').'.';
						continue;
					}
					$why = $filing->addFile($f, (string) $item, isset($periods[$item]) ? (string) $periods[$item] : 'auto', (string) $name, (string) $_FILES['up']['tmp_name'][$item][$i], $who);
					if ($why === '') {
						$kept++;
					} else {
						$errs[] = $why;
					}
				}
			}
		}
		foreach ((array) GETPOST('none', 'array') as $item => $on) {
			if ($on) {
				$filing->markNone($f, (string) $item, $who);
			}
		}
		$left = $filing->missingCount($filing->fetch($id));
		$msgs[] = ($kept ? $kept.' file'.($kept == 1 ? '' : 's').' saved. ' : 'Saved. ').($left ? $left.' still missing.' : 'Everything is in: press All uploaded below.');
	} elseif ($do == 'docsdone') {
		$r = $filing->docsDone($f, $who, GETPOST('note', 'nohtml'));
		if ($r == 'note') {
			$errs[] = 'Some items are still missing. Say why in the box (for example "Venmo was not used"), or upload them, then press All uploaded again.';
		} else {
			$msgs[] = $r == 'done' ? 'Thank you. The next step is below, and it is also in your email.' : 'This step was already done.';
		}
	} elseif ($do == 'irs' || $do == 'state') {
		if (!empty($_FILES['proof']['name']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
			$why = $filing->addFile($f, $do, 'year', (string) $_FILES['proof']['name'], (string) $_FILES['proof']['tmp_name'], $who);
			if ($why !== '') {
				$errs[] = $why;
			}
		}
		if ($do == 'irs') {
			$amount = trim(GETPOST('receipts', 'alphanohtml'));
			$amount = $amount !== '' ? (float) price2num(str_replace(array('$', ','), '', $amount)) : null;
			$r = $filing->irsFiled($f, $who, GETPOST('day', 'alphanohtml'), $amount, GETPOST('form', 'alphanohtml'));
		} else {
			$r = $filing->stateFiled($f, $who, GETPOST('day', 'alphanohtml'));
		}
		$msgs[] = $r == 'done' ? 'Marked filed. Thank you.' : 'It was already marked filed.';
	} elseif ($do == 'help') {
		$msgs[] = $filing->help($f, $who, GETPOST('q', 'nohtml'), $email) ? 'Your question was sent to the president. They will reply by email.' : 'Type a question first.';
	}
	$to = $self.($msgs ? '&msg='.rawurlencode(implode(' ', $msgs)) : '').($errs ? '&err='.rawurlencode(implode(' ', $errs)) : '');
	header('Location: '.$to, true, 303);
	exit;
}

$f = $filing->fetch($id);
$facts = $filing->facts();
$irsDue = OnboardingFiling::irsDue($fy);
$stateDue = OnboardingFiling::stateDue($fy);
$h = '<p class="meta">'.dol_escape_htmltag(OnboardingFiling::showPeriod($fy)).' &middot; IRS tax year '.dol_escape_htmltag(OnboardingFiling::taxYear($fy)).' &middot; for '.dol_escape_htmltag($email).'</p>';
$msg = GETPOST('msg', 'alphanohtml');
$err = GETPOST('err', 'alphanohtml');
if ($msg !== '') {
	$h .= '<p class="msg">'.dol_escape_htmltag($msg).'</p>';
}
if ($err !== '') {
	$h .= '<p class="err">'.dol_escape_htmltag($err).'</p>';
}
$step = function ($done, $now, $text) {
	return '<li class="'.($done ? 'done' : ($now ? 'now' : '')).'">'.($done ? '&#10003; ' : '').$text.'</li>';
};
$h .= '<ol class="steps">';
$h .= $step($f->status != 'docs', $f->status == 'docs', 'Upload the year\'s statements and reports');
$h .= $step((bool) $f->irs_filed_at, $f->status == 'file' && !$f->irs_filed_at, 'File the IRS return, due '.dol_escape_htmltag(OnboardingFiling::show($irsDue)));
$h .= $step((bool) $f->state_filed_at, $f->status == 'file' && !$f->state_filed_at, 'File the '.dol_escape_htmltag($facts['state']).' annual registration report, due '.dol_escape_htmltag(OnboardingFiling::show($stateDue)));
$h .= '</ol>';

$months = OnboardingFiling::months($fy);
$checklist = $filing->checklist($f);
$fileLink = function ($d) use ($self) {
	return '<a href="'.dol_escape_htmltag($self.'&file='.((int) $d->rowid)).'">'.dol_escape_htmltag($d->original).'</a>';
};

// The statements: the upload form while collecting, a list of them afterwards.
$upload = '<form method="post" enctype="multipart/form-data" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">'.$hidden.'<input type="hidden" name="do" value="save">';
foreach ($checklist as $i) {
	$k = dol_escape_htmltag($i['key']);
	$badge = $i['state'] == 'done' ? '<span class="ok">done</span>' : ($i['state'] == 'none' ? '<span class="meta">none this year</span>' : '<span class="meta">'.($i['type'] == 'account' ? count($i['missing']).' month'.(count($i['missing']) == 1 ? '' : 's').' missing' : 'missing').'</span>');
	$upload .= '<div class="item'.($i['state'] != 'missing' ? ' ok' : '').'"><h3>'.dol_escape_htmltag($i['label']).' &middot; '.$badge.'</h3>';
	if ($i['help'] !== '') {
		$upload .= '<div class="small meta">'.dol_escape_htmltag($i['help']).'</div>';
	}
	if ($i['type'] == 'account') {
		$names = array('file' => 'uploaded', 'year' => 'in the whole-year file', 'reconciled' => 'reconciled in Dolibarr', 'none' => 'not used', 'missing' => 'missing');
		$upload .= '<div class="months">';
		foreach ($i['months'] as $ym => $st) {
			$upload .= '<span class="m '.$st.'" title="'.dol_escape_htmltag($names[$st]).'">'.dol_escape_htmltag(OnboardingFiling::showMonth($ym)).'</span>';
		}
		$upload .= '</div>';
	}
	if ($i['docs']) {
		$upload .= '<ul class="files">';
		foreach ($i['docs'] as $d) {
			if ($d->kind == 'none') {
				$upload .= '<li>Marked none by '.dol_escape_htmltag($d->who).($d->note ? ': '.dol_escape_htmltag($d->note) : '');
			} else {
				$upload .= '<li>'.$fileLink($d);
				if ($i['type'] == 'account') {
					if ($d->period === '' || $d->period === null) {
						$upload .= ' <span class="err small">which month?</span> <select name="assign['.((int) $d->rowid).']"><option value="">pick</option><option value="year">whole year</option>';
						foreach ($months as $ym) {
							$upload .= '<option value="'.$ym.'">'.dol_escape_htmltag(OnboardingFiling::showMonth($ym)).'</option>';
						}
						$upload .= '</select>';
					} else {
						$upload .= ' <span class="meta small">('.dol_escape_htmltag($d->period == 'year' ? 'whole year' : OnboardingFiling::showMonth($d->period)).')</span>';
					}
				}
			}
			$upload .= ' <label style="display:inline" class="small meta"><input type="checkbox" name="rm['.((int) $d->rowid).']" value="1"> take back</label></li>';
		}
		$upload .= '</ul>';
	}
	if ($i['state'] == 'missing') {
		$upload .= '<label class="small">Add files <input type="file" name="up['.$k.'][]" multiple></label>';
		if ($i['type'] == 'account') {
			$upload .= '<label class="small">The files are for <select name="period['.$k.']"><option value="auto">the month in each file\'s name</option><option value="year">the whole year (one file)</option>';
			foreach ($i['missing'] as $ym) {
				$upload .= '<option value="'.$ym.'">'.dol_escape_htmltag(OnboardingFiling::showMonth($ym)).'</option>';
			}
			$upload .= '</select></label>';
		}
		$upload .= '<label class="small"><input type="checkbox" name="none['.$k.']" value="1"> '.($i['type'] == 'account' ? 'This account was not used this year' : 'There is none this year').'</label>';
	}
	$upload .= '</div>';
}
if (!$checklist) {
	$upload .= '<p class="meta">Nothing is asked for: no money accounts or yearly reports are set up.</p>';
}
$upload .= '<button type="submit">Save</button></form>';

if ($f->status == 'docs') {
	$missing = $filing->missing($f);
	$h .= '<h2>1. Upload the year\'s statements</h2>';
	$h .= '<p>For each money account, the bank or service statements for every month from '.dol_escape_htmltag(OnboardingFiling::showMonth($months[0])).' to '.dol_escape_htmltag(OnboardingFiling::showMonth($months[11])).', or one file for the whole year. Files named with their month (for example <code>2025-06.pdf</code> or <code>June 2025.pdf</code>) are sorted by themselves. PDF, CSV, QFX, OFX, Excel or photos, up to 25 MB each.</p>';
	$h .= $upload;
	$h .= '<h2>When everything is in</h2><form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">'.$hidden.'<input type="hidden" name="do" value="docsdone">';
	if ($missing) {
		$h .= '<p>Still missing:</p><ul><li>'.implode('</li><li>', array_map('dol_escape_htmltag', $missing)).'</li></ul>';
		$h .= '<label>To go on without them, say why <textarea name="note" placeholder="For example: the bank is mailing the June statement"></textarea></label>';
	}
	$h .= '<button type="submit">All uploaded</button> <span class="small meta">You then get an email with everything needed to file.</span></form>';
} else {
	if ($f->status == 'file') {
		$form = $filing->form($f);
		if (!$f->irs_filed_at) {
			$h .= '<h2>2. File the IRS Form '.dol_escape_htmltag($form['form']).'</h2>';
			// Line breaks kept, and the addresses in it clickable.
			$h .= '<pre>'.preg_replace('#https?://[^\s<]+#', '<a href="$0">$0</a>', dol_escape_htmltag($filing->irsText($f, $form, $facts), 0, 1)).'</pre>';
			$h .= '<form method="post" enctype="multipart/form-data" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">'.$hidden.'<input type="hidden" name="do" value="irs">';
			$h .= '<label>Filed on <input type="date" name="day" value="'.OnboardingFiling::today().'"></label>';
			$h .= '<label>Form <select name="form">';
			foreach (array('990-N', '990-EZ', '990') as $x) {
				$h .= '<option'.($x == $form['form'] ? ' selected' : '').'>'.$x.'</option>';
			}
			$h .= '</select></label>';
			$h .= '<label>Gross receipts you reported for the year <input type="text" name="receipts" value="'.dol_escape_htmltag(number_format($form['this']['total'], 2, '.', '')).'"></label>';
			$h .= '<label class="small">Confirmation (optional: the IRS acceptance email as a PDF, or a screenshot) <input type="file" name="proof"></label>';
			$h .= '<button type="submit">IRS return filed</button></form>';
		}
		if (!$f->state_filed_at) {
			$h .= '<h2>3. File the '.dol_escape_htmltag($facts['state']).' annual registration report</h2>';
			$h .= '<p>File it at <a href="'.dol_escape_htmltag($facts['state_url']).'">'.dol_escape_htmltag($facts['state_url']).'</a>'.($facts['state_id'] !== '' ? ', charter number <strong>'.dol_escape_htmltag($facts['state_id']).'</strong>' : '').'. Due '.dol_escape_htmltag(OnboardingFiling::show($stateDue)).'.</p>';
			$officers = $filing->officers();
			if ($officers) {
				$h .= '<p>It asks for the officers and directors. Dolibarr\'s role tags say:</p><ul><li>'.implode('</li><li>', array_map('dol_escape_htmltag', $officers)).'</li></ul>';
			}
			$h .= '<form method="post" enctype="multipart/form-data" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">'.$hidden.'<input type="hidden" name="do" value="state">';
			$h .= '<label>Filed on <input type="date" name="day" value="'.OnboardingFiling::today().'"></label>';
			$h .= '<label class="small">Receipt (optional) <input type="file" name="proof"></label>';
			$h .= '<button type="submit">State report filed</button></form>';
		}
	}
	if ($f->irs_filed_at || $f->state_filed_at) {
		$h .= '<h2>Filed</h2><ul>';
		if ($f->irs_filed_at) {
			$h .= '<li class="ok">IRS Form '.dol_escape_htmltag($f->irs_form).': '.dol_escape_htmltag(OnboardingFiling::show($f->irs_filed_at)).', by '.dol_escape_htmltag($f->irs_filed_by).($f->receipts !== null ? ', gross receipts '.dol_escape_htmltag(OnboardingFiling::money($f->receipts)) : '').'</li>';
		}
		if ($f->state_filed_at) {
			$h .= '<li class="ok">'.dol_escape_htmltag($facts['state']).' report: '.dol_escape_htmltag(OnboardingFiling::show($f->state_filed_at)).', by '.dol_escape_htmltag($f->state_filed_by).'</li>';
		}
		$h .= '</ul>';
		$docs = $filing->docs($f->rowid);
		foreach (array('irs' => 'IRS confirmation', 'state' => 'State receipt') as $item => $label) {
			foreach (isset($docs[$item]) ? $docs[$item] : array() as $d) {
				$h .= '<p class="small">'.$label.': '.$fileLink($d).'</p>';
			}
		}
		if ($f->irs_filed_at) {
			$h .= '<p class="small meta">A few weeks after filing, the return should show on the <a href="'.OnboardingFiling::IRS_CHECK_URL.'">IRS Tax Exempt Organization Search</a> under EIN '.dol_escape_htmltag($facts['ein']).'.</p>';
		}
	}
	if ($f->status == 'done') {
		$h .= '<p class="ok">This year is done. Thank you.</p>';
	}
	$h .= '<h2>The year\'s statements</h2>';
	if ($f->docs_note) {
		$h .= '<p class="small">Note when they were sent: '.dol_escape_htmltag($f->docs_note).'</p>';
	}
	if ($f->status == 'done') {
		$h .= '<ul>';
		foreach ($checklist as $i) {
			$links = array();
			foreach ($i['docs'] as $d) {
				$links[] = $d->kind == 'none' ? 'none'.($d->note ? ' ('.dol_escape_htmltag($d->note).')' : '') : $fileLink($d);
			}
			$h .= '<li>'.dol_escape_htmltag($i['label']).': '.($links ? implode(', ', $links) : '<span class="meta">nothing uploaded</span>').'</li>';
		}
		$h .= '</ul>';
	} else {
		$h .= '<details><summary>Show them, or add one that came late</summary>'.$upload.'</details>';
	}
}

$h .= '<h2>A question?</h2><form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">'.$hidden.'<input type="hidden" name="do" value="help">';
$h .= '<label class="small meta">Goes to the president, who replies by email.<textarea name="q"></textarea></label><button type="submit" class="second">Send question</button></form>';
onboarding_filing_page('FY'.$fy.' taxes', $h);
