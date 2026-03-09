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

    if ($action === 'tambah') {
        $email = trim($_POST['email'] ?? '');
        $nama  = trim($_POST['nama']  ?? '');
        $pass  = $_POST['password']   ?? '';

        if (empty($email) || empty($nama) || empty($pass)) {
            echo json_encode(['success' => false, 'message' => 'Semua field wajib diisi']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Format email tidak valid']);
            exit;
        }

        // Check duplicate email
        $dup = db()->prepare("SELECT COUNT(*) FROM admin WHERE email = ?");
        $dup->execute([$email]);
        if ($dup->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Email sudah terdaftar']);
            exit;
        }

        $row = db()->query("SELECT id FROM admin ORDER BY CAST(SUBSTRING(id,2) AS UNSIGNED) DESC LIMIT 1")->fetch();
        $nextNum = $row ? (int)substr($row['id'], 1) + 1 : 1;
        $newId   = 'A' . $nextNum;
        $hash    = password_hash($pass, PASSWORD_BCRYPT);

        $stmt = db()->prepare("INSERT INTO admin (id, email, nama, password) VALUES (?, ?, ?, ?)");
        $stmt->execute([$newId, $email, $nama, $hash]);
        echo json_encode(['success' => true, 'message' => 'Admin berhasil ditambahkan']);
        exit;
    }

    if ($action === 'edit') {
        $id    = $_POST['id']    ?? '';
        $email = trim($_POST['email'] ?? '');
        $nama  = trim($_POST['nama']  ?? '');
        $pass  = $_POST['password']   ?? '';

        if (empty($id) || empty($email) || empty($nama)) {
            echo json_encode(['success' => false, 'message' => 'ID, email, dan nama wajib diisi']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Format email tidak valid']);
            exit;
        }

        // Check duplicate email excluding current record
        $dup = db()->prepare("SELECT COUNT(*) FROM admin WHERE email = ? AND id != ?");
        $dup->execute([$email, $id]);
        if ($dup->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Email sudah dipakai admin lain']);
            exit;
        }

        if (!empty($pass)) {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            db()->prepare("UPDATE admin SET email = ?, nama = ?, password = ? WHERE id = ?")
                ->execute([$email, $nama, $hash, $id]);
        } else {
            db()->prepare("UPDATE admin SET email = ?, nama = ? WHERE id = ?")
                ->execute([$email, $nama, $id]);
        }
        echo json_encode(['success' => true, 'message' => 'Admin berhasil diperbarui']);
        exit;
    }

    if ($action === 'hapus') {
        $id = $_POST['id'] ?? '';
        // Prevent deleting own account
        if ($id === (string)$_SESSION['admin_id']) {
            echo json_encode(['success' => false, 'message' => 'Tidak dapat menghapus akun sendiri']);
            exit;
        }
        db()->prepare("DELETE FROM admin WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Admin berhasil dihapus']);
        exit;
    }

    if ($action === 'hapus_multiple') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!empty($ids) && is_array($ids)) {
            // Exclude own account
            $ids = array_filter($ids, fn($id) => $id !== (string)$_SESSION['admin_id']);
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                db()->prepare("DELETE FROM admin WHERE id IN ($placeholders)")->execute(array_values($ids));
            }
        }
        echo json_encode(['success' => true, 'message' => 'Admin terpilih berhasil dihapus']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali']);
    exit;
}

$adminList = db()->query("SELECT id, email, nama, created_at FROM admin ORDER BY CAST(SUBSTRING(id,2) AS UNSIGNED)")->fetchAll();
$appName   = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
$csrfToken = generateCsrfToken();
$myId      = $_SESSION['admin_id'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Administrator - <?= htmlspecialchars($appName) ?></title>
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
      <h1 class="text-2xl font-bold text-gray-800">Administrator</h1>
      <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium transition">
        <i class="fas fa-plus"></i> Tambah Admin
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
          <input type="text" id="search" oninput="filterTable()" placeholder="Cari admin..."
            class="border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
              <th class="px-4 py-3 w-10"><input type="checkbox" id="check-all" onchange="toggleAll(this)"></th>
              <th class="px-4 py-3">ID</th>
              <th class="px-4 py-3">Email</th>
              <th class="px-4 py-3">Nama</th>
              <th class="px-4 py-3">Dibuat</th>
              <th class="px-4 py-3 text-center">Aksi</th>
            </tr>
          </thead>
          <tbody id="admin-tbody">
            <?php foreach ($adminList as $admin): ?>
            <tr class="border-t hover:bg-gray-50 admin-row"
                data-search="<?= htmlspecialchars(strtolower($admin['email'] . ' ' . $admin['nama'])) ?>">
              <td class="px-4 py-3">
                <?php if ($admin['id'] !== $myId): ?>
                <input type="checkbox" class="row-check" value="<?= htmlspecialchars($admin['id']) ?>" onchange="updateBulkBtn()">
                <?php else: ?>
                <span title="Tidak dapat memilih akun sendiri" class="text-gray-300"><i class="fas fa-lock text-xs"></i></span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 font-mono font-bold text-blue-600"><?= htmlspecialchars($admin['id']) ?></td>
              <td class="px-4 py-3"><?= htmlspecialchars($admin['email']) ?></td>
              <td class="px-4 py-3 font-medium">
                <?= htmlspecialchars($admin['nama']) ?>
                <?php if ($admin['id'] === $myId): ?>
                <span class="ml-1 text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Anda</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars($admin['created_at'] ?? '') ?></td>
              <td class="px-4 py-3 text-center">
                <button onclick="editAdmin('<?= htmlspecialchars($admin['id']) ?>','<?= htmlspecialchars(addslashes($admin['email'])) ?>','<?= htmlspecialchars(addslashes($admin['nama'])) ?>')"
                  class="text-blue-600 hover:text-blue-800 mr-2 p-1" title="Edit">
                  <i class="fas fa-edit"></i>
                </button>
                <?php if ($admin['id'] !== $myId): ?>
                <button onclick="hapusAdmin('<?= htmlspecialchars($admin['id']) ?>','<?= htmlspecialchars(addslashes($admin['nama'])) ?>')"
                  class="text-red-600 hover:text-red-800 p-1" title="Hapus">
                  <i class="fas fa-trash"></i>
                </button>
                <?php else: ?>
                <span class="text-gray-300 p-1" title="Tidak dapat menghapus akun sendiri"><i class="fas fa-trash"></i></span>
                <?php endif; ?>
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

<!-- Modal Tambah Admin -->
<div id="add-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Tambah Admin</h3>
    <div class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
        <input type="email" id="add-email" placeholder="admin@example.com"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nama <span class="text-red-500">*</span></label>
        <input type="text" id="add-nama" placeholder="Nama lengkap"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Password <span class="text-red-500">*</span></label>
        <div class="relative">
          <input type="password" id="add-password" placeholder="Minimal 8 karakter"
            class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 pr-10">
          <button type="button" onclick="togglePwd('add-password', this)" class="absolute right-2 top-2 text-gray-400 hover:text-gray-600">
            <i class="fas fa-eye text-sm"></i>
          </button>
        </div>
      </div>
    </div>
    <div class="flex gap-3 justify-end mt-6">
      <button onclick="closeAddModal()" class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50">Batal</button>
      <button onclick="submitTambah()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Simpan</button>
    </div>
  </div>
</div>

<!-- Modal Edit Admin -->
<div id="edit-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Edit Admin</h3>
    <input type="hidden" id="edit-id">
    <div class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
        <input type="email" id="edit-email"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nama <span class="text-red-500">*</span></label>
        <input type="text" id="edit-nama"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Password Baru <span class="text-gray-400 text-xs font-normal">(kosongkan jika tidak diubah)</span></label>
        <div class="relative">
          <input type="password" id="edit-password" placeholder="Isi untuk mengubah password"
            class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 pr-10">
          <button type="button" onclick="togglePwd('edit-password', this)" class="absolute right-2 top-2 text-gray-400 hover:text-gray-600">
            <i class="fas fa-eye text-sm"></i>
          </button>
        </div>
      </div>
    </div>
    <div class="flex gap-3 justify-end mt-6">
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
  const rows = Array.from(document.querySelectorAll('.admin-row'));
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

function togglePwd(inputId, btn) {
  const input = document.getElementById(inputId);
  const icon  = btn.querySelector('i');
  if (input.type === 'password') {
    input.type = 'text';
    icon.className = 'fas fa-eye-slash text-sm';
  } else {
    input.type = 'password';
    icon.className = 'fas fa-eye text-sm';
  }
}

function openAddModal() {
  document.getElementById('add-email').value    = '';
  document.getElementById('add-nama').value     = '';
  document.getElementById('add-password').value = '';
  document.getElementById('add-modal').classList.remove('hidden');
}

function closeAddModal()  { document.getElementById('add-modal').classList.add('hidden'); }
function closeEditModal() { document.getElementById('edit-modal').classList.add('hidden'); }

function submitTambah() {
  const email = document.getElementById('add-email').value.trim();
  const nama  = document.getElementById('add-nama').value.trim();
  const pass  = document.getElementById('add-password').value;

  if (!email || !nama || !pass) { Swal.fire('Error', 'Semua field wajib diisi', 'error'); return; }
  if (pass.length < 8) { Swal.fire('Error', 'Password minimal 8 karakter', 'error'); return; }

  const fd = new FormData();
  fd.append('csrf_token', csrf);
  fd.append('action', 'tambah');
  fd.append('email', email);
  fd.append('nama', nama);
  fd.append('password', pass);

  fetch('administrator.php', { method: 'POST', body: fd })
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

function editAdmin(id, email, nama) {
  document.getElementById('edit-id').value       = id;
  document.getElementById('edit-email').value    = email;
  document.getElementById('edit-nama').value     = nama;
  document.getElementById('edit-password').value = '';
  document.getElementById('edit-modal').classList.remove('hidden');
}

function submitEdit() {
  const id    = document.getElementById('edit-id').value;
  const email = document.getElementById('edit-email').value.trim();
  const nama  = document.getElementById('edit-nama').value.trim();
  const pass  = document.getElementById('edit-password').value;

  if (!email || !nama) { Swal.fire('Error', 'Email dan nama wajib diisi', 'error'); return; }
  if (pass && pass.length < 8) { Swal.fire('Error', 'Password minimal 8 karakter', 'error'); return; }

  const fd = new FormData();
  fd.append('csrf_token', csrf);
  fd.append('action', 'edit');
  fd.append('id', id);
  fd.append('email', email);
  fd.append('nama', nama);
  fd.append('password', pass);

  fetch('administrator.php', { method: 'POST', body: fd })
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

function hapusAdmin(id, nama) {
  Swal.fire({
    title: 'Hapus Admin?', text: `Admin "${nama}" akan dihapus permanen!`, icon: 'warning',
    showCancelButton: true, confirmButtonColor: '#dc2626',
    confirmButtonText: 'Hapus', cancelButtonText: 'Batal'
  }).then(result => {
    if (!result.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', 'hapus');
    fd.append('id', id);
    fetch('administrator.php', { method: 'POST', body: fd })
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
    title: `Hapus ${ids.length} admin?`, text: 'Admin terpilih akan dihapus permanen!', icon: 'warning',
    showCancelButton: true, confirmButtonColor: '#dc2626',
    confirmButtonText: 'Hapus Semua', cancelButtonText: 'Batal'
  }).then(result => {
    if (!result.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', 'hapus_multiple');
    fd.append('ids', JSON.stringify(ids));
    fetch('administrator.php', { method: 'POST', body: fd })
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

filterTable();
</script>
</body>
</html>
