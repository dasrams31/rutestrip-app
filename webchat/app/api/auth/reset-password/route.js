import { NextResponse } from 'next/server';

export async function POST(request) {
  try {
    const body = await request.json();
    const res = await fetch(`${process.env.RUTESTRIP_API_BASE || 'http://127.0.0.1:8010'}/api/auth/reset-password`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });

    const data = await res.json();
    if (!res.ok) {
      return NextResponse.json(
        { error: data.detail?.[0]?.msg || data.message || 'Reset password gagal' },
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
