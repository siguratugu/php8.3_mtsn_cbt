<?php
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/csrf.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Redirect if already logged in
if (isset($_SESSION['admin_id'])) { header('Location: admin/dashboard.php'); exit; }
if (isset($_SESSION['guru_id'])) { header('Location: guru/dashboard.php'); exit; }
if (isset($_SESSION['siswa_id'])) { header('Location: siswa/dashboard.php'); exit; }

$error = '';
$tab = 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF tidak valid.';
    } else {
        $loginType = $_POST['login_type'] ?? '';

        if ($loginType === 'admin') {
            $tab = 'admin';
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Format email tidak valid.';
            } elseif (empty($password)) {
                $error = 'Password wajib diisi.';
            } else {
                $stmt = db()->prepare("SELECT * FROM admin WHERE email = ?");
                $stmt->execute([$email]);
                $admin = $stmt->fetch();

                if ($admin && password_verify($password, $admin['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_nama'] = $admin['nama'];
                    $_SESSION['admin_email'] = $admin['email'];
                    header('Location: admin/dashboard.php');
                    exit;
                } else {
                    $error = 'Email atau password salah.';
                }
            }
        } elseif ($loginType === 'guru') {
            $tab = 'guru';
            $nik = trim($_POST['nik'] ?? '');
            $password = $_POST['password'] ?? '';

            if (!preg_match('/^\d{16}$/', $nik)) {
                $error = 'NIK harus tepat 16 digit angka.';
            } elseif (empty($password)) {
                $error = 'Password wajib diisi.';
            } else {
                $stmt = db()->prepare("SELECT * FROM guru WHERE nik = ?");
                $stmt->execute([$nik]);
                $guru = $stmt->fetch();

                if ($guru && password_verify($password, $guru['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['guru_id'] = $guru['id'];
                    $_SESSION['guru_nama'] = $guru['nama'];
                    $_SESSION['guru_nik'] = $guru['nik'];
                    header('Location: guru/dashboard.php');
                    exit;
                } else {
                    $error = 'NIK atau password salah.';
                }
            }
        } elseif ($loginType === 'siswa') {
            $tab = 'siswa';
            $nisn = trim($_POST['nisn'] ?? '');
            $password = $_POST['password'] ?? '';

            if (!preg_match('/^\d{10}$/', $nisn)) {
                $error = 'NISN harus tepat 10 digit angka.';
            } elseif (empty($password)) {
                $error = 'Password wajib diisi.';
            } else {
                $stmt = db()->prepare("SELECT * FROM siswa WHERE nisn = ?");
                $stmt->execute([$nisn]);
                $siswa = $stmt->fetch();

                if ($siswa && password_verify($password, $siswa['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['siswa_id'] = $siswa['id'];
                    $_SESSION['siswa_nama'] = $siswa['nama'];
                    $_SESSION['siswa_nisn'] = $siswa['nisn'];
                    $_SESSION['siswa_kelas_id'] = $siswa['kelas_id'];
                    header('Location: siswa/dashboard.php');
                    exit;
                } else {
                    $error = 'NISN atau password salah.';
                }
            }
        }
    }
}

// Check exambrowser mode
try {
    $stmt = db()->prepare("SELECT value FROM settings WHERE key_name = 'exambrowser_mode'");
    $stmt->execute();
    $exambrowserMode = (int)($stmt->fetchColumn() ?? 0);
} catch (Exception $e) {
    $exambrowserMode = 0;
}

$csrfToken = generateCsrfToken();
$appName = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - <?= htmlspecialchars($appName) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
body { background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%); min-height: 100vh; }
.tab-btn.active { background: #2563eb; color: white; }
.tab-btn { transition: all 0.2s; }
.tab-content { display: none; }
.tab-content.active { display: block; }
</style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">

<div class="bg-white rounded-2xl shadow-2xl overflow-hidden w-full max-w-4xl flex">
  <!-- Left Panel -->
  <div class="hidden md:flex flex-col items-center justify-center bg-gradient-to-br from-blue-600 to-blue-800 p-8 w-2/5 text-white">
    <img src="https://exam1.unimed.ac.id/landpage/images/icons/mku.gif" alt="CBT" class="w-48 h-48 object-contain mb-6 rounded-xl" onerror="this.style.display='none'">
    <h2 class="text-xl font-bold text-center mb-4">Sistem Ujian Berbasis Komputer</h2>
    <div class="flex flex-wrap gap-2 justify-center">
      <span class="bg-white bg-opacity-20 px-4 py-1 rounded-full text-sm font-medium">✓ Responsif</span>
      <span class="bg-white bg-opacity-20 px-4 py-1 rounded-full text-sm font-medium">⚡ Cepat</span>
      <span class="bg-white bg-opacity-20 px-4 py-1 rounded-full text-sm font-medium">😊 Mudah</span>
    </div>
  </div>

  <!-- Right Panel -->
  <div class="flex-1 p-8">
    <div class="text-center mb-6">
      <img src="https://e-learning.mtsn1mesuji.sch.id/__statics/img/logo.png" alt="Logo" class="w-16 h-16 mx-auto mb-3 rounded-full" onerror="this.style.display='none'">
      <h1 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($appName) ?></h1>
      <p class="text-gray-500 text-sm">Masuk ke akun Anda</p>
    </div>

    <!-- Tabs -->
    <div class="flex rounded-lg bg-gray-100 p-1 mb-6">
      <button class="tab-btn flex-1 py-2 px-3 rounded-md text-sm font-medium text-gray-700 active" onclick="switchTab('admin', this)">Admin</button>
      <button class="tab-btn flex-1 py-2 px-3 rounded-md text-sm font-medium text-gray-700" onclick="switchTab('guru', this)">Guru</button>
      <button class="tab-btn flex-1 py-2 px-3 rounded-md text-sm font-medium text-gray-700" onclick="switchTab('siswa', this)">Siswa</button>
    </div>

    <!-- Tab Admin -->
    <div id="tab-admin" class="tab-content active">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="login_type" value="admin">
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input type="email" name="email" required class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="admin@mtsn1mesuji.sch.id">
        </div>
        <div class="mb-6 relative">
          <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
          <input type="password" name="password" id="admin-pw" required class="w-full border border-gray-300 rounded-lg px-4 py-2.5 pr-12 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="••••••••">
          <button type="button" onclick="togglePw('admin-pw')" class="absolute right-3 top-9 text-gray-400 hover:text-gray-600">
            <i class="fas fa-eye" id="admin-pw-icon"></i>
          </button>
        </div>
        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 rounded-lg transition">Login sebagai Admin</button>
      </form>
    </div>

    <!-- Tab Guru -->
    <div id="tab-guru" class="tab-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="login_type" value="guru">
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1">NIK (16 Digit)</label>
          <input type="text" name="nik" required maxlength="16" pattern="\d{16}" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Masukkan NIK 16 digit" oninput="this.value=this.value.replace(/\D/g,'')">
        </div>
        <div class="mb-6 relative">
          <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
          <input type="password" name="password" id="guru-pw" required class="w-full border border-gray-300 rounded-lg px-4 py-2.5 pr-12 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="••••••••">
          <button type="button" onclick="togglePw('guru-pw')" class="absolute right-3 top-9 text-gray-400 hover:text-gray-600">
            <i class="fas fa-eye" id="guru-pw-icon"></i>
          </button>
        </div>
        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 rounded-lg transition">Login sebagai Guru</button>
      </form>
    </div>

    <!-- Tab Siswa -->
    <div id="tab-siswa" class="tab-content">
      <form method="POST" id="form-siswa">
        <?= csrfField() ?>
        <input type="hidden" name="login_type" value="siswa">
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1">NISN (10 Digit)</label>
          <input type="text" name="nisn" required maxlength="10" pattern="\d{10}" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Masukkan NISN 10 digit" oninput="this.value=this.value.replace(/\D/g,'')">
        </div>
        <div class="mb-6 relative">
          <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
          <input type="password" name="password" id="siswa-pw" required class="w-full border border-gray-300 rounded-lg px-4 py-2.5 pr-12 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="••••••••">
          <button type="button" onclick="togglePw('siswa-pw')" class="absolute right-3 top-9 text-gray-400 hover:text-gray-600">
            <i class="fas fa-eye" id="siswa-pw-icon"></i>
          </button>
        </div>
        <?php if ($exambrowserMode): ?>
        <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
          <i class="fas fa-exclamation-triangle mr-1"></i> Mode Exambrowser Aktif. Gunakan aplikasi Android MTsN 1 Mesuji.
        </div>
        <?php endif; ?>
        <button type="submit" id="btn-siswa-login" class="w-full <?= $exambrowserMode ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700' ?> text-white font-semibold py-2.5 rounded-lg transition mb-3" <?= $exambrowserMode ? 'onclick="return checkExamBrowser(event)"' : '' ?>>Login sebagai Siswa</button>
        <button type="button" onclick="showTokenModal()" class="w-full border border-blue-600 text-blue-600 hover:bg-blue-50 font-semibold py-2.5 rounded-lg transition">Login Menggunakan TOKEN</button>
      </form>
    </div>

    <!-- Footer -->
    <p class="text-center text-gray-400 text-xs mt-6">© <span id="year"></span> | Developer by Asmin Pratama</p>
  </div>
</div>

<!-- Token Modal -->
<div id="token-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
  <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-md mx-4">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Login dengan TOKEN Ujian</h3>
    <form id="token-form">
      <?= csrfField() ?>
      <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">NISN</label>
        <input type="text" id="token-nisn" required maxlength="10" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="NISN 10 digit" oninput="this.value=this.value.replace(/\D/g,'')">
      </div>
      <div class="mb-4 relative">
        <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
        <input type="password" id="token-password" required class="w-full border border-gray-300 rounded-lg px-4 py-2.5 pr-12 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="••••••••">
      </div>
      <div class="mb-6">
        <label class="block text-sm font-medium text-gray-700 mb-1">Token Ujian</label>
        <input type="text" id="token-value" required maxlength="10" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 uppercase font-mono tracking-widest" placeholder="Masukkan token ujian">
      </div>
      <div class="flex gap-3">
        <button type="button" onclick="hideTokenModal()" class="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg hover:bg-gray-50">Batal</button>
        <button type="button" onclick="submitTokenLogin()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-lg font-semibold">Login</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('year').textContent = new Date().getFullYear();

function switchTab(tab, btn) {
  document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  btn.classList.add('active');
}

function togglePw(id) {
  const input = document.getElementById(id);
  const icon = document.getElementById(id + '-icon');
  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.replace('fa-eye', 'fa-eye-slash');
  } else {
    input.type = 'password';
    icon.classList.replace('fa-eye-slash', 'fa-eye');
  }
}

function showTokenModal() { document.getElementById('token-modal').classList.remove('hidden'); }
function hideTokenModal() { document.getElementById('token-modal').classList.add('hidden'); }

function checkExamBrowser(e) {
  e.preventDefault();
  Swal.fire({ icon: 'warning', title: 'Exambrowser Aktif', text: 'Mode Exambrowser aktif. Gunakan aplikasi Android MTsN 1 Mesuji untuk login.', confirmButtonColor: '#dc2626' });
  return false;
}

function submitTokenLogin() {
  const nisn = document.getElementById('token-nisn').value.trim();
  const password = document.getElementById('token-password').value;
  const token = document.getElementById('token-value').value.trim().toUpperCase();
  const csrf = document.querySelector('#token-form [name="csrf_token"]').value;

  if (!/^\d{10}$/.test(nisn)) { Swal.fire('Error', 'NISN harus 10 digit angka', 'error'); return; }
  if (!password) { Swal.fire('Error', 'Password wajib diisi', 'error'); return; }
  if (!token) { Swal.fire('Error', 'Token ujian wajib diisi', 'error'); return; }

  fetch('api/token_login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ nisn, password, token, csrf_token: csrf })
  }).then(r => r.json()).then(data => {
    if (data.success) {
      hideTokenModal();
      Swal.fire({
        title: 'Siap Mengerjakan Ujian?',
        html: '<b>' + data.nama_ruang + '</b><br><br>Apakah kamu sudah siap mengerjakan ujian? Jika siap klik tombol Kerjakan',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Kerjakan',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#16a34a'
      }).then(result => {
        if (result.isConfirmed) window.location.href = 'siswa/ujian.php?token=' + data.token;
      });
    } else {
      Swal.fire('Login Gagal', data.message || 'NISN, password, atau token tidak valid', 'error');
    }
  }).catch(() => Swal.fire('Error', 'Terjadi kesalahan. Coba lagi.', 'error'));
}

<?php if ($error): ?>
Swal.fire({ icon: 'error', title: 'Login Gagal', text: <?= json_encode($error) ?>, confirmButtonColor: '#2563eb' });
<?php endif; ?>

<?php if (isset($_GET['logout'])): ?>
Swal.fire({ icon: 'success', title: 'Berhasil Logout', text: 'Anda telah berhasil keluar.', timer: 2000, showConfirmButton: false });
<?php endif; ?>

<?php if (isset($_GET['tab']) && in_array($_GET['tab'], ['admin', 'guru', 'siswa'], true)): ?>
(function() {
  const tab = '<?= htmlspecialchars($_GET['tab']) ?>';
  const btn = document.querySelector('.tab-btn:nth-child(' + (['admin','guru','siswa'].indexOf(tab) + 1) + ')');
  if (btn) switchTab(tab, btn);
})();
<?php endif; ?>
</script>
</body>
</html>
