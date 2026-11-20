/**
 * RuteStrip Bot API wrapper
 * All endpoints auto-configured from env or fallback
 */

const BASE =
  (typeof process !== 'undefined' && process.env?.RUTESTRIP_API_BASE && !process.env.RUTESTRIP_API_BASE.includes('cement-affiliation'))
    ? process.env.RUTESTRIP_API_BASE
    : `${process.env.RUTESTRIP_API_BASE || 'http://127.0.0.1:8010'}`;

async function apiFetch(path) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 15000); // 15s timeout
  try {
    const res = await fetch(`${BASE}${path}`, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    });
    clearTimeout(timeout);
    if (!res.ok) throw new Error(`API ${res.status}: ${res.statusText}`);
    return res.json();
  } catch (err) {
    clearTimeout(timeout);
    if (err.name === 'AbortError') throw new Error('API timeout — server tidak merespons dalam 15 detik');
    throw err;
  }
}

// ─── Individual API wrappers ───

export const getRekomendasi = (query) =>
  apiFetch(`/api/rekomendasi?query=${encodeURIComponent(query)}`);

export const getCuaca = (mountain) =>
  apiFetch(`/api/cuaca?mountain=${encodeURIComponent(mountain)}`);

export const getMapUrl = (mountain, mapType = 'satelit') =>
  `${BASE.replace(/\/$/, '')}/api/map/satellite?mountain=${encodeURIComponent(mountain)}&map_type=${encodeURIComponent(mapType)}`;

export const getGpxUrl = (mountain, format = 'gpx') =>
  `${BASE.replace(/\/$/, '')}/api/gpx?mountain=${encodeURIComponent(mountain)}&format=${encodeURIComponent(format)}`;

export const getItinerary = (mountain, mode = '2d1n') =>
  apiFetch(`/api/itinerary?mountain=${encodeURIComponent(mountain)}&mode=${mode}`);

export const getLogistik = (people, days) =>
  apiFetch(`/api/logistik?people=${people}&days=${days}`);

export const getBiaya = (mountain, people, days) =>
  apiFetch(`/api/biaya?mountain=${encodeURIComponent(mountain)}&people=${people}&days=${days}`);

export const getSurvival = (topic) =>
  apiFetch(`/api/survival?topic=${encodeURIComponent(topic)}`);

export const getPorter = (mountain) =>
  apiFetch(`/api/porter?mountain=${encodeURIComponent(mountain)}`);

// ─── API v1.7.0 New Endpoints ───

export const getNewsBulletin = () => apiFetch('/api/news/bulletin');

export const getNewsPortal = () => apiFetch('/api/news/portal');

export const getGetaway = () => apiFetch('/api/getaway');

export const getSurvivalTips = () => apiFetch('/api/survival/tips');

export const getStatsSubscribers = () => apiFetch('/api/stats/subscribers');


// ─── Helpers ───

/** Strip noise words and punctuation to isolate the mountain name */
function extractMountain(text, extraStrip = []) {
  const noise = [
    'gunung', 'mount', 'mt', 'dari', 'di', 'untuk', 'yang', 'ke',
    'lihat', 'tampilkan', 'tunjukkan', 'cari', 'berapa', 'apa',
    'tolong', 'minta', 'kasih', 'info', 'informasi', 'tentang',
    ...extraStrip,
  ];
  const cleanedText = text.replace(/[^a-zA-Z0-9\s]/g, ' ');
  const pattern = new RegExp(`\\b(${noise.join('|')})\\b`, 'gi');
  const result = cleanedText.replace(pattern, '').replace(/\s+/g, ' ').trim();
  return result;
}

/** Extract a number pair like "4 orang 2 hari" or "4 2" */
function extractNumbers(text) {
  const m = text.match(/(\d+)\s*(?:orang|org|people|pax)?\s+(\d+)\s*(?:hari|day|malam|night)?/i);
  if (m) return { people: parseInt(m[1]), days: parseInt(m[2]) };
  // fallback: just two numbers anywhere
  const nums = text.match(/\d+/g);
  if (nums && nums.length >= 2) return { people: parseInt(nums[0]), days: parseInt(nums[1]) };
  return null;
}

// ─── Smart Router ───

/**
 * Detect intent from user message and call the appropriate API.
 * Returns { type, data, ...extras }
 */
