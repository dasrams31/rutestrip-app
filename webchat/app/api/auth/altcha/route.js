import { NextResponse } from 'next/server';
import { laravelFetch, laravelError } from '@/lib/sso';

export const dynamic = 'force-dynamic';

// ALTCHA challenge for the sign-up form, issued by Laravel so one key verifies it.
export async function GET(request) {
  const result = await laravelFetch(request, '/altcha/challenge');
  const res = result.ok && result.data ? NextResponse.json(result.data) : laravelError(request, result);
  res.headers.set('Cache-Control', 'no-store');
  return res;
}
