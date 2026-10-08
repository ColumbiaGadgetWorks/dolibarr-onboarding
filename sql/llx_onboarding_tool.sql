-- Tools and equipment people can be trained on, and requests to add one to the
-- Givebutter training form.
--   status: requested -> active (on the form) or declined; active -> retired
CREATE TABLE llx_onboarding_tool(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	zone varchar(100) NOT NULL,
	label varchar(150) NOT NULL,
	price double(24,8),
	status varchar(20) DEFAULT 'requested' NOT NULL,
	requested_by varchar(150),
	requester_email varchar(255),
	note varchar(500),
	decision_note varchar(255),
	fk_user_decided integer,
	date_decided datetime,
	datec datetime
) ENGINE=innodb;
