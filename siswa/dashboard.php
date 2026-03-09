<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['siswa_id'])) { header('Location: ../login.php'); exit; }
$sid = (int)$_SESSION['siswa_id'];
$kelas_id = $_SESSION['siswa_kelas_id'] ?? null;

// Count rooms accessible to this student (via kelas)
$stmtTotal = db()->prepare("
    SELECT COUNT(DISTINCT r.id) FROM ruang_ujian r
    JOIN ruang_ujian_kelas rk ON r.id=rk.ruang_ujian_id
    WHERE rk.kelas_id=?
");
$stmtTotal->execute([$kelas_id]);
$totalRuang = $stmtTotal->fetchColumn();

$stmtBelum = db()->prepare("
    SELECT COUNT(*) FROM ujian_siswa WHERE siswa_id=? AND status='belum'
");
$stmtBelum->execute([$sid]); $belum = $stmtBelum->fetchColumn();

$stmtMengerjakan = db()->prepare("
    SELECT COUNT(*) FROM ujian_siswa WHERE siswa_id=? AND status='mengerjakan'
");
$stmtMengerjakan->execute([$sid]); $mengerjakan = $stmtMengerjakan->fetchColumn();

$stmtSelesai = db()->prepare("
    SELECT COUNT(*) FROM ujian_siswa WHERE siswa_id=? AND status='selesai'
");
$stmtSelesai->execute([$sid]); $selesai = $stmtSelesai->fetchColumn();

// Pengumuman for this student's kelas
$stmtPengumuman = db()->prepare("
    SELECT p.isi, p.created_at FROM pengumuman p
    JOIN pengumuman_kelas pk ON p.id=pk.pengumuman_id
    WHERE pk.kelas_id=?
    ORDER BY p.created_at DESC LIMIT 10
");
$stmtPengumuman->execute([$kelas_id]);
$pengumumanList = $stmtPengumuman->fetchAll();
$appName = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Siswa - <?= htmlspecialchars($appName) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
<?php include __DIR__ . '/../includes/sidebar_siswa.php'; ?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="ml-64 pt-16 pb-10 px-6">
  <div class="mt-6">
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
      <p class="text-blue-800 font-semibold text-lg">Selamat datang, <?= htmlspecialchars($_SESSION['siswa_nama'] ?? '') ?>!</p>
      <p class="text-blue-600 text-sm">NISN: <?= htmlspecialchars($_SESSION['siswa_nisn'] ?? '') ?></p>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <div class="bg-blue-600 text-white rounded-xl p-5 shadow flex items-center gap-3">
        <i class="fas fa-door-open text-2xl opacity-80"></i>
        <div><p class="text-xs opacity-80">Total Ruang Ujian</p><p class="text-2xl font-bold"><?= $totalRuang ?></p></div>
      </div>
      <div class="bg-gray-500 text-white rounded-xl p-5 shadow flex items-center gap-3">
        <i class="fas fa-hourglass-start text-2xl opacity-80"></i>
        <div><p class="text-xs opacity-80">Belum Dikerjakan</p><p class="text-2xl font-bold"><?= $belum ?></p></div>
      </div>
      <div class="bg-orange-500 text-white rounded-xl p-5 shadow flex items-center gap-3">
        <i class="fas fa-pencil-alt text-2xl opacity-80"></i>
        <div><p class="text-xs opacity-80">Sedang Dikerjakan</p><p class="text-2xl font-bold"><?= $mengerjakan ?></p></div>
      </div>
      <div class="bg-green-600 text-white rounded-xl p-5 shadow flex items-center gap-3">
        <i class="fas fa-check-circle text-2xl opacity-80"></i>
        <div><p class="text-xs opacity-80">Selesai Dikerjakan</p><p class="text-2xl font-bold"><?= $selesai ?></p></div>
      </div>
    </div>
    <?php if (!empty($pengumumanList)): ?>
    <h2 class="text-lg font-semibold text-gray-700 mb-4">Pengumuman</h2>
    <div class="space-y-4">
      <?php foreach ($pengumumanList as $p): ?>
      <div class="bg-white rounded-xl shadow p-5">
        <p class="text-xs text-gray-400 mb-2"><?= htmlspecialchars($p['created_at']) ?></p>
        <div class="prose max-w-none text-gray-700"><?= $p['isi'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
