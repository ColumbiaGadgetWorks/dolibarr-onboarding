<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');

/**
 * The signup steps a new member goes through: details, the signed waiver and
 * agreement, the ID photo; and what happens to signups that are dropped.
 * Reached through OnboardingService::signup().
 */
class OnboardingSignup
{
	/** @var DoliDB */
	public $db;
	/** @var OnboardingService */
	public $svc;

	/**
	 * @param OnboardingService $svc The shared service
	 */
	public function __construct($svc)
	{
		$this->svc = $svc;
		$this->db = $svc->db;
	}
	/**
	 * The applicant's link token, and email them a link back into the signup.
	 *
	 * Each signup has one token for good, worked out from its own random salt and a
	 * secret, so every "pick up where you left off" email carries the same link and
	 * an older email still works. (Until 0.22.3 every email made a new token and
	 * killed the links sent before, so someone who asked twice, or whose first email
	 * waited for approval, was sent back to the start.) Only its hash is stored.
	 *
	 * @param object $app Applicant row
	 * @param bool $send Send the email
	 * @return string The token
	 */
	public function issueToken($app, $send = true)
	{
		global $conf;
		$salt = isset($app->token_salt) ? (string) $app->token_salt : '';
		if ($salt === '') {
			$salt = bin2hex(random_bytes(16));
			$this->svc->save($app->rowid, array('token_salt' => $salt));
		}
		$secret = getDolGlobalString('ONBOARDING_SIGNUP_SECRET');
		if ($secret === '') {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			$secret = bin2hex(random_bytes(24));
			dolibarr_set_const($this->db, 'ONBOARDING_SIGNUP_SECRET', $secret, 'chaine', 0, 'Makes the links in membership signup emails', $conf->entity);
		}
		$token = substr(hash_hmac('sha256', 'signup:'.((int) $app->rowid).':'.$salt, $secret), 0, 48);
		if (!isset($app->token_hash) || $app->token_hash !== hash('sha256', $token)) {
			$this->svc->save($app->rowid, array('token_hash' => hash('sha256', $token)));
		}
		$base = getDolGlobalString('ONBOARDING_JOIN_URL');
		if ($send && $base) {
			// The token rides in the fragment so it never reaches a server log.
			$link = $base.'#t='.$token;
			$body = $this->svc->template('ONBOARDING_MAIL_RESUME_BODY', $app, array('link' => $link));
			$this->svc->mail($app->email, $this->svc->template('ONBOARDING_MAIL_RESUME_SUBJECT', $app), $body);
		}
		return $token;
	}

	/**
	 * Step 1. Create the applicant and their contact card.
	 *
	 * @param array<string,mixed> $in Form fields
	 * @return array<string,mixed> Response for the website
	 */
	public function start($in)
	{
		global $conf;

		$email = OnboardingService::cleanEmail(isset($in['email']) ? $in['email'] : '');
		$first = trim(dol_string_nohtmltag((string) (isset($in['firstname']) ? $in['firstname'] : '')));
		$last = trim(dol_string_nohtmltag((string) (isset($in['lastname']) ? $in['lastname'] : '')));
		$discord = trim(dol_string_nohtmltag((string) (isset($in['discord']) ? $in['discord'] : '')));
		if (!isValidEmail($email) || $first === '' || $last === '') {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}

		// A known address never gets a token back in the browser: whoever typed it
		// may not own it. The link goes to the inbox instead.
		$existing = $this->svc->findByEmail($email);
		if ($existing && !empty($existing->training_only) && $existing->stage != 'complete') {
			// Came for a training, now joining: the signup carries on from what they signed.
			$this->svc->save($existing->rowid, array('training_only' => 0));
			$existing = $this->svc->refreshStage($existing->rowid);
		}
		if ($existing) {
			if ($existing->stage == 'complete') {
				$this->svc->mail($existing->email, $this->svc->template('ONBOARDING_MAIL_ALREADY_SUBJECT', $existing), $this->svc->template('ONBOARDING_MAIL_ALREADY_BODY', $existing));
			} else {
				$this->issueToken($existing, true);
			}
			return array('ok' => true, 'check_email' => true);
		}
		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."adherent WHERE email = '".$this->db->escape($email)."' AND entity IN (".getEntity('adherent').") LIMIT 1");
		if ($res && $this->db->fetch_object($res)) {
			return array('ok' => true, 'check_email' => true);
		}

