import { NextResponse } from 'next/server';

const BASE = process.env.RUTESTRIP_API_BASE || 'http://127.0.0.1:8010';

export async function GET(request) {
  const { searchParams } = new URL(request.url);
  const mountain = searchParams.get('mountain') || '';
  const mapType = searchParams.get('map_type') || 'satelit';

  try {
    const targetUrl = `${BASE.replace(/\/$/, '')}/api/map/satellite?mountain=${encodeURIComponent(mountain)}&map_type=${encodeURIComponent(mapType)}`;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);
    const res = await fetch(targetUrl, {
      signal: controller.signal,
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'bypass-tunnel-reminder': 'true',
      },
    });
    clearTimeout(timeout);

    if (!res.ok) {
      return new NextResponse('Map not found', { status: res.status });
    }

    const imageBuffer = await res.arrayBuffer();
    const contentType = res.headers.get('content-type') || 'image/png';

    return new NextResponse(imageBuffer, {
      headers: {
        'Content-Type': contentType,
        'Cache-Control': 'public, max-age=86400',
        'Access-Control-Allow-Origin': '*',
      },
    });
  } catch (err) {
    console.error('Proxy map fetch error:', err);
    return new NextResponse('Error fetching map image', { status: 500 });
  }
}
