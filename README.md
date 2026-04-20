# Pantau Jalan — Backend PHP v7

Backend PHP Native + MySQL untuk website monitoring jalan rusak.

---

## Struktur Folder

```
pantau-jalan/          ← taruh di C:\laragon\www\
├── index.html         ← Frontend (dari ZIP v7)
├── laporan.html
├── admin.html
├── peta.html
├── detail.html
├── assets/
│   ├── store.js
│   ├── utils.js
│   ├── nav.js
│   └── style.css
├── api/               ← Backend PHP (folder ini)
│   ├── reports.php        GET semua laporan publik, POST laporan baru
│   ├── report_detail.php  GET detail 1 laporan + history
│   ├── admin.php          Login, update status, hapus laporan
│   └── upload.php         Upload foto
├── config/
│   ├── database.php       Konfigurasi koneksi MySQL
│   └── cors.php           Header CORS
├── uploads/
│   └── photos/            Foto yang diupload warga
├── database.sql           Script SQL untuk setup database
├── .htaccess              Keamanan folder
└── README.md              Panduan ini
```

---

## Cara Setup (Laragon)

### 1. Letakkan folder di www
Ekstrak dan pindahkan folder menjadi:
```
C:\laragon\www\pantau-jalan\
```

### 2. Buat database
- Buka **Laragon → Menu → Database → HeidiSQL** (atau phpMyAdmin)
- Buat database baru: `pantau_jalan`
- Klik tab **Query**, paste isi `database.sql`, klik **Run**

### 3. Sesuaikan config database (jika perlu)
Edit `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'pantau_jalan');
define('DB_USER', 'root');   // default Laragon
define('DB_PASS', '');       // default Laragon (kosong)
```

### 4. Generate password yang benar
Password default di database.sql adalah `password` (bukan admin123).
Untuk ganti ke password yang kamu mau, jalankan di terminal Laragon:
```bash
php -r "echo password_hash('admin123', PASSWORD_BCRYPT, ['cost'=>12]);"
```
Lalu update kolom `password` di tabel `users`.

### 5. Akses website
Buka browser: `http://localhost/pantau-jalan/`

---

## API Endpoints

| Method | URL | Fungsi | Auth |
|--------|-----|--------|------|
| GET    | `/api/reports.php` | Ambil laporan publik | ❌ |
| GET    | `/api/reports.php?status=damaged` | Filter by status | ❌ |
| GET    | `/api/reports.php?from=2026-01-01&to=2026-12-31` | Filter by tanggal | ❌ |
| POST   | `/api/reports.php` | Kirim laporan baru | ❌ |
| GET    | `/api/report_detail.php?id=UUID` | Detail 1 laporan | ❌ |
| POST   | `/api/admin.php` (action=login) | Login admin/petugas | ❌ |
| POST   | `/api/admin.php` (action=logout) | Logout | ✅ |
| GET    | `/api/admin.php?action=get_all` | Semua laporan (+ pending) | ✅ |
| PUT    | `/api/admin.php` (action=update_status) | Ubah status laporan | ✅ |
| DELETE | `/api/admin.php` (action=delete) | Hapus laporan | ✅ Admin |
| POST   | `/api/upload.php` | Upload foto | ❌ |

---

## Contoh Request (JavaScript fetch)

### Kirim laporan baru
```javascript
const res = await fetch('/pantau-jalan/api/reports.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    roadName:    'Jl. Contoh',
    description: 'Jalan berlubang di depan kantor.',
    reporter:    'Budi',
    lat:         -2.2082,
    lng:         113.9136,
  })
});
const data = await res.json();
```

### Login admin
```javascript
const res = await fetch('/pantau-jalan/api/admin.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  credentials: 'include', // penting untuk session cookie
  body: JSON.stringify({ action: 'login', username: 'admin', password: 'admin123' })
});
```

### Update status laporan
```javascript
const res = await fetch('/pantau-jalan/api/admin.php', {
  method: 'PUT',
  headers: { 'Content-Type': 'application/json' },
  credentials: 'include',
  body: JSON.stringify({ action: 'update_status', id: 'UUID', status: 'fixed', note: 'Selesai.' })
});
```

---

## Langkah Selanjutnya

- [ ] Sambungkan `laporan.html` → `fetch()` ke `api/reports.php`
- [ ] Sambungkan `admin.html` → `fetch()` ke `api/admin.php`
- [ ] Sambungkan `peta.html` → `fetch()` ke `api/reports.php`
- [ ] Sambungkan `detail.html` → `fetch()` ke `api/report_detail.php`
- [ ] Upload foto via `api/upload.php`
