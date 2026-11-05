<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

/**
 * Google Calendar, through a small Google Apps Script that runs as the calendar's
 * own account (scripts/calendar-bridge.gs): Dolibarr posts to the script's web app
 * address with a shared secret, and the script calls the Calendar API on the one
 * calendar named in it. No Google Cloud project, service account or key file. The
 * address and the secret are on the setup page, Free classes.
 *
 * Only what free classes need: read the dates of the monthly series, change one of
 * those dates, add or remove a single event. An edit to one date of a repeating
 * event is made on that date's own instance id, so the rest of the series is left
 * alone.
 */
class OnboardingGcal
{
	const TZ = 'America/Chicago';

	/** @var string Last error, for the screen and the log */
	public $error = '';

	/**
	 * @return bool The script's address and the secret are set
	 */
	public static function configured()
	{
		return getDolGlobalString('ONBOARDING_GCAL_SCRIPT_URL') !== '' && getDolGlobalString('ONBOARDING_GCAL_SECRET') !== '';
	}

	/**
	 * Ask the script for one Calendar API call.
	 *
	 * @param string $method GET, POST, PATCH or DELETE
	 * @param string $path /events, /events/ID or /events/ID/instances
	 * @param array<string,mixed>|null $body JSON body
	 * @param array<string,mixed> $query Query parameters
	 * @return array|null The answer (empty for a DELETE), null with error set
	 */
	public function call($method, $path, $body = null, $query = array())
	{
		$this->error = '';
		if (!self::configured()) {
			$this->error = 'The calendar script\'s address and secret are not on the setup page (Free classes).';
			return null;
		}
		$id = '';
		if (preg_match('#^/events/([^/]+)/instances$#', $path, $m) && $method == 'GET') {
			$op = 'instances';
			$id = rawurldecode($m[1]);
		} elseif (preg_match('#^/events/([^/]+)$#', $path, $m)) {
			$ops = array('GET' => 'get', 'PATCH' => 'patch', 'DELETE' => 'delete');
			$op = isset($ops[$method]) ? $ops[$method] : '';
			$id = rawurldecode($m[1]);
		} elseif ($path == '/events') {
			$op = $method == 'POST' ? 'insert' : ($method == 'GET' ? 'list' : '');
		} else {
			$op = '';
		}
		if ($op === '') {
			$this->error = 'Not a calendar call the script knows: '.$method.' '.$path;
			return null;
		}
		foreach ($query as $k => $v) {
			if ($v === 'true' || $v === 'false') {
				$query[$k] = $v === 'true';
			} elseif (is_string($v) && ctype_digit($v)) {
				$query[$k] = (int) $v;
			}
		}
		$req = json_encode(array('secret' => getDolGlobalString('ONBOARDING_GCAL_SECRET'), 'op' => $op, 'id' => $id, 'query' => (object) $query, 'body' => $body === null ? null : (object) $body));
		// A web app answers a POST with a redirect to where the answer waits, to be
		// fetched with a GET. Dolibarr's own redirect handling would POST again, so
		// the redirect is followed here.
		$r = getURLContent(getDolGlobalString('ONBOARDING_GCAL_SCRIPT_URL'), 'POSTALREADYFORMATED', $req, 0, array('Content-Type: application/json', 'Accept: application/json'), array('http', 'https'), 2);
		$code = isset($r['http_code']) ? (int) $r['http_code'] : 0;
		if (in_array($code, array(301, 302, 303, 307), true) && !empty($r['redirect_url'])) {
			$r = getURLContent((string) $r['redirect_url'], 'GET', '', 1, array('Accept: application/json'), array('http', 'https'), 2);
			$code = isset($r['http_code']) ? (int) $r['http_code'] : 0;
		}
		$json = json_decode((string) (isset($r['content']) ? $r['content'] : ''), true);
		if ($code == 200 && is_array($json) && !empty($json['ok'])) {
			return isset($json['result']) && is_array($json['result']) ? $json['result'] : array();
		}
		$gcode = is_array($json) && isset($json['code']) ? (int) $json['code'] : 0;
		if ($op == 'delete' && $gcode == 410) {
			return array(); // Already gone.
		}
		if (is_array($json) && isset($json['ok'])) {
			$this->error = 'Google Calendar '.$op.' failed'.($gcode ? ' ('.$gcode.')' : '').(!empty($json['message']) ? ': '.$json['message'] : '').'.';
		} else {
			$this->error = 'The calendar script did not answer (HTTP '.($code ?: '?').(!empty($r['curl_error_msg']) ? ', '.$r['curl_error_msg'] : '').'). Check its address on the setup page, and that it is deployed as a web app anyone can reach.';
		}
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
