<?php
$currentPage = basename($_SERVER['PHP_SELF']);

function isActiveSiswa(string $page): string {
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
        <p class="text-gray-400 text-xs">Siswa</p>
      </div>
    </div>
  </div>
  <nav class="flex-1 overflow-y-auto py-4 px-3">
    <a href="../siswa/dashboard.php" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition <?= isActiveSiswa('dashboard.php') ?>">
      <i class="fa fa-tachometer-alt w-4 text-center"></i> Dashboard
    </a>
    <a href="../siswa/ruang_ujian.php" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition <?= isActiveSiswa('ruang_ujian.php') ?>">
      <i class="fa fa-door-open w-4 text-center"></i> Ruang Ujian
    </a>
    <a href="#" onclick="confirmLogout()" class="flex items-center gap-3 px-3 py-2 rounded-lg mb-1 text-sm transition text-red-400 hover:bg-red-900 hover:text-red-300">
      <i class="fa fa-sign-out-alt w-4 text-center"></i> Logout
    </a>
  </nav>
</aside>
