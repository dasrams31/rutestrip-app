"""Offline tests for sso_auth. Run: python test_sso_auth.py  (or pytest)."""

import base64
import hashlib
import hmac
import json
import os
import time
import unittest
from unittest import mock

os.environ["RUTESTRIP_SSO_SECRET"] = "test-sso-secret"
os.environ["LARAVEL_URL"] = "http://laravel.test"
import requests  # noqa: E402
import sso_auth  # noqa: E402

SECRET = b"test-sso-secret"


def b64url(data) -> str:
    if not isinstance(data, bytes):
        data = json.dumps(data, separators=(",", ":")).encode()
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()


def make_token(claims=None, header=None, secret=SECRET, alg="sha256"):
    now = int(time.time())
    payload = {"iss": "rutestrip.ai.id", "sub": "12", "name": "Rama", "email": "rama@example.com",
               "role": "user", "locale": "id", "sv": 0, "iat": now, "exp": now + 3600}
    payload.update(claims or {})
    head = b64url(header or {"typ": "JWT", "alg": "HS256"})
    body = b64url(payload)
    sig = hmac.new(secret, f"{head}.{body}".encode(), getattr(hashlib, alg)).digest()
    return f"{head}.{body}.{b64url(sig)}"


class FakeResponse:
    def __init__(self, status, body=None):
        self.status_code = status
        self._body = body

    def json(self):
        if self._body is None:
            raise ValueError("no json")
        return self._body


ME_OK = FakeResponse(200, {"user": {"id": 12, "name": "Rama", "email": "rama@example.com", "role": "user", "locale": "en"}})


class DecodeTokenTest(unittest.TestCase):
    def test_valid(self):
        claims = sso_auth.decode_token(make_token())
        self.assertEqual(claims["sub"], "12")

    def test_numeric_sub_is_normalised(self):
        self.assertEqual(sso_auth.decode_token(make_token({"sub": 7}))["sub"], "7")

    def test_tampered_payload(self):
        head, body, sig = make_token().split(".")
        forged = b64url({"sub": "1", "exp": int(time.time()) + 3600})
        self.assertIsNone(sso_auth.decode_token(f"{head}.{forged}.{sig}"))

    def test_tampered_signature(self):
        token = make_token()
        flipped = token[:-2] + ("A" if token[-2] != "A" else "B") + token[-1]
        self.assertIsNone(sso_auth.decode_token(flipped))

    def test_wrong_secret(self):
        self.assertIsNone(sso_auth.decode_token(make_token(secret=b"other")))

    def test_expired(self):
        self.assertIsNone(sso_auth.decode_token(make_token({"exp": int(time.time()) - 1})))

    def test_expiry_uses_now(self):
        token = make_token({"exp": 2000})
        self.assertIsNotNone(sso_auth.decode_token(token, now=1999))
        self.assertIsNone(sso_auth.decode_token(token, now=2000))

    def test_missing_or_bad_exp(self):
        self.assertIsNone(sso_auth.decode_token(make_token({"exp": None})))
        self.assertIsNone(sso_auth.decode_token(make_token({"exp": "9999999999"})))
        self.assertIsNone(sso_auth.decode_token(make_token({"exp": True})))

    def test_wrong_alg_header(self):
        # Correct HS256 signature, but the header claims another algorithm
        self.assertIsNone(sso_auth.decode_token(make_token(header={"typ": "JWT", "alg": "HS512"})))
        self.assertIsNone(sso_auth.decode_token(make_token(header={"typ": "JWT", "alg": "none"})))
        self.assertIsNone(sso_auth.decode_token(make_token(header={"typ": "JWT"})))

    def test_alg_none_unsigned(self):
        head = b64url({"alg": "none"})
        body = b64url({"sub": "12", "exp": int(time.time()) + 3600})
        self.assertIsNone(sso_auth.decode_token(f"{head}.{body}."))

    def test_hs512_signature_rejected(self):
        self.assertIsNone(sso_auth.decode_token(make_token(header={"alg": "HS512"}, alg="sha512")))

    def test_missing_sub(self):
        self.assertIsNone(sso_auth.decode_token(make_token({"sub": ""})))
        self.assertIsNone(sso_auth.decode_token(make_token({"sub": None})))

    def test_malformed(self):
        for bad in ["", "abc", "a.b", "a.b.c.d", "***.***.***", None, 123, "x" * 5000]:
            self.assertIsNone(sso_auth.decode_token(bad), bad)

    def test_empty_secret_rejects(self):
        self.assertIsNone(sso_auth.decode_token(make_token(), secret=b""))


