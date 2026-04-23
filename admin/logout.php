<?php
require_once __DIR__ . '/../db.php';

logout_admin_session();

// Redirect to login page with no-cache
send_no_cache_headers();
header('Location: admin_login.php');
exit;
