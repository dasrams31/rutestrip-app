export const maxDuration = 60; // Max duration for Vercel Serverless Function (60 seconds)

import { NextResponse } from 'next/server';
import { GoogleGenerativeAI } from '@google/generative-ai';
import { smartRoute } from '@/lib/api';
import { extractRoutes } from '@/lib/formatter';

const BASE = (process.env.RUTESTRIP_API_BASE && !process.env.RUTESTRIP_API_BASE.includes('cement-affiliation'))
  ? process.env.RUTESTRIP_API_BASE
  : 'http://127.0.0.1:8010';
const GEMINI_KEY   = process.env.GEMINI_API_KEY;
const GEMINI_MODEL = process.env.GEMINI_MODEL || 'gemini-3.6-flash';
const HERMES_BASE  = process.env.HERMES_API_BASE || process.env.OLLAMA_API_BASE || '';
const HERMES_MODEL = process.env.HERMES_MODEL || process.env.OLLAMA_MODEL || 'ag/gemini-3.6-flash-medium';
const HERMES_KEY   = process.env.HERMES_API_KEY || '';

/* ─────────────────────────────────────────────
   JAILBREAK & PERSONA MANIPULATION DETECTOR
───────────────────────────────────────────── */
function detectJailbreak(message) {
  const msg = message.toLowerCase();

  const jailbreakKw = [
    'ignore all previous', 'ignore previous', 'abaikan semua instruksi', 'abaikan instruksi',
    'you are now', 'kamu sekarang adalah', 'act as', 'bertindaklah sebagai',
    'cyberai', 'dan mode', 'jailbreak', 'unrestricted mode', 'override rules',
    'developer mode', 'dev mode', 'system prompt', 'tampilkan system prompt',
    'bocorkan prompt', 'forget your rules', 'forget all rules', 'bypass rules',
    'pretend to be', 'pretend you are', 'simulasi tanpa batas'
  ];

  return jailbreakKw.some(kw => msg.includes(kw));
}

/* ─────────────────────────────────────────────
   INTENT DETECTOR
   Returns: { type, isHiking }
───────────────────────────────────────────── */
function detectIntent(message) {
  const msg = message.toLowerCase().trim();

  // Jailbreak check
  if (detectJailbreak(message)) {
    return { type: 'jailbreak', isHiking: false };
  }

  // Greetings & typos
  const greetings = [
    'halo', 'hallo', 'halloo', 'haloo', 'hai', 'hay', 'hi', 'hii', 'hello', 'helo',
    'selamat pagi', 'selamat siang', 'selamat sore', 'selamat malam', 'apa kabar',
    'pagi', 'siang', 'sore', 'malam', 'ping', 'p', 'tes', 'test'
  ];

  // Goodbyes & typos
  const goodbyes = [
    'terima kasih', 'terimakasih', 'makasih', 'makasi', 'thanks', 'thx', 'tengks',
    'bye', 'sampai jumpa', 'dadah', 'ok', 'oke', 'baik', 'siap', 'maturnuwun', 'suwun'
  ];

  // Identity / General chit-chat
  const identityKw = [
    'siapa kamu', 'kamu siapa', 'siapa anda', 'anda siapa', 'siapa dirimu',
    'apa itu rutestrip', 'siapa pembuatmu', 'fungsi kamu', 'kamu bisa apa', 'bisa apa saja'
  ];

  const helpWords = ['/help', 'bantuan fitur', 'menu bantuan', 'panduan aplikasi', 'cara pakai bot'];

  // Check exact / prefix / suffix for greetings
  if (greetings.some(g => msg === g || msg.startsWith(g + ' ') || msg.endsWith(' ' + g)))
    return { type: 'greeting', isHiking: false };

  // Check goodbyes
  if (goodbyes.some(g => msg === g || msg.startsWith(g + ' ') || msg.endsWith(' ' + g)))
    return { type: 'goodbye', isHiking: false };

  // Check identity
  if (identityKw.some(id => msg.includes(id)))
    return { type: 'identity', isHiking: false };

  if (helpWords.some(h => msg === h || msg.startsWith(h + ' ')))
    return { type: 'help', isHiking: false };

  // Hiking-specific keywords
  const hikingKw = [
    'jalur', 'gunung', 'pendakian', 'mendaki', 'trekking', 'hiking',
    'basecamp', 'pos', 'puncak', 'cuaca', 'itinerary', 'jadwal',
    'logistik', 'biaya', 'survival', 'porter', 'rekomendasi', 'rekomendasikan',
    'elevation', 'simaksi', 'edelweis', 'summit', 'ridge', 'kaldera',
    'via', 'rute', 'track', 'trek', 'gpx', 'maps',
    'peta', 'foto', 'satelit', 'satellite', 'topografi', 'topo', 'map',
    'download', 'kml',
    'p3k', 'darurat', 'hipotermia', 'pertolongan', 'first aid', 'dehidrasi',
    'perlengkapan', 'persiapan', 'logistic',
    'merbabu', 'sumbing', 'prau', 'lawu', 'semeru', 'rinjani', 'kerinci',
    'arjuno', 'welirang', 'papandayan', 'ciremai', 'sindoro', 'ijen',
    'bromo', 'batur', 'agung', 'latimojong', 'tambora', 'slamet',
    'pemula', 'menantang', 'sulit', 'mudah', 'estimasi', 'durasi',
    'budget', 'harga', 'cost', 'ojek', 'pemandu', 'guide',
  ];

  if (hikingKw.some(k => msg.includes(k)))
    return { type: 'hiking', isHiking: true };

  return { type: 'general', isHiking: false };
}

