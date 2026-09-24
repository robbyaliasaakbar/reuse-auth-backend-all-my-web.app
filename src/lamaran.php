<?php
// lamaran.php — CRUD lamaran per user. Semua butuh token (email pemilik dari sessions).
// Pola sama kayak auth.php: handle_* baca body(), jawab json(), parameterized query.

const LAMARAN_STATUS = ['baru', 'interview-hr', 'technical-test', 'interview-user', 'offering', 'diterima', 'ditolak'];

// Rapihin 1 baris DB ke bentuk frontend (id string biar onclick aman).
function lamaran_row(array $r): array {
    return [
        'id' => (string) $r['id'],
        'company' => (string) $r['company'],
        'position' => (string) $r['position'],
        'date' => (string) $r['tanggal'],
        'status' => (string) $r['status'],
        'portal' => (string) $r['portal'],
        'link' => (string) $r['link'],
        'createdAt' => (string) $r['created_at'],
    ];
}

// Validasi field lamaran. Return array bersih atau json(422) + exit kalau jebol.
function lamaran_bersih(array $in): array {
    $company = trim((string) ($in['company'] ?? ''));
    $position = trim((string) ($in['position'] ?? ''));
    $tanggal = trim((string) ($in['date'] ?? $in['tanggal'] ?? ''));
    $status = trim((string) ($in['status'] ?? 'baru'));
    $portal = trim((string) ($in['portal'] ?? ''));
    $link = trim((string) ($in['link'] ?? ''));
    if ($company === '' || $position === '') json(422, ['error' => 'Perusahaan dan posisi wajib diisi']);
    if ($tanggal !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) json(422, ['error' => 'Tanggal harus format yyyy-mm-dd']);
    if (!in_array($status, LAMARAN_STATUS, true)) json(422, ['error' => 'Status tidak dikenal']);
    if ($link !== '' && !preg_match('/^https?:\/\//i', $link)) $link = 'https://' . $link;
    return compact('company', 'position', 'tanggal', 'status', 'portal', 'link');
}

// GET /api/lamaran — list milik sendiri, baru dulu.
function handle_lamaran_list(PDO $pdo, string $email): void {
    $q = $pdo->prepare('SELECT * FROM lamaran WHERE email = :e ORDER BY tanggal DESC, id DESC LIMIT 500');
    $q->execute([':e' => $email]);
    json(200, ['data' => array_map('lamaran_row', $q->fetchAll(PDO::FETCH_ASSOC))]);
}

// POST /api/lamaran — tambah 1 milik sendiri.
function handle_lamaran_create(PDO $pdo, string $email, array $in): void {
    $b = lamaran_bersih($in);
    $now = now_iso();
    $q = $pdo->prepare('INSERT INTO lamaran (email, company, position, tanggal, status, portal, link, created_at, updated_at)
        VALUES (:e, :c, :p, :t, :s, :po, :l, :now, :now)');
    $q->execute([':e' => $email, ':c' => $b['company'], ':p' => $b['position'], ':t' => $b['tanggal'], ':s' => $b['status'], ':po' => $b['portal'], ':l' => $b['link'], ':now' => $now]);
    $id = (int) $pdo->lastInsertId();
    $row = $pdo->prepare('SELECT * FROM lamaran WHERE id = :id');
    $row->execute([':id' => $id]);
    json(201, lamaran_row($row->fetch(PDO::FETCH_ASSOC)));
}

// Ambil 1 milik sendiri atau null (anti intip data orang).
function lamaran_milik(PDO $pdo, string $email, string $id): ?array {
    if (!ctype_digit($id)) return null;
    $q = $pdo->prepare('SELECT * FROM lamaran WHERE id = :id AND email = :e');
    $q->execute([':id' => (int) $id, ':e' => $email]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

// PUT /api/lamaran/:id — edit milik sendiri.
function handle_lamaran_update(PDO $pdo, string $email, string $id, array $in): void {
    if (lamaran_milik($pdo, $email, $id) === null) json(404, ['error' => 'Data tidak ditemukan']);
    $b = lamaran_bersih($in);
    $pdo->prepare('UPDATE lamaran SET company=:c, position=:p, tanggal=:t, status=:s, portal=:po, link=:l, updated_at=:now WHERE id=:id AND email=:e')
        ->execute([':c' => $b['company'], ':p' => $b['position'], ':t' => $b['tanggal'], ':s' => $b['status'], ':po' => $b['portal'], ':l' => $b['link'], ':now' => now_iso(), ':id' => (int) $id, ':e' => $email]);
    $row = $pdo->prepare('SELECT * FROM lamaran WHERE id = :id');
    $row->execute([':id' => (int) $id]);
    json(200, lamaran_row($row->fetch(PDO::FETCH_ASSOC)));
}

// DELETE /api/lamaran/:id — hapus milik sendiri.
function handle_lamaran_delete(PDO $pdo, string $email, string $id): void {
    if (lamaran_milik($pdo, $email, $id) === null) json(404, ['error' => 'Data tidak ditemukan']);
    $pdo->prepare('DELETE FROM lamaran WHERE id = :id AND email = :e')->execute([':id' => (int) $id, ':e' => $email]);
    json(200, ['ok' => true]);
}
