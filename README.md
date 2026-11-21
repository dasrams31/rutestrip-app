# RuteStrip

Live: https://rutestrip.ai.id (web utama) dan https://chat.rutestrip.ai.id (AI webchat).

Notebook Google Colab (metode & evaluasi): https://s.peka.ai.id/rutestrip-notebook
— sumbernya `python/RuteStrip_Colab.ipynb`, dibuat ulang dengan `python python/tools/build_colab_notebook.py python/RuteStrip_Colab.ipynb`.

RuteStrip adalah aplikasi Laravel untuk manajemen, pencarian, dan rekomendasi jalur pendakian berbasis file GPX. Aplikasi memakai SBERT untuk embedding/search semantic, Google Gemini untuk chatbot RAG, serta modul provenance GPX (versioning dan verifikasi hash SHA-256).

## Fitur

- Upload dan parsing file GPX.
- Ekstraksi jarak, elevasi, estimasi durasi Naismith, grade, dan koordinat rute.
- Search semantic berbasis SBERT `paraphrase-multilingual-MiniLM-L12-v2`.
- Rekomendasi rute menggunakan cosine similarity.
- Chatbot RAG berbasis Google Gemini.
- Login/register user dan dashboard admin.
- GPX provenance: versioning, hashing, verifikasi integritas, dan restore versi.
- Python processor bisa berjalan lokal atau remote via Hugging Face Spaces/FastAPI.

## Struktur Repository

```txt
/              Laravel app (rutestrip.ai.id)
/python        Python processor SBERT + RAG chatbot dipanggil Laravel
/webchat       Next.js AI webchat (chat.rutestrip.ai.id)
/webchat-api   FastAPI backend webchat: rekomendasi SBERT, cuaca, peta, GPX, auth, riwayat chat (PostgreSQL)
/deploy        Contoh Caddyfile dan unit systemd untuk VPS
```

## Deploy VPS (Caddy + PHP-FPM + systemd)

