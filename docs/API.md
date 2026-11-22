# Mobile API (`/api/v1`)

JSON API for the RuteStrip Android app. Stateless: no session, no CSRF, no cookies.

- Base URL: `https://rutestrip.ai.id/api/v1`
- Language: `Accept-Language: id` (default) or `en`. Labels, sentences and
  validation messages follow it.
- Signed-in user: `Authorization: Bearer <token>` with the token from the SSO API
  (`POST /api/sso/login`, `POST /api/sso/register`, `GET /api/sso/me`, see `docs/SSO.md`).
- Errors: `{"error": "..."}` (401, 503), Laravel's `{"message": "..."}` (404, 429) or
  Laravel validation JSON (422).
- Rate limits per client IP: reads 60/min, search 20/min, like 30/min (429 with `Retry-After`).

Code: `app/Http/Controllers/Api/V1/*`, `app/Services/RouteApiPresenter.php`,
tests in `tests/Feature/MobileApiTest.php`.

## Route summary (used in every list)

```json
{
  "id": 21, "name": "Mt. Merbabu via Selo", "mountain": "Gunung Merbabu",
  "basecamp": "Basecamp Selo", "province": "Jawa Tengah", "regency": "Boyolali",
  "distance_km": 11.1, "elevation_gain_m": 1424, "naismith_duration_hour": 5.9,
  "duration_label": "Waktu ke puncak", "summit_route": true,
  "average_grade_pct": 12.8, "steepest_grade_pct": 31.4, "max_elevation_m": 3128,
  "difficulty_level": "Sulit", "difficulty_label": "Sulit", "likes_count": 3,
  "track": [[-7.45, 110.43], ...],
  "web_url": "https://rutestrip.ai.id/routes/21-mt-merbabu-via-selo"
}
```

`difficulty_level` is the stored Indonesian key (Mudah, Sedang, Sulit, Sangat Sulit);
`difficulty_label` is translated. `track` has at most 60 points.

## Endpoints

| Method | Path | Notes |
|---|---|---|
| GET | `/routes?page=&per_page=&mountain=&province=` | Paged list by name (`per_page` 1 to 50, default 20). `mountain` and `province` match the names from `/filters`. Returns `data` and `meta` (`current_page`, `last_page`, `per_page`, `total`). |
| GET | `/filters` | `mountains` and `provinces` as `{name, count}`, `examples` (the website's example searches), `total_routes`. |
| GET | `/routes/{id}` | Summary plus `description`, `narrative_text`, `difficulty_hint`, `naismith_note`, `coordinates` (up to 400 `[lat, lng]`), `basecamp_point` / `summit_point` (`name`, `lat`, `lng`, `elevation_m`), `elevation_source`, `elevation_profile` (`[[km, m], ...]` or null), `liked` (with a token) and `similar` (4 closest routes with `similarity_score`). |
| POST | `/search` | `{query: 3..500 chars, all_routes?: bool}`. Same ranking as the website: SBERT query embedding plus `RouteSearchService::rank` and `plan`. Returns up to 10 `results` (summary plus `similarity_score`, `match_label`, `why`), `plan` (`intents`, `sort`, `also`, `mountains`, `mountain_filter`, `region_labels`, `region_filter`, `all_routes`), `explanation` (`filter_line`, `sort_line`, `also_line`, `notice`, `can_search_all`, `search_all_label`) and `search_time_ms`. 503 when the model service is down. A signed-in search is saved to the user's history. |
| POST | `/routes/{id}/like` | Token required. `{liked?: bool}` sets the state; without it the like is toggled. Returns `{liked, count}`. |
| GET | `/likes` | Token required. The user's liked routes, newest like first. |
