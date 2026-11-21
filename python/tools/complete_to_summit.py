"""
Complete INCOMPLETE RuteStrip tracks to their summit along REAL OpenStreetMap ways.

For each route classified INCOMPLETE in OUT/audit_raw.json:
  1. base = the original track from the trailhead to the point nearest the peak
     (round trips that turned back early become one way: outbound half only).
  2. Fetch the OSM path network (highway=path|footway|track|steps|bridleway
     plus every way of route=hiking relations) in a bbox around the base's end
     and the peak.
  3. Shortest path on that graph from an OSM node near the base's last
     ATTACH_WINDOW_M (within ATTACH_DIST_M of the track) to the way node
     nearest the peak (must be within TARGET_DIST_M of the peak). Accepted only
     if the added path is <= MAX_ADD_M and climbs toward the peak (DEM).
     Unconnected way ends closer than SNAP_M are joined (OSM noding gaps).
  4. If that fails: look for an OSM hiking relation or named trail ways
     ("Jalur/Pendakian/Trek/Trail/Via ...") starting within REPL_START_M of
     the same trailhead that reach the summit, and use that as replacement.
  5. Else CANNOT_COMPLETE with the reason. Geometry is never invented.

Output: OUT/replace/<route_id>-<slug>.gpx (resampled 30 m, DEM elevation,
ODbL attribution) and OUT/completion_results.json.

Usage
  python python/tools/complete_to_summit.py [--ids 24,25] [--out DIR] [--dry-run]
"""
import argparse
import json
import os
import sys

import networkx as nx

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import route_audit_lib as L  # noqa: E402

HIGHWAYS = "path|footway|track|steps|bridleway"
ATTACH_WINDOW_M = 2500
ATTACH_DIST_M = 60
TARGET_DIST_M = 150
MAX_ADD_M = 3000
SNAP_M = 30
REPL_START_M = 1500
SPACING_M = 30
CRATER_DIST_M = 800


# --------------------------------------------------------------------------- OSM network
def fetch_network(s, w, n, e, with_names=False):
    bb = L.bbox_str(s, w, n, e)
    q = (f'[out:json][timeout:120];('
         f'way["highway"~"^({HIGHWAYS})$"]({bb});'
         f'rel["route"="hiking"]({bb});'
         f');out geom;')
    data = L.overpass(q)
    ways, rels = {}, {}
    for el in data["elements"]:
        if el["type"] == "way" and el.get("geometry"):
            ways[el["id"]] = el
        elif el["type"] == "relation":
            rels[el["id"]] = el
    # ways of hiking relations (any highway value, or none) inside the bbox
    for rel in rels.values():
        for m in rel.get("members", []):
            if m.get("type") == "way" and m.get("geometry") and m["ref"] not in ways:
                ways[m["ref"]] = {"id": m["ref"], "type": "way", "geometry": m["geometry"],
                                  "nodes": None, "tags": {"_from_relation": rel["id"]}}
    return ways, rels


def build_graph(ways):
    """Graph over OSM nodes (real node ids when available, else rounded coords)."""
    G = nx.Graph()
    for wid, w in ways.items():
        geom = [(g["lat"], g["lon"]) for g in w["geometry"] if g]
        ids = w.get("nodes") or [f"{p[0]:.7f},{p[1]:.7f}" for p in geom]
        if len(ids) != len(geom):
            ids = [f"{p[0]:.7f},{p[1]:.7f}" for p in geom]
        for nid, p in zip(ids, geom):
            G.add_node(nid, pos=p)
        for a, b, pa, pb in zip(ids, ids[1:], geom, geom[1:]):
            G.add_edge(a, b, weight=L.haversine(pa, pb), way=wid)
    # join OSM noding gaps: way end-points within SNAP_M of another way's node
    nodes = list(G.nodes(data="pos"))
    ends = {n for n in G.nodes if G.degree(n) == 1}
    grid = {}
    for nid, p in nodes:
        grid.setdefault((round(p[0] / 0.0005), round(p[1] / 0.0005)), []).append((nid, p))
    for nid in ends:
        p = G.nodes[nid]["pos"]
        gx, gy = round(p[0] / 0.0005), round(p[1] / 0.0005)
        best = None
        for dx in (-1, 0, 1):
            for dy in (-1, 0, 1):
                for oid, op in grid.get((gx + dx, gy + dy), []):
                    if oid == nid or G.has_edge(nid, oid):
                        continue
                    if G.edges[next(iter(G.edges(nid)))]["way"] == next(
                            (G.edges[e]["way"] for e in G.edges(oid)), None):
                        continue
                    d = L.haversine(p, op)
                    if d <= SNAP_M and (best is None or d < best[0]):
                        best = (d, oid)
        if best:
            G.add_edge(nid, best[1], weight=best[0], way="snap")
    return G


