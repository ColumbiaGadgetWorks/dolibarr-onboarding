-- One person booked on one training session (or signed in to a group class).
-- The tool, zone, trainer, time and price live here; the Givebutter payment
-- carries only an email and an amount, and is matched to the booking by them.
--   status: held -> paid -> confirmed -> trained
--           paid/confirmed -> needs_new_date (trainer can't, no confirmation,
--             closed day, first no-show) -> paid again on a new date
--           -> no_show (second), other (waiting on the secretary),
--           refund_requested -> refunded, cancelled, expired (hold ran out)
--   price_type: member, nonmember, free
--   trainer_sent / trainee_sent: comma separated names of the emails already
--     sent for the current date, so none goes twice; cleared when the date moves
CREATE TABLE llx_onboarding_booking(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	ref varchar(20),
	token varchar(48) NOT NULL,
	fk_tool integer NOT NULL,
	fk_slot integer,
	fk_class integer,
	fk_trainer integer,
	fk_adherent integer,
	fk_socpeople integer,
	fk_applicant integer,
	email varchar(255) NOT NULL,
	firstname varchar(100),
	lastname varchar(100),
	session_start datetime,
	minutes integer DEFAULT 60 NOT NULL,
	price double(24,8) DEFAULT 0 NOT NULL,
	price_type varchar(10) DEFAULT 'nonmember' NOT NULL,
	status varchar(20) DEFAULT 'held' NOT NULL,
	hold_until datetime,
	gb_transaction_id varchar(64),
	amount_paid double(24,8),
	amount_net double(24,8),
	paid_at datetime,
	confirmed_at datetime,
	no_shows integer DEFAULT 0 NOT NULL,
	rebooks integer DEFAULT 0 NOT NULL,
	trainer_sent varchar(255),
	trainee_sent varchar(255),
	answer varchar(20),
	comment text,
	answered_at datetime,
	fk_training integer,
	source varchar(10) DEFAULT 'web' NOT NULL,
	ip varchar(64),
	datec datetime,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
