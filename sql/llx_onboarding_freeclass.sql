-- A free class people sign up for on the website's /classes/ page.
--   kind    oca: a date of the monthly City of Columbia (Office of Cultural Affairs)
--                series on the Google Calendar; its event is that one date
--           extra: any other date, its own event on the calendar
--   gcal_event_id  the calendar event this class writes to (an instance id for oca)
--   gcal_written   hash of what was last written, so the daily check can put it back
--   status  open -> done, or cancelled (taken off the calendar, everyone told)
CREATE TABLE llx_onboarding_freeclass(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	kind varchar(10) DEFAULT 'oca' NOT NULL,
	title varchar(200) NOT NULL,
	description text,
	session_start datetime NOT NULL,
	minutes integer DEFAULT 120 NOT NULL,
	seats integer DEFAULT 12 NOT NULL,
	fk_instructor integer,
	status varchar(10) DEFAULT 'open' NOT NULL,
	gcal_event_id varchar(255),
	gcal_written varchar(64),
	gcal_error varchar(255),
	reminders_sent integer DEFAULT 0 NOT NULL,
	roster_sent integer DEFAULT 0 NOT NULL,
	fk_user integer,
	datec datetime
) ENGINE=innodb;
