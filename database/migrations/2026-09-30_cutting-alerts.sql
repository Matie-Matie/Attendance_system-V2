-- Cutting-class email alerts (per missed class).
-- NOT REQUIRED to run by hand: bootstrap's notify_ensure_schema() applies this
-- automatically (schema_version 3) on the first request after deploy.
-- Run manually in phpMyAdmin only if you prefer to. Skip a step that already exists.
ALTER TABLE sms_logs
  MODIFY type ENUM('morning_in','afternoon_out','absent','cutting') NOT NULL DEFAULT 'morning_in',
  ADD COLUMN dedup_key INT NOT NULL DEFAULT 0 AFTER channel,
  DROP INDEX uq_notif_student_type_day_channel,
  ADD UNIQUE KEY uq_notif_student_type_day_channel (student_id, type, sms_date, channel, dedup_key);
