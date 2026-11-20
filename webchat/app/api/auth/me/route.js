import { NextResponse } from 'next/server';

export async function GET(request) {
  try {
    const authHeader = request.headers.get('authorization') || '';
    const res = await fetch(`${process.env.RUTESTRIP_API_BASE || 'http://127.0.0.1:8010'}/api/auth/me`, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        Authorization: authHeader,
      },
    });

    const data = await res.json();
    if (!res.ok) {
      return NextResponse.json(
        { error: data.message || 'Sesi tidak valid' },
        { status: res.status }
      );
    }
    return NextResponse.json(data);
  } catch (err) {
    return NextResponse.json(
      { error: 'Gagal verifikasi sesi token' },
      { status: 500 }
    );
  }
}
