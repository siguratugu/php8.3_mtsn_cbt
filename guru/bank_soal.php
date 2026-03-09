<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['guru_id'])) { header('Location: ../login.php'); exit; }

// ── GET AJAX ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_GET['action'] === 'get_mapel_guru') {
        $guru_id = (int)($_GET['guru_id'] ?? 0);
        $stmt = db()->prepare(
            "SELECT DISTINCT m.id, m.nama_mapel
             FROM mapel m
             INNER JOIN relasi_guru rg ON rg.mapel_id = m.id
             WHERE rg.guru_id = ?
             ORDER BY m.nama_mapel"
        );
        $stmt->execute([$guru_id]);
        echo json_encode($stmt->fetchAll());
        exit;
    }
    echo json_encode([]);
    exit;
}

// ── POST AJAX ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF tidak valid']); exit;
    }

    $action  = $_POST['action'] ?? '';
    $adminId = $_SESSION['guru_id'];

    // ── tambah / tambah_redirect ───────────────────────────────────────────
    if (in_array($action, ['tambah', 'tambah_redirect'], true)) {
        $pembuat       = $_POST['pembuat'] ?? 'admin';
        $guruId        = ($pembuat === 'guru')  ? (int)($_POST['guru_id'] ?? 0) : null;
        $thisAdminId   = ($pembuat === 'admin') ? $adminId : null;
        $namaSoal      = trim($_POST['nama_soal'] ?? '');
        $mapelId       = ($_POST['mapel_id'] ?? '') ?: null;
        $waktu         = (int)($_POST['waktu_mengerjakan'] ?? 60);
        $bobotPg       = (float)($_POST['bobot_pg']          ?? 0);
        $bobotEssai    = (float)($_POST['bobot_essai']        ?? 0);
        $bobotMenjodoh = (float)($_POST['bobot_menjodohkan']  ?? 0);
        $bobotBs       = (float)($_POST['bobot_benar_salah']  ?? 0);

        if (empty($namaSoal)) {
            echo json_encode(['success' => false, 'message' => 'Nama soal wajib diisi']); exit;
        }
        if ($pembuat === 'guru' && !$guruId) {
            echo json_encode(['success' => false, 'message' => 'Pilih guru terlebih dahulu']); exit;
        }

        $stmt = db()->prepare(
            "INSERT INTO bank_soal
             (guru_id, admin_id, mapel_id, nama_soal, waktu_mengerjakan,
              bobot_pg, bobot_essai, bobot_menjodohkan, bobot_benar_salah)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$guruId, $thisAdminId, $mapelId, $namaSoal, $waktu,
                        $bobotPg, $bobotEssai, $bobotMenjodoh, $bobotBs]);
        $newId = (int)db()->lastInsertId();

        echo json_encode([
            'success'  => true,
            'message'  => 'Bank soal berhasil ditambahkan',
            'bank_id'  => $newId,
            'redirect' => ($action === 'tambah_redirect'),
        ]);
        exit;
    }

    // ── edit ──────────────────────────────────────────────────────────────
    if ($action === 'edit') {
        $id            = (int)($_POST['id'] ?? 0);
        $pembuat       = $_POST['pembuat'] ?? 'admin';
        $guruId        = ($pembuat === 'guru')  ? (int)($_POST['guru_id'] ?? 0) : null;
        $thisAdminId   = ($pembuat === 'admin') ? $adminId : null;
        $namaSoal      = trim($_POST['nama_soal'] ?? '');
        $mapelId       = ($_POST['mapel_id'] ?? '') ?: null;
        $waktu         = (int)($_POST['waktu_mengerjakan'] ?? 60);
        $bobotPg       = (float)($_POST['bobot_pg']          ?? 0);
        $bobotEssai    = (float)($_POST['bobot_essai']        ?? 0);
        $bobotMenjodoh = (float)($_POST['bobot_menjodohkan']  ?? 0);
        $bobotBs       = (float)($_POST['bobot_benar_salah']  ?? 0);

        if (!$id || empty($namaSoal)) {
            echo json_encode(['success' => false, 'message' => 'Data tidak valid']); exit;
        }

        $stmt = db()->prepare(
            "UPDATE bank_soal
             SET guru_id=?, admin_id=?, mapel_id=?, nama_soal=?,
                 waktu_mengerjakan=?, bobot_pg=?, bobot_essai=?,
                 bobot_menjodohkan=?, bobot_benar_salah=?
             WHERE id=?"
        );
        $stmt->execute([$guruId, $thisAdminId, $mapelId, $namaSoal, $waktu,
                        $bobotPg, $bobotEssai, $bobotMenjodoh, $bobotBs, $id]);
        echo json_encode(['success' => true, 'message' => 'Bank soal berhasil diperbarui']);
        exit;
    }

    // ── hapus ─────────────────────────────────────────────────────────────
    if ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM bank_soal WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Bank soal berhasil dihapus']);
        exit;
    }

    // ── hapus_multiple ────────────────────────────────────────────────────
    if ($action === 'hapus_multiple') {
        $ids = array_map('intval', json_decode($_POST['ids'] ?? '[]', true) ?: []);
        if (!empty($ids)) {
            $pl = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("DELETE FROM bank_soal WHERE id IN ($pl)")->execute($ids);
        }
        echo json_encode(['success' => true, 'message' => 'Bank soal terpilih berhasil dihapus']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali']); exit;
}

// ── Page Data ─────────────────────────────────────────────────────────────────
$bankSoalList = db()->query(
    "SELECT bs.*,
            COALESCE(g.nama, a.nama) AS pembuat_nama,
            CASE WHEN bs.guru_id IS NOT NULL THEN 'Guru' ELSE 'Admin' END AS pembuat_tipe,
            m.nama_mapel,
            COUNT(s.id) AS jumlah_soal
     FROM bank_soal bs
     LEFT JOIN guru  g  ON g.id = bs.guru_id
     LEFT JOIN admin a  ON a.id = bs.admin_id
     LEFT JOIN mapel m  ON m.id = bs.mapel_id
     LEFT JOIN soal  s  ON s.bank_soal_id = bs.id
     GROUP BY bs.id
     ORDER BY bs.created_at DESC"
)->fetchAll();

$guruList  = db()->query("SELECT id, nama FROM guru  ORDER BY nama")->fetchAll();
$mapelList = db()->query("SELECT id, nama_mapel FROM mapel ORDER BY nama_mapel")->fetchAll();
$appName   = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bank Soal – <?= htmlspecialchars($appName) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100">
<?php include __DIR__ . '/../includes/sidebar_guru.php'; ?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="ml-64 pt-16 pb-10 px-6">
  <div class="mt-6">

    <!-- Page Title + Add Button -->
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-gray-800">Bank Soal</h1>
      <button onclick="openTambahModal()"
        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium transition">
        <i class="fas fa-plus"></i> Tambah Soal
      </button>
    </div>

    <!-- Table Card -->
    <div class="bg-white rounded-xl shadow overflow-hidden">

      <!-- Toolbar -->
      <div class="p-4 border-b flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-center gap-2">
          <span class="text-sm text-gray-600">Tampilkan:</span>
          <select id="perPage" onchange="filterTable()"
            class="border rounded px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="999">Semua</option>
          </select>
        </div>
        <div class="flex items-center gap-3">
          <button id="btn-bulk-delete" onclick="hapusTerpilih()"
            class="hidden bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
            <i class="fas fa-trash mr-1"></i> Hapus Terpilih
          </button>
          <input type="text" id="search" oninput="filterTable()" placeholder="Cari soal, mapel, pembuat…"
            class="border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-64">
        </div>
      </div>

      <!-- Table -->
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
              <th class="px-4 py-3 w-10">
                <input type="checkbox" id="check-all" onchange="toggleAll(this)">
              </th>
              <th class="px-4 py-3 w-12">No</th>
              <th class="px-4 py-3">Pembuat</th>
              <th class="px-4 py-3">Nama Soal</th>
              <th class="px-4 py-3">Mata Pelajaran</th>
              <th class="px-4 py-3 text-center">Waktu</th>
              <th class="px-4 py-3 text-center">Jumlah Soal</th>
              <th class="px-4 py-3 text-center">Aksi</th>
            </tr>
          </thead>
          <tbody id="bank-tbody">
            <?php foreach ($bankSoalList as $i => $row): ?>
            <tr class="border-t hover:bg-gray-50 bank-row"
                data-search="<?= htmlspecialchars(strtolower(
                    $row['nama_soal'] . ' ' . ($row['nama_mapel'] ?? '') . ' ' . $row['pembuat_nama']
                )) ?>">
              <td class="px-4 py-3">
                <input type="checkbox" class="row-check" value="<?= $row['id'] ?>" onchange="updateBulkBtn()">
              </td>
              <td class="px-4 py-3 text-gray-500 row-num"><?= $i + 1 ?></td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-2">
                  <span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $row['pembuat_tipe'] === 'Guru' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700' ?>">
                    <?= htmlspecialchars($row['pembuat_tipe']) ?>
                  </span>
                  <span class="font-medium text-gray-800"><?= htmlspecialchars($row['pembuat_nama']) ?></span>
                </div>
              </td>
              <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($row['nama_soal']) ?></td>
              <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($row['nama_mapel'] ?? '—') ?></td>
              <td class="px-4 py-3 text-center text-gray-600">
                <span class="inline-flex items-center gap-1">
                  <i class="fas fa-clock text-gray-400 text-xs"></i>
                  <?= $row['waktu_mengerjakan'] ?> menit
                </span>
              </td>
              <td class="px-4 py-3 text-center">
                <span class="bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full text-xs font-medium">
                  <?= $row['jumlah_soal'] ?> soal
                </span>
              </td>
              <td class="px-4 py-3 text-center">
                <button
                  onclick="editBankSoal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)"
                  class="text-blue-600 hover:text-blue-800 p-1.5 hover:bg-blue-50 rounded" title="Edit">
                  <i class="fas fa-edit"></i>
                </button>
                <a href="buat_soal.php?bank_id=<?= $row['id'] ?>"
                  class="text-green-600 hover:text-green-800 p-1.5 hover:bg-green-50 rounded inline-block" title="Buat Soal">
                  <i class="fas fa-pen-to-square"></i>
                </a>
                <button onclick="hapusBankSoal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['nama_soal'])) ?>')"
                  class="text-red-600 hover:text-red-800 p-1.5 hover:bg-red-50 rounded" title="Hapus">
                  <i class="fas fa-trash"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Footer / Pagination -->
      <div class="p-4 border-t flex items-center justify-between text-sm text-gray-600 flex-wrap gap-3">
        <span id="showing-info"></span>
        <div id="pagination" class="flex gap-1"></div>
      </div>
    </div>

  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

