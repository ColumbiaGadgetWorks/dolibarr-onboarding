<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Update this module from GitHub, from its setup page: download the main
 * branch as a zip, back up the installed copy, put the new files in place
 * (files that no longer exist are removed), then disable and enable the
 * module in a second request, so the new code sets up its tables, menus and
 * jobs. The same as the docker exec command in the README, without a shell.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

/**
 * Self-update from GitHub.
 */
class OnboardingSelfUpdate
{
	/** Where the module comes from */
	const ZIP_URL = 'https://github.com/ColumbiaGadgetWorks/dolibarr-onboarding/archive/refs/heads/main.zip';
	const VERSION_URL = 'https://raw.githubusercontent.com/ColumbiaGadgetWorks/dolibarr-onboarding/main/core/modules/modOnboarding.class.php';

	/** @var DoliDB */
	public $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @return string The installed module's folder
	 */
	public static function moduleDir()
	{
		return dirname(__DIR__);
	}

	/**
	 * @return string Where downloads and backups go
	 */
	public static function workDir()
	{
		return DOL_DATA_ROOT.'/onboarding/updates';
	}

	/**
	 * @param string $file A copy of core/modules/modOnboarding.class.php
	 * @return string Its version, '' if none
	 */
	public static function versionIn($file)
	{
		return preg_match('/\$this->version\s*=\s*\'([0-9][0-9A-Za-z.\-]*)\'/', (string) $file, $m) ? $m[1] : '';
	}

	/**
	 * @return string The installed version
	 */
	public static function installed()
	{
		return self::versionIn(@file_get_contents(self::moduleDir().'/core/modules/modOnboarding.class.php'));
	}

