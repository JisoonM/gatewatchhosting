<?php
require_once __DIR__ . '/../db.php';

logout_security_session();

send_no_cache_headers();
header('Location: security_login.php');
exit();
