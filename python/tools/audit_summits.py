"""
Audit RuteStrip routes: does each GPX track actually reach its summit?

For every route the intended summit is picked from OSM natural=peak|volcano
nodes within SEARCH_RADIUS_M of the track (name match against the route's
mountain, else the highest peak near the track's high point; manual choices in
route_audit_overrides.json win). The FULL GPX is then measured: minimum
horizontal distance track->peak, track max elevation vs peak elevation, round
trip or one way, and GPX elevation vs DEM (broken elevation check).

Classes
  COMPLETE            track passes within COMPLETE_DIST_M of the peak and its
                      max elevation is within COMPLETE_ELE_M of the peak
                      elevation (GPX max vs peak ele, or DEM-vs-DEM at the
                      closest track point when the node is well placed), or
                      the summit-crater rule for natural=volcano nodes (see
                      CRATER_* constants)
  INCOMPLETE          stops short: missing horizontal distance / height reported
  NOT_A_SUMMIT_ROUTE  destination is legitimately not a peak (override, justified)
  DATA_PROBLEM        wrong area / broken elevation / no track

Usage
  python python/tools/audit_summits.py                        # audit dataset/routes.json + dataset/gpx
  python python/tools/audit_summits.py --gpx FILE --route-id N  # re-check one replacement GPX
  options: --routes PATH --gpx-dir DIR --out DIR --ids 3,4,5

Writes OUT/audit_raw.json (all numbers + candidate peaks). The report
(audit.csv / audit.md) is produced by build_audit_report.py.
"""
import argparse
import difflib
import json
import os
import statistics
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import route_audit_lib as L  # noqa: E402

SEARCH_RADIUS_M = 4000
COMPLETE_DIST_M = 250
COMPLETE_ELE_M = 80
CRATER_DIST_M = 800     # summit-crater rule (volcano node over the crater, ele tag >= 50 m above DEM):
                        # track within 800 m of the node,
CRATER_DEM_TOL_M = 30   # max ele within 80 m, and DEM at the closest track point >= node DEM - 30 m
ROUND_TRIP_M = 300
OVERRIDES_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "route_audit_overrides.json")
STOP = {"to", "ke", "dan", "via", "loop", "jalur", "tahura", "baru", "lama", "trek", "trail", "the", "and",
        "alternatif", "sentul", "pacet"}


def load_overrides():
    return {str(k): v for k, v in L._load(OVERRIDES_PATH, {}).items() if not k.startswith("_")}


def target_tokens(route, ov):
    if ov.get("peak_names"):
        return [L.norm_name(n) for n in ov["peak_names"]]
    mountain = route.get("mountain") or route.get("name")
    via = L.norm_name(route.get("via") or "")
    toks = [t for t in L.norm_name(mountain).split() if t not in STOP and t not in via.split()]
    return toks


def name_matches(peak_name, tokens):
    pn = L.norm_name(peak_name)
    if not pn:
        return 0.0
    if pn in tokens:
        return 1.0
    ptoks = pn.split()
    best = 0.0
    for t in tokens:
        if " " in t:  # multi-word target (e.g. "kenteng songo")
            if t in pn:
                best = max(best, 0.95)
            continue
        for p in ptoks:
            if len(t) >= 3 and len(p) >= 3:
                r = difflib.SequenceMatcher(None, t, p).ratio()
                if t == p:
                    r = 1.0
                if r >= 0.8:
                    # multi-mountain names ("Arjuno-Welirang") rank below single-summit names
                    best = max(best, r * (0.9 if len(ptoks) > 1 else 1.0))
            elif t == p:  # short tokens like "29"
                best = max(best, 0.9)
    return best


def fetch_peaks(routes_pts):
    """routes_pts: {rid: points}. One union query per batch of 15 bboxes."""
    ids = list(routes_pts)
    peaks = {}
    for i in range(0, len(ids), 15):
        parts = []
        for rid in ids[i:i + 15]:
            s, w, n, e = L.buffered_bbox(routes_pts[rid], SEARCH_RADIUS_M)
            parts.append(f'node["natural"~"^(peak|volcano|hill)$"]({L.bbox_str(s, w, n, e)});')
        data = L.overpass(f"[out:json][timeout:120];({''.join(parts)});out;")
        for el in data["elements"]:
            peaks[el["id"]] = el
    return peaks


