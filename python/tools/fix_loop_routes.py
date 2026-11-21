"""
Fix routes whose GPX mixes two trails (up one trail, down another), holds
several tracks in one file, or has a big jump. For each route keep ONE one-way
track trailhead -> summit made only of ORIGINAL recorded points (pieces may be
reversed, sliced, or joined where their ends are <= 100 m apart; nothing is
invented).

Method per route
  1. split the recording into pieces: by <trk>/<trkseg>, at jumps > 300 m and
     at the summit (point nearest the OSM peak from audit_raw.json);
  2. pick the piece that is the named trail: share of points within 60 m of
     OSM ways/relations whose name holds the basecamp word ("via X"); else the
     piece whose low end is nearest the OSM place/camp node of that name; for
     routes without "via" keep the piece that starts at the recording's start;
  3. orient it trailhead -> summit; check start/end, jumps, stats (processor.py).
Routes that cannot be decided from evidence are written as action "rename".

Overpass use is light: one small-bbox query per mountain + one exact-name node
query, all cached in OUT/cache (route_audit_lib.overpass).

Usage
  python python/tools/fix_loop_routes.py [--ids 42,51] [--out DIR]
Writes OUT/fixes_loops/<id>-<slug>.gpx + .png, plan-loops.json, README.md
Data (c) OpenStreetMap contributors, ODbL 1.0.
"""
import argparse
import json
import os
import re
import sys
import xml.etree.ElementTree as ET

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
import route_audit_lib as L  # noqa: E402

JUMP_M = 300
JOIN_M = 100
NEAR_TRAIL_M = 60
SUMMIT_OK_M = 250

GEDE_PEAK = (-6.7898841, 106.9831941, "Puncak Gunung Gede", 2958)   # audit peak of route 101

# mountain group -> route ids sharing one small-bbox Overpass query
GROUPS = {"arjuno": [25, 42], "gede": [51, 101], "sindoro": [72], "ungaran": [76], "wilis": [79],
          "panderman": [110], "rakutak": [119], "guntur": [30, 31]}
# basecamp word(s) from the route name (regex, case-insensitive)
WORDS = {42: r"purwosari", 25: r"tambak ?watu|tambaksari|purwosari", 51: r"cibodas", 101: r"sukatani",
         72: r"sigedang", 76: r"mawar", 30: r"tanjung ?karya", 31: r"warung ?tanjung"}
PLACE_NAMES = ["Purwosari", "Tambakwatu", "Tambak Watu", "Tambaksari", "Cibodas", "Sukatani", "Sigedang",
               "Mawar", "Basecamp Mawar", "Tanjungkarya", "Tanjung Karya", "Warung Tanjung", "Warungtanjung"]


# --------------------------------------------------------------------------- data
def read_segments(path):
    """[[(lat, lon, ele)], ...] one list per <trkseg> (all <trk>s, file order)."""
    out = []
    for el in ET.parse(path).iter():
        if el.tag.rsplit("}", 1)[-1] != "trkseg":
            continue
        pts = []
        for p in el:
            if p.tag.rsplit("}", 1)[-1] != "trkpt":
                continue
            ele = next((float(c.text) for c in p if c.tag.rsplit("}", 1)[-1] == "ele" and c.text), None)
            pts.append((float(p.get("lat")), float(p.get("lon")), ele))
        if pts:
            out.append(pts)
    return out


def area_query(points):
    bb = L.bbox_str(*L.buffered_bbox(points, 1000))
    return (f'[out:json][timeout:90];(rel["route"="hiking"]({bb});'
            f'way["highway"~"^(path|footway|steps|track)$"]["name"]({bb});'
            f'way["highway"]["name"~"jalur|pendakian|trek|trail|hiking|via",i]({bb});'
            f'node["place"~"^(village|hamlet|neighbourhood|isolated_dwelling|locality)$"]({bb});'
            f'node["tourism"~"^(camp_site|alpine_hut|wilderness_hut)$"]({bb}););out geom;')


def place_query():
    return "[out:json][timeout:90];(" + "".join(f'node["name"="{n}"];' for n in PLACE_NAMES) + ");out;"


