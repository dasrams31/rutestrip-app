"""
Build GPX files for Java hiking routes from OpenStreetMap.

Source: OpenStreetMap hiking route relations (route=hiking), fetched with the
Overpass API. Elevation is added from the SRTM 30 m DEM via the public
OpenTopoData API, because OSM geometry has no elevation.

Data licence: (c) OpenStreetMap contributors, ODbL 1.0
(https://www.openstreetmap.org/copyright). Elevation: NASA SRTM via
OpenTopoData. Keep the attribution when redistributing the generated files.

Usage:
    python osm_hiking_to_gpx.py OUTPUT_DIR [relation_id ...]

Without relation ids the curated RELATIONS list below is used.
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
OVERPASS_URL = "https://overpass-api.de/api/interpreter"
ELEVATION_URL = "https://api.opentopodata.org/v1/srtm30m"
SAMPLE_SPACING_M = 30
MAX_JOIN_GAP_M = 60

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


def http_post(url, data, timeout=300):
    req = urllib.request.Request(url, data=urllib.parse.urlencode(data).encode(),
                                 headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.load(r)


def http_get(url, timeout=60):
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.load(r)


def haversine(a, b):
    lat1, lon1, lat2, lon2 = map(math.radians, (a[0], a[1], b[0], b[1]))
    h = math.sin((lat2 - lat1) / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin((lon2 - lon1) / 2) ** 2
    return 2 * 6371000 * math.asin(math.sqrt(h))


def chain_ways(ways):
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
                    if at_end and haversine(chain[-1], cand[0]) <= MAX_JOIN_GAP_M:
                        chain += cand[1:]
                    elif not at_end and haversine(cand[-1], chain[0]) <= MAX_JOIN_GAP_M:
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


def write_gpx(path, name, rel_id, points, eles):
    pts = "\n".join(
        f'      <trkpt lat="{lat:.6f}" lon="{lon:.6f}"><ele>{ele:.1f}</ele></trkpt>'
        for (lat, lon), ele in zip(points, eles)
    )
    xml = f"""<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="RuteStrip osm_hiking_to_gpx.py" xmlns="http://www.topografix.com/GPX/1/1">
  <metadata>
    <name>{escape(name)}</name>
    <desc>Track: OpenStreetMap relation {rel_id}, (c) OpenStreetMap contributors, ODbL 1.0. Elevation: SRTM 30 m via OpenTopoData.</desc>
    <link href="https://www.openstreetmap.org/relation/{rel_id}"><text>OpenStreetMap relation {rel_id}</text></link>
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


def main():
    out_dir = sys.argv[1]
    ids = [int(x) for x in sys.argv[2:]] or RELATIONS
    os.makedirs(out_dir, exist_ok=True)

    query = f"[out:json][timeout:240];rel(id:{','.join(map(str, ids))});out geom;"
    data = http_post(OVERPASS_URL, {"data": query})
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

        track = chain_ways(ways)
        points = resample(track, SAMPLE_SPACING_M)
        eles = elevations(points)
        if any(e is None for e in eles):
            print(f"skip {rel_id} {name}: missing elevation")
            continue
        if eles[0] > eles[-1]:  # orient trailhead -> summit
            points, eles = points[::-1], eles[::-1]

        km = sum(haversine(points[i], points[i + 1]) for i in range(len(points) - 1)) / 1000
        filename = re.sub(r'[\\/:*?"<>|]', "", name) + ".gpx"
        write_gpx(os.path.join(out_dir, filename), name, rel_id, points, eles)
        sources.append((filename, rel_id, raw_name))
        print(f"ok   {rel_id} {name}: {km:.1f} km, {min(eles):.0f}-{max(eles):.0f} m, {len(points)} pts")

    with open(os.path.join(out_dir, "OSM_SOURCES.md"), "a", encoding="utf-8") as f:
        for filename, rel_id, raw_name in sources:
            f.write(f"| {filename} | [{rel_id}](https://www.openstreetmap.org/relation/{rel_id}) | {raw_name} |\n")


if __name__ == "__main__":
    main()
