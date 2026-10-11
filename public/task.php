<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The "mark complete" link in deadline reminders. No login: the link is signed
 * for one task and one person (see OnboardingDeadlines::link). Opening it only
 * shows the task and a button, because mail scanners open links on their own;
 * the task changes only when the button is pressed.
 *
 *   GET  .../custom/onboarding/public/task.php?t=TASK&u=USER&k=SIGNATURE
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

dol_include_once('/onboarding/class/deadline.class.php');
require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';

/**
 * @param string $title Heading
 * @param string $html Body, already escaped
 * @param int $code HTTP status
 * @return never
 */
function onboarding_task_page($title, $html, $code = 200)
{
	global $db;
	http_response_code($code);
	header('Content-Type: text/html; charset=utf-8');
	header('X-Robots-Tag: noindex');
	header('Referrer-Policy: no-referrer');
	$org = dol_escape_htmltag(getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'Dolibarr'));
	print '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
	print '<meta name="robots" content="noindex"><title>'.dol_escape_htmltag($title).' - '.$org.'</title>';
	print '<style>body{font-family:system-ui,sans-serif;max-width:34rem;margin:3rem auto;padding:0 1rem;line-height:1.5;color:#222}';
	print 'h1{font-size:1.4rem}.meta{color:#555}button{font-size:1.05rem;padding:.6rem 1.4rem;border:0;border-radius:6px;background:#2d6a4f;color:#fff;cursor:pointer}';
	print '.ok{color:#2d6a4f;font-weight:600}</style></head><body>';
	print '<p class="meta">'.$org.'</p><h1>'.dol_escape_htmltag($title).'</h1>'.$html.'</body></html>';
	$db->close();
	exit;
}

$taskId = (int) GETPOST('t', 'int');
$userId = (int) GETPOST('u', 'int');
$key = GETPOST('k', 'alphanohtml');
$deadlines = new OnboardingDeadlines($db);

if (!isModEnabled('onboarding') || !isModEnabled('project') || !$deadlines->verify($taskId, $userId, $key)) {
	onboarding_task_page('Link not valid', '<p>This link is not valid. Open the task in Dolibarr instead.</p>', 403);
}
$task = new Task($db);
if ($task->fetch($taskId) <= 0) {
	onboarding_task_page('Task not found', '<p>This task no longer exists.</p>', 404);
}
$project = new Project($db);
$project->fetch((int) $task->fk_project);

$facts = '<p><strong>'.dol_escape_htmltag($task->label).'</strong><br><span class="meta">'.dol_escape_htmltag($project->title);
if ($task->date_end) {
	$facts .= ' &middot; due '.dol_escape_htmltag(OnboardingDeadlines::show(date('Y-m-d', $task->date_end)));
}
$facts .= ' &middot; '.((int) $task->progress).'% done</span></p>';
$card = '<p class="meta"><a href="'.dol_escape_htmltag(OnboardingDeadlines::cardUrl($taskId)).'">Open it in Dolibarr</a></p>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$result = $deadlines->complete($taskId, $userId);
	if ($result === 'done') {
		onboarding_task_page('Marked complete', '<p class="ok">Thank you. '.dol_escape_htmltag($task->label).' is marked complete, and the reminders have stopped.</p>'.$card);
	}
	if ($result === 'already') {
		onboarding_task_page('Already complete', '<p>'.dol_escape_htmltag($task->label).' was already marked complete. Nothing changed.</p>'.$card);
	}
	onboarding_task_page('Something went wrong', '<p>The task could not be updated. Mark it complete in Dolibarr instead.</p>'.$card, 500);
}

if ((int) $task->progress >= 100) {
	onboarding_task_page('Already complete', $facts.'<p>This task is already marked complete.</p>'.$card);
}
$form = '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
$form .= '<input type="hidden" name="t" value="'.((int) $taskId).'"><input type="hidden" name="u" value="'.((int) $userId).'"><input type="hidden" name="k" value="'.dol_escape_htmltag($key).'">';
$form .= '<button type="submit">Mark complete</button></form>';
onboarding_task_page('Mark this task complete?', $facts.$form.$card);
