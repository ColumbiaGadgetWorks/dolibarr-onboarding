<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

/**
 * The automatic parts of the information security and records policy.
 *
 * Removing access when a role ends: a Dolibarr login linked to a member card is
 * disabled when the membership ends, and an administrator's when their member
 * card no longer has an officer tag. The person and the security owner are
 * warned first, and the login goes a few days later if nothing changes, so a tag
 * taken off by mistake can be put back. The security owner then gets the list of
 * the other accounts to remove the person from. Logins not linked to a member
 * card (script accounts, the installation's first administrator) are left alone, and the
 * last working administrator is never disabled.
 *
 * Retention: bank transactions read from the banks (statement uploads and bank
 * feeds) are deleted, with the uploaded files, once older than the years set.
 *
 * The Access review page lists every login and what would happen to it, for the
 * yearly review.
 */
class OnboardingSecurity
{
	/** @var DoliDB */
	public $db;

	/** @var OnboardingService */
	public $svc;

	/** The officer tags on member cards. An administrator must hold one. */
	const OFFICER_ROLES = array('president', 'vice-president', 'secretary', 'treasurer');

	/** Days between the warning and the login being disabled. */
	const GRACE_DAYS = 3;

	/** Where the warnings stand: {user id: when first seen}. */
	const SEEN = 'ONBOARDING_DEPROVISION_SEEN';

	const CHECKLIST = "Google Workspace (the organization's email and Drive)\nVoidAuth (sign-in)\nCloudflare\nGitHub (the ColumbiaGadgetWorks organization)\nCentral Bank and US Bank online banking\nPayPal, Givebutter and Venmo\nPlaid dashboard\nDiscord (admin and officer roles)\nThe password manager: change every shared password they knew";

	/**
	 * @param DoliDB $db Database handler
	 * @param OnboardingService|null $svc Shared service
	 */
	public function __construct($db, $svc = null)
	{
		$this->db = $db;
		$this->svc = $svc ?: new OnboardingService($db);
	}

	/**
	 * @return bool Logins are disabled by the daily job (the setting is not "off")
	 */
	public static function enabled()
	{
		return strtolower(trim(getDolGlobalString('ONBOARDING_DEPROVISION', 'on'))) !== 'off';
	}

	/**
	 * @return string[] The security owner's email addresses
	 */
	public function owners()
	{
		$set = array();
		foreach (preg_split('/[\s,;]+/', getDolGlobalString('ONBOARDING_SECURITY_EMAIL')) as $e) {
			$e = OnboardingService::cleanEmail($e);
			if ($e !== '' && strpos($e, '@') !== false) {
				$set[$e] = $e;
			}
		}
		if (!$set) {
			dol_include_once('/onboarding/class/purchase.class.php');
			$pu = new OnboardingPurchase($this->db, $this->svc);
			foreach ($pu->roleEmails(array('security')) as $e) {
				$set[$e] = $e;
			}
			if (!$set) {
				foreach ($pu->roleEmails(array('secretary')) as $e) {
					$set[$e] = $e;
				}
			}
		}
		if (!$set && getDolGlobalString('ONBOARDING_STAFF_EMAIL') !== '') {
			$set[] = getDolGlobalString('ONBOARDING_STAFF_EMAIL');
		}
		return array_values($set);
	}

