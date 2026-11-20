-- Connected social accounts and the incoming Meta webhook events (specification §3.3, §6).
-- External identifiers are strings prefixed by the platform ('fb:', 'ig:'). Credentials,
-- login flow and policy follow in later migrations together with their tables.

CREATE TABLE social_accounts (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	platform VARCHAR(20) NOT NULL COMMENT 'App\\Meta\\Platform',
	external_id VARCHAR(100) NOT NULL COMMENT 'Page or IG professional account ID with the platform prefix',
	name VARCHAR(255) NOT NULL,
	status VARCHAR(20) NOT NULL COMMENT 'App\\Account\\AccountStatus',
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_social_accounts_platform_external (platform, external_id)
) ENGINE=InnoDB;

CREATE TABLE social_events (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	platform VARCHAR(20) NOT NULL COMMENT 'App\\Meta\\Platform',
	account_external_id VARCHAR(100) NOT NULL COMMENT 'entry.id of the webhook with the platform prefix, also for unknown accounts',
	account_id INT UNSIGNED NULL COMMENT 'NULL = account not connected, the event is ignored',
	event_key CHAR(64) NOT NULL COMMENT 'SHA-256 deduplication key, unique per platform and account',
	topic VARCHAR(50) NOT NULL COMMENT 'changes.field (feed, comments, ...) or messaging/standby',
	payload_encrypted MEDIUMBLOB NOT NULL COMMENT 'App\\Core\\Encryptor, context social_events.payload',
	status VARCHAR(20) NOT NULL COMMENT 'App\\Ingest\\EventStatus',
	attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	received_at DATETIME(3) NOT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uq_social_events_platform_account_key (platform, account_external_id, event_key),
	KEY ix_social_events_status (status, id),
	CONSTRAINT fk_social_events_account FOREIGN KEY (account_id) REFERENCES social_accounts (id)
) ENGINE=InnoDB;
