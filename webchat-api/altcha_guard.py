"""Anti-robot checks for webchat registration.

- Self-hosted ALTCHA proof-of-work captcha (https://altcha.org, v1 challenge format):
  the server issues sha256(salt + number) signed with HMAC, the browser widget
  brute-forces the number, the server verifies the solution statelessly.
- Replay protection and per-IP rate limits are kept in process memory. That is
  correct for the single uvicorn worker this service runs with (see
  deploy/rutestrip-webchat-api.service); with several workers/hosts they would
  need a shared store (e.g. Postgres or Redis).
"""

import base64
import binascii
import hashlib
import hmac
import json
import logging
import os
import secrets
import threading
import time
from collections import deque
from urllib.parse import parse_qs

logger = logging.getLogger("rutestrip.altcha")

ERROR_MESSAGE = "Verifikasi anti-robot gagal, silakan coba lagi."
RATE_LIMIT_MESSAGE = "Terlalu banyak percobaan. Silakan tunggu sebentar lalu coba lagi."
ALGORITHM = "SHA-256"
MAX_NUMBER = int(os.environ.get("ALTCHA_MAX_NUMBER", "100000"))
EXPIRES_SECONDS = int(os.environ.get("ALTCHA_EXPIRES", "600"))


def _load_key() -> bytes:
    key = os.environ.get("ALTCHA_HMAC_KEY", "").strip()
    if key:
        return key.encode("utf-8")
    db_secret = os.environ.get("POSTGRES_PASSWORD", "")
    if db_secret:
        logger.warning("ALTCHA_HMAC_KEY is not set; deriving the ALTCHA key from POSTGRES_PASSWORD.")
        return hmac.new(db_secret.encode("utf-8"), b"altcha-hmac-key", hashlib.sha256).digest()
    logger.warning("ALTCHA_HMAC_KEY is not set; using a random per-process key "
                   "(challenges become invalid when the service restarts).")
    return secrets.token_bytes(32)


HMAC_KEY = _load_key()

_lock = threading.Lock()
_used_challenges = {}  # challenge hex -> expires (unix ts)


def create_challenge(max_number: int = None) -> dict:
    max_number = max_number or MAX_NUMBER
    salt = f"{secrets.token_hex(12)}?expires={int(time.time()) + EXPIRES_SECONDS}"
    number = secrets.randbelow(max_number + 1)
    challenge = hashlib.sha256(f"{salt}{number}".encode("utf-8")).hexdigest()
    return {
        "algorithm": ALGORITHM,
        "challenge": challenge,
        "maxnumber": max_number,
        "salt": salt,
        "signature": hmac.new(HMAC_KEY, challenge.encode("utf-8"), hashlib.sha256).hexdigest(),
    }


def _expires_from_salt(salt: str):
    query = salt.split("?", 1)[1] if "?" in salt else ""
    value = parse_qs(query).get("expires", [""])[0]
    return int(value) if value.isdigit() else None


def verify_payload(payload, max_number: int = None) -> bool:
    """Check a base64 ALTCHA payload from the widget and mark it as used."""
    max_number = max_number or MAX_NUMBER
    if not isinstance(payload, str) or not payload or len(payload) > 2048:
        return False
    try:
        data = json.loads(base64.b64decode(payload, validate=True))
    except (binascii.Error, ValueError):
        return False
    if not isinstance(data, dict):
        return False

    challenge = data.get("challenge")
    number = data.get("number")
    salt = data.get("salt")
    signature = data.get("signature")
    if (data.get("algorithm") != ALGORITHM
            or not all(isinstance(v, str) for v in (challenge, salt, signature))
            or not isinstance(number, int) or isinstance(number, bool)
            or not 0 <= number <= max_number):
        return False

    now = int(time.time())
    expires = _expires_from_salt(salt)
    if expires is None or expires < now:
        return False

    expected_signature = hmac.new(HMAC_KEY, challenge.encode("utf-8"), hashlib.sha256).hexdigest()
    expected_challenge = hashlib.sha256(f"{salt}{number}".encode("utf-8")).hexdigest()
    if not (hmac.compare_digest(expected_signature, signature)
            and hmac.compare_digest(expected_challenge, challenge)):
        return False

    with _lock:
        for used, exp in list(_used_challenges.items()):
            if exp < now:
                del _used_challenges[used]
        if challenge in _used_challenges:
            return False
        _used_challenges[challenge] = expires
    return True


class RateLimiter:
    """Sliding-window limit of `limit` hits per `window` seconds per key."""

    def __init__(self, limit: int, window: int):
        self.limit = limit
        self.window = window
        self._hits = {}
        self._lock = threading.Lock()

    def hit(self, key: str) -> bool:
        """Record a hit; return False when the key is over the limit."""
        now = time.monotonic()
        with self._lock:
            hits = self._hits.setdefault(key, deque())
            while hits and hits[0] <= now - self.window:
                hits.popleft()
            if len(hits) >= self.limit:
                return False
            hits.append(now)
            if len(self._hits) > 10000:  # drop idle keys so memory stays bounded
                for k in [k for k, v in self._hits.items() if not v or v[-1] <= now - self.window]:
                    del self._hits[k]
            return True


register_limiter = RateLimiter(limit=5, window=60)
challenge_limiter = RateLimiter(limit=30, window=60)


def client_ip(request) -> str:
    """Client IP. The service only listens on 127.0.0.1 and the webchat proxy forwards
    the visitor IP that Caddy set in X-Forwarded-For. uvicorn's proxy-header handling
    (trusts 127.0.0.1 by default) usually already puts it in request.client; the
    header fallback covers a loopback peer when that handling is off."""
    peer = request.client.host if request.client else ""
    if peer in ("127.0.0.1", "::1", "::ffff:127.0.0.1", "localhost", "testclient"):
        forwarded = request.headers.get("x-forwarded-for", "")
        first = forwarded.split(",")[0].strip()
        if first:
            return first
    return peer or "unknown"
