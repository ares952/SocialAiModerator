-- Reply suggestions for comments (specification §5.3, Milestone 3): the ReplyGenerator writes a
-- draft only from published knowledge, a moderator may edit it and must approve it. The text is
-- encrypted like customer content; every reply keeps the knowledge versions, model and prompt
-- version it was written with.

ALTER TABLE moderation_actions
	ADD COLUMN text_encrypted BLOB NULL COMMENT 'App\\Core\\Encryptor, context moderation_actions.text; reply text, NULL = not drafted yet' AFTER external_target_id,
	ADD COLUMN edited TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'the moderator changed the drafted text' AFTER text_encrypted,
	ADD COLUMN knowledge_json JSON NULL COMMENT 'knowledge sources the draft used: [{id, key, version}]' AFTER edited,
	ADD COLUMN model VARCHAR(100) NULL COMMENT 'model that wrote the draft' AFTER knowledge_json,
	ADD COLUMN prompt_version VARCHAR(50) NULL AFTER model;