	/**
	 * Every working login, with its member card and what the policy says about it.
	 *
	 * @return object[] rowid, login, name, email, admin, datelastlogin, fk_member,
	 *                  member (name), member_status, roles, reason ('' when it may stay),
	 *                  since and disable_on (when warned)
	 */
	public function logins()
	{
		global $conf;
		$p = $this->db->prefix();
		$seen = $this->seen();
		$out = array();
		$res = $this->db->query("SELECT u.rowid, u.login, u.firstname, u.lastname, u.email, u.admin, u.datelastlogin, u.fk_member, a.statut AS member_status, a.firstname AS mfirst, a.lastname AS mlast FROM ".$p."user AS u LEFT JOIN ".$p."adherent AS a ON a.rowid = u.fk_member WHERE u.statut = 1 AND u.entity IN (0, ".((int) $conf->entity).") ORDER BY u.admin DESC, u.login ASC");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$o->name = trim($o->firstname.' '.$o->lastname);
			$o->member = trim($o->mfirst.' '.$o->mlast);
			$o->roles = array();
			$out[(int) $o->rowid] = $o;
		}
		$ids = array();
		foreach ($out as $o) {
			if ((int) $o->fk_member > 0) {
				$ids[] = (int) $o->fk_member;
			}
		}
		if ($ids) {
			$in = array();
			foreach (self::OFFICER_ROLES as $r) {
				$in[] = "'".$this->db->escape($r)."'";
			}
			$res = $this->db->query("SELECT cm.fk_member, LOWER(c.label) AS role FROM ".$p."categorie_member AS cm INNER JOIN ".$p."categorie AS c ON c.rowid = cm.fk_categorie WHERE cm.fk_member IN (".implode(',', $ids).") AND LOWER(c.label) IN (".implode(',', $in).")");
			$roles = array();
			while ($res && ($o = $this->db->fetch_object($res))) {
				$roles[(int) $o->fk_member][] = $o->role;
			}
			foreach ($out as $o) {
				$o->roles = isset($roles[(int) $o->fk_member]) ? $roles[(int) $o->fk_member] : array();
			}
		}
		foreach ($out as $id => $o) {
			$o->reason = $this->reason($o);
			$o->since = $o->reason !== '' && isset($seen[$id]) ? (int) $seen[$id] : null;
			$o->disable_on = $o->since ? $o->since + self::GRACE_DAYS * 86400 : null;
		}
		return array_values($out);
	}

	/**
	 * @param object $o A row from logins()
	 * @return string Why the policy says this login should go, or ''
	 */
	private function reason($o)
	{
		if ((int) $o->fk_member <= 0) {
			return '';
		}
		if ($o->member_status === null) {
			return 'its member card was deleted';
		}
		if ((int) $o->member_status != 1) {
			return 'the membership has ended';
		}
		if ((int) $o->admin && !$o->roles) {
			return 'an administrator whose member card no longer has an officer tag ('.implode(', ', self::OFFICER_ROLES).')';
		}
		return '';
	}

	/**
	 * @return array<int,int> Logins warned: user id => when
	 */
	private function seen()
	{
		$v = json_decode(getDolGlobalString(self::SEEN), true);
		return is_array($v) ? $v : array();
	}

	/**
	 * The daily step: warn about logins that should go, disable those warned long
	 * enough ago, and forget warnings about logins that were put right.
	 *
	 * @return string Summary
	 */
	public function run()
	{
		global $conf;
		if (!self::enabled()) {
			return 'removing logins: off';
		}
		$now = OnboardingService::now();
		$seen = $this->seen();
		$keep = array();
		$warned = $disabled = 0;
		$stuck = false;
		$logins = $this->logins();
		$admins = 0;
		foreach ($logins as $o) {
			$admins += (int) $o->admin;
		}
		foreach ($logins as $o) {
			$id = (int) $o->rowid;
			if ($o->reason === '') {
				continue;
			}
			if (!isset($seen[$id])) {
				$keep[$id] = $now;
				$this->warn($o, $now + self::GRACE_DAYS * 86400);
				$warned++;
				continue;
			}
			if ($now < (int) $seen[$id] + self::GRACE_DAYS * 86400) {
				$keep[$id] = (int) $seen[$id];
				continue;
			}
			if ((int) $o->admin && $admins <= 1) {
				$keep[$id] = (int) $seen[$id];
				$stuck = true;
				$this->svc->alert('deprovision-last-admin', 'The last administrator was not disabled', 'The login '.$o->login.' should be disabled because '.$o->reason.', but it is the only working administrator left. Give another officer an administrator login, or put this one right.');
				continue;
			}
			$u = new User($this->db);
			if ($u->fetch($id) <= 0 || $u->setstatus(0) < 0) {
				$keep[$id] = (int) $seen[$id];
				dol_syslog('Onboarding: could not disable login '.$o->login.': '.$u->error, LOG_ERR);
				continue;
			}
			$admins -= (int) $o->admin;
			$this->removed($o);
			$disabled++;
		}
		if (!$stuck) {
			$this->svc->alertClear('deprovision-last-admin', 'No administrator is waiting to be disabled any more.');
		}
		dolibarr_set_const($this->db, self::SEEN, json_encode($keep, JSON_FORCE_OBJECT), 'chaine', 0, 'Logins warned that they will be disabled', $conf->entity);
		return 'removing logins: '.$warned.' warned, '.$disabled.' disabled';
	}

	/**
	 * Tell the person and the security owner that a login is about to go.
	 *
	 * @param object $o Login
	 * @param int $when When it will be disabled
	 * @return void
	 */
	private function warn($o, $when)
	{
		$who = $o->name !== '' ? $o->name : $o->login;
		$body = "The Dolibarr login ".$o->login." (".$who.") will be disabled on ".dol_print_date($when, 'day')." because ".$o->reason.".\n\n"
			."This follows the information security and records policy: access ends when a role or membership ends.\n\n"
			."If this is a mistake, put it right before then: renew the membership, or put the officer tag back on the member card (Members, the member, Tags). Nothing else needs doing.\n\n"
			."Access review: ".dol_buildpath('/onboarding/security.php', 2);
		$to = $this->owners();
		$e = OnboardingService::cleanEmail((string) $o->email);
		if ($e !== '' && strpos($e, '@') !== false && !in_array($e, $to, true)) {
			$to[] = $e;
		}
		$this->svc->mail(implode(', ', $to), 'Dolibarr login '.$o->login.' will be disabled on '.dol_print_date($when, 'day'), $body);
	}

	/**
	 * Tell the security owner a login was disabled, with the other accounts to check.
	 *
	 * @param object $o Login
	 * @return void
	 */
	private function removed($o)
	{
		$who = $o->name !== '' ? $o->name : $o->login;
		$list = trim(getDolGlobalString('ONBOARDING_SECURITY_CHECKLIST'));
		if ($list === '') {
			$list = self::CHECKLIST;
		}
		$items = array();
		foreach (preg_split('/\r?\n/', $list) as $l) {
			if (trim($l) !== '') {
				$items[] = '[ ] '.trim($l);
			}
		}
		$body = "The Dolibarr login ".$o->login." (".$who.") was disabled today because ".$o->reason.".\n\n"
			."The policy gives 7 days to remove them everywhere else. Remove them from each of these that they had:\n\n".implode("\n", $items)."\n\n"
			."Access review: ".dol_buildpath('/onboarding/security.php', 2);
		$this->svc->mail(implode(', ', $this->owners()), 'Dolibarr login '.$o->login.' disabled: remove '.$who.' from the other accounts', $body);
	}

	/**
	 * @return int How many years bank transactions read from the banks are kept (0: forever)
	 */
	public static function bankYears()
	{
		return max(0, (int) getDolGlobalString('ONBOARDING_BANKDATA_YEARS', '7'));
	}

	/**
	 * @return string|null The first day still kept (Y-m-d), or null when kept forever
	 */
	public static function bankCutoff()
	{
		$y = self::bankYears();
		return $y ? date('Y-m-d', strtotime('-'.$y.' years', OnboardingService::now())) : null;
	}

	/**
	 * Delete bank transactions read from the banks that are past the years kept,
	 * with uploaded statement files that only covered such days. The Dolibarr bank
	 * entries (the books) stay.
	 *
	 * @return string Summary
	 */
	public function purge()
	{
		global $conf;
		$cut = self::bankCutoff();
		if ($cut === null) {
			return 'old bank data: kept';
		}
		$p = $this->db->prefix();
		$files = 0;
		$res = $this->db->query("SELECT rowid, fk_account, filename FROM ".$p."onboarding_stmt_file WHERE entity = ".((int) $conf->entity)." AND source = 'upload' AND date_to IS NOT NULL AND date_to < '".$this->db->escape($cut)."'");
		$rows = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$rows[] = $o;
		}
		foreach ($rows as $o) {
			if ((string) $o->filename !== '') {
				@unlink(DOL_DATA_ROOT.'/onboarding/statements/'.((int) $o->fk_account).'/'.basename($o->filename));
			}
			$this->db->query("DELETE FROM ".$p."onboarding_stmt WHERE fk_file = ".((int) $o->rowid));
			$this->db->query("DELETE FROM ".$p."onboarding_stmt_file WHERE rowid = ".((int) $o->rowid));
			$files++;
		}
		$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$p."onboarding_stmt WHERE entity = ".((int) $conf->entity)." AND dateo < '".$this->db->escape($cut)."'");
		$o = $res ? $this->db->fetch_object($res) : null;
		$lines = $o ? (int) $o->n : 0;
		if ($lines) {
			$this->db->query("DELETE FROM ".$p."onboarding_stmt WHERE entity = ".((int) $conf->entity)." AND dateo < '".$this->db->escape($cut)."'");
		}
		return 'old bank data: '.$lines.' lines and '.$files.' statement files deleted';
	}

	/**
	 * For the yearly review: records the policy says should be gone by now.
	 *
	 * @return array<string,int> What => how many
	 */
	public function pastRetention()
	{
		global $conf;
		$p = $this->db->prefix();
		$out = array();
		$cut = date('Y-m-d', strtotime('-7 years', OnboardingService::now()));
		$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$p."adherent WHERE entity IN (".getEntity('adherent').") AND statut IN (0, -2) AND COALESCE(datefin, tms) < '".$this->db->escape($cut)."'");
		$o = $res ? $this->db->fetch_object($res) : null;
		$out['Former members whose membership ended more than 7 years ago'] = $o ? (int) $o->n : 0;
		$cut = self::bankCutoff();
		if ($cut === null) {
			$cut = date('Y-m-d', strtotime('-7 years', OnboardingService::now()));
		}
		$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$p."onboarding_stmt WHERE entity = ".((int) $conf->entity)." AND dateo < '".$this->db->escape($cut)."'");
		$o = $res ? $this->db->fetch_object($res) : null;
		$out['Bank transactions read from the banks older than 7 years'] = $o ? (int) $o->n : 0;
		return $out;
	}
}
