# DESIGN.md: RuteStrip

Arah desain dari pemilik produk (dijawab 21 November 2026). Dipakai bersama
filter antislop (mode: diterapkan saat redesain).

## Karakter

Pemandu senior: hangat, membumi, bisa dipercaya; bicara seperti pemandu yang
sudah ratusan kali naik.

## Warna

Zamrud + kuning fajar: tetap memakai warna logo yang sekarang, hijau zamrud
dengan aksen kuning matahari.

## Huruf judul

Sans tebal kondensed: tegas dan sporty, seperti papan penunjuk jalur atau
poster ekspedisi.

## Motif

Garis kontur topografi: garis ketinggian peta sebagai pola dan pembatas bagian.

## Karakter halaman

Berani & ekspresif: kesan petualangan, komposisi asimetris, tipografi besar,
gerak lebih banyak.

Dial: ENERGY 3 / RHYTHM 3 / MOTION 3

## Keputusan desain landing

Alasan satu baris per keputusan (antislop R-31), untuk halaman `/`
(`resources/views/search/index.blade.php`).

- Warna: zamrud gelap #053b30 untuk hero dan bagian AI Chat, zamrud #047857
  untuk tautan dan tombol sekunder, kertas peta #f6f4ec sebagai dasar; semua
  diambil dari logo dan arah "membumi".
- Aksen: kuning fajar #FCD34D (matahari di logo) hanya untuk tombol aksi utama
  (Cari jalur, Upload file GPX), berbingkai gelap seperti papan penunjuk jalur,
  supaya kuning selalu berarti "langkah berikutnya".
- Huruf judul: Barlow Condensed 700/800 (SIL OFL, Google Fonts) karena
  dirancang dari rambu jalan raya, cocok dengan "papan penunjuk jalur atau poster
  ekspedisi"; dimuat dengan display=optional agar judul tidak bergeser. Teks isi
  tetap Inter yang sudah dipakai situs.
- Motif: garis kontur dibuat deterministik di PHP (cincin bertingkat per puncak,
  setiap cincin ke-5 lebih tebal seperti garis indeks di peta cetak) dan dipakai
  di hero, pembatas bagian, latar kartu, AI Chat, dan Upload; jejak jalur di kartu
  adalah koordinat GPX asli, konturnya hanya pola.
- Hero: kolom kiri 7/12 untuk judul besar dan kotak pencarian karena mencari
  adalah aksi utama; kolom kanan satu jalur nyata dari database (desktop saja);
  angka database di bawahnya, dan angka yang tidak tersedia tidak ditampilkan.
- Jalur pilihan: satu kartu besar plus dua kartu mendatar, karena jalur teratas
  layak lebih menonjol; tingkat kesulitan berupa batang 1 sampai 4 berwarna
  zamrud agar palet tidak bertambah merah, oranye, atau kuning.
- Cara kerja: judul lengket di kiri dan jalur putus-putus berliku di kanan
  dengan penanda segitiga puncak (bukan lingkaran bernomor), berisi empat tahap
  yang memang ada di kode, plus contoh hitungan Naismith dengan angka jalur asli.
- Penelitian: susunan editorial (kutipan dan tautan di kiri, angka P@5 besar di
  kanan agak turun) dengan label tegas bahwa angkanya hasil artikel, bukan situs.
- AI Chat: dibalik (contoh percakapan di kiri, teks di kanan) dan berlatar gelap
  untuk memecah irama; contoh percakapan diberi keterangan sebagai contoh.
- Upload: satu kolom rata kiri di atas kontur besar, penutup yang tenang dengan
  satu aksi kuning.
- Jarak: padding bagian berbeda-beda (hero lebih rapat, Cara kerja dan Upload
  lebih lega) dan di ponsel kira-kira separuhnya; target sentuh minimal 44 px.
- Sudut dan bayangan: radius kecil 8 sampai 12 px, tanpa bentuk pil dan tanpa
  bayangan besar; elevasi cukup dari kontras permukaan.
- Gerak: kontur hero tergambar saat halaman dibuka, jejak GPX tertelusur, garis
  jalur Cara kerja tergambar dengan penanda muncul saat garis sampai, batang P@5
  tumbuh, parallax ringan pada kontur hero (tablet ke atas); hanya transform,
  opacity, dan stroke-dashoffset lewat IntersectionObserver, sekali jalan, dan
  semuanya mati dengan prefers-reduced-motion.
- Ikon: hanya ikon yang menunjuk benda nyata (kaca pembesar di kotak cari, hati
  untuk jumlah suka, robot karena tombol asisten di pojok memang ikon robot).

## Keputusan desain situs: landing, pencarian, daftar dan detail jalur (redesain 2)

