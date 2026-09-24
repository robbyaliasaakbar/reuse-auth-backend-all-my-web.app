<?php
// config.php — baca .env sederhana TANPA library.
// Format: KEY=nilai, baris # = komentar. getenv() menang kalau sudah ada (misal dari compose).

function load_env(string $path): array {
    $cfg = [];
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if ($key !== '' && getenv($key) === false) {
                putenv("$key=$val");
            }
        }
    }
    $get = fn($k, $d) => getenv($k) === false ? $d : getenv($k);
    return [
        'PORT'         => (int) $get('PORT', '7002'),
        'CORS_ORIGINS' => $get('CORS_ORIGINS', 'http://localhost:7001'),
        'DB_PATH'      => $get('DB_PATH', __DIR__ . '/../data/auth.db'),
        'SMTP_HOST'    => $get('SMTP_HOST', 'smtp.gmail.com'),
        'SMTP_PORT'    => (int) $get('SMTP_PORT', '587'),
        'SMTP_USER'    => $get('SMTP_USER', ''),   // wajib diisi (email Gmail pengirim)
        'SMTP_PASS'    => $get('SMTP_PASS', ''),   // App Password 16 huruf, JANGAN password asli
        'MAIL_FROM'    => $get('MAIL_FROM', 'no-reply@jobtracker.local'),
        'OTP_TTL'      => (int) $get('OTP_TTL', '300'),     // detik: OTP daftar 5 menit
        'TOKEN_TTL'    => (int) $get('TOKEN_TTL', '3600'),  // detik: token 1 jam
        'RATE_MAX'     => (int) $get('RATE_MAX', '5'),      // max request per window
        'RATE_WINDOW'  => (int) $get('RATE_WINDOW', '60'),  // detik
    ];
}
