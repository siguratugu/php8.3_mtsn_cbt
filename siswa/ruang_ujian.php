<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['siswa_id'])) { header('Location: ../login.php'); exit; }
$sid = (int)$_SESSION['siswa_id'];
$kelas_id = $_SESSION['siswa_kelas_id'] ?? null;

// Handle token validation AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) { echo json_encode(['success'=>false,'message'=>'CSRF tidak valid']); exit; }

    if ($action === 'validate_token') {
        $token = strtoupper(trim($_POST['token'] ?? ''));
        $ruangId = (int)($_POST['ruang_id'] ?? 0);
        $stmt = db()->prepare("SELECT id, nama_ruang, token FROM ruang_ujian WHERE id=? AND token=? AND tanggal_mulai<=NOW() AND tanggal_selesai>=NOW()");
        $stmt->execute([$ruangId, $token]);
        $ruang = $stmt->fetch();
        if (!$ruang) { echo json_encode(['success'=>false,'message'=>'Token tidak valid atau ujian sudah berakhir']); exit; }
        // Check student's kelas is in ruang
        $stmtK = db()->prepare("SELECT id FROM ruang_ujian_kelas WHERE ruang_ujian_id=? AND kelas_id=?");
        $stmtK->execute([$ruangId, $kelas_id]);
        if (!$stmtK->fetch()) { echo json_encode(['success'=>false,'message'=>'Kelas Anda tidak terdaftar di ruang ujian ini']); exit; }
        // Create ujian_siswa if not exists
        $stmtU = db()->prepare("INSERT IGNORE INTO ujian_siswa (ruang_ujian_id,siswa_id,status) VALUES (?,?,\'belum\')");
        $stmtU->execute([$ruangId, $sid]);
        echo json_encode(['success'=>true,'ruang_id'=>$ruangId,'nama_ruang'=>$ruang['nama_ruang']]);
        exit;
    }
    echo json_encode(['success'=>false,'message'=>'Aksi tidak valid']); exit;
}

// Get all rooms accessible to this student
$stmt = db()->prepare("
    SELECT r.id, r.nama_ruang, r.token, r.tanggal_mulai, r.tanggal_selesai,
           m.nama_mapel, b.nama_soal,
           COALESCE(us.status,'belum') as status,
           COALESCE(us.jumlah_benar,0) as jumlah_benar,
           COALESCE(us.jumlah_salah,0) as jumlah_salah,
           COALESCE(us.nilai,0) as nilai
    FROM ruang_ujian r
    JOIN ruang_ujian_kelas rk ON r.id=rk.ruang_ujian_id
    JOIN bank_soal b ON r.bank_soal_id=b.id
    LEFT JOIN mapel m ON b.mapel_id=m.id
    LEFT JOIN ujian_siswa us ON r.id=us.ruang_ujian_id AND us.siswa_id=?
    WHERE rk.kelas_id=?
    GROUP BY r.id
    ORDER BY r.tanggal_mulai DESC
");
$stmt->execute([$sid, $kelas_id]);
$ruangList = $stmt->fetchAll();
$csrfToken = generateCsrfToken();
$appName = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
$now = new DateTime();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ruang Ujian - <?= htmlspecialchars($appName) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100">
<?php include __DIR__ . '/../includes/sidebar_siswa.php'; ?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="ml-64 pt-16 pb-10 px-6">
  <div class="mt-6">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Ruang Ujian</h1>
    <div class="bg-white rounded-xl shadow overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
              <th class="px-4 py-3">No</th>
              <th class="px-4 py-3">Nama Ruang Ujian</th>
              <th class="px-4 py-3">Mapel</th>
              <th class="px-4 py-3 text-center">Status</th>
              <th class="px-4 py-3 text-center">Benar/Salah</th>
              <th class="px-4 py-3 text-center">Nilai</th>
              <th class="px-4 py-3 text-center">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ruangList as $i => $r): ?>
            <?php
              $mulai = new DateTime($r['tanggal_mulai']);
              $selesai = new DateTime($r['tanggal_selesai']);
              $beforeStart = $now < $mulai;
              $afterEnd = $now > $selesai;
              $status = $r['status'];
            ?>
            <tr class="border-t hover:bg-gray-50">
              <td class="px-4 py-3"><?= $i+1 ?></td>
              <td class="px-4 py-3">
                <p class="font-medium"><?= htmlspecialchars($r['nama_ruang']) ?></p>
                <p class="text-xs text-gray-400"><?= htmlspecialchars($r['nama_soal'] ?? '') ?></p>
                <p class="text-xs text-gray-400"><?= $mulai->format('d/m/Y H:i') ?> - <?= $selesai->format('d/m/Y H:i') ?></p>
              </td>
              <td class="px-4 py-3"><?= htmlspecialchars($r['nama_mapel'] ?? '-') ?></td>
              <td class="px-4 py-3 text-center">
                <?php if ($beforeStart): ?>
                  <span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs">Belum Mulai</span>
                <?php elseif ($afterEnd && $status !== 'selesai'): ?>
                  <span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs">Sudah Berakhir</span>
                <?php elseif ($status === 'selesai'): ?>
                  <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs font-semibold">Selesai</span>
                <?php elseif ($status === 'mengerjakan'): ?>
                  <span class="bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full text-xs font-semibold">Mengerjakan</span>
                <?php else: ?>
                  <span class="bg-red-100 text-red-700 px-2 py-0.5 rounded-full text-xs font-semibold">Belum</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-center text-sm">
                <?php if ($status === 'selesai'): ?>
                <span class="text-green-600 font-medium"><?= $r['jumlah_benar'] ?></span> / <span class="text-red-600"><?= $r['jumlah_salah'] ?></span>
                <?php else: ?>-<?php endif; ?>
              </td>
              <td class="px-4 py-3 text-center">
                <?php if ($status === 'selesai'): ?>
                <span class="text-2xl font-bold text-blue-600"><?= number_format($r['nilai'],2) ?></span>
                <?php else: ?>-<?php endif; ?>
              </td>
              <td class="px-4 py-3 text-center">
                <?php if ($status === 'selesai'): ?>
                  <button disabled class="bg-gray-300 text-gray-500 px-3 py-1 rounded text-xs cursor-not-allowed">Selesai</button>
                <?php elseif ($status === 'mengerjakan'): ?>
                  <a href="ujian.php?ruang_id=<?= $r['id'] ?>" class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-1 rounded text-xs font-medium">Lanjutkan</a>
                <?php elseif (!$beforeStart && !$afterEnd): ?>
                  <button onclick="mulaiUjian(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['nama_ruang'])) ?>')" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-xs font-medium">Mulai Ujian</button>
                <?php else: ?>
                  <button disabled class="bg-gray-200 text-gray-400 px-3 py-1 rounded text-xs cursor-not-allowed">-</button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($ruangList)): ?>
            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Tidak ada ruang ujian tersedia</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

