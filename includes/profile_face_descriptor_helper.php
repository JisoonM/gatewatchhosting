<?php
declare(strict_types=1);

/**
 * Helpers for student profile-picture face descriptors.
 *
 * These descriptors are stored separately from enrollment descriptors and are
 * used as an authenticity anchor: enrollment captures must match the profile
 * picture descriptor of the same student account.
 */

function ensure_profile_face_descriptors_table(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS profile_face_descriptors (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            descriptor_data TEXT NOT NULL,
            descriptor_iv VARCHAR(255) NOT NULL,
            descriptor_tag VARCHAR(255) NOT NULL,
            detection_score DECIMAL(6,5) NULL,
            source_picture VARCHAR(255) NULL,
            registered_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_profile_face_registered_by (registered_by),
            CONSTRAINT fk_profile_face_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $ensured = true;
}

/**
 * @return array<string,mixed>|null
 */
function get_profile_face_descriptor_row(PDO $pdo, int $userId): ?array {
    ensure_profile_face_descriptors_table($pdo);
    $stmt = $pdo->prepare("
        SELECT id, user_id, descriptor_data, descriptor_iv, descriptor_tag, detection_score, source_picture, updated_at
        FROM profile_face_descriptors
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function upsert_profile_face_descriptor(
    PDO $pdo,
    int $userId,
    string $descriptorCiphertext,
    string $descriptorIv,
    string $descriptorTag,
    ?float $detectionScore,
    ?string $sourcePicture,
    ?int $registeredBy
): void {
    ensure_profile_face_descriptors_table($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO profile_face_descriptors
            (user_id, descriptor_data, descriptor_iv, descriptor_tag, detection_score, source_picture, registered_by)
        VALUES
            (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            descriptor_data = VALUES(descriptor_data),
            descriptor_iv = VALUES(descriptor_iv),
            descriptor_tag = VALUES(descriptor_tag),
            detection_score = VALUES(detection_score),
            source_picture = VALUES(source_picture),
            registered_by = VALUES(registered_by),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $userId,
        $descriptorCiphertext,
        $descriptorIv,
        $descriptorTag,
        $detectionScore,
        $sourcePicture,
        $registeredBy,
    ]);
}

function delete_profile_face_descriptor(PDO $pdo, int $userId): void {
    ensure_profile_face_descriptors_table($pdo);
    $stmt = $pdo->prepare("DELETE FROM profile_face_descriptors WHERE user_id = ?");
    $stmt->execute([$userId]);
}

