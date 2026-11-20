import { NextResponse } from 'next/server';
import { laravelFetch, laravelError, setSsoCookie, publicUser } from '@/lib/sso';

export const dynamic = 'force-dynamic';

// Creates the account in Laravel (same ALTCHA + honeypot rules as /register).
export async function POST(request) {
  let body = {};
  try { body = await request.json(); } catch {}
  const str = (v) => (typeof v === 'string' ? v : '');
  const result = await laravelFetch(request, '/api/sso/register', {
    method: 'POST',
    body: {
      name: str(body.name).trim(),
      email: str(body.email).trim(),
      password: str(body.password),
      password_confirmation: str(body.password_confirmation),
      altcha: str(body.altcha),
      website: str(body.website),
    },
  });

  const user = publicUser(result.data?.user);
  if (!result.ok || !result.data?.token || !user) return laravelError(request, result);
  return setSsoCookie(NextResponse.json({ user }, { status: 201 }), result.data.token);
}
