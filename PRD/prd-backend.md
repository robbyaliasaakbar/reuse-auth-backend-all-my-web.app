# PRD - Backend Auth (project-2.1)

> **Tanggal:** 2026-09-09
> **Pemilik:** Bang Rob (supervisi AI, keputusan di Bang Rob)
> **Lokasi:** `project-kedua/backend/` (1 kesatuan sama webapp di `project-kedua/`)
> **Status:** BUILDING - PHP locked 2026-09-09 (lihat Bagian 12)

**Ide inti (dari Bang Rob):** 1 backend khusus login, dipakai rame-rame oleh banyak webapp.
Job Tracker (`:7001`) jadi pelanggan pertama. Webapp berikutnya (`:7003`, ...) tinggal daftar,
gak usah bikin login lagi.

**Batasan scope (dikunci):** fokus lokal + alur auth jalan. Deploy online + checklist security
penuh = **project lain**, tidak dibahas di sini selain catatan singkat.

---

## 1. Latar Belakang & Tujuan

**Masalah:** tiap webapp butuh register + OTP + login + lupa password. Kalau dibangun
di tiap app, duplikat 5x dan gampang beda perilaku.

**Tujuan:** 1 service auth lokal yang melayani banyak frontend. Frontend cuma lempar
`fetch`, semua keputusan (hash, OTP, expiry, token) di backend.

**Prinsip belajar:** sama kayak Job Tracker — Udin bangun simple tapi polanya bener,
Bang Rob bedah setelah jadi. AI bikin syntax + saran, keputusan di Bang Rob.

---

## 2. User & Cara Jalanin

- **User:** Bang Rob (developer + satu-satunya user webapp saat ini).
- **Cara jalanin (1 command, semua nyala — PHP jalan di Docker karena laptop tidak ada PHP native):**
  ```bash
  # dari folder project-kedua/backend/
  docker compose up --build
  # Terminal lain - webapp (project-kedua/)
  python3 -m http.server 7001
  ```
  Buka webapp di `http://localhost:7001`. API di `http://localhost:7002`.
  OTP dikirim via Gmail ke inbox pendaftar (isi SMTP_USER/PASS di `.env` dulu).
  Matikan semua: `docker compose down` (data SQLite di `./data/` tetap ada).
- **Aturan isolasi:** project-pertama (`:7000`) dikunci FIX, tidak disentuh. Semua
  obok-obok auth di project-kedua + backend ini.
- **Tanpa backend:** webapp tetap tampil, tapi semua aksi login gagal (tampilan hidup, data mati).
  Ini perilaku yang benar, bukan bug.

---

## 3. Scope - Wajib vs Opsional

### WAJIB Fase A (register → OTP → login → token)
1. `POST /api/register` — daftar + kirim OTP 6 digit via Gmail ke inbox pendaftar
2. `POST /api/verify` — verifikasi OTP → user jadi active → dapat token
3. `POST /api/login` — login email + password → dapat token
4. `GET /api/me` — cek token masih sah (dipakai frontend buat route guard)
5. Aturan password: min 8 karakter + ada huruf besar + ada angka (dicek di server)
6. Rem dasar: max 5x/menit per IP di endpoint sensitif (anti brute force kasar)

### WAJIB Fase B (lupa password, dibangun setelah Fase A terverifikasi)
7. `POST /api/forgot` — minta OTP reset (respon selalu generik, biar email tidak bisa dipanen)
8. `POST /api/reset` — tukar OTP + password baru

### OPSIONAL (dikunci, JANGAN sekarang)
- Refresh token / logout semua device
- Kirim email beneran via Gmail SMTP (butuh internet + App Password, dibahas pas Fase B mau dites beneran)
- JWT (v1 pakai token opaque yang lebih gampang dipahami — lihat Bagian 7)
- Deploy online + hardening penuh (project lain)

---

## 4. Data Model (SQLite, 1 file `auth.db`)

```sql
users(id INTEGER PK, email TEXT UNIQUE, password_hash TEXT, nama TEXT, telepon TEXT, status TEXT, created_at TEXT)
-- status: 'pending' (daftar tapi belum verifikasi) → 'active'
-- migrasi: DB lama tanpa nama/telepon di-ALTER otomatis, data tidak hilang.

otps(id INTEGER PK, email TEXT, kode TEXT, expired_at TEXT, attempts INT DEFAULT 0, dipakai INT DEFAULT 0)
-- kode: 6 digit. expired: +5 menit. attempts: max 5 lalu dikunci sementara.

reset_tokens(id INTEGER PK, email TEXT, kode TEXT, expired_at TEXT, dipakai INT DEFAULT 0)
-- sama kayak otps tapi untuk lupa password. expired: +10 menit.

sessions(token TEXT PK, email TEXT, expired_at TEXT)
-- token opaque 64 hex (crypto.randomBytes). expired: +1 jam.
```