Alasan satu baris per keputusan (antislop R-31) untuk `layouts/app`, `/`, hasil
pencarian, `/routes`, detail jalur, jalur mirip, dan `/kebijakan-cookie`.
Butir Hero dan Penelitian di bagian sebelumnya diganti oleh butir di bawah.
Dial tetap ENERGY 3 / RHYTHM 3 / MOTION 3 di landing; halaman alat (hasil,
daftar, detail) memakai ENERGY 2 / RHYTHM 2 / MOTION 1 karena pendaki datang
untuk membaca angka, bukan ditonton.

- Hero tanpa kartu contoh: kartu jalur di kanan bersaing dengan kotak cari, jadi
  dibuang; kotak cari melebar (max-w-3xl, tinggi 56 px) agar jadi satu-satunya
  fokus, kontur di kanan mengisi ruang kosong.
- Contoh pencarian: tiga di layar lebar, dua di ponsel, supaya tidak ada dinding
  chip di atas lipatan.
- Penelitian dihapus dari landing dan footer: angka evaluasi tidak membantu
  pendaki memilih jalur; artikel cukup satu baris "Publikasi" di footer tanpa angka.
- Cara kerja ditulis dengan bahasa pendaki; SBERT, cosine, dan bobot hanya di
  lipatan "Cara kami mencocokkan" untuk yang penasaran, karena istilah teknis di
  permukaan membuat kalimatnya terasa aneh.
- Label kecocokan berupa kata (Sangat cocok, Cocok, Lumayan cocok, Sedikit
  mirip) dan bukan "% cocok", karena persen dari skor gabungan tidak punya arti
  bagi pendaki; angkanya tetap ada di title dan teks pembaca layar.
- Kartu hasil memberi satu kalimat "kenapa muncul" dari angka yang ditanya
  (kemiringan, waktu, jarak), supaya rekomendasi bisa dicek, bukan dipercaya.
- Jalur pilihan di landing hanya jejak yang benar-benar terlihat seperti jalur
  (minimal 40 titik, kotak batas tidak tipis, tidak naik-turun di garis yang sama),
  karena garis lurus atau garis bolak-balik terlihat seperti data rusak.
- Token warna dipindah ke layout (`rs-z9`, `rs-z7`, `rs-paper`, `rs-line`,
  `rs-sun`) dan latar seluruh situs jadi kertas peta #f6f4ec, agar semua halaman
  terasa satu keluarga dengan landing.
- Barlow Condensed dimuat di layout untuk semua halaman, karena judul papan jalur
  adalah suara situs, bukan hanya suara landing.
- Navbar putih solid tanpa blur (R-10), tautan aktif ditandai garis zamrud 3 px di
  bawah seperti penanda jalur; tombol Upload zamrud gelap, bukan kuning, karena
  kuning disimpan untuk aksi utama di dalam halaman.
- Tombol menu di ponsel diberi tulisan "Menu", karena ikon hamburger saja tidak
  semua orang paham.
- Footer zamrud gelap dengan tiga garis kontur di tepi atas (motif dipakai sekali),
  satu daftar tautan dua kolom, tanpa daftar "fitur unggulan" dan tanpa pemilih
  bahasa (pemilih bahasa cukup di navbar dan menu ponsel).
- Header halaman alat (hasil, daftar, detail, mirip, cookie) memakai pita zamrud
  berkontur yang sama dengan landing, supaya pindah halaman tidak terasa pindah situs.
- Detail jalur: angka utama (jarak, waktu jalan, total naik, kemiringan) dan tombol
  suka naik ke header, karena itu yang dicari pertama di ponsel; cuaca diletakkan
  tepat setelah peta karena paling menentukan berangkat atau tidak.
- Data file GPX (versi, hash, unduhan, riwayat) dilipat dalam `<details>`, karena
  hanya sedikit orang yang perlu, tapi tetap ada untuk yang ingin memeriksa.
- Jalur mirip digambar sebagai garis SVG kecil, bukan peta Leaflet, karena empat
  peta mini memperlambat ponsel dan garis jalur sudah cukup untuk mengenali bentuknya.
- Kartu daftar jalur memakai thumbnail peta yang baru dibuat saat kartu mendekati
  layar dan titik jejak yang dipangkas ke 80, supaya halaman 12 jalur tetap ringan.
- Pemberitahuan cookie satu kalimat dengan tombol "Mengerti" di bar bawah, bukan
  pop-up, karena situs hanya memakai cookie fungsional; tidak ada pilihan tolak
  yang palsu.
- Tawaran pasang aplikasi muncul paling cepat 5 detik, tidak bersamaan dengan
  pemberitahuan cookie, tidak di halaman masuk atau unggah, dan diam 14 hari setelah
  "Nanti", karena tawaran yang datang terlalu cepat terasa seperti iklan.
