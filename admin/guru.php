<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }

/* ------------------------------------------------------------------ */
/* CSV Template download                                                */
/* ------------------------------------------------------------------ */
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_guru.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Nama Guru', 'NIK']);
    fputcsv($out, ['Contoh Nama Guru', '1234567890123456']);
    fclose($out);
    exit;
}

/* ------------------------------------------------------------------ */
/* AJAX / POST handlers                                                 */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        echo json_encode(['success' => false, 'message' => 'CSRF tidak valid']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    /* ---- Tambah ---- */
    if ($action === 'tambah') {
        $nama = trim($_POST['nama'] ?? '');
        $nik  = trim($_POST['nik']  ?? '');
        if (empty($nama) || empty($nik)) {
            echo json_encode(['success' => false, 'message' => 'Nama dan NIK wajib diisi']);
            exit;
        }
        if (!preg_match('/^\d{16}$/', $nik)) {
            echo json_encode(['success' => false, 'message' => 'NIK harus 16 digit angka']);
            exit;
        }
        $existing = db()->prepare("SELECT id FROM guru WHERE nik = ?");
        $existing->execute([$nik]);
        if ($existing->fetch()) {
            echo json_encode(['success' => false, 'message' => 'NIK sudah terdaftar']);
            exit;
        }
        $password = password_hash('123456', PASSWORD_BCRYPT);
        $stmt = db()->prepare("INSERT INTO guru (nama, nik, password) VALUES (?, ?, ?)");
        $stmt->execute([$nama, $nik, $password]);
        echo json_encode(['success' => true, 'message' => 'Guru berhasil ditambahkan']);
        exit;
    }

    /* ---- Edit ---- */
    if ($action === 'edit') {
        $id   = (int)($_POST['id'] ?? 0);
        $nama = trim($_POST['nama'] ?? '');
        $nik  = trim($_POST['nik']  ?? '');
        if (!$id || empty($nama) || empty($nik)) {
            echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
            exit;
        }
        if (!preg_match('/^\d{16}$/', $nik)) {
            echo json_encode(['success' => false, 'message' => 'NIK harus 16 digit angka']);
            exit;
        }
        $dup = db()->prepare("SELECT id FROM guru WHERE nik = ? AND id != ?");
        $dup->execute([$nik, $id]);
        if ($dup->fetch()) {
            echo json_encode(['success' => false, 'message' => 'NIK sudah digunakan guru lain']);
            exit;
        }
        db()->prepare("UPDATE guru SET nama = ?, nik = ? WHERE id = ?")->execute([$nama, $nik, $id]);
        echo json_encode(['success' => true, 'message' => 'Data guru berhasil diperbarui']);
        exit;
    }

    /* ---- Hapus ---- */
    if ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM guru WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Guru berhasil dihapus']);
        exit;
    }

    /* ---- Hapus multiple ---- */
    if ($action === 'hapus_multiple') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!empty($ids) && is_array($ids)) {
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("DELETE FROM guru WHERE id IN ($placeholders)")->execute($ids);
        }
        echo json_encode(['success' => true, 'message' => 'Guru terpilih berhasil dihapus']);
        exit;
    }

    /* ---- Reset password (single) ---- */
    if ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $password = password_hash('123456', PASSWORD_BCRYPT);
        db()->prepare("UPDATE guru SET password = ? WHERE id = ?")->execute([$password, $id]);
        echo json_encode(['success' => true, 'message' => 'Password berhasil direset ke 123456']);
        exit;
    }

    /* ---- Reset password (multiple) ---- */
    if ($action === 'reset_password_multiple') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!empty($ids) && is_array($ids)) {
            $ids = array_map('intval', $ids);
            $password = password_hash('123456', PASSWORD_BCRYPT);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$password], $ids);
            db()->prepare("UPDATE guru SET password = ? WHERE id IN ($placeholders)")->execute($params);
        }
        echo json_encode(['success' => true, 'message' => 'Password guru terpilih berhasil direset']);
        exit;
    }

    /* ---- Import CSV ---- */
    if ($action === 'import_csv') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'File CSV tidak valid atau tidak dipilih']);
            exit;
        }
        $tmpPath = $_FILES['csv_file']['tmp_name'];
        $handle  = fopen($tmpPath, 'r');
        if (!$handle) {
            echo json_encode(['success' => false, 'message' => 'Gagal membaca file CSV']);
            exit;
        }
        // Skip header row
        fgetcsv($handle);
        $inserted = 0;
        $skipped  = 0;
        $errors   = [];
        $password = password_hash('123456', PASSWORD_BCRYPT);
        $stmtCheck  = db()->prepare("SELECT id FROM guru WHERE nik = ?");
        $stmtInsert = db()->prepare("INSERT INTO guru (nama, nik, password) VALUES (?, ?, ?)");

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) continue;
            $nama = trim($row[0]);
            $nik  = trim($row[1]);
            if (empty($nama) || empty($nik)) { $skipped++; continue; }
            if (!preg_match('/^\d{16}$/', $nik)) {
                $errors[] = "NIK tidak valid: $nik";
                $skipped++;
                continue;
            }
            $stmtCheck->execute([$nik]);
            if ($stmtCheck->fetch()) { $skipped++; continue; }
            $stmtInsert->execute([$nama, $nik, $password]);
            $inserted++;
        }
        fclose($handle);

        $msg = "$inserted guru berhasil diimport";
        if ($skipped)  $msg .= ", $skipped baris dilewati";
        if ($errors)   $msg .= '. Error: ' . implode('; ', array_slice($errors, 0, 3));
        echo json_encode(['success' => true, 'message' => $msg]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali']);
    exit;
}

