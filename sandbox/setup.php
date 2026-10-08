<?php
/* Sandbox only. Run inside the sandbox Dolibarr container on first start:
 * turns on the modules and points everything at the fake Givebutter and the
 * mail catcher. Never run this against a real Dolibarr.
 */

if (php_sapi_name() !== 'cli') {
	die('CLI only');
}
if (getenv('ONBOARDING_SANDBOX') !== '1') {
	fwrite(STDERR, "Refusing to run: ONBOARDING_SANDBOX is not 1.\n");
	exit(1);
}

define('NOSESSION', '1');
require '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

$user = new User($db);
$user->fetch(0, getenv('DOLI_ADMIN_LOGIN') ?: 'admin');
$user->getrights();
if ($user->id <= 0) {
	fwrite(STDERR, "Admin user not found.\n");
	exit(1);
}

foreach (array('modSociete', 'modAdherent', 'modCategorie', 'modMailing', 'modCron', 'modOnboarding') as $module) {
	$r = activateModule($module);
	if (!empty($r['errors'])) {
		fwrite(STDERR, $module.': '.implode('; ', $r['errors'])."\n");
		exit(1);
	}
	echo "Enabled ".$module."\n";
}

$host = getenv('SANDBOX_HOST') ?: 'localhost';
$consts = array(
	'ONBOARDING_API_KEY' => getenv('ONBOARDING_API_KEY') ?: 'sandbox-key-sandbox-key-sandbox-key',
	'ONBOARDING_GB_API_KEY' => 'sandbox',
	'ONBOARDING_GB_API_BASE' => 'http://givebutter:8090/v1',
	'ONBOARDING_GB_CAMPAIGN_CODE' => 'SANDBOX',
	'ONBOARDING_TRAINING_CAMPAIGN_CODE' => 'TRAINING',
	'ONBOARDING_TRAINING_LOOKUP_URL' => 'http://'.$host.':8787/training/',
	'ONBOARDING_CHECKOUT_URL' => 'http://'.$host.':8090/checkout',
	'ONBOARDING_JOIN_URL' => 'http://'.$host.':8787/membership/join/',
	'ONBOARDING_STAFF_EMAIL' => 'membership-team@example.test',
	'MAIN_MAIL_SENDMODE' => 'smtps',
	'MAIN_MAIL_SMTP_SERVER' => 'mail',
	'MAIN_MAIL_SMTP_PORT' => '1025',
	'MAIN_MAIL_EMAIL_TLS' => '0',
	'MAIN_MAIL_EMAIL_STARTTLS' => '0',
	'MAIN_MAIL_EMAIL_FROM' => 'dolibarr@example.test',
	'MAIN_INFO_SOCIETE_NOM' => 'Sandbox Makerspace',
	'MAIN_DISABLE_ALL_MAILS' => '0',
);
foreach ($consts as $name => $value) {
	dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity);
}
// Let the admin see everything, including ID photos.
$user->addrights(0, 'onboarding');
echo "Sandbox configured.\n";