<!-- ════════════════════════ MODAL TAMBAH ════════════════════════ -->
<div id="tambah-modal" class="fixed inset-0 bg-black/50 hidden z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl mx-auto max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between p-6 border-b sticky top-0 bg-white z-10">
      <h3 class="text-lg font-bold text-gray-800">Tambah Bank Soal</h3>
      <button onclick="closeTambahModal()" class="text-gray-400 hover:text-gray-600">
        <i class="fas fa-times text-xl"></i>
      </button>
    </div>
    <div class="p-6 space-y-4">

      <!-- Pembuat -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Pembuat <span class="text-red-500">*</span></label>
        <select id="t-pembuat" onchange="onPembuatChange('t')"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="admin">Admin (Saya)</option>
          <option value="guru">Guru</option>
        </select>
      </div>

      <!-- Guru dropdown (hidden by default) -->
      <div id="t-guru-wrap" class="hidden">
        <label class="block text-sm font-medium text-gray-700 mb-1">Pilih Guru <span class="text-red-500">*</span></label>
        <select id="t-guru-id" onchange="loadMapelGuru('t')"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">— Pilih Guru —</option>
          <?php foreach ($guruList as $g): ?>
          <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Nama Soal -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nama Soal <span class="text-red-500">*</span></label>
        <input type="text" id="t-nama-soal" placeholder="Contoh: UTS Matematika Kelas 7"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
      </div>

      <!-- Mata Pelajaran -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Mata Pelajaran</label>
        <select id="t-mapel-id"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">— Pilih Mapel —</option>
          <?php foreach ($mapelList as $m): ?>
          <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nama_mapel']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Waktu -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Waktu Mengerjakan (menit) <span class="text-red-500">*</span></label>
        <input type="number" id="t-waktu" value="60" min="1" max="300"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
      </div>

      <!-- Bobot -->
      <div class="bg-gray-50 rounded-xl p-4">
        <p class="text-sm font-semibold text-gray-700 mb-3">Bobot Nilai (%)</p>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs text-gray-600 mb-1">Pilihan Ganda (PG)</label>
            <input type="number" id="t-bobot-pg" value="0" min="0" max="100" step="0.01"
              oninput="updateTotal('t')"
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
          <div>
            <label class="block text-xs text-gray-600 mb-1">Essai</label>
            <input type="number" id="t-bobot-essai" value="0" min="0" max="100" step="0.01"
              oninput="updateTotal('t')"
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
          <div>
            <label class="block text-xs text-gray-600 mb-1">Menjodohkan</label>
            <input type="number" id="t-bobot-menjodohkan" value="0" min="0" max="100" step="0.01"
              oninput="updateTotal('t')"
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
          <div>
            <label class="block text-xs text-gray-600 mb-1">Benar / Salah</label>
            <input type="number" id="t-bobot-benar-salah" value="0" min="0" max="100" step="0.01"
              oninput="updateTotal('t')"
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
        </div>
        <div class="mt-3 flex items-center justify-between">
          <span class="text-sm font-medium text-gray-700">Total Bobot:</span>
          <span id="t-total-bobot" class="text-sm font-bold text-green-600">0%</span>
        </div>
        <p id="t-bobot-warn" class="hidden text-xs text-red-500 mt-1">
          <i class="fas fa-exclamation-triangle mr-1"></i>Total bobot melebihi 100%!
        </p>
      </div>

    </div>
    <div class="flex gap-3 justify-end p-6 border-t bg-gray-50 rounded-b-2xl">
      <button onclick="closeTambahModal()"
        class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-100 transition">Batal</button>
      <button onclick="submitTambah(false)"
        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
        <i class="fas fa-save mr-1"></i> Simpan
      </button>
      <button onclick="submitTambah(true)"
        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
        <i class="fas fa-pen-to-square mr-1"></i> Simpan &amp; Buat Soal
      </button>
    </div>
  </div>
