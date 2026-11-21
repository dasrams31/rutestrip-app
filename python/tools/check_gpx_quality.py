"""
Quality gates for generated RuteStrip GPX files (completed, replacement, new).

Gates
  reaches_summit   passes within 250 m of the peak and max elevation within 80 m
                   of the peak elevation (OSM ele, or DEM at the peak); for volcano
                   nodes over the summit crater: rim within 800 m at summit height
  ascent_shape     trailhead -> summit: the highest point lies in the last 15 %
                   of the distance and the end is >= 70 % up the elevation range
                   (descents along the way are allowed)
  gain_plausible   elevation gain <= 2 x (max - min) + 150 m
  grade            average grade (gain / distance) <= 45 %
  no_jump          no segment longer than 300 m
  distance         0.8 - 30 km (0.3 km minimum for new trails on tiny hills, owner-approved)
Stats come from python/processor.py (parse_gpx + calculate_statistics), the
same code the app uses on ingest.

Usage
  python python/tools/check_gpx_quality.py --plan OUT/plan.json   # every file in the plan (writes quality.json)
  python python/tools/check_gpx_quality.py --gpx FILE --peak LAT,LON,ELE
"""
import argparse
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
sys.path.insert(0, os.path.dirname(HERE))
import route_audit_lib as L  # noqa: E402

_env = {k: os.environ.get(k) for k in ("CURL_CA_BUNDLE", "REQUESTS_CA_BUNDLE")}
import processor  # noqa: E402  (sets CA bundle env vars; restore them below)
for _k, _v in _env.items():
    if _v is None:
        os.environ.pop(_k, None)
    else:
        os.environ[_k] = _v


def check(path, peak_lat, peak_lon, peak_ele, peak_dem_ele=None, min_km=0.8, crater=False):
    pts = L.read_gpx(path)
    xy = [(p[0], p[1]) for p in pts]
    eles = [p[2] for p in pts]
    gpx, ppts = processor.parse_gpx(path)
    stats = processor.calculate_statistics(gpx, ppts)
    cd = L.cumdist(xy)
    total = cd[-1]
    dist_pk, _ = L.min_distance_to_track(xy, (peak_lat, peak_lon))
    emax, emin = max(eles), min(eles)
    imax = eles.index(emax)
    gap = peak_ele - emax
    gap_dem = (peak_dem_ele - emax) if peak_dem_ele is not None else gap
    jump = max((L.haversine(a, b) for a, b in zip(xy, xy[1:])), default=0)
    raw_gain = sum(max(0, b - a) for a, b in zip(eles, eles[1:]))
    gates = {
        "reaches_summit": (dist_pk <= 250 or (crater and dist_pk <= 800 and emax >= (peak_dem_ele or 0) - 30))
                          and (gap <= 80 or gap_dem <= 80),
        "ascent_shape": cd[imax] >= 0.85 * total and (eles[-1] - emin) >= 0.7 * (emax - emin),
        "gain_plausible": stats["elevation_gain_m"] <= 2 * (emax - emin) + 150,
        "grade": stats["average_grade_pct"] <= 45,
        "no_jump": jump <= 300,
        "distance": min_km <= total / 1000 <= 30,
    }
    return {"file": path, "pass": all(gates.values()), "gates": gates, "dist_to_peak_m": round(dist_pk),
            "max_ele": round(emax), "peak_ele": round(peak_ele), "ele_gap_m": round(gap),
            "max_jump_m": round(jump), "raw_gain_m": round(raw_gain), "km": round(total / 1000, 2),
            "processor": stats}


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--plan")
    ap.add_argument("--gpx")
    ap.add_argument("--peak", help="LAT,LON,ELE")
    args = ap.parse_args()
    if args.gpx:
        lat, lon, ele = map(float, args.peak.split(","))
        print(json.dumps(check(args.gpx, lat, lon, ele), indent=1))
        return
    out_dir = os.path.dirname(os.path.abspath(args.plan))
    plan = json.load(open(args.plan, encoding="utf-8"))
    results = []
    for e in plan:
        pk = e["peak"] if "peak" in e else e
        r = check(os.path.join(out_dir, e["file"]), pk["peak_lat"], pk["peak_lon"], pk["peak_ele"],
                  pk.get("peak_dem_ele"))
        r["file"] = e["file"]
        results.append(r)
        bad = [g for g, ok in r["gates"].items() if not ok]
        print(f"{'PASS' if r['pass'] else 'FAIL'} {e['file']:<70} {r['km']:>5} km gain {r['processor']['elevation_gain_m']:>5} "
              f"max {r['max_ele']} / peak {r['peak_ele']} d={r['dist_to_peak_m']} {' '.join(bad)}")
    with open(os.path.join(out_dir, "quality.json"), "w", encoding="utf-8") as f:
        json.dump(results, f, indent=1, ensure_ascii=False)
    print(f"{sum(r['pass'] for r in results)}/{len(results)} pass")


if __name__ == "__main__":
    main()
