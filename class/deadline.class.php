<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');

/**
 * Deadline reminders on ordinary Dolibarr project tasks.
 *
 * A task gets four extra fields: remind every N days, escalate this many days
 * before the due date, the user group to escalate to, and repeat every year.
 * The daily job then emails everyone assigned to the task every N days from its
 * start date until it is 100% done, emails the escalation group once when the
 * due date is that close and the task is still open, and when a yearly task is
 * done, makes next year's copy with the same people and settings.
 *
 * Each reminder carries a link that marks the task complete without logging in.
 * The link is signed for one task and one person, so it cannot be guessed or
 * reused for another task.
 */
class OnboardingDeadlines
{
	/** @var DoliDB */
	public $db;

	/** @var OnboardingService */
	public $svc;

	/** Extra fields on project tasks: label, type, size, position, help. */
	const FIELDS = array(
		'remind_every' => array('Remind assignees every (days)', 'int', 4, 1000, 'Emails everyone assigned to this task this often, from its start date until it is 100% done. Empty or 0: no reminders.'),
		'escalate_days' => array('Escalate this many days before due', 'int', 4, 1001, 'If the task is not done this many days before its due date, the escalation group gets one email. 0 means on the due date. Empty: never.'),
		'escalate_group' => array('Escalation group', 'sellist', '', 1002, 'The user group to email, for example Board. People assigned to the task are left out; they already get the reminders.'),
		'repeat_yearly' => array('Repeat every year', 'boolean', '', 1003, 'When the task is done, a copy for next year is made: same people and settings, start and due dates one year later.'),
	);

	/**
	 * @param DoliDB $db Database handler
	 * @param OnboardingService|null $svc Shared service
	 */
	public function __construct($db, $svc = null)
	{
		$this->db = $db;
		$this->svc = $svc ? $svc : new OnboardingService($db);
	}

	/**
	 * Create the extra fields on project tasks. Safe to run again.
	 *
	 * @param DoliDB $db Database handler
	 * @return void
	 */
	public static function installFields($db)
	{
		// No isModEnabled('project') check: when Projects is switched on as this
		// module's dependency, some versions only see it after the request.
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($db);
		$extrafields->fetch_name_optionals_label('projet_task', true);
		$have = isset($extrafields->attributes['projet_task']['label']) ? $extrafields->attributes['projet_task']['label'] : array();
		foreach (self::FIELDS as $name => $def) {
			if (isset($have[$name])) {
				continue;
			}
			$param = $def[1] == 'sellist' ? array('options' => array('usergroup:nom:rowid' => null)) : '';
			$extrafields->addExtraField($name, $def[0], $def[1], $def[3], $def[2], 'projet_task', 0, 0, '', $param, 1, '', '1', $def[4]);
		}
	}

	/**
	 * @param string $from Y-m-d
	 * @param string $to Y-m-d
	 * @return int Whole days from $from to $to (negative if $to is earlier)
	 */
	public static function days($from, $to)
	{
		return (int) round((strtotime($to.' 12:00:00') - strtotime($from.' 12:00:00')) / 86400);
	}

	/**
	 * @return string Today, Y-m-d, on the module's clock
	 */
	public static function today()
	{
		return date('Y-m-d', OnboardingService::now());
	}

	/**
	 * @param string|null $datetime Database datetime
	 * @return string|null Y-m-d
	 */
	private static function day($datetime)
	{
		return $datetime ? substr((string) $datetime, 0, 10) : null;
	}

	/**
	 * @param string $day Y-m-d
	 * @return string "Tue 15 Oct 2026"
	 */
	public static function show($day)
	{
		return date('D j M Y', strtotime($day.' 12:00:00'));
	}

	/**
	 * Secret for the mark-complete links, made on first use.
	 *
	 * @return string
	 */
	private function secret()
	{
		global $conf;
		$secret = getDolGlobalString('ONBOARDING_TASK_SECRET');
		if ($secret === '') {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			$secret = bin2hex(random_bytes(24));
			dolibarr_set_const($this->db, 'ONBOARDING_TASK_SECRET', $secret, 'chaine', 0, 'Signs the mark-complete links in deadline reminders', $conf->entity);
		}
		return $secret;
	}

