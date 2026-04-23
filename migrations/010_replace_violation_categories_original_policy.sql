-- =====================================================================
-- Migration 010: Replace Active Violation Categories with Original Policy
-- Date: 2026-04-06
-- Description:
--   Deactivates currently active categories and inserts the original
--   policy category set requested by stakeholders.
--   Historical records remain intact because existing rows are not deleted.
--
-- NOTE ABOUT TYPE MAPPING:
--   Table enum supports: minor, major, grave.
--   UI mapping uses:
--     minor -> Minor
--     major -> Moderate
--     grave -> Major
-- =====================================================================

START TRANSACTION;

-- Deactivate current active master list (preserve rows for history joins).
UPDATE violation_categories
SET is_active = 0
WHERE is_active = 1;

-- ---------------------------------------------------------------------
-- MINOR OFFENSES (type = minor)
-- ---------------------------------------------------------------------
INSERT INTO violation_categories (name, type, description, default_sanction, article_reference, is_active) VALUES
('Violation of school''s uniform, general appearance, and hair grooming policy.', 'minor', 'Violation of school''s uniform, general appearance, and hair grooming policy.', NULL, NULL, 1),
('Littering', 'minor', 'Littering', NULL, NULL, 1),
('PDA or public display of affection', 'minor', 'PDA or public display of affection', NULL, NULL, 1),
('Inexcusable disturbance during classes and school functions', 'minor', 'Inexcusable disturbance during classes and school functions', NULL, NULL, 1),
('Unauthorized usage of gadgets during classes or spiritual activities.', 'minor', 'Unauthorized usage of gadgets during classes or spiritual activities.', NULL, NULL, 1),
('Violation of School ID policy', 'minor', 'Violation of School ID policy', NULL, NULL, 1),
('Playing of computer games, browsing of social media sites, or any unwarranted activities inside the computer laboratories', 'minor', 'Playing of computer games, browsing of social media sites, or any unwarranted activities inside the computer laboratories', NULL, NULL, 1);

-- ---------------------------------------------------------------------
-- MODERATE OFFENSES (stored as type = major; UI maps major -> Moderate)
-- ---------------------------------------------------------------------
INSERT INTO violation_categories (name, type, description, default_sanction, article_reference, is_active) VALUES
('Refusal to comply with disciplinary procedure, imposed interventions, instruction, counseling notices, and other matters relative thereto.', 'major', 'Refusal to comply with disciplinary procedure, imposed interventions, instruction, counseling notices, and other matters relative thereto.', NULL, NULL, 1),
('Entering school premises under the influence of alcohol.', 'major', 'Entering school premises under the influence of alcohol.', NULL, NULL, 1),
('Bringing gambling items inside the campus.', 'major', 'Bringing gambling items inside the campus.', NULL, NULL, 1),
('Vandalism or writing, removing, posting, or altering anything on a bulletin board, building wall, or any school property.', 'major', 'Vandalism or writing, removing, posting, or altering anything on a bulletin board, building wall, or any school property.', NULL, NULL, 1),
('Disorderly or immoral conduct or expression.', 'major', 'Disorderly or immoral conduct or expression.', NULL, NULL, 1),
('Gossiping or Intriguing against honor', 'major', 'Gossiping or Intriguing against honor', NULL, NULL, 1),
('Possession of pornographic materials inside the school either in printed or digital form.', 'major', 'Possession of pornographic materials inside the school either in printed or digital form.', NULL, NULL, 1),
('All forms of cheating inside the class (during quizzes, examinations, and other learning assessments).', 'major', 'All forms of cheating inside the class (during quizzes, examinations, and other learning assessments).', NULL, NULL, 1),
('Physical attempt to cause physical harm to any person inside the school premises.', 'major', 'Physical attempt to cause physical harm to any person inside the school premises.', NULL, NULL, 1),
('Possession of any smoking paraphernalia or alcohol inside the campus.', 'major', 'Possession of any smoking paraphernalia or alcohol inside the campus.', NULL, NULL, 1),
('Unauthorized usage and disposition of any of the school''s property and/or facility.', 'major', 'Unauthorized usage and disposition of any of the school''s property and/or facility.', NULL, NULL, 1),
('Repeated cutting classes and/or loitering during classes', 'major', 'Repeated cutting classes and/or loitering during classes', NULL, NULL, 1),
('Inexcusable utilization of unauthorized access to or from school premises.', 'major', 'Inexcusable utilization of unauthorized access to or from school premises.', NULL, NULL, 1),
('Non-disclosure of information that will compromise the health or safety of students.', 'major', 'Non-disclosure of information that will compromise the health or safety of students.', NULL, NULL, 1),
('Sabotage or Unauthorized Interference with the University''s Information and Communication Technology (ICT) Resources', 'major', 'Sabotage or Unauthorized Interference with the University''s Information and Communication Technology (ICT) Resources', NULL, NULL, 1),
('Involvement in fights and any form of violence inside the school premises or during school-related activities.', 'major', 'Involvement in fights and any form of violence inside the school premises or during school-related activities.', NULL, NULL, 1),
('Violation of school''s social media policy.', 'major', 'Violation of school''s social media policy.', NULL, NULL, 1);