<!-- Token Modal -->
<div id="token-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4">
    <h3 class="text-lg font-bold text-gray-800 mb-1">Masukkan Token Ujian</h3>
    <p class="text-gray-500 text-sm mb-4" id="modal-ruang-nama"></p>
    <input type="hidden" id="modal-ruang-id">
    <div class="mb-4">
      <label class="block text-sm font-medium text-gray-700 mb-1">Token Ujian</label>
      <input type="text" id="token-input" maxlength="10" class="w-full border rounded-lg px-4 py-2.5 text-center uppercase font-mono tracking-widest text-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="XXXXXX">
    </div>
    <div class="flex gap-3">
      <button onclick="document.getElementById('token-modal').classList.add('hidden')" class="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg hover:bg-gray-50">Batal</button>
      <button onclick="validateToken()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-lg font-semibold">Masuk</button>
    </div>
  </div>
</div>

<script>
const csrf = '<?= $csrfToken ?>';
function mulaiUjian(ruangId, namaRuang) {
  document.getElementById('modal-ruang-id').value = ruangId;
  document.getElementById('modal-ruang-nama').textContent = namaRuang;
  document.getElementById('token-input').value = '';
  document.getElementById('token-modal').classList.remove('hidden');
}
function validateToken() {
  const ruangId = document.getElementById('modal-ruang-id').value;
  const token = document.getElementById('token-input').value.trim();
  if (!token) { Swal.fire('Error','Token wajib diisi','error'); return; }
  const fd = new FormData();
  fd.append('csrf_token',csrf); fd.append('action','validate_token');
  fd.append('ruang_id',ruangId); fd.append('token',token);
  fetch('ruang_ujian.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
    if (data.success) {
      document.getElementById('token-modal').classList.add('hidden');
      Swal.fire({title:'Siap Mengerjakan?',text:'Apakah kamu sudah siap mengerjakan ujian? Jika siap klik tombol Kerjakan',icon:'question',showCancelButton:true,confirmButtonText:'Kerjakan',cancelButtonText:'Batal',confirmButtonColor:'#16a34a'})
      .then(res=>{ if(res.isConfirmed) window.location.href='ujian.php?ruang_id='+data.ruang_id; });
    } else Swal.fire('Gagal',data.message,'error');
  });
}
</script>
</body>
</html>
