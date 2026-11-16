<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

dol_include_once('/onboarding/class/onboarding.class.php');

/**
 * The email-updates list: the contact tag mass emailings select by, signups
 * from the website and imports of old lists.
 * Reached through OnboardingService::updates().
 */
class OnboardingUpdates
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
	 * The contact tag that marks "send me email updates". Mass emailings pick
	 * their recipients by it (Emailing, Contacts, filter by tag).
	 *
	 * @param bool $create Create the tag if it does not exist yet
	 * @return Categorie|null
	 */
	public function updatesTag($create = true)
	{
		static $tag = null;
		if ($tag !== null) {
			return $tag;
		}
		if (!isModEnabled('categorie')) {
			dol_syslog('Onboarding: the Tags/Categories module is off, so email signups are not tagged', LOG_WARNING);
			return null;
		}
		$label = getDolGlobalString('ONBOARDING_UPDATES_TAG', 'Email updates');
		$cat = new Categorie($this->db);
		if ($cat->fetch(0, $label, Categorie::TYPE_CONTACT) > 0) {
			return $tag = $cat;
		}
		if (!$create) {
			return null;
		}
		$cat = new Categorie($this->db);
		$cat->label = $label;
		$cat->type = Categorie::TYPE_CONTACT;
		$cat->description = 'People who asked for email updates on the website. Use this tag to pick recipients for a mass emailing.';
		$cat->visible = 0;
		if ($cat->create($this->svc->actor()) <= 0) {
			dol_syslog('Onboarding: could not create the email updates tag: '.$cat->error, LOG_ERR);
			return null;
		}
		return $tag = $cat;
	}

	/**
	 * @param int $contactId Contact id
	 * @return bool Contact carries the email updates tag
	 */
	public function isTaggedForUpdates($contactId)
	{
		$tag = $this->updatesTag(false);
		return $tag ? (bool) $tag->containsObject(Categorie::TYPE_CONTACT, (int) $contactId) : false;
	}

	/**
	 * Put a contact on the email updates list: tag it and tick both notify fields.
	 *
	 * @param Contact $contact Fetched contact (its extra fields are rewritten)
	 * @param bool $fields Also tick both notify fields
	 * @return void
	 */
	public function tagForUpdates($contact, $fields = true)
	{
		$tag = $this->updatesTag();
		if ($tag && !$tag->containsObject(Categorie::TYPE_CONTACT, $contact->id)) {
			$tag->add_type($contact, Categorie::TYPE_CONTACT);
		}
		if ($fields && (empty($contact->array_options['options_notify_events']) || empty($contact->array_options['options_notify_news']))) {
			$contact->array_options['options_notify_events'] = 1;
			$contact->array_options['options_notify_news'] = 1;
			$contact->insertExtraFields();
		}
	}

	/**
	 * @param string $email Email
	 * @return bool The address is on Dolibarr's unsubscribe list
	 */
	public function isUnsubscribed($email)
	{
		$res = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."mailing_unsubscribe WHERE LOWER(email) = '".$this->db->escape(OnboardingService::cleanEmail($email))."' AND entity IN (".getEntity('mailing', 0).") LIMIT 1");
		return $res && $this->db->fetch_object($res);
	}

	/**
	 * Someone asked for email updates. Finds or creates their contact and puts
	 * it on the list.
	 *
	 * A signup on the website is a fresh request, so it also takes the address
	 * off Dolibarr's unsubscribe list. An import ($optin false) never does: an
	 * old list must not undo an unsubscribe.
	 *
	 * @param array<string,mixed> $in email, and optionally name, page, date (Y-m-d), source
	 * @param bool $optin The person is asking right now
	 * @param bool $dry Only report what would happen
	 * @return array<string,mixed> ok, result (added, tagged, already, unsubscribed) or error
	 */
	public function subscribe($in, $optin = true, $dry = false)
	{
		$email = OnboardingService::cleanEmail(isset($in['email']) ? $in['email'] : '');
		if (!isValidEmail($email)) {
			return array('ok' => false, 'error' => 'invalid', 'http' => 400);
		}
		if (!$optin && $this->isUnsubscribed($email)) {
			return array('ok' => true, 'result' => 'unsubscribed');
		}
		$name = trim(dol_string_nohtmltag((string) (isset($in['name']) ? $in['name'] : '')));
		$source = trim(dol_string_nohtmltag((string) (isset($in['source']) ? $in['source'] : 'the website')));
		$page = trim(dol_string_nohtmltag((string) (isset($in['page']) ? $in['page'] : '')));
		$date = isset($in['date']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $in['date']) ? substr((string) $in['date'], 0, 10) : dol_print_date(OnboardingService::now(), '%Y-%m-%d');
		$line = 'Asked for email updates on '.$date.' ('.$source.($page !== '' ? ', '.$page : '').').';

		$contact = new Contact($this->db);
		$id = $this->svc->contactByEmail($email);
		if ($id > 0) {
			if ($contact->fetch($id) <= 0) {
				return array('ok' => false, 'error' => 'server', 'http' => 500);
			}
			if ($this->isTaggedForUpdates($id) && !($optin && $this->isUnsubscribed($email))) {
				return array('ok' => true, 'result' => 'already');
			}
			if ($dry) {
				return array('ok' => true, 'result' => 'tagged');
			}
			$contact->update_note(trim($contact->note_private."\n".$line), '_private');
			$result = 'tagged';
		} else {
			if ($dry) {
				return array('ok' => true, 'result' => 'added');
			}
			$pos = strrpos($name, ' ');
			// Both name columns hold 50 characters; a longer value makes the insert fail.
			$contact->firstname = dol_substr($pos === false ? '' : substr($name, 0, $pos), 0, 50);
			// A contact needs a last name. With no name given, the address stands in.
			$contact->lastname = dol_substr($name === '' ? $email : ($pos === false ? $name : substr($name, $pos + 1)), 0, 50);
			$contact->email = $email;
			$contact->statut = 1;
			$contact->status = 1;
			$contact->note_private = $line;
			if ($contact->create($this->svc->actor()) <= 0) {
				$why = trim($contact->error.' '.implode(' ', (array) $contact->errors));
				dol_syslog('Onboarding: contact create failed: '.$why, LOG_ERR);
				return array('ok' => false, 'error' => 'server', 'detail' => $why, 'http' => 500);
			}
			$contact->fetch($contact->id);
			$result = 'added';
		}
		$this->tagForUpdates($contact);
		if ($optin) {
			$contact->setNoEmail(0);
		}
		return array('ok' => true, 'result' => $result);
	}

	/**
	 * Import an old email list. Paste, check, import. Columns are matched by
	 * name: email is required; name (or first and last name) and a signup date
	 * are used when present. Addresses on the unsubscribe list are skipped.
	 *
	 * @param string $text Pasted spreadsheet or CSV
	 * @param string $source Where the list came from, written on each contact
	 * @param bool $dry Only report what would happen
	 * @return string[] Summary first, then one line per row that needs a look
	 */
	public function importEmails($text, $source, $dry = true)
	{
		$lines = preg_split('/\r\n|\r|\n/', trim((string) $text));
		if (count($lines) < 2) {
			return array('Nothing to import: paste the header line and at least one row.');
		}
		$sep = strpos($lines[0], "\t") !== false ? "\t" : (substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',');
		$head = array_map(function ($h) {
			return preg_replace('/[^a-z]/', '', strtolower($h));
		}, str_getcsv(array_shift($lines), $sep, '"', '\\'));
		$find = function ($names) use ($head) {
			foreach ($names as $n) {
				$i = array_search($n, $head, true);
				if ($i !== false) {
					return $i;
				}
			}
			return -1;
		};
		$ce = $find(array('email', 'emailaddress', 'mail', 'subscriber', 'subscriberemail'));
		if ($ce < 0) {
			return array('The header line needs an "email" column.');
		}
		$cn = $find(array('name', 'fullname', 'displayname'));
		$cf = $find(array('firstname', 'first', 'givenname'));
		$cl = $find(array('lastname', 'last', 'surname', 'familyname'));
		$cd = $find(array('subscribed', 'date', 'datesubscribed', 'subscriptiondate', 'created', 'createdat', 'signupdate', 'optintime', 'confirmtime'));
		$source = trim((string) $source) === '' ? 'imported list' : trim((string) $source);

		$count = array('added' => 0, 'tagged' => 0, 'already' => 0, 'unsubscribed' => 0, 'invalid' => 0, 'duplicate' => 0, 'failed' => 0);
		$notes = array();
		$seen = array();
		foreach ($lines as $n => $line) {
			if (trim($line) === '') {
				continue;
			}
			$cells = str_getcsv($line, $sep, '"', '\\');
			$get = function ($i) use ($cells) {
				return $i >= 0 && isset($cells[$i]) ? trim($cells[$i]) : '';
			};
			$email = OnboardingService::cleanEmail($get($ce));
			if (isset($seen[$email])) {
				$count['duplicate']++;
				continue;
			}
			$seen[$email] = 1;
			$name = $cn >= 0 ? $get($cn) : trim($get($cf).' '.$get($cl));
			$date = '';
			$raw = $get($cd);
			if ($raw !== '' && ($t = strtotime($raw)) !== false) {
				$date = gmdate('Y-m-d', $t);
			}
			$r = $this->subscribe(array('email' => $email, 'name' => $name, 'date' => $date, 'source' => $source), false, $dry);
			if (!$r['ok'] && $r['error'] == 'invalid') {
				$count['invalid']++;
				$notes[] = 'Row '.($n + 2).': "'.$get($ce).'" is not a valid email address, skipped.';
				continue;
			}
			if (!$r['ok']) {
				$count['failed']++;
				$notes[] = 'Row '.($n + 2).' ('.$email.'): Dolibarr could not save the contact'.(empty($r['detail']) ? '' : ': '.$r['detail']).'.';
				continue;
			}
			$count[$r['result']]++;
		}
		$verb = $dry ? 'would be' : 'were';
		$summary = $count['added'].' new contacts '.$verb.' added, '.$count['tagged'].' existing contacts '.$verb.' added to the list, '
			.$count['already'].' were already on it, '.$count['unsubscribed'].' skipped because they unsubscribed, '
			.$count['invalid'].' invalid'.($count['duplicate'] ? ', '.$count['duplicate'].' repeated in the paste' : '')
			.($count['failed'] ? ', '.$count['failed'].' COULD NOT BE SAVED (see below)' : '').'.';
		return array_merge(array($summary), $notes);
	}
}
