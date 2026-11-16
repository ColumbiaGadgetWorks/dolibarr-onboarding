<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

/**
 * Schema changes to tables that already exist. The CREATE TABLE files in sql/
 * describe a fresh install in full; a step here brings an older database to the
 * same shape. Run from modOnboarding::init(), keyed to the module version, so
 * nothing depends on Dolibarr ignoring "column already exists".
 *
 * To change a table: edit its CREATE TABLE file, add a step here with the module
 * version that ships it, and bump the module version.
 */
class OnboardingMigrations
{
	/**
	 * Each step adds one column. 'since' is the module version that ships it: a
	 * database already migrated to that version or later skips it. Every step is
	 * also skipped when the column is already there, so running twice is safe.
	 * Columns added before 0.23.0 (once in the .key.sql files) are listed under
	 * 0.23.0, the first version with migrations: databases that have never been
	 * migrated check them all.
	 */
	const STEPS = array(
		array('0.23.0', 'onboarding_applicant', 'training_only', 'integer DEFAULT 0 NOT NULL'),
		array('0.23.0', 'onboarding_applicant', 'birthdate', 'date'),
		array('0.23.0', 'onboarding_applicant', 'guardian_name', 'varchar(200)'),
		array('0.23.0', 'onboarding_applicant', 'guardian_relation', 'varchar(50)'),
		array('0.23.0', 'onboarding_applicant', 'guardian_email', 'varchar(255)'),
		array('0.23.0', 'onboarding_applicant', 'token_salt', 'varchar(32)'),
		array('0.23.0', 'onboarding_purchase', 'needs', 'varchar(100)'),
		array('0.23.0', 'onboarding_purchase', 'items', 'text'),
		array('0.23.0', 'onboarding_purchase', 'amount_final', 'double(24,8)'),
		array('0.23.0', 'onboarding_stmt', 'note', 'varchar(12)'),
		array('0.23.0', 'onboarding_stmt_file', 'begin_balance', 'double(24,8)'),
		array('0.23.0', 'onboarding_stmt_file', 'begin_date', 'date'),
		array('0.23.0', 'onboarding_training', 'source', "varchar(10) DEFAULT 'paid' NOT NULL"),
		array('0.23.0', 'onboarding_training', 'fk_booking', 'integer'),
		array('0.23.0', 'onboarding_training', 'fk_tool', 'integer'),
		array('0.23.0', 'onboarding_training', 'free_reason', 'varchar(255)'),
		array('0.23.0', 'onboarding_training', 'fk_user', 'integer'),
		array('0.23.0', 'onboarding_training', 'amount_net', 'double(24,8)'),
	);

	/**
	 * @param DoliDB $db Database handler
	 * @param string $table Table name without the prefix
	 * @param string $column Column
	 * @return bool
	 */
	public static function hasColumn($db, $table, $column)
	{
		$res = $db->DDLDescTable($db->prefix().$table, $column);
		return $res && $db->num_rows($res) > 0;
	}

	/**
	 * Apply the steps newer than the version the database was last migrated to.
	 *
	 * @param DoliDB $db Database handler
	 * @param string $to Module version being installed
	 * @return string[] Errors; empty if all went well
	 */
	public static function run($db, $to)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$from = getDolGlobalString('ONBOARDING_SCHEMA_VERSION');
		$errors = array();
		foreach (self::STEPS as $step) {
			list($since, $table, $column, $def) = $step;
			if ($from !== '' && version_compare($from, $since, '>=')) {
				continue;
			}
			if (self::hasColumn($db, $table, $column)) {
				continue;
			}
			if (!$db->query("ALTER TABLE ".$db->prefix().$table." ADD COLUMN ".$column." ".$def)) {
				$errors[] = $table.'.'.$column.': '.$db->lasterror();
			}
		}
		if ($errors) {
			dol_syslog('Onboarding: schema migration failed: '.implode('; ', $errors), LOG_ERR);
			return $errors;
		}
		if ($from === '' || version_compare($from, $to, '<')) {
			// Schema is shared by every entity: stored once, for entity 0.
			dolibarr_set_const($db, 'ONBOARDING_SCHEMA_VERSION', $to, 'chaine', 0, 'Module version the onboarding tables were last migrated to', 0);
		}
		return array();
	}
}
