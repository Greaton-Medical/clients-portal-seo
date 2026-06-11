<?php
// Renders the filterable/searchable task table.
// Expects $display_tasks (array of enriched rows) and $display_counts (slug => count).
// Uses IDs submissions-table / filter-chips / search-input / no-results for dashboard.js.
?>

<?php if (!empty($display_counts)): ?>
<div class="status-summary">
  <?php foreach ($all_statuses as $st):
    $count = $display_counts[$st['slug']] ?? 0;
    if ($count === 0) continue;
  ?>
    <div class="status-card js-chip-trigger" data-filter="<?= e($st['slug']) ?>">
      <span class="status-count"><?= $count ?></span>
      <span class="status-pill status-<?= e($st['slug']) ?>"><?= e($st['label']) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="filter-bar">
  <div class="filter-chips" id="filter-chips">
    <button class="chip active" data-filter="all">All <span class="chip-count"><?= count($display_tasks) ?></span></button>
    <?php foreach ($all_statuses as $st):
      $count = $display_counts[$st['slug']] ?? 0;
      if ($count === 0) continue;
    ?>
      <button class="chip" data-filter="<?= e($st['slug']) ?>">
        <?= e($st['label']) ?> <span class="chip-count"><?= $count ?></span>
      </button>
    <?php endforeach; ?>
  </div>
  <input type="search" id="search-input" class="search-input"
         placeholder="Search tasks…" autocomplete="off">
</div>

<div class="table-card">
  <table class="submissions-table" id="submissions-table">
    <thead>
      <tr>
        <th>Task</th>
        <th>Status</th>
        <th class="col-priority">Priority</th>
        <th class="deadline-cell">Deadline</th>
        <th class="col-updated">Updated</th>
        <th class="col-action">Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($display_tasks as $row):
        $is_review      = $row['status']['slug'] === 'client_review';
        $has_sub_review = !empty($row['subitem_indicator']['review']);
        $filter_status  = ($has_sub_review && !$is_review) ? 'client_review' : $row['status']['slug'];
        $task_url       = '/task.php?monday_id=' . (int)$row['monday_id'];
      ?>
        <?php $desc = $row['item_description'] ?? ''; $code = htmlspecialchars($row['task_name'] ?? '', ENT_QUOTES); ?>
        <tr data-status="<?= e($filter_status) ?>"
            data-name="<?= e(strtolower($row['task_name'])) ?>"
            data-desc="<?= e(strtolower($desc)) ?>"
            data-task-url="<?= e($task_url) ?>"
            <?= $is_review ? 'class="row-review-pending"' : '' ?>>
          <td class="task-combined-cell">
            <?php if ($desc !== ''): ?>
              <a href="<?= e($task_url) ?>" class="task-primary-link" title="<?= htmlspecialchars($desc, ENT_QUOTES) ?>">
                <?= htmlspecialchars($desc, ENT_QUOTES) ?>
              </a>
              <span class="task-code-sub"><?= $code ?></span>
            <?php else: ?>
              <a href="<?= e($task_url) ?>" class="task-primary-link"><?= $code ?></a>
            <?php endif; ?>
          </td>
          <td>
            <span class="status-pill status-<?= e($row['status']['slug']) ?>">
              <?= e($row['status']['label']) ?>
            </span>
            <?php if ($is_review): ?>
              <span class="review-row-icon" title="This task needs your review. Click to approve or request changes."><?= icon('circle-alert', 13, 'review-row-icon') ?></span>
            <?php elseif ($row['status']['slug'] === 'changes_requested'): ?>
              <div class="changes-requested-sub">Our team is addressing your feedback</div>
            <?php endif; ?>
            <?php $ind = $row['subitem_indicator'] ?? null; if ($ind): ?>
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
          <td class="col-priority">
            <?php if ($row['priority']): ?>
              <span class="priority-label" style="color:<?= e($row['priority']['color']) ?>">
                <?= e($row['priority']['label']) ?>
              </span>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td class="deadline-cell">
            <?php if ($row['deadline'] !== ''): ?>
              <span class="deadline <?= deadline_class($row['deadline']) ?>">
                <?= e(date('M j, Y', strtotime($row['deadline']))) ?>
              </span>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td class="col-updated muted small">
            <?= $row['updated_at'] ? e(relative_time($row['updated_at'])) : '—' ?>
          </td>
          <td class="col-action">
            <?php if ($is_review): ?>
              <a href="<?= e($task_url) ?>"
                 class="btn-table-review"
                 onclick="event.stopPropagation()">
                <?= icon('eye', 13) ?> Review
              </a>
            <?php else: ?>
              <a href="<?= e($task_url) ?>"
                 class="btn-table-view"
                 onclick="event.stopPropagation()">
                <?= icon('eye', 13) ?> View
              </a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div id="no-results" class="no-results-row" style="display:none;">
    <h3>No tasks match your filter.</h3>
    <p></p>
  </div>
</div>
