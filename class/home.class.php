<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * The CGW home page (home.php): sections laid out by a YAML file, edited on
 * the setup page (CGW home page). home.default.yaml is used until it is.
 */

dol_include_once('/onboarding/class/yaml.class.php');
dol_include_once('/onboarding/class/onboarding.class.php');

/**
 * Build the CGW home page from its layout.
 */
class OnboardingHome
{
	/** @var DoliDB */
	public $db;

	/** Counts for "show: numbers": key => label */
	const NUMBERS = array(
		'active_members' => 'Active members',
		'paying_givebutter' => 'Pay by Givebutter',
		'paying_paypal' => 'Pay by PayPal',
		'paying_check' => 'Pay by check or cash',
		'scholarship' => 'Scholarship or waived',
		'expired' => 'Active, but end date passed',
		'not_paying' => 'Not paying (ended)',
		'signups_in_progress' => 'Signups in progress',
		'new_this_month' => 'New members this month',
		'dues_this_month' => 'Dues this month',
		'dues_last_month' => 'Dues last month',
		'unmatched_payments' => 'Unmatched payments',
		'emails_waiting' => 'Emails waiting for approval',
	);

	/** What MAIN_LANDING_PAGE holds when the CGW home page is the landing page */
	const LANDING = '/onboarding/home.php?mainmenu=cgw';

	/** Lists for "show: list" */
	const LISTS = array('recent_payments', 'new_members', 'expiring', 'upcoming_trainings', 'open_deadlines');

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @return string The layout in use: the setup page's, else the shipped default
	 */
	public static function layoutText()
	{
		$text = getDolGlobalString('ONBOARDING_HOME_YAML');
		if (trim($text) === '') {
			$text = (string) @file_get_contents(dirname(__DIR__).'/home.default.yaml');
		}
		return $text;
	}

	/**
	 * Read a layout and say what is wrong with it.
	 *
	 * @param string $text YAML
	 * @return array{0:array,1:string[]} Sections, and problems found
	 */
	public static function read($text)
	{
		try {
			$doc = OnboardingYaml::parse($text);
		} catch (InvalidArgumentException $e) {
			return array(array(), array($e->getMessage()));
		}
		$problems = array();
		$sections = isset($doc['sections']) && is_array($doc['sections']) ? $doc['sections'] : array();
		if (!$sections) {
			$problems[] = 'There is no "sections:" list.';
		}
		$out = array();
		foreach ($sections as $i => $s) {
			$name = 'Section '.($i + 1).(is_array($s) && isset($s['title']) ? ' ("'.$s['title'].'")' : '');
			if (!is_array($s) || empty($s['show'])) {
				$problems[] = $name.': needs "show:".';
				continue;
			}
			$show = (string) $s['show'];
			if ($show == 'numbers') {
				$keys = isset($s['numbers']) && is_array($s['numbers']) ? $s['numbers'] : array();
				foreach ($keys as $k) {
					if (!isset(self::NUMBERS[$k])) {
						$problems[] = $name.': unknown number "'.$k.'".';
					}
				}
			} elseif ($show == 'list') {
				if (!in_array(isset($s['list']) ? $s['list'] : '', self::LISTS, true)) {
					$problems[] = $name.': "list:" must be one of '.implode(', ', self::LISTS).'.';
				}
			} elseif ($show == 'links') {
				if (empty($s['links']) || !is_array($s['links'])) {
					$problems[] = $name.': needs a "links:" list of "- Label: address" lines.';
				}
			} elseif ($show == 'widget') {
				if (empty($s['widget']) || !preg_match('/^box_[a-z0-9_]+\.php$/', (string) $s['widget'])) {
					$problems[] = $name.': "widget:" must be a widget file name such as box_comptes.php.';
				}
			} elseif ($show != 'attention') {
				$problems[] = $name.': "show: '.$show.'" is not one of attention, numbers, list, links, widget.';
			}
			$out[] = $s;
		}
		return array($out, $problems);
	}

