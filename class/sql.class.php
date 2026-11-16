<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

/**
 * Builds INSERT and UPDATE statements from column => value arrays, so every
 * value is escaped (or cast) the same way and none can be missed.
 *
 * Values: null is NULL, true/false are 1/0, int and float are written as
 * numbers, anything else is an escaped string. Dates are passed as the string
 * $db->idate() returns. Column names must be plain lowercase identifiers.
 */
class OnboardingSql
{
	/**
	 * @param DoliDB $db Database handler
	 * @param mixed $v Value
	 * @return string SQL literal
	 */
	public static function value($db, $v)
	{
		if ($v === null) {
			return 'NULL';
		}
		if (is_bool($v)) {
			return $v ? '1' : '0';
		}
		if (is_int($v)) {
			return (string) $v;
		}
		if (is_float($v)) {
			// %F ignores the locale, so a decimal comma never reaches SQL.
			$s = rtrim(rtrim(sprintf('%.8F', $v), '0'), '.');
			return $s === '' || $s === '-' ? '0' : $s;
		}
		return "'".$db->escape((string) $v)."'";
	}

	/**
	 * @param array<string,mixed> $fields Column => value
	 * @return array<string,mixed> The same, with any column name that is not a plain identifier dropped
	 */
	private static function clean($fields)
	{
		$out = array();
		foreach ($fields as $col => $v) {
			if (preg_match('/^[a-z_][a-z0-9_]*$/', (string) $col)) {
				$out[$col] = $v;
			} else {
				dol_syslog('Onboarding: refused column name '.$col, LOG_ERR);
			}
		}
		return $out;
	}

	/**
	 * Insert one row.
	 *
	 * @param DoliDB $db Database handler
	 * @param string $table Table name without the prefix, e.g. onboarding_payment
	 * @param array<string,mixed> $fields Column => value
	 * @return int New rowid, or 0 if the insert failed (a duplicate key, say)
	 */
	public static function insert($db, $table, $fields)
	{
		$fields = self::clean($fields);
		if (!$fields || !preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
			return 0;
		}
		$vals = array();
		foreach ($fields as $v) {
			$vals[] = self::value($db, $v);
		}
		$sql = "INSERT INTO ".$db->prefix().$table." (".implode(', ', array_keys($fields)).") VALUES (".implode(', ', $vals).")";
		if (!$db->query($sql)) {
			return 0;
		}
		return (int) $db->last_insert_id($db->prefix().$table);
	}

	/**
	 * Update rows.
	 *
	 * @param DoliDB $db Database handler
	 * @param string $table Table name without the prefix
	 * @param array<string,mixed> $fields Column => value
	 * @param string $where SQL condition, already escaped by the caller
	 * @return resource|bool|object Query result, for $db->affected_rows(); false on failure
	 */
	public static function update($db, $table, $fields, $where)
	{
		$fields = self::clean($fields);
		if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table) || trim((string) $where) === '') {
			return false;
		}
		if (!$fields) {
			return true;
		}
		$set = array();
		foreach ($fields as $col => $v) {
			$set[] = $col." = ".self::value($db, $v);
		}
		return $db->query("UPDATE ".$db->prefix().$table." SET ".implode(', ', $set)." WHERE ".$where);
	}
}
