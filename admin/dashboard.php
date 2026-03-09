<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }

$stats = [
    'siswa'       => db()->query("SELECT COUNT(*) FROM siswa")->fetchColumn(),
    'guru'        => db()->query("SELECT COUNT(*) FROM guru")->fetchColumn(),
    'kelas'       => db()->query("SELECT COUNT(*) FROM kelas")->fetchColumn(),
    'mapel'       => db()->query("SELECT COUNT(*) FROM mapel")->fetchColumn(),
    'bank_soal'   => db()->query("SELECT COUNT(*) FROM bank_soal")->fetchColumn(),
    'ruang_ujian' => db()->query("SELECT COUNT(*) FROM ruang_ujian")->fetchColumn(),
];
$appName = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Admin - <?= htmlspecialchars($appName) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100">
<?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="ml-64 pt-16 pb-10 px-6">
  <div class="mt-6">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Dashboard Admin</h1>

    <!-- Row 1 stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
      <div class="bg-blue-600 text-white rounded-xl p-6 shadow flex items-center gap-4">
        <div class="bg-white bg-opacity-20 rounded-full p-3"><i class="fas fa-user-graduate text-2xl"></i></div>
        <div><p class="text-sm opacity-80">Total Siswa</p><p class="text-3xl font-bold"><?= (int)$stats['siswa'] ?></p></div>
      </div>
      <div class="bg-green-600 text-white rounded-xl p-6 shadow flex items-center gap-4">
        <div class="bg-white bg-opacity-20 rounded-full p-3"><i class="fas fa-chalkboard-teacher text-2xl"></i></div>
        <div><p class="text-sm opacity-80">Total Guru</p><p class="text-3xl font-bold"><?= (int)$stats['guru'] ?></p></div>
      </div>
      <div class="bg-yellow-500 text-white rounded-xl p-6 shadow flex items-center gap-4">
        <div class="bg-white bg-opacity-20 rounded-full p-3"><i class="fas fa-school text-2xl"></i></div>
        <div><p class="text-sm opacity-80">Total Kelas</p><p class="text-3xl font-bold"><?= (int)$stats['kelas'] ?></p></div>
      </div>
    </div>

    <!-- Row 2 stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
      <div class="bg-purple-600 text-white rounded-xl p-6 shadow flex items-center gap-4">
        <div class="bg-white bg-opacity-20 rounded-full p-3"><i class="fas fa-book text-2xl"></i></div>
        <div><p class="text-sm opacity-80">Total Mapel</p><p class="text-3xl font-bold"><?= (int)$stats['mapel'] ?></p></div>
      </div>
      <div class="bg-orange-500 text-white rounded-xl p-6 shadow flex items-center gap-4">
        <div class="bg-white bg-opacity-20 rounded-full p-3"><i class="fas fa-clipboard-list text-2xl"></i></div>
        <div><p class="text-sm opacity-80">Total Bank Soal</p><p class="text-3xl font-bold"><?= (int)$stats['bank_soal'] ?></p></div>
      </div>
      <div class="bg-red-600 text-white rounded-xl p-6 shadow flex items-center gap-4">
        <div class="bg-white bg-opacity-20 rounded-full p-3"><i class="fas fa-door-open text-2xl"></i></div>
        <div><p class="text-sm opacity-80">Total Ruang Ujian</p><p class="text-3xl font-bold"><?= (int)$stats['ruang_ujian'] ?></p></div>
      </div>
    </div>

    <!-- Quick nav -->
    <h2 class="text-lg font-semibold text-gray-700 mb-4">Navigasi Cepat</h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <a href="bank_soal.php" class="bg-white rounded-xl shadow p-5 text-center hover:bg-blue-50 transition group">
        <i class="fas fa-clipboard-list text-3xl text-blue-500 mb-2 group-hover:scale-110 inline-block transition"></i>
        <p class="font-medium text-gray-700">Bank Soal</p>
      </a>
      <a href="ruang_ujian.php" class="bg-white rounded-xl shadow p-5 text-center hover:bg-green-50 transition group">
        <i class="fas fa-door-open text-3xl text-green-500 mb-2 group-hover:scale-110 inline-block transition"></i>
        <p class="font-medium text-gray-700">Ruang Ujian</p>
      </a>
      <a href="exambrowser.php" class="bg-white rounded-xl shadow p-5 text-center hover:bg-red-50 transition group">
        <i class="fas fa-shield-alt text-3xl text-red-500 mb-2 group-hover:scale-110 inline-block transition"></i>
        <p class="font-medium text-gray-700">Exambrowser</p>
      </a>
      <a href="pengumuman.php" class="bg-white rounded-xl shadow p-5 text-center hover:bg-yellow-50 transition group">
        <i class="fas fa-bullhorn text-3xl text-yellow-500 mb-2 group-hover:scale-110 inline-block transition"></i>
        <p class="font-medium text-gray-700">Pengumuman</p>
      </a>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
