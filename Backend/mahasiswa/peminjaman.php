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
// AUTO REDIRECT: WAJIB LAPORAN KONDISI
// ==========================================
// Cek apakah ada peminjaman berstatus 'Selesai' yang belum dilaporkan
$stmt_wajib_lapor = $pdo->prepare("
    SELECT p.id_peminjaman 
    FROM peminjaman p
    LEFT JOIN laporan_kondisi lk ON p.id_peminjaman = lk.id_peminjaman
    WHERE p.id_peminjam = :id_peminjam 
      AND p.status_peminjaman = 'Selesai' 
      AND lk.id_laporankondisi IS NULL
    ORDER BY p.waktu_kembali DESC LIMIT 1
");
$stmt_wajib_lapor->execute([':id_peminjam' => $id_peminjam]);
$id_wajib_lapor = $stmt_wajib_lapor->fetchColumn();

if ($id_wajib_lapor) {
    // Jika ada yang belum dilaporkan, paksa pindah ke halaman laporan
    header("Location: laporan.php?id=" . $id_wajib_lapor);
    exit;
}
// ==========================================

// ==========================================
// 1. PROSES PENGAJUAN PENGEMBALIAN (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return') {
    try {
        // Hanya ubah status jadi 'Menunggu Kembali', jangan set waktu_kembali dulu
        $stmt_update_pinjam = $pdo->prepare("UPDATE peminjaman SET status_peminjaman = 'Menunggu Kembali' WHERE id_peminjam = :id AND waktu_kembali IS NULL AND status_peminjaman = 'Dipinjam'");
        $stmt_update_pinjam->execute([':id' => $id_peminjam]);

        header("Location: peminjaman.php");
        exit;

    } catch (PDOException $e) {
        die("Gagal memproses pengajuan: " . $e->getMessage());
    }
}

// ==========================================
// 2. AMBIL DATA PEMINJAMAN AKTIF
// ==========================================
$peminjaman = null;
$status_pinjam = '';
$waktu_mulai = '';
$batas_kembali = '';
$batas_kembali_iso = '';
$is_terlambat = false;

try {
    $sql = "SELECT p.id_peminjaman, sp.kode_qr, sh.nama_shelter, p.waktu_mulai, p.batas_kembali, p.status_peminjaman 
            FROM peminjaman p
            JOIN sepeda sp ON p.id_sepeda = sp.id_sepeda
            JOIN shelter sh ON sp.id_shelter = sh.id_shelter
            WHERE p.id_peminjam = :id_peminjam AND p.waktu_kembali IS NULL
            ORDER BY p.waktu_mulai DESC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_peminjam' => $id_peminjam]);
    $peminjaman = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($peminjaman) {
        $status_pinjam = isset($peminjaman['status_peminjaman']) && $peminjaman['status_peminjaman'] !== ''
            ? $peminjaman['status_peminjaman']
            : 'Menunggu';

        $waktu_mulai = date('H.i', strtotime($peminjaman['waktu_mulai']));
        $batas_kembali = date('H.i', strtotime($peminjaman['batas_kembali']));
        $batas_kembali_iso = date('Y-m-d\TH:i:s', strtotime($peminjaman['batas_kembali']));

        // Cek apakah saat halaman dimuat, waktu sudah habis DAN status masih 'Dipinjam'
        $waktu_sekarang = time();
        $waktu_batas = strtotime($peminjaman['batas_kembali']);
        if ($waktu_sekarang > $waktu_batas && $status_pinjam === 'Dipinjam') {
            $is_terlambat = true;
        }
    }

} catch (PDOException $e) {
    die("Error Database saat mengambil data: " . $e->getMessage());
}
?>