export async function smartRoute(message) {
  const msg = message.toLowerCase().trim();
  const t0 = Date.now();

  // ── 1. Cuaca ──
  if (/\b(cuaca|weather|iklim|ramalan|prakiraan|suhu|temperatur)\b/.test(msg)) {
    const rawMtn = extractMountain(msg, ['cuaca', 'weather', 'iklim', 'ramalan', 'prakiraan', 'suhu', 'temperatur', 'hari', 'ini', 'sekarang', 'saat']);
    const mountain = rawMtn || 'merbabu';
    const data = await getCuaca(mountain);
    return { type: 'cuaca', mountain, data, ms: Date.now() - t0 };
  }

  // ── 2. Itinerary ──
  if (/\b(itinerary|jadwal|timeline|naismith|rencana\s*perjalanan)\b/.test(msg)) {
    const mode = /\b(tek.?tok|tektok|1d|day\s?trip)\b/.test(msg) ? 'tektok' : '2d1n';
    const rawMtn = extractMountain(msg, ['itinerary', 'jadwal', 'timeline', 'naismith', 'rencana', 'perjalanan', '2d1n', '3d2n', 'tektok', '1d']);
    const mountain = rawMtn || 'merbabu';
    const data = await getItinerary(mountain, mode);
    return { type: 'itinerary', mountain, mode, data, ms: Date.now() - t0 };
  }

  // ── 3. Biaya (needs mountain) ──
  if (/\b(biaya|budget|harga|cost|estimasi\s*biaya|berapa\s*biaya)\b/.test(msg)) {
    const nums = extractNumbers(msg) || { people: 1, days: 2 };
    const mountain = extractMountain(msg, ['biaya', 'budget', 'harga', 'cost', 'estimasi', 'orang', 'org', 'hari', 'malam', 'pendakian']);
    const cleanMtn = mountain.replace(/\d+/g, '').trim() || 'merbabu';
    const data = await getBiaya(cleanMtn, nums.people, nums.days);
    return { type: 'biaya', mountain: cleanMtn, people: nums.people, days: nums.days, data, ms: Date.now() - t0 };
  }

  // ── 4. Logistik (needs numbers) ──
  if (/\b(logistik|logistic|perlengkapan|persiapan\s*logistik)\b/.test(msg)) {
    const nums = extractNumbers(msg) || { people: 3, days: 2 };
    const data = await getLogistik(nums.people, nums.days);
    return { type: 'logistik', people: nums.people, days: nums.days, data, ms: Date.now() - t0 };
  }

  // ── 5. Survival / P3K ──
  if (/\b(survival|first\s*aid|p3k|darurat|hipotermia|hipotermi|ams|altitude|tersesat|gigitan|patah|dehidrasi|kecelakaan|pertolongan)\b/.test(msg)) {
    const topic = extractMountain(msg, ['survival', 'first', 'aid', 'p3k', 'panduan', 'guide', 'tips', 'cara', 'mengatasi', 'pertolongan', 'pertama']);
    const data = await getSurvival(topic || 'hipotermia');
    return { type: 'survival', topic: topic || 'hipotermia', data, ms: Date.now() - t0 };
  }

  // ── 6. Porter / Basecamp ──
  if (/\b(porter|basecamp|ojek|kontak\s*porter|guide\s*lokal|pemandu)\b/.test(msg)) {
    const rawMtn = extractMountain(msg, ['porter', 'basecamp', 'ojek', 'kontak', 'guide', 'lokal', 'pemandu', 'contact']);
    const mountain = rawMtn || 'merbabu';
    const data = await getPorter(mountain);
    return { type: 'porter', mountain, data, ms: Date.now() - t0 };
  }

  // ── 7. Peta Satelit / Topografi ──
  if (/\b(peta|foto\s*satelit|map|satelit|satellite|topografi|topo)\b/.test(msg)) {
    const mapType = /\b(topo|topografi)\b/.test(msg) ? 'topografi' : 'satelit';
    const rawMtn = extractMountain(msg, ['peta', 'foto', 'map', 'satelit', 'satellite', 'topografi', 'topo', 'gambar', 'image']);
    const mountain = rawMtn || 'merbabu';
    const url = getMapUrl(mountain, mapType);
    return { type: 'map', mountain, mapType, mapUrl: url, data: { map_url: url, mountain, map_type: mapType }, ms: Date.now() - t0 };
  }

  // ── 8. GPX / KML Download ──
  if (/\b(gpx|kml|download\s*track|track\s*file)\b/.test(msg)) {
    const format = msg.includes('kml') ? 'kml' : 'gpx';
    const rawMtn = extractMountain(msg, ['gpx', 'kml', 'track', 'download', 'file']);
    const mountain = rawMtn || 'merbabu';
    const url = getGpxUrl(mountain, format);
    return { type: 'gpx', mountain, format, gpxUrl: url, data: { gpx_url: url, mountain, format }, ms: Date.now() - t0 };
  }

  // ── 9. Pertanyaan Identitas / Tips & Trik Pendakian Bebas / Kata Pendek (Jangan dipaksa rekomendasi gunung) ──
  const generalQuestions = [
    'siapa kamu', 'kamu siapa', 'siapa anda', 'anda siapa', 'siapa dirimu',
    'apa itu rutestrip', 'siapa pembuatmu', 'siapa yang membuatmu', 'fungsi kamu',
    'apa tugasmu', 'kamu bisa apa', 'bisa apa saja', 'apa kelebihanmu',
    'atur nafas', 'bernafas', 'packing', 'carrier', 'tenda', 'sepatu',
    'latihan', 'persiapan fisik', 'kram', 'cedera', 'etika', 'sampah', 'penyakit',
    'saran', 'solusi', 'cara mendaki', 'tips mendaki', 'tips pendakian',
    'halo', 'hallo', 'halloo', 'hai', 'hay', 'hi', 'hello', 'ping', 'p', 'tes', 'test'
  ];
  // Match whole words/phrases only: a plain substring check made short entries
  // like 'p', 'hi' or 'tes' match almost every message ("pemula", "hiking", ...),
  // so SBERT recommendations were almost never used.
  const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const isGeneral = generalQuestions.some(q => new RegExp(`(^|[^a-z0-9])${escapeRe(q)}([^a-z0-9]|$)`).test(msg));
  if (isGeneral || msg.split(/\s+/).length < 2) {
    return { type: 'general', data: null, ms: Date.now() - t0 };
  }

  // ── Default: SBERT Rekomendasi (dengan Ambang Relevansi Ketat >= 25%) ──
  try {
    const data = await getRekomendasi(message);
    if (data?.results && data.results.length > 0) {
      const topScore = data.results[0].similarity_score || 0;
      // Relevansi di bawah 25% dianggap noise/tidak relevan
      if (topScore < 0.25) {
        return { type: 'general', data: null, ms: Date.now() - t0 };
      }
    }
    return { type: 'rekomendasi', data, ms: Date.now() - t0 };
  } catch {
    return { type: 'general', data: null, ms: Date.now() - t0 };
  }
}
