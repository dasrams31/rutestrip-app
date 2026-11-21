"""
Preview PNGs for the replacement GPX files in OUT/plan.json (standard tier),
so they can be checked visually like the EXTENDED_REVIEW ones.
Draws the original track, the new track (kept part dark blue, last km_added
in red), the peak and a 1 km scale bar. No API calls.

Usage
  python python/tools/render_previews.py [--out DIR]
Writes OUT/review/<id>-completion.png
"""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import route_audit_lib as L  # noqa: E402
from extend_to_summit import render_png  # noqa: E402


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--routes", default=os.path.join(L.ROOT, "dataset", "routes.json"))
    ap.add_argument("--gpx-dir", default=os.path.join(L.ROOT, "dataset"))
    ap.add_argument("--out", default=L.DEFAULT_OUT)
    args = ap.parse_args()
    routes = {r["route_id"]: r for r in json.load(open(args.routes, encoding="utf-8"))}
    plan = json.load(open(os.path.join(args.out, "plan.json"), encoding="utf-8"))
    os.makedirs(os.path.join(args.out, "review"), exist_ok=True)
    for p in plan:
        if p["action"] != "replace" or p.get("status") != "COMPLETE":
            continue
        r = routes[p["route_id"]] if p["route_id"] in routes else routes[str(p["route_id"])]
        original = [(q[0], q[1]) for q in L.read_gpx(os.path.join(args.gpx_dir, r["gpx_file"]))]
        new = [(q[0], q[1]) for q in L.read_gpx(os.path.join(args.out, p["file"]))]
        cd = L.cumdist(new)
        cut = next((i for i, c in enumerate(cd) if c >= cd[-1] - p["km_added"] * 1000), len(new) - 1)
        notes = (f"#{p['route_id']} {r['name']} -> {p['peak_name']} ({p['peak_ele']} m)\n"
                 f"added {p['km_added']} km on OSM ways (red); new length {p['new_distance_km']} km\n"
                 f"new max {p['new_max_ele']} m (old {p['old_max_ele']} m)")
        render_png(os.path.join(args.out, "review", f"{int(p['route_id']):03d}-completion.png"),
                   f"COMPLETE (auto, <= 3 km) #{p['route_id']} {r['name']}", original, new[:cut + 1],
                   new[cut:], (p["peak_lat"], p["peak_lon"]), {}, None, notes)
        print(f"ok {p['route_id']}")


if __name__ == "__main__":
    main()
