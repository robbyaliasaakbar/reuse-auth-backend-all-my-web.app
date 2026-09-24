<?php
// profile.php — Edit profil sendiri (nama + username). ADITIF: auth.php/lamaran.php tidak disentuh.
// Aturan: email + role TIDAK PERNAH dari request (ngikut token). Tanpa token = 401 di router.

function handle_profile_update(PDO $pdo, string $email, array $in): void {
    $nama = trim((string) ($in['nama'] ?? ''));
    $punyaUsername = array_key_exists('username', $in);
    $username = $punyaUsername ? trim((string) $in['username']) : null;

    if ($nama === '') json(422, ['error' => 'Nama lengkap wajib diisi']);

    // Ambil baris sendiri biar field yang tidak dikirim tidak ke-wipe.
    $q = $pdo->prepare('SELECT nama, username FROM users WHERE email = :e');
    $q->execute([':e' => $email]);
    $cur = $q->fetch(PDO::FETCH_ASSOC);
    if ($cur === false) json(404, ['error' => 'Akun tidak ditemukan']);

    $baruUsername = $cur['username'];
    if ($punyaUsername) {
        if ($username !== '' && ($e = username_error($username)) !== null) json(422, ['error' => $e]);
        if ($username !== '') {
            $c = $pdo->prepare('SELECT email FROM users WHERE username = :u COLLATE NOCASE');
            $c->execute([':u' => $username]);
            $r = $c->fetch(PDO::FETCH_ASSOC);
            if ($r !== false && strtolower((string) $r['email']) !== strtolower($email)) {
                json(409, ['error' => 'Username sudah dipakai']);
            }
            $baruUsername = $username;
        } else {
            $baruUsername = null; // dikosongkan eksplisit = lepas username
        }
    }

    $pdo->prepare('UPDATE users SET nama = :n, username = :u WHERE email = :e')
        ->execute([':n' => $nama, ':u' => $baruUsername, ':e' => $email]);
    json(200, ['email' => $email, 'nama' => $nama, 'username' => (string) ($baruUsername ?? ''), 'role' => role_of($pdo, $email)]);
}