</div>

<!-- ════════════════════════ MODAL EDIT ════════════════════════ -->
<div id="edit-modal" class="fixed inset-0 bg-black/50 hidden z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl mx-auto max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between p-6 border-b sticky top-0 bg-white z-10">
      <h3 class="text-lg font-bold text-gray-800">Edit Bank Soal</h3>
      <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
        <i class="fas fa-times text-xl"></i>
      </button>
    </div>
    <div class="p-6 space-y-4">
      <input type="hidden" id="e-id">

      <!-- Pembuat -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Pembuat <span class="text-red-500">*</span></label>
        <select id="e-pembuat" onchange="onPembuatChange('e')"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="admin">Admin (Saya)</option>
          <option value="guru">Guru</option>
        </select>
      </div>

      <!-- Guru dropdown -->
      <div id="e-guru-wrap" class="hidden">
        <label class="block text-sm font-medium text-gray-700 mb-1">Pilih Guru <span class="text-red-500">*</span></label>
        <select id="e-guru-id" onchange="loadMapelGuru('e')"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">— Pilih Guru —</option>
          <?php foreach ($guruList as $g): ?>
          <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Nama Soal -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nama Soal <span class="text-red-500">*</span></label>
        <input type="text" id="e-nama-soal"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
      </div>

      <!-- Mata Pelajaran -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Mata Pelajaran</label>
        <select id="e-mapel-id"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">— Pilih Mapel —</option>
          <?php foreach ($mapelList as $m): ?>
          <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nama_mapel']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Waktu -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Waktu Mengerjakan (menit) <span class="text-red-500">*</span></label>
        <input type="number" id="e-waktu" min="1" max="300"
          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
      </div>

      <!-- Bobot -->
      <div class="bg-gray-50 rounded-xl p-4">
        <p class="text-sm font-semibold text-gray-700 mb-3">Bobot Nilai (%)</p>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs text-gray-600 mb-1">Pilihan Ganda (PG)</label>
            <input type="number" id="e-bobot-pg" value="0" min="0" max="100" step="0.01"
              oninput="updateTotal('e')"
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
          <div>
            <label class="block text-xs text-gray-600 mb-1">Essai</label>
            <input type="number" id="e-bobot-essai" value="0" min="0" max="100" step="0.01"
              oninput="updateTotal('e')"
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
          <div>
            <label class="block text-xs text-gray-600 mb-1">Menjodohkan</label>
            <input type="number" id="e-bobot-menjodohkan" value="0" min="0" max="100" step="0.01"
              oninput="updateTotal('e')"
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
          <div>
            <label class="block text-xs text-gray-600 mb-1">Benar / Salah</label>
            <input type="number" id="e-bobot-benar-salah" value="0" min="0" max="100" step="0.01"
              oninput="updateTotal('e')"
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
        </div>
        <div class="mt-3 flex items-center justify-between">
          <span class="text-sm font-medium text-gray-700">Total Bobot:</span>
          <span id="e-total-bobot" class="text-sm font-bold text-green-600">0%</span>
        </div>
        <p id="e-bobot-warn" class="hidden text-xs text-red-500 mt-1">
          <i class="fas fa-exclamation-triangle mr-1"></i>Total bobot melebihi 100%!
        </p>
      </div>

    </div>
    <div class="flex gap-3 justify-end p-6 border-t bg-gray-50 rounded-b-2xl">
      <button onclick="closeEditModal()"
        class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-100 transition">Batal</button>
      <button onclick="submitEdit()"
        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
        <i class="fas fa-save mr-1"></i> Simpan Perubahan
      </button>
    </div>
  </div>
