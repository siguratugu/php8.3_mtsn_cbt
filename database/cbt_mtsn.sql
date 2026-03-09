SET NAMES utf8mb4;
SET time_zone = '+07:00';

CREATE DATABASE IF NOT EXISTS `cbt_mtsn` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `cbt_mtsn`;

CREATE TABLE IF NOT EXISTS `admin` (
  `id` VARCHAR(10) PRIMARY KEY,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `nama` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `admin` VALUES ('A1','admin@mtsn1mesuji.sch.id','Admin Utama','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW());

CREATE TABLE IF NOT EXISTS `guru` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nama` VARCHAR(100) NOT NULL,
  `nik` VARCHAR(16) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kelas` (
  `id` VARCHAR(10) PRIMARY KEY,
  `nama_kelas` VARCHAR(50) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mapel` (
  `id` VARCHAR(10) PRIMARY KEY,
  `nama_mapel` VARCHAR(100) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `siswa` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nama` VARCHAR(100) NOT NULL,
  `nisn` VARCHAR(10) NOT NULL UNIQUE,
  `kelas_id` VARCHAR(10) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`kelas_id`) REFERENCES `kelas`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `relasi_guru` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `guru_id` INT NOT NULL,
  `kelas_id` VARCHAR(10) NOT NULL,
  `mapel_id` VARCHAR(10) NOT NULL,
  UNIQUE KEY `uq_relasi` (`guru_id`,`kelas_id`,`mapel_id`),
  FOREIGN KEY (`guru_id`) REFERENCES `guru`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`kelas_id`) REFERENCES `kelas`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`mapel_id`) REFERENCES `mapel`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bank_soal` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `guru_id` INT DEFAULT NULL,
  `admin_id` VARCHAR(10) DEFAULT NULL,
  `mapel_id` VARCHAR(10) DEFAULT NULL,
  `nama_soal` VARCHAR(200) NOT NULL,
  `waktu_mengerjakan` INT NOT NULL DEFAULT 60,
  `bobot_pg` DECIMAL(5,2) DEFAULT 0,
  `bobot_essai` DECIMAL(5,2) DEFAULT 0,
  `bobot_menjodohkan` DECIMAL(5,2) DEFAULT 0,
  `bobot_benar_salah` DECIMAL(5,2) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`guru_id`) REFERENCES `guru`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`mapel_id`) REFERENCES `mapel`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `soal` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `bank_soal_id` INT NOT NULL,
  `jenis_soal` ENUM('pg','essai','menjodohkan','benar_salah') NOT NULL DEFAULT 'pg',
  `pertanyaan` LONGTEXT NOT NULL,
  `urutan` INT DEFAULT 1,
  FOREIGN KEY (`bank_soal_id`) REFERENCES `bank_soal`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `opsi_jawaban` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `soal_id` INT NOT NULL,
  `kode_opsi` VARCHAR(10) NOT NULL,
  `isi_opsi` LONGTEXT,
  FOREIGN KEY (`soal_id`) REFERENCES `soal`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kunci_jawaban` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `soal_id` INT NOT NULL UNIQUE,
  `jawaban` TEXT NOT NULL,
  FOREIGN KEY (`soal_id`) REFERENCES `soal`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ruang_ujian` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nama_ruang` VARCHAR(200) NOT NULL,
  `token` VARCHAR(10) NOT NULL UNIQUE,
  `guru_id` INT DEFAULT NULL,
  `admin_id` VARCHAR(10) DEFAULT NULL,
  `bank_soal_id` INT NOT NULL,
  `waktu_hentikan` INT NOT NULL DEFAULT 30,
  `batas_keluar` INT NOT NULL DEFAULT 3,
  `tanggal_mulai` DATETIME NOT NULL,
  `tanggal_selesai` DATETIME NOT NULL,
  `acak_soal` TINYINT(1) DEFAULT 0,
  `acak_jawaban` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`guru_id`) REFERENCES `guru`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`bank_soal_id`) REFERENCES `bank_soal`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ruang_ujian_kelas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ruang_ujian_id` INT NOT NULL,
  `kelas_id` VARCHAR(10) NOT NULL,
  FOREIGN KEY (`ruang_ujian_id`) REFERENCES `ruang_ujian`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`kelas_id`) REFERENCES `kelas`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ujian_siswa` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ruang_ujian_id` INT NOT NULL,
  `siswa_id` INT NOT NULL,
  `status` ENUM('belum','mengerjakan','selesai') DEFAULT 'belum',
  `waktu_mulai` DATETIME DEFAULT NULL,
  `waktu_selesai` DATETIME DEFAULT NULL,
  `waktu_tambahan` INT DEFAULT 0,
  `jumlah_benar` INT DEFAULT 0,
  `jumlah_salah` INT DEFAULT 0,
  `nilai` DECIMAL(5,2) DEFAULT 0,
  `jumlah_keluar` INT DEFAULT 0,
  UNIQUE KEY `uq_ujian` (`ruang_ujian_id`,`siswa_id`),
  FOREIGN KEY (`ruang_ujian_id`) REFERENCES `ruang_ujian`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`siswa_id`) REFERENCES `siswa`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `jawaban_siswa` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ujian_siswa_id` INT NOT NULL,
  `soal_id` INT NOT NULL,
  `jawaban` TEXT DEFAULT NULL,
  `is_benar` TINYINT(1) DEFAULT 0,
  UNIQUE KEY `uq_jawaban` (`ujian_siswa_id`,`soal_id`),
  FOREIGN KEY (`ujian_siswa_id`) REFERENCES `ujian_siswa`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`soal_id`) REFERENCES `soal`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `pengumuman` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `isi` LONGTEXT NOT NULL,
  `created_by` VARCHAR(10) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `pengumuman_kelas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pengumuman_id` INT NOT NULL,
  `kelas_id` VARCHAR(10) NOT NULL,
  FOREIGN KEY (`pengumuman_id`) REFERENCES `pengumuman`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`kelas_id`) REFERENCES `kelas`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `key_name` VARCHAR(50) NOT NULL UNIQUE,
  `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings` (`key_name`,`value`) VALUES ('exambrowser_mode','0');