	/**
	 * @param int $taskId Task
	 * @param int $userId Person the link is for
	 * @return string Signature
	 */
	public function key($taskId, $userId)
	{
		return substr(hash_hmac('sha256', 'task-done:'.((int) $taskId).':'.((int) $userId), $this->secret()), 0, 32);
	}

	/**
	 * @param int $taskId Task
	 * @param int $userId Person the link is for
	 * @param string $key Signature from the link
	 * @return bool
	 */
	public function verify($taskId, $userId, $key)
	{
		return $taskId > 0 && $userId > 0 && hash_equals($this->key($taskId, $userId), (string) $key);
	}

	/**
	 * @param int $taskId Task
	 * @param int $userId Person the link is for
	 * @return string Link that marks the task complete
	 */
	public function link($taskId, $userId)
	{
		return dol_buildpath('/onboarding/public/task.php', 2).'?t='.((int) $taskId).'&u='.((int) $userId).'&k='.$this->key($taskId, $userId);
	}

	/**
	 * @param int $taskId Task
	 * @return string The task's page in Dolibarr
	 */
	public static function cardUrl($taskId)
	{
		return DOL_MAIN_URL_ROOT.'/projet/tasks/task.php?id='.((int) $taskId).'&withproject=1';
	}

	/**
	 * @param int $taskId Task
	 * @return array<int,object> Active users assigned to the task, by id
	 */
	public function assignees($taskId)
	{
		$p = $this->db->prefix();
		$sql = "SELECT DISTINCT u.rowid, u.email, u.firstname, u.lastname FROM ".$p."element_contact AS ec";
		$sql .= " JOIN ".$p."c_type_contact AS tc ON tc.rowid = ec.fk_c_type_contact AND tc.element = 'project_task' AND tc.source = 'internal'";
		$sql .= " JOIN ".$p."user AS u ON u.rowid = ec.fk_socpeople";
		$sql .= " WHERE ec.element_id = ".((int) $taskId)." AND u.statut = 1";
		return $this->users($sql);
	}

	/**
	 * @param int $groupId User group
	 * @return array<int,object> Active users in the group, by id
	 */
	public function groupUsers($groupId)
	{
		$p = $this->db->prefix();
		$sql = "SELECT DISTINCT u.rowid, u.email, u.firstname, u.lastname FROM ".$p."usergroup_user AS ug";
		$sql .= " JOIN ".$p."user AS u ON u.rowid = ug.fk_user";
		$sql .= " WHERE ug.fk_usergroup = ".((int) $groupId)." AND u.statut = 1";
		return $this->users($sql);
	}

