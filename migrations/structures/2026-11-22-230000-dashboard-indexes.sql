-- Dashboard charts filter decisions and interventions by creation time over up to 60 days
-- (the chosen period and the one before it).

ALTER TABLE moderation_decisions
	ADD KEY ix_moderation_decisions_created (created_at);

ALTER TABLE moderation_actions
	ADD KEY ix_moderation_actions_created (created_at);