/* ─────────────────────────────────────────────
   RETRIEVE CONTEXT from RuteStrip Bot API
───────────────────────────────────────────── */
async function retrieveContext(message) {
  try {
    const result = await smartRoute(message);
    const routes = extractRoutes(result);

    let contextText = '';

    if (result.type === 'rekomendasi' && result.data?.results?.length) {
      contextText += '=== DATA RESMI API SBERT (REKOMENDASI JALUR) ===\n';
      contextText += 'Instruksi Utama: Kamu WAJIB menyajikan daftar rute di bawah ini lengkap dengan statistik angka (Jarak km, Elevation Gain, Durasi Naismith, & Relevansi %) di bagian atas balasanmu!\n\n';
      result.data.results.slice(0, 5).forEach((r, i) => {
        const s = r.stats || {};
        contextText += `[Jalur ${i + 1}] ${r.mountain_route || r.name}\n`;
        if (s.difficulty)             contextText += `  • Tingkat Kesulitan: ${s.difficulty}\n`;
        if (s.distance_km)            contextText += `  • Jarak Rute: ${s.distance_km} km\n`;
        if (s.elevation_gain_m)       contextText += `  • Elevation Gain: ${s.elevation_gain_m} m\n`;
        if (s.naismith_duration_hour) contextText += `  • Estimasi Waktu (Naismith): ${s.naismith_duration_hour.toFixed(1)} jam\n`;
        if (s.average_grade_pct != null) contextText += `  • Kemiringan Rata-Rata: ${s.average_grade_pct.toFixed(1)}%\n`;
        if (s.min_elevation && s.max_elevation) contextText += `  • Elevasi: ${s.min_elevation}m - ${s.max_elevation}m\n`;
        if (r.narrative)              contextText += `  • Deskripsi: ${r.narrative}\n`;
        if (r.similarity_score != null) contextText += `  • Skor Relevansi: ${(r.similarity_score * 100).toFixed(0)}%\n`;
        contextText += '\n';
      });
    } else if (result.type === 'cuaca' && result.data?.weather_info) {
      contextText += '=== DATA RESMI API OPEN-METEO (CUACA REALTIME) ===\n';
      contextText += 'Instruksi Utama: Kamu WAJIB menyajikan data suhu, status cuaca, dan kecepatan angin resmi dari teks di bawah ini DI BAGIAN PALING ATAS BALASANMU!\n\n';
      if (Array.isArray(result.data.weather_info)) {
        result.data.weather_info.forEach(line => { contextText += `${line}\n`; });
      } else {
        contextText += String(result.data.weather_info).replace(/\\n/g, '\n') + '\n';
      }
      contextText += '\n';
    } else if (result.type === 'itinerary' && result.data?.itinerary) {
      contextText += '=== DATA RESMI API NAISMITH (ITINERARY PENDAKIAN) ===\n';
      contextText += 'Instruksi Utama: Tampilkan rincian estimasi jam dan timeline perjalanan di bawah ini DI BAGIAN ATAS RESPONSMU!\n\n';
      contextText += String(result.data.itinerary).replace(/\\n/g, '\n') + '\n\n';
    } else if (result.type === 'biaya' && result.data?.budget_info) {
      contextText += '=== DATA RESMI API ESTIMASI BIAYA PENDAKIAN ===\n';
      contextText += 'Instruksi Utama: Tampilkan rincian estimasi biaya rupiah (Simaksi, Ojek, Basecamp) dari data di bawah ini DI BAGIAN ATAS RESPONSMU!\n\n';
      contextText += String(result.data.budget_info).replace(/\\n/g, '\n') + '\n\n';
    } else if (result.type === 'map') {
      contextText += `=== DATA RESMI API PETA SATELIT ===\n\nPeta ${result.mapType} untuk Gunung ${result.mountain} telah berhasil digenerate.\n`;
      let finalMapUrl = result.mapUrl;
      try {
        const fetchMtn = result.mountain || 'merbabu';
        const fetchType = result.mapType || 'satelit';
        const targetApiUrl = `${BASE.replace(/\/$/, '')}/api/map/satellite?mountain=${encodeURIComponent(fetchMtn)}&map_type=${encodeURIComponent(fetchType)}`;
        const imgRes = await fetch(targetApiUrl, {
          headers: {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'bypass-tunnel-reminder': 'true',
          },
        });
        if (imgRes.ok) {
          const buf = await imgRes.arrayBuffer();
          const base64Str = Buffer.from(buf).toString('base64');
          const mime = imgRes.headers.get('content-type') || 'image/png';
          finalMapUrl = `data:${mime};base64,${base64Str}`;
        }
      } catch (mapErr) {
        console.error('Error fetching map image for base64:', mapErr);
      }
      return {
        contextText,
        routes: [],
        type: 'map',
        map_url: finalMapUrl,
        mountain: result.mountain,
        mapType: result.mapType,
      };
    } else if (result.type === 'gpx') {
      contextText += `=== DATA RESMI API GPX TRACK ===\n\nFile ${result.format.toUpperCase()} untuk Gunung ${result.mountain} tersedia untuk didownload.\n`;
      return {
        contextText,
        routes: [],
        type: 'gpx',
        gpx_url: result.gpxUrl,
        mountain: result.mountain,
        format: result.format,
      };
    } else if (result.type === 'survival' && result.data?.guide) {
      contextText += '=== DATA RESMI API PANDUAN SURVIVAL & P3K ===\n';
      contextText += 'Instruksi Utama: Tampilkan panduan langkah darurat resmi di bawah ini DI BAGIAN ATAS RESPONSMU!\n\n';
      contextText += String(result.data.guide).replace(/\\n/g, '\n') + '\n\n';
    } else if (result.type === 'logistik') {
      contextText += '=== DATA RESMI API LOGISTIK & KEBUTUHAN AIR ===\n';
      contextText += 'Instruksi Utama: Tampilkan rincian kebutuhan air & perlengkapan dari data di bawah ini DI BAGIAN ATAS RESPONSMU!\n\n';
      const rawText = result.data?.logistics || result.data?.logistics_info || (typeof result.data === 'string' ? result.data : '');
      if (rawText) {
        contextText += String(rawText).replace(/\\n/g, '\n') + '\n\n';
      } else {
        contextText += JSON.stringify(result.data, null, 2) + '\n\n';
      }
    } else if (result.type === 'porter') {
      contextText += '=== DATA RESMI API KONTAK PORTER & BASECAMP ===\n';
      contextText += 'Instruksi Utama: Tampilkan nomor kontak basecamp & estimasi tarif porter dari data di bawah ini DI BAGIAN ATAS RESPONSMU!\n\n';
      const rawText = result.data?.porter_info || result.data?.porter || result.data?.basecamp || (typeof result.data === 'string' ? result.data : '');
      if (rawText) {
        contextText += String(rawText).replace(/\\n/g, '\n') + '\n\n';
      } else {
        contextText += JSON.stringify(result.data, null, 2) + '\n\n';
      }
    } else if (result.data) {
      contextText += `=== DATA: ${result.type?.toUpperCase()} ===\n\n`;
      if (typeof result.data === 'string') {
        contextText += result.data.replace(/\\n/g, '\n') + '\n\n';
      } else {
        const textField = result.data.info || result.data.text || result.data.response || result.data.result || result.data.logistics;
        if (textField && typeof textField === 'string') {
          contextText += textField.replace(/\\n/g, '\n') + '\n\n';
        } else {
          contextText += JSON.stringify(result.data, null, 2) + '\n\n';
        }
      }
    }

    return { contextText, routes, type: result.type };
  } catch (err) {
    console.error('Context retrieval error:', err);
    return { contextText: '', routes: [], type: 'error', map_url: null, gpx_url: null };
  }
}

