-- Alarms of the administration dashboard and of `bin/console monitor:check` (specification §8.4).
-- Each kind has an editable threshold; NULL turns the alarm off. Reply drafts keep their token
-- usage like decisions, so the dashboard shows the whole AI consumption.

CREATE TABLE alarm_thresholds (
	kind VARCHAR(30) NOT NULL COMMENT 'App\\Monitoring\\AlarmKind',
	value SMALLINT UNSIGNED NULL COMMENT 'unit of the kind; NULL = alarm off',
	updated_by INT UNSIGNED NULL,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (kind),
	CONSTRAINT fk_alarm_thresholds_operator FOREIGN KEY (updated_by) REFERENCES operators (id)
) ENGINE=InnoDB;

ALTER TABLE moderation_actions
	ADD COLUMN input_tokens INT UNSIGNED NULL COMMENT 'reply draft' AFTER prompt_version,
	ADD COLUMN output_tokens INT UNSIGNED NULL AFTER input_tokens;
