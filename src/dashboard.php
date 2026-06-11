<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/monday.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/status_map.php';
require_login();

$user = current_user();
$page_title = 'Dashboard — ' . APP_NAME;

$board_id  = (int)$user['monday_board_id'];

// ── 1. Fetch all items from client's monday board (cached) ───────────────────
$force_refresh = isset($_GET['refresh']);
$cache_key     = 'monday_board_items_' . $user['client_id'] . '_' . $board_id;

$raw_items  = [];
$fetched_at = null;
$monday_error = null;

$cached = null;
if (!$force_refresh) {
    $cached = cache_get($cache_key);
} else {
    error_log("[CACHE] BYPASS: $cache_key (forced refresh by user)");
}

if ($cached !== null) {
    $raw_items  = $cached['items'];
    $fetched_at = $cached['fetched_at'];
} else {
    $result = monday_get_items_in_board($board_id);
    if (isset($result['error'])) {
        $monday_error = 'monday.com data temporarily unavailable. Please try again shortly.';
        error_log('[Dashboard] monday_get_items_in_board failed: ' . $result['error']);
    } else {
        $fetched_at = time();
        $raw_items  = $result['items'];
        cache_set($cache_key, ['items' => $raw_items, 'fetched_at' => $fetched_at], MONDAY_CACHE_TTL);
    }
}

// ── 2. Helpers ───────────────────────────────────────────────────────────────
function get_priority_info(string $text): ?array {
    $map = [
        'urgent' => ['label' => 'URGENT', 'color' => '#ef4444'],
        'high'   => ['label' => 'HIGH',   'color' => '#f97316'],
        'medium' => ['label' => 'MEDIUM', 'color' => '#fbbf24'],
        'low'    => ['label' => 'LOW',    'color' => '#6b7280'],
    ];
    return $map[strtolower(trim($text))] ?? null;
}

