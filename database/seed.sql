-- ============================================================
-- Seed Data for SCPLS v2
-- ============================================================

USE scpls_v2;

-- ------------------------------------------------------------
-- Roles
-- ------------------------------------------------------------
INSERT INTO roles (name) VALUES
('admin'),
('teacher');

-- ------------------------------------------------------------
-- Users (passwords hashed with bcrypt)
-- admin   / admin123
-- teacher1/ teacher123
-- teacher2/ teacher123
-- ------------------------------------------------------------
INSERT INTO users (username, password, full_name, email, role_id, status) VALUES
('admin',    '$2y$10$9Fg7cvrp1qz9cF2SRXa1euvKXX7fskqn/ibemDyCWxO9k63vMjh7K', 'System Administrator', 'admin@mnrhs.edu.ph',    1, 1),
('teacher1', '$2y$10$HSC4fy0NXYiHrgHyBwQOUeZXOI.YWYYtWQyvEC8V5XZVw4YOsIhra', 'Juan Dela Cruz',       'juan.delacruz@mnrhs.edu.ph', 2, 1),
('teacher2', '$2y$10$HSC4fy0NXYiHrgHyBwQOUeZXOI.YWYYtWQyvEC8V5XZVw4YOsIhra', 'Maria Santos',         'maria.santos@mnrhs.edu.ph',  2, 1);

-- ------------------------------------------------------------
-- Teachers
-- ------------------------------------------------------------
INSERT INTO teachers (user_id, employee_id, first_name, last_name) VALUES
(2, 'TCH-2026-001', 'Juan',  'Dela Cruz'),
(3, 'TCH-2026-002', 'Maria', 'Santos');

-- ------------------------------------------------------------
-- Subjects
-- ------------------------------------------------------------
INSERT INTO subjects (name, code, description) VALUES
('ICT',               'ICT101', 'Information and Communications Technology'),
('English',           'ENG101', 'English Language Arts'),
('Mathematics',       'MATH101','General Mathematics'),
('Science',           'SCI101', 'Earth and Life Science'),
('Filipino',          'FIL101', 'Komunikasyon at Pananaliksik sa Wika at Kulturang Pilipino'),
('Araling Panlipunan','AP101',  'Understanding Culture, Society and Politics'),
('Physical Education','PE101',  'Physical Education and Health');

-- ------------------------------------------------------------
-- Sections
-- ------------------------------------------------------------
INSERT INTO sections (name, grade_level, track_strand) VALUES
('Grade 11 - STEM',   'Grade 11', 'STEM'),
('Grade 11 - ABM',    'Grade 11', 'ABM'),
('Grade 11 - HUMSS',  'Grade 11', 'HUMSS'),
('Grade 12 - STEM',   'Grade 12', 'STEM'),
('Grade 12 - ABM',    'Grade 12', 'ABM'),
('Grade 12 - HUMSS',  'Grade 12', 'HUMSS');

-- ------------------------------------------------------------
-- School Year & Semester
-- ------------------------------------------------------------
INSERT INTO school_years (name, start_date, end_date, is_current) VALUES
('2026-2027', '2026-06-01', '2027-03-31', 1);

INSERT INTO semesters (school_year_id, name, start_date, end_date, is_current) VALUES
(1, '1st Semester', '2026-06-01', '2026-10-31', 1),
(1, '2nd Semester', '2026-11-01', '2027-03-31', 0);

-- ------------------------------------------------------------
-- Students (from old system + new)
-- ------------------------------------------------------------
INSERT INTO students (student_id, first_name, last_name, grade_level, section_id, parent_name, parent_contact, parent_email) VALUES
('SHS-2026-001', 'Maria',  'Santos',     'Grade 11', 3, 'Luzviminda Santos',   '09171234567', 'luzviminda.santos@email.com'),
('SHS-2026-002', 'Jose',   'Reyes',      'Grade 11', 3, 'Pedro Reyes',         '09171234568', 'pedro.reyes@email.com'),
('SHS-2026-003', 'Ana',    'Gonzales',   'Grade 11', 3, 'Carmen Gonzales',     '09171234569', 'carmen.gonzales@email.com'),
('SHS-2026-004', 'Carlos', 'Mendoza',    'Grade 12', 5, 'Rosa Mendoza',        '09171234570', 'rosa.mendoza@email.com'),
('SHS-2026-005', 'Bella',  'Fernandez',  'Grade 12', 5, 'Manuel Fernandez',    '09171234571', 'manuel.fernandez@email.com'),
('SHS-2026-006', 'David',  'Villanueva', 'Grade 11', 3, 'Teresa Villanueva',   '09171234572', 'teresa.villanueva@email.com'),
('SHS-2026-007', 'Ellen',  'Aquino',     'Grade 12', 5, 'Ramon Aquino',        '09171234573', 'ramon.aquino@email.com'),
('SHS-2026-008', 'Frank',  'Luna',       'Grade 11', 2, 'Sofia Luna',          '09171234574', 'sofia.luna@email.com'),
('SHS-2026-009', 'Grace',  'Magsaysay',  'Grade 12', 6, 'Benigno Magsaysay',   '09171234575', 'benigno.magsaysay@email.com'),
('SHS-2026-010', 'Henry',  'Garcia',     'Grade 12', 4, 'Luz Garcia',          '09171234576', 'luz.garcia@email.com'),
('SHS-2026-011', 'Isabel', 'Torres',     'Grade 12', 4, 'Antonio Torres',      '09171234577', 'antonio.torres@email.com'),
('SHS-2026-012', 'Kevin',  'Ramos',      'Grade 12', 4, 'Elena Ramos',         '09171234578', 'elena.ramos@email.com');

-- ------------------------------------------------------------
-- Schedule Entries (Master Schedule)
-- Teacher 1 (Juan Dela Cruz) - ICT
-- ------------------------------------------------------------
INSERT INTO schedule_entries (teacher_id, subject_id, section_id, semester_id, day_of_week, start_time, end_time, room) VALUES
(1, 1, 3, 1, 'Monday',    '08:00:00', '09:00:00', 'RM 201'),
(1, 1, 2, 1, 'Monday',    '09:00:00', '11:00:00', 'RM 201'),
(1, 1, 3, 1, 'Tuesday',   '08:00:00', '09:00:00', 'RM 201'),
(1, 1, 2, 1, 'Wednesday', '09:00:00', '11:00:00', 'RM 201'),
(1, 1, 4, 1, 'Thursday',  '10:00:00', '12:00:00', 'COMP LAB'),
(1, 1, 4, 1, 'Friday',    '10:00:00', '11:00:00', 'COMP LAB');

-- Teacher 2 (Maria Santos) - English
INSERT INTO schedule_entries (teacher_id, subject_id, section_id, semester_id, day_of_week, start_time, end_time, room) VALUES
(2, 2, 4, 1, 'Monday',    '08:00:00', '09:00:00', 'RM 102'),
(2, 2, 5, 1, 'Monday',    '09:00:00', '10:00:00', 'RM 102'),
(2, 2, 4, 1, 'Tuesday',   '08:00:00', '09:00:00', 'RM 102'),
(2, 2, 5, 1, 'Wednesday', '11:00:00', '12:00:00', 'RM 102'),
(2, 2, 6, 1, 'Thursday',  '13:00:00', '15:00:00', 'RM 102'),
(2, 2, 6, 1, 'Friday',    '08:00:00', '10:00:00', 'RM 102');
