<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Email approval. When an approver is set on the setup page, every email
 * Dolibarr is about to send (this module's, Dolibarr's own password resets,
 * mass emailings, anything that goes through CMailFile) to someone not on the
 * exempt list is held instead. The approver gets a copy with buttons:
 *
 *   Approve                             send this one
 *   Approve, and always allow this kind send it, and send this kind from now on
 *   Reject                              never send it
 *
 * The "kind" is where the email comes from in the code plus the fixed start of
 * its subject, e.g. "OnboardingService::lapse | your columbia gadget works
 * membership has ended", so allowing one kind never lets another through. A
 * mass emailing is one kind per mailing, approved once for all its recipients.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Hold, show, release and reject emails.
 */
class OnboardingMailGate
{
	/** @var bool True while the gate sends (approval requests, released emails), so they pass */
	public static $passing = false;

	/** @var DoliDB */
	public $db;

	/**
	 * Frames of the call stack that are not where an email comes from: the mail
	 * classes and the module's helpers that only pass an email along.
	 */
	const WRAPPERS = array(
		'CMailFile::*', 'HookManager::*', 'ActionsOnboarding::*', 'OnboardingMailGate::*',
		'OnboardingService::mail', 'OnboardingBooking::mailTpl', 'OnboardingVote::mailTo',
	);

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @return string The approver's address, '' when email approval is off
	 */
	public static function approver()
	{
		$all = self::addresses(getDolGlobalString('ONBOARDING_MAILGATE_APPROVER'));
		return $all ? $all[0] : '';
	}

	/**
	 * Bare, lower-case addresses in a list such as '"Ann" <ann@x.org>, bob@y.org'.
	 *
	 * @param string $list Addresses
	 * @return string[]
	 */
	public static function addresses($list)
	{
		preg_match_all('/[A-Z0-9._%+\'-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', (string) $list, $m);
		return array_values(array_unique(array_map('strtolower', $m[0])));
	}

	/**
	 * @return string[] Addresses that are never held: the exempt list and the approver
	 */
	public static function exempt()
	{
		$all = self::addresses(getDolGlobalString('ONBOARDING_MAILGATE_EXEMPT'));
		$approver = self::approver();
		if ($approver !== '') {
			$all[] = $approver;
		}
		return array_values(array_unique($all));
	}

	// ---------------------------------------------------------------- holding

	/**
	 * Called by the sendMail hook for every email Dolibarr is about to send.
	 *
	 * @param CMailFile $mail The email
	 * @return int 0 to send it now, 1 when it was held, -1 when it could not be held
	 */
	public function intercept($mail)
	{
		if (self::$passing || self::approver() === '') {
			return 0;
		}
		$to = self::addresses($mail->addr_to.','.$mail->addr_cc.','.$mail->addr_bcc);
		if (!$to || !array_diff($to, self::exempt())) {
			return 0;
		}
		list($kind, $example) = $this->kindOf($mail);
		$this->noteKind($kind, $example);
		if ($this->isAllowed($kind)) {
			return 0;
		}
		$id = $this->hold($mail, $kind);
		if ($id <= 0) {
			// Never let an email through that should have been held.
			dol_syslog('Onboarding: could not hold an email to '.$mail->addr_to.': '.$this->db->lasterror(), LOG_ERR);
			return -1;
		}
		$this->requestApproval($id);
		return 1;
	}