- Kedua bar bawah duduk di atas tab bar dan menulis tingginya ke `--rs-banner-h`,
  agar tombol mengambang dan isi halaman bergeser, bukan tertutup.
- Pilihan status file GPX di daftar jalur menjadi satu baris tersegmentasi, karena
  tiga chip yang menumpuk di ponsel memakan layar sebelum jalur pertama terlihat.

## Keputusan desain halaman akun, unggah, dan admin (redesain 2)

Alasan satu baris per keputusan (antislop R-31) untuk halaman masuk, daftar, masuk
admin, pengaturan, dashboard, favorit, riwayat, unggah satuan dan sekaligus, versi
GPX baru, riwayat file GPX, arsitektur, kuesioner SUS, panel admin, dan
`offline.html`. Bahan bersama ada di `resources/views/partials/rs-*.blade.php`.
Dial halaman ini ENERGY 2 / RHYTHM 2 / MOTION 2: formulir dan tabel butuh tenang,
jadi energi dikumpulkan di pita judul, dan gerak hanya kontur yang tergambar sekali.

- Pita judul: setiap halaman dibuka dengan pita zamrud #053b30 berkontur dan judul
  Barlow Condensed huruf besar (`rs-head`), supaya pindah dari landing ke halaman
  akun tidak terasa pindah situs.
- Kuning #FCD34D hanya untuk satu aksi utama per halaman (Masuk, Buat akun, Unggah,
  Kirim jawaban, Unduh dataset), berbingkai gelap seperti papan jalur; aksi lain
  zamrud #047857 atau tombol bergaris.
- Halaman masuk dan daftar: panel zamrud di kiri berisi judul dan empat manfaat
  akun yang memang ada di kode; di ponsel panel menyusut jadi pita pendek supaya
  formulir langsung terlihat tanpa menggulir lewat poster.
- Penanda daftar manfaat dan langkah: segitiga puncak, sama dengan garis jalur di
  landing, bukan lingkaran bernomor atau ikon centang.
- Formulir: kolom setinggi 48 px, garis tepi #7c8c84 (kontras 3,5:1 terhadap
  putih), label di atas, pesan salah selalu berupa kalimat dengan ikon, dan setelah
  gagal kirim fokus pindah ke kolom pertama yang perlu diperbaiki.
- Kotak unggah: garis putus-putus seperti rencana rute di peta dengan pola kontur
  tipis; nama file panjang dipotong dua baris, nama jalur terisi dari nama file, dan
  daftar unggah sekaligus menandai file yang akan dilewati sebelum dikirim.
- Progres unggah sekaligus jujur: tulisan "sedang mengunggah" tanpa persen palsu,
  karena server tidak mengirim kemajuan yang sebenarnya.
- Riwayat file GPX dan Cara kerja: garis jalur putus-putus dengan segitiga puncak
  per versi atau tahap, versi aktif segitiga gelap; log pemeriksaan dilipat dalam
  `<details>` karena jarang dibaca.
- Status selalu berupa kata dalam chip (Cocok, Isi berubah, Gagal diperiksa), bukan
  warna saja, dan istilah hash ditulis "sidik file" dengan nama teknis di belakangnya.
- Angka ringkasan (dashboard, admin) memakai Barlow Condensed dengan angka tabular
  supaya terbaca seperti papan jarak; hanya angka dari database.
- Arsitektur tanpa hasil evaluasi: lima tahap dengan bahasa pendaki, istilah teknis
  di satu baris kecil per tahap, dan tautan ke artikel tanpa angka P@5 atau MRR;
  angka evaluasi hanya ada di halaman admin.
- Kuesioner SUS: sepuluh pernyataan asli tidak diubah karena itu instrumen baku;
  skala 1 sampai 5 berupa tombol 52 px yang muat lima sejajar di layar 360 px.
- Admin: palet yang sama, alat dikelompokkan per tugas (Dataset, Penelitian) sebagai
  daftar, bukan sepuluh kartu berhuruf; setiap tabel punya tombol Unduh CSV di
  judulnya dan bergulir di dalam kotaknya sendiri, bukan menggeser halaman.
- Penampil embedding memakai `<dialog>` bawaan browser, karena Escape dan fokus
  di dalam dialog sudah benar tanpa skrip tambahan.
- Kartu dan sudut: radius 8 sampai 12 px, garis 1 px #dcd8c9, tanpa bayangan; kartu
  putih di atas kertas #f6f4ec sudah cukup sebagai elevasi.
- `offline.html`: bahasa visual yang sama tanpa font web, karena halaman itu harus
  tampil saat tidak ada koneksi; judul "Sinyal hilang di jalur." dan satu tombol.
