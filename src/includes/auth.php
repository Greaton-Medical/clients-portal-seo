<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/icons.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /index.php');
        exit;
    }
    // Ensure user has at least one active client assignment
    $user = current_user();
    if (!$user || empty($user['active_client_id'])) {
        logout_user();
        header('Location: /index.php');
        exit;
    }
}

function current_user(): ?array {
    if (!is_logged_in()) return null;
    static $user = null;
    if ($user !== null) return $user;

    // Load all active client assignments for this user via the pivot table
    $stmt = db()->prepare("
        SELECT u.id, u.username, u.email, u.full_name, u.role,
               c.id   AS cid,  c.name AS cname, c.slug AS cslug,
               c.monday_board_id, c.monday_group_id,
               c.accent_color, c.logo_url, c.form_iframe_url,
               c.submitted_by_column_id, c.hidden_column_ids,
               c.subitem_revision_notes_column_id,
               c.task_status_column_id, c.production_status_column_id, c.copy_review_enabled,
               uc.is_primary
        FROM users u
        JOIN user_clients uc ON uc.user_id = u.id
        JOIN clients c       ON uc.client_id = c.id AND c.active = 1
        WHERE u.id = ? AND u.active = 1
        ORDER BY uc.is_primary DESC, c.id ASC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) return null;

    $clients = [];
    foreach ($rows as $row) {
        $clients[] = [
            'id'                               => (int)$row['cid'],
            'name'                             => $row['cname'],
            'slug'                             => $row['cslug'],
            'monday_board_id'                  => $row['monday_board_id'],
            'monday_group_id'                  => $row['monday_group_id'],
            'accent_color'                     => $row['accent_color'],
            'logo_url'                         => $row['logo_url'],
            'form_iframe_url'                  => $row['form_iframe_url'],
            'submitted_by_column_id'           => $row['submitted_by_column_id'],
            'hidden_column_ids'                => $row['hidden_column_ids'],
            'subitem_revision_notes_column_id' => $row['subitem_revision_notes_column_id'],
            'task_status_column_id'            => $row['task_status_column_id'],
            'production_status_column_id'      => $row['production_status_column_id'],
            'copy_review_enabled'              => (bool)$row['copy_review_enabled'],
            'is_primary'                       => (bool)$row['is_primary'],
        ];
    }

    // Resolve active client: session → primary → first
    $active      = null;
    $session_cid = (int)($_SESSION['active_client_id'] ?? 0);
    if ($session_cid) {
        foreach ($clients as $c) { if ($c['id'] === $session_cid) { $active = $c; break; } }
    }
    if (!$active) {
        foreach ($clients as $c) { if ($c['is_primary']) { $active = $c; break; } }
    }
    if (!$active) $active = $clients[0];

    $first = $rows[0];
    $user  = [
        'id'                               => (int)$first['id'],
        'username'                         => $first['username'],
        'email'                            => $first['email'],
        'full_name'                        => $first['full_name'],
        'role'                             => $first['role'],
        // Active-client fields exposed at top level for backwards compatibility
        'client_id'                        => $active['id'],
        'client_name'                      => $active['name'],
        'client_slug'                      => $active['slug'],
        'monday_board_id'                  => $active['monday_board_id'],
        'monday_group_id'                  => $active['monday_group_id'],
        'accent_color'                     => $active['accent_color'],
        'logo_url'                         => $active['logo_url'],
        'form_iframe_url'                  => $active['form_iframe_url'],
        'submitted_by_column_id'           => $active['submitted_by_column_id'],
        'hidden_column_ids'                => $active['hidden_column_ids'],
        'subitem_revision_notes_column_id' => $active['subitem_revision_notes_column_id'],
        'task_status_column_id'            => $active['task_status_column_id'],
        'production_status_column_id'      => $active['production_status_column_id'],
        'copy_review_enabled'              => $active['copy_review_enabled'],
        // Multi-client accessors
        'clients'                          => $clients,
        'active_client'                    => $active,
        'active_client_id'                 => $active['id'],
    ];

    return $user;
}

/**
 * Switch the currently active client for this session.
 * Only succeeds if the user is explicitly assigned to $client_id.
 * Returns true on success, false (+ error_log) on unauthorised attempt.
 */
function switch_active_client(int $client_id): bool {
    $user = current_user();
    if (!$user) return false;
    foreach ($user['clients'] as $c) {
        if ($c['id'] === $client_id) {
            $_SESSION['active_client_id'] = $client_id;
            return true;
        }
    }
    error_log("[AUTH] switch_active_client: user {$user['id']} attempted unauthorized switch to client {$client_id}");
    return false;
}