/* ─────────────────────────────────────────────
   BUILD GEMINI CHAT HISTORY
───────────────────────────────────────────── */
function buildGeminiHistory(history) {
  if (!history?.length) return [];

  const result = [];
  for (const msg of history.slice(-8)) {
    result.push({
      role: msg.role === 'user' ? 'user' : 'model',
      parts: [{ text: msg.content }],
    });
  }
  return result;
}

/* ─────────────────────────────────────────────
   SYSTEM PROMPT
───────────────────────────────────────────── */
const SYSTEM_PROMPT = `Kamu adalah **RuteStrip AI**, asisten AI cerdas & ahli seputar dunia pendakian gunung, kegiatan alam bebas, dan outdoor di Indonesia.

## KEPRIBADIAN & GAYA BAHASA
- Ramah, sopan, antusias, dan informatif seperti teman pendaki berpengalaman / senior outdoor guide.
- Gunakan Bahasa Indonesia yang natural, hangat, dan mudah dipahami.
- Gunakan emoji yang relevan (🏔️ ⛰️ 🥾 ☁️ ⏱️ 🎒 ⛺) untuk membuat percakapan hidup.

## CAKUPAN TOPIK YANG DIPERBOLEHKAN (IN-SCOPE):
Kamu BISA menjawab pertanyaan bebas secara fleksibel dan luas JIKA pertanyaan berkaitan dengan:
1. **Perkenalan & Identitas**: Menyapa pengguna, perkenalan RuteStrip AI, fitur/kemampuan asisten.
2. **Pengetahuan Pendakian & Gunung**: Gunung-gunung di Indonesia/dunia, info cuaca, karakteristik medan, perizinan (simaksi), dll.
3. **Tips & Trik Outdoor**: Cara packing carrier, memilih tenda/sepatu/layering pakaian, manajemen air & logistik, etika pendakian (Leave No Trace), persiapan fisik & latihan endurance.
4. **Keselamatan & Survival**: Pertolongan pertama (P3K), penanganan hipotermia, AMS (penyakit ketinggian), navigasi kompas/peta, trik jika tersesat/cuaca buruk.

⛔ **BATASAN & PENOLAKAN SONTAN (OUT-OF-SCOPE):**
- Jika pengguna bertanya topik yang SAMA SEKALI TIDAK RELEVAN dengan dunia pendakian, alam bebas, atau perkenalan (contoh: koding/pemrograman, politik, matematika sekolah, resep masakan umum, finansial/saham, gosip selebriti, dll.), TOLAK secara ramah dan alihkan kembali ke topik pendakian.
- Contoh penolakan ramah: "Maaf ya, sebagai RuteStrip AI fokus utama saya adalah seputar dunia pendakian gunung, tips outdoor, dan informasi alam bebas. Ada yang ingin kamu tanyakan seputar persiapan pendakianmu? 🏔️"

## PANDUAN UTAMA DATA API & INTERPRETASI AI (API-FIRST + AI HYBRID):
1. **WAJIB MENAMPILKAN DATA RESMI DARI API TERLEBIH DAHULU (API-FIRST)**:
   - Jika terdapat data dari API (Cuaca Realtime, Rekomendasi Jalur SBERT, Estimasi Biaya Rupiah, Naismith Itinerary, Logistik Air, Panduan Survival, Kontak Porter, Peta Satelit, GPX Track), KAMU WAJIB MENAMPILKAN DATA ANGKA & STATUS RESMI DARI API TERSEBUT DI BAGIAN PALING ATAS RESPONSMU!
   - Contoh Cuaca: Tampilkan *☁️ Mendung | 🌡️ 10.2°C | 💨 2.9 km/h* paling atas.
   - Contoh Itinerary: Tampilkan *Timeline Jam & Naismith Duration* paling atas.
   - Contoh Biaya: Tampilkan *Rincian Budget Rupiah & Simaksi* paling atas.
   - Contoh Porter: Tampilkan *Nomor Kontak & Tarif Porter* paling atas.
   - DILARANG BERBICARA "saya tidak tahu data presisi" atau "silakan cek sumber lain" jika data API sudah diberikan di atas.
2. **ANALISIS & SARAN PENDAMPING DARIPADA HERMES AI**:
   - Setelah menyajikan data angka resmi dari API di bagian atas, berikan ulasan, analisis, dan tips pendakian tambahan yang ramah & bermanfaat.
3. **Jika Tidak Ada Data API**: Jawab pertanyaan seputar dunia pendakian/outdoor secara umum menggunakan pengetahuan pendakian yang baik.`;

