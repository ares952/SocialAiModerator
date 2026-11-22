-- Current state of posts, comments, conversations and messages, projected by the worker from the
-- normalized events (specification §3.3, §3.5, §6). Events can arrive repeatedly and out of
-- order: the projection is idempotent and compares the Meta time of each change. External IDs are
-- strings with the platform prefix. Customer texts are encrypted (App\Core\Encryptor).
-- Post text and publication time follow with the Graph read client; takeover and assignee with
-- the conversation takeover.

CREATE TABLE social_posts (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	account_id INT UNSIGNED NOT NULL,
	external_id VARCHAR(255) NOT NULL COMMENT 'post or media ID with the platform prefix',
	permalink VARCHAR(500) NULL COMMENT 'Facebook or Instagram link only',
	created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	PRIMARY KEY (id),
	UNIQUE KEY uq_social_posts_account_external (account_id, external_id),
	CONSTRAINT fk_social_posts_account FOREIGN KEY (account_id) REFERENCES social_accounts (id)
) ENGINE=InnoDB;

CREATE TABLE social_comments (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	account_id INT UNSIGNED NOT NULL,
	post_id BIGINT UNSIGNED NULL,
	external_id VARCHAR(255) NOT NULL COMMENT 'comment ID with the platform prefix',
	parent_external_id VARCHAR(255) NULL COMMENT 'comment replied to; NULL = top-level',
	author_external_id VARCHAR(255) NULL COMMENT 'author with the platform prefix',
	own TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'written by the account itself',
	text_encrypted BLOB NULL COMMENT 'App\\Core\\Encryptor, context social_comments.text; NULL = unknown or removed',
	visibility VARCHAR(20) NOT NULL COMMENT 'App\\Comment\\Visibility',
	text_changed_at DATETIME(3) NULL COMMENT 'time of the change that set the text',
	visibility_changed_at DATETIME(3) NULL COMMENT 'time of the change that set the visibility',
	created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT 'last projected event; retention, not changed by re-encryption',
	PRIMARY KEY (id),
	UNIQUE KEY uq_social_comments_account_external (account_id, external_id),
	KEY ix_social_comments_author (account_id, author_external_id, created_at),
	KEY ix_social_comments_updated (updated_at),
	CONSTRAINT fk_social_comments_account FOREIGN KEY (account_id) REFERENCES social_accounts (id),
	CONSTRAINT fk_social_comments_post FOREIGN KEY (post_id) REFERENCES social_posts (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE social_conversations (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	account_id INT UNSIGNED NOT NULL,
	customer_external_id VARCHAR(255) NOT NULL COMMENT 'PSID or IGSID with the platform prefix',
	last_customer_message_at DATETIME(3) NULL COMMENT 'Meta time of the latest customer message; NULL = none verified',
	window_expires_at DATETIME(3) NULL COMMENT 'end of the standard reply window; NULL = no window',
	created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	PRIMARY KEY (id),
	UNIQUE KEY uq_social_conversations_account_customer (account_id, customer_external_id),
	CONSTRAINT fk_social_conversations_account FOREIGN KEY (account_id) REFERENCES social_accounts (id)
) ENGINE=InnoDB;

CREATE TABLE social_messages (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	conversation_id BIGINT UNSIGNED NOT NULL,
	external_id VARCHAR(255) NOT NULL COMMENT 'message mid with the platform prefix',
	direction VARCHAR(20) NOT NULL COMMENT 'App\\Conversation\\Direction',
	text_encrypted BLOB NULL COMMENT 'App\\Core\\Encryptor, context social_messages.text; NULL = none or unsent',
	sent_at DATETIME(3) NULL COMMENT 'Meta time; NULL = not verified',
	deleted_at DATETIME(3) NULL COMMENT 'unsent by the customer',
	created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	PRIMARY KEY (id),
	UNIQUE KEY uq_social_messages_conversation_external (conversation_id, external_id),
	KEY ix_social_messages_created (created_at),
	CONSTRAINT fk_social_messages_conversation FOREIGN KEY (conversation_id) REFERENCES social_conversations (id) ON DELETE CASCADE
) ENGINE=InnoDB;
