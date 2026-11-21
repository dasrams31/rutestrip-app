"""
Route search for RuteStrip: filters first, then a deterministic order.

The core stays SBERT (paraphrase-multilingual-MiniLM-L12-v2) + cosine similarity.

rank_routes() is what the website uses (mirror of
app/Services/RouteSearchService.php::rank(); same words, same keys, same
rounding; tests/fixtures/search_ranking.json is checked by both sides):

  1. Filters (hard). A mountain named in the query ("merbabu", "gunung slamet",
     "sumbing via garung", "puncak upas", "arjuna") keeps only routes of that
     mountain, any of them when several are named. A province or regency
     ("di Jawa Barat", "jabar", "dekat Malang") keeps only routes there, when
     there are any. Place words are never read as mountain names.
  2. Order. With a named mountain, routes matching more of the named words come
     first ("sumbing via garung": Garung, then the other Sumbing routes). Intent
     words decide the order from the GPX statistics (see _sort_key); routes with
     broken statistics go last and the SBERT score only breaks ties. Without an
     intent: hybrid relevance, SBERT cosine on the document (40%) and the closest
     aspect sentence (60%), blended with the region match (40%) and the share of
     route-name words matched (35%).

hybrid_score() is the older single-route score (attribute percentiles blended
into the relevance); kept for callers that score one route at a time.
"""
import math
import re
from collections import Counter

from processor import classify_difficulty, difficulty_score, preprocess_text

W_DOC = 0.4          # cosine(query, whole document)
W_ASPECT = 0.6       # max cosine(query, aspect sentence)
W_ATTR = 0.45        # share of the order position in the shown score when an intent is detected
W_NAME = 0.35        # share of name match when a mountain/basecamp is named

# Base words per intent. Superlatives and "-nya" forms are matched through
# intent_base() ("tercepat", "termudah", "teringan", "landainya"), so they are not listed.
INTENT_WORDS = {
    "gentle": ["landai", "datar", "melandai", "gentle", "gentlest", "flat"],
    "easy": ["mudah", "pemula", "santai", "ringan", "keluarga", "aman", "pertama", "awam", "anak", "gampang",
             "easy", "easiest", "beginner", "beginners"],
    "hard": ["sulit", "menantang", "ekstrem", "ekstrim", "berat", "berpengalaman", "expert", "teknis", "susah", "sukar",
             "hard", "hardest", "difficult", "challenging"],
    "steep": ["curam", "terjal", "steep", "steepest"],
    "short": ["pendek", "singkat", "cepat", "tektok", "sebentar", "cepet", "bentar", "kilat",
              "short", "shortest", "quick", "quickest", "fast", "fastest"],
    "long": ["panjang", "lama", "ekspedisi", "jauh", "lintas", "traverse", "long", "longest"],
}
ALL_INTENT_WORDS = {w for words in INTENT_WORDS.values() for w in words}

# Which intent decides the order when several are asked; the rest only break ties.
INTENT_PRIORITY = ["gentle", "steep", "easy", "hard", "short", "long"]

NAME_STOPWORDS = {"mt", "mount", "gunung", "via", "to", "jalur", "the", "and", "dan", "semua", "test", "trail", "python"}

# Words in front of a mountain name ("Gunung Slamet", "Puncak Upas", "Bukit Sikendil").
MOUNTAIN_PREFIXES = {"gunung", "mt", "mount", "g", "puncak", "bukit", "kawah", "gumuk", "puthuk"}
# Words in route names that are not part of a mountain's name.
MOUNTAIN_GENERIC = {"ke", "loop", "baru", "dua", "besar", "tahura", "alternatif", "trek"}
# Spellings people type -> the spelling used for matching.
MOUNTAIN_ALIASES = {
    "arjuna": "arjuno", "cereme": "ciremai", "ceremai": "ciremai",
    "sundoro": "sindoro", "perahu": "parahu", "krakatoa": "krakatau",
}
# Mountain names that are also everyday words: only count after "gunung", "puncak", ...
MOUNTAIN_AMBIGUOUS = {"anak", "sangar", "kembang", "lembu", "akin", "rante", "kencur", "geulis", "bongkok"}


