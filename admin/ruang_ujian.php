<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }

// ── GET: CSV Exports & AJAX ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];

    // ── export_nilai ──────────────────────────────────────────────────────────
    if ($action === 'export_nilai') {
        $ruangId = (int)($_GET['ruang_id'] ?? 0);
        if (!$ruangId) { http_response_code(400); exit; }

        $ruangStmt = db()->prepare("SELECT nama_ruang FROM ruang_ujian WHERE id = ?");
        $ruangStmt->execute([$ruangId]);
        $ruang = $ruangStmt->fetch();
        if (!$ruang) { http_response_code(404); exit; }

        $stmt = db()->prepare("
            SELECT s.nama, s.nisn, k.nama_kelas,
                   COALESCE(us.jumlah_benar, 0)  AS jumlah_benar,
                   COALESCE(us.jumlah_salah, 0)  AS jumlah_salah,
                   COALESCE(us.nilai, 0)          AS nilai,
                   COALESCE(us.status, 'belum')   AS status
            FROM siswa s
            INNER JOIN kelas k ON k.id = s.kelas_id
            INNER JOIN ruang_ujian_kelas ruk ON ruk.kelas_id = s.kelas_id
            LEFT  JOIN ujian_siswa us ON us.siswa_id = s.id AND us.ruang_ujian_id = ?
            WHERE ruk.ruang_ujian_id = ?
            ORDER BY k.nama_kelas, s.nama
        ");
        $stmt->execute([$ruangId, $ruangId]);
        $rows = $stmt->fetchAll();

        $filename = 'nilai_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $ruang['nama_ruang']) . '_' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel
        fputcsv($out, ['No', 'Nama Siswa', 'NISN', 'Kelas', 'Jumlah Benar', 'Jumlah Salah', 'Nilai', 'Status']);
        $no = 1;
        foreach ($rows as $row) {
            fputcsv($out, [$no++, $row['nama'], $row['nisn'], $row['nama_kelas'],
                           $row['jumlah_benar'], $row['jumlah_salah'], $row['nilai'], $row['status']]);
        }
        fclose($out);
        exit;
    }

    // ── export_analisis ───────────────────────────────────────────────────────
    if ($action === 'export_analisis') {
        $ruangId = (int)($_GET['ruang_id'] ?? 0);
        if (!$ruangId) { http_response_code(400); exit; }

        $ruangStmt = db()->prepare("SELECT nama_ruang, bank_soal_id FROM ruang_ujian WHERE id = ?");
        $ruangStmt->execute([$ruangId]);
        $ruang = $ruangStmt->fetch();
        if (!$ruang) { http_response_code(404); exit; }

        $soalStmt = db()->prepare("SELECT id, urutan FROM soal WHERE bank_soal_id = ? ORDER BY urutan, id");
        $soalStmt->execute([$ruang['bank_soal_id']]);
        $soalList = $soalStmt->fetchAll();

        $siswaStmt = db()->prepare("
            SELECT s.id AS siswa_id, s.nama, s.nisn, k.nama_kelas
            FROM siswa s
            INNER JOIN kelas k ON k.id = s.kelas_id
            INNER JOIN ruang_ujian_kelas ruk ON ruk.kelas_id = s.kelas_id
            WHERE ruk.ruang_ujian_id = ?
            ORDER BY k.nama_kelas, s.nama
        ");
        $siswaStmt->execute([$ruangId]);
        $siswaList = $siswaStmt->fetchAll();

        // Build jawaban index [siswa_id][soal_id]
        $jawabanStmt = db()->prepare("
            SELECT us.siswa_id, js.soal_id, js.jawaban, js.is_benar
            FROM jawaban_siswa js
            INNER JOIN ujian_siswa us ON us.id = js.ujian_siswa_id
            WHERE us.ruang_ujian_id = ?
        ");
        $jawabanStmt->execute([$ruangId]);
        $jawabanIndex = [];
        foreach ($jawabanStmt->fetchAll() as $j) {
            $jawabanIndex[$j['siswa_id']][$j['soal_id']] = $j;
        }

        // Build nilai index [siswa_id] => nilai
        $nilaiStmt = db()->prepare("SELECT siswa_id, nilai FROM ujian_siswa WHERE ruang_ujian_id = ?");
        $nilaiStmt->execute([$ruangId]);
        $nilaiIndex = [];
        foreach ($nilaiStmt->fetchAll() as $n) {
            $nilaiIndex[$n['siswa_id']] = $n['nilai'];
        }

        $filename = 'analisis_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $ruang['nama_ruang']) . '_' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        $header = ['No', 'Nama Siswa', 'NISN', 'Kelas'];
        foreach ($soalList as $idx => $soal) {
            $header[] = 'No.' . ($idx + 1);
        }
        $header[] = 'Jumlah Benar';
        $header[] = 'Jumlah Salah';
        $header[] = 'Nilai';
        fputcsv($out, $header);

        $no = 1;
        foreach ($siswaList as $siswa) {
            $row   = [$no++, $siswa['nama'], $siswa['nisn'], $siswa['nama_kelas']];
            $benar = 0;
            $salah = 0;
            foreach ($soalList as $soal) {
                $j = $jawabanIndex[$siswa['siswa_id']][$soal['id']] ?? null;
                if ($j === null || $j['jawaban'] === null) {
                    $row[] = '-';
                } elseif ($j['is_benar']) {
                    $row[] = 'B';
                    $benar++;
                } else {
                    $row[] = 'S';
                    $salah++;
                }
            }
            $row[] = $benar;
            $row[] = $salah;
            $row[] = $nilaiIndex[$siswa['siswa_id']] ?? 0;
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    // ── AJAX: get_bank_soal ────────────────────────────────────────────────────
    if ($action === 'get_bank_soal') {
        header('Content-Type: application/json');
        $pembuat = $_GET['pembuat'] ?? 'admin';
        $guruId  = (int)($_GET['guru_id'] ?? 0);

        if ($pembuat === 'guru' && $guruId) {
            $stmt = db()->prepare("
                SELECT bs.id, bs.nama_soal, m.nama_mapel
                FROM bank_soal bs
                LEFT JOIN mapel m ON m.id = bs.mapel_id
                WHERE bs.guru_id = ?
                ORDER BY bs.nama_soal
            ");
            $stmt->execute([$guruId]);
        } else {
            $stmt = db()->prepare("
                SELECT bs.id, bs.nama_soal, m.nama_mapel
                FROM bank_soal bs
                LEFT JOIN mapel m ON m.id = bs.mapel_id
                WHERE bs.admin_id IS NOT NULL
                ORDER BY bs.nama_soal
            ");
            $stmt->execute();
        }
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // ── AJAX: get_monitoring ───────────────────────────────────────────────────
    if ($action === 'get_monitoring') {
        header('Content-Type: application/json');
        $ruangId     = (int)($_GET['ruang_id'] ?? 0);
        $filterKelas = trim($_GET['filter_kelas'] ?? '');
        $search      = trim($_GET['search'] ?? '');

        if (!$ruangId) { echo json_encode(['success' => false, 'message' => 'ID tidak valid']); exit; }

        $params = [$ruangId, $ruangId];
        $where  = '';
        if ($filterKelas !== '') {
            $where   .= ' AND s.kelas_id = ?';
            $params[] = $filterKelas;
        }
        if ($search !== '') {
            $where   .= ' AND (s.nama LIKE ? OR s.nisn LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $stmt = db()->prepare("
            SELECT s.id            AS siswa_id,
                   s.nama, s.nisn,
                   k.nama_kelas,  k.id AS kelas_id,
                   COALESCE(us.id, 0)              AS ujian_siswa_id,
                   COALESCE(us.status, 'belum')    AS status,
                   us.waktu_mulai, us.waktu_selesai,
                   COALESCE(us.jumlah_benar,  0)   AS jumlah_benar,
                   COALESCE(us.jumlah_salah,  0)   AS jumlah_salah,
                   COALESCE(us.nilai,          0)  AS nilai,
                   COALESCE(us.waktu_tambahan, 0)  AS waktu_tambahan,
                   COALESCE(us.jumlah_keluar,  0)  AS jumlah_keluar
            FROM siswa s
            INNER JOIN kelas k ON k.id = s.kelas_id
            INNER JOIN ruang_ujian_kelas ruk ON ruk.kelas_id = s.kelas_id
            LEFT  JOIN ujian_siswa us ON us.siswa_id = s.id AND us.ruang_ujian_id = ?
            WHERE ruk.ruang_ujian_id = ?
            $where
            ORDER BY k.nama_kelas, s.nama
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $belum = 0; $mengerjakan = 0; $selesai = 0;
        foreach ($rows as &$r) {
            if ($r['waktu_mulai'] && $r['waktu_selesai']) {
                $diff       = strtotime($r['waktu_selesai']) - strtotime($r['waktu_mulai']);
                $r['durasi'] = sprintf('%d mnt %02d dtk', (int)floor($diff / 60), $diff % 60);
            } elseif ($r['waktu_mulai'] && $r['status'] === 'mengerjakan') {
                $diff       = time() - strtotime($r['waktu_mulai']);
                $r['durasi'] = sprintf('%d mnt %02d dtk', (int)floor($diff / 60), $diff % 60);
            } else {
                $r['durasi'] = '-';
            }
            if ($r['status'] === 'belum')        $belum++;
            elseif ($r['status'] === 'mengerjakan') $mengerjakan++;
            else                                    $selesai++;
        }
        unset($r);

        echo json_encode([
            'success' => true,
            'data'    => $rows,
            'stats'   => ['belum' => $belum, 'mengerjakan' => $mengerjakan, 'selesai' => $selesai],
        ]);
        exit;
    }

    // ── AJAX: get_kelas_ruang ─────────────────────────────────────────────────
    if ($action === 'get_kelas_ruang') {
        header('Content-Type: application/json');
        $ruangId = (int)($_GET['ruang_id'] ?? 0);
        $stmt = db()->prepare("
            SELECT k.id, k.nama_kelas
            FROM ruang_ujian_kelas ruk
            INNER JOIN kelas k ON k.id = ruk.kelas_id
            WHERE ruk.ruang_ujian_id = ?
            ORDER BY k.nama_kelas
        ");
        $stmt->execute([$ruangId]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

// ── POST: AJAX Actions ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF tidak valid']); exit;
    }

    $action  = $_POST['action'] ?? '';
    $adminId = $_SESSION['admin_id'];

    // ── tambah ─────────────────────────────────────────────────────────────────
    if ($action === 'tambah') {
        $namaRuang      = trim($_POST['nama_ruang']    ?? '');
        $pembuat        = $_POST['pembuat']            ?? 'admin';
        $guruId         = ($pembuat === 'guru')  ? (int)($_POST['guru_id']      ?? 0) : null;
        $thisAdminId    = ($pembuat === 'admin') ? $adminId                           : null;
        $bankSoalId     = (int)($_POST['bank_soal_id'] ?? 0);
        $waktuHentikan  = max(1, (int)($_POST['waktu_hentikan'] ?? 30));
        $batasKeluar    = max(0, (int)($_POST['batas_keluar']   ?? 3));
        $kelasIds       = array_filter((array)($_POST['kelas_ids'] ?? []));
        $tanggalMulai   = trim($_POST['tanggal_mulai']   ?? '');
        $tanggalSelesai = trim($_POST['tanggal_selesai'] ?? '');
        $acakSoal       = (($_POST['acak_soal']    ?? '0') === '1') ? 1 : 0;
        $acakJawaban    = (($_POST['acak_jawaban'] ?? '0') === '1') ? 1 : 0;

        if (empty($namaRuang)) {
            echo json_encode(['success' => false, 'message' => 'Nama ruang ujian wajib diisi']); exit;
        }
        if (!$bankSoalId) {
            echo json_encode(['success' => false, 'message' => 'Pilih bank soal terlebih dahulu']); exit;
        }
        if (empty($kelasIds)) {
            echo json_encode(['success' => false, 'message' => 'Pilih minimal satu kelas']); exit;
        }
        if (empty($tanggalMulai) || empty($tanggalSelesai)) {
            echo json_encode(['success' => false, 'message' => 'Tanggal mulai dan batas akhir wajib diisi']); exit;
        }
        if ($pembuat === 'guru' && !$guruId) {
            echo json_encode(['success' => false, 'message' => 'Pilih guru terlebih dahulu']); exit;
        }

        // Generate unique 6-char uppercase alphanumeric token
        $token    = '';
        $chars    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $attempts = 0;
        do {
            $token = '';
            for ($i = 0; $i < 6; $i++) {
                $token .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $dupStmt = db()->prepare("SELECT id FROM ruang_ujian WHERE token = ?");
            $dupStmt->execute([$token]);
            $attempts++;
        } while ($dupStmt->fetch() && $attempts < 20);

        db()->beginTransaction();
        try {
            $stmt = db()->prepare("
                INSERT INTO ruang_ujian
                    (nama_ruang, token, guru_id, admin_id, bank_soal_id,
                     waktu_hentikan, batas_keluar, tanggal_mulai, tanggal_selesai,
                     acak_soal, acak_jawaban)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $namaRuang, $token, $guruId, $thisAdminId, $bankSoalId,
                $waktuHentikan, $batasKeluar, $tanggalMulai, $tanggalSelesai,
                $acakSoal, $acakJawaban,
            ]);
            $newRuangId = (int)db()->lastInsertId();

            $stmtKelas = db()->prepare("INSERT IGNORE INTO ruang_ujian_kelas (ruang_ujian_id, kelas_id) VALUES (?, ?)");
            foreach ($kelasIds as $kelasId) {
                $stmtKelas->execute([$newRuangId, trim($kelasId)]);
            }

            db()->commit();
            echo json_encode([
                'success' => true,
                'message' => "Ruang ujian berhasil ditambahkan. TOKEN: $token",
                'token'   => $token,
            ]);
        } catch (Exception $e) {
            db()->rollBack();
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── hapus ──────────────────────────────────────────────────────────────────
    if ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'ID tidak valid']); exit; }
        db()->prepare("DELETE FROM ruang_ujian WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Ruang ujian berhasil dihapus']);
        exit;
    }

    // ── hapus_multiple ─────────────────────────────────────────────────────────
    if ($action === 'hapus_multiple') {
        $ids = array_values(array_filter(array_map('intval', json_decode($_POST['ids'] ?? '[]', true) ?: [])));
        if (empty($ids)) { echo json_encode(['success' => false, 'message' => 'Tidak ada yang dipilih']); exit; }
        $pl = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("DELETE FROM ruang_ujian WHERE id IN ($pl)")->execute($ids);
        echo json_encode(['success' => true, 'message' => count($ids) . ' ruang ujian berhasil dihapus']);
        exit;
    }

    // ── reset_ujian (single) ───────────────────────────────────────────────────
    if ($action === 'reset_ujian') {
        $ujianSiswaId = (int)($_POST['ujian_siswa_id'] ?? 0);
        if (!$ujianSiswaId) { echo json_encode(['success' => false, 'message' => 'ID tidak valid']); exit; }
        db()->prepare("
            UPDATE ujian_siswa
            SET status='belum', waktu_mulai=NULL, waktu_selesai=NULL,
                jumlah_benar=0, jumlah_salah=0, nilai=0,
                jumlah_keluar=0, waktu_tambahan=0
            WHERE id = ?
        ")->execute([$ujianSiswaId]);
        db()->prepare("DELETE FROM jawaban_siswa WHERE ujian_siswa_id = ?")->execute([$ujianSiswaId]);
        echo json_encode(['success' => true, 'message' => 'Ujian siswa berhasil direset']);
        exit;
    }

    // ── reset_multiple ─────────────────────────────────────────────────────────
    if ($action === 'reset_multiple') {
        $ids = array_values(array_filter(array_map('intval', json_decode($_POST['ids'] ?? '[]', true) ?: [])));
        if (empty($ids)) { echo json_encode(['success' => false, 'message' => 'Tidak ada yang dipilih']); exit; }
        $pl = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("
            UPDATE ujian_siswa
            SET status='belum', waktu_mulai=NULL, waktu_selesai=NULL,
                jumlah_benar=0, jumlah_salah=0, nilai=0,
                jumlah_keluar=0, waktu_tambahan=0
            WHERE id IN ($pl)
        ")->execute($ids);
        db()->prepare("DELETE FROM jawaban_siswa WHERE ujian_siswa_id IN ($pl)")->execute($ids);
        echo json_encode(['success' => true, 'message' => count($ids) . ' ujian berhasil direset']);
        exit;
    }

    // ── tambah_waktu (single) ──────────────────────────────────────────────────
    if ($action === 'tambah_waktu') {
        $ujianSiswaId = (int)($_POST['ujian_siswa_id'] ?? 0);
        $menit        = (int)($_POST['menit']          ?? 0);
        if (!$ujianSiswaId || $menit <= 0) {
            echo json_encode(['success' => false, 'message' => 'Data tidak valid']); exit;
        }
        db()->prepare("UPDATE ujian_siswa SET waktu_tambahan = waktu_tambahan + ? WHERE id = ?")
            ->execute([$menit, $ujianSiswaId]);
        echo json_encode(['success' => true, 'message' => "$menit menit berhasil ditambahkan"]);
        exit;
    }

    // ── tambah_waktu_multiple ──────────────────────────────────────────────────
    if ($action === 'tambah_waktu_multiple') {
        $ids   = array_values(array_filter(array_map('intval', json_decode($_POST['ids'] ?? '[]', true) ?: [])));
        $menit = (int)($_POST['menit'] ?? 0);
        if (empty($ids) || $menit <= 0) {
            echo json_encode(['success' => false, 'message' => 'Data tidak valid']); exit;
        }
        $pl     = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$menit], $ids);
        db()->prepare("UPDATE ujian_siswa SET waktu_tambahan = waktu_tambahan + ? WHERE id IN ($pl)")
            ->execute($params);
        echo json_encode([
            'success' => true,
            'message' => "$menit menit berhasil ditambahkan ke " . count($ids) . " siswa",
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali']); exit;
}

// ── Page Data ──────────────────────────────────────────────────────────────────
$ruangList = db()->query("
    SELECT ru.*,
           bs.nama_soal,
           COALESCE(g.nama, a.nama) AS pembuat_nama,
           CASE WHEN ru.guru_id IS NOT NULL THEN 'Guru' ELSE 'Admin' END AS pembuat_tipe,
           GROUP_CONCAT(k.nama_kelas ORDER BY k.nama_kelas SEPARATOR ', ') AS kelas_list
    FROM ruang_ujian ru
    LEFT JOIN bank_soal bs       ON bs.id = ru.bank_soal_id
    LEFT JOIN guru g             ON g.id  = ru.guru_id
    LEFT JOIN admin a            ON a.id  = ru.admin_id
    LEFT JOIN ruang_ujian_kelas ruk ON ruk.ruang_ujian_id = ru.id
    LEFT JOIN kelas k            ON k.id  = ruk.kelas_id
    GROUP BY ru.id
    ORDER BY ru.created_at DESC
")->fetchAll();

$guruList  = db()->query("SELECT id, nama FROM guru  ORDER BY nama")->fetchAll();
$kelasList = db()->query("SELECT id, nama_kelas FROM kelas ORDER BY nama_kelas")->fetchAll();
$appName   = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ruang Ujian – <?= htmlspecialchars($appName) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .hidden-page { display: none !important; }
    /* Smooth toggle switch via Tailwind peer utilities */
  </style>
</head>
<body class="bg-gray-100">

<?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="ml-64 pt-16 pb-10 px-6">

  <!-- Page heading ──────────────────────────────────────────────────────────── -->
  <div class="flex items-center justify-between mt-6 mb-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
        <i class="fa fa-door-open text-blue-600"></i> Ruang Ujian
      </h1>
      <p class="text-gray-500 text-sm mt-1">Kelola ruang ujian CBT dan pantau peserta secara real-time</p>
    </div>
    <button onclick="openTambahModal()"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2 transition shadow-sm">
      <i class="fa fa-plus"></i> Tambah Ruang Ujian
    </button>
  </div>

  <!-- Main card ─────────────────────────────────────────────────────────────── -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">

    <!-- Table toolbar -->
    <div class="p-4 border-b flex flex-wrap items-center gap-3">
      <!-- Bulk delete (hidden until rows checked) -->
      <button id="btn-hapus-multiple" onclick="hapusMultiple()"
              class="hidden bg-red-100 hover:bg-red-200 text-red-700 px-3 py-1.5 rounded-lg text-sm font-medium items-center gap-1.5 transition">
        <i class="fa fa-trash"></i> Hapus Terpilih (<span id="selected-count">0</span>)
      </button>

      <div class="flex items-center gap-3 ml-auto">
        <input type="text" id="search-input" oninput="filterTable()"
               placeholder="Cari nama / token…"
               class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-60 focus:outline-none focus:ring-2 focus:ring-blue-400">
        <select id="rows-per-page" onchange="changeRowsPerPage()"
                class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
          <option value="10">10 baris</option>
          <option value="25">25 baris</option>
          <option value="50">50 baris</option>
          <option value="all">Semua</option>
        </select>
      </div>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="px-4 py-3 w-10 text-center">
              <input type="checkbox" id="check-all" onchange="toggleSelectAll()" class="rounded">
            </th>
            <th class="px-4 py-3 text-left w-10">No</th>
            <th class="px-4 py-3 text-left">Nama Ruang</th>
            <th class="px-4 py-3 text-left">TOKEN</th>
            <th class="px-4 py-3 text-left">Kelas</th>
            <th class="px-4 py-3 text-left">Tanggal</th>
            <th class="px-4 py-3 text-left">Aksi</th>
          </tr>
        </thead>
        <tbody id="table-body">
          <?php if (empty($ruangList)): ?>
          <tr>
            <td colspan="7" class="px-4 py-12 text-center text-gray-400">
              <i class="fa fa-inbox text-4xl mb-3 block"></i>
              <p class="text-sm">Belum ada ruang ujian. Klik <strong>Tambah Ruang Ujian</strong> untuk memulai.</p>
            </td>
          </tr>
          <?php else: ?>
          <?php foreach ($ruangList as $i => $r): ?>
          <tr class="border-t hover:bg-gray-50 transition table-row"
              data-search="<?= htmlspecialchars(mb_strtolower($r['nama_ruang'] . ' ' . $r['token'])) ?>">

            <td class="px-4 py-3 text-center">
              <input type="checkbox" class="row-checkbox rounded" value="<?= $r['id'] ?>"
                     onchange="updateSelectAll()">
            </td>

            <td class="px-4 py-3 text-gray-500 text-xs"><?= $i + 1 ?></td>

            <td class="px-4 py-3">
              <p class="font-semibold text-gray-800"><?= htmlspecialchars($r['nama_ruang']) ?></p>
              <p class="text-xs text-gray-400 mt-0.5">
                <i class="fa fa-file-alt text-blue-400"></i>
                <?= htmlspecialchars($r['nama_soal'] ?? '–') ?>
                &nbsp;·&nbsp;
                <i class="fa fa-user text-purple-400"></i>
                <?= htmlspecialchars($r['pembuat_nama'] ?? '–') ?>
                <span class="inline-block bg-<?= $r['pembuat_tipe'] === 'Guru' ? 'purple' : 'blue' ?>-100
                             text-<?= $r['pembuat_tipe'] === 'Guru' ? 'purple' : 'blue' ?>-600
                             text-[10px] px-1.5 py-0.5 rounded ml-1">
                  <?= $r['pembuat_tipe'] ?>
                </span>
              </p>
            </td>

            <td class="px-4 py-3">
              <span class="font-mono font-bold text-blue-600 bg-blue-50 border border-blue-200
                           px-2.5 py-1 rounded-md text-sm tracking-widest">
                <?= htmlspecialchars($r['token']) ?>
              </span>
            </td>

            <td class="px-4 py-3 text-xs text-gray-600 max-w-[180px]">
              <?php if ($r['kelas_list']): ?>
                <div class="flex flex-wrap gap-1">
                  <?php foreach (explode(', ', $r['kelas_list']) as $kn): ?>
                  <span class="bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-[10px] font-medium">
                    <?= htmlspecialchars(trim($kn)) ?>
                  </span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="text-gray-400">–</span>
              <?php endif; ?>
            </td>

            <td class="px-4 py-3 text-xs text-gray-600">
              <div class="flex flex-col gap-0.5">
                <span><i class="fa fa-play-circle text-green-500 mr-1"></i><?= date('d/m/Y H:i', strtotime($r['tanggal_mulai'])) ?></span>
                <span><i class="fa fa-stop-circle text-red-400 mr-1"></i><?= date('d/m/Y H:i', strtotime($r['tanggal_selesai'])) ?></span>
              </div>
            </td>

            <td class="px-4 py-3">
              <div class="flex items-center gap-1.5">
                <button onclick="openMonitorModal(<?= $r['id'] ?>, <?= json_encode($r['nama_ruang']) ?>, <?= json_encode($r['token']) ?>)"
                        class="bg-green-100 hover:bg-green-200 text-green-700 px-2.5 py-1.5 rounded-lg
                               text-xs font-medium transition flex items-center gap-1">
                  <i class="fa fa-chart-line"></i> Monitor
                </button>
                <button onclick="hapusRuang(<?= $r['id'] ?>, <?= json_encode($r['nama_ruang']) ?>)"
                        class="bg-red-100 hover:bg-red-200 text-red-700 px-2.5 py-1.5 rounded-lg
                               text-xs font-medium transition flex items-center gap-1">
                  <i class="fa fa-trash"></i> Hapus
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination bar -->
    <div class="px-4 py-3 border-t flex flex-wrap items-center justify-between gap-2 text-sm text-gray-500">
      <span id="showing-info"></span>
      <div id="pagination-controls" class="flex flex-wrap gap-1"></div>
    </div>
  </div>

</main>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL: Tambah Ruang Ujian
════════════════════════════════════════════════════════════════════════════ -->
<div id="tambah-modal" class="fixed inset-0 z-50 hidden overflow-y-auto">
  <div class="min-h-screen px-4 py-8 flex items-start justify-center">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeTambahModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl z-10 my-4">

      <!-- Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b">
        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
          <i class="fa fa-plus-circle text-blue-600"></i> Tambah Ruang Ujian
        </h3>
        <button onclick="closeTambahModal()" class="text-gray-400 hover:text-gray-600 text-xl leading-none">
          <i class="fa fa-times"></i>
        </button>
      </div>

      <!-- Body -->
      <div class="px-6 py-5 space-y-5 max-h-[72vh] overflow-y-auto">

        <!-- Nama Ruang -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Nama Ruang Ujian <span class="text-red-500">*</span>
          </label>
          <input type="text" id="t-nama-ruang"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm
                        focus:outline-none focus:ring-2 focus:ring-blue-400"
                 placeholder="Contoh: UTS Matematika Kelas 7A">
        </div>

        <!-- Pembuat -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Pembuat <span class="text-red-500">*</span>
          </label>
          <div class="flex items-center gap-8">
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="radio" name="t-pembuat" value="admin" checked
                     onchange="handlePembuatChange()" class="accent-blue-600">
              <span class="text-sm text-gray-700">Admin</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="radio" name="t-pembuat" value="guru"
                     onchange="handlePembuatChange()" class="accent-blue-600">
              <span class="text-sm text-gray-700">Guru</span>
            </label>
          </div>
          <!-- Guru dropdown (conditionally shown) -->
          <div id="t-guru-section" class="hidden mt-2">
            <select id="t-guru-id" onchange="loadBankSoal()"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm
                           focus:outline-none focus:ring-2 focus:ring-blue-400">
              <option value="">-- Pilih Guru --</option>
              <?php foreach ($guruList as $g): ?>
              <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['nama']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Bank Soal -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Bank Soal <span class="text-red-500">*</span>
          </label>
          <select id="t-bank-soal-id"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm
                         focus:outline-none focus:ring-2 focus:ring-blue-400">
            <option value="">Memuat…</option>
          </select>
        </div>

        <!-- Waktu Hentikan + Batas Keluar -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Waktu Hentikan Ujian (menit) <span class="text-red-500">*</span>
            </label>
            <input type="number" id="t-waktu-hentikan" min="1" value="30"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm
                          focus:outline-none focus:ring-2 focus:ring-blue-400">
            <p class="text-xs text-gray-400 mt-1">
              <i class="fa fa-info-circle"></i>
              Ujian otomatis dihentikan setelah waktu ini habis.
            </p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Batas Keluar (kali) <span class="text-red-500">*</span>
            </label>
            <input type="number" id="t-batas-keluar" min="0" value="3"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm
                          focus:outline-none focus:ring-2 focus:ring-blue-400">
            <p class="text-xs text-gray-400 mt-1">
              <i class="fa fa-info-circle"></i>
              Siswa selesai paksa jika keluar melebihi batas ini. (0 = tidak dibatasi)
            </p>
          </div>
        </div>

        <!-- Pilih Kelas -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Pilih Kelas <span class="text-red-500">*</span>
          </label>
          <select id="t-kelas-ids" multiple size="5"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm
                         focus:outline-none focus:ring-2 focus:ring-blue-400">
            <?php foreach ($kelasList as $k): ?>
            <option value="<?= htmlspecialchars($k['id']) ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-gray-400 mt-1">
            <i class="fa fa-info-circle"></i>
            Gunakan <kbd class="bg-gray-100 border px-1 rounded text-xs">Ctrl</kbd> /
            <kbd class="bg-gray-100 border px-1 rounded text-xs">Shift</kbd>
            untuk memilih lebih dari satu kelas.
          </p>
        </div>

        <!-- Tanggal Mulai + Batas Akhir -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Tanggal Mulai <span class="text-red-500">*</span>
            </label>
            <input type="datetime-local" id="t-tanggal-mulai"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm
                          focus:outline-none focus:ring-2 focus:ring-blue-400">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Batas Akhir <span class="text-red-500">*</span>
            </label>
            <input type="datetime-local" id="t-tanggal-selesai"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm
                          focus:outline-none focus:ring-2 focus:ring-blue-400">
          </div>
        </div>

        <!-- Toggle Acak Soal + Acak Jawaban -->
        <div class="flex items-center gap-8 py-1">
          <!-- Acak Soal -->
          <div class="flex items-center gap-3">
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" id="t-acak-soal" class="sr-only peer">
              <div class="w-11 h-6 bg-gray-200 rounded-full peer
                          peer-focus:ring-2 peer-focus:ring-blue-300
                          peer-checked:bg-blue-600
                          after:content-[''] after:absolute after:top-[2px] after:left-[2px]
                          after:bg-white after:rounded-full after:h-5 after:w-5
                          after:transition-all peer-checked:after:translate-x-full">
              </div>
            </label>
            <span class="text-sm text-gray-700 font-medium">Acak Soal</span>
          </div>
          <!-- Acak Jawaban -->
          <div class="flex items-center gap-3">
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" id="t-acak-jawaban" class="sr-only peer">
              <div class="w-11 h-6 bg-gray-200 rounded-full peer
                          peer-focus:ring-2 peer-focus:ring-blue-300
                          peer-checked:bg-blue-600
                          after:content-[''] after:absolute after:top-[2px] after:left-[2px]
                          after:bg-white after:rounded-full after:h-5 after:w-5
                          after:transition-all peer-checked:after:translate-x-full">
              </div>
            </label>
            <span class="text-sm text-gray-700 font-medium">Acak Jawaban</span>
          </div>
        </div>

      </div>

      <!-- Footer -->
      <div class="flex justify-end gap-3 px-6 py-4 border-t bg-gray-50 rounded-b-2xl">
        <button onclick="closeTambahModal()"
                class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg transition">
          Batal
        </button>
        <button id="btn-save-tambah" onclick="saveTambahRuang()"
                class="px-6 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white
                       font-medium rounded-lg transition flex items-center gap-2 shadow-sm">
          <i class="fa fa-save"></i> Simpan
        </button>
      </div>

    </div>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL: Monitoring Ujian
════════════════════════════════════════════════════════════════════════════ -->
<div id="monitor-modal" class="fixed inset-0 z-50 hidden overflow-y-auto">
  <div class="min-h-screen px-4 py-6 flex items-start justify-center">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeMonitorModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-6xl z-10 my-4">

      <!-- Header -->
      <div class="flex items-start justify-between px-6 py-4 border-b">
        <div>
          <h3 class="text-lg font-bold text-gray-800" id="monitor-title">Monitoring Ujian</h3>
          <div class="flex items-center gap-2 mt-1">
            <span class="text-xs text-gray-500">TOKEN:</span>
            <span id="monitor-token"
                  class="font-mono font-bold text-blue-600 bg-blue-50 border border-blue-200
                         px-2 py-0.5 rounded tracking-widest text-sm"></span>
            <span class="text-xs text-green-600 bg-green-50 px-2 py-0.5 rounded-full border border-green-200">
              <i class="fa fa-sync-alt fa-spin text-[10px] mr-1"></i>Auto-refresh 10 dtk
            </span>
          </div>
        </div>
        <button onclick="closeMonitorModal()"
                class="text-gray-400 hover:text-gray-600 text-xl leading-none mt-0.5">
          <i class="fa fa-times"></i>
        </button>
      </div>

      <div class="px-6 py-5 space-y-4">

        <!-- Filter bar + Export buttons -->
        <div class="flex flex-wrap items-center gap-3">
          <select id="monitor-filter-kelas" onchange="refreshMonitoring()"
                  class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm
                         focus:outline-none focus:ring-2 focus:ring-blue-400">
            <option value="">Semua Kelas</option>
          </select>
          <input type="text" id="monitor-search" oninput="refreshMonitoring()"
                 placeholder="Cari nama / NISN…"
                 class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-52
                        focus:outline-none focus:ring-2 focus:ring-blue-400">
          <div class="ml-auto flex gap-2">
            <button onclick="exportNilai()"
                    class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg
                           text-xs font-medium flex items-center gap-1.5 transition shadow-sm">
              <i class="fa fa-file-csv"></i> Cetak Nilai
            </button>
            <button onclick="exportAnalisis()"
                    class="bg-violet-600 hover:bg-violet-700 text-white px-3 py-1.5 rounded-lg
                           text-xs font-medium flex items-center gap-1.5 transition shadow-sm">
              <i class="fa fa-chart-bar"></i> Cetak Analisis Soal
            </button>
          </div>
        </div>

        <!-- Stats boxes -->
        <div class="grid grid-cols-3 gap-3">
          <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-center">
            <p class="text-3xl font-extrabold text-red-600" id="stat-belum">0</p>
            <p class="text-xs text-red-500 font-semibold mt-0.5 uppercase tracking-wide">Belum Mengerjakan</p>
          </div>
          <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 text-center">
            <p class="text-3xl font-extrabold text-orange-500" id="stat-mengerjakan">0</p>
            <p class="text-xs text-orange-500 font-semibold mt-0.5 uppercase tracking-wide">Sedang Mengerjakan</p>
          </div>
          <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-center">
            <p class="text-3xl font-extrabold text-emerald-600" id="stat-selesai">0</p>
            <p class="text-xs text-emerald-600 font-semibold mt-0.5 uppercase tracking-wide">Selesai</p>
          </div>
        </div>

        <!-- Bulk action row -->
        <div class="flex items-center gap-3 pb-1">
          <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-600 select-none">
            <input type="checkbox" id="monitor-check-all" onchange="toggleMonitorSelectAll()" class="rounded">
            <span id="monitor-selected-label">Pilih semua</span>
          </label>
          <div id="monitor-bulk-actions" class="hidden items-center gap-2">
            <button onclick="resetMultiple()"
                    class="bg-red-100 hover:bg-red-200 text-red-700 px-3 py-1 rounded-lg
                           text-xs font-medium transition flex items-center gap-1">
              <i class="fa fa-redo"></i> Reset Ujian
            </button>
            <button onclick="openTambahWaktuMultiple()"
                    class="bg-blue-100 hover:bg-blue-200 text-blue-700 px-3 py-1 rounded-lg
                           text-xs font-medium transition flex items-center gap-1">
              <i class="fa fa-clock"></i> Tambah Waktu
            </button>
          </div>
        </div>

        <!-- Monitoring table -->
        <div class="border rounded-xl overflow-hidden">
          <div class="overflow-x-auto max-h-[380px] overflow-y-auto">
            <table class="w-full text-sm min-w-[860px]">
              <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide sticky top-0 z-10">
                <tr>
                  <th class="px-3 py-2.5 w-10 text-center"></th>
                  <th class="px-3 py-2.5 w-10 text-left">No</th>
                  <th class="px-3 py-2.5 text-left">Nama Siswa</th>
                  <th class="px-3 py-2.5 text-left">NISN</th>
                  <th class="px-3 py-2.5 text-left">Kelas</th>
                  <th class="px-3 py-2.5 text-left">Waktu Mengerjakan</th>
                  <th class="px-3 py-2.5 text-center w-12">B</th>
                  <th class="px-3 py-2.5 text-center w-12">S</th>
                  <th class="px-3 py-2.5 text-center w-16">Nilai</th>
                  <th class="px-3 py-2.5 text-center w-28">Status</th>
                  <th class="px-3 py-2.5 text-left w-24">Aksi</th>
                </tr>
              </thead>
              <tbody id="monitor-table-body">
                <tr>
                  <td colspan="11" class="py-10 text-center text-gray-400">
                    <i class="fa fa-spinner fa-spin text-3xl"></i>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL: Tambah Waktu
════════════════════════════════════════════════════════════════════════════ -->
<div id="tambah-waktu-modal" class="fixed inset-0 z-[60] hidden">
  <div class="min-h-screen flex items-center justify-center px-4">
    <div class="fixed inset-0 bg-black/40" onclick="closeTambahWaktuModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm z-10">
      <div class="flex items-center justify-between px-5 py-4 border-b">
        <h3 class="text-base font-bold text-gray-800 flex items-center gap-2">
          <i class="fa fa-clock text-blue-600"></i> Tambah Waktu Ujian
        </h3>
        <button onclick="closeTambahWaktuModal()" class="text-gray-400 hover:text-gray-600">
          <i class="fa fa-times"></i>
        </button>
      </div>
      <div class="px-5 py-4">
        <p class="text-sm text-gray-500 mb-3" id="tambah-waktu-desc">Tambah waktu pengerjaan ujian.</p>
        <label class="block text-sm font-medium text-gray-700 mb-1">Tambahan Waktu (menit)</label>
        <input type="number" id="tambah-waktu-input" min="1" value="5"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm
                      focus:outline-none focus:ring-2 focus:ring-blue-400">
      </div>
      <div class="flex justify-end gap-3 px-5 py-4 border-t bg-gray-50 rounded-b-2xl">
        <button onclick="closeTambahWaktuModal()"
                class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg transition">
          Batal
        </button>
        <button id="btn-save-waktu" onclick="saveTambahWaktu()"
                class="px-6 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white
                       font-medium rounded-lg transition flex items-center gap-2">
          <i class="fa fa-check"></i> Simpan
        </button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
const CSRF = <?= json_encode($csrfToken) ?>;

// ── Helpers ────────────────────────────────────────────────────────────────────
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

async function postJson(params) {
    const res = await fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: CSRF, ...params }),
    });
    return res.json();
}

// ── Main Table: Pagination + Search ───────────────────────────────────────────
let currentPage  = 1;
let rowsPerPage  = 10;

function filterTable() {
    const q = document.getElementById('search-input').value.toLowerCase().trim();
    document.querySelectorAll('#table-body .table-row').forEach(r => {
        r.style.display = r.dataset.search.includes(q) ? '' : 'none';
    });
    currentPage = 1;
    renderPagination();
}

function changeRowsPerPage() {
    rowsPerPage = document.getElementById('rows-per-page').value;
    currentPage = 1;
    renderPagination();
}

function renderPagination() {
    const visible = [...document.querySelectorAll('#table-body .table-row')]
                        .filter(r => r.style.display !== 'none');
    const total   = visible.length;

    if (rowsPerPage === 'all') {
        visible.forEach(r => r.classList.remove('hidden-page'));
        document.getElementById('showing-info').textContent = `Menampilkan ${total} data`;
        document.getElementById('pagination-controls').innerHTML = '';
        return;
    }

    const rpp        = parseInt(rowsPerPage);
    const totalPages = Math.max(1, Math.ceil(total / rpp));
    currentPage      = Math.min(currentPage, totalPages);

    visible.forEach((r, i) => {
        r.classList.toggle('hidden-page', Math.floor(i / rpp) + 1 !== currentPage);
    });

    const start = (currentPage - 1) * rpp + 1;
    const end   = Math.min(currentPage * rpp, total);
    document.getElementById('showing-info').textContent =
        total > 0 ? `Menampilkan ${start}–${end} dari ${total} data` : 'Tidak ada data';

    const ctrl = document.getElementById('pagination-controls');
    ctrl.innerHTML = '';
    for (let p = 1; p <= totalPages; p++) {
        const btn = document.createElement('button');
        btn.textContent = p;
        btn.className = `px-3 py-1 rounded text-xs font-medium transition ${
            p === currentPage
                ? 'bg-blue-600 text-white'
                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
        }`;
        btn.onclick = () => { currentPage = p; renderPagination(); };
        ctrl.appendChild(btn);
    }
}

// ── Main Table: Bulk Select ────────────────────────────────────────────────────
function toggleSelectAll() {
    const checked = document.getElementById('check-all').checked;
    document.querySelectorAll('.row-checkbox').forEach(c => c.checked = checked);
    updateSelectAll();
}

function updateSelectAll() {
    const all     = document.querySelectorAll('.row-checkbox');
    const checked = document.querySelectorAll('.row-checkbox:checked');
    const ca      = document.getElementById('check-all');
    ca.checked       = all.length > 0 && checked.length === all.length;
    ca.indeterminate = checked.length > 0 && checked.length < all.length;

    const btn = document.getElementById('btn-hapus-multiple');
    if (checked.length > 0) {
        btn.classList.remove('hidden');
        btn.classList.add('flex');
        document.getElementById('selected-count').textContent = checked.length;
    } else {
        btn.classList.add('hidden');
        btn.classList.remove('flex');
    }
}

function hapusMultiple() {
    const ids = [...document.querySelectorAll('.row-checkbox:checked')].map(c => parseInt(c.value));
    if (!ids.length) return;
    Swal.fire({
        title: 'Hapus Ruang Ujian?',
        html: `Hapus <strong>${ids.length}</strong> ruang ujian yang dipilih?<br>
               <span class="text-sm text-gray-500">Semua data ujian siswa juga akan terhapus!</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText:  'Batal',
        confirmButtonColor: '#dc2626',
    }).then(async r => {
        if (!r.isConfirmed) return;
        const d = await postJson({ action: 'hapus_multiple', ids: JSON.stringify(ids) });
        if (d.success) {
            Swal.fire({ icon: 'success', title: 'Berhasil', text: d.message,
                        timer: 1500, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: d.message });
        }
    });
}

// ── Modal: Tambah Ruang Ujian ──────────────────────────────────────────────────
function openTambahModal() {
    document.getElementById('t-nama-ruang').value = '';
    document.querySelector('input[name="t-pembuat"][value="admin"]').checked = true;
    document.getElementById('t-guru-section').classList.add('hidden');
    document.getElementById('t-guru-id').value = '';
    document.getElementById('t-waktu-hentikan').value = 30;
    document.getElementById('t-batas-keluar').value   = 3;
    document.getElementById('t-acak-soal').checked    = false;
    document.getElementById('t-acak-jawaban').checked = false;
    document.getElementById('t-tanggal-mulai').value   = '';
    document.getElementById('t-tanggal-selesai').value = '';
    [...document.getElementById('t-kelas-ids').options].forEach(o => o.selected = false);
    loadBankSoal();
    document.getElementById('tambah-modal').classList.remove('hidden');
}

function closeTambahModal() {
    document.getElementById('tambah-modal').classList.add('hidden');
}

function handlePembuatChange() {
    const pembuat = document.querySelector('input[name="t-pembuat"]:checked').value;
    if (pembuat === 'guru') {
        document.getElementById('t-guru-section').classList.remove('hidden');
        // Reset bank soal until a guru is selected
        document.getElementById('t-bank-soal-id').innerHTML =
            '<option value="">-- Pilih guru terlebih dahulu --</option>';
    } else {
        document.getElementById('t-guru-section').classList.add('hidden');
        document.getElementById('t-guru-id').value = '';
        loadBankSoal();
    }
}

async function loadBankSoal() {
    const pembuat = document.querySelector('input[name="t-pembuat"]:checked').value;
    const guruId  = document.getElementById('t-guru-id').value;
    const sel     = document.getElementById('t-bank-soal-id');

    if (pembuat === 'guru' && !guruId) {
        sel.innerHTML = '<option value="">-- Pilih guru terlebih dahulu --</option>';
        return;
    }

    sel.innerHTML = '<option value="">Memuat…</option>';
    let url = `?action=get_bank_soal&pembuat=${encodeURIComponent(pembuat)}`;
    if (pembuat === 'guru') url += `&guru_id=${encodeURIComponent(guruId)}`;

    try {
        const list = await fetch(url).then(r => r.json());
        sel.innerHTML = '<option value="">-- Pilih Bank Soal --</option>';
        if (!list.length) {
            sel.innerHTML += '<option value="" disabled>Tidak ada bank soal tersedia</option>';
        } else {
            list.forEach(b => {
                const label = escHtml(b.nama_soal) + (b.nama_mapel ? ` (${escHtml(b.nama_mapel)})` : '');
                sel.innerHTML += `<option value="${b.id}">${label}</option>`;
            });
        }
    } catch {
        sel.innerHTML = '<option value="">Gagal memuat bank soal</option>';
    }
}

async function saveTambahRuang() {
    const namaRuang     = document.getElementById('t-nama-ruang').value.trim();
    const pembuat       = document.querySelector('input[name="t-pembuat"]:checked').value;
    const guruId        = document.getElementById('t-guru-id').value;
    const bankSoalId    = document.getElementById('t-bank-soal-id').value;
    const waktuHentikan = document.getElementById('t-waktu-hentikan').value;
    const batasKeluar   = document.getElementById('t-batas-keluar').value;
    const tanggalMulai  = document.getElementById('t-tanggal-mulai').value;
    const tanggalSelesai= document.getElementById('t-tanggal-selesai').value;
    const acakSoal      = document.getElementById('t-acak-soal').checked    ? '1' : '0';
    const acakJawaban   = document.getElementById('t-acak-jawaban').checked ? '1' : '0';
    const kelasIds      = [...document.getElementById('t-kelas-ids').options]
                              .filter(o => o.selected).map(o => o.value);

    if (!namaRuang)  { Swal.fire({ icon:'warning', title:'Perhatian', text:'Nama ruang ujian wajib diisi' }); return; }
    if (!bankSoalId) { Swal.fire({ icon:'warning', title:'Perhatian', text:'Pilih bank soal terlebih dahulu' }); return; }
    if (!kelasIds.length) { Swal.fire({ icon:'warning', title:'Perhatian', text:'Pilih minimal satu kelas' }); return; }
    if (!tanggalMulai || !tanggalSelesai) { Swal.fire({ icon:'warning', title:'Perhatian', text:'Tanggal mulai dan batas akhir wajib diisi' }); return; }
    if (pembuat === 'guru' && !guruId) { Swal.fire({ icon:'warning', title:'Perhatian', text:'Pilih guru terlebih dahulu' }); return; }

    const params = new URLSearchParams({
        action: 'tambah', csrf_token: CSRF,
        nama_ruang: namaRuang, pembuat,
        bank_soal_id: bankSoalId,
        waktu_hentikan: waktuHentikan, batas_keluar: batasKeluar,
        tanggal_mulai: tanggalMulai, tanggal_selesai: tanggalSelesai,
        acak_soal: acakSoal, acak_jawaban: acakJawaban,
    });
    if (pembuat === 'guru') params.append('guru_id', guruId);
    kelasIds.forEach(id => params.append('kelas_ids[]', id));

    const btn = document.getElementById('btn-save-tambah');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Menyimpan…';

    try {
        const res  = await fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params });
        const data = await res.json();
        if (data.success) {
            closeTambahModal();
            Swal.fire({
                icon: 'success', title: 'Berhasil!',
                html: `Ruang ujian ditambahkan.<br>
                       TOKEN: <span class="font-mono font-bold text-blue-600 text-lg">${escHtml(data.token)}</span>`,
                confirmButtonText: 'OK',
            }).then(() => location.reload());
        } else {
            Swal.fire({ icon:'error', title:'Gagal', text: data.message });
        }
    } catch {
        Swal.fire({ icon:'error', title:'Error', text:'Terjadi kesalahan koneksi' });
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-save"></i> Simpan';
    }
}

function hapusRuang(id, nama) {
    Swal.fire({
        title: 'Hapus Ruang Ujian?',
        html: `Hapus <strong>${escHtml(nama)}</strong>?<br>
               <span class="text-sm text-gray-500">Semua data ujian siswa juga akan terhapus!</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText:  'Batal',
        confirmButtonColor: '#dc2626',
    }).then(async r => {
        if (!r.isConfirmed) return;
        const d = await postJson({ action: 'hapus', id });
        if (d.success) {
            Swal.fire({ icon:'success', title:'Berhasil', text: d.message,
                        timer:1500, showConfirmButton:false })
                .then(() => location.reload());
        } else {
            Swal.fire({ icon:'error', title:'Gagal', text: d.message });
        }
    });
}

// ── Modal: Monitoring ──────────────────────────────────────────────────────────
let currentMonitorRuangId  = null;
let monitorRefreshInterval = null;

async function openMonitorModal(ruangId, namaRuang, token) {
    currentMonitorRuangId = ruangId;

    document.getElementById('monitor-title').textContent = 'Monitoring Ujian: ' + namaRuang;
    document.getElementById('monitor-token').textContent = token;
    document.getElementById('monitor-search').value = '';
    document.getElementById('monitor-check-all').checked      = false;
    document.getElementById('monitor-check-all').indeterminate = false;
    document.getElementById('monitor-selected-label').textContent = 'Pilih semua';
    document.getElementById('monitor-bulk-actions').classList.add('hidden');
    document.getElementById('monitor-bulk-actions').classList.remove('flex');

    // Reset kelas filter
    const kf = document.getElementById('monitor-filter-kelas');
    kf.innerHTML = '<option value="">Semua Kelas</option>';

    document.getElementById('monitor-modal').classList.remove('hidden');

    // Load kelas list for filter
    try {
        const kelasList = await fetch(`?action=get_kelas_ruang&ruang_id=${ruangId}`).then(r => r.json());
        kelasList.forEach(k => {
            const opt = document.createElement('option');
            opt.value = k.id;
            opt.textContent = k.nama_kelas;
            kf.appendChild(opt);
        });
    } catch { /* non-critical */ }

    // Initial data load + auto-refresh
    await refreshMonitoring();
    monitorRefreshInterval = setInterval(refreshMonitoring, 10000);
}

function closeMonitorModal() {
    clearInterval(monitorRefreshInterval);
    monitorRefreshInterval = null;
    currentMonitorRuangId  = null;
    document.getElementById('monitor-modal').classList.add('hidden');
}

async function refreshMonitoring() {
    if (!currentMonitorRuangId) return;
    const filterKelas = document.getElementById('monitor-filter-kelas').value;
    const search      = document.getElementById('monitor-search').value;

    let url = `?action=get_monitoring&ruang_id=${currentMonitorRuangId}`;
    if (filterKelas) url += `&filter_kelas=${encodeURIComponent(filterKelas)}`;
    if (search)      url += `&search=${encodeURIComponent(search)}`;

    try {
        const data = await fetch(url).then(r => r.json());
        if (!data.success) return;

        document.getElementById('stat-belum').textContent       = data.stats.belum;
        document.getElementById('stat-mengerjakan').textContent = data.stats.mengerjakan;
        document.getElementById('stat-selesai').textContent     = data.stats.selesai;

        renderMonitorTable(data.data);
    } catch (e) {
        console.error('Monitor refresh error:', e);
    }
}

function renderMonitorTable(rows) {
    const tbody = document.getElementById('monitor-table-body');
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="11" class="py-10 text-center text-gray-400">
            <i class="fa fa-inbox text-3xl mb-2 block"></i>Tidak ada siswa ditemukan
        </td></tr>`;
        return;
    }

    const statusMap = {
        belum:       '<span class="bg-red-100    text-red-700    text-[11px] px-2 py-0.5 rounded-full font-semibold">Belum</span>',
        mengerjakan: '<span class="bg-orange-100 text-orange-600 text-[11px] px-2 py-0.5 rounded-full font-semibold">Mengerjakan</span>',
        selesai:     '<span class="bg-emerald-100 text-emerald-700 text-[11px] px-2 py-0.5 rounded-full font-semibold">Selesai</span>',
    };

    tbody.innerHTML = rows.map((r, i) => {
        const canReset      = parseInt(r.ujian_siswa_id) > 0;
        const canAddTime    = canReset && r.status === 'mengerjakan';
        const nameSafe      = r.nama.replace(/\\/g, '\\\\').replace(/'/g, "\\'");

        const btnReset = canReset
            ? `<button onclick="resetUjian(${r.ujian_siswa_id})"
                       class="bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded text-xs transition"
                       title="Reset Ujian"><i class="fa fa-redo"></i></button>`
            : `<button disabled class="bg-gray-100 text-gray-300 px-2 py-1 rounded text-xs cursor-not-allowed"
                       title="Belum mulai"><i class="fa fa-redo"></i></button>`;

        const btnTime = canAddTime
            ? `<button onclick="openTambahWaktuSingle(${r.ujian_siswa_id}, '${nameSafe}')"
                       class="bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-1 rounded text-xs transition"
                       title="Tambah Waktu"><i class="fa fa-clock"></i></button>`
            : `<button disabled class="bg-gray-100 text-gray-300 px-2 py-1 rounded text-xs cursor-not-allowed"
                       title="Hanya untuk siswa yang sedang mengerjakan"><i class="fa fa-clock"></i></button>`;

        const checkbox = canReset
            ? `<input type="checkbox" class="monitor-checkbox rounded" value="${r.ujian_siswa_id}"
                      onchange="updateMonitorBulk()">`
            : '';

        return `
        <tr class="border-t hover:bg-gray-50 transition">
            <td class="px-3 py-2.5 text-center">${checkbox}</td>
            <td class="px-3 py-2.5 text-gray-500 text-xs">${i + 1}</td>
            <td class="px-3 py-2.5 font-medium text-gray-800">${escHtml(r.nama)}</td>
            <td class="px-3 py-2.5 font-mono text-xs text-gray-600">${escHtml(r.nisn)}</td>
            <td class="px-3 py-2.5 text-xs text-gray-600">${escHtml(r.nama_kelas)}</td>
            <td class="px-3 py-2.5 text-xs text-gray-600">${escHtml(r.durasi)}</td>
            <td class="px-3 py-2.5 text-center font-bold text-emerald-600">${r.jumlah_benar}</td>
            <td class="px-3 py-2.5 text-center font-bold text-red-500">${r.jumlah_salah}</td>
            <td class="px-3 py-2.5 text-center font-bold text-gray-800">${r.nilai}</td>
            <td class="px-3 py-2.5 text-center">${statusMap[r.status] ?? ''}</td>
            <td class="px-3 py-2.5">
                <div class="flex items-center gap-1">${btnReset}${btnTime}</div>
            </td>
        </tr>`;
    }).join('');
}

function toggleMonitorSelectAll() {
    const checked = document.getElementById('monitor-check-all').checked;
    document.querySelectorAll('.monitor-checkbox').forEach(c => c.checked = checked);
    updateMonitorBulk();
}

function updateMonitorBulk() {
    const all     = document.querySelectorAll('.monitor-checkbox');
    const checked = document.querySelectorAll('.monitor-checkbox:checked');
    const ca      = document.getElementById('monitor-check-all');
    ca.checked       = all.length > 0 && checked.length === all.length;
    ca.indeterminate = checked.length > 0 && checked.length < all.length;

    const label   = document.getElementById('monitor-selected-label');
    const actions = document.getElementById('monitor-bulk-actions');

    if (checked.length > 0) {
        label.textContent = `${checked.length} dipilih`;
        actions.classList.remove('hidden');
        actions.classList.add('flex');
    } else {
        label.textContent = 'Pilih semua';
        actions.classList.add('hidden');
        actions.classList.remove('flex');
    }
}

function getMonitorCheckedIds() {
    return [...document.querySelectorAll('.monitor-checkbox:checked')].map(c => parseInt(c.value));
}

async function resetUjian(ujianSiswaId) {
    const r = await Swal.fire({
        title: 'Reset Ujian?',
        text: 'Status ujian siswa ini akan direset ke "Belum". Semua jawaban akan terhapus!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Reset!',
        cancelButtonText:  'Batal',
        confirmButtonColor: '#dc2626',
    });
    if (!r.isConfirmed) return;

    const d = await postJson({ action: 'reset_ujian', ujian_siswa_id: ujianSiswaId });
    if (d.success) {
        Swal.fire({ icon:'success', title:'Berhasil', text: d.message, timer:1200, showConfirmButton:false });
        await refreshMonitoring();
    } else {
        Swal.fire({ icon:'error', title:'Gagal', text: d.message });
    }
}

async function resetMultiple() {
    const ids = getMonitorCheckedIds();
    if (!ids.length) return;
    const r = await Swal.fire({
        title: 'Reset Ujian?',
        html: `Reset <strong>${ids.length}</strong> ujian siswa yang dipilih?<br>
               <span class="text-sm text-gray-500">Semua jawaban siswa akan terhapus!</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Reset!',
        cancelButtonText:  'Batal',
        confirmButtonColor: '#dc2626',
    });
    if (!r.isConfirmed) return;

    const d = await postJson({ action: 'reset_multiple', ids: JSON.stringify(ids) });
    if (d.success) {
        Swal.fire({ icon:'success', title:'Berhasil', text: d.message, timer:1500, showConfirmButton:false });
        await refreshMonitoring();
    } else {
        Swal.fire({ icon:'error', title:'Gagal', text: d.message });
    }
}

// ── Modal: Tambah Waktu ────────────────────────────────────────────────────────
let tambahWaktuTarget = null; // { type:'single', id } | { type:'multiple', ids:[] }

function openTambahWaktuSingle(ujianSiswaId, nama) {
    tambahWaktuTarget = { type: 'single', id: ujianSiswaId };
    document.getElementById('tambah-waktu-desc').textContent =
        `Tambah waktu untuk: ${nama}`;
    document.getElementById('tambah-waktu-input').value = 5;
    document.getElementById('tambah-waktu-modal').classList.remove('hidden');
}

function openTambahWaktuMultiple() {
    const ids = getMonitorCheckedIds();
    if (!ids.length) { Swal.fire({ icon:'info', title:'Info', text:'Pilih siswa terlebih dahulu' }); return; }
    tambahWaktuTarget = { type: 'multiple', ids };
    document.getElementById('tambah-waktu-desc').textContent =
        `Tambah waktu untuk ${ids.length} siswa yang dipilih.`;
    document.getElementById('tambah-waktu-input').value = 5;
    document.getElementById('tambah-waktu-modal').classList.remove('hidden');
}

function closeTambahWaktuModal() {
    tambahWaktuTarget = null;
    document.getElementById('tambah-waktu-modal').classList.add('hidden');
}

async function saveTambahWaktu() {
    const menit = parseInt(document.getElementById('tambah-waktu-input').value);
    if (!menit || menit <= 0) {
        Swal.fire({ icon:'warning', title:'Perhatian', text:'Masukkan jumlah menit yang valid' });
        return;
    }
    if (!tambahWaktuTarget) return;

    const btn = document.getElementById('btn-save-waktu');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Menyimpan…';

    try {
        let params;
        if (tambahWaktuTarget.type === 'single') {
            params = { action: 'tambah_waktu', ujian_siswa_id: tambahWaktuTarget.id, menit };
        } else {
            params = { action: 'tambah_waktu_multiple', ids: JSON.stringify(tambahWaktuTarget.ids), menit };
        }

        const d = await postJson(params);
        if (d.success) {
            closeTambahWaktuModal();
            Swal.fire({ icon:'success', title:'Berhasil', text: d.message,
                        timer:1500, showConfirmButton:false });
            await refreshMonitoring();
        } else {
            Swal.fire({ icon:'error', title:'Gagal', text: d.message });
        }
    } catch {
        Swal.fire({ icon:'error', title:'Error', text:'Terjadi kesalahan koneksi' });
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-check"></i> Simpan';
    }
}

// ── CSV Exports ────────────────────────────────────────────────────────────────
function exportNilai() {
    if (!currentMonitorRuangId) return;
    window.location.href = `?action=export_nilai&ruang_id=${currentMonitorRuangId}`;
}

function exportAnalisis() {
    if (!currentMonitorRuangId) return;
    window.location.href = `?action=export_analisis&ruang_id=${currentMonitorRuangId}`;
}

// ── Init ───────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    renderPagination();
});
</script>
</body>
</html>