**Keputusan jujur (biar lu tahu tradeoff-nya):** kode OTP disimpan plaintext di DB lokal
dengan expiry pendek. Buat belajar lokal ini oke dan gampang di-tracing. Versi production
nanti di-hash juga — masuk project security, bukan di sini.

---

## 5. Detail Tiap Endpoint (kontrak frontend ↔ backend)

Format umum: request/response JSON. Error selalu `{ "error": "pesan generik" }`.

### 5.1 POST /api/register
- Request: `{ "email": "rob@mail.com", "nama": "Bang Rob", "telepon": "08123456789", "password": "Rahasia123", "password_konfirmasi": "Rahasia123" }`
- Validasi server: email format benar, nama wajib isi, telepon 9-15 digit (boleh + spasi strip),
  password ≥8 + huruf besar + angka, **konfirmasi wajib sama persis** (dicek di server, browser cuma cek duluan biar instan).
- Sukses `201`: `{ "message": "OTP dikirim ke email (cek inbox email kamu)", "cek": "(cek inbox email kamu)" }` — frontend tempel field `cek` ke teks form OTP (jangan hardcode).
- Gagal: `400` validasi (termasuk "Konfirmasi password tidak sama"), `409` email sudah terdaftar, `429` kebanyakan request.
- Efek: user `pending` + OTP 6 digit tersimpan + terkirim via Gmail ke inbox pendaftar.

### 5.2 POST /api/verify
- Request: `{ "email": "rob@mail.com", "kode": "482913" }`
- Sukses `200`: `{ "token": "abc...64hex", "user": { "email": "rob@mail.com", "nama": "Bang Rob" } }`
- Gagal: `400` "Kode salah atau kedaluwarsa" (sengaja generik). Salah 5x → kunci 10 menit.
- Efek: user jadi `active`, OTP ditandai dipakai, session 1 jam dibuat.

### 5.3 POST /api/login
- Request: `{ "email": "...", "password": "..." }`
- Sukses `200`: `{ "token": "...", "user": { "email": "...", "nama": "..." } }` (nama ikut biar frontend bisa nyapa; telepon tidak dikirim ke browser karena tidak dibutuhkan di sana)
- Gagal: `401` "Email atau password salah" (selalu ini, JANGAN bedain "email tidak ada"
  vs "password salah" — biar daftar email tidak bisa dipanen).
- Catatan: user `pending` tidak bisa login, disuruh verifikasi dulu.

### 5.4 GET /api/me
- Header: `Authorization: Bearer <token>`
- Sukses `200`: `{ "email": "...", "nama": "..." }`. Gagal: `401` token tidak sah/expired.
- Dipakai frontend tiap buka app: token mati → tendang ke halaman login.

### 5.5 POST /api/forgot
- Request: `{ "email": "..." }`
- Respon **selalu** `200`: `{ "message": "Kalau email terdaftar, OTP reset sudah dikirim" }`
  walau email tidak ada. OTP cuma dibuat kalau email `active`.
- Kenapa? Biar attacker tidak bisa nebak email mana yang terdaftar.

### 5.6 POST /api/reset
- Request: `{ "email": "...", "kode": "...", "password_baru": "Baru1234" }`
- Sukses `200`: `{ "message": "Password diganti, silakan login" }`
- Efek: password di-hash ulang, OTP reset ditandai dipakai, **semua session email itu dihapus**
  (paksa login ulang di semua tempat — pengecualian dari aturan "logout lokal", karena ini momen ganti kunci).

---

## 6. Alur E2E (yang pengen Bang Rob tahu)

### Alur happy path
```
1. Bang Rob buka localhost:7001 → frontend cek token (GET /api/me) → tidak ada → tampil form login
   ↓
2. Klik "Daftar" → isi nama+telepon+email+password → POST /api/register → "OTP dikirim"
   ↓
3. Buka inbox email pendaftar → salin 6 digit → isi form OTP → POST /api/verify → dapat token
   ↓
4. Frontend simpan token (localStorage key jobTracker.token) → masuk app → tabel lamaran tampil
   ↓
5. Refresh browser → frontend kirim token ke /api/me → sah → tetap di dalam
   ↓
6. Token 1 jam expired → /api/me 401 → tendang ke login (login ulang, bukan daftar ulang)
```

### Alur lupa password
```
1. Klik "Lupa password" → isi email → POST /api/forgot → pesan generik (selalu sama)
   ↓
2. Kalau email active: OTP reset masuk inbox → isi kode + password baru → POST /api/reset
   ↓
3. Semua session lama hangus → login ulang dengan password baru
```

