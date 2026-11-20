import { NextResponse } from 'next/server';

export const dynamic = 'force-dynamic';

// Proxies a fresh ALTCHA anti-robot challenge from the webchat API (register form).
export async function GET(request) {
  try {
    const forwardedFor = request.headers.get('x-forwarded-for');
    const res = await fetch(`${process.env.RUTESTRIP_API_BASE || 'http://127.0.0.1:8010'}/api/auth/altcha-challenge`, {
      cache: 'no-store',
      headers: forwardedFor ? { 'X-Forwarded-For': forwardedFor } : {},
    });
    const data = await res.json();
    return NextResponse.json(res.ok ? data : { error: data.detail || 'Gagal memuat verifikasi' }, {
      status: res.status,
      headers: { 'Cache-Control': 'no-store' },
    });
  } catch (err) {
    return NextResponse.json(
      { error: 'Gagal terhubung ke server autentikasi' },
      { status: 502, headers: { 'Cache-Control': 'no-store' } }
    );
  }
}
