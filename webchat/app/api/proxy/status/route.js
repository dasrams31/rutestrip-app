import { NextResponse } from 'next/server';

export const revalidate = 0; // live status, never cached by Next

/*
 * Live status for /system. Every value here is measured; nothing is estimated
 * or hard-coded (the page shows these to hikers).
 *
 *  - fastapi_api: webchat-api (route data, weather, maps, GPX)
 *  - ai_engine:   the engine that writes chat answers. Hermes when
 *                 HERMES_API_BASE is set (with Gemini as fallback, like
 *                 app/api/chat/route.js), otherwise Gemini alone.
 *  - gemini / hermes_gateway: the individual checks behind ai_engine.
 *
 * The Gemini check lists models (no tokens used) and is cached for 60 s, so many
 * open status pages don't hit Google every 30 s each.
 */

const API_BASE = process.env.RUTESTRIP_API_BASE || 'http://127.0.0.1:8010';
const HERMES_BASE = (process.env.HERMES_API_BASE || process.env.OLLAMA_API_BASE || '').replace(/\/$/, '');
const GEMINI_KEY = process.env.GEMINI_API_KEY || '';
const CHECK_TIMEOUT_MS = 5000;
const GEMINI_CACHE_MS = 60000;

let geminiCache = { at: 0, state: null };

async function ping(url, init = {}) {
  try {
    const res = await fetch(url, { cache: 'no-store', signal: AbortSignal.timeout(CHECK_TIMEOUT_MS), ...init });
    return res.ok ? 'ONLINE' : 'OFFLINE';
  } catch {
    return 'OFFLINE';
  }
}

async function checkGemini() {
  if (!GEMINI_KEY) return 'NOT_CONFIGURED';
  if (geminiCache.state && Date.now() - geminiCache.at < GEMINI_CACHE_MS) return geminiCache.state;
  const state = await ping('https://generativelanguage.googleapis.com/v1beta/models?pageSize=1', {
    headers: { 'x-goog-api-key': GEMINI_KEY },
  });
  geminiCache = { at: Date.now(), state };
  return state;
}

export async function GET() {
  const [fastapi, gemini, hermes] = await Promise.all([
    ping(`${API_BASE}/api/stats/subscribers`, { headers: { Accept: 'application/json' } }),
    checkGemini(),
    HERMES_BASE
      ? ping(`${HERMES_BASE}/v1/models`, {
          headers: process.env.HERMES_API_KEY ? { Authorization: `Bearer ${process.env.HERMES_API_KEY}` } : {},
        })
      : Promise.resolve('NOT_CONFIGURED'),
  ]);

  // The chat can answer when at least one configured engine responds.
  const aiEngine = hermes === 'ONLINE' || gemini === 'ONLINE' ? 'ONLINE' : 'OFFLINE';

  const services = { fastapi_api: fastapi, ai_engine: aiEngine, gemini };
  if (HERMES_BASE) services.hermes_gateway = hermes;

  return NextResponse.json(
    { status: 'success', timestamp: new Date().toISOString(), services },
    { headers: { 'Cache-Control': 'no-store, no-cache, must-revalidate, proxy-revalidate' } },
  );
}
