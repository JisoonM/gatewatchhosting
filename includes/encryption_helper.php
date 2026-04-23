<?php

/**
 * Encryption Helper for Face Recognition Descriptors
 * Uses AES-256-GCM for authenticated encryption
 * 
 * Security: Descriptors are encrypted at rest in the database.
 * The encryption key is stored in .env and never exposed to the client.
 */

/**
 * Get the encryption key from environment
 * Validates key length for AES-256 (32 bytes)
 * 
 * @return string Binary encryption key
 * @throws RuntimeException If key is not configured or invalid
 */
function get_encryption_key(): string {
    $key = env('FACE_ENCRYPTION_KEY', '');
    
    if (empty($key)) {
        throw new RuntimeException('FACE_ENCRYPTION_KEY is not configured in .env');
    }
    
    // Key should be hex-encoded 32-byte key (64 hex chars)
    $binaryKey = hex2bin($key);
    
    if ($binaryKey === false || strlen($binaryKey) !== 32) {
        throw new RuntimeException('FACE_ENCRYPTION_KEY must be a 64-character hex string (256-bit key)');
    }
    
    return $binaryKey;
}

/**
 * Encrypt face descriptor data using AES-256-GCM
 * 
 * @param string $plaintext JSON-encoded descriptor data
 * @return array ['ciphertext' => string, 'iv' => string, 'tag' => string] Base64-encoded values
 * @throws RuntimeException If encryption fails
 */
function encrypt_descriptor(string $plaintext): array {
    $key = get_encryption_key();
    
    // Generate random IV (12 bytes for GCM)
    $iv = random_bytes(12);
    
    // Encrypt with AES-256-GCM
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '', // AAD (Additional Authenticated Data) - empty for now
        16  // Tag length
    );
    
    if ($ciphertext === false) {
        throw new RuntimeException('Encryption failed: ' . openssl_error_string());
    }
    
    return [
        'ciphertext' => base64_encode($ciphertext),
        'iv'         => base64_encode($iv),
        'tag'        => base64_encode($tag),
    ];
}

/**
 * Decrypt face descriptor data using AES-256-GCM
 * 
 * @param string $ciphertext Base64-encoded ciphertext
 * @param string $iv Base64-encoded initialization vector
 * @param string $tag Base64-encoded authentication tag
 * @return string Decrypted plaintext (JSON descriptor)
 * @throws RuntimeException If decryption fails (tampered data or wrong key)
 */
function decrypt_descriptor(string $ciphertext, string $iv, string $tag): string {
    $key = get_encryption_key();
    
    $rawCiphertext = base64_decode($ciphertext, true);
    $rawIv = base64_decode($iv, true);
    $rawTag = base64_decode($tag, true);
    
    if ($rawCiphertext === false || $rawIv === false || $rawTag === false) {
        throw new RuntimeException('Invalid base64 encoding in encrypted data');
    }
    
    $plaintext = openssl_decrypt(
        $rawCiphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $rawIv,
        $rawTag
    );
    
    if ($plaintext === false) {
        throw new RuntimeException('Decryption failed - data may be tampered or key mismatch');
    }
    
    return $plaintext;
}

/**
 * Generate a secure encryption key for .env
 * Run this once to generate FACE_ENCRYPTION_KEY value
 * 
 * @return string 64-character hex string
 */
function generate_encryption_key(): string {
    return bin2hex(random_bytes(32));
}
