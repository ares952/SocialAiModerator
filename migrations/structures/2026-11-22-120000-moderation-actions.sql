-- Interventions on comments and their delivery to Meta (specification §6, Milestone 3).
-- moderation_actions holds what should happen to a comment (from an AI decision or an operator)
-- and who approved it; outbox holds the approved send with its idempotency key. Only the
-- ActionDispatcher sends outbox records, and it re-validates the policy, mode, panic switch,
-- account and comment state right before every send. A send with an uncertain result becomes
-- `unknown` and is never retried blindly. An action goes away with its comment (retention).

CREATE TABLE moderation_actions (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	comment_id BIGINT UNSIGNED NOT NULL,
	event_id BIGINT UNSIGNED NULL COMMENT 'event the action was proposed or requested from',
	decision_id BIGINT UNSIGNED NULL COMMENT 'AI decision behind the action; NULL = requested by an operator',
	type VARCHAR(20) NOT NULL COMMENT 'App\\Outbox\\ActionType',
	status VARCHAR(20) NOT NULL COMMENT 'App\\Outbox\\ActionStatus',
	automatic TINYINT(1) NOT NULL COMMENT 'queued without a human approval',
	external_target_id VARCHAR(255) NOT NULL COMMENT 'comment ID with the platform prefix',
	reasons_json JSON NULL COMMENT 'codes that blocked the action (decision.reason.<code>)',
	reviewed_by INT UNSIGNED NULL COMMENT 'operator who requested, approved or rejected the action',
	reviewed_at DATETIME(3) NULL,
	executed_at DATETIME(3) NULL COMMENT 'when Meta confirmed the send',
	result_json JSON NULL COMMENT 'Meta error of the last attempt (HTTP status, error code); never tokens or customer content',
	created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	PRIMARY KEY (id),
	UNIQUE KEY uq_moderation_actions_decision_type (decision_id, type),
	KEY ix_moderation_actions_status (status, id),
	KEY ix_moderation_actions_comment (comment_id, id),
	KEY ix_moderation_actions_event (event_id),
	CONSTRAINT fk_moderation_actions_comment FOREIGN KEY (comment_id) REFERENCES social_comments (id) ON DELETE CASCADE,
	CONSTRAINT fk_moderation_actions_event FOREIGN KEY (event_id) REFERENCES social_events (id) ON DELETE SET NULL,
	CONSTRAINT fk_moderation_actions_decision FOREIGN KEY (decision_id) REFERENCES moderation_decisions (id) ON DELETE SET NULL,
	CONSTRAINT fk_moderation_actions_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES operators (id)
) ENGINE=InnoDB;

CREATE TABLE outbox (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	action_id BIGINT UNSIGNED NOT NULL,
	idempotency_key VARCHAR(100) NOT NULL,
	status VARCHAR(20) NOT NULL COMMENT 'App\\Outbox\\OutboxStatus',
	attempts INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'sends started',
	last_error VARCHAR(1000) NULL COMMENT 'exception class and message, never tokens or customer content',
	created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	PRIMARY KEY (id),
	UNIQUE KEY uq_outbox_action (action_id),
	UNIQUE KEY uq_outbox_idempotency_key (idempotency_key),
	KEY ix_outbox_status (status),
	CONSTRAINT fk_outbox_action FOREIGN KEY (action_id) REFERENCES moderation_actions (id) ON DELETE CASCADE
) ENGINE=InnoDB;
