"""Out-and-back recordings (up and down the same path): keep the ascent only,
trailhead -> highest point, original <trkpt> elements untouched. Run on the VPS:
  python3 _rs_outback.py /var/www/rutestrip-final /tmp/outback"""
import csv, json, math, os, re, sqlite3, sys
from xml.sax.saxutils import escape

IDS = [7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 18, 19, 20, 23, 35, 36, 37, 41, 44, 53, 56, 60, 67, 69, 70, 71, 73, 78, 114]
root, outdir = sys.argv[1], sys.argv[2]
os.makedirs(outdir, exist_ok=True)
db = sqlite3.connect(os.path.join(root, "database", "database.sqlite"))
audit = {r["route_id"]: r for r in csv.DictReader(open(os.path.join(root, "python", "output", "route_audit", "audit.csv"), encoding="utf-8"))}


def hv(a, b):
    la1, lo1, la2, lo2 = map(math.radians, (a[0], a[1], b[0], b[1]))
    h = math.sin((la2 - la1) / 2) ** 2 + math.cos(la1) * math.cos(la2) * math.sin((lo2 - lo1) / 2) ** 2
    return 2 * 6371000 * math.asin(math.sqrt(h))


def slug(s):
    return re.sub(r"[^a-z0-9]+", "-", s.lower()).strip("-")


plan, report = [], []
for rid in IDS:
    name, path = db.execute("select name, gpx_file_path from hiking_routes where id=?", (rid,)).fetchone()
    xml = open(os.path.join(root, "storage", "app", "public", path), encoding="utf-8", errors="ignore").read()
    blocks = re.findall(r"<trkpt\b[^>]*?(?:/>|>.*?</trkpt>)", xml, re.S)
    pts = []
    for b in blocks:
        la = float(re.search(r'lat="([-\d.]+)"', b).group(1))
        lo = float(re.search(r'lon="([-\d.]+)"', b).group(1))
        e = re.search(r"<ele>([-\d.]+)</ele>", b)
        pts.append((la, lo, float(e.group(1)) if e else None))
    ele = [p[2] if p[2] is not None else -1e9 for p in pts]
    w = 5  # smooth over +-5 points so a single elevation spike cannot be the summit
    sm = [sum(ele[max(0, i - w):i + w + 1]) / len(ele[max(0, i - w):i + w + 1]) for i in range(len(ele))]
    top = max(range(len(sm)), key=lambda i: sm[i])
    top = max(range(max(0, top - w), min(len(ele), top + w + 1)), key=lambda i: ele[i])
    cum = [0.0]
    for i in range(1, len(pts)):
        cum.append(cum[-1] + hv(pts[i - 1], pts[i]))
    asc = blocks[:top + 1]
    start_ele, end_ele, top_ele = pts[0][2], pts[-1][2], pts[top][2]
    gain = sum(max(0, (pts[i][2] or 0) - (pts[i - 1][2] or 0)) for i in range(1, top + 1))
    a = audit.get(str(rid), {})
    ok = top > 10 and len(pts) - top > 10 and start_ele is not None and top_ele - start_ele > 100
    report.append({"id": rid, "name": name, "points": len(pts), "kept": len(asc), "old_km": round(cum[-1] / 1000, 2),
                   "new_km": round(cum[top] / 1000, 2), "start_ele": start_ele, "top_ele": top_ele, "end_ele": end_ele,
                   "start_end_m": round(hv(pts[0], pts[-1])), "peak": a.get("peak_name"), "peak_ele": a.get("peak_ele"),
                   "audit_dist_to_peak_m": a.get("dist_to_peak_m"), "ok": ok})
    if not ok:
        continue
    fname = f"{rid:03d}-{slug(name)}.gpx"
    body = "\n".join("      " + b.strip() for b in asc)
    open(os.path.join(outdir, fname), "w", encoding="utf-8").write(f"""<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="RuteStrip route fix" xmlns="http://www.topografix.com/GPX/1/1">
  <metadata>
    <name>{escape(name)}</name>
    <desc>Ascent only (trailhead to summit). The original recording went up and came back down the same path; the descent half was removed. GPS points are unchanged.</desc>
  </metadata>
  <trk>
    <name>{escape(name)}</name>
    <trkseg>
{body}
    </trkseg>
  </trk>
</gpx>
""")
    plan.append({"route_id": rid, "action": "replace", "status": "COMPLETE", "file": fname, "km_added": 0,
                 "new_max_ele": int(round(top_ele)), "peak_name": a.get("peak_name") or "",
                 "peak_ele": int(float(a["peak_ele"])) if a.get("peak_ele") else None,
                 "old_km": round(cum[-1] / 1000, 2), "new_km": round(cum[top] / 1000, 2),
                 "reason": "rekaman naik-turun di jalur yang sama; diambil bagian naiknya saja (basecamp ke puncak), titik GPS asli"})
json.dump(plan, open(os.path.join(outdir, "plan.json"), "w", encoding="utf-8"), indent=1, ensure_ascii=False)
json.dump(report, open(os.path.join(outdir, "report.json"), "w", encoding="utf-8"), indent=1, ensure_ascii=False)
for r in report:
    print(f"{r['id']:>4} {'OK ' if r['ok'] else 'BAD'} {r['old_km']:>6} -> {r['new_km']:>6} km  ele {r['start_ele']}->{r['top_ele']}->{r['end_ele']}  s-e {r['start_end_m']}m  peak {r['peak']} {r['peak_ele']} d{r['audit_dist_to_peak_m']}  {r['name']}")