def clean_route_name(name: str) -> str:
    name = name or ""
    name = re.sub(r"^\s*(mt\.?|mount)\s+", "Gunung ", name, flags=re.I)
    return name.strip()


NEGATIONS = {"tidak", "bukan", "jangan", "tanpa", "ga", "gak", "nggak", "enggak", "kurang"}
OPPOSITE = {"gentle": "steep", "steep": "gentle", "easy": "hard", "hard": "easy", "short": "long", "long": "short"}


def _alias(word: str) -> str:
    return MOUNTAIN_ALIASES.get(word, word)


def name_tokens(name: str) -> set:
    """Words of a route name usable for name matching (mountain and basecamp)."""
    skip = NAME_STOPWORDS | MOUNTAIN_PREFIXES | MOUNTAIN_GENERIC | NEGATIONS | ALL_INTENT_WORDS
    words = (_alias(w) for w in re.findall(r"[a-z]+", (name or "").lower()))
    return {w for w in words if len(w) >= 3 and w not in skip}


def mountain_tokens(name: str) -> list:
    """Mountain words of a route name: the part before "via", without "Gunung", "Puncak", ..."""
    head = re.split(r"\s+via\s+|\(", (name or "").lower())[0]
    skip = NAME_STOPWORDS | MOUNTAIN_PREFIXES | MOUNTAIN_GENERIC
    out = []
    for w in (_alias(w) for w in re.findall(r"[a-z]+", head)):
        if len(w) >= 3 and w not in skip and w not in out:
            out.append(w)
    return out


def split_sentences(text: str) -> list:
    parts = re.split(r"(?<=[.!?])\s+", (text or "").strip())
    return [p.strip() for p in parts if len(p.strip()) > 8]


def build_document(route: dict) -> dict:
    """Return the text to embed for a route and its aspect sentences."""
    name = clean_route_name(route.get("name"))
    narrative = (route.get("narrative_text") or "").strip()
    description = (route.get("description") or "").strip()

    text = f"Jalur pendakian {name}. {narrative}"
    if description:
        text += f" {description}"

    aspects = [f"Jalur pendakian {name}."] + split_sentences(narrative) + split_sentences(description)[:6]
    return {"text": text, "aspects": aspects}


def query_text(query: str) -> str:
    """Query text used for embedding (same preprocessing as the paper)."""
    return preprocess_text(query, remove_stopwords=True)


def intent_base(word: str):
    """The intent word behind a query word, or None: the word itself, without
    "-nya" ("landainya"), and without the superlative "ter-" ("tercepat",
    "termudah"; "teringan" is ter- + ringan)."""
    forms = [word]
    if len(word) > 5 and word.endswith("nya"):
        forms.append(word[:-3])
    for form in list(forms):
        if len(form) > 5 and form.startswith("ter"):
            forms += [form[3:], "r" + form[3:]]
    return next((f for f in forms if f in ALL_INTENT_WORDS), None)


def detect_intents(query: str) -> set:
    """Intent words. A negation in the previous two words flips the intent
    ("tidak terlalu curam" -> gentle, "tidak sulit" -> easy); a mountain name after
    "gunung"/"puncak"/... is not an intent ("Gunung Anak Krakatau"). Opposite
    intents cancel each other."""
    words = re.findall(r"[a-z]+", (query or "").lower())
    intents = set()
    for i, word in enumerate(words):
        if i > 0 and word in MOUNTAIN_AMBIGUOUS and words[i - 1] in MOUNTAIN_PREFIXES:
            continue
        base = intent_base(word)
        for intent, vocab in INTENT_WORDS.items():
            if base is not None and base in vocab:
                negated = any(w in NEGATIONS for w in words[max(0, i - 2):i])
                intents.add(OPPOSITE[intent] if negated else intent)
    if intents & {"hard", "steep"} and intents & {"gentle", "easy"}:
        intents -= {"gentle", "easy", "hard", "steep"}
    if "short" in intents and "long" in intents:
        intents -= {"short", "long"}
    return intents


