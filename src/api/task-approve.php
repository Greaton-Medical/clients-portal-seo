<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/monday.php';
require_once __DIR__ . '/../includes/status_map.php';
require_once __DIR__ . '/../includes/cache.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require_login();
$user = current_user();

$raw_body       = file_get_contents('php://input');
$body           = json_decode($raw_body, true) ?? [];
$monday_item_id = (int)($body['monday_item_id'] ?? 0);
$csrf_token     = (string)($body['csrf_token'] ?? '');

error_log("[APPROVE DEBUG] Request received. user_id=" . ($user['id'] ?? 'N/A') . " board=" . ($user['monday_board_id'] ?? 'N/A') . " raw_body=" . $raw_body);

if (!$monday_item_id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid item ID']);
    exit;
}
if (!verify_csrf($csrf_token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Rate limit: max 10 review actions per user per hour
$stmt = db()->prepare("SELECT COUNT(*) FROM review_actions WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$stmt->execute([$user['id']]);
if ((int)$stmt->fetchColumn() >= 10) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many review actions. Please wait before trying again.']);
    exit;
}

// Fetch item and verify ownership
$fetch = monday_get_items([$monday_item_id]);
error_log("[APPROVE DEBUG] monday_get_items response: " . json_encode($fetch));
if (isset($fetch['error'])) {
    echo json_encode(['ok' => false, 'error' => 'Could not fetch task from monday.com']);
    exit;
}
$item = $fetch['data']['items'][0] ?? null;
if ($item === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Task not found']);
    exit;
}
if (!MOCK_MONDAY) {
    $is_subitem = !empty($item['parent_item']);
    if ($is_subitem) {
        $parent_board = (string)($item['parent_item']['board']['id'] ?? '');
        $allowed = ($parent_board === (string)$user['monday_board_id']);
    } else {
        $allowed = ((string)($item['board']['id'] ?? '') === (string)$user['monday_board_id']);
    }
    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to modify this task']);
        exit;
    }
}

// Find the status column to update
$col_id = find_task_status_column_id($item);
error_log("[APPROVE DEBUG] Using status column: " . ($col_id ?? 'NOT FOUND') . " for item $monday_item_id");
if (!$col_id) {
    echo json_encode(['ok' => false, 'error' => 'Task status column not found on monday board, cannot update status.']);
    exit;
}

// Use the item's actual board (subitems live on a separate monday board)
$actual_board_id = (int)($item['board']['id'] ?? $user['monday_board_id']);

// Apply the change
$mut = monday_change_item_status($monday_item_id, $actual_board_id, $col_id, 'APPROVED');
error_log("[APPROVE DEBUG] monday_change_item_status response: " . json_encode($mut));
if (isset($mut['error'])) {
    $detail = is_array($mut['detail'] ?? null) ? json_encode($mut['detail']) : ($mut['detail'] ?? '');
    echo json_encode(['ok' => false, 'error' => 'Failed to update status on monday.com: ' . ($detail ?: 'unknown error')]);
    exit;
}

// Log action and bust cache
db()->prepare("INSERT INTO review_actions (user_id, action, monday_item_id) VALUES (?, 'approve', ?)")
    ->execute([$user['id'], $monday_item_id]);

// Record approval timestamp so task detail page can hide post-approval team comments
db()->prepare("INSERT INTO task_approvals (monday_item_id, approved_by_user_id, approved_at)
               VALUES (?, ?, NOW())
               ON DUPLICATE KEY UPDATE approved_at = NOW(), approved_by_user_id = VALUES(approved_by_user_id)")
    ->execute([$monday_item_id, $user['id']]);

cache_invalidate('monday_board_items_' . $user['client_id'] . '_' . $user['monday_board_id']);

echo json_encode(['ok' => true]);
