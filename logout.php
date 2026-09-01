<?php
require_once __DIR__ . '/config/db.php';

logout_user();
set_flash('info', 'You have been signed out securely.');
header("Location: " . BASE_URL . "/login.php");
exit;