</div>

<script>
const csrf = '<?= $csrfToken ?>';
let currentPage = 1;
let perPage = 10;
let filteredRows = [];

// ── Table filter & pagination ──────────────────────────────────────────────────
function filterTable() {
  perPage = parseInt(document.getElementById('perPage').value);
  const q = document.getElementById('search').value.toLowerCase();
  const rows = Array.from(document.querySelectorAll('.bank-row'));
  filteredRows = rows.filter(r => r.dataset.search.includes(q));
  rows.forEach(r => r.style.display = 'none');
  currentPage = 1;
  renderPage();
}

function renderPage() {
  const total = filteredRows.length;
  const start = (currentPage - 1) * perPage;
  const end   = perPage === 999 ? total : start + perPage;
  filteredRows.forEach((r, i) => r.style.display = (i >= start && i < end) ? '' : 'none');

  // renumber
  document.querySelectorAll('.bank-row:not([style*="none"]) .row-num')
    .forEach((cell, idx) => cell.textContent = start + idx + 1);

  document.getElementById('showing-info').textContent =
    total === 0 ? 'Tidak ada data' :
    `Menampilkan ${start + 1}–${Math.min(end, total)} dari ${total} data`;
  renderPagination(total);
}

function renderPagination(total) {
  const pages = perPage === 999 ? 1 : Math.ceil(total / perPage);
  const div = document.getElementById('pagination');
  div.innerHTML = '';
  if (pages <= 1) return;
  const mkBtn = (label, page, active, disabled) => {
    const b = document.createElement('button');
    b.innerHTML = label;
    b.className = `px-3 py-1 rounded text-sm transition ${
      active   ? 'bg-blue-600 text-white' :
      disabled ? 'bg-gray-100 text-gray-400 cursor-not-allowed' :
                 'bg-gray-200 hover:bg-gray-300'}`;
    if (!disabled) b.onclick = () => { currentPage = page; renderPage(); };
    return b;
  };
  div.appendChild(mkBtn('&laquo;', currentPage - 1, false, currentPage === 1));
  for (let i = 1; i <= pages; i++) div.appendChild(mkBtn(i, i, i === currentPage, false));
  div.appendChild(mkBtn('&raquo;', currentPage + 1, false, currentPage === pages));
}