-- ---------------------------------------------------------------------
-- MAJOR OFFENSES (stored as type = grave; UI maps grave -> Major)
-- ---------------------------------------------------------------------
INSERT INTO violation_categories (name, type, description, default_sanction, article_reference, is_active) VALUES
('Direct physical assault upon any student, faculty, staff, or administrator resulting in physical injury.', 'grave', 'Direct physical assault upon any student, faculty, staff, or administrator resulting in physical injury.', NULL, NULL, 1),
('Theft', 'grave', 'Theft', NULL, NULL, 1),
('Robbery', 'grave', 'Robbery', NULL, NULL, 1),
('Hazing', 'grave', 'Hazing', NULL, NULL, 1),
('Organization-related violence', 'grave', 'Organization-related violence', NULL, NULL, 1),
('Drug-related offense', 'grave', 'Drug-related offense', NULL, NULL, 1),
('Possession of deadly weapons, combustible, or explosive materials at school or to any recognized activity held outside of the school', 'grave', 'Possession of deadly weapons, combustible, or explosive materials at school or to any recognized activity held outside of the school', NULL, NULL, 1),
('Willful destruction of the school''s property or facility', 'grave', 'Willful destruction of the school''s property or facility', NULL, NULL, 1),
('Any form of forgery and falsification of faculty, staff, and administrator''s signature.', 'grave', 'Any form of forgery and falsification of faculty, staff, and administrator''s signature.', NULL, NULL, 1),
('Submission of fraudulent documents and/or falsification of documents', 'grave', 'Submission of fraudulent documents and/or falsification of documents', NULL, NULL, 1),
('Unlawful mass action and barricade', 'grave', 'Unlawful mass action and barricade', NULL, NULL, 1),
('Sexual harassment and any of its forms.', 'grave', 'Sexual harassment and any of its forms.', NULL, NULL, 1),
('Submission of someone else''s work in their own name (Complete plagiarism)', 'grave', 'Submission of someone else''s work in their own name (Complete plagiarism)', NULL, NULL, 1),
('Tampering of school records', 'grave', 'Tampering of school records', NULL, NULL, 1),
('Any form of misrepresentation that may cause loss or damage to the school.', 'grave', 'Any form of misrepresentation that may cause loss or damage to the school.', NULL, NULL, 1),
('Unjust vexation', 'grave', 'Unjust vexation', NULL, NULL, 1),
('Slanderous/Oral defamatory statement or accusation against any student or employee of the University.', 'grave', 'Slanderous/Oral defamatory statement or accusation against any student or employee of the University.', NULL, NULL, 1),
('Libelous statement or accusation by means of writings or similar means against any student or employee of the University (including cyber libel).', 'grave', 'Libelous statement or accusation by means of writings or similar means against any student or employee of the University (including cyber libel).', NULL, NULL, 1),
('Bullying or other forms of bullying (cyber-bullying, etc.).', 'grave', 'Bullying or other forms of bullying (cyber-bullying, etc.).', NULL, NULL, 1),
('Sexual intercourse while inside the school.', 'grave', 'Sexual intercourse while inside the school.', NULL, NULL, 1),
('Unauthorized collection of money in any transaction either personal or pertaining to the University or any of its departments, and recognized student councils, clubs, and organizations', 'grave', 'Unauthorized collection of money in any transaction either personal or pertaining to the University or any of its departments, and recognized student councils, clubs, and organizations', NULL, NULL, 1),
('Acts or publishing or circulating false and unfounded information that would malign the good name and reputation of the University, its officials, faculty, staff, and students', 'grave', 'Acts or publishing or circulating false and unfounded information that would malign the good name and reputation of the University, its officials, faculty, staff, and students', NULL, NULL, 1),
('Preventing and/or threatening any student or school personnel from entering school premises to attend their classes and/or discharge their duties', 'grave', 'Preventing and/or threatening any student or school personnel from entering school premises to attend their classes and/or discharge their duties', NULL, NULL, 1),
('Repeated willful violations of the school''s rules and regulations including the commission of a fourth minor offense.', 'grave', 'Repeated willful violations of the school''s rules and regulations including the commission of a fourth minor offense.', NULL, NULL, 1),
('Creating and/or joining unauthorized or illegal student organizations.', 'grave', 'Creating and/or joining unauthorized or illegal student organizations.', NULL, NULL, 1);

INSERT IGNORE INTO schema_migrations (migration_name, applied_by)
VALUES ('010_replace_violation_categories_original_policy', CURRENT_USER());

COMMIT;
