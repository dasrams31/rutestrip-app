import { NextResponse } from 'next/server';

export async function POST(request) {
  try {
    const body = await request.json();
    const forwardedFor = request.headers.get('x-forwarded-for');
    const res = await fetch(`${process.env.RUTESTRIP_API_BASE || 'http://127.0.0.1:8010'}/api/auth/register`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        // Visitor IP (set by Caddy) for the API's per-IP registration limit
        ...(forwardedFor ? { 'X-Forwarded-For': forwardedFor } : {}),
      },
      body: JSON.stringify(body),
    });

    const data = await res.json();
    if (!res.ok) {
      const detail = typeof data.detail === 'string' ? data.detail : data.detail?.[0]?.msg;
      return NextResponse.json(
        { error: detail || data.message || 'Registrasi gagal' },
        { status: res.status }
      );
    }
    return NextResponse.json(data);
  } catch (err) {
    return NextResponse.json(
      { error: 'Gagal terhubung ke server autentikasi (VPS timeout)' },
      { status: 500 }
    );
  }
}
