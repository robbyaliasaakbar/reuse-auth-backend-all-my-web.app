<?php
// response.php — 2 helper: json() buat jawab, body() buat baca request JSON.

function json(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// Baca php://input sekali, balikin array. Body rusak → array kosong (validasi yang menolak).
function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
