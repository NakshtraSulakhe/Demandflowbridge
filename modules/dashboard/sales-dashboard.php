<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['sales_director','sales_manager','sdr','admin','director','manager_director']);

header('Location: ../sales/dashboard');
exit;
?>
