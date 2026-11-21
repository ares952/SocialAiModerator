-- Decisions about incoming comments and messages (specification §5, §6; Milestone 2, AI in shadow
-- mode): what the classifier said and what the PolicyEngine decided with which policy version.
-- In `observe` mode nothing is executed; the decision is shown to the moderator. A failed
-- classification is stored too, as a fail-closed review with the error.

CREATE TABLE moderation_decisions (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	event_id BIGINT UNSIGNED NOT NULL,
	model VARCHAR(100) NOT NULL COMMENT 'model that answered, or the configured one when the call failed',
	prompt_version VARCHAR(50) NOT NULL,
	policy_version INT UNSIGNED NOT NULL COMMENT '0 = default policy from AUTOMATION_DEFAULT_MODE',
	category VARCHAR(30) NULL COMMENT 'App\\Moderation\\Category; NULL = no classification',
	confidence DECIMAL(5,4) NULL,
	action VARCHAR(20) NOT NULL COMMENT 'App\\Moderation\\Action decided by the PolicyEngine',
	automatic TINYINT(1) NOT NULL COMMENT 'the policy allows the action without a human',
	result_json JSON NOT NULL COMMENT 'classification without its reason, moderation flags, decision reasons',
	reason_encrypted BLOB NULL COMMENT 'App\\Core\\Encryptor, context moderation_decisions.reason; model explanation, may paraphrase the customer',
	input_tokens INT UNSIGNED NULL,
	output_tokens INT UNSIGNED NULL,
	error VARCHAR(500) NULL COMMENT 'exception class and message, never customer content',
	created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	PRIMARY KEY (id),
	KEY ix_moderation_decisions_event (event_id, id),
	CONSTRAINT fk_moderation_decisions_event FOREIGN KEY (event_id) REFERENCES social_events (id) ON DELETE CASCADE
) ENGINE=InnoDB;
