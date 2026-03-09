<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        echo json_encode(['success' => false, 'message' => 'CSRF tidak valid']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $stmt = db()->prepare("SELECT value FROM settings WHERE key_name = 'exambrowser_mode'");
        $stmt->execute();
        $current = $stmt->fetchColumn();

        $newValue = ($current === '1') ? '0' : '1';

        // Upsert: update if exists, insert if not
        $check = db()->prepare("SELECT COUNT(*) FROM settings WHERE key_name = 'exambrowser_mode'");
        $check->execute();
        if ($check->fetchColumn() > 0) {
            db()->prepare("UPDATE settings SET value = ? WHERE key_name = 'exambrowser_mode'")->execute([$newValue]);
        } else {
            db()->prepare("INSERT INTO settings (key_name, value) VALUES ('exambrowser_mode', ?)")->execute([$newValue]);
        }

        $label = $newValue === '1' ? 'AKTIF' : 'NONAKTIF';
        echo json_encode(['success' => true, 'active' => $newValue === '1', 'message' => "Exambrowser sekarang $label"]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali']);
    exit;
}

$stmt = db()->prepare("SELECT value FROM settings WHERE key_name = 'exambrowser_mode'");
$stmt->execute();
$isActive  = $stmt->fetchColumn() === '1';
$appName   = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Exambrowser - <?= htmlspecialchars($appName) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100">
<?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="ml-64 pt-16 pb-10 px-6">
  <div class="mt-6 max-w-2xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Pengaturan Exambrowser</h1>

    <!-- Status card -->
    <div id="status-card" class="rounded-2xl shadow-lg p-8 mb-6 transition-all duration-500 <?= $isActive ? 'bg-red-600' : 'bg-green-600' ?>">
      <div class="flex flex-col items-center text-white text-center">
        <div class="mb-4">
          <i id="status-icon" class="fas <?= $isActive ? 'fa-shield-alt' : 'fa-shield' ?> text-6xl opacity-90"></i>
        </div>
        <h2 id="status-title" class="text-3xl font-extrabold mb-2">
          <?= $isActive ? 'EXAMBROWSER AKTIF' : 'EXAMBROWSER NONAKTIF' ?>
        </h2>
        <p id="status-desc" class="text-base opacity-90 max-w-md">
          <?= $isActive
            ? 'Siswa hanya dapat mengakses ujian melalui aplikasi Exambrowser. Browser biasa tidak diizinkan.'
            : 'Siswa dapat mengakses ujian melalui browser apapun. Mode aman sedang dinonaktifkan.' ?>
        </p>
      </div>
    </div>

    <!-- Toggle section -->
    <div class="bg-white rounded-2xl shadow p-6">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-gray-800 font-semibold text-lg">Status Exambrowser</p>
          <p class="text-gray-500 text-sm mt-1">Aktifkan untuk memaksa penggunaan Exambrowser saat ujian berlangsung.</p>
        </div>
        <!-- Toggle switch -->
        <button id="toggle-btn" onclick="toggleExambrowser()" class="relative inline-flex h-10 w-20 items-center rounded-full transition-colors duration-300 focus:outline-none focus:ring-2 focus:ring-offset-2 <?= $isActive ? 'bg-red-500 focus:ring-red-500' : 'bg-gray-300 focus:ring-gray-400' ?>">
          <span id="toggle-knob" class="inline-block h-8 w-8 transform rounded-full bg-white shadow-md transition-transform duration-300 <?= $isActive ? 'translate-x-11' : 'translate-x-1' ?>"></span>
        </button>
      </div>

      <!-- Warning if active -->
      <div id="warning-box" class="mt-5 <?= $isActive ? '' : 'hidden' ?>">
        <div class="bg-red-50 border border-red-200 rounded-xl p-4 flex gap-3">
          <i class="fas fa-exclamation-triangle text-red-500 mt-0.5 flex-shrink-0"></i>
          <div>
            <p class="text-red-800 font-semibold text-sm">Perhatian – Mode Exambrowser Aktif</p>
            <ul class="text-red-700 text-sm mt-1 list-disc list-inside space-y-1">
              <li>Siswa <strong>wajib</strong> menggunakan aplikasi Exambrowser untuk mengerjakan ujian.</li>
              <li>Akses melalui browser biasa akan ditolak secara otomatis.</li>
              <li>Pastikan semua siswa telah menginstall Exambrowser sebelum ujian dimulai.</li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Info if inactive -->
      <div id="info-box" class="mt-5 <?= $isActive ? 'hidden' : '' ?>">
        <div class="bg-green-50 border border-green-200 rounded-xl p-4 flex gap-3">
          <i class="fas fa-info-circle text-green-500 mt-0.5 flex-shrink-0"></i>
          <div>
            <p class="text-green-800 font-semibold text-sm">Mode Normal – Semua Browser Diizinkan</p>
            <p class="text-green-700 text-sm mt-1">
              Siswa dapat mengakses ujian dari browser apapun. Aktifkan Exambrowser untuk meningkatkan keamanan ujian.
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Info card -->
    <div class="bg-white rounded-2xl shadow p-6 mt-4">
      <h3 class="font-semibold text-gray-800 mb-3 flex items-center gap-2">
        <i class="fas fa-info-circle text-blue-500"></i> Tentang Exambrowser
      </h3>
      <p class="text-gray-600 text-sm leading-relaxed">
        Exambrowser adalah aplikasi browser khusus yang dirancang untuk mencegah kecurangan saat ujian berlangsung.
        Ketika mode ini diaktifkan, siswa tidak dapat membuka tab lain, menggunakan clipboard, atau mengakses
        aplikasi lain selama ujian berlangsung.
      </p>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
const csrf = '<?= $csrfToken ?>';
let isActive = <?= $isActive ? 'true' : 'false' ?>;

function toggleExambrowser() {
  const action = isActive ? 'menonaktifkan' : 'mengaktifkan';
  Swal.fire({
    title: `${isActive ? 'Nonaktifkan' : 'Aktifkan'} Exambrowser?`,
    text: `Anda akan ${action} mode Exambrowser. Lanjutkan?`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: isActive ? '#16a34a' : '#dc2626',
    confirmButtonText: isActive ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan',
    cancelButtonText: 'Batal'
  }).then(result => {
    if (!result.isConfirmed) return;

    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', 'toggle');

    fetch('exambrowser.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          isActive = data.active;
          updateUI();
          Swal.fire({ icon: 'success', title: 'Berhasil', text: data.message, timer: 2000, showConfirmButton: false });
        } else {
          Swal.fire('Error', data.message, 'error');
        }
      });
  });
}

