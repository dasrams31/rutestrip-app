"""
Build GPX files for Java hiking routes from OpenStreetMap.

Source: OpenStreetMap hiking route relations (route=hiking) and named hiking
path ways ("Jalur Pendakian ..."), fetched with the Overpass API. Elevation is added from the SRTM 30 m DEM via the public
OpenTopoData API, because OSM geometry has no elevation.

Data licence: (c) OpenStreetMap contributors, ODbL 1.0
(https://www.openstreetmap.org/copyright). Elevation: NASA SRTM via
OpenTopoData. Keep the attribution when redistributing the generated files.

Usage:
    python osm_hiking_to_gpx.py OUTPUT_DIR [relation_id ...]
    python osm_hiking_to_gpx.py --ways [--probe] OUTPUT_DIR [route name ...]

Without relation ids the curated RELATIONS list below is used; "batch2"
selects RELATIONS_2. --ways builds
tracks from the curated WAYS list (OSM ways grouped by their name tag);
--probe only prints the chained length and endpoints, without elevation.
"""
import json
import math
import os
import re
import sys
import time
import urllib.parse
import urllib.request
from xml.sax.saxutils import escape

USER_AGENT = "rutestrip-gpx-builder/1.0 (+https://rutestrip.ai.id)"
OVERPASS_URLS = [
    "https://overpass-api.de/api/interpreter",
    "https://overpass.private.coffee/api/interpreter",
    "https://overpass.kumi.systems/api/interpreter",
]
JAVA_BBOX = "-8.85,105.1,-5.85,114.65"
ELEVATION_URL = "https://api.opentopodata.org/v1/srtm30m"
SAMPLE_SPACING_M = 30
MAX_JOIN_GAP_M = 60
WAY_JOIN_GAP_M = 150

# Curated Java summit routes not yet in the RuteStrip dataset (OSM relation ids).
RELATIONS = [
    17262572, 17252410, 17278243, 17262060, 17252724, 17252779, 17252812,
    17252911, 18158094, 18159119, 18159120, 19012229, 18299963, 17253830,
    17253841, 17253936, 17253994, 17254387, 17254417, 17254473, 17254474,
    17254560, 17256233, 17256365, 17256368, 17256377, 17256502, 17256565,
    17256566, 17256640, 17256681, 17256724, 17256878, 17260815, 17260916,
    18158645, 18158651, 18158648, 18158958, 18299768, 18299769, 19011967,
    19012100, 18158913, 19362091, 18158692, 17253859,
]

RELATIONS_2 = [
    17252297, 17252338, 17252375, 17252385, 18158095, 18158647, 18158649,
    18158650, 18158689, 18158690, 18158691, 18299770, 18704193, 19011965,
    19011966, 19012230, 19358747, 19358748, 18158936, 17262104,
]

