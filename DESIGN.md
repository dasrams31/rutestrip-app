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
- Huruf judul: Barlow Condensed 700/800 (SIL OFL) karena
  dirancang dari rambu jalan raya, cocok dengan "papan penunjuk jalur atau poster
  ekspedisi". Teks isi tetap Inter. Keduanya di-host sendiri di public/fonts
  (dari Fontsource, `npm run build:vendor`) dengan font-display: swap, tanpa
  Google Fonts.
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

## Keputusan desain halaman status

Alasan satu baris per keputusan (antislop R-31) untuk `chat.rutestrip.ai.id/system`
(`webchat/app/system/page.js`; `/monitoring` dialihkan ke sini). Dial ENERGY 2 /
RHYTHM 2 / MOTION 1: halaman ini dibaca ulang tiap 30 detik, jadi gerak hanya akan
bersaing dengan kabar statusnya.

- Hanya data yang benar-benar diukur `/api/proxy/status`: `fastapi_api`,
  `hermes_gateway`, dan `timestamp`; angka CPU, RAM, disk, uptime, pelanggan, jumlah
  GPX, cronjob, permintaan AI, dan daftar provider di respons itu nilai tetap atau
  salinan, jadi tidak ditampilkan (R-17, R-36).
- Satu titik fokus: pita zamrud #053b30 berkontur dengan satu kalimat besar Barlow
  Condensed (Semua layanan berjalan, Ada gangguan, Tidak bisa dihubungi), karena
  pendaki cukup membaca satu kalimat itu dalam lima detik.
- Kalimat di bawah judul menyebut akibatnya bagi pendaki (misalnya chat tetap
  menjawab tanpa data jalur), bukan nama server, karena itu yang menentukan perlu
  menunggu atau tidak.
- Kuning #FCD34D hanya untuk langkah berikutnya: "Kembali ke chat" saat server
  terjangkau, berganti "Coba hubungi lagi" saat tidak, supaya kuning tetap berarti
  satu hal.
- Layanan berupa baris dalam satu lembar putih, bukan kartu-kartu, karena tiga
  layanan dibaca berurutan dari atas ke bawah seperti papan jalur.
- Setiap baris punya garis pendek di tepi kiri seperti tanda cat di batang pohon
  jalur, dan status selalu kata plus ikon dalam chip, tidak warna saja.
- Situs chat dianggap berjalan bila status terbaca; kalau pemeriksaan gagal, dua
  layanan lain jadi "Belum diketahui" dengan keadaan terakhir yang terlihat, karena
  dari browser kami memang tidak tahu.
- Bot Telegram dibuat kartu terpisah tanpa lampu status, karena bot tidak diperiksa
  oleh endpoint ini dan kami tidak mau mengarang statusnya.
- Detail teknis (komponen, alamat pemeriksaan, nilai mentah, waktu server, lama
  pemeriksaan, galat terakhir) dilipat dalam `<details>` di bawah daftar, karena
  hanya pengelola yang butuh.
- Periksa otomatis tiap 30 detik (bukan 4 detik), berhenti saat tab tidak terlihat,
  dan labelnya menyebut angka yang sama dengan kode; setelah 60 detik tanpa
  pemeriksaan muncul catatan bahwa hasilnya sudah lama.
- Muat awal memakai baris kerangka diam tanpa kilau, dan ikon putar hanya selama
  memeriksa; keduanya mati dengan prefers-reduced-motion; tidak ada titik berdenyut.
- Kontur di pita dibuat dengan resep yang sama seperti `rs-contours` (cincin
  bertingkat, setiap cincin ke-5 lebih tebal) dan diam, supaya chat dan situs utama
  terasa satu keluarga.
- Barlow Condensed dimuat lewat `next/font` hanya di layout `/system` dengan
  display=optional, seperti situs utama, agar judul tidak bergeser.
- Bilah atas putih solid tanpa blur, tautan Chat dan Situs utama setinggi 44 px,
  pemilih bahasa diperbesar ke 44 px; di bawah 400 px nama merek dan di bawah 560 px
  tulisan "Situs utama" disembunyikan supaya muat di 320 px.
- Radius 8 sampai 12 px, garis 1 px #dcd8c9, tanpa bayangan; tombol sakelar
  bersudut 6 px, bukan pil, mengikuti arah "tanpa bentuk pil".
- Warna amber dan merah hanya untuk tanda gangguan (ikon, chip, catatan), selalu
  bersama kata, agar palet tetap zamrud, kertas, dan satu kuning.

## Keputusan desain halaman admin (statistik)

