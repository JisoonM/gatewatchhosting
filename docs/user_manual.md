# GateWatch (PCU RFID System) — User Manual

**Document purpose:** This manual explains how to use the GateWatch / PCU RFID System for day-to-day campus gate entry monitoring and student services workflows. It is written in simple academic terms for capstone documentation.

**Document scope:** This is a user manual (how to operate the system). It is not a legal policy document; institutional policy should be defined by the authorized office.

**Target users (roles):**
- Student
- Security Guard (Gate Monitor)
- Admin / Student Services Office (SSO)
- Super Admin (system-level admin management)

**Core functions covered:**
- Account registration (Google Sign-In), verification, and login
- Gate entry identification using RFID, Digital ID (QR), and optional Face Recognition
- Violation recording, assigned reparation tasks, and resolution
- RFID card lifecycle (register, lost/found, unregister)
- Audit logging and basic analytics (admin-facing)

---

## Table of Contents

1) System Overview

2) Access and Accounts

3) Student User Guide

4) Security Guard (Gate Monitor) User Guide

5) Admin / SSO User Guide

6) Super Admin User Guide

7) Terms, Consent, and Data Handling (User-Facing Summary)

8) Common Issues and Troubleshooting

9) Appendix (For Institutional Staff)

---

## 1) System Overview

GateWatch is a web-based campus safety and student services support system. Its main goal is to:
1) Help identify students at campus entry points, and
2) Track gate-related and policy-related violations with a clear, auditable workflow.

GateWatch supports three identification methods:
- **RFID Card** (tap/scan at the gate)
- **Digital ID (QR Code)** (shown by the student and scanned by the guard)
- **Face Recognition** (optional feature; requires enrollment and camera support)

The system also supports:
- **Administrative verification** of new student accounts (to prevent unauthorized registration)
- **Guardian contact information** collection (for emergency contact and optional notifications)
- **Audit logs** for accountability of admin actions

---

## 2) Access and Accounts

### 2.0 Common Pages (Navigation Map)

The system is organized by role. Typical page entry points include:
- **Student**: `login.php`, `homepage.php`, `digital_id.php`, `profile.php`
- **Admin/SSO**: `admin/admin_login.php`, `admin/homepage.php`
- **Security Guard**: `security/security_login.php`, `security/gate_monitor.php`
- **Super Admin**: `superadmin/superadmin_login.php`, `superadmin/homepage.php`

### 2.1 Student Registration (Google Sign-In)

**Who uses this:** Students

**Purpose:** Create a student account using Google Sign-In, then wait for SSO verification.

**Steps:**
1) Open the system login page.
2) Select **Sign in with Google**.
3) If the account is new, the system shows the **GateWatch Terms and Conditions**.
4) Read the Terms and either:
   - **Accept** the Terms and continue, or
   - **Decline** to cancel registration.
5) Provide the required emergency contact information:
   - Parent/Guardian full name
   - Parent/Guardian email address
   - Parent/Guardian contact number
6) Submit the form to complete registration.

**Result:**
- Your account is created as **Pending**.
- You cannot fully access the system until the SSO/Admin verifies your account.

**Important notes:**
- The Google signup session expires after about **15 minutes**. If it expires, repeat the Google Sign-In process.
- The system can restrict sign-in to approved email domains (institution-controlled).

### 2.2 Student Login

**Who uses this:** Students

**Behavior:**
- If your account is **Pending**, the system shows an informational message and blocks full access.
- If your account is **Locked**, the system blocks login and requires SSO assistance.
- If approved, you are redirected to the Student Homepage/Dashboard.

### 2.3 Admin Login (SSO)

**Who uses this:** Admin / Student Services Office

Admins log in through the Admin Login page (separate from the Student login). After login, admins access the Admin Dashboard.

### 2.4 Security Guard Login

**Who uses this:** Security guards assigned to the gate

Security guards log in through the Security Login page (separate from Student and Admin logins). Credentials are configured by the institution.

### 2.5 Super Admin Login

**Who uses this:** Super Admin

Super Admin accounts are separate from normal Admin accounts. Super Admin access is used to manage Admin accounts and review Super Admin audit logs.

---

## 3) Student User Guide

### 3.1 Student Homepage (Dashboard)

The Student Homepage provides a summary of:
- **RFID Card Status** (Active / Not Active / Lost)
- **Violation Status** (Clear / Active)
- **Face Recognition Status** (Verified / Unverified, if the feature is used)
- **Pending Reparation Tasks** (if assigned by SSO)

It also provides shortcuts such as:
- **Open Digital ID** (to display your QR code)
- **Contact Support** (to contact the Student Services Office)

### 3.2 Profile Page

Students can view profile information (such as Student ID, name, email, account status, and last login) on the Profile page.

### 3.3 Using Digital ID (QR Code)

**Purpose:** Use a time-limited QR code as a digital identity proof at the gate.

**Steps:**
1) On the Student Homepage, click **Open Digital ID**.
2) Present the QR code to the guard for scanning.

**Important constraints (expected behavior):**
- The Digital ID QR is **short-lived** (about a few minutes).
- A QR is intended for **one-time use**. If it is already used or expired, generate a new one.

