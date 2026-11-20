"""Self-check for altcha_guard. Run: python test_altcha_guard.py  (or pytest)."""

import base64
import hashlib
import hmac
import json
import os
import time

os.environ.setdefault("ALTCHA_HMAC_KEY", "test-hmac-key")
import altcha_guard  # noqa: E402

MAX = 50


def _solve(challenge, **override):
    number = next(n for n in range(challenge["maxnumber"] + 1)
                  if hashlib.sha256(f"{challenge['salt']}{n}".encode()).hexdigest() == challenge["challenge"])
    data = {k: challenge[k] for k in ("algorithm", "challenge", "salt", "signature")}
    data.update(number=number, took=5)
    data.update(override)
    return base64.b64encode(json.dumps(data).encode()).decode()


def _signed(salt, number, key=b"test-hmac-key"):
    challenge = hashlib.sha256(f"{salt}{number}".encode()).hexdigest()
    return {"algorithm": "SHA-256", "challenge": challenge, "maxnumber": MAX, "salt": salt,
            "signature": hmac.new(key, challenge.encode(), hashlib.sha256).hexdigest()}


def test_valid_payload_passes_once():
    payload = _solve(altcha_guard.create_challenge(MAX))
    assert altcha_guard.verify_payload(payload, MAX) is True
    assert altcha_guard.verify_payload(payload, MAX) is False  # replay


def test_rejects_missing_and_garbage():
    for bad in (None, "", "not base64!", base64.b64encode(b"[1,2]").decode(), "x" * 5000):
        assert altcha_guard.verify_payload(bad, MAX) is False


def test_rejects_wrong_number_algorithm_and_signature():
    ch = altcha_guard.create_challenge(MAX)
    good = json.loads(base64.b64decode(_solve(ch)))
    assert not altcha_guard.verify_payload(_solve(ch, number=(good["number"] + 1) % (MAX + 1)), MAX)
    assert not altcha_guard.verify_payload(_solve(ch, algorithm="SHA-512"), MAX)
    assert not altcha_guard.verify_payload(_solve(ch, number=True), MAX)
    forged = _signed(f"abc?expires={int(time.time()) + 600}", 7, key=b"attacker-key")
    assert not altcha_guard.verify_payload(_solve(forged), MAX)


def test_rejects_expired_and_missing_expiry():
    assert not altcha_guard.verify_payload(_solve(_signed(f"abc?expires={int(time.time()) - 1}", 3)), MAX)
    assert not altcha_guard.verify_payload(_solve(_signed("abc", 3)), MAX)
    assert altcha_guard.verify_payload(_solve(_signed(f"abc?expires={int(time.time()) + 60}", 3)), MAX)


def test_rate_limiter():
    limiter = altcha_guard.RateLimiter(limit=2, window=60)
    assert limiter.hit("1.2.3.4") and limiter.hit("1.2.3.4")
    assert not limiter.hit("1.2.3.4")
    assert limiter.hit("5.6.7.8")


if __name__ == "__main__":
    for name, fn in list(globals().items()):
        if name.startswith("test_") and callable(fn):
            fn()
            print("ok", name)
    print("all altcha_guard checks passed")