/* ─────────────────────────────────────────────
   STATIC RESPONSES for non-hiking intents
───────────────────────────────────────────── */
const GREETING_RESPONSES = [
  'Halo! 👋 Selamat datang di **RuteStrip AI**! Saya siap membantu kamu menemukan jalur pendakian yang sempurna.\n\nMau tanya apa hari ini? Rekomendasi jalur, info cuaca, estimasi biaya, atau panduan survival? 🏔️',
  'Hai! 😊 Saya RuteStrip AI, asisten pendakian gunung Indonesia. Ada yang bisa saya bantu?\n\nCoba tanya:\n- *"Rekomendasikan jalur untuk pemula"*\n- *"Cuaca Gunung Sumbing"*\n- *"Itinerary Merbabu 2D1N"*',
  'Halo, pendaki! ⛰️ Siap jelajahi gunung hari ini?\n\nSaya bisa bantu:\n🥾 Rekomendasi jalur sesuai kemampuan\n☁️ Info cuaca realtime\n💰 Estimasi biaya pendakian\n🚑 Panduan survival',
];

const HELP_RESPONSE = `## 🏔️ Panduan Penggunaan RuteStrip AI

Saya bisa menjawab berbagai pertanyaan seputar pendakian gunung!

**📍 Rekomendasi Jalur**
> "Rekomendasikan jalur pendakian untuk pemula"
> "Jalur dengan pemandangan sabana terbaik"

**☁️ Info Cuaca**
> "Cuaca Gunung Sumbing sekarang"
> "Cuaca Merbabu hari ini"

**⏱️ Itinerary**
> "Itinerary Gunung Sindoro 2D1N"
> "Jadwal pendakian Prau tektok"

**💰 Estimasi Biaya**
> "Biaya pendakian Sumbing untuk 3 orang 2 hari"

**🧳 Logistik**
> "Logistik pendakian 4 orang 3 hari"

**🚑 Panduan Survival**
> "Pertolongan pertama hipotermia"
> "Tips survival tersesat di gunung"

**📞 Kontak Porter**
> "Porter Gunung Semeru"`;

