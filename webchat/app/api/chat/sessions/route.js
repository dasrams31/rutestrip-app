import { proxyToApi } from '@/lib/apiProxy';

export const dynamic = 'force-dynamic';

export function GET(request) {
  return proxyToApi(request, '/api/chat/sessions');
}

// Delete every session of the signed-in user
export function DELETE(request) {
  return proxyToApi(request, '/api/chat/history', 'DELETE');
}