	/**
	 * @param array $s Section
	 * @param User $user Who is looking
	 * @return string HTML, '' when they may not see it
	 */
	public function render($s, $user)
	{
		switch ((string) $s['show']) {
			case 'attention':
				return $this->attention($user);
			case 'numbers':
				return $this->numbers(isset($s['numbers']) && is_array($s['numbers']) ? $s['numbers'] : array(), $user);
			case 'list':
				return $this->listing((string) $s['list'], max(1, min(50, (int) (isset($s['limit']) ? $s['limit'] : 8))), $user);
			case 'links':
				return $this->links($s['links'], $user);
			case 'widget':
				return $this->widget((string) $s['widget'], (int) (isset($s['limit']) ? $s['limit'] : 8));
		}
		return '';
	}

	// ---------------------------------------------------------------- sections

	/**
	 * @param User $user User
	 * @return string
	 */
	private function attention($user)
	{
		dol_include_once('/onboarding/class/attention.class.php');
		$rows = '';
		foreach ((new OnboardingAttention($this->db))->items($user) as $i) {
			if ($i['count'] > 0) {
				$rows .= '<tr class="oddeven"><td><a href="'.dol_escape_htmltag($i['url']).'">'.dol_escape_htmltag($i['label']).'</a></td><td class="right"><span class="badge '.($i['urgent'] ? 'badge-status8' : 'badge-status1').'">'.$i['count'].'</span></td></tr>';
			}
		}
		return $rows !== '' ? '<table class="noborder centpercent">'.$rows.'</table>' : '<span class="opacitymedium">Nothing needs attention.</span>';
	}

	/**
	 * @param string $sql A SELECT ... AS n
	 * @return float
	 */
	private function one($sql)
	{
		$res = @$this->db->query($sql);
		$obj = $res ? $this->db->fetch_object($res) : null;
		return $obj && $obj->n !== null ? (float) $obj->n : 0;
	}

