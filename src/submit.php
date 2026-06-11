<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/monday.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /new-request.php');
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Security token mismatch. Please refresh and try again.';
    header('Location: /new-request.php');
    exit;
}

$user = current_user();
if (!$user) {
    header('Location: /index.php');
    exit;
}

$task_name = trim($_POST['name'] ?? '');
if ($task_name === '') {
    $_SESSION['flash_error'] = 'Task description is required.';
    header('Location: /new-request.php');
    exit;
}

$category_index = $_POST['single_selectns3rnbh'] ?? '';
if ($category_index === '') {
    $_SESSION['flash_error'] = 'Content category is required.';
    header('Location: /new-request.php');
    exit;
}

// All monday columns we map to
$column_keys = [
    'single_selectns3rnbh', // Content Category
    'single_selectk3z4saf', // Ad Media Type
    'single_selectlcz9v33', // Social Media Type
    'single_select9mx584c', // Graphic Post Type
    'single_select0c3sgtk', // Video Post Type
    'single_selecterkc2dl', // Printables Type
    'single_selectxpjz6ur', // Website Content Type
    'single_selectuzq2sgk', // Branding Type
    'single_selectbtzlng9', // Email Content Type
    'long_textx5aid0ob',    // Description
    'long_textmx37gkay',    // Copy
    'priority_Mjj26KQF',    // Priority
    'date4',                // Deadline
    'link0vw7l3n2',         // Sample link
];

$raw_inputs = [];
foreach ($column_keys as $key) {
    $raw_inputs[$key] = $_POST[$key] ?? '';
}

$column_values = format_column_values($raw_inputs);

// Submit to monday (or mock)
$board_id = (int)$user['monday_board_id'];
$group_id = $user['monday_group_id'];

$result = monday_create_item($board_id, $group_id, $task_name, $column_values);

if (isset($result['error'])) {
    error_log('Monday submit failed: ' . json_encode($result));
    $_SESSION['flash_error'] = 'Failed to create request on monday.com: ' . $result['error'];
    header('Location: /new-request.php');
    exit;
}

$monday_item_id = $result['data']['create_item']['id'] ?? null;
$is_mock = !empty($result['_mock']);

if (!$monday_item_id) {
    error_log('Monday returned no item id: ' . json_encode($result));
    $_SESSION['flash_error'] = 'monday.com returned an unexpected response. Check logs.';
    header('Location: /new-request.php');
    exit;
}

// ── Upload files to monday file column ──────────────────────────────────────
$files_uploaded = 0;
$allowed_mimes  = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'video/mp4', 'video/quicktime',
    'application/zip', 'application/x-zip-compressed',
];
$max_file_bytes = 25 * 1024 * 1024; // 25 MB

if (!empty($_FILES['sample_files']['name'][0])) {
    $file_count = min(count($_FILES['sample_files']['name']), 10);
    for ($i = 0; $i < $file_count; $i++) {
        if ($_FILES['sample_files']['error'][$i] !== UPLOAD_ERR_OK) {
            error_log("[Upload] File index {$i} error code: " . $_FILES['sample_files']['error'][$i]);
            continue;
        }

        $original_name = basename($_FILES['sample_files']['name'][$i]);
        $tmp_path      = $_FILES['sample_files']['tmp_name'][$i];
        $file_size     = (int)$_FILES['sample_files']['size'][$i];

        if ($file_size > $max_file_bytes) {
            error_log("[Upload] '{$original_name}' exceeds 25 MB limit, skipping");
            continue;
        }

        $mime = mime_content_type($tmp_path) ?: '';
        if (!in_array($mime, $allowed_mimes, true)) {
            error_log("[Upload] '{$original_name}' has disallowed MIME type '{$mime}', skipping");
            continue;
        }

        $safe_name  = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original_name);
        $stable_tmp = sys_get_temp_dir() . '/' . uniqid('gm_upload_') . '_' . $safe_name;

        try {
            if (!move_uploaded_file($tmp_path, $stable_tmp)) {
                error_log("[Upload] move_uploaded_file failed for '{$original_name}'");
                $stable_tmp = null;
                continue;
            }
            $up = monday_upload_file_to_item((int)$monday_item_id, 'fileuz19sv2z', $stable_tmp, $original_name);
            if (isset($up['error'])) {
                error_log("[Upload] Monday upload failed for '{$original_name}': " . json_encode($up));
            } else {
                $files_uploaded++;
            }
        } finally {
            if ($stable_tmp && file_exists($stable_tmp)) {
                @unlink($stable_tmp);
            }
        }
    }
}

// ── Create subitems (versions) ───────────────────────────────────────────────
$versions = array_values(array_filter(
    array_map('trim', $_POST['versions'] ?? []),
    fn($v) => $v !== ''
));
$subitems_count = 0;
foreach (array_slice($versions, 0, 10) as $version_name) {
    $sub = monday_create_subitem((int)$monday_item_id, $version_name);
    if (isset($sub['error'])) {
        error_log('[Monday] Subitem creation failed for "' . $version_name . '": ' . json_encode($sub));
    } else {
        $subitems_count++;
    }
}

// ── Save mapping to our DB ───────────────────────────────────────────────────
try {
    $stmt = db()->prepare("
        INSERT INTO submissions
            (user_id, client_id, monday_item_id, task_name, content_category, is_mock, files_uploaded, subitems_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $user['id'],
        $user['client_id'],
        (int)$monday_item_id,
        $task_name,
        content_category_label($category_index),
        $is_mock ? 1 : 0,
        $files_uploaded,
        $subitems_count,
    ]);
} catch (Exception $e) {
    error_log('DB insert failed after monday success: ' . $e->getMessage());
}

$redirect = '/new-request.php?ok=1&id=' . urlencode($monday_item_id)
    . '&files=' . $files_uploaded
    . '&versions=' . $subitems_count;
if ($is_mock) $redirect .= '&mock=1';
header('Location: ' . $redirect);
exit;
