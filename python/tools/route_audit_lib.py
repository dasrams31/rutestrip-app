"""
Shared helpers for the RuteStrip route audit tools (audit_summits.py,
complete_to_summit.py, find_new_trails.py, check_gpx_quality.py).

- Overpass API with mirror fallback, exponential backoff, on-disk cache and a
  persisted request counter (CACHE_DIR/api_usage.json).
- Elevation from OpenTopoData srtm30m (<=100 points/request, 1 request/s),
  falling back to Open-Meteo (90 m DEM) when OpenTopoData fails or the daily
  budget is used up. Point elevations are cached per rounded coordinate.
- GPX reading/writing (ODbL attribution in <desc> + <link>), geometry helpers.

Data: (c) OpenStreetMap contributors, ODbL 1.0. Elevation: NASA SRTM via
OpenTopoData / Copernicus DEM via Open-Meteo.
"""
import hashlib
import json
import math
import os
import re
import sys
import time
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from xml.sax.saxutils import escape

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DEFAULT_OUT = os.path.join(ROOT, "python", "output", "route_audit")
CACHE_DIR = os.environ.get("ROUTE_AUDIT_CACHE", os.path.join(DEFAULT_OUT, "cache"))
USER_AGENT = "rutestrip-route-audit/1.0 (+https://rutestrip.ai.id)"
# overpass-api.de is slow but the most reliable; mirrors are tried in between.
OVERPASS_URLS = [
    "https://overpass-api.de/api/interpreter",
    "https://maps.mail.ru/osm/tools/overpass/api/interpreter",
    "https://overpass-api.de/api/interpreter",
    "https://overpass.kumi.systems/api/interpreter",
]
OPENTOPO_URL = "https://api.opentopodata.org/v1/srtm30m"
OPENMETEO_URL = "https://api.open-meteo.com/v1/elevation"
OPENTOPO_DAILY_BUDGET = 900  # public limit is 1000/day; keep a margin

os.makedirs(CACHE_DIR, exist_ok=True)
_USAGE_PATH = os.path.join(CACHE_DIR, "api_usage.json")
_ELE_PATH = os.path.join(CACHE_DIR, "elevation_cache.json")


def _load(path, default):
    try:
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    except (OSError, ValueError):
        return default


USAGE = _load(_USAGE_PATH, {})
_ELE = _load(_ELE_PATH, {})


def _count(api, n=1):
    day = time.strftime("%Y-%m-%d")
    USAGE.setdefault(day, {})
    USAGE[day][api] = USAGE[day].get(api, 0) + n
    USAGE.setdefault("total", {})
    USAGE["total"][api] = USAGE["total"].get(api, 0) + n
    with open(_USAGE_PATH, "w", encoding="utf-8") as f:
        json.dump(USAGE, f, indent=1)


def usage_today(api):
    return USAGE.get(time.strftime("%Y-%m-%d"), {}).get(api, 0)


