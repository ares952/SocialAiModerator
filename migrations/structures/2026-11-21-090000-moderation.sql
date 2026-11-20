-- Milestone 2 (AI in shadow mode): versioned moderation policies, the panic switch, the knowledge
-- base and the evaluation set with its runs. Customer texts of samples are encrypted at rest.

CREATE TABLE moderation_policies (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	account_id INT UNSIGNED NULL COMMENT 'NULL = global policy',
	version INT UNSIGNED NOT NULL COMMENT 'Stored as policy_version with every decision',
	mode VARCHAR(20) NOT NULL COMMENT 'App\\Moderation\\Mode',
	settings_json JSON NOT NULL COMMENT 'App\\Moderation\\PolicySettings: thresholds and allowed automatic actions',
	active TINYINT(1) NOT NULL DEFAULT 0,
	created_by INT UNSIGNED NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_moderation_policies_version (version),
	KEY ix_moderation_policies_active (account_id, active),
	CONSTRAINT fk_moderation_policies_account FOREIGN KEY (account_id) REFERENCES social_accounts (id),
	CONSTRAINT fk_moderation_policies_operator FOREIGN KEY (created_by) REFERENCES operators (id)
) ENGINE=InnoDB;

CREATE TABLE automation_pauses (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	account_id INT UNSIGNED NULL COMMENT 'NULL = global panic switch',
	reason VARCHAR(255) NULL,
	paused_by INT UNSIGNED NULL,
	paused_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	resumed_by INT UNSIGNED NULL,
	resumed_at DATETIME NULL COMMENT 'NULL = the pause is in effect',
	PRIMARY KEY (id),
	KEY ix_automation_pauses_open (resumed_at, account_id),
	CONSTRAINT fk_automation_pauses_account FOREIGN KEY (account_id) REFERENCES social_accounts (id),
	CONSTRAINT fk_automation_pauses_paused_by FOREIGN KEY (paused_by) REFERENCES operators (id),
	CONSTRAINT fk_automation_pauses_resumed_by FOREIGN KEY (resumed_by) REFERENCES operators (id)
) ENGINE=InnoDB;

CREATE TABLE knowledge_sources (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`key` VARCHAR(100) NOT NULL COMMENT 'Stable name the AI refers to, e.g. pricing_current',
	locale VARCHAR(5) NOT NULL,
	version INT UNSIGNED NOT NULL,
	source_type VARCHAR(20) NOT NULL COMMENT 'App\\Knowledge\\SourceType',
	title VARCHAR(200) NOT NULL,
	content MEDIUMTEXT NOT NULL,
	valid_from DATE NULL,
	valid_to DATE NULL COMMENT 'Inclusive',
	status VARCHAR(20) NOT NULL COMMENT 'App\\Knowledge\\SourceStatus',
	created_by INT UNSIGNED NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	approved_by INT UNSIGNED NULL,
	approved_at DATETIME NULL COMMENT 'Set when published',
	PRIMARY KEY (id),
	UNIQUE KEY uq_knowledge_sources_version (`key`, locale, version),
	KEY ix_knowledge_sources_status (status, `key`, locale),
	CONSTRAINT fk_knowledge_sources_created_by FOREIGN KEY (created_by) REFERENCES operators (id),
	CONSTRAINT fk_knowledge_sources_approved_by FOREIGN KEY (approved_by) REFERENCES operators (id)
) ENGINE=InnoDB;

CREATE TABLE evaluation_samples (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	platform VARCHAR(20) NOT NULL COMMENT 'App\\Meta\\Platform',
	kind VARCHAR(20) NOT NULL COMMENT 'App\\Moderation\\ContentKind',
	language VARCHAR(5) NOT NULL COMMENT 'Expected language, ISO 639-1',
	text_encrypted BLOB NOT NULL COMMENT 'App\\Core\\Encryptor, context evaluation_samples.text; synthetic or anonymized only',
	expected_category VARCHAR(30) NOT NULL COMMENT 'App\\Moderation\\Category',
	expected_action VARCHAR(20) NOT NULL COMMENT 'App\\Moderation\\Action',
	note VARCHAR(500) NULL,
	created_by INT UNSIGNED NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	CONSTRAINT fk_evaluation_samples_created_by FOREIGN KEY (created_by) REFERENCES operators (id)
) ENGINE=InnoDB;

CREATE TABLE evaluation_runs (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	model VARCHAR(100) NOT NULL,
	prompt_version VARCHAR(50) NOT NULL,
	policy_version INT UNSIGNED NOT NULL COMMENT '0 = default policy from AUTOMATION_DEFAULT_MODE',
	sample_count INT UNSIGNED NOT NULL,
	created_by INT UNSIGNED NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	CONSTRAINT fk_evaluation_runs_created_by FOREIGN KEY (created_by) REFERENCES operators (id)
) ENGINE=InnoDB;

CREATE TABLE evaluation_results (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	run_id INT UNSIGNED NOT NULL,
	sample_id INT UNSIGNED NULL COMMENT 'NULL when the sample was deleted later',
	expected_category VARCHAR(30) NOT NULL COMMENT 'Label at the time of the run',
	expected_action VARCHAR(20) NOT NULL,
	status VARCHAR(20) NOT NULL COMMENT 'App\\Evaluation\\ResultStatus',
	category VARCHAR(30) NULL,
	language VARCHAR(5) NULL,
	confidence DECIMAL(5,4) NULL,
	spam_probability DECIMAL(5,4) NULL,
	recommended_action VARCHAR(20) NULL COMMENT 'Advice of the model',
	action VARCHAR(20) NULL COMMENT 'Decision of the PolicyEngine',
	automatic TINYINT(1) NULL COMMENT 'The policy would allow the action without a human',
	reasons_json JSON NULL,
	moderation_flagged TINYINT(1) NULL,
	input_tokens INT UNSIGNED NULL,
	output_tokens INT UNSIGNED NULL,
	error VARCHAR(500) NULL COMMENT 'Exception class and message, never customer content',
	completed_at DATETIME NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uq_evaluation_results_run_sample (run_id, sample_id),
	KEY ix_evaluation_results_status (run_id, status),
	CONSTRAINT fk_evaluation_results_run FOREIGN KEY (run_id) REFERENCES evaluation_runs (id) ON DELETE CASCADE,
	CONSTRAINT fk_evaluation_results_sample FOREIGN KEY (sample_id) REFERENCES evaluation_samples (id) ON DELETE SET NULL
) ENGINE=InnoDB;
