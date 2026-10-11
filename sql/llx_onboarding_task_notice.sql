-- What the deadline reminders did for a project task, so the daily job knows
-- when it last nagged, whether it already escalated, and whether it already
-- made next year's copy.
--   kind: nag (fk_user = recipient), escalate (fk_user = recipient),
--         complete (fk_user = who used the email link), repeat (note = new task id)
CREATE TABLE llx_onboarding_task_notice(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_task integer NOT NULL,
	kind varchar(20) NOT NULL,
	fk_user integer,
	note varchar(255),
	datec datetime
) ENGINE=innodb;
