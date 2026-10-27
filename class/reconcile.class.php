<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');

/**
 * Reconcile bank accounts: tick Dolibarr's bank entries as matching the bank's
 * own record, and list what does not match for a person.
 *
 * Reconciling is checking each entry in Dolibarr against the account's
 * statement. Here the statement comes from:
 * - PayPal's own transaction list, read through the PayPal connection (no file;
 *   the daily job does it too);
 * - an export uploaded on the page: CSV or Excel from online banking, Givebutter,
 *   Venmo or the card, or OFX/QFX.
 *
 * Each statement transaction is matched to an unreconciled entry of the same
 * account with the same amount, a few days apart at most (closest first). A
 * payment shown with its fee on one line (PayPal, Givebutter, Venmo) matches
 * either an entry for the full amount plus one for the fee, or a single entry
 * for what was left after the fee. Matched entries are marked reconciled with
 * the statement month (YYYYMM) as statement number. What is left is listed:
 * on the statement but not in Dolibarr (add it, match it by hand, or ignore
 * it), and in Dolibarr but not on the statement (fix it, or reconcile anyway).
 */
class OnboardingReconcile
{
	const TYPES = array('csv', 'txt', 'xlsx', 'xls', 'ofx', 'qfx', 'qbo');
	const MAX_FILE_BYTES = 10485760;

	/** @var DoliDB */
	public $db;
	/** @var OnboardingService */
	public $svc;
	/** @var string */
	public $error = '';

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
	 * @return int How many days apart a statement line and its entry may be
	 */
	public static function window()
	{
		return max(0, (int) getDolGlobalString('ONBOARDING_RECONCILE_DAYS', '5'));
	}

	/**
	 * @param float $n Amount
	 * @return string "$1,234.00" or "-$5.00"
	 */
	public static function money($n)
	{
		return ($n < 0 ? '-' : '').'$'.number_format(abs((float) $n), 2);
	}

	/**
	 * @param string $a Y-m-d
	 * @param string $b Y-m-d
	 * @return int Days between them
	 */
	private static function apart($a, $b)
	{
		return (int) abs(round((strtotime($a.' 12:00:00') - strtotime($b.' 12:00:00')) / 86400));
	}

	// ---------------------------------------------------------------- accounts