/* ------------------------------------------------------------------ */
/* Page data                                                            */
/* ------------------------------------------------------------------ */
$guruList  = db()->query("SELECT * FROM guru ORDER BY nama")->fetchAll();
$csrfToken = generateCsrfToken();
$appName   = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Menu Guru - <?= htmlspecialchars($appName) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100">
<?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="ml-64 pt-16 pb-10 px-6">
  <div class="mt-6">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-gray-800">Menu Guru</h1>
      <div class="flex gap-2">
        <button onclick="openImportModal()"
          class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium transition">
          <i class="fas fa-file-import"></i> Import Guru
        </button>
        <button onclick="openAddModal()"
          class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium transition">
          <i class="fas fa-plus"></i> Tambah Data
        </button>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden">
      <div class="p-4 border-b flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-center gap-2">
          <span class="text-sm text-gray-600">Tampilkan:</span>
          <select id="perPage" onchange="filterTable()" class="border rounded px-2 py-1 text-sm">
            <option value="10">10</option>
            <option value="32">32</option>
            <option value="999">All</option>
          </select>
        </div>
        <div class="flex items-center gap-3">
          <button id="btn-reset-terpilih" onclick="resetPasswordMultiple()" class="hidden bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
            <i class="fas fa-key mr-1"></i> Reset Password
          </button>
          <button id="btn-hapus-terpilih" onclick="hapusTerpilih()" class="hidden bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
            <i class="fas fa-trash mr-1"></i> Hapus Terpilih
          </button>
          <input type="text" id="search" oninput="filterTable()" placeholder="Cari guru atau NIK..."
            class="border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
              <th class="px-4 py-3 w-10">
                <input type="checkbox" id="check-all" onchange="toggleAll(this)">
              </th>
              <th class="px-4 py-3">No</th>
              <th class="px-4 py-3">Nama Guru</th>
              <th class="px-4 py-3">NIK</th>
              <th class="px-4 py-3 text-center">Aksi</th>
            </tr>
          </thead>
          <tbody id="guru-tbody">
            <?php foreach ($guruList as $i => $guru): ?>
            <tr class="border-t hover:bg-gray-50 guru-row"
                data-search="<?= htmlspecialchars(strtolower($guru['nama'] . ' ' . $guru['nik'])) ?>">
              <td class="px-4 py-3">
                <input type="checkbox" class="row-check" value="<?= (int)$guru['id'] ?>" onchange="updateBulkBtn()">
              </td>
              <td class="px-4 py-3 row-no"><?= $i + 1 ?></td>
              <td class="px-4 py-3 font-medium"><?= htmlspecialchars($guru['nama']) ?></td>
              <td class="px-4 py-3 font-mono text-gray-600"><?= htmlspecialchars($guru['nik']) ?></td>
              <td class="px-4 py-3 text-center">
                <button onclick="resetPassword(<?= (int)$guru['id'] ?>, '<?= htmlspecialchars(addslashes($guru['nama'])) ?>')"
                  class="text-yellow-600 hover:text-yellow-800 p-1 mr-1" title="Reset Password">
                  <i class="fas fa-key"></i>
                </button>
                <button onclick="editGuru(<?= (int)$guru['id'] ?>, '<?= htmlspecialchars(addslashes($guru['nama'])) ?>', '<?= htmlspecialchars($guru['nik']) ?>')"
                  class="text-blue-600 hover:text-blue-800 p-1 mr-1" title="Edit">
                  <i class="fas fa-edit"></i>
                </button>
                <button onclick="hapusGuru(<?= (int)$guru['id'] ?>, '<?= htmlspecialchars(addslashes($guru['nama'])) ?>')"
                  class="text-red-600 hover:text-red-800 p-1" title="Hapus">
                  <i class="fas fa-trash"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="p-4 border-t flex items-center justify-between text-sm text-gray-600">
        <span id="showing-info"></span>
        <div id="pagination" class="flex gap-2"></div>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