	/**
	 * @param string[] $keys Which numbers
	 * @param User $user User
	 * @return string
	 */
	private function numbers($keys, $user)
	{
		global $conf;
		$p = $this->db->prefix();
		$members = $user->hasRight('adherent', 'lire');
		$now = OnboardingService::now();
		$today = $this->db->idate(dol_mktime(0, 0, 0, (int) dol_print_date($now, '%m'), (int) dol_print_date($now, '%d'), (int) dol_print_date($now, '%Y')));
		$month = dol_mktime(0, 0, 0, (int) dol_print_date($now, '%m'), 1, (int) dol_print_date($now, '%Y'));
		$lastMonth = dol_time_plus_duree($month, -1, 'm');
		$a = $p."adherent AS a LEFT JOIN ".$p."adherent_extrafields AS e ON e.fk_object = a.rowid WHERE a.entity IN (".getEntity('adherent').")";
		$list = DOL_URL_ROOT.'/adherents/list.php';
		$base = dol_buildpath('/onboarding/', 1);
		$tiles = '';
		foreach ($keys as $k) {
			$value = null;
			$url = '';
			$money = false;
			switch ($k) {
				case 'active_members':
					if ($members) {
						$value = $this->one("SELECT COUNT(*) AS n FROM ".$a." AND a.statut = 1");
						$url = $list.'?statut=1';
					}
					break;
				case 'paying_givebutter':
				case 'paying_paypal':
					if ($members) {
						$value = $this->one("SELECT COUNT(*) AS n FROM ".$a." AND a.statut = 1 AND e.payment_channel = '".($k == 'paying_paypal' ? 'PayPal' : 'Givebutter')."'");
						$url = $list.'?statut=1';
					}
					break;
				case 'paying_check':
					if ($members) {
						$value = $this->one("SELECT COUNT(*) AS n FROM ".$a." AND a.statut = 1 AND e.payment_channel IN ('Check', 'Cash')");
						$url = $list.'?statut=1';
					}
					break;
				case 'scholarship':
					if ($members) {
						$value = $this->one("SELECT COUNT(*) AS n FROM ".$a." AND a.statut = 1 AND (e.payment_channel = 'None' OR e.dues_waived_until >= '".$today."')");
						$url = $list.'?statut=1';
					}
					break;
				case 'expired':
					if ($members) {
						$value = $this->one("SELECT COUNT(*) AS n FROM ".$a." AND a.statut = 1 AND a.datefin < '".$today."'");
						$url = $list.'?statut=1&filter=outofdate';
					}
					break;
				case 'not_paying':
					if ($members) {
						$value = $this->one("SELECT COUNT(*) AS n FROM ".$a." AND a.statut = 0");
						$url = $list.'?statut=0';
					}
					break;
				case 'new_this_month':
					if ($members) {
						$value = $this->one("SELECT COUNT(*) AS n FROM ".$a." AND a.statut = 1 AND a.datevalid >= '".$this->db->idate($month)."'");
						$url = $list.'?statut=1';
					}
					break;
				case 'signups_in_progress':
					if ($user->hasRight('onboarding', 'applicant', 'read')) {
						$value = $this->one("SELECT COUNT(*) AS n FROM ".$p."onboarding_applicant WHERE entity = ".((int) $conf->entity)." AND stage <> 'complete' AND training_only = 0");
						$url = $base.'applicants.php';
					}
					break;
				case 'dues_this_month':
				case 'dues_last_month':
					if ($members) {
						$from = $k == 'dues_this_month' ? $month : $lastMonth;
						$to = $k == 'dues_this_month' ? dol_time_plus_duree($month, 1, 'm') : $month;
						$value = $this->one("SELECT SUM(s.subscription) AS n FROM ".$p."subscription AS s INNER JOIN ".$p."adherent AS a ON a.rowid = s.fk_adherent WHERE a.entity IN (".getEntity('adherent').") AND s.dateadh >= '".$this->db->idate($from)."' AND s.dateadh < '".$this->db->idate($to)."'");
						$url = DOL_URL_ROOT.'/adherents/subscription/list.php';
						$money = true;
					}
					break;
				case 'unmatched_payments':
					if ($user->hasRight('onboarding', 'applicant', 'read')) {
						$value = $this->one("SELECT COUNT(*) AS n FROM ".$p."onboarding_payment WHERE entity = ".((int) $conf->entity)." AND status = 'unmatched'") + $this->one("SELECT COUNT(*) AS n FROM ".$p."onboarding_paypal WHERE entity = ".((int) $conf->entity)." AND status = 'unmatched'");
						$url = $base.'payments.php';
					}
					break;
				case 'emails_waiting':
					if (!empty($user->admin)) {
						$value = $this->one("SELECT COUNT(*) AS n FROM ".$p."onboarding_mail_hold WHERE entity = ".((int) $conf->entity)." AND status IN ('pending', 'failed')");
						$url = $base.'mailgate.php';
					}
					break;
			}
			if ($value === null || !isset(self::NUMBERS[$k])) {
				continue;
			}
			$shown = $money ? price($value, 0, '', 1, -1, 0, $conf->currency) : (string) (int) $value;
			$tiles .= '<a href="'.dol_escape_htmltag($url).'" style="display:inline-block;min-width:9.5em;margin:0 .5em .5em 0;padding:.6em .8em;border:1px solid var(--colortopbordertitle1, #ccc);border-radius:6px;text-decoration:none">';
			$tiles .= '<span style="display:block;font-size:1.6em;font-weight:600">'.dol_escape_htmltag($shown).'</span><span class="opacitymedium small">'.dol_escape_htmltag(self::NUMBERS[$k]).'</span></a>';
		}
		return $tiles;
	}

