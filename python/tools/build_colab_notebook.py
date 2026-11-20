"""
Generate python/RuteStrip_Colab.ipynb: a Colab notebook that reproduces the
search method used by the live website (rutestrip.ai.id) with the code from
this repository, so the notebook and the web app never drift apart.

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
# 🏔️ RuteStrip — Sistem Rekomendasi Jalur Pendakian Gunung di Jawa
## Content-Based Filtering berbasis SBERT dan Cosine Similarity (versi web rutestrip.ai.id)

Notebook ini menjalankan **kode yang sama dengan website** (`python/processor.py` dan `python/search_scoring.py` dari repository `dasrams31/rutestrip-app`), sehingga hasilnya konsisten dengan aplikasi yang berjalan.

**Alur sistem**
1. **Data GPX** → statistik jalur (jarak 3D, total tanjakan, estimasi waktu Naismith, kemiringan rata-rata, elevasi min/maks).
2. **Tingkat kesulitan** dari profil jalur (Shenandoah difficulty rating + faktor kecuraman & ketinggian).
3. **Narasi otomatis** dari statistik → teks konten tiap jalur.
4. **Preprocessing** (regex cleaning, case folding, stopword removal selektif, tanpa stemming).
5. **SBERT** `paraphrase-multilingual-MiniLM-L12-v2` (384 dimensi) → embedding dokumen jalur dan per kalimat aspek.
6. **Cosine similarity** antara query dan jalur → **skor hybrid** (cosine + kecocokan atribut GPX + kecocokan nama) → Top-N.
7. **Evaluasi** Precision@5 dan MRR: metode awal (cosine murni) vs metode web (hybrid).

| Komponen | Metode awal (paper) | Versi web saat ini |
|---|---|---|
| Model SBERT, preprocessing, Naismith, grade | sama | sama |
| Elevasi GPX | tanpa smoothing | `gpx.smooth(vertical=True)` |
| Tingkat kesulitan | grade saja (<5/<10/<15%) | skor profil jalur (Shenandoah) |
| Teks yang di-embed | narasi statistik | nama jalur + narasi, plus embedding per aspek |
| Ranking | cosine murni | hybrid (cosine + atribut + nama) |
""")

code(r"""
#@title 1️⃣ Setup: install library & ambil kode + data dari repository
import os, sys, subprocess

IN_COLAB = 'google.colab' in sys.modules
if IN_COLAB:
    subprocess.run([sys.executable, '-m', 'pip', 'install', '-q', 'gpxpy', 'sentence-transformers', 'scikit-learn', 'pandas'], check=True)

REPO_DIR = os.environ.get('RUTESTRIP_REPO_DIR', 'rutestrip-app')
if not os.path.isdir(REPO_DIR):
    subprocess.run(['git', 'clone', '--depth', '1', '""" + REPO + r"""', REPO_DIR], check=True)

PY_DIR = os.path.join(REPO_DIR, 'python')
GPX_DIR = os.path.join(REPO_DIR, 'webchat-api', 'gpx_db')
if PY_DIR not in sys.path:
    sys.path.insert(0, PY_DIR)

import warnings; warnings.filterwarnings('ignore')
import inspect, re, hashlib, json, math
import numpy as np
import pandas as pd

import processor        # GPX processing, Naismith, difficulty, narrative, preprocessing, SBERT
import search_scoring   # hybrid scoring yang dipakai website

print('Repository :', os.path.abspath(REPO_DIR))
print('File GPX   :', len([f for f in os.listdir(GPX_DIR) if f.endswith('.gpx')]))
""")

md(r"""
## 2️⃣ Preprocessing teks
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
## 3️⃣ Statistik GPX, Naismith, dan tingkat kesulitan
- **Jarak**: `gpx.length_3d()` (km). Elevasi dihaluskan dulu (`gpx.smooth(vertical=True)`) untuk mengurangi noise GPS.
- **Total tanjakan**: jumlah selisih elevasi positif antar titik.
- **Naismith**: `jarak/5 + tanjakan/600` (jam).
- **Grade rata-rata**: `tanjakan / (jarak × 1000) × 100%`.
- **Tingkat kesulitan** (bukan hanya grade): skor = √(tanjakan[ft] × 2 × jarak[mil]) — *Shenandoah National Park hiking difficulty rating* — dikali 1,15 bila grade rata-rata ≥ 25% dan 1,15 bila puncak ≥ 3.000 m.
  Mudah < 75 ≤ Sedang < 200 ≤ Sulit < 460 ≤ Sangat Sulit; tanjakan ≥ 1.200 m minimal **Sulit**.
  Alasannya: grade saja membuat jalur 40 km dengan rata-rata 6% (Semeru) tampak "sedang", sedangkan bukit 1 km dengan 20% tampak "sangat sulit".
""")

