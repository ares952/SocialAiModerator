-- Initial alarm thresholds; an admin changes them in the administration (Upozornění).
INSERT INTO alarm_thresholds (kind, value) VALUES
	('token_expiry', 7),
	('webhook_silence', 48),
	('queue_lag', 15),
	('failures', 3),
	('hidden_comments', 20),
	('stale_approvals', 24);
