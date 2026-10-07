<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Serves an applicant's signed documents and ID photo to logged-in staff.
 * The ID photo needs its own permission.
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

$id = (int) GETPOST('id', 'int');
$which = GETPOST('file', 'aZ09');

if (!isModEnabled('onboarding') || !in_array($which, array('waiver', 'agreement', 'id'))) {
	accessforbidden();
}
if ($which == 'id' ? !$user->hasRight('onboarding', 'idphoto', 'read') : !$user->hasRight('onboarding', 'applicant', 'read')) {
	accessforbidden();
}

$svc = new OnboardingService($db);
$app = $svc->fetch($id);
if (!$app) {
	accessforbidden();
}

$dir = DOL_DATA_ROOT.'/onboarding/applicant/'.((int) $app->rowid);
$path = '';
if ($which == 'id') {
	if (!empty($app->id_file)) {
		$path = $dir.'/'.basename($app->id_file);
	}
} else {
	foreach (array('pdf', 'txt') as $ext) {
		if (!$path && is_readable($dir.'/'.$which.'.'.$ext)) {
			$path = $dir.'/'.$which.'.'.$ext;
		}
	}
}
if (!$path || !is_readable($path)) {
	http_response_code(404);
	print 'File not found';
	exit;
}

$types = array('pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: '.(isset($types[$ext]) ? $types[$ext] : 'application/octet-stream'));
header('Content-Disposition: inline; filename="applicant-'.((int) $app->rowid).'-'.$which.'.'.$ext.'"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