def _request(url, data=None, timeout=200):
    body = urllib.parse.urlencode(data).encode() if data else None
    req = urllib.request.Request(url, data=body, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.load(r)


# --------------------------------------------------------------------------- Overpass
def overpass(query, attempts=12):
    """Run an Overpass QL query (cached by query text). Returns parsed JSON."""
    key = hashlib.sha1(query.encode()).hexdigest()[:16]
    path = os.path.join(CACHE_DIR, f"overpass_{key}.json")
    cached = _load(path, None)
    if cached is not None:
        return cached
    last = None
    for attempt in range(attempts):
        url = OVERPASS_URLS[attempt % len(OVERPASS_URLS)]
        try:
            _count("overpass_requests")
            data = _request(url, {"data": query})
            if "remark" in data and "runtime error" in data.get("remark", ""):
                raise RuntimeError(data["remark"][:200])
            with open(path, "w", encoding="utf-8") as f:
                json.dump(data, f)
            time.sleep(1.0)
            return data
        except Exception as exc:  # 429/502/504 from overloaded public servers
            last = exc
            _count("overpass_failures")
            wait = min(10 * 2 ** (attempt // len(OVERPASS_URLS)), 60)
            print(f"  overpass retry {attempt + 1} ({url.split('/')[2]}): {str(exc)[:120]}; sleep {wait}s",
                  file=sys.stderr)
            time.sleep(wait)
    raise last


def bbox_str(s, w, n, e):
    return f"{s:.5f},{w:.5f},{n:.5f},{e:.5f}"


# --------------------------------------------------------------------------- Elevation
def _ekey(lat, lon):
    return f"{lat:.5f},{lon:.5f}"


def _save_ele():
    with open(_ELE_PATH, "w", encoding="utf-8") as f:
        json.dump(_ELE, f)


def elevations(points, prefer="opentopo"):
    """DEM elevation for [(lat, lon)]. Cached; OpenTopoData srtm30m first
    (1 request/s, 100 points), Open-Meteo fallback. Returns list of floats."""
    todo = sorted({_ekey(*p) for p in points if _ekey(*p) not in _ELE})
    for i in range(0, len(todo), 100):
        batch = todo[i:i + 100]
        got = None
        if prefer == "opentopo" and usage_today("opentopodata_requests") < OPENTOPO_DAILY_BUDGET:
            for attempt in range(3):
                try:
                    _count("opentopodata_requests")
                    data = _request(f"{OPENTOPO_URL}?locations={urllib.parse.quote('|'.join(batch))}", timeout=60)
                    vals = [r.get("elevation") for r in data["results"]]
                    if all(v is not None for v in vals):
                        got = [(v, "srtm30m") for v in vals]
                    time.sleep(1.1)
                    break
                except Exception as exc:
                    print(f"  opentopodata retry: {exc}", file=sys.stderr)
                    time.sleep(3 + 3 * attempt)
        if got is None:
            lats = ",".join(k.split(",")[0] for k in batch)
            lons = ",".join(k.split(",")[1] for k in batch)
            for attempt in range(4):
                try:
                    _count("openmeteo_requests")
                    data = _request(f"{OPENMETEO_URL}?latitude={lats}&longitude={lons}", timeout=60)
                    got = [(v, "openmeteo90m") for v in data["elevation"]]
                    time.sleep(0.5)
                    break
                except Exception as exc:
                    print(f"  open-meteo retry: {exc}", file=sys.stderr)
                    time.sleep(5 + 5 * attempt)
        if got is None:
            raise RuntimeError("elevation lookup failed")
        for k, (v, src) in zip(batch, got):
            _ELE[k] = [v, src]
        _save_ele()
    return [float(_ELE[_ekey(*p)][0]) for p in points]


def elevation_sources(points):
    return sorted({_ELE[_ekey(*p)][1] for p in points if _ekey(*p) in _ELE})


# --------------------------------------------------------------------------- Geometry
def haversine(a, b):
    lat1, lon1, lat2, lon2 = map(math.radians, (a[0], a[1], b[0], b[1]))
    h = math.sin((lat2 - lat1) / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin((lon2 - lon1) / 2) ** 2
    return 2 * 6371000 * math.asin(min(1.0, math.sqrt(h)))


def track_length(points):
    return sum(haversine(points[i], points[i + 1]) for i in range(len(points) - 1))


def cumdist(points):
    out = [0.0]
    for i in range(1, len(points)):
        out.append(out[-1] + haversine(points[i - 1], points[i]))
    return out


def resample(points, spacing=30.0):
    """Points every `spacing` m along the polyline (keeps first and last)."""
    out = [tuple(points[0][:2])]
    carry = 0.0
    for a, b in zip(points, points[1:]):
        seg = haversine(a, b)
        if seg == 0:
            continue
        pos = spacing - carry
        while pos <= seg:
            t = pos / seg
            out.append((a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t))
            pos += spacing
        carry = (carry + seg) % spacing
    if haversine(out[-1], points[-1]) > 1:
        out.append(tuple(points[-1][:2]))
    return out


def nearest_index(points, target):
    best, bi = float("inf"), 0
    for i, p in enumerate(points):
        d = haversine(p, target)
        if d < best:
            best, bi = d, i
    return bi, best


def point_segment_distance(p, a, b):
    """Approximate distance (m) from p to segment ab using a local equirectangular projection."""
    k = math.cos(math.radians(p[0]))
    ax, ay = (a[1] - p[1]) * k * 111320, (a[0] - p[0]) * 110540
    bx, by = (b[1] - p[1]) * k * 111320, (b[0] - p[0]) * 110540
    dx, dy = bx - ax, by - ay
    L2 = dx * dx + dy * dy
    t = 0.0 if L2 == 0 else max(0.0, min(1.0, -(ax * dx + ay * dy) / L2))
    return math.hypot(ax + t * dx, ay + t * dy)


def min_distance_to_track(points, target):
    best, bi = float("inf"), 0
    for i in range(len(points) - 1):
        d = point_segment_distance(target, points[i], points[i + 1])
        if d < best:
            best, bi = d, i
    if len(points) == 1:
        return haversine(points[0], target), 0
    return best, bi


def buffered_bbox(points, buffer_m):
    lats = [p[0] for p in points]
    lons = [p[1] for p in points]
    dlat = buffer_m / 110540
    dlon = buffer_m / (111320 * math.cos(math.radians(sum(lats) / len(lats))))
    return min(lats) - dlat, min(lons) - dlon, max(lats) + dlat, max(lons) + dlon


# --------------------------------------------------------------------------- GPX
def read_gpx(path):
    """Return [(lat, lon, ele_or_None)] from all trk/rte points of a GPX file."""
    tree = ET.parse(path)
    pts = []
    for el in tree.iter():
        tag = el.tag.rsplit("}", 1)[-1]
        if tag in ("trkpt", "rtept"):
            ele = None
            for c in el:
                if c.tag.rsplit("}", 1)[-1] == "ele" and c.text:
                    try:
                        ele = float(c.text)
                    except ValueError:
                        pass
            pts.append((float(el.get("lat")), float(el.get("lon")), ele))
    if not pts:  # waypoint-only files
        for el in tree.iter():
            if el.tag.rsplit("}", 1)[-1] == "wpt":
                pts.append((float(el.get("lat")), float(el.get("lon")), None))
    return pts


def dedupe(points, min_step=0.5):
    out = [points[0]]
    for p in points[1:]:
        if haversine(out[-1], p) >= min_step:
            out.append(p)
    return out


def write_gpx(path, name, desc, links, points, eles, creator="RuteStrip route audit"):
    """links: [(href, text)]. desc must carry the ODbL attribution."""
    pts = "\n".join(
        f'      <trkpt lat="{lat:.6f}" lon="{lon:.6f}"><ele>{ele:.1f}</ele></trkpt>'
        for (lat, lon), ele in zip(points, eles))
    link_xml = "\n".join(f'    <link href="{escape(h)}"><text>{escape(t)}</text></link>' for h, t in links)
    xml = f"""<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="{escape(creator)}" xmlns="http://www.topografix.com/GPX/1/1">
  <metadata>
    <name>{escape(name)}</name>
    <desc>{escape(desc)}</desc>
{link_xml}
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


def slugify(text):
    text = re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")
    return text


def safe_filename(name):
    return re.sub(r'[\\/:*?"<>|]', "", name).strip()


# --------------------------------------------------------------------------- Names
PREFIXES = r"^(gunung|gn|gng|g|mt|mount|mountain|puncak|pucuk|pk|bukit|puthuk|putuk|gumuk|pasir|curug|kawah|tugu)\b\.?\s*"
ALIASES = {
    "arjuna": "arjuno", "ceremai": "ciremai", "cereme": "ciremai", "cermai": "ciremai",
    "argapura": "argopuro", "lawoe": "lawu", "slamat": "slamet", "sundoro": "sindoro",
    "tangkubanparahu": "tangkuban", "tangkuban": "tangkuban", "prahu": "prau",
    "ngliman": "liman", "kawi": "kawi", "welirang": "welirang", "raung": "raung",
}


def norm_name(name):
    """'Gunung Arjuna' -> 'arjuno'; 'Puncak Kenteng Songo' -> 'kenteng songo'."""
    s = (name or "").lower().replace("-", " ")
    s = re.sub(r"[^a-z0-9 ]+", " ", s)
    prev = None
    while prev != s:
        prev = s
        s = re.sub(PREFIXES, "", s.strip())
    toks = [ALIASES.get(t, t) for t in s.split()]
    return " ".join(toks).strip()


def parse_ele(val):
    if not val:
        return None
    m = re.search(r"-?\d[\d.,]*", str(val))
    if not m:
        return None
    s = m.group(0)
    if re.fullmatch(r"\d{1,2}[.,]\d{3}", s):  # "3.145" / "3,145" thousands separators
        s = s.replace(".", "").replace(",", "")
    s = s.replace(",", ".")
    try:
        v = float(s)
    except ValueError:
        return None
    if "ft" in str(val).lower():
        v *= 0.3048
    return v if 0 < v < 4000 else None