def _percentile(value: float, values: list) -> float:
    if not values:
        return 0.5
    below = sum(1 for v in values if v < value)
    equal = sum(1 for v in values if v == value)
    return (below + 0.5 * equal) / len(values)


STEEP_GRADE_PCT = 15.0   # narrative: >= 15% is "sangat curam", never "landai"
GENTLE_STEEPEST_PCT = 20.0  # steepest ~1 km stretch still called landai (steepest_grade_pct)
VERY_STEEP_GRADE_PCT = 30.0  # too steep to recommend to beginners (hybrid_score only)
SHORT_HOURS = 4.0        # narrative: under 4 h is not "lama"
MAX_HIKE_KM = 60.0       # longer tracks almost always include road/vehicle segments


def stats_reliable(route: dict) -> bool:
    """GPX without elevation (gain 0) or with vehicle segments (> 60 km) gives
    meaningless grade/duration, so such routes go last for attribute ordering."""
    gain = float(route.get("elevation_gain_m") or 0)
    dist = float(route.get("distance_km") or 0)
    return gain > 0 and 0 < dist <= MAX_HIKE_KM


def attribute_score(intents: set, route: dict, all_routes: list) -> float:
    """hybrid_score() only: how well the route's GPX statistics fit the intent,
    as a 0..1 percentile mix (gentle: grade 40%, gain 30%, duration 30%, halved
    at >= 15%; easy: grade 20%, gain 40%, duration 40%, halved at >= 30%;
    hard/steep: grade 60%, gain 20%, duration 20%)."""
    if not intents:
        return 0.0
    if not stats_reliable(route):
        return 0.0
    grades = [float(r.get("average_grade_pct") or 0) for r in all_routes]
    durations = [float(r.get("naismith_duration_hour") or 0) for r in all_routes]
    gains = [float(r.get("elevation_gain_m") or 0) for r in all_routes]
    grade = float(route.get("average_grade_pct") or 0)
    g = _percentile(grade, grades)
    d = _percentile(float(route.get("naismith_duration_hour") or 0), durations)
    e = _percentile(float(route.get("elevation_gain_m") or 0), gains)
    parts = []
    if "gentle" in intents:
        gentle = 0.4 * (1 - g) + 0.3 * (1 - e) + 0.3 * (1 - d)
        if grade >= STEEP_GRADE_PCT:
            gentle *= 0.5
        parts.append(gentle)
    if "easy" in intents:
        easy = 0.2 * (1 - g) + 0.4 * (1 - e) + 0.4 * (1 - d)
        if grade >= VERY_STEEP_GRADE_PCT:
            easy *= 0.5
        parts.append(easy)
    if intents & {"hard", "steep"}:
        parts.append(0.6 * g + 0.2 * e + 0.2 * d)
    if "short" in intents:
        parts.append(1 - d)
    if "long" in intents:
        parts.append(d)
    return sum(parts) / len(parts)


def _query_words(query: str) -> list:
    return [_alias(w) for w in re.findall(r"[a-z]+", (query or "").lower())]


def mentioned_names(query: str, all_routes: list) -> set:
    vocab = set()
    for r in all_routes:
        vocab |= name_tokens(r.get("name"))
    return set(_query_words(query)) & vocab


def _is_common_word(word: str) -> bool:
    return word in MOUNTAIN_AMBIGUOUS or word in NEGATIONS or word in ALL_INTENT_WORDS


