<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/monday.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/status_map.php';
require_admin();

$page_title = 'Submissions — Admin';

// Filters
$f_client       = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT) ?: 0;
$f_status       = $_GET['status']       ?? '';
$f_submitted_by = $_GET['submitted_by'] ?? '';
$f_from         = $_GET['from']         ?? '';
$f_to           = $_GET['to']           ?? '';
$force_refresh  = isset($_GET['refresh']);
$show_all       = isset($_GET['show_all']);

// Validate date strings
$f_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from) ? $f_from : '';
$f_to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to)   ? $f_to   : '';

// Get active clients (all, or filtered to one)
$clients_sql = "SELECT id, name, monday_board_id, monday_group_id, accent_color, submitted_by_column_id FROM clients WHERE active = 1";
if ($f_client) {
    $cs = db()->prepare($clients_sql . " AND id = ? ORDER BY name");
    $cs->execute([$f_client]);
} else {
    $cs = db()->query($clients_sql . " ORDER BY name");
}
$active_clients = $cs->fetchAll();

// Fetch all unresolved change requests (admin-wide)
$cr_rows = db()->query("SELECT DISTINCT monday_item_id FROM task_change_requests WHERE resolved_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
$unresolved_change_items = array_flip($cr_rows);

// Fetch items per client (cached 60s)
$all_rows    = [];
$last_cached = null;

foreach ($active_clients as $client) {
    $cache_key = 'monday_board_items_' . $client['id'] . '_' . $client['monday_board_id'];

    $cached = null;
    if (!$force_refresh) {
        $cached = cache_get($cache_key);
    }

    if ($cached !== null) {
        $items      = $cached['items'];
        $fetched_at = $cached['fetched_at'];
    } else {
        $result = monday_get_items_in_board((int)$client['monday_board_id']);
        if (isset($result['error'])) {
            error_log("[Admin Submissions] monday_get_items_in_board failed for client {$client['id']} ({$client['name']}): {$result['error']}");
            continue;
        }
        $items      = $result['items'];
        $fetched_at = time();
        cache_set($cache_key, ['items' => $items, 'fetched_at' => $fetched_at], MONDAY_CACHE_TTL);
    }

    if ($last_cached === null || $fetched_at < $last_cached) {
        $last_cached = $fetched_at;
    }

    foreach ($items as $item) {
        if (($item['state'] ?? '') === 'deleted') continue;
        $all_rows[] = $item + ['_client' => $client];
    }
}

// Enrich rows with status + submitted_by (needed for filters)
$changes_requested_status = ['label' => 'Changes Requested', 'slug' => 'changes_requested', 'color' => '#f59e0b'];
foreach ($all_rows as &$row) {
    $raw_st               = extract_item_status($row);
    $mapped               = map_monday_status_to_client_status($raw_st);
    $row['_status']       = isset($unresolved_change_items[$row['id']]) || isset($unresolved_change_items[(int)$row['id']]) ? $changes_requested_status : $mapped;
    $row['_submitted_by'] = extract_submitted_by($row, $row['_client']['submitted_by_column_id'] ?? null);
}
unset($row);

// ── Compute subitem activity indicators ──────────────────────────────────────
$admin_subitem_indicators = []; // parent monday_id => ['review' => n, 'changes' => n]

if (!empty($all_rows)) {
    $all_sub_ids   = [];
    $parent_sub_st = []; // parent_id => [[id, slug], ...]

    foreach ($all_rows as $row) {
        $parent_id = (string)$row['id'];
        foreach ($row['subitems'] ?? [] as $sub) {
            if (($sub['state'] ?? '') === 'deleted') continue;
            $sub_id   = (string)$sub['id'];
            $sub_slug = map_monday_status_to_client_status(extract_item_status($sub))['slug'];
            $parent_sub_st[$parent_id][] = ['id' => $sub_id, 'slug' => $sub_slug];
            $all_sub_ids[] = $sub_id;
        }
    }

    $unresolved_sub_admin = [];
    if (!empty($all_sub_ids)) {
        $ph   = implode(',', array_fill(0, count($all_sub_ids), '?'));
        $stmt = db()->prepare(
            "SELECT DISTINCT monday_item_id FROM task_change_requests
             WHERE monday_item_id IN ($ph) AND resolved_at IS NULL"
        );
        $stmt->execute(array_map('intval', $all_sub_ids));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $unresolved_sub_admin[(string)$id] = true;
        }
    }

    foreach ($parent_sub_st as $parent_id => $subs) {
        $review  = 0;
        $changes = 0;
        foreach ($subs as $sub) {
            if (isset($unresolved_sub_admin[$sub['id']])) {
                $changes++;
            } elseif ($sub['slug'] === 'client_review') {
                $review++;
            }
        }
        if ($review > 0 || $changes > 0) {
            $admin_subitem_indicators[$parent_id] = ['review' => $review, 'changes' => $changes];
        }
    }
}