/* ─────────────────────────────────────────────
   MAIN POST HANDLER
───────────────────────────────────────────── */
export async function POST(request) {
  try {
    const body = await request.json();
    const { message, history = [], session_id = null } = body;

    if (!message?.trim()) {
      return NextResponse.json({ error: 'Pesan tidak boleh kosong' }, { status: 400 });
    }

    const t0 = Date.now();
    const { type: intentType } = detectIntent(message);

    if (intentType === 'jailbreak') {
      return NextResponse.json({
        response: 'Maaf, saya adalah **RuteStrip AI**, asisten resmi pendakian gunung dan kegiatan outdoor di Indonesia. 🏔️\n\nIdentitas, prinsip keselamatan, dan batasan operasional saya bersifat permanen dan tidak dapat diubah oleh instruksi luar. Ada informasi seputar pendakian gunung yang bisa saya bantu? 🥾',
        retrieved_routes: [],
        timing: { retrieval_ms: 0, generation_ms: Date.now() - t0, type: 'jailbreak_blocked' },
      });
    }

    if (intentType === 'greeting') {
      const resp = GREETING_RESPONSES[Math.floor(Math.random() * GREETING_RESPONSES.length)];
      return NextResponse.json({
        response: resp,
        retrieved_routes: [],
        timing: { retrieval_ms: 0, generation_ms: Date.now() - t0, type: 'greeting' },
      });
    }

    if (intentType === 'identity') {
      return NextResponse.json({
        response: 'Halo! Saya **RuteStrip AI**, asisten cerdas khusus pendakian gunung di Indonesia. 🏔️\n\nSaya bisa membantu kamu dengan:\n🥾 **Rekomendasi Jalur** pendakian sesuai kemampuanmu\n☁️ **Info Cuaca** realtime di berbagai gunung\n⏱️ **Itinerary & Kalkulasi Naismith** per pos\n💰 **Estimasi Biaya & Logistik** kelompok\n🚑 **Panduan Survival & P3K** darurat\n🗺️ **Peta Satelit & Download File GPX/KML**\n\nAda yang ingin kamu persiapkan untuk pendakianmu berikutnya? 😊',
        retrieved_routes: [],
        timing: { retrieval_ms: 0, generation_ms: Date.now() - t0, type: 'identity' },
      });
    }

    if (intentType === 'help') {
      return NextResponse.json({
        response: HELP_RESPONSE,
        retrieved_routes: [],
        timing: { retrieval_ms: 0, generation_ms: Date.now() - t0, type: 'help' },
      });
    }

    if (intentType === 'goodbye') {
      return NextResponse.json({
        response: 'Sama-sama! Semoga pendakianmu menyenangkan dan aman. **Happy hiking!** 🏔️🥾\n\nKalau butuh info lain, saya siap membantu kapan saja!',
        retrieved_routes: [],
        timing: { retrieval_ms: 0, generation_ms: Date.now() - t0, type: 'goodbye' },
      });
    }

    // ── HIKING / GENERAL QUERIES: Always run Smart Router + Gemini ──
    const t1 = Date.now();
    const { contextText, routes, type: apiType, map_url, gpx_url, mountain: mapMountain, mapType, format } = await retrieveContext(message);
    const retrieval_ms = Date.now() - t1;

    if (apiType === 'map' && map_url) {
      const cap = mapMountain ? mapMountain.charAt(0).toUpperCase() + mapMountain.slice(1) : '';
      return NextResponse.json({
        response: `Berikut peta **${mapType}** Gunung ${cap} 🗺️\n\nKlik gambar untuk memperbesar.`,
        map_url,
        retrieved_routes: [],
        timing: { retrieval_ms, generation_ms: 0, total_ms: Date.now() - t0, type: 'map' },
      });
    }

    if (apiType === 'gpx' && gpx_url) {
      const cap = mapMountain ? mapMountain.charAt(0).toUpperCase() + mapMountain.slice(1) : '';
      return NextResponse.json({
        response: `File **${format?.toUpperCase()}** Gunung ${cap} siap didownload 📥`,
        gpx_url,
        retrieved_routes: [],
        timing: { retrieval_ms, generation_ms: 0, total_ms: Date.now() - t0, type: 'gpx' },
      });
    }

    const augmentedMessage = contextText
      ? `${message}\n\n---\n${contextText}\n---\n\nJawab pertanyaan pengguna di atas dengan ramah, natural, dan informatif.`
      : message;

    let responseText = '';
    let geminiErrorMsg = '';
    let hermesErrorMsg = '';

    // ── 1. PRIMARY: Coba Hermes / 9router API (HERMES_API_BASE) ──
    if (HERMES_BASE) {
      try {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000); // 15s max timeout
        const headers = {
          'Content-Type': 'application/json',
          'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        };
        if (HERMES_KEY) {
          headers['Authorization'] = `Bearer ${HERMES_KEY}`;
        }

        const hermesRes = await fetch(`${HERMES_BASE.replace(/\/$/, '')}/v1/chat/completions`, {
          method: 'POST',
          headers,
          signal: controller.signal,
          body: JSON.stringify({
            model: HERMES_MODEL,
            messages: [
              { role: 'system', content: SYSTEM_PROMPT },
              { role: 'user', content: augmentedMessage }
            ],
            temperature: 0.7,
            max_tokens: 800,
            stream: false,
          }),
        });
        clearTimeout(timeout);

        if (hermesRes.ok) {
          const rawText = await hermesRes.text();
          try {
            const hermesData = JSON.parse(rawText);
            responseText = hermesData.choices?.[0]?.message?.content || '';
          } catch (jsonErr) {
            // Parser untuk format SSE (Server-Sent Events) jika 9router mengembalikan "data: {...}"
            let extractedContent = '';
            const lines = rawText.split('\n');
            for (const line of lines) {
              if (line.startsWith('data: ') && !line.includes('[DONE]')) {
                try {
                  const chunk = JSON.parse(line.substring(6));
                  const delta = chunk.choices?.[0]?.delta?.content || chunk.choices?.[0]?.message?.content || '';
                  extractedContent += delta;
                } catch (e) {}
              }
            }
            if (extractedContent) {
              responseText = extractedContent;
            } else {
              hermesErrorMsg = `Failed to parse Hermes response: ${rawText.substring(0, 100)}`;
            }
          }
        } else {
          const errBody = await hermesRes.text();
          hermesErrorMsg = `HTTP ${hermesRes.status}: ${errBody.substring(0, 150)}`;
          console.error(`Hermes VPS returned status ${hermesRes.status}`);
        }
      } catch (hermesErr) {
        hermesErrorMsg = hermesErr?.message || String(hermesErr);
        console.error('Hermes VPS API error:', hermesErrorMsg);
      }
    }

    // ── 2. FALLBACK: Coba Gemini API jika Hermes VPS offline / timeout ──
    if (!responseText && GEMINI_KEY) {
      try {
        const genAI = new GoogleGenerativeAI(GEMINI_KEY);
        const model = genAI.getGenerativeModel({
          model: GEMINI_MODEL,
          systemInstruction: SYSTEM_PROMPT,
        });

        const chat = model.startChat({ history: buildGeminiHistory(history) });
        const genResult = await chat.sendMessage(augmentedMessage);
        responseText = genResult.response.text();
      } catch (geminiErr) {
        geminiErrorMsg = geminiErr?.message || String(geminiErr);
        console.error('Gemini API error:', geminiErrorMsg);
      }
    }

    // ── 3. Fallback Ramah ──
    if (!responseText) {
      if (contextText) {
        responseText = `Berikut informasi pendakian yang saya temukan:\n\n${contextText}`;
      } else {
        responseText = 'Halo! Saya RuteStrip AI, asisten pendakian gunung Indonesia. Mohon maaf, server AI sedang mengalami antrean padat. Silakan coba tanyakan kembali beberapa saat lagi. 🏔️';
      }
    }

    // ── 4. Sync history to VPS backend if Authorization token present ──
    let returnedSessionId = session_id;
    const authHeader = request.headers.get('authorization');
    if (authHeader) {
      try {
        const vpsRes = await fetch(`${process.env.RUTESTRIP_API_BASE || 'http://127.0.0.1:8010'}/api/chat/query`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': authHeader,
          },
          body: JSON.stringify({ prompt: message, session_id }),
        });
        if (vpsRes.ok) {
          const vpsData = await vpsRes.json();
          if (vpsData?.session_id) {
            returnedSessionId = vpsData.session_id;
          }
        }
      } catch (syncErr) {
        console.error('VPS chat history sync error:', syncErr);
      }
    }

    return NextResponse.json({
      response: responseText,
      retrieved_routes: routes,
      session_id: returnedSessionId,
      timing: {
        retrieval_ms,
        generation_ms: Date.now() - t0 - retrieval_ms,
        total_ms: Date.now() - t0,
        type: apiType,
      },
    });

  } catch (err) {
    console.error('Chat API fatal error:', err);
    return NextResponse.json({
      response: `Maaf, terjadi kesalahan teknis. Silakan coba lagi.\n\n*Error: ${err.message}*`,
      retrieved_routes: [],
      timing: null,
    }, { status: 200 });
  }
}
