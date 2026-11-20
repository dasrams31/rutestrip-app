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
