<?php
// mail.php — kirim email via SMTP MENTAH (tanpa library, biar tiap baris ke-tracing).
// Alur Gmail: EHLO → STARTTLS → EHLO → AUTH LOGIN → MAIL → RCPT → DATA → QUIT.
// Tiap perintah dicek kode balasannya. Gagal di mana pun → throw (caller jawab 500).

function smtp_kirim(array $cfg, string $ke, string $subjek, string $isi): void {
    $fp = @fsockopen($cfg['SMTP_HOST'], $cfg['SMTP_PORT'], $errno, $errstr, 10);
    if (!$fp) throw new RuntimeException("SMTP tidak terjangkau: $errstr");

    $baca = function () use ($fp): string {
        $res = '';
        while ($baris = fgets($fp, 512)) {           // balasan multi-baris diakhiri "kode + spasi"
            $res .= $baris;
            if (preg_match('/^\d{3} /', $baris)) break;
        }
        return $res;
    };
    $harap = function (string $kode) use ($baca): void {
        if (strpos($baca(), $kode) !== 0) throw new RuntimeException("SMTP menolak (harap $kode)");
    };
    $kirim = function (string $s) use ($fp): void { fwrite($fp, $s . "\r\n"); };

    $harap('220');                                   // sapaan server
    $kirim('EHLO jobtracker');
    $cap = $baca();                                 // balasan multi-baris "250-..." diakhiri "250 ..."
    if (strpos($cap, '250') !== 0) throw new RuntimeException('SMTP menolak EHLO');

    // Naikkan ke jalur aman dulu (wajib ke Gmail), baru login.
    if (stripos($cap, 'STARTTLS') === false) throw new RuntimeException('Server tidak tawarkan STARTTLS');
    $kirim('STARTTLS'); $harap('220');
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        throw new RuntimeException('Gagal bikin jalur aman (TLS)');
    }
    $kirim('EHLO jobtracker'); $baca();       // ulangi EHLO di atas jalur aman
    $kirim('AUTH LOGIN'); $harap('334');      // server minta username
    $kirim(base64_encode($cfg['SMTP_USER'])); $harap('334'); // server minta password
    $kirim(base64_encode($cfg['SMTP_PASS'])); $harap('235'); // 235 = login diterima
    $kirim('MAIL FROM:<' . $cfg['MAIL_FROM'] . '>'); $harap('250');
    $kirim("RCPT TO:<$ke>"); $harap('250');
    $kirim('DATA'); $harap('354');

    // Isi email: header + baris kosong + body. Titik di awal baris digandakan (aturan SMTP).
    $body = str_replace("\n.", "\n..", "From: {$cfg['MAIL_FROM']}\r\nTo: $ke\r\nSubject: $subjek\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$isi");
    $kirim($body . "\r\n."); $harap('250');
    $kirim('QUIT');
    fclose($fp);
}

function kirim_otp(array $cfg, string $ke, string $kode, string $buat): void {
    $subjek = $buat === 'reset' ? 'Kode reset password Job Tracker' : 'Kode verifikasi Job Tracker';
    $menit = $buat === 'reset' ? '10' : '5';
    smtp_kirim($cfg, $ke, $subjek,
        "Halo,\n\nKode $buat kamu: $kode\nBerlaku $menit menit. Jangan kasih ke siapa pun.\n\n— Job Tracker (lokal)");
}