### Cara frontend (7001) ngomong ke backend (7002)
```js
const API_URL = location.protocol + "//" + location.hostname + ":7002"; // ngikutin host browser (localhost/IP Tailscale), ganti manual hanya kalau backend beda mesin
fetch(API_URL + "/api/login", { method: "POST", headers: {"Content-Type":"application/json"}, body: JSON.stringify({email, password}) })
```
**Jebakan yang sudah diantisipasi:** beda port = beda origin, browser blokir fetch kecuali
backend mengizinkan. Jadi backend wajib `cors({ origin: ["http://localhost:7001"] })`.
Webapp baru (`:7003`) tinggal ditambah ke daftar itu — tidak ngoding ulang auth.

---

## 7. Arsitektur

```
project-kedua/
├── index.html, css/, js/   ← frontend :7001 (nanti store.js/token diganti fetch, UI tidak diubah)
├── PRD/, Checkpoint-Belajar/
└── backend/                ← service auth :7002 (folder ini, PHP di Docker)
    ├── PRD/prd-backend.md  ← file ini
    ├── Dockerfile          ← php:8.3-cli + pdo_sqlite (1 image, tanpa PHP native di laptop)
    ├── docker-compose.yml  ← service backend (:7002), Mailpit dihapus 2026-09-09
    ├── public/index.php    ← front controller: CORS + router + baca JSON
    ├── src/config.php      ← baca .env sederhana (tanpa library)
    ├── src/db.php          ← PDO SQLite + bikin 4 tabel kalau belum ada
    ├── src/response.php    ← helper json($code, $data)
    ├── src/validate.php    ← email + aturan password + rate limit SQLite
    ├── src/auth.php        ← logika register/verify/login/forgot/reset + hash + OTP + token
    ├── src/mail.php        ← kirim OTP via SMTP mentah (tanpa library — STARTTLS + AUTH ke Gmail)
    ├── .env                ← rahasia (TIDAK masuk git)
    ├── .env.example        ← contoh isi .env
    ├── .gitignore          ← data/, .env
    └── data/auth.db        ← database SQLite (dibuat otomatis, tidak masuk git)
```

**Kenapa token opaque (acak 64 hex di tabel sessions), bukan JWT?**
Rekomendasi Udin buat v1: opaque gampang di-tracing (cek = `SELECT` cocok/tidak + cek expired),
cabut session gampang (`DELETE`). JWT butuh pelajaran tanda-tangan digital + tidak bisa
dicabut sebelum expired. Nanti pas project security, JWT dibahas sebagai upgrade. Keputusan
tetap di Bang Rob — kalau mau langsung JWT, bilang aja, PRD direvisi.

**Kenapa kirim mail pakai SMTP mentah vanilla, bukan PHPMailer?**
Biar 0 dependency: tidak perlu Composer sama sekali, tiap baris bisa di-tracing
(EHLO → STARTTLS → AUTH → MAIL → RCPT → DATA → QUIT ke Gmail). PHPMailer dicatat sebagai
upgrade production di project security nanti.

**Reusable untuk banyak app (keputusan Bang Rob, Udin setuju):** token v1 berlaku untuk
semua webapp yang origin-nya terdaftar. Limitasi jujur: logout di 1 app tidak menendang
app lain (session dihapus per-token). Token pendek (1 jam) bikin resikonya kecil.

---

## 8. UI / Testing (backend tidak punya UI)

- Tidak ada halaman web di backend. Test pakai `curl` (lihat Bagian 10);
  OTP dibaca dari inbox email pendaftar.
- Frontend `:7001` adalah "UI"-nya: `auth.html` + guard + tombol Keluar (Step 4 DONE),
  tanpa mengubah tabel/chart yang sudah FIX polanya.

---

## 9. Tech Stack (LOCKED PHP 2026-09-09)

**Stack: PHP 8.3 + PDO SQLite + password_hash/password_verify bawaan + SMTP mentah vanilla + Docker.**
- PHP dipilih Bang Rob (lock PHP): sekeluarga sama `save.php` invoice, 1 mail dikit,
  fungsi auth penting (`password_hash`, `random_bytes`, `PDO`) sudah bawaan — 0 dependency.
- Docker dipilih karena laptop tidak ada PHP native + tidak bisa apt install (no sudo):
  1 image `php:8.3-cli + pdo_sqlite`, 1 command `docker compose up` nyalain backend.
- SQLite = 1 file `./data/auth.db`, nol install server, pola SQL-nya kepakai juga nanti di Postgres.
- Gmail SMTP (`smtp.gmail.com:587`, STARTTLS + AUTH via App Password) = satu-satunya jalur kirim.
  Mailpit dihapus total 2026-09-09 (compose, env, code, image) atas perintah Bang Rob.

