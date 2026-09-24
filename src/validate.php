<?php
// validate.php — validasi server (wajib, karena validasi browser gampang dilewati)
// + rem rate limit sederhana pakai tabel rate_limits.

function valid_email(mixed $e): bool {
    return is_string($e) && filter_var(trim($e), FILTER_VALIDATE_EMAIL) !== false;
}

// Balikin string error kalau tidak memenuhi, null kalau OK.
// Aturan (locked PRD): min 8 + ada huruf besar + ada angka.
function password_error(mixed $p): ?string {
    if (!is_string($p) || strlen($p) < 8) return 'Password minimal 8 karakter';
    if (!preg_match('/[A-Z]/', $p)) return 'Password wajib ada huruf besar';
    if (!preg_match('/[0-9]/', $p)) return 'Password wajib ada angka';
    return null;
}

// Username Mini Leads: 3-20 char, huruf KECIL + angka + underscore, tanpa spasi.
// Huruf besar DITOLAK (bukan di-lowercase diam-diam) biar user sadar aturan main.
// Kosong = skip (jalur JobTracker yang tidak kirim field ini). Wajib-isi dicek di form React.
function username_error(mixed $u): ?string {
    if (!is_string($u) || $u === '') return null;
    if (!preg_match('/^[a-z0-9_]{3,20}$/', $u)) return 'Username 3-20 karakter: huruf kecil, angka, underscore';
    if (in_array($u, ['root', 'system'], true)) return 'Username tidak tersedia';
    return null;
}

// Tanggal lahir: YYYY-MM-DD, tanggal beneran, tidak boleh masa depan.
// Kosong = skip (jalur JobTracker). Wajib-isi dicek di form React.
function tanggal_lahir_error(mixed $t): ?string {
    if (!is_string($t) || trim($t) === '') return null;
    $t = trim($t);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) return 'Tanggal lahir format YYYY-MM-DD';
    [$y, $m, $d] = array_map('intval', explode('-', $t));
    if (!checkdate($m, $d, $y)) return 'Tanggal lahir tidak valid';
    if ($t > gmdate('Y-m-d')) return 'Tanggal lahir tidak boleh masa depan';
    return null;
}

// Aturan telepon (santai, Indonesia): boleh campur + spasi strip, total digit 9-15.
// Kosong = skip (form Mini Leads tidak punya field telepon). JobTracker tetap kirim → tetap dicek.
function telepon_error(mixed $t): ?string {
    if (!is_string($t) || trim($t) === '') return null;
    $n = strlen(preg_replace('/\D/', '', $t));
    if ($n < 9 || $n > 15) return 'Nomor telepon 9-15 digit';
    return null;
}

// Rem: max $max request per $window detik untuk 1 kunci (misal "1.2.3.4:/api/register").
// True = boleh lewat, false = kena rem (caller jawab 429).
function rate_boleh(PDO $pdo, string $kunci, int $max, int $window): bool {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $row = $pdo->prepare('SELECT window_start, hitung FROM rate_limits WHERE kunci = :k');
    $row->execute([':k' => $kunci]);
    $r = $row->fetch(PDO::FETCH_ASSOC);

    if (!$r || $now->getTimestamp() - strtotime($r['window_start']) >= $window) {
        $s = $pdo->prepare('INSERT OR REPLACE INTO rate_limits (kunci, window_start, hitung) VALUES (:k, :w, 1)');
        $s->execute([':k' => $kunci, ':w' => $now->format('Y-m-d\TH:i:s\Z')]);
        return true;
    }
    if ((int) $r['hitung'] >= $max) return false;
    $s = $pdo->prepare('UPDATE rate_limits SET hitung = hitung + 1 WHERE kunci = :k');
    $s->execute([':k' => $kunci]);
    return true;
}
