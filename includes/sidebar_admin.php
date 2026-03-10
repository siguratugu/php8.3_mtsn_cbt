<?php
$currentPage = basename($_SERVER['PHP_SELF']);

function isActive(string $page): string {
    global $currentPage;
    return $currentPage === $page ? 'bg-blue-700 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white';
}
?>
<aside id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-gray-900 z-40 transition-transform duration-300 flex flex-col">
  <div class="p-4 border-b border-gray-700">
    <div class="flex items-center gap-3">
      <img src="https://e-learning.mtsn1mesuji.sch.id/__statics/img/logo.png" alt="Logo" class="w-10 h-10 rounded-full" onerror="this.style.display='none'">
      <div>
        <p class="text-white font-bold text-sm">CBT MTsN 1 Mesuji</p>
        <p class="text-gray-400 text-xs">Administrator</p>
      </div>
    </div>
  </div>
  <nav class="flex-1 overflow-y-auto py-4 px-3">
    <a href="../admin/dashboard.php" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition <?= isActive('dashboard.php') ?>">
      <i class="fa fa-tachometer-alt w-4 text-center"></i> Dashboard
    </a>

    <p class="text-gray-500 text-xs font-semibold uppercase px-3 py-2 mt-2">Master Data</p>
    <a href="../admin/kelas.php" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition <?= isActive('kelas.php') ?>">
      <i class="fa fa-school w-4 text-center"></i> Menu Kelas
    </a>
    <a href="../admin/mapel.php" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition <?= isActive('mapel.php') ?>">
      <i class="fa fa-book w-4 text-center"></i> Menu Mapel
    </a>
    <a href="../admin/relasi.php" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition <?= isActive('relasi.php') ?>">
      <i class="fa fa-link w-4 text-center"></i> Menu Relasi
    </a>
    <a href="../admin/guru.php" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition <?= isActive('guru.php') ?>">
      <i class="fa fa-chalkboard-teacher w-4 text-center"></i> Data Guru
    </a>
    <a href="../admin/siswa.php" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition <?= isActive('siswa.php') ?>">
      <i class="fa fa-user-graduate w-4 text-center"></i> Data Siswa
    </a>

    <p class="text-gray-500 text-xs font-semibold uppercase px-3 py-2 mt-2">Ujian CBT</p>
    <a href="../admin/bank_soal.php" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition <?= isActive('bank_soal.php') ?>">
      <i class="fa fa-clipboard-list w-4 text-center"></i> Bank Soal
    </a>
    <a href="../admin/ruang_ujian.php" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition <?= isActive('ruang_ujian.php') ?>">
      <i class="fa fa-door-open w-4 text-center"></i> Ruang Ujian
    </a>
    <a href="../admin/exambrowser.php" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition <?= isActive('exambrowser.php') ?>">
      <i class="fa fa-shield-alt w-4 text-center"></i> Exambrowser
    </a>

    <p class="text-gray-500 text-xs font-semibold uppercase px-3 py-2 mt-2">Pengaturan</p>
    <a href="../admin/administrator.php" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition <?= isActive('administrator.php') ?>">
      <i class="fa fa-cog w-4 text-center"></i> Administrator
    </a>
    <a href="../admin/pengumuman.php" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition <?= isActive('pengumuman.php') ?>">
      <i class="fa fa-bullhorn w-4 text-center"></i> Pengumuman
    </a>
    <a href="#" onclick="confirmLogout()" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition text-red-400 hover:bg-red-900 hover:text-red-300">
      <i class="fa fa-sign-out-alt w-4 text-center"></i> Logout
    </a>
  </nav>
</aside>
