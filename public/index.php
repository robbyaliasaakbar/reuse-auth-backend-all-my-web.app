<?php
// public/index.php — front controller: CORS + router + baca JSON.
// Dijalankan via: php -S 0.0.0.0:7002 -t public public/index.php (lihat Dockerfile).

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/response.php';
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/validate.php';
require __DIR__ . '/../src/mail.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/profile.php';
require __DIR__ . '/../src/lamaran.php';

$cfg = load_env(__DIR__ . '/../.env');

// CORS: cuma origin terdaftar yang boleh.
// 1) Daftar eksplisit di .env (tambah :7003 dst kalau ada webapp baru).
// 2) Origin se-mesin (host origin == host yang diketuk browser, misal IP Tailscale).
//    Aman dari situs asing karena Origin mereka pasti beda host. Ini yang bikin
//    share via Tailscale jalan tanpa edit config tiap ganti IP.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$boleh = array_map('trim', explode(',', $cfg['CORS_ORIGINS']));
$izinkan = $origin !== '' && in_array($origin, $boleh, true);
if (!$izinkan && $origin !== '') {
    $hostAsal = parse_url($origin, PHP_URL_HOST);
    $hostTuju = explode(':', $_SERVER['HTTP_HOST'] ?? '', 2)[0];
    $izinkan = $hostAsal !== '' && $hostAsal === $hostTuju;
}
if ($izinkan) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; } // preflight

try {
    $pdo = db($cfg);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if ($method === 'GET' && $path === '/api/health') {
        json(200, ['ok' => true, 'time' => now_iso()]);
    }
    if ($method === 'POST' && $path === '/api/register') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        handle_register($pdo, $cfg, body(), $ip); // jawab + exit di dalam
    }
    if ($method === 'POST' && $path === '/api/verify') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        handle_verify($pdo, $cfg, body(), $ip);
    }
    if ($method === 'POST' && $path === '/api/login') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        handle_login($pdo, $cfg, body(), $ip);
    }
    if ($method === 'GET' && $path === '/api/me') {
        $email = email_dari_token($pdo, $_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($email === null) json(401, ['error' => 'Token tidak sah atau kedaluwarsa']);
        json(200, ['email' => $email, 'nama' => nama_of($pdo, $email), 'username' => username_of($pdo, $email), 'role' => role_of($pdo, $email)]);
    }
    if ($method === 'POST' && $path === '/api/forgot') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        handle_forgot($pdo, $cfg, body(), $ip);
    }
    if ($method === 'POST' && $path === '/api/reset') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        handle_reset($pdo, $cfg, body(), $ip);
    }
    // Profil sendiri: PUT /api/me (aditif, di bawah auth, di atas lamaran).
    if ($method === 'PUT' && $path === '/api/me') {
        $email = email_dari_token($pdo, $_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($email === null) json(401, ['error' => 'Token tidak sah atau kedaluwarsa']);
        handle_profile_update($pdo, $email, body());
    }
    // Lamaran sync: semua butuh token. ID cuma milik sendiri (cek di handler).
    if (str_starts_with($path, '/api/lamaran')) {
        $email = email_dari_token($pdo, $_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($email === null) json(401, ['error' => 'Token tidak sah atau kedaluwarsa']);
        if ($method === 'GET' && $path === '/api/lamaran') handle_lamaran_list($pdo, $email);
        if ($method === 'POST' && $path === '/api/lamaran') handle_lamaran_create($pdo, $email, body());
        if (preg_match('#^/api/lamaran/([A-Za-z0-9-]+)$#', $path, $m)) {
            if ($method === 'PUT') handle_lamaran_update($pdo, $email, $m[1], body());
            if ($method === 'DELETE') handle_lamaran_delete($pdo, $email, $m[1]);
        }
        json(405, ['error' => 'Metode tidak didukung']);
    }
    json(404, ['error' => 'Not found']);
} catch (Throwable $t) {
    error_log('ERROR: ' . $t->getMessage());
    json(500, ['error' => 'Terjadi kesalahan server']); // pesan ke browser selalu generik
}
