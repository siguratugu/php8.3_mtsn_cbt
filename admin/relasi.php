<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $csrf   = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        echo json_encode(['success' => false, 'message' => 'CSRF tidak valid']);
        exit;
    }

    if ($action === 'get_relasi') {
        $guruId = (int)($_POST['guru_id'] ?? 0);
        $stmt = db()->prepare("SELECT DISTINCT kelas_id FROM relasi_guru WHERE guru_id = ?");
        $stmt->execute([$guruId]);
        $kelas = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt2 = db()->prepare("SELECT DISTINCT mapel_id FROM relasi_guru WHERE guru_id = ?");
        $stmt2->execute([$guruId]);
        $mapel = $stmt2->fetchAll(PDO::FETCH_COLUMN);

        echo json_encode(['success' => true, 'kelas' => $kelas, 'mapel' => $mapel]);
        exit;
    }

    if ($action === 'simpan_relasi') {
        $guruId    = (int)($_POST['guru_id'] ?? 0);
        $kelasList = $_POST['kelas_ids'] ?? [];
        $mapelList = $_POST['mapel_ids'] ?? [];

        db()->prepare("DELETE FROM relasi_guru WHERE guru_id = ?")->execute([$guruId]);

        if (!empty($kelasList) && !empty($mapelList)) {
            $stmt = db()->prepare(
                "INSERT IGNORE INTO relasi_guru (guru_id, kelas_id, mapel_id) VALUES (?, ?, ?)"
            );
            foreach ($kelasList as $kId) {
                foreach ($mapelList as $mId) {
                    $stmt->execute([$guruId, (string)$kId, (string)$mId]);
                }
            }
        }
        echo json_encode(['success' => true, 'message' => 'Relasi berhasil disimpan']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali']);
    exit;
}

$guruList = db()->query("
    SELECT g.id, g.nama,
           COUNT(DISTINCT r.mapel_id) AS jml_mapel,
           COUNT(DISTINCT r.kelas_id) AS jml_kelas
    FROM guru g
    LEFT JOIN relasi_guru r ON g.id = r.guru_id
    GROUP BY g.id, g.nama
    ORDER BY g.nama
")->fetchAll();

$kelasList = db()->query(
    "SELECT * FROM kelas ORDER BY CAST(SUBSTRING(id,2) AS UNSIGNED)"
)->fetchAll();

$mapelList = db()->query(
    "SELECT * FROM mapel ORDER BY CAST(SUBSTRING(id,2) AS UNSIGNED)"
)->fetchAll();

$csrfToken = generateCsrfToken();
$appName   = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Menu Relasi - <?= htmlspecialchars($appName) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100">
<?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="ml-64 pt-16 pb-10 px-6">
  <div class="mt-6">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Menu Relasi Guru</h1>

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
        <input type="text" id="search" oninput="filterTable()" placeholder="Cari guru..."
          class="border rounded-lg px-3 py-2 text-sm w-56 focus:outline-none focus:ring-2 focus:ring-blue-500">
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
              <th class="px-4 py-3">No</th>
              <th class="px-4 py-3">Nama Guru</th>
              <th class="px-4 py-3 text-center">Jumlah Mapel</th>
              <th class="px-4 py-3 text-center">Jumlah Kelas</th>
              <th class="px-4 py-3 text-center">Aksi</th>
            </tr>
          </thead>
          <tbody id="relasi-tbody">
            <?php foreach ($guruList as $i => $guru): ?>
            <tr class="border-t hover:bg-gray-50 guru-row"
                data-search="<?= htmlspecialchars(strtolower($guru['nama'])) ?>">
              <td class="px-4 py-3 row-no"><?= $i + 1 ?></td>
              <td class="px-4 py-3 font-medium"><?= htmlspecialchars($guru['nama']) ?></td>
              <td class="px-4 py-3 text-center">
                <span class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full text-xs font-semibold">
                  <?= (int)$guru['jml_mapel'] ?>
                </span>
              </td>
              <td class="px-4 py-3 text-center">
                <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs font-semibold">
                  <?= (int)$guru['jml_kelas'] ?>
                </span>
              </td>
              <td class="px-4 py-3 text-center">
                <button
                  onclick="openRelasi(<?= (int)$guru['id'] ?>, '<?= htmlspecialchars(addslashes($guru['nama'])) ?>')"
                  class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-xs font-medium transition">
                  <i class="fas fa-link mr-1"></i>Relasikan
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

<!-- Modal Relasi -->
<div id="relasi-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-2xl mx-4">
    <h3 class="text-lg font-bold text-gray-800 mb-1">Relasi Guru</h3>
    <p class="text-gray-500 text-sm mb-4" id="relasi-guru-nama"></p>
    <input type="hidden" id="relasi-guru-id">

    <div class="grid grid-cols-2 gap-6 mb-6">
      <div>
        <h4 class="font-semibold text-gray-700 mb-3">Pilih Kelas</h4>
        <div class="border rounded-lg p-3 max-h-60 overflow-y-auto space-y-2">
          <?php if (empty($kelasList)): ?>
            <p class="text-gray-400 text-xs text-center py-4">Belum ada data kelas</p>
          <?php else: ?>
            <?php foreach ($kelasList as $kelas): ?>
            <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 p-1 rounded">
              <input type="checkbox" name="relasi_kelas[]"
                value="<?= htmlspecialchars($kelas['id']) ?>"
                class="kelas-check w-4 h-4 accent-blue-600">
              <span class="text-sm">
                <?= htmlspecialchars($kelas['nama_kelas']) ?>
                <span class="text-gray-400 text-xs">(<?= htmlspecialchars($kelas['id']) ?>)</span>
              </span>
            </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <button type="button" onclick="toggleAllCheckboxes('kelas-check')"
          class="mt-2 text-xs text-blue-600 hover:underline">Pilih / Hapus Semua</button>
      </div>
      <div>
        <h4 class="font-semibold text-gray-700 mb-3">Pilih Mapel</h4>
        <div class="border rounded-lg p-3 max-h-60 overflow-y-auto space-y-2">
          <?php if (empty($mapelList)): ?>
            <p class="text-gray-400 text-xs text-center py-4">Belum ada data mapel</p>
          <?php else: ?>
            <?php foreach ($mapelList as $mapel): ?>
            <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 p-1 rounded">
              <input type="checkbox" name="relasi_mapel[]"
                value="<?= htmlspecialchars($mapel['id']) ?>"
                class="mapel-check w-4 h-4 accent-blue-600">
              <span class="text-sm">
                <?= htmlspecialchars($mapel['nama_mapel']) ?>
                <span class="text-gray-400 text-xs">(<?= htmlspecialchars($mapel['id']) ?>)</span>
              </span>
            </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <button type="button" onclick="toggleAllCheckboxes('mapel-check')"
          class="mt-2 text-xs text-blue-600 hover:underline">Pilih / Hapus Semua</button>
      </div>
    </div>

    <div class="flex gap-3 justify-end">
      <button onclick="closeRelasi()"
        class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50">Batal</button>
      <button onclick="simpanRelasi()"
        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
        <i class="fas fa-save mr-1"></i>Simpan Relasi
      </button>
    </div>
  </div>
</div>

<script>
const csrf = '<?= $csrfToken ?>';
let perPage = 10, currentPage = 1, filteredRows = [];

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

function toggleAllCheckboxes(cls) {
  const boxes = document.querySelectorAll('.' + cls);
  const allChecked = Array.from(boxes).every(b => b.checked);
  boxes.forEach(b => b.checked = !allChecked);
}

function openRelasi(guruId, guruNama) {
  document.getElementById('relasi-guru-id').value = guruId;
  document.getElementById('relasi-guru-nama').textContent = 'Guru: ' + guruNama;
  document.querySelectorAll('.kelas-check, .mapel-check').forEach(c => c.checked = false);

  const fd = new FormData();
  fd.append('csrf_token', csrf);
  fd.append('action', 'get_relasi');
  fd.append('guru_id', guruId);

  fetch('relasi.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        data.kelas.forEach(k => {
          const c = document.querySelector(`.kelas-check[value="${k}"]`);
          if (c) c.checked = true;
        });
        data.mapel.forEach(m => {
          const c = document.querySelector(`.mapel-check[value="${m}"]`);
          if (c) c.checked = true;
        });
      }
      document.getElementById('relasi-modal').classList.remove('hidden');
    })
    .catch(() => Swal.fire('Error', 'Gagal memuat data relasi', 'error'));
}

function closeRelasi() {
  document.getElementById('relasi-modal').classList.add('hidden');
}

function simpanRelasi() {
  const guruId   = document.getElementById('relasi-guru-id').value;
  const kelasIds = Array.from(document.querySelectorAll('.kelas-check:checked')).map(c => c.value);
  const mapelIds = Array.from(document.querySelectorAll('.mapel-check:checked')).map(c => c.value);

  const fd = new FormData();
  fd.append('csrf_token', csrf);
  fd.append('action', 'simpan_relasi');
  fd.append('guru_id', guruId);
  kelasIds.forEach(k => fd.append('kelas_ids[]', k));
  mapelIds.forEach(m => fd.append('mapel_ids[]', m));

  fetch('relasi.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Swal.fire({
          icon: 'success', title: 'Berhasil',
          text: data.message, timer: 1500, showConfirmButton: false
        });
        closeRelasi();
        setTimeout(() => location.reload(), 1500);
      } else {
        Swal.fire('Error', data.message, 'error');
      }
    })
    .catch(() => Swal.fire('Error', 'Gagal menyimpan relasi', 'error'));
}

filterTable();
</script>
</body>
</html>
