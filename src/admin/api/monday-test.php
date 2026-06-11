<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/monday.php';
require_admin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']); exit;
}
if (!verify_admin_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch.']); exit;
}

if (MOCK_MONDAY) {
    echo json_encode(['ok' => false, 'mock' => true, 'message' => 'Connection test is disabled in mock mode (MOCK_MONDAY=true).']);
    exit;
}

$board_id = trim($_POST['board_id'] ?? '');
$group_id = trim($_POST['group_id'] ?? '');

if (!filter_var($board_id, FILTER_VALIDATE_INT)) {
    echo json_encode(['ok' => false, 'message' => 'Board ID must be a number.']); exit;
}

$query = '
    query ($boardId: [ID!]) {
        boards(ids: $boardId) {
            id
            name
            groups {
                id
                title
            }
        }
    }
';

$result = monday_query($query, ['boardId' => [(string)$board_id]]);

if (isset($result['error'])) {
    echo json_encode(['ok' => false, 'message' => 'monday.com API error: ' . $result['error']]);
    exit;
}

$boards = $result['data']['boards'] ?? [];
if (empty($boards)) {
    echo json_encode(['ok' => false, 'message' => "Board {$board_id} not found or not accessible with current API token."]);
    exit;
}

$board = $boards[0];
$board_name = $board['name'] ?? 'Unknown';

if ($group_id !== '') {
    $matched_group = null;
    foreach ($board['groups'] ?? [] as $g) {
        if ($g['id'] === $group_id) { $matched_group = $g; break; }
    }
    if (!$matched_group) {
        $available = implode(', ', array_column($board['groups'] ?? [], 'id'));
        echo json_encode(['ok' => false, 'message' => "Board '{$board_name}' found, but group '{$group_id}' does not exist. Available: {$available}"]);
        exit;
    }
    echo json_encode(['ok' => true, 'message' => "✓ Board '{$board_name}' · Group '{$matched_group['title']}' — connection OK"]);
} else {
    echo json_encode(['ok' => true, 'message' => "✓ Board '{$board_name}' found. Enter a Group ID to verify the group."]);
}
