<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');
dol_include_once('/onboarding/class/reconcile.class.php');
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

/**
 * Bank feeds through Plaid: the organization's own bank, card and payment
 * accounts send their transactions to Dolibarr, where Reconcile bank accounts
 * matches them to the entries, the rules enter what nobody typed, and the rest
 * waits for a person. No statement uploads.
 *
 * Connecting is done on the Bank feeds page with Plaid Link (Plaid's own window,
 * where the bank login is typed; Dolibarr never sees it). Dolibarr keeps the
 * login's access token, encrypted when it can, and which Dolibarr account each
 * of the login's accounts feeds. The hourly job reads new transactions with
 * Plaid's transactions sync and hands them to reconciling. When the bank wants
 * the login again, the page offers Reconnect and Needs attention says so.
 */
class OnboardingPlaid
{
	/** @var DoliDB */
	public $db;
	/** @var OnboardingService */
	public $svc;
	/** @var string */
	public $error = '';
	/** @var string Plaid's error code from the last call, e.g. ITEM_LOGIN_REQUIRED */
	public $errorCode = '';

	/**
	 * @param DoliDB $db Database handler
	 * @param OnboardingService|null $svc Service
	 */
	public function __construct($db, $svc = null)
	{
		$this->db = $db;
		$this->svc = $svc ? $svc : new OnboardingService($db);
	}

	/**
	 * @return bool Keys are set
	 */
	public static function configured()
	{
		return getDolGlobalString('ONBOARDING_PLAID_CLIENT_ID') !== '' && getDolGlobalString('ONBOARDING_PLAID_SECRET') !== '';
	}

	/**
	 * @return string sandbox or production
	 */
	public static function env()
	{
		return getDolGlobalString('ONBOARDING_PLAID_ENV', 'sandbox') === 'production' ? 'production' : 'sandbox';
	}

	/**
	 * @return string Plaid's address for the environment (or the sandbox's fake one)
	 */
	private static function base()
	{
		$b = rtrim(getDolGlobalString('ONBOARDING_PLAID_API_BASE'), '/');
		return $b !== '' ? $b : 'https://'.self::env().'.plaid.com';
	}

	/**
	 * Call Plaid.
	 *
	 * @param string $path e.g. /transactions/sync
	 * @param array $body Request, without the keys
	 * @return array|null The answer, or null with error and errorCode set
	 */
	public function call($path, $body)
	{
		$this->error = $this->errorCode = '';
		$body['client_id'] = getDolGlobalString('ONBOARDING_PLAID_CLIENT_ID');
		$body['secret'] = getDolGlobalString('ONBOARDING_PLAID_SECRET');
		$r = getURLContent(self::base().$path, 'POSTALREADYFORMATED', json_encode($body), 1, array('Content-Type: application/json', 'Accept: application/json', 'Plaid-Version: 2020-09-14'), array('http', 'https'), 2);
		$code = isset($r['http_code']) ? (int) $r['http_code'] : 0;
		$json = json_decode((string) (isset($r['content']) ? $r['content'] : ''), true);
		if ($code == 200 && is_array($json)) {
			return $json;
		}
		$this->errorCode = is_array($json) && isset($json['error_code']) ? (string) $json['error_code'] : '';
		$this->error = 'Plaid '.$path.' failed (HTTP '.($code ?: '?').($this->errorCode !== '' ? ', '.$this->errorCode : '').(is_array($json) && !empty($json['error_message']) ? ': '.$json['error_message'] : (isset($r['curl_error_msg']) && $r['curl_error_msg'] ? ', '.$r['curl_error_msg'] : '')).')';
		dol_syslog('Onboarding: '.$this->error, LOG_WARNING);
		return null;
	}

	// ---------------------------------------------------------------- the access token

	/**
	 * @param string $s Access token
	 * @return string Encrypted for the database when Dolibarr can
	 */
	private static function seal($s)
	{
		self::crypto();
		return function_exists('dolEncrypt') ? dolEncrypt($s) : $s;
	}

