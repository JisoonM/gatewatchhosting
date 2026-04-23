-- =====================================================================
-- Migration: Comprehensive Student Violation Tracking System
-- Date: 2026-03-18
-- Description: Creates violation_categories lookup table, student_violations
--              audit-log table, and adds active_violations_count to users.
--              Pre-seeds violation categories from the student handbook
--              (Article 11 & 12 offenses).
-- =====================================================================

-- 1. Violation Categories Reference Table
CREATE TABLE IF NOT EXISTS violation_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type ENUM('minor', 'major', 'grave') NOT NULL,
    description TEXT NULL,
    default_sanction VARCHAR(255) NULL,
    article_reference VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Student Violations Audit Log Table (records are never deleted)
CREATE TABLE IF NOT EXISTS student_violations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    description TEXT NULL,
    offense_number INT NOT NULL DEFAULT 1,
    status ENUM('active', 'pending_reparation', 'apprehended') NOT NULL DEFAULT 'active',
    reparation_type VARCHAR(100) NULL,
    reparation_notes TEXT NULL,
    reparation_completed_at DATETIME NULL,
    resolved_by INT NULL,
    recorded_by INT NULL,
    school_year VARCHAR(20) NOT NULL,
    semester ENUM('1st', '2nd', 'summer') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES violation_categories(id),
    INDEX idx_user_category_status (user_id, category_id, status),
    INDEX idx_user_school_year (user_id, school_year),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Add active_violations_count to users table (if not already present)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'users' 
                   AND COLUMN_NAME = 'active_violations_count');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN active_violations_count INT NOT NULL DEFAULT 0',
    'SELECT "active_violations_count column already exists"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
-- 4. Seed Violation Categories from Student Handbook (Article 12)
-- =====================================================================