		$actor = $this->svc->actor();
		$contact = new Contact($this->db);
		$contact->firstname = $first;
		$contact->lastname = $last;
		$contact->email = $email;
		$contact->statut = 1;
		$contact->status = 1;
		$contact->note_private = 'Created by the website membership signup.';
		$cid = $contact->create($actor);
		if ($cid <= 0) {
			dol_syslog('Onboarding: contact create failed: '.$contact->error, LOG_ERR);
			return array('ok' => false, 'error' => 'server', 'http' => 500);
		}

		$newId = OnboardingSql::insert($this->db, 'onboarding_applicant', array(
			'entity' => (int) $conf->entity,
			'email' => $email,
			'firstname' => $first,
			'lastname' => $last,
			'discord' => substr($discord, 0, 100),
			'notify_events' => !empty($in['notify_events']),
			'notify_news' => !empty($in['notify_news']),
			'fk_socpeople' => (int) $cid,
			'ip' => substr((string) (isset($in['ip']) ? $in['ip'] : ''), 0, 64),
			'datec' => $this->db->idate(OnboardingService::now()),
		));
		if (!$newId) {
			dol_syslog('Onboarding: applicant insert failed: '.$this->db->lasterror(), LOG_ERR);
			return array('ok' => false, 'error' => 'server', 'http' => 500);
		}
		$app = $this->svc->fetch($newId);
		$token = $this->issueToken($app, true);
		$this->svc->syncExtraFields($app);
		// Either box ticked puts them on the email updates list too. The two
		// notify fields keep exactly what was ticked.
		if (!empty($in['notify_events']) || !empty($in['notify_news'])) {
			$tagged = new Contact($this->db);
			if ($tagged->fetch((int) $cid) > 0) {
				$this->svc->updates()->tagForUpdates($tagged, false);
			}
		}