**Optional security behavior (some deployments):**
- The system may require a fresh “gate challenge” during scanning to reduce QR sharing/proxy attempts.
- If instructed by the guard or the system, simply refresh/regenerate your Digital ID QR and rescan.

### 3.4 Gate Entry Guide (Student Perspective)

To enter campus smoothly:
1) **Before you reach the gate**, prepare either:
   - your RFID card, or
   - your Digital ID QR code, or
   - (if available and enrolled) face recognition readiness.
2) At the gate, use one of the accepted methods:
   - Tap/scan **RFID card**
   - Show **Digital ID QR** for scanning
   - Use **Face Recognition** (supported gates only)
3) Entry is logged by the system.

**Policy reminder:**
- Entering without a valid ID can result in a **violation record**.

### 3.5 If Your RFID Card is Marked Lost

If your RFID card is marked as lost:
- Use **Digital ID (QR)** for gate entry until the Admin issues a replacement.
- Contact the Admin/SSO for assistance.

### 3.6 Viewing Violations and Reparation Tasks

**Violation record:**
- The dashboard shows whether you have active violations.
- You can open **Violation History** to view past records.

**Pending reparation tasks:**
- If SSO assigns a reparation task (e.g., written apology, counseling, parent conference), it will appear as “Action Required”.
- Follow the instruction and report to the SSO as advised.

**How tasks are completed (typical):**
1) Student follows the instruction (e.g., report to SSO, submit required document, attend session).
2) Admin/SSO validates completion.
3) Admin/SSO updates the system to resolve/clear the violation according to policy.

---

## 4) Security Guard (Gate Monitor) User Guide

### 4.1 Primary Responsibilities

The Security Guard role focuses on:
- Identifying students at the gate (RFID / QR / Face Recognition)
- Recording violations when required by gate policy
- Ensuring the gate process is consistent and auditable

### 4.2 Gate Monitor Workflow (High Level)

A typical gate workflow is:
1) Identify a student using RFID, QR, or Face Recognition.
2) The system checks status rules (e.g., unresolved cases or blocks).
3) If entry is allowed, the scan is logged.
4) If a violation must be recorded, the guard selects the appropriate violation category (depending on system policy and allowed guard actions).

In many deployments, the system uses a practical two-step flow:
1) **Scan/identify** the student, then
2) **Record a violation category** (only when needed), using the on-screen violation selection.

### 4.3 RFID Scan (Gate)

**Expected behavior:**
- The system looks up the RFID UID and maps it to a student.
- The scan is logged with date and time.

**Common outcomes:**
- **Recognized RFID:** student record appears.
- **Unknown RFID:** guard should follow institutional procedure (student may need Admin assistance).
- **RFID marked lost:** student should use Digital ID instead.

### 4.4 QR Scan (Digital ID)

**Expected behavior:**
- The guard scans the student’s QR code.
- The system validates the QR token (time-limited, intended for one-time use).

If the QR is rejected:
- Ask the student to generate a new Digital ID QR.
- Ensure the camera permission is enabled on the guard device.

Common QR rejection reasons (normal security behavior):
- QR is **expired**
- QR was **already used**
- QR is **invalid** (tampered or not issued by the system)

If “gate challenge” mode is enabled:
- The system may require a **fresh challenge** and a freshly refreshed student QR.
- Follow the on-screen prompt (commonly: ask the student to refresh their Digital ID, then rescan).

### 4.5 Face Recognition (Optional)

If Face Recognition is enabled:
- The student must already be enrolled by an Admin.
- The gate device must allow camera access.

**Typical outcomes:**
- **Match found:** entry can be logged.
- **No match:** use RFID or QR as fallback (institution policy).

### 4.6 When the System Denies Entry

In some cases the system may deny entry due to an unresolved case or administrative hold.

Recommended guard action:
1) Inform the student that entry is blocked by a system status.
2) Refer the student to the Admin/SSO for resolution.
3) Follow campus policy for on-site handling.

---

## 5) Admin / SSO User Guide

### 5.1 Core Responsibilities

The Admin/SSO role typically includes:
- Verifying student accounts
- Managing RFID registration (including lost/found handling)
- Managing violation records and resolutions
- Enrolling or deleting face recognition records (if enabled)
- Reviewing audit logs for accountability

Depending on configuration, admins may also:
- Upload student profile pictures
- Toggle guardian notification settings
- Export and filter audit/security logs

### 5.2 Verify Student Accounts (Approve / Deny)

**Purpose:** Ensure only valid students gain access.

**Typical steps:**
1) Open the Admin Dashboard.
2) View the list of **Pending** students.
3) Select **Approve** to activate a student account, or **Deny** to reject.

**Expected behavior:**
- Approval enables student access.
- Denial prevents access; the student may be notified based on system configuration.
- Actions are recorded in the **audit log**.

### 5.3 Register an RFID Card to a Student

**Purpose:** Link a physical RFID UID to a student account.

**Important constraints:**
- The RFID UID is validated as **10 digits** (numeric format).
- UID uniqueness is enforced (a UID cannot be assigned to multiple users).