code(r"""
#@title Kode statistik, kesulitan, dan narasi (python/processor.py)
for fn in (processor.calculate_statistics, processor.difficulty_score, processor.classify_difficulty, processor.generate_narrative):
    print(inspect.getsource(fn))
""")

md(r"""
## 4️⃣ Membangun dataset dari file GPX
Filter kualitas yang sama dengan perintah web `php artisan routes:import-gpx-dir`:
- lewati file duplikat (hash sama) dan jalur yang sama (nama sama, atau statistik nyaris sama pada gunung yang sama);
- lewati file gabungan beberapa jalur ("semua jalur", "4 jalur");
- lewati GPX tanpa elevasi atau > 60 km (ikut merekam perjalanan kendaraan);
- lewati elevasi tidak masuk akal: puncak > 3.800 m (salah satuan), titik terendah < 20% puncak (merekam perjalanan dari dataran rendah), tanjakan > 2× rentang elevasi atau grade > 35% (noise).

File berawalan `Mt. ` adalah dataset awal paper: diproses lebih dulu dan, seperti di web, tidak melewati filter kualitas (filter hanya untuk file tambahan).
""")

code(r"""
#@title Proses semua GPX → dataset jalur
NAME_STOP = r'\b(mt|mount|gunung|gurung|via|jalur|to|bc|basecamp|puncak)\b'

def name_key(name):
    words = sorted(set(re.findall(r'[a-z]+', re.sub(NAME_STOP, ' ', name.lower()))))
    return ' '.join(words)

def pretty_name(raw):
    if raw.startswith('Mt. '):
        return raw                                   # nama asli dataset paper
    n = raw.replace('_', ' ')
    n = re.sub(r'\s+\d{1,2}\s+[a-z]{3}\s+\d{4}$', '', n, flags=re.I)
    n = re.sub(r'\s*\(\d+\)$', '', n)
    n = re.sub(r'\s+-\s+', ' via ', n, count=1)
    n = ' '.join(w[:1].upper() + w[1:] for w in n.split())
    n = re.sub(r'\bvia\b', 'via', n, flags=re.I)
    return n if n.startswith(('Gunung ', 'Bukit ', 'Puncak ')) else 'Gunung ' + n

files = sorted(f for f in os.listdir(GPX_DIR) if f.endswith('.gpx'))
files = [f for f in files if f.startswith('Mt. ')] + [f for f in files if not f.startswith('Mt. ')]

routes, skipped, hashes = [], [], set()
for f in files:
    path = os.path.join(GPX_DIR, f)
    h = hashlib.sha256(open(path, 'rb').read()).hexdigest()
    if h in hashes:
        skipped.append((f, 'file sama')); continue
    if re.search(r'semua\s*jalur|\d\s*jalur', f, re.I):
        skipped.append((f, 'gabungan beberapa jalur')); continue
    r = processor.mode_ingest(path)
    if not r.get('success'):
        skipped.append((f, 'gagal diproses')); continue
    km, gain, grade = r['distance_km'], r['elevation_gain_m'], r['average_grade_pct']
    m = re.search(r'dari (\d+)m\s+hingga (\d+)m', r['narrative_text'])
    lo, hi = (int(m.group(1)), int(m.group(2))) if m else (0, 0)
    original = f.startswith('Mt. ')   # dataset paper: tidak difilter (sama seperti di web)
    if not original and (gain <= 0 or km <= 0 or km > 60):
        skipped.append((f, f'statistik tidak valid ({km} km, {gain} m)')); continue
    if not original and (hi > 3800 or (hi > 1000 and lo < 0.2 * hi)):
        skipped.append((f, f'elevasi tidak masuk akal {lo}-{hi} m')); continue
    if not original and (grade > 35 or (hi - lo > 0 and gain > 2 * (hi - lo))):
        skipped.append((f, f'elevasi noise ({gain} m tanjakan, rentang {hi - lo} m)')); continue
    name = pretty_name(f[:-4]); key = name_key(name)
    dup = next((x for x in routes if x['key'] == key or (set(x['key'].split()) & set(key.split())
                and abs(x['distance_km'] - km) <= 0.03 * max(x['distance_km'], km)
                and abs(x['elevation_gain_m'] - gain) <= 0.05 * max(x['elevation_gain_m'], gain, 1))), None)
    if dup:
        skipped.append((f, 'jalur sama dengan ' + dup['name'])); continue
    hashes.add(h)
    routes.append({'id': len(routes) + 1, 'name': name, 'key': key, 'file': f,
                   'distance_km': km, 'elevation_gain_m': gain, 'naismith_duration_hour': r['naismith_duration_hour'],
                   'average_grade_pct': grade, 'min_elevation': lo, 'max_elevation': hi,
                   'difficulty': processor.classify_difficulty(km, gain, grade, hi),
                   'difficulty_score': round(processor.difficulty_score(km, gain, grade, hi)),
                   'narrative_text': r['narrative_text'], 'baseline_embedding': r['embedding']})

print(f'Jalur dipakai : {len(routes)}')
print(f'Dilewati      : {len(skipped)}')
pd.DataFrame(skipped, columns=['file', 'alasan']).groupby('alasan').size().sort_values(ascending=False).head(15)
""")

