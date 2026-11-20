"""SSO for the webchat API (see docs/SSO.md).

Accounts live in Laravel (rutestrip.ai.id). The chat sends the shared `rs_sso`
JWT as `Authorization: Bearer <token>`. A token is accepted when:

1. it is a well-formed HS256 JWT signed with RUTESTRIP_SSO_SECRET (checked
   locally, constant-time) and not expired, and
2. Laravel confirms it via GET {LARAVEL_URL}/api/sso/me (catches deleted users
   and tokens made stale by a password change). Confirmations are cached in
   memory for CONFIRM_TTL seconds per token.

If Laravel cannot be reached (timeout, connection error, 5xx), a token that
Laravel already confirmed earlier keeps working on the local check alone until
it expires; a token never confirmed is refused.

The chat identity key is "u:<sub>"; chat sessions are stored under it.
The cache is per process, which matches the single uvicorn worker this service
runs with (deploy/rutestrip-webchat-api.service).
"""

import base64
import binascii
import hashlib
import hmac
import json
import logging
import os
import threading
import time

import requests

logger = logging.getLogger("rutestrip.sso")

CONFIRM_TTL = 300          # seconds a Laravel confirmation is trusted
CONFIRM_TIMEOUT = float(os.environ.get("SSO_CONFIRM_TIMEOUT", "3"))
MAX_CACHE_ENTRIES = 5000
MAX_TOKEN_LENGTH = 4096


def _secret() -> bytes:
    return os.environ.get("RUTESTRIP_SSO_SECRET", "").encode("utf-8")


def _laravel_url() -> str:
    return os.environ.get("LARAVEL_URL", "").strip().rstrip("/")


def _b64url_decode(part: str) -> bytes:
    if not part or not all(c.isalnum() or c in "-_" for c in part):
        raise ValueError("not base64url")
    return base64.urlsafe_b64decode(part + "=" * (-len(part) % 4))


def decode_token(token, secret: bytes = None, now: float = None):
    """Verify signature, algorithm and expiry. Returns the claims dict or None."""
    secret = _secret() if secret is None else secret
    if not secret or not isinstance(token, str) or not token or len(token) > MAX_TOKEN_LENGTH:
        return None
    parts = token.split(".")
    if len(parts) != 3:
        return None
    header_b64, payload_b64, sig_b64 = parts
    try:
        header = json.loads(_b64url_decode(header_b64))
        signature = _b64url_decode(sig_b64)
    except (ValueError, binascii.Error, UnicodeDecodeError):
        return None
    # Only HS256: rejects "none", RS256/HS512 confusion and missing headers.
    if not isinstance(header, dict) or header.get("alg") != "HS256":
        return None

    expected = hmac.new(secret, f"{header_b64}.{payload_b64}".encode("ascii"), hashlib.sha256).digest()
    if not hmac.compare_digest(expected, signature):
        return None

    try:
        claims = json.loads(_b64url_decode(payload_b64))
    except (ValueError, binascii.Error, UnicodeDecodeError):
        return None
    if not isinstance(claims, dict):
        return None

    exp = claims.get("exp")
    if not isinstance(exp, (int, float)) or isinstance(exp, bool):
        return None
    if exp <= (time.time() if now is None else now):
        return None

    sub = claims.get("sub")
    if isinstance(sub, int) and not isinstance(sub, bool):
        sub = str(sub)
    if not isinstance(sub, str) or not sub.strip():
        return None
    claims["sub"] = sub.strip()
    return claims


# token sha256 -> {"user": dict, "confirmed_at": float, "exp": float}
_cache = {}
_lock = threading.Lock()
_warned_no_laravel = False


def _cache_key(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


def _prune(now: float):
    for key in [k for k, v in _cache.items() if v["exp"] <= now]:
        del _cache[key]
    if len(_cache) > MAX_CACHE_ENTRIES:
        oldest = sorted(_cache.items(), key=lambda kv: kv[1]["confirmed_at"])
        for key, _ in oldest[: len(_cache) - MAX_CACHE_ENTRIES]:
            del _cache[key]


def clear_cache():
    with _lock:
        _cache.clear()


def _identity(claims: dict, user: dict = None) -> dict:
    user = user or {}
    sub = claims["sub"]
    return {
        "key": f"u:{sub}",
        "id": sub,
        "name": user.get("name") or claims.get("name") or "",
        "email": user.get("email") or claims.get("email") or "",
        "role": user.get("role") or claims.get("role") or "user",
        "locale": user.get("locale") or claims.get("locale") or "id",
    }


def _confirm_with_laravel(token: str):
    """Returns ("ok", user) | ("invalid", None) | ("unavailable", None)."""
    global _warned_no_laravel
    base = _laravel_url()
    if not base:
        if not _warned_no_laravel:
            logger.warning("LARAVEL_URL is not set; SSO tokens are only checked locally.")
            _warned_no_laravel = True
        return "skip", None
    try:
        res = requests.get(
            f"{base}/api/sso/me",
            headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
            timeout=CONFIRM_TIMEOUT,
        )
    except requests.RequestException as exc:
        logger.warning("Laravel /api/sso/me unreachable: %s", exc)
        return "unavailable", None
    if res.status_code == 200:
        try:
            user = res.json().get("user")
        except ValueError:
            return "unavailable", None
        return ("ok", user) if isinstance(user, dict) else ("unavailable", None)
    if res.status_code in (401, 403, 404, 422):
        return "invalid", None
    logger.warning("Laravel /api/sso/me returned HTTP %s", res.status_code)
    return "unavailable", None


def authenticate_token(token: str, now: float = None):
    """Identity dict for a valid, Laravel-confirmed token, else None."""
    claims = decode_token(token, now=now)
    if not claims:
        return None
    now = time.time() if now is None else now
    key = _cache_key(token)

    with _lock:
        _prune(now)
        entry = _cache.get(key)
    if entry and now - entry["confirmed_at"] < CONFIRM_TTL:
        return _identity(claims, entry["user"])

    status, user = _confirm_with_laravel(token)
    if status == "skip":
        return _identity(claims)
    if status == "ok":
        if str(user.get("id")) != claims["sub"]:
            logger.warning("Laravel confirmed a different user than the token subject")
            return None
        with _lock:
            _cache[key] = {"user": user, "confirmed_at": now, "exp": float(claims["exp"])}
        return _identity(claims, user)
    if status == "invalid":
        with _lock:
            _cache.pop(key, None)
        return None
    # Laravel unavailable: only tokens it confirmed before keep working.
    if entry:
        return _identity(claims, entry["user"])
    return None


def bearer_token(authorization) -> str:
    if not authorization or not isinstance(authorization, str):
        return ""
    scheme, _, value = authorization.strip().partition(" ")
    return value.strip() if scheme.lower() == "bearer" else ""


def authenticate(authorization):
    """Identity for an `Authorization: Bearer <rs_sso>` header value, else None."""
    token = bearer_token(authorization)
    return authenticate_token(token) if token else None
