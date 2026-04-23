<?php
/**
 * Post-Google Sign-In completion endpoint for NEW students.
 * - Handles Accept/Decline actions submitted from login.php modal
 * - Validates Parent/Guardian full name, email, and contact number
 * - Creates the student account as Pending verification
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/terms_helper.php';

send_security_headers();
send_no_cache_headers();

$pdo = pdo();

$signup = $_SESSION['google_signup'] ?? null;
if (!is_array($signup)) {
    header('Location: login.php');
    exit;
}

$startedAt = (int)($signup['started_at'] ?? 0);
if ($startedAt <= 0 || (time() - $startedAt) > 900) {
    unset($_SESSION['google_signup']);
    $_SESSION['error'] = 'Signup session expired. Please try signing in with Google again.';
    header('Location: login.php');
    exit;
}

$studentName = (string)($signup['name'] ?? '');
$studentEmail = (string)($signup['email'] ?? '');
$googleId = (string)($signup['google_id'] ?? '');

if ($studentName === '' || $studentEmail === '' || $googleId === '') {
    unset($_SESSION['google_signup']);
    $_SESSION['error'] = 'Incomplete Google sign-in details. Please try again.';
    header('Location: login.php');
    exit;
}

// Only allow this page for truly NEW signups.
// If the Google account/email is already registered, do not allow direct URL access.
try {
  $stmt = $pdo->prepare('SELECT id FROM users WHERE google_id = ? OR email = ? LIMIT 1');
  $stmt->execute([$googleId, $studentEmail]);
  if ($stmt->fetch()) {
    unset($_SESSION['google_signup']);
    $_SESSION['info'] = 'Your account is already registered. If verification is pending, please wait for approval.';
    header('Location: login.php');
    exit;
  }
} catch (Throwable $e) {
  // If we cannot validate uniqueness, fail closed for safety.
  unset($_SESSION['google_signup']);
  $_SESSION['error'] = 'Unable to continue registration at this time. Please try again.';
  header('Location: login.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

function users_terms_columns_available(PDO $pdo): bool {
    try {
        $stmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('terms_accepted_at','terms_version')");
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_map('strtolower', array_map('strval', $cols));
        return in_array('terms_accepted_at', $cols, true) && in_array('terms_version', $cols, true);
    } catch (Throwable $e) {
        return false;
    }
}

function split_full_name(string $fullName): array {
    $fullName = trim(preg_replace('/\s+/', ' ', $fullName));
    if ($fullName === '') {
        return ['', ''];
    }

    $parts = explode(' ', $fullName);
    if (count($parts) === 1) {
        return [$parts[0], $parts[0]];
    }

    $lastName = array_pop($parts);
    $firstName = implode(' ', $parts);
    return [$firstName, $lastName];
}

verify_csrf();

$action = (string)($_POST['action'] ?? '');

if ($action === 'decline') {
  unset($_SESSION['google_signup']);
  unset($_SESSION['google_terms_error'], $_SESSION['google_terms_old']);
  $_SESSION['error'] = 'You declined the Terms and Conditions. Registration was cancelled.';
  header('Location: login.php');
  exit;
}

if ($action !== 'complete') {
  $_SESSION['google_terms_error'] = 'Invalid request. Please review the terms again.';
  header('Location: login.php');
  exit;
}

$accepted = (string)($_POST['accepted_terms'] ?? '');
$guardianFullName = trim((string)($_POST['guardian_full_name'] ?? ''));
$guardianEmail = strtolower(trim((string)($_POST['guardian_email'] ?? '')));
$guardianContact = trim((string)($_POST['guardian_contact_number'] ?? ''));

$_SESSION['google_terms_old'] = [
  'guardian_full_name' => $guardianFullName,
  'guardian_email' => $guardianEmail,
  'guardian_contact_number' => $guardianContact,
  'accepted_terms' => $accepted,
];

if ($accepted !== '1') {
  $_SESSION['google_terms_error'] = 'You must accept the Terms and Conditions to continue.';
  header('Location: login.php');
  exit;
}

if ($guardianFullName === '' || $guardianEmail === '' || $guardianContact === '') {
  $_SESSION['google_terms_error'] = 'Please provide Parent/Guardian full name, email, and contact number.';
  header('Location: login.php');
  exit;
}

if (!filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) {
  $_SESSION['google_terms_error'] = 'Please enter a valid Parent/Guardian email address.';
  header('Location: login.php');
  exit;
}

try {
  // Ensure this is still a brand-new signup (avoid duplicates if user refreshes)
  $stmt = $pdo->prepare('SELECT id FROM users WHERE google_id = ? OR email = ? LIMIT 1');
  $stmt->execute([$googleId, $studentEmail]);
  if ($stmt->fetch()) {
    unset($_SESSION['google_signup']);
    unset($_SESSION['google_terms_error'], $_SESSION['google_terms_old']);
    $_SESSION['info'] = 'Your account is already registered. If verification is pending, please wait for approval.';
    header('Location: login.php');
    exit;
  }

  $pdo->beginTransaction();

  $temporaryStudentId = generate_temporary_student_id($pdo);

  $randomPassword = bin2hex(random_bytes(32));
  $hashedPassword = password_hash($randomPassword, PASSWORD_ARGON2ID);

  $termsAt = date('Y-m-d H:i:s');
  $termsVersion = gatewatch_terms_version();

  if (users_terms_columns_available($pdo)) {
    $insertStmt = $pdo->prepare('
      INSERT INTO users (student_id, name, email, password, google_id, role, status, created_at, terms_accepted_at, terms_version)
      VALUES (?, ?, ?, ?, ?, "Student", "Pending", NOW(), ?, ?)
    ');
    $insertStmt->execute([
      $temporaryStudentId,
      $studentName,
      $studentEmail,
      $hashedPassword,
      $googleId,
      $termsAt,
      $termsVersion,
    ]);
  } else {
    $insertStmt = $pdo->prepare('
      INSERT INTO users (student_id, name, email, password, google_id, role, status, created_at)
      VALUES (?, ?, ?, ?, ?, "Student", "Pending", NOW())
    ');
    $insertStmt->execute([
      $temporaryStudentId,
      $studentName,
      $studentEmail,
      $hashedPassword,
      $googleId,
    ]);
  }

  $newUserId = (int)$pdo->lastInsertId();

  // Upsert guardian by email, then link as primary
  [$guardianFirst, $guardianLast] = split_full_name($guardianFullName);

  $stmt = $pdo->prepare('SELECT id FROM guardians WHERE email = ? LIMIT 1');
  $stmt->execute([$guardianEmail]);
  $guardianId = (int)($stmt->fetchColumn() ?: 0);

  if ($guardianId > 0) {
    // Keep existing relationship; update contact info and name to latest provided.
    $update = $pdo->prepare('UPDATE guardians SET first_name = ?, last_name = ?, phone_number = ? WHERE id = ?');
    $update->execute([$guardianFirst ?: 'Guardian', $guardianLast ?: 'Contact', $guardianContact, $guardianId]);
  } else {
    $insert = $pdo->prepare('
      INSERT INTO guardians (email, first_name, last_name, phone_number, relationship)
      VALUES (?, ?, ?, ?, "Guardian")
    ');
    $insert->execute([
      $guardianEmail,
      $guardianFirst ?: 'Guardian',
      $guardianLast ?: 'Contact',
      $guardianContact,
    ]);
    $guardianId = (int)$pdo->lastInsertId();
  }

  $link = $pdo->prepare('
    INSERT INTO student_guardians (student_id, guardian_id, is_primary)
    VALUES (?, ?, 1)
  ');
  $link->execute([$newUserId, $guardianId]);

  $pdo->commit();

  unset($_SESSION['google_signup']);
  unset($_SESSION['google_terms_error'], $_SESSION['google_terms_old']);

  $_SESSION['info'] = 'Your account has been created successfully and is pending verification. You will receive an email once your account is approved by the Student Services Office.';
  header('Location: login.php');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log('[PCU RFID] Google signup completion error: ' . $e->getMessage());
  $_SESSION['google_terms_error'] = 'Registration failed. Please try again or contact support.';
  header('Location: login.php');
  exit;
}
