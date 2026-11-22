-- Alarm when the last scheduled database backup (bin/backup create) is older than 30 hours,
-- i.e. a daily backup was missed. An admin changes or turns it off in the administration.
INSERT INTO alarm_thresholds (kind, value) VALUES
	('backup_age', 30);