// ── Bulk checkbox ──────────────────────────────────────────────────────────────
function toggleAll(cb) {
  document.querySelectorAll('.row-check').forEach(c => c.checked = cb.checked);
  updateBulkBtn();
}
function updateBulkBtn() {
  const total   = document.querySelectorAll('.row-check').length;
  const checked = document.querySelectorAll('.row-check:checked').length;
  document.getElementById('btn-bulk-delete').classList.toggle('hidden', checked === 0);
  document.getElementById('check-all').indeterminate = checked > 0 && checked < total;
  if (checked === total && total > 0) document.getElementById('check-all').checked = true;
  if (checked === 0) document.getElementById('check-all').checked = false;
}

// ── Bobot total ────────────────────────────────────────────────────────────────
function updateTotal(prefix) {
  const ids = ['bobot-pg', 'bobot-essai', 'bobot-menjodohkan', 'bobot-benar-salah'];
  const total = ids.reduce((sum, id) => {
    return sum + (parseFloat(document.getElementById(`${prefix}-${id}`)?.value) || 0);
  }, 0);
  const el = document.getElementById(`${prefix}-total-bobot`);
  const warn = document.getElementById(`${prefix}-bobot-warn`);
  el.textContent = total.toFixed(2) + '%';
  el.className = `text-sm font-bold ${total > 100 ? 'text-red-600' : total === 100 ? 'text-green-600' : 'text-yellow-600'}`;
  warn.classList.toggle('hidden', total <= 100);
}

