<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The buttons in an email-approval request. No login: the link carries the
 * held email's own random token. Opening a link only shows the email and a
 * button, because mail scanners open links on their own; nothing is sent or
 * rejected until the button is pressed.
 *
 *   GET  .../custom/onboarding/public/mail.php?h=ID&t=TOKEN[&do=approve|always|reject]
 *   POST the same, from the button
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

dol_include_once('/onboarding/class/mailgate.class.php');

/**
 * @param string $title Heading
 * @param string $html Body, already escaped
 * @param int $code HTTP status
 * @return never
 */
function onboarding_mail_page($title, $html, $code = 200)
{
	global $db;
	http_response_code($code);
	header('Content-Type: text/html; charset=utf-8');
	header('X-Robots-Tag: noindex');
	header('Referrer-Policy: no-referrer');
	$org = dol_escape_htmltag(getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'Dolibarr'));
	print '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
	print '<meta name="robots" content="noindex"><title>'.dol_escape_htmltag($title).' - '.$org.'</title>';
	print '<style>body{font-family:system-ui,sans-serif;max-width:46rem;margin:2.5rem auto;padding:0 1rem;line-height:1.5;color:#222}';
	print 'h1{font-size:1.4rem}.meta{color:#555}table{border-collapse:collapse;font-size:.95rem}td{padding:2px 12px 2px 0;vertical-align:top}td:first-child{color:#666}';
	print 'button{font-size:1rem;padding:.55rem 1.2rem;border:0;border-radius:6px;color:#fff;cursor:pointer;margin:0 .5rem .5rem 0}.ok{color:#2d6a4f;font-weight:600}';
	print '.box{margin:1rem 0;padding:1rem;border:1px solid #ccc;border-radius:6px;background:#fafafa;white-space:pre-wrap}iframe{width:100%;min-height:22rem;border:1px solid #ccc;border-radius:6px;background:#fff}</style></head><body>';
	print '<p class="meta">'.$org.' &middot; email approval</p><h1>'.dol_escape_htmltag($title).'</h1>'.$html.'</body></html>';
	$db->close();
	exit;
}

$id = (int) GETPOST('h', 'int');
$token = GETPOST('t', 'alphanohtml');
$gate = new OnboardingMailGate($db);
$row = isModEnabled('onboarding') ? $gate->byLink($id, $token) : null;
if (!$row) {
	onboarding_mail_page('Link not valid', '<p>This link is not valid. Open Email approval in Dolibarr instead.</p>', 403);
}
$labels = array('approve' => array('Approve and send', '#2d6a4f'), 'always' => array('Approve, and always allow this kind', '#1d4e89'), 'reject' => array('Reject', '#9b2226'));
$mailing = strpos((string) $row->kind, 'emailing-') === 0;
if ($mailing) {
	$labels['approve'][0] = 'Approve the mailing';
	unset($labels['always']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$what = GETPOST('do', 'aZ09');
	if (!isset($labels[$what])) {
		onboarding_mail_page('Choose what to do', '<p>Use one of the buttons.</p>', 400);
	}
	$result = $gate->decide($row->rowid, $what, OnboardingMailGate::approver().' (email link)');
	onboarding_mail_page($what == 'reject' ? 'Rejected' : 'Done', '<p class="ok">'.dol_escape_htmltag($result).'</p><p class="meta"><a href="'.dol_escape_htmltag(OnboardingMailGate::pageUrl()).'">Email approval in Dolibarr</a></p>');
}

$facts = '<table>';
foreach (array('To' => $row->mail_to, 'Cc' => $row->mail_cc, 'Bcc' => $row->mail_bcc, 'From' => $row->mail_from, 'Subject' => $row->subject, 'Kind' => $row->kind) as $k => $v) {
	if ((string) $v !== '') {
		$facts .= '<tr><td>'.$k.'</td><td>'.dol_escape_htmltag((string) $v).'</td></tr>';
	}
}
$names = array();
foreach ($gate->files($row) as $f) {
	$names[] = $f['name'];
}
if ($names) {
	$facts .= '<tr><td>Attached</td><td>'.dol_escape_htmltag(implode(', ', $names)).'</td></tr>';
}
$facts .= '</table>';
$body = (int) $row->ishtml ? '<iframe sandbox srcdoc="'.dol_escape_htmltag((string) $row->body, 0, 1).'"></iframe>' : '<div class="box">'.dol_escape_htmltag((string) $row->body, 0, 1).'</div>';

if ($row->status != 'pending') {
	onboarding_mail_page('Already decided', '<p>This email was already '.dol_escape_htmltag($row->status == 'sent' ? 'approved and sent' : $row->status).($row->decided_by ? ' by '.dol_escape_htmltag($row->decided_by) : '').'. Nothing to do.</p>'.$facts.$body);
}
$want = GETPOST('do', 'aZ09');
$form = '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'"><input type="hidden" name="h" value="'.((int) $row->rowid).'"><input type="hidden" name="t" value="'.dol_escape_htmltag($token).'">';
foreach ($labels as $k => $l) {
	if ($want === '' || $want === $k || !isset($labels[$want])) {
		$form .= '<button type="submit" name="do" value="'.$k.'" style="background:'.$l[1].'">'.dol_escape_htmltag($l[0]).'</button>';
	}
}
$form .= '</form>';
if (isset($labels[$want]) && count($labels) > 1) {
	$form .= '<p class="meta"><a href="?h='.((int) $row->rowid).'&t='.urlencode($token).'">Other choices</a></p>';
}
onboarding_mail_page($mailing ? 'Send this mailing?' : 'Send this email?', $facts.$body.$form);
