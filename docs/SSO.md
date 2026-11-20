# Single sign-on: rutestrip.ai.id + chat.rutestrip.ai.id

One account store, one login. The Laravel app (rutestrip.ai.id) owns all user
accounts (`users` table, bcrypt). The web chat (Next.js `webchat/` + FastAPI
`webchat-api/`) has no user table of its own any more: it signs users in through
Laravel and recognises them by a shared signed cookie.

## Secrets and settings (env)

| Name | Where | Meaning |
|---|---|---|
| `RUTESTRIP_SSO_SECRET` | Laravel `.env`, `webchat/.env.local`, `webchat-api/.env` | HMAC-SHA256 key for SSO tokens (same value in all three). Laravel falls back to a key derived from `APP_KEY` when empty (tests/dev only). |
| `RUTESTRIP_INTERNAL_SECRET` | Laravel `.env`, `webchat/.env.local` | Shared secret for server-to-server calls from the chat to Laravel. Lets Laravel trust the forwarded client IP. |
| `SSO_COOKIE_DOMAIN` | Laravel `.env`, `webchat/.env.local` | `.rutestrip.ai.id` in production; empty locally (host-only cookie). |
| `SSO_COOKIE_SECURE` | Laravel `.env`, `webchat/.env.local` | `true` in production. |
| `LARAVEL_URL` | `webchat/.env.local`, `webchat-api/.env` | `https://rutestrip.ai.id` in production, `http://127.0.0.1:8000` locally. |
| `WEBCHAT_URL` | Laravel `.env` (already exists) | `https://chat.rutestrip.ai.id`. |

## SSO token

JWT, HS256, signed with `RUTESTRIP_SSO_SECRET`:

```json
{ "iss": "rutestrip.ai.id", "sub": "12", "name": "Rama", "email": "rama@example.com",
  "role": "user", "locale": "id", "sv": 0, "iat": 1795000000, "exp": 1797592000 }
```

- `sub` is the Laravel user id as a string. `sv` is `users.sso_version`; Laravel
  increments it when the password changes, which invalidates every older token.
- Lifetime 30 days.

## Cookies (both unencrypted, readable by both apps)

| Cookie | Value | Attributes |
|---|---|---|
| `rs_sso` | the JWT | Domain=`SSO_COOKIE_DOMAIN`, Path=/, HttpOnly, Secure (prod), SameSite=Lax, Max-Age 30 days |
| `rs_lang` | `id` or `en` | Domain=`SSO_COOKIE_DOMAIN`, Path=/, not HttpOnly, SameSite=Lax, Max-Age 1 year |

Laravel must list both in the `EncryptCookies` exceptions.

- Login or register on either site sets `rs_sso`. Logout on either site deletes
  it (same Domain and Path, so the delete actually matches).
- Laravel middleware (web group): a guest with a valid `rs_sso` is logged in;
  a logged-in session whose `rs_sso` is missing, invalid or for another user is
  logged out ("logged out on the other site").
- Language: `?lang=` (sets the cookie) > `rs_lang` cookie > `users.locale` > `id`.

## Laravel JSON API (stateless, no CSRF, under `/api/sso`)

All responses are JSON. Errors: `{ "error": "<message in the request locale>" }`
plus `errors` (field => [messages]) for validation failures.

| Method + path | Body | Success | Failure |
|---|---|---|---|
| `POST /api/sso/login` | `{email, password}` | 200 `{token, user}` | 401 wrong credentials, 422 validation, 429 throttled (10/min per client IP) |
| `POST /api/sso/register` | `{name, email, password, password_confirmation, altcha, website}` | 201 `{token, user}` | 422 validation or anti-robot failure (same ALTCHA + honeypot rules as `/register`), 429 throttled (5/min per client IP) |
| `GET /api/sso/me` | header `Authorization: Bearer <token>` | 200 `{user}` | 401 invalid, expired, unknown user or stale `sv` |

`user` = `{ id, name, email, role, locale }`.

Client IP for throttling: when the request carries
`X-RuteStrip-Internal: <RUTESTRIP_INTERNAL_SECRET>` (compared in constant time),
Laravel uses `X-RuteStrip-Client-IP` as the client IP; otherwise the normal
request IP. The ALTCHA challenge for the chat's sign-up form comes from Laravel's
`GET /altcha/challenge` (the chat proxies it), so one key verifies it.

Account settings live only on Laravel: `GET /settings` (name, email, password,
language). The chat links there.

## Chat side

- Next API routes call Laravel server-to-server (`LARAVEL_URL`) with the internal
  secret and the visitor's IP, set/delete `rs_sso` themselves, and never expose
  the token to client JavaScript (HttpOnly cookie, no localStorage token).
- Calls from Next to webchat-api pass `Authorization: Bearer <rs_sso>`.
- webchat-api verifies the signature and `exp` locally, then confirms the user
  with Laravel `GET /api/sso/me` (cached 5 minutes per token). The chat identity
  key is `u:<sub>`; chat sessions are stored under that key.
- webchat-api's own register/login/reset-password endpoints are retired.