**Typical steps:**
1) Search/select the student in the Admin dashboard.
2) Enter the RFID UID.
3) Save the RFID registration.

**Student ID update (common workflow):**
- Students created via Google Sign-In can initially receive a **temporary student ID**.
- During first RFID registration, Admin may replace the temporary ID with an official ID (institution policy).

### 5.4 Unregister an RFID Card

Use this when:
- A student’s RFID was incorrectly assigned.
- A card needs to be cleared before replacement.

This action is logged for accountability.

### 5.5 Mark RFID as Lost / Found

**Lost RFID:**
- Mark the student’s RFID as **lost**.
- The student should use **Digital ID QR** while the RFID is disabled.

**Found RFID:**
- Mark the RFID as **found** to restore normal use.

These actions are recorded in audit logs and may trigger email notifications depending on configuration.

### 5.6 Upload/Update Student Profile Picture

Admins can upload a student’s profile picture (commonly used for identity verification workflows). Typical constraints:
- Supported formats: JPG/PNG
- Size limit (commonly up to a few MB)

### 5.7 Manage Violations

Admins can:
- View violation categories
- Review student violation history and summaries
- Add violations when needed
- Resolve/clear violations and assign required reparation tasks

**Practical workflow example (typical):**
1) Review a student’s active violations.
2) Assign the appropriate reparation task and add notes (if needed).
3) When the student completes the task, mark the violation as resolved/cleared.

**Academic note:**
- Disciplinary guidance can follow an institution-defined policy (e.g., escalation by offense count and violation type). GateWatch supports such policy-driven messaging.

### 5.8 Face Recognition Management (Optional)

If face recognition is enabled:
- Admin can register a student’s face using a camera-enabled device.
- Admin can delete face descriptors if needed (e.g., re-enrollment).

### 5.9 Guardian Notifications (Optional)

Some deployments allow Admin/SSO to enable or disable guardian notifications globally. This is a system setting (institution-wide).

### 5.10 Audit Logs and Accountability

Admin actions can be recorded in audit logs (commonly including the actor, action type, target record, time, IP address, and user agent). Use audit logs to support accountability and incident review.

---

## 6) Super Admin User Guide

### 6.1 Core Responsibilities

Super Admin users generally:
- Manage Admin accounts
- Review Super Admin audit logs
- Maintain governance and system accountability

### 6.2 Create Admin Accounts

Typical steps:
1) Log in to the Super Admin dashboard.
2) Create an Admin account (name, email, password).
3) Confirm the admin can log in to the Admin portal.

All actions are recorded in Super Admin audit logs.

---

## 7) Terms, Consent, and Data Handling (User-Facing Summary)

GateWatch provides Terms and Conditions during new student registration. In summary:
- The system collects student information for identity and campus monitoring workflows.
- It also collects parent/guardian contact information for emergency contact purposes and optional notifications.
- Depending on enabled features, the system may also process RFID identifiers, entry logs, violation records, and face recognition metadata.

For the complete Terms text, the system displays the current version during registration.

**Important reminder:** The Terms displayed in the system have an explicit effective date/version. Students are asked to consent during new Google-based registration before the account is created.

---

## 8) Common Issues and Troubleshooting

### 8.1 “Signup session expired”
- Repeat Google Sign-In and complete registration again.

### 8.2 “Account pending verification”
- Wait for SSO/Admin approval.
- Contact SSO if approval is delayed.

### 8.3 “Account locked”
- Contact SSO/Admin for account review.

### 8.4 Digital ID QR not scanning
- Increase screen brightness.
- Keep the QR steady.
- Allow camera permission on the guard device.
- Generate a new QR if it expired.

If the QR was already used:
- Return to the Digital ID page and generate a new QR.

### 8.5 RFID not working
- Confirm the RFID UID is registered.
- If marked lost, use Digital ID until Admin resolves.

### 8.6 Face recognition not working
- Ensure face recognition is enabled by the institution.
- Ensure the student is enrolled by Admin.
- Ensure camera permission is enabled.

### 8.7 “Only approved email domains are allowed for Google Sign-In”

- Use the official institution email account.
- If you believe your email should be allowed, contact the Admin/SSO.

---

## 9) Appendix (For Institutional Staff)

### 9.1 Minimum Technical Requirements (Typical)

**Server:**
- PHP web server (commonly deployed via XAMPP for local development)
- MySQL/MariaDB database

**Gate workstation:**
- Browser with camera permission support
- Camera/webcam (for QR scanning and face recognition, when enabled)
- RFID reader compatible with the deployed card format (institution-provided)

### 9.2 Face Recognition Model Setup (If Used)

If Face Recognition is enabled, the system may require downloading face recognition model files into `assets/models/` using the provided setup script:
- `php setup/download_models.php`

### 9.3 Notes on Privacy and Responsible Use

For capstone documentation and institutional deployment:
- Follow data-minimization principles (collect only what is required to operate the system).
- Limit access based on role (Student vs Guard vs Admin vs Super Admin).
- Use audit logs to review sensitive operations.

---

**End of Manual**