# Named OSM hiking ways (name tag variants) -> RuteStrip route name.
WAYS = [
    (["Trek G Prau via Patakbanteng"], "Gunung Prau via Patak Banteng"),
    (["Trek G Prau via Wates"], "Gunung Prau via Wates"),
    (["Jalur Pendakian Puncak 29 Via Desa Rahtawu"], "Gunung Muria Puncak 29 via Rahtawu"),
    (["Jalur Pendakian Gunung Muria"], "Gunung Muria"),
    (["Penanggungan via Jolotundo (Jalur Baru)"], "Gunung Penanggungan via Jolotundo"),
    (["Jalur Pendakian Gunung Penanggungan via Tamiajeng"], "Gunung Penanggungan via Tamiajeng"),
    (["Jalur Pendakian Gunung Penanggungan via Genting"], "Gunung Penanggungan via Genting"),
    (["Jalur Pendakian Watu Jengger"], "Bukit Watu Jengger"),
    (["Trek Alternatif Merbabu via Wonolelo", "Trek Alernatif Merbabu via Wonolelo"], "Gunung Merbabu via Wonolelo"),
    (["Trek Merbabu via Cuntel"], "Gunung Merbabu via Cuntel"),
    (["Jalur Pendakian Artapela via Cirawa"], "Gunung Artapela via Cirawa"),
    (["Jalur pendakian Gunung Butak via Panderman"], "Gunung Butak via Panderman"),
    (["Jalur pendakian Gunung Butak via Sirahkencong"], "Gunung Butak via Sirah Kencong"),
    (["Jalur pendakian Gunung Butak via Kraton Kawi"], "Gunung Butak via Kraton Kawi"),
    (["Jalur pendakian Gunung Panderman"], "Gunung Panderman"),
    (["Trek Ungaran via Perantunan"], "Gunung Ungaran via Perantunan"),
    (["Jalur Pendakian Gunung Sindoro via Kalitengah"], "Gunung Sindoro via Kalitengah"),
    (["Jalur Pendakian Gunung Sindoro via Bansari"], "Gunung Sindoro via Bansari"),
    (["Jalur Pendakian Gunung Sindoro via Ndoroarum"], "Gunung Sindoro via Ndoroarum"),
    (["Jalur Pendakian Gunung Sindoro via Bedakah"], "Gunung Sindoro via Bedakah"),
    (["Jalur Pendakian Gunung Sumbing via Mangli"], "Gunung Sumbing via Mangli"),
    (["Jalur Pendakian Gunung Sumbing via Butuh"], "Gunung Sumbing via Butuh"),
    (["Jalur Pendakian Gunung Sumbing via Gerung 1"], "Gunung Sumbing via Gerung"),
    (["Jalur Pendakian Gunung Sumbing via Banaran"], "Gunung Sumbing via Banaran"),
    (["Jalur Pendakian Gunung Sumbing via Pagergunung/Cepit"], "Gunung Sumbing via Cepit"),
    (["Jalur Pendakian Gunung Ngliman Wilis via Kare"], "Gunung Liman via Kare"),
    (["Jalur pendakian Gn. Rakutak"], "Gunung Rakutak"),
    (["Jalur Pendakian Arjuno via Telih"], "Gunung Arjuno via Telih"),
    (["Jalur Pendakian Gunung Andong via Temu Kidul"], "Gunung Andong via Temu Kidul"),
    (["Trek Gn Andong via Gogik"], "Gunung Andong via Gogik"),
    (["Hiking Trail Gunung Rante"], "Gunung Rante"),
    (["Jalur Pendakian Gunung Slamet via Sawangan"], "Gunung Slamet via Sawangan"),
    (["Jalur pendakian Gunung Raung via Kalibaru"], "Gunung Raung via Kalibaru"),
    (["Trek Telomoyo via Arsal"], "Gunung Telomoyo via Arsal"),
    (["Jalur Pendakian Gunung Bisma via Sikunang"], "Gunung Bisma via Sikunang"),
    (["Jalur Hiking Gunung Bangkok Trailhead Sukamulya"], "Gunung Bangkok via Sukamulya"),
    (["Jalur Pendakian Gunung Gegermenjangan"], "Bukit Gegermenjangan"),
    (["Jalur pendakian Gunung Penuksapi"], "Gunung Penuksapi"),
    (["Jalur Pendakian ke Watupayon / Tremulus"], "Gunung Watupayon Muria"),
    (["Jalur Pendakian via Silandak"], "Gunung Bisma via Silandak"),
    (["Jalur Pendakian via Ngandam"], "Gunung Bisma via Ngandam"),
    (["jalur pendakian via ngroto"], "Puncak Argopuro Lasem via Ngroto"),
    (["Jalur Pendakian Puthuk Siwur", "ihwalpraja_Puthuk Siwur Trail"], "Puthuk Siwur"),
    (["ihwalpraja Trail Puthuk Gragal via Cembor", "Trail Puthuk Gragal via Cembor"], "Gragal Hill via Cembor"),
    (["Jalur Bukit Cendono", "Jalur Puthuk Kencur dan Bukit Cendono"], "Puthuk Kencur dan Bukit Cendono"),
    (["Cisadon Trail", "Cisadon Trail2"], "Jalur Cisadon Sentul"),
    (["Pendakian Gumuk Akin"], "Gumuk Akin"),
    (["Sunrise View Trek"], "Kawah Ijen via Sunrise View Trek"),
    (["Jalur Pendakian Gunung Patuha"], "Gunung Patuha"),
    (["Jalur Alternatif Pendakian Gunung Slamet via Guci"], "Gunung Slamet via Guci Jalur Alternatif"),
    (["Jalur ke Argojembangan"], "Puncak Argojembangan Muria"),
    (["Claket - Asitaba Trail", "ihwalpraja_Claket - Asitaba Trail"], "Jalur Claket - Asitaba Pacet"),
]


