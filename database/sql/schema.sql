-- create database db_gowes;
CREATE DATABASE db_gowes;

-- 1. Tabel Superadmin
CREATE TABLE superadmin (
	id_superadmin SERIAL PRIMARY KEY,
	username VARCHAR(30) NOT NULL UNIQUE,
	password VARCHAR(255) NOT NULL
);

-- 2. Tabel Admin (ditambahkan oleh Superadmin)
CREATE TABLE admin (
	id_admin SERIAL PRIMARY KEY,
	id_superadmin INT NOT NULL,
	username VARCHAR(50) NOT NULL UNIQUE,
	password VARCHAR(255) NOT NULL,
	FOREIGN KEY (id_superadmin) REFERENCES superadmin(id_superadmin)
);

-- 3. Tabel Shelter
CREATE TABLE shelter (
	id_shelter SERIAL PRIMARY KEY,
	id_superadmin INT NOT NULL,
	nama_shelter VARCHAR(50) NOT NULL,
	lokasi VARCHAR(100) NOT NULL,
	FOREIGN KEY (id_superadmin) REFERENCES superadmin(id_superadmin)
);

-- 4. Tabel Petugas
CREATE TABLE petugas (
	id_petugas SERIAL PRIMARY KEY,
	id_superadmin INT NOT NULL,
	id_shelter INT NOT NULL,
	username VARCHAR(30) NOT NULL UNIQUE,
	password VARCHAR(255) NOT NULL,
	FOREIGN KEY (id_superadmin) REFERENCES superadmin(id_superadmin),
	FOREIGN KEY (id_shelter) REFERENCES shelter(id_shelter)
);

-- 5. Tabel Sepeda
CREATE TABLE sepeda (
	id_sepeda SERIAL PRIMARY KEY,
	id_shelter INT NOT NULL,
	id_admin INT NOT NULL,
	kode_qr VARCHAR(100) NOT NULL UNIQUE,
	status_ketersediaan VARCHAR(20) NOT NULL DEFAULT 'Tersedia',
	FOREIGN KEY (id_shelter) REFERENCES shelter(id_shelter),
	FOREIGN KEY (id_admin) REFERENCES admin(id_admin)
);

-- 6. Tabel Peminjam
CREATE TABLE peminjam (
	id_peminjam SERIAL PRIMARY KEY,
	no_identitas VARCHAR(20) NOT NULL UNIQUE,
	nama VARCHAR(50) NOT NULL,
	password VARCHAR(255) NOT NULL
);

-- 7. Tabel Peminjaman 
CREATE TABLE peminjaman (
	id_peminjaman SERIAL PRIMARY KEY,
	id_peminjam INT NOT NULL,
	id_shelter INT NOT NULL,
	id_sepeda INT NOT NULL,
	id_petugas INT,
	waktu_mulai TIMESTAMP NOT NULL,
	durasi_pinjam INT NOT NULL,
	waktu_kembali TIMESTAMP,
	batas_kembali TIMESTAMP NOT NULL,
	status_keterlambatan VARCHAR(20) NOT NULL DEFAULT 'Tepat Waktu',
	status_peminjaman VARCHAR(20) NOT NULL DEFAULT 'Menunggu',
	FOREIGN KEY (id_peminjam) REFERENCES peminjam(id_peminjam),
	FOREIGN KEY (id_shelter) REFERENCES shelter(id_shelter),
	FOREIGN KEY (id_sepeda) REFERENCES sepeda(id_sepeda),
	FOREIGN KEY (id_petugas) REFERENCES petugas(id_petugas)
);

-- 8. Tabel Laporan Kondisi
CREATE TABLE laporan_kondisi (
	id_laporankondisi SERIAL PRIMARY KEY,
	id_peminjaman INT UNIQUE NOT NULL,
	nilai_ban INT NOT NULL,
	nilai_rem INT NOT NULL,
	nilai_rantai INT NOT NULL,
	nilai_rangka_bodi INT NOT NULL,
	keterangan VARCHAR(255),
	tgl_lapor DATE NOT NULL,
	FOREIGN KEY (id_peminjaman) REFERENCES peminjaman(id_peminjaman)
);

-- 9. Tabel Sanksi
CREATE TABLE sanksi (
	id_sanksi SERIAL PRIMARY KEY,
	id_peminjam INT NOT NULL,
	id_admin INT NOT NULL,
	jenis_tanggungan VARCHAR(50) NOT NULL,
	tgl_mulai DATE NOT NULL,
	tgl_selesai DATE NOT NULL,
	FOREIGN KEY (id_peminjam) REFERENCES peminjam(id_peminjam),
	FOREIGN KEY (id_admin) REFERENCES admin(id_admin)
);

--trigger untuk pilih durasi (jalanin di database)
CREATE OR REPLACE FUNCTION hitung_batas_kembali()
RETURNS TRIGGER AS $$
BEGIN
    NEW.batas_kembali := NEW.waktu_mulai + (NEW.durasi_pinjam * INTERVAL '1 hour');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_batas_kembali
BEFORE INSERT ON peminjaman
FOR EACH ROW
EXECUTE FUNCTION hitung_batas_kembali();

INSERT INTO peminjaman (id_sepeda, id_peminjam, waktu_mulai, durasi_pinjam)
VALUES ($1, $2, $3, $4)
RETURNING id_peminjaman, batas_kembali;

-- trigger status keterlambatan (run abis tabel peminjaman)
CREATE OR REPLACE FUNCTION update_status_keterlambatan()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.waktu_kembali IS NOT NULL THEN
        IF NEW.waktu_kembali > NEW.batas_kembali THEN
            NEW.status_keterlambatan := 'Terlambat';
        ELSE
            NEW.status_keterlambatan := 'Tepat Waktu';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_status_keterlambatan
BEFORE UPDATE ON peminjaman
FOR EACH ROW
EXECUTE FUNCTION update_status_keterlambatan();