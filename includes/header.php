<?php
$appName = getenv('APP_NAME') ?: 'CBT MTsN 1 Mesuji';
$userName = $_SESSION['admin_nama'] ?? $_SESSION['guru_nama'] ?? $_SESSION['siswa_nama'] ?? 'User';
$userRole = isset($_SESSION['admin_id']) ? 'Administrator' : (isset($_SESSION['guru_id']) ? 'Guru' : 'Siswa');
?>
<header class="fixed top-0 left-64 right-0 h-16 bg-white shadow-sm z-30 flex items-center justify-between px-6">
  <button onclick="toggleSidebar()" class="text-gray-500 hover:text-gray-700 focus:outline-none">
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
    </svg>
  </button>
  <div class="flex items-center gap-4">
    <div class="text-right">
      <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($userName) ?></p>
      <p class="text-xs text-blue-600"><?= htmlspecialchars($userRole) ?></p>
    </div>
    <button onclick="confirmLogout()" class="flex items-center gap-1 bg-red-50 hover:bg-red-100 text-red-600 px-3 py-1.5 rounded-lg transition text-sm font-medium">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
      </svg>
      Logout
    </button>
  </div>
</header>
<script>
function confirmLogout() {
  Swal.fire({
    title: 'Konfirmasi Logout',
    text: 'Apakah Anda yakin ingin keluar?',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Ya, Logout',
    cancelButtonText: 'Batal',
    confirmButtonColor: '#dc2626'
  }).then(result => { if (result.isConfirmed) window.location.href = '../logout.php'; });
}
function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  sidebar.classList.toggle('-translate-x-full');
}
</script>