-- MINOR OFFENSES
INSERT INTO violation_categories (name, type, description, default_sanction, article_reference) VALUES
('No Physical ID', 'minor', 'Student entered school premises without carrying their physical student ID card.', 'Verbal Warning / Written Apology', 'Article 12, Section 1.a'),
('Improper Wearing of ID/Uniform', 'minor', 'Failure to wear the prescribed school uniform or student ID properly while on campus.', 'Verbal Warning', 'Article 12, Section 1.b'),
('Littering', 'minor', 'Throwing of trash or refuse in areas not designated as waste disposal areas.', 'Community Service', 'Article 12, Section 1.c'),
('Loitering in Restricted Areas', 'minor', 'Being in off-limits or restricted areas of the campus without authorization.', 'Verbal Warning', 'Article 12, Section 1.d'),
('Creating Noise or Disturbance', 'minor', 'Causing unnecessary noise or disturbance that disrupts classes or school activities.', 'Written Apology', 'Article 12, Section 1.e'),
('Unauthorized Posting', 'minor', 'Posting or distributing materials on campus without proper authorization from the administration.', 'Confiscation / Written Apology', 'Article 12, Section 1.f'),
('Eating in Restricted Areas', 'minor', 'Eating or drinking in classrooms, laboratories, or other restricted areas.', 'Verbal Warning', 'Article 12, Section 1.g'),
('Minor Discourtesy', 'minor', 'Minor acts of discourtesy or impoliteness toward fellow students, faculty, or staff.', 'Written Apology', 'Article 12, Section 1.h')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- MAJOR OFFENSES
INSERT INTO violation_categories (name, type, description, default_sanction, article_reference) VALUES
('Cutting Classes', 'major', 'Absence from scheduled classes without valid reason or prior approval from the instructor.', 'Conference with Parents / Suspension', 'Article 12, Section 2.a'),
('Disrespectful Behavior', 'major', 'Disrespectful or defiant behavior toward faculty members, administrators, or staff.', 'Suspension / Conference', 'Article 12, Section 2.b'),
('Smoking on Campus', 'major', 'Smoking or vaping within the school premises including buildings and grounds.', 'Suspension', 'Article 12, Section 2.c'),
('Gambling', 'major', 'Engaging in any form of gambling within the school premises.', 'Suspension / Community Service', 'Article 12, Section 2.d'),
('Unauthorized Use of Facilities', 'major', 'Using school facilities, equipment, or property without proper authorization.', 'Suspension / Restitution', 'Article 12, Section 2.e'),
('Falsification of Documents', 'major', 'Falsifying, altering, or misusing school documents, records, or identification.', 'Suspension / Possible Expulsion', 'Article 12, Section 2.f'),
('Bullying or Harassment', 'major', 'Engaging in bullying, intimidation, or harassment of any member of the school community.', 'Suspension / Counseling', 'Article 12, Section 2.g'),
('Cheating in Examinations', 'major', 'Cheating, using unauthorized materials, or copying during examinations or academic assessments.', 'Automatic Failure / Suspension', 'Article 12, Section 2.h'),
('Unauthorized Solicitation', 'major', 'Soliciting funds, selling merchandise, or conducting commercial activities without school approval.', 'Confiscation / Suspension', 'Article 12, Section 2.i'),
('Disruption of School Activities', 'major', 'Deliberately disrupting official school programs, activities, or ceremonies.', 'Suspension', 'Article 12, Section 2.j')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- GRAVE OFFENSES (may lead to expulsion as stated in student handbook)
INSERT INTO violation_categories (name, type, description, default_sanction, article_reference) VALUES
('Physical Assault', 'grave', 'Inflicting physical harm or bodily injury on any member of the school community.', 'Expulsion', 'Article 12, Section 3.a'),
('Possession of Illegal Drugs', 'grave', 'Possession, use, sale, or distribution of illegal drugs or controlled substances on campus.', 'Expulsion / Legal Action', 'Article 12, Section 3.b'),
('Theft or Robbery', 'grave', 'Stealing or attempting to steal property belonging to the school, students, faculty, or staff.', 'Expulsion / Legal Action', 'Article 12, Section 3.c'),
('Possession of Deadly Weapons', 'grave', 'Bringing or possessing firearms, knives, or any deadly weapon within school premises.', 'Expulsion / Legal Action', 'Article 12, Section 3.d'),
('Sexual Harassment or Assault', 'grave', 'Any form of sexual harassment, sexual assault, or acts of lasciviousness against any person on campus.', 'Expulsion / Legal Action', 'Article 12, Section 3.e'),
('Vandalism', 'grave', 'Willful damage, destruction, or defacement of school property, facilities, or equipment.', 'Expulsion / Restitution', 'Article 12, Section 3.f'),
('Hazing', 'grave', 'Planning, organizing, or participating in hazing activities in any form, as prohibited by R.A. 8049.', 'Expulsion / Legal Action', 'Article 12, Section 3.g'),
('Arson', 'grave', 'Deliberately setting fire to, or attempting to burn, school property or facilities.', 'Expulsion / Legal Action', 'Article 12, Section 3.h'),
('Threatening or Intimidating Behavior', 'grave', 'Making threats of violence, intimidation, or coercion against any member of the school community.', 'Suspension / Expulsion', 'Article 12, Section 3.i'),
('Forgery or Fraud', 'grave', 'Committing forgery or fraud involving school documents, credentials, or academic records.', 'Expulsion', 'Article 12, Section 3.j'),
('Involvement in Illegal Activities', 'grave', 'Engaging in illegal activities within or outside the campus that bring disrepute to the institution.', 'Expulsion / Legal Action', 'Article 12, Section 3.k'),
('Gross Misconduct', 'grave', 'Any act of gross misconduct or moral turpitude that brings serious harm or dishonor to the university.', 'Expulsion', 'Article 12, Section 3.l')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- =====================================================================
-- 5. Sync active_violations_count with any pre-existing student_violations
--    (safe to run on fresh install or re-run)
-- =====================================================================
UPDATE users u
LEFT JOIN (
    SELECT user_id, COUNT(*) as active_count
    FROM student_violations
    WHERE status = 'active'
    GROUP BY user_id
) sv ON u.id = sv.user_id
SET u.active_violations_count = COALESCE(sv.active_count, 0);
