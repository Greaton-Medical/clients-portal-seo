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
$comment        = trim((string)($body['comment'] ?? ''));
$csrf_token     = (string)($body['csrf_token'] ?? '');

error_log("[REQUEST-CHANGES DEBUG] Request received. user_id=" . ($user['id'] ?? 'N/A') . " board=" . ($user['monday_board_id'] ?? 'N/A') . " raw_body=" . $raw_body);

if (!$monday_item_id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid item ID']);
    exit;
}
if ($comment === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Comment cannot be empty']);
    exit;
}
if (mb_strlen($comment) > 2000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Comment exceeds 2000 characters']);
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
error_log("[REQUEST-CHANGES DEBUG] monday_get_items response: " . json_encode($fetch));
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

$is_subitem = !empty($item['parent_item']);

if (!MOCK_MONDAY) {
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

// Use the item's actual board ID (subitems live on a separate monday board)
$actual_board_id = (int)($item['board']['id'] ?? $user['monday_board_id']);

if ($is_subitem) {
    // SUBITEM PATH: write feedback to the Revision Notes column.
    // Mae's monday automation reads this column and handles status transitions.
    $revision_col_id = $user['subitem_revision_notes_column_id'] ?: 'text_mm2xqbeh';

    $value_json = json_encode((string)$comment);
    $result = monday_change_column_value($monday_item_id, $actual_board_id, $revision_col_id, $value_json);
    error_log("[REQ-CHANGES] Subitem path: wrote revision notes. item=$monday_item_id board=$actual_board_id col=$revision_col_id result=" . json_encode($result));

    if (isset($result['error'])) {
        $detail = is_array($result['detail'] ?? null) ? json_encode($result['detail']) : ($result['detail'] ?? '');
        echo json_encode(['ok' => false, 'error' => 'Failed to write revision notes on monday.com: ' . ($detail ?: 'unknown error')]);
        exit;
    }
} else {
    // PARENT PATH: post comment. Mae's monday automation reads new comments and handles status.
    $author    = $user['full_name'] ?: $user['username'];
    $full_body = "[Client request from {$author} ({$user['client_name']})]: " . $comment;
    $upd = monday_create_update($monday_item_id, $full_body);
    error_log("[REQ-CHANGES] Parent path: posted comment. item=$monday_item_id result=" . json_encode($upd));

    if (isset($upd['error'])) {
        $detail = is_array($upd['detail'] ?? null) ? json_encode($upd['detail']) : ($upd['detail'] ?? '');
        echo json_encode(['ok' => false, 'error' => 'Failed to post comment on monday.com: ' . ($detail ?: 'unknown error')]);
        exit;
    }
}

// Log action, record change request, and bust cache
db()->prepare("INSERT INTO review_actions (user_id, action, monday_item_id) VALUES (?, 'request_changes', ?)")
    ->execute([$user['id'], $monday_item_id]);

db()->prepare("INSERT INTO task_change_requests (monday_item_id, requested_by_user_id, comment_text) VALUES (?, ?, ?)")
    ->execute([$monday_item_id, $user['id'], $comment]);

cache_invalidate('monday_board_items_' . $user['client_id'] . '_' . $user['monday_board_id']);

echo json_encode(['ok' => true]);
