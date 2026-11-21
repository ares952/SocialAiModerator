-- Retention periods of stored data (specification §6, §8.3). The default period is an open
-- question (specification §13, item 8), so nothing is preset: a missing row or NULL days means
-- the data is kept. An admin sets the periods in the administration; the worker purges.

CREATE TABLE retention_periods (
	data VARCHAR(30) NOT NULL COMMENT 'App\\Retention\\RetentionData',
	days SMALLINT UNSIGNED NULL COMMENT 'NULL = keep',
	updated_by INT UNSIGNED NULL,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (data),
	CONSTRAINT fk_retention_periods_operator FOREIGN KEY (updated_by) REFERENCES operators (id)
) ENGINE=InnoDB;

ALTER TABLE social_events
	ADD KEY ix_social_events_received (received_at);