		return array('ok' => true, 'token' => $token) + $this->status($this->svc->fetch($app->rowid));
	}

	/**
	 * What the website needs to draw the right step.
	 *
	 * @param object $app Applicant row
	 * @return array<string,mixed>
	 */
	public function status($app)
	{
		$docs = $this->svc->docs();
		return array(
			'stage' => $app->stage,
			'firstname' => $app->firstname,
			'lastname' => $app->lastname,
			'email' => $app->email,
			'waiver' => !empty($app->waiver_signed_at) && $app->waiver_version == $docs['waiver']['version'],
			'agreement' => !empty($app->agreement_signed_at) && $app->agreement_version == $docs['agreement']['version'],
			'id' => !empty($app->id_uploaded_at),
			'paid' => $app->payment_state == 'active',
			'training_only' => !empty($app->training_only),
			'birthdate' => !empty($app->birthdate),
			'needs' => $this->trainingNeeds($app),
			'checkout' => array(
				'url' => getDolGlobalString('ONBOARDING_CHECKOUT_URL'),
				'standard' => (float) getDolGlobalString('ONBOARDING_DUES_STANDARD', '50'),
				'supporter' => (float) getDolGlobalString('ONBOARDING_DUES_SUPPORTER', '100'),
			),
		);
	}

	/**
	 * @param object $app Applicant row
	 * @return string[] What they still have to sign before booking a training
	 */
	public function trainingNeeds($app)
	{
		dol_include_once('/onboarding/class/booking.class.php');
		$b = new OnboardingBooking($this->db, $this->svc);
		$p = $b->person($app->email);
		return $p['needs'];
	}

	/**
	 * Step 2a/2b. Record a signature on the waiver or the agreement.
	 *
	 * Someone signing to book a training also gives their date of birth. Under
	 * 18, a parent or guardian signs the waiver for them: the typed name is the
	 * guardian's, and the record says whose waiver it is. Under the minimum age
	 * (setting) nobody can sign online.
	 *
	 * @param object $app Applicant row
	 * @param string $doc 'waiver' or 'agreement'
	 * @param string $name Typed full name (the guardian's, for a minor)
	 * @param string $version Version the browser showed
	 * @param string $ip Signer's address
	 * @param string $signature The drawn signature, PNG bytes
	 * @param array<string,string> $extra birthdate (Y-m-d), guardian_name, guardian_relation, guardian_email
	 * @return array<string,mixed>
	 */
	public function sign($app, $doc, $name, $version, $ip, $signature = '', $extra = array())
	{
		$docs = $this->svc->docs();
		$name = trim(dol_string_nohtmltag((string) $name));
		if (!isset($docs[$doc]) || strlen($name) < 3) {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		if ($version !== $docs[$doc]['version']) {
			// The text changed while the form was open. They must read the new one.
			return array('ok' => false, 'error' => 'stale', 'http' => 409);
		}
		$info = strlen($signature) > 200 && strlen($signature) < 1024 * 1024 ? @getimagesizefromstring($signature) : false;
		if (!$info || $info[2] != IMAGETYPE_PNG || $info[0] > 2400 || $info[1] > 1200) {
			return array('ok' => false, 'error' => 'signature', 'http' => 400);
		}
		$now = OnboardingService::now();
		$fields = array();
		$guardian = '';
		if ($doc == 'waiver') {
			$birth = isset($extra['birthdate']) ? trim((string) $extra['birthdate']) : '';
			if ($birth === '' && !empty($app->birthdate)) {
				$birth = substr((string) $app->birthdate, 0, 10);
			}
			if ($birth === '' && !empty($app->training_only)) {
				return array('ok' => false, 'error' => 'birthdate', 'http' => 400);
			}
			if ($birth !== '') {
				$bd = DateTime::createFromFormat('!Y-m-d', $birth);
				if (!$bd || $bd->format('Y-m-d') !== $birth || $bd->getTimestamp() > $now) {
					return array('ok' => false, 'error' => 'birthdate', 'http' => 400);
				}
				$age = (int) $bd->diff(new DateTime('@'.$now))->y;
				if ($age < (int) getDolGlobalString('ONBOARDING_TRAINING_MIN_AGE', '12')) {
					return array('ok' => false, 'error' => 'too_young', 'http' => 400);
				}
				$fields['birthdate'] = $birth;
				if ($age < 18) {
					$gname = trim(dol_string_nohtmltag(isset($extra['guardian_name']) ? (string) $extra['guardian_name'] : ''));
					$grel = trim(dol_string_nohtmltag(isset($extra['guardian_relation']) ? (string) $extra['guardian_relation'] : ''));
					$gmail = OnboardingService::cleanEmail(isset($extra['guardian_email']) ? $extra['guardian_email'] : '');
					if (strlen($gname) < 3 || $grel === '' || ($gmail !== '' && !isValidEmail($gmail))) {
						return array('ok' => false, 'error' => 'guardian', 'http' => 400);
					}
					$name = $gname;
					$fields['guardian_name'] = dol_trunc($gname, 200, 'right', 'UTF-8', 1);
					$fields['guardian_relation'] = dol_trunc($grel, 50, 'right', 'UTF-8', 1);
					$fields['guardian_email'] = $gmail;
					$guardian = "Signed by: ".$gname." (".$grel.") as parent or guardian of ".$app->firstname.' '.$app->lastname.", born ".$birth.($gmail !== '' ? ", guardian email ".$gmail : '')."\n";
				}
			}
		}
		// The words signed are kept in Dolibarr whatever happens to the source later.
		if (!$this->svc->docAt($doc, $version)) {
			$this->svc->addDocVersion($doc, $docs[$doc]['title'], $docs[$doc]['text'], $docs[$doc]['source']);
		}
		$this->svc->save($app->rowid, $fields + array(
			$doc.'_signed_at' => $this->db->idate($now),
			$doc.'_name' => substr($name, 0, 200),
			$doc.'_version' => $version,
			$doc.'_ip' => substr((string) $ip, 0, 64),
		));

		$record = $docs[$doc]['title']."\n".str_repeat('=', 60)."\n\n".$docs[$doc]['text']."\n\n".str_repeat('-', 60)."\n";
		$record .= "Signed electronically by: ".$name."\n";
		$record .= $guardian;
		$record .= "Account: ".$app->firstname.' '.$app->lastname.' <'.$app->email.">\n";
		if (!empty($fields['birthdate']) && $guardian === '') {
			$record .= "Date of birth: ".$fields['birthdate']."\n";
		}
		$record .= "Date: ".dol_print_date($now, '%Y-%m-%d %H:%M:%S', 'gmt')." UTC\n";
		$record .= "IP address: ".$ip."\n";
		$record .= "Document version: ".$version.($docs[$doc]['source'] !== '' ? ' ('.$docs[$doc]['source'].')' : '')."\n";
		$dir = $this->svc->dir($app);
		// The version is in the names, so signing a newer version keeps the old record.
		$sigFile = $dir.'/'.OnboardingService::signedName($doc, $version, '-signature.png');
		file_put_contents($dir.'/'.OnboardingService::signedName($doc, $version, '.txt'), $record);
		file_put_contents($sigFile, $signature);
		$this->writePdf($dir.'/'.OnboardingService::signedName($doc, $version, '.pdf'), $docs[$doc]['title'], $record, $sigFile);

		$app = $this->svc->refreshStage($app->rowid);
		return array('ok' => true) + $this->status($app);
	}

	/**
	 * Best effort PDF of a signed document. The .txt beside it is the fallback.
	 *
	 * @param string $path Target file
	 * @param string $title Title
	 * @param string $text Text
	 * @param string $image Drawn signature to place under the text
	 * @return void
	 */
	private function writePdf($path, $title, $text, $image = '')
	{
		global $langs;
		try {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
			$pdf = pdf_getInstance();
			if (method_exists($pdf, 'setPrintHeader')) {
				$pdf->setPrintHeader(false);
				$pdf->setPrintFooter(false);
			}
			$pdf->SetTitle($title);
			$pdf->SetMargins(15, 15, 15);
			$pdf->SetAutoPageBreak(true, 15);
			$pdf->AddPage();
			$pdf->SetFont(pdf_getPDFFont($langs), '', 10);
			$pdf->MultiCell(0, 5, $text, 0, 'L');
			if ($image && is_readable($image)) {
				if ($pdf->GetY() > 240) {
					$pdf->AddPage();
				}
				$pdf->Image($image, 15, $pdf->GetY() + 3, 80);
			}
			$pdf->Output($path, 'F');
		} catch (Throwable $e) {
			dol_syslog('Onboarding: PDF failed: '.$e->getMessage(), LOG_WARNING);
		}
	}

	/**
	 * Step 2c. Store the ID photo.
	 *
	 * @param object $app Applicant row
	 * @param string $bytes Raw image bytes
	 * @return array<string,mixed>
	 */
	public function uploadId($app, $bytes)
	{
		if (strlen($bytes) < 100 || strlen($bytes) > 8 * 1024 * 1024) {
			return array('ok' => false, 'error' => 'size', 'http' => 400);
		}
		$info = @getimagesizefromstring($bytes);
		$ext = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp');
		if (!$info || !isset($ext[$info[2]])) {
			return array('ok' => false, 'error' => 'type', 'http' => 400);
		}
		$dir = $this->svc->dir($app);
		foreach (glob($dir.'/id.*') as $old) {
			@unlink($old);
		}
		$file = 'id.'.$ext[$info[2]];
		if (file_put_contents($dir.'/'.$file, $bytes) === false) {
			return array('ok' => false, 'error' => 'server', 'http' => 500);
		}
		$this->svc->save($app->rowid, array('id_uploaded_at' => $this->db->idate(OnboardingService::now()), 'id_file' => $file));
		$app = $this->svc->refreshStage($app->rowid);
		return array('ok' => true) + $this->status($app);
	}

	/**
	 * Can this signup still be thrown away? Not once it is complete or any
	 * dues have been paid: from then on it is a member's record.
	 *
	 * @param object $app Applicant row
	 * @return bool
	 */
	public function canDiscard($app)
	{
		return $app && $app->stage != 'complete' && $app->payment_state == 'none';
	}

	/**
	 * Delete a signup that never became a membership: the applicant row, its
	 * signed documents and ID photo, its contact, and the draft member made when
	 * the paperwork was done. A contact on the email updates list is kept, and so
	 * is any member record that is more than an unpaid draft.
	 *
	 * @param object $app Applicant row
	 * @return void
	 */
	public function discard($app)
	{
		$actor = $this->svc->actor();
		if ($app->fk_adherent > 0) {
			$adh = new Adherent($this->db);
			if ($adh->fetch((int) $app->fk_adherent) > 0 && (int) $adh->statut == Adherent::STATUS_DRAFT) {
				$res = $this->db->query("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."subscription WHERE fk_adherent = ".((int) $adh->id));
				$obj = $res ? $this->db->fetch_object($res) : null;
				if ($obj && (int) $obj->n == 0) {
					// Dolibarr 20 dropped the leading $rowid argument.
					$params = (new ReflectionMethod($adh, 'delete'))->getParameters();
					if ($params && $params[0]->getName() == 'rowid') {
						$adh->delete($adh->id, $actor);
					} else {
						$adh->delete($actor);
					}
				}
			}
		}
		if ($app->fk_socpeople > 0) {
			$contact = new Contact($this->db);
			// Someone who asked for email updates stays on the list even though
			// they never finished joining.
			if ($contact->fetch((int) $app->fk_socpeople) > 0 && !$this->svc->updates()->isTaggedForUpdates($contact->id)) {
				$contact->delete($actor);
			}
		}
		dol_delete_dir_recursive(DOL_DATA_ROOT.'/onboarding/applicant/'.((int) $app->rowid));
		$this->db->query("DELETE FROM ".$this->db->prefix()."onboarding_applicant WHERE rowid = ".((int) $app->rowid));
	}

	/**
	 * The applicant pressed "Start over" on the join page.
	 *
	 * @param object $app Applicant row
	 * @return array<string,mixed> Response for the website
	 */
	public function cancel($app)
	{
		if (!$this->canDiscard($app)) {
			return array('ok' => false, 'error' => 'paid', 'http' => 409);
		}
		$this->discard($app);
		return array('ok' => true);
	}

	/**
	 * Delete abandoned signups and expired ID photos.
	 *
	 * @return string Summary
	 */
	public function cleanup()
	{
		global $conf;

		$now = OnboardingService::now();
		$deleted = 0;
		$photos = 0;
		$actor = $this->svc->actor();

		$days = (int) getDolGlobalString('ONBOARDING_DRAFT_DAYS', '30');
		if ($days > 0) {
			$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."onboarding_applicant WHERE entity = ".((int) $conf->entity)." AND stage <> 'complete' AND payment_state = 'none' AND (fk_adherent IS NULL OR fk_adherent = 0) AND datec < '".$this->db->idate($now - $days * 86400)."'");
			$ids = array();
			while ($res && ($obj = $this->db->fetch_object($res))) {
				$ids[] = (int) $obj->rowid;
			}
			foreach ($ids as $id) {
				$this->discard($this->svc->fetch($id));
				$deleted++;
			}
		}

		$keep = (int) getDolGlobalString('ONBOARDING_ID_RETENTION_DAYS', '0');
		if ($keep > 0) {
			$res = $this->db->query("SELECT rowid, id_file FROM ".$this->db->prefix()."onboarding_applicant WHERE entity = ".((int) $conf->entity)." AND id_file IS NOT NULL AND id_file <> '' AND completed_at IS NOT NULL AND completed_at < '".$this->db->idate($now - $keep * 86400)."'");
			$rows = array();
			while ($res && ($obj = $this->db->fetch_object($res))) {
				$rows[] = $obj;
			}
			foreach ($rows as $obj) {
				$src = DOL_DATA_ROOT.'/onboarding/applicant/'.((int) $obj->rowid);
				@unlink($src.'/'.basename($obj->id_file));
				// And its copy on the member card.
				$done = is_readable($src.'/member-copies.json') ? json_decode((string) file_get_contents($src.'/member-copies.json'), true) : null;
				if (!empty($done['id']['name']) && ($dest = $this->svc->memberDir((int) $done['id']['member'])) !== '') {
					@unlink($dest.'/'.basename($done['id']['name']));
				}
				// The "ID photo uploaded" tick stays: it records that the check was done.
				$this->svc->save($obj->rowid, array('id_file' => null));
				$photos++;
			}
		}
		return $deleted.' abandoned signups deleted, '.$photos.' ID photos removed';
	}
}
