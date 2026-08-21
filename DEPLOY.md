# Panduan Deploy E-Recepsionis

## Penting: Vercel tidak cocok untuk aplikasi penuh ini

E-Recepsionis adalah **PHP + MySQL** (upload file, session admin, API PHP, mysqli).

| Platform | Cocok? | Alasan |
|----------|--------|--------|
| **Vercel** | ❌ Tidak untuk app penuh | Vercel untuk frontend/Node (Next.js, static). Tidak menjalankan PHP + MySQL + upload seperti hosting tradisional. |
| **VPS / shared hosting PHP** (Hostinger, Niagahoster, cPanel, VPS yang sudah dipakai) | ✅ Ya | Cocok: PHP, MySQL, folder `uploads/` |
| **Railway / Render / Fly.io** | ⚠️ Bisa, lebih rumit | Perlu image PHP + MySQL terpisah |

**Kesimpulan trial Vercel:** jangan pakai Vercel sebagai host utama E-Recepsionis. Gunakan hosting PHP. Vercel hanya masuk akal jika suatu saat Anda memisahkan frontend React statis — backend tetap harus di server PHP.

---

## Checklist siap hosting (PHP)

### 1. Push kode ke GitHub
Pastikan akun GitHub punya akses push ke repo.

```bash
git push origin main
```

### 2. Siapkan database di hosting
1. Buat database MySQL + user di panel hosting.
2. Import salah satu:
   - `database.sql` (kosong / struktur dasar), atau
   - dump dari lokal / VPS yang sudah jalan
3. Di server, jalankan migrasi:

```bash
php migrations/ensure_latest_schema.php
```

### 3. Buat `config.local.php` di server
```bash
cp config.local.example.php config.local.php
```

Isi:
- `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`
- `PUBLIC_BASE_URL` → URL publik lengkap (contoh `https://domain.com/Recepsionis/`)

**Jangan commit** `config.local.php`.

### 4. Upload file
Upload seluruh project (kecuali `node_modules/`, `.git/` opsional, `config.local.php` dibuat di server).

Pastikan folder writable:
- `uploads/`
- `uploads/floor_plans/`
- `uploads/tv_info/`

```bash
chmod -R 775 uploads
```

### 5. Document root
Arahkan domain / subdomain ke folder project (tempat `index.php` berada).  
Jika di subfolder, `PUBLIC_BASE_URL` harus menyertakan path tersebut.

### 6. Setelah live
1. Login admin → **Settings** → isi URL publik & WhatsApp jika dipakai.
2. Coba check-in visitor, helpdesk, denah, laporan.
3. Ganti password default admin.

### 7. Opsional: Live Chat (Node)
Hanya jika memakai live chat Socket.io — jalankan `realtime-server/` di VPS terpisah / process manager. Tidak wajib untuk helpdesk + laporan + denah.

---

## Alternatif jika tetap ingin “coba Vercel”

Vercel bisa dipakai hanya untuk **demo landing React** (`visitor-app`), tanpa backend. Itu **bukan** E-Recepsionis penuh (tanpa login admin, database, helpdesk).

Untuk trial penuh yang benar:
1. Deploy ke **shared hosting PHP** atau VPS yang sudah ada (`103.107.4.29:89` dll.)
2. Atau layanan PHP managed (contoh: Hostinger, Cloudways, dsb.)

---

## File penting produksi

| File | Fungsi |
|------|--------|
| `config.local.example.php` | Template → salin jadi `config.local.php` |
| `koneksi.php` | Baca DB dari `config.local.php` / env |
| `migrations/ensure_latest_schema.php` | Samakan skema DB |
| `.gitignore` | Melindungi secret & flag maintenance |

## Jangan di-upload / di-commit

- `config.local.php`
- `realtime-server/.env`
- password / API token WhatsApp di repo publik
- dump database berisi data sensitif (kecuali private)
