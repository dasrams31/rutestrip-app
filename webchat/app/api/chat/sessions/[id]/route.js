import { proxyToApi } from '@/lib/apiProxy';

export const dynamic = 'force-dynamic';

export async function GET(request, { params }) {
  const { id } = await params;
  return proxyToApi(request, `/api/chat/sessions/${encodeURIComponent(id)}`);
}

export async function DELETE(request, { params }) {
  const { id } = await params;
  return proxyToApi(request, `/api/chat/sessions/${encodeURIComponent(id)}`, 'DELETE');
}
