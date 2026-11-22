-- Unified inbox (specification §3.5, §4.2, Milestone 4): an operator takes a conversation over and
-- releases it explicitly; while taken over, no AI reply is drafted or sent. A moderation action
-- targets either a comment or a customer message (a reply to it); a reply is re-validated against
-- the standard window at the moment of sending.

ALTER TABLE social_conversations
	ADD COLUMN human_takeover_at DATETIME(3) NULL COMMENT 'taken over by an operator; NULL = released' AFTER window_expires_at,
	ADD COLUMN assignee_id INT UNSIGNED NULL COMMENT 'operator who took the conversation over' AFTER human_takeover_at,
	ADD CONSTRAINT fk_social_conversations_assignee FOREIGN KEY (assignee_id) REFERENCES operators (id);

ALTER TABLE moderation_actions
	MODIFY comment_id BIGINT UNSIGNED NULL,
	ADD COLUMN message_id BIGINT UNSIGNED NULL COMMENT 'customer message a reply answers' AFTER comment_id,
	ADD KEY ix_moderation_actions_message (message_id, id),
	ADD CONSTRAINT fk_moderation_actions_message FOREIGN KEY (message_id) REFERENCES social_messages (id) ON DELETE CASCADE,
	ADD CONSTRAINT chk_moderation_actions_target CHECK ((comment_id IS NULL) <> (message_id IS NULL));
