-- Languages of the administration UI and their translation texts. The texts themselves are not
-- part of the migrations: the application imports them from data/language_<code>.json
-- (bin/console translations:import), keeping rows edited in the administration (is_manual = 1).

CREATE TABLE languages (
	id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
	code VARCHAR(5) NOT NULL COMMENT 'ISO 639-1 code, also the suffix of data/language_<code>.json',
	name VARCHAR(50) NOT NULL,
	source_hash CHAR(64) NULL COMMENT 'SHA-256 of the last imported data/language_<code>.json',
	imported_at DATETIME NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uq_languages_code (code)
) ENGINE=InnoDB;

CREATE TABLE translations (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	language_id SMALLINT UNSIGNED NOT NULL,
	`key` VARCHAR(191) NOT NULL,
	plurality TINYINT UNSIGNED NOT NULL COMMENT 'Plural form: 1, 2 (cs: 2-4, en: other), 5 (cs: other)',
	text TEXT NOT NULL,
	is_manual TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = edited in the administration, never overwritten or removed by the import',
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_translations_language_key_plurality (language_id, `key`, plurality),
	CONSTRAINT fk_translations_language FOREIGN KEY (language_id) REFERENCES languages (id) ON DELETE CASCADE
) ENGINE=InnoDB;