// ── Pembuat change ─────────────────────────────────────────────────────────────
function onPembuatChange(prefix) {
  const val  = document.getElementById(`${prefix}-pembuat`).value;
  const wrap = document.getElementById(`${prefix}-guru-wrap`);
  wrap.classList.toggle('hidden', val !== 'guru');
  if (val === 'admin') {
    // restore full mapel list
    populateMapel(prefix, <?= json_encode($mapelList) ?>, null);
  } else {
    document.getElementById(`${prefix}-mapel-id`).innerHTML = '<option value="">— Pilih mapel guru dahulu —</option>';
  }
}

function populateMapel(prefix, list, selectedId) {
  const sel = document.getElementById(`${prefix}-mapel-id`);
  sel.innerHTML = '<option value="">— Pilih Mapel —</option>';
  list.forEach(m => {
    const opt = document.createElement('option');
    opt.value = m.id;
    opt.textContent = m.nama_mapel;
    if (selectedId && String(m.id) === String(selectedId)) opt.selected = true;
    sel.appendChild(opt);
  });
}

async function loadMapelGuru(prefix) {
  const guruId = document.getElementById(`${prefix}-guru-id`).value;
  if (!guruId) {
    document.getElementById(`${prefix}-mapel-id`).innerHTML = '<option value="">— Pilih mapel guru dahulu —</option>';
    return;
  }
  const res  = await fetch(`bank_soal.php?action=get_mapel_guru&guru_id=${guruId}`);
  const list = await res.json();
  populateMapel(prefix, list, null);
}

// ── Modal Tambah ───────────────────────────────────────────────────────────────
function openTambahModal() {
  document.getElementById('t-pembuat').value = 'admin';
  document.getElementById('t-guru-wrap').classList.add('hidden');
  document.getElementById('t-nama-soal').value = '';
  document.getElementById('t-waktu').value = '60';
  document.getElementById('t-mapel-id').value = '';
  ['t-bobot-pg','t-bobot-essai','t-bobot-menjodohkan','t-bobot-benar-salah']
    .forEach(id => document.getElementById(id).value = '0');
  updateTotal('t');
  populateMapel('t', <?= json_encode($mapelList) ?>, null);
  document.getElementById('tambah-modal').classList.remove('hidden');
}
function closeTambahModal() { document.getElementById('tambah-modal').classList.add('hidden'); }

