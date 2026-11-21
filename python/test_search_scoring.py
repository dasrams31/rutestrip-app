"""
Self-check: rank_routes() gives the same order as the website.

tests/fixtures/search_ranking.json holds 18 routes (real statistics) and the
expected order per query; tests/Feature/RouteSearchRankingTest.php checks the
PHP side (RouteSearchService::rank) against the same file.

    python python/test_search_scoring.py      (or: pytest python/test_search_scoring.py)
"""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import search_scoring as ss  # noqa: E402

FIXTURE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "tests", "fixtures", "search_ranking.json")


def _rank(data, query, limit=10, apply_filters=True):
    routes = data["routes"]
    ranked = ss.rank_routes(
        query, data["query_vector"], routes,
        {r["id"]: r["embedding"] for r in routes},
        {r["id"]: r["aspect_embeddings"] for r in routes},
        limit=limit, apply_filters=apply_filters,
    )
    names = {r["id"]: r["name"] for r in routes}
    return [names[row["id"]] for row in ranked], [row["score"] for row in ranked]


def test_same_order_as_php():
    data = json.load(open(FIXTURE, encoding="utf-8"))
    for case in data["cases"]:
        names, scores = _rank(data, case["query"])
        assert names == case["expected"], (case["query"], names)
        assert scores == sorted(scores, reverse=True), case["query"]


def test_rules():
    data = json.load(open(FIXTURE, encoding="utf-8"))
    names, _ = _rank(data, "sumbing via garung")
    assert names[0] == "Gunung Sumbing via Garung" and all("Sumbing" in n for n in names)
    assert sorted(_rank(data, "arjuna")[0]) == ["Gunung Arjuna via Lawang", "Mt. Arjuno via Tretes"]
    assert ss.detect_intents("jalur yang tidak terlalu curam") == {"gentle"}
    assert ss.detect_intents("jalur landai tapi curam") == set()
    assert ss.detect_intents("gunung anak krakatau") == set()
    assert len(_rank(data, "merbabu", 50, apply_filters=False)[0]) == 18
    # hybrid_score() is kept for older callers
    r = data["routes"][0]
    assert 0 <= ss.hybrid_score("jalur landai", data["query_vector"], r["embedding"], r["aspect_embeddings"], r, data["routes"])["score"] <= 1


if __name__ == "__main__":
    test_same_order_as_php()
    test_rules()
    print("search_scoring: same order as RouteSearchService on", FIXTURE)
