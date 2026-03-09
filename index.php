<?php
require_once __DIR__ . '/config/env.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_SESSION['admin_id'])) {
    header('Location: admin/dashboard.php');
} elseif (isset($_SESSION['guru_id'])) {
    header('Location: guru/dashboard.php');
} elseif (isset($_SESSION['siswa_id'])) {
    header('Location: siswa/dashboard.php');
} else {
    header('Location: login.php');
}
exit;
