<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }

// ── GET AJAX ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // Return all soal for a bank
    if ($action === 'get_soal') {
        $bankId = (int)($_GET['bank_id'] ?? 0);
        $soalList = db()->prepare(
            "SELECT s.id, s.jenis_soal, s.pertanyaan, s.urutan FROM soal s
             WHERE s.bank_soal_id = ? ORDER BY s.urutan, s.id"
        );
        $soalList->execute([$bankId]);
        $rows = $soalList->fetchAll();

        foreach ($rows as &$row) {
            // Options
            $opsiStmt = db()->prepare("SELECT kode_opsi, isi_opsi FROM opsi_jawaban WHERE soal_id = ? ORDER BY kode_opsi");
            $opsiStmt->execute([$row['id']]);
            $row['opsi'] = $opsiStmt->fetchAll();

            // Key
            $kunciStmt = db()->prepare("SELECT jawaban FROM kunci_jawaban WHERE soal_id = ?");
            $kunciStmt->execute([$row['id']]);
            $kunci = $kunciStmt->fetch();
            $row['kunci'] = $kunci ? $kunci['jawaban'] : '';
        }
        echo json_encode($rows);
        exit;
    }

    // Return single soal detail
    if ($action === 'get_soal_detail') {
        $soalId = (int)($_GET['soal_id'] ?? 0);
        $stmt = db()->prepare("SELECT * FROM soal WHERE id = ?");
        $stmt->execute([$soalId]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(null); exit; }

        $opsiStmt = db()->prepare("SELECT kode_opsi, isi_opsi FROM opsi_jawaban WHERE soal_id = ? ORDER BY kode_opsi");
        $opsiStmt->execute([$soalId]);
        $row['opsi'] = $opsiStmt->fetchAll();

        $kunciStmt = db()->prepare("SELECT jawaban FROM kunci_jawaban WHERE soal_id = ?");
        $kunciStmt->execute([$soalId]);
        $k = $kunciStmt->fetch();
        $row['kunci'] = $k ? $k['jawaban'] : '';

        echo json_encode($row);
        exit;
    }

    echo json_encode(null); exit;
}

