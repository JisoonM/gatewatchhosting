<?php
/**
 * Google OAuth Callback Handler
 * This file receives the response from Google after user signs in
 */

require_once 'vendor/autoload.php';
require_once 'config/google_config.php';
require_once 'db.php';

use Google\Client as Google_Client;
use Google\Service\Oauth2 as Google_Service_Oauth2;

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get PDO instance
$pdo = pdo();

// NOTE: The google_id column is part of the base schema in database.sql.
// The runtime ALTER TABLE check that was here has been removed.
// If you are setting up a fresh database, ensure you run database.sql first.

// Initialize Google Client
$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URI);
$client->addScope("email");
$client->addScope("profile");

try {
    // Validate OAuth state parameter (CSRF protection)
    if (!isset($_GET['state']) || !isset($_SESSION['oauth_state']) || 
        $_GET['state'] !== $_SESSION['oauth_state']) {
        unset($_SESSION['oauth_state']);
        unset($_SESSION['oauth_state_time']);
        $_SESSION['error'] = 'Invalid OAuth state. Please try again.';
        header('Location: login.php');
        exit;
    }
    
    // Check state expiration (10 minutes max)
    if (!isset($_SESSION['oauth_state_time']) || (time() - $_SESSION['oauth_state_time']) > 600) {
        unset($_SESSION['oauth_state']);
        unset($_SESSION['oauth_state_time']);
        $_SESSION['error'] = 'OAuth session expired. Please try again.';
        header('Location: login.php');
        exit;
    }
    
    // Clear state after validation (single use)
    unset($_SESSION['oauth_state']);
    unset($_SESSION['oauth_state_time']);
    
    // Check if we have an authorization code
    if (!isset($_GET['code'])) {
        $_SESSION['error'] = 'Authorization code missing. Please try again.';
        header('Location: login.php');
        exit;
    }

    // Exchange authorization code for access token
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    
    if (isset($token['error'])) {
        $_SESSION['error'] = 'Failed to obtain access token. Please try again.';
        header('Location: login.php');
        exit;
    }

    $client->setAccessToken($token);

    // Get user profile information
    $google_oauth = new Google_Service_Oauth2($client);
    $google_account_info = $google_oauth->userinfo->get();
    
    $google_id = $google_account_info->id;
    $email = $google_account_info->email;
    $name = $google_account_info->name;
    $picture = $google_account_info->picture;
    $verified_email = $google_account_info->verifiedEmail;

    // Check if user already exists with this Google ID
    $stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$google_id]);
    $user = $stmt->fetch();

    if ($user) {
        // User exists with this Google ID - check status
        
        // Check verification status using 'status' column instead of 'verification_status'
        if (isset($user['status'])) {
            if ($user['status'] === 'Pending') {
                $_SESSION['info'] = 'Your account is pending for verification. Please wait for approval from the Student Services Office. You will receive an email once your account is verified.';
                header('Location: login.php');
                exit;
            }
            if ($user['status'] === 'Locked') {
                $_SESSION['error'] = 'Your account has been locked. Please contact the Student Services Office for more information.';
                header('Location: login.php');
                exit;
            }
        }
        
        // Update last login
        $updateStmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $updateStmt->execute([$user['id']]);
        
        // Check if account is locked
        if ($user['status'] === 'Locked') {
            $_SESSION['error'] = 'Your account has been locked. Please contact the Student Services Office.';
            header('Location: login.php');
            exit;
        }
        
        // Set session in correct format
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'student_id' => $user['student_id'],
            'login_method' => 'google'
        ];
        
        // Redirect based on role
        if ($user['role'] === 'Admin') {
            header('Location: admin/homepage.php');
        } else {
            header('Location: homepage.php');
        }
        exit;
        
    } else {
        // Check if email already exists (user signed up with email/password before)
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$email]);
        $existing_user = $stmt->fetch();
        
        if ($existing_user) {
            if (($existing_user['status'] ?? '') === 'Pending') {
                $_SESSION['info'] = 'Your account is pending for verification. Please wait for approval from the Student Services Office. You will receive an email once your account is verified.';
                header('Location: login.php');
                exit;
            }

            if (($existing_user['status'] ?? '') === 'Locked') {
                $_SESSION['error'] = 'Your account has been locked. Please contact the Student Services Office for more information.';
                header('Location: login.php');
                exit;
            }

            if (($existing_user['verification_status'] ?? '') === 'denied') {
                $_SESSION['error'] = 'Your account verification was denied. Please contact the Student Services Office for more information.';
                header('Location: login.php');
                exit;
            }

            // Link Google account to an already approved user without changing status.
            $linkStmt = $pdo->prepare('UPDATE users SET google_id = ? WHERE id = ?');
            $linkStmt->execute([$google_id, $existing_user['id']]);
            
            // Update last login
            $updateStmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $updateStmt->execute([$existing_user['id']]);
            
            // Set session in correct format
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$existing_user['id'],
                'name' => $existing_user['name'],
                'email' => $existing_user['email'],
                'role' => $existing_user['role'],
                'student_id' => $existing_user['student_id'],
                'login_method' => 'google'
            ];
            
            // Redirect based on role
            if ($existing_user['role'] === 'Admin') {
                header('Location: admin/homepage.php');
            } else {
                header('Location: homepage.php');
            }
            exit;
            
        } else {
            // New user - create account with temporary Student ID
            // Admin will update with real student ID later

            $allowedDomainsRaw = (string)env('ALLOWED_EMAIL_DOMAINS', '');
            $allowedDomains = array_values(array_filter(array_map('trim', explode(',', $allowedDomainsRaw))));

            if ($allowedDomains === []) {
                // DEVELOPMENT WARNING: No domain restriction is configured.
                error_log('[PCU RFID] WARNING: ALLOWED_EMAIL_DOMAINS is not set. Google Sign-In is open to all domains.');
            } else {
                $emailDomain = strtolower(substr((string)$email, (int)strrpos((string)$email, '@') + 1));
                if (!in_array($emailDomain, $allowedDomains, true)) {
                    $_SESSION['error'] = 'Only approved email domains are allowed for Google Sign-In.';
                    header('Location: login.php');
                    exit;
                }
            }

            // Defer account creation until the student accepts Terms & Conditions
            // and provides Parent/Guardian emergency contact information.
            $_SESSION['google_signup'] = [
                'google_id' => (string)$google_id,
                'email' => (string)$email,
                'name' => (string)$name,
                'picture' => (string)$picture,
                'verified_email' => (bool)$verified_email,
                'started_at' => time(),
            ];

            header('Location: login.php');
            exit;
        }
    }
    
} catch (Exception $e) {
    // Log detailed error information
    error_log('Google OAuth Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Log full details server-side only — never expose to client
    error_log('Google OAuth Exception: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    $_SESSION['error'] = 'Google Sign-In failed. Please try again or contact support if the problem persists.';
    
    header('Location: login.php');
    exit;
}
?>