	/**
	 * @param string $which List name
	 * @param int $limit Lines
	 * @param User $user User
	 * @return string
	 */
	private function listing($which, $limit, $user)
	{
		global $conf;
		$p = $this->db->prefix();
		$now = OnboardingService::now();
		$card = DOL_URL_ROOT.'/adherents/card.php?rowid=';
		$rows = array();
		$member = function ($o) use ($card) {
			return '<a href="'.$card.((int) $o->rowid).'">'.dol_escape_htmltag(trim($o->firstname.' '.$o->lastname)).'</a>';
		};
		if (in_array($which, array('recent_payments', 'new_members', 'expiring'), true) && !$user->hasRight('adherent', 'lire')) {
			return '';
		}
		if ($which == 'recent_payments') {
			$res = $this->db->query("SELECT a.rowid, a.firstname, a.lastname, s.subscription, s.note, s.datec FROM ".$p."subscription AS s INNER JOIN ".$p."adherent AS a ON a.rowid = s.fk_adherent WHERE a.entity IN (".getEntity('adherent').") ORDER BY s.datec DESC, s.rowid DESC LIMIT ".((int) $limit));
			while ($res && ($o = $this->db->fetch_object($res))) {
				$from = preg_match('/^(Givebutter|PayPal|Check|Cash|Venmo)/i', (string) $o->note, $m) ? $m[1] : '';
				$rows[] = array(dol_print_date($this->db->jdate($o->datec), 'day'), $member($o), price($o->subscription), dol_escape_htmltag($from));
			}
		} elseif ($which == 'new_members') {
			$res = $this->db->query("SELECT a.rowid, a.firstname, a.lastname, a.datevalid, e.payment_channel FROM ".$p."adherent AS a LEFT JOIN ".$p."adherent_extrafields AS e ON e.fk_object = a.rowid WHERE a.entity IN (".getEntity('adherent').") AND a.statut = 1 AND a.datevalid IS NOT NULL ORDER BY a.datevalid DESC LIMIT ".((int) $limit));
			while ($res && ($o = $this->db->fetch_object($res))) {
				$rows[] = array(dol_print_date($this->db->jdate($o->datevalid), 'day'), $member($o), dol_escape_htmltag((string) $o->payment_channel));
			}
		} elseif ($which == 'expiring') {
			// Active members whose paid-up date is within a week either side of today.
			$res = $this->db->query("SELECT a.rowid, a.firstname, a.lastname, a.datefin, e.payment_channel FROM ".$p."adherent AS a LEFT JOIN ".$p."adherent_extrafields AS e ON e.fk_object = a.rowid WHERE a.entity IN (".getEntity('adherent').") AND a.statut = 1 AND a.datefin BETWEEN '".$this->db->idate($now - 7 * 86400)."' AND '".$this->db->idate($now + 7 * 86400)."' ORDER BY a.datefin LIMIT ".((int) $limit));
			while ($res && ($o = $this->db->fetch_object($res))) {
				$end = $this->db->jdate($o->datefin);
				$rows[] = array(dol_print_date($end, 'day').($end < $now ? ' <span class="badge badge-status8">passed</span>' : ''), $member($o), dol_escape_htmltag((string) $o->payment_channel));
			}
		} elseif ($which == 'upcoming_trainings') {
			if (!$user->hasRight('onboarding', 'training', 'read')) {
				return '';
			}
			$res = $this->db->query("SELECT b.ref, b.session_start, b.firstname, b.lastname, b.status, t.label FROM ".$p."onboarding_booking AS b LEFT JOIN ".$p."onboarding_tool AS t ON t.rowid = b.fk_tool WHERE b.entity = ".((int) $conf->entity)." AND b.status IN ('paid', 'confirmed') AND b.session_start >= '".$this->db->idate($now)."' ORDER BY b.session_start LIMIT ".((int) $limit));
			while ($res && ($o = $this->db->fetch_object($res))) {
				$rows[] = array(dol_print_date($this->db->jdate($o->session_start), 'dayhour'), '<a href="'.dol_buildpath('/onboarding/trainings.php', 1).'?booking='.urlencode((string) $o->ref).'">'.dol_escape_htmltag((string) $o->label).'</a>', dol_escape_htmltag(trim($o->firstname.' '.$o->lastname)), $o->status == 'confirmed' ? 'confirmed' : '<span class="opacitymedium">waiting for the trainer</span>');
			}
		} elseif ($which == 'open_deadlines') {
			if (!isModEnabled('project') || !$user->hasRight('projet', 'lire')) {
				return '';
			}
			dol_include_once('/onboarding/class/deadline.class.php');
			$n = 0;
			foreach ((new OnboardingDeadlines($this->db))->tasks(true) as $t) {
				if ($n++ >= $limit) {
					break;
				}
				$due = $t->datee ? $this->db->jdate($t->datee) : 0;
				$rows[] = array($due ? dol_print_date($due, 'day').($due < $now ? ' <span class="badge badge-status8">overdue</span>' : '') : '', '<a href="'.DOL_URL_ROOT.'/projet/tasks/task.php?id='.((int) $t->rowid).'&withproject=1">'.dol_escape_htmltag($t->label).'</a>', ((int) $t->progress).'%');
			}
		}
		if (!$rows) {
			return '<span class="opacitymedium">Nothing to show.</span>';
		}
		$html = '<table class="noborder centpercent">';
		foreach ($rows as $r) {
			$html .= '<tr class="oddeven"><td class="nowraponall">'.implode('</td><td>', $r).'</td></tr>';
		}
		return $html.'</table>';
	}

