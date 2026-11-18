<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once '../config/koneksi.php';

if (!isset($_SESSION['id_peminjam'])) {
  header("Location: ../auth/login.php");
  exit;
}

$id_peminjam = $_SESSION['id_peminjam'];
$nama_user = $_SESSION['nama'];
$inisial = strtoupper(substr($nama_user, 0, 1));

// ==========================================
// 1. TENTUKAN ID PEMINJAMAN
// ==========================================
$id_peminjaman = isset($_GET['id']) ? (int) $_GET['id'] : 0;

try {
  if ($id_peminjaman === 0) {
    // Cari otomatis peminjaman yang belum ada laporannya
    $stmt_cari = $pdo->prepare("
            SELECT p.id_peminjaman 
            FROM peminjaman p
            LEFT JOIN laporan_kondisi lk ON p.id_peminjaman = lk.id_peminjaman
            WHERE p.id_peminjam = :id 
              AND p.status_peminjaman = 'Selesai' 
              AND lk.id_laporankondisi IS NULL
            ORDER BY p.waktu_kembali DESC LIMIT 1
        ");
    $stmt_cari->execute([':id' => $id_peminjam]);
    $id_peminjaman = $stmt_cari->fetchColumn();
  }

  if (!$id_peminjaman) {
    // Jika tidak ada yang perlu dilaporkan, kembalikan ke riwayat/peminjaman
    echo "<script>alert('Tidak ada peminjaman yang perlu dilaporkan.'); window.location.href='peminjaman.php';</script>";
    exit;
  }

  // Ambil ID Petugas (untuk syarat insert)
  $stmt_ptg = $pdo->prepare("SELECT id_petugas FROM peminjaman WHERE id_peminjaman = :id");
  $stmt_ptg->execute([':id' => $id_peminjaman]);
  $id_petugas = $stmt_ptg->fetchColumn();

  if (!$id_petugas) {
    $id_petugas = $pdo->query("SELECT id_petugas FROM petugas LIMIT 1")->fetchColumn();
  }
  if (!$id_petugas) {
    $id_petugas = 1;
  }

} catch (PDOException $e) {
  die("Error: " . $e->getMessage());
}

// ==========================================
// 2. PROSES KIRIM LAPORAN (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nilai_ban = (int) $_POST['nilai_ban'];
  $nilai_rem = (int) $_POST['nilai_rem'];
  $nilai_rantai = (int) $_POST['nilai_rantai'];
  $nilai_rangka_bodi = (int) $_POST['nilai_rangka_bodi'];
  $keterangan = trim($_POST['keterangan']);
  $tgl_lapor = date('Y-m-d');

  try {
    $sql = "INSERT INTO laporan_kondisi 
                (id_peminjaman, id_petugas, nilai_ban, nilai_rem, nilai_rantai, nilai_rangka_bodi, keterangan, tgl_lapor) 
                VALUES (:id_peminjaman, :id_petugas, :ban, :rem, :rantai, :rangka, :keterangan, :tgl)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':id_peminjaman' => $id_peminjaman,
      ':id_petugas' => $id_petugas,
      ':ban' => $nilai_ban,
      ':rem' => $nilai_rem,
      ':rantai' => $nilai_rantai,
      ':rangka' => $nilai_rangka_bodi,
      ':keterangan' => $keterangan,
      ':tgl' => $tgl_lapor
    ]);

    // Jika sukses, arahkan ke halaman selesai
    echo "<script>alert('Laporan kondisi berhasil dikirim! Terima kasih.'); window.location.href='selesai.php';</script>";
    exit;
  } catch (PDOException $e) {
    $error = "Gagal mengirim laporan: " . $e->getMessage();
  }
}
?>

