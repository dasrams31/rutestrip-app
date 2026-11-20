import { NextResponse } from 'next/server';

const BASE = process.env.RUTESTRIP_API_BASE || 'http://127.0.0.1:8010';

export async function GET(request) {
  const { searchParams } = new URL(request.url);
  const mountain = searchParams.get('mountain') || '';
  const format = searchParams.get('format') || 'gpx';

  try {
    const targetUrl = `${BASE}/api/gpx?mountain=${encodeURIComponent(mountain)}&format=${encodeURIComponent(format)}`;
    const res = await fetch(targetUrl);

    if (!res.ok) {
      return new NextResponse('File not found', { status: res.status });
    }

    const fileBuffer = await res.arrayBuffer();
    const contentType = res.headers.get('content-type') || 'application/gpx+xml';
    const filename = `${mountain || 'track'}.${format}`;

    return new NextResponse(fileBuffer, {
      headers: {
        'Content-Type': contentType,
        'Content-Disposition': `attachment; filename="${filename}"`,
      },
    });
  } catch (err) {
    return new NextResponse('Error fetching GPX file', { status: 500 });
  }
}
