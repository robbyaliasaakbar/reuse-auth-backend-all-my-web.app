<?php
// auth.php — Step 1: REGISTER saja (verify/login/reset di step berikutnya).
// Aturan: user active daftar lagi → 409. User pending daftar lagi → OTP baru dikirim ulang.

function handle_register(PDO $pdo, array $cfg, array $in, string $ip): void {
    $email = strtolower(trim($in['email'] ?? ''));
    $pw = $in['password'] ?? '';
    $pw2 = $in['password_konfirmasi'] ?? '';
    $nama = trim($in['nama'] ?? '');
    $telepon = trim($in['telepon'] ?? '');
    // Field Mini Leads (opsional di server, wajib di form React): username + nama pisah + tanggal lahir.
    $username = trim($in['username'] ?? '');
    $depan = trim($in['nama_depan'] ?? '');
    $belakang = trim($in['nama_belakang'] ?? '');
    $tgl = trim($in['tanggal_lahir'] ?? '');

    if (!valid_email($email)) json(400, ['error' => 'Format email tidak valid']);
    // Nama: JobTracker kirim 'nama', Mini Leads kirim depan+belakang → gabung.
    if ($nama === '' && ($depan !== '' || $belakang !== '')) $nama = trim("$depan $belakang");
    if ($nama === '') json(400, ['error' => 'Nama lengkap wajib diisi']);
    if (($e = telepon_error($telepon)) !== null) json(400, ['error' => $e]);
    if (($e = username_error($username)) !== null) json(400, ['error' => $e]);
    if (($e = tanggal_lahir_error($tgl)) !== null) json(400, ['error' => $e]);
    if (($e = password_error($pw)) !== null) json(400, ['error' => $e]);
    if ($pw !== $pw2) json(400, ['error' => 'Konfirmasi password tidak sama']); // dicek di SERVER (browser gampang dilewati)
    if (!rate_boleh($pdo, "$ip:/api/register", $cfg['RATE_MAX'], $cfg['RATE_WINDOW'])) {
        json(429, ['error' => 'Kebanyakan request, coba lagi sebentar']);
    }

    $q = $pdo->prepare('SELECT status FROM users WHERE email = :e');
    $q->execute([':e' => $email]);
    $ada = $q->fetch(PDO::FETCH_ASSOC);

    if ($ada && $ada['status'] === 'active') {
        json(409, ['error' => 'Email sudah terdaftar, silakan login']);
    }
    // Username unik (case-insensitive). Dicek SEBELUM insert biar jawab 409 rapi, bukan 500.
    if ($username !== '') {
        $q = $pdo->prepare('SELECT id FROM users WHERE username = :u COLLATE NOCASE');
        $q->execute([':u' => $username]);
        if ($q->fetch() !== false) json(409, ['error' => 'Username sudah dipakai']);
    }
    if (!$ada) {
        $s = $pdo->prepare("INSERT INTO users (email, password_hash, nama, telepon, username, nama_depan, nama_belakang, tanggal_lahir, status, created_at) VALUES (:e, :h, :n, :t, :u, :d, :b, :g, 'pending', :c)");
        $s->execute([':e' => $email, ':h' => password_hash($pw, PASSWORD_BCRYPT), ':n' => $nama, ':t' => $telepon, ':u' => ($username !== '' ? $username : null), ':d' => ($depan !== '' ? $depan : null), ':b' => ($belakang !== '' ? $belakang : null), ':g' => ($tgl !== '' ? $tgl : null), ':c' => now_iso()]);
    }
    // Kalau pending: data LAMA dipertahankan (pendaftaran pertama yang sah), OTP-nya yang diperbarui.
    // Note: role TIDAK PERNAH diambil dari request — register selalu 'user' (default DB).

    $kode = (string) random_int(100000, 999999);   // random_int = acak kripto, bukan rand()
    $pdo->prepare('DELETE FROM otps WHERE email = :e')->execute([':e' => $email]);
    $s = $pdo->prepare('INSERT INTO otps (email, kode, expired_at) VALUES (:e, :k, :x)');
    $s->execute([':e' => $email, ':k' => $kode, ':x' => plus_detik($cfg['OTP_TTL'])]);

    try {
        kirim_otp($cfg, $email, $kode, 'daftar');
    } catch (Throwable $t) {
        error_log('Gagal kirim OTP: ' . $t->getMessage());
        json(500, ['error' => 'Gagal kirim OTP, coba lagi sebentar']);
    }
    // OTP selalu ke inbox email pendaftar (Gmail beneran).
    json(201, ['message' => 'OTP dikirim ke email (cek inbox email kamu)', 'cek' => '(cek inbox email kamu)']);
}

// Bikin token opaque: 32 byte acak kripto → 64 hex. Simpan + expired 1 jam. Balikin stringnya.
function buat_token(PDO $pdo, array $cfg, string $email): string {
    $token = bin2hex(random_bytes(32));
    $s = $pdo->prepare('INSERT INTO sessions (token, email, expired_at) VALUES (:t, :e, :x)');
    $s->execute([':t' => $token, ':e' => $email, ':x' => plus_detik($cfg['TOKEN_TTL'])]);
    return $token;
}

