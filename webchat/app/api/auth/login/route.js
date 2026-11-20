import { NextResponse } from 'next/server';
import { laravelFetch, laravelError, setSsoCookie, publicUser } from '@/lib/sso';

export const dynamic = 'force-dynamic';

// Email + password login against Laravel; sets the shared rs_sso cookie.
export async function POST(request) {
  let body = {};
  try { body = await request.json(); } catch {}
  const result = await laravelFetch(request, '/api/sso/login', {
    method: 'POST',
    body: {
      email: typeof body.email === 'string' ? body.email.trim() : '',
      password: typeof body.password === 'string' ? body.password : '',
    },
  });

  const user = publicUser(result.data?.user);
  if (!result.ok || !result.data?.token || !user) return laravelError(request, result);
  return setSsoCookie(NextResponse.json({ user }), result.data.token);
}
