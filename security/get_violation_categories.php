<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION['security_logged_in']) || $_SESSION['security_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

require_permission('violation.record', [
    'actor_role' => 'security',
    'response' => 'json',
    'message' => 'Forbidden: missing permission violation.record.',
]);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

function db_type_to_policy_type(string $dbType): string {
    $type = strtolower(trim($dbType));
    if ($type === 'grave') {
        return 'major';
    }
    if ($type === 'major') {
        return 'moderate';
    }
    return 'minor';
}

try {
    $pdo = pdo();

    $stmt = $pdo->query('SELECT id, name, type, description, default_sanction, article_reference FROM violation_categories WHERE is_active = 1 ORDER BY FIELD(type, "minor", "major", "grave"), name ASC, id ASC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [
        'minor' => [],
        'moderate' => [],
        'major' => []
    ];
    $flat = [];
    $seenCategoryKeys = [];

    foreach ($rows as $row) {
        $policyType = db_type_to_policy_type((string)($row['type'] ?? 'minor'));
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        // DB may contain duplicate active rows for the same logical category.
        // Keep the first (lowest id due ORDER BY ... id ASC) to preserve original choices.
        $dedupeKey = strtolower($policyType . '|' . $name);
        if (isset($seenCategoryKeys[$dedupeKey])) {
            continue;
        }
        $seenCategoryKeys[$dedupeKey] = true;

        $formatted = [
            'id' => (int)$row['id'],
            'name' => $name,
            'type' => $policyType,
            'db_type' => (string)($row['type'] ?? 'minor'),
            'description' => (string)($row['description'] ?? ''),
            'default_sanction' => (string)($row['default_sanction'] ?? ''),
            'article_reference' => (string)($row['article_reference'] ?? ''),
        ];

        $grouped[$policyType][] = $formatted;
        $flat[] = $formatted;
    }

    echo json_encode([
        'success' => true,
        'categories' => $grouped,
        'flat' => $flat,
        'meta' => [
            'source' => 'database_read_only',
            'count' => count($flat),
            'duplicates_filtered' => max(0, count($rows) - count($flat))
        ]
    ]);
} catch (PDOException $e) {
    error_log('get_violation_categories error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load violation categories']);
}