<!-- Modal Tambah Guru -->
<div id="add-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Tambah Guru</h3>
    <div class="mb-4">
      <label class="block text-sm font-medium text-gray-700 mb-1">Nama Guru <span class="text-red-500">*</span></label>
      <input type="text" id="add-nama" placeholder="Masukkan nama lengkap"
        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
    </div>
    <div class="mb-4">
      <label class="block text-sm font-medium text-gray-700 mb-1">NIK (16 Digit) <span class="text-red-500">*</span></label>
      <input type="text" id="add-nik" placeholder="Masukkan 16 digit NIK" maxlength="16"
        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
    </div>
    <div class="mb-5 p-3 bg-blue-50 rounded-lg text-xs text-blue-700">
      <i class="fas fa-info-circle mr-1"></i>
      Password default: <strong>123456</strong> (guru dapat mengubahnya setelah login)
    </div>
    <div class="flex gap-3 justify-end">
      <button onclick="closeAddModal()"
        class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50">Batal</button>
      <button onclick="submitTambah()"
        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">Simpan</button>
    </div>
  </div>
</div>

<!-- Modal Edit Guru -->
<div id="edit-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Edit Guru</h3>
    <input type="hidden" id="edit-id">
    <div class="mb-4">
      <label class="block text-sm font-medium text-gray-700 mb-1">Nama Guru <span class="text-red-500">*</span></label>
      <input type="text" id="edit-nama"
        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
    </div>
    <div class="mb-5">
      <label class="block text-sm font-medium text-gray-700 mb-1">NIK (16 Digit) <span class="text-red-500">*</span></label>
      <input type="text" id="edit-nik" maxlength="16"
        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
    </div>
    <div class="flex gap-3 justify-end">
      <button onclick="closeEditModal()"
        class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50">Batal</button>
      <button onclick="submitEdit()"
        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">Simpan</button>
    </div>
  </div>
</div>

<!-- Modal Import CSV -->
<div id="import-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4">
    <h3 class="text-lg font-bold text-gray-800 mb-2">Import Guru dari CSV</h3>
    <p class="text-sm text-gray-500 mb-4">Upload file CSV dengan kolom: <strong>Nama Guru</strong>, <strong>NIK</strong></p>

    <div class="mb-4">
      <a href="guru.php?action=download_template"
        class="inline-flex items-center gap-2 text-sm text-green-700 bg-green-50 hover:bg-green-100 px-4 py-2 rounded-lg border border-green-200 transition">
        <i class="fas fa-download"></i> Download Template CSV
      </a>
    </div>

    <div id="drop-zone"
      class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition mb-4"
      ondragover="event.preventDefault(); this.classList.add('border-blue-400','bg-blue-50')"
      ondragleave="this.classList.remove('border-blue-400','bg-blue-50')"
      ondrop="handleDrop(event)"
      onclick="document.getElementById('csv-input').click()">
      <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2"></i>
      <p class="text-sm text-gray-500">Drag & drop file CSV di sini, atau klik untuk memilih</p>
      <p id="file-name" class="text-xs text-blue-600 mt-2 font-medium"></p>
    </div>
    <input type="file" id="csv-input" accept=".csv" class="hidden" onchange="handleFileSelect(this)">

    <div class="flex gap-3 justify-end">
      <button onclick="closeImportModal()"
        class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50">Batal</button>
      <button onclick="submitImport()"
        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
        <i class="fas fa-upload mr-1"></i>Import
      </button>
    </div>
  </div>