def _mountain_label(tokens: list, routes: list) -> str:
    """"Gunung Merbabu" for ["merbabu"]: the most common name head (the part before
    "via", "Mt." written as "Gunung") among routes with all the words; ties go to
    the shortest."""
    heads = Counter()
    for r in routes:
        if set(tokens) <= set(mountain_tokens(r.get("name"))):
            head = re.split(r"\s+via\s+|\(", (r.get("name") or "").strip(), flags=re.I)[0]
            head = re.sub(r"^(mt\.?|mount|g\.)\s+", "Gunung ", head.strip(), flags=re.I)
            heads[re.sub(r"\s+", " ", head)] += 1
    if not heads:
        return " ".join(tokens).title()
    return sorted(heads, key=lambda h: (-heads[h], len(h), h))[0]


def detect_mountains(query: str, routes: list, exclude=()) -> list:
    """Mountains named in the query, as groups of words that belong to one mountain
    ("gede pangrango", "anak krakatau"): [{"tokens": [...], "label": "Gunung ..."}]."""
    route_tokens = [mountain_tokens(r.get("name")) for r in routes]
    vocab = {w for t in route_tokens for w in t}
    words = _query_words(query)
    groups = []
    last = None
    for i, word in enumerate(words):
        prev = words[i - 1] if i > 0 else ""
        hit = (word in vocab and word not in exclude
               and (not _is_common_word(word) or prev in MOUNTAIN_PREFIXES))
        if not hit:
            last = None
            continue
        together = last is not None and any(last in t and word in t for t in route_tokens)
        if together:
            groups[-1].append(word)
        elif not any(word in g for g in groups):
            groups.append([word])
        last = word
    return [{"tokens": g, "label": _mountain_label(g, routes)} for g in groups]


W_REGION = 0.4       # share of region match when the query names a province/regency

# Province names as stored from OpenStreetMap Nominatim -> words people type.
PROVINCE_ALIASES = {
    "jawa barat": ["jawa barat", "jabar", "west java"],
    "jawa tengah": ["jawa tengah", "jateng", "central java"],
    "jawa timur": ["jawa timur", "jatim", "east java"],
    "daerah istimewa yogyakarta": ["yogyakarta", "jogjakarta", "yogya", "jogja", "diy"],
    "banten": ["banten"],
    "dki jakarta": ["jakarta"],
    "lampung": ["lampung"],
}


def _norm_place(value) -> str:
    value = re.sub(r"^(kabupaten|kota|kab\.?)\s+", "", (value or "").strip().lower())
    return re.sub(r"\s+", " ", value)


def _has_phrase(text: str, phrase: str) -> bool:
    return re.search(r"(?<![a-z])" + re.escape(phrase) + r"(?![a-z])", text) is not None


def detect_regions(query: str, all_routes: list) -> dict:
    """Provinces and regencies named in the query, e.g. "di Jawa Barat",
    "jabar", "dekat Malang". Regencies come from the routes' own locations."""
    text = " " + re.sub(r"[^a-z]+", " ", (query or "").lower()) + " "
    provinces = {p for p, aliases in PROVINCE_ALIASES.items() if any(_has_phrase(text, a) for a in aliases)}
    regencies = set()
    for r in all_routes:
        reg = _norm_place(r.get("regency"))
        if len(reg) >= 4 and _has_phrase(text, reg):
            regencies.add(reg)
    return {"provinces": provinces, "regencies": regencies}


def region_words(regions: dict) -> set:
    words = set()
    for p in regions["provinces"]:
        for alias in PROVINCE_ALIASES[p]:
            words |= set(alias.split())
    for reg in regions["regencies"]:
        words |= set(reg.split())
    return words


def region_hit(regions: dict, route: dict) -> float:
    if _norm_place(route.get("province")) in regions["provinces"]:
        return 1.0
    if _norm_place(route.get("regency")) in regions["regencies"]:
        return 1.0
    return 0.0


