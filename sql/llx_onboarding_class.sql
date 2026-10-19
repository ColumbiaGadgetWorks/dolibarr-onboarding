-- A group class: several people trained at once, signing themselves in with a
-- code that works only around the class time. Attendees are bookings with
-- fk_class set.
--   mode   confirm: the trainer confirms the list afterwards; auto: trained at sign-in
--   status open -> done, or cancelled
CREATE TABLE llx_onboarding_class(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	code varchar(20) NOT NULL,
	fk_tool integer NOT NULL,
	fk_trainer integer NOT NULL,
	session_start datetime NOT NULL,
	minutes integer DEFAULT 60 NOT NULL,
	seats integer DEFAULT 10 NOT NULL,
	price double(24,8) DEFAULT 0 NOT NULL,
	mode varchar(10) DEFAULT 'confirm' NOT NULL,
	status varchar(10) DEFAULT 'open' NOT NULL,
	note varchar(255),
	roster_sent integer DEFAULT 0 NOT NULL,
	reminders_sent integer DEFAULT 0 NOT NULL,
	fk_user integer,
	datec datetime
) ENGINE=innodb;
