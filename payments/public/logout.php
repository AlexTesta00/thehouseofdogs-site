<?php
declare(strict_types=1);

require __DIR__ . '/app-path.php';

hodpay_session_start();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && hodpay_same_origin_check()
    && hodpay_csrf_check(isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null)) {
    hodpay_logout();
}
hodpay_redirect(hodpay_base_url() . '/index.php');
