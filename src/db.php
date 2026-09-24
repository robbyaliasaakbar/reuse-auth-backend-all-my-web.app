<?php
// db.php — buka SQLite via PDO + bikin 4 tabel kalau belum ada.
// Pola query parameterized (:nama) dipakai di semua file — ini obat SQL injection.

function db(array $cfg): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dir = dirname($cfg['DB_PATH']);
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $pdo = new PDO('sqlite:' . $cfg['DB_PATH']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        created_at TEXT NOT NULL
    )");
    // Migrasi ringan: DB lama (cuma email+password) ditambah kolom tanpa hapus data.
    // PRAGMA table_info = intip struktur tabel. Kolom tidak ada → ALTER tambah.
    $kolom = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('nama', $kolom, true)) $pdo->exec("ALTER TABLE users ADD COLUMN nama TEXT NOT NULL DEFAULT ''");
    if (!in_array('telepon', $kolom, true)) $pdo->exec("ALTER TABLE users ADD COLUMN telepon TEXT NOT NULL DEFAULT ''");
    // Upgrade Mini Leads (2026-09-14): username + role + nama pisah + tanggal lahir.
    // Semua nullable/ber-default → user lama (JobTracker) tetap jalan tanpa diubah.
    if (!in_array('username', $kolom, true)) $pdo->exec('ALTER TABLE users ADD COLUMN username TEXT');
    if (!in_array('role', $kolom, true)) $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
    if (!in_array('nama_depan', $kolom, true)) $pdo->exec('ALTER TABLE users ADD COLUMN nama_depan TEXT');
    if (!in_array('nama_belakang', $kolom, true)) $pdo->exec('ALTER TABLE users ADD COLUMN nama_belakang TEXT');
    if (!in_array('tanggal_lahir', $kolom, true)) $pdo->exec('ALTER TABLE users ADD COLUMN tanggal_lahir TEXT');
    // Unik case-insensitive (Admin = admin). NULL boleh dobel (user lama).
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username COLLATE NOCASE)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS otps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        kode TEXT NOT NULL,
        expired_at TEXT NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0,
        dipakai INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reset_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        kode TEXT NOT NULL,
        expired_at TEXT NOT NULL,
        dipakai INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
        token TEXT PRIMARY KEY,
        email TEXT NOT NULL,
        expired_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        kunci TEXT PRIMARY KEY,
        window_start TEXT NOT NULL,
        hitung INTEGER NOT NULL
    )");
    // Tabel lamaran: 1 baris = 1 lamaran milik 1 user (email pemilik dari token).
    // Sync online: PC + HP baca tulis tabel ini, bukan localStorage per device.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lamaran (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        company TEXT NOT NULL DEFAULT '',
        position TEXT NOT NULL DEFAULT '',
        tanggal TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'baru',
        portal TEXT NOT NULL DEFAULT '',
        link TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lamaran_email ON lamaran(email)");
    return $pdo;
}

function now_iso(): string {
    return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

function plus_detik(int $detik): string {
    return (new DateTime('now', new DateTimeZone('UTC')))->modify("+$detik seconds")->format('Y-m-d\TH:i:s\Z');
}
