<?php

declare(strict_types=1);

/**
 * Superadmin admin-face-recognition helpers.
 *
 * Kept under /superadmin to avoid touching existing student face flows.
 */

function ensure_admin_face_tables(PDO $pdo): void {
    // Idempotent create. No foreign keys to avoid cross-module coupling.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_face_descriptors (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            descriptor_data TEXT NOT NULL,
            descriptor_iv VARCHAR(48) NOT NULL,
            descriptor_tag VARCHAR(48) NOT NULL,
            label VARCHAR(100) DEFAULT NULL,
            quality_score FLOAT DEFAULT NULL,
            registered_by_superadmin_id INT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            descriptor_dimension SMALLINT UNSIGNED NOT NULL DEFAULT 128,
            UNIQUE KEY uk_user_label_active (user_id, label, is_active),
            INDEX idx_user_id (user_id),
            INDEX idx_is_active (is_active),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ensure_admin_face_registration_tables(PDO $pdo): void {
    // Staging tables used to enforce "face first" enrollment before creating the admin user record.
    // Kept under /superadmin to avoid touching existing student flows.
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS admin_face_registration_tokens (
            id INT PRIMARY KEY AUTO_INCREMENT,
            token VARCHAR(96) NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            student_id VARCHAR(100) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_by_superadmin_id INT NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_token (token),
            INDEX idx_expires_at (expires_at),
            INDEX idx_created_by (created_by_superadmin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS admin_face_registration_faces (
            id INT PRIMARY KEY AUTO_INCREMENT,
            token VARCHAR(96) NOT NULL,
            descriptor_data TEXT NOT NULL,
            descriptor_iv VARCHAR(48) NOT NULL,
            descriptor_tag VARCHAR(48) NOT NULL,
            label VARCHAR(100) NOT NULL,
            quality_score FLOAT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_token_label (token, label),
            INDEX idx_token (token),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function admin_face_cleanup_expired_registrations(PDO $pdo): void {
    // Best-effort cleanup to avoid leaving staged data indefinitely.
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT token FROM admin_face_registration_tokens WHERE expires_at < UTC_TIMESTAMP()');
        $stmt->execute();
        $expiredTokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($expiredTokens)) {
            $in = implode(',', array_fill(0, count($expiredTokens), '?'));
            $pdo->prepare("DELETE FROM admin_face_registration_faces WHERE token IN ($in)")->execute($expiredTokens);
            $pdo->prepare("DELETE FROM admin_face_registration_tokens WHERE token IN ($in)")->execute($expiredTokens);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

function admin_face_next_required_label(array $existingActiveLabels): ?string {
    $order = ['front', 'left', 'right'];
    $set = [];
    foreach ($existingActiveLabels as $l) {
        $set[strtolower((string)$l)] = true;
    }
    foreach ($order as $label) {
        if (empty($set[$label])) {
            return $label;
        }
    }
    return null;
}
