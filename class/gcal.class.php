<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

/**
 * Google Calendar, written to by a service account: a Google Cloud robot account
 * that the calendar is shared with ("Make changes to events"). Its key file (JSON)
 * and the calendar's ID are on the setup page, Free classes.
 *
 * Only what free classes need: read the dates of the monthly series, change one of
 * those dates, add or remove a single event. An edit to one date of a repeating
 * event is made on that date's own instance id, so the rest of the series is left
 * alone. Dolibarr 19 cannot send PATCH, so it goes as a POST with Google's
 * X-HTTP-Method-Override header.
 */
class OnboardingGcal
{
	const SCOPE = 'https://www.googleapis.com/auth/calendar.events';
	const TZ = 'America/Chicago';

	/** @var string Last error, for the screen and the log */
	public $error = '';

	/** @var array{token:string,until:int}|null */
	private static $token = null;

	/**
	 * @return bool A key and a calendar are set
	 */
	public static function configured()
	{
		$key = self::key();
		return $key !== null && getDolGlobalString('ONBOARDING_GCAL_CALENDAR') !== '';
	}

	/**
	 * @return array<string,string>|null The service account key file
	 */
	public static function key()
	{
		$k = json_decode(getDolGlobalString('ONBOARDING_GCAL_KEY'), true);
		return is_array($k) && !empty($k['client_email']) && !empty($k['private_key']) ? $k : null;
	}

	/**
	 * @return string Who to share the calendar with
	 */
	public static function robotEmail()
	{
		$k = self::key();
		return $k ? (string) $k['client_email'] : '';
	}

	/**
	 * @return string The Calendar API, or the sandbox's fake one
	 */
	private static function base()
	{
		$b = rtrim(getDolGlobalString('ONBOARDING_GCAL_API_BASE'), '/');
		return $b !== '' ? $b : 'https://www.googleapis.com/calendar/v3';
	}

	/**
	 * @param string $s Bytes
	 * @return string base64url
	 */
	private static function b64($s)
	{
		return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
	}