// ── POST AJAX ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF tidak valid']); exit;
    }

    $action = $_POST['action'] ?? '';

    // ── simpan_soal ───────────────────────────────────────────────────────
    if ($action === 'simpan_soal') {
        $bankId    = (int)($_POST['bank_id'] ?? 0);
        $soalId    = (int)($_POST['soal_id'] ?? 0);
        $jenis     = $_POST['jenis_soal']   ?? 'pg';
        $pertanyaan = trim($_POST['pertanyaan'] ?? '');
        $urutan    = (int)($_POST['urutan'] ?? 1);

        if (!$bankId || empty($pertanyaan)) {
            echo json_encode(['success' => false, 'message' => 'Data soal tidak lengkap']); exit;
        }

        $validJenis = ['pg', 'essai', 'menjodohkan', 'benar_salah'];
        if (!in_array($jenis, $validJenis, true)) {
            echo json_encode(['success' => false, 'message' => 'Jenis soal tidak valid']); exit;
        }

        db()->beginTransaction();
        try {
            if ($soalId) {
                // Update
                db()->prepare("UPDATE soal SET jenis_soal=?, pertanyaan=?, urutan=? WHERE id=?")
                   ->execute([$jenis, $pertanyaan, $urutan, $soalId]);
                db()->prepare("DELETE FROM opsi_jawaban WHERE soal_id=?")->execute([$soalId]);
                db()->prepare("DELETE FROM kunci_jawaban WHERE soal_id=?")->execute([$soalId]);
            } else {
                // Insert
                $stmtMaxUrutan = db()->prepare("SELECT COALESCE(MAX(urutan),0)+1 AS next_urutan FROM soal WHERE bank_soal_id=?");
                $stmtMaxUrutan->execute([$bankId]);
                $nextUrutan = (int)$stmtMaxUrutan->fetchColumn();
                $urutan = $nextUrutan;

                $ins = db()->prepare("INSERT INTO soal (bank_soal_id, jenis_soal, pertanyaan, urutan) VALUES (?,?,?,?)");
                $ins->execute([$bankId, $jenis, $pertanyaan, $urutan]);
                $soalId = (int)db()->lastInsertId();
            }

            // Insert options & keys by type
            if ($jenis === 'pg') {
                $opts   = json_decode($_POST['opsi'] ?? '{}', true) ?: [];
                $kunci  = strtoupper(trim($_POST['kunci'] ?? 'A'));
                $opsiStmt = db()->prepare("INSERT INTO opsi_jawaban (soal_id, kode_opsi, isi_opsi) VALUES (?,?,?)");
                foreach ($opts as $kode => $isi) {
                    $opsiStmt->execute([$soalId, strtoupper($kode), $isi]);
                }
                db()->prepare("INSERT INTO kunci_jawaban (soal_id, jawaban) VALUES (?,?)")->execute([$soalId, $kunci]);

            } elseif ($jenis === 'essai') {
                $kunci = trim($_POST['kunci'] ?? '');
                if ($kunci) {
                    db()->prepare("INSERT INTO kunci_jawaban (soal_id, jawaban) VALUES (?,?)")->execute([$soalId, $kunci]);
                }

            } elseif ($jenis === 'menjodohkan') {
                $pasangan = json_decode($_POST['pasangan'] ?? '[]', true) ?: [];
                $opsiStmt = db()->prepare("INSERT INTO opsi_jawaban (soal_id, kode_opsi, isi_opsi) VALUES (?,?,?)");
                foreach ($pasangan as $idx => $pair) {
                    $opsiStmt->execute([$soalId, 'kiri_' . ($idx + 1), $pair['kiri'] ?? '']);
                    $opsiStmt->execute([$soalId, 'kanan_' . ($idx + 1), $pair['kanan'] ?? '']);
                }
                // Key = JSON encoding of pairs
                db()->prepare("INSERT INTO kunci_jawaban (soal_id, jawaban) VALUES (?,?)")
                    ->execute([$soalId, json_encode($pasangan)]);

            } elseif ($jenis === 'benar_salah') {
                $kunci = trim($_POST['kunci'] ?? 'Benar');
                db()->prepare("INSERT INTO kunci_jawaban (soal_id, jawaban) VALUES (?,?)")->execute([$soalId, $kunci]);
            }

            db()->commit();
            echo json_encode(['success' => true, 'message' => 'Soal berhasil disimpan', 'soal_id' => $soalId, 'urutan' => $urutan]);

        } catch (Exception $e) {
            db()->rollBack();
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── hapus_soal ────────────────────────────────────────────────────────
    if ($action === 'hapus_soal') {
        $soalId = (int)($_POST['soal_id'] ?? 0);
        if (!$soalId) { echo json_encode(['success' => false, 'message' => 'ID soal tidak valid']); exit; }
        db()->prepare("DELETE FROM soal WHERE id=?")->execute([$soalId]);
        echo json_encode(['success' => true, 'message' => 'Soal berhasil dihapus']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali']); exit;
}

// ── Page Data ─────────────────────────────────────────────────────────────────
$bankId = (int)($_GET['bank_id'] ?? 0);
if (!$bankId) { header('Location: bank_soal.php'); exit; }

$bankSoal = db()->prepare(
    "SELECT bs.*, COALESCE(g.nama, a.nama) AS pembuat_nama, m.nama_mapel
     FROM bank_soal bs
     LEFT JOIN guru  g ON g.id = bs.guru_id
     LEFT JOIN admin a ON a.id = bs.admin_id
     LEFT JOIN mapel m ON m.id = bs.mapel_id
     WHERE bs.id = ?"
);
$bankSoal->execute([$bankId]);
$bank = $bankSoal->fetch();
if (!$bank) { header('Location: bank_soal.php'); exit; }

$cntStmt = db()->prepare("SELECT COUNT(*) FROM soal WHERE bank_soal_id=?");
$cntStmt->execute([$bankId]);
$totalSoal = (int)$cntStmt->fetchColumn();

$appName   = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Buat Soal – <?= htmlspecialchars($bank['nama_soal']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Quill -->
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<style>
  .ql-container { font-size: 14px; }
  .ql-editor { min-height: 80px; }
  .ql-editor.ql-blank::before { font-style: normal; color: #9ca3af; }
  .tab-btn.active  { border-bottom: 2px solid #2563eb; color: #2563eb; font-weight: 600; }
  .tab-content     { display: none; }
  .tab-content.active { display: block; }
  .soal-btn        { transition: all .15s; }
  .soal-btn.empty  { background:#e5e7eb; color:#374151; }
  .soal-btn.saved  { background:#22c55e; color:#fff; }
  .soal-btn.active { outline: 2px solid #2563eb; outline-offset:2px; }
</style>
</head>
<body class="bg-gray-100">
<?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="ml-64 pt-16 pb-10 px-6">
  <div class="mt-6">

    <!-- Page Header -->
    <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
      <div>
        <h1 class="text-xl font-bold text-gray-800">
          <i class="fas fa-book-open text-blue-600 mr-2"></i>
          Edit Soal: <span class="text-blue-700"><?= htmlspecialchars($bank['nama_soal']) ?></span>
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">
          <?= htmlspecialchars($bank['nama_mapel'] ?? 'Tanpa Mapel') ?> &bull;
          <span id="total-soal-label" class="font-medium text-gray-700">Total Soal: <?= $totalSoal ?></span>
        </p>
      </div>
      <div class="flex gap-3">
        <a href="bank_soal.php"
          class="flex items-center gap-2 px-4 py-2 border rounded-lg text-sm hover:bg-gray-100 transition text-gray-700">
          <i class="fas fa-arrow-left"></i> Kembali
        </a>
        <button onclick="simpanKeBank()"
          class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
          <i class="fas fa-floppy-disk"></i> Simpan Ke Bank
        </button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="bg-white rounded-xl shadow mb-5">
      <div class="flex border-b">
        <button class="tab-btn active px-6 py-3 text-sm text-gray-600 hover:text-blue-600 transition" onclick="switchTab('manual', this)">
          <i class="fas fa-pen mr-2"></i>Buat Soal Manual
        </button>
        <button class="tab-btn px-6 py-3 text-sm text-gray-600 hover:text-blue-600 transition" onclick="switchTab('word', this)">
          <i class="fas fa-file-word mr-2 text-blue-500"></i>Import Word
        </button>
        <button class="tab-btn px-6 py-3 text-sm text-gray-600 hover:text-blue-600 transition" onclick="switchTab('excel', this)">
          <i class="fas fa-file-excel mr-2 text-green-600"></i>Import Excel
        </button>
      </div>
    </div>

    <!-- ── Tab: Buat Soal Manual ── -->
    <div id="tab-manual" class="tab-content active">
      <div class="flex gap-5 items-start">

        <!-- Left Panel: Question Grid -->
        <div class="w-72 flex-shrink-0">
          <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center justify-between mb-3">
              <h3 class="font-semibold text-gray-700 text-sm">Daftar Soal</h3>
              <span id="soal-count-badge" class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-medium">
                0 soal
              </span>
            </div>
            <!-- Grid buttons -->
            <div id="soal-grid" class="grid grid-cols-5 gap-2 mb-4 min-h-[40px]">
              <!-- JS renders buttons here -->
            </div>
            <!-- Tambah soal button -->
            <button onclick="tambahSoalBaru()"
              class="w-full border-2 border-dashed border-blue-400 text-blue-600 hover:bg-blue-50 rounded-lg py-2 text-sm font-medium transition flex items-center justify-center gap-2">
              <i class="fas fa-plus"></i> Tambah Soal
            </button>
            <!-- Legend -->
            <div class="mt-4 space-y-1.5 text-xs text-gray-500">
              <div class="flex items-center gap-2">
                <span class="w-5 h-5 rounded bg-gray-200 inline-block"></span> Belum diisi
              </div>
              <div class="flex items-center gap-2">
                <span class="w-5 h-5 rounded bg-green-500 inline-block"></span> Sudah disimpan
              </div>
              <div class="flex items-center gap-2">
                <span class="w-5 h-5 rounded bg-gray-200 ring-2 ring-blue-500 ring-offset-1 inline-block"></span> Aktif
              </div>
            </div>
          </div>
        </div>

        <!-- Right Panel: Question Form -->
        <div class="flex-1 min-w-0">
          <!-- Empty state -->
          <div id="soal-empty-state" class="bg-white rounded-xl shadow p-12 text-center">
            <i class="fas fa-file-pen text-5xl text-gray-300 mb-4"></i>
            <p class="text-gray-500 font-medium">Pilih soal dari daftar atau tambah soal baru</p>
            <button onclick="tambahSoalBaru()"
              class="mt-4 bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg text-sm font-medium transition">
              <i class="fas fa-plus mr-2"></i>Tambah Soal Pertama
            </button>
          </div>

          <!-- Form panel (hidden until soal selected) -->
          <div id="soal-form-panel" class="hidden">
            <div class="bg-white rounded-xl shadow p-6">
              <!-- Form top row -->
              <div class="flex items-center justify-between mb-5">
                <div class="flex items-center gap-3">
                  <span class="text-sm font-medium text-gray-600">Soal</span>
                  <span id="form-soal-nomor" class="font-bold text-blue-600 text-lg"></span>
                  <select id="jenis-soal" onchange="onJenisSoalChange()"
                    class="border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="pg">Pilihan Ganda</option>
                    <option value="essai">Essai</option>
                    <option value="menjodohkan">Menjodohkan</option>
                    <option value="benar_salah">Benar / Salah</option>
                  </select>
                </div>
                <button onclick="hapusSoalAktif()"
                  class="flex items-center gap-2 bg-red-50 hover:bg-red-100 text-red-600 px-3 py-1.5 rounded-lg text-sm transition">
                  <i class="fas fa-trash"></i> Hapus Soal
                </button>
              </div>

              <!-- Pertanyaan -->
              <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                  Pertanyaan <span class="text-red-500">*</span>
                </label>
                <div id="quill-pertanyaan" class="border rounded-lg overflow-hidden"></div>
              </div>

              <!-- ── PG Options ── -->
              <div id="section-pg" class="space-y-3 mb-5">
                <?php foreach (['A','B','C','D','E'] as $opt): ?>
                <div class="flex items-start gap-3">
                  <span class="mt-2 w-7 h-7 flex items-center justify-center rounded-full bg-blue-100 text-blue-700 font-bold text-sm flex-shrink-0">
                    <?= $opt ?>
                  </span>
                  <div class="flex-1 border rounded-lg overflow-hidden">
                    <div id="quill-opsi-<?= strtolower($opt) ?>"></div>
                  </div>
                </div>
                <?php endforeach; ?>
                <div class="mt-4">
                  <label class="block text-sm font-medium text-gray-700 mb-1">Kunci Jawaban</label>
                  <select id="kunci-pg" class="border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                    <option value="E">E</option>
                  </select>
                </div>
              </div>

              <!-- ── Essai ── -->
              <div id="section-essai" class="hidden mb-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">Kunci Jawaban (opsional)</label>
                <textarea id="kunci-essai" rows="4" placeholder="Tulis kunci jawaban / rubrik penilaian…"
                  class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"></textarea>
              </div>

              <!-- ── Menjodohkan ── -->
              <div id="section-menjodohkan" class="hidden mb-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">Pasangan Soal</label>
                <div class="overflow-x-auto rounded-lg border">
                  <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                      <tr>
                        <th class="px-4 py-2 text-left text-gray-600 font-medium w-8">No</th>
                        <th class="px-4 py-2 text-left text-gray-600 font-medium">Kolom Kiri</th>
                        <th class="px-4 py-2 text-left text-gray-600 font-medium">Kolom Kanan</th>
                        <th class="px-4 py-2 w-10"></th>
                      </tr>
                    </thead>
                    <tbody id="menjodohkan-tbody">
                      <!-- JS renders rows -->
                    </tbody>
                  </table>
                </div>
                <button onclick="tambahPasangan()"
                  class="mt-3 text-blue-600 hover:text-blue-800 text-sm flex items-center gap-1">
                  <i class="fas fa-plus-circle"></i> Tambah Pasangan
                </button>
              </div>

              <!-- ── Benar / Salah ── -->
              <div id="section-benar-salah" class="hidden mb-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">Kunci Jawaban</label>
                <select id="kunci-benar-salah" class="border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                  <option value="Benar">Benar</option>
                  <option value="Salah">Salah</option>
                </select>
              </div>

              <!-- Save Button -->
              <div class="flex justify-end border-t pt-4 mt-2">
                <button onclick="simpanSoal()"
                  class="bg-green-600 hover:bg-green-700 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition flex items-center gap-2">
                  <i class="fas fa-floppy-disk"></i> Simpan Soal
                </button>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /flex -->
    </div><!-- /tab-manual -->

    <!-- ── Tab: Import Word ── -->
    <div id="tab-word" class="tab-content">
      <div class="bg-white rounded-xl shadow p-8 max-w-lg">
        <div class="text-center mb-6">
          <i class="fas fa-file-word text-5xl text-blue-500 mb-3"></i>
          <h3 class="text-lg font-semibold text-gray-800">Import Format Word (.DOCX)</h3>
          <p class="text-sm text-gray-500 mt-1">
            Pastikan file mengikuti format template yang disediakan
          </p>
        </div>
        <a href="#" onclick="Swal.fire('Info','Fitur unduh template Word akan segera tersedia.','info'); return false;"
          class="flex items-center justify-center gap-2 w-full border-2 border-blue-500 text-blue-600 hover:bg-blue-50 px-4 py-2.5 rounded-lg text-sm font-medium transition mb-4">
          <i class="fas fa-download"></i> Unduh Format Template Word
        </a>
        <div class="border-2 border-dashed border-gray-300 rounded-xl p-6 text-center hover:border-blue-400 transition">
          <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2"></i>
          <p class="text-sm text-gray-500 mb-3">Pilih file .docx atau drag & drop di sini</p>
          <input type="file" id="import-word-file" accept=".docx"
            class="block mx-auto text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-100 file:text-blue-700 hover:file:bg-blue-200">
        </div>
        <button onclick="Swal.fire('Info','Fitur import Word akan segera tersedia.','info')"
          class="mt-4 w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-lg text-sm font-medium transition">
          <i class="fas fa-upload mr-2"></i>Import Sekarang
        </button>
      </div>
    </div>

    <!-- ── Tab: Import Excel ── -->
    <div id="tab-excel" class="tab-content">
      <div class="bg-white rounded-xl shadow p-8 max-w-lg">
        <div class="text-center mb-6">
          <i class="fas fa-file-excel text-5xl text-green-600 mb-3"></i>
          <h3 class="text-lg font-semibold text-gray-800">Import Format Excel</h3>
          <p class="text-sm text-gray-500 mt-1">
            Pastikan file mengikuti format template yang disediakan
          </p>
        </div>
        <a href="#" onclick="Swal.fire('Info','Fitur unduh template Excel akan segera tersedia.','info'); return false;"
          class="flex items-center justify-center gap-2 w-full border-2 border-green-500 text-green-600 hover:bg-green-50 px-4 py-2.5 rounded-lg text-sm font-medium transition mb-4">
          <i class="fas fa-download"></i> Unduh Format Template (.XLS)
        </a>
        <div class="border-2 border-dashed border-gray-300 rounded-xl p-6 text-center hover:border-green-400 transition">
          <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2"></i>
          <p class="text-sm text-gray-500 mb-3">Pilih file .xls / .xlsx atau drag & drop di sini</p>
          <input type="file" id="import-excel-file" accept=".xls,.xlsx"
            class="block mx-auto text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-green-100 file:text-green-700 hover:file:bg-green-200">
        </div>
        <button onclick="Swal.fire('Info','Fitur import Excel akan segera tersedia.','info')"
          class="mt-4 w-full bg-green-600 hover:bg-green-700 text-white py-2.5 rounded-lg text-sm font-medium transition">
          <i class="fas fa-upload mr-2"></i>Import Sekarang
        </button>
      </div>
    </div>

  </div><!-- /mt-6 -->
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
const BANK_ID    = <?= $bankId ?>;
const CSRF_TOKEN = '<?= $csrfToken ?>';

// ── State ──────────────────────────────────────────────────────────────────────
let soalList    = [];   // [{id, jenis_soal, pertanyaan, urutan, opsi, kunci}, ...]
let activeIndex = -1;   // index in soalList (-1 = none, soalList.length = new unsaved)
let isNewSoal   = false; // true when editing unsaved new soal
let isDirty     = false;

// Quill instances keyed by name
let quills = {};

// ── Tab Switching ──────────────────────────────────────────────────────────────
function switchTab(name, btn) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('tab-' + name).classList.add('active');
}

// ── Load all soal on page start ────────────────────────────────────────────────
async function loadAllSoal() {
  const res  = await fetch(`buat_soal.php?action=get_soal&bank_id=${BANK_ID}`);
  soalList   = await res.json();
  renderGrid();
}

// ── Render grid buttons ────────────────────────────────────────────────────────
function renderGrid() {
  const grid = document.getElementById('soal-grid');
  grid.innerHTML = '';
  soalList.forEach((s, i) => {
    const btn = document.createElement('button');
    btn.textContent = s.urutan;
    btn.className   = `soal-btn w-9 h-9 rounded-lg text-sm font-medium saved${i === activeIndex ? ' active' : ''}`;
    btn.onclick = () => selectSoal(i);
    grid.appendChild(btn);
  });
  document.getElementById('soal-count-badge').textContent = soalList.length + ' soal';
  document.getElementById('total-soal-label').textContent = 'Total Soal: ' + soalList.length;
}

// ── Select soal by index ───────────────────────────────────────────────────────
async function selectSoal(index) {
  if (isDirty) {
    const conf = await Swal.fire({
      title: 'Ada perubahan belum disimpan',
      text: 'Simpan soal saat ini sebelum pindah?',
      icon: 'question', showCancelButton: true,
      confirmButtonText: 'Simpan dulu', cancelButtonText: 'Buang perubahan',
      showDenyButton: false
    });
    if (conf.isConfirmed) { await simpanSoal(); return; }
  }
  activeIndex = index;
  isNewSoal   = false;
  isDirty     = false;
  renderGrid();
  loadSoalForm(soalList[index]);
}

// ── Tambah soal baru ───────────────────────────────────────────────────────────
async function tambahSoalBaru() {
  if (isDirty) {
    const conf = await Swal.fire({
      title: 'Ada perubahan belum disimpan',
      text: 'Buang perubahan soal saat ini?',
      icon: 'question', showCancelButton: true,
      confirmButtonText: 'Ya, tambah baru', cancelButtonText: 'Batal'
    });
    if (!conf.isConfirmed) return;
  }
  isNewSoal   = true;
  activeIndex = -1;
  isDirty     = false;
  renderGrid();
  loadSoalForm(null);
}

// ── Load soal form ─────────────────────────────────────────────────────────────
function loadSoalForm(soal) {
  document.getElementById('soal-empty-state').classList.add('hidden');
  document.getElementById('soal-form-panel').classList.remove('hidden');

  const nomor = soal ? soal.urutan : soalList.length + 1;
  document.getElementById('form-soal-nomor').textContent = '#' + nomor;

  const jenis = soal ? soal.jenis_soal : 'pg';
  document.getElementById('jenis-soal').value = jenis;

  // Destroy existing quill instances
  destroyQuills();

  // Create pertanyaan Quill
  createQuill('pertanyaan', '#quill-pertanyaan', 'Tulis pertanyaan di sini…');
  if (soal && soal.pertanyaan) {
    quills['pertanyaan'].root.innerHTML = soal.pertanyaan;
  }

  // Show section + create option quills
  onJenisSoalChange(soal);
}

// ── Create / destroy Quill ─────────────────────────────────────────────────────
function createQuill(key, selector, placeholder) {
  const el = document.querySelector(selector);
  if (!el) return null;
  el.innerHTML = '';
  const q = new Quill(el, {
    theme: 'snow',
    placeholder: placeholder || '',
    modules: { toolbar: [['bold','italic','underline'], ['image'], [{ list: 'ordered' }, { list: 'bullet' }]] }
  });
  quills[key] = q;
  q.on('text-change', () => isDirty = true);
  return q;
}

function destroyQuills() {
  Object.keys(quills).forEach(k => {
    const q = quills[k];
    if (q && q.container) {
      q.container.innerHTML = '';
    }
  });
  quills = {};
}

// ── Jenis soal switch ──────────────────────────────────────────────────────────
function onJenisSoalChange(soal) {
  const jenis   = document.getElementById('jenis-soal').value;
  const sections = ['pg', 'essai', 'menjodohkan', 'benar_salah'];
  sections.forEach(s => document.getElementById(`section-${s}`).classList.add('hidden'));
  document.getElementById(`section-${jenis}`).classList.remove('hidden');

  // Destroy old option quills except pertanyaan
  Object.keys(quills).filter(k => k !== 'pertanyaan').forEach(k => {
    if (quills[k]?.container) quills[k].container.innerHTML = '';
    delete quills[k];
  });

  if (jenis === 'pg') {
    ['a','b','c','d','e'].forEach(opt => {
      createQuill('opsi_' + opt, `#quill-opsi-${opt}`, `Opsi ${opt.toUpperCase()}…`);
    });
    // Populate existing data
    if (soal && soal.opsi) {
      soal.opsi.forEach(o => {
        const key = 'opsi_' + o.kode_opsi.toLowerCase();
        if (quills[key]) quills[key].root.innerHTML = o.isi_opsi || '';
      });
    }
    if (soal && soal.kunci) document.getElementById('kunci-pg').value = soal.kunci;
    else document.getElementById('kunci-pg').value = 'A';

  } else if (jenis === 'essai') {
    document.getElementById('kunci-essai').value = soal ? soal.kunci : '';

  } else if (jenis === 'menjodohkan') {
    const pairs = [];
    if (soal && soal.opsi) {
      const leftItems  = soal.opsi.filter(o => o.kode_opsi.startsWith('kiri_'));
      const rightItems = soal.opsi.filter(o => o.kode_opsi.startsWith('kanan_'));
      const count = Math.max(leftItems.length, rightItems.length);
      for (let i = 0; i < count; i++) {
        pairs.push({ kiri: (leftItems[i] || {}).isi_opsi || '', kanan: (rightItems[i] || {}).isi_opsi || '' });
      }
    }
    if (pairs.length === 0) pairs.push({ kiri: '', kanan: '' });
    renderMenjodohkan(pairs);

  } else if (jenis === 'benar_salah') {
    document.getElementById('kunci-benar-salah').value = soal ? soal.kunci : 'Benar';
  }

  isDirty = false;
}

// ── Menjodohkan helpers ────────────────────────────────────────────────────────
function renderMenjodohkan(pairs) {
  const tbody = document.getElementById('menjodohkan-tbody');
  tbody.innerHTML = '';
  pairs.forEach((p, i) => {
    const tr = document.createElement('tr');
    tr.className = 'border-t';
    tr.innerHTML = `
      <td class="px-4 py-2 text-gray-500">${i + 1}</td>
      <td class="px-4 py-2">
        <input type="text" value="${escHtml(p.kiri)}" placeholder="Kolom kiri…"
          class="menjodohkan-kiri w-full border rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
          oninput="isDirty=true">
      </td>
      <td class="px-4 py-2">
        <input type="text" value="${escHtml(p.kanan)}" placeholder="Kolom kanan…"
          class="menjodohkan-kanan w-full border rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
          oninput="isDirty=true">
      </td>
      <td class="px-4 py-2">
        <button onclick="hapusPasangan(this)" class="text-red-500 hover:text-red-700">
          <i class="fas fa-times"></i>
        </button>
      </td>`;
    tbody.appendChild(tr);
  });
}

function tambahPasangan() {
  const tbody = document.getElementById('menjodohkan-tbody');
  const idx   = tbody.rows.length + 1;
  const tr    = document.createElement('tr');
  tr.className = 'border-t';
  tr.innerHTML = `
    <td class="px-4 py-2 text-gray-500">${idx}</td>
    <td class="px-4 py-2">
      <input type="text" placeholder="Kolom kiri…"
        class="menjodohkan-kiri w-full border rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
        oninput="isDirty=true">
    </td>
    <td class="px-4 py-2">
      <input type="text" placeholder="Kolom kanan…"
        class="menjodohkan-kanan w-full border rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
        oninput="isDirty=true">
    </td>
    <td class="px-4 py-2">
      <button onclick="hapusPasangan(this)" class="text-red-500 hover:text-red-700">
        <i class="fas fa-times"></i>
      </button>
    </td>`;
  tbody.appendChild(tr);
  isDirty = true;
}

function hapusPasangan(btn) {
  const rows = document.querySelectorAll('#menjodohkan-tbody tr');
  if (rows.length <= 1) { Swal.fire('Info','Minimal harus ada 1 pasangan','info'); return; }
  btn.closest('tr').remove();
  // Renumber
  document.querySelectorAll('#menjodohkan-tbody tr').forEach((r, i) => r.cells[0].textContent = i + 1);
  isDirty = true;
}

// ── Simpan soal ────────────────────────────────────────────────────────────────
async function simpanSoal() {
  const jenis      = document.getElementById('jenis-soal').value;
  const pertanyaan = quills['pertanyaan'] ? quills['pertanyaan'].root.innerHTML : '';

  if (!pertanyaan || pertanyaan === '<p><br></p>') {
    Swal.fire('Error', 'Pertanyaan tidak boleh kosong', 'error'); return;
  }

  const fd = new FormData();
  fd.append('csrf_token', CSRF_TOKEN);
  fd.append('action', 'simpan_soal');
  fd.append('bank_id', BANK_ID);
  fd.append('jenis_soal', jenis);
  fd.append('pertanyaan', pertanyaan);

  // soal_id (for update)
  const existingSoal = (!isNewSoal && activeIndex >= 0) ? soalList[activeIndex] : null;
  if (existingSoal) fd.append('soal_id', existingSoal.id);

  if (jenis === 'pg') {
    const opsi = {};
    ['a','b','c','d','e'].forEach(opt => {
      opsi[opt.toUpperCase()] = quills['opsi_' + opt] ? quills['opsi_' + opt].root.innerHTML : '';
    });
    fd.append('opsi', JSON.stringify(opsi));
    fd.append('kunci', document.getElementById('kunci-pg').value);

  } else if (jenis === 'essai') {
    fd.append('kunci', document.getElementById('kunci-essai').value);

  } else if (jenis === 'menjodohkan') {
    const kiriEls  = document.querySelectorAll('.menjodohkan-kiri');
    const kananEls = document.querySelectorAll('.menjodohkan-kanan');
    const pasangan = Array.from(kiriEls).map((el, i) => ({
      kiri:  el.value.trim(),
      kanan: kananEls[i] ? kananEls[i].value.trim() : ''
    }));
    if (pasangan.some(p => !p.kiri && !p.kanan)) {
      Swal.fire('Error', 'Semua pasangan harus diisi', 'error'); return;
    }
    fd.append('pasangan', JSON.stringify(pasangan));

  } else if (jenis === 'benar_salah') {
    fd.append('kunci', document.getElementById('kunci-benar-salah').value);
  }

  const res  = await fetch('buat_soal.php', { method: 'POST', body: fd });
  const data = await res.json();

  if (data.success) {
    isDirty   = false;
    isNewSoal = false;
    // Reload list to update state
    await loadAllSoal();
    // Find saved soal by id and set active
    const newIndex = soalList.findIndex(s => s.id == data.soal_id);
    if (newIndex >= 0) activeIndex = newIndex;
    renderGrid();
    Swal.fire({ icon: 'success', title: 'Tersimpan!', text: data.message, timer: 1200, showConfirmButton: false });
  } else {
    Swal.fire('Error', data.message, 'error');
  }
}

// ── Hapus soal aktif ───────────────────────────────────────────────────────────
async function hapusSoalAktif() {
  if (isNewSoal) {
    // Just discard
    activeIndex = -1;
    isNewSoal   = false;
    isDirty     = false;
    document.getElementById('soal-form-panel').classList.add('hidden');
    document.getElementById('soal-empty-state').classList.remove('hidden');
    renderGrid();
    return;
  }
  if (activeIndex < 0 || !soalList[activeIndex]) return;

  const conf = await Swal.fire({
    title: 'Hapus Soal?', text: 'Soal ini akan dihapus permanen!', icon: 'warning',
    showCancelButton: true, confirmButtonColor: '#dc2626',
    confirmButtonText: 'Hapus', cancelButtonText: 'Batal'
  });
  if (!conf.isConfirmed) return;

  const fd = new FormData();
  fd.append('csrf_token', CSRF_TOKEN);
  fd.append('action', 'hapus_soal');
  fd.append('soal_id', soalList[activeIndex].id);

  const res  = await fetch('buat_soal.php', { method: 'POST', body: fd });
  const data = await res.json();

  if (data.success) {
    activeIndex = -1;
    isDirty     = false;
    document.getElementById('soal-form-panel').classList.add('hidden');
    document.getElementById('soal-empty-state').classList.remove('hidden');
    await loadAllSoal();
    Swal.fire({ icon: 'success', title: 'Dihapus!', timer: 1200, showConfirmButton: false });
  } else {
    Swal.fire('Error', data.message, 'error');
  }
}

// ── Simpan Ke Bank ─────────────────────────────────────────────────────────────
async function simpanKeBank() {
  if (isDirty) {
    const conf = await Swal.fire({
      title: 'Ada perubahan belum disimpan',
      text: 'Simpan soal aktif sebelum kembali ke bank soal?',
      icon: 'question', showCancelButton: true,
      confirmButtonText: 'Simpan & Kembali', cancelButtonText: 'Kembali tanpa simpan'
    });
    if (conf.isConfirmed) await simpanSoal();
  }
  window.location.href = 'bank_soal.php';
}

// ── Utilities ──────────────────────────────────────────────────────────────────
function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Init ───────────────────────────────────────────────────────────────────────
loadAllSoal();
</script>
</body>
</html>