def named_geoms(data, rx):
    """[(label, [(lat, lon)])] for ways named ~rx and members of relations named ~rx."""
    out, seen = [], set()
    for e in data["elements"]:
        nm = e.get("tags", {}).get("name", "")
        if not re.search(rx, nm, re.I):
            continue
        if e["type"] == "way" and e.get("geometry") and ("w", e["id"]) not in seen:
            seen.add(("w", e["id"]))
            out.append((f"way {e['id']} '{nm}'", [(g["lat"], g["lon"]) for g in e["geometry"] if g]))
        if e["type"] == "relation":
            for m in e.get("members", []):
                if m.get("geometry") and ("r", e["id"], m["ref"]) not in seen:
                    seen.add(("r", e["id"], m["ref"]))
                    out.append((f"relation {e['id']} '{nm}'", [(g["lat"], g["lon"]) for g in m["geometry"] if g]))
    return out


def share_near(piece, geoms, tol=NEAR_TRAIL_M):
    if not geoms or not piece:
        return 0.0
    return sum(any(L.min_distance_to_track(g, p)[0] <= tol for _, g in geoms) for p in piece) / len(piece)


def route_points(rid):
    import glob
    f = glob.glob(os.path.join(L.ROOT, "dataset", "gpx", f"{rid:03d}-*.gpx"))[0]
    return [q[:2] for q in L.read_gpx(f)]


def trails_on(piece, osm, exclude=None, min_share=0.15, kinds=("way", "relation")):
    """'name (share)' of named OSM ways / hiking relations the piece runs on."""
    names = {}
    for lbl, g in named_geoms(osm, r"."):
        if lbl.split()[0] in kinds and not (exclude and re.search(exclude, lbl, re.I)):
            sh = share_near(piece, [(lbl, g)])
            if sh >= min_share:
                nm = lbl.split()[0] + " '" + lbl.split("'")[1] + "'"
                names[nm] = max(names.get(nm, 0), sh)
    if kinds == ("way", "relation"):
        # whole-relation share = share of the piece near any member
        for lbl in {x[0] for x in named_geoms(osm, r".") if x[0].startswith("relation")}:
            if exclude and re.search(exclude, lbl, re.I):
                continue
            sh = share_near(piece, [x for x in named_geoms(osm, r".") if x[0] == lbl])
            nm = "relation '" + lbl.split("'")[1] + "'"
            if sh >= min_share:
                names[nm] = sh
            else:
                names.pop(nm, None)
    return ", ".join(f"{n} ({v:.0%})" for n, v in sorted(names.items(), key=lambda x: -x[1]))


def max_jump(points):
    return max((L.haversine(a, b) for a, b in zip(points, points[1:])), default=0.0)


def nearest(points, target, lo=0, hi=None):
    hi = len(points) if hi is None else hi
    i = min(range(lo, hi), key=lambda k: L.haversine(points[k], target))
    return i, L.haversine(points[i], target)


def argmin_ele(points, lo=0, hi=None):
    hi = len(points) if hi is None else hi
    return min(range(lo, hi), key=lambda k: points[k][2] if points[k][2] is not None else 1e9)


def closest_join(a, b, max_gap=30):
    """Join piece a then piece b at their closest approach, skipping as much of
    the wandering at the end of a / start of b as possible. Returns (ia, ib, gap)."""
    for ib in range(len(b) - 1, -1, -1):
        ia, d = nearest(a, b[ib])
        if d <= max_gap:
            return ia, ib, d
    ia, ib = len(a) - 1, 0
    return ia, ib, L.haversine(a[-1], b[0])


# --------------------------------------------------------------------------- stats
def stats(path):
    import processor  # python/processor.py (gpxpy, vertical smoothing, 3D length)
    gpx, pts = processor.parse_gpx(path)
    return processor.calculate_statistics(gpx, pts)


