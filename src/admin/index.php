<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/monday.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/status_map.php';
require_admin();

$page_title = 'Dashboard — Admin';

$db = db();

// Stats
$total_clients = (int)$db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$total_users   = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();

// Recent activity — pull from Monday.com (cached 60s) across all active clients
$active_clients = $db->query("
    SELECT id, name, monday_board_id, submitted_by_column_id, accent_color
    FROM clients WHERE active = 1 ORDER BY name
")->fetchAll();

$all_recent = [];
foreach ($active_clients as $client) {
    if (empty($client['monday_board_id'])) continue;
    $cache_key = 'monday_board_items_' . $client['id'] . '_' . $client['monday_board_id'];
    $cached = cache_get($cache_key);
    if ($cached !== null) {
        $items = $cached['items'];
    } else {
        $result = monday_get_items_in_board((int)$client['monday_board_id']);
        if (isset($result['error'])) continue;
        $items = $result['items'];
        cache_set($cache_key, ['items' => $items, 'fetched_at' => time()], MONDAY_CACHE_TTL);
    }
    foreach ($items as $item) {
        if (($item['state'] ?? '') === 'deleted') continue;
        $submitted_by = extract_submitted_by($item, $client['submitted_by_column_id'] ?? null);
        if (empty($client['submitted_by_column_id']) || $submitted_by === null || $submitted_by === '') continue;
        $all_recent[] = [
            'task_name'    => $item['name'],
            'updated_at'   => $item['updated_at'] ?? '',
            'client_name'  => $client['name'],
            'accent_color' => $client['accent_color'],
            'submitted_by' => $submitted_by,
            'status'       => map_monday_status_to_client_status(extract_item_status($item)),
        ];
    }
}

usort($all_recent, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
$recent = array_slice($all_recent, 0, 10);
$total_submissions = count($all_recent);

$week_ago = date('Y-m-d\TH:i:s\Z', strtotime('-7 days'));
$week_submissions = count(array_filter($all_recent, fn($r) => $r['updated_at'] >= $week_ago));

// Active clients quick list
$clients = $db->query("SELECT id, name, active FROM clients ORDER BY name")->fetchAll();

include __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-container">

  <div class="admin-page-header">
    <div>
      <h1>Admin Dashboard</h1>
      <p>Overview across all clients</p>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-value"><?= $total_clients ?></div>
      <div class="stat-label">Active Clients</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $total_users ?></div>
      <div class="stat-label">Total Users</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $total_submissions ?></div>
      <div class="stat-label">Total Submissions</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $week_submissions ?></div>
      <div class="stat-label">Submissions This Week</div>
    </div>
  </div>

  <div class="admin-two-col">

    <!-- Recent Activity -->
    <div class="admin-panel">
      <div class="admin-panel-header">
        <h2>Recent Submissions</h2>
        <a href="/admin/submissions.php">View all <?= icon('chevron-right', 13) ?></a>
      </div>
      <?php if (empty($recent)): ?>
        <p class="muted small" style="padding:20px;">No submissions yet.</p>
      <?php else: ?>
        <ul class="activity-list">
          <?php foreach ($recent as $row): ?>
            <li class="activity-item">
              <span class="activity-dot" style="background:<?= e($row['accent_color']) ?>"></span>
              <span class="activity-text">
                <strong><?= e($row['task_name']) ?></strong>
                <br>
                <span class="muted"><?= e($row['client_name']) ?> · <?= e($row['submitted_by']) ?></span>
              </span>
              <span class="activity-time muted small">
                <?= $row['updated_at'] ? e(date('M j, H:i', strtotime($row['updated_at']))) : '—' ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <!-- Quick Links -->
    <div class="admin-panel">
      <div class="admin-panel-header">
        <h2>Quick Actions</h2>
      </div>
      <div class="quick-links">
        <a href="/admin/client-edit.php" class="quick-link-item">
          <span class="quick-link-icon"><?= icon('building-2', 18) ?></span> Add New Client
        </a>
        <a href="/admin/user-edit.php" class="quick-link-item">
          <span class="quick-link-icon"><?= icon('user', 18) ?></span> Add New User
        </a>
        <a href="/admin/clients.php" class="quick-link-item">
          <span class="quick-link-icon"><?= icon('file-text', 18) ?></span> Manage Clients
        </a>
        <a href="/admin/users.php" class="quick-link-item">
          <span class="quick-link-icon"><?= icon('users', 18) ?></span> Manage Users
        </a>
        <a href="/admin/submissions.php" class="quick-link-item">
          <span class="quick-link-icon"><?= icon('file-text', 18) ?></span> View All Submissions
        </a>
      </div>
    </div>

  </div>
</div>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