// Default view: only portal submissions (tasks with a Submitted By value, from clients with tracking configured).
// "Show all" toggle bypasses this to expose manual/template tasks for debugging.
if (!$show_all) {
    $all_rows = array_values(array_filter($all_rows, function ($r) {
        // Skip client entirely if tracking column not configured — can't distinguish submissions
        if (empty($r['_client']['submitted_by_column_id'])) return false;
        // Keep only tasks that have a Submitted By value
        return $r['_submitted_by'] !== null && $r['_submitted_by'] !== '';
    }));
}

// Collect unique submitted_by values for the filter dropdown (after portal filter, before other filters)
$all_submitted_by_values = [];
foreach ($all_rows as $r) {
    $v = $r['_submitted_by'];
    if ($v !== null && !in_array($v, $all_submitted_by_values, true)) {
        $all_submitted_by_values[] = $v;
    }
}
sort($all_submitted_by_values);

// Apply user-selected filters
if ($f_from) {
    $all_rows = array_values(array_filter($all_rows, fn($r) => substr($r['updated_at'] ?? '', 0, 10) >= $f_from));
}
if ($f_to) {
    $all_rows = array_values(array_filter($all_rows, fn($r) => substr($r['updated_at'] ?? '', 0, 10) <= $f_to));
}
if ($f_status) {
    $all_rows = array_values(array_filter($all_rows, fn($r) => $r['_status']['slug'] === $f_status));
}
if ($f_submitted_by === '(unknown)') {
    $all_rows = array_values(array_filter($all_rows, fn($r) => $r['_submitted_by'] === null));
} elseif ($f_submitted_by !== '') {
    $all_rows = array_values(array_filter($all_rows, fn($r) => $r['_submitted_by'] === $f_submitted_by));
}

// Sort newest first
usort($all_rows, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));

// Client list for filter dropdown
$clients_all = db()->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

function admin_fetched_ago(int $ts): string {
    $s = time() - $ts;
    if ($s < 60)   return "{$s}s ago";
    if ($s < 3600) return floor($s / 60) . 'm ago';
    return floor($s / 3600) . 'h ago';
}

