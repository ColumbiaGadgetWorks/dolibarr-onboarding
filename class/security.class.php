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
 * The Access review page lists every login and what would happen to it. The
 * yearly access review is emailed to the security owner on the first of the
 * review month (April), with the steps and what Dolibarr knows now, and again
 * every week until they press "Access review done" (in the email, no login, or
 * on the Access review page). Each year's result is kept.
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

	/** Each year's review: {year: {sent, last, done, by}}. */
	const REVIEWS = 'ONBOARDING_ACCESS_REVIEWS';

	/** Days between reminders while the year's review is open. */
	const REVIEW_EVERY = 7;

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
	// ---------------------------------------------------------------- yearly access review

	/**
	 * @return int The month the yearly review starts (1-12, default April)
	 */
	public static function reviewMonth()
	{
		$m = (int) getDolGlobalString('ONBOARDING_ACCESS_REVIEW_MONTH', '4');
		return $m >= 1 && $m <= 12 ? $m : 4;
	}

	/**
	 * @return array<string,array<string,mixed>> Each year's review
	 */
	public function reviews()
	{
		$v = json_decode(getDolGlobalString(self::REVIEWS), true);
		return is_array($v) ? $v : array();
	}

	/**
	 * @param array<string,array<string,mixed>> $all Each year's review
	 * @return void
	 */
	private function saveReviews($all)
	{
		global $conf;
		krsort($all);
		dolibarr_set_const($this->db, self::REVIEWS, json_encode($all, JSON_FORCE_OBJECT), 'chaine', 0, 'Yearly access reviews: sent, reminded, done', $conf->entity);
	}

	/**
	 * @return int|null The review year still open (sent, not done), or null
	 */
	public function openReview()
	{
		foreach ($this->reviews() as $year => $r) {
			if (!empty($r['sent']) && empty($r['done'])) {
				return (int) $year;
			}
		}
		return null;
	}

	/**
	 * The daily step: on or after the first of the review month, email the year's
	 * review once, then remind every week until it is done.
	 *
	 * @param bool $now Start this year's review today, whatever the month
	 * @return string Summary
	 */
	public function review($now = false)
	{
		$t = OnboardingService::now();
		$year = (string) date('Y', $t);
		$all = $this->reviews();
		$r = isset($all[$year]) ? $all[$year] : array();
		if (!empty($r['done'])) {
			return 'access review: '.$year.' done';
		}
		if (empty($r['sent'])) {
			if (!$now && (int) date('n', $t) != self::reviewMonth()) {
				$open = $this->openReview();
				if ($open === null) {
					return 'access review: not due';
				}
				// Last year's is still open: keep reminding about that one.
				$year = (string) $open;
				$r = $all[$year];
			} else {
				$r = array('sent' => $t, 'last' => $t);
				$all[$year] = $r;
				$this->saveReviews($all);
				$this->sendReview((int) $year, false);
				return 'access review: '.$year.' sent';
			}
		}
		if ($t - (int) $r['last'] < self::REVIEW_EVERY * 86400) {
			return 'access review: '.$year.' open';
		}
		$all[$year]['last'] = $t;
		$this->saveReviews($all);
		$this->sendReview((int) $year, true);
		return 'access review: '.$year.' reminded';
	}

	/**
	 * Record a year's review as done.
	 *
	 * @param int $year Review year
	 * @param string $who Who said so
	 * @return string done, or already
	 */
	public function reviewDone($year, $who)
	{
		$all = $this->reviews();
		$key = (string) ((int) $year);
		if (!empty($all[$key]['done'])) {
			return 'already';
		}
		$t = OnboardingService::now();
		if (!isset($all[$key])) {
			$all[$key] = array('sent' => $t, 'last' => $t);
		}
		$all[$key]['done'] = $t;
		$all[$key]['by'] = dol_trunc((string) $who, 120, 'right', 'UTF-8', 1);
		$this->saveReviews($all);
		return 'done';
	}

	/**
	 * @param int $year Review year
	 * @param string $email Who the link is for
	 * @return string Signature
	 */
	public function reviewKey($year, $email)
	{
		$secret = getDolGlobalString('ONBOARDING_REVIEW_SECRET');
		if ($secret === '') {
			global $conf;
			$secret = bin2hex(random_bytes(24));
			dolibarr_set_const($this->db, 'ONBOARDING_REVIEW_SECRET', $secret, 'chaine', 0, 'Signs the "access review done" links', $conf->entity);
		}
		return substr(hash_hmac('sha256', 'access-review:'.((int) $year).':'.OnboardingService::cleanEmail($email), $secret), 0, 32);
	}

	/**
	 * @param int $year Review year
	 * @param string $email Who the link is for
	 * @param string $key Signature from the link
	 * @return bool
	 */
	public function reviewVerify($year, $email, $key)
	{
		return $year > 2000 && strpos((string) $email, '@') !== false && getDolGlobalString('ONBOARDING_REVIEW_SECRET') !== '' && hash_equals($this->reviewKey($year, $email), (string) $key);
	}

	/**
	 * @param int $year Review year
	 * @param string $email Who the link is for
	 * @return string The "access review done" link
	 */
	public function reviewLink($year, $email)
	{
		$email = OnboardingService::cleanEmail($email);
		return dol_buildpath('/onboarding/public/review.php', 2).'?y='.((int) $year).'&e='.rawurlencode($email).'&k='.$this->reviewKey($year, $email);
	}

	/**
	 * Email the security owner the year's review: the steps, what Dolibarr knows
	 * now, and their own "Access review done" link.
	 *
	 * @param int $year Review year
	 * @param bool $reminder A reminder, not the first email
	 * @return void
	 */
	private function sendReview($year, $reminder)
	{
		$logins = $this->logins();
		$lines = array();
		$going = 0;
		foreach ($logins as $o) {
			$state = $o->reason !== '' ? 'TO BE DISABLED: '.$o->reason : ((int) $o->fk_member > 0 ? 'member card '.$o->member.($o->roles ? ' ('.implode(', ', $o->roles).')' : '') : 'not linked to a member card: check by hand');
			$going += $o->reason !== '' ? 1 : 0;
			$lines[] = '- '.$o->login.($o->name !== '' ? ' ('.$o->name.')' : '').((int) $o->admin ? ', administrator' : '').', last login '.($o->datelastlogin ? dol_print_date($this->db->jdate($o->datelastlogin), 'day') : 'never').': '.$state;
		}
		$past = array();
		foreach ($this->pastRetention() as $what => $n) {
			$past[] = '- '.$what.': '.$n;
		}
		$list = trim(getDolGlobalString('ONBOARDING_SECURITY_CHECKLIST'));
		if ($list === '') {
			$list = self::CHECKLIST;
		}
		$accounts = array();
		foreach (preg_split('/\r?\n/', $list) as $l) {
			if (trim($l) !== '' && stripos($l, 'password manager: change') === false) {
				$accounts[] = '   [ ] '.trim($l);
			}
		}
		$page = dol_buildpath('/onboarding/security.php', 2);
		$body = ($reminder ? "A reminder: the yearly access review for ".$year." is not done yet.\n\n" : "It is time for the yearly access review for ".$year." (information security and records policy, section 7). Do it with the Secretary.\n\n")
			."1. Dolibarr logins. Open the Access review page: ".$page."\n"
			."   Disable anyone who no longer needs a login (the user card, Disable), and check each officer's login shows their member card and officer tag. Today there are ".count($logins)." working logins".($going ? ", ".$going." of them already due to be disabled" : '').":\n"
			."   ".implode("\n   ", $lines)."\n\n"
			."2. Every other account. For each one, list who can get in, remove anyone who no longer needs it, and check that at least two officers can:\n"
			.implode("\n", $accounts)."\n\n"
			."3. Records past their retention time (also on the Access review page). Delete former members whose membership ended more than 7 years ago:\n"
			."   ".implode("\n   ", $past)."\n\n"
			."4. Read the information security and records policy again, and change it (by pull request) if anything has changed.\n\n"
			."5. Write the result in the board minutes, and in the Plaid dashboard attest \"Periodic access reviews and audits\".\n\n"
			."When all of that is done, press the button behind your link (one per person, no login needed):\n\n";
		foreach ($this->owners() as $e) {
			$this->svc->mail($e, ($reminder ? 'Reminder: ' : '').'Yearly access review '.$year, $body.$this->reviewLink($year, $e)."\n\nYou will be reminded every ".self::REVIEW_EVERY." days until it is done.");
		}
	}
}