// VERIFY: tukar kode OTP jadi token. Salah 5x → kunci 10 menit (OTP dibuang biar minta baru).
function handle_verify(PDO $pdo, array $cfg, array $in, string $ip): void {
    $email = strtolower(trim($in['email'] ?? ''));
    $kode = trim($in['kode'] ?? '');

    if (!valid_email($email) || $kode === '') json(400, ['error' => 'Kode salah atau kedaluwarsa']);
    if (!rate_boleh($pdo, "$ip:/api/verify", $cfg['RATE_MAX'], $cfg['RATE_WINDOW'])) {
        json(429, ['error' => 'Kebanyakan request, coba lagi sebentar']);
    }

    $q = $pdo->prepare('SELECT * FROM otps WHERE email = :e AND dipakai = 0 ORDER BY id DESC LIMIT 1');
    $q->execute([':e' => $email]);
    $otp = $q->fetch(PDO::FETCH_ASSOC);

    $salah = $otp === false
        || $otp['kode'] !== $kode
        || strtotime($otp['expired_at']) < time();
    if ($salah) {
        if ($otp !== false) { // catat percobaan. 5x salah → buang OTP, user minta baru via register.
            $coba = (int) $otp['attempts'] + 1;
            $s = $pdo->prepare('UPDATE otps SET attempts = :c WHERE id = :id');
            $s->execute([':c' => $coba, ':id' => $otp['id']]);
            if ($coba >= 5) {
                $pdo->prepare('DELETE FROM otps WHERE id = :id')->execute([':id' => $otp['id']]);
            }
        }
        json(400, ['error' => 'Kode salah atau kedaluwarsa']); // sengaja generik
    }

    $pdo->prepare('UPDATE otps SET dipakai = 1 WHERE id = :id')->execute([':id' => $otp['id']]);
    $pdo->prepare("UPDATE users SET status = 'active' WHERE email = :e")->execute([':e' => $email]);
    json(200, ['token' => buat_token($pdo, $cfg, $email), 'user' => ['email' => $email, 'nama' => nama_of($pdo, $email)]]);
}

// LOGIN: pintu harian. Dua jalur:
// - Bentuk email → JALUR LAMA (JobTracker), perilaku byte-identical.
// - Bukan email → JALUR BARU (Mini Leads username, lowercase).
// Pesan gagal SELALU generik per jalur (anti panen email/username).
function handle_login(PDO $pdo, array $cfg, array $in, string $ip): void {
    $raw = trim($in['identifier'] ?? $in['email'] ?? '');
    $pw = $in['password'] ?? '';

    if (valid_email($raw)) {
        $email = strtolower($raw);
        $GAGAL = 'Email atau password salah';

        if (!is_string($pw) || $pw === '') json(401, ['error' => $GAGAL]);
        if (!rate_boleh($pdo, "$ip:/api/login", $cfg['RATE_MAX'], $cfg['RATE_WINDOW'])) {
            json(429, ['error' => 'Kebanyakan request, coba lagi sebentar']);
        }

        $q = $pdo->prepare('SELECT password_hash, status, nama FROM users WHERE email = :e');
        $q->execute([':e' => $email]);
        $u = $q->fetch(PDO::FETCH_ASSOC);

        if ($u === false || $u['status'] !== 'active' || !password_verify($pw, $u['password_hash'])) {
            json(401, ['error' => $GAGAL]); // email ngawur / pending / password salah → sama semua
        }
        json(200, ['token' => buat_token($pdo, $cfg, $email), 'user' => ['email' => $email, 'nama' => (string) $u['nama']]]);
    }

    $u = strtolower($raw);
    $GAGAL = 'Username atau password salah';

    if ($u === '' || !is_string($pw) || $pw === '') json(401, ['error' => $GAGAL]);
    if (!rate_boleh($pdo, "$ip:/api/login", $cfg['RATE_MAX'], $cfg['RATE_WINDOW'])) {
        json(429, ['error' => 'Kebanyakan request, coba lagi sebentar']);
    }

    $q = $pdo->prepare('SELECT email, password_hash, status, nama, username FROM users WHERE username = :u COLLATE NOCASE');
    $q->execute([':u' => $u]);
    $r = $q->fetch(PDO::FETCH_ASSOC);

    if ($r === false || $r['status'] !== 'active' || !password_verify($pw, $r['password_hash'])) {
        json(401, ['error' => $GAGAL]); // username ngawur / pending / password salah → sama semua
    }
    json(200, ['token' => buat_token($pdo, $cfg, $r['email']), 'user' => ['email' => $r['email'], 'nama' => (string) $r['nama'], 'username' => (string) $r['username'], 'role' => role_of($pdo, $r['email'])]]);
}