# --------------------------------------------------------------------------- preview
def render(path, title, original_segs, kept, trail_geoms, other_geoms, peak, places, notes, extra_peaks=()):
    import math
    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt
    k = math.cos(math.radians(peak[0]))
    X = lambda pts: [p[1] * k for p in pts]  # noqa: E731
    Y = lambda pts: [p[0] for p in pts]  # noqa: E731
    fig, ax = plt.subplots(figsize=(9, 10.5), dpi=110)
    fig.subplots_adjust(left=0.02, right=0.98, top=0.95, bottom=0.17)
    for i, (_, g) in enumerate(other_geoms):
        ax.plot(X(g), Y(g), color="#c8c8c8", lw=0.8, zorder=1, label="other named OSM trails" if i == 0 else None)
    for i, (_, g) in enumerate(trail_geoms):
        ax.plot(X(g), Y(g), color="#2ca02c", lw=4.5, alpha=0.35, zorder=2,
                label="OSM ways/relations named after the basecamp" if i == 0 else None)
    for i, s in enumerate(original_segs):
        ax.plot(X(s), Y(s), color="#f4a582", lw=1.3, zorder=3, label="original recording (all parts)" if i == 0 else None)
    ax.plot(X(kept), Y(kept), color="#1f4e8c", lw=2.4, zorder=4, label="kept one-way track")
    ax.scatter([kept[0][1] * k], [kept[0][0]], marker="o", s=70, color="#1f4e8c", zorder=6, label="trailhead (kept start)")
    ax.scatter([peak[1] * k], [peak[0]], marker="^", s=170, color="black", zorder=6, label=f"peak: {peak[2]}")
    for la, lo, nm, _ in extra_peaks:
        ax.scatter([lo * k], [la], marker="^", s=120, color="#555", zorder=6)
        ax.annotate(nm, (lo * k, la), fontsize=8, xytext=(6, -10), textcoords="offset points")
    for nm, (la, lo) in places:
        ax.scatter([lo * k], [la], marker="s", s=40, color="#9467bd", zorder=6)
        ax.annotate(nm, (lo * k, la), fontsize=7, color="#5b3a8c", xytext=(4, 4), textcoords="offset points")
    allp = [q for s in original_segs for q in s] + list(kept) + [peak[:2]]
    xs, ys = X(allp), Y(allp)
    pad = 0.003
    ax.set_xlim(min(xs) - pad, max(xs) + pad)
    ax.set_ylim(min(ys) - pad, max(ys) + pad)
    ax.set_aspect("equal")
    x0, y0 = ax.get_xlim()[0] + 0.002, ax.get_ylim()[0] + 0.002
    ax.plot([x0, x0 + 1000 / 111320], [y0, y0], color="black", lw=3)
    ax.text(x0, y0 + 0.0008, "1 km", fontsize=9)
    ax.set_title(title, fontsize=10)
    fig.text(0.02, 0.15, notes, va="top", fontsize=8, family="monospace")
    ax.legend(loc="best", fontsize=7, framealpha=0.85)
    ax.set_xticks([])
    ax.set_yticks([])
    ax.text(0.99, 0.005, "OSM data (c) OpenStreetMap contributors, ODbL 1.0", transform=ax.transAxes,
            ha="right", fontsize=7, color="#555")
    fig.savefig(path)
    plt.close(fig)