def _cos(a, b) -> float:
    dot = na = nb = 0.0
    for x, y in zip(a, b):
        dot += x * y
        na += x * x
        nb += y * y
    if na == 0 or nb == 0:
        return 0.0
    return dot / (math.sqrt(na) * math.sqrt(nb))


def hybrid_score(query, q_vec, doc_vec, aspect_vecs, route, all_routes) -> dict:
    """Older single-route score (no filters). Prefer rank_routes()."""
    cos_doc = _cos(q_vec, doc_vec)
    cos_aspect = max((_cos(q_vec, a) for a in aspect_vecs), default=cos_doc)
    semantic = W_DOC * cos_doc + W_ASPECT * cos_aspect

    score = semantic
    intents = detect_intents(query)
    if intents:
        score = (1 - W_ATTR) * score + W_ATTR * attribute_score(intents, route, all_routes)

    regions = detect_regions(query, all_routes)
    has_regions = (regions["provinces"] or regions["regencies"]) and any(r.get("province") for r in all_routes)
    if has_regions:
        score = (1 - W_REGION) * score + W_REGION * region_hit(regions, route)

    names = mentioned_names(query, all_routes) - region_words(regions)
    if names:
        hit = len(names & name_tokens(route.get("name"))) / len(names)
        score = (1 - W_NAME) * score + W_NAME * hit

    return {
        "score": max(0.0, min(1.0, score)),
        "cosine_doc": cos_doc,
        "cosine_aspect": cos_aspect,
    }


# --- rank_routes(): mirror of RouteSearchService::rank() ---------------------------

def plan(query: str, routes: list, apply_filters: bool = True) -> dict:
    """What the query asks for and which routes are left after the filters."""
    found = detect_intents(query)
    intents = [i for i in INTENT_PRIORITY if i in found]
    regions = detect_regions(query, routes)
    place_words = region_words(regions)
    use_region = bool(regions["provinces"] or regions["regencies"]) and any(r.get("province") for r in routes)
    mountains = detect_mountains(query, routes, place_words)
    tokens = []
    for m in mountains:
        tokens += [t for t in m["tokens"] if t not in tokens]

    candidates = list(routes)
    mountain_filter = region_filter = False
    if apply_filters and tokens:
        candidates = [r for r in candidates if set(tokens) & set(mountain_tokens(r.get("name")))]
        mountain_filter = True
    if apply_filters and use_region:
        in_region = [r for r in candidates if region_hit(regions, r) > 0]
        if in_region:
            candidates = in_region
            region_filter = True

    return {
        "intents": intents,
        "sort": intents[0] if intents else None,
        "also": intents[1:],
        "mountains": mountains,
        "mountain_tokens": tokens,
        "mountain_filter": mountain_filter,
        "regions": regions,
        "use_region": use_region,
        "region_filter": region_filter,
        "names": mentioned_names(query, routes) - place_words,
        "routes": candidates,
    }


def _round_half_up(value: float) -> int:
    return int(math.floor(value + 0.5))


def _max_elevation(route: dict):
    m = re.search(r"hingga (\d+)m", route.get("narrative_text") or "")
    return int(m.group(1)) if m else route.get("max_elevation_m")