function relative_time(string $datetime): string {
    $ts = strtotime($datetime);
    if ($ts === false || $ts === 0) return '—';
    $diff = time() - $ts;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return (int)($diff / 60) . 'm ago';
    if ($diff < 86400)  return (int)($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'yesterday';
    return date('M j', $ts);
}

function deadline_class(string $date): string {
    if ($date === '') return '';
    $ts = strtotime($date);
    if ($ts === false) return '';
    if ($ts < time()) return 'deadline-overdue';
    if ($ts < time() + 3 * 86400) return 'deadline-soon';
    return '';
}

function fetched_ago(int $ts): string {
    $s = time() - $ts;
    if ($s < 60)   return "{$s}s ago";
    if ($s < 3600) return floor($s / 60) . 'm ago';
    return floor($s / 3600) . 'h ago';
}

// ── 3. Enrich items with mapped status ───────────────────────────────────────
$enriched      = [];
$status_counts = [];

foreach ($raw_items as $item) {
    if (($item['state'] ?? '') === 'deleted') continue;

    $raw_status   = extract_item_status($item);
    $status       = map_monday_status_to_client_status($raw_status);
    $priority     = get_priority_info(extract_item_column($item, 'priority_Mjj26KQF'));
    $deadline     = extract_item_column($item, 'date4');
    $output_url   = extract_item_link_url($item, 'link_mksbghas');
    $updated_at   = $item['updated_at'] ?? '';
    $submitted_by = extract_submitted_by($item, $user['submitted_by_column_id'] ?? null);

    $enriched[] = [
        'monday_id'        => $item['id'],
        'task_name'        => $item['name'],
        'item_description' => extract_item_description($item),
        'status'           => $status,
        'priority'         => $priority,
        'deadline'         => $deadline,
        'output_url'       => $output_url,
        'updated_at'       => $updated_at,
        'submitted_by'     => $submitted_by,
    ];

    $slug = $status['slug'];
    $status_counts[$slug] = ($status_counts[$slug] ?? 0) + 1;
}

$has_tracking = !empty($user['submitted_by_column_id']);

// ── 3b. Query unresolved change requests for items on this client's board ────
// Items in CLIENT REVIEW get auto-resolved only when monday's updated_at is strictly after
// the change request was submitted — prevents same-session race conditions.
// Items NOT in client_review with a pending request get an overridden "Changes Requested" status.
$unresolved_items = []; // monday_id (string) => latest_requested_at (unix timestamp)
if (!empty($enriched)) {
    $stmt = db()->prepare(
        "SELECT tcr.monday_item_id, MAX(UNIX_TIMESTAMP(tcr.requested_at)) as latest_requested_at
         FROM task_change_requests tcr
         JOIN user_clients uc ON tcr.requested_by_user_id = uc.user_id
         WHERE uc.client_id = ? AND tcr.resolved_at IS NULL
         GROUP BY tcr.monday_item_id"
    );
    $stmt->execute([$user['active_client_id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
        $unresolved_items[(string)$cr['monday_item_id']] = (int)$cr['latest_requested_at'];
    }
}

// Auto-resolve: item back in CLIENT REVIEW AND monday updated it after the change request (+60s buffer)
$to_resolve = [];
foreach ($enriched as $row) {
    if (!isset($unresolved_items[$row['monday_id']])) continue;
    if ($row['status']['slug'] !== 'client_review') continue;
    $item_updated_ts = strtotime($row['updated_at'] ?? '');
    if ($item_updated_ts !== false && $item_updated_ts > ($unresolved_items[$row['monday_id']] + 60)) {
        $to_resolve[] = $row['monday_id'];
    }
}
if (!empty($to_resolve)) {
    $placeholders = implode(',', array_fill(0, count($to_resolve), '?'));
    $params = array_merge([$user['active_client_id']], array_map('intval', $to_resolve));
    db()->prepare(
        "UPDATE task_change_requests tcr
         JOIN user_clients uc ON tcr.requested_by_user_id = uc.user_id
         SET tcr.resolved_at = NOW()
         WHERE uc.client_id = ? AND tcr.resolved_at IS NULL
           AND tcr.monday_item_id IN ($placeholders)"
    )->execute($params);
    foreach ($to_resolve as $rid) {
        unset($unresolved_items[$rid]);
    }
}

// Apply "Changes Requested" override and rebuild status counts
$changes_requested_status = ['label' => 'Changes Requested', 'slug' => 'changes_requested', 'color' => '#f59e0b'];
$status_counts = [];
foreach ($enriched as &$row) {
    if (isset($unresolved_items[$row['monday_id']])) {
        $row['status'] = $changes_requested_status;
    }
    $slug = $row['status']['slug'];
    $status_counts[$slug] = ($status_counts[$slug] ?? 0) + 1;
}
unset($row);

// ── 3c. Compute subitem activity indicators (client_review + changes_requested) ─
$subitem_indicators = []; // parent monday_id => ['review' => n, 'changes' => n]

if (!empty($raw_items)) {
    $all_subitem_ids   = [];
    $parent_sub_status = []; // parent_id => [[id, slug], ...]

    foreach ($raw_items as $item) {
        if (($item['state'] ?? '') === 'deleted') continue;
        $parent_id = (string)$item['id'];
        foreach ($item['subitems'] ?? [] as $sub) {
            if (($sub['state'] ?? '') === 'deleted') continue;
            $sub_id   = (string)$sub['id'];
            $sub_slug = map_monday_status_to_client_status(extract_item_status($sub))['slug'];
            $parent_sub_status[$parent_id][] = ['id' => $sub_id, 'slug' => $sub_slug];
            $all_subitem_ids[] = $sub_id;
        }
    }

    $unresolved_sub_crs = [];
    if (!empty($all_subitem_ids)) {
        $ph   = implode(',', array_fill(0, count($all_subitem_ids), '?'));
        $stmt = db()->prepare(
            "SELECT DISTINCT monday_item_id FROM task_change_requests
             WHERE monday_item_id IN ($ph) AND resolved_at IS NULL"
        );
        $stmt->execute(array_map('intval', $all_subitem_ids));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $unresolved_sub_crs[(string)$id] = true;
        }
    }

    foreach ($parent_sub_status as $parent_id => $subs) {
        $review  = 0;
        $changes = 0;
        foreach ($subs as $sub) {
            if (isset($unresolved_sub_crs[$sub['id']])) {
                $changes++;
            } elseif ($sub['slug'] === 'client_review') {
                $review++;
            }
        }
        if ($review > 0 || $changes > 0) {
            $subitem_indicators[$parent_id] = ['review' => $review, 'changes' => $changes];
        }
    }
}

foreach ($enriched as &$row) {
    $row['subitem_indicator'] = $subitem_indicators[$row['monday_id']] ?? null;
}
unset($row);

// All portal-submitted tasks for this client (any user on the team can see all tasks).
// Tasks without a Submitted By value are internal/manual monday tasks — hide them from the client view.
$my_tasks         = [];
$my_status_counts = [];

if ($has_tracking) {
    foreach ($enriched as $row) {
        if ($row['submitted_by'] === null || $row['submitted_by'] === '') {
            continue; // hide manual/template tasks created directly on monday
        }
        $my_tasks[] = $row;
        // Tasks with subitem reviews (but not own client_review) count under client_review
        // so banner / stats / filter chips all report the same number.
        $slug = (!empty($row['subitem_indicator']['review']) && $row['status']['slug'] !== 'client_review')
            ? 'client_review'
            : $row['status']['slug'];
        $my_status_counts[$slug] = ($my_status_counts[$slug] ?? 0) + 1;
    }
}

// Banner count: tasks needing client attention (client_review OR subitems in review).
// Derived from $my_status_counts so it always matches the stats box and filter chips.
$review_count = $my_status_counts['client_review'] ?? 0;

// Status chip definitions (order matters for display)
$all_statuses = [
    ['label' => 'In Progress',         'slug' => 'in_progress'],
    ['label' => 'Internal Review',     'slug' => 'internal_review'],
    ['label' => 'Awaiting Your Review','slug' => 'client_review'],
    ['label' => 'Changes Requested',   'slug' => 'changes_requested'],
    ['label' => 'Approved',            'slug' => 'approved'],
    ['label' => 'On Hold',             'slug' => 'on_hold'],
    ['label' => 'Scrapped',            'slug' => 'scrapped'],
    ['label' => 'Unknown',             'slug' => 'unknown'],
];

if (!empty($user['form_iframe_url'])) {
    $head_extra = '<link rel="prefetch" href="' . e($user['form_iframe_url']) . '" as="document">';
}
include __DIR__ . '/includes/header.php';
?>
<div class="container-wide">

  <?php if ($monday_error): ?>
    <div class="alert alert-error" style="margin-bottom:20px;">
      Could not fetch live status from monday.com: <?= e($monday_error) ?>
    </div>
  <?php endif; ?>

  <?php if ($review_count > 0): ?>
  <div class="review-banner" id="review-banner">
    <?= icon('circle-alert', 18) ?>
    <span>You have <strong><?= $review_count ?></strong> task<?= $review_count !== 1 ? 's' : '' ?> waiting for your review</span>
    <button class="review-banner-btn" onclick="activateReviewFilter()">
      <?= $review_count === 1 ? 'Review now' : 'View all (' . $review_count . ') pending reviews' ?>
    </button>
  </div>
  <?php endif; ?>

  <!-- Header -->
  <div class="dashboard-header">
    <div>
      <h1>Dashboard</h1>
      <p class="muted">Your submitted requests for <?= e($user['client_name']) ?></p>
    </div>
    <div class="dashboard-actions">
      <?php if ($fetched_at): ?>
        <span class="refresh-info muted small">Updated <?= fetched_ago($fetched_at) ?></span>
      <?php endif; ?>
      <a href="?refresh=1" class="btn-secondary btn-inline"><?= icon('refresh-cw', 14) ?> Refresh</a>
      <a href="/new-request.php" class="btn-primary btn-inline"><?= icon('plus', 14) ?> New Request</a>
    </div>
  </div>

  <?php if (!$has_tracking): ?>
    <!-- ── Tracking not configured ──────────────────────────────────────────── -->
    <div class="empty-state">
      <h3>Tracking not configured</h3>
      <p>Per-user submission tracking isn't configured for this account. Contact your account manager.</p>
    </div>

  <?php else: ?>
    <!-- ── Client Requests ──────────────────────────────────────────────────── -->
    <?php if (empty($my_tasks)): ?>
      <div class="empty-state">
        <h3>No requests yet</h3>
        <p>Submit your first content request by clicking "New Request" above.</p>
      </div>
    <?php else: ?>
      <?php $display_tasks = $my_tasks; $display_counts = $my_status_counts; ?>
      <?php include __DIR__ . '/includes/dashboard_table.php'; ?>
    <?php endif; ?>

  <?php endif; ?>
</div>

<script src="/assets/js/dashboard.js"></script>
<script>
function activateReviewFilter() {
    var chip = document.querySelector('#filter-chips .chip[data-filter="client_review"]');
    if (chip) chip.click();
    var firstReviewRow = document.querySelector('#submissions-table tbody tr[data-status="client_review"]');
    var target = firstReviewRow || document.getElementById('submissions-table');
    if (target) target.scrollIntoView({behavior: 'smooth', block: 'start'});
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
