# Central Auth & API Service (Backend)

Backend auth service berbasis PHP 8.3 CLI dan SQLite yang berjalan dalam Docker container. Dirancang sebagai shared backend untuk berbagai frontend webapp (Job Tracker, Mini Leads, dsb.).

## 🚀 Fitur Utama

- **Authentication & Authorization**: Register, Login, Verify OTP, Reset Password, Session Token (Bearer).
- **Email Service**: Integrasi Gmail SMTP mentah (STARTTLS) untuk pengiriman OTP tanpa library eksternal.
- **Data Management**: Profile update (`/api/me`) dan sinkronisasi CRUD Lamaran (`/api/lamaran`).
- **Security**:
  - Hashing password menggunakan `bcrypt` (`PASSWORD_BCRYPT`).
  - Parameterized query (PDO SQLite) untuk pencegahan SQL injection.
  - Rate limiting berbasis IP & waktu (tabel `rate_limits`).
  - CORS whitelist origin.

## 🛠️ Stack Teknologi

- **Runtime**: PHP 8.3 CLI (via Docker)
- **Database**: SQLite 3 (PDO SQLite)
- **Container**: Docker & Docker Compose

## 📁 Struktur Direktori

```text
.
├── PRD/                    # Dokumen spesifikasi & requirements (PRD)
├── data/                   # File database SQLite (auth.db) - di-ignore oleh git
│   └── .gitkeep
├── public/                 # Entrypoint server (public/index.php)
├── src/                    # Source code & logic modular
│   ├── auth.php            # Handler alur autentikasi (register, verify, login, reset)
│   ├── config.php          # Parser file .env tanpa dependency
│   ├── db.php              # Inisialisasi PDO SQLite & migrasi tabel
│   ├── lamaran.php         # CRUD data lamaran kerja
│   ├── mail.php            # Kirim email via socket SMTP STARTTLS
│   ├── profile.php         # Handler update profil user
│   ├── response.php        # Helper format JSON response & request body
│   └── validate.php        # Validasi email, password, & rate limiter
├── Dockerfile              # Setup image PHP 8.3 + pdo_sqlite
├── docker-compose.yml      # Orchestration container
├── .env.example            # Template environment variables
└── .gitignore              # Rules pencegahan commit secrets & database
```

## ⚙️ Cara Menjalankan

### 1. Salin File Environment
Buat file `.env` dari template:
```bash
cp .env.example .env
```

Sesuaikan konfigurasi di `.env`, terutama kredensial SMTP Gmail:
- `SMTP_USER`: Alamat Gmail pengirim.
- `SMTP_PASS`: 16 digit **Google App Password** (bukan password login biasa).
- `CORS_ORIGINS`: Daftar URL frontend yang diizinkan (pisahkan dengan koma).

### 2. Jalankan Container
Nyalakan service dengan Docker Compose:
```bash
docker compose up -d --build
```

Service akan aktif di port `7002`.

### 3. Verifikasi
Cek status service lewat endpoint health check:
```bash
curl http://localhost:7002/api/health
```
Respons sukses:
```json
{"ok":true,"time":"..."}
```

### 4. Menghentikan Service
```bash
docker compose down
```
> Data database SQLite di `./data/auth.db` akan tetap aman tersimpan di host machine.
# reuse-auth-backend-all-my-web.app