</div>

<script>
const csrf = '<?= $csrfToken ?>';
let perPage = 10, currentPage = 1, filteredRows = [];
let selectedCsvFile = null;

function filterTable() {
  perPage = parseInt(document.getElementById('perPage').value);
  const search = document.getElementById('search').value.toLowerCase();
  const rows = Array.from(document.querySelectorAll('.guru-row'));
  filteredRows = rows.filter(r => r.dataset.search.includes(search));
  rows.forEach(r => r.style.display = 'none');
  currentPage = 1;
  renderPage();
}

function renderPage() {
  const total = filteredRows.length;
  const start = (currentPage - 1) * perPage;
  const end   = perPage === 999 ? total : start + perPage;
  let visibleNo = start + 1;
  filteredRows.forEach((r, i) => {
    const visible = i >= start && i < end;
    r.style.display = visible ? '' : 'none';
    if (visible) {
      const noCell = r.querySelector('.row-no');
      if (noCell) noCell.textContent = visibleNo++;
    }
  });
  document.getElementById('showing-info').textContent =
    `Menampilkan ${total === 0 ? 0 : start + 1}–${Math.min(end, total)} dari ${total} data`;
  renderPagination(total);
}

function renderPagination(total) {
  const pages = perPage === 999 ? 1 : Math.ceil(total / perPage);
  const div = document.getElementById('pagination');
  div.innerHTML = '';
  if (pages <= 1) return;
  for (let i = 1; i <= pages; i++) {
    const btn = document.createElement('button');
    btn.textContent = i;
    btn.className = 'px-3 py-1 rounded text-sm ' +
      (i === currentPage ? 'bg-blue-600 text-white' : 'bg-gray-200 hover:bg-gray-300');
    btn.onclick = () => { currentPage = i; renderPage(); };
    div.appendChild(btn);
  }
}

function toggleAll(cb) {
  document.querySelectorAll('.row-check').forEach(c => c.checked = cb.checked);
  updateBulkBtn();
}

function updateBulkBtn() {
  const checked = document.querySelectorAll('.row-check:checked').length;
  const total   = document.querySelectorAll('.row-check').length;
  document.getElementById('btn-hapus-terpilih').classList.toggle('hidden', checked === 0);
  document.getElementById('btn-reset-terpilih').classList.toggle('hidden', checked === 0);
  const ca = document.getElementById('check-all');
  ca.indeterminate = checked > 0 && checked < total;
  ca.checked = checked === total && total > 0;
}

/* --- Add modal --- */
function openAddModal() {
  document.getElementById('add-nama').value = '';
  document.getElementById('add-nik').value  = '';
  document.getElementById('add-modal').classList.remove('hidden');
}
function closeAddModal() { document.getElementById('add-modal').classList.add('hidden'); }

function submitTambah() {
  const nama = document.getElementById('add-nama').value.trim();
  const nik  = document.getElementById('add-nik').value.trim();
  if (!nama) { Swal.fire('Error', 'Nama guru wajib diisi', 'error'); return; }
  if (!/^\d{16}$/.test(nik)) { Swal.fire('Error', 'NIK harus 16 digit angka', 'error'); return; }
  const fd = new FormData();
  fd.append('csrf_token', csrf); fd.append('action', 'tambah');
  fd.append('nama', nama);       fd.append('nik', nik);
  postAction(fd, closeAddModal);
}

/* --- Edit modal --- */
function editGuru(id, nama, nik) {
  document.getElementById('edit-id').value   = id;
  document.getElementById('edit-nama').value = nama;
  document.getElementById('edit-nik').value  = nik;
  document.getElementById('edit-modal').classList.remove('hidden');
}
function closeEditModal() { document.getElementById('edit-modal').classList.add('hidden'); }

function submitEdit() {
  const id   = document.getElementById('edit-id').value;
  const nama = document.getElementById('edit-nama').value.trim();
  const nik  = document.getElementById('edit-nik').value.trim();
  if (!nama) { Swal.fire('Error', 'Nama guru wajib diisi', 'error'); return; }
  if (!/^\d{16}$/.test(nik)) { Swal.fire('Error', 'NIK harus 16 digit angka', 'error'); return; }
  const fd = new FormData();
  fd.append('csrf_token', csrf); fd.append('action', 'edit');
  fd.append('id', id);           fd.append('nama', nama); fd.append('nik', nik);
  postAction(fd, closeEditModal);
}

