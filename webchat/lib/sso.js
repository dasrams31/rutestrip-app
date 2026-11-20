/*
 * Server-only helpers for single sign-on with rutestrip.ai.id (docs/SSO.md).
 * Laravel owns the accounts; the chat talks to it server-to-server and keeps
 * the SSO token in the shared HttpOnly `rs_sso` cookie (never in client JS).
 */
import { NextResponse } from 'next/server';

export const SSO_COOKIE = 'rs_sso';
export const LANG_COOKIE = 'rs_lang';
const SSO_MAX_AGE = 30 * 24 * 60 * 60; // 30 days

const LARAVEL_URL = (process.env.LARAVEL_URL || 'https://rutestrip.ai.id').replace(/\/$/, '');
const INTERNAL_SECRET = process.env.RUTESTRIP_INTERNAL_SECRET || '';
const LARAVEL_TIMEOUT_MS = 10000;

export const API_BASE = process.env.RUTESTRIP_API_BASE || 'http://127.0.0.1:8010';

export function cookieDomain() {
  return (process.env.SSO_COOKIE_DOMAIN || '').trim() || undefined;
}

export function cookieSecure() {
  return /^(1|true|yes|on)$/i.test(process.env.SSO_COOKIE_SECURE || '');
}

export function requestLang(request) {
  return request.cookies.get(LANG_COOKIE)?.value === 'en' ? 'en' : 'id';
}

/** Visitor IP: first X-Forwarded-For hop (set by Caddy), then X-Real-IP. */
export function clientIp(request) {
  const fwd = request.headers.get('x-forwarded-for');
  const first = fwd ? fwd.split(',')[0].trim() : '';
  return first || request.headers.get('x-real-ip')?.trim() || '';
}

export function ssoToken(request) {
  return request.cookies.get(SSO_COOKIE)?.value || '';
}

/** Authorization header for webchat-api calls, from the rs_sso cookie. */
export function apiAuthHeaders(request) {
  const token = ssoToken(request);
  return token ? { Authorization: `Bearer ${token}` } : {};
}

function cookieOptions(maxAge) {
  return {
    httpOnly: true,
    secure: cookieSecure(),
    sameSite: 'lax',
    path: '/',
    domain: cookieDomain(),
    maxAge,
  };
}

export function setSsoCookie(response, token) {
  response.cookies.set(SSO_COOKIE, token, cookieOptions(SSO_MAX_AGE));
  return response;
}

/** Delete rs_sso with the same Domain and Path it was set with. */
export function clearSsoCookie(response) {
  response.cookies.set(SSO_COOKIE, '', { ...cookieOptions(0), expires: new Date(0) });
  return response;
}

/**
 * Call the Laravel SSO API. Resolves to { ok, status, data }; status 0 means
 * Laravel could not be reached.
 */
export async function laravelFetch(request, path, { method = 'GET', body, token } = {}) {
  const lang = requestLang(request);
  const headers = {
    Accept: 'application/json',
    'Accept-Language': lang,
    // Laravel picks the message language from rs_lang (docs/SSO.md)
    Cookie: `${LANG_COOKIE}=${lang}`,
  };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = `Bearer ${token}`;
  if (INTERNAL_SECRET) {
    headers['X-RuteStrip-Internal'] = INTERNAL_SECRET;
    const ip = clientIp(request);
    if (ip) headers['X-RuteStrip-Client-IP'] = ip;
  }

  try {
    const res = await fetch(`${LARAVEL_URL}${path}`, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      cache: 'no-store',
      redirect: 'manual',
      signal: AbortSignal.timeout(LARAVEL_TIMEOUT_MS),
    });
    let data = null;
    try { data = await res.json(); } catch { data = null; }
    return { ok: res.ok, status: res.status, data };
  } catch (err) {
    console.error(`Laravel ${method} ${path} failed:`, err?.message || err);
    return { ok: false, status: 0, data: null };
  }
}

const UNREACHABLE = {
  id: 'Server akun RuteStrip sedang tidak dapat dihubungi. Coba lagi sebentar lagi.',
  en: 'The RuteStrip account server cannot be reached right now. Please try again shortly.',
};
const FAILED = {
  id: 'Permintaan gagal. Silakan coba lagi.',
  en: 'The request failed. Please try again.',
};

/** JSON error response that passes Laravel's message (and field errors) through. */
export function laravelError(request, result) {
  const lang = requestLang(request);
  if (result.status === 0) {
    return NextResponse.json({ error: UNREACHABLE[lang] }, { status: 503 });
  }
  const data = result.data && typeof result.data === 'object' ? result.data : {};
  const firstFieldError = data.errors && typeof data.errors === 'object'
    ? Object.values(data.errors).flat().find((m) => typeof m === 'string')
    : null;
  const error = (typeof data.error === 'string' && data.error)
    || (typeof data.message === 'string' && data.message)
    || firstFieldError
    || FAILED[lang];
  const status = result.status >= 400 && result.status < 600 ? result.status : 502;
  return NextResponse.json(
    { error, ...(data.errors && typeof data.errors === 'object' ? { errors: data.errors } : {}) },
    { status }
  );
}

/** Only the fields the browser needs. */
export function publicUser(user) {
  if (!user || typeof user !== 'object') return null;
  return {
    id: user.id,
    name: user.name || '',
    email: user.email || '',
    role: user.role || 'user',
    locale: user.locale === 'en' ? 'en' : 'id',
  };
}