class AuthenticateTest(unittest.TestCase):
    def setUp(self):
        sso_auth.clear_cache()

    def test_bearer_parsing(self):
        self.assertEqual(sso_auth.bearer_token("Bearer abc"), "abc")
        self.assertEqual(sso_auth.bearer_token("bearer  abc "), "abc")
        self.assertEqual(sso_auth.bearer_token("Basic abc"), "")
        self.assertEqual(sso_auth.bearer_token(None), "")

    def test_confirmed_and_cached(self):
        token = make_token()
        with mock.patch.object(requests, "get", return_value=ME_OK) as get:
            ident = sso_auth.authenticate(f"Bearer {token}")
            self.assertEqual(ident["key"], "u:12")
            self.assertEqual(ident["locale"], "en")  # Laravel's view wins
            sso_auth.authenticate(f"Bearer {token}")
            self.assertEqual(get.call_count, 1)  # second call served from cache
            url = get.call_args[0][0]
            self.assertEqual(url, "http://laravel.test/api/sso/me")
            self.assertEqual(get.call_args[1]["headers"]["Authorization"], f"Bearer {token}")
            self.assertTrue(get.call_args[1]["timeout"] <= 5)

    def test_reconfirms_after_ttl(self):
        token = make_token()
        now = time.time()
        with mock.patch.object(requests, "get", return_value=ME_OK) as get:
            sso_auth.authenticate_token(token, now=now)
            sso_auth.authenticate_token(token, now=now + sso_auth.CONFIRM_TTL + 1)
            self.assertEqual(get.call_count, 2)

    def test_laravel_rejects(self):
        with mock.patch.object(requests, "get", return_value=FakeResponse(401, {"error": "x"})):
            self.assertIsNone(sso_auth.authenticate(f"Bearer {make_token()}"))

    def test_rejected_after_password_change_even_if_cached_before(self):
        token = make_token()
        now = time.time()
        with mock.patch.object(requests, "get", return_value=ME_OK):
            self.assertIsNotNone(sso_auth.authenticate_token(token, now=now))
        with mock.patch.object(requests, "get", return_value=FakeResponse(401, {})):
            self.assertIsNone(sso_auth.authenticate_token(token, now=now + sso_auth.CONFIRM_TTL + 1))
        # and the stale confirmation is gone, so an outage does not revive it
        with mock.patch.object(requests, "get", side_effect=requests.ConnectionError("down")):
            self.assertIsNone(sso_auth.authenticate_token(token, now=now + sso_auth.CONFIRM_TTL + 2))

    def test_outage_without_prior_confirmation(self):
        with mock.patch.object(requests, "get", side_effect=requests.Timeout("slow")):
            self.assertIsNone(sso_auth.authenticate(f"Bearer {make_token()}"))

    def test_outage_with_prior_confirmation(self):
        token = make_token()
        now = time.time()
        with mock.patch.object(requests, "get", return_value=ME_OK):
            sso_auth.authenticate_token(token, now=now)
        with mock.patch.object(requests, "get", side_effect=requests.ConnectionError("down")):
            ident = sso_auth.authenticate_token(token, now=now + sso_auth.CONFIRM_TTL + 5)
            self.assertEqual(ident["key"], "u:12")
        with mock.patch.object(requests, "get", return_value=FakeResponse(503)):
            self.assertIsNotNone(sso_auth.authenticate_token(token, now=now + sso_auth.CONFIRM_TTL + 6))

    def test_outage_does_not_save_expired_token(self):
        token = make_token({"exp": int(time.time()) + 10})
        now = time.time()
        with mock.patch.object(requests, "get", return_value=ME_OK):
            sso_auth.authenticate_token(token, now=now)
        with mock.patch.object(requests, "get", side_effect=requests.ConnectionError("down")):
            self.assertIsNone(sso_auth.authenticate_token(token, now=now + 3600))

    def test_invalid_token_never_reaches_laravel(self):
        with mock.patch.object(requests, "get", return_value=ME_OK) as get:
            self.assertIsNone(sso_auth.authenticate(f"Bearer {make_token(secret=b'x')}"))
            self.assertIsNone(sso_auth.authenticate(f"Bearer {make_token({'exp': 1})}"))
            get.assert_not_called()

    def test_laravel_user_mismatch(self):
        other = FakeResponse(200, {"user": {"id": 99, "name": "X"}})
        with mock.patch.object(requests, "get", return_value=other):
            self.assertIsNone(sso_auth.authenticate(f"Bearer {make_token()}"))


if __name__ == "__main__":
    unittest.main(verbosity=2)
