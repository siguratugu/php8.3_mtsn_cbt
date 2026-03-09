<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }

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

    $action    = $_POST['action']    ?? '';
    $adminId   = $_SESSION['admin_id'];

    /* ---- Tambah ---- */
    if ($action === 'tambah') {
        $isi      = trim($_POST['isi']       ?? '');
        $kelasIds = $_POST['kelas_ids']      ?? [];

        if (empty($isi)) {
            echo json_encode(['success' => false, 'message' => 'Isi pengumuman wajib diisi']);
            exit;
        }

        $stmt = db()->prepare(
            "INSERT INTO pengumuman (isi, created_by) VALUES (?, ?)"
        );
        $stmt->execute([$isi, $adminId]);
        $pengId = (int)db()->lastInsertId();

        if (!empty($kelasIds)) {
            $stmtKelas = db()->prepare(
                "INSERT IGNORE INTO pengumuman_kelas (pengumuman_id, kelas_id) VALUES (?, ?)"
            );
            foreach ($kelasIds as $kId) {
                $stmtKelas->execute([$pengId, (string)$kId]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Pengumuman berhasil ditambahkan']);
        exit;
    }

    /* ---- Edit ---- */
    if ($action === 'edit') {
        $id       = (int)($_POST['id']    ?? 0);
        $isi      = trim($_POST['isi']    ?? '');
        $kelasIds = $_POST['kelas_ids']   ?? [];

        if (!$id || empty($isi)) {
            echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
            exit;
        }

        db()->prepare("UPDATE pengumuman SET isi = ? WHERE id = ?")->execute([$isi, $id]);

        db()->prepare("DELETE FROM pengumuman_kelas WHERE pengumuman_id = ?")->execute([$id]);

        if (!empty($kelasIds)) {
            $stmtKelas = db()->prepare(
                "INSERT IGNORE INTO pengumuman_kelas (pengumuman_id, kelas_id) VALUES (?, ?)"
            );
            foreach ($kelasIds as $kId) {
                $stmtKelas->execute([$id, (string)$kId]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Pengumuman berhasil diperbarui']);
        exit;
    }

    /* ---- Hapus ---- */
    if ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM pengumuman WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Pengumuman berhasil dihapus']);
        exit;
    }

    /* ---- Get single (for edit modal) ---- */
    if ($action === 'get') {
        $id = (int)($_POST['id'] ?? 0);
        $row = db()->prepare("SELECT * FROM pengumuman WHERE id = ?");
        $row->execute([$id]);
        $data = $row->fetch(PDO::FETCH_ASSOC);
        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
            exit;
        }
        $stmtKelas = db()->prepare(
            "SELECT kelas_id FROM pengumuman_kelas WHERE pengumuman_id = ?"
        );
        $stmtKelas->execute([$id]);
        $data['kelas_ids'] = $stmtKelas->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali']);
    exit;
}

/* ------------------------------------------------------------------ */
/* Page data                                                            */
/* ------------------------------------------------------------------ */
$pengumumanList = db()->query("
    SELECT p.*,
           GROUP_CONCAT(k.nama_kelas ORDER BY k.nama_kelas SEPARATOR ', ') AS kelas_list,
           GROUP_CONCAT(pk.kelas_id ORDER BY pk.kelas_id SEPARATOR ',') AS kelas_ids
    FROM pengumuman p
    LEFT JOIN pengumuman_kelas pk ON p.id = pk.pengumuman_id
    LEFT JOIN kelas k ON pk.kelas_id = k.id
    GROUP BY p.id
    ORDER BY p.created_at DESC
")->fetchAll();

$kelasList = db()->query(
    "SELECT * FROM kelas ORDER BY CAST(SUBSTRING(id,2) AS UNSIGNED)"
)->fetchAll();

$csrfToken = generateCsrfToken();
$appName   = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pengumuman - <?= htmlspecialchars($appName) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Quill.js -->
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
</head>
<body class="bg-gray-100">
<?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="ml-64 pt-16 pb-10 px-6">
  <div class="mt-6">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-gray-800">Pengumuman</h1>
      <button onclick="openAddModal()"
        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium transition">
        <i class="fas fa-plus"></i> Tambah Pengumuman
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
        <input type="text" id="search" oninput="filterTable()" placeholder="Cari pengumuman atau kelas..."
          class="border rounded-lg px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-blue-500">
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
              <th class="px-4 py-3">No</th>
              <th class="px-4 py-3">Isi Pengumuman</th>
              <th class="px-4 py-3">Kelas</th>
              <th class="px-4 py-3">Tanggal</th>
              <th class="px-4 py-3 text-center">Aksi</th>
            </tr>
          </thead>
          <tbody id="peng-tbody">
            <?php foreach ($pengumumanList as $i => $peng): ?>
            <?php
              $plainText = strip_tags($peng['isi']);
              $preview   = mb_strlen($plainText) > 100
                         ? mb_substr($plainText, 0, 100) . '…'
                         : $plainText;
              $searchStr = strtolower($plainText . ' ' . ($peng['kelas_list'] ?? ''));
            ?>
            <tr class="border-t hover:bg-gray-50 peng-row"
                data-search="<?= htmlspecialchars($searchStr) ?>"
                data-id="<?= (int)$peng['id'] ?>">
              <td class="px-4 py-3"><?= $i + 1 ?></td>
              <td class="px-4 py-3 max-w-sm">
                <span class="text-gray-700"><?= htmlspecialchars($preview) ?></span>
              </td>
              <td class="px-4 py-3">
                <?php if ($peng['kelas_list']): ?>
                  <div class="flex flex-wrap gap-1">
                    <?php foreach (explode(', ', $peng['kelas_list']) as $kNama): ?>
                    <span class="bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full text-xs font-semibold">
                      <?= htmlspecialchars(trim($kNama)) ?>
                    </span>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <span class="bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full text-xs">Semua Kelas</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">
                <?= htmlspecialchars($peng['created_at'] ?? '') ?>
              </td>
              <td class="px-4 py-3 text-center">
                <button onclick="editPengumuman(<?= (int)$peng['id'] ?>)"
                  class="text-blue-600 hover:text-blue-800 p-1 mr-1" title="Edit">
                  <i class="fas fa-edit"></i>
                </button>
                <button onclick="hapusPengumuman(<?= (int)$peng['id'] ?>)"
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

<!-- Modal Tambah Pengumuman -->
<div id="add-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-2xl mx-4">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Tambah Pengumuman</h3>

    <div class="mb-4">
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Isi Pengumuman <span class="text-red-500">*</span>
      </label>
      <div id="add-editor" class="bg-white rounded-lg" style="height: 200px;"></div>
    </div>

    <div class="mb-5">
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Kelas yang Menerima
        <span class="text-gray-400 font-normal">(kosongkan = semua kelas)</span>
      </label>
      <div class="border rounded-lg p-3 max-h-40 overflow-y-auto">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
          <?php if (empty($kelasList)): ?>
            <p class="text-gray-400 text-xs col-span-3">Belum ada data kelas</p>
          <?php else: ?>
            <?php foreach ($kelasList as $kelas): ?>
            <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 p-1 rounded text-sm">
              <input type="checkbox" name="add_kelas[]"
                value="<?= htmlspecialchars($kelas['id']) ?>"
                class="add-kelas-check w-4 h-4 accent-blue-600">
              <span>
                <?= htmlspecialchars($kelas['nama_kelas']) ?>
                <span class="text-gray-400 text-xs">(<?= htmlspecialchars($kelas['id']) ?>)</span>
              </span>
            </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="flex gap-3 justify-end">
      <button onclick="closeAddModal()" class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50">Batal</button>
      <button onclick="submitTambah()"
        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
        <i class="fas fa-save mr-1"></i>Simpan
      </button>
    </div>
  </div>
</div>

<!-- Modal Edit Pengumuman -->
<div id="edit-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-2xl mx-4">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Edit Pengumuman</h3>
    <input type="hidden" id="edit-id">

    <div class="mb-4">
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Isi Pengumuman <span class="text-red-500">*</span>
      </label>
      <div id="edit-editor" class="bg-white rounded-lg" style="height: 200px;"></div>
    </div>

    <div class="mb-5">
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Kelas yang Menerima
        <span class="text-gray-400 font-normal">(kosongkan = semua kelas)</span>
      </label>
      <div class="border rounded-lg p-3 max-h-40 overflow-y-auto">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
          <?php if (empty($kelasList)): ?>
            <p class="text-gray-400 text-xs col-span-3">Belum ada data kelas</p>
          <?php else: ?>
            <?php foreach ($kelasList as $kelas): ?>
            <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 p-1 rounded text-sm">
              <input type="checkbox" name="edit_kelas[]"
                value="<?= htmlspecialchars($kelas['id']) ?>"
                class="edit-kelas-check w-4 h-4 accent-blue-600">
              <span>
                <?= htmlspecialchars($kelas['nama_kelas']) ?>
                <span class="text-gray-400 text-xs">(<?= htmlspecialchars($kelas['id']) ?>)</span>
              </span>
            </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="flex gap-3 justify-end">
      <button onclick="closeEditModal()" class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50">Batal</button>
      <button onclick="submitEdit()"
        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
        <i class="fas fa-save mr-1"></i>Simpan
      </button>
    </div>
  </div>
</div>

<script>
const csrf = '<?= $csrfToken ?>';
let perPage = 10, currentPage = 1, filteredRows = [];

/* ---- Quill editors ---- */
const toolbarOptions = [
  ['bold', 'italic', 'underline'],
  [{ 'list': 'ordered' }, { 'list': 'bullet' }],
  [{ 'size': ['small', false, 'large'] }],
  [{ 'color': [] }],
  ['link'],
  ['clean']
];

const addQuill  = new Quill('#add-editor',  { theme: 'snow', modules: { toolbar: toolbarOptions } });
const editQuill = new Quill('#edit-editor', { theme: 'snow', modules: { toolbar: toolbarOptions } });

/* ---- Table filter / pagination ---- */
function filterTable() {
  perPage = parseInt(document.getElementById('perPage').value);
  const search = document.getElementById('search').value.toLowerCase();
  const rows = Array.from(document.querySelectorAll('.peng-row'));
  filteredRows = rows.filter(r => r.dataset.search.includes(search));
  rows.forEach(r => r.style.display = 'none');
  currentPage = 1;
  renderPage();
}

function renderPage() {
  const total = filteredRows.length;
  const start = (currentPage - 1) * perPage;
  const end   = perPage === 999 ? total : start + perPage;
  filteredRows.forEach((r, i) => r.style.display = (i >= start && i < end) ? '' : 'none');
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

/* ---- Add modal ---- */
function openAddModal() {
  addQuill.setContents([]);
  document.querySelectorAll('.add-kelas-check').forEach(c => c.checked = false);
  document.getElementById('add-modal').classList.remove('hidden');
}
function closeAddModal() { document.getElementById('add-modal').classList.add('hidden'); }

function submitTambah() {
  const isi = addQuill.root.innerHTML.trim();
  if (!isi || isi === '<p><br></p>') {
    Swal.fire('Error', 'Isi pengumuman wajib diisi', 'error'); return;
  }
  const kelasIds = Array.from(document.querySelectorAll('.add-kelas-check:checked')).map(c => c.value);
  const fd = new FormData();
  fd.append('csrf_token', csrf); fd.append('action', 'tambah'); fd.append('isi', isi);
  kelasIds.forEach(k => fd.append('kelas_ids[]', k));
  postAction(fd, closeAddModal);
}

/* ---- Edit modal ---- */
function editPengumuman(id) {
  const fd = new FormData();
  fd.append('csrf_token', csrf); fd.append('action', 'get'); fd.append('id', id);
  fetch('pengumuman.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (!data.success) { Swal.fire('Error', data.message, 'error'); return; }
      document.getElementById('edit-id').value = id;
      editQuill.root.innerHTML = data.data.isi;
      document.querySelectorAll('.edit-kelas-check').forEach(c => {
        c.checked = data.data.kelas_ids.includes(c.value);
      });
      document.getElementById('edit-modal').classList.remove('hidden');
    })
    .catch(() => Swal.fire('Error', 'Gagal memuat data', 'error'));
}
function closeEditModal() { document.getElementById('edit-modal').classList.add('hidden'); }

function submitEdit() {
  const id  = document.getElementById('edit-id').value;
  const isi = editQuill.root.innerHTML.trim();
  if (!isi || isi === '<p><br></p>') {
    Swal.fire('Error', 'Isi pengumuman wajib diisi', 'error'); return;
  }
  const kelasIds = Array.from(document.querySelectorAll('.edit-kelas-check:checked')).map(c => c.value);
  const fd = new FormData();
  fd.append('csrf_token', csrf); fd.append('action', 'edit');
  fd.append('id', id);           fd.append('isi', isi);
  kelasIds.forEach(k => fd.append('kelas_ids[]', k));
  postAction(fd, closeEditModal);
}

/* ---- Delete ---- */
function hapusPengumuman(id) {
  Swal.fire({
    title: 'Hapus Pengumuman?',
    text: 'Pengumuman ini akan dihapus permanen.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc2626', confirmButtonText: 'Hapus', cancelButtonText: 'Batal'
  }).then(r => {
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf); fd.append('action', 'hapus'); fd.append('id', id);
    postAction(fd, null);
  });
}

/* ---- Shared POST helper ---- */
function postAction(fd, closeCallback) {
  fetch('pengumuman.php', { method: 'POST', body: fd })
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