	/**
	 * @param string $s Stored token
	 * @return string The access token
	 */
	private static function unseal($s)
	{
		self::crypto();
		return function_exists('dolDecrypt') && strpos((string) $s, 'dolcrypt:') === 0 ? dolDecrypt($s) : (string) $s;
	}

	/**
	 * @return void Load Dolibarr's encryption (its place moved between versions)
	 */
	private static function crypto()
	{
		if (!function_exists('dolEncrypt')) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
		}
		if (!function_exists('dolEncrypt') && is_file(DOL_DOCUMENT_ROOT.'/blockedlog/lib/securitycore.lib.php')) {
			require_once DOL_DOCUMENT_ROOT.'/blockedlog/lib/securitycore.lib.php';
		}
	}

	// ---------------------------------------------------------------- records

	/**
	 * @return object[] Connected logins, with their accounts in ->accounts
	 */
	public function items()
	{
		global $conf;
		$p = $this->db->prefix();
		$out = array();
		$res = @$this->db->query("SELECT rowid, item_id, institution, status, last_sync, last_error, datec FROM ".$p."onboarding_plaid_item WHERE entity = ".((int) $conf->entity)." ORDER BY rowid");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$o->accounts = array();
			$out[(int) $o->rowid] = $o;
		}
		$res = @$this->db->query("SELECT * FROM ".$p."onboarding_plaid_account WHERE entity = ".((int) $conf->entity)." ORDER BY rowid");
		while ($res && ($o = $this->db->fetch_object($res))) {
			if (isset($out[(int) $o->fk_item])) {
				$out[(int) $o->fk_item]->accounts[] = $o;
			}
		}
		return $out;
	}

	/**
	 * @param int $id Login
	 * @return string Its access token, '' when there is none
	 */
	private function token($id)
	{
		$res = $this->db->query("SELECT access_token FROM ".$this->db->prefix()."onboarding_plaid_item WHERE rowid = ".((int) $id));
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o ? self::unseal($o->access_token) : '';
	}

	/**
	 * @param int $id Login
	 * @param array<string,mixed> $fields Column => value (null for NULL)
	 * @return void
	 */
	private function save($id, $fields)
	{
		$set = array();
		foreach ($fields as $k => $v) {
			$set[] = $k.' = '.($v === null ? 'NULL' : "'".$this->db->escape((string) $v)."'");
		}
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_plaid_item SET ".implode(', ', $set)." WHERE rowid = ".((int) $id));
	}

	// ---------------------------------------------------------------- connecting

	/**
	 * A link token for Plaid Link: a new connection, or (with a login) the bank's
	 * login again for one that stopped working.
	 *
	 * @param User $user Who is connecting
	 * @param int $itemId Login to reconnect, 0 for a new one
	 * @return string The token, '' on failure (error set)
	 */
	public function linkToken($user, $itemId = 0)
	{
		$body = array(
			'client_name' => dol_trunc(getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'Dolibarr'), 30, 'right', 'UTF-8', 1),
			'user' => array('client_user_id' => 'dolibarr-'.((int) $user->id)),
			'country_codes' => array('US'),
			'language' => 'en',
		);
		if ($itemId) {
			$body['access_token'] = $this->token($itemId);
		} else {
			$body['products'] = array('transactions');
			$body['transactions'] = array('days_requested' => 730);
		}
		$redirect = getDolGlobalString('ONBOARDING_PLAID_REDIRECT_URI');
		if ($redirect !== '') {
			$body['redirect_uri'] = $redirect;
		}
		$r = $this->call('/link/token/create', $body);
		return $r && !empty($r['link_token']) ? (string) $r['link_token'] : '';
	}

	/**
	 * After Plaid Link: keep the login and read its accounts.
	 *
	 * @param string $publicToken From Plaid Link
	 * @param string $institution The bank's name, from Plaid Link
	 * @return string What happened ("Error: ..." on failure)
	 */
	public function connect($publicToken, $institution)
	{
		global $conf;
		$r = $this->call('/item/public_token/exchange', array('public_token' => (string) $publicToken));
		if (!$r || empty($r['access_token'])) {
			return 'Error: '.$this->error;
		}
		$p = $this->db->prefix();
		$itemId = (string) $r['item_id'];
		$res = $this->db->query("SELECT rowid FROM ".$p."onboarding_plaid_item WHERE entity = ".((int) $conf->entity)." AND item_id = '".$this->db->escape($itemId)."'");
		$o = $res ? $this->db->fetch_object($res) : null;
		if ($o) {
			$id = (int) $o->rowid;
			$this->save($id, array('access_token' => self::seal((string) $r['access_token']), 'status' => 'ok', 'last_error' => null));
		} else {
			$this->db->query("INSERT INTO ".$p."onboarding_plaid_item (entity, item_id, access_token, institution, status, datec) VALUES (".((int) $conf->entity).", '".$this->db->escape($itemId)."', '".$this->db->escape(self::seal((string) $r['access_token']))."', '".$this->db->escape(dol_trunc((string) $institution, 195, 'right', 'UTF-8', 1))."', 'ok', '".$this->db->idate(OnboardingService::now())."')");
			$id = (int) $this->db->last_insert_id($p.'onboarding_plaid_item');
		}
		$n = $this->refreshAccounts($id);
		if ($n < 0) {
			return 'Error: connected, but its accounts could not be read: '.$this->error;
		}
		return $institution.' connected: '.$n.' account'.($n == 1 ? '' : 's').'. Choose below which Dolibarr account each one feeds.';
	}

	/**
	 * Read a login's accounts and balances. An account Dolibarr has not seen is
	 * fed to the open bank account whose name or ref fits it, if exactly one does.
	 *
	 * @param int $id Login
	 * @return int Accounts, -1 on failure
	 */
	public function refreshAccounts($id)
	{
		$r = $this->call('/accounts/get', array('access_token' => $this->token($id)));
		if (!$r) {
			return -1;
		}
		$this->keepAccounts($id, isset($r['accounts']) && is_array($r['accounts']) ? $r['accounts'] : array());
		return isset($r['accounts']) ? count($r['accounts']) : 0;
	}

	/**
	 * @param int $id Login
	 * @param array $accounts Plaid's accounts (with balances)
	 * @return void
	 */
	private function keepAccounts($id, $accounts)
	{
		global $conf;
		$p = $this->db->prefix();
		foreach ($accounts as $a) {
			if (empty($a['account_id'])) {
				continue;
			}
			$aid = (string) $a['account_id'];
			$bal = isset($a['balances']['current']) && $a['balances']['current'] !== null ? (float) $a['balances']['current'] : null;
			$res = $this->db->query("SELECT rowid FROM ".$p."onboarding_plaid_account WHERE entity = ".((int) $conf->entity)." AND account_id = '".$this->db->escape($aid)."'");
			$o = $res ? $this->db->fetch_object($res) : null;
			$cols = "name = '".$this->db->escape(dol_trunc((string) (isset($a['official_name']) && $a['official_name'] ? $a['official_name'] : (isset($a['name']) ? $a['name'] : '')), 195, 'right', 'UTF-8', 1))."', mask = '".$this->db->escape((string) (isset($a['mask']) ? $a['mask'] : ''))."', type = '".$this->db->escape((string) (isset($a['type']) ? $a['type'] : ''))."', subtype = '".$this->db->escape((string) (isset($a['subtype']) ? $a['subtype'] : ''))."'";
			$cols .= ", balance = ".($bal !== null ? price2num($bal) : 'NULL').", balance_at = '".$this->db->idate(OnboardingService::now())."'";
			if ($o) {
				$this->db->query("UPDATE ".$p."onboarding_plaid_account SET ".$cols." WHERE rowid = ".((int) $o->rowid));
			} else {
				$this->db->query("INSERT INTO ".$p."onboarding_plaid_account (entity, fk_item, account_id, fk_bank_account) VALUES (".((int) $conf->entity).", ".((int) $id).", '".$this->db->escape($aid)."', ".$this->guess($a).")");
				$this->db->query("UPDATE ".$p."onboarding_plaid_account SET ".$cols." WHERE entity = ".((int) $conf->entity)." AND account_id = '".$this->db->escape($aid)."'");
			}
		}
	}

	/**
	 * @param array $a A Plaid account
	 * @return int The one open bank account its name or last digits point to, 0 when not exactly one
	 */
	private function guess($a)
	{
		$rec = new OnboardingReconcile($this->db, $this->svc);
		$name = strtolower((isset($a['name']) ? $a['name'] : '').' '.(isset($a['official_name']) ? $a['official_name'] : ''));
		$mask = isset($a['mask']) ? (string) $a['mask'] : '';
		$hits = array();
		foreach ($rec->accounts() as $acc) {
			$label = strtolower($acc->label.' '.$acc->ref);
			$words = array_filter(preg_split('/[^a-z0-9]+/', $label), function ($w) {
				return strlen($w) >= 4 && !in_array($w, array('bank', 'account', 'checking', 'card'), true);
			});
			foreach ($words as $w) {
				if (strpos($name, $w) !== false) {
					$hits[(int) $acc->rowid] = true;
				}
			}
			if ($mask !== '' && strpos($label, $mask) !== false) {
				$hits[(int) $acc->rowid] = true;
			}
		}
		return count($hits) == 1 ? (int) key($hits) : 0;
	}

	/**
	 * @param int $plaidAccount Row of llx_onboarding_plaid_account
	 * @param int $bankAccount Dolibarr bank account, 0 for none
	 * @return void
	 */
	public function map($plaidAccount, $bankAccount)
	{
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_plaid_account SET fk_bank_account = ".((int) $bankAccount)." WHERE rowid = ".((int) $plaidAccount));
	}

	/**
	 * Disconnect a login: Plaid forgets it (and stops charging for it), Dolibarr
	 * forgets its token and accounts. What it fed stays in the books.
	 *
	 * @param int $id Login
	 * @return string What happened
	 */
	public function disconnect($id)
	{
		$p = $this->db->prefix();
		$token = $this->token($id);
		$ok = $token === '' || $this->call('/item/remove', array('access_token' => $token)) !== null || in_array($this->errorCode, array('ITEM_NOT_FOUND', 'INVALID_ACCESS_TOKEN'), true);
		$this->db->query("DELETE FROM ".$p."onboarding_plaid_account WHERE fk_item = ".((int) $id));
		$this->db->query("DELETE FROM ".$p."onboarding_plaid_item WHERE rowid = ".((int) $id));
		return $ok ? 'Disconnected: Plaid no longer reads that bank, and its access is deleted.' : 'Disconnected in Dolibarr, but Plaid did not confirm ('.$this->error.'). Remove it in the Plaid dashboard too.';
	}

	// ---------------------------------------------------------------- reading transactions

	/**
	 * Read every login's new transactions and hand them to reconciling. A
	 * transaction still pending is left until it posts; one Plaid takes back is
	 * taken back here too while nobody has matched it.
	 *
	 * @return string Summary; contains "failed" when a login could not be read
	 */
	public function sync()
	{
		if (!self::configured()) {
			return 'Plaid not set up';
		}
		$rec = new OnboardingReconcile($this->db, $this->svc);
		$user = $this->svc->actor();
		$open = $rec->accounts();
		$out = array();
		$failed = false;
		foreach ($this->items() as $item) {
			$map = array();
			foreach ($item->accounts as $a) {
				if ((int) $a->fk_bank_account > 0 && isset($open[(int) $a->fk_bank_account])) {
					$map[(string) $a->account_id] = (int) $a->fk_bank_account;
				}
			}
			$res = $this->db->query("SELECT cursor_value FROM ".$this->db->prefix()."onboarding_plaid_item WHERE rowid = ".((int) $item->rowid));
			$o = $res ? $this->db->fetch_object($res) : null;
			$cursor = $o && $o->cursor_value ? (string) $o->cursor_value : '';
			$token = $this->token((int) $item->rowid);
			$rows = array();
			$gone = array();
			$accounts = array();
			$ok = true;
			for ($page = 0; $page < 100; $page++) {
				$body = array('access_token' => $token, 'count' => 500);
				if ($cursor !== '') {
					$body['cursor'] = $cursor;
				}
				$r = $this->call('/transactions/sync', $body);
				if (!$r) {
					$ok = false;
					break;
				}
				foreach (array('added', 'modified') as $k) {
					foreach (isset($r[$k]) && is_array($r[$k]) ? $r[$k] : array() as $t) {
						if (!empty($t['pending']) || !isset($map[(string) $t['account_id']])) {
							continue;
						}
						if ($k === 'modified') {
							$gone[] = (string) $t['transaction_id'];
						}
						$rows[$map[(string) $t['account_id']]][] = self::row($t);
					}
				}
				foreach (isset($r['removed']) && is_array($r['removed']) ? $r['removed'] : array() as $t) {
					$gone[] = (string) (isset($t['transaction_id']) ? $t['transaction_id'] : '');
				}
				if (!empty($r['accounts']) && is_array($r['accounts'])) {
					$accounts = $r['accounts'];
				}
				$cursor = isset($r['next_cursor']) ? (string) $r['next_cursor'] : $cursor;
				if (empty($r['has_more'])) {
					break;
				}
			}
			if (!$ok) {
				$failed = true;
				$login = in_array($this->errorCode, array('ITEM_LOGIN_REQUIRED', 'PENDING_EXPIRATION', 'PENDING_DISCONNECT'), true);
				$this->save((int) $item->rowid, array('status' => $login ? 'login' : 'error', 'last_error' => dol_trunc($this->error, 495, 'right', 'UTF-8', 1)));
				$out[] = $item->institution.': '.($login ? 'the bank wants the login again (Reconnect on Bank feeds)' : 'failed: '.$this->error);
				continue;
			}
			// Taken back by the bank (or changed): forget the line while nobody matched it.
			$gone = array_filter(array_unique($gone));
			if ($gone) {
				$in = implode(',', array_map(function ($id) {
					return "'".$this->db->escape($id)."'";
				}, $gone));
				$this->db->query("DELETE FROM ".$this->db->prefix()."onboarding_stmt WHERE ref IN (".$in.") AND status = 'open'");
			}
			$n = 0;
			$done = 0;
			foreach ($rows as $bank => $list) {
				$rec->store($bank, $list, array('source' => 'plaid', 'original' => 'Plaid: '.$item->institution, 'filename' => '', 'who' => 'Plaid'));
				$done += $rec->match($bank, $user);
				$n += count($list);
			}
			if ($accounts) {
				$this->keepAccounts((int) $item->rowid, $accounts);
			}
			$this->save((int) $item->rowid, array('cursor_value' => $cursor, 'status' => 'ok', 'last_error' => null, 'last_sync' => $this->db->idate(OnboardingService::now())));
			$out[] = $item->institution.': '.$n.' transactions, '.$done.' entries reconciled';
		}
		if (!$out) {
			return 'Plaid: no banks connected';
		}
		return 'Plaid: '.implode('; ', $out).($failed ? ' (some failed)' : '');
	}

	/**
	 * A Plaid transaction as a statement line. Plaid counts money out as positive.
	 *
	 * @param array $t Transaction
	 * @return array Statement line
	 */
	public static function row($t)
	{
		$label = trim((string) (isset($t['name']) ? $t['name'] : ''));
		if (!empty($t['merchant_name']) && stripos($label, (string) $t['merchant_name']) === false) {
			$label = trim($t['merchant_name'].' - '.$label, ' -');
		}
		return array(
			'date' => OnboardingReconcile::date(isset($t['date']) ? $t['date'] : ''),
			'amount' => -round((float) $t['amount'], 2),
			'fee' => 0.0,
			'alts' => array(),
			'label' => dol_trunc($label, 250, 'right', 'UTF-8', 1),
			'ref' => dol_trunc((string) $t['transaction_id'], 60, 'right', 'UTF-8', 1),
			'balance' => null,
		);
	}

	/**
	 * For Needs attention.
	 *
	 * @return int Logins that need a person (Reconnect, or failing)
	 */
	public function needing()
	{
		global $conf;
		$res = @$this->db->query("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_plaid_item WHERE entity = ".((int) $conf->entity)." AND status <> 'ok'");
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o ? (int) $o->n : 0;
	}
}
