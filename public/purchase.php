<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The approve and reject links in purchase request emails. No login: each link is
 * signed for one request, one person and one action (see OnboardingPurchase::link).
 * Opening a link only shows the request, because mail scanners open links on their
 * own; it is decided only when the button is pressed.
 *
 *   GET  .../custom/onboarding/public/purchase.php?p=REQUEST&e=EMAIL&a=approve|reject&k=SIGNATURE
 *   POST the same, from the button
 *   GET  ...&a=view&n=1&k=SIGNATURE   one attached receipt, for the person the link was made for
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

dol_include_once('/onboarding/class/purchase.class.php');

/**
 * @param string $title Heading
 * @param string $html Body, already escaped
 * @param int $code HTTP status
 * @return never
 */
function onboarding_purchase_page($title, $html, $code = 200)
{
	global $db;
	http_response_code($code);
	header('Content-Type: text/html; charset=utf-8');
	header('X-Robots-Tag: noindex');
	header('Referrer-Policy: no-referrer');
	$org = dol_escape_htmltag(getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'Dolibarr'));
	print '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
	print '<meta name="robots" content="noindex"><title>'.dol_escape_htmltag($title).' - '.$org.'</title>';
	print '<style>body{font-family:system-ui,sans-serif;max-width:36rem;margin:3rem auto;padding:0 1rem;line-height:1.5;color:#222}';
	print 'h1{font-size:1.4rem}.meta{color:#555}dl{display:grid;grid-template-columns:8rem 1fr;gap:.2rem 1rem}dt{color:#555}dd{margin:0}';
	print 'button{font-size:1.05rem;padding:.6rem 1.4rem;border:0;border-radius:6px;color:#fff;cursor:pointer}.go{background:#2d6a4f}.no{background:#9b2226}';
	print '.ok{color:#2d6a4f;font-weight:600}</style></head><body>';
	print '<p class="meta">'.$org.'</p><h1>'.dol_escape_htmltag($title).'</h1>'.$html.'</body></html>';
	$db->close();
	exit;
}

$id = (int) GETPOST('p', 'int');
$email = OnboardingService::cleanEmail(GETPOST('e', 'alphanohtml'));
$act = GETPOST('a', 'aZ09');
$key = GETPOST('k', 'alphanohtml');
$n = (int) GETPOST('n', 'int');
$purchase = new OnboardingPurchase($db);

if (!isModEnabled('onboarding') || !$purchase->verify($id, $email, $act, $key, $n)) {
	onboarding_purchase_page('Link not valid', '<p>This link is not valid. Ask the treasurer to send it again.</p>', 403);
}
$row = $purchase->fetch($id);
if (!$row || $row->status == 'draft') {
	onboarding_purchase_page('Request not found', '<p>This request no longer exists.</p>', 404);
}

if ($act == 'view') {
	$path = $purchase->filePath($row, $n);
	if ($path === '') {
		onboarding_purchase_page('Not found', '<p>That receipt is not there.</p>', 404);
	}
	$types = array('pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'application/octet-stream');
	$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
	header('Content-Type: '.(isset($types[$ext]) ? $types[$ext] : 'application/octet-stream'));
	header('Content-Disposition: inline; filename="'.$row->ref.'-receipt-'.$n.'.'.$ext.'"');
	header('Cache-Control: private, no-store');
	header('X-Content-Type-Options: nosniff');
	readfile($path);
	exit;
}

$member = $purchase->memberById((int) $row->fk_adherent);
$who = $member ? trim($member->firstname.' '.$member->lastname) : 'A member';
$facts = '<dl><dt>Request</dt><dd>'.dol_escape_htmltag($row->ref).'</dd>';
$facts .= '<dt>From</dt><dd>'.dol_escape_htmltag($who).'</dd>';
$facts .= '<dt>Zone</dt><dd>'.dol_escape_htmltag($row->zone).' (available now '.dol_escape_htmltag(OnboardingPurchase::money($purchase->available($row->zone))).')</dd>';
$facts .= '<dt>Amount</dt><dd><strong>'.dol_escape_htmltag(OnboardingPurchase::money($row->amount)).'</strong></dd>';
$facts .= '<dt>Kind</dt><dd>'.dol_escape_htmltag(OnboardingPurchase::KINDS[$row->kind]).'</dd>';
if ($row->kind == 'purchase') {
	$facts .= '<dt>Link</dt><dd>'.dol_escape_htmltag($row->link).'</dd>';
} else {
	$facts .= '<dt>Bought at</dt><dd>'.dol_escape_htmltag($row->vendor).' on '.dol_escape_htmltag($row->purchase_date).'</dd>';
}
$facts .= '<dt>What and why</dt><dd>'.nl2br(dol_escape_htmltag($row->description)).'</dd>';
$links = array();
foreach ($purchase->files($row) as $i => $name) {
	$links[] = '<a href="'.dol_escape_htmltag($purchase->link($row->rowid, $email, 'view', $i + 1)).'">receipt '.($i + 1).'</a>';
}
$facts .= '<dt>Receipts</dt><dd>'.($links ? implode(', ', $links) : 'none').'</dd>';
$facts .= '<dt>Needs</dt><dd>'.($row->need_board ? 'the treasurer and one other director' : 'the zone lead').'</dd>';
$facts .= '</dl>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$result = $purchase->decide($id, $email, $act);
	$map = array(
		'approved' => array('Approved', '<p class="ok">Thank you. '.dol_escape_htmltag($row->ref).' is fully approved. The treasurer has been told to pay it.</p>'),
		'done' => array('Approval recorded', '<p class="ok">Thank you. Your approval is recorded. It still needs one more person before it can be paid.</p>'),
		'rejected' => array('Rejected', '<p>'.dol_escape_htmltag($row->ref).' was rejected and the member was told.</p>'),
		'already' => array('Already recorded', '<p>You already approved this one. Nothing changed.</p>'),
		'closed' => array('Already decided', '<p>This request is no longer waiting for a decision. Nothing changed.</p>'),
		'not-approver' => array('Not allowed', '<p>You are not one of the people who may decide this request.</p>'),
	);
	$r = isset($map[$result]) ? $map[$result] : array('Something went wrong', '<p>Nothing was changed.</p>');
	onboarding_purchase_page($r[0], $r[1], $result == 'not-approver' ? 403 : 200);
}

if ($row->status != 'pending') {
	onboarding_purchase_page('Already decided', $facts.'<p>This request is '.dol_escape_htmltag($row->status).'. Nothing more to do.</p>');
}
$yes = $act == 'approve';
$form = '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
$form .= '<input type="hidden" name="p" value="'.((int) $id).'"><input type="hidden" name="e" value="'.dol_escape_htmltag($email).'"><input type="hidden" name="a" value="'.dol_escape_htmltag($act).'"><input type="hidden" name="k" value="'.dol_escape_htmltag($key).'">';
$form .= '<button type="submit" class="'.($yes ? 'go' : 'no').'">'.($yes ? 'Approve' : 'Reject').' '.dol_escape_htmltag($row->ref).'</button></form>';
onboarding_purchase_page(($yes ? 'Approve' : 'Reject').' this request?', $facts.$form);