def http_post(url, data, timeout=300):
    req = urllib.request.Request(url, data=urllib.parse.urlencode(data).encode(),
                                 headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.load(r)


def overpass(query):
    last = None
    for attempt in range(6):
        url = OVERPASS_URLS[attempt % len(OVERPASS_URLS)]
        try:
            return http_post(url, {"data": query})
        except Exception as exc:  # busy mirrors answer 429/502/504
            last = exc
            print(f"overpass retry ({url}): {exc}", file=sys.stderr)
            time.sleep(15)
    raise last


def http_get(url, timeout=60):
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.load(r)


def haversine(a, b):
    lat1, lon1, lat2, lon2 = map(math.radians, (a[0], a[1], b[0], b[1]))
    h = math.sin((lat2 - lat1) / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin((lon2 - lon1) / 2) ** 2
    return 2 * 6371000 * math.asin(math.sqrt(h))


def chain_ways(ways, max_gap=MAX_JOIN_GAP_M):
    """Greedily join way geometries end-to-end (reversing as needed) and return
    the longest connected chain as a list of (lat, lon)."""
    pieces = [list(w) for w in ways if len(w) >= 2]
    chains = []
    while pieces:
        chain = pieces.pop(0)
        grown = True
        while grown:
            grown = False
            for i, p in enumerate(pieces):
                for cand, at_end in ((p, True), (p[::-1], True), (p, False), (p[::-1], False)):
                    if at_end and haversine(chain[-1], cand[0]) <= max_gap:
                        chain += cand[1:]
                    elif not at_end and haversine(cand[-1], chain[0]) <= max_gap:
                        chain = cand[:-1] + chain
                    else:
                        continue
                    pieces.pop(i)
                    grown = True
                    break
                if grown:
                    break
        chains.append(chain)
    return max(chains, key=lambda c: sum(haversine(c[i], c[i + 1]) for i in range(len(c) - 1)))


def resample(points, spacing):
    out = [points[0]]
    carry = 0.0
    for a, b in zip(points, points[1:]):
        seg = haversine(a, b)
        pos = spacing - carry
        while pos <= seg:
            t = pos / seg
            out.append((a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t))
            pos += spacing
        carry = (carry + seg) % spacing
    if out[-1] != points[-1]:
        out.append(points[-1])
    return out


def elevations(points):
    result = []
    for i in range(0, len(points), 100):
        batch = points[i:i + 100]
        locs = "|".join(f"{lat:.6f},{lon:.6f}" for lat, lon in batch)
        for attempt in range(4):
            try:
                data = http_get(f"{ELEVATION_URL}?locations={urllib.parse.quote(locs)}")
                break
            except Exception:
                time.sleep(2 + attempt * 3)
        else:
            raise RuntimeError("elevation lookup failed")
        result += [r.get("elevation") for r in data["results"]]
        time.sleep(1.1)  # public API: 1 request per second
    return result


def clean_name(name):
    name = re.sub(r"^(Gurung|Gutung)\b", "Gunung", name.strip())
    name = re.sub(r"\s*\((.+?)\s+BC\)", r" via \1", name)
    name = re.sub(r"\s*\((.+?)\)", r" (\1)", name)
    if name.startswith("Ijen"):
        name = "Gunung Ijen"
    if not re.match(r"^(Gunung|Puncak|Bukit)\b", name):
        name = "Gunung " + name
    return re.sub(r"\s+", " ", name).strip()


def write_gpx(path, name, source, link, points, eles):
    pts = "\n".join(
        f'      <trkpt lat="{lat:.6f}" lon="{lon:.6f}"><ele>{ele:.1f}</ele></trkpt>'
        for (lat, lon), ele in zip(points, eles)
    )
    xml = f"""<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="RuteStrip osm_hiking_to_gpx.py" xmlns="http://www.topografix.com/GPX/1/1">
  <metadata>
    <name>{escape(name)}</name>
    <desc>Track: {escape(source)}, (c) OpenStreetMap contributors, ODbL 1.0. Elevation: SRTM 30 m via OpenTopoData.</desc>
    <link href="{escape(link)}"><text>{escape(source)}</text></link>
  </metadata>
  <trk>
    <name>{escape(name)}</name>
    <trkseg>
{pts}
    </trkseg>
  </trk>
</gpx>
"""
    with open(path, "w", encoding="utf-8") as f:
        f.write(xml)


def track_length(points):
    return sum(haversine(points[i], points[i + 1]) for i in range(len(points) - 1))


def build_track(out_dir, name, ways, source, link, max_gap=MAX_JOIN_GAP_M):
    """Chain, resample, add elevation and write one GPX. Returns the filename or None."""
    points = resample(chain_ways(ways, max_gap), SAMPLE_SPACING_M)
    eles = elevations(points)
    if any(e is None for e in eles):
        print(f"skip {name}: missing elevation")
        return None
    if eles[0] > eles[-1]:  # orient trailhead -> summit
        points, eles = points[::-1], eles[::-1]
    filename = re.sub(r'[\\/:*?"<>|]', "", name) + ".gpx"
    write_gpx(os.path.join(out_dir, filename), name, source, link, points, eles)
    print(f"ok   {name}: {track_length(points) / 1000:.1f} km, {min(eles):.0f}-{max(eles):.0f} m, {len(points)} pts")
    return filename


def main_relations(out_dir, ids):
    data = overpass(f"[out:json][timeout:240];rel(id:{','.join(map(str, ids))});out geom;")
    sources = []
    for rel in data["elements"]:
        rel_id = rel["id"]
        raw_name = rel.get("tags", {}).get("name") or f"OSM {rel_id}"
        name = clean_name(raw_name)
        ways = [[(g["lat"], g["lon"]) for g in m["geometry"]]
                for m in rel.get("members", []) if m.get("type") == "way" and m.get("geometry")]
        if not ways:
            print(f"skip {rel_id} {name}: no way geometry")
            continue
        link = f"https://www.openstreetmap.org/relation/{rel_id}"
        filename = build_track(out_dir, name, ways, f"OpenStreetMap relation {rel_id}", link)
        if filename:
            sources.append(f"| {filename} | [{rel_id}]({link}) | {raw_name} |")
    return sources


def main_ways(out_dir, wanted, probe):
    entries = [(osm_names, name) for osm_names, name in WAYS if not wanted or name in wanted]
    # Name-only filters hit Overpass' tag index; bbox and highway are checked here.
    union = "".join(f'way["name"="{n}"];' for osm_names, _ in entries for n in osm_names)
    data = overpass(f"[out:json][timeout:240];({union});out geom;")
    south, west, north, east = map(float, JAVA_BBOX.split(","))
    by_name = {}
    for el in data["elements"]:
        geom = el.get("geometry") or []
        if geom and "highway" in el.get("tags", {}) \
                and south <= geom[0]["lat"] <= north and west <= geom[0]["lon"] <= east:
            by_name.setdefault(el["tags"]["name"], []).append(
                (el["id"], [(g["lat"], g["lon"]) for g in el["geometry"]]))

    sources = []
    for osm_names, name in entries:
        found = [w for n in osm_names for w in by_name.get(n, [])]
        if not found:
            print(f"skip {name}: no OSM ways named {osm_names}")
            continue
        ways = [geom for _, geom in found]
        if probe:
            chain = chain_ways(ways, WAY_JOIN_GAP_M)
            total = sum(track_length(w) for w in ways)
            a, b = chain[0], chain[-1]
            print(f"{track_length(chain) / 1000:6.2f}/{total / 1000:6.2f} km {len(ways):2d}w "
                  f"{a[0]:.3f},{a[1]:.3f} -> {b[0]:.3f},{b[1]:.3f}  {name}")
            continue
        way_ids = sorted(wid for wid, _ in found)
        link = f"https://www.openstreetmap.org/way/{way_ids[0]}"
        source = f'OpenStreetMap ways named "{osm_names[0]}" ({len(way_ids)} ways)'
        filename = build_track(out_dir, name, ways, source, link, WAY_JOIN_GAP_M)
        if filename:
            sources.append(f"| {filename} | way [{way_ids[0]}]({link}) +{len(way_ids) - 1} | {osm_names[0]} |")
    return sources


def main():
    args = sys.argv[1:]
    ways_mode, probe = "--ways" in args, "--probe" in args
    args = [a for a in args if a not in ("--ways", "--probe")]
    out_dir, rest = args[0], args[1:]
    os.makedirs(out_dir, exist_ok=True)

    if ways_mode:
        sources = main_ways(out_dir, set(rest), probe)
    else:
        ids = RELATIONS_2 if rest == ["batch2"] else [int(x) for x in rest] or RELATIONS
        sources = main_relations(out_dir, ids)

    if sources:
        with open(os.path.join(out_dir, "OSM_SOURCES.md"), "a", encoding="utf-8") as f:
            f.write("\n".join(sources) + "\n")


if __name__ == "__main__":
    main()
