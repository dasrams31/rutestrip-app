"""
Hybrid relevance scoring for RuteStrip route search.

The core stays SBERT (paraphrase-multilingual-MiniLM-L12-v2) + cosine similarity.
On top of it:
  1. Document text includes the route name (and description when available), so
     queries that mention a mountain can match it semantically.
  2. Aspect-level matching: every route also has one embedding per narrative
     sentence (distance, duration, grade, elevation, difficulty, suitability).
     A short query such as "jalur landai" is compared with the closest aspect
     instead of only with the whole paragraph.
  3. Attribute-aware boost: intent words (landai/pemula/curam/singkat/panjang)
     are checked against the numeric GPX statistics, and mountain/basecamp names
     in the query are matched against route names.

The PHP side (app/Services/RouteSearchService.php) mirrors hybrid_score(); keep
the weights in sync.
"""
import re

from processor import preprocess_text

W_DOC = 0.4          # cosine(query, whole document)
W_ASPECT = 0.6       # max cosine(query, aspect sentence)
W_ATTR = 0.45        # share of attribute match when an intent is detected
W_NAME = 0.35        # share of name match when a mountain/basecamp is named

INTENT_WORDS = {
    # "landai" = gentle slope; "pemula/mudah" = little climbing and short duration
    # (a short steep hill like Andong is beginner-friendly but not "landai").
    "gentle": {"landai", "datar", "melandai"},
    "easy": {"mudah", "pemula", "santai", "ringan", "keluarga", "aman", "pertama", "awam", "anak"},
    "hard": {"curam", "sulit", "menantang", "ekstrem", "ekstrim", "berat", "terjal", "berpengalaman", "expert", "teknis"},
    "short": {"pendek", "singkat", "cepat", "tektok", "sebentar", "dekat"},
    "long": {"panjang", "lama", "ekspedisi", "jauh", "lintas", "traverse"},
}

NAME_STOPWORDS = {"mt", "mount", "gunung", "via", "to", "jalur", "the", "and", "dan", "semua", "test", "trail", "python"}


def clean_route_name(name: str) -> str:
    name = name or ""
    name = re.sub(r"^\s*(mt\.?|mount)\s+", "Gunung ", name, flags=re.I)
    return name.strip()


def name_tokens(name: str) -> set:
    words = re.findall(r"[a-z]+", (name or "").lower())
    return {w for w in words if len(w) >= 3 and w not in NAME_STOPWORDS}


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


NEGATIONS = {"tidak", "bukan", "jangan", "tanpa", "ga", "gak", "nggak", "enggak", "kurang"}
OPPOSITE = {"gentle": "hard", "easy": "hard", "hard": "gentle", "short": "long", "long": "short"}


def detect_intents(query: str) -> set:
    """Intent words, with a negation in the previous two words flipping the intent
    ("tidak terlalu curam" -> gentle)."""
    words = re.findall(r"[a-z]+", (query or "").lower())
    intents = set()
    for i, word in enumerate(words):
        for intent, vocab in INTENT_WORDS.items():
            if word in vocab:
                negated = any(w in NEGATIONS for w in words[max(0, i - 2):i])
                intents.add(OPPOSITE[intent] if negated else intent)
    if "hard" in intents and intents & {"gentle", "easy"}:
        intents -= {"gentle", "easy", "hard"}
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
VERY_STEEP_GRADE_PCT = 30.0  # too steep to recommend to beginners
MAX_HIKE_KM = 60.0       # longer tracks almost always include road/vehicle segments


def stats_reliable(route: dict) -> bool:
    """GPX without elevation (gain 0) or with vehicle segments (> 60 km) gives
    meaningless grade/duration, so such routes get no attribute boost."""
    gain = float(route.get("elevation_gain_m") or 0)
    dist = float(route.get("distance_km") or 0)
    return gain > 0 and 0 < dist <= MAX_HIKE_KM


def attribute_score(intents: set, route: dict, all_routes: list) -> float:
    """How well the route's GPX statistics fit the intent, as a 0..1 percentile mix.

    "gentle" (landai) is not only a low average grade: a 40 km route can average
    6% and still be a multi-day climb. It combines grade (40%), total elevation
    gain (30%) and Naismith duration (30%); routes averaging >= 15% are halved.
    "easy" (pemula/mudah/santai) weights gain (40%) and duration (40%) over grade
    (20%); routes averaging >= 30% are halved.
    "hard" weights grade 60%, gain 20%, duration 20%.
    """
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
    if "hard" in intents:
        parts.append(0.6 * g + 0.2 * e + 0.2 * d)
    if "short" in intents:
        parts.append(1 - d)
    if "long" in intents:
        parts.append(d)
    return sum(parts) / len(parts)


def mentioned_names(query: str, all_routes: list) -> set:
    vocab = set()
    for r in all_routes:
        vocab |= name_tokens(r.get("name"))
    words = set(re.findall(r"[a-z]+", (query or "").lower()))
    return words & vocab


def _cos(a, b) -> float:
    dot = na = nb = 0.0
    for x, y in zip(a, b):
        dot += x * y
        na += x * x
        nb += y * y
    if na == 0 or nb == 0:
        return 0.0
    return dot / ((na ** 0.5) * (nb ** 0.5))


def hybrid_score(query, q_vec, doc_vec, aspect_vecs, route, all_routes) -> dict:
    cos_doc = _cos(q_vec, doc_vec)
    cos_aspect = max((_cos(q_vec, a) for a in aspect_vecs), default=cos_doc)
    semantic = W_DOC * cos_doc + W_ASPECT * cos_aspect

    score = semantic
    intents = detect_intents(query)
    if intents:
        score = (1 - W_ATTR) * score + W_ATTR * attribute_score(intents, route, all_routes)

    names = mentioned_names(query, all_routes)
    if names:
        hit = 1.0 if names & name_tokens(route.get("name")) else 0.0
        score = (1 - W_NAME) * score + W_NAME * hit

    return {
        "score": max(0.0, min(1.0, score)),
        "cosine_doc": cos_doc,
        "cosine_aspect": cos_aspect,
    }