	/**
	 * An access token, from a JWT signed with the service account's key.
	 *
	 * @return string Empty on failure, with error set
	 */
	private function token()
	{
		if (self::$token && self::$token['until'] > time() + 60) {
			return self::$token['token'];
		}
		$k = self::key();
		if (!$k) {
			$this->error = 'No Google service account key on the setup page (Free classes).';
			return '';
		}
		$aud = !empty($k['token_uri']) ? (string) $k['token_uri'] : 'https://oauth2.googleapis.com/token';
		$now = time();
		$head = self::b64(json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
		$claims = self::b64(json_encode(array('iss' => $k['client_email'], 'scope' => self::SCOPE, 'aud' => $aud, 'iat' => $now, 'exp' => $now + 3600)));
		$sig = '';
		if (!function_exists('openssl_sign') || !@openssl_sign($head.'.'.$claims, $sig, (string) $k['private_key'], OPENSSL_ALGO_SHA256)) {
			$this->error = 'The Google key could not sign (is it the whole JSON key file?).';
			return '';
		}
		$body = 'grant_type='.rawurlencode('urn:ietf:params:oauth:grant-type:jwt-bearer').'&assertion='.$head.'.'.$claims.'.'.self::b64($sig);
		$r = getURLContent($aud, 'POSTALREADYFORMATED', $body, 1, array('Content-Type: application/x-www-form-urlencoded'), array('http', 'https'), 2);
		$json = json_decode((string) (isset($r['content']) ? $r['content'] : ''), true);
		if (empty($json['access_token'])) {
			$this->error = 'Google sign-in failed (HTTP '.(isset($r['http_code']) ? (int) $r['http_code'] : '?').(is_array($json) && !empty($json['error_description']) ? ': '.$json['error_description'] : '').').';
			dol_syslog('Onboarding: '.$this->error, LOG_WARNING);
			return '';
		}
		self::$token = array('token' => (string) $json['access_token'], 'until' => $now + (int) (isset($json['expires_in']) ? $json['expires_in'] : 3600));
		return self::$token['token'];
	}

	/**
	 * @param string $method GET, POST, PATCH or DELETE
	 * @param string $path Under the calendar, e.g. /events/ID
	 * @param array<string,mixed>|null $body JSON body
	 * @param array<string,string> $query Query string
	 * @return array|null The answer (empty for a DELETE), null with error set
	 */
	public function call($method, $path, $body = null, $query = array())
	{
		$this->error = '';
		$cal = getDolGlobalString('ONBOARDING_GCAL_CALENDAR');
		if ($cal === '') {
			$this->error = 'No calendar ID on the setup page (Free classes).';
			return null;
		}
		$token = $this->token();
		if ($token === '') {
			return null;
		}
		$url = self::base().'/calendars/'.rawurlencode($cal).$path.($query ? '?'.http_build_query($query) : '');
		$headers = array('Authorization: Bearer '.$token, 'Accept: application/json');
		if ($method == 'GET') {
			$r = getURLContent($url, 'GET', '', 1, $headers, array('http', 'https'), 2);
		} elseif ($method == 'DELETE') {
			$r = getURLContent($url, 'DELETE', '', 1, $headers, array('http', 'https'), 2);
		} else {
			$headers[] = 'Content-Type: application/json';
			if ($method == 'PATCH') {
				$headers[] = 'X-HTTP-Method-Override: PATCH';
			}
			$r = getURLContent($url, 'POSTALREADYFORMATED', json_encode($body), 1, $headers, array('http', 'https'), 2);
		}
		$code = isset($r['http_code']) ? (int) $r['http_code'] : 0;
		$json = json_decode((string) (isset($r['content']) ? $r['content'] : ''), true);
		if ($code >= 200 && $code < 300) {
			return is_array($json) ? $json : array();
		}
		if ($method == 'DELETE' && $code == 410) {
			return array(); // Already gone.
		}
		$this->error = 'Google Calendar '.$method.' failed (HTTP '.($code ?: '?').(is_array($json) && !empty($json['error']['message']) ? ': '.$json['error']['message'] : (!empty($r['curl_error_msg']) ? ', '.$r['curl_error_msg'] : '')).').';
		dol_syslog('Onboarding: '.$this->error, LOG_WARNING);
		return null;
	}

	/**
	 * Repeating events on the calendar, for picking the monthly class series.
	 *
	 * @return array<string,string>|null id => name
	 */
	public function series()
	{
		$r = $this->call('GET', '/events', null, array('singleEvents' => 'false', 'maxResults' => '250', 'timeMin' => gmdate('Y-m-d\TH:i:s\Z', time() - 400 * 86400)));
		if ($r === null) {
			return null;
		}
		$out = array();
		foreach (isset($r['items']) ? $r['items'] : array() as $e) {
			if (!empty($e['recurrence']) && (!isset($e['status']) || $e['status'] != 'cancelled')) {
				$out[(string) $e['id']] = (string) (isset($e['summary']) ? $e['summary'] : $e['id']);
			}
		}
		asort($out);
		return $out;
	}

	/**
	 * The coming dates of a repeating event.
	 *
	 * @param string $seriesId The repeating event
	 * @param int $days How far ahead
	 * @return array<int,array{id:string,start:int,minutes:int,summary:string}>|null
	 */
	public function dates($seriesId, $days = 400)
	{
		$r = $this->call('GET', '/events/'.rawurlencode($seriesId).'/instances', null, array('timeMin' => gmdate('Y-m-d\TH:i:s\Z', time()), 'timeMax' => gmdate('Y-m-d\TH:i:s\Z', time() + $days * 86400), 'maxResults' => '100'));
		if ($r === null) {
			return null;
		}
		$out = array();
		foreach (isset($r['items']) ? $r['items'] : array() as $e) {
			if ((isset($e['status']) && $e['status'] == 'cancelled') || empty($e['start']['dateTime'])) {
				continue;
			}
			$start = strtotime($e['start']['dateTime']);
			$end = !empty($e['end']['dateTime']) ? strtotime($e['end']['dateTime']) : $start + 7200;
			$out[] = array('id' => (string) $e['id'], 'start' => (int) $start, 'minutes' => (int) round(($end - $start) / 60), 'summary' => (string) (isset($e['summary']) ? $e['summary'] : ''));
		}
		return $out;
	}

	/**
	 * @param string $id Event (or one date of a series)
	 * @return array|null
	 */
	public function get($id)
	{
		return $this->call('GET', '/events/'.rawurlencode($id));
	}

	/**
	 * Change an event: only the fields given.
	 *
	 * @param string $id Event (or one date of a series)
	 * @param array<string,mixed> $fields summary, description, start, end
	 * @return array|null
	 */
	public function patch($id, $fields)
	{
		return $this->call('PATCH', '/events/'.rawurlencode($id), $fields);
	}

	/**
	 * @param array<string,mixed> $fields summary, description, start, end, location
	 * @return array|null The new event
	 */
	public function insert($fields)
	{
		return $this->call('POST', '/events', $fields);
	}

	/**
	 * Remove an event, or one date of a series.
	 *
	 * @param string $id Event
	 * @return bool
	 */
	public function delete($id)
	{
		return $this->call('DELETE', '/events/'.rawurlencode($id)) !== null;
	}

	/**
	 * @param int $ts Unix time
	 * @return array{dateTime:string,timeZone:string} A Calendar API time
	 */
	public static function at($ts)
	{
		$d = new DateTime('@'.$ts);
		$d->setTimezone(new DateTimeZone(self::TZ));
		return array('dateTime' => $d->format('Y-m-d\TH:i:sP'), 'timeZone' => self::TZ);
	}
}
