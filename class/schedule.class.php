<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/booking.class.php');

/**
 * Tools, trainers' weekly slots, closed days, the catalog sent to the website,
 * and which session times are free. Reached through OnboardingBooking::schedule().
 */
class OnboardingSchedule
{
	/** @var OnboardingBooking */
	public $bk;
	/** @var DoliDB */
	public $db;
	/** @var OnboardingService */
	public $svc;

	/**
	 * @param OnboardingBooking $bk The booking service this is part of
	 */
	public function __construct($bk)
	{
		$this->bk = $bk;
		$this->db = $bk->db;
		$this->svc = $bk->svc;
	}

	// ---------------------------------------------------------------- tools

	/**
	 * @param bool $activeOnly Only tools in use
	 * @return object[] Tools, by zone and name
	 */
	public function tools($activeOnly = false)
	{
		global $conf;
		$out = array();
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_tool WHERE entity = ".((int) $conf->entity).($activeOnly ? " AND active = 1" : "")." ORDER BY zone, label");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param int $id Tool
	 * @return object|null
	 */
	public function tool($id)
	{
		global $conf;
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_tool WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param string $ref Tool id as the website knows it, e.g. 3d-printers
	 * @return object|null
	 */
	public function toolByRef($ref)
	{
		global $conf;
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_tool WHERE ref = '".$this->db->escape((string) $ref)."' AND entity = ".((int) $conf->entity));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}



	/**
	 * Add or change a tool.
	 *
	 * @param array<string,mixed> $in ref, label, zone, fee, member_discount, trainer_share, seats, minutes, covers, wiki_url, page_url, bookable, active
	 * @param User $by Who did it
	 * @param int $id Tool to change, 0 for a new one
	 * @return array{ok:bool,id?:int,error?:string}
	 */
	public function saveTool($in, $by, $id = 0)
	{
		global $conf;
		$get = function ($k, $max = 255) use ($in) {
			return dol_trunc(trim((string) (isset($in[$k]) ? $in[$k] : '')), $max, 'right', 'UTF-8', 1);
		};
		$ref = strtolower($get('ref', 64));
		$label = $get('label', 150);
		$zone = $get('zone', 100);
		if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $ref)) {
			return array('ok' => false, 'error' => 'The id must be lowercase letters, digits and dashes, for example 3d-printers.');
		}
		if ($label === '' || $zone === '') {
			return array('ok' => false, 'error' => 'A tool needs a name and a zone.');
		}
		$same = $this->toolByRef($ref);
		if ($same && (int) $same->rowid != (int) $id) {
			return array('ok' => false, 'error' => 'Another tool already uses the id '.$ref.'.');
		}
		$fee = (float) price2num($get('fee', 20));
		$discount = $get('member_discount', 10) === '' ? 50 : (float) price2num($get('member_discount', 10));
		$share = $get('trainer_share', 10) === '' ? 'NULL' : price2num(max(0, min(100, (float) price2num($get('trainer_share', 10)))));
		$cols = array(
			'ref' => "'".$this->db->escape($ref)."'",
			'label' => "'".$this->db->escape($label)."'",
			'zone' => "'".$this->db->escape($zone)."'",
			'fee' => price2num(max(0, $fee)),
			'member_discount' => price2num(max(0, min(100, $discount))),
			'trainer_share' => $share,
			'seats' => max(1, (int) $get('seats', 6)),
			'minutes' => max(15, (int) ($get('minutes', 6) ?: 60)),
			'covers' => "'".$this->db->escape($get('covers'))."'",
			'wiki_url' => "'".$this->db->escape($get('wiki_url'))."'",
			'page_url' => "'".$this->db->escape($get('page_url'))."'",
			'bookable' => empty($in['bookable']) ? 0 : 1,
			'active' => isset($in['active']) && empty($in['active']) ? 0 : 1,
		);
		$table = $this->db->prefix().'onboarding_tool';
		if ($id > 0) {
			$set = array();
			foreach ($cols as $k => $v) {
				$set[] = $k.' = '.$v;
			}
			if (!$this->db->query("UPDATE ".$table." SET ".implode(', ', $set)." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity))) {
				return array('ok' => false, 'error' => $this->db->lasterror());
			}
		} else {
			if (!$this->db->query("INSERT INTO ".$table." (entity, ".implode(', ', array_keys($cols)).", datec) VALUES (".((int) $conf->entity).", ".implode(', ', $cols).", '".$this->db->idate(OnboardingService::now())."')")) {
				return array('ok' => false, 'error' => $this->db->lasterror());
			}
			$id = (int) $this->db->last_insert_id($table);
		}
		$this->syncTrainerTags($id);
		$this->changed(($same ? 'Changed ' : 'Saved ').$label, $by);
		return array('ok' => true, 'id' => (int) $id);
	}

	/**
	 * @param int $toolId Tool, 0 for every tool
	 * @param bool $activeOnly Only slots in use
	 * @return object[] Trainer times, with the trainer's name
	 */
	public function slots($toolId = 0, $activeOnly = true)
	{
		global $conf;
		$p = $this->db->prefix();
		$sql = "SELECT s.*, a.firstname, a.lastname, a.email, a.statut FROM ".$p."onboarding_trainer_slot AS s LEFT JOIN ".$p."adherent AS a ON a.rowid = s.fk_adherent WHERE s.entity = ".((int) $conf->entity);
		if ($toolId > 0) {
			$sql .= " AND s.fk_tool = ".((int) $toolId);
		}
		if ($activeOnly) {
			$sql .= " AND s.active = 1";
		}
		$res = $this->db->query($sql." ORDER BY s.weekday, s.start_time, a.firstname");
		$out = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * @param int $id Slot
	 * @return object|null
	 */
	public function slot($id)
	{
		global $conf;
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT s.*, a.firstname, a.lastname, a.email FROM ".$p."onboarding_trainer_slot AS s LEFT JOIN ".$p."adherent AS a ON a.rowid = s.fk_adherent WHERE s.rowid = ".((int) $id)." AND s.entity = ".((int) $conf->entity));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * Give a trainer a weekly time on a tool. A trainer must be a member who is
	 * trained on the tool themselves, unless nobody is trained on it yet (the
	 * first trainer of a new tool).
	 *
	 * @param int $toolId Tool
	 * @param int $memberId Trainer
	 * @param int $weekday 1 Monday .. 7 Sunday
	 * @param string $time HH:MM
	 * @param int $minutes Length, 0 for the tool's
	 * @param int $seats Seats, 0 for the tool's
	 * @param User $by Who did it
	 * @return array{ok:bool,error?:string}
	 */
	public function addSlot($toolId, $memberId, $weekday, $time, $minutes, $seats, $by)
	{
		global $conf;
		$tool = $this->tool($toolId);
		if (!$tool || $memberId <= 0 || $weekday < 1 || $weekday > 7 || !preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time)) {
			return array('ok' => false, 'error' => 'Pick a trainer, a day and a time like 17:00.');
		}
		$time = str_pad($time, 5, '0', STR_PAD_LEFT);
		$adh = new Adherent($this->db);
		if ($adh->fetch($memberId) <= 0 || (int) $adh->statut != Adherent::STATUS_VALIDATED) {
			return array('ok' => false, 'error' => 'A trainer must be a current member.');
		}
		$anyone = $this->db->query("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_training WHERE entity = ".((int) $conf->entity)." AND status = 'recorded' AND (fk_tool = ".((int) $toolId)." OR tool = '".$this->db->escape($tool->label)."')");
		$trainedCount = $anyone ? (int) $this->db->fetch_object($anyone)->n : 0;
		if ($trainedCount > 0 && !$this->isTrainedOn($memberId, $tool)) {
			return array('ok' => false, 'error' => trim($adh->firstname.' '.$adh->lastname).' is not trained on the '.$tool->label.' yet. Record their training first (Mark trained, no fee).');
		}
		$ok = OnboardingSql::insert($this->db, 'onboarding_trainer_slot', array(
			'entity' => (int) $conf->entity,
			'fk_tool' => (int) $toolId,
			'fk_adherent' => (int) $memberId,
			'weekday' => (int) $weekday,
			'start_time' => $time,
			'minutes' => $minutes > 0 ? (int) $minutes : null,
			'seats' => $seats > 0 ? (int) $seats : null,
			'active' => 1,
			'datec' => $this->db->idate(OnboardingService::now()),
		));
		if (!$ok) {
			return array('ok' => false, 'error' => $this->db->lasterror());
		}
		$this->syncTrainerTags($toolId);
		$this->changed('Added '.trim($adh->firstname.' '.$adh->lastname).' on '.OnboardingBooking::WEEKDAYS[$weekday].'s at '.$time.' for the '.$tool->label, $by);
		return array('ok' => true);
	}

	/**
	 * Stop a trainer's weekly time. Bookings already made on it keep their date.
	 *
	 * @param int $slotId Slot
	 * @param User $by Who did it
	 * @return bool
	 */
	public function removeSlot($slotId, $by)
	{
		$slot = $this->slot($slotId);
		if (!$slot) {
			return false;
		}
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_trainer_slot SET active = 0 WHERE rowid = ".((int) $slotId));
		$this->syncTrainerTags((int) $slot->fk_tool);
		$tool = $this->tool((int) $slot->fk_tool);
		$this->changed('Removed '.trim($slot->firstname.' '.$slot->lastname).' on '.OnboardingBooking::WEEKDAYS[(int) $slot->weekday].'s at '.$slot->start_time.($tool ? ' for the '.$tool->label : ''), $by);
		return true;
	}

	/**
	 * @param int $memberId Member
	 * @param object $tool Tool
	 * @return bool A recorded training on this tool
	 */
	public function isTrainedOn($memberId, $tool)
	{
		global $conf;
		$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_training WHERE entity = ".((int) $conf->entity)." AND fk_adherent = ".((int) $memberId)." AND status = 'recorded' AND (fk_tool = ".((int) $tool->rowid)." OR tool = '".$this->db->escape($tool->label)."')");
		return $res && (int) $this->db->fetch_object($res)->n > 0;
	}

	/**
	 * The "Trainer: <tool>" tag goes on exactly the members with a time on the tool.
	 *
	 * @param int $toolId Tool
	 * @return void
	 */
	public function syncTrainerTags($toolId)
	{
		$tool = $this->tool($toolId);
		if (!$tool || !isModEnabled('categorie')) {
			return;
		}
		$tag = $this->trainerTag($tool->label);
		if (!$tag) {
			return;
		}
		$want = array();
		if ($tool->active) {
			foreach ($this->slots($toolId) as $s) {
				$want[(int) $s->fk_adherent] = true;
			}
		}
		$res = $this->db->query("SELECT fk_member FROM ".$this->db->prefix()."categorie_member WHERE fk_categorie = ".((int) $tag->id));
		$have = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$have[(int) $o->fk_member] = true;
		}
		foreach (array_diff_key($want, $have) as $id => $x) {
			$adh = new Adherent($this->db);
			if ($adh->fetch($id) > 0) {
				$tag->add_type($adh, Categorie::TYPE_MEMBER);
			}
		}
		foreach (array_diff_key($have, $want) as $id => $x) {
			$adh = new Adherent($this->db);
			if ($adh->fetch($id) > 0) {
				$tag->del_type($adh, Categorie::TYPE_MEMBER);
			}
		}
	}

	/**
	 * @param string $label Tool name
	 * @return Categorie|null The "Trainer: <tool>" member tag, under "Trainer"
	 */
	public function trainerTag($label)
	{
		$root = getDolGlobalString('ONBOARDING_TRAINER_TAG', 'Trainer');
		$parent = new Categorie($this->db);
		if ($parent->fetch(0, $root, Categorie::TYPE_MEMBER) <= 0) {
			$parent = new Categorie($this->db);
			$parent->label = $root;
			$parent->type = Categorie::TYPE_MEMBER;
			$parent->description = 'People who may run a training. Set on Members, Onboarding, Training tools, not by hand.';
			$parent->visible = 0;
			if ($parent->create($this->svc->actor()) <= 0) {
				return null;
			}
		}
		$name = dol_trunc($root.': '.$label, 180, 'right', 'UTF-8', 1);
		$tag = new Categorie($this->db);
		if ($tag->fetch(0, $name, Categorie::TYPE_MEMBER) > 0) {
			return $tag;
		}
		$tag = new Categorie($this->db);
		$tag->label = $name;
		$tag->type = Categorie::TYPE_MEMBER;
		$tag->fk_parent = $parent->id;
		$tag->description = 'May run the training for the '.$label.'.';
		$tag->visible = 0;
		return $tag->create($this->svc->actor()) > 0 ? $tag : null;
	}

	// ---------------------------------------------------------------- closed days

	/**
	 * @param string $from Y-m-d
	 * @param string $to Y-m-d
	 * @return array<string,string> Closed day => reason
	 */
	public function closedDays($from, $to)
	{
		global $conf;
		$out = array();
		$res = $this->db->query("SELECT day, reason FROM ".$this->db->prefix()."onboarding_closed_date WHERE entity = ".((int) $conf->entity)." AND day >= '".$this->db->escape($from)."' AND day <= '".$this->db->escape($to)."'");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[substr((string) $o->day, 0, 10)] = (string) $o->reason;
		}
		return $out;
	}

	/**
	 * @return object[] Closed days from today on
	 */
	public function upcomingClosed()
	{
		global $conf;
		$out = array();
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_closed_date WHERE entity = ".((int) $conf->entity)." AND day >= '".$this->db->escape(OnboardingBooking::fmt(OnboardingService::now(), 'Y-m-d'))."' ORDER BY day");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * Close a day by hand. Anyone booked that day is asked to pick a new date.
	 *
	 * @param string $day Y-m-d
	 * @param string $reason Why
	 * @param User $by Who did it
	 * @return string Result for the page
	 */
	public function closeDay($day, $reason, $by)
	{
		global $conf;
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
			return 'Pick a date.';
		}
		$this->db->query("INSERT INTO ".$this->db->prefix()."onboarding_closed_date (entity, day, reason, source, datec) VALUES (".((int) $conf->entity).", '".$this->db->escape($day)."', '".$this->db->escape(dol_trunc($reason, 255, 'right', 'UTF-8', 1))."', 'hand', '".$this->db->idate(OnboardingService::now())."')");
		$this->changed('Closed '.$day.($reason !== '' ? ' ('.$reason.')' : ''), $by);
		$n = $this->moveOffClosedDays();
		return 'Closed '.$day.'.'.($n ? ' '.$n.' booking'.($n > 1 ? 's were' : ' was').' cancelled and asked to pick a new date.' : '');
	}

	/**
	 * @param int $id Closed day row
	 * @param User $by Who did it
	 * @return void
	 */
	public function openDay($id, $by)
	{
		$this->db->query("DELETE FROM ".$this->db->prefix()."onboarding_closed_date WHERE rowid = ".((int) $id)." AND source = 'hand'");
		$this->changed('Reopened a closed day', $by);
	}

	/**
	 * The website found these days on the shop calendar with Open Hack Night
	 * cancelled. They replace the calendar's earlier list from today on.
	 *
	 * @param array<int,array{day:string,reason?:string}> $days Days
	 * @return array<string,mixed>
	 */
	public function setCalendarClosures($days)
	{
		global $conf;
		$today = OnboardingBooking::fmt(OnboardingService::now(), 'Y-m-d');
		$want = array();
		foreach ((array) $days as $d) {
			$day = is_array($d) && isset($d['day']) ? (string) $d['day'] : (string) $d;
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && $day >= $today) {
				$want[$day] = is_array($d) && isset($d['reason']) ? dol_trunc((string) $d['reason'], 255, 'right', 'UTF-8', 1) : 'Open Hack Night cancelled';
			}
		}
		$table = $this->db->prefix().'onboarding_closed_date';
		$have = array();
		$res = $this->db->query("SELECT rowid, day FROM ".$table." WHERE entity = ".((int) $conf->entity)." AND source = 'calendar' AND day >= '".$today."'");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$have[substr((string) $o->day, 0, 10)] = (int) $o->rowid;
		}
		$added = 0;
		foreach ($want as $day => $reason) {
			if (!isset($have[$day])) {
				$this->db->query("INSERT INTO ".$table." (entity, day, reason, source, datec) VALUES (".((int) $conf->entity).", '".$day."', '".$this->db->escape($reason)."', 'calendar', '".$this->db->idate(OnboardingService::now())."')");
				$added++;
			}
		}
		$removed = 0;
		foreach ($have as $day => $rowid) {
			if (!isset($want[$day])) {
				$this->db->query("DELETE FROM ".$table." WHERE rowid = ".$rowid);
				$removed++;
			}
		}
		if ($added || $removed) {
			$this->changed('Shop calendar: '.$added.' day(s) closed, '.$removed.' reopened', null);
		}
		$moved = $this->moveOffClosedDays();
		return array('ok' => true, 'added' => $added, 'removed' => $removed, 'moved' => $moved);
	}

	/**
	 * Bookings on a day that has since been closed lose their date.
	 *
	 * @return int Bookings moved off
	 */
	public function moveOffClosedDays()
	{
		$n = 0;
		$now = OnboardingService::now();
		$closed = $this->closedDays(OnboardingBooking::fmt($now, 'Y-m-d'), OnboardingBooking::fmt($now + 400 * 86400, 'Y-m-d'));
		if (!$closed) {
			return 0;
		}
		foreach ($this->bk->bookingsWhere("status IN ('paid', 'confirmed') AND fk_class IS NULL AND session_start > '".$this->db->idate($now)."'") as $b) {
			$day = OnboardingBooking::fmt($this->db->jdate($b->session_start), 'Y-m-d');
			if (isset($closed[$day])) {
				$this->bk->loseDate($b, 'closed', $closed[$day] !== '' ? $closed[$day] : 'The shop is closed that day.');
				$n++;
			}
		}
		return $n;
	}

	// ---------------------------------------------------------------- catalog and the website

	/**
	 * Note a change to the catalog and send the website its new copy.
	 *
	 * @param string $note What changed
	 * @param User|null $by Who did it
	 * @return void
	 */
	public function changed($note, $by)
	{
		global $conf;
		$this->db->query("INSERT INTO ".$this->db->prefix()."onboarding_catalog_log (entity, note, fk_user, datec) VALUES (".((int) $conf->entity).", '".$this->db->escape(dol_trunc($note, 255, 'right', 'UTF-8', 1))."', ".($by && $by->id ? (int) $by->id : 'NULL').", '".$this->db->idate(OnboardingService::now())."')");
		$this->pushCatalog();
	}

	/**
	 * @return int Catalog version: the number of changes so far
	 */
	public function version()
	{
		global $conf;
		$res = $this->db->query("SELECT MAX(rowid) AS v FROM ".$this->db->prefix()."onboarding_catalog_log WHERE entity = ".((int) $conf->entity));
		return $res ? (int) $this->db->fetch_object($res)->v : 0;
	}

	/**
	 * @param int $limit Rows
	 * @return object[] Latest catalog changes
	 */
	public function changes($limit = 20)
	{
		global $conf;
		$out = array();
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT l.*, u.login FROM ".$p."onboarding_catalog_log AS l LEFT JOIN ".$p."user AS u ON u.rowid = l.fk_user WHERE l.entity = ".((int) $conf->entity)." ORDER BY l.rowid DESC LIMIT ".((int) $limit));
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * What the website shows: tools, prices, trainers' first names and weekly
	 * times, closed days. Nothing private.
	 *
	 * @return array<string,mixed>
	 */
	public function catalog()
	{
		$tools = array();
		$zones = array();
		$now = OnboardingService::now();
		foreach ($this->tools(true) as $t) {
			$zones[$t->zone] = true;
			$slots = array();
			foreach ($this->slots((int) $t->rowid) as $s) {
				if ((int) $s->statut != Adherent::STATUS_VALIDATED) {
					continue;
				}
				$slots[] = array(
					'id' => (int) $s->rowid,
					'trainer' => (string) $s->firstname,
					'weekday' => (int) $s->weekday,
					'day' => OnboardingBooking::WEEKDAYS[(int) $s->weekday],
					'time' => (string) $s->start_time,
					'label' => OnboardingBooking::WEEKDAYS[(int) $s->weekday].'s at '.OnboardingBooking::fmt(OnboardingBooking::at(OnboardingBooking::fmt($now, 'Y-m-d'), $s->start_time), 'g:i A'),
					'minutes' => (int) ($s->minutes ?: $t->minutes),
					'seats' => (int) ($s->seats ?: $t->seats),
				);
			}
			$tools[] = array(
				'id' => (string) $t->ref,
				'name' => (string) $t->label,
				'zone' => (string) $t->zone,
				'fee' => (float) $t->fee,
				'member_fee' => OnboardingBooking::memberPrice($t),
				'seats' => (int) $t->seats,
				'minutes' => (int) $t->minutes,
				'covers' => (string) $t->covers,
				'wiki' => (string) $t->wiki_url,
				'page' => (string) $t->page_url,
				'bookable' => (bool) $t->bookable && count($slots) > 0,
				'slots' => $slots,
			);
		}
		$closed = array();
		foreach ($this->upcomingClosed() as $c) {
			$closed[] = array('day' => substr((string) $c->day, 0, 10), 'reason' => (string) $c->reason);
		}
		return array(
			'version' => $this->version(),
			'generated' => gmdate('Y-m-d\TH:i:s\Z', $now),
			'timezone' => OnboardingBooking::tz()->getName(),
			'zones' => array_keys($zones),
			'tools' => $tools,
			'closed' => $closed,
		);
	}

	/**
	 * The website fetched the catalog: remember which version it holds now.
	 *
	 * @param int $version Version the website reports after this fetch
	 * @return void
	 */
	public function websiteHas($version)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_set_const($this->db, 'ONBOARDING_WEBSITE_VERSION', (int) $version, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'ONBOARDING_WEBSITE_SEEN', OnboardingService::now(), 'chaine', 0, '', $conf->entity);
	}

	/**
	 * POST to the website Worker's sync endpoint.
	 *
	 * @param array<string,mixed> $payload catalog and/or lookups
	 * @return bool Accepted
	 */
	public function pushToWebsite($payload)
	{
		$base = rtrim(getDolGlobalString('ONBOARDING_WEBSITE_URL'), '/');
		$key = getDolGlobalString('ONBOARDING_API_KEY');
		if ($base === '' || $key === '') {
			return false;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
		$r = getURLContent($base.'/api/training/sync', 'POSTALREADYFORMATED', json_encode($payload), 1, array('Content-Type: application/json', 'X-Onboarding-Key: '.$key), array('http', 'https'), 2);
		$code = isset($r['http_code']) ? (int) $r['http_code'] : 0;
		$json = isset($r['content']) ? json_decode((string) $r['content'], true) : null;
		if ($code == 200 && is_array($json) && !empty($json['ok'])) {
			if (isset($json['version'])) {
				$this->websiteHas((int) $json['version']);
			}
			$this->svc->alertClear('website-push', 'The website accepts updates from Dolibarr again.');
			return true;
		}
		dol_syslog('Onboarding: website sync returned '.$code.' '.dol_trunc(isset($r['content']) ? (string) $r['content'] : (isset($r['curl_error_msg']) ? $r['curl_error_msg'] : ''), 200), LOG_WARNING);
		$this->svc->alert('website-push', 'Dolibarr cannot update the website', "Dolibarr tried to send the training catalog or a training record to the website and got HTTP ".$code." from ".$base."/api/training/sync.\n\nCheck that the website's DOLIBARR_API_KEY matches the \"Key for the website\" on the module setup page, and that the website address on that page is right. The website keeps its last copy and picks up changes every 15 minutes, so nothing is lost.");
		return false;
	}

	/**
	 * @return bool Catalog accepted by the website
	 */
	public function pushCatalog()
	{
		return $this->pushToWebsite(array('catalog' => $this->catalog()));
	}

	/**
	 * Send the website the masked lookup answer for these people, so "What am I
	 * trained on?" works while Dolibarr is down. The website stores it under a
	 * keyed hash of the email, never the email.
	 *
	 * @param string[] $emails Emails, empty for everyone trained
	 * @return int People sent
	 */
	public function pushLookups($emails = array())
	{
		global $conf;
		if (!$emails) {
			$res = $this->db->query("SELECT DISTINCT LOWER(email) AS e FROM ".$this->db->prefix()."onboarding_training WHERE entity = ".((int) $conf->entity)." AND email <> ''");
			while ($res && ($o = $this->db->fetch_object($res))) {
				$emails[] = (string) $o->e;
			}
		}
		$sent = 0;
		foreach (array_chunk(array_unique(array_filter($emails)), 100) as $chunk) {
			$rows = array();
			foreach ($chunk as $e) {
				$l = $this->bk->lookup($e);
				$rows[] = array('email' => OnboardingService::cleanEmail($e), 'found' => $l['found'], 'email_masked' => $l['email'], 'name' => $l['name'], 'trainings' => $l['trainings']);
			}
			if ($this->pushToWebsite(array('lookups' => $rows))) {
				$sent += count($rows);
			}
		}
		return $sent;
	}

	// ---------------------------------------------------------------- availability


	/**
	 * Seats taken on a trainer's session, and whether another tool has it.
	 *
	 * @param int $trainerId Trainer
	 * @param int $start Session start
	 * @param int $toolId Tool asked for
	 * @param int $exceptBooking A booking not to count (the one being moved)
	 * @return array{taken:int,other_tool:bool}
	 */
	public function seatsTaken($trainerId, $start, $toolId, $exceptBooking = 0)
	{
		global $conf;
		$now = OnboardingService::now();
		$sql = "SELECT fk_tool, COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_booking WHERE entity = ".((int) $conf->entity)." AND fk_trainer = ".((int) $trainerId)." AND session_start = '".$this->db->idate($start)."'";
		$sql .= " AND (status IN ('paid', 'confirmed') OR (status = 'held' AND hold_until > '".$this->db->idate($now)."'))";
		if ($exceptBooking) {
			$sql .= " AND rowid <> ".((int) $exceptBooking);
		}
		$res = $this->db->query($sql." GROUP BY fk_tool");
		$out = array('taken' => 0, 'other_tool' => false);
		while ($res && ($o = $this->db->fetch_object($res))) {
			if ((int) $o->fk_tool == (int) $toolId) {
				$out['taken'] += (int) $o->n;
			} elseif ((int) $o->n > 0) {
				$out['other_tool'] = true;
			}
		}
		return $out;
	}

	/**
	 * Sessions that can be booked: each trainer's weekly time from the booking
	 * cutoff to the booking horizon, less closed days, full sessions and
	 * sessions the trainer already gives on another tool.
	 *
	 * @param int $toolId Tool, 0 for all
	 * @param int $exceptBooking Booking being moved (its own seat is free)
	 * @return array<int,array<string,mixed>>
	 */
	public function available($toolId = 0, $exceptBooking = 0)
	{
		$now = OnboardingService::now();
		$from = $now + (int) round(OnboardingBooking::num('TRAINING_CLOSE_HOURS', 48) * 3600);
		$to = $now + (int) round(OnboardingBooking::num('TRAINING_WEEKS_AHEAD', 6) * 7 * 86400);
		$closed = $this->closedDays(OnboardingBooking::fmt($from, 'Y-m-d'), OnboardingBooking::fmt($to, 'Y-m-d'));
		$out = array();
		foreach ($this->tools(true) as $tool) {
			if (($toolId && (int) $tool->rowid != (int) $toolId) || !$tool->bookable) {
				continue;
			}
			foreach ($this->slots((int) $tool->rowid) as $slot) {
				if ((int) $slot->statut != Adherent::STATUS_VALIDATED) {
					continue;
				}
				$seats = (int) ($slot->seats ?: $tool->seats);
				foreach (OnboardingBooking::occurrences($slot, $from, $to) as $start) {
					if (isset($closed[OnboardingBooking::fmt($start, 'Y-m-d')])) {
						continue;
					}
					$t = $this->seatsTaken((int) $slot->fk_adherent, $start, (int) $tool->rowid, $exceptBooking);
					if ($t['other_tool'] || $t['taken'] >= $seats) {
						continue;
					}
					$out[] = array(
						'tool' => (string) $tool->ref,
						'tool_name' => (string) $tool->label,
						'slot' => (int) $slot->rowid,
						'trainer' => (string) $slot->firstname,
						'start' => gmdate('Y-m-d\TH:i:s\Z', $start),
						'ts' => $start,
						'label' => OnboardingBooking::fmt($start),
						'minutes' => (int) ($slot->minutes ?: $tool->minutes),
						'seats_left' => $seats - $t['taken'],
						'seats' => $seats,
					);
				}
			}
		}
		usort($out, function ($a, $b) {
			return $a['ts'] - $b['ts'];
		});
		return $out;
	}

	/**
	 * @param int $slotId Slot
	 * @param int $start Start asked for
	 * @param int $toolId Tool
	 * @param int $exceptBooking Booking being moved
	 * @return array<string,mixed>|null That session, if it can still be booked
	 */
	public function findAvailable($slotId, $start, $toolId, $exceptBooking = 0)
	{
		foreach ($this->available($toolId, $exceptBooking) as $a) {
			if ($a['slot'] == (int) $slotId && $a['ts'] == (int) $start) {
				return $a;
			}
		}
		return null;
	}
}