# --------------------------------------------------------------------------- per-route decisions
def build(rid, segs, peak, osm, places):
    """Return dict(status, kept, what, evidence, notes). `kept` runs trailhead -> summit."""
    pts = L.dedupe([q for s in segs for q in s])
    pk = peak[:2]
    word = WORDS.get(rid)
    trail = named_geoms(osm, word) if word else []
    ev, res = [], {}

    def place_ev(end_pt):
        if not word:
            return None
        cand = [(L.haversine(end_pt, ll), nm) for nm, ll in places if re.search(word, nm, re.I)]
        if not cand:
            return None
        d, nm = min(cand)
        return f"OSM node '{nm}' is {d / 1000:.2f} km from the kept trailhead"

    if rid == 25:
        gaps = [L.haversine(a[-1], b[0]) for a, b in zip(segs, segs[1:])]
        assert max(gaps) <= JOIN_M, gaps
        kept = [q for k, sg in enumerate(segs) for q in (sg if k == 0 or sg[0] != segs[k - 1][-1] else sg[1:])]
        res["what"] = (f"all {len(segs)} track parts joined in file order (gaps between parts "
                       f"{', '.join(f'{g:.0f}' for g in gaps)} m); already one-way trailhead -> summit")
        ev.append(f"{share_near(kept, trail):.0%} of points within {NEAR_TRAIL_M} m of OSM Purwosari trail "
                  f"(relation 'Gunung Arjuno (Purwosari BC)'); Tambak Watu is the Purwosari basecamp, the first "
                  f"~2.6 km below the OSM way are the basecamp approach")
    elif rid == 42:
        lo = argmin_ele(pts)
        s, sd = nearest(pts, pk, 0, lo)
        kept = pts[s:lo + 1][::-1]
        back = pts[lo:]
        med = sorted(L.min_distance_to_track([q[:2] for q in pts[s:lo + 1]], q)[0] for q in back)[len(back) // 2]
        res["what"] = (f"recording = junction(3085 m) -> summit -> junction -> down to {pts[lo][2]:.0f} m -> same path "
                       f"back up (median {med:.0f} m from the way down); kept the way down, reversed: "
                       f"{pts[lo][2]:.0f} m -> summit")
        ev.append(f"{share_near(kept, trail):.0%} of kept points within {NEAR_TRAIL_M} m of OSM ways "
                  f"'Jalur Pendakian Gunung Arjuno via Purwosari' (relation 'Gunung Arjuno (Purwosari BC)')")
        low_end = min((L.haversine(kept[0], g[0]), L.haversine(kept[0], g[-1])) for _, g in trail if len(g) > 50)
        ev.append(f"kept trailhead is {min(low_end):.0f} m from the lower end of OSM way 815499577 (via Purwosari)")
        ev.append(f"{share_near(kept, [('25', route_points(25))], 30):.0%} of kept points within 30 m of route #25 "
                  f"(Arjuno via Tambak Watu = the same Purwosari trail, which starts ~2.6 km lower at the basecamp)")
    elif rid == 51:
        lo = argmin_ele(pts)
        pang, _ = nearest(pts, pk, lo)
        gede, gd = nearest(pts, GEDE_PEAK[:2], pang)
        kept = pts[lo:gede + 1]
        res["what"] = (f"recording = Kandang Badak -> down to Cibodas ({pts[lo][2]:.0f} m) -> same path back up "
                       f"-> Pangrango -> back to Kandang Badak -> Gede -> part way down; kept the contiguous part "
                       f"Cibodas -> Pangrango -> Kandang Badak -> Gede summit ({gd:.0f} m from the Gede node), "
                       f"as recorded, without the return")
        res["end_peak"] = GEDE_PEAK
        for e in osm["elements"]:
            nm = e.get("tags", {}).get("name", "")
            if e["type"] == "node" and re.search(r"perkemahan|cibodas", nm, re.I):
                ev.append(f"kept trailhead (lowest point, {pts[lo][2]:.0f} m) is "
                          f"{L.haversine(kept[0], (e['lat'], e['lon'])):.0f} m from OSM {e['tags'].get('tourism', 'node')} "
                          f"'{nm}' at the Cibodas park gate")
            if e["type"] == "node" and nm == "Kandang Badak":
                ev.append(f"passes OSM locality 'Kandang Badak' at {nearest(kept, (e['lat'], e['lon']))[1]:.0f} m")
        ev.append(f"name 'Gede Pangrango': both summits kept in recorded order; the Kandang Badak <-> Pangrango "
                  f"spur is walked twice (it is a dead end)")
    elif rid == 101:
        s, _ = nearest(pts, pk)
        kept = pts[:s + 1]
        tail = pts[s:]
        res["what"] = f"kept start -> Gede summit; dropped {L.track_length(tail) / 1000:.2f} km after the summit"
        on51 = share_near(tail, [("51", route_points(51))], 30)
        ev.append(f"dropped part runs on the Cibodas trail: {on51:.0%} of it within 30 m of route #51 (Gede Pangrango "
                  f"via Cibodas), heading to Kandang Badak")
        for e in osm["elements"]:
            if e["type"] == "node" and e.get("tags", {}).get("name") == "Gunung Putri":
                ev.append(f"kept trailhead is {L.haversine(kept[0], (e['lat'], e['lon'])):.0f} m from OSM village "
                          f"'Gunung Putri' (the Gunung Putri basecamp lies in Desa Sukatani, Pacet)")
    elif rid == 110:
        kept = pts
        res["status"] = "KEEP"
        res["what"] = "no change: already one-way trailhead -> summit (start-end gap is just the trail length)"
        geoms = [g for g in named_geoms(osm, r"pendakian gunung panderman$") if g[0].startswith("way")]
        if geoms:
            lbl, g = geoms[0]
            ev.append(f"{share_near(kept, geoms):.0%} of points within {NEAR_TRAIL_M} m of OSM {lbl}; its ends are "
                      f"{L.haversine(kept[0], g[0]):.0f} m / {L.haversine(kept[-1], g[-1]):.0f} m from the track ends")
    elif rid in (79, 119):
        s, _ = nearest(pts, pk)
        kept = pts[:s + 1]
        rest = pts[s:]
        lo = argmin_ele(rest)
        back = sorted(L.min_distance_to_track([q[:2] for q in rest[:lo + 1]], q)[0] for q in rest[lo:])[len(rest[lo:]) // 2]
        retrace = (f", then back up the same way (median {back:.0f} m off) and down the way up" if back < 30 and len(rest) - lo > 20 else "")
        res["what"] = (f"no 'via' in name: kept the uploader's start -> summit ({L.track_length(kept) / 1000:.2f} km); "
                       f"dropped the rest ({L.track_length(rest) / 1000:.2f} km: down another trail to "
                       f"{rest[lo][2]:.0f} m{retrace})")
        for label, piece in (("kept", kept), ("dropped", pts[s:])):
            on = trails_on(piece, osm)
            ev.append(f"{label} part on OSM {on}" if on else f"{label} part: no named OSM trail within {NEAR_TRAIL_M} m")
    elif rid in (72, 76):
        s, _ = nearest(pts, pk)
        a, b = pts[:s + 1], pts[s:][::-1]
        kept = None
        # ways first (a relation may itself be the same loop), then relations
        for kind in ("way", "relation"):
            geoms = [t for t in trail if t[0].startswith(kind)]
            sa, sb = share_near(a, geoms), share_near(b, geoms)
            res.setdefault("shares", {})[kind] = (round(sa, 2), round(sb, 2))
            if max(sa, sb) >= 0.3 and abs(sa - sb) >= 0.25:
                kept, other = (a, b) if sa > sb else (b, a)
                res["what"] = (f"recording goes up one trail and down another; kept the "
                               f"{'first half (start -> summit)' if sa > sb else 'second half (summit -> end), reversed'}")
                ev.append(f"OSM {kind}s named ~'{word}': {max(sa, sb):.0%} of the kept half vs {min(sa, sb):.0%} of "
                          f"the dropped half within {NEAR_TRAIL_M} m ({', '.join(sorted({g[0] for g in geoms}))})")
                break
            if geoms:
                ev.append(f"OSM {kind}s named ~'{word}' cover {sa:.0%} / {sb:.0%} of the two halves (not decisive)")
        if kept is not None:
            # name the trails the dropped half runs on
            on = trails_on(other, osm, exclude=word, kinds=("way",))
            if on:
                ev.append("dropped half lies on OSM " + on)
            rel = [t for t in trail if t[0].startswith("relation")]
            if rel and share_near(other, rel) > 0.8:
                ev.append(f"note: OSM {rel[0][0]} covers both halves (it maps the same loop), so it is not used")
        else:
            # rule (b): place / camp node named after the basecamp nearest a low end
            cand = [(min(L.haversine(a[0], ll), L.haversine(b[0], ll)), nm, ll) for nm, ll in places
                    if re.search(word, nm, re.I)]
            if cand:
                d, nm, ll = min(cand)
                da, db = L.haversine(a[0], ll), L.haversine(b[0], ll)
                if min(da, db) <= 1500 and abs(da - db) >= 500:
                    kept = a if da < db else b
                    res["what"] = (f"recording goes up one trail and down another; kept the "
                                   f"{'first half (start -> summit)' if da < db else 'second half (summit -> end), reversed'}")
                    ev.append(f"OSM node '{nm}' is {min(da, db):.0f} m from the kept low end vs {max(da, db):.0f} m "
                              f"from the other")
            if kept is None:
                res["status"] = "AMBIGUOUS"
                res["what"] = "neither OSM named ways nor a named place/camp node decide which half is the named trail"
    elif rid == 30:
        s0, s3 = segs[0], segs[3]
        ia, ib, gap = closest_join(s0, s3)
        kept = L.dedupe(s0[:ia + 1] + s3[ib:])
        dropped = L.track_length(s0[ia:]) + sum(L.track_length(x) for x in segs[1:3] + segs[4:]) + L.track_length(s3[:ib + 1])
        res["what"] = (f"kept track part 1 (trailhead -> camp area) joined to part 4 (camp -> Masigit summit) at their "
                       f"closest approach ({gap:.0f} m); dropped parts 2, 3, 5 and wandering at the camp "
                       f"({dropped / 1000:.2f} km)")
    elif rid == 31:
        j = next(i for i in range(1, len(pts)) if L.haversine(pts[i - 1], pts[i]) > JUMP_M)
        kept = pts[j:]
        res["what"] = (f"dropped the first {j} recorded positions ({L.track_length(pts[:j]) / 1000:.2f} km) before a "
                       f"{L.haversine(pts[j - 1], pts[j]):.0f} m straight jump; every point was stored twice "
                       f"(duplicates removed)")
    else:
        raise ValueError(rid)

    if kept is not None and rid in (25, 30, 31, 51, 101):
        on = trails_on(kept, osm)
        if on:
            ev.append(f"kept track on OSM {on}")
    if kept is not None and word and rid not in (72, 76):
        pe = place_ev(kept[0])
        if pe:
            ev.append(pe)
    res.setdefault("status", "ONE_WAY")
    res["kept"] = kept
    res["evidence"] = ev
    return res


# --------------------------------------------------------------------------- main
def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--routes", default=os.path.join(L.ROOT, "dataset", "routes.json"))
    ap.add_argument("--gpx-dir", default=os.path.join(L.ROOT, "dataset"))
    ap.add_argument("--out", default=L.DEFAULT_OUT)
    ap.add_argument("--ids", default="")
    args = ap.parse_args()
    out_dir = os.path.join(args.out, "fixes_loops")
    os.makedirs(out_dir, exist_ok=True)
    routes = {int(r["route_id"]): r for r in json.load(open(args.routes, encoding="utf-8"))}
    audit = {int(a["route_id"]): a for a in json.load(open(os.path.join(args.out, "audit_raw.json"), encoding="utf-8"))}
    ids = [int(i) for i in args.ids.split(",") if i.strip()] or [i for g in GROUPS.values() for i in g]

    segs_of = {rid: read_segments(os.path.join(args.gpx_dir, routes[rid]["gpx_file"])) for rid in ids}
    osm_of = {}
    for g, members in GROUPS.items():
        todo = [m for m in members if m in ids]
        if todo:
            pts = [q[:2] for m in members for s in read_segments(os.path.join(args.gpx_dir, routes[m]["gpx_file"]))
                   for q in s]
            data = L.overpass(area_query(pts))
            for m in todo:
                osm_of[m] = data
    pdata = L.overpass(place_query())
    places = [(e["tags"]["name"], (e["lat"], e["lon"])) for e in pdata["elements"]]

    plan_path = os.path.join(out_dir, "plan-loops.json")
    plan = {int(p["route_id"]): p for p in L._load(plan_path, [])}
    table = L._load(os.path.join(out_dir, "table.json"), {})
    for rid in ids:
        r, a = routes[rid], audit[rid]
        peak = (a["peak_lat"], a["peak_lon"], a["peak_name"], a["peak_ele"])
        segs = segs_of[rid]
        osm = osm_of[rid]
        near_places = [(nm, ll) for nm, ll in places
                       if min(L.haversine(ll, q) for s in segs for q in s[::5]) < 5000]
        # local place/camp nodes from the bbox query, labelled on the map when named after the basecamp
        word = WORDS.get(rid)
        for e in osm["elements"]:
            if e["type"] == "node" and word and re.search(word, e.get("tags", {}).get("name", ""), re.I):
                near_places.append((e["tags"]["name"], (e["lat"], e["lon"])))
        res = build(rid, segs, peak, osm, near_places)
        slug = r["slug"] if "slug" in r else L.slugify(r["name"])
        base = f"{rid:03d}-{slug}"
        old = stats(os.path.join(args.gpx_dir, r["gpx_file"]))
        trail = named_geoms(osm, word) if word else []
        other = [(lbl, g) for lbl, g in named_geoms(osm, r".") if (lbl, g) not in trail]
        if res["status"] == "AMBIGUOUS":
            entry = {"route_id": rid, "action": "rename", "new_name": f"{r['name']} (naik dan turun beda jalur)", "reason": res["what"],
                     "evidence": res["evidence"]}
            plan[rid] = entry
            table[str(rid)] = {"name": r["name"], "before_km": r["distance_km"], "after_km": None,
                               "kept": "AMBIGUOUS: " + res["what"], "evidence": "; ".join(res["evidence"])}
            print(f"AMBIGUOUS {rid} {r['name']}: {res['what']}")
            continue
        kept = res["kept"]
        end_peak = res.get("end_peak", peak)
        end_d = L.haversine(kept[-1], end_peak[:2])
        jump = max_jump(kept)
        assert jump <= JUMP_M, (rid, jump)
        assert end_d <= SUMMIT_OK_M, (rid, end_d)
        gpx_path = os.path.join(out_dir, base + ".gpx")
        desc = (f"{r['name']}: one-way trailhead -> {end_peak[2]} from the ORIGINAL recording "
                f"({r.get('source', '')}), no added geometry. {res['what']}. Evidence: "
                f"{'; '.join(res['evidence']) or 'route has no via-name; uploader start kept'}. "
                f"OSM data (c) OpenStreetMap contributors, ODbL 1.0.")
        if res["status"] == "KEEP":
            gpx_path = None
        else:
            eles = [q[2] if q[2] is not None else 0.0 for q in kept]
            L.write_gpx(gpx_path, r["name"], desc,
                        [(r.get("source_url") or r.get("url") or "", "original source")] if (r.get("source_url") or r.get("url")) else [],
                        [q[:2] for q in kept], eles, creator="RuteStrip fix_loop_routes")
        new = stats(gpx_path) if gpx_path else old
        notes = (f"#{rid} {r['name']}\n{res['what']}\n"
                 f"before {r['distance_km']} km (processor {old['distance_km']} km), after {new['distance_km']} km, "
                 f"gain {new['elevation_gain_m']} m, Naismith {new['naismith_duration_hour']} h\n"
                 f"start {kept[0][2]:.0f} m, end {end_d:.0f} m from {end_peak[2]}, max jump {jump:.0f} m")
        import textwrap
        notes = "\n".join(textwrap.fill(line, 120) for line in notes.split("\n"))
        render(os.path.join(out_dir, base + ".png"), f"{res['status']} #{rid} {r['name']}",
               [[q[:2] for q in s] for s in segs], [q[:2] for q in kept], trail, other,
               end_peak, [(nm, ll) for nm, ll in near_places if word and re.search(word, nm, re.I)], notes,
               extra_peaks=[peak] if end_peak is not peak else [])
        entry = {"route_id": rid, "action": "replace" if gpx_path else "keep", "status": res["status"],
                 "file": os.path.basename(gpx_path) if gpx_path else None, "reason": res["what"], "km_added": 0,
                 "new_max_ele": new["max_elevation"], "peak_name": end_peak[2], "peak_ele": end_peak[3],
                 "old_km": r["distance_km"], "new_km": new["distance_km"], "evidence": res["evidence"],
                 "png": base + ".png", "end_to_peak_m": round(end_d), "max_jump_m": round(jump),
                 "trailhead_lat": round(kept[0][0], 6), "trailhead_lon": round(kept[0][1], 6),
                 "trailhead_ele": kept[0][2], "new_elevation_gain_m": new["elevation_gain_m"],
                 "new_naismith_h": new["naismith_duration_hour"], "new_steepest_grade_pct": new["steepest_grade_pct"],
                 "new_difficulty": new["difficulty"], "old_elevation_gain_m": r["elevation_gain_m"]}
        if end_peak is not peak:
            entry["also_visits"] = f"{peak[2]} ({peak[3]} m)"
        plan[rid] = entry
        table[str(rid)] = {"name": r["name"], "before_km": r["distance_km"], "after_km": new["distance_km"],
                           "kept": res["what"], "evidence": "; ".join(res["evidence"]) or "no via-name: uploader start kept"}
        print(f"{res['status']:9s} {rid:3d} {r['name']}: {r['distance_km']} -> {new['distance_km']} km, "
              f"gain {new['elevation_gain_m']} m, end {end_d:.0f} m from {end_peak[2]}, jump {jump:.0f} m, "
              f"start ele {kept[0][2]}")
    order = [i for g in GROUPS.values() for i in g]
    plan_list = [plan[i] for i in sorted(plan, key=lambda i: order.index(i) if i in order else 999)]
    with open(plan_path, "w", encoding="utf-8") as f:
        json.dump(plan_list, f, indent=1, ensure_ascii=False)
    with open(os.path.join(out_dir, "table.json"), "w", encoding="utf-8") as f:
        json.dump(table, f, indent=1, ensure_ascii=False)
    write_readme(out_dir, plan_list, table)


def write_readme(out_dir, plan_list, table):
    esc = lambda s: str(s).replace("|", "/")  # noqa: E731
    lines = ["# Loop / multi-track / jump fixes", "",
             "Generated by `python python/tools/fix_loop_routes.py`. Each fixed GPX is ONE one-way track "
             "trailhead -> summit made only of original recorded points (sliced, reversed or joined where the "
             "pieces are <= 100 m apart); nothing was invented. Preview PNG per route: original recording in "
             "light orange, kept track dark blue, OSM ways named after the basecamp in green. Machine-readable "
             "plan: `plan-loops.json` (same fields as `../plan.json`).", "",
             "| route | before km | after km | what was kept | evidence |", "|---|---|---|---|---|"]
    for p in plan_list:
        t = table[str(p["route_id"])]
        after = t["after_km"] if p["action"] != "rename" else f"rename -> {p['new_name']}"
        if p["action"] == "keep":
            after = f"{t['after_km']} (unchanged)"
        lines.append(f"| #{p['route_id']} {esc(t['name'])} | {t['before_km']} | {after} | {esc(t['kept'])} | "
                     f"{esc(t['evidence'])} |")
    lines += ["", "before km = `distance_km` in dataset/routes.json; after km = python/processor.py "
              "(3D length, same method as the dataset).", "",
              "## Notes", "",
              "- #42 is the same Purwosari trail as #25 (100% of #42 within 30 m of #25); #25 starts ~2.6 km lower "
              "at the Tambak Watu basecamp, #42 starts where OSM's 'via Purwosari' way begins (1195 m). Consider "
              "merging or dropping #42 as a near-duplicate.",
              "- #51 visits both summits; the kept track is the recording's own order Cibodas -> Pangrango -> "
              "Kandang Badak -> Gede, so the Kandang Badak <-> Pangrango spur (~2 km) appears twice. For a pure "
              "one-way to Pangrango only, cut at Pangrango (8.6 km 2D).",
              "- #79 Wilis and #119 Rakutak: the OSM named way/relation covers both trails of the loop, so OSM does "
              "not tell which side is the main trail; rule (c) kept the uploader's start.",
              "- #72 and #76: the OSM hiking relations named after the basecamp map the whole loop (both halves), "
              "so the decision uses the individually named ways.",
              "- #31: the old 9.47 km was inflated by every point being stored twice (gpxpy smoothing on "
              "duplicates adds vertical zig-zag); the dropped start is a flat 0.6 km (751-761 m) incl. the 504 m "
              "straight line.",
              "- #25: points unchanged, only the 6 <trk> merged into one (km unchanged within rounding).",
              "- #110 Panderman needs no fix (identical to OSM 'Jalur pendakian Gunung Panderman'); plan action "
              "'keep', no GPX written. It starts at 1600 m, part-way up; only an extension could change that.",
              "",
              "OSM data (c) OpenStreetMap contributors, ODbL 1.0."]
    with open(os.path.join(out_dir, "README.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")


if __name__ == "__main__":
    main()