	/**
	 * The version on GitHub, asked at most once an hour unless forced.
	 *
	 * @param bool $force Ask now
	 * @return string '' when GitHub could not be reached
	 */
	public function latest($force = false)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$cached = explode('|', getDolGlobalString('ONBOARDING_UPDATE_LATEST'), 2);
		if (!$force && count($cached) == 2 && (int) $cached[0] > dol_now() - 3600) {
			return $cached[1];
		}
		$r = getURLContent(getDolGlobalString('ONBOARDING_UPDATE_VERSION_URL', self::VERSION_URL), 'GET', '', 1, array(), array('http', 'https'), 2);
		$version = (!empty($r['http_code']) && $r['http_code'] == 200) ? self::versionIn($r['content']) : '';
		dolibarr_set_const($this->db, 'ONBOARDING_UPDATE_LATEST', dol_now().'|'.$version, 'chaine', 0, '', $conf->entity);
		return $version;
	}

	/**
	 * @return string '' when the module folder can be written, else why not
	 */
	public static function cannotWrite()
	{
		$dir = self::moduleDir();
		foreach (array($dir, $dir.'/core/modules/modOnboarding.class.php', $dir.'/class') as $path) {
			if (!is_writable($path)) {
				return 'Dolibarr cannot write to its copy of the module ('.$path.'). Run the update command from the README once; it gives the folder to the web server, and this button works from then on.';
			}
		}
		return '';
	}

	/**
	 * Download the newest version and put it in place.
	 *
	 * @return array{ok:bool,message:string,from?:string,to?:string}
	 */
	public function update()
	{
		$why = self::cannotWrite();
		if ($why !== '') {
			return array('ok' => false, 'message' => $why);
		}
		$work = self::workDir();
		dol_mkdir($work);
		$r = getURLContent(getDolGlobalString('ONBOARDING_UPDATE_ZIP_URL', self::ZIP_URL), 'GET', '', 1, array(), array('http', 'https'), 2);
		if (empty($r['http_code']) || $r['http_code'] != 200 || strlen((string) $r['content']) < 1000) {
			return array('ok' => false, 'message' => 'Could not download the module from GitHub (HTTP '.(isset($r['http_code']) ? $r['http_code'] : '?').(isset($r['curl_error_msg']) && $r['curl_error_msg'] ? ', '.$r['curl_error_msg'] : '').'). Nothing was changed.');
		}
		$zip = $work.'/download.zip';
		file_put_contents($zip, $r['content']);
		return $this->install($zip, self::moduleDir());
	}

	/**
	 * Put the module from a downloaded zip into a folder, keeping a backup.
	 *
	 * @param string $zip The zip, as GitHub makes it (one top folder)
	 * @param string $dir The module's folder
	 * @return array{ok:bool,message:string,from?:string,to?:string}
	 */
	public function install($zip, $dir)
	{
		$work = self::workDir();
		$tmp = $work.'/new';
		dol_delete_dir_recursive($tmp);
		dol_mkdir($tmp);
		$res = dol_uncompress($zip, $tmp);
		if (!empty($res['error'])) {
			return array('ok' => false, 'message' => 'The download could not be unpacked: '.$res['error'].'. Nothing was changed.');
		}
		// GitHub puts everything in one folder named after the repository and branch.
		$src = '';
		foreach (glob($tmp.'/*', GLOB_ONLYDIR) ?: array() as $d) {
			if (is_file($d.'/core/modules/modOnboarding.class.php')) {
				$src = $d;
			}
		}
		$to = $src !== '' ? self::versionIn(@file_get_contents($src.'/core/modules/modOnboarding.class.php')) : '';
		if ($src === '' || $to === '' || !is_file($src.'/class/onboarding.class.php')) {
			dol_delete_dir_recursive($tmp);
			return array('ok' => false, 'message' => 'The download does not look like this module. Nothing was changed.');
		}
		$from = self::versionIn(@file_get_contents($dir.'/core/modules/modOnboarding.class.php'));

		// Keep the installed copy, and the two before it.
		$backup = $work.'/backup-'.($from !== '' ? $from : 'unknown').'-'.dol_print_date(dol_now(), '%Y%m%d%H%M%S');
		if (dolCopyDir($dir, $backup, '0', 1) < 0) {
			dol_delete_dir_recursive($tmp);
			return array('ok' => false, 'message' => 'Could not back up the installed module to '.$backup.'. Nothing was changed.');
		}
		$backups = glob($work.'/backup-*', GLOB_ONLYDIR) ?: array();
		usort($backups, function ($a, $b) {
			return filemtime($b) - filemtime($a);
		});
		foreach (array_slice($backups, 3) as $old) {
			dol_delete_dir_recursive($old);
		}

		if (dolCopyDir($src, $dir, '0', 1) < 0) {
			// Put the old files back rather than leave half an update.
			dolCopyDir($backup, $dir, '0', 1);
			dol_delete_dir_recursive($tmp);
			return array('ok' => false, 'message' => 'Copying the new files failed, so the installed module was put back as it was.');
		}
		// Files the new version no longer has.
		$removed = 0;
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) {
			$rel = substr($f->getPathname(), strlen($dir));
			if (!file_exists($src.$rel)) {
				$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
				$removed++;
			}
		}
		dol_delete_dir_recursive($tmp);
		@unlink($zip);
		if (function_exists('opcache_reset')) {
			@opcache_reset();
		}
		return array('ok' => true, 'from' => $from, 'to' => $to, 'message' => 'Module files updated from '.($from !== '' ? $from : '?').' to '.$to.($removed ? ', '.$removed.' old files removed' : '').'. Backup: '.$backup.'.');
	}

	/**
	 * Disable and enable the module, as the Modules page does, so the new
	 * version sets up its tables, fields, menus and jobs. Settings are kept.
	 *
	 * @return string What happened
	 */
	public function reenable()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$off = unActivateModule('modOnboarding', 0);
		if ($off) {
			return 'Turning the module off failed: '.$off;
		}
		// 1: do not trust the cached "already on", which still says yes until the
		// next request.
		$on = activateModule('modOnboarding', 0, 1);
		if (!empty($on['errors'])) {
			return 'Turning the module back on failed: '.implode(' ', (array) $on['errors']).' Turn it on in Home, Setup, Modules.';
		}
		$res = $this->db->query("SELECT value FROM ".$this->db->prefix()."const WHERE name = 'MAIN_MODULE_ONBOARDING'");
		if (!$res || !$this->db->fetch_object($res)) {
			return 'The module did not come back on. Turn Member onboarding on in Home, Setup, Modules.';
		}
		return '';
	}
}
