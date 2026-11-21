# RuteStrip — Dataset Jalur Pendakian Gunung di Pulau Jawa

*English version below.*

Dataset jalur pendakian dari [RuteStrip](https://rutestrip.ai.id), sistem rekomendasi
jalur pendakian berbasis content-based filtering dengan SBERT dan cosine similarity.

**Versi:** 2.1 (21 November 2026) · **Jumlah jalur:** 143 · **Wilayah:** Pulau Jawa, Indonesia (ditambah Gunung Anak Krakatau, Lampung)

## Isi folder

| File | Isi |
|---|---|
| `routes.csv` | Satu baris per jalur: statistik GPX, tingkat kesulitan, titik basecamp dan puncak, lokasi administratif, narasi, sumber dan lisensi. |
| `routes.json` | Sama dengan `routes.csv`, ditambah koordinat jalur (disederhanakan, `[lat, lng]`) dan profil elevasi (`[km, mdpl]`). |
| `embeddings.csv` | Vektor SBERT 384 dimensi per jalur (`paraphrase-multilingual-MiniLM-L12-v2`) dari teks dokumen jalur. |
| `gpx/` | File GPX asli per jalur, bernama `<route_id>-<slug>.gpx`. |
| `data_dictionary.csv` | Penjelasan setiap kolom. |

## Cara data dibuat

- **Statistik** dihitung dari GPX dengan `python/processor.py`: jarak 3D, total tanjakan
  (elevation gain), grade rata-rata, bagian paling curam ±1 km (`steepest_grade_pct`), dan
  estimasi waktu **Naismith** (jarak/5 + tanjakan/600 jam).
- **Satu jalur per file, dari basecamp sampai puncak.** Rekaman yang naik lalu turun lewat
  jalur yang sama, berupa loop, atau berisi beberapa track dirapikan dengan hanya membuang
  titik (titik GPS tidak digeser atau ditambah). Versi 2.1 memuat hasil perapian ini.
- **Tingkat kesulitan**: skor Shenandoah √(tanjakan[ft] × 2 × jarak[mil]), dikali 1,15 bila
  grade ≥ 25% dan 1,15 bila puncak ≥ 3.000 m. Mudah < 75 ≤ Sedang < 200 ≤ Sulit < 460 ≤ Sangat Sulit;
  tanjakan ≥ 1.200 m minimal Sulit.
- **Narasi** dibuat otomatis dari statistik dan dipakai sebagai teks SBERT.
- **Titik basecamp** = titik pertama jalur; **puncak** = titik tertinggi (elevasi dari GPX
  atau model elevasi Open-Meteo, DEM 90 m). **Lokasi administratif** dari reverse geocoding
  OpenStreetMap Nominatim pada titik basecamp.
- Data diekspor dari basis data produksi dengan `php artisan dataset:export`.

## Sumber dan lisensi

- Jalur dengan `source = OpenStreetMap` dibangun dari relasi/way jalur pendakian
  OpenStreetMap, dengan elevasi NASA SRTM 30 m (OpenTopoData). Data tersebut
  © kontributor OpenStreetMap, berlisensi **ODbL 1.0**
  (https://www.openstreetmap.org/copyright); turunan dari data ini harus tetap ODbL dan
  mencantumkan atribusi.
- Jalur lain (`source = RuteStrip research collection` / `RuteStrip user upload`) berasal
  dari koleksi GPX penelitian dan unggahan pengguna RuteStrip; lisensinya ditentukan oleh
  pemilik dataset (lihat bagian Lisensi di halaman unduhan dataset).
- Lokasi: OpenStreetMap Nominatim (ODbL). Elevasi DEM: Open-Meteo.

## Sitasi

R. D. P. Wijaya dan A. N. Rahman, "Mountain Hiking Route Recommendation System using
Content-based Filtering with SBERT and Cosine Similarity," *SISTEMASI: Jurnal Sistem
Informasi*, vol. 15, no. 4, hlm. 1241–1256, 2026. DOI:
[10.32520/stmsi.v15i4.6087](https://doi.org/10.32520/stmsi.v15i4.6087) (SINTA 3).

Paper mengevaluasi 39 jalur; dataset ini adalah versi lanjutan dengan 143 jalur.

## Catatan

- Data untuk riset dan perencanaan awal, **bukan** alat navigasi. Cek kondisi jalur,
  izin, dan status buka-tutup ke pengelola sebelum mendaki.
- Narasi ditulis dalam Bahasa Indonesia.

---

# RuteStrip — Hiking Route Dataset, Java (Indonesia)

Hiking routes from [RuteStrip](https://rutestrip.ai.id), a content-based hiking route
recommender using SBERT and cosine similarity.

**Version:** 2.1 (21 November 2026) · **Routes:** 143 · **Region:** Java, Indonesia (plus Anak Krakatau, Lampung)

## Files

| File | Contents |
|---|---|
| `routes.csv` | One row per route: GPX statistics, difficulty, trailhead and summit points, administrative location, narrative, source and licence. |
| `routes.json` | Same as `routes.csv` plus the simplified track (`[lat, lng]`) and elevation profile (`[km, m]`). |
| `embeddings.csv` | 384-dimension SBERT vector per route (`paraphrase-multilingual-MiniLM-L12-v2`). |
| `gpx/` | Original GPX file per route, named `<route_id>-<slug>.gpx`. |
| `data_dictionary.csv` | Column descriptions. |

## Method

Statistics come from the GPX via `python/processor.py` (3D distance, elevation gain,
average grade, steepest ~1 km stretch, Naismith time = km/5 + gain/600 h). Each file holds
one track from the trailhead to the summit: recordings that went up and back the same way,
looped, or held several tracks were trimmed by removing points only (version 2.1). Difficulty uses the Shenandoah rating
√(gain[ft] × 2 × distance[mi]) with ×1.15 for grade ≥ 25% and ×1.15 for summits ≥ 3,000 m
(Easy < 75 ≤ Moderate < 200 ≤ Hard < 460 ≤ Very hard; ≥ 1,200 m gain is at least Hard).
Narratives (Indonesian) are generated from the statistics and are the SBERT input.
Trailhead = first track point; summit = highest point (GPX or Open-Meteo 90 m DEM);
administrative location from OpenStreetMap Nominatim at the trailhead.

## Sources and licence

Routes with `source = OpenStreetMap` are built from OpenStreetMap hiking relations and
named ways with NASA SRTM 30 m elevation, © OpenStreetMap contributors, **ODbL 1.0**.
Other routes come from the RuteStrip research collection and user uploads; their licence
is set by the dataset owner. Not a navigation tool — check trail status with the local
management before hiking.

## Citation

R. D. P. Wijaya and A. N. Rahman, "Mountain Hiking Route Recommendation System using
Content-based Filtering with SBERT and Cosine Similarity," *SISTEMASI: Jurnal Sistem
Informasi*, vol. 15, no. 4, pp. 1241–1256, 2026. doi:10.32520/stmsi.v15i4.6087