async function submitTambah(redirect) {
  const pembuat  = document.getElementById('t-pembuat').value;
  const namaSoal = document.getElementById('t-nama-soal').value.trim();
  if (!namaSoal) { Swal.fire('Error', 'Nama soal wajib diisi', 'error'); return; }
  if (pembuat === 'guru' && !document.getElementById('t-guru-id').value) {
    Swal.fire('Error', 'Pilih guru terlebih dahulu', 'error'); return;
  }
  const total = ['t-bobot-pg','t-bobot-essai','t-bobot-menjodohkan','t-bobot-benar-salah']
    .reduce((s, id) => s + (parseFloat(document.getElementById(id).value) || 0), 0);
  if (total > 100) {
    const conf = await Swal.fire({
      title: 'Total bobot > 100%', text: 'Tetap simpan?', icon: 'warning',
      showCancelButton: true, confirmButtonText: 'Ya, Simpan', cancelButtonText: 'Batal'
    });
    if (!conf.isConfirmed) return;
  }

  const fd = new FormData();
  fd.append('csrf_token', csrf);
  fd.append('action', redirect ? 'tambah_redirect' : 'tambah');
  fd.append('pembuat', pembuat);
  if (pembuat === 'guru') fd.append('guru_id', document.getElementById('t-guru-id').value);
  fd.append('nama_soal', namaSoal);
  fd.append('mapel_id', document.getElementById('t-mapel-id').value);
  fd.append('waktu_mengerjakan', document.getElementById('t-waktu').value);
  fd.append('bobot_pg',          document.getElementById('t-bobot-pg').value);
  fd.append('bobot_essai',       document.getElementById('t-bobot-essai').value);
  fd.append('bobot_menjodohkan', document.getElementById('t-bobot-menjodohkan').value);
  fd.append('bobot_benar_salah', document.getElementById('t-bobot-benar-salah').value);

  const res  = await fetch('bank_soal.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.success) {
    closeTambahModal();
    if (data.redirect) {
      window.location.href = `buat_soal.php?bank_id=${data.bank_id}`;
    } else {
      Swal.fire({ icon: 'success', title: 'Berhasil', text: data.message, timer: 1500, showConfirmButton: false });
      setTimeout(() => location.reload(), 1500);
    }
  } else {
    Swal.fire('Error', data.message, 'error');
  }
}

// ── Modal Edit ─────────────────────────────────────────────────────────────────
async function editBankSoal(row) {
  document.getElementById('e-id').value         = row.id;
  document.getElementById('e-nama-soal').value  = row.nama_soal;
  document.getElementById('e-waktu').value      = row.waktu_mengerjakan;
  document.getElementById('e-bobot-pg').value           = row.bobot_pg;
  document.getElementById('e-bobot-essai').value        = row.bobot_essai;
  document.getElementById('e-bobot-menjodohkan').value  = row.bobot_menjodohkan;
  document.getElementById('e-bobot-benar-salah').value  = row.bobot_benar_salah;

  const isGuru = row.guru_id !== null && row.guru_id !== '';
  document.getElementById('e-pembuat').value = isGuru ? 'guru' : 'admin';
  document.getElementById('e-guru-wrap').classList.toggle('hidden', !isGuru);

  if (isGuru) {
    document.getElementById('e-guru-id').value = row.guru_id;
    const res  = await fetch(`bank_soal.php?action=get_mapel_guru&guru_id=${row.guru_id}`);
    const list = await res.json();
    populateMapel('e', list, row.mapel_id);
  } else {
    populateMapel('e', <?= json_encode($mapelList) ?>, row.mapel_id);
  }
  updateTotal('e');
  document.getElementById('edit-modal').classList.remove('hidden');
}
function closeEditModal() { document.getElementById('edit-modal').classList.add('hidden'); }