<!doctype html>
<html lang="id">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Laporan kondisi · GOWES</title>
    <link rel="icon" href="../img/logo.png" />
    
    <link rel="stylesheet" href="../assets/css/fonts.css" />
    <link rel="stylesheet" href="../assets/css/tokens.css" />
    <link rel="stylesheet" href="../assets/css/base.css" />
    <link rel="stylesheet" href="../assets/css/components.css" />
    <link rel="stylesheet" href="../assets/css/mahasiswa.css" />
    
    <style>
        /* Menghilangkan menu navbar agar user fokus mengisi laporan */
        .topnav, .user-pill-btn {
            display: none !important; 
        }
        .warning-box {
            background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
            padding: 12px 16px; border-radius: 12px; font-size: 13px;
            margin-bottom: 20px; font-weight: 500; line-height: 1.5;
        }
        .required-star { color: #ef4444; font-weight: bold; margin-left: 4px; }
    </style>
  </head>

  <body class="user">
    <!-- Topbar disederhanakan, tanpa menu navigasi -->
    <header class="topbar" style="justify-content: center;">
      <a class="brand" href="javascript:void(0);" style="pointer-events: none;">
        <img src="../img/logo.png" alt="GOWES" />
      </a>
    </header>

    <main class="page no-tab">
      <div class="col">
        <h1 class="title" style="text-align: center;">
          <span class="m">Laporan kondisi</span>
          <span class="d">Laporan Kondisi Go-bike</span>
        </h1>
        
        <div class="warning-box">
            ⚠️ <strong>Perhatian:</strong> Anda harus menyelesaikan dan mengisi <b>semua</b> bagian laporan ini sebelum dapat mengakses menu lain.
        </div>

        <p class="lead">
          Beri nilai tiap bagian: 1 = sangat rusak, 5 = sangat baik.
        </p>

        <?php if (isset($error)): ?>
              <div style="background:#ffebee; color:#c62828; padding:10px; border-radius:8px; margin-bottom:15px;">
                  <?= htmlspecialchars($error) ?>
              </div>
        <?php endif; ?>

        <form method="POST" action="" id="formLaporan" style="display: flex; flex-direction: column; gap: 10px">
          <input type="hidden" name="id_peminjaman" value="<?= $id_peminjaman ?>">
          
          <!-- HIDDEN INPUT DIKOSONGKAN AGAR USER WAJIB KLIK -->
          <input type="hidden" name="nilai_ban" id="input-ban" value="">
          <input type="hidden" name="nilai_rem" id="input-rem" value="">
          <input type="hidden" name="nilai_rantai" id="input-rantai" value="">
          <input type="hidden" name="nilai_rangka_bodi" id="input-rangka" value="">

          <!-- BAN -->
          <section class="rate" data-rating="ban">
            <div class="rate-title">Ban <span class="required-star">*</span></div>
            <div class="scale">
              <button type="button" data-nilai="1" aria-label="Ban: nilai 1">1</button>
              <button type="button" data-nilai="2" aria-label="Ban: nilai 2">2</button>
              <button type="button" data-nilai="3" aria-label="Ban: nilai 3">3</button>
              <button type="button" data-nilai="4" aria-label="Ban: nilai 4">4</button>
              <button type="button" data-nilai="5" aria-label="Ban: nilai 5">5</button>
            </div>
            <span class="cap l">Sangat rusak</span>
            <span class="cap r">Sangat baik</span>
          </section>

          <!-- REM -->
          <section class="rate" data-rating="rem">
            <div class="rate-title">Rem <span class="required-star">*</span></div>
            <div class="scale">
              <button type="button" data-nilai="1" aria-label="Rem: nilai 1">1</button>
              <button type="button" data-nilai="2" aria-label="Rem: nilai 2">2</button>
              <button type="button" data-nilai="3" aria-label="Rem: nilai 3">3</button>
              <button type="button" data-nilai="4" aria-label="Rem: nilai 4">4</button>
              <button type="button" data-nilai="5" aria-label="Rem: nilai 5">5</button>
            </div>
            <span class="cap l">Sangat rusak</span>
            <span class="cap r">Sangat baik</span>
          </section>

          <!-- RANTAI -->
          <section class="rate" data-rating="rantai">
            <div class="rate-title">Rantai <span class="required-star">*</span></div>
            <div class="scale">
              <button type="button" data-nilai="1" aria-label="Rantai: nilai 1">1</button>
              <button type="button" data-nilai="2" aria-label="Rantai: nilai 2">2</button>
              <button type="button" data-nilai="3" aria-label="Rantai: nilai 3">3</button>
              <button type="button" data-nilai="4" aria-label="Rantai: nilai 4">4</button>
              <button type="button" data-nilai="5" aria-label="Rantai: nilai 5">5</button>
            </div>
            <span class="cap l">Sangat rusak</span>
            <span class="cap r">Sangat baik</span>
          </section>

          <!-- RANGKA DAN BODI -->
          <section class="rate" data-rating="rangka">
            <div class="rate-title">Rangka dan bodi <span class="required-star">*</span></div>
            <div class="scale">
              <button type="button" data-nilai="1" aria-label="Rangka dan bodi: nilai 1">1</button>
              <button type="button" data-nilai="2" aria-label="Rangka dan bodi: nilai 2">2</button>
              <button type="button" data-nilai="3" aria-label="Rangka dan bodi: nilai 3">3</button>
              <button type="button" data-nilai="4" aria-label="Rangka dan bodi: nilai 4">4</button>
              <button type="button" data-nilai="5" aria-label="Rangka dan bodi: nilai 5">5</button>
            </div>
            <span class="cap l">Sangat rusak</span>
            <span class="cap r">Sangat baik</span>
          </section>

          <!-- KETERANGAN WAJIB DIISI -->
          <div style="position: relative;">
            <textarea
              class="textarea"
              name="keterangan"
              placeholder="Tuliskan keterangan kondisi atau kerusakan (Wajib diisi)"
              required
            ></textarea>
            <span class="required-star" style="position: absolute; top: 10px; right: 15px;">*</span>
          </div>
          
          <button class="btn btn-primary" type="submit" style="margin-top: 6px">
            Kirim Laporan
          </button>
        </form>
      </div>
    </main>

    <script src="../assets/js/common.js"></script>
    
    <script>
        // 1. SCRIPT INTERAKSI TOMBOL RATING
        document.addEventListener("DOMContentLoaded", function() {
            const rateSections = document.querySelectorAll('.rate');
            
            rateSections.forEach(section => {
                const buttons = section.querySelectorAll('.scale button');
                const type = section.getAttribute('data-rating'); 
                const hiddenInput = document.getElementById('input-' + type);

                buttons.forEach(btn => {
                    btn.addEventListener('click', function() {
                        buttons.forEach(b => b.classList.remove('on'));
                        this.classList.add('on');
                        
                        if (hiddenInput) {
                            hiddenInput.value = this.getAttribute('data-nilai');
                        }
                    });
                });
            });
        });

        // 2. SCRIPT VALIDASI & MENCEGAH KELUAR HALAMAN
        let isSubmitting = false;

        document.getElementById('formLaporan').addEventListener('submit', function(e) {
            let isValid = true;
            let missingFields = [];
            
            // Cek apakah semua rating sudah dipilih
            const ratings = [
                { id: 'input-ban', label: 'Ban' },
                { id: 'input-rem', label: 'Rem' },
                { id: 'input-rantai', label: 'Rantai' },
                { id: 'input-rangka', label: 'Rangka dan bodi' }
            ];

            ratings.forEach(item => {
                const inputVal = document.getElementById(item.id).value;
                if (!inputVal || inputVal === '') {
                    isValid = false;
                    missingFields.push(item.label);
                }
            });

            // Cek apakah textarea sudah diisi
            const textarea = document.querySelector('textarea[name="keterangan"]');
            if (textarea.value.trim() === '') {
                isValid = false;
                missingFields.push('Keterangan');
            }

            // Jika ada yang kosong, cegah form terkirim
            if (!isValid) {
                e.preventDefault(); // Tahan pengiriman form
                alert('Harap lengkapi bagian berikut sebelum mengirim:\n\n- ' + missingFields.join('\n- '));
                return false;
            }

            // Jika lolos validasi, izinkan form terkirim
            isSubmitting = true;
        });

        // Peringatan jika mencoba keluar/refresh/back
        window.addEventListener('beforeunload', function (e) {
            if (!isSubmitting) {
                var confirmationMessage = 'Anda harus menyelesaikan dan mengirim laporan ini terlebih dahulu!';
                e.returnValue = confirmationMessage; 
                return confirmationMessage;
            }
        });
    </script>
  </body>
</html>