"""
Generate python/RuteStrip_Colab.ipynb: a Colab notebook that reproduces the
search used by the live website (rutestrip.ai.id) with the code and the
dataset from this repository, so the notebook and the web app never drift apart.

Data: dataset/routes.json and dataset/embeddings.csv, exported from the
production database with `php artisan dataset:export` (the same routes and
statistics the website shows). GPX processing is demonstrated on the GPX files
in webchat-api/gpx_db.

Usage: python build_colab_notebook.py OUTPUT.ipynb
"""
import sys

import nbformat as nbf

REPO = "https://github.com/dasrams31/rutestrip-app.git"

cells = []


def md(text):
    cells.append(nbf.v4.new_markdown_cell(text.strip("\n")))


def code(text):
    cells.append(nbf.v4.new_code_cell(text.strip("\n")))


md(r"""
# RuteStrip — Sistem Rekomendasi Jalur Pendakian Gunung di Jawa
## Content-Based Filtering berbasis SBERT dan Cosine Similarity (versi web rutestrip.ai.id)

Notebook ini menjalankan **kode dan data yang sama dengan website**: `python/processor.py`, `python/search_scoring.py`, dan dataset `dataset/` (hasil ekspor database produksi) dari repository `dasrams31/rutestrip-app`.

Link notebook ini: https://s.peka.ai.id/rutestrip-notebook

**Alur sistem**
1. **Data GPX** → statistik jalur (jarak 3D, total tanjakan, waktu Naismith, kemiringan rata-rata, bagian paling curam ±1 km, elevasi min/maks).
2. **Tingkat kesulitan** dari profil jalur (Shenandoah difficulty rating + faktor kecuraman dan ketinggian).
3. **Narasi otomatis** dari statistik → teks konten tiap jalur.
4. **Preprocessing** (regex cleaning, case folding, stopword removal selektif, tanpa stemming).
5. **SBERT** `paraphrase-multilingual-MiniLM-L12-v2` (384 dimensi) → embedding dokumen jalur dan per kalimat aspek.
6. **Pencarian**: nama gunung/daerah yang disebut = saringan; kata kriteria (landai, mudah, tercepat, ...) = urutan berdasarkan angka GPX; selebihnya diurutkan dengan **cosine similarity**.
7. **Evaluasi** Precision@5 dan MRR.

Versi penelitian (dataset 39 jalur di paper) tersimpan di tag git `penelitian-39-jalur`.
""")

code(r"""
#@title 1. Setup: install library & ambil kode + data dari repository
import os, sys, subprocess

IN_COLAB = 'google.colab' in sys.modules
if IN_COLAB:
    subprocess.run([sys.executable, '-m', 'pip', 'install', '-q', 'gpxpy', 'sentence-transformers', 'scikit-learn', 'pandas'], check=True)

REPO_DIR = os.environ.get('RUTESTRIP_REPO_DIR', 'rutestrip-app')
if not os.path.isdir(REPO_DIR):
    subprocess.run(['git', 'clone', '--depth', '1', '""" + REPO + r"""', REPO_DIR], check=True)

PY_DIR = os.path.join(REPO_DIR, 'python')
DATA_DIR = os.path.join(REPO_DIR, 'dataset')
GPX_DIR = os.path.join(REPO_DIR, 'webchat-api', 'gpx_db')
if PY_DIR not in sys.path:
    sys.path.insert(0, PY_DIR)

import warnings; warnings.filterwarnings('ignore')
import inspect, re, json
import numpy as np
import pandas as pd
pd.set_option('display.max_colwidth', None)

import processor        # GPX processing, Naismith, difficulty, narrative, preprocessing, SBERT
import search_scoring   # ranking yang dipakai website (sama dengan RouteSearchService.php)
import evaluate_search as ev

print('Repository :', os.path.basename(os.path.abspath(REPO_DIR)))
print('Dataset    :', sorted(os.listdir(DATA_DIR)))
""")

md(r"""
## 2. Preprocessing teks
Sama dengan paper: hapus URL, karakter non-alfanumerik, angka berdiri sendiri, normalisasi spasi, case folding, lalu **stopword removal selektif** (kata negasi dan kata sifat jalur seperti *landai, curam, pemula* tetap dipertahankan). Tidak memakai stemming karena SBERT peka konteks.
""")

code(r"""
#@title Kode `preprocess_text` (python/processor.py)
print(inspect.getsource(processor.preprocess_text))
print('Stopwords      :', len(processor.STOPWORDS_ID))
print('Preserve words :', sorted(processor.PRESERVE_WORDS))
contoh = 'Ini adalah jalur yang sangat indah dan tidak terlalu curam, cocok untuk pemula. Info: https://example.com'
print('\nAsli  :', contoh)
print('Hasil :', processor.preprocess_text(contoh))
""")

md(r"""
## 3. Statistik GPX, Naismith, dan tingkat kesulitan
- **Jarak**: `gpx.length_3d()` (km). Elevasi dihaluskan dulu (`gpx.smooth(vertical=True)`) untuk mengurangi noise GPS.
- **Total tanjakan**: jumlah selisih elevasi positif antar titik.
- **Naismith**: `jarak/5 + tanjakan/600` (jam), sama dengan paper.
- **Kemiringan rata-rata**: `tanjakan / (jarak × 1000) × 100%`.
- **Bagian paling curam**: kemiringan terbesar pada potongan ±1 km di sepanjang profil. Rata-rata menyembunyikan bagian curam pendek, misalnya lereng pasir Semeru.
- **Tingkat kesulitan**: skor = √(tanjakan[ft] × 2 × jarak[mil]) — *Shenandoah National Park hiking difficulty rating* — dikali 1,15 bila kemiringan rata-rata ≥ 25% dan 1,15 bila puncak ≥ 3.000 m.
  Mudah < 75 ≤ Sedang < 200 ≤ Sulit < 460 ≤ Sangat Sulit; tanjakan ≥ 1.200 m minimal **Sulit**.

Rekaman GPX yang naik lalu turun lewat jalur yang sama, berupa loop, atau berisi beberapa track sudah dirapikan menjadi satu jalur dari basecamp sampai puncak (hanya membuang titik, tidak menggeser atau menambah titik GPS).
""")

code(r"""
#@title Kode statistik, kesulitan, dan narasi (python/processor.py)
for fn in (processor.calculate_statistics, processor.steepest_section_grade, processor.difficulty_score,
           processor.classify_difficulty, processor.generate_narrative):
    print(inspect.getsource(fn))
""")

md(r"""
## 4. Dataset jalur (sama dengan website)
`dataset/routes.json` adalah hasil `php artisan dataset:export` dari database produksi: nama jalur, lokasi titik awal (OpenStreetMap Nominatim), statistik GPX, narasi, dan sumber datanya. `dataset/embeddings.csv` berisi embedding SBERT narasi yang tersimpan di database.
""")

code(r"""
#@title Muat dataset
raw = json.load(open(os.path.join(DATA_DIR, 'routes.json'), encoding='utf-8'))
emb = pd.read_csv(os.path.join(DATA_DIR, 'embeddings.csv'), encoding='utf-8-sig')
stored = {int(row[0]): [float(v) for v in row[1:]] for row in emb.itertuples(index=False)}

routes = []
for r in raw:
    routes.append({
        'id': r['route_id'], 'name': r['name'], 'mountain': r['mountain'], 'via': r['via'],
        'province': r['province'], 'regency': r['regency'],
        'distance_km': r['distance_km'], 'elevation_gain_m': r['elevation_gain_m'],
        'naismith_duration_hour': r['naismith_duration_hour'], 'average_grade_pct': r['average_grade_pct'],
        'steepest_grade_pct': r['steepest_grade_pct'], 'max_elevation_m': r['max_elevation_m'],
        'difficulty': r['difficulty'], 'difficulty_score': r['difficulty_score'],
        'narrative_text': r['narrative_text'], 'description': r['description'] or '',
        'source': r['source'], 'sbert_embedding': stored.get(r['route_id']),
    })

df = pd.DataFrame(routes)
print(f"Jumlah jalur : {len(df)}  |  gunung/bukit: {df['mountain'].nunique()}")
print('Provinsi     :', df['province'].value_counts().to_dict())
print('Kesulitan    :', df['difficulty'].value_counts().reindex(['Mudah', 'Sedang', 'Sulit', 'Sangat Sulit']).to_dict())
print('Sumber       :', df['source'].value_counts().to_dict())
display(df[['id', 'name', 'regency', 'distance_km', 'elevation_gain_m', 'naismith_duration_hour',
            'average_grade_pct', 'steepest_grade_pct', 'difficulty']].rename(columns={
    'name': 'Nama', 'regency': 'Kabupaten', 'distance_km': 'Jarak (km)', 'elevation_gain_m': 'Tanjakan (m)',
    'naismith_duration_hour': 'Naismith (jam)', 'average_grade_pct': 'Rata-rata (%)',
    'steepest_grade_pct': 'Paling curam (%)', 'difficulty': 'Kesulitan'}).head(15))
""")

code(r"""
#@title Cek ulang: hitung statistik langsung dari file GPX (webchat-api/gpx_db)
STOP = {'gunung', 'mt', 'mount', 'via', 'jalur', 'puncak', 'to', 'bc', 'basecamp', 'dan'}
key = lambda name: ' '.join(sorted({w for w in re.findall(r'[a-z]+', name.lower())} - STOP))
gpx_files = {}
for f in os.listdir(GPX_DIR):
    if f.endswith('.gpx'):
        gpx_files.setdefault(key(f[:-4]), []).append(f)

rows = []
for r in routes:
    hits = gpx_files.get(key(r['name']), [])
    if len(hits) != 1:
        continue
    s = processor.mode_ingest(os.path.join(GPX_DIR, hits[0]))
    if s.get('success'):
        rows.append({'Nama': r['name'], 'Jarak dataset': r['distance_km'], 'Jarak GPX': s['distance_km'],
                     'Tanjakan dataset': r['elevation_gain_m'], 'Tanjakan GPX': s['elevation_gain_m'],
                     'Naismith dataset': r['naismith_duration_hour'], 'Naismith GPX': s['naismith_duration_hour']})
check = pd.DataFrame(rows)
same = (abs(check['Jarak dataset'] - check['Jarak GPX']) <= 0.05) & (abs(check['Tanjakan dataset'] - check['Tanjakan GPX']) <= 5)
print(f"Jalur dengan file GPX yang cocok namanya: {len(check)} dari {len(routes)}")
print(f"Statistik sama dengan dataset (selisih ≤ 0,05 km dan ≤ 5 m): {same.sum()} jalur")
display(check.head(10))
""")

md(r"""
## 5. Representasi dokumen untuk SBERT
- **Dokumen jalur** = `"Jalur pendakian {nama}. {narasi} {deskripsi}"` → satu embedding.
- **Aspek** = setiap kalimat narasi (jarak, waktu, kemiringan, elevasi, kesulitan, kecocokan) + kalimat nama → satu embedding per aspek, agar query pendek seperti "jalur landai" dibandingkan dengan aspek yang paling relevan.
- Query diproses dengan `preprocess_text` yang sama seperti paper.
""")

code(r"""
#@title Load SBERT & buat embedding dokumen + aspek
model = processor.get_model()
print('Dimensi embedding:', model.get_sentence_embedding_dimension())
print(inspect.getsource(search_scoring.build_document))

doc_vecs, aspect_vecs = {}, {}
for r in routes:
    doc = search_scoring.build_document(r)
    vecs = model.encode([doc['text']] + doc['aspects'])
    doc_vecs[r['id']] = vecs[0].tolist()
    aspect_vecs[r['id']] = vecs[1:].tolist()

contoh = search_scoring.build_document(routes[0])
print('Contoh dokumen:', contoh['text'][:200], '...')
print('Jumlah aspek  :', len(contoh['aspects']))
""")

md(r"""
## 6. Cara website mengurutkan hasil
1. **Saringan nama gunung**: kalau query menyebut gunung ("merbabu", "arjuna" = Arjuno), hanya jalur gunung itu yang ditampilkan.
2. **Saringan daerah**: kalau query menyebut provinsi atau kabupaten ("jawa barat", "jabar", "magelang"), hanya jalur di daerah itu.
3. **Kata kriteria** menentukan urutan dengan angka GPX:
   *landai* → bagian paling curam lalu kemiringan rata-rata naik; *curam/terjal* → kebalikannya;
   *mudah/pemula/santai* → skor kesulitan naik; *sulit/berat/menantang* → turun;
   *singkat/cepat/tektok* → waktu Naismith naik; *panjang/lama/ekspedisi* → turun.
   Bentuk *ter-* dan *-nya* ikut terbaca (*tercepat, termudah, terlandai, landainya*), juga beberapa kata Inggris (*fastest, easiest*). Negasi membalik arti ("tidak terlalu curam" = landai).
4. **Relevansi semantik** (selalu dihitung, dan menjadi urutan utama bila tidak ada kata kriteria):
   `0,4 × cos(Q, dokumen) + 0,6 × max cos(Q, aspek)`, dengan `cos(Q, D) = (Q · D) / (‖Q‖ × ‖D‖)`, dicampur kecocokan nama (0,35) dan daerah (0,4) bila disebut.
""")

code(r"""
#@title Kode deteksi kata kriteria dan pengurutan (python/search_scoring.py)
print(inspect.getsource(search_scoring.intent_base))
print(inspect.getsource(search_scoring.detect_intents))
print(inspect.getsource(search_scoring.rank_routes))

by_id = {r['id']: r for r in routes}

def search(query, top_n=5):
    q = model.encode(search_scoring.query_text(query)).tolist()
    ranked = search_scoring.rank_routes(query, q, routes, doc_vecs, aspect_vecs, limit=top_n)
    return [(by_id[row['id']], row['score']) for row in ranked]

for q in ['jalur tercepat ke merbabu', 'jalur landai di jawa barat', 'tidak terlalu curam']:
    p = search_scoring.plan(q, routes)
    print(f"{q!r}: kriteria={p['intents']} gunung={p['mountain_tokens']} "
          f"daerah={sorted(p['regions']['provinces']) + sorted(p['regions']['regencies'])} -> {len(p['routes'])} jalur tersisa")
""")

