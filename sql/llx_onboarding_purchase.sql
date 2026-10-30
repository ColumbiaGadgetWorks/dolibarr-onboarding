-- A member's request to spend from a zone's budget, filed on the website.
--   kind:   reimburse (already bought, receipt attached) or purchase (treasurer buys it)
--   status: draft (receipts still uploading) -> pending -> approved -> paid,
--           or pending -> rejected, or pending/approved -> cancelled
--   needs: JSON list of the approvals it needs, each a different person except the
--          zone boss: treasurer, president, director (any other) or boss (of the zone)
--   need_board: 1 when it needs more than the treasurer and a zone boss (kept for the website)
--   approvals: JSON list of {email, name, role, at}
--   items: for a purchase, JSON list of {link, price}; amount is their total, an estimate
--   amount_final: what the treasurer really paid, from the order receipt (tax, shipping and fees included)
--   files: JSON list of stored receipt file names under onboarding/purchase/<rowid>/
CREATE TABLE llx_onboarding_purchase(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	ref varchar(20),
	token varchar(48) NOT NULL,
	fk_adherent integer NOT NULL,
	zone varchar(100) NOT NULL,
	kind varchar(12) NOT NULL,
	amount double(24,8) NOT NULL,
	amount_final double(24,8),
	items text,
	vendor varchar(200),
	purchase_date date,
	link varchar(500),
	description text,
	pay_method varchar(20),
	pay_handle varchar(200),
	status varchar(12) DEFAULT 'draft' NOT NULL,
	need_board integer DEFAULT 0 NOT NULL,
	needs varchar(100),
	approvals text,
	decided_note varchar(255),
	fk_bank_line integer,
	paid_from varchar(100),
	paid_at datetime,
	fk_user_paid integer,
	ip varchar(64),
	files text,
	datec datetime,
	date_submitted datetime
) ENGINE=innodb;
