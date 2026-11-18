<?php
session_start();

// 1. Proteksi Halaman
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'petugas')) {
    header("Location: ../auth/login.php");
    exit;
}

require_once '../config/koneksi.php';

// Ambil role dan id_shelter dari session
$is_admin = ($_SESSION['role'] === 'admin');
$id_shelter_petugas = isset($_SESSION['id_shelter']) ? (int) $_SESSION['id_shelter'] : 0;

// Jika petugas tidak punya id_shelter, tendang kembali
if (!$is_admin && $id_shelter_petugas === 0) {
    echo "<script>alert('Akun petugas Anda belum ditugaskan ke shelter manapun. Hubungi Admin.'); window.location='../auth/login.php?logout=true';</script>";
    exit;
}

// ==========================================
// 2. LOGIKA KONFIRMASI PINJAM (OLEH SATPAM)
// ==========================================
if (isset($_GET['konfirmasi_pinjam'])) {
    $id_peminjaman = $_GET['konfirmasi_pinjam'];
    try {
        // VALIDASI: Cek apakah sepeda ini milik shelter petugas (kecuali Admin)
        if (!$is_admin) {
            $cek_pinjam = $pdo->prepare("SELECT s.id_shelter FROM peminjaman p JOIN sepeda s ON p.id_sepeda = s.id_sepeda WHERE p.id_peminjaman = :id");
            $cek_pinjam->execute(['id' => $id_peminjaman]);
            $shelter_sepeda = $cek_pinjam->fetchColumn();

            if ($shelter_sepeda != $id_shelter_petugas) {
                echo "<script>alert('Akses Ditolak: Anda tidak berhak mengonfirmasi sepeda dari shelter lain.'); window.location='peminjaman.php';</script>";
                exit;
            }
        }

        // Ubah status menjadi 'Dipinjam', catat waktu mulai, dan hitung batas kembali
        $stmt = $pdo->prepare("UPDATE peminjaman 
                               SET status_peminjaman = 'Dipinjam', 
                                   waktu_mulai = CURRENT_TIMESTAMP,
                                   batas_kembali = CURRENT_TIMESTAMP + (durasi_pinjam * interval '1 hour')
                               WHERE id_peminjaman = :id");
        $stmt->execute(['id' => $id_peminjaman]);

        echo "<script>alert('Peminjaman berhasil dikonfirmasi! Mahasiswa sekarang bisa mulai menggunakan sepeda.'); window.location='peminjaman.php';</script>";
    } catch (PDOException $e) {
        echo "<script>alert('Gagal memproses: " . $e->getMessage() . "');</script>";
    }
}

// ==========================================
// 3. LOGIKA ACC PENGEMBALIAN
// ==========================================
if (isset($_GET['kembali'])) {
    $id_peminjaman = $_GET['kembali'];
    try {
        // VALIDASI: Cek apakah sepeda ini milik shelter petugas (kecuali Admin)
        if (!$is_admin) {
            $cek_pinjam = $pdo->prepare("SELECT s.id_shelter FROM peminjaman p JOIN sepeda s ON p.id_sepeda = s.id_sepeda WHERE p.id_peminjaman = :id");
            $cek_pinjam->execute(['id' => $id_peminjaman]);
            $shelter_sepeda = $cek_pinjam->fetchColumn();

            if ($shelter_sepeda != $id_shelter_petugas) {
                echo "<script>alert('Akses Ditolak: Anda tidak berhak meng-ACC pengembalian sepeda dari shelter lain.'); window.location='peminjaman.php';</script>";
                exit;
            }
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE peminjaman SET waktu_kembali = CURRENT_TIMESTAMP, status_peminjaman = 'Selesai' WHERE id_peminjaman = :id");
        $stmt->execute(['id' => $id_peminjaman]);

        $stmt_sep = $pdo->prepare("SELECT id_sepeda FROM peminjaman WHERE id_peminjaman = :id");
        $stmt_sep->execute(['id' => $id_peminjaman]);
        $id_sepeda = $stmt_sep->fetchColumn();

        $pdo->prepare("UPDATE sepeda SET status_ketersediaan = 'Tersedia' WHERE id_sepeda = :id_sep")->execute(['id_sep' => $id_sepeda]);

        $pdo->commit();
        echo "<script>alert('Pengembalian berhasil di-ACC! Sepeda sekarang tersedia.'); window.location='peminjaman.php';</script>";
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo "<script>alert('Gagal memproses: " . $e->getMessage() . "');</script>";
    }
}

// ==========================================
// 4. FITUR PENCARIAN & AMBIL DATA
// ==========================================
$search = isset($_GET['cari']) ? trim($_GET['cari']) : '';
$where_clauses = ["p.waktu_kembali IS NULL"];
$params = [];

// FILTER SHELTER: Jika bukan admin, kunci datanya hanya untuk shelter dia
if (!$is_admin) {
    $where_clauses[] = "s.id_shelter = :id_shelter";
    $params['id_shelter'] = $id_shelter_petugas;
}

// FILTER PENCARIAN
if (!empty($search)) {
    $where_clauses[] = "(pm.no_identitas ILIKE :search OR s.kode_qr ILIKE :search)";
    $params['search'] = "%$search%";
}

$where_sql = implode(" AND ", $where_clauses);

$sql = "SELECT p.*, pm.no_identitas, s.kode_qr, sh.nama_shelter 
        FROM peminjaman p
        JOIN peminjam pm ON p.id_peminjam = pm.id_peminjam
        JOIN sepeda s ON p.id_sepeda = s.id_sepeda
        JOIN shelter sh ON s.id_shelter = sh.id_shelter
        WHERE $where_sql
        ORDER BY p.waktu_mulai DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$peminjaman = $stmt->fetchAll();

// Ambil nama shelter untuk ditampilkan di header (jika petugas)
$nama_shelter_petugas = "";
if (!$is_admin) {
    $stmt_sh = $pdo->prepare("SELECT nama_shelter FROM shelter WHERE id_shelter = :id");
    $stmt_sh->execute(['id' => $id_shelter_petugas]);
    $nama_shelter_petugas = $stmt_sh->fetchColumn();
}
?>

<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Peminjaman · GOWES</title>
    <link rel="icon" href="../img/logo.png">

    <link rel="stylesheet" href="../assets/css/fonts.css">
    <link rel="stylesheet" href="../assets/css/tokens.css">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/panel.css">

    <style>
        /* CSS Disesuaikan dengan Desain Figma */
        .header-panel {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            background: #ffffff;
            border-radius: 16px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
            border: 1px solid #f1f5f9;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-panel h2 {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }

        .search-container {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 0 16px;
            height: 42px;
            width: 350px;
            transition: all 0.2s ease;
        }

        .search-container:focus-within {
            border-color: #4f46e5;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .search-container svg {
            color: #94a3b8;
            width: 18px;
            height: 18px;
        }

        .search-container input {
            border: none;
            background: transparent;
            outline: none;
            width: 100%;
            margin-left: 10px;
            font-size: 14px;
            color: #334155;
            font-family: inherit;
        }

        .btn-search {
            background: #4f46e5;
            color: white;
            border: none;
            height: 42px;
            padding: 0 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: 0.2s;
            margin-left: 10px;
        }

        .btn-search:hover {
            background: #4338ca;
        }

        .table-responsive {
            overflow-x: auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
            border: 1px solid #f1f5f9;
        }

        .tbl-modern {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .tbl-modern th {
            background: #f8fafc;
            padding: 16px 24px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .tbl-modern td {
            padding: 16px 24px;
            font-size: 14px;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .tbl-modern tbody tr {
            transition: background-color 0.2s;
        }

        .tbl-modern tbody tr:hover {
            background-color: #f8fafc;
        }

        .tbl-modern tr:last-child td {
            border-bottom: none;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-waiting {
            background: #fef3c7;
            color: #d97706;
        }

        .badge-active {
            background: #dcfce7;
            color: #15803d;
        }

        .badge-warning {
            background: #ffedd5;
            color: #c2410c;
        }

        .badge-danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .badge-info {
            background: #e0e7ff;
            color: #4338ca;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }

        .btn-active {
            background: #4f46e5;
            color: white;
        }

        .btn-active:hover {
            background: #4338ca;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);
        }

        .btn-inactive {
            background: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
            pointer-events: none;
        }

        .countdown-text {
            font-family: 'Courier New', Courier, monospace;
            font-weight: 700;
            color: #4f46e5;
        }
    </style>
</head>

<body class="admin" data-peran="petugas">
    <div class="admin-shell">

        <aside class="sidebar">
            <input type="checkbox" id="nav-toggle" class="nav-toggle" aria-label="Buka atau tutup menu">
            <div class="sidebar-header">
                <div>
                    <a class="logo" href="dashboard.php"><img src="../img/logo-white.png" alt="GOWES"></a>
                    <div class="role">Petugas / Satpam</div>
                </div>
                <label class="hamburger" for="nav-toggle" aria-label="Buka menu">
                    <span></span><span></span><span></span>
                </label>
            </div>
            <nav class="menu" aria-label="Menu Petugas">
                <a href="dashboard.php">Dashboard</a>
                <a href="unit.php">Unit Go-bike</a>
                <a href="peminjaman.php" class="on">Peminjaman</a>
                <a href="laporan.php">Laporan Kondisi</a>
                <a href="riwayat.php">Riwayat Peminjaman</a>
            </nav>
            <a class="menu-logout" href="javascript:void(0);" onclick="openLogoutModal()">Keluar</a>
        </aside>

        <main class="main">
            <header class="topbar-a" style="margin-bottom: 24px;">
                <div>
                    <h1 style="font-size: 28px; color: #1e293b;">Peminjaman</h1>
                    <p style="color: #64748b; margin-top: 4px;">Verifikasi bukti digital dan konfirmasi pengembalian</p>
                </div>
                <!-- Menampilkan Shelter Petugas -->
                <span class="pill" style="background: #e0e7ff; color: #4338ca; font-weight: 700;">
                    <?= $is_admin ? 'Admin - Semua Shelter' : 'Petugas - ' . htmlspecialchars($nama_shelter_petugas) ?>
                </span>
            </header>

            <div class="header-panel">
                <h2>Verifikasi Bukti</h2>
                <form method="GET" action="" style="display: flex; margin: 0;">
                    <div class="search-container">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input type="search" name="cari" placeholder="Cari Nomor Identitas atau ID Go-bike"
                            value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <button type="submit" class="btn-search">Cari</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="tbl-modern">
                    <thead>
                        <tr>
                            <th>Nomor Identitas</th>
                            <th>Unit</th>
                            <th>Shelter</th>
                            <th>Mulai</th>
                            <th>Batas</th>
                            <th>Sisa waktu</th>
                            <th>Status</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($peminjaman) > 0): ?>
                            <?php foreach ($peminjaman as $row): ?>
                                <?php
                                $status_db = $row['status_peminjaman'] ?? 'Menunggu';
                                $is_menunggu_pinjam = ($status_db === 'Menunggu');
                                $is_menunggu_kembali = ($status_db === 'Menunggu Kembali');

                                $jam_mulai = date('H.i', strtotime($row['waktu_mulai']));
                                $jam_batas = date('H.i', strtotime($row['batas_kembali']));
                                $status_waktu = $row['status_keterlambatan'] ?? 'Aktif';

                                // Logika Warna Badge Status
                                if ($is_menunggu_pinjam) {
                                    $chip_class = 'badge-waiting';
                                    $chip_text = 'Menunggu Pinjam';
                                } elseif ($is_menunggu_kembali) {
                                    $chip_class = 'badge-info';
                                    $chip_text = 'Menunggu ACC Kembali';
                                } elseif ($status_waktu === 'Terlambat') {
                                    $chip_class = 'badge-danger';
                                    $chip_text = 'Terlambat';
                                } elseif ((strtotime($row['batas_kembali']) - time() < 1800)) {
                                    $chip_class = 'badge-warning';
                                    $chip_text = 'Mendekati Batas';
                                } else {
                                    $chip_class = 'badge-active';
                                    $chip_text = 'Aktif Dipinjam';
                                }

                                $batas_iso = date('Y-m-d\TH:i:s', strtotime($row['batas_kembali']));
                                ?>
                                <tr>
                                    <td style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($row['no_identitas']) ?>
                                    </td>
                                    <td>
                                        <span
                                            style="background: #e0e7ff; color: #4338ca; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 13px;">
                                            <?= htmlspecialchars($row['kode_qr']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($row['nama_shelter']) ?></td>
                                    <td><?= $jam_mulai ?></td>
                                    <td><?= $jam_batas ?></td>
                                    <td>
                                        <?php if ($is_menunggu_pinjam): ?>
                                            <span style="color: #94a3b8; font-style: italic; font-size: 13px;">Belum dimulai</span>
                                        <?php else: ?>
                                            <span class="countdown countdown-text" data-target="<?= $batas_iso ?>">00:00:00</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $chip_class ?>"><?= $chip_text ?></span>
                                    </td>
                                    <td>
                                        <div class="action-buttons" style="justify-content: flex-end;">
                                            <!-- Tombol ACC Pinjam -->
                                            <?php if ($is_menunggu_pinjam): ?>
                                                <a class="btn-action btn-active"
                                                    href="?konfirmasi_pinjam=<?= $row['id_peminjaman'] ?>"
                                                    onclick="return confirm('Konfirmasi sepeda sudah diserahkan ke mahasiswa?')">Konfirmasi
                                                    Pinjam</a>
                                            <?php else: ?>
                                                <a class="btn-action btn-inactive" href="#">Konfirmasi Pinjam</a>
                                            <?php endif; ?>

                                            <!-- Tombol ACC Kembali -->
                                            <?php if ($is_menunggu_kembali): ?>
                                                <a class="btn-action btn-active" style="background:#10b981;"
                                                    href="?kembali=<?= $row['id_peminjaman'] ?>"
                                                    onclick="return confirm('ACC pengembalian sepeda ini?')">Konfirmasi Kembali</a>
                                            <?php else: ?>
                                                <a class="btn-action btn-inactive" href="#">Konfirmasi Kembali</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px;">
                                    <div
                                        style="display: flex; flex-direction: column; align-items: center; justify-content: center; color: #94a3b8;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                            stroke-linejoin="round" style="margin-bottom: 12px; opacity: 0.5;">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <line x1="12" y1="8" x2="12" y2="12"></line>
                                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                        </svg>
                                        <span style="font-size: 15px;">Tidak ada peminjaman aktif di shelter Anda.</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <div class="modal-overlay" id="logoutModal">
        <div class="modal-content">
            <div class="modal-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
            </div>
            <h2>Konfirmasi Keluar</h2>
            <p>Apakah Anda yakin ingin keluar dari akun ini? Anda harus login kembali untuk mengakses halaman admin.</p>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeLogoutModal()">Batal</button>
                <a href="../auth/login.php?logout=true" class="btn-confirm">Ya, Keluar</a>
            </div>
        </div>
    </div>

    <script src="../assets/js/common.js"></script>
    <script src="../assets/js/countdown.js"></script>
    <script src="../assets/js/logout-modal.js"></script>
</body>

</html>