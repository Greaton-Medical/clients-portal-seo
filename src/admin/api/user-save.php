<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']); exit;
}
if (!verify_admin_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch.']); exit;
}

// ── Input validation ────────────────────────────────────────────────────────
$id        = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
$username  = trim($_POST['username'] ?? '');
$full_name = trim($_POST['full_name'] ?? '');
$email     = trim($_POST['email'] ?? '');
$role      = $_POST['role'] ?? 'user';
$active    = filter_var($_POST['active'] ?? '0', FILTER_VALIDATE_INT) ? 1 : 0;

// Multi-client fields
$client_ids_raw      = $_POST['clients'] ?? [];
$client_ids          = array_values(array_unique(array_filter(array_map('intval', (array)$client_ids_raw))));
$primary_client_id   = filter_var($_POST['primary_client_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;

if (empty($client_ids)) {
    echo json_encode(['ok' => false, 'error' => 'At least one client must be selected.']); exit;
}
if (!$primary_client_id || !in_array($primary_client_id, $client_ids, true)) {
    $primary_client_id = $client_ids[0];
}
if ($username === '' || !preg_match('/^[a-z0-9_]+$/i', $username)) {
    echo json_encode(['ok' => false, 'error' => 'Username is required and must contain only letters, numbers, and underscores.']); exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'A valid email address is required.']); exit;
}
if (!in_array($role, ['user', 'client_admin'], true)) {
    $role = 'user';
}

// Verify all selected clients exist and are active
$ph           = implode(',', array_fill(0, count($client_ids), '?'));
$client_check = db()->prepare("SELECT id FROM clients WHERE id IN ($ph) AND active = 1");
$client_check->execute($client_ids);
$valid_ids    = array_map('intval', array_column($client_check->fetchAll(), 'id'));
if (count($valid_ids) !== count($client_ids)) {
    echo json_encode(['ok' => false, 'error' => 'One or more selected clients do not exist or are inactive.']); exit;
}

$db     = db();
$is_new = !$id;

// Username uniqueness within the same primary client
$uniq = $db->prepare("SELECT id FROM users WHERE client_id = ? AND username = ? AND id != ?");
$uniq->execute([$primary_client_id, $username, $id ?: 0]);
if ($uniq->fetch()) {
    echo json_encode(['ok' => false, 'error' => "Username '{$username}' is already taken for this client."]); exit;
}

try {
    $db->beginTransaction();

    if ($is_new) {
        $temp_pw = generate_temp_password();
        $hash    = password_hash($temp_pw, PASSWORD_BCRYPT);

        $stmt = $db->prepare("
            INSERT INTO users (client_id, username, email, password_hash, full_name, role, active)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$primary_client_id, $username, $email, $hash, $full_name ?: null, $role]);
        $new_id = (int)$db->lastInsertId();

        foreach ($client_ids as $cid) {
            $db->prepare("INSERT INTO user_clients (user_id, client_id, is_primary) VALUES (?, ?, ?)")
               ->execute([$new_id, $cid, $cid === $primary_client_id ? 1 : 0]);
        }

        $db->commit();
        echo json_encode(['ok' => true, 'id' => $new_id, 'username' => $username, 'password' => $temp_pw]);
    } else {
        $stmt = $db->prepare("
            UPDATE users SET client_id=?, username=?, email=?, full_name=?, role=?, active=? WHERE id=?
        ");
        $stmt->execute([$primary_client_id, $username, $email, $full_name ?: null, $role, $active, $id]);

        $db->prepare("DELETE FROM user_clients WHERE user_id = ?")->execute([$id]);
        foreach ($client_ids as $cid) {
            $db->prepare("INSERT INTO user_clients (user_id, client_id, is_primary) VALUES (?, ?, ?)")
               ->execute([$id, $cid, $cid === $primary_client_id ? 1 : 0]);
        }

        $db->commit();
        echo json_encode(['ok' => true, 'id' => $id, 'username' => $username]);
    }
} catch (Exception $e) {
    $db->rollBack();
    error_log('[user-save] DB error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error. Please try again.']);
}