	/**
	 * @param array $links "- Label: address" items
	 * @return string
	 */
	private function links($links, $user)
	{
		$out = '';
		foreach ((array) $links as $item) {
			if (!is_array($item)) {
				continue;
			}
			foreach ($item as $label => $url) {
				$url = (string) $url;
				if ($url === 'update') {
					$out .= $this->updateButton((string) $label, $user);
					continue;
				}
				if (!preg_match('#^(https?://|/)#', $url)) {
					continue;
				}
				$href = strpos($url, '/') === 0 ? DOL_URL_ROOT.$url : $url;
				$out .= '<a class="butAction" style="margin:0 .4em .4em 0" href="'.dol_escape_htmltag($href).'">'.dol_escape_htmltag((string) $label).'</a>';
			}
		}
		return $out;
	}

	/**
	 * "- Label: update": the setup page's Update from GitHub, in green, for admins.
	 * Says when a newer version is on GitHub; goes to the setup page's Module
	 * updates box when Dolibarr cannot write the module folder.
	 *
	 * @param string $label Label
	 * @param User $user User
	 * @return string
	 */
	private function updateButton($label, $user)
	{
		if (empty($user->admin)) {
			return '';
		}
		dol_include_once('/onboarding/class/selfupdate.class.php');
		$latest = (new OnboardingSelfUpdate($this->db))->latest();
		$newer = $latest !== '' && version_compare($latest, OnboardingSelfUpdate::installed(), '>');
		$setup = dol_buildpath('/onboarding/admin/setup.php', 1);
		$style = 'margin:0 .4em .4em 0;background:#2d6a4f;border-color:#2d6a4f;color:#fff';
		$text = dol_escape_htmltag($label).($newer ? ' to '.dol_escape_htmltag($latest) : '');
		if (OnboardingSelfUpdate::cannotWrite() !== '') {
			return '<a class="butAction" style="'.$style.'" href="'.$setup.'#module-updates">'.$text.'</a>';
		}
		// Back to this page when done, not the setup page.
		$back = isset($_SERVER['REQUEST_URI']) ? '&backtopage='.urlencode((string) $_SERVER['REQUEST_URI']) : '';
		return '<a class="butAction" style="'.$style.'" href="'.$setup.'?action=selfupdate&token='.newToken().dol_escape_htmltag($back).'" onclick="return confirm(\'Update Member onboarding from GitHub and turn it off and on?\')" title="'.dol_escape_htmltag($newer ? 'Version '.$latest.' is on GitHub' : 'Already the newest version on GitHub; updating again does no harm').'">'.$text.'</a>';
	}

	/**
	 * A Dolibarr home page widget, drawn here.
	 *
	 * @param string $file Widget file, e.g. box_comptes.php
	 * @param int $limit Lines
	 * @return string
	 */
	private function widget($file, $limit)
	{
		if (!preg_match('/^(box_[a-z0-9_]+)\.php$/', $file, $m)) {
			return '';
		}
		$path = DOL_DOCUMENT_ROOT.'/core/boxes/'.$file;
		if (!is_file($path)) {
			$path = dol_buildpath('/onboarding/core/boxes/'.$file, 0);
		}
		if (!is_file($path)) {
			return '<span class="opacitymedium">No widget called '.dol_escape_htmltag($file).'.</span>';
		}
		global $langs;
		// Widgets name themselves with keys from Dolibarr's language files.
		$langs->loadLangs(array('boxes', 'main', 'banks', 'members', 'bills', 'orders', 'projects', 'agenda', 'cron'));
		try {
			include_once $path;
			if (!class_exists($m[1])) {
				return '';
			}
			$box = new $m[1]($this->db, '');
			if (!empty($box->hidden)) {
				return ''; // Not for this user, by the widget's own rules.
			}
			$box->loadBox($limit > 0 ? $limit : 8);
			return (string) $box->showBox($box->info_box_head, $box->info_box_contents, 1);
		} catch (Throwable $e) {
			dol_syslog('Onboarding home: widget '.$file.' failed: '.$e->getMessage(), LOG_WARNING);
			return '<span class="opacitymedium">The '.dol_escape_htmltag($file).' widget could not be shown.</span>';
		}
	}
}
