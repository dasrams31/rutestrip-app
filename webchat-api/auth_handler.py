import json
import os
import secrets
import time
import hashlib
import uuid
from db_postgres import get_connection

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
USERS_DB_PATH = os.path.join(BASE_DIR, "users_auth.json")
MIN_PASSWORD_LENGTH = 6


def _load_users_json():
    if not os.path.exists(USERS_DB_PATH):
        return {}
    try:
        with open(USERS_DB_PATH, "r", encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return {}


def _save_users_json(data):
    with open(USERS_DB_PATH, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2)


def hash_password(password: str) -> str:
    return hashlib.sha256(password.encode('utf-8')).hexdigest()


def _new_token() -> str:
    return f"rutestrip_{secrets.token_hex(32)}"


def _ensure_token_table(cur):
    cur.execute("""
    CREATE TABLE IF NOT EXISTS auth_tokens (
        token VARCHAR(128) PRIMARY KEY,
        user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)


def _issue_pg_token(cur, user_id: int) -> str:
    _ensure_token_table(cur)
    token = _new_token()
    cur.execute("INSERT INTO auth_tokens (token, user_id) VALUES (%s, %s);", (token, user_id))
    return token


def _pg_user_payload(user_id, username, token=None):
    payload = {
        "user_id": str(user_id),
        "username": username,
        "email": f"{username}@rutestrip.local",
        "full_name": username,
    }
    if token:
        payload["token"] = token
    return payload


def _find_json_user(users, query):
    for uid, uinfo in users.items():
        if uinfo.get("username") == query or uinfo.get("email") == query:
            return uid, uinfo
    return None, None


def register_user(username: str, email: str, password: str, full_name: str = ""):
    username = username.strip().lower()
    email = email.strip().lower()

    if not username or not email or not password:
        return {"status": "error", "message": "Username, email, dan password wajib diisi."}
    if len(password) < MIN_PASSWORD_LENGTH:
        return {"status": "error", "message": f"Password minimal {MIN_PASSWORD_LENGTH} karakter."}

    pwd_hash = hash_password(password)

    # PostgreSQL is the primary store
    try:
        conn = get_connection()
        cur = conn.cursor()
        cur.execute("SELECT 1 FROM users WHERE LOWER(username) = %s;", (username,))
        if cur.fetchone():
            cur.close()
            conn.close()
            return {"status": "error", "message": "Username sudah terdaftar."}

        cur.execute(
            "INSERT INTO users (username, password, role) VALUES (%s, %s, %s) RETURNING id;",
            (username, pwd_hash, "user"),
        )
        user_id = cur.fetchone()[0]
        token = _issue_pg_token(cur, user_id)
        conn.commit()
        cur.close()
        conn.close()
        return {
            "status": "success",
            "message": "Registrasi berhasil!",
            "user": _pg_user_payload(user_id, username, token),
        }
    except Exception as e:
        print(f"[PG ERROR] register_user fallback to JSON: {e}")

    # JSON fallback when PostgreSQL is unavailable
    users = _load_users_json()
    if _find_json_user(users, username)[1] or _find_json_user(users, email)[1]:
        return {"status": "error", "message": "Username sudah terdaftar."}

    user_id = str(uuid.uuid4())
    token = _new_token()
    new_user = {
        "user_id": user_id,
        "username": username,
        "email": email,
        "full_name": full_name or username,
        "password_hash": pwd_hash,
        "created_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "token": token,
    }
    users[user_id] = new_user
    _save_users_json(users)

    return {
        "status": "success",
        "message": "Registrasi berhasil!",
        "user": {
            "user_id": user_id,
            "username": username,
            "email": email,
            "full_name": new_user["full_name"],
            "token": token,
        },
    }


def login_user(username_or_email: str, password: str):
    query = username_or_email.strip().lower()
    pass_hash = hash_password(password)

    try:
        conn = get_connection()
        cur = conn.cursor()
        cur.execute("SELECT id, username, password FROM users WHERE LOWER(username) = %s;", (query,))
        row = cur.fetchone()
        if row:
            if not secrets.compare_digest(row[2], pass_hash):
                cur.close()
                conn.close()
                return {"status": "error", "message": "Username atau password salah."}
            token = _issue_pg_token(cur, row[0])
            conn.commit()
            cur.close()
            conn.close()
            return {
                "status": "success",
                "message": "Login berhasil!",
                "user": _pg_user_payload(row[0], row[1], token),
            }
        cur.close()
        conn.close()
    except Exception as e:
        print(f"[PG ERROR] login_user fallback: {e}")

    users = _load_users_json()
    _, target_user = _find_json_user(users, query)
    if not target_user or not secrets.compare_digest(target_user.get("password_hash", ""), pass_hash):
        return {"status": "error", "message": "Username atau password salah."}

    new_token = _new_token()
    target_user["token"] = new_token
    users[target_user["user_id"]] = target_user
    _save_users_json(users)

    return {
        "status": "success",
        "message": "Login berhasil!",
        "user": {
            "user_id": target_user["user_id"],
            "username": target_user["username"],
            "email": target_user["email"],
            "full_name": target_user["full_name"],
            "token": new_token,
        },
    }


def verify_token(token: str):
    invalid = {"status": "error", "valid": False, "message": "Token tidak valid atau kadaluarsa."}
    if not token or not token.startswith("rutestrip_"):
        return invalid

    try:
        conn = get_connection()
        cur = conn.cursor()
        _ensure_token_table(cur)
        conn.commit()
        cur.execute(
            "SELECT u.id, u.username FROM auth_tokens t JOIN users u ON u.id = t.user_id WHERE t.token = %s;",
            (token,),
        )
        row = cur.fetchone()
        cur.close()
        conn.close()
        if row:
            return {"status": "success", "valid": True, "user": _pg_user_payload(row[0], row[1])}
    except Exception as e:
        print(f"[PG ERROR] verify_token fallback: {e}")

    users = _load_users_json()
    for uinfo in users.values():
        if uinfo.get("token") and secrets.compare_digest(uinfo["token"], token):
            return {
                "status": "success",
                "valid": True,
                "user": {
                    "user_id": uinfo["user_id"],
                    "username": uinfo["username"],
                    "email": uinfo["email"],
                    "full_name": uinfo["full_name"],
                },
            }
    return invalid


def reset_password(username_or_email: str, old_password: str, new_password: str):
    """Change a password. The current password is required; all existing sessions are revoked."""
    query = username_or_email.strip().lower()

    if not old_password or not new_password:
        return {"status": "error", "message": "Password lama dan password baru wajib diisi."}
    if len(new_password) < MIN_PASSWORD_LENGTH:
        return {"status": "error", "message": f"Password baru minimal {MIN_PASSWORD_LENGTH} karakter."}

    old_hash = hash_password(old_password)
    new_hash = hash_password(new_password)

    try:
        conn = get_connection()
        cur = conn.cursor()
        cur.execute("SELECT id, password FROM users WHERE LOWER(username) = %s;", (query,))
        row = cur.fetchone()
        if row:
            if not secrets.compare_digest(row[1], old_hash):
                cur.close()
                conn.close()
                return {"status": "error", "message": "Username atau password lama salah."}
            cur.execute("UPDATE users SET password = %s WHERE id = %s;", (new_hash, row[0]))
            _ensure_token_table(cur)
            cur.execute("DELETE FROM auth_tokens WHERE user_id = %s;", (row[0],))
            conn.commit()
            cur.close()
            conn.close()
            return {"status": "success", "message": "Password berhasil diperbarui. Silakan login ulang."}
        cur.close()
        conn.close()
    except Exception as e:
        print(f"[PG ERROR] reset_password fallback: {e}")

    users = _load_users_json()
    target_id, target_user = _find_json_user(users, query)
    if not target_user or not secrets.compare_digest(target_user.get("password_hash", ""), old_hash):
        return {"status": "error", "message": "Username atau password lama salah."}

    target_user["password_hash"] = new_hash
    target_user["token"] = None
    users[target_id] = target_user
    _save_users_json(users)
    return {"status": "success", "message": "Password berhasil diperbarui. Silakan login ulang."}