def measure(points, peak, peak_ele, dem):
    """points: [(lat, lon, ele)] full track. dem: callable for DEM lookups."""
    p = (peak["lat"], peak["lon"])
    dist, seg = L.min_distance_to_track(points, p)
    ni = seg if L.haversine(points[seg], p) <= L.haversine(points[min(seg + 1, len(points) - 1)], p) \
        else min(seg + 1, len(points) - 1)
    eles = [q[2] for q in points if q[2] is not None and q[2] > 0]
    return dist, ni, (max(eles) if eles else None)


def audit_route(rid, route, points, peaks_all, ov):
    res = {"route_id": rid, "name": route.get("name"), "mountain": route.get("mountain"), "via": route.get("via"),
           "source": route.get("source"), "gpx_file": route.get("gpx_file")}
    if len(points) < 2:
        res.update(cls="DATA_PROBLEM", reason="GPX has fewer than 2 points")
        return res
    xy = [(q[0], q[1]) for q in points]
    length = L.track_length(xy)
    start, end = xy[0], xy[-1]
    res.update(n_points=len(points), length_km=round(length / 1000, 2),
               start=[round(start[0], 6), round(start[1], 6)], end=[round(end[0], 6), round(end[1], 6)],
               round_trip=L.haversine(start, end) <= ROUND_TRIP_M and length > 1000,
               start_end_gap_m=round(L.haversine(start, end)))
    # wrong-area check vs routes.json trailhead
    if route.get("trailhead_lat") not in (None, ""):
        th = (float(route["trailhead_lat"]), float(route["trailhead_lng"]))
        res["trailhead_offset_m"] = round(L.nearest_index(xy, th)[1])
    # candidate peaks
    s, w, n, e = L.buffered_bbox(xy, SEARCH_RADIUS_M)
    thin = xy[:: max(1, len(xy) // 1500)] + [xy[-1]]
    cands = []
    for pk in peaks_all.values():
        if not (s <= pk["lat"] <= n and w <= pk["lon"] <= e):
            continue
        d, _ = L.min_distance_to_track(thin, (pk["lat"], pk["lon"]))
        if d <= SEARCH_RADIUS_M:
            cands.append((pk, d))
    # DEM for candidates + sampled track points (broken-elevation check)
    samp_idx = sorted(set(range(0, len(xy), max(1, len(xy) // 25))) | {len(xy) - 1})
    L.elevations([(pk["lat"], pk["lon"]) for pk, _ in cands] + [xy[i] for i in samp_idx])
    dem = lambda pts: L.elevations(pts)  # noqa: E731
    cinfo = []
    for pk, d in cands:
        tags = pk.get("tags", {})
        osm_ele = L.parse_ele(tags.get("ele"))
        dem_ele = dem([(pk["lat"], pk["lon"])])[0]
        cinfo.append({"id": pk["id"], "name": tags.get("name") or tags.get("name:id") or "", "lat": pk["lat"],
                      "lon": pk["lon"], "osm_ele": osm_ele, "dem_ele": dem_ele, "dist_m": round(d),
                      "natural": tags.get("natural"), "gns": tags.get("source") == "NGA-GNS"})
    res["candidates"] = sorted(cinfo, key=lambda c: c["dist_m"])[:12]

    # GPX elevation sanity
    gpx_eles = [points[i][2] for i in samp_idx]
    dem_s = dem([xy[i] for i in samp_idx])
    if all(v is None for v in gpx_eles):
        res["ele_status"] = "missing (DEM used)"
        points = [(q[0], q[1], None) for q in points]
    else:
        diffs = [abs(g - d) for g, d in zip(gpx_eles, dem_s) if g is not None]
        res["ele_median_abs_diff_dem_m"] = round(statistics.median(diffs)) if diffs else None
        res["ele_status"] = "ok" if diffs and statistics.median(diffs) <= 150 else "broken"
    track_eles = [q[2] for q in points if q[2] is not None and q[2] > 0]
    res["track_max_ele"] = round(max(track_eles)) if track_eles else round(max(dem_s))
    res["track_min_ele"] = round(min(track_eles)) if track_eles else round(min(dem_s))

    if ov.get("class") == "NOT_A_SUMMIT_ROUTE" and not ov.get("peak_node"):
        res.update(cls="NOT_A_SUMMIT_ROUTE", reason=ov.get("reason", ""))
        return res

    # choose intended peak
    peak = None
    if ov.get("peak_node"):
        peak = next((c for c in cinfo if c["id"] == ov["peak_node"]), None)
        if peak is None:
            data = L.overpass(f"[out:json];node({ov['peak_node']});out;")
            el = data["elements"][0]
            tags = el.get("tags", {})
            peak = {"id": el["id"], "name": tags.get("name", ""), "lat": el["lat"], "lon": el["lon"],
                    "osm_ele": L.parse_ele(tags.get("ele")), "dem_ele": dem([(el["lat"], el["lon"])])[0],
                    "dist_m": None, "natural": tags.get("natural"), "gns": tags.get("source") == "NGA-GNS"}
        res["peak_choice"] = "override"
    else:
        toks = target_tokens(route, ov)
        res["target_tokens"] = toks
        scored = [(name_matches(c["name"], toks), c) for c in cinfo]
        scored = [(sc, c) for sc, c in scored if sc >= 0.8 and c["natural"] != "hill" or sc >= 0.95]
        if scored:
            # best name score; then non-GNS nodes (GNS positions are often off by km); then highest
            scored.sort(key=lambda t: (-round(t[0], 2), t[1]["gns"], -(t[1]["osm_ele"] or t[1]["dem_ele"])))
            peak = scored[0][1]
            res["peak_choice"] = "name"
        else:
            hi = max(range(len(xy)), key=lambda i: (points[i][2] or 0)) if track_eles else 0
            near = [c for c in cinfo if L.haversine((c["lat"], c["lon"]), xy[hi]) <= 2000
                    and c["natural"] != "hill"]
            if near:
                peak = max(near, key=lambda c: c["osm_ele"] or c["dem_ele"])
                res["peak_choice"] = "highest-near-track-high-point"
    if peak is None:
        res.update(cls="NOT_A_SUMMIT_ROUTE" if ov.get("class") == "NOT_A_SUMMIT_ROUTE" else "UNRESOLVED",
                   reason=ov.get("reason") or "no OSM peak near the track matches the route")
        return res

    pk_ele = ov.get("peak_ele") or (peak["osm_ele"] if peak["osm_ele"] else peak["dem_ele"])
    dist, ni, _ = measure(points, {"lat": peak["lat"], "lon": peak["lon"]}, pk_ele, dem)
    near_dem = dem([xy[ni]])[0]
    res.update(peak_id=peak["id"], peak_name=peak["name"], peak_lat=peak["lat"], peak_lon=peak["lon"],
               peak_ele=round(pk_ele), peak_ele_src="override" if ov.get("peak_ele") else ("osm" if peak["osm_ele"] else "dem"),
               peak_dem_ele=round(peak["dem_ele"]), peak_gns=peak["gns"],
               dist_to_peak_m=round(dist), nearest_idx=ni, nearest_is_end=ni >= len(xy) - 3 or ni <= 2,
               nearest_frac=round(L.cumdist(xy[:ni + 1])[-1] / length, 3) if length else 0,
               ele_gap_m=round(pk_ele - res["track_max_ele"]),
               dem_gap_m=round(peak["dem_ele"] - near_dem))
    # GPS/barometric track elevations are often off; accept the DEM-vs-DEM gap only when the
    # peak node's elevation agrees with the DEM at its position (node is well placed).
    node_consistent = abs(pk_ele - peak["dem_ele"]) <= 100
    res["peak_node_consistent"] = node_consistent
    # volcano node tagged >= 50 m above the DEM at its position: it sits over the summit crater
    res["peak_crater"] = peak["natural"] == "volcano" and pk_ele - peak["dem_ele"] >= 50
    ele_ok = res["ele_gap_m"] <= COMPLETE_ELE_M or (node_consistent and res["dem_gap_m"] <= COMPLETE_ELE_M)
    if res.get("trailhead_offset_m", 0) > 3000:
        res.update(cls="DATA_PROBLEM", reason=f"track is {res['trailhead_offset_m']} m from the listed trailhead")
    elif ov.get("class") == "NOT_A_SUMMIT_ROUTE":
        res.update(cls="NOT_A_SUMMIT_ROUTE", reason=ov.get("reason", ""))
    elif dist <= COMPLETE_DIST_M and ele_ok:
        res.update(cls="COMPLETE", reason=f"passes {round(dist)} m from {peak['name']}")
    elif (res["peak_crater"] and dist <= CRATER_DIST_M and res["ele_gap_m"] <= COMPLETE_ELE_M
          and res["dem_gap_m"] <= CRATER_DEM_TOL_M):
        # summit-crater rule: volcano nodes often sit in/over the crater, not on the rim high point
        res.update(cls="COMPLETE", crater_rule=True,
                   reason=f"reaches the summit crater rim at {res['track_max_ele']} m "
                          f"({round(dist)} m from the {peak['name']} node, which is not on the track's rim)")
    elif dist <= COMPLETE_DIST_M:
        res.update(cls="DATA_PROBLEM" if res["ele_status"] == "broken" else "REVIEW",
                   reason=f"passes {round(dist)} m from peak but elevation gap {res['ele_gap_m']} m "
                          f"(DEM gap {res['dem_gap_m']} m)")
    else:
        res.update(cls="INCOMPLETE",
                   reason=f"stops {round(dist)} m (horizontal) and {max(0, res['ele_gap_m'])} m "
                          f"(height) short of {peak['name']}")
    if ov.get("class") and ov["class"] != res["cls"] and ov["class"] != "NOT_A_SUMMIT_ROUTE":
        res["auto_cls"] = res["cls"]
        res.update(cls=ov["class"], reason=ov.get("reason", res["reason"]))
    if ov.get("note"):
        res["note"] = ov["note"]
    return res


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--routes", default=os.path.join(L.ROOT, "dataset", "routes.json"))
    ap.add_argument("--gpx-dir", default=os.path.join(L.ROOT, "dataset"))
    ap.add_argument("--out", default=L.DEFAULT_OUT)
    ap.add_argument("--ids", default="")
    ap.add_argument("--gpx", help="audit this GPX instead of the route's own file (needs --route-id)")
    ap.add_argument("--route-id")
    args = ap.parse_args()
    routes = {str(r["route_id"]): r for r in json.load(open(args.routes, encoding="utf-8"))}
    overrides = load_overrides()
    if args.gpx:
        ids = [args.route_id]
    else:
        ids = [i.strip() for i in args.ids.split(",") if i.strip()] or list(routes)
    pts = {}
    for rid in ids:
        path = args.gpx or os.path.join(args.gpx_dir, routes[rid]["gpx_file"])
        pts[rid] = L.dedupe(L.read_gpx(path))
    peaks = fetch_peaks({rid: [(q[0], q[1]) for q in p] for rid, p in pts.items()})
    results = []
    for rid in ids:
        r = audit_route(rid, routes[rid], pts[rid], peaks, overrides.get(rid, {}))
        print(f"{rid:>4} {r['cls']:<18} {r.get('dist_to_peak_m', '-'):>6} m {r.get('ele_gap_m', '-'):>5} m "
              f"{(r.get('peak_name') or '')[:28]:<28} {r['name']}")
        results.append(r)
    if not args.gpx:
        os.makedirs(args.out, exist_ok=True)
        out = os.path.join(args.out, "audit_raw.json")
        old = {str(r["route_id"]): r for r in L._load(out, [])} if args.ids else {}
        old.update({r["route_id"]: r for r in results})
        with open(out, "w", encoding="utf-8") as f:
            json.dump(sorted(old.values(), key=lambda r: int(r["route_id"])), f, indent=1, ensure_ascii=False)
        print(f"wrote {out}")
    else:
        print(json.dumps({k: v for k, v in results[0].items() if k != "candidates"}, indent=1, ensure_ascii=False))
    print("API usage:", json.dumps(L.USAGE.get("total", {})))


if __name__ == "__main__":
    main()
