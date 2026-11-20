-- Administration accounts (operators), their audit trail and failed sign-ins for throttling.
-- The first admin is created by `bin/console operator:create` (deploy/README.md).

CREATE TABLE operators (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	email VARCHAR(254) NOT NULL COMMENT 'Sign-in name, stored lower-case',
	name VARCHAR(100) NOT NULL,
	role VARCHAR(20) NOT NULL COMMENT 'App\\Operator\\Role',
	password_hash VARCHAR(255) NOT NULL,
	active TINYINT(1) NOT NULL DEFAULT 1,
	session_version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Raised on password change or deactivation; sessions with an older version are signed out',
	last_login_at DATETIME NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_operators_email (email)
) ENGINE=InnoDB;

CREATE TABLE admin_audit (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	operator_id INT UNSIGNED NULL COMMENT 'NULL = console (bin/console)',
	action VARCHAR(50) NOT NULL COMMENT 'App\\Audit\\AuditAction',
	target_type VARCHAR(50) NOT NULL,
	target_id VARCHAR(100) NULL,
	details VARCHAR(1000) NULL COMMENT 'Changed values, never passwords or customer content',
	created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	PRIMARY KEY (id),
	KEY ix_admin_audit_target (target_type, target_id),
	KEY ix_admin_audit_created (created_at),
	CONSTRAINT fk_admin_audit_operator FOREIGN KEY (operator_id) REFERENCES operators (id)
) ENGINE=InnoDB;

CREATE TABLE login_failures (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	email VARCHAR(254) NOT NULL,
	ip_address VARCHAR(45) NOT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	KEY ix_login_failures_email (email, created_at),
	KEY ix_login_failures_ip (ip_address, created_at)
) ENGINE=InnoDB;