1. Clone ke `/var/www/rutestrip-final`, lalu setup Laravel seperti bagian "Deploy" di bawah (`.env`, `composer install --no-dev`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan storage:link`). Jangan jalankan `php artisan config:cache`, karena beberapa controller membaca `env()` langsung.
2. Webchat API: buat venv Python (`torch` CPU + `pip install -r webchat-api/requirements.txt`), PostgreSQL database `rutestrip`, salin `webchat-api/.env.example` ke `webchat-api/.env`, lalu pasang `deploy/rutestrip-webchat-api.service`.
3. Webchat: salin `webchat/.env.example` ke `webchat/.env.local`, jalankan `npm ci && npm run build` di folder `webchat`, lalu pasang `deploy/rutestrip-webchat.service`.
4. Tambahkan isi `deploy/Caddyfile` ke `/etc/caddy/Caddyfile` lalu `systemctl reload caddy`.

Link "AI Chat" di navbar Laravel memakai `WEBCHAT_URL` (default `https://chat.rutestrip.ai.id`).

## Data Jalur (GPX)

- `webchat-api/gpx_db/` berisi file GPX yang dipakai webchat dan bisa diimpor ke Laravel:
  `php artisan routes:import-gpx-dir webchat-api/gpx_db` (pakai `--dry-run` dulu). Command ini
  melewati duplikat, file gabungan banyak jalur, dan GPX dengan elevasi rusak.
- Sebagian GPX dibuat dari relasi jalur pendakian OpenStreetMap dengan
  `python/tools/osm_hiking_to_gpx.py` (elevasi SRTM 30 m via OpenTopoData). Data tersebut
  &copy; OpenStreetMap contributors, lisensi ODbL; daftar sumber ada di `webchat-api/gpx_db/OSM_SOURCES.md`.

## Upload GPX

- Halaman upload single (`/routes/create`) dan batch (`/routes-batch`) bisa dibuka siapa saja; pengunjung yang belum login diminta masuk/daftar dulu dan setelah login kembali ke halaman upload.
- Mengirim file butuh akun (user biasa maupun admin), dibatasi 10 kiriman per menit, dan setiap jalur menyimpan pengunggahnya (`hiking_routes.uploaded_by`) yang tampil sebagai "Diunggah oleh …". Jalur tanpa pengunggah ditampilkan sebagai "Tim RuteStrip".
- File harus XML dengan root `<gpx>` dan disimpan sebagai `.gpx` dengan nama acak; Caddy menyajikan `/storage/gpx_files/*` sebagai unduhan.

## SEO

- `/robots.txt` dan `/sitemap.xml` dibuat dinamis (semua halaman rute ikut masuk sitemap).
- URL rute memakai slug, misalnya `/routes/21-mt-sumbing-via-butuh-kaliangkrik` (link lama `/routes/21` tetap jalan).
- Setiap halaman punya title, meta description, canonical, Open Graph/Twitter card, dan JSON-LD
  (`WebSite`, `TouristAttraction`, `BreadcrumbList`). Halaman akun/admin/upload diberi `noindex`.
- Verifikasi Google Search Console / Bing: isi `GOOGLE_SITE_VERIFICATION` / `BING_SITE_VERIFICATION`
  di `.env` (Laravel) dan `NEXT_PUBLIC_GOOGLE_SITE_VERIFICATION` / `NEXT_PUBLIC_BING_SITE_VERIFICATION`
  di `webchat/.env.local` (lalu build ulang webchat).

## Arsitektur Deploy

```txt
User Browser
  -> Laravel App di cPanel/VPS
  -> MySQL/MariaDB
  -> Python Processor lokal atau Hugging Face Space
  -> Gemini API untuk chatbot
```

Mode yang direkomendasikan untuk shared cPanel:

```txt
cPanel: Laravel, Blade UI, auth, upload, database
Hugging Face Space: Python, SBERT, GPX processing, embedding
MySQL: data aplikasi dan embedding hasil proses
```

## Tech Stack

- Laravel 12, PHP 8.2+
- Blade, Tailwind CSS v3 (static build), Alpine.js, Leaflet
- MySQL/MariaDB
- Python, FastAPI, SBERT, scikit-learn, gpxpy
- Google Gemini API

## Setup Lokal

```bash
git clone https://github.com/dasrams31/rutestrip-final.git
cd rutestrip-final
composer install
npm install
npm run build
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Jalankan Laravel:

```bash
php artisan serve
```

CSS, JS, dan huruf situs di-host sendiri (tanpa CDN), jadi halaman tetap utuh di Brave Shields atau ad blocker. File hasil build ikut di-commit, server tidak perlu Node:

```bash
npm run build:css     # Tailwind v3 -> public/css/site.css (setelah menambah/mengubah class di Blade, PHP, atau JS)
npm run build:vendor  # Alpine, Leaflet, huruf Inter + Barlow Condensed -> public/vendor, public/fonts (setelah ganti versi di package.json)
```

Jalankan Python processor lokal:

```bash
cd python
pip install -r requirements.txt
uvicorn api:app --host 127.0.0.1 --port 7860
```

Set `.env` untuk memakai Python processor lokal:

```env
PYTHON_PROCESSOR_URL=http://127.0.0.1:7860
```

Kalau `PYTHON_PROCESSOR_URL` dikosongkan, Laravel fallback ke proses lokal `python/processor.py` lewat `PYTHON_PATH`.

## Environment Penting

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://domainmu.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=nama_database
DB_USERNAME=user_database
DB_PASSWORD=password_database

PYTHON_PATH=python
PYTHON_PROCESSOR_URL=

GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.6-flash
```

## Deploy Ke cPanel

1. Clone repository melalui cPanel Git Version Control.
2. Set document root domain/subdomain ke folder `public`.
3. Buat database MySQL dan update `.env`.
4. Jalankan command berikut melalui Terminal/SSH cPanel:

```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Untuk shared cPanel, gunakan Hugging Face Space untuk Python processor dan isi:

```env
PYTHON_PROCESSOR_URL=https://username-rutestrip-processor.hf.space
```

## Deploy Python Processor Ke Hugging Face Space

Gunakan Hugging Face Space dengan SDK Docker atau Python/FastAPI. Minimal file yang dibutuhkan dari repo ini:

- `python/api.py`
- `python/processor.py`
- `python/requirements.txt`

Start command:

```bash
uvicorn api:app --host 0.0.0.0 --port 7860
```

Endpoint yang tersedia:

- `GET /` health check
- `POST /ingest` upload GPX multipart field `gpx_file`
- `POST /embed` JSON `{ "query": "..." }`
- `POST /search` JSON `{ "query": "...", "routes": [...] }`

## Akun Default

Admin seeder membuat akun admin untuk login `/admin/login`. Jalankan seeder terkait bila database masih kosong.

```bash
php artisan db:seed --class=AdminSeeder
```

## Catatan

- Jangan commit `.env` karena berisi secret.
- Shared cPanel biasanya tidak cocok menjalankan SBERT langsung karena RAM, timeout, dan `exec()` bisa dibatasi.
- Hugging Face free Space bisa sleep, jadi request pertama dapat lambat.
- Untuk production stabil, pakai VPS/Railway/Render atau Hugging Face Space berbayar untuk Python processor.
