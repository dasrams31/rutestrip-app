"""
Offline evaluation of RuteStrip route search quality.

Compares three rankings:
    baseline  cosine between the preprocessed query and the stored narrative
              embedding (the paper's method);
    hybrid    the older hybrid_score() (name-aware document text, aspect
              sentences, attribute percentiles blended into the score);
    ranked    rank_routes(), what the website uses now (named mountain or place
              = filter, intent words = deterministic order, SBERT breaks ties).

Usage:
    python evaluate_search.py --db ../database/database.sqlite [--labels new|old]

Relevance labels are derived from the route statistics, so the evaluation is
reproducible without manual annotation. --labels new (default) follows the
current search rules:
    gentle queries -> average grade in the lowest third ("landai")
    easy queries   -> difficulty score (Shenandoah) in the lowest third
    hard queries   -> difficulty score in the highest third
    steep queries  -> average grade in the highest third ("curam", "terjal")
    short queries  -> Naismith duration in the lowest third
    long queries   -> Naismith duration in the highest third
    name queries   -> route name contains the mountain name
--labels old keeps the earlier definitions for comparison with older runs
(gentle: grade < 15% and gain in the lowest third; easy: gain in the lowest
third and grade < 30%; hard and steep: grade in the highest third).
"""
import argparse
import json
import sqlite3
import time

import numpy as np

import search_scoring as ss
from processor import difficulty_score, get_model, preprocess_text

QUERIES = [
    ("jalur landai", "gentle"),
    ("jalur untuk pemula", "easy"),
    ("pendakian santai dan mudah", "easy"),
    ("jalur ramah pemula tidak terlalu curam", "gentle"),
    ("jalur curam dan menantang", "steep"),
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
    if "steepest_grade_pct" in cols:
        place += ", steepest_grade_pct"
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


def _thirds(routes, value):
    values = sorted(value(r) for r in routes)
    return values[len(values) // 3], values[(2 * len(values)) // 3]


def relevant_ids(routes, label, labels="new"):
    if label.startswith("region:") or label.startswith("name:") or labels == "old":
        return relevant_ids_old(routes, "hard" if label == "steep" else label)
    if label in ("easy", "hard"):
        value = lambda r: difficulty_score(r["distance_km"], r["elevation_gain_m"], r["average_grade_pct"], ss._max_elevation(r))
    elif label in ("gentle", "steep"):
        value = lambda r: float(r["average_grade_pct"] or 0)
    else:
        value = lambda r: float(r["naismith_duration_hour"] or 0)
    low_cut, high_cut = _thirds(routes, value)
    if label in ("gentle", "easy", "short"):
        return {r["id"] for r in routes if value(r) <= low_cut}
    return {r["id"] for r in routes if value(r) >= high_cut}


def relevant_ids_old(routes, label):
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
    ap.add_argument("--labels", choices=("new", "old"), default="new")
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

    rows = {"baseline": [], "hybrid": [], "ranked": []}
    names = {r["id"]: r["name"] for r in routes}
    for query, label in queries:
        rel = relevant_ids(routes, label, args.labels)
        q_base = model.encode(preprocess_text(query)).tolist()
        base_ranked = sorted(((r["id"], cos(q_base, r["sbert_embedding"])) for r in routes), key=lambda x: -x[1])

        q_vec = model.encode(ss.query_text(query)).tolist()
        scored = []
        for r in routes:
            s = ss.hybrid_score(query, q_vec, doc_vecs[r["id"]], aspect_vecs[r["id"]], r, routes)
            scored.append((r["id"], s["score"]))
        hyb_ranked = sorted(scored, key=lambda x: -x[1])

        new_ranked = [(row["id"], row["score"]) for row in ss.rank_routes(query, q_vec, routes, doc_vecs, aspect_vecs)]
        print(f"  {query!r}: top3 = {[names[rid] for rid, _ in new_ranked[:3]]}")

        for name, ranked in (("baseline", base_ranked), ("hybrid", hyb_ranked), ("ranked", new_ranked)):
            p5, rr = metrics(ranked, rel)
            rows[name].append((query, p5, rr, ranked[0][1] if ranked else 0.0, np.mean([s for _, s in ranked[:5]]) if ranked else 0.0))

    print(f"routes={len(routes)} queries={len(queries)} index_ms={index_ms:.0f}")
    print(f"{'query':42s} {'P@5 base':>8s} {'P@5 hyb':>8s} {'P@5 rank':>8s} {'MRR rank':>8s}")
    for (q, p5b, *_), (_, p5h, *_), (_, p5r, rrr, *_) in zip(rows["baseline"], rows["hybrid"], rows["ranked"]):
        print(f"{q:42s} {p5b:8.2f} {p5h:8.2f} {p5r:8.2f} {rrr:8.2f}")
    n_main = len(QUERIES)
    for label, sl in (("", slice(0, n_main)), (" region", slice(n_main, None))):
        for name in ("baseline", "hybrid", "ranked"):
            part = rows[name][sl]
            if not part:
                continue
            arr = np.array([r[1:] for r in part], dtype=float)
            print(f"{name + label:16s} P@5={arr[:,0].mean():.3f} MRR={arr[:,1].mean():.3f} "
                  f"mean_top1={arr[:,2].mean():.3f} mean_top5={arr[:,3].mean():.3f}")


if __name__ == "__main__":
    main()
