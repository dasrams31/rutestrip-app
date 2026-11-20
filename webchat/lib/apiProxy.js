/*
 * Server-only proxy from the Next API routes to webchat-api. The SSO token is
 * read from the rs_sso cookie and sent as `Authorization: Bearer`.
 */
import { NextResponse } from 'next/server';
import { API_BASE, apiAuthHeaders, requestLang } from '@/lib/sso';

const UNREACHABLE = {
  id: 'Server riwayat chat tidak dapat dihubungi. Coba lagi sebentar lagi.',
  en: 'The chat history server cannot be reached. Please try again shortly.',
};

export async function proxyToApi(request, path, method = 'GET') {
  const auth = apiAuthHeaders(request);
  if (!auth.Authorization) {
    return NextResponse.json({ error: requestLang(request) === 'en' ? 'Not signed in.' : 'Belum masuk.' }, { status: 401 });
  }
  try {
    const res = await fetch(`${API_BASE}${path}`, {
      method,
      headers: { Accept: 'application/json', ...auth },
      cache: 'no-store',
      signal: AbortSignal.timeout(15000),
    });
    let data = {};
    try { data = await res.json(); } catch {}
    if (!res.ok) {
      const detail = typeof data.detail === 'string' ? data.detail : data.message || data.error;
      return NextResponse.json({ error: detail || `HTTP ${res.status}` }, { status: res.status });
    }
    return NextResponse.json(data, { headers: { 'Cache-Control': 'no-store' } });
  } catch {
    return NextResponse.json({ error: UNREACHABLE[requestLang(request)] }, { status: 502 });
  }
}