/* --- Delete --- */
function hapusGuru(id, nama) {
  Swal.fire({
    title: 'Hapus Guru?',
    text: `"${nama}" akan dihapus permanen beserta relasinya.`,
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc2626', confirmButtonText: 'Hapus', cancelButtonText: 'Batal'
  }).then(r => {
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf); fd.append('action', 'hapus'); fd.append('id', id);
    postAction(fd, null);
  });
}

function hapusTerpilih() {
  const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(c => c.value);
  if (!ids.length) return;
  Swal.fire({
    title: `Hapus ${ids.length} guru?`,
    text: 'Semua guru terpilih akan dihapus permanen.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc2626', confirmButtonText: 'Hapus Semua', cancelButtonText: 'Batal'
  }).then(r => {
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf); fd.append('action', 'hapus_multiple');
    fd.append('ids', JSON.stringify(ids));
    postAction(fd, null);
  });
}

/* --- Reset password --- */
function resetPassword(id, nama) {
  Swal.fire({
    title: 'Reset Password?',
    text: `Password "${nama}" akan direset ke 123456.`,
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#d97706', confirmButtonText: 'Reset', cancelButtonText: 'Batal'
  }).then(r => {
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf); fd.append('action', 'reset_password'); fd.append('id', id);
    postAction(fd, null);
  });
}

function resetPasswordMultiple() {
  const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(c => c.value);
  if (!ids.length) return;
  Swal.fire({
    title: `Reset ${ids.length} password?`,
    text: 'Password semua guru terpilih akan direset ke 123456.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#d97706', confirmButtonText: 'Reset', cancelButtonText: 'Batal'
  }).then(r => {
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf); fd.append('action', 'reset_password_multiple');
    fd.append('ids', JSON.stringify(ids));
    postAction(fd, null);
  });
}

/* --- Import modal --- */
function openImportModal() {
  selectedCsvFile = null;
  document.getElementById('csv-input').value = '';
  document.getElementById('file-name').textContent = '';
  document.getElementById('import-modal').classList.remove('hidden');
}
function closeImportModal() { document.getElementById('import-modal').classList.add('hidden'); }

function handleFileSelect(input) {
  if (input.files.length) {
    selectedCsvFile = input.files[0];
    document.getElementById('file-name').textContent = '✓ ' + selectedCsvFile.name;
  }
}

function handleDrop(event) {
  event.preventDefault();
  document.getElementById('drop-zone').classList.remove('border-blue-400', 'bg-blue-50');
  const file = event.dataTransfer.files[0];
  if (file && file.name.endsWith('.csv')) {
    selectedCsvFile = file;
    document.getElementById('file-name').textContent = '✓ ' + file.name;
  } else {
    Swal.fire('Error', 'Hanya file CSV yang diizinkan', 'error');
  }
}

function submitImport() {
  if (!selectedCsvFile) { Swal.fire('Error', 'Pilih file CSV terlebih dahulu', 'error'); return; }
  const fd = new FormData();
  fd.append('csrf_token', csrf); fd.append('action', 'import_csv');
  fd.append('csv_file', selectedCsvFile);
  Swal.fire({ title: 'Mengimport...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
  fetch('guru.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Swal.fire({ icon: 'success', title: 'Berhasil', text: data.message, timer: 2500, showConfirmButton: false });
        closeImportModal();
        setTimeout(() => location.reload(), 2500);
      } else {
        Swal.fire('Error', data.message, 'error');
      }
    })
    .catch(() => Swal.fire('Error', 'Gagal mengimport file', 'error'));
}

/* --- Shared POST helper --- */
function postAction(fd, closeCallback) {
  fetch('guru.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Swal.fire({ icon: 'success', title: 'Berhasil', text: data.message, timer: 1500, showConfirmButton: false });
        if (closeCallback) closeCallback();
        setTimeout(() => location.reload(), 1500);
      } else {
        Swal.fire('Error', data.message, 'error');
      }
    })
    .catch(() => Swal.fire('Error', 'Terjadi kesalahan', 'error'));
}

filterTable();
</script>
</body>
</html>
