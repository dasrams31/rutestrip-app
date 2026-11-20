import { NextResponse } from 'next/server';
import { clearSsoCookie } from '@/lib/sso';

export const dynamic = 'force-dynamic';

// Deleting the shared cookie signs the user out of rutestrip.ai.id as well:
// Laravel logs out any session whose rs_sso is missing (docs/SSO.md).
export async function POST() {
  return clearSsoCookie(NextResponse.json({ ok: true }, { headers: { 'Cache-Control': 'no-store' } }));
}