// Nama user buat sapaan di frontend (telepon tidak dikirim ke browser — tidak dibutuhkan di sana).
function nama_of(PDO $pdo, string $email): string {
    $q = $pdo->prepare('SELECT nama FROM users WHERE email = :e');
    $q->execute([':e' => $email]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? (string) $r['nama'] : '';
}

// Username + role buat guard Mini Leads (JobTracker abaikan field tambahan ini).
function username_of(PDO $pdo, string $email): string {
    $q = $pdo->prepare('SELECT username FROM users WHERE email = :e');
    $q->execute([':e' => $email]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return ($r && $r['username'] !== null) ? (string) $r['username'] : '';
}

function role_of(PDO $pdo, string $email): string {
    $q = $pdo->prepare('SELECT role FROM users WHERE email = :e');
    $q->execute([':e' => $email]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return ($r && $r['role'] !== null) ? (string) $r['role'] : 'user';
}

// ME: satpam cek kartu. Balikin email kalau token sah + belum expired, else 401.
function email_dari_token(PDO $pdo, string $auth): ?string {
    if (!str_starts_with($auth, 'Bearer ')) return null;
    $token = trim(substr($auth, 7));
    if ($token === '') return null;
    $q = $pdo->prepare('SELECT email, expired_at FROM sessions WHERE token = :t');
    $q->execute([':t' => $token]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if ($s === false || strtotime($s['expired_at']) < time()) return null;
    return $s['email'];
}

// FORGOT: minta OTP reset. Respon SELALU sama walau email ngawur (anti panen email).
// OTP cuma dibuat kalau email user active.
function handle_forgot(PDO $pdo, array $cfg, array $in, string $ip): void {
    $email = strtolower(trim($in['email'] ?? ''));
    $PESAN = 'Kalau email terdaftar, OTP reset sudah dikirim';

    if (!valid_email($email)) json(200, ['message' => $PESAN]); // email ngawur pun 200
    if (!rate_boleh($pdo, "$ip:/api/forgot", $cfg['RATE_MAX'], $cfg['RATE_WINDOW'])) {
        json(429, ['error' => 'Kebanyakan request, coba lagi sebentar']);
    }

    $q = $pdo->prepare("SELECT id FROM users WHERE email = :e AND status = 'active'");
    $q->execute([':e' => $email]);
    if ($q->fetch() !== false) {
        $kode = (string) random_int(100000, 999999);
        $pdo->prepare('DELETE FROM reset_tokens WHERE email = :e')->execute([':e' => $email]);
        $s = $pdo->prepare('INSERT INTO reset_tokens (email, kode, expired_at) VALUES (:e, :k, :x)');
        $s->execute([':e' => $email, ':k' => $kode, ':x' => plus_detik(600)]); // 10 menit
        try {
            kirim_otp($cfg, $email, $kode, 'reset');
        } catch (Throwable $t) {
            error_log('Gagal kirim OTP reset: ' . $t->getMessage());
            // Tetap jawab generik: jangan bocorkan gagal kirim = email ada.
        }
    }
    json(200, ['message' => $PESAN]);
}

// RESET: tukar OTP reset + password baru. Sukses → semua session email itu dihanguskan.
function handle_reset(PDO $pdo, array $cfg, array $in, string $ip): void {
    $email = strtolower(trim($in['email'] ?? ''));
    $kode = trim($in['kode'] ?? '');
    $baru = $in['password_baru'] ?? '';

    if (!valid_email($email) || $kode === '') json(400, ['error' => 'Kode salah atau kedaluwarsa']);
    if (($e = password_error($baru)) !== null) json(400, ['error' => $e]);
    if (!rate_boleh($pdo, "$ip:/api/reset", $cfg['RATE_MAX'], $cfg['RATE_WINDOW'])) {
        json(429, ['error' => 'Kebanyakan request, coba lagi sebentar']);
    }

    $q = $pdo->prepare('SELECT * FROM reset_tokens WHERE email = :e AND dipakai = 0 ORDER BY id DESC LIMIT 1');
    $q->execute([':e' => $email]);
    $rt = $q->fetch(PDO::FETCH_ASSOC);

    if ($rt === false || $rt['kode'] !== $kode || strtotime($rt['expired_at']) < time()) {
        json(400, ['error' => 'Kode salah atau kedaluwarsa']); // sengaja generik
    }

    $pdo->prepare('UPDATE reset_tokens SET dipakai = 1 WHERE id = :id')->execute([':id' => $rt['id']]);
    $s = $pdo->prepare('UPDATE users SET password_hash = :h WHERE email = :e');
    $s->execute([':h' => password_hash($baru, PASSWORD_BCRYPT), ':e' => $email]);
    $pdo->prepare('DELETE FROM sessions WHERE email = :e')->execute([':e' => $email]); // ganti kunci → semua kartu lama mati
    json(200, ['message' => 'Password diganti, silakan login']);
}