function updateUI() {
  const card    = document.getElementById('status-card');
  const icon    = document.getElementById('status-icon');
  const title   = document.getElementById('status-title');
  const desc    = document.getElementById('status-desc');
  const btn     = document.getElementById('toggle-btn');
  const knob    = document.getElementById('toggle-knob');
  const warnBox = document.getElementById('warning-box');
  const infoBox = document.getElementById('info-box');

  if (isActive) {
    card.className    = card.className.replace('bg-green-600', 'bg-red-600');
    icon.className    = 'fas fa-shield-alt text-6xl opacity-90';
    title.textContent = 'EXAMBROWSER AKTIF';
    desc.textContent  = 'Siswa hanya dapat mengakses ujian melalui aplikasi Exambrowser. Browser biasa tidak diizinkan.';
    btn.className     = btn.className.replace('bg-gray-300 focus:ring-gray-400', 'bg-red-500 focus:ring-red-500');
    knob.className    = knob.className.replace('translate-x-1', 'translate-x-11');
    warnBox.classList.remove('hidden');
    infoBox.classList.add('hidden');
  } else {
    card.className    = card.className.replace('bg-red-600', 'bg-green-600');
    icon.className    = 'fas fa-shield text-6xl opacity-90';
    title.textContent = 'EXAMBROWSER NONAKTIF';
    desc.textContent  = 'Siswa dapat mengakses ujian melalui browser apapun. Mode aman sedang dinonaktifkan.';
    btn.className     = btn.className.replace('bg-red-500 focus:ring-red-500', 'bg-gray-300 focus:ring-gray-400');
    knob.className    = knob.className.replace('translate-x-11', 'translate-x-1');
    warnBox.classList.add('hidden');
    infoBox.classList.remove('hidden');
  }
}
</script>
</body>
</html>
