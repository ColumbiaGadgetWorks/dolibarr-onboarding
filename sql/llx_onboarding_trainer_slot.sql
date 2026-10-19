-- When a trainer runs a tool's training, every week. Holding a slot for a tool
-- is what makes someone a trainer on it (their member card also gets the tag
-- "Trainer: <tool>"). weekday: 1 Monday .. 7 Sunday. start_time: HH:MM, local.
CREATE TABLE llx_onboarding_trainer_slot(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_tool integer NOT NULL,
	fk_adherent integer NOT NULL,
	weekday integer NOT NULL,
	start_time varchar(5) NOT NULL,
	minutes integer,
	seats integer,
	active integer DEFAULT 1 NOT NULL,
	datec datetime
) ENGINE=innodb;
