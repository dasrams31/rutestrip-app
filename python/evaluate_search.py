"""
Offline evaluation of RuteStrip route search quality.

Compares the original scoring (cosine between the preprocessed query and the
stored narrative embedding) with the hybrid scoring used by the app
(name-aware document text, aspect sentences, attribute-aware boost).

Usage:
    python evaluate_search.py --db ../database/database.sqlite

Relevance labels are derived from the route statistics, so the evaluation is
reproducible without manual annotation:
    gentle queries -> average grade < 15% and total elevation gain in the
                      lowest third ("landai")
    easy queries   -> total elevation gain in the lowest third and average
                      grade < 30% (beginner: little climbing, short)
    hard queries   -> average grade in the highest third
    short queries  -> Naismith duration in the lowest third
    long queries   -> Naismith duration in the highest third
    name queries   -> route name contains the mountain name
"""
import argparse
import json
import sqlite3
import time

import numpy as np

import search_scoring as ss
from processor import get_model, preprocess_text

QUERIES = [
    ("jalur landai", "gentle"),
    ("jalur untuk pemula", "easy"),
    ("pendakian santai dan mudah", "easy"),
    ("jalur ramah pemula tidak terlalu curam", "gentle"),
    ("jalur curam dan menantang", "hard"),
    ("jalur sulit untuk pendaki berpengalaman", "hard"),
    ("pendakian ekstrem", "hard"),
    ("pendakian singkat", "short"),
    ("jalur pendek bisa tektok", "short"),
    ("jalur panjang untuk ekspedisi", "long"),
    ("pendakian lama beberapa hari", "long"),
    ("merbabu", "name:merbabu"),
    ("jalur pemula merbabu", "name:merbabu"),
    ("gunung slamet", "name:slamet"),
    ("ciremai via apuy", "name:ciremai"),
    ("pendakian lawu", "name:lawu"),
    ("sumbing", "name:sumbing"),
]

# Queries that name a place; scored separately so the main numbers stay
# comparable with earlier runs. Used only when routes have a stored province.
REGION_QUERIES = [
    ("jalur landai di jawa barat", "region:jawa barat"),
    ("pendakian di jawa tengah untuk pemula", "region:jawa tengah"),
    ("gunung di jatim", "region:jawa timur"),
    ("jalur pendek jabar", "region:jawa barat"),
]


def load_routes(db_path):
    con = sqlite3.connect(db_path)
    con.row_factory = sqlite3.Row
    cols = {c[1] for c in con.execute("PRAGMA table_info(hiking_routes)")}
    place = ", province, regency" if {"province", "regency"} <= cols else ""
    rows = con.execute(
        "SELECT id, name, distance_km, elevation_gain_m, naismith_duration_hour, average_grade_pct, "
        "narrative_text, description, sbert_embedding" + place + " FROM hiking_routes WHERE sbert_embedding IS NOT NULL"
    ).fetchall()
    con.close()
    routes = []
    for r in rows:
        d = dict(r)
        d["sbert_embedding"] = json.loads(d["sbert_embedding"])
        routes.append(d)
    return routes


def relevant_ids(routes, label):
    if label.startswith("region:"):
        key = label.split(":", 1)[1]
        return {r["id"] for r in routes if (r.get("province") or "").lower() == key}
    if label.startswith("name:"):
        key = label.split(":", 1)[1]
        return {r["id"] for r in routes if key in (r["name"] or "").lower()}
    if label in ("gentle", "easy"):
        gains = sorted(float(r["elevation_gain_m"] or 0) for r in routes)
        gain_cut = gains[len(gains) // 3]
        max_grade = 15 if label == "gentle" else 30
        return {r["id"] for r in routes
                if float(r["average_grade_pct"] or 0) < max_grade and float(r["elevation_gain_m"] or 0) <= gain_cut}
    field = "average_grade_pct" if label in ("easy", "hard") else "naismith_duration_hour"
    values = sorted(float(r[field] or 0) for r in routes)
    low_cut = values[len(values) // 3]
    high_cut = values[(2 * len(values)) // 3]
    if label in ("easy", "short"):
        return {r["id"] for r in routes if float(r[field] or 0) <= low_cut}
    return {r["id"] for r in routes if float(r[field] or 0) >= high_cut}


def cos(a, b):
    a = np.asarray(a, dtype=np.float32)
    b = np.asarray(b, dtype=np.float32)
    return float(a @ b / (np.linalg.norm(a) * np.linalg.norm(b) + 1e-12))


def metrics(ranked, rel, k=5):
    top = [rid for rid, _ in ranked[:k]]
    p_at_k = sum(1 for rid in top if rid in rel) / k
    rr = 0.0
    for i, (rid, _) in enumerate(ranked, 1):
        if rid in rel:
            rr = 1.0 / i
            break
    return p_at_k, rr


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--db", required=True)
    args = ap.parse_args()

    routes = load_routes(args.db)
    model = get_model()

    t0 = time.time()
    docs = {r["id"]: ss.build_document(r) for r in routes}
    doc_vecs = {rid: model.encode(d["text"]).tolist() for rid, d in docs.items()}
    aspect_vecs = {rid: model.encode(d["aspects"]).tolist() for rid, d in docs.items()}
    index_ms = (time.time() - t0) * 1000

    queries = list(QUERIES)
    if any(r.get("province") for r in routes):
        queries += REGION_QUERIES

    rows = {"baseline": [], "hybrid": []}
    for query, label in queries:
        rel = relevant_ids(routes, label)
        q_base = model.encode(preprocess_text(query)).tolist()
        base_ranked = sorted(((r["id"], cos(q_base, r["sbert_embedding"])) for r in routes), key=lambda x: -x[1])

        q_vec = model.encode(ss.query_text(query)).tolist()
        scored = []
        for r in routes:
            s = ss.hybrid_score(query, q_vec, doc_vecs[r["id"]], aspect_vecs[r["id"]], r, routes)
            scored.append((r["id"], s["score"]))
        hyb_ranked = sorted(scored, key=lambda x: -x[1])

        if label in ("gentle", "easy"):
            names = {r["id"]: r["name"] for r in routes}
            print(f"  {query!r}: top3 = {[names[rid] for rid, _ in hyb_ranked[:3]]}")

        for name, ranked in (("baseline", base_ranked), ("hybrid", hyb_ranked)):
            p5, rr = metrics(ranked, rel)
            rows[name].append((query, p5, rr, ranked[0][1], np.mean([s for _, s in ranked[:5]])))

    print(f"routes={len(routes)} queries={len(queries)} index_ms={index_ms:.0f}")
    print(f"{'query':42s} {'P@5 base':>8s} {'P@5 hyb':>8s} {'top1 base':>9s} {'top1 hyb':>9s}")
    for (q, p5b, _, t1b, _), (_, p5h, _, t1h, _) in zip(rows["baseline"], rows["hybrid"]):
        print(f"{q:42s} {p5b:8.2f} {p5h:8.2f} {t1b:9.3f} {t1h:9.3f}")
    n_main = len(QUERIES)
    for label, sl in (("", slice(0, n_main)), (" region", slice(n_main, None))):
        for name in ("baseline", "hybrid"):
            part = rows[name][sl]
            if not part:
                continue
            arr = np.array([r[1:] for r in part], dtype=float)
            print(f"{name + label:16s} P@5={arr[:,0].mean():.3f} MRR={arr[:,1].mean():.3f} "
                  f"mean_top1={arr[:,2].mean():.3f} mean_top5={arr[:,3].mean():.3f}")


if __name__ == "__main__":
    main()
