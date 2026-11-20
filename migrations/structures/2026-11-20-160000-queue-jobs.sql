-- DB-backed job queue (no Redis in v1). Workers reserve a job in a transaction with
-- SELECT ... FOR UPDATE SKIP LOCKED, so no job runs twice in parallel. A reservation expires at
-- reserved_until (crashed worker); the job is then reserved again.

CREATE TABLE queue_jobs (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	type VARCHAR(50) NOT NULL COMMENT 'App\\Queue\\JobType',
	subject_id BIGINT UNSIGNED NOT NULL COMMENT 'Row the job works on, by type (event.process: social_events.id)',
	status VARCHAR(20) NOT NULL COMMENT 'App\\Queue\\JobStatus',
	attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	available_at DATETIME(3) NOT NULL COMMENT 'Not reserved before this time (retry backoff)',
	reserved_until DATETIME(3) NULL COMMENT 'Reservation lease of the worker',
	reserved_by VARCHAR(100) NULL COMMENT 'host:pid of the worker',
	last_error VARCHAR(1000) NULL COMMENT 'Exception class and message, never customer content',
	created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	PRIMARY KEY (id),
	KEY ix_queue_jobs_status_available (status, available_at),
	KEY ix_queue_jobs_type_subject (type, subject_id)
) ENGINE=InnoDB;

-- Events stored by 0.2.0 before the queue existed. UTC_TIMESTAMP: the migration runner uses the
-- SYSTEM time zone, the application UTC.
INSERT INTO queue_jobs (type, subject_id, status, available_at, created_at)
SELECT 'event.process', id, 'pending', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
FROM social_events
WHERE status = 'received';
