-- ============================================================
-- Migration: email-only parent notifications + absentee alerts
-- Date: 2026-09-02
-- Apply to EXISTING live databases (scpls.xo.je / if0_42526287_scpls).
-- Fresh installs get this from schema.sql — do NOT re-run setup.php,
-- it drops and reseeds everything.
-- ============================================================

-- 1. Add the 'absent' notification type.
ALTER TABLE sms_logs
  MODIFY type ENUM('morning_in','afternoon_out','absent') NOT NULL DEFAULT 'morning_in';

-- 2. Default the channel to email (SMS channel retired).
ALTER TABLE sms_logs
  MODIFY channel ENUM('sms','email') NOT NULL DEFAULT 'email';

-- 3. Key/value store for background-task throttling (absentee sweep timestamp).
--    The app also self-heals this via CREATE TABLE IF NOT EXISTS, but create it
--    here so the first request doesn't have to.
CREATE TABLE IF NOT EXISTS settings (
  skey       VARCHAR(64) NOT NULL PRIMARY KEY,
  svalue     TEXT        DEFAULT NULL,
  updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Legacy channel='sms' rows are left as history; nothing reads them.

-- ============================================================
-- DEPLOY VERIFICATION (do these on the live box after syncing files)
-- ============================================================
-- 1. Confirm SCRIPT_NAME contains '/api/' for the scan endpoint on this host.
--    The CSRF exemption + error-format switch key off it. On InfinityFree
--    (LiteSpeed) it should be '/api/scan.php'; if a rewrite changes it, the
--    exemption inverts. Quick check: temporarily add error_log($_SERVER['SCRIPT_NAME'])
--    to bootstrap.php, hit /api/scan.php and an admin page, read the log, remove it.
-- 2. config/app.php: set 'debug' => false (already the default) so debug_time
--    stays disabled in production.
-- 3. Set a fresh mail.task_key and wire an InfinityFree cron to
--    GET /tasks/notifications.php?key=<that>&task=all  (daily is fine — it is a
--    backstop; the in-app tick handles the rest during school hours).
-- 4. Smoke test: log in as admin + teacher1, take + close one attendance session,
--    confirm the closed session shows present/late/absent counts and reports
--    lists the absentees.