include __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-container">

  <div class="admin-page-header">
    <div>
      <h1>All Submissions</h1>
      <p><?= count($all_rows) ?> item<?= count($all_rows) !== 1 ? 's' : '' ?></p>
    </div>
    <a href="?<?= http_build_query(array_merge($_GET, ['refresh' => '1'])) ?>"
       class="btn-secondary btn-inline"><?= icon('refresh-cw', 14) ?> Refresh</a>
  </div>

  <p style="margin:0 0 12px;font-size:.8125rem;color:var(--text-muted);">
    <?php if ($show_all): ?>
      Showing all tasks from all boards (manual, template, and submitted).
    <?php else: ?>
      Showing tasks submitted through the portal only. Tasks created manually on monday or without a "Submitted By" value are excluded.
    <?php endif; ?>
  </p>

  <!-- Filters -->
  <form method="GET" class="admin-filter-bar" style="margin-bottom:12px;">
    <?php if ($show_all): ?><input type="hidden" name="show_all" value="1"><?php endif; ?>
    <div>
      <label>Client</label>
      <select name="client_id">
        <option value="">All clients</option>
        <?php foreach ($clients_all as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $f_client === (int)$c['id'] ? 'selected' : '' ?>>
            <?= e($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Status</label>
      <select name="status">
        <option value="">All statuses</option>
        <option value="in_progress"     <?= $f_status === 'in_progress'     ? 'selected' : '' ?>>In Progress</option>
        <option value="internal_review" <?= $f_status === 'internal_review' ? 'selected' : '' ?>>Internal Review</option>
        <option value="client_review"       <?= $f_status === 'client_review'       ? 'selected' : '' ?>>Awaiting Client</option>
        <option value="changes_requested"   <?= $f_status === 'changes_requested'   ? 'selected' : '' ?>>Changes Requested</option>
        <option value="approved"            <?= $f_status === 'approved'            ? 'selected' : '' ?>>Approved</option>
        <option value="on_hold"         <?= $f_status === 'on_hold'         ? 'selected' : '' ?>>On Hold</option>
        <option value="scrapped"        <?= $f_status === 'scrapped'        ? 'selected' : '' ?>>Scrapped</option>
        <option value="unknown"         <?= $f_status === 'unknown'         ? 'selected' : '' ?>>Unknown</option>
      </select>
    </div>
    <div>
      <label>Submitted by</label>
      <select name="submitted_by">
        <option value="">All users</option>
        <?php foreach ($all_submitted_by_values as $sbv): ?>
          <option value="<?= e($sbv) ?>" <?= $f_submitted_by === $sbv ? 'selected' : '' ?>>
            <?= e($sbv) ?>
          </option>
        <?php endforeach; ?>
        <?php if ($show_all): ?>
          <option value="(unknown)" <?= $f_submitted_by === '(unknown)' ? 'selected' : '' ?>>(Unknown / untracked)</option>
        <?php endif; ?>
      </select>
    </div>
    <div>
      <label>From</label>
      <input type="date" name="from" value="<?= e($f_from) ?>">
    </div>
    <div>
      <label>To</label>
      <input type="date" name="to" value="<?= e($f_to) ?>">
    </div>
    <div style="align-self:flex-end;">
      <button type="submit" class="btn-secondary btn-inline" style="padding:11px 16px;">Filter</button>
      <a href="/admin/submissions.php<?= $show_all ? '?show_all=1' : '' ?>" class="btn-secondary btn-inline" style="padding:11px 16px;">Clear</a>
    </div>
  </form>

  <div style="display:flex;align-items:center;gap:16px;margin-bottom:12px;">
    <input type="search" id="admin-search" placeholder="Search tasks…"
           style="width:100%;max-width:360px;padding:8px 12px;border:1px solid var(--border,#e5e7eb);border-radius:6px;font-size:.875rem;"
           autocomplete="off">
    <label style="display:flex;align-items:center;gap:6px;font-size:.8125rem;color:var(--text-muted);white-space:nowrap;cursor:pointer;">
      <input type="checkbox" id="toggle-show-all" <?= $show_all ? 'checked' : '' ?>>
      Show all tasks (include manual/template)
    </label>
  </div>

  <div class="admin-table-card">
    <table class="admin-table" id="submissions-table">
      <thead>
        <tr>
          <th>Task</th>
          <th>Status</th>
          <th>Client</th>
          <th>Submitted By</th>
          <th>ID</th>
          <th>Updated</th>
          <th class="col-action">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($all_rows)): ?>
          <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No items match the current filter.</td></tr>
        <?php else: foreach ($all_rows as $row):
          $client   = $row['_client'];
          $status   = $row['_status'];
          $updated  = $row['updated_at'] ?? '';
          $task_url = '/admin/task.php?monday_id=' . (int)$row['id'];
        ?>
          <?php $admin_desc = extract_item_description($row); $admin_code = htmlspecialchars($row['name'] ?? '', ENT_QUOTES); ?>
          <tr data-task-name="<?= e(strtolower($row['name'])) ?>"
              data-item-desc="<?= e(strtolower($admin_desc)) ?>"
              data-task-url="<?= e($task_url) ?>">
            <td class="task-combined-cell">
              <?php if ($admin_desc !== ''): ?>
                <a href="<?= e($task_url) ?>" class="task-primary-link" title="<?= htmlspecialchars($admin_desc, ENT_QUOTES) ?>" onclick="event.stopPropagation()">
                  <?= htmlspecialchars($admin_desc, ENT_QUOTES) ?>
                </a>
                <span class="task-code-sub"><?= $admin_code ?></span>
              <?php else: ?>
                <a href="<?= e($task_url) ?>" class="task-primary-link" onclick="event.stopPropagation()"><?= $admin_code ?></a>
              <?php endif; ?>
            </td>
            <td>
              <span class="status-pill status-<?= e($status['slug']) ?>">
                <?= e($status['label']) ?>
              </span>
              <?php $ind = $admin_subitem_indicators[(string)$row['id']] ?? null; if ($ind): ?>
                <div class="subitem-indicators">
                  <?php if (!empty($ind['review'])): ?>
                    <a href="<?= e($task_url) ?>#subitems" class="subitem-indicator-chip" onclick="event.stopPropagation()">
                      <?= $ind['review'] ?> subitem<?= $ind['review'] !== 1 ? 's' : '' ?> awaiting review
                    </a>
                  <?php endif; ?>
                  <?php if (!empty($ind['changes'])): ?>
                    <a href="<?= e($task_url) ?>#subitems" class="subitem-indicator-chip amber" onclick="event.stopPropagation()">
                      <?= $ind['changes'] ?> subitem<?= $ind['changes'] !== 1 ? 's' : '' ?> with changes requested
                    </a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="color-swatch" style="background:<?= e($client['accent_color']) ?>"></span>
              <?= e($client['name']) ?>
            </td>
            <td class="muted small"><?= $row['_submitted_by'] !== null ? e($row['_submitted_by']) : '<span class="muted">—</span>' ?></td>
            <td><code><?= e($row['id']) ?></code></td>
            <td class="muted small">
              <?= $updated ? e(date('M j, Y · H:i', strtotime($updated))) : '—' ?>
            </td>
            <td class="col-action">
              <a href="<?= e($task_url) ?>"
                 class="btn-table-view"
                 onclick="event.stopPropagation()">
                <?= icon('eye', 13) ?> View
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

</div>
<script>
(function () {
  document.querySelectorAll('#submissions-table tbody tr[data-task-url]').forEach(function (tr) {
    tr.style.cursor = 'pointer';
    tr.addEventListener('click', function () {
      window.location.href = tr.dataset.taskUrl;
    });
  });

  var input = document.getElementById('admin-search');
  if (input) {
    input.addEventListener('input', function () {
      var q = this.value.toLowerCase().trim();
      document.querySelectorAll('#submissions-table tbody tr[data-task-name]').forEach(function (tr) {
        var match = !q || tr.dataset.taskName.includes(q) || (tr.dataset.itemDesc || '').includes(q);
        tr.style.display = match ? '' : 'none';
      });
    });
  }

  var toggle = document.getElementById('toggle-show-all');
  if (toggle) {
    toggle.addEventListener('change', function () {
      var url = new URL(window.location.href);
      if (this.checked) {
        url.searchParams.set('show_all', '1');
      } else {
        url.searchParams.delete('show_all');
      }
      window.location.href = url.toString();
    });
  }
})();
</script>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
