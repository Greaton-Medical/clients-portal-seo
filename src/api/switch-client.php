<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$client_id = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);

if ($client_id && switch_active_client($client_id)) {
    header('Location: /dashboard.php');
} else {
    $_SESSION['flash_error'] = 'You do not have access to that client.';
    header('Location: /dashboard.php');
}
exit;
