# PCU RFID2 — Data Dictionary

> Auto-generated: 2026-03-25 | Schema version: Migration 007

---

## Table of Contents

1. [Schema Governance](#schema-governance)
2. [Identity & Authentication](#identity--authentication)
3. [RFID Access Control](#rfid-access-control)
4. [Face Recognition](#face-recognition)
5. [Violations](#violations)
6. [Guardian Notifications](#guardian-notifications)
7. [Audit & Logging](#audit--logging)
8. [Security / QR Gate](#security--qr-gate)
9. [Rate Limiting](#rate-limiting)
10. [System Configuration](#system-configuration)
11. [Views](#views)
12. [Triggers](#triggers)
13. [Delete Behavior Matrix](#delete-behavior-matrix)
14. [Denormalized Fields](#denormalized-fields)

---

## Schema Governance

### `schema_migrations`
Tracks all applied database migrations to prevent re-execution and detect drift.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT UNSIGNED | NO | AUTO_INCREMENT | PK |
| migration_name | VARCHAR(255) | NO | — | Unique migration identifier |
| applied_at | TIMESTAMP | NO | CURRENT_TIMESTAMP | When migration was applied |
| checksum | CHAR(64) | YES | NULL | SHA-256 of migration file |
| execution_time_ms | INT UNSIGNED | YES | NULL | Execution duration |
| applied_by | VARCHAR(100) | YES | NULL | User/script that ran it |

---

## Identity & Authentication

### `users`
Central hub table for all system users (students and admins).

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| student_id | VARCHAR(20) | NO | — | University student ID (UK) |
| name | VARCHAR(100) | NO | — | Full name |
| email | VARCHAR(100) | NO | — | Email address (UK) |
| course | VARCHAR(255) | YES | NULL | Degree program |
| google_id | VARCHAR(255) | YES | NULL | Google OAuth ID (UK) |
| password | VARCHAR(255) | NO | — | Bcrypt hash |
| role | ENUM | NO | 'Student' | Admin \| Student |
| status | ENUM | NO | 'Pending' | Pending \| Active \| Locked |
| locked_until | DATETIME | YES | NULL | Account lockout expiry |
| failed_attempts | INT | NO | 0 | Login failure counter (≥0) |
| last_login | DATETIME | YES | NULL | Last successful login |
| created_at | TIMESTAMP | NO | CURRENT_TIMESTAMP | Registration time |
| updated_at | TIMESTAMP | NO | CURRENT_TIMESTAMP | Last modification |
| profile_picture | VARCHAR(255) | YES | NULL | **Derived**: synced from student_profiles via trigger |
| profile_picture_uploaded_at | DATETIME | YES | NULL | **Derived**: synced from student_profiles via trigger |
| rfid_uid | VARCHAR(50) | YES | NULL | **Derived**: synced from rfid_cards via trigger (UK) |
| rfid_registered_at | TIMESTAMP | YES | NULL | **Derived**: synced from rfid_cards via trigger |
| violation_count | INT | NO | 0 | **Derived**: COUNT(*) of violations table via trigger |
| active_violations_count | INT | NO | 0 | **Derived**: COUNT(*) of active student_violations via trigger |
| gate_mark_count | INT | NO | 0 | Security gate flag counter |
| face_registered | TINYINT(1) | NO | 0 | Has face biometric registered |
| face_registered_at | TIMESTAMP | YES | NULL | When face was registered |
| deleted_at | DATETIME | YES | NULL | Soft-delete timestamp (NULL = active) |

**Indexes**: student_id (UK), email (UK), rfid_uid (UK), google_id (UK), role, status, rfid_lookup composite, violations composite, face_lookup composite, gate_mark, deleted_at, role+status, role+status+deleted_at

### `auth_providers`
Available authentication providers (Google, manual, etc.).

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| provider_name | VARCHAR(50) | NO | — | Provider identifier (UK) |
| is_enabled | TINYINT(1) | NO | 1 | Whether provider is active |
| is_primary | TINYINT(1) | NO | 0 | Whether provider is default |
| created_at | TIMESTAMP | NO | CURRENT_TIMESTAMP | — |
| updated_at | TIMESTAMP | NO | CURRENT_TIMESTAMP | Auto-updated |

**Seed data**: `google` (enabled, primary), `manual` (disabled)

### `user_auth_methods`
Junction: links users to their authentication providers.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| user_id | INT | NO | — | FK → users.id (CASCADE) |
| provider_id | INT | NO | — | FK → auth_providers.id (CASCADE) |
| provider_user_id | VARCHAR(255) | YES | NULL | External provider ID |
| is_primary_method | TINYINT(1) | NO | 0 | Primary auth method flag |
| first_used_at | TIMESTAMP | NO | CURRENT_TIMESTAMP | First authentication |
| last_used_at | TIMESTAMP | YES | NULL | Most recent authentication |
| use_count | INT | NO | 0 | Usage counter |

### `password_resets`
One-time password reset tokens with expiry.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| user_id | INT | NO | — | FK → users.id (CASCADE) |
| token | VARCHAR(64) | NO | — | Reset token (UK) |
| expires_at | DATETIME | NO | — | Token expiry |
| used | TINYINT(1) | NO | 0 | Whether token was consumed |
| created_at | TIMESTAMP | NO | CURRENT_TIMESTAMP | — |

### `student_profiles`
Extended profile data for students (1:1 with users).

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| user_id | INT | NO | — | FK → users.id (CASCADE, UK) |
| profile_picture | VARCHAR(255) | YES | NULL | Filename |
| profile_picture_uploaded_at | DATETIME | YES | NULL | Upload timestamp |
| profile_picture_size | INT | YES | NULL | File size in bytes |
| profile_picture_mime_type | VARCHAR(50) | YES | NULL | MIME type |
| bio | TEXT | YES | NULL | Student bio |
| phone | VARCHAR(20) | YES | NULL | Phone number |
| emergency_contact | VARCHAR(100) | YES | NULL | Emergency contact name |
| emergency_phone | VARCHAR(20) | YES | NULL | Emergency contact phone |

---

## RFID Access Control

### `rfid_cards`
Physical RFID card registrations with lost/found state machine.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| user_id | INT | NO | — | FK → users.id (CASCADE) |
| rfid_uid | VARCHAR(50) | NO | — | Card UID (UK) |
| registered_at | TIMESTAMP | NO | CURRENT_TIMESTAMP | Registration time |
| unregistered_at | TIMESTAMP | YES | NULL | Deactivation time |
| is_active | TINYINT(1) | NO | 1 | Active flag |
| registered_by | INT | YES | NULL | FK → users.id (SET NULL) |
| unregistered_by | INT | YES | NULL | FK → users.id (SET NULL) |
| notes | TEXT | YES | NULL | Admin notes |
| is_lost | TINYINT(1) | NO | 0 | Lost flag |
| lost_at | DATETIME | YES | NULL | When marked lost |
| lost_reason | VARCHAR(255) | YES | NULL | Reason |
| lost_reported_by | INT | YES | NULL | FK → users.id (SET NULL) |
| found_at | DATETIME | YES | NULL | When found |
| found_by | INT | YES | NULL | FK → users.id (SET NULL) |

**Triggers**: `after_rfid_insert` syncs rfid_uid to users, `after_rfid_update` clears on deactivation

### `rfid_status_history`
Immutable audit trail for RFID card state transitions.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| rfid_card_id | INT | NO | — | FK → rfid_cards.id (CASCADE) |
| user_id | INT | NO | — | FK → users.id (**RESTRICT**) |
| status_change | VARCHAR(50) | NO | — | LOST \| FOUND \| REGISTERED \| UNREGISTERED |
| changed_at | TIMESTAMP | NO | CURRENT_TIMESTAMP | — |
| changed_by | INT | YES | NULL | FK → users.id (SET NULL) |
| reason | TEXT | YES | NULL | — |
| notes | TEXT | YES | NULL | — |
| ip_address | VARCHAR(45) | YES | NULL | — |

---

## Face Recognition

### `face_descriptors`
Encrypted face biometric descriptors for identity verification.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| user_id | INT | NO | — | FK → users.id (CASCADE) |
| descriptor_data | TEXT | NO | — | AES-encrypted descriptor |
| descriptor_iv | VARCHAR(48) | NO | — | Initialization vector |
| descriptor_tag | VARCHAR(48) | NO | — | Authentication tag |
| label | VARCHAR(100) | YES | NULL | Angle label (front/left/right/down) |
| quality_score | FLOAT | YES | NULL | 0.0–1.0 quality metric |
| registered_by | INT | YES | NULL | FK → users.id (SET NULL) |
| is_active | TINYINT(1) | NO | 1 | Active flag |

### `face_entry_logs`
Immutable log of face recognition gate events. **ON DELETE RESTRICT** — cannot delete user while logs exist.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| user_id | INT | NO | — | FK → users.id (**RESTRICT**) |
| confidence_score | FLOAT | NO | — | Match confidence 0.0–1.0 |
| match_threshold | FLOAT | NO | — | Required threshold 0.0–1.0 |
| gate_location | VARCHAR(100) | YES | NULL | Gate identifier |
| security_guard_id | INT | YES | NULL | FK → users.id (SET NULL) |
| entry_type | ENUM | NO | 'face_match' | face_match \| face_violation \| face_denied |
| snapshot_path | VARCHAR(255) | YES | NULL | Path to captured image |

### `face_registration_log`
Immutable log of face registration events. **ON DELETE RESTRICT**.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| user_id | INT | NO | — | FK → users.id (**RESTRICT**) |
| action | ENUM | NO | — | registered \| deactivated \| reactivated \| deleted |
| descriptor_count | INT | YES | 0 | Number of descriptors (≥0) |
| performed_by | INT | YES | NULL | FK → users.id (SET NULL) |

---

## Violations

### `violations` (Legacy)
Original violation log from RFID gate scans. Kept for backward compatibility.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| user_id | INT | NO | — | FK → users.id (CASCADE) |
| rfid_uid | VARCHAR(50) | NO | — | RFID scanned at gate |
| scanned_at | TIMESTAMP | NO | CURRENT_TIMESTAMP | — |
| violation_type | ENUM | NO | 'forgot_card' | forgot_card \| unauthorized_access \| blocked_entry |
| gate_location | VARCHAR(100) | YES | NULL | — |
| security_guard_id | INT | YES | NULL | FK → users.id (SET NULL) |
| email_sent | TINYINT(1) | NO | 0 | Email notification sent |
| email_sent_at | TIMESTAMP | YES | NULL | — |
| notes | TEXT | YES | NULL | — |

**Trigger**: `after_violation_insert` recalculates users.violation_count

### `violation_categories`
Reference table of offense types from the student handbook (Article 12).

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| name | VARCHAR(255) | NO | — | Offense name |
| type | ENUM | NO | — | minor \| major \| grave |
| description | TEXT | YES | NULL | Full description |
| default_sanction | VARCHAR(255) | YES | NULL | Recommended sanction |
| article_reference | VARCHAR(100) | YES | NULL | Handbook reference |
| is_active | TINYINT(1) | NO | 1 | Active flag |

**Seed data**: 8 minor + 10 major + 12 grave = 30 categories from PCU handbook

### `student_violations`
Comprehensive violation audit log (records are never deleted). This is the **primary** violation tracking system.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| user_id | INT | NO | — | FK → users.id (CASCADE) |
| category_id | INT | NO | — | FK → violation_categories.id (**RESTRICT**) |
| description | TEXT | YES | NULL | Specific details |
| offense_number | INT | NO | 1 | Which offense (1st, 2nd, etc.) |
| status | ENUM | NO | 'active' | active \| pending_reparation \| apprehended |
| reparation_type | VARCHAR(100) | YES | NULL | How it was resolved |
| reparation_notes | TEXT | YES | NULL | Resolution details |
| reparation_completed_at | DATETIME | YES | NULL | Completion time |
| resolved_by | INT | YES | NULL | FK → users.id (SET NULL) |
| recorded_by | INT | YES | NULL | FK → users.id (SET NULL) |
| school_year | VARCHAR(20) | NO | — | e.g., "2025-2026" |
| semester | ENUM | NO | — | 1st \| 2nd \| summer |

**Triggers**: insert/update/delete sync `users.active_violations_count`

---

## Guardian Notifications

### `guardians`
Parent/guardian contact information.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT | NO | AUTO_INCREMENT | PK |
| email | VARCHAR(255) | NO | — | Email (UK) |
| first_name | VARCHAR(100) | NO | — | — |
| last_name | VARCHAR(100) | NO | — | — |
| phone_number | VARCHAR(20) | YES | NULL | — |
| relationship | ENUM | NO | 'Guardian' | Mother \| Father \| Guardian \| Other |

### `student_guardians`
Junction: links students to their guardians (M:N).

### `notification_settings`
Per-guardian notification preferences (1:1 with guardians).

### `notification_queue`
Pending notification dispatch queue with retry support.

### `notification_logs`
Immutable log of sent notifications. **ON DELETE RESTRICT** on student_id.

---

## Audit & Logging

### `audit_log`
Generic audit trail for data changes (old/new values as JSON).

### `audit_logs`
Admin-specific action audit trail. **ON DELETE RESTRICT** on admin_id — admins cannot be deleted while audit records exist.

### `auth_audit_log`
Authentication event log (login, logout, signup, link_account).

---

## Security / QR Gate

### `qr_entry_logs`
QR code and RFID gate entry records.

### `used_qr_tokens`
One-time QR tokens that have been consumed (prevents replay).

### `qr_scan_challenges`
Active QR scan challenges for face-binding verification flow.

### `qr_face_pending`
Pending face verification requests linked to QR scans.

### `qr_security_events`
Security event log for QR/face binding operations.

---

## Rate Limiting

### `ip_rate_limits`
DB-backed IP rate limiter for login and sensitive actions.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | INT UNSIGNED | NO | AUTO_INCREMENT | PK |
| identifier | VARCHAR(180) | NO | — | action\|ip composite key (UK) |
| attempts | INT UNSIGNED | NO | 1 | Current attempt count |
| first_attempt | INT UNSIGNED | NO | — | Unix timestamp of window start |
| blocked_until | INT UNSIGNED | NO | 0 | Unix timestamp of block expiry |

---

## System Configuration

### `system_settings`
Key-value system configuration store.

---

## Views

### `v_active_rfid_cards`
Active RFID cards joined with student info. Used by admin card management UI.

### `v_students_complete`
Complete student profile view with violation aggregates. **Optimized**: uses LEFT JOIN on pre-aggregated subquery instead of correlated subqueries.

**Output columns**: id, student_id, name, email, role, status, created_at, last_login, profile_picture, profile_picture_uploaded_at, rfid_uid, rfid_registered_at, violation_count, bio, phone, emergency_contact, emergency_phone, total_violations, last_violation_date

---

## Triggers

| Trigger | Table | Event | Action |
|---------|-------|-------|--------|
| after_rfid_insert | rfid_cards | INSERT | Syncs rfid_uid → users |
| after_rfid_update | rfid_cards | UPDATE | Clears rfid_uid on deactivation |
| after_profile_update | student_profiles | UPDATE | Syncs profile_picture → users |
| after_violation_insert | violations | INSERT | Recalculates users.violation_count |
| after_student_violation_insert | student_violations | INSERT | Recalculates users.active_violations_count |
| after_student_violation_update | student_violations | UPDATE | Recalculates on status change |
| after_student_violation_delete | student_violations | DELETE | Safety net recalculation |

---

## Delete Behavior Matrix

| Parent Table | Child Table | FK Column | On Delete |
|-------------|-------------|-----------|-----------|
| users | rfid_cards | user_id | CASCADE |
| users | face_descriptors | user_id | CASCADE |
| users | violations | user_id | CASCADE |
| users | student_violations | user_id | CASCADE |
| users | student_profiles | user_id | CASCADE |
| users | user_auth_methods | user_id | CASCADE |
| users | password_resets | user_id | CASCADE |
| users | qr_entry_logs | user_id | CASCADE |
| users | used_qr_tokens | user_id | CASCADE |
| users | **face_entry_logs** | user_id | **RESTRICT** |
| users | **face_registration_log** | user_id | **RESTRICT** |
| users | **rfid_status_history** | user_id | **RESTRICT** |
| users | **notification_logs** | student_id | **RESTRICT** |
| users | **audit_logs** | admin_id | **RESTRICT** |
| users | audit_log | user_id | SET NULL |
| users | auth_audit_log | user_id | SET NULL |
| guardians | notification_logs | guardian_id | CASCADE |
| guardians | notification_queue | guardian_id | CASCADE |
| guardians | notification_settings | guardian_id | CASCADE |
| violation_categories | student_violations | category_id | **RESTRICT** |

> **RESTRICT** tables block user deletion until their records are handled (preserves audit trail).

---

## Denormalized Fields

These fields are cached copies of computed values, kept in sync via triggers.

| Field | Source of Truth | Sync Mechanism |
|-------|----------------|----------------|
| users.rfid_uid | rfid_cards.rfid_uid WHERE is_active=1 | after_rfid_insert / after_rfid_update triggers |
| users.rfid_registered_at | rfid_cards.registered_at | after_rfid_insert trigger |
| users.profile_picture | student_profiles.profile_picture | after_profile_update trigger |
| users.profile_picture_uploaded_at | student_profiles.profile_picture_uploaded_at | after_profile_update trigger |
| users.violation_count | COUNT(*) FROM violations | after_violation_insert trigger |
| users.active_violations_count | COUNT(*) FROM student_violations WHERE status='active' | after_student_violation_* triggers |
| users.gate_mark_count | Manual increment/reset in PHP | No trigger — managed in gate_scan.php and clear_violation.php |
