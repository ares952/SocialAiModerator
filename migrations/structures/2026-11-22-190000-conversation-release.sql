-- Automatic takeover (specification §3.5): when the account answers a customer outside the
-- application (e.g. Meta Business Suite), the conversation is taken over, so the AI never answers
-- in parallel with a person. released_at keeps an older reply from taking it over again after an
-- operator released it.

ALTER TABLE social_conversations
	ADD COLUMN released_at DATETIME(3) NULL COMMENT 'last explicit release by an operator' AFTER assignee_id;
