-- A member vote: the bylaw questions and board seats put to full members at an
-- annual meeting. Worked by the Secretary and the President from email buttons
-- (public/vote.php); see class/vote.class.php.
--   status   draft -> noticed (notice sent) -> open (voting) -> closed
--   voters   size of the roll when voting opened; ballots: how many voted
--   ratify   1 at the first annual meeting: the elections count only if the
--            first bylaw question passes
--   done     steps already emailed or done, as a JSON object of name => time
--   test     1 for a practice vote: the board members are the voters, the date
--            rules are skipped, and nothing is posted on GitHub
CREATE TABLE llx_onboarding_vote(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	label varchar(128) NOT NULL,
	meeting_at datetime NOT NULL,
	status varchar(10) DEFAULT 'draft' NOT NULL,
	ratify integer DEFAULT 0 NOT NULL,
	test integer DEFAULT 0 NOT NULL,
	pr_url varchar(255),
	tag varchar(64),
	tag_sha varchar(64),
	notice_at datetime,
	opened_at datetime,
	closes_at datetime,
	closed_at datetime,
	voters integer DEFAULT 0 NOT NULL,
	ballots integer DEFAULT 0 NOT NULL,
	result text,
	posted_url varchar(255),
	post_error varchar(255),
	done text,
	datec datetime
) ENGINE=innodb;
