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
	const TYPES = array('csv', 'txt', 'xlsx', 'xls', 'ofx', 'qfx', 'qbo', 'pdf');
	const MAX_FILE_BYTES = 10485760;
	// Reconciling rules: what to do with a statement line nobody entered.
	const DEFAULT_RULES = "# A statement line that matches no entry, and fits a rule, is entered by itself.
# One rule per line:   [account] in: words => accounting account (and its name)
#                  or: [account] in: words => transfer ACCOUNT
# [account] (part of the account's name or ref) and in:/out: are optional.
# The words are found anywhere in the line's description, in any case. The
# first rule that fits wins. Lines no rule fits wait for a person.
Fee: => 6300 Processing fees
SERVICE CHARGE => 6310 Bank fees
out: SECRETARY OF S => 6600 Filing fees
ACCTVERIFY => 4900 Other income (account checks)
[Venmo] in: soda => 4900 Other income (soda)
[Venmo] in: to Columbia Gadget Works => 4900 Other income (soda)
[Givebutter] in: Membership => 4000 Membership dues
[Givebutter] in: Training Fee => 4300 Class fees
[Givebutter] in: Donation => 4100 Donations
[PayPal] out: transfer to the bank => transfer CENTRAL
[Central] in: PAYPAL => transfer PAYPAL
[Central] in: GIVEBUTTER => transfer GIVEBUTTER
[Central] in: VENMO => transfer VENMO
";

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
	 * @return array{rows:array<int,array{date:string,amount:float,fee:float,alts:float[],label:string,ref:string,balance:float|null}>,error:string,end?:array{date:string,balance:float}|null}
	 */
	public static function read($path, $name)
	{
		$ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
		if (!in_array($ext, self::TYPES, true)) {
			return array('rows' => array(), 'error' => $name.': not a statement export (PDF, CSV, Excel, OFX or QFX).');
		}
		$head = (string) @file_get_contents($path, false, null, 0, 4096);
		if ($ext == 'pdf' || strpos($head, '%PDF') === 0) {
			$pdf = self::readPdf((string) file_get_contents($path));
			return array('rows' => $pdf['rows'], 'end' => $pdf['end'], 'begin' => $pdf['begin'], 'error' => $pdf['rows'] ? '' : $name.': no transactions found in the PDF. A scanned statement (a picture of the page) has no text to read; download the statement from online banking, or its CSV export.');
		}
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
	 * A PDF statement as printed: dated lines with an amount at the end, signed by
	 * the section they are in (Deposits, Checks, Withdrawals and other charges,
	 * Purchases, Payments and credits...), a column of checks (number, date,
	 * amount, two to a line), the year from the statement period, and the ending
	 * balance. A line in no known section is tried both ways when matching.
	 *
	 * @param string $data PDF bytes
	 * @return array{rows:array<int,array>,end:array{date:string,balance:float}|null}
	 */
	public static function readPdf($data)
	{
		dol_include_once('/onboarding/class/pdftext.class.php');
		$lines = array();
		foreach (OnboardingPdfText::lines($data) as $page) {
			foreach ($page as $l) {
				$lines[] = $l;
			}
		}
		$all = implode("\n", $lines);
		// The period: "09/01/2020 - 09/30/2020", else the newest full date.
		$to = '';
		if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{2,4})\s*(?:-|to|through|thru)\s*(\d{1,2}\/\d{1,2}\/\d{2,4})/i', $all, $m)) {
			$to = self::date($m[2]);
		}
		if ($to === '' && preg_match_all('/\b(\d{1,2}\/\d{1,2}\/\d{4}|(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.? \d{1,2}, \d{4})\b/i', $all, $mm)) {
			foreach ($mm[1] as $d) {
				$d = self::date(str_replace('.', '', $d));
				$to = $d > $to ? $d : $to;
			}
		}
		if ($to === '') {
			$to = date('Y-m-d', OnboardingService::now());
		}
		$months = array('jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12);
		$mon = '(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s*\d{1,2}(?:,?\s+\d{4})?';
		$num = '\d{1,2}\/\d{1,2}(?:\/\d{2,4})?';
		$dateRe = '(?:'.$mon.'|'.$num.'|\d{4}-\d{2}-\d{2})';
		// A day with no year: the statement's year, or the one before for a December line on a January statement.
		$day = function ($t) use ($to, $months) {
			$t = trim(str_replace('.', '', strtolower($t)));
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) || preg_match('/\d{4}$/', $t) || preg_match('/^\d{1,2}\/\d{1,2}\/\d{2}$/', $t)) {
				return self::date(preg_match('/^[a-z]/', $t) ? ucwords($t) : $t);
			}
			if (preg_match('/^([a-z]{3})[a-z]*\s*(\d{1,2})$/', $t, $m) && isset($months[$m[1]])) {
				list($mo, $d) = array($months[$m[1]], (int) $m[2]);
			} elseif (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $t, $m)) {
				list($mo, $d) = array((int) $m[1], (int) $m[2]);
			} else {
				return '';
			}
			$y = (int) substr($to, 0, 4);
			if (!checkdate($mo, $d, $y) && !checkdate($mo, $d, $y - 1)) {
				return '';
			}
			$cand = sprintf('%04d-%02d-%02d', $y, $mo, $d);
			return $cand > date('Y-m-d', strtotime($to.' 12:00:00') + 7 * 86400) ? sprintf('%04d-%02d-%02d', $y - 1, $mo, $d) : $cand;
		};
		$money = '-?\(?\$?\s?[\d,]*\d\.\d{2}\)?-?';
		$rows = array();
		$sign = 0;
		$balance = null;
		$begin = $end = null;
		$prev = -1;
		foreach ($lines as $line) {
			$low = strtolower(trim($line));
			$plain = trim(preg_replace('/\s+/', ' ', $low));
			// Section headings decide the sign of what follows.
			if (preg_match('/^(deposits|credits|other credits|additions|deposits (and|&) (other )?(credits|additions)|payments(,)? (and )?(other )?credits|payments|money in|incoming|interest (paid|earned))\b/', $plain) && !preg_match('/'.$money.'\s*$/', $plain)) {
				$sign = 1;
				$prev = -1;
				continue;
			}
			if (preg_match('/^(checks|checks paid|withdrawals|debits|other debits|withdrawals (and|&) other (charges|debits)|charges|fees|service charges|purchases|purchases (and|&) (adjustments|advances)|electronic (withdrawals|debits|payments)|money out|outgoing|card (transactions|purchases)|atm)\b/', $plain) && !preg_match('/'.$money.'\s*$/', $plain)) {
				$sign = -1;
				$prev = -1;
				continue;
			}
			if (preg_match('/^(beginning|opening|previous|starting) balance\b(.*?)('.$money.')\s*$/', $plain, $m)) {
				$balance = self::amount($m[3]);
				$d = $day(preg_match('/'.$dateRe.'/i', $m[2], $dm) ? $dm[0] : '');
				$begin = array('date' => $d, 'balance' => (float) $balance);
				$prev = -1;
				continue;
			}
			if (preg_match('/^(ending|closing|new) balance\b(.*?)('.$money.')\s*$/', $plain, $m)) {
				$d = $day(preg_match('/'.$dateRe.'/i', $m[2], $dm) ? $dm[0] : '');
				$end = array('date' => $d !== '' ? $d : $to, 'balance' => (float) self::amount($m[3]));
				$prev = -1;
				continue;
			}
			if (preg_match('/^(total|subtotal|balance|daily balance|date\b|check no)/', $plain)) {
				$prev = -1;
				continue;
			}
			$cols = explode('   ', trim($line));
			// Checks, often two to a line: number, date paid, amount.
			if (preg_match('/^\d{1,7}\*?\s/', $plain) && preg_match_all('/(?:^|\s)(\d{1,7})\*?\s+('.$dateRe.')\s+('.$money.')(?=\s|$)/i', $plain, $cm, PREG_SET_ORDER)) {
				foreach ($cm as $c) {
					$d = $day($c[2]);
					$a = self::amount($c[3]);
					if ($d !== '' && $a !== null) {
						$rows[] = array('date' => $d, 'amount' => -abs($a), 'fee' => 0.0, 'alts' => array(), 'label' => 'Check '.$c[1], 'ref' => 'check '.$c[1], 'balance' => null);
					}
				}
				$prev = -1;
				continue;
			}
			if (count($cols) >= 2 && preg_match('/^'.$dateRe.'$/i', trim($cols[0])) && ($d = $day($cols[0])) !== '') {
				// The amounts at the end: the transaction, then maybe the balance after it.
				$amounts = array();
				while (count($cols) > 1 && count($amounts) < 2 && preg_match('/^'.$money.'$/', str_replace(' ', '', trim(end($cols))))) {
					array_unshift($amounts, trim(array_pop($cols)));
				}
				if (!$amounts) {
					continue;
				}
				$a = self::amount($amounts[0]);
				$bal = isset($amounts[1]) ? self::amount($amounts[1]) : null;
				if ($a === null || abs($a) < 0.005) {
					continue;
				}
				$explicit = preg_match('/^-|-$|^\(/', str_replace(' ', '', $amounts[0]));
				$s = $explicit ? ($a < 0 ? -1 : 1) : $sign;
				if (!$explicit && $bal !== null && $balance !== null) {
					$s = round($bal - $balance, 2) == round(-abs($a), 2) ? -1 : (round($bal - $balance, 2) == round(abs($a), 2) ? 1 : $s);
				}
				if ($bal !== null) {
					$balance = $bal;
				}
				$amt = $s == 0 ? abs($a) : $s * abs($a);
				$rows[] = array('date' => $d, 'amount' => $amt, 'fee' => 0.0, 'alts' => $s == 0 ? array(-abs($a)) : array(), 'label' => dol_trunc(trim(implode(' ', array_slice($cols, 1))), 250, 'right', 'UTF-8', 1), 'ref' => '', 'balance' => $bal);
				$prev = count($rows) - 1;
				continue;
			}
			// A description carried onto the next line.
			if ($prev >= 0 && !preg_match('/'.$money.'\s*$/', $plain) && strlen($plain) < 80) {
				$rows[$prev]['label'] = dol_trunc($rows[$prev]['label'].' '.trim($line), 250, 'right', 'UTF-8', 1);
				$prev = -1;
			}
		}
		return array('rows' => $rows, 'end' => $end, 'begin' => $begin);
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
			'covered' => array('fee covered'),
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
			// Givebutter: a fee the donor chose to cover never left the organization's money.
			$covered = self::amount($cell($row, 'covered'));
			if ($covered !== null && abs($covered) + 0.005 >= abs($fee)) {
				$fee = 0.0;
			}
			// What actually arrived (Net, Payout) settles it: the fee is the difference.
			$net = self::amount($cell($row, 'net'));
			if ($net !== null && abs($net) > 0.005 && ($net > 0) === ($a > 0)) {
				$fee = round($net - $a, 2);
				$fee = abs($fee) < 0.005 || $fee > 0 ? 0.0 : $fee;
			}
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
		// The balance the statement ends on: given (a PDF's Ending Balance), else the newest day's, in the file's own order.
		$end = isset($file['end']['balance']) ? (float) $file['end']['balance'] : null;
		$endDate = isset($file['end']['date']) ? (string) $file['end']['date'] : '';
		$newestFirst = $rows && $rows[0]['date'] > $rows[count($rows) - 1]['date'];
		foreach ($end !== null ? array() : ($newestFirst ? $rows : array_reverse($rows)) as $r) {
			if ($r['date'] === $to && $r['balance'] !== null) {
				$end = (float) $r['balance'];
				$endDate = $to;
				break;
			}
		}
		// The balance it starts from: given, else the oldest line's balance less that line.
		$begin = isset($file['begin']['balance']) ? (float) $file['begin']['balance'] : null;
		$beginDate = isset($file['begin']['date']) ? (string) $file['begin']['date'] : '';
		if ($begin === null) {
			foreach ($newestFirst ? array_reverse($rows) : $rows as $r) {
				if ($r['date'] === $from && $r['balance'] !== null) {
					$begin = round((float) $r['balance'] - $r['amount'] - $r['fee'], 2);
					$beginDate = date('Y-m-d', strtotime($from.' 12:00:00') - 86400);
				}
				break;
			}
		}
		if ($begin !== null && $beginDate === '' && $from !== '') {
			$beginDate = date('Y-m-d', strtotime($from.' 12:00:00') - 86400);
		}
		$fileId = 0;
		if ($file['source'] == 'upload') {
			// The same export uploaded again: one record of it.
			$res = $this->db->query("SELECT rowid FROM ".$p."onboarding_stmt_file WHERE fk_account = ".((int) $accountId)." AND source = 'upload' AND original = '".$this->db->escape(dol_trunc($file['original'], 250, 'right', 'UTF-8', 1))."' AND date_from ".($from ? "= '".$from."'" : 'IS NULL')." AND date_to ".($to ? "= '".$to."'" : 'IS NULL')." AND nrows = ".count($rows));
			$o = $res ? $this->db->fetch_object($res) : null;
			if ($o) {
				// Read again: its unmatched lines are read afresh, so a better reading replaces them.
				$fileId = (int) $o->rowid;
				$this->db->query("DELETE FROM ".$p."onboarding_stmt WHERE fk_file = ".$fileId." AND status = 'open'");
				$this->db->query("UPDATE ".$p."onboarding_stmt_file SET datec = '".$this->db->idate(OnboardingService::now())."', begin_balance = ".($begin !== null ? price2num($begin) : 'NULL').", begin_date = ".($beginDate ? "'".$beginDate."'" : 'NULL').", end_balance = ".($end !== null ? price2num($end) : 'NULL').", end_date = ".($endDate ? "'".$endDate."'" : 'NULL')." WHERE rowid = ".$fileId);
			}
		}
		if ($file['source'] == 'api' || $file['source'] == 'plaid') {
			$res = $this->db->query("SELECT rowid, date_from, date_to FROM ".$p."onboarding_stmt_file WHERE fk_account = ".((int) $accountId)." AND source = '".$this->db->escape($file['source'])."'");
			$o = $res ? $this->db->fetch_object($res) : null;
			if ($o) {
				$fileId = (int) $o->rowid;
				$from = $o->date_from && $o->date_from < $from ? $o->date_from : $from;
				$to = $o->date_to && $o->date_to > $to ? $o->date_to : $to;
				$this->db->query("UPDATE ".$p."onboarding_stmt_file SET date_from = ".($from ? "'".$from."'" : 'NULL').", date_to = ".($to ? "'".$to."'" : 'NULL').", nrows = nrows + ".count($rows).", datec = '".$this->db->idate(OnboardingService::now())."' WHERE rowid = ".$fileId);
			}
		}
		if (!$fileId) {
			$this->db->query("INSERT INTO ".$p."onboarding_stmt_file (entity, fk_account, source, filename, original, date_from, date_to, begin_balance, begin_date, end_balance, end_date, nrows, who, datec) VALUES (".((int) $conf->entity).", ".((int) $accountId).", '".$this->db->escape($file['source'])."', ".($file['filename'] !== '' ? "'".$this->db->escape($file['filename'])."'" : 'NULL').", '".$this->db->escape(dol_trunc($file['original'], 250, 'right', 'UTF-8', 1))."', ".($from ? "'".$from."'" : 'NULL').", ".($to ? "'".$to."'" : 'NULL').", ".($begin !== null ? price2num($begin) : 'NULL').", ".($beginDate ? "'".$beginDate."'" : 'NULL').", ".($end !== null ? price2num($end) : 'NULL').", ".($endDate ? "'".$endDate."'" : 'NULL').", ".count($rows).", '".$this->db->escape(dol_trunc($file['who'], 250, 'right', 'UTF-8', 1))."', '".$this->db->idate(OnboardingService::now())."')");
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
		// Lines from before the account's first entry in Dolibarr: the books did not exist yet.
		$start = $this->booksStart($accountId);
		if ($start !== '') {
			$this->db->query("UPDATE ".$p."onboarding_stmt SET status = 'ignored', note = 'before' WHERE fk_account = ".((int) $accountId)." AND status = 'open' AND dateo < '".$this->db->escape($start)."'");
		}
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
			return $this->transfers($accountId, $user);
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
		$this->settleLeftovers($accountId, $user);
		$this->applyRules($accountId, $user);
		return $done;
	}

	/**
	 * @param int $accountId Account
	 * @return string Day of its first entry in Dolibarr (the opening balance), '' when it has none
	 */
	public function booksStart($accountId)
	{
		$res = $this->db->query("SELECT MIN(dateo) AS d FROM ".$this->db->prefix()."bank WHERE fk_account = ".((int) $accountId));
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o && $o->d ? substr((string) $o->d, 0, 10) : '';
	}

	/**
	 * Statement lines with no unreconciled entry that need no person:
	 * - an entry of that amount nearby was reconciled earlier, by hand or before
	 *   this page (and no other statement line claims it): matched to it;
	 * - the same transaction from another upload of the account (a CSV and the
	 *   PDF of the same month) that is already matched: left out as a duplicate.
	 *
	 * @param int $accountId Account
	 * @return void
	 */
	private function settleLeftovers($accountId, $user)
	{
		$p = $this->db->prefix();
		$w = self::window();
		$this->lumps($accountId, $user);
		$this->transfers($accountId, $user);
		$open = array();
		$res = $this->db->query("SELECT rowid, fk_file, dateo, amount, alt_amount FROM ".$p."onboarding_stmt WHERE fk_account = ".((int) $accountId)." AND status = 'open' ORDER BY dateo, rowid");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$open[(int) $o->rowid] = $o;
		}
		if (!$open) {
			return;
		}
		$amounts = function ($o) {
			$a = array(number_format(round((float) $o->amount, 2), 2, '.', ''));
			if ($o->alt_amount !== null && $o->alt_amount !== '') {
				$a[] = number_format(round((float) $o->alt_amount, 2), 2, '.', '');
			}
			return $a;
		};
		// Reconciled entries no statement line points to.
		$free = array();
		$sql = "SELECT b.rowid, b.dateo, b.amount FROM ".$p."bank AS b WHERE b.fk_account = ".((int) $accountId)." AND b.rappro = 1";
		$sql .= " AND NOT EXISTS (SELECT 1 FROM ".$p."onboarding_stmt AS s WHERE s.fk_bank = b.rowid)";
		$res = $this->db->query($sql);
		while ($res && ($o = $this->db->fetch_object($res))) {
			$free[number_format(round((float) $o->amount, 2), 2, '.', '')][] = array((int) $o->rowid, substr((string) $o->dateo, 0, 10));
		}
		foreach ($open as $id => $o) {
			$best = null;
			foreach ($amounts($o) as $k) {
				foreach (isset($free[$k]) ? $free[$k] : array() as $i => $f) {
					$d = self::apart(substr((string) $o->dateo, 0, 10), $f[1]);
					if ($d <= $w && ($best === null || $d < $best[0])) {
						$best = array($d, $k, $i, $f[0]);
					}
				}
			}
			if ($best) {
				$this->db->query("UPDATE ".$p."onboarding_stmt SET status = 'matched', note = 'already', fk_bank = ".((int) $best[3])." WHERE rowid = ".((int) $id));
				unset($free[$best[1]][$best[2]], $open[$id]);
			}
		}
		// The same line from another upload, already matched.
		$taken = array();
		foreach ($open as $id => $o) {
			$in = implode(' OR ', array_map(function ($k) {
				return 'ABS(amount - '.$k.') < 0.005';
			}, $amounts($o)));
			$d = substr((string) $o->dateo, 0, 10);
			$sql = "SELECT rowid, fk_bank FROM ".$p."onboarding_stmt WHERE fk_account = ".((int) $accountId)." AND status IN ('matched', 'added') AND fk_file <> ".((int) $o->fk_file);
			$sql .= " AND (".$in.") AND dateo >= '".date('Y-m-d', strtotime($d.' 12:00:00') - $w * 86400)."' AND dateo <= '".date('Y-m-d', strtotime($d.' 12:00:00') + $w * 86400)."'";
			$sql .= " ORDER BY ABS(DATEDIFF(dateo, '".$d."')), rowid";
			$res = $this->db->query($sql);
			while ($res && ($t = $this->db->fetch_object($res))) {
				if (!isset($taken[(int) $t->rowid])) {
					$taken[(int) $t->rowid] = true;
					$this->db->query("UPDATE ".$p."onboarding_stmt SET status = 'ignored', note = 'duplicate', fk_bank = ".($t->fk_bank ? (int) $t->fk_bank : 'NULL')." WHERE rowid = ".((int) $id));
					break;
				}
			}
		}
	}

	/**
	 * Entries typed as one total for many statement lines (a month of $1 Venmo
	 * sodas, card purchases recorded as the payment that paid them): the lines
	 * from the 45 days before the entry (and the matching window after) that add
	 * up to it exactly, fees with fees and payments with payments, are matched to
	 * it, and it is reconciled.
	 *
	 * @param int $accountId Account
	 * @param User $user Who
	 * @return int Entries reconciled
	 */
	private function lumps($accountId, $user)
	{
		$p = $this->db->prefix();
		$w = self::window();
		$rows = array();
		$res = $this->db->query("SELECT rowid, dateo, amount, kind FROM ".$p."onboarding_stmt WHERE fk_account = ".((int) $accountId)." AND status = 'open' ORDER BY dateo DESC, rowid DESC");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$rows[(int) $o->rowid] = array('date' => substr((string) $o->dateo, 0, 10), 'cents' => (int) round((float) $o->amount * 100), 'kind' => (string) $o->kind);
		}
		if (count($rows) < 2) {
			return 0;
		}
		$first = 0;
		$res = $this->db->query("SELECT rowid FROM ".$p."bank WHERE fk_account = ".((int) $accountId)." ORDER BY dateo, rowid LIMIT 1");
		if ($res && ($o = $this->db->fetch_object($res))) {
			$first = (int) $o->rowid;
		}
		$done = 0;
		// Never a transfer, a payout or a member's payment: only an entry linked to nothing.
		$res = $this->db->query("SELECT b.rowid, b.dateo, b.amount FROM ".$p."bank AS b WHERE b.fk_account = ".((int) $accountId)." AND b.rappro = 0 AND b.rowid <> ".$first." AND NOT EXISTS (SELECT 1 FROM ".$p."bank_url AS u WHERE u.fk_bank = b.rowid) AND NOT EXISTS (SELECT 1 FROM ".$p."subscription AS sb WHERE sb.fk_bank = b.rowid) ORDER BY b.dateo, b.rowid");
		$entries = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$entries[] = $o;
		}
		foreach ($entries as $e) {
			$target = (int) round((float) $e->amount * 100);
			$day = substr((string) $e->dateo, 0, 10);
			if ($target == 0 || abs($target) > 500000) {
				continue;
			}
			$lo = date('Y-m-d', strtotime($day.' 12:00:00') - 45 * 86400);
			$hi = date('Y-m-d', strtotime($day.' 12:00:00') + $w * 86400);
			foreach (array('main', 'fee') as $kind) {
				// Closest to the entry first: a lump usually covers the days just before it.
				$cand = array();
				foreach ($rows as $id => $r) {
					if ($r['kind'] === $kind && $r['date'] >= $lo && $r['date'] <= $hi && ($r['cents'] > 0) === ($target > 0) && abs($r['cents']) <= abs($target)) {
						$cand[$id] = abs($r['cents']);
					}
				}
				if (count($cand) < 2) {
					continue;
				}
				$set = self::subset($cand, abs($target));
				if (count($set) < 2) {
					continue;
				}
				if ($this->reconcileLine((int) $e->rowid, $day, $user)) {
					$this->db->query("UPDATE ".$p."onboarding_stmt SET status = 'matched', note = 'lump', fk_bank = ".((int) $e->rowid)." WHERE rowid IN (".implode(',', array_map('intval', $set)).")");
					foreach ($set as $id) {
						unset($rows[$id]);
					}
					$done++;
				}
				break;
			}
		}
		return $done;
	}

	/**
	 * Lines whose amounts add up to the target: the closest ones in order if
	 * they do, else any combination of the first 40.
	 *
	 * @param array<int,int> $cand Line => cents, closest first
	 * @param int $target Cents
	 * @return int[] Lines, empty when none add up
	 */
	public static function subset($cand, $target)
	{
		$sum = 0;
		$take = array();
		foreach ($cand as $id => $c) {
			$sum += $c;
			$take[] = $id;
			if ($sum === $target) {
				return $take;
			}
			if ($sum > $target) {
				break;
			}
		}
		// Which sums can be made, and from which line each was first reached.
		$ids = array_slice(array_keys($cand), 0, 40);
		$from = array(0 => array(-1, -1));
		foreach ($ids as $i => $id) {
			$c = $cand[$id];
			foreach (array_keys($from) as $s) {
				$n = $s + $c;
				if ($n <= $target && !isset($from[$n])) {
					$from[$n] = array($s, $i);
				}
			}
			if (isset($from[$target])) {
				break;
			}
			if (count($from) > 200000) {
				return array();
			}
		}
		if (!isset($from[$target])) {
			return array();
		}
		$out = array();
		for ($s = $target; $s > 0; $s = $from[$s][0]) {
			$out[] = $ids[$from[$s][1]];
		}
		return $out;
	}

	/**
	 * The other half of a transfer between our own accounts: when one side is
	 * reconciled on its statement (a Givebutter payout seen arriving at the bank),
	 * the side here is reconciled too.
	 *
	 * @param int $accountId Account
	 * @param User $user Who
	 * @return int Entries reconciled
	 */
	public function transfers($accountId, $user)
	{
		$p = $this->db->prefix();
		$sql = "SELECT b.rowid, b.dateo, o.num_releve FROM ".$p."bank AS b";
		$sql .= " INNER JOIN ".$p."bank_url AS u ON u.fk_bank = b.rowid AND u.type = 'banktransfert'";
		$sql .= " INNER JOIN ".$p."bank AS o ON o.rowid = u.url_id AND o.rappro = 1";
		$sql .= " WHERE b.fk_account = ".((int) $accountId)." AND b.rappro = 0";
		$res = $this->db->query($sql);
		$n = 0;
		$todo = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$todo[] = $o;
		}
		foreach ($todo as $o) {
			if ($this->reconcileLine((int) $o->rowid, substr((string) $o->dateo, 0, 10), $user)) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * An entry no statement shows that has a twin: the same amount a few days
	 * away, reconciled to a statement line. Usually the same payment entered
	 * twice (by hand, and by the PayPal sync).
	 *
	 * @param object $line Entry (rowid, fk_account, dateo, amount)
	 * @return object|null The twin (rowid, dateo, label)
	 */
	public function twin($line)
	{
		$p = $this->db->prefix();
		$d = substr((string) $line->dateo, 0, 10);
		$w = self::window();
		$res = $this->db->query("SELECT b.rowid, b.dateo, b.label FROM ".$p."bank AS b WHERE b.fk_account = ".((int) $line->fk_account)." AND b.rowid <> ".((int) $line->rowid)." AND b.rappro = 1 AND ABS(b.amount - ".price2num((float) $line->amount).") < 0.005 AND b.dateo >= '".date('Y-m-d', strtotime($d.' 12:00:00') - $w * 86400)."' AND b.dateo <= '".date('Y-m-d', strtotime($d.' 12:00:00') + $w * 86400)."' AND EXISTS (SELECT 1 FROM ".$p."onboarding_stmt AS s WHERE s.fk_bank = b.rowid) ORDER BY ABS(DATEDIFF(b.dateo, '".$this->db->escape($d)."')) LIMIT 1");
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param object $line Entry (rowid)
	 * @return string The other account, when the entry is one side of a transfer between our own accounts
	 */
	public function transferOf($line)
	{
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT ba.label FROM ".$p."bank_url AS u INNER JOIN ".$p."bank AS o ON o.rowid = u.url_id INNER JOIN ".$p."bank_account AS ba ON ba.rowid = o.fk_account WHERE u.fk_bank = ".((int) $line->rowid)." AND u.type = 'banktransfert' LIMIT 1");
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o ? (string) $o->label : '';
	}

	/**
	 * @param int $lineId Bank entry
	 * @return bool Something is linked to it (a member, payment, subscription or transfer)
	 */
	private function linked($lineId)
	{
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT (SELECT COUNT(*) FROM ".$p."bank_url WHERE fk_bank = ".((int) $lineId).") + (SELECT COUNT(*) FROM ".$p."subscription WHERE fk_bank = ".((int) $lineId).") AS n");
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o && (int) $o->n > 0;
	}

	/**
	 * Fix a payment entered twice: keep the copy something is linked to (the
	 * PayPal sync's, linked to the member), delete the other, and leave the kept
	 * one reconciled to the statement.
	 *
	 * @param int $lineId The copy no statement shows
	 * @param User $user Who
	 * @return string What happened
	 */
	public function fixTwin($lineId, $user)
	{
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT rowid, fk_account, dateo, amount, label FROM ".$p."bank WHERE rowid = ".((int) $lineId)." AND rappro = 0");
		$line = $res ? $this->db->fetch_object($res) : null;
		$twin = $line ? $this->twin($line) : null;
		if (!$twin) {
			return 'Error: no second copy found.';
		}
		// Keep the copy a subscription points to, else the one linked to something,
		// else the one the statement matched.
		$weigh = function ($id) use ($p) {
			$res = $this->db->query("SELECT (SELECT COUNT(*) FROM ".$p."subscription WHERE fk_bank = ".((int) $id).") AS s, (SELECT COUNT(*) FROM ".$p."bank_url WHERE fk_bank = ".((int) $id)." AND type IN ('payment', 'payment_supplier', 'payment_various', 'banktransfert', 'payment_donation', 'payment_expensereport', 'payment_salary', 'payment_sc', 'payment_vat')) AS pay, (SELECT COUNT(*) FROM ".$p."bank_url WHERE fk_bank = ".((int) $id).") AS u");
			$o = $res ? $this->db->fetch_object($res) : null;
			return $o ? array((int) $o->s, (int) $o->pay, (int) $o->u) : array(0, 0, 0);
		};
		$mine = $weigh((int) $line->rowid);
		$theirs = $weigh((int) $twin->rowid);
		if ($mine[1] && $theirs[1]) {
			return 'Error: both copies belong to a payment or transfer; open them and delete the wrong one by hand.';
		}
		$keepMine = $mine[0] > $theirs[0] || ($mine[0] == $theirs[0] && ($mine[1] > $theirs[1] || ($mine[1] == $theirs[1] && $mine[2] > $theirs[2])));
		$keep = $keepMine ? (int) $line->rowid : (int) $twin->rowid;
		$drop = $keepMine ? (int) $twin->rowid : (int) $line->rowid;
		$extra = '';
		// Both copies are a subscription's: the dues were recorded twice. The second
		// one goes with its entry, and the member's paid-until date is worked out again.
		$res = $this->db->query("SELECT rowid FROM ".$p."subscription WHERE fk_bank = ".$drop);
		$sub = $res ? $this->db->fetch_object($res) : null;
		if ($sub && $mine[0] && $theirs[0]) {
			require_once DOL_DOCUMENT_ROOT.'/adherents/class/subscription.class.php';
			$so = new Subscription($this->db);
			if ($so->fetch((int) $sub->rowid) > 0) {
				$this->db->query("UPDATE ".$p."subscription SET fk_bank = NULL WHERE rowid = ".((int) $sub->rowid));
				$so->fk_bank = 0;
				if ($so->delete($user) > 0) {
					$extra = ' The dues had been recorded twice on the member card too; the second record was removed.';
				}
			}
		} elseif ($sub) {
			$this->db->query("UPDATE ".$p."subscription SET fk_bank = ".$keep." WHERE rowid = ".((int) $sub->rowid));
		}
		$res = $this->db->query("SELECT num_releve FROM ".$p."bank WHERE rowid = ".((int) $twin->rowid));
		$o = $res ? $this->db->fetch_object($res) : null;
		$num = $o ? (string) $o->num_releve : '';
		$this->db->query("UPDATE ".$p."onboarding_stmt SET fk_bank = ".$keep." WHERE fk_bank = ".$drop);
		$this->db->query("UPDATE ".$p."bank SET rappro = 1, num_releve = '".$this->db->escape($num)."', fk_user_rappro = ".((int) $user->id)." WHERE rowid = ".$keep);
		$this->db->query("UPDATE ".$p."bank SET rappro = 0 WHERE rowid = ".$drop);
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$al = new AccountLine($this->db);
		if ($al->fetch($drop) <= 0 || $al->delete($user) <= 0) {
			return 'Error: the second copy could not be deleted: '.$al->error;
		}
		return 'Double entry fixed: one copy deleted, the other kept and reconciled.'.$extra;
	}

	/**
	 * The rules on the setup page (or the built-in ones).
	 *
	 * @return array<int,array{text:string,account:string,sign:int,words:string,code:string,name:string,transfer:string}>
	 */
	public static function rules()
	{
		$text = getDolGlobalString('ONBOARDING_RECONCILE_RULES', self::DEFAULT_RULES);
		$out = array();
		foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || strpos($line, '=>') === false) {
				continue;
			}
			list($left, $right) = array_map('trim', explode('=>', $line, 2));
			$r = array('text' => $line, 'account' => '', 'sign' => 0, 'words' => '', 'code' => '', 'name' => '', 'transfer' => '');
			if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $left, $m)) {
				$r['account'] = strtolower(trim($m[1]));
				$left = $m[2];
			}
			if (preg_match('/^(in|out):\s*(.*)$/i', $left, $m)) {
				$r['sign'] = strtolower($m[1]) === 'in' ? 1 : -1;
				$left = $m[2];
			}
			$r['words'] = strtolower(trim($left));
			if (preg_match('/^transfer\s+(.+)$/i', $right, $m)) {
				$r['transfer'] = trim($m[1]);
			} elseif (preg_match('/^(\S+)\s*(.*)$/', $right, $m)) {
				$r['code'] = $m[1];
				$r['name'] = trim($m[2]);
			}
			if ($r['words'] !== '' && ($r['code'] !== '' || $r['transfer'] !== '')) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * @param object $acc Account (ref, label)
	 * @param object $row Statement line (amount, label)
	 * @return array|null The first rule that fits
	 */
	public static function ruleFor($acc, $row)
	{
		$name = strtolower($acc->ref.' '.$acc->label);
		$label = strtolower((string) $row->label);
		foreach (self::rules() as $r) {
			if ($r['account'] !== '' && strpos($name, $r['account']) === false) {
				continue;
			}
			if ($r['sign'] && ($r['sign'] > 0) !== ((float) $row->amount > 0)) {
				continue;
			}
			if (strpos($label, $r['words']) !== false) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * Enter the account's still unmatched statement lines that a rule fits: as a
	 * miscellaneous payment on the rule's accounting account, or as a transfer
	 * with another of our accounts. Each is reconciled, and listed under "Entered
	 * by rules" with an Undo.
	 *
	 * @param int $accountId Account
	 * @param User $user Who
	 * @return int Lines entered
	 */
	public function applyRules($accountId, $user)
	{
		if (!self::rules()) {
			return 0;
		}
		$p = $this->db->prefix();
		$accs = $this->accounts();
		if (!isset($accs[$accountId])) {
			return 0;
		}
		$acc = $accs[$accountId];
		$rows = array();
		$res = $this->db->query("SELECT * FROM ".$p."onboarding_stmt WHERE fk_account = ".((int) $accountId)." AND status = 'open' AND (note IS NULL OR note <> 'norule') ORDER BY dateo, rowid");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$rows[] = $o;
		}
		$n = 0;
		foreach ($rows as $row) {
			$r = self::ruleFor($acc, $row);
			if (!$r) {
				continue;
			}
			$line = $r['transfer'] !== '' ? $this->ruleTransfer($accountId, $row, $r['transfer'], $user) : $this->ruleEntry($accountId, $row, $r, $user);
			if ($line > 0) {
				$this->reconcileLine($line, substr((string) $row->dateo, 0, 10), $user);
				$this->db->query("UPDATE ".$p."onboarding_stmt SET status = 'added', note = 'rule', fk_bank = ".((int) $line)." WHERE rowid = ".((int) $row->rowid));
				$n++;
			}
		}
		return $n;
	}

	/**
	 * @return int The new entry, or 0
	 */
	private function ruleEntry($accountId, $row, $r, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$acc = new Account($this->db);
		if ($acc->fetch((int) $accountId) <= 0) {
			return 0;
		}
		$t = strtotime(substr((string) $row->dateo, 0, 10).' 12:00:00');
		$pv = new PaymentVarious($this->db);
		$pv->fk_account = (int) $accountId;
		$pv->datep = $t;
		$pv->datev = $t;
		$pv->sens = (float) $row->amount > 0 ? 1 : 0;
		$pv->amount = abs((float) $row->amount);
		$pv->type_payment = dol_getIdFromCode($this->db, $acc->type == Account::TYPE_CASH ? 'LIQ' : 'VIR', 'c_paiement', 'code', 'id', 1);
		$pv->label = dol_trunc(trim((string) $row->label) !== '' ? (string) $row->label : $r['name'], 250, 'right', 'UTF-8', 1);
		$pv->accountancy_code = $r['code'];
		$pv->note = 'Entered by the reconciling rule "'.$r['text'].'"';
		if ($pv->create($user) <= 0) {
			dol_syslog('Onboarding: rule entry failed: '.$pv->error, LOG_WARNING);
			return 0;
		}
		$pv->fetch($pv->id);
		return (int) $pv->fk_bank;
	}

	/**
	 * A transfer with another of our accounts: this side as the statement shows it,
	 * the other side the opposite, linked as Dolibarr links them.
	 *
	 * @return int This side's entry, or 0
	 */
	private function ruleTransfer($accountId, $row, $target, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$other = 0;
		foreach ($this->accounts() as $a) {
			if ((int) $a->rowid !== (int) $accountId && (strcasecmp($a->ref, $target) === 0 || stripos($a->label, $target) !== false)) {
				$other = (int) $a->rowid;
				break;
			}
		}
		$here = new Account($this->db);
		$there = new Account($this->db);
		if (!$other || $here->fetch((int) $accountId) <= 0 || $there->fetch($other) <= 0) {
			return 0;
		}
		$t = strtotime(substr((string) $row->dateo, 0, 10).' 12:00:00');
		$amount = (float) $row->amount;
		$a = $here->addline($t, 'VIR', ($amount < 0 ? 'Transfer to ' : 'Transfer from ').$there->label, $amount, '', 0, $user);
		$b = $there->addline($t, 'VIR', ($amount < 0 ? 'Transfer from ' : 'Transfer to ').$here->label, -$amount, '', 0, $user);
		if ($a <= 0 || $b <= 0) {
			return 0;
		}
		$here->add_url_line($a, $b, DOL_URL_ROOT.'/compta/bank/line.php?rowid=', '(banktransfert)', 'banktransfert');
		$there->add_url_line($b, $a, DOL_URL_ROOT.'/compta/bank/line.php?rowid=', '(banktransfert)', 'banktransfert');
		return (int) $a;
	}

	/**
	 * @param int $accountId Account
	 * @return object[] Lines entered by rules in the last 120 days, newest first
	 */
	public function byRules($accountId)
	{
		$out = array();
		$res = $this->db->query("SELECT s.*, pv.accountancy_code FROM ".$this->db->prefix()."onboarding_stmt AS s LEFT JOIN ".$this->db->prefix()."payment_various AS pv ON pv.fk_bank = s.fk_bank WHERE s.fk_account = ".((int) $accountId)." AND s.status = 'added' AND s.note = 'rule' AND s.datec >= '".$this->db->idate(OnboardingService::now() - 120 * 86400)."' ORDER BY s.dateo DESC, s.rowid DESC LIMIT 300");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * Take back what a rule entered: the entry (and a transfer's other side) is
	 * deleted, and the line waits for a person; no rule enters it again.
	 *
	 * @param int $id Statement line
	 * @param User $user Who
	 * @return string What happened
	 */
	public function undoRule($id, $user)
	{
		$row = $this->row($id);
		if (!$row || $row->status != 'added' || $row->note != 'rule' || !$row->fk_bank) {
			return 'Error: that line was not entered by a rule.';
		}
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';
		$p = $this->db->prefix();
		$drop = array((int) $row->fk_bank);
		$pvId = 0;
		$res = $this->db->query("SELECT url_id, type FROM ".$p."bank_url WHERE fk_bank = ".((int) $row->fk_bank));
		while ($res && ($o = $this->db->fetch_object($res))) {
			if ($o->type == 'banktransfert') {
				$drop[] = (int) $o->url_id;
			} elseif ($o->type == 'payment_various') {
				$pvId = (int) $o->url_id;
			}
		}
		foreach ($drop as $l) {
			$this->db->query("UPDATE ".$p."bank SET rappro = 0 WHERE rowid = ".((int) $l));
			$al = new AccountLine($this->db);
			if ($al->fetch($l) > 0) {
				$al->delete($user);
			}
		}
		if ($pvId) {
			$pv = new PaymentVarious($this->db);
			if ($pv->fetch($pvId) > 0) {
				$pv->delete($user);
			}
		}
		$this->db->query("UPDATE ".$p."onboarding_stmt SET status = 'open', note = 'norule', fk_bank = NULL WHERE rowid = ".((int) $id));
		return 'Taken back: the line waits for a person, and no rule enters it again.';
	}

	/**
	 * Why a statement line found no entry, in a few words, for the person looking.
	 *
	 * @param object $row Statement line
	 * @return string
	 */
	public function why($row)
	{
		$p = $this->db->prefix();
		$d = substr((string) $row->dateo, 0, 10);
		$w = self::window();
		$a = round((float) $row->amount, 2);
		$res = $this->db->query("SELECT rowid, dateo, rappro FROM ".$p."bank WHERE fk_account = ".((int) $row->fk_account)." AND ABS(amount - ".price2num($a).") < 0.005 AND dateo >= '".date('Y-m-d', strtotime($d.' 12:00:00') - 60 * 86400)."' AND dateo <= '".date('Y-m-d', strtotime($d.' 12:00:00') + 60 * 86400)."' ORDER BY ABS(DATEDIFF(dateo, '".$this->db->escape($d)."')) LIMIT 1");
		$o = $res ? $this->db->fetch_object($res) : null;
		if ($o) {
			$n = self::apart($d, substr((string) $o->dateo, 0, 10));
			return 'Dolibarr has '.self::money($a).' on '.dol_print_date($this->db->jdate($o->dateo), 'day').', '.$n.' days away'.((int) $o->rappro ? ', already reconciled with another line' : ', more than '.$w.' days, so not matched: if it is this, match it');
		}
		$res = $this->db->query("SELECT amount FROM ".$p."bank WHERE fk_account = ".((int) $row->fk_account)." AND rappro = 0 AND dateo >= '".date('Y-m-d', strtotime($d.' 12:00:00') - 2 * 86400)."' AND dateo <= '".date('Y-m-d', strtotime($d.' 12:00:00') + 2 * 86400)."' LIMIT 4");
		$near = array();
		while ($res && ($x = $this->db->fetch_object($res))) {
			$near[] = self::money((float) $x->amount);
		}
		return $near ? 'Unmatched entries those days in Dolibarr: '.implode(', ', $near).' (a typo or a lump sum?)' : 'Nothing like it in Dolibarr: it was probably never entered';
	}

	/**
	 * What was set aside without a person, by reason.
	 *
	 * @param int $accountId Account
	 * @return array<string,int> before, duplicate, already => count
	 */
	public function setAside($accountId)
	{
		$out = array();
		$res = $this->db->query("SELECT note, COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_stmt WHERE fk_account = ".((int) $accountId)." AND note IS NOT NULL GROUP BY note");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[(string) $o->note] = (int) $o->n;
		}
		return $out;
	}

	/**
	 * A statement against Dolibarr: balances at both ends, money in and out between.
	 *
	 * @param object $f Statement file (with begin and end balances)
	 * @return array{stmt:array{begin:float,in:float,out:float,end:float},doli:array{begin:float,in:float,out:float,end:float}}|null
	 */
	public function compare($f)
	{
		if ($f->begin_balance === null || $f->end_balance === null || !$f->begin_date || !$f->end_date) {
			return null;
		}
		$p = $this->db->prefix();
		$b = substr((string) $f->begin_date, 0, 10);
		$e = substr((string) $f->end_date, 0, 10);
		$res = $this->db->query("SELECT SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS i, SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) AS o FROM ".$p."onboarding_stmt WHERE fk_file = ".((int) $f->rowid)." AND (note IS NULL OR note <> 'duplicate')");
		$s = $res ? $this->db->fetch_object($res) : null;
		$res = $this->db->query("SELECT SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS i, SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) AS o FROM ".$p."bank WHERE fk_account = ".((int) $f->fk_account)." AND dateo > '".$b." 23:59:59' AND dateo <= '".$e." 23:59:59'");
		$d = $res ? $this->db->fetch_object($res) : null;
		return array(
			'stmt' => array('begin' => round((float) $f->begin_balance, 2), 'in' => round((float) ($s ? $s->i : 0), 2), 'out' => round((float) ($s ? $s->o : 0), 2), 'end' => round((float) $f->end_balance, 2)),
			'doli' => array('begin' => $this->balanceAt((int) $f->fk_account, $b), 'in' => round((float) ($d ? $d->i : 0), 2), 'out' => round((float) ($d ? $d->o : 0), 2), 'end' => $this->balanceAt((int) $f->fk_account, $e)),
		);
	}

	/**
	 * Undo an upload: entries it reconciled go back to unreconciled (unless another
	 * statement matched them too), and its lines are forgotten. Entries made from
	 * its lines (Add, or a rule) stay: they are real.
	 *
	 * @param int $fileId Upload
	 * @return string What happened
	 */
	public function undo($fileId)
	{
		$p = $this->db->prefix();
		$lines = array();
		$res = $this->db->query("SELECT DISTINCT fk_bank FROM ".$p."onboarding_stmt WHERE fk_file = ".((int) $fileId)." AND status = 'matched' AND fk_bank IS NOT NULL");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$lines[] = (int) $o->fk_bank;
		}
		$n = 0;
		foreach ($lines as $l) {
			$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$p."onboarding_stmt WHERE fk_bank = ".$l." AND fk_file <> ".((int) $fileId)." AND status IN ('matched', 'added')");
			$o = $res ? $this->db->fetch_object($res) : null;
			if ($o && (int) $o->n === 0) {
				$this->db->query("UPDATE ".$p."bank SET rappro = 0, num_releve = NULL WHERE rowid = ".$l);
				$n++;
			}
		}
		$this->db->query("DELETE FROM ".$p."onboarding_stmt WHERE fk_file = ".((int) $fileId));
		$this->db->query("DELETE FROM ".$p."onboarding_stmt_file WHERE rowid = ".((int) $fileId));
		return 'Upload undone: '.$n.' entries are unreconciled again. Upload the file again to match it afresh.';
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
		// Turned around only when that clearly fits better.
		$minus = $this->signScore($accountId, $rows, -1);
		$flipped = $minus >= 2 && $minus > 2 * $this->signScore($accountId, $rows, 1);
		if ($flipped) {
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
		$end = isset($read['end']) ? $read['end'] : null;
		$begin = isset($read['begin']) ? $read['begin'] : null;
		if ($end && $flipped) {
			$end['balance'] = -$end['balance'];
		}
		if ($begin && $flipped) {
			$begin['balance'] = -$begin['balance'];
		}
		$this->store($accountId, $rows, array('source' => 'upload', 'original' => dol_sanitizeFileName(basename($name)), 'filename' => $stored, 'who' => trim($user->getFullName($GLOBALS['langs']).' ('.$user->login.')'), 'end' => $end, 'begin' => $begin));
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
		// Every entry on the file's days, reconciled or not: a file read again must
		// keep the sign it had, though its entries are reconciled by now.
		$from = $to = '';
		foreach ($rows as $r) {
			$from = ($from === '' || $r['date'] < $from) ? $r['date'] : $from;
			$to = ($to === '' || $r['date'] > $to) ? $r['date'] : $to;
		}
		$w = self::window();
		$have = array();
		$res = $this->db->query("SELECT amount FROM ".$this->db->prefix()."bank WHERE fk_account = ".((int) $accountId)." AND dateo >= '".date('Y-m-d', strtotime($from.' 12:00:00') - $w * 86400)."' AND dateo <= '".date('Y-m-d', strtotime($to.' 12:00:00') + $w * 86400)." 23:59:59'");
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
			// The account's first entry is its opening balance, not a transaction a statement shows.
			$first = 0;
			$res = $this->db->query("SELECT rowid FROM ".$p."bank WHERE fk_account = ".((int) $acc)." ORDER BY dateo, rowid LIMIT 1");
			if ($res && ($o = $this->db->fetch_object($res))) {
				$first = (int) $o->rowid;
			}
			$res = $this->db->query("SELECT rowid, fk_account, dateo, amount, label FROM ".$p."bank WHERE fk_account = ".((int) $acc)." AND rappro = 0 AND rowid <> ".$first." ORDER BY dateo, rowid");
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
