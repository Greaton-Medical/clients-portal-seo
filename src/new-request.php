<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$user = current_user();
$page_title = 'New Request — ' . APP_NAME;
$iframe_url = $user['form_iframe_url'] ?? '';

// Inject submitted_by param so monday URL prefill tracks which user submitted the form
if ($iframe_url !== '' && !empty($user['submitted_by_column_id']) && !empty($user['username'])) {
    $sep        = str_contains($iframe_url, '?') ? '&' : '?';
    $iframe_url .= $sep . urlencode($user['submitted_by_column_id']) . '=' . urlencode($user['username']);
}

include __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-card">
    <div class="page-header">
      <div class="page-header-text">
        <h1>Content Request Form</h1>
        <p>Submit a new content request to the <?= e(AGENCY_NAME) ?> creative team</p>
      </div>
      <?php if (!empty($user['logo_url'])): ?>
        <img src="<?= e($user['logo_url']) ?>" alt="<?= e($user['client_name']) ?> logo" class="page-header-logo">
      <?php endif; ?>
    </div>

    <?php if ($iframe_url !== ''): ?>
      <div style="padding: 20px 32px 8px;">
        <p class="muted small">Submissions go directly to <?= e(AGENCY_NAME) ?>. Track their status in your <a href="/dashboard.php">Dashboard</a>.</p>
      </div>
      <div style="padding: 0 32px 32px;">
        <div class="iframe-wrapper" id="iframe-wrapper">

          <!-- Loading skeleton: shown while iframe fetches monday form -->
          <div class="form-skeleton" aria-hidden="true">
            <div class="sk-shimmer sk-title"></div>

            <div class="sk-shimmer sk-label"></div>
            <div class="sk-shimmer sk-input"></div>

            <div class="sk-shimmer sk-label"></div>
            <div class="sk-shimmer sk-input"></div>

            <div class="sk-shimmer sk-label"></div>
            <div class="sk-shimmer sk-textarea"></div>

            <div class="sk-shimmer sk-label"></div>
            <div class="sk-row">
              <div class="sk-shimmer sk-input-half"></div>
              <div class="sk-shimmer sk-input-half"></div>
            </div>

            <div class="sk-shimmer sk-label"></div>
            <div class="sk-shimmer sk-input"></div>

            <div class="sk-shimmer sk-label"></div>
            <div class="sk-shimmer sk-input"></div>

            <div class="sk-shimmer sk-btn"></div>
          </div>

          <iframe
            id="form-iframe"
            src="<?= e($iframe_url) ?>"
            width="100%"
            height="1400"
            frameborder="0"
            title="Content Request Form"
            onload="document.getElementById('iframe-wrapper').classList.add('iframe-loaded')"
            allowfullscreen>
          </iframe>
        </div>
      </div>
    <?php else: ?>
      <div style="padding: 48px 32px; text-align: center;">
        <p class="muted">Your form hasn't been configured yet. Please contact your <?= e(AGENCY_NAME) ?> account manager.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($iframe_url !== ''): ?>
<script>
// Fallback: if iframe onload doesn't fire within 8s, reveal anyway
(function () {
  var wrapper = document.getElementById('iframe-wrapper');
  setTimeout(function () { wrapper.classList.add('iframe-loaded'); }, 8000);
}());
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
