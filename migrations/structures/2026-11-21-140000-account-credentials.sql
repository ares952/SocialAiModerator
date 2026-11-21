-- Meta access tokens of connected accounts (specification §6, §8.2). Tokens live in the database,
-- encrypted with APP_ENCRYPTION_KEY, which stays outside of it (config/secrets/app.env). One token
-- per account; a replacement updates the row and the audit log keeps who replaced it and when.

CREATE TABLE account_credentials (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	social_account_id INT UNSIGNED NOT NULL,
	token_type VARCHAR(30) NOT NULL COMMENT 'App\\Account\\TokenType',
	encrypted_token BLOB NOT NULL COMMENT 'App\\Core\\Encryptor, context account_credentials.token; the ciphertext header carries the key ID',
	scopes VARCHAR(2000) NOT NULL COMMENT 'granted permissions, comma-separated',
	expires_at DATETIME NULL COMMENT 'UTC; NULL = never expires, the token can still be revoked',
	data_access_expires_at DATETIME NULL COMMENT 'UTC; NULL = not known',
	status VARCHAR(20) NOT NULL COMMENT 'App\\Account\\TokenStatus',
	checked_at DATETIME NULL COMMENT 'UTC; last validity check against Meta',
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	refreshed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'last replacement of the token',
	PRIMARY KEY (id),
	UNIQUE KEY uq_account_credentials_account (social_account_id),
	CONSTRAINT fk_account_credentials_account FOREIGN KEY (social_account_id) REFERENCES social_accounts (id)
) ENGINE=InnoDB;