def _grid(G):
    if "grid" not in G.graph:
        g = {}
        for n, q in G.nodes(data="pos"):
            g.setdefault((int(q[0] // 0.001), int(q[1] // 0.001)), []).append((n, q))
        G.graph["grid"] = g
    return G.graph["grid"]


def nodes_near(G, p, radius):
    g = _grid(G)
    r = int(radius // 100) + 1
    cx, cy = int(p[0] // 0.001), int(p[1] // 0.001)
    out = []
    for dx in range(-r, r + 1):
        for dy in range(-r, r + 1):
            for n, q in g.get((cx + dx, cy + dy), []):
                d = L.haversine(p, q)
                if d <= radius:
                    out.append((n, d))
    return out


def path_coords(G, path):
    return [G.nodes[n]["pos"] for n in path]


def path_ways(G, path):
    ws = []
    for a, b in zip(path, path[1:]):
        w = G.edges[a, b]["way"]
        if w not in ("snap", "target") and w not in ws:
            ws.append(w)
    return ws


def first_pass(base, pos, i, d):
    """Earliest base index that passes within ATTACH_DIST_M of the attach node: drops spurs/loops the
    original made after first reaching that junction (e.g. a side trip to another summit)."""
    for j in range(i + 1):
        dj = L.haversine(base[j], pos)
        if dj <= ATTACH_DIST_M:
            return j, dj
    return i, d


def max_jump(points):
    return max((L.haversine(a, b) for a, b in zip(points, points[1:])), default=0)


def crater_targets(G, peak, peak_ele, crater_dem):
    """Summit-crater peaks (volcano node over the crater): OSM way nodes on the rim within
    CRATER_DIST_M of the node whose DEM is >= max(node DEM - 30, peak ele - 120)."""
    near = sorted(nodes_near(G, peak, CRATER_DIST_M), key=lambda t: t[1])[:300]
    if not near:
        return []
    dem = L.elevations([G.nodes[n]["pos"] for n, _ in near])
    floor = max(crater_dem - 30, peak_ele - 120)
    return [n for (n, _), e in zip(near, dem) if e >= floor]


def connect_to_peak(base, peak, peak_ele, G=None, net_bbox=None, max_add=MAX_ADD_M, crater_dem=None):
    """Try to extend `base` [(lat, lon)] to the peak along OSM ways.
    crater_dem: DEM at a volcano node that sits over the summit crater -> any rim node qualifies.
    Returns dict(ok, reason, points, ways, km_added, cut_idx)."""
    if G is None:
        s, w, n, e = L.buffered_bbox(base[-min(len(base), 200):] + [peak], 1200)
        ways, _ = fetch_network(s, w, n, e)
        if not ways:
            return {"ok": False, "reason": "no OSM paths in the area between the track end and the peak"}
        G = build_graph(ways)
    tgt = nodes_near(G, peak, TARGET_DIST_M)
    rim = crater_targets(G, peak, peak_ele, crater_dem) if crater_dem is not None else []
    if not tgt and not rim:
        nn = min(((L.haversine(peak, q)) for _, q in G.nodes(data="pos")), default=None)
        return {"ok": False, "reason": f"no OSM way node within {TARGET_DIST_M} m of the peak "
                                       f"(nearest {round(nn) if nn else '-'} m)"
                                       + (" and no rim node at summit height" if crater_dem is not None else "")}
    if rim:
        G = G.copy()
        for n_ in rim + [t for t, _ in tgt]:
            G.add_edge("TARGET", n_, weight=0.0, way="target")
        tnode = "TARGET"
    else:
        tnode = min(tgt, key=lambda t: t[1])[0]
    # attach candidates along the last ATTACH_WINDOW_M of the base
    cd = L.cumdist(base)
    best = None
    lengths = nx.single_source_dijkstra_path_length(G, tnode, weight="weight")
    last_c = None
    for i in range(len(base) - 1, -1, -1):
        if cd[-1] - cd[i] > ATTACH_WINDOW_M:
            break
        if last_c is not None and last_c - cd[i] < 10 and i != 0:
            continue  # check every ~10 m of track
        last_c = cd[i]
        for nid, d in nodes_near(G, base[i], ATTACH_DIST_M):
            if nid in lengths:
                # total = what we keep of the track is fixed up to i; cost = added length (+ attach gap)
                cost = lengths[nid] + d
                if best is None or cost < best[0] - 1:
                    best = (cost, i, nid, d)
    if best is None:
        return {"ok": False, "reason": "track end is not connected to the OSM path network leading to the peak"}
    cost, i, nid, d = best
    i, d = first_pass(base, G.nodes[nid]["pos"], i, d)
    path = nx.shortest_path(G, nid, tnode, weight="weight")
    if path[-1] == "TARGET":
        path = path[:-1]
    added = path_coords(G, path)
    km_added = (L.track_length(added) + d) / 1000
    if km_added * 1000 > max_add:
        return {"ok": False, "reason": f"OSM connection is {km_added:.1f} km (> {max_add / 1000:.1f} km limit)"}
    snaps = sum(1 for a, b in zip(path, path[1:]) if G.edges[a, b]["way"] == "snap")
    # climbs toward the peak?
    e0, e1 = L.elevations([base[i], added[-1]])
    if e1 < e0 - 20:
        return {"ok": False, "reason": f"OSM connection descends ({e0:.0f} -> {e1:.0f} m)"}
    if e1 < peak_ele - 100 and L.haversine(added[-1], peak) > TARGET_DIST_M and not rim:
        return {"ok": False, "reason": "OSM connection does not reach the summit elevation"}
    points = list(base[:i + 1]) + added
    # final hop from the target way node to the peak itself only if it is tiny (summit marker off the path)
    return {"ok": True, "points": points, "ways": path_ways(G, path), "km_added": round(km_added, 2),
            "cut_idx": i, "km_cut": round((cd[-1] - cd[i]) / 1000, 2), "attach_gap_m": round(d),
            "snaps": snaps, "reason": f"connected along {len(path_ways(G, path))} OSM way(s)"}


# --------------------------------------------------------------------------- replacement
def chain_ordered(ways_geom, start_hint, max_gap=150):
    """Chain way geometries greedily starting from the piece nearest start_hint."""
    pieces = [list(g) for g in ways_geom if len(g) >= 2]
    if not pieces:
        return []
    # pick start piece/orientation nearest the trailhead
    bi, bo = 0, False
    bd = float("inf")
    for i, pc in enumerate(pieces):
        for rev in (False, True):
            d = L.haversine((pc[::-1] if rev else pc)[0], start_hint)
            if d < bd:
                bd, bi, bo = d, i, rev
    chain = pieces.pop(bi)
    chain = chain[::-1] if bo else chain
    while pieces:
        best = None
        for i, pc in enumerate(pieces):
            for rev in (False, True):
                cand = pc[::-1] if rev else pc
                d = L.haversine(chain[-1], cand[0])
                if d <= max_gap and (best is None or d < best[0]):
                    best = (d, i, cand)
        if best is None:
            break
        chain += best[2][1:]
        pieces.pop(best[1])
    return chain


def find_replacement(trailhead, peak, peak_ele, mountain_tokens):
    s, w, n, e = L.buffered_bbox([trailhead, peak], 1500)
    bb = L.bbox_str(s, w, n, e)
    q = (f'[out:json][timeout:120];(rel["route"="hiking"]({bb});'
         f'way["highway"]["name"~"(jalur|pendakian|trek|trail|via|hiking)",i]({bb}););out geom;')
    data = L.overpass(q)
    groups = []
    for el in data["elements"]:
        tags = el.get("tags", {})
        if el["type"] == "relation":
            geoms = [[(g["lat"], g["lon"]) for g in m["geometry"]] for m in el.get("members", [])
                     if m.get("type") == "way" and m.get("geometry")]
            groups.append(("relation", [el["id"]], tags.get("name", ""), geoms, tags))
    by_name = {}
    for el in data["elements"]:
        if el["type"] == "way":
            nm = el.get("tags", {}).get("name", "")
            by_name.setdefault(nm, []).append(el)
    for nm, els in by_name.items():
        groups.append(("ways", [x["id"] for x in els], nm, [[(g["lat"], g["lon"]) for g in x["geometry"]]
                                                           for x in els], els[0].get("tags", {})))
    out = []
    for kind, ids, name, geoms, tags in groups:
        if tags.get("access") == "no" or any(k in name.lower() for k in ("nonaktif", "ditutup", "closed")):
            continue
        chain = chain_ordered(geoms, trailhead)
        if len(chain) < 2:
            continue
        if L.haversine(chain[0], trailhead) > L.haversine(chain[-1], trailhead):
            chain = chain[::-1]
        d0 = L.haversine(chain[0], trailhead)
        dpk, _ = L.min_distance_to_track(chain, peak)
        if d0 <= REPL_START_M:
            out.append({"kind": kind, "ids": ids, "name": name, "chain": chain, "start_gap_m": round(d0),
                        "peak_dist_m": round(dpk)})
    out.sort(key=lambda c: (c["peak_dist_m"], c["start_gap_m"]))
    return out


# --------------------------------------------------------------------------- driver
def finalize(points, peak, peak_ele):
    """Cut at the point nearest the peak, resample, DEM elevation."""
    ni, _ = L.nearest_index(points, peak)
    pts = L.dedupe(points[:ni + 1])
    pts = L.resample(pts, SPACING_M)
    eles = L.elevations(pts)
    return pts, eles


def osm_links(ways, rels=()):
    links = [(f"https://www.openstreetmap.org/relation/{r}", f"OSM relation {r}") for r in rels]
    links += [(f"https://www.openstreetmap.org/way/{w}", f"OSM way {w}") for w in ways if isinstance(w, int)]
    links.append(("https://www.openstreetmap.org/copyright", "(c) OpenStreetMap contributors, ODbL 1.0"))
    return links


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--routes", default=os.path.join(L.ROOT, "dataset", "routes.json"))
    ap.add_argument("--gpx-dir", default=os.path.join(L.ROOT, "dataset"))
    ap.add_argument("--out", default=L.DEFAULT_OUT)
    ap.add_argument("--ids", default="")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()
    routes = {str(r["route_id"]): r for r in json.load(open(args.routes, encoding="utf-8"))}
    audit = {r["route_id"]: r for r in json.load(open(os.path.join(args.out, "audit_raw.json"), encoding="utf-8"))}
    ids = [i.strip() for i in args.ids.split(",") if i.strip()] or \
        [rid for rid, a in audit.items() if a["cls"] == "INCOMPLETE"]
    os.makedirs(os.path.join(args.out, "replace"), exist_ok=True)
    res_path = os.path.join(args.out, "completion_results.json")
    results = L._load(res_path, {})
    for rid in ids:
        a, route = audit[rid], routes[rid]
        peak = (a["peak_lat"], a["peak_lon"])
        pk_ele = a["peak_ele"]
        raw = L.dedupe(L.read_gpx(os.path.join(args.gpx_dir, route["gpx_file"])))
        xy = [(q[0], q[1]) for q in raw]
        ni = a["nearest_idx"]
        if ni <= 2 and not a["round_trip"]:  # recorded summit-side first
            xy, ni = xy[::-1], len(xy) - 1 - ni
        base = xy[:ni + 1]
        mode = "round trip -> one way" if a["round_trip"] else ("truncated at nearest point"
                                                                if ni < len(xy) - 3 else "one way")
        crater_dem = a["peak_dem_ele"] if a.get("peak_crater") else None
        r = connect_to_peak(base, peak, pk_ele, crater_dem=crater_dem)
        entry = {"route_id": rid, "name": route["name"], "peak_name": a["peak_name"], "peak_ele": pk_ele,
                 "old_max_ele": a["track_max_ele"], "old_dist_to_peak_m": a["dist_to_peak_m"], "base_mode": mode}
        if r["ok"]:
            entry.update(action="replace", method="completion", ways=r["ways"], km_added=r["km_added"],
                         km_cut=r["km_cut"], attach_gap_m=r["attach_gap_m"], snaps=r["snaps"], reason=r["reason"])
            pts_all = r["points"]
            rels = []
        else:
            entry["completion_fail"] = r["reason"]
            reps = find_replacement(xy[0], peak, pk_ele, [])
            good = [c for c in reps if c["peak_dist_m"] <= TARGET_DIST_M + 100]
            if not good:
                # replacement reaching near the summit + completion on OSM paths (<= 1.5 km)
                for c in reps:
                    ni2, _ = L.nearest_index(c["chain"], peak)
                    rr = connect_to_peak(c["chain"][:ni2 + 1], peak, pk_ele, max_add=1500)
                    if rr["ok"]:
                        c.update(chain=rr["points"], extra_ways=rr["ways"], km_added_tail=rr["km_added"])
                        good = [c]
                        break
            if not good:
                entry.update(action="cannot_complete", cls_after="CANNOT_COMPLETE",
                             reason=r["reason"] + ("; no OSM hiking relation / named trail from the same "
                                                   "trailhead reaches the summit"),
                             replacement_candidates=[{k: v for k, v in c.items() if k != "chain"} for c in reps[:5]])
                results[rid] = entry
                print(f"{rid:>4} CANNOT_COMPLETE  {entry['reason']}")
                continue
            c = good[0]
            entry.update(action="replace", method="substitute", osm_kind=c["kind"], osm_ids=c["ids"],
                         osm_name=c["name"], start_gap_m=c["start_gap_m"], extra_ways=c.get("extra_ways", []),
                         reason=f"original track cannot be connected ({r['reason']}); substituted by OSM "
                                f"{c['kind']} '{c['name']}' from the same trailhead")
            pts_all = c["chain"]
            rels = c["ids"] if c["kind"] == "relation" else []
            entry["ways"] = (c["ids"] if c["kind"] == "ways" else []) + c.get("extra_ways", [])
        pts, eles = finalize(pts_all, peak, pk_ele)
        entry.update(new_max_ele=round(max(eles)), new_km=round(L.track_length(pts) / 1000, 2),
                     new_dist_to_peak_m=round(L.min_distance_to_track(pts, peak)[0]),
                     max_jump_m=round(max_jump(pts_all)))
        fname = f"{int(rid):03d}-{route['slug']}.gpx"
        entry["file"] = f"replace/{fname}"
        if entry["method"] == "completion":
            desc = (f"{route['name']}: original RuteStrip track ({route.get('source')}) from the trailhead, "
                    f"completed to {a['peak_name']} along OpenStreetMap ways {', '.join(map(str, entry['ways']))}. "
                    f"Path data (c) OpenStreetMap contributors, ODbL 1.0. Elevation: DEM "
                    f"({'/'.join(L.elevation_sources(pts))}).")
        else:
            desc = (f"{route['name']}: OpenStreetMap {c['kind']} {', '.join(map(str, c['ids']))} "
                    f"\"{c['name']}\"" + (f" + OSM ways {', '.join(map(str, c['extra_ways']))}" if c.get('extra_ways') else "")
                    + f", (c) OpenStreetMap contributors, ODbL 1.0. Elevation: DEM "
                      f"({'/'.join(L.elevation_sources(pts))}).")
        entry["source_urls"] = [h for h, _ in osm_links(entry["ways"], rels)][:-1]
        if not args.dry_run:
            L.write_gpx(os.path.join(args.out, "replace", fname), route["name"], desc,
                        osm_links(entry["ways"], rels)[:20], pts, eles)
        results[rid] = entry
        print(f"{rid:>4} {entry['method']:<11} +{entry.get('km_added', '-')} km, max {entry['new_max_ele']} m "
              f"(peak {pk_ele}), dist {entry['new_dist_to_peak_m']} m  {route['name']}")
    with open(res_path, "w", encoding="utf-8") as f:
        json.dump(results, f, indent=1, ensure_ascii=False)
    print("API usage:", json.dumps(L.USAGE.get("total", {})))


if __name__ == "__main__":
    main()