function login_user(string $username, string $password): array {
    // Rate limit: max 5 failed attempts per IP in 15 min
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = db()->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE ip_address = ? AND success = 0
          AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $stmt->execute([$ip]);
    if ((int)$stmt->fetchColumn() >= 5) {
        return ['ok' => false, 'error' => 'Too many failed attempts. Try again in 15 minutes.'];
    }

    $stmt = db()->prepare("
        SELECT u.*, c.active AS client_active
        FROM users u
        JOIN clients c ON u.client_id = c.id
        WHERE u.username = ? AND u.active = 1
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $success = false;
    if ($user && $user['client_active'] && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id']    = $user['id'];
        unset($_SESSION['active_client_id']); // reset to primary on fresh login
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        session_regenerate_id(true);

        db()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
            ->execute([$user['id']]);

        $success = true;
    }

    db()->prepare("INSERT INTO login_attempts (ip_address, username, success) VALUES (?, ?, ?)")
        ->execute([$ip, $username, $success ? 1 : 0]);

    return $success
        ? ['ok' => true]
        : ['ok' => false, 'error' => 'Invalid username or password.'];
}

function logout_user(): void {
    unset($_SESSION['user_id'], $_SESSION['active_client_id'], $_SESSION['csrf_token']);
    // Only fully destroy the session if no admin is also logged in
    if (empty($_SESSION['admin_id'])) {
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool {
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// HTML escape helper
function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Render a monday comment body safely.
// Monday sends HTML for rich comments; plain-text comments have no tags.
function sanitize_comment_html(string $html): string {
    $allowed = '<br><p><strong><em><b><i><u><a><ul><ol><li>';
    $clean = strip_tags($html, $allowed);
    $clean = preg_replace_callback('/<a\s+([^>]*)>/i', function ($m) {
        if (preg_match('/href\s*=\s*["\']?([^"\'>\s]+)/i', $m[1], $hrefMatch)) {
            $url = $hrefMatch[1];
            if (preg_match('/^(https?:\/\/|mailto:)/i', $url)) {
                return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener">';
            }
        }
        return '';
    }, $clean);
    return $clean;
}

function render_comment_body(string $body): string {
    if (preg_match('/<(br|p|strong|em|a|ul|ol|li)\b/i', $body)) {
        return sanitize_comment_html($body);
    }
    return nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
}

// =============================================
// ADMIN AUTH — session key: admin_id
// Completely separate from client user auth.
// Both admin_id and user_id can coexist in the
// same session (e.g. testing in same browser).
// =============================================

function is_admin_logged_in(): bool {
    return isset($_SESSION['admin_id']);
}

function require_admin(): void {
    if (!is_admin_logged_in()) {
        header('Location: /admin/login.php');
        exit;
    }
}

function current_admin(): ?array {
    if (!is_admin_logged_in()) return null;
    static $admin = null;
    if ($admin === null) {
        $stmt = db()->prepare("SELECT id, username, email, full_name FROM admins WHERE id = ? AND active = 1 LIMIT 1");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch() ?: null;
    }
    return $admin;
}

function login_admin(string $username, string $password): array {
    $stmt = db()->prepare("SELECT * FROM admins WHERE username = ? AND active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
        session_regenerate_id(true);

        db()->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);

        return ['ok' => true];
    }
    return ['ok' => false, 'error' => 'Invalid username or password.'];
}

function logout_admin(): void {
    unset($_SESSION['admin_id'], $_SESSION['admin_csrf_token']);
    // Only fully destroy the session if no client user is also logged in
    if (empty($_SESSION['user_id'])) {
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}

function admin_csrf_token(): string {
    if (empty($_SESSION['admin_csrf_token'])) {
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf_token'];
}

function verify_admin_csrf(string $token): bool {
    return !empty($_SESSION['admin_csrf_token']) && hash_equals($_SESSION['admin_csrf_token'], $token);
}

/**
 * Generate a 12-char temp password.
 * Excludes ambiguous chars (0/O/1/l/I) for easy reading.
 * Uses random_int (CSPRNG) for selection and shuffle.
 */
function generate_temp_password(): string {
    $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower   = 'abcdefghjkmnpqrstuvwxyz';
    $digits  = '23456789';
    $symbols = '!@#$%';
    $all     = $upper . $lower . $digits . $symbols;

    // Guarantee at least one of each character class
    $chars = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)],
    ];
    for ($i = 0; $i < 8; $i++) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    // Fisher-Yates shuffle
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}
