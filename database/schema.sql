-- ============================================================
-- Smart Class Presence Log-in System v2
-- Database Schema
-- Monico Rarama National High School
-- ============================================================

CREATE DATABASE IF NOT EXISTS scpls_v2
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE scpls_v2;

-- ------------------------------------------------------------
-- 1. ROLES
-- ------------------------------------------------------------
CREATE TABLE roles (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(50) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. USERS — login accounts (admin, teacher, etc.)
-- ------------------------------------------------------------
CREATE TABLE users (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(50)  NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  full_name  VARCHAR(100) NOT NULL,
  email      VARCHAR(100) DEFAULT NULL,
  role_id    INT          NOT NULL,
  status     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_role FOREIGN KEY (role_id)
    REFERENCES roles(id) ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. TEACHERS — teacher profiles linked to users
-- ------------------------------------------------------------
CREATE TABLE teachers (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT          NOT NULL UNIQUE,
  employee_id VARCHAR(20)  DEFAULT NULL UNIQUE,
  first_name  VARCHAR(50)  NOT NULL,
  last_name   VARCHAR(50)  NOT NULL,
  status      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_teacher_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. SUBJECTS
-- ------------------------------------------------------------
CREATE TABLE subjects (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  code        VARCHAR(20)  DEFAULT NULL UNIQUE,
  description TEXT         DEFAULT NULL,
  status      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. SECTIONS — grade level + track/strand
-- ------------------------------------------------------------
CREATE TABLE sections (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(100) NOT NULL,
  grade_level  VARCHAR(20)  NOT NULL,
  track_strand VARCHAR(100) DEFAULT NULL,
  status       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6. SCHOOL YEARS
-- ------------------------------------------------------------
CREATE TABLE school_years (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(20) NOT NULL UNIQUE,
  start_date  DATE DEFAULT NULL,
  end_date    DATE DEFAULT NULL,
  is_current  TINYINT(1) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7. SEMESTERS
-- ------------------------------------------------------------
CREATE TABLE semesters (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  school_year_id INT         NOT NULL,
  name           VARCHAR(50) NOT NULL,
  start_date     DATE DEFAULT NULL,
  end_date       DATE DEFAULT NULL,
  is_current     TINYINT(1)  NOT NULL DEFAULT 0,
  created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_semester_sy FOREIGN KEY (school_year_id)
    REFERENCES school_years(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 8. STUDENTS — linked to section
-- ------------------------------------------------------------
CREATE TABLE students (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  student_id     VARCHAR(20) NOT NULL UNIQUE,
  first_name     VARCHAR(50) NOT NULL,
  last_name      VARCHAR(50) NOT NULL,
  grade_level    VARCHAR(20) DEFAULT NULL,
  section_id     INT         DEFAULT NULL,
  parent_name    VARCHAR(100) DEFAULT NULL,
  parent_contact VARCHAR(20)  DEFAULT NULL,
  parent_email   VARCHAR(100) DEFAULT NULL,
  status         TINYINT(1)  NOT NULL DEFAULT 1,
  created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_student_section FOREIGN KEY (section_id)
    REFERENCES sections(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 9. SCHEDULE ENTRIES — THE MASTER SCHEDULE
-- ------------------------------------------------------------
CREATE TABLE schedule_entries (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id  INT  NOT NULL,
  subject_id  INT  NOT NULL,
  section_id  INT  NOT NULL,
  semester_id INT  NOT NULL,
  day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL,
  start_time  TIME NOT NULL,
  end_time    TIME NOT NULL,
  room        VARCHAR(50) DEFAULT NULL,
  status      TINYINT(1)  NOT NULL DEFAULT 1,
  created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_schedule_teacher  FOREIGN KEY (teacher_id)
    REFERENCES teachers(id) ON UPDATE CASCADE,
  CONSTRAINT fk_schedule_subject  FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON UPDATE CASCADE,
  CONSTRAINT fk_schedule_section  FOREIGN KEY (section_id)
    REFERENCES sections(id) ON UPDATE CASCADE,
  CONSTRAINT fk_schedule_semester FOREIGN KEY (semester_id)
    REFERENCES semesters(id) ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 10. ATTENDANCE SESSIONS
-- ------------------------------------------------------------
CREATE TABLE attendance_sessions (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  schedule_entry_id INT       NOT NULL,
  teacher_id        INT       NOT NULL,
  attendance_date   DATE      NOT NULL,
  started_at        DATETIME  NOT NULL,
  ended_at          DATETIME  DEFAULT NULL,
  status            ENUM('active','closed') NOT NULL DEFAULT 'active',
  total_present     INT       NOT NULL DEFAULT 0,
  total_late        INT       NOT NULL DEFAULT 0,
  total_absent      INT       NOT NULL DEFAULT 0,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_session_schedule FOREIGN KEY (schedule_entry_id)
    REFERENCES schedule_entries(id) ON UPDATE CASCADE,
  CONSTRAINT fk_session_teacher  FOREIGN KEY (teacher_id)
    REFERENCES teachers(id) ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 11. ATTENDANCE RECORDS
-- ------------------------------------------------------------
CREATE TABLE attendance_records (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  attendance_session_id INT            NOT NULL,
  student_id            INT            NOT NULL,
  status                ENUM('present','late','absent') NOT NULL DEFAULT 'present',
  timestamp             DATETIME       NOT NULL,
  created_at            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_record_session FOREIGN KEY (attendance_session_id)
    REFERENCES attendance_sessions(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_record_student FOREIGN KEY (student_id)
    REFERENCES students(id) ON UPDATE CASCADE,
  UNIQUE KEY uq_session_student (attendance_session_id, student_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 12. NOTIFICATION LOGS — parent emails, at most one of each type per student
--     per day: arrival (morning_in), dismissal (afternoon_out), or absent.
-- ------------------------------------------------------------
CREATE TABLE sms_logs (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  attendance_record_id  INT          DEFAULT NULL,
  student_id            INT          NOT NULL,
  sms_date              DATE         NOT NULL,
  type                  ENUM('morning_in','afternoon_out','absent','cutting') NOT NULL DEFAULT 'morning_in',
  channel               ENUM('sms','email') NOT NULL DEFAULT 'email',
  dedup_key             INT          NOT NULL DEFAULT 0, -- 0 for daily types; attendance_session id for 'cutting'
  parent_contact        VARCHAR(20)  DEFAULT NULL,
  email_to              VARCHAR(100) DEFAULT NULL,
  message               TEXT         DEFAULT NULL,
  status                ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  sent_at               TIMESTAMP    NULL DEFAULT NULL,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sms_record FOREIGN KEY (attendance_record_id)
    REFERENCES attendance_records(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_sms_student FOREIGN KEY (student_id)
    REFERENCES students(id) ON UPDATE CASCADE,
  UNIQUE KEY uq_notif_student_type_day_channel (student_id, type, sms_date, channel, dedup_key)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 13. SETTINGS — small key/value store (e.g. background-task timestamps)
-- ------------------------------------------------------------
CREATE TABLE settings (
  skey       VARCHAR(64)  NOT NULL PRIMARY KEY,
  svalue     TEXT         DEFAULT NULL,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 14. AUDIT LOGS
-- ------------------------------------------------------------
CREATE TABLE audit_logs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT          DEFAULT NULL,
  action      VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50)  DEFAULT NULL,
  entity_id   INT          DEFAULT NULL,
  old_values  JSON         DEFAULT NULL,
  new_values  JSON         DEFAULT NULL,
  ip_address  VARCHAR(45)  DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- INDEXES
-- ============================================================
CREATE INDEX idx_student_grade    ON students(grade_level);
CREATE INDEX idx_student_section  ON students(section_id);
CREATE INDEX idx_session_date     ON attendance_sessions(attendance_date);
CREATE INDEX idx_session_status   ON attendance_sessions(status);
CREATE INDEX idx_record_timestamp ON attendance_records(timestamp);
CREATE INDEX idx_record_status    ON attendance_records(status);
CREATE INDEX idx_sms_status       ON sms_logs(status);
CREATE INDEX idx_audit_user       ON audit_logs(user_id);
CREATE INDEX idx_audit_action     ON audit_logs(action);
CREATE INDEX idx_schedule_day     ON schedule_entries(day_of_week);
CREATE INDEX idx_schedule_teacher ON schedule_entries(teacher_id);