	/**
	 * @param string $sql Query returning rowid, email, firstname, lastname
	 * @return array<int,object>
	 */
	private function users($sql)
	{
		$out = array();
		$res = $this->db->query($sql);
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$out[(int) $obj->rowid] = $obj;
		}
		return $out;
	}

	/**
	 * @param object $u User row
	 * @return string "First Last"
	 */
	private static function name($u)
	{
		return trim($u->firstname.' '.$u->lastname);
	}

	/**
	 * @param int $taskId Task
	 * @param string $kind nag, escalate, complete, repeat
	 * @return object|null Latest notice of that kind
	 */
	public function lastNotice($taskId, $kind)
	{
		$res = $this->db->query("SELECT datec, fk_user, note FROM ".$this->db->prefix()."onboarding_task_notice WHERE fk_task = ".((int) $taskId)." AND kind = '".$this->db->escape($kind)."' ORDER BY datec DESC, rowid DESC LIMIT 1");
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param int $taskId Task
	 * @param string $kind nag, escalate, complete, repeat
	 * @param int $userId Recipient or actor
	 * @param string $note Note
	 * @return void
	 */
	private function log($taskId, $kind, $userId = 0, $note = '')
	{
		global $conf;
		$sql = "INSERT INTO ".$this->db->prefix()."onboarding_task_notice (entity, fk_task, kind, fk_user, note, datec) VALUES (";
		$sql .= ((int) $conf->entity).", ".((int) $taskId).", '".$this->db->escape($kind)."', ".($userId > 0 ? (int) $userId : "NULL").", ";
		$sql .= ($note !== '' ? "'".$this->db->escape(dol_trunc($note, 250, 'right', 'UTF-8', 1))."'" : "NULL").", '".$this->db->idate(OnboardingService::now())."')";
		$this->db->query($sql);
	}

	/**
	 * Tasks with any deadline setting, open or done, newest due date last.
	 *
	 * @param bool $openOnly Only tasks that are not done
	 * @return array<int,object>
	 */
	public function tasks($openOnly = false)
	{
		$p = $this->db->prefix();
		$sql = "SELECT t.rowid, t.ref, t.label, t.description, t.dateo, t.datee, t.progress, t.fk_statut, t.fk_projet, p.ref AS project_ref, p.title AS project,";
		$sql .= " ef.remind_every, ef.escalate_days, ef.escalate_group, ef.repeat_yearly";
		$sql .= " FROM ".$p."projet_task AS t";
		$sql .= " JOIN ".$p."projet AS p ON p.rowid = t.fk_projet";
		$sql .= " JOIN ".$p."projet_task_extrafields AS ef ON ef.fk_object = t.rowid";
		$sql .= " WHERE p.entity IN (".getEntity('project').") AND p.fk_statut <> 2";
		$sql .= " AND (ef.remind_every > 0 OR (ef.escalate_days IS NOT NULL AND ef.escalate_group > 0) OR ef.repeat_yearly = 1)";
		if ($openOnly) {
			$sql .= " AND ".self::OPEN;
		}
		$sql .= " ORDER BY t.datee IS NULL, t.datee, t.rowid";
		$out = array();
		$res = $this->db->query($sql);
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$out[(int) $obj->rowid] = $obj;
		}
		return $out;
	}

	/** SQL: the task is not done (not 100%, not closed, transferred or cancelled). */
	const OPEN = "COALESCE(t.progress, 0) < 100 AND COALESCE(t.fk_statut, 1) NOT IN (3, 4, 9)";

	/**
	 * @param object $t Task row from tasks()
	 * @return bool The task is done
	 */
	public static function isDone($t)
	{
		return (int) $t->progress >= 100 || in_array((int) $t->fk_statut, array(3, 4, 9), true);
	}

	/**
	 * @param object $t Task row from tasks()
	 * @return string|null Y-m-d of the next reminder, if any
	 */
	public function nextNag($t)
	{
		$every = (int) $t->remind_every;
		if ($every <= 0 || self::isDone($t)) {
			return null;
		}
		$today = self::today();
		$last = $this->lastNotice($t->rowid, 'nag');
		$next = $last ? date('Y-m-d', strtotime(self::day($last->datec).' 12:00:00') + $every * 86400) : (self::day($t->dateo) ?: $today);
		return $next < $today ? $today : $next;
	}

	/**
	 * @param object $t Task row from tasks()
	 * @return string|null Y-m-d when the escalation group is due to be told
	 */
	public static function escalateOn($t)
	{
		if ($t->escalate_days === null || $t->escalate_days === '' || (int) $t->escalate_group <= 0 || !$t->datee) {
			return null;
		}
		return date('Y-m-d', strtotime(self::day($t->datee).' 12:00:00') - (int) $t->escalate_days * 86400);
	}

	/**
	 * Daily job: reminders, escalations, next year's copies.
	 *
	 * @return string Summary
	 */
	public function run()
	{
		if (!isModEnabled('project')) {
			return 'deadlines: the Projects module is off';
		}
		$today = self::today();
		$nags = 0;
		$escalations = 0;
		$problems = array();

		foreach ($this->tasks(true) as $t) {
			$assignees = $this->assignees($t->rowid);
			$due = self::day($t->datee);

			$next = $this->nextNag($t);
			if ($next !== null && $next <= $today) {
				$sent = 0;
				foreach ($assignees as $u) {
					if ($u->email && $this->svc->mail($u->email, $this->nagSubject($t, $today), $this->nagBody($t, $u, $today))) {
						$this->log($t->rowid, 'nag', $u->rowid, $u->email);
						$sent++;
					}
				}
				if ($sent) {
					$nags += $sent;
				} else {
					// Recorded anyway, so the job does not retry every day.
					$this->log($t->rowid, 'nag', 0, $assignees ? 'no assignee has an email address' : 'nobody assigned');
					$problems[] = $t->ref.' '.($assignees ? 'has no assignee with an email address' : 'has nobody assigned');
				}
			}

			$on = self::escalateOn($t);
			if ($on !== null && $on <= $today && !$this->lastNotice($t->rowid, 'escalate')) {
				$sent = 0;
				foreach ($this->groupUsers((int) $t->escalate_group) as $u) {
					if (isset($assignees[$u->rowid]) || !$u->email) {
						continue;
					}
					if ($this->svc->mail($u->email, 'Not done yet: '.$t->label.' (due '.self::show($due).')', $this->escalateBody($t, $u, $assignees, $today))) {
						$this->log($t->rowid, 'escalate', $u->rowid, $u->email);
						$sent++;
					}
				}
				if ($sent) {
					$escalations += $sent;
				} else {
					$this->log($t->rowid, 'escalate', 0, 'nobody else in the group has an email address');
					$problems[] = $t->ref.': nobody in its escalation group could be emailed';
				}
			}
		}

		$repeated = $this->repeatDone();
		return 'deadlines: '.$nags.' reminders, '.$escalations.' escalations, '.$repeated.' repeated for next year'.($problems ? ' ('.implode('; ', $problems).')' : '');
	}

	/**
	 * @param object $t Task row
	 * @param string $today Y-m-d
	 * @return string
	 */
	private function nagSubject($t, $today)
	{
		$due = self::day($t->datee);
		if ($due && $due < $today) {
			return 'Overdue: '.$t->label.' (was due '.self::show($due).')';
		}
		return 'Reminder: '.$t->label.($due ? ' (due '.self::show($due).')' : '');
	}

	/**
	 * @param object $t Task row
	 * @param string $today Y-m-d
	 * @return string One line about the due date
	 */
	private static function dueLine($t, $today)
	{
		$due = self::day($t->datee);
		if (!$due) {
			return '"'.$t->label.'" has no due date.';
		}
		$left = self::days($today, $due);
		if ($left < 0) {
			return '"'.$t->label.'" was due '.self::show($due).', '.(-$left).' day'.($left == -1 ? '' : 's').' ago.';
		}
		if ($left == 0) {
			return '"'.$t->label.'" is due today.';
		}
		return '"'.$t->label.'" is due '.self::show($due).', in '.$left.' day'.($left == 1 ? '' : 's').'.';
	}

	/**
	 * @param object $t Task row
	 * @return string Description as plain text, or ''
	 */
	private static function description($t)
	{
		$text = trim(dol_string_nohtmltag((string) $t->description, 0));
		return $text !== '' ? "\n".$text."\n" : '';
	}

	/**
	 * @param object $t Task row
	 * @param object $u Recipient
	 * @param string $today Y-m-d
	 * @return string
	 */
	private function nagBody($t, $u, $today)
	{
		$every = (int) $t->remind_every;
		$body = 'Hi '.($u->firstname ?: self::name($u)).",\n\n";
		$body .= self::dueLine($t, $today).' It is part of '.$t->project.' and is '.((int) $t->progress).'% done.'."\n";
		$body .= self::description($t);
		$body .= "\nWhen it is done, mark it complete here (no login needed):\n".$this->link($t->rowid, $u->rowid)."\n";
		$body .= "\nOr open it in Dolibarr:\n".self::cardUrl($t->rowid)."\n";
		$body .= "\nYou will get this reminder every ".$every.' day'.($every == 1 ? '' : 's').' until the task is marked complete.';
		$on = self::escalateOn($t);
		if ($on !== null && $on > $today) {
			$body .= ' If it is not done by '.self::show($on).', the rest of the group is told.';
		}
		return $body."\n";
	}

	/**
	 * @param object $t Task row
	 * @param object $u Recipient
	 * @param array<int,object> $assignees Assigned users
	 * @param string $today Y-m-d
	 * @return string
	 */
	private function escalateBody($t, $u, $assignees, $today)
	{
		$names = array_map(array(__CLASS__, 'name'), $assignees);
		$body = 'Hi '.($u->firstname ?: self::name($u)).",\n\n";
		$body .= self::dueLine($t, $today).' It is not marked complete yet ('.((int) $t->progress).'% done).'."\n\n";
		$body .= ($names ? 'It is assigned to '.implode(', ', $names).'.' : 'Nobody is assigned to it.');
		$first = $this->db->query("SELECT MIN(datec) AS first FROM ".$this->db->prefix()."onboarding_task_notice WHERE fk_task = ".((int) $t->rowid)." AND kind = 'nag' AND fk_user IS NOT NULL");
		$row = $first ? $this->db->fetch_object($first) : null;
		if ($row && $row->first) {
			$body .= ' Reminders have gone out since '.self::show(self::day($row->first)).'.';
		}
		$body .= "\n".self::description($t);
		$body .= "\nOpen it in Dolibarr:\n".self::cardUrl($t->rowid)."\n";
		$body .= "\nIf it is already done, mark it complete here:\n".$this->link($t->rowid, $u->rowid)."\n";
		return $body;
	}

	/**
	 * Done yearly tasks that have no copy for next year yet get one.
	 *
	 * @return int Copies made
	 */
	public function repeatDone()
	{
		require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
		$p = $this->db->prefix();
		$sql = "SELECT t.rowid, t.fk_projet, t.fk_task_parent, t.dateo, t.datee FROM ".$p."projet_task AS t";
		$sql .= " JOIN ".$p."projet AS p ON p.rowid = t.fk_projet";
		$sql .= " JOIN ".$p."projet_task_extrafields AS ef ON ef.fk_object = t.rowid";
		$sql .= " WHERE p.entity IN (".getEntity('project').") AND p.fk_statut <> 2 AND ef.repeat_yearly = 1";
		$sql .= " AND (t.progress >= 100 OR t.fk_statut = 3)";
		$sql .= " AND NOT EXISTS (SELECT 1 FROM ".$p."onboarding_task_notice AS n WHERE n.fk_task = t.rowid AND n.kind = 'repeat')";
		$rows = array();
		$res = $this->db->query($sql);
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$rows[] = $obj;
		}
		$made = 0;
		$actor = $this->svc->actor();
		foreach ($rows as $obj) {
			$task = new Task($this->db);
			$newId = $task->createFromClone($actor, (int) $obj->rowid, (int) $obj->fk_projet, (int) $obj->fk_task_parent, false, true, false, false, true, false);
			if ($newId <= 0) {
				dol_syslog('Onboarding: could not copy task '.$obj->rowid.' for next year: '.$task->error, LOG_ERR);
				continue;
			}
			$set = array("progress = 0", "fk_statut = 1");
			foreach (array('dateo' => $obj->dateo, 'datee' => $obj->datee) as $col => $value) {
				if ($value) {
					$set[] = $col." = '".$this->db->idate(dol_time_plus_duree($this->db->jdate($value), 1, 'y'))."'";
				}
			}
			$this->db->query("UPDATE ".$p."projet_task SET ".implode(', ', $set)." WHERE rowid = ".((int) $newId));
			$this->log((int) $obj->rowid, 'repeat', 0, (string) $newId);
			$made++;
		}
		return $made;
	}

	/**
	 * Mark a task 100% done, from the email link.
	 *
	 * @param int $taskId Task
	 * @param int $userId Who used the link
	 * @return string done, already, or error
	 */
	public function complete($taskId, $userId)
	{
		require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
		$task = new Task($this->db);
		if ($task->fetch((int) $taskId) <= 0) {
			return 'error';
		}
		if ((int) $task->progress >= 100) {
			return 'already';
		}
		$task->progress = 100;
		if (defined('Task::STATUS_CLOSED')) {
			$task->status = Task::STATUS_CLOSED;
			$task->fk_statut = Task::STATUS_CLOSED;
		}
		$actor = new User($this->db);
		if ($actor->fetch((int) $userId) <= 0) {
			$actor = $this->svc->actor();
		}
		if ($task->update($actor) <= 0) {
			dol_syslog('Onboarding: marking task '.$taskId.' complete failed: '.$task->error, LOG_ERR);
			return 'error';
		}
		$this->log((int) $taskId, 'complete', (int) $userId, 'email link');
		return 'done';
	}
}
