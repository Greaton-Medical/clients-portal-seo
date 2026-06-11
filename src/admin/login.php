<?php
require_once __DIR__ . '/../includes/auth.php';

if (is_admin_logged_in()) {
    header('Location: /admin/index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $res = login_admin($username, $password);
        if ($res['ok']) {
            header('Location: /admin/index.php');
            exit;
        }
        $error = $res['error'];
    }
}

$_agency_logo = brand_logo_src();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — <?= e(APP_NAME) ?></title>
<?php if (FAVICON_URL): ?><link rel="icon" type="image/png" href="<?= e(FAVICON_URL) ?>"><?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/admin.css">
<style>:root { --brand-primary: <?= e(BRAND_PRIMARY_COLOR) ?>; --brand-primary-dark: <?= e(BRAND_SECONDARY_COLOR) ?>; --brand-primary-light: <?= e(BRAND_PRIMARY_LIGHT) ?>; }</style>
</head>
<body class="login-body">
<div class="login-card">
  <div class="login-header admin-login-header">
    <div class="login-logo">
      <?php if ($_agency_logo): ?>
        <img src="<?= e($_agency_logo) ?>" alt="<?= e(AGENCY_NAME) ?> logo">
      <?php else: ?>
        <span class="login-initials"><?= e(BRAND_LOGO_INITIALS) ?></span>
      <?php endif; ?>
    </div>
    <p>Admin Panel</p>
  </div>
  <form method="POST" class="login-form" autocomplete="off">
    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required autofocus
             value="<?= e($_POST['username'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required>
    </div>
    <button type="submit" class="btn-primary">Sign in</button>
    <a href="/" class="btn-admin">Sign in as a client</a>
  </form>
</div>
</body>
</html>
