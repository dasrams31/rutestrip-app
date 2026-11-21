"""
Assemble the route-audit deliverables from the intermediate JSON files.

Inputs (OUT = python/output/route_audit):
  audit_raw.json            audit_summits.py (before)
  completion_results.json   complete_to_summit.py
  new_trails.json           find_new_trails.py
Runs check_gpx_quality.check() on every generated GPX; files failing a gate
are moved to OUT/rejected/ and left out of plan.json.

Outputs: audit.csv, audit.md, plan.json, quality.json, OSM_SOURCES_additions.md

Usage
  python python/tools/build_audit_report.py [--out DIR]
"""
import argparse
import csv
import json
import os
import shutil
import sys
from collections import Counter

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import route_audit_lib as L  # noqa: E402
from check_gpx_quality import check  # noqa: E402

CSV_COLS = ["route_id", "name", "mountain", "via", "source", "status_before", "status_after", "peak_name",
            "peak_osm_id", "peak_ele", "peak_ele_src", "track_max_ele", "ele_gap_m", "dist_to_peak_m",
            "dem_gap_m", "round_trip", "length_km", "ele_status", "peak_choice", "action", "file", "km_added",
            "new_max_ele", "new_dist_to_peak_m", "ext_descent_m", "ext_net_ascent_pct", "ext_detour_ratio",
            "ext_evidence", "gpx_ele_vs_dem_median_m", "reason"]


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--out", default=L.DEFAULT_OUT)
    args = ap.parse_args()
    out = args.out
    audit = json.load(open(os.path.join(out, "audit_raw.json"), encoding="utf-8"))
    comp = L._load(os.path.join(out, "completion_results.json"), {})
    new = L._load(os.path.join(out, "new_trails.json"), [])
    notes = L._load(os.path.join(os.path.dirname(os.path.abspath(__file__)), "route_audit_overrides.json"), {})
    os.makedirs(os.path.join(out, "rejected"), exist_ok=True)

    plan, quality, sources, rejected = [], [], [], []
    crater_peaks = {a["peak_id"] for a in audit if a.get("peak_crater")}
    for rid, c in sorted(comp.items(), key=lambda t: int(t[0])):
        if c.get("action") != "replace":
            continue
        a = next(x for x in audit if x["route_id"] == rid)
        path = os.path.join(out, c["file"])
        if not os.path.exists(path):  # rejected on an earlier run: re-check and keep it rejected
            path = os.path.join(out, "rejected", os.path.basename(c["file"]))
            if not os.path.exists(path):
                continue
        q = check(path, a["peak_lat"], a["peak_lon"], a["peak_ele"], a["peak_dem_ele"],
                  crater=bool(a.get("peak_crater")))
        q["file"] = c["file"]
        quality.append(q)
        if not q["pass"]:
            if os.path.dirname(path) != os.path.join(out, "rejected"):
                shutil.move(path, os.path.join(out, "rejected", os.path.basename(path)))
            c["quality_fail"] = [g for g, ok in q["gates"].items() if not ok]
            rejected.append((rid, c))
            continue
        c["processor"] = q["processor"]
        status = c.get("status", "COMPLETE")
        extra = {k: c[k] for k in ("descent_m", "net_ascent_m", "height_gap_m", "net_ascent_pct", "detour_ratio",
                                   "straight_km", "evidence_share_pct", "evidence", "highways", "png") if k in c}
        c["source_urls"] = list(dict.fromkeys(c["source_urls"]))
        plan.append({"route_id": int(rid), "action": "replace", "status": status, "file": c["file"],
                     "reason": c["reason"], **extra,
                     "source_urls": c["source_urls"], "old_max_ele": c["old_max_ele"],
                     "new_max_ele": c["new_max_ele"], "peak_name": c["peak_name"], "peak_ele": c["peak_ele"],
                     "km_added": c.get("km_added", 0.0), "method": c["method"],
                     "peak_lat": a["peak_lat"], "peak_lon": a["peak_lon"], "peak_osm_id": a["peak_id"],
                     "new_distance_km": q["processor"]["distance_km"],
                     "new_elevation_gain_m": q["processor"]["elevation_gain_m"],
                     "new_difficulty": q["processor"]["difficulty"]})
        osm = ", ".join(f"[{u.rsplit('/', 2)[-2]} {u.rsplit('/', 1)[-1]}]({u})" for u in c["source_urls"][:6])
        more = f" +{len(c['source_urls']) - 6}" if len(c["source_urls"]) > 6 else ""
        orig = c.get("osm_name") or (f"completion of route {rid} ({c['name']})")
        sources.append(f"| {c['file']} | {osm}{more} | {orig} |")
    new_ok = []
    for e in new:
        path = os.path.join(out, e["file"])
        if not os.path.exists(path):
            path = os.path.join(out, "rejected", os.path.basename(e["file"]))
            if not os.path.exists(path):
                continue
        pk = e["peak"]
        q = check(path, pk["peak_lat"], pk["peak_lon"], pk["peak_ele"], pk.get("peak_dem_ele"), min_km=0.3,
                  crater=e["peak_id"] in crater_peaks)
        q["file"] = e["file"]
        quality.append(q)
        if not q["pass"]:
            if os.path.dirname(path) != os.path.join(out, "rejected"):
                shutil.move(path, os.path.join(out, "rejected", os.path.basename(path)))
            e["quality_fail"] = [g for g, ok in q["gates"].items() if not ok]
            rejected.append((None, e))
            continue
        e["processor"] = q["processor"]
        new_ok.append(e)
        plan.append({"action": "add", "file": e["file"], "name": e["name"], "source_urls": e["source_urls"],
                     "peak": {"name": pk["name"], "lat": pk["peak_lat"], "lon": pk["peak_lon"],
                              "ele": pk["peak_ele"], "osm_id": e["peak_id"]},
                     "trailhead": e["trailhead"], "mountain": e["mountain"],
                     "distance_km": q["processor"]["distance_km"],
                     "elevation_gain_m": q["processor"]["elevation_gain_m"],
                     "difficulty": q["processor"]["difficulty"]})
        osm = ", ".join(f"[{u.rsplit('/', 2)[-2]} {u.rsplit('/', 1)[-1]}]({u})" for u in e["source_urls"][:6])
        more = f" +{len(e['source_urls']) - 6}" if len(e["source_urls"]) > 6 else ""
        sources.append(f"| {e['file']} | {osm}{more} | {e['osm_name']} |")

    with open(os.path.join(out, "plan.json"), "w", encoding="utf-8") as f:
        json.dump(plan, f, indent=1, ensure_ascii=False)
    with open(os.path.join(out, "quality.json"), "w", encoding="utf-8") as f:
        json.dump(quality, f, indent=1, ensure_ascii=False)

    # per-route rows
    replaced = {str(p["route_id"]): p for p in plan if p["action"] == "replace"}
    rej = {rid: c for rid, c in rejected if rid}
    rows = []
    for a in audit:
        rid = a["route_id"]
        c = comp.get(rid, {})
        after = a["cls"]
        action = ""
        if rid in replaced:
            after, action = replaced[rid]["status"], f"replace ({replaced[rid]['method']})"
        elif a["cls"] == "INCOMPLETE":
            after = "CANNOT_COMPLETE"
            action = "none"
        row = {k: "" for k in CSV_COLS}
        row.update(route_id=rid, name=a["name"], mountain=a["mountain"], via=a.get("via") or "",
                   source=a.get("source") or "", status_before=a["cls"], status_after=after,
                   peak_name=a.get("peak_name", ""), peak_osm_id=a.get("peak_id", ""),
                   peak_ele=a.get("peak_ele", ""), peak_ele_src=a.get("peak_ele_src", ""),
                   track_max_ele=a.get("track_max_ele", ""), ele_gap_m=a.get("ele_gap_m", ""),
                   dist_to_peak_m=a.get("dist_to_peak_m", ""), dem_gap_m=a.get("dem_gap_m", ""),
                   round_trip=a.get("round_trip", ""), length_km=a.get("length_km", ""),
                   ele_status=a.get("ele_status", ""), peak_choice=a.get("peak_choice", ""), action=action,
                   reason=a.get("reason", ""))
        row["gpx_ele_vs_dem_median_m"] = a.get("ele_median_abs_diff_dem_m", "")
        if a.get("note"):
            row["reason"] = f"{row['reason']} [{a['note']}]"
        if rid in replaced:
            p = replaced[rid]
            row.update(file=p["file"], km_added=p["km_added"], new_max_ele=p["new_max_ele"],
                       new_dist_to_peak_m=c.get("new_dist_to_peak_m", ""),
                       ext_descent_m=c.get("descent_m", ""), ext_net_ascent_pct=c.get("net_ascent_pct", ""),
                       ext_detour_ratio=c.get("detour_ratio", ""), ext_evidence="; ".join(c.get("evidence", [])),
                       reason=f"{a.get('reason', '')} -> {c['reason']}")
        elif a["cls"] == "INCOMPLETE":
            why = c.get("reason") or "not processed"
            if c.get("extended_fail"):
                why += f"; extended tier (<= 6 km): {c['extended_fail']}"
            if rid in rej:
                why = f"completion produced a track failing quality gates ({', '.join(rej[rid]['quality_fail'])})"
            row.update(reason=f"{a.get('reason', '')} -> cannot complete: {why}")
        rows.append(row)
    with open(os.path.join(out, "audit.csv"), "w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=CSV_COLS)
        w.writeheader()
        w.writerows(rows)

    with open(os.path.join(out, "OSM_SOURCES_additions.md"), "w", encoding="utf-8") as f:
        f.write("# GPX dari OpenStreetMap (tambahan audit rute)\n\n"
                "Dibuat dengan `python/tools/complete_to_summit.py` (penyelesaian / pengganti jalur) dan "
                "`python/tools/find_new_trails.py` (jalur baru). Elevasi dari DEM SRTM 30 m (OpenTopoData), "
                "fallback Open-Meteo 90 m.\n\nData jalur (c) OpenStreetMap contributors, dilisensikan di bawah "
                "Open Database License (ODbL) 1.0: https://www.openstreetmap.org/copyright\n\n"
                "| File | OSM | Nama di OSM |\n|---|---|---|\n" + "\n".join(sources) + "\n")

    write_md(out, audit, rows, comp, plan, new_ok, rejected, notes)
    print(f"plan: {sum(p['action'] == 'replace' for p in plan)} replace, {len(new_ok)} add; "
          f"{len(rejected)} rejected by quality gates")


def base_mode(a):
    if a.get("round_trip"):
        return "round trip -> one way (outbound half)"
    frac = a.get("nearest_frac") or 1
    if frac < 0.9:
        return (f"one way; last {a['length_km'] * (1 - frac):.1f} km of the original (beyond the point nearest "
                f"the summit, e.g. a traverse descent) dropped")
    return "one way"


def fmt(v):
    return "" if v is None else str(v)


def write_md(out, audit, rows, comp, plan, new_ok, rejected, notes):
    before = Counter(r["status_before"] for r in rows)
    after = Counter(r["status_after"] for r in rows)
    usage = L.USAGE.get("total", {})
    L_ = []
    A = L_.append
    A("# Audit puncak rute RuteStrip\n")
    A("Generated by `python/tools/audit_summits.py`, `complete_to_summit.py`, `extend_to_summit.py`, "
      "`render_previews.py` and `build_audit_report.py` (new trails later: `find_new_trails.py`) from `dataset/routes.json` + `dataset/gpx/` (143 routes, full original GPX).\n")
    A("## Method and thresholds\n")
    A("- Intended summit: OSM `natural=peak|volcano` nodes within 4 km of the track (one Overpass union query per "
      "15 routes). Matched on the route's mountain name after removing Gunung/Mt./Puncak/Bukit/Puthuk prefixes and "
      "aliasing spelling variants (Arjuna->Arjuno, Ceremai->Ciremai ...); exact single-summit names beat "
      "multi-mountain names (\"Arjuno-Welirang\"), real mapped nodes beat NGA-GNS name imports (often mislocated). "
      "Without a name match: highest peak within 2 km of the track's high point. Manual choices are in "
      "`python/tools/route_audit_overrides.json` (each with a reason).")
    A("- Peak elevation: OSM `ele`, else SRTM 30 m DEM at the node.")
    A("- **COMPLETE**: the full track passes within **250 m** (horizontal, point-to-segment) of the peak AND its max "
      "elevation is within **80 m** of the peak elevation. Track elevations from phones/watches are often 50-150 m "
      "off, so the vertical test also passes when the DEM at the closest track point is within 80 m of the DEM at "
      "the peak, provided the peak node's `ele` agrees with the DEM (|ele-DEM| <= 100 m, i.e. the node is well "
      "placed).")
    A("- **Summit-crater rule** (tuned): when the peak is a `natural=volcano` node whose `ele` is >= 50 m above "
      "the DEM at its position, the node sits over the summit crater rather than on the rim high point (Slamet, "
      "Sumbing, Raung). Such a route is COMPLETE when the track reaches the rim within 800 m of the node, its max "
      "elevation is within 80 m of the peak ele, and the DEM at its closest point is not lower than the node's "
      "DEM - 30 m. Completions on these peaks may end at any OSM rim node within 800 m whose DEM is >= max(node "
      "DEM - 30, peak ele - 120).")
    A("- **INCOMPLETE**: farther than 250 m (missing horizontal distance and height reported).")
    A("- **NOT_A_SUMMIT_ROUTE**: destination is not a peak (crater rim, waterfall, ridge/forest traverse); each "
      "justified below.")
    A("- **DATA_PROBLEM**: wrong area (>3 km from the listed trailhead) or GPX elevation broken (median |GPX-DEM| > "
      "150 m) with an otherwise ambiguous result.")
    A("- Completion: original track from the trailhead up to its point nearest the peak (round trips that turned "
      "back become one way), extended by the shortest path on the OSM path graph (`highway=path|footway|track|"
      "steps|bridleway` + all ways of `route=hiking` relations) to the way node nearest the peak (<= 150 m from "
      "it), <= 3 km and climbing. OSM noding gaps <= 30 m are joined; nothing else is drawn. Fallback: an OSM "
      "hiking relation / named trail from the same trailhead (<= 1.5 km) that reaches the summit.")
    A("- Output tracks are resampled every 30 m with DEM elevation (SRTM 30 m, OpenTopoData; Open-Meteo 90 m "
      "fallback) and carry ODbL attribution in `<desc>` and `<link>`.\n")
    A("## Counts\n")
    A("| Class | Before | After |\n|---|---|---|")
    for k in ["COMPLETE", "INCOMPLETE", "EXTENDED_REVIEW", "CANNOT_COMPLETE", "NOT_A_SUMMIT_ROUTE", "DATA_PROBLEM", "REVIEW",
              "UNRESOLVED"]:
        if before.get(k) or after.get(k):
            A(f"| {k} | {before.get(k, 0)} | {after.get(k, 0)} |")
    A(f"| **Total** | {sum(before.values())} | {sum(after.values())} |\n")
    A(f"Plan: {sum(p['action'] == 'replace' for p in plan)} replacements, {len(new_ok)} new trails. "
      f"API usage (cumulative, incl. retries): Overpass {usage.get('overpass_requests', 0)} requests "
      f"({usage.get('overpass_failures', 0)} failed/retried), OpenTopoData {usage.get('opentopodata_requests', 0)}, "
      f"Open-Meteo {usage.get('openmeteo_requests', 0)}.\n")

    A("## Routes completed / replaced\n")
    A("Automatic tier (<= 3 km on OSM paths); all passed the quality gates (`quality.json`). Preview PNGs: "
      "`review/<id>-completion.png` (`python/tools/render_previews.py`). A spur or side trip the original made "
      "after first reaching the junction where the OSM completion starts is dropped (e.g. #3's Welirang detour).\n")
    A("| ID | Route | Method | Base | Peak (ele) | Old max | New max | Gap before (m) | km added | New km | File |")
    A("|---|---|---|---|---|---|---|---|---|---|---|")
    for p in plan:
        if p["action"] != "replace" or p["status"] != "COMPLETE":
            continue
        a = next(x for x in audit if x["route_id"] == str(p["route_id"]))
        A(f"| {p['route_id']} | {a['name']} | {p['method']} | {base_mode(a)} | {p['peak_name']} ({p['peak_ele']}) | "
          f"{p['old_max_ele']} | {p['new_max_ele']} | {a['dist_to_peak_m']} m / {max(0, a['ele_gap_m'])} m | "
          f"{p['km_added']} | {p['new_distance_km']} | `{p['file']}` |")
    A("")
    ext = [p for p in plan if p["action"] == "replace" and p["status"] == "EXTENDED_REVIEW"]
    A("## Extended tier: needs visual review (EXTENDED_REVIEW)\n")
    A("Owner-approved second tier for routes whose OSM connection is longer than 3 km: up to 6 km, only "
      "`highway=path|footway|track|steps`, total descent <= 150 m, net ascent >= 70 % of the height gap (DEM), "
      "added length <= 1.8 x the straight line, most hiking evidence (Jalur/Pendakian/Trek/Trail names, "
      "sac_scale, route=hiking membership) then shortest. GPX + preview PNG in `review/`; not applied "
      "automatically. If a preview is rejected, treat the route as CANNOT_COMPLETE (community GPX).\n")
    A("| ID | Route | Peak (ele) | Old max | New max | km added | Straight km | Detour | Descent m | Net ascent | "
      "Evidence | Preview | GPX |")
    A("|---|---|---|---|---|---|---|---|---|---|---|---|---|")
    for p in ext:
        a = next(x for x in audit if x["route_id"] == str(p["route_id"]))
        A(f"| {p['route_id']} | {a['name']} | {p['peak_name']} ({p['peak_ele']}) | {p['old_max_ele']} | "
          f"{p['new_max_ele']} | {p['km_added']} | {p.get('straight_km')} | {p.get('detour_ratio')} | "
          f"{p.get('descent_m')} | {p.get('net_ascent_pct')}% | {p.get('evidence_share_pct')}%: "
          f"{'; '.join(p.get('evidence', [])[:3])} | `{p.get('png')}` | `{p['file']}` |")
    A("")
    cannot = [r for r in rows if r["status_after"] == "CANNOT_COMPLETE"]
    A("## Routes that could not be completed\n")
    A("| ID | Route | Peak | Missing (horizontal / height) | Why |\n|---|---|---|---|---|")
    for r in cannot:
        c = comp.get(r["route_id"], {})
        why = c.get("reason", "")
        if c.get("extended_fail"):
            why += f"; extended tier (<= 6 km): {c['extended_fail']}"
        rj = next((cc for rid, cc in rejected if rid == r["route_id"]), None)
        if rj:
            why = f"quality gates failed: {', '.join(rj['quality_fail'])}"
        A(f"| {r['route_id']} | {r['name']} | {r['peak_name']} ({r['peak_ele']}) | {r['dist_to_peak_m']} m / "
          f"{max(0, int(r['ele_gap_m'] or 0))} m | {why} |")
    A("")
    A("## Perlu GPX dari komunitas\n")
    A("Rute di bawah tidak bisa dilengkapi dari OpenStreetMap. Mohon minta rekaman GPX (dengan izin redistribusi) "
      "dari komunitas pendaki / basecamp. Jangan mengunduh dari Wikiloc/AllTrails/Strava/blog tanpa izin.\n")
    A("| ID | Rute | Gunung | Basecamp / trailhead | Yang kurang |\n|---|---|---|---|---|")
    for r in cannot:
        a = next(x for x in audit if x["route_id"] == r["route_id"])
        A(f"| {r['route_id']} | {r['name']} | {r['mountain']} -> {r['peak_name']} ({r['peak_ele']} m) | "
          f"{r['via'] or '-'} ({a['start'][0]:.5f}, {a['start'][1]:.5f}) | "
          f"{round(int(r['dist_to_peak_m']) / 1000, 2)} km horizontal, ~{max(0, int(r['ele_gap_m']))} m naik "
          f"ke puncak (track max {r['track_max_ele']} m) |")
    A("")
    A("## NOT_A_SUMMIT_ROUTE\n")
    A("| ID | Route | Why |\n|---|---|---|")
    for r in rows:
        if r["status_before"] == "NOT_A_SUMMIT_ROUTE":
            A(f"| {r['route_id']} | {r['name']} | {r['reason']} |")
    A("")
    eleq = [r for r in rows if isinstance(r["gpx_ele_vs_dem_median_m"], (int, float))
            and r["gpx_ele_vs_dem_median_m"] > 50]
    if eleq:
        A("## Elevation quality of original GPX files\n")
        A("Median |GPX elevation - SRTM DEM| over ~26 sample points > 50 m (stats shown in the app are affected; "
          "consider re-elevating with a DEM):\n")
        A("| ID | Route | Median diff (m) | Track max | Status |\n|---|---|---|---|---|")
        for r in eleq:
            A(f"| {r['route_id']} | {r['name']} | {r['gpx_ele_vs_dem_median_m']} | {r['track_max_ele']} | "
              f"{r['status_after']} |")
        A("")
    probs = [r for r in rows if r["status_after"] in ("DATA_PROBLEM", "REVIEW", "UNRESOLVED")]
    if probs:
        A("## Data problems / review\n")
        A("| ID | Route | Status | Detail |\n|---|---|---|---|")
        for r in probs:
            A(f"| {r['route_id']} | {r['name']} | {r['status_after']} | {r['reason']} |")
        A("")
    A("## New trails (OSM, not yet in the dataset)\n")
    if not os.path.exists(os.path.join(out, "new_trails.json")):
        A("Not run yet: step 3 (`find_new_trails.py`) is pending the owner's go-ahead; this plan contains "
          "replacements only.\n")
    by_m = Counter(e["mountain"] for e in new_ok)
    A("Count by mountain: " + ", ".join(f"{k} {v}" for k, v in sorted(by_m.items())) + "\n")
    A("| File | Peak (ele) | Trailhead | km | Gain | Difficulty | OSM name |\n|---|---|---|---|---|---|---|")
    for e in new_ok:
        A(f"| `{e['file']}` | {e['peak']['name']} ({e['peak']['peak_ele']}) | {e['trailhead']['basecamp']} "
          f"({e['trailhead']['lat']}, {e['trailhead']['lon']}) | {e['processor']['distance_km']} | "
          f"{e['processor']['elevation_gain_m']} | {e['processor']['difficulty']} | {e['osm_name']} |")
    treport = L._load(os.path.join(out, "new_trail_targets_report.json"), [])
    req = [t for t in treport if t["kind"] == "requested"]
    if req:
        A("\n### Requested mountains / areas (owner list)\n")
        A("Trails listed are those that passed every gate (accepted new trails); 'tidak ada jalur di OSM' means "
          "OSM has no hiking relation or named trail way reaching that summit from a new trailhead (ask the "
          "community for a permission-cleared GPX).\n")
        A("| Requested | OSM summit(s) found | Trails accepted (OSM) | Notes |\n|---|---|---|---|")
        for t in req:
            for nm in t["report_names"] or [t["label"]]:
                key = L.norm_name(nm)
                pks = [p for p in t["peaks"] if key in L.norm_name(p["name"])]
                trails = [e for e in new_ok if e.get("target") == t["label"] and key in L.norm_name(e["peak"]["name"])]
                if not trails and t["label"] in ("Dieng", "Lawu selatan"):
                    trails = [e for e in new_ok if key in L.norm_name(e["name"])]
                # trails for the same summit may have been accepted under an existing-mountain cluster
                trails += [e for e in new_ok if e.get("target") != t["label"] and pks
                           and e["peak_id"] in {p["id"] for p in pks} and e not in trails]
                tr = "<br>".join(f"`{e['file']}` ({e['processor']['distance_km']} km; "
                                 + ", ".join(f"[{u.rsplit('/', 2)[-2]} {u.rsplit('/', 1)[-1]}]({u})"
                                             for u in e["source_urls"][:3]) + ")" for e in trails)
                existing = [a_["route_id"] for a_ in audit if pks and a_.get("peak_id") in {p["id"] for p in pks}]
                notes = []
                if existing:
                    notes.append(f"already in DB: routes {', '.join(existing)}")
                if t.get("error"):
                    notes.append(t["error"])
                A(f"| {nm} ({t['label']}) | {', '.join(sorted({p['name'] for p in pks})) or 'no named OSM peak'} | "
                  f"{tr or 'tidak ada jalur di OSM'} | {'; '.join(notes)} |")
        A("")
    rej_new = [e for rid, e in rejected if rid is None]
    if rej_new:
        A("\nRejected by quality gates: " + "; ".join(f"{e['name']} ({', '.join(e['quality_fail'])})"
                                                      for e in rej_new))
    A("")
    A("## All routes\n")
    A("| ID | Route | Before | After | Peak | Peak ele | Track max | d(peak) m | Round trip | Reason |")
    A("|---|---|---|---|---|---|---|---|---|---|")
    for r in rows:
        A(f"| {r['route_id']} | {r['name']} | {r['status_before']} | {r['status_after']} | {r['peak_name']} | "
          f"{r['peak_ele']} | {r['track_max_ele']} | {r['dist_to_peak_m']} | {r['round_trip']} | {r['reason']} |")
    A("")
    A("## Caveats\n")
    for n in notes.get("_caveats", []):
        A(f"- {n}")
    with open(os.path.join(out, "audit.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(L_) + "\n")


if __name__ == "__main__":
    main()