	/**
	 * Where an email comes from: the first function on the call stack that is
	 * not a mail helper, plus the fixed start of the subject (the part before a
	 * colon, else its first four words, without numbers).
	 *
	 * @param CMailFile $mail The email
	 * @return array{0:string,1:string} Kind, and an example to show for it
	 */
	public function kindOf($mail)
	{
		$subject = trim((string) $mail->subject);
		if (preg_match('/^emailing-(\d+)/', (string) $mail->trackid, $m)) {
			$title = '';
			$res = $this->db->query("SELECT titre FROM ".$this->db->prefix()."mailing WHERE rowid = ".((int) $m[1]));
			if ($res && ($obj = $this->db->fetch_object($res))) {
				$title = (string) $obj->titre;
			}
			return array('emailing-'.$m[1], 'Mass emailing #'.$m[1].($title !== '' ? ' "'.$title.'"' : '').': '.$subject);
		}

		$where = '';
		$file = '';
		foreach (debug_backtrace(0, 40) as $f) {
			$cls = isset($f['class']) ? $f['class'] : '';
			$fn = isset($f['function']) ? $f['function'] : '';
			if ($file === '' && $cls === 'CMailFile' && $fn === 'sendfile' && isset($f['file'])) {
				$file = basename($f['file']);
			}
			if ($cls === 'OnboardingBooking' && $fn === 'mailTpl' && isset($f['args'][0])) {
				$where = 'OnboardingBooking::mailTpl('.$f['args'][0].')';
				break;
			}
			if ($cls === '' || in_array($cls.'::*', self::WRAPPERS, true) || in_array($cls.'::'.$fn, self::WRAPPERS, true)) {
				continue;
			}
			$where = $cls.'::'.$fn;
			break;
		}
		if ($where === '') {
			$where = $file !== '' ? $file : 'unknown';
		}

		$pos = strpos($subject, ':');
		$shape = ($pos !== false && $pos > 0) ? substr($subject, 0, $pos) : implode(' ', array_slice(preg_split('/\s+/', $subject), 0, 4));
		$shape = strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/[0-9#$.,]+/', '', $shape))));
		return array(dol_trunc($where.' | '.$shape, 189, 'right', 'UTF-8', 1), $subject);
	}

	/**
	 * Store an email and copies of its attachments.
	 *
	 * @param CMailFile $mail The email
	 * @param string $kind Its kind
	 * @return int Row id, <= 0 on failure
	 */
	public function hold($mail, $kind)
	{
		global $conf;
		$p = $this->db->prefix();
		$token = bin2hex(random_bytes(20));
		$sql = "INSERT INTO ".$p."onboarding_mail_hold (entity, kind, token, status, mail_from, mail_to, mail_cc, mail_bcc, reply_to, errors_to, subject, body, ishtml, css, trackid, sendcontext, deliveryreceipt, datec) VALUES (";
		$sql .= ((int) $conf->entity).", '".$this->db->escape($kind)."', '".$token."', 'pending', ";
		foreach (array($mail->addr_from, $mail->addr_to, $mail->addr_cc, $mail->addr_bcc, $mail->reply_to, $mail->errors_to) as $v) {
			$sql .= "'".$this->db->escape((string) $v)."', ";
		}
		$sql .= "'".$this->db->escape(dol_trunc((string) $mail->subject, 250, 'right', 'UTF-8', 1))."', '".$this->db->escape((string) $mail->msg)."', ".((int) $mail->msgishtml).", ";
		$sql .= "'".$this->db->escape((string) $mail->css)."', '".$this->db->escape((string) $mail->trackid)."', '".$this->db->escape((string) $mail->sendcontext)."', ".((int) $mail->deliveryreceipt).", ";
		$sql .= "'".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			return -1;
		}
		$id = (int) $this->db->last_insert_id($p.'onboarding_mail_hold');

		// The originals may be temporary files that are gone by the time it is approved.
		$files = array();
		$names = is_array($mail->filename_list) ? $mail->filename_list : array();
		if ($names) {
			$dir = $this->dir($id);
			dol_mkdir($dir);
			foreach (array_values($names) as $i => $path) {
				if (!is_file($path)) {
					continue;
				}
				$copy = $dir.'/'.$i.'-'.preg_replace('/[^A-Za-z0-9._-]/', '_', basename($path));
				if (@copy($path, $copy)) {
					$files[] = array(
						'path' => basename($copy),
						'mime' => isset($mail->mimetype_list[$i]) ? (string) $mail->mimetype_list[$i] : '',
						'name' => isset($mail->mimefilename_list[$i]) && $mail->mimefilename_list[$i] !== '' ? (string) $mail->mimefilename_list[$i] : basename($path),
					);
				}
			}
			$this->db->query("UPDATE ".$p."onboarding_mail_hold SET files = '".$this->db->escape(json_encode($files))."' WHERE rowid = ".$id);
		}
		return $id;
	}

	/**
	 * @param int $id Row id
	 * @return string Where its attachments are kept
	 */
	public function dir($id)
	{
		return DOL_DATA_ROOT.'/onboarding/mailhold/'.((int) $id);
	}