code(r"""
#@title 7. Coba pencarian (ubah query di kolom kanan)
QUERY = "jalur tercepat ke merbabu"  #@param {type:"string"}

print(f"Query: {QUERY!r}\n")
for i, (r, s) in enumerate(search(QUERY), 1):
    print(f"{i}. {r['name']:<44} skor {s:.3f} | {r['distance_km']} km, +{r['elevation_gain_m']} m, "
          f"{r['naismith_duration_hour']} jam, rata-rata {r['average_grade_pct']}%, paling curam {r['steepest_grade_pct']}%, {r['difficulty']}")
""")

code(r"""
#@title Contoh hasil beberapa query (Top-3)
rows = []
for q in ['jalur untuk pemula', 'jalur landai', 'jalur tercepat ke merbabu', 'jalur tersulit ke slamet',
          'pendakian panjang beberapa hari', 'jalur pendek di jawa barat', 'gede pangrango', 'jalur dengan sabana luas']:
    rows.append({'Query': q, 'Top-3': ' | '.join(f"{r['name']} ({s:.2f})" for r, s in search(q, 3))})
display(pd.DataFrame(rows))
""")

md(r"""
## 8. Evaluasi
Kalimat uji dan label relevansi dari `python/evaluate_search.py`, diurutkan dengan cara yang sama seperti website (`rank_routes`).

Label relevansi diturunkan otomatis dari statistik GPX, sehingga dapat direproduksi:
- *landai* → kemiringan rata-rata di sepertiga terendah; *curam* → sepertiga tertinggi;
- *mudah/pemula* → skor kesulitan sepertiga terendah; *sulit* → sepertiga tertinggi;
- *singkat* / *panjang* → waktu Naismith sepertiga terendah / tertinggi;
- nama gunung → nama jalur memuat nama gunung itu; daerah → jalur di provinsi itu.

Metrik: **Precision@5** (proporsi jalur relevan di 5 teratas) dan **MRR** (rata-rata 1/peringkat jalur relevan pertama).

> **Catatan metodologi:** label relevansi dan urutan kata kriteria sama-sama memakai statistik GPX, sehingga angka ini mengukur *konsistensi urutan dengan definisi kriteria*, bukan penilaian manusia.
""")

code(r"""
#@title Precision@5 & MRR
res, n_main, _ = ev.evaluate(routes, model, log=None)
table = pd.DataFrame({
    'Query': [row[0] for row in res['ranked']],
    'P@5': [row[1] for row in res['ranked']],
    'RR': [row[2] for row in res['ranked']],
})
table['Kelompok'] = ['utama'] * n_main + ['daerah'] * (len(table) - n_main)
display(table)

summary = []
for group, part in (('utama', slice(0, n_main)), ('daerah', slice(n_main, None))):
    rows = res['ranked'][part]
    if rows:
        summary.append({'Kelompok': group, 'Query': len(rows),
                        'P@5': round(float(np.mean([r[1] for r in rows])), 3),
                        'MRR': round(float(np.mean([r[2] for r in rows])), 3)})
summary = pd.DataFrame(summary)
display(summary)
""")

code(r"""
#@title 9. Ringkasan (dihitung dari hasil di atas)
pick = lambda g, c: summary[summary['Kelompok'] == g][c].iloc[0]
print(f"Dataset          : {len(routes)} jalur, {df['mountain'].nunique()} gunung/bukit")
print(f"Kesulitan        : " + ', '.join(f"{k} {v}" for k, v in df['difficulty'].value_counts().items()))
print(f"Query uji        : {n_main} utama + {len(table) - n_main} daerah")
print(f"Precision@5      : {pick('utama', 'P@5'):.3f}")
print(f"MRR              : {pick('utama', 'MRR'):.3f}")
if (summary['Kelompok'] == 'daerah').any():
    print(f"Query daerah     : P@5 {pick('daerah', 'P@5'):.3f}, MRR {pick('daerah', 'MRR'):.3f}")
""")

nb = nbf.v4.new_notebook()
nb["cells"] = cells
nb["metadata"] = {
    "colab": {"provenance": [], "name": "RuteStrip_Colab.ipynb"},
    "kernelspec": {"name": "python3", "display_name": "Python 3"},
    "language_info": {"name": "python"},
}
nbf.write(nb, sys.argv[1])
print("written", sys.argv[1], len(cells), "cells")