**Yang disingkirkan (dicatat biar tidak ditanya ulang):** Node/Express (butuh belajar runtime baru),
PHPMailer/Composer (diganti SMTP vanilla biar tiap baris ke-tracing), JWT (diganti token opaque).

---

## 10. Verifikasi (curl checklist, tanpa frontend pun bisa)

```bash
# 1. Register (201 + OTP masuk inbox pendaftar; butuh SMTP_USER/PASS terisi di .env)
curl -X POST localhost:7002/api/register -H 'Content-Type: application/json' -d '{"email":"rob@mail.com","nama":"Bang Rob","telepon":"08123456789","password":"Rahasia123","password_konfirmasi":"Rahasia123"}'
# 2. Register lagi email sama (409)
curl -X POST localhost:7002/api/register -H 'Content-Type: application/json' -d '{"email":"rob@mail.com","password":"Rahasia123"}'
# 3. Verify salah (400 generik)
curl -X POST localhost:7002/api/verify -H 'Content-Type: application/json' -d '{"email":"rob@mail.com","kode":"000000"}'
# 4. Verify benar (200 + token) — salin kode dari inbox email pendaftar
curl -X POST localhost:7002/api/verify -H 'Content-Type: application/json' -d '{"email":"rob@mail.com","kode":"<6DIGIT>"}'
# 5. Login salah (401 generik)
curl -X POST localhost:7002/api/login -H 'Content-Type: application/json' -d '{"email":"rob@mail.com","password":"salah"}'
# 6. Login benar (200 + token)
curl -X POST localhost:7002/api/login -H 'Content-Type: application/json' -d '{"email":"rob@mail.com","password":"Rahasia123"}'
# 7. /api/me pakai token (200 email)
curl localhost:7002/api/me -H 'Authorization: Bearer <TOKEN>'
# 8. Forgot (200 generik, walau email ngawur juga 200)
curl -X POST localhost:7002/api/forgot -H 'Content-Type: application/json' -d '{"email":"rob@mail.com"}'
# 9. Reset pakai OTP (200) lalu login password baru (200)
curl -X POST localhost:7002/api/reset -H 'Content-Type: application/json' -d '{"email":"rob@mail.com","kode":"<6DIGIT>","password_baru":"Baru1234"}'
# 10. Token lama mati setelah reset (401) — bukti session dihanguskan
curl localhost:7002/api/me -H 'Authorization: Bearer <TOKEN_LAMA>'
```

10 lolos → backend DONE. 1 gagal → berhenti, benerin dulu.

---

## 11. Rencana Building (bertahap, 1 step = 1 confirm Bang Rob)

- **Step 0 — Rumahnya:** `Dockerfile + docker-compose.yml + .env.example + .gitignore` → `docker compose up` nyala, `/api/health` jawab OK.
- **Step 1 — Register:** `db.php + validate.php + mail.php + POST /register` → ceklis 1-2 lolos.
- **Step 2 — Verify + login + token + /me (+CORS 7001)** → ceklis 3-7 lolos.
- **Step 3 — Forgot + reset + hanguskan session** → ceklis 8-10 lolos.
- **Step 4 — Colok frontend :7001:** form auth + simpan token + guard `/api/me`. Tabel/chart/lamaran **tidak diubah polanya**.
- **Nanti (project lain):** SMTP beneran, hardening security, deploy.

---

## 12. Keputusan (locked vs terbuka)

**Locked (hasil diskusi 2026-09-09):**
1. ✅ 1 backend auth dipakai rame-rame banyak webapp (Job Tracker pelanggan pertama)
2. ✅ 2 server lokal: `:7001` webapp + `:7002` backend (Mailpit dihapus 2026-09-09, OTP via Gmail)
3. ✅ SQLite 1 file, 4 tabel (users, otps, reset_tokens, sessions)
4. ✅ OTP 6 digit (5 mnt) / reset (10 mnt), max 5x coba, respon generik anti-panen email
5. ✅ Token opaque 1 jam (JWT nanti), password bcrypt + aturan 8+besar+angka
6. ✅ Security deploy penuh = project lain. PRD backend nyusul di dalam `backend/` biar ga kepecah

**Terbuka (tidak ada — semua sudah di-lock):**
7. ✅ Stack: **PHP** (lock Bang Rob 2026-09-09, alasan: sekeluarga save.php). Jalan via Docker
   karena laptop tidak ada PHP native + no sudo. Mail vanilla (PHPMailer = upgrade nanti).

*PRD ini BUILDING. Step 0-1 jalan dulu, tiap step nunggu kata Bang Rob baru next.*
