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

    if ($action === 'tambah_multi') {
        $names = $_POST['nama_kelas'] ?? [];
        $inserted = 0;
        foreach ($names as $nama) {
            $nama = trim($nama);
            if (empty($nama)) continue;
            $row = db()->query("SELECT id FROM kelas ORDER BY CAST(SUBSTRING(id,2) AS UNSIGNED) DESC LIMIT 1")->fetch();
            $nextNum = $row ? (int)substr($row['id'], 1) + 1 : 1;
            $newId = 'K' . $nextNum;
            $stmt = db()->prepare("INSERT INTO kelas (id, nama_kelas) VALUES (?, ?)");
            $stmt->execute([$newId, $nama]);
            $inserted++;
        }
        echo json_encode(['success' => true, 'message' => "$inserted kelas berhasil ditambahkan"]);
        exit;
    }

    if ($action === 'edit') {
        $id   = $_POST['id'] ?? '';
        $nama = trim($_POST['nama_kelas'] ?? '');
        if (empty($id) || empty($nama)) {
            echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
            exit;
        }
        $stmt = db()->prepare("UPDATE kelas SET nama_kelas = ? WHERE id = ?");
        $stmt->execute([$nama, $id]);
        echo json_encode(['success' => true, 'message' => 'Kelas berhasil diperbarui']);
        exit;
    }

    if ($action === 'hapus') {
        $id   = $_POST['id'] ?? '';
        $stmt = db()->prepare("DELETE FROM kelas WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Kelas berhasil dihapus']);
        exit;
    }

    if ($action === 'hapus_multiple') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!empty($ids) && is_array($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("DELETE FROM kelas WHERE id IN ($placeholders)")->execute($ids);
        }
        echo json_encode(['success' => true, 'message' => 'Kelas terpilih berhasil dihapus']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali']);
    exit;
}

$kelasList = db()->query("SELECT * FROM kelas ORDER BY CAST(SUBSTRING(id,2) AS UNSIGNED)")->fetchAll();
$appName   = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Menu Kelas - <?= htmlspecialchars($appName) ?></title>
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
      <h1 class="text-2xl font-bold text-gray-800">Menu Kelas</h1>
      <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium transition">
        <i class="fas fa-plus"></i> Tambah Kelas
      </button>
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
          <button id="btn-hapus-terpilih" onclick="hapusTerpilih()" class="hidden bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            <i class="fas fa-trash mr-1"></i> Hapus Terpilih
          </button>
          <input type="text" id="search" oninput="filterTable()" placeholder="Cari kelas..."
            class="border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
              <th class="px-4 py-3 w-10"><input type="checkbox" id="check-all" onchange="toggleAll(this)"></th>
              <th class="px-4 py-3">ID</th>
              <th class="px-4 py-3">Nama Kelas</th>
              <th class="px-4 py-3">Dibuat</th>
              <th class="px-4 py-3 text-center">Aksi</th>
            </tr>
          </thead>
          <tbody id="kelas-tbody">
            <?php foreach ($kelasList as $kelas): ?>
            <tr class="border-t hover:bg-gray-50 kelas-row"
                data-search="<?= htmlspecialchars(strtolower($kelas['nama_kelas'])) ?>">
              <td class="px-4 py-3">
                <input type="checkbox" class="row-check" value="<?= htmlspecialchars($kelas['id']) ?>" onchange="updateBulkBtn()">
              </td>
              <td class="px-4 py-3 font-mono font-bold text-blue-600"><?= htmlspecialchars($kelas['id']) ?></td>
              <td class="px-4 py-3 font-medium"><?= htmlspecialchars($kelas['nama_kelas']) ?></td>
              <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars($kelas['created_at'] ?? '') ?></td>
              <td class="px-4 py-3 text-center">
                <button onclick="editKelas('<?= htmlspecialchars($kelas['id']) ?>','<?= htmlspecialchars(addslashes($kelas['nama_kelas'])) ?>')"
                  class="text-blue-600 hover:text-blue-800 mr-2 p-1" title="Edit">
                  <i class="fas fa-edit"></i>
                </button>
                <button onclick="hapusKelas('<?= htmlspecialchars($kelas['id']) ?>')"
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

<!-- Modal Tambah Kelas -->
<div id="add-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-lg mx-4">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Tambah Kelas</h3>
    <div id="rows-container">
      <div class="flex gap-2 mb-2">
        <input type="text" placeholder="Nama kelas (contoh: VII A)"
          class="row-input flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        <button onclick="removeRow(this)" class="text-red-500 hover:text-red-700 px-2" title="Hapus baris">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </div>
    <button onclick="addRow()" class="text-blue-600 hover:text-blue-800 text-sm mb-4">
      <i class="fas fa-plus mr-1"></i>Tambah Baris
    </button>
    <div class="flex gap-3 justify-end">
      <button onclick="closeAddModal()" class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50">Batal</button>
      <button onclick="submitTambah()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Simpan</button>
    </div>
  </div>
</div>

<!-- Modal Edit Kelas -->
<div id="edit-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Edit Kelas</h3>
    <input type="hidden" id="edit-id">
    <div class="mb-4">
      <label class="block text-sm font-medium text-gray-700 mb-1">Nama Kelas</label>
      <input type="text" id="edit-nama"
        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
    </div>
    <div class="flex gap-3 justify-end">
      <button onclick="closeEditModal()" class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50">Batal</button>
      <button onclick="submitEdit()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Simpan</button>
    </div>
  </div>
</div>

<script>
const csrf = '<?= $csrfToken ?>';
let currentPage = 1;
let perPage = 10;
let filteredRows = [];

function filterTable() {
  perPage = parseInt(document.getElementById('perPage').value);
  const search = document.getElementById('search').value.toLowerCase();
  const rows = Array.from(document.querySelectorAll('.kelas-row'));
  filteredRows = rows.filter(row => row.dataset.search.includes(search));
  rows.forEach(row => row.style.display = 'none');
  currentPage = 1;
  renderPage();
}

function renderPage() {
  const total = filteredRows.length;
  const start = (currentPage - 1) * perPage;
  const end = perPage === 999 ? total : start + perPage;
  filteredRows.forEach((row, i) => row.style.display = (i >= start && i < end) ? '' : 'none');
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
  document.getElementById('btn-hapus-terpilih').classList.toggle('hidden', checked === 0);
  document.getElementById('check-all').indeterminate =
    checked > 0 && checked < document.querySelectorAll('.row-check').length;
}

function openAddModal() {
  document.getElementById('rows-container').innerHTML =
    '<div class="flex gap-2 mb-2">' +
    '<input type="text" placeholder="Nama kelas (contoh: VII A)" class="row-input flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">' +
    '<button onclick="removeRow(this)" class="text-red-500 hover:text-red-700 px-2"><i class="fas fa-times"></i></button>' +
    '</div>';
  document.getElementById('add-modal').classList.remove('hidden');
}

function closeAddModal() { document.getElementById('add-modal').classList.add('hidden'); }
function closeEditModal() { document.getElementById('edit-modal').classList.add('hidden'); }

function addRow() {
  const div = document.createElement('div');
  div.className = 'flex gap-2 mb-2';
  div.innerHTML =
    '<input type="text" placeholder="Nama kelas" class="row-input flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">' +
    '<button onclick="removeRow(this)" class="text-red-500 hover:text-red-700 px-2"><i class="fas fa-times"></i></button>';
  document.getElementById('rows-container').appendChild(div);
}

function removeRow(btn) {
  const rows = document.querySelectorAll('#rows-container > div');
  if (rows.length > 1) btn.parentElement.remove();
}

function submitTambah() {
  const inputs = document.querySelectorAll('.row-input');
  const names = Array.from(inputs).map(i => i.value.trim()).filter(v => v);
  if (!names.length) { Swal.fire('Error', 'Nama kelas wajib diisi', 'error'); return; }

  const fd = new FormData();
  fd.append('csrf_token', csrf);
  fd.append('action', 'tambah_multi');
  names.forEach(n => fd.append('nama_kelas[]', n));

  fetch('kelas.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Swal.fire({ icon: 'success', title: 'Berhasil', text: data.message, timer: 1500, showConfirmButton: false });
        closeAddModal();
        setTimeout(() => location.reload(), 1500);
      } else {
        Swal.fire('Error', data.message, 'error');
      }
    });
}