Alasan satu baris per keputusan (antislop R-31) untuk `/admin/pengguna`, detail
pengguna, `/admin/aktivitas`, `/admin/log`, dan `/admin/moderasi`
(`resources/views/admin/users/*`, `activity`, `logs`, `moderation`). Dial ENERGY 2 /
RHYTHM 2 / MOTION 1: admin datang untuk membaca angka dan memutuskan, jadi energi
tetap di pita judul dan gerak hanya kontur bawaan `rs-head`.

- Pita judul `rs-head` yang sama dengan dashboard, ditambah baris tab (Dataset,
  Pengguna, Aktivitas, Log, Moderasi) di dasar pita, supaya lima halaman admin terasa
  satu papan dan pindah bagian cukup satu ketukan.
- Tab aktif ditandai garis putih 3 px di bawah, sama dengan penanda tautan aktif di
  navbar; kuning tidak dipakai karena kuning berarti langkah berikutnya.
- Di ponsel baris tab bergeser ke samping di dalam pitanya sendiri, bukan turun jadi
  dua baris, supaya pita tidak memakan layar.
- Jumlah laporan yang menunggu tampil sebagai angka di tab Moderasi, karena itu
  satu-satunya hal di panel yang menunggu tindakan admin.
- Ringkasan pengguna: satu angka besar (akun terdaftar) di kiri dan empat angka lebih
  kecil di kanan, karena total adalah yang dibaca pertama; angka masuk diberi catatan
  bahwa waktu masuk baru dicatat sejak fitur ini dipasang (tidak ada angka karangan).
- Kuning hanya untuk satu aksi per halaman: Cari pengguna dan Saring log; Aktivitas
  dan Moderasi tidak punya aksi utama, jadi tidak ada kuning di sana.
- Grafik 30 hari berupa enam grafik batang kecil (satu seri per kartu, skala sendiri),
  bukan satu grafik enam warna, supaya palet tetap satu zamrud dan tidak ada sumbu ganda.
- Batang SVG buatan sendiri tanpa pustaka grafik: 30 batang dengan jarak 2 px, garis
  dasar #7c8c84 (kontras 3,5:1), dan `<title>` per hari untuk angka saat disorot.
- Angka per hari juga ada di tabel yang dilipat dalam `<details>`, karena sorot kursor
  tidak ada di ponsel dan pembaca layar butuh tabel.
- Seri yang kosong 30 hari menampilkan kalimat "Belum ada", bukan grafik datar, agar
  nol terbaca sebagai keadaan, bukan grafik rusak.
- Paling sering dicari berupa daftar bernomor dengan batang mendatar dan rincian
  pengguna/tamu, karena admin membaca kalimatnya dulu, angkanya kemudian.
- Kabar terbaru ditulis sebagai kalimat ("Rama mengunggah Gunung Prau via Wates") dan
  setiap baris diberi garis pendek di kiri seperti tanda cat jalur; merah hanya untuk
  gagal masuk, penolakan admin, dan laporan, selalu bersama chip berkata.
- Gagal masuk ditaruh paling atas di halaman Log, karena itu satu-satunya bagian yang
  mungkin butuh tindakan hari ini; baris dengan 5 kali atau lebih diberi chip
  "Perlu dilihat".
- Galat server memakai huruf monospace kecil hanya untuk isi pesan, karena itu teks
  mesin yang dibaca persis; waktu dan tingkat tetap Inter.
- Semua tabel bergulir di dalam kotaknya sendiri (`rs-table-wrap`) dan kolom nama punya
  lebar minimum, supaya halaman tidak bergeser ke samping di 360 px.
- Moderasi berupa kartu per pesan (isi pesan dalam kutipan bergaris kiri) dan bukan
  tabel, karena admin perlu membaca isi pesan utuh sebelum memutuskan.
- Tombol Hapus pesan bergaris merah, Abaikan laporan bergaris zamrud: hapus adalah
  satu-satunya pilihan yang tidak bisa dibatalkan dari panel, jadi diberi warna
  gangguan dan konfirmasi.
- Konfirmasi memakai `confirm()` bawaan browser, sama dengan pemeriksaan massal di
  dashboard, karena Enter, Escape, dan fokus sudah benar tanpa skrip dialog.
- Saat formulir dikirim, tombol berganti tulisan (Mencari, Menyaring) dan formulir
  diberi aria-busy, sebagai keadaan memuat tanpa ikon berputar.
- Kartu, radius, dan garis mengikuti bagian admin di atas: putih di atas kertas
  #f6f4ec, radius 8 sampai 12 px, garis 1 px #dcd8c9, tanpa bayangan.