code(r"""
#@title Dataset jalur
df = pd.DataFrame([{k: r[k] for k in ('id', 'name', 'distance_km', 'elevation_gain_m', 'naismith_duration_hour',
                                      'average_grade_pct', 'max_elevation', 'difficulty_score', 'difficulty')} for r in routes])
df.columns = ['ID', 'Nama', 'Jarak (km)', 'Tanjakan (m)', 'Naismith (jam)', 'Grade (%)', 'Puncak (m)', 'Skor kesulitan', 'Kesulitan']
display(df.sort_values('Skor kesulitan'))
print(df['Kesulitan'].value_counts().reindex(['mudah', 'sedang', 'sulit', 'sangat sulit']))
""")

md(r"""
## 5️⃣ Representasi dokumen untuk SBERT
- **Dokumen jalur** = `"Jalur pendakian {nama}. {narasi}"` → satu embedding (nama ikut masuk sehingga query "merbabu" bisa cocok secara semantik).
- **Aspek** = setiap kalimat narasi (jarak, waktu, kemiringan, elevasi, kesulitan, kecocokan) + kalimat nama → satu embedding per aspek, agar query pendek seperti "jalur landai" dibandingkan dengan aspek yang paling relevan.
- Query diproses dengan `preprocess_text` yang sama seperti paper.
""")

code(r"""
#@title Load SBERT & buat embedding dokumen + aspek
model = processor.get_model()
print('Dimensi embedding:', model.get_sentence_embedding_dimension())
print(inspect.getsource(search_scoring.build_document))

for r in routes:
    doc = search_scoring.build_document(r)
    vecs = model.encode([doc['text']] + doc['aspects'])
    r['embedding'] = vecs[0].tolist()
    r['aspect_embeddings'] = vecs[1:].tolist()

contoh = search_scoring.build_document(routes[0])
print('Contoh dokumen:', contoh['text'][:200], '...')
print('Jumlah aspek  :', len(contoh['aspects']))
""")

md(r"""
## 6️⃣ Skor relevansi
**Metode awal (paper)**: `Sim(Q, D) = (Q · D) / (‖Q‖ × ‖D‖)` antara query dan narasi.

**Metode web (hybrid)**:
1. `semantik = 0,4 × cos(Q, dokumen) + 0,6 × max cos(Q, aspek)`
2. Jika query memuat kata intent, skor dicampur dengan kecocokan atribut GPX (bobot 0,45):
   *landai* → kemiringan rendah (0,4 grade + 0,3 tanjakan + 0,3 durasi); *pemula/mudah/santai* → sedikit tanjakan & singkat (0,2 / 0,4 / 0,4);
   *curam/sulit/menantang* → kebalikannya; *singkat/tektok* dan *panjang/ekspedisi* → durasi. Negasi dibaca ("tidak terlalu curam" → landai).
3. Jika query menyebut nama gunung/basecamp, skor dicampur dengan proporsi nama yang cocok (bobot 0,35).

Atribut tidak diberikan pada GPX yang statistiknya tidak valid.
""")

code(r"""
#@title Kode hybrid scoring (python/search_scoring.py)
print(inspect.getsource(search_scoring.detect_intents))
print(inspect.getsource(search_scoring.attribute_score))
print(inspect.getsource(search_scoring.hybrid_score))

def cos(a, b):
    a, b = np.asarray(a), np.asarray(b)
    return float(a @ b / (np.linalg.norm(a) * np.linalg.norm(b) + 1e-12))

def search(query, mode='hybrid', top_n=5):
    if mode == 'paper':
        q = model.encode(processor.preprocess_text(query)).tolist()
        scored = [(r, cos(q, r['baseline_embedding'])) for r in routes]
    else:
        q = model.encode(search_scoring.query_text(query)).tolist()
        scored = [(r, search_scoring.hybrid_score(query, q, r['embedding'], r['aspect_embeddings'], r, routes)['score']) for r in routes]
    return sorted(scored, key=lambda x: -x[1])[:top_n]
""")

code(r"""
#@title 7️⃣ Coba pencarian (ubah query di kolom kanan)
QUERY = "jalur landai untuk pemula"  #@param {type:"string"}

for mode in ('paper', 'hybrid'):
    print(f"\n=== {'Metode awal (cosine murni)' if mode == 'paper' else 'Metode web (hybrid)'} — query: {QUERY!r}")
    for i, (r, s) in enumerate(search(QUERY, mode), 1):
        print(f"{i}. {r['name']:<42} skor {s:.4f} | {r['distance_km']} km, {r['elevation_gain_m']} m, "
              f"{r['naismith_duration_hour']} jam, grade {r['average_grade_pct']}%, {r['difficulty']}")
""")

code(r"""
#@title Perbandingan beberapa query (Top-3)
rows = []
for q in ['jalur mudah untuk pemula', 'jalur landai', 'trek menantang elevasi tinggi', 'pendakian singkat 2-3 jam',
          'pendakian panjang beberapa hari', 'jalur pemula merbabu', 'gede pangrango', 'kawah ijen']:
    p = search(q, 'paper', 3); h = search(q, 'hybrid', 3)
    rows.append({'Query': q,
                 'Cosine murni (paper)': ' | '.join(f"{r['name']} ({s:.2f})" for r, s in p),
                 'Hybrid (web)': ' | '.join(f"{r['name']} ({s:.2f})" for r, s in h)})
pd.set_option('display.max_colwidth', None)
display(pd.DataFrame(rows))
""")

md(r"""
## 8️⃣ Evaluasi
Label relevansi diturunkan otomatis dari statistik GPX (`python/evaluate_search.py`), sehingga dapat direproduksi:
- query *mudah/pemula/landai* → grade < 15% dan total tanjakan di sepertiga terendah;
- query *curam/sulit* → grade di sepertiga tertinggi; *singkat* / *panjang* → durasi Naismith sepertiga terendah / tertinggi;
- query nama gunung → nama jalur mengandung nama gunung tersebut.

Metrik: **Precision@5** dan **MRR**.

> **Catatan metodologi:** label relevansi dan boost atribut sama-sama diturunkan dari statistik GPX, sehingga angka metode hybrid mengukur *konsistensi ranking dengan definisi intent*, bukan penilaian manusia. Untuk naskah, lengkapi dengan label relevansi manual (misalnya penilaian pendaki/pakar) agar evaluasinya independen.
""")

code(r"""
#@title Precision@5 & MRR: metode awal vs metode web
import evaluate_search as ev

def rank(query, mode):
    return [(r['id'], s) for r, s in search(query, mode, top_n=len(routes))]

res = []
for q, label in ev.QUERIES:
    rel = ev.relevant_ids(routes, label)
    for mode in ('paper', 'hybrid'):
        ranked = rank(q, mode)
        p5, rr = ev.metrics(ranked, rel)
        res.append({'query': q, 'mode': mode, 'P@5': p5, 'RR': rr, 'top1': ranked[0][1]})
ev_df = pd.DataFrame(res)
summary = ev_df.groupby('mode')[['P@5', 'RR', 'top1']].mean().rename(columns={'RR': 'MRR', 'top1': 'skor top-1'})
display(ev_df.pivot(index='query', columns='mode', values='P@5'))
display(summary.round(3))
""")

code(r"""
#@title 9️⃣ Ringkasan (dihitung dari hasil di atas)
s = summary
print(f"Dataset: {len(routes)} jalur dari {len(files)} file GPX ({len(skipped)} dilewati karena duplikat/data rusak).")
print(f"Kesulitan: " + ', '.join(f"{k} {v}" for k, v in df['Kesulitan'].value_counts().items()))
print(f"Precision@5 : cosine murni {s.loc['paper','P@5']:.3f} → hybrid {s.loc['hybrid','P@5']:.3f}")
print(f"MRR         : cosine murni {s.loc['paper','MRR']:.3f} → hybrid {s.loc['hybrid','MRR']:.3f}")
print(f"Skor top-1  : cosine murni {s.loc['paper','skor top-1']:.3f} → hybrid {s.loc['hybrid','skor top-1']:.3f}")
""")

code(r"""
#@title 🔟 Export dataset ke CSV
out = df.copy()
out['Narasi'] = [r['narrative_text'] for r in routes]
out.to_csv('rutestrip_routes.csv', index=False)
print('Tersimpan: rutestrip_routes.csv')
if IN_COLAB:
    from google.colab import files
    files.download('rutestrip_routes.csv')
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
