-- Circuit breakers shared by all workers (specification §8.1): after repeated transient failures
-- of an external API the breaker opens and calls are postponed until opened_until.

CREATE TABLE circuit_breakers (
	name VARCHAR(50) NOT NULL COMMENT 'External API, e.g. openai',
	failures INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Consecutive transient failures',
	opened_until DATETIME(3) NULL COMMENT 'Calls are postponed until this time',
	updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	PRIMARY KEY (name)
) ENGINE=InnoDB;
