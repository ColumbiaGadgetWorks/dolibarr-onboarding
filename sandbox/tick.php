<?php
/* Sandbox only. Runs a scheduled job right now, optionally pretending it is
 * some days in the future, so reminders and lapses can be watched without waiting.
 *
 *   php tick.php daily 8      run reminders as if it were 8 days from now
 *   php tick.php sync         pull from the fake Givebutter
 *   php tick.php dump EMAIL   print what Dolibarr holds for one person
 */

if (php_sapi_name() !== 'cli') {
	die('CLI only');
}
define('NOSESSION', '1');
require '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/onboarding/class/onboarding.class.php');

$svc = new OnboardingService($db);
$user = $svc->actor();
$job = isset($argv[1]) ? $argv[1] : '';

if ($job == 'daily') {
	OnboardingService::$now = dol_now() + ((int) (isset($argv[2]) ? $argv[2] : 0)) * 86400;
	echo $svc->dunning().'; '.$svc->cleanup()."\n";
} elseif ($job == 'sync') {
	echo $svc->reconcile()."\n";
} elseif ($job == 'dump') {
	$app = $svc->findByEmail(isset($argv[2]) ? $argv[2] : '');
	$out = array('applicant' => $app, 'member' => null, 'contact_fields' => null);
	if ($app && $app->fk_adherent > 0) {
		$adh = new Adherent($db);
		$adh->fetch((int) $app->fk_adherent);
		$res = $db->query("SELECT COUNT(*) AS n FROM ".$db->prefix()."subscription WHERE fk_adherent = ".((int) $adh->id));
		$n = $res ? $db->fetch_object($res) : null;
		$out['member'] = array('id' => $adh->id, 'status' => (int) $adh->statut, 'typeid' => (int) $adh->typeid, 'paid_until' => $adh->datefin ? gmdate('Y-m-d', $adh->datefin) : null, 'subscriptions' => $n ? (int) $n->n : 0, 'fields' => $adh->array_options);
	}
	if ($app && $app->fk_socpeople > 0) {
		$c = new Contact($db);
		if ($c->fetch((int) $app->fk_socpeople) > 0) {
			$out['contact_fields'] = $c->array_options;
		}
	}
	if ($app) {
		unset($out['applicant']->token_hash);
		$out['files'] = array_map('basename', glob(DOL_DATA_ROOT.'/onboarding/applicant/'.((int) $app->rowid).'/*') ?: array());
	}
	echo json_encode($out)."\n";
} else {
	fwrite(STDERR, "Usage: php tick.php daily [days] | sync | dump EMAIL\n");
	exit(1);
}
