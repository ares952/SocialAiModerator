-- Review queue (specification §4.3): an event that needs a human stays open until an operator
-- resolves it; it can be handed over to a colleague. Interventions waiting for approval keep their
-- own state in moderation_actions.

ALTER TABLE social_events
	ADD COLUMN resolved_at DATETIME(3) NULL COMMENT 'an operator handled the event; NULL = open in the review queue' AFTER last_error,
	ADD COLUMN resolved_by INT UNSIGNED NULL AFTER resolved_at,
	ADD COLUMN assignee_id INT UNSIGNED NULL COMMENT 'operator the event was handed over to' AFTER resolved_by,
	ADD KEY ix_social_events_queue (status, resolved_at, id),
	ADD KEY ix_social_events_assignee (assignee_id),
	ADD CONSTRAINT fk_social_events_resolved_by FOREIGN KEY (resolved_by) REFERENCES operators (id),
	ADD CONSTRAINT fk_social_events_assignee FOREIGN KEY (assignee_id) REFERENCES operators (id);