<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Peminjaman Saya · GOWES</title>
    <link rel="icon" href="../img/logo.png">

    <link rel="stylesheet" href="../assets/css/fonts.css">
    <link rel="stylesheet" href="../assets/css/tokens.css">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/mahasiswa.css">

    <style>
        /* CSS PRESISI POPUP TERLAMBAT */
        .modal-content.late-dialog {
            padding: 32px 24px !important;
            border-radius: 20px !important;
            text-align: center !important;
            background: #ffffff !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
        }

        .late-title {
            color: #e53e3e;
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }

        .late-desc {
            font-size: 14px;
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 24px;
            padding: 0 10px;
        }

        .late-box {
            background: #fef2f2;
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .late-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .late-icon {
            width: 40px;
            height: 40px;
            background: #e53e3e;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 900;
            flex-shrink: 0;
        }

        .late-text-wrap {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .late-label {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
            margin-bottom: 2px;
            line-height: 1;
        }

        .late-value {
            font-size: 14px;
            color: #e53e3e;
            font-weight: 700;
            line-height: 1;
        }

        .late-timer {
            font-size: 32px;
            font-weight: 800;
            color: #e53e3e;
            line-height: 1;
            font-variant-numeric: tabular-nums;
            letter-spacing: -0.5px;
        }

        .late-warning {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 6px;
            font-size: 12px;
            color: #e53e3e;
            font-weight: 600;
            margin-bottom: 24px;
            padding-left: 4px;
        }

        .late-warning svg {
            flex-shrink: 0;
            width: 14px;
            height: 14px;
        }

        .btn-late {
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            background: #4f46e5;
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-late:hover {
            background: #4338ca;
        }
    </style>
</head>

<body class="user">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><img src="../img/logo.png" alt="GOWES"></a>
        <nav class="topnav" aria-label="Menu utama">
            <a class="navitem" href="dashboard.php"><span class="ico"><svg xmlns="http://www.w3.org/2000/svg" width="20"
                        height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M3 9.6 10 3.5l7 6.1V16a1 1 0 0 1-1 1h-3.4v-4.6H7.4V17H4a1 1 0 0 1-1-1z" />
                    </svg></span>Dashboard</a>
            <a class="navitem on" href="peminjaman.php"><span class="ico"><svg xmlns="http://www.w3.org/2000/svg"
                        width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="4.8" cy="13" r="3" />
                        <circle cx="15.2" cy="13" r="3" />
                        <path d="M4.8 13 8 7.2h4.2l3 5.8M8 7.2 7 5H5M12.2 7.2 10.6 13" />
                    </svg></span>Peminjaman</a>
            <a class="navitem" href="scan.php"><span class="ico"><svg xmlns="http://www.w3.org/2000/svg" width="20"
                        height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path
                            d="M3 7V4.5A1.5 1.5 0 0 1 4.5 3H7M13 3h2.5A1.5 1.5 0 0 1 17 4.5V7M17 13v2.5a1.5 1.5 0 0 1-1.5 1.5H13M7 17H4.5A1.5 1.5 0 0 1 3 15.5V13M6.5 10h7" />
                    </svg></span>Scan QR</a>
            <a class="navitem" href="profil.php"><span class="ico"><svg xmlns="http://www.w3.org/2000/svg" width="20"
                        height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="10" cy="6.6" r="3.2" />
                        <path d="M3.8 17c.9-3.1 3.2-4.6 6.2-4.6s5.3 1.5 6.2 4.6" />
                    </svg></span>Profil</a>
        </nav>
        <a href="profil.php" class="user-pill-btn logged-in" title="Buka Profil" style="text-decoration: none;">
            <span><?= htmlspecialchars($nama_user) ?></span>
            <div class="avatar-sm"><?= $inisial ?></div>
        </a>
    </header>

    <main class="page no-tab">
        <div class="col">

            <h1 class="title">
                <span class="m">Peminjaman Saya</span>
                <span class="d">Peminjaman Saya</span>
            </h1>

            <?php if ($peminjaman): ?>
                <?php if ($status_pinjam === 'Menunggu'): ?>

                    <section class="card" style="text-align: center; padding: 40px 20px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 24 24" fill="none"
                            stroke="var(--primary)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                            style="margin: 0 auto 15px;">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        <h2 style="font-size: 22px; margin-bottom: 10px; color: var(--ink);">Menunggu Konfirmasi Pinjam</h2>
                        <p style="font-size: 15px; color: var(--muted); line-height: 1.6; margin-bottom: 24px;">
                            Silakan temui petugas di <strong><?= htmlspecialchars($peminjaman['nama_shelter']) ?></strong> untuk
                            mengambil kunci Go-bike
                            <strong><?= htmlspecialchars($peminjaman['kode_qr']) ?></strong>.<br><br>Waktu Anda akan mulai
                            berjalan setelah petugas mengonfirmasi.
                        </p>
                        <button onclick="window.location.reload()" class="btn btn-soft">Cek Status Terbaru</button>
                    </section>

                <?php elseif ($status_pinjam === 'Menunggu Kembali'): ?>

                    <section class="card" style="text-align: center; padding: 40px 20px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 24 24" fill="none"
                            stroke="#10b981" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                            style="margin: 0 auto 15px;">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                        <h2 style="font-size: 22px; margin-bottom: 10px; color: var(--ink);">Menunggu ACC Pengembalian</h2>
                        <p style="font-size: 15px; color: var(--muted); line-height: 1.6; margin-bottom: 24px;">
                            Anda telah mengajukan pengembalian Go-bike
                            <strong><?= htmlspecialchars($peminjaman['kode_qr']) ?></strong>.<br><br>Silakan serahkan kunci
                            fisik ke petugas. Status akan berubah setelah petugas meng-ACC pengembalian Anda.
                        </p>
                        <button onclick="window.location.reload()" class="btn btn-soft">Cek Status Terbaru</button>
                    </section>

                <?php else: ?>

                    <section class="countdown">
                        <div class="lbl">Sisa Waktu Peminjaman</div>
                        <div class="time" id="countdown" data-batas="<?= $batas_kembali_iso ?>">--:--:--</div>
                        <div class="foot">Kembali sebelum <?= $batas_kembali ?> WIB</div>
                    </section>

                    <section class="card">
                        <div class="rows">
                            <div class="row"><span class="k">ID Go-bike</span><span
                                    class="v"><?= htmlspecialchars($peminjaman['kode_qr']) ?></span></div>
                            <div class="row"><span class="k">Shelter</span><span
                                    class="v"><?= htmlspecialchars($peminjaman['nama_shelter']) ?></span></div>
                            <div class="row"><span class="k">Waktu mulai</span><span class="v"><?= $waktu_mulai ?> WIB</span>
                            </div>
                            <div class="row"><span class="k">Batas pengembalian</span><span class="v"><?= $batas_kembali ?>
                                    WIB</span></div>
                        </div>
                    </section>

                    <button type="button" class="btn btn-primary"
                        onclick="document.getElementById('modal-kembali').classList.add('active')">
                        Kembalikan Go-bike
                    </button>
                <?php endif; ?>

            <?php else: ?>
                <section class="card" style="text-align: center; padding: 40px 20px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 24 24" fill="none"
                        stroke="var(--muted)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                        style="margin: 0 auto 15px;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <h2 style="font-size: 22px; margin-bottom: 10px; color: var(--ink);">Belum Ada Peminjaman</h2>
                    <p style="font-size: 15px; color: var(--muted); margin-bottom: 24px;">Anda saat ini tidak memiliki
                        Go-bike yang sedang dipinjam.</p>
                    <a href="scan.php" class="btn btn-primary" style="text-decoration:none;">Pinjam Go-bike Sekarang</a>
                </section>
            <?php endif; ?>

        </div>
    </main>

    <!-- MODAL KHUSUS TERLAMBAT -->
    <?php if ($peminjaman && $status_pinjam === 'Dipinjam'): ?>
        <div class="modal-overlay <?= $is_terlambat ? 'active' : '' ?>" id="modal-terlambat">
            <div class="modal-content late-dialog">
                <h2 class="late-title">Waktu Peminjaman Habis!</h2>
                <p class="late-desc">Batas Waktu pengembalian Go-bike kamu telah berakhir.<br>Segera kembalikan sepeda ke
                    shelter dan serahkan kunci kepada petugas.</p>

                <div class="late-box">
                    <div class="late-left">
                        <div class="late-icon">!</div>
                        <div class="late-text-wrap">
                            <span class="late-label">Status:</span>
                            <span class="late-value">Terlambat</span>
                        </div>
                    </div>
                    <div class="late-timer" id="late-timer">00:00:00</div>
                </div>

                <div class="late-warning">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#e53e3e" stroke="white"
                        stroke-width="2">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z">
                        </path>
                        <line x1="12" y1="9" x2="12" y2="13"></line>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
                    Keterlambatan dapat dikenai sanksi sesuai ketentuan.
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="return">
                    <button type="submit" class="btn-late">Ya, Kembalikan</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- MODAL KONFIRMASI KEMBALIKAN NORMAL -->
    <?php if ($peminjaman && $status_pinjam === 'Dipinjam'): ?>
        <div class="modal-overlay" id="modal-kembali" role="dialog" aria-modal="true">
            <div class="modal-content dialog">
                <h2 style="font-size: 20px; font-weight: 700; color: var(--ink);">Kembalikan Go-bike?</h2>
                <p style="font-size: 14px; color: var(--muted); margin-top: 14px; line-height: 1.5;">Pastikan Go-bike sudah
                    diparkir di shelter dan kunci diserahkan ke petugas. Anda harus menunggu ACC dari petugas.</p>

                <form method="POST" action="" style="margin-top: 20px;">
                    <input type="hidden" name="action" value="return">
                    <button type="submit" class="btn btn-primary" style="margin-bottom: 10px;">Ya, Ajukan Kembali</button>
                </form>

                <button type="button" class="btn btn-soft"
                    onclick="document.getElementById('modal-kembali').classList.remove('active')">Batal</button>
            </div>
        </div>
    <?php endif; ?>

    <script src="../assets/js/common.js"></script>

    <!-- SCRIPT COUNTDOWN & POPUP TERLAMBAT -->
    <?php if ($peminjaman && $status_pinjam === 'Dipinjam'): ?>
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                const countdownEl = document.getElementById('countdown');
                const modalTerlambat = document.getElementById('modal-terlambat');
                const lateTimerEl = document.getElementById('late-timer');

                if (!countdownEl) return;
                const targetDateStr = countdownEl.getAttribute('data-batas');
                if (!targetDateStr) return;

                const targetDate = new Date(targetDateStr.replace(' ', 'T')).getTime();

                function updateLateTimer() {
                    const now = new Date().getTime();
                    const diff = now - targetDate;
                    const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((diff % (1000 * 60)) / 1000);

                    lateTimerEl.innerHTML =
                        String(hours).padStart(2, '0') + ":" +
                        String(minutes).padStart(2, '0') + ":" +
                        String(seconds).padStart(2, '0');
                }

                // Jika PHP mendeteksi sudah terlambat saat halaman dimuat
                if (modalTerlambat && modalTerlambat.classList.contains('active')) {
                    updateLateTimer();
                    setInterval(updateLateTimer, 1000);
                }

                // Jalankan Countdown Normal
                const interval = setInterval(function () {
                    const now = new Date().getTime();
                    const distance = targetDate - now;

                    if (distance < 0) {
                        clearInterval(interval);
                        countdownEl.innerHTML = "Waktu Habis";

                        // MUNCULKAN POPUP TERLAMBAT!
                        if (modalTerlambat) {
                            modalTerlambat.classList.add('active');
                            updateLateTimer();
                            setInterval(updateLateTimer, 1000);
                        }
                        return;
                    }
                    const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((distance % (1000 * 60)) / 1000);

                    countdownEl.innerHTML =
                        String(hours).padStart(2, '0') + ":" +
                        String(minutes).padStart(2, '0') + ":" +
                        String(seconds).padStart(2, '0');
                }, 1000);
            });
        </script>
    <?php endif; ?>
</body>

</html>