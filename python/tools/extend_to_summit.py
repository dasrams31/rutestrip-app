"""
Extended completion tier (owner-approved) for routes that complete_to_summit.py
left CANNOT_COMPLETE because the OSM connection is longer than 3 km.

A connection of up to EXT_MAX_ADD_M (6 km) is accepted only if ALL hold:
  1. every added way is highway=path|footway|track|steps (no roads; OSM noding
     gaps <= 30 m may be joined),
  2. it climbs: total descent along the added part <= 150 m and net ascent
     >= 70 % of the height gap between the attach point and the peak (DEM,
     resampled every 30 m),
  3. detour ratio: added length <= 1.8 x straight line attach point -> peak,
  4. among candidate paths (k shortest, up to K_PATHS) the one with the most
     hiking evidence (share of length on ways named Jalur/Pendakian/Trek/Trail,
     with sac_scale, or in a route=hiking relation) wins, then the shortest.
Results are status EXTENDED_REVIEW (not COMPLETE): GPX + preview PNG in
OUT/review/, numbers in OUT/completion_results.json (method "extended").

Usage
  python python/tools/extend_to_summit.py [--ids 3,56,104] [--out DIR]
  (default ids: every cannot_complete entry whose reason is the 3 km limit)
"""
import argparse
import itertools
import json
import os
import re
import sys

import networkx as nx

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import route_audit_lib as L  # noqa: E402
from complete_to_summit import (ATTACH_DIST_M, ATTACH_WINDOW_M, SPACING_M, TARGET_DIST_M, build_graph,  # noqa: E402
                                fetch_network, finalize, first_pass, max_jump, nodes_near, osm_links, path_coords,
                                path_ways)

EXT_MAX_ADD_M = 6000
MAX_DESCENT_M = 150
MIN_NET_ASCENT = 0.70
MAX_DETOUR = 1.8
K_PATHS = 12
ALLOWED = {"path", "footway", "track", "steps"}
EVIDENCE_NAME = re.compile(r"jalur|pendakian|trek|trail", re.I)


def evidence(way, rel_members):
    t = way.get("tags", {})
    ev = []
    if EVIDENCE_NAME.search(t.get("name", "")):
        ev.append(f"name '{t['name']}'")
    if "sac_scale" in t:
        ev.append(f"sac_scale={t['sac_scale']}")
    if way["id"] in rel_members:
        ev.append(f"route=hiking relation {rel_members[way['id']]}")
    return ev


def extended_connect(base, peak, peak_ele, buffer_m=1200, tail=200):
    # same path-network window as complete_to_summit.py (last 200 track points + peak, 1.2 km buffer),
    # so the Overpass response cached by the standard tier is reused
    s, w, n, e = L.buffered_bbox(base[-min(len(base), tail):] + [peak], buffer_m)
    ways_all, rels = fetch_network(s, w, n, e)
    rel_members = {}
    for r in rels.values():
        for m in r.get("members", []):
            if m.get("type") == "way":
                rel_members.setdefault(m["ref"], r["id"])
    ways = {wid: wy for wid, wy in ways_all.items() if wy.get("tags", {}).get("highway") in ALLOWED}
    ctx = {"ways_all": ways_all, "bbox": (s, w, n, e)}
    if not ways:
        return {"ok": False, "reason": "no OSM path/footway/track/steps ways in the area", **ctx}
    G = build_graph(ways)
    tgt = nodes_near(G, peak, TARGET_DIST_M)
    if not tgt:
        return {"ok": False, "reason": f"no path node within {TARGET_DIST_M} m of the peak", **ctx}
    tnode = min(tgt, key=lambda t: t[1])[0]
    cd = L.cumdist(base)
    attach = {}  # node -> (gap, base index): best (latest, closest) base point per node
    last_c = None
    for i in range(len(base) - 1, -1, -1):
        if cd[-1] - cd[i] > ATTACH_WINDOW_M:
            break
        if last_c is not None and last_c - cd[i] < 10 and i != 0:
            continue
        last_c = cd[i]
        for nid, d in nodes_near(G, base[i], ATTACH_DIST_M):
            if nid not in attach or d < attach[nid][0] - 5:
                attach[nid] = (d, i)
    if not attach:
        return {"ok": False, "reason": "track end is not near any OSM path", **ctx}
    H = G.copy()
    for nid, (d, i) in attach.items():
        H.add_edge("SRC", nid, weight=d, way="attach")
    if not nx.has_path(H, "SRC", tnode):
        return {"ok": False, "reason": "no OSM path/footway/track/steps connection to the peak", **ctx}
    way_len = {}
    cands = []
    for path in itertools.islice(nx.shortest_simple_paths(H, "SRC", tnode, weight="weight"), K_PATHS * 4):
        p = path[1:]
        length = sum(G.edges[a, b]["weight"] for a, b in zip(p, p[1:])) + H.edges["SRC", p[0]]["weight"]
        if length > EXT_MAX_ADD_M:
            break
        ev_len = 0.0
        way_len.clear()
        for a, b in zip(p, p[1:]):
            wid = G.edges[a, b]["way"]
            if wid != "snap" and evidence(ways[wid], rel_members):
                ev_len += G.edges[a, b]["weight"]
        key = tuple(path_ways(G, p))
        if any(c["key"] == key for c in cands):
            continue
        cands.append({"path": p, "len": length, "ev_share": ev_len / length if length else 0, "key": key})
        if len(cands) >= K_PATHS:
            break
    if not cands:
        return {"ok": False, "reason": f"OSM path connection longer than {EXT_MAX_ADD_M / 1000:.0f} km", **ctx}
    cands.sort(key=lambda c: (-round(c["ev_share"], 1), c["len"]))
    fails = []
    for c in cands:
        p = c["path"]
        gap, i = attach[p[0]]
        i, gap = first_pass(base, G.nodes[p[0]]["pos"], i, gap)
        start = base[i]
        added = [start] + path_coords(G, p)
        straight = L.haversine(start, peak)
        detour = c["len"] / straight if straight else 99
        if detour > MAX_DETOUR:
            fails.append(f"{c['len'] / 1000:.1f} km: detour {detour:.2f}")
            continue
        rs = L.resample(added, SPACING_M)
        el = L.elevations(rs)
        descent = sum(max(0.0, a - b) for a, b in zip(el, el[1:]))
        height_gap = peak_ele - el[0]
        net = el[-1] - el[0]
        net_share = net / height_gap if height_gap > 0 else 1.0
        if descent > MAX_DESCENT_M or net_share < MIN_NET_ASCENT:
            fails.append(f"{c['len'] / 1000:.1f} km: descent {descent:.0f} m, net ascent {net_share:.0%}")
            continue
        wl = path_ways(G, p)
        ev_detail = sorted({x for wid in wl for x in evidence(ways[wid], rel_members)})
        snaps = sum(1 for a, b in zip(p, p[1:]) if G.edges[a, b]["way"] == "snap")
        return {"ok": True, "points": list(base[:i + 1]) + added[1:], "added": added, "ways": wl,
                "km_added": round(c["len"] / 1000, 2), "cut_idx": i, "km_cut": round((cd[-1] - cd[i]) / 1000, 2),
                "attach_gap_m": round(gap), "descent_m": round(descent), "net_ascent_m": round(net),
                "height_gap_m": round(height_gap), "net_ascent_pct": round(100 * net_share),
                "detour_ratio": round(detour, 2), "straight_km": round(straight / 1000, 2),
                "evidence_share_pct": round(100 * c["ev_share"]), "evidence": ev_detail[:8], "snaps": snaps,
                "candidates_tested": len(fails) + 1, "rejected_candidates": fails,
                "highways": sorted({ways[wid]["tags"]["highway"] for wid in wl}), **ctx}
    return {"ok": False, "reason": "no candidate path satisfies the climb/detour rules (" + "; ".join(fails[:5]) + ")",
            **ctx}


def render_png(path, title, original, kept, added, peak, ways_all, bbox, notes):
    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt
    import math
    lat0 = peak[0]
    k = math.cos(math.radians(lat0))
    X = lambda pts: [p[1] * k for p in pts]  # noqa: E731
    Y = lambda pts: [p[0] for p in pts]  # noqa: E731
    fig, ax = plt.subplots(figsize=(9, 9), dpi=110)
    for wy in ways_all.values():
        g = [(q["lat"], q["lon"]) for q in wy["geometry"] if q]
        hw = wy.get("tags", {}).get("highway", "")
        ax.plot(X(g), Y(g), color="#c8c8c8" if hw in ALLOWED else "#e6d6b8", lw=0.8, zorder=1)
    ax.plot(X(original), Y(original), color="#7aa6d8", lw=1.2, zorder=2, label="original track (full)")
    ax.plot(X(kept), Y(kept), color="#1f4e8c", lw=2.2, zorder=3, label="kept part of original")
    ax.plot(X(added), Y(added), color="#d62728", lw=2.6, zorder=4, label="added OSM segment")
    ax.scatter([peak[1] * k], [peak[0]], marker="^", s=160, color="black", zorder=5, label="peak")
    ax.scatter([kept[0][1] * k], [kept[0][0]], marker="o", s=60, color="#1f4e8c", zorder=5, label="trailhead")
    allp = original + kept + added + [peak]
    xs, ys = X(allp), Y(allp)
    pad = 0.004
    ax.set_xlim(min(xs) - pad, max(xs) + pad)
    ax.set_ylim(min(ys) - pad, max(ys) + pad)
    ax.set_aspect("equal")
    # 1 km scale bar
    x0, y0 = ax.get_xlim()[0] + 0.002, ax.get_ylim()[0] + 0.002
    ax.plot([x0, x0 + 1000 / 111320], [y0, y0], color="black", lw=3)
    ax.text(x0, y0 + 0.0008, "1 km", fontsize=9)
    ax.set_title(title, fontsize=10)
    ax.text(0.01, 0.99, notes, transform=ax.transAxes, va="top", fontsize=8,
            bbox={"facecolor": "white", "alpha": 0.85, "edgecolor": "#999"})
    ax.legend(loc="lower right", fontsize=8)
    ax.set_xticks([])
    ax.set_yticks([])
    ax.text(0.99, 0.005, "Paths (c) OpenStreetMap contributors, ODbL 1.0 | grey = path/footway/track/steps, "
            "beige = other OSM ways", transform=ax.transAxes, ha="right", fontsize=7, color="#555")
    fig.tight_layout()
    fig.savefig(path)
    plt.close(fig)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--routes", default=os.path.join(L.ROOT, "dataset", "routes.json"))
    ap.add_argument("--gpx-dir", default=os.path.join(L.ROOT, "dataset"))
    ap.add_argument("--out", default=L.DEFAULT_OUT)
    ap.add_argument("--ids", default="")
    args = ap.parse_args()
    routes = {str(r["route_id"]): r for r in json.load(open(args.routes, encoding="utf-8"))}
    audit = {r["route_id"]: r for r in json.load(open(os.path.join(args.out, "audit_raw.json"), encoding="utf-8"))}
    res_path = os.path.join(args.out, "completion_results.json")
    results = L._load(res_path, {})
    ids = [i.strip() for i in args.ids.split(",") if i.strip()] or [
        rid for rid, c in results.items()
        if c.get("action") == "cannot_complete" and "km limit" in c.get("completion_fail", c.get("reason", ""))]
    os.makedirs(os.path.join(args.out, "review"), exist_ok=True)
    for rid in ids:
        a, route = audit[rid], routes[rid]
        peak = (a["peak_lat"], a["peak_lon"])
        raw = L.dedupe(L.read_gpx(os.path.join(args.gpx_dir, route["gpx_file"])))
        original = [(q[0], q[1]) for q in raw]
        xy = list(original)
        ni = a["nearest_idx"]
        if ni <= 2 and not a["round_trip"]:
            xy, ni = xy[::-1], len(xy) - 1 - ni
        base = xy[:ni + 1]
        r = extended_connect(base, peak, a["peak_ele"])
        prev = results.get(rid, {})
        if not r["ok"]:
            prev["extended_fail"] = r["reason"]
            results[rid] = prev
            print(f"{rid:>4} EXTENDED FAIL  {r['reason']}")
            continue
        pts, eles = finalize(r["points"], peak, a["peak_ele"])
        fname = f"{int(rid):03d}-{route['slug']}.gpx"
        entry = {"route_id": rid, "name": route["name"], "peak_name": a["peak_name"], "peak_ele": a["peak_ele"],
                 "old_max_ele": a["track_max_ele"], "old_dist_to_peak_m": a["dist_to_peak_m"],
                 "action": "replace", "status": "EXTENDED_REVIEW", "method": "extended", "file": f"review/{fname}",
                 "png": f"review/{int(rid):03d}.png", "standard_tier_fail": prev.get("standard_tier_fail") or prev.get("completion_fail")
                 or prev.get("reason"),
                 "base_mode": "round trip -> one way" if a["round_trip"] else "one way",
                 **{k: r[k] for k in ("ways", "km_added", "km_cut", "attach_gap_m", "descent_m", "net_ascent_m",
                                      "height_gap_m", "net_ascent_pct", "detour_ratio", "straight_km",
                                      "evidence_share_pct", "evidence", "snaps", "candidates_tested",
                                      "rejected_candidates", "highways")}}
        entry["reason"] = (f"extended tier: +{r['km_added']} km on OSM {'/'.join(r['highways'])} ways, descent "
                           f"{r['descent_m']} m, net ascent {r['net_ascent_pct']}% of the {r['height_gap_m']} m gap, "
                           f"detour {r['detour_ratio']}x, hiking evidence on {r['evidence_share_pct']}% of length")
        entry.update(new_max_ele=round(max(eles)), new_km=round(L.track_length(pts) / 1000, 2),
                     new_dist_to_peak_m=round(L.min_distance_to_track(pts, peak)[0]),
                     max_jump_m=round(max_jump(r["points"])))
        entry["source_urls"] = [h for h, _ in osm_links(entry["ways"])][:-1]
        desc = (f"{route['name']}: original RuteStrip track ({route.get('source')}) from the trailhead, completed to "
                f"{a['peak_name']} along OpenStreetMap ways {', '.join(map(str, entry['ways']))} (extended tier, "
                f"needs review). Path data (c) OpenStreetMap contributors, ODbL 1.0. Elevation: DEM "
                f"({'/'.join(L.elevation_sources(pts))}).")
        L.write_gpx(os.path.join(args.out, "review", fname), route["name"], desc, osm_links(entry["ways"])[:20],
                    pts, eles)
        notes = (f"#{rid} {route['name']} -> {a['peak_name']} ({a['peak_ele']} m)\n"
                 f"added {r['km_added']} km (straight {r['straight_km']} km, detour {r['detour_ratio']}x)\n"
                 f"descent {r['descent_m']} m, net ascent {r['net_ascent_m']} m = {r['net_ascent_pct']}% of gap\n"
                 f"hiking evidence {r['evidence_share_pct']}%: {'; '.join(r['evidence'][:3])}\n"
                 f"new max {entry['new_max_ele']} m (old {a['track_max_ele']} m), new length {entry['new_km']} km")
        render_png(os.path.join(args.out, entry["png"]), f"EXTENDED_REVIEW #{rid} {route['name']}",
                   original, r["points"][:r["cut_idx"] + 1], r["added"], peak, r["ways_all"], r["bbox"], notes)
        results[rid] = entry
        print(f"{rid:>4} EXTENDED_REVIEW +{r['km_added']} km, descent {r['descent_m']} m, net {r['net_ascent_pct']}%, "
              f"detour {r['detour_ratio']}, evidence {r['evidence_share_pct']}%  {route['name']}")
    with open(res_path, "w", encoding="utf-8") as f:
        json.dump(results, f, indent=1, ensure_ascii=False)
    print("API usage:", json.dumps(L.USAGE.get("total", {})))


if __name__ == "__main__":
    main()