def _sort_key(p: dict, route: dict, hit: float, relevance: float) -> tuple:
    """Smaller first; see RouteSearchService::sortKey() for the rules."""
    key = [-hit] if p["mountain_tokens"] else []
    if p["intents"]:
        grade = float(route.get("average_grade_pct") or 0)
        steepest = route.get("steepest_grade_pct")
        steep = float(steepest) if steepest is not None else grade
        s10 = _round_half_up(steep * 10)
        gentle = steep < GENTLE_STEEPEST_PCT and grade < STEEP_GRADE_PCT
        hours = float(route.get("naismith_duration_hour") or 0)
        km = float(route.get("distance_km") or 0)
        gain_m = float(route.get("elevation_gain_m") or 0)
        max_ele = _max_elevation(route)
        g10 = _round_half_up(grade * 10)
        minutes = _round_half_up(hours * 60)
        gain = _round_half_up(gain_m)
        points = _round_half_up(difficulty_score(km, gain_m, grade, max_ele))
        easy = classify_difficulty(km, gain_m, grade, max_ele) in ("mudah", "sedang")
        fits = {
            "gentle": gentle, "steep": not gentle,
            "easy": easy, "hard": not easy,
            "short": hours < SHORT_HOURS, "long": hours >= SHORT_HOURS,
        }
        metrics = {
            "gentle": [s10, g10, gain], "steep": [-s10, -g10, -points],
            "easy": [points, minutes], "hard": [-points, -g10],
            "short": [minutes], "long": [-minutes],
        }
        reliable = stats_reliable(route)
        key.append(0 if reliable else 1)
        key.append(sum(1 for i in p["intents"] if not fits[i]) if reliable else 0)
        for intent in p["intents"]:
            key += metrics[intent] if reliable else [0] * len(metrics[intent])
    key.append(-relevance)
    key.append(route.get("name") or "")
    return tuple(key)


def _vec(vecs, rid, index):
    if isinstance(vecs, dict):
        return vecs.get(rid)
    return vecs[index] if vecs is not None and index < len(vecs) else None


def rank_routes(query, q_vec, routes, doc_vecs, aspect_vecs=None, limit=None, apply_filters=True) -> list:
    """Ordered routes for a query, the same order as the website.

    routes: dicts with id, name, distance_km, elevation_gain_m,
    naismith_duration_hour, average_grade_pct and optionally narrative_text,
    province, regency, max_elevation_m. doc_vecs / aspect_vecs: dicts keyed by
    route id (or lists aligned with routes).

    Returns [{"id", "score" (0..1, never rising down the list), "relevance",
    "cosine_doc", "cosine_aspect"}].
    """
    usable = []
    for i, r in enumerate(routes):
        doc = _vec(doc_vecs, r.get("id"), i)
        if doc:
            aspects = _vec(aspect_vecs, r.get("id"), i) if aspect_vecs is not None else None
            usable.append((r, doc, aspects or []))
    vectors = {id(r): (doc, aspects) for r, doc, aspects in usable}
    p = plan(query, [r for r, _, _ in usable], apply_filters)
    names = p["names"]

    rows = []
    for route in p["routes"]:
        doc, aspects = vectors[id(route)]
        cos_doc = _cos(q_vec, doc)
        cos_aspect = cos_doc
        for a in aspects:
            cos_aspect = max(cos_aspect, _cos(q_vec, a))
        relevance = W_DOC * cos_doc + W_ASPECT * cos_aspect if aspects else cos_doc
        if p["use_region"]:
            relevance = (1 - W_REGION) * relevance + W_REGION * region_hit(p["regions"], route)
        hit = len(names & name_tokens(route.get("name"))) / len(names) if names else 0.0
        if names:
            relevance = (1 - W_NAME) * relevance + W_NAME * hit
        rows.append({
            "id": route.get("id"), "relevance": relevance, "cosine_doc": cos_doc, "cosine_aspect": cos_aspect,
            "_key": _sort_key(p, route, hit, relevance),
        })

    rows.sort(key=lambda row: row["_key"])
    n = len(rows)
    prev = 1.0
    out = []
    for i, row in enumerate(rows):
        score = row["relevance"]
        if p["intents"]:
            fit = 1 - i / (n - 1) if n > 1 else 1.0
            score = (1 - W_ATTR) * score + W_ATTR * fit
        prev = min(prev, max(0.0, min(1.0, score)))
        if prev * 100 < 0.05:   # the website drops routes whose shown score rounds to 0
            continue
        row.pop("_key")
        row["score"] = prev
        out.append(row)
    return out[:limit] if limit else out