	/**
	 * @param int $id Row id
	 * @return object|null
	 */
	public function fetch($id)
	{
		global $conf;
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_mail_hold WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param object $row Held email
	 * @return array<int,array{path:string,mime:string,name:string,full:string}>
	 */
	public function files($row)
	{
		$out = array();
		foreach ((array) json_decode((string) $row->files, true) as $f) {
			if (is_array($f) && !empty($f['path'])) {
				$f['full'] = $this->dir($row->rowid).'/'.basename($f['path']);
				$out[] = $f;
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------- kinds

	/**
	 * Remember a kind the first time it is seen, with an example subject.
	 *
	 * @param string $kind Kind
	 * @param string $example Example
	 * @return void
	 */
	public function noteKind($kind, $example)
	{
		global $conf;
		$p = $this->db->prefix();
		$res = $this->db->query("SELECT rowid FROM ".$p."onboarding_mail_kind WHERE kind = '".$this->db->escape($kind)."' AND entity = ".((int) $conf->entity));
		if ($res && $this->db->num_rows($res) > 0) {
			return;
		}
		// A unique index stops a second copy when two emails race here.
		@$this->db->query("INSERT INTO ".$p."onboarding_mail_kind (entity, kind, example, allowed, datec) VALUES (".((int) $conf->entity).", '".$this->db->escape($kind)."', '".$this->db->escape(dol_trunc($example, 250, 'right', 'UTF-8', 1))."', 0, '".$this->db->idate(dol_now())."')");
	}

	/**
	 * @param string $kind Kind
	 * @return bool The approver said to always allow it
	 */
	public function isAllowed($kind)
	{
		global $conf;
		$res = $this->db->query("SELECT allowed FROM ".$this->db->prefix()."onboarding_mail_kind WHERE kind = '".$this->db->escape($kind)."' AND entity = ".((int) $conf->entity));
		$obj = $res ? $this->db->fetch_object($res) : null;
		return $obj && (int) $obj->allowed == 1;
	}

	/**
	 * @param string $kind Kind
	 * @param bool $allowed Always allow, or hold again
	 * @param string $by Who decided
	 * @return void
	 */
	public function setAllowed($kind, $allowed, $by)
	{
		global $conf;
		$this->noteKind($kind, '');
		$this->db->query("UPDATE ".$this->db->prefix()."onboarding_mail_kind SET allowed = ".($allowed ? 1 : 0).", allowed_by = '".$this->db->escape($by)."', allowed_at = '".$this->db->idate(dol_now())."' WHERE kind = '".$this->db->escape($kind)."' AND entity = ".((int) $conf->entity));
	}

	/**
	 * @return object[] Kinds, allowed first
	 */
	public function kinds()
	{
		global $conf;
		$out = array();
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_mail_kind WHERE entity = ".((int) $conf->entity)." ORDER BY allowed DESC, kind");
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$out[] = $obj;
		}
		return $out;
	}

	// ---------------------------------------------------------------- deciding

	/**
	 * @param int $id Row id
	 * @param string $token Token from the link
	 * @return object|null The email, when the link is right
	 */
	public function byLink($id, $token)
	{
		$row = $this->fetch($id);
		if (!$row || !is_string($token) || $token === '' || !hash_equals((string) $row->token, $token)) {
			return null;
		}
		return $row;
	}

	/**
	 * Act on a held email.
	 *
	 * @param int $id Row id
	 * @param string $what approve, always or reject
	 * @param string $by Who decided
	 * @return string What happened, for the person who pressed the button
	 */
	public function decide($id, $what, $by)
	{
		$row = $this->fetch($id);
		if (!$row) {
			return 'That email is no longer here.';
		}
		if ($row->status != 'pending') {
			return 'Already '.($row->status == 'sent' ? 'sent' : $row->status).($row->decided_at ? ' ('.dol_print_date($this->db->jdate($row->decided_at), 'dayhour').')' : '').'. Nothing changed.';
		}
		if ($what == 'reject') {
			$this->db->query("UPDATE ".$this->db->prefix()."onboarding_mail_hold SET status = 'rejected', decided_by = '".$this->db->escape($by)."', decided_at = '".$this->db->idate(dol_now())."' WHERE status = 'pending' AND rowid = ".((int) $id));
			return 'Rejected. It will not be sent.';
		}
		// A mass emailing is approved once, for every one of its recipients.
		$mailing = strpos((string) $row->kind, 'emailing-') === 0;
		if ($what == 'always' || $mailing) {
			$this->setAllowed($row->kind, true, $by);
			$sent = 0;
			$failed = 0;
			foreach ($this->pendingOfKind($row->kind) as $other) {
				if ($this->release($other, $by)) {
					$sent++;
				} else {
					$failed++;
				}
			}
			$msg = $sent.' email'.($sent == 1 ? '' : 's').' sent'.($failed ? ', '.$failed.' failed (see Email approval in Dolibarr)' : '').'.';
			return $mailing ? 'Mailing approved: '.$msg.' Its remaining recipients will get it without asking again.' : $msg.' This kind of email is now sent without asking. Change that under Email approval in Dolibarr.';
		}
		return $this->release($row, $by) ? 'Approved and sent.' : 'Approved, but sending failed: '.$this->fetch($id)->error.'. Try again from Email approval in Dolibarr.';
	}

	/**
	 * @param string $kind Kind
	 * @return object[] Its emails still waiting, oldest first
	 */
	public function pendingOfKind($kind)
	{
		global $conf;
		$out = array();
		$res = $this->db->query("SELECT * FROM ".$this->db->prefix()."onboarding_mail_hold WHERE status = 'pending' AND entity = ".((int) $conf->entity)." AND kind = '".$this->db->escape($kind)."' ORDER BY rowid");
		while ($res && ($obj = $this->db->fetch_object($res))) {
			$out[] = $obj;
		}
		return $out;
	}

	/**
	 * Send a held email as it was written.
	 *
	 * @param object $row Held email
	 * @param string $by Who approved it
	 * @param bool $retry Also send one that failed before
	 * @return bool Sent
	 */
	public function release($row, $by, $retry = false)
	{
		$p = $this->db->prefix();
		$from = $retry ? "status IN ('pending', 'failed')" : "status = 'pending'";
		// Claim it first, so two clicks cannot send it twice.
		$claim = $this->db->query("UPDATE ".$p."onboarding_mail_hold SET status = 'sending', decided_by = '".$this->db->escape($by)."', decided_at = '".$this->db->idate(dol_now())."' WHERE ".$from." AND rowid = ".((int) $row->rowid));
		if (!$claim || $this->db->affected_rows($claim) < 1) {
			return false;
		}
		$paths = array();
		$mimes = array();
		$names = array();
		foreach ($this->files($row) as $f) {
			$paths[] = $f['full'];
			$mimes[] = $f['mime'];
			$names[] = $f['name'];
		}
		$ok = false;
		$error = '';
		self::$passing = true;
		try {
			$mail = new CMailFile($row->subject, $row->mail_to, $row->mail_from, $row->body, $paths, $mimes, $names, $row->mail_cc, $row->mail_bcc, (int) $row->deliveryreceipt, (int) $row->ishtml, $row->errors_to, $row->css, $row->trackid, '', $row->sendcontext ?: 'standard', $row->reply_to);
			$ok = (bool) $mail->sendfile();
			$error = $ok ? '' : (string) $mail->error;
		} catch (Throwable $e) {
			$error = $e->getMessage();
		}
		self::$passing = false;
		$this->db->query("UPDATE ".$p."onboarding_mail_hold SET status = '".($ok ? 'sent' : 'failed')."', error = ".($ok ? 'NULL' : "'".$this->db->escape(dol_trunc($error, 250, 'right', 'UTF-8', 1))."'")." WHERE rowid = ".((int) $row->rowid));
		return $ok;
	}

	// ---------------------------------------------------------------- telling the approver

	/**
	 * @param int $id Row id
	 * @param string $what approve, always or reject; '' for the page with all three
	 * @return string Link to the approval page for a held email
	 */
	public function link($id, $what = '')
	{
		dol_include_once('/onboarding/class/onboarding.class.php');
		$row = $this->fetch($id);
		return OnboardingService::publicUrl('mail.php').'?h='.((int) $id).'&t='.($row ? $row->token : '').($what !== '' ? '&do='.$what : '');
	}

	/**
	 * @return string The staff page
	 */
	public static function pageUrl()
	{
		return dol_buildpath('/onboarding/mailgate.php', 2);
	}

	/**
	 * Email the approver a copy of a held email with the buttons. A mass emailing
	 * asks once, not once per recipient.
	 *
	 * @param int $id Row id
	 * @return bool Sent
	 */
	public function requestApproval($id)
	{
		$row = $this->fetch($id);
		$approver = self::approver();
		if (!$row || $approver === '') {
			return false;
		}
		if (strpos((string) $row->kind, 'emailing-') === 0) {
			$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."onboarding_mail_hold WHERE status = 'pending' AND notified = 1 AND kind = '".$this->db->escape($row->kind)."'");
			$obj = $res ? $this->db->fetch_object($res) : null;
			if ($obj && (int) $obj->n > 0) {
				return true;
			}
		}
		$mailing = strpos((string) $row->kind, 'emailing-') === 0;
		$first = self::addresses($row->mail_to);
		$who = $first ? $first[0] : (string) $row->mail_to;
		$subject = 'Approve email to '.$who.': '.$row->subject;

		$button = function ($href, $label, $bg) {
			return '<a href="'.dol_escape_htmltag($href).'" style="display:inline-block;margin:0 8px 8px 0;padding:10px 18px;border-radius:6px;background:'.$bg.';color:#fff;text-decoration:none;font-weight:600">'.dol_escape_htmltag($label).'</a>';
		};
		$html = '<div style="font-family:system-ui,sans-serif;font-size:14px;line-height:1.5;color:#222;max-width:680px">';
		$html .= '<p>Dolibarr wants to send '.($mailing ? 'a mass emailing. This is the first recipient\'s copy; approving sends it to everyone on the mailing.' : 'this email').'. It waits until you decide.</p>';
		$html .= '<p>'.($mailing ? $button($this->link($id, 'approve'), 'Approve the mailing', '#2d6a4f') : $button($this->link($id, 'approve'), 'Approve', '#2d6a4f').$button($this->link($id, 'always'), 'Approve, and always allow this kind', '#1d4e89')).$button($this->link($id, 'reject'), 'Reject', '#9b2226').'</p>';
		$html .= '<table style="border-collapse:collapse;font-size:13px">';
		foreach (array('To' => $row->mail_to, 'Cc' => $row->mail_cc, 'Bcc' => $row->mail_bcc, 'From' => $row->mail_from, 'Reply to' => $row->reply_to, 'Subject' => $row->subject) as $k => $v) {
			if ((string) $v !== '') {
				$html .= '<tr><td style="padding:2px 12px 2px 0;color:#666;vertical-align:top">'.$k.'</td><td style="padding:2px 0">'.dol_escape_htmltag((string) $v).'</td></tr>';
			}
		}
		$names = array();
		foreach ($this->files($row) as $f) {
			$names[] = $f['name'];
		}
		if ($names) {
			$html .= '<tr><td style="padding:2px 12px 2px 0;color:#666;vertical-align:top">Attached</td><td style="padding:2px 0">'.dol_escape_htmltag(implode(', ', $names)).' (copies attached here too)</td></tr>';
		}
		$html .= '<tr><td style="padding:2px 12px 2px 0;color:#666;vertical-align:top">Kind</td><td style="padding:2px 0;color:#666">'.dol_escape_htmltag($row->kind).'</td></tr>';
		$html .= '</table>';
		$html .= '<div style="margin:14px 0;padding:14px;border:1px solid #ccc;border-radius:6px;background:#fafafa">';
		$html .= (int) $row->ishtml ? (string) $row->body : '<div style="white-space:pre-wrap;font-family:inherit">'.dol_escape_htmltag((string) $row->body, 0, 1).'</div>';
		$html .= '</div>';
		$html .= '<p style="color:#666;font-size:12px">Everything waiting: <a href="'.dol_escape_htmltag(self::pageUrl()).'">Email approval in Dolibarr</a>.</p></div>';

		$paths = array();
		$mimes = array();
		$fnames = array();
		foreach ($this->files($row) as $f) {
			$paths[] = $f['full'];
			$mimes[] = $f['mime'];
			$fnames[] = $f['name'];
		}
		$ok = $this->sendOwn($approver, $subject, $html, $paths, $mimes, $fnames);
		if ($ok) {
			$this->db->query("UPDATE ".$this->db->prefix()."onboarding_mail_hold SET notified = 1 WHERE rowid = ".((int) $id));
		}
		return $ok;
	}

	/**
	 * Send one of the gate's own emails (never held).
	 *
	 * @param string $to Address
	 * @param string $subject Subject
	 * @param string $html HTML body
	 * @param string[] $paths Attachments
	 * @param string[] $mimes Their types
	 * @param string[] $names Their names
	 * @return bool
	 */
	private function sendOwn($to, $subject, $html, $paths = array(), $mimes = array(), $names = array())
	{
		$from = trim(getDolGlobalString('ONBOARDING_MAIL_FROM', getDolGlobalString('MAIN_MAIL_EMAIL_FROM', 'noreply@localhost')));
		$name = trim(str_replace(array('"', '<', '>', "\r", "\n"), '', getDolGlobalString('ONBOARDING_MAIL_FROM_NAME', getDolGlobalString('MAIN_INFO_SOCIETE_NOM'))));
		if ($name !== '' && strpos($from, '<') === false) {
			$from = '"'.$name.'" <'.$from.'>';
		}
		self::$passing = true;
		try {
			$mail = new CMailFile($subject, $to, $from, $html, $paths, $mimes, $names, '', '', 0, 1, '', '', 'onboarding-approval', '', 'standard');
			$ok = (bool) $mail->sendfile();
			if (!$ok) {
				dol_syslog('Onboarding: approval request to '.$to.' failed: '.$mail->error, LOG_WARNING);
			}
		} catch (Throwable $e) {
			dol_syslog('Onboarding: approval request to '.$to.' failed: '.$e->getMessage(), LOG_WARNING);
			$ok = false;
		}
		self::$passing = false;
		return $ok;
	}

	/**
	 * Daily: remind the approver of anything waiting more than 20 hours, ask
	 * again for emails whose request never went out, and delete decided emails
	 * after 90 days.
	 *
	 * @return string Summary
	 */
	public function daily()
	{
		global $conf;
		$p = $this->db->prefix();
		$approver = self::approver();
		$asked = 0;
		$waiting = array();
		if ($approver !== '') {
			$res = $this->db->query("SELECT rowid, notified, mail_to, subject, datec FROM ".$p."onboarding_mail_hold WHERE status IN ('pending', 'failed') AND entity = ".((int) $conf->entity)." ORDER BY rowid");
			$rows = array();
			while ($res && ($obj = $this->db->fetch_object($res))) {
				$rows[] = $obj;
			}
			foreach ($rows as $obj) {
				if (!(int) $obj->notified && $this->requestApproval((int) $obj->rowid)) {
					$asked++;
				}
				if ($this->db->jdate($obj->datec) < dol_now() - 20 * 3600) {
					$waiting[] = $obj;
				}
			}
			if ($waiting) {
				$html = '<div style="font-family:system-ui,sans-serif;font-size:14px;line-height:1.5"><p>'.count($waiting).' email'.(count($waiting) == 1 ? ' has' : 's have').' been waiting for your approval for more than a day:</p><ul>';
				foreach (array_slice($waiting, 0, 50) as $w) {
					$html .= '<li>'.dol_escape_htmltag(dol_print_date($this->db->jdate($w->datec), 'dayhour').': '.$w->subject.' (to '.$w->mail_to.')').'</li>';
				}
				$html .= '</ul><p><a href="'.dol_escape_htmltag(self::pageUrl()).'">Decide them in Dolibarr</a>, or use the buttons in the earlier emails.</p></div>';
				$this->sendOwn($approver, count($waiting).' email'.(count($waiting) == 1 ? '' : 's').' still waiting for approval', $html);
			}
		}
		$old = $this->db->idate(dol_now() - 90 * 86400);
		$res = $this->db->query("SELECT rowid FROM ".$p."onboarding_mail_hold WHERE status IN ('sent', 'rejected') AND decided_at < '".$old."' AND entity = ".((int) $conf->entity));
		$purged = 0;
		while ($res && ($obj = $this->db->fetch_object($res))) {
			dol_delete_dir_recursive($this->dir($obj->rowid));
			$purged++;
		}
		$this->db->query("DELETE FROM ".$p."onboarding_mail_hold WHERE status IN ('sent', 'rejected') AND decided_at < '".$old."' AND entity = ".((int) $conf->entity));
		if ($approver === '') {
			return 'email approval off';
		}
		return 'email approval: '.count($waiting).' waiting over a day, '.$asked.' asked again, '.$purged.' old ones deleted';
	}
}