	/**
	 * Open accounts Dolibarr reconciles (not cash, reconciliation on).
	 *
	 * @return array<int,object> rowid, ref, label, todo, late, oldest
	 */
	public function accounts()
	{
		global $conf;
		if (!isModEnabled('banque') && !isModEnabled('bank')) {
			return array();
		}
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$p = $this->db->prefix();
		$delay = isset($conf->bank->rappro->warning_delay) ? (int) $conf->bank->rappro->warning_delay : 30 * 86400;
		$late = $this->db->idate(OnboardingService::now() - $delay);
		$out = array();
		$sql = "SELECT ba.rowid, ba.ref, ba.label, COUNT(b.rowid) AS todo, SUM(CASE WHEN b.datev < '".$late."' THEN 1 ELSE 0 END) AS late, MIN(b.dateo) AS oldest";
		$sql .= " FROM ".$p."bank_account AS ba LEFT JOIN ".$p."bank AS b ON b.fk_account = ba.rowid AND b.rappro = 0";
		$sql .= " WHERE ba.entity IN (".getEntity('bank_account').") AND ba.clos = 0 AND ba.rappro = 1 AND ba.courant <> ".Account::TYPE_CASH;
		$sql .= " GROUP BY ba.rowid, ba.ref, ba.label ORDER BY ba.label";
		$res = $this->db->query($sql);
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[(int) $o->rowid] = $o;
		}
		return $out;
	}

	/**
	 * @return int The PayPal account (ref PAYPAL), 0 when there is none
	 */
	public function paypalAccount()
	{
		foreach ($this->accounts() as $a) {
			if (strtoupper((string) $a->ref) === 'PAYPAL') {
				return (int) $a->rowid;
			}
		}
		return 0;
	}

	// ---------------------------------------------------------------- reading files

	/**
	 * @param string $s "$1,234.56", "(12.00)", "- $5.00", "1.234,56" is not supported
	 * @return float|null
	 */
	public static function amount($s)
	{
		if (is_int($s) || is_float($s)) {
			return (float) $s;
		}
		$s = trim((string) $s);
		if ($s === '') {
			return null;
		}
		$neg = (bool) preg_match('/^\(.*\)$/', $s) || (bool) preg_match('/^-|-\s*\$|\$\s*-|\s-$/', $s);
		$n = preg_replace('/[^0-9.]/', '', $s);
		if ($n === '' || !is_numeric($n)) {
			return null;
		}
		return ($neg ? -1 : 1) * (float) $n;
	}

	/**
	 * @param mixed $s 2026-05-27, 5/27/2026, 05/27/26, 2026-05-27T10:00:00Z, an Excel day number
	 * @return string Y-m-d, '' when it is not a date
	 */
	public static function date($s)
	{
		if ($s instanceof DateTimeInterface) {
			return $s->format('Y-m-d');
		}
		$s = trim((string) $s);
		if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m) || preg_match('/^(\d{4})(\d{2})(\d{2})(?:\d{6})?(?:\.\d+)?(?:\[.*\])?$/', $s, $m)) {
			return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : '';
		}
		if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/', $s, $m)) {
			$y = strlen($m[3]) == 2 ? 2000 + (int) $m[3] : (int) $m[3];
			return checkdate((int) $m[1], (int) $m[2], $y) ? sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]) : '';
		}
		if (is_numeric($s) && (float) $s > 30000 && (float) $s < 80000) {
			return gmdate('Y-m-d', (int) round(((float) $s - 25569) * 86400)); // Excel's day number
		}
		if (preg_match('/[a-z]{3}/i', $s) && preg_match('/\d{4}/', $s) && ($t = strtotime($s))) {
			return date('Y-m-d', $t);
		}
		return '';
	}

	/**
	 * The rows of a CSV or Excel file.
	 *
	 * @param string $path File
	 * @param string $ext Its type
	 * @return array<int,array<int,mixed>>
	 */
	public static function table($path, $ext)
	{
		if ($ext == 'xlsx' || $ext == 'xls') {
			require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
			require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
			require_once PHPEXCELNEW_PATH.'Spreadsheet.php';
			$book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
			$rows = array();
			foreach ($book->getSheet(0)->getRowIterator() as $row) {
				$cells = array();
				foreach ($row->getCellIterator() as $cell) {
					$v = $cell->getValue();
					if (is_string($v) && strpos($v, '=') === 0) {
						$v = ''; // A formula: not a statement column.
					} elseif (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell) && is_numeric($v)) {
						$v = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)->format('Y-m-d');
					}
					$cells[] = $v;
				}
				$rows[] = $cells;
			}
			return $rows;
		}
		$text = (string) file_get_contents($path);
		$text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
		$fh = fopen('php://memory', 'r+');
		fwrite($fh, $text);
		rewind($fh);
		$first = strtok($text, "\n");
		$sep = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : (substr_count((string) $first, "\t") > substr_count((string) $first, ',') ? "\t" : ',');
		$rows = array();
		while (($r = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
			$rows[] = $r;
		}
		fclose($fh);
		return $rows;
	}

	/**
	 * Read a statement export into transactions.
	 *
	 * @param string $path File
	 * @param string $name Its name, for its type
	 * @return array{rows:array<int,array{date:string,amount:float,fee:float,alts:float[],label:string,ref:string,balance:float|null}>,error:string}
	 */
	public static function read($path, $name)
	{
		$ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
		if (!in_array($ext, self::TYPES, true)) {
			return array('rows' => array(), 'error' => $name.': not a statement export (CSV, Excel, OFX or QFX).');
		}
		$head = (string) @file_get_contents($path, false, null, 0, 4096);
		if (in_array($ext, array('ofx', 'qfx', 'qbo'), true) || stripos($head, '<OFX>') !== false || stripos($head, 'OFXHEADER') !== false) {
			return array('rows' => self::readOfx((string) file_get_contents($path)), 'error' => '');
		}
		try {
			$table = self::table($path, $ext);
		} catch (Throwable $e) {
			return array('rows' => array(), 'error' => $name.': could not be read ('.$e->getMessage().').');
		}
		$rows = self::readTable($table);
		return array('rows' => $rows, 'error' => $rows ? '' : $name.': no transactions found. It needs a row of column names with a date and an amount (or withdrawals and deposits).');
	}

	/**
	 * @param string $text OFX or QFX
	 * @return array<int,array>
	 */
	public static function readOfx($text)
	{
		$out = array();
		$bal = null;
		$balDate = '';
		if (preg_match('/<LEDGERBAL>.*?<BALAMT>\s*([-0-9.]+).*?<DTASOF>\s*(\d{8})/is', $text, $m)) {
			$bal = (float) $m[1];
			$balDate = self::date($m[2]);
		}
		preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>)|(?=<\/BANKTRANLIST>))/is', $text, $all);
		foreach ($all[1] as $t) {
			$get = function ($tag) use ($t) {
				return preg_match('/<'.$tag.'>\s*([^<\r\n]*)/i', $t, $m) ? trim(html_entity_decode($m[1])) : '';
			};
			$d = self::date($get('DTPOSTED'));
			$a = self::amount($get('TRNAMT'));
			if ($d === '' || $a === null) {
				continue;
			}
			$out[] = array('date' => $d, 'amount' => $a, 'fee' => 0.0, 'alts' => array(), 'label' => trim($get('NAME').' '.$get('MEMO')), 'ref' => $get('FITID'), 'balance' => null);
		}
		if ($bal !== null && $out) {
			// The ledger balance is at DTASOF; give it to the last transaction on or before it.
			usort($out, function ($x, $y) {
				return strcmp($x['date'], $y['date']);
			});
			$out[count($out) - 1]['balance'] = $balDate !== '' && $balDate >= $out[count($out) - 1]['date'] ? $bal : null;
		}
		return $out;
	}

	/**
	 * @param array<int,array<int,mixed>> $table Rows of cells
	 * @return array<int,array>
	 */
	public static function readTable($table)
	{
		$names = array(
			'date' => array('date', 'transaction date (utc)', 'datetime', 'transaction date', 'posting date', 'posted date', 'date posted', 'trans date', 'payment captured (utc)'),
			'amount' => array('amount', 'amount (total)', 'gross', 'transaction amount'),
			'fee' => array('fee', 'amount (fee)', 'fees'),
			'net' => array('net', 'payout'),
			'donated' => array('donated'),
			'out' => array('withdrawals', 'withdrawal', 'debit', 'debits', 'amount debit', 'money out'),
			'in' => array('deposits', 'deposit', 'credit', 'credits', 'amount credit', 'money in'),
			'label' => array('description', 'name', 'payee', 'note', 'memo', 'transaction', 'type', 'transaction type', 'item title', 'campaign title', 'from', 'to'),
			'ref' => array('transaction id', 'reference number', 'id', 'fitid', 'reference', 'check number'),
			'status' => array('status', 'status friendly'),
			'balance' => array('balance', 'ending balance', 'running balance'),
			'impact' => array('balance impact'),
		);
		$col = null;
		$start = 0;
		foreach (array_slice($table, 0, 40) as $i => $row) {
			$norm = array();
			foreach ($row as $j => $c) {
				$norm[$j] = strtolower(trim((string) $c));
			}
			$found = array();
			foreach ($names as $k => $list) {
				foreach ($list as $want) {
					$j = array_search($want, $norm, true);
					if ($j !== false && !isset($found[$k])) {
						$found[$k] = $j;
					}
				}
			}
			if (isset($found['date']) && (isset($found['amount']) || isset($found['in']) || isset($found['out']))) {
				// Labels: up to two of the columns, in the order listed.
				$found['labels'] = array();
				foreach ($names['label'] as $want) {
					$j = array_search($want, $norm, true);
					if ($j !== false && count($found['labels']) < 2 && !in_array($j, $found['labels'], true)) {
						$found['labels'][] = $j;
					}
				}
				$col = $found;
				$start = $i + 1;
				break;
			}
		}
		if ($col === null) {
			return array();
		}
		$cell = function ($row, $k) use ($col) {
			return isset($col[$k]) && isset($row[$col[$k]]) ? $row[$col[$k]] : '';
		};
		$out = array();
		foreach (array_slice($table, $start) as $row) {
			$d = self::date($cell($row, 'date'));
			if ($d === '') {
				continue;
			}
			$status = strtolower(trim((string) $cell($row, 'status')));
			if ($status !== '' && preg_match('/pending|denied|declined|failed|cancel|void|refunded|incomplete/', $status)) {
				continue;
			}
			if (strtolower(trim((string) $cell($row, 'impact'))) === 'memo') {
				continue; // PayPal: shown, but does not move the balance.
			}
			if (isset($col['amount'])) {
				$a = self::amount($cell($row, 'amount'));
			} else {
				$in = self::amount($cell($row, 'in'));
				$o = self::amount($cell($row, 'out'));
				$a = ($in === null && $o === null) ? null : (float) $in - abs((float) $o);
			}
			if ($a === null || abs($a) < 0.005) {
				continue;
			}
			$fee = self::amount($cell($row, 'fee'));
			$fee = $fee === null ? 0.0 : (float) $fee;
			// A fee is money out of the account, whatever sign the column uses.
			$fee = abs($fee) < 0.005 ? 0.0 : -abs($fee);
			$alts = array();
			foreach (array('net', 'donated') as $k) {
				$v = self::amount($cell($row, $k));
				if ($v !== null && abs($v - $a) > 0.005 && abs($v) > 0.005) {
					$alts[] = ($a < 0 ? -1 : 1) * abs($v);
				}
			}
			if ($fee != 0.0 && !in_array(round($a + $fee, 2), array_map(function ($x) {
				return round($x, 2);
			}, $alts), true)) {
				$alts[] = $a + $fee;
			}
			$labels = array();
			foreach ($col['labels'] as $j) {
				if (isset($row[$j]) && trim((string) $row[$j]) !== '') {
					$labels[] = trim((string) $row[$j]);
				}
			}
			$bal = self::amount($cell($row, 'balance'));
			$out[] = array('date' => $d, 'amount' => (float) $a, 'fee' => $fee, 'alts' => $alts, 'label' => dol_trunc(implode(' - ', $labels), 250, 'right', 'UTF-8', 1), 'ref' => dol_trunc(trim((string) $cell($row, 'ref')), 60, 'right', 'UTF-8', 1), 'balance' => $bal);
		}
		return $out;
	}

	// ---------------------------------------------------------------- storing

	/**
	 * Keep a statement's transactions (each once) and say what it covers.
	 *
	 * @param int $accountId Account
	 * @param array<int,array> $rows From read()
	 * @param array{source:string,original:string,filename:string,who:string} $file About the file
	 * @return int The file's id
	 */
	public function store($accountId, $rows, $file)
	{
		global $conf;
		$p = $this->db->prefix();
		$from = $to = '';
		foreach ($rows as $r) {
			$from = ($from === '' || $r['date'] < $from) ? $r['date'] : $from;
			$to = ($to === '' || $r['date'] > $to) ? $r['date'] : $to;
		}
		// The balance the statement ends on: the newest day's, in the file's own order.
		$end = null;
		$endDate = '';
		$newestFirst = $rows && $rows[0]['date'] > $rows[count($rows) - 1]['date'];
		foreach ($newestFirst ? $rows : array_reverse($rows) as $r) {
			if ($r['date'] === $to && $r['balance'] !== null) {
				$end = (float) $r['balance'];
				$endDate = $to;
				break;
			}
		}
		$fileId = 0;
		if ($file['source'] == 'upload') {
			// The same export uploaded again: one record of it.
			$res = $this->db->query("SELECT rowid FROM ".$p."onboarding_stmt_file WHERE fk_account = ".((int) $accountId)." AND source = 'upload' AND original = '".$this->db->escape(dol_trunc($file['original'], 250, 'right', 'UTF-8', 1))."' AND date_from ".($from ? "= '".$from."'" : 'IS NULL')." AND date_to ".($to ? "= '".$to."'" : 'IS NULL')." AND nrows = ".count($rows));
			$o = $res ? $this->db->fetch_object($res) : null;
			if ($o) {
				$fileId = (int) $o->rowid;
				$this->db->query("UPDATE ".$p."onboarding_stmt_file SET datec = '".$this->db->idate(OnboardingService::now())."' WHERE rowid = ".$fileId);
			}
		}
		if ($file['source'] == 'api') {
			$res = $this->db->query("SELECT rowid, date_from, date_to FROM ".$p."onboarding_stmt_file WHERE fk_account = ".((int) $accountId)." AND source = 'api'");
			$o = $res ? $this->db->fetch_object($res) : null;
			if ($o) {
				$fileId = (int) $o->rowid;
				$from = $o->date_from && $o->date_from < $from ? $o->date_from : $from;
				$to = $o->date_to && $o->date_to > $to ? $o->date_to : $to;
				$this->db->query("UPDATE ".$p."onboarding_stmt_file SET date_from = ".($from ? "'".$from."'" : 'NULL').", date_to = ".($to ? "'".$to."'" : 'NULL').", nrows = nrows + ".count($rows).", datec = '".$this->db->idate(OnboardingService::now())."' WHERE rowid = ".$fileId);
			}
		}
		if (!$fileId) {
			$this->db->query("INSERT INTO ".$p."onboarding_stmt_file (entity, fk_account, source, filename, original, date_from, date_to, end_balance, end_date, nrows, who, datec) VALUES (".((int) $conf->entity).", ".((int) $accountId).", '".$this->db->escape($file['source'])."', ".($file['filename'] !== '' ? "'".$this->db->escape($file['filename'])."'" : 'NULL').", '".$this->db->escape(dol_trunc($file['original'], 250, 'right', 'UTF-8', 1))."', ".($from ? "'".$from."'" : 'NULL').", ".($to ? "'".$to."'" : 'NULL').", ".($end !== null ? price2num($end) : 'NULL').", ".($endDate ? "'".$endDate."'" : 'NULL').", ".count($rows).", '".$this->db->escape(dol_trunc($file['who'], 250, 'right', 'UTF-8', 1))."', '".$this->db->idate(OnboardingService::now())."')");
			$fileId = (int) $this->db->last_insert_id($p.'onboarding_stmt_file');
		}
		$seen = array();
		foreach ($rows as $r) {
			$key = $accountId.'|'.$r['date'].'|'.number_format($r['amount'], 2, '.', '').'|'.$r['ref'].'|'.$r['label'];
			$seen[$key] = isset($seen[$key]) ? $seen[$key] + 1 : 1;
			$hash = sha1($key.'|'.$seen[$key]);
			$main = $this->insertRow($accountId, $fileId, $r['date'], $r['amount'], $r['alts'] ? $r['alts'][0] : null, $r['label'], $r['ref'], 'main', 0, $hash);
			if ($main && $r['fee'] != 0.0) {
				$this->insertRow($accountId, $fileId, $r['date'], $r['fee'], null, 'Fee: '.$r['label'], $r['ref'], 'fee', $main, sha1($key.'|'.$seen[$key].'|fee'));
			}
		}
		return $fileId;
	}

	/**
	 * @return int The new row, 0 when it was already there
	 */
	private function insertRow($accountId, $fileId, $date, $amount, $alt, $label, $ref, $kind, $pair, $hash)
	{
		global $conf;
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT rowid FROM ".$p."onboarding_stmt WHERE entity = ".((int) $conf->entity)." AND hash = '".$this->db->escape($hash)."'");
		if ($res && $this->db->fetch_object($res)) {
			return 0;
		}
		$this->db->query("INSERT INTO ".$p."onboarding_stmt (entity, fk_account, fk_file, dateo, amount, alt_amount, label, ref, kind, fk_pair, status, hash, datec) VALUES (".((int) $conf->entity).", ".((int) $accountId).", ".((int) $fileId).", '".$this->db->escape($date)."', ".price2num($amount).", ".($alt !== null ? price2num($alt) : 'NULL').", '".$this->db->escape((string) $label)."', ".($ref !== '' ? "'".$this->db->escape($ref)."'" : 'NULL').", '".$kind."', ".($pair ? (int) $pair : 'NULL').", 'open', '".$this->db->escape($hash)."', '".$this->db->idate(OnboardingService::now())."')");
		return (int) $this->db->last_insert_id($p.'onboarding_stmt');
	}

	// ---------------------------------------------------------------- matching

	/**
	 * Match the account's open statement lines to its unreconciled entries, and
	 * reconcile what matches.
	 *
	 * @param int $accountId Account
	 * @param User $user Who reconciles
	 * @return int Entries reconciled
	 */
	public function match($accountId, $user)
	{
		$p = $this->db->prefix();
		$w = self::window();
		$rows = array();
		$res = $this->db->query("SELECT rowid, dateo, amount, alt_amount, kind, fk_pair FROM ".$p."onboarding_stmt WHERE fk_account = ".((int) $accountId)." AND status = 'open' ORDER BY dateo, rowid");
		$from = $to = '';
		while ($res && ($o = $this->db->fetch_object($res))) {
			$o->dateo = substr((string) $o->dateo, 0, 10);
			$rows[(int) $o->rowid] = $o;
			$from = ($from === '' || $o->dateo < $from) ? $o->dateo : $from;
			$to = ($to === '' || $o->dateo > $to) ? $o->dateo : $to;
		}
		if (!$rows) {
			return 0;
		}
		$lines = array();
		$a = date('Y-m-d', strtotime($from.' 12:00:00') - $w * 86400);
		$b = date('Y-m-d', strtotime($to.' 12:00:00') + $w * 86400);
		$res = $this->db->query("SELECT rowid, dateo, amount FROM ".$p."bank WHERE fk_account = ".((int) $accountId)." AND rappro = 0 AND dateo >= '".$a."' AND dateo <= '".$b." 23:59:59'");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$lines[(int) $o->rowid] = array('date' => substr((string) $o->dateo, 0, 10), 'amount' => round((float) $o->amount, 2));
		}
		$byAmount = array();
		foreach ($lines as $id => $l) {
			$byAmount[number_format($l['amount'], 2, '.', '')][] = $id;
		}
		// Every same-amount pair close enough in time; the closest pairs are taken first.
		$pairs = array();
		foreach ($rows as $rid => $r) {
			foreach (array('amount' => 0, 'alt_amount' => 1) as $field => $alt) {
				if ($r->$field === null || $r->$field === '') {
					continue;
				}
				$k = number_format(round((float) $r->$field, 2), 2, '.', '');
				foreach (isset($byAmount[$k]) ? $byAmount[$k] : array() as $lid) {
					$d = self::apart($r->dateo, $lines[$lid]['date']);
					if ($d <= $w) {
						$pairs[] = array($d * 2 + $alt, $rid, $lid, $alt);
					}
				}
			}
		}
		usort($pairs, function ($x, $y) {
			return $x[0] <=> $y[0] ?: $x[1] <=> $y[1] ?: $x[2] <=> $y[2];
		});
		$usedRow = $usedLine = array();
		$done = 0;
		foreach ($pairs as $pr) {
			list(, $rid, $lid, $alt) = $pr;
			if (isset($usedRow[$rid]) || isset($usedLine[$lid])) {
				continue;
			}
			$usedRow[$rid] = $usedLine[$lid] = true;
			if ($this->reconcileLine($lid, $rows[$rid]->dateo, $user)) {
				$this->db->query("UPDATE ".$p."onboarding_stmt SET status = 'matched', fk_bank = ".((int) $lid)." WHERE rowid = ".((int) $rid));
				$done++;
				if ($alt) {
					// Matched after the fee: the fee is inside this entry.
					$this->db->query("UPDATE ".$p."onboarding_stmt SET status = 'ignored' WHERE fk_pair = ".((int) $rid)." AND status = 'open'");
					foreach ($rows as $x => $r2) {
						if ((int) $r2->fk_pair === $rid) {
							$usedRow[$x] = true;
						}
					}
				}
			}
		}
		return $done;
	}

	/**
	 * Mark one bank entry reconciled, with the statement month as statement number.
	 *
	 * @param int $lineId Bank entry
	 * @param string $day Statement day, Y-m-d
	 * @param User $user Who
	 * @return bool
	 */
	public function reconcileLine($lineId, $day, $user)
	{
		return (bool) $this->db->query("UPDATE ".$this->db->prefix()."bank SET rappro = 1, num_releve = '".$this->db->escape(str_replace('-', '', substr($day, 0, 7)))."', fk_user_rappro = ".((int) $user->id)." WHERE rowid = ".((int) $lineId)." AND rappro = 0");
	}

	/**
	 * Read an uploaded export, keep it, and reconcile from it. A file whose
	 * amounts have the opposite sign to Dolibarr's (some card exports show
	 * purchases as positive) is turned around when that matches more.
	 *
	 * @param int $accountId Account
	 * @param string $tmp Uploaded file
	 * @param string $name Its name
	 * @param User $user Who
	 * @return string What happened
	 */
	public function upload($accountId, $tmp, $name, $user)
	{
		$accounts = $this->accounts();
		if (!isset($accounts[$accountId])) {
			return 'Error: choose an account that Dolibarr reconciles.';
		}
		$size = (int) @filesize($tmp);
		if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
			return 'Error: '.$name.' is '.($size <= 0 ? 'empty' : 'larger than 10 MB').'.';
		}
		$read = self::read($tmp, $name);
		if ($read['error'] !== '') {
			return 'Error: '.$read['error'];
		}
		$rows = $read['rows'];
		if ($this->signScore($accountId, $rows, -1) > $this->signScore($accountId, $rows, 1)) {
			foreach ($rows as &$r) {
				$r['amount'] = -$r['amount'];
				$r['fee'] = -$r['fee'];
				$r['alts'] = array_map(function ($x) {
					return -$x;
				}, $r['alts']);
				$r['balance'] = $r['balance'] !== null ? -$r['balance'] : null;
			}
			unset($r);
		}
		$dir = DOL_DATA_ROOT.'/onboarding/statements/'.((int) $accountId);
		dol_mkdir($dir);
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$stored = dol_print_date(OnboardingService::now(), '%Y%m%d%H%M%S').'-'.substr(sha1($name.mt_rand()), 0, 6).'.'.$ext;
		is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dir.'/'.$stored) : @copy($tmp, $dir.'/'.$stored);
		$this->store($accountId, $rows, array('source' => 'upload', 'original' => dol_sanitizeFileName(basename($name)), 'filename' => $stored, 'who' => trim($user->getFullName($GLOBALS['langs']).' ('.$user->login.')')));
		$done = $this->match($accountId, $user);
		$left = $this->openCount($accountId);
		return $accounts[$accountId]->label.': '.count($rows).' transactions read from '.$name.', '.$done.' entries reconciled'.($left ? ', '.$left.' to check below' : '').'.';
	}

	/**
	 * @param int $accountId Account
	 * @param array<int,array> $rows Transactions
	 * @param int $sign 1 or -1
	 * @return int How many of them have an unreconciled entry of that amount in the account
	 */
	private function signScore($accountId, $rows, $sign)
	{
		$have = array();
		$res = $this->db->query("SELECT amount FROM ".$this->db->prefix()."bank WHERE fk_account = ".((int) $accountId)." AND rappro = 0");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$k = number_format(round((float) $o->amount, 2), 2, '.', '');
			$have[$k] = isset($have[$k]) ? $have[$k] + 1 : 1;
		}
		$n = 0;
		foreach ($rows as $r) {
			$k = number_format(round($sign * $r['amount'], 2), 2, '.', '');
			if (!empty($have[$k])) {
				$have[$k]--;
				$n++;
			}
		}
		return $n;
	}

	/**
	 * PayPal's own transaction list as the statement: read from the oldest
	 * unreconciled PayPal entry (a few days before) to yesterday, and reconcile.
	 *
	 * @param User $user Who
	 * @return string What happened; contains "failed" when PayPal could not be read
	 */
	public function paypal($user)
	{
		dol_include_once('/onboarding/class/paypal.class.php');
		$acc = $this->paypalAccount();
		if (!$acc) {
			return 'PayPal reconciling: no open PayPal account (ref PAYPAL) to reconcile';
		}
		if (!OnboardingPaypal::configured()) {
			return 'PayPal reconciling: PayPal is not connected';
		}
		$res = $this->db->query("SELECT MIN(dateo) AS d FROM ".$this->db->prefix()."bank WHERE fk_account = ".$acc." AND rappro = 0");
		$o = $res ? $this->db->fetch_object($res) : null;
		if (!$o || !$o->d) {
			return 'PayPal: nothing to reconcile';
		}
		$now = OnboardingService::now();
		$to = $now - 3600;
		$from = max(strtotime(substr((string) $o->d, 0, 10).' 00:00:00') - (self::window() + 1) * 86400, $now - 3 * 365 * 86400);
		$pp = new OnboardingPaypal($this->db, $this->svc);
		$rows = array();
		$stamp = function ($t) {
			return gmdate('Y-m-d\TH:i:s', $t).'-0000';
		};
		for ($start = $from; $start < $to; $start += 30 * 86400) {
			$end = min($to, $start + 30 * 86400);
			for ($page = 1; $page <= 50; $page++) {
				$json = $pp->get('/v1/reporting/transactions?start_date='.rawurlencode($stamp($start)).'&end_date='.rawurlencode($stamp($end)).'&fields=transaction_info,payer_info&page_size=500&page='.$page);
				if ($json === null) {
					return 'PayPal reconciling failed: '.$pp->error;
				}
				foreach ((isset($json['transaction_details']) && is_array($json['transaction_details']) ? $json['transaction_details'] : array()) as $t) {
					$ti = isset($t['transaction_info']) ? $t['transaction_info'] : array();
					if (!in_array(isset($ti['transaction_status']) ? $ti['transaction_status'] : 'S', array('S', 'V'), true)) {
						continue; // Pending or denied: not in the balance.
					}
					$a = isset($ti['transaction_amount']['value']) ? (float) $ti['transaction_amount']['value'] : 0.0;
					$fee = isset($ti['fee_amount']['value']) ? (float) $ti['fee_amount']['value'] : 0.0;
					$d = self::date(isset($ti['transaction_initiation_date']) ? substr((string) $ti['transaction_initiation_date'], 0, 10) : '');
					if ($d === '' || abs($a) < 0.005) {
						continue;
					}
					$code = isset($ti['transaction_event_code']) ? (string) $ti['transaction_event_code'] : '';
					$pi = isset($t['payer_info']) && is_array($t['payer_info']) ? $t['payer_info'] : array();
					$who = trim((isset($pi['payer_name']['given_name']) ? $pi['payer_name']['given_name'] : '').' '.(isset($pi['payer_name']['surname']) ? $pi['payer_name']['surname'] : '')).(!empty($pi['email_address']) ? ' ('.$pi['email_address'].')' : '');
					$what = in_array($code, array('T0002', 'T0001'), true) ? 'payment' : (strpos($code, 'T04') === 0 ? 'transfer to the bank' : (strpos($code, 'T00') === 0 ? 'payment' : $code));
					$rows[] = array('date' => $d, 'amount' => $a, 'fee' => $fee, 'alts' => abs($fee) > 0.005 ? array($a + $fee) : array(), 'label' => dol_trunc('PayPal '.$what.($who !== '' ? ' from '.$who : '').(isset($ti['transaction_subject']) ? ': '.$ti['transaction_subject'] : '').' ['.$code.']', 250, 'right', 'UTF-8', 1), 'ref' => isset($ti['transaction_id']) ? (string) $ti['transaction_id'] : '', 'balance' => null);
				}
				if ($page >= (int) (isset($json['total_pages']) ? $json['total_pages'] : 1)) {
					break;
				}
			}
		}
		$this->store($acc, $rows, array('source' => 'api', 'original' => 'PayPal, read automatically', 'filename' => '', 'who' => 'PayPal connection'));
		$done = $this->match($acc, $user);
		$left = $this->openCount($acc);
		return 'PayPal: '.count($rows).' transactions read, '.$done.' entries reconciled'.($left ? ', '.$left.' to check' : '');
	}

	/**
	 * Daily: PayPal from its connection, and every account's open lines tried
	 * again (an entry may have been added since).
	 *
	 * @return string Summary
	 */
	public function daily()
	{
		$user = $this->svc->actor();
		$out = array();
		dol_include_once('/onboarding/class/paypal.class.php');
		if ($this->paypalAccount() && OnboardingPaypal::configured()) {
			$out[] = $this->paypal($user);
		}
		$n = 0;
		foreach ($this->accounts() as $a) {
			$n += $this->match((int) $a->rowid, $user);
		}
		if ($n) {
			$out[] = $n.' more entries reconciled';
		}
		return $out ? implode('; ', $out) : 'reconciling: nothing to do';
	}

	// ---------------------------------------------------------------- what is left

	/**
	 * @param int $accountId Account, 0 for all
	 * @return int Statement lines with no entry
	 */
	public function openCount($accountId = 0)
	{
		global $conf;
		$res = @$this->db->query("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_stmt WHERE entity = ".((int) $conf->entity)." AND status = 'open'".($accountId ? " AND fk_account = ".((int) $accountId) : ''));
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o ? (int) $o->n : 0;
	}

	/**
	 * @param int $accountId Account
	 * @return object[] Statement lines with no entry
	 */
	public function open($accountId)
	{
		$out = array();
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_stmt WHERE fk_account = ".((int) $accountId)." AND status = 'open' ORDER BY dateo, rowid");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * Unreconciled entries on days a statement covers, two days in from its
	 * edges: the statement should have had them.
	 *
	 * @param int $accountId Account, 0 for all
	 * @return object[] rowid, fk_account, dateo, amount, label
	 */
	public function missing($accountId = 0)
	{
		global $conf;
		$p = $this->db->prefix();
		$ranges = array();
		$res = @$this->db->query("SELECT fk_account, date_from, date_to FROM ".$p."onboarding_stmt_file WHERE entity = ".((int) $conf->entity)." AND date_from IS NOT NULL".($accountId ? " AND fk_account = ".((int) $accountId) : ''));
		while ($res && ($o = $this->db->fetch_object($res))) {
			$ranges[(int) $o->fk_account][] = array(substr($o->date_from, 0, 10), substr($o->date_to, 0, 10));
		}
		$out = array();
		// Banks post a day or two late, so an entry at the very edge may be on the next statement.
		$w = min(2, self::window());
		foreach ($ranges as $acc => $list) {
			$res = $this->db->query("SELECT rowid, fk_account, dateo, amount, label FROM ".$p."bank WHERE fk_account = ".((int) $acc)." AND rappro = 0 ORDER BY dateo, rowid");
			while ($res && ($o = $this->db->fetch_object($res))) {
				$d = substr((string) $o->dateo, 0, 10);
				foreach ($list as $r) {
					if ($d >= date('Y-m-d', strtotime($r[0].' 12:00:00') + $w * 86400) && $d <= date('Y-m-d', strtotime($r[1].' 12:00:00') - $w * 86400)) {
						$out[] = $o;
						break;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * @param int $accountId Account
	 * @return object[] Its uploads and the PayPal reading, newest first
	 */
	public function files($accountId)
	{
		$out = array();
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_stmt_file WHERE fk_account = ".((int) $accountId)." ORDER BY datec DESC, rowid DESC");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param int $accountId Account
	 * @param string $day Y-m-d
	 * @return float Dolibarr's balance at the end of that day
	 */
	public function balanceAt($accountId, $day)
	{
		$res = $this->db->query("SELECT SUM(amount) AS s FROM ".$this->db->prefix()."bank WHERE fk_account = ".((int) $accountId)." AND dateo <= '".$this->db->escape($day)." 23:59:59'");
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o ? round((float) $o->s, 2) : 0.0;
	}

	/**
	 * @param int $id Statement line
	 * @return object|null
	 */
	public function row($id)
	{
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_stmt WHERE rowid = ".((int) $id));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * Unreconciled entries near a statement line, to match it by hand.
	 *
	 * @param object $row Statement line
	 * @return object[] rowid, dateo, amount, label
	 */
	public function nearby($row)
	{
		$out = array();
		$d = substr((string) $row->dateo, 0, 10);
		$res = $this->db->query("SELECT rowid, dateo, amount, label FROM ".$this->db->prefix()."bank WHERE fk_account = ".((int) $row->fk_account)." AND rappro = 0 AND dateo >= '".date('Y-m-d', strtotime($d) - 20 * 86400)."' AND dateo <= '".date('Y-m-d', strtotime($d) + 20 * 86400)."' ORDER BY ABS(amount - ".price2num((float) $row->amount).") , dateo LIMIT 15");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * A person's decision on a statement line with no entry.
	 *
	 * @param int $id Statement line
	 * @param string $what add (make the entry and reconcile it), ignore, or match
	 * @param int $lineId For match: the entry it is
	 * @param User $user Who
	 * @return string What happened
	 */
	public function decide($id, $what, $lineId, $user)
	{
		$row = $this->row($id);
		if (!$row || $row->status != 'open') {
			return 'Already dealt with.';
		}
		$p = $this->db->prefix();
		$d = substr((string) $row->dateo, 0, 10);
		if ($what == 'ignore') {
			$this->db->query("UPDATE ".$p."onboarding_stmt SET status = 'ignored' WHERE rowid = ".((int) $id));
			return 'Left out: '.$d.' '.self::money($row->amount).'.';
		}
		if ($what == 'match') {
			$res = $this->db->query("SELECT rowid FROM ".$p."bank WHERE rowid = ".((int) $lineId)." AND fk_account = ".((int) $row->fk_account)." AND rappro = 0");
			if (!$res || !$this->db->fetch_object($res) || !$this->reconcileLine($lineId, $d, $user)) {
				return 'Error: that entry is not an unreconciled one of this account.';
			}
			$this->db->query("UPDATE ".$p."onboarding_stmt SET status = 'matched', fk_bank = ".((int) $lineId)." WHERE rowid = ".((int) $id));
			return 'Matched and reconciled.';
		}
		if ($what == 'add') {
			require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
			$acc = new Account($this->db);
			if ($acc->fetch((int) $row->fk_account) <= 0) {
				return 'Error: the account is gone.';
			}
			$label = trim((string) $row->label) !== '' ? (string) $row->label : 'From the statement';
			$line = $acc->addline(strtotime($d.' 12:00:00'), $acc->type == Account::TYPE_CASH ? 'LIQ' : 'VIR', dol_trunc($label, 250, 'right', 'UTF-8', 1), (float) $row->amount, '', 0, $user);
			if ($line <= 0) {
				return 'Error: the entry could not be made: '.$acc->error;
			}
			$this->reconcileLine($line, $d, $user);
			$this->db->query("UPDATE ".$p."onboarding_stmt SET status = 'added', fk_bank = ".((int) $line)." WHERE rowid = ".((int) $id));
			return 'Added to '.$acc->label.' and reconciled. Open it to give it a category or link it to a member or invoice.';
		}
		return '';
	}

	/**
	 * Reconcile an entry no statement shows, because a person checked it.
	 *
	 * @param int $lineId Bank entry
	 * @param User $user Who
	 * @return string What happened
	 */
	public function forceLine($lineId, $user)
	{
		$res = $this->db->query("SELECT dateo FROM ".$this->db->prefix()."bank WHERE rowid = ".((int) $lineId)." AND rappro = 0");
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o && $this->reconcileLine($lineId, substr((string) $o->dateo, 0, 10), $user) ? 'Reconciled.' : 'Already reconciled.';
	}
}
