import { proxyToApi } from '@/lib/apiProxy';

export const dynamic = 'force-dynamic';

export function GET(request) {
  return proxyToApi(request, '/api/chat/history');
}

export function DELETE(request) {
  return proxyToApi(request, '/api/chat/history', 'DELETE');
}
