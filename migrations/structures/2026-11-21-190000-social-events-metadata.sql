-- Metadata of incoming events filled by the worker from App\Meta\EventNormalizer (R9 in the
-- Meta webhook brief, specification §3.3 and §6). Customer content stays in payload_encrypted.
-- NULL event_type = not normalized yet (bin/console events:normalize fills older events).

ALTER TABLE social_events
	ADD COLUMN event_type VARCHAR(30) NULL COMMENT 'App\\Meta\\EventType' AFTER topic,
	ADD COLUMN external_id VARCHAR(255) NULL COMMENT 'comment ID or message mid with the platform prefix' AFTER event_type,
	ADD COLUMN occurred_at DATETIME(3) NULL COMMENT 'UTC time of the change according to Meta' AFTER external_id,
	ADD COLUMN last_error VARCHAR(500) NULL COMMENT 'why the event is unsupported; never customer content' AFTER attempts,
	ADD KEY ix_social_events_type (event_type, id),
	ADD KEY ix_social_events_external (platform, external_id);
