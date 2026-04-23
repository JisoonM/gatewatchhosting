<?php
/**
 * Face Recognition Model Download Setup
 * 
 * Downloads pre-trained face-api.js models from the official repository.
 * Models: SSD MobileNet v1 (face detection), Face Landmark 68 (alignment), Face Recognition Net (128-dim descriptor)
 * 
 * Run this script once to download required model files.
 * Usage: php setup/download_models.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this script can only be run from CLI.\n");
}

$modelsDir = __DIR__ . '/../assets/models';

// Create models directory
if (!is_dir($modelsDir)) {
    mkdir($modelsDir, 0755, true);
    echo "Created directory: assets/models/\n";
}

// Model files from face-api.js GitHub repository
$baseUrl = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights';

$models = [
    // SSD MobileNet v1 - Face Detection
    'ssd_mobilenetv1_model-weights_manifest.json',
    'ssd_mobilenetv1_model-shard1',
    'ssd_mobilenetv1_model-shard2',
    
    // Face Landmark 68 - Facial Alignment
    'face_landmark_68_model-weights_manifest.json',
    'face_landmark_68_model-shard1',
    
    // Face Recognition Net - 128-dim Descriptor
    'face_recognition_model-weights_manifest.json',
    'face_recognition_model-shard1',
    'face_recognition_model-shard2',
];

$totalFiles = count($models);
$downloaded = 0;
$skipped = 0;
$failed = 0;

echo "=== PCU Face Recognition Model Downloader ===\n\n";
echo "Source: face-api.js v0.22.2 (pre-trained TensorFlow.js models)\n";
echo "Destination: assets/models/\n";
echo "Files to check: $totalFiles\n\n";

foreach ($models as $file) {
    $targetPath = $modelsDir . '/' . $file;
    $url = $baseUrl . '/' . $file;
    
    // Skip if already exists
    if (file_exists($targetPath) && filesize($targetPath) > 0) {
        $size = number_format(filesize($targetPath) / 1024, 1);
        echo "[SKIP] $file ({$size} KB - already exists)\n";
        $skipped++;
        continue;
    }
    
    echo "[DOWNLOAD] $file ... ";
    
    // Download with error handling
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'user_agent' => 'PCU-RFID-FaceRec-Setup/1.0'
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]
    ]);
    
    $content = @file_get_contents($url, false, $context);
    
    if ($content === false) {
        echo "FAILED ❌\n";
        $failed++;
        continue;
    }
    
    $bytes = file_put_contents($targetPath, $content);
    
    if ($bytes === false) {
        echo "WRITE FAILED ❌\n";
        $failed++;
        continue;
    }
    
    $size = number_format($bytes / 1024, 1);
    echo "OK ✅ ({$size} KB)\n";
    $downloaded++;
}

echo "\n=== Summary ===\n";
echo "Downloaded: $downloaded\n";
echo "Skipped (exists): $skipped\n";
echo "Failed: $failed\n";
echo "Total: " . ($downloaded + $skipped) . "/$totalFiles\n\n";

if ($failed > 0) {
    echo "⚠️  Some files failed to download. Please retry or download manually from:\n";
    echo "    $baseUrl\n\n";
} else {
    echo "✅ All face recognition models are ready!\n";
    echo "   Face recognition can now be used in the gate monitor.\n\n";
}

// Verify all files exist
$allPresent = true;
foreach ($models as $file) {
    if (!file_exists($modelsDir . '/' . $file) || filesize($modelsDir . '/' . $file) === 0) {
        $allPresent = false;
        echo "MISSING: $file\n";
    }
}

if ($allPresent) {
    echo "\n✅ Verification passed - all model files present and non-empty.\n";
} else {
    echo "\n❌ Verification failed - some model files are missing.\n";
}