function editKelas(id, nama) {
  document.getElementById('edit-id').value = id;
  document.getElementById('edit-nama').value = nama;
  document.getElementById('edit-modal').classList.remove('hidden');
}

function submitEdit() {
  const id   = document.getElementById('edit-id').value;
  const nama = document.getElementById('edit-nama').value.trim();
  if (!nama) { Swal.fire('Error', 'Nama kelas wajib diisi', 'error'); return; }

  const fd = new FormData();
  fd.append('csrf_token', csrf);
  fd.append('action', 'edit');
  fd.append('id', id);
  fd.append('nama_kelas', nama);

  fetch('kelas.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Swal.fire({ icon: 'success', title: 'Berhasil', text: data.message, timer: 1500, showConfirmButton: false });
        closeEditModal();
        setTimeout(() => location.reload(), 1500);
      } else {
        Swal.fire('Error', data.message, 'error');
      }
    });
}

function hapusKelas(id) {
  Swal.fire({
    title: 'Hapus Kelas?', text: 'Data kelas akan dihapus permanen!', icon: 'warning',
    showCancelButton: true, confirmButtonColor: '#dc2626',
    confirmButtonText: 'Hapus', cancelButtonText: 'Batal'
  }).then(result => {
    if (!result.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', 'hapus');
    fd.append('id', id);
    fetch('kelas.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          Swal.fire({ icon: 'success', title: 'Dihapus!', timer: 1500, showConfirmButton: false });
          setTimeout(() => location.reload(), 1500);
        } else {
          Swal.fire('Error', data.message, 'error');
        }
      });
  });
}

function hapusTerpilih() {
  const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(c => c.value);
  if (!ids.length) return;
  Swal.fire({
    title: `Hapus ${ids.length} kelas?`, text: 'Semua kelas terpilih akan dihapus permanen!', icon: 'warning',
    showCancelButton: true, confirmButtonColor: '#dc2626',
    confirmButtonText: 'Hapus Semua', cancelButtonText: 'Batal'
  }).then(result => {
    if (!result.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', 'hapus_multiple');
    fd.append('ids', JSON.stringify(ids));
    fetch('kelas.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          Swal.fire({ icon: 'success', title: 'Dihapus!', timer: 1500, showConfirmButton: false });
          setTimeout(() => location.reload(), 1500);
        } else {
          Swal.fire('Error', data.message, 'error');
        }
      });
  });
}

// Init on load
filterTable();
</script>
</body>
</html>