async function submitEdit() {
  const pembuat  = document.getElementById('e-pembuat').value;
  const namaSoal = document.getElementById('e-nama-soal').value.trim();
  if (!namaSoal) { Swal.fire('Error', 'Nama soal wajib diisi', 'error'); return; }

  const total = ['e-bobot-pg','e-bobot-essai','e-bobot-menjodohkan','e-bobot-benar-salah']
    .reduce((s, id) => s + (parseFloat(document.getElementById(id).value) || 0), 0);
  if (total > 100) {
    const conf = await Swal.fire({
      title: 'Total bobot > 100%', text: 'Tetap simpan?', icon: 'warning',
      showCancelButton: true, confirmButtonText: 'Ya, Simpan', cancelButtonText: 'Batal'
    });
    if (!conf.isConfirmed) return;
  }

  const fd = new FormData();
  fd.append('csrf_token', csrf);
  fd.append('action', 'edit');
  fd.append('id',       document.getElementById('e-id').value);
  fd.append('pembuat',  pembuat);
  if (pembuat === 'guru') fd.append('guru_id', document.getElementById('e-guru-id').value);
  fd.append('nama_soal',   document.getElementById('e-nama-soal').value.trim());
  fd.append('mapel_id',    document.getElementById('e-mapel-id').value);
  fd.append('waktu_mengerjakan', document.getElementById('e-waktu').value);
  fd.append('bobot_pg',          document.getElementById('e-bobot-pg').value);
  fd.append('bobot_essai',       document.getElementById('e-bobot-essai').value);
  fd.append('bobot_menjodohkan', document.getElementById('e-bobot-menjodohkan').value);
  fd.append('bobot_benar_salah', document.getElementById('e-bobot-benar-salah').value);

  const res  = await fetch('bank_soal.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.success) {
    closeEditModal();
    Swal.fire({ icon: 'success', title: 'Berhasil', text: data.message, timer: 1500, showConfirmButton: false });
    setTimeout(() => location.reload(), 1500);
  } else {
    Swal.fire('Error', data.message, 'error');
  }
}

// ── Delete single ──────────────────────────────────────────────────────────────
function hapusBankSoal(id, nama) {
  Swal.fire({
    title: 'Hapus Bank Soal?',
    html: `Soal "<b>${nama}</b>" dan semua pertanyaan di dalamnya akan dihapus permanen!`,
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc2626', confirmButtonText: 'Hapus', cancelButtonText: 'Batal'
  }).then(r => {
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf); fd.append('action', 'hapus'); fd.append('id', id);
    fetch('bank_soal.php', { method: 'POST', body: fd })
      .then(r => r.json()).then(data => {
        if (data.success) {
          Swal.fire({ icon: 'success', title: 'Dihapus!', timer: 1500, showConfirmButton: false });
          setTimeout(() => location.reload(), 1500);
        } else Swal.fire('Error', data.message, 'error');
      });
  });
}

// ── Bulk delete ────────────────────────────────────────────────────────────────
function hapusTerpilih() {
  const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(c => c.value);
  if (!ids.length) return;
  Swal.fire({
    title: `Hapus ${ids.length} bank soal?`,
    text: 'Semua soal di dalamnya akan ikut dihapus!', icon: 'warning',
    showCancelButton: true, confirmButtonColor: '#dc2626',
    confirmButtonText: 'Hapus Semua', cancelButtonText: 'Batal'
  }).then(r => {
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf); fd.append('action', 'hapus_multiple');
    fd.append('ids', JSON.stringify(ids));
    fetch('bank_soal.php', { method: 'POST', body: fd })
      .then(r => r.json()).then(data => {
        if (data.success) {
          Swal.fire({ icon: 'success', title: 'Dihapus!', timer: 1500, showConfirmButton: false });
          setTimeout(() => location.reload(), 1500);
        } else Swal.fire('Error', data.message, 'error');
      });
  });
}

// ── Close modal on backdrop click ─────────────────────────────────────────────
['tambah-modal', 'edit-modal'].forEach(id => {
  document.getElementById(id).addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
  });
});

filterTable();
</script>
</body>
</html>
