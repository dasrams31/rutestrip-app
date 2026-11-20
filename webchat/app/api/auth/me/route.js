import { NextResponse } from 'next/server';
import { laravelFetch, laravelError, ssoToken, clearSsoCookie, publicUser, requestLang } from '@/lib/sso';

export const dynamic = 'force-dynamic';

const NOT_SIGNED_IN = { id: 'Belum masuk.', en: 'Not signed in.' };

// Who is signed in, according to the shared rs_sso cookie.
export async function GET(request) {
  const token = ssoToken(request);
  if (!token) {
    return NextResponse.json({ error: NOT_SIGNED_IN[requestLang(request)] }, { status: 401 });
  }
  const result = await laravelFetch(request, '/api/sso/me', { token });
  const user = publicUser(result.data?.user);
  if (result.ok && user) {
    return NextResponse.json({ user }, { headers: { 'Cache-Control': 'no-store' } });
  }
  const res = laravelError(request, result);
  // Invalid, expired or stale token: drop it on both sites (shared cookie).
  if (result.status === 401) clearSsoCookie(res);
  return res;
}
