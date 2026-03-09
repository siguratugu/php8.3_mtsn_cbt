<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['guru_id'])) { header('Location: ../login.php'); exit; }
$gid = (int)$_SESSION['guru_id'];
$jml_kelas = db()->prepare("SELECT COUNT(DISTINCT kelas_id) FROM relasi_guru WHERE guru_id=?");
$jml_kelas->execute([$gid]); $jml_kelas = $jml_kelas->fetchColumn();
$jml_mapel = db()->prepare("SELECT COUNT(DISTINCT mapel_id) FROM relasi_guru WHERE guru_id=?");
$jml_mapel->execute([$gid]); $jml_mapel = $jml_mapel->fetchColumn();
$jml_bank = db()->prepare("SELECT COUNT(*) FROM bank_soal WHERE guru_id=?");
$jml_bank->execute([$gid]); $jml_bank = $jml_bank->fetchColumn();
$jml_ruang = db()->prepare("SELECT COUNT(*) FROM ruang_ujian WHERE guru_id=?");
$jml_ruang->execute([$gid]); $jml_ruang = $jml_ruang->fetchColumn();
$appName = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Guru - <?= htmlspecialchars($appName) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100">
<?php include __DIR__ . '/../includes/sidebar_guru.php'; ?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="ml-64 pt-16 pb-10 px-6">
  <div class="mt-6">
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
      <p class="text-blue-800 font-semibold text-lg">Selamat datang, <?= htmlspecialchars($_SESSION['guru_nama'] ?? '') ?>!</p>
      <p class="text-blue-600 text-sm">Anda login sebagai Guru</p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
      <div class="bg-blue-600 text-white rounded-xl p-6 shadow flex items-center gap-4">
        <div class="bg-white bg-opacity-20 rounded-full p-3"><i class="fas fa-school text-2xl"></i></div>
        <div><p class="text-sm opacity-80">Jumlah Kelas</p><p class="text-3xl font-bold"><?= $jml_kelas ?></p></div>
      </div>
      <div class="bg-green-600 text-white rounded-xl p-6 shadow flex items-center gap-4">
        <div class="bg-white bg-opacity-20 rounded-full p-3"><i class="fas fa-book text-2xl"></i></div>
        <div><p class="text-sm opacity-80">Jumlah Mapel</p><p class="text-3xl font-bold"><?= $jml_mapel ?></p></div>
      </div>
      <div class="bg-orange-500 text-white rounded-xl p-6 shadow flex items-center gap-4">
        <div class="bg-white bg-opacity-20 rounded-full p-3"><i class="fas fa-clipboard-list text-2xl"></i></div>
        <div><p class="text-sm opacity-80">Bank Soal</p><p class="text-3xl font-bold"><?= $jml_bank ?></p></div>
      </div>
      <div class="bg-red-600 text-white rounded-xl p-6 shadow flex items-center gap-4">
        <div class="bg-white bg-opacity-20 rounded-full p-3"><i class="fas fa-door-open text-2xl"></i></div>
        <div><p class="text-sm opacity-80">Ruang Ujian</p><p class="text-3xl font-bold"><?= $jml_ruang ?></p></div>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
