export const maxDuration = 60; // Max duration for Vercel Serverless Function (60 seconds)

import { NextResponse } from 'next/server';
import { GoogleGenerativeAI } from '@google/generative-ai';
import { smartRoute } from '@/lib/api';
import { extractRoutes } from '@/lib/formatter';
import { apiAuthHeaders, requestLang, API_BASE } from '@/lib/sso';

const BASE = (process.env.RUTESTRIP_API_BASE && !process.env.RUTESTRIP_API_BASE.includes('cement-affiliation'))
  ? process.env.RUTESTRIP_API_BASE
  : 'http://127.0.0.1:8010';
const GEMINI_KEY   = process.env.GEMINI_API_KEY;
// Ordered model fallback list (de-duplicated). GEMINI_MODELS overrides the default.
const GEMINI_MODELS = [...new Set(
  (process.env.GEMINI_MODELS
    ? process.env.GEMINI_MODELS.split(',')
    : [process.env.GEMINI_MODEL, 'gemini-3.5-flash', 'gemini-3.1-flash-lite']
  ).map(m => (m || '').trim()).filter(Boolean)
)];
const GEMINI_FIRST_TOKEN_TIMEOUT_MS = Number(process.env.GEMINI_FIRST_TOKEN_TIMEOUT_MS) || 12000;
const GEMINI_TOTAL_TIMEOUT_MS       = Number(process.env.GEMINI_TOTAL_TIMEOUT_MS) || 45000;
const GEMINI_MAX_OUTPUT_TOKENS      = 1024;
// Models that answered 400 to thinkingConfig (thinking cannot be disabled); remembered per process.
const MODELS_WITHOUT_THINKING_CONFIG = new Set();
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
    'pagi', 'siang', 'sore', 'malam', 'ping', 'p', 'tes', 'test',
    'hey', 'good morning', 'good afternoon', 'good evening', 'how are you'
  ];

  // Goodbyes & typos
  const goodbyes = [
    'terima kasih', 'terimakasih', 'makasih', 'makasi', 'thanks', 'thx', 'tengks',
    'bye', 'sampai jumpa', 'dadah', 'ok', 'oke', 'baik', 'siap', 'maturnuwun', 'suwun',
    'thank you', 'thanks a lot', 'see you', 'goodbye', 'okay'
  ];

  // Identity / General chit-chat
  const identityKw = [
    'siapa kamu', 'kamu siapa', 'siapa anda', 'anda siapa', 'siapa dirimu',
    'apa itu rutestrip', 'siapa pembuatmu', 'fungsi kamu', 'kamu bisa apa', 'bisa apa saja',
    'who are you', 'what are you', 'what is rutestrip', 'who made you', 'what can you do'
  ];

  const helpWords = ['/help', 'help', 'bantuan', 'bantuan fitur', 'menu bantuan', 'panduan aplikasi', 'cara pakai bot', 'how to use'];

  // Check exact / prefix / suffix for greetings
  if (greetings.some(g => msg === g || msg.startsWith(g + ' ') || msg.endsWith(' ' + g)))
    return { type: 'greeting', isHiking: false };

  // Check goodbyes
  if (goodbyes.some(g => msg === g || msg.startsWith(g + ' ') || msg.endsWith(' ' + g)))
    return { type: 'goodbye', isHiking: false };

  // Check identity
  if (identityKw.some(id => msg.includes(id)))
    return { type: 'identity', isHiking: false };

  if (helpWords.some(h => msg === h || (h.length > 4 && msg.startsWith(h + ' '))))
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
    'mountain', 'trail', 'hike', 'weather', 'hypothermia', 'logistics', 'beginner', 'route', 'camp',
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
- JANGAN gunakan emoji atau emotikon dalam jawaban. Gunakan teks biasa dengan format markdown (judul, daftar, teks tebal) agar jawaban tetap rapi dan mudah dibaca.

## CAKUPAN TOPIK YANG DIPERBOLEHKAN (IN-SCOPE):
Kamu BISA menjawab pertanyaan bebas secara fleksibel dan luas JIKA pertanyaan berkaitan dengan:
1. **Perkenalan & Identitas**: Menyapa pengguna, perkenalan RuteStrip AI, fitur/kemampuan asisten.
2. **Pengetahuan Pendakian & Gunung**: Gunung-gunung di Indonesia/dunia, info cuaca, karakteristik medan, perizinan (simaksi), dll.
3. **Tips & Trik Outdoor**: Cara packing carrier, memilih tenda/sepatu/layering pakaian, manajemen air & logistik, etika pendakian (Leave No Trace), persiapan fisik & latihan endurance.
4. **Keselamatan & Survival**: Pertolongan pertama (P3K), penanganan hipotermia, AMS (penyakit ketinggian), navigasi kompas/peta, trik jika tersesat/cuaca buruk.

**BATASAN & PENOLAKAN SONTAN (OUT-OF-SCOPE):**
- Jika pengguna bertanya topik yang SAMA SEKALI TIDAK RELEVAN dengan dunia pendakian, alam bebas, atau perkenalan (contoh: koding/pemrograman, politik, matematika sekolah, resep masakan umum, finansial/saham, gosip selebriti, dll.), TOLAK secara ramah dan alihkan kembali ke topik pendakian.
- Contoh penolakan ramah: "Maaf ya, sebagai RuteStrip AI fokus utama saya adalah seputar dunia pendakian gunung, tips outdoor, dan informasi alam bebas. Ada yang ingin kamu tanyakan seputar persiapan pendakianmu?"

## PANDUAN UTAMA DATA API & INTERPRETASI AI (API-FIRST + AI HYBRID):
1. **WAJIB MENAMPILKAN DATA RESMI DARI API TERLEBIH DAHULU (API-FIRST)**:
   - Jika terdapat data dari API (Cuaca Realtime, Rekomendasi Jalur SBERT, Estimasi Biaya Rupiah, Naismith Itinerary, Logistik Air, Panduan Survival, Kontak Porter, Peta Satelit, GPX Track), KAMU WAJIB MENAMPILKAN DATA ANGKA & STATUS RESMI DARI API TERSEBUT DI BAGIAN PALING ATAS RESPONSMU!
   - Contoh Cuaca: Tampilkan *Cuaca: Mendung | Suhu: 10.2°C | Angin: 2.9 km/h* paling atas.
   - Contoh Itinerary: Tampilkan *Timeline Jam & Naismith Duration* paling atas.
   - Contoh Biaya: Tampilkan *Rincian Budget Rupiah & Simaksi* paling atas.
   - Contoh Porter: Tampilkan *Nomor Kontak & Tarif Porter* paling atas.
   - DILARANG BERBICARA "saya tidak tahu data presisi" atau "silakan cek sumber lain" jika data API sudah diberikan di atas.
2. **ANALISIS & SARAN PENDAMPING DARIPADA HERMES AI**:
   - Setelah menyajikan data angka resmi dari API di bagian atas, berikan ulasan, analisis, dan tips pendakian tambahan yang ramah & bermanfaat.
3. **Jika Tidak Ada Data API**: Jawab pertanyaan seputar dunia pendakian/outdoor secara umum menggunakan pengetahuan pendakian yang baik.`;

const LANGUAGE_RULE_EN = `

## LANGUAGE (OVERRIDES THE LANGUAGE RULES ABOVE)
The user has set the interface to English. Always answer in natural, clear English, even though these instructions and the API data are written in Indonesian:
- Translate data labels and descriptions into English; keep mountain names, trail names, places, phone numbers and figures unchanged (amounts stay in Rupiah, written as "Rp").
- Out-of-scope refusals are also in English, for example: "Sorry, as RuteStrip AI I focus on mountain hiking, outdoor tips and nature information. Is there anything about your hike I can help with?"
- Still no emoji.`;

function systemPrompt(locale) {
  return locale === 'en' ? SYSTEM_PROMPT + LANGUAGE_RULE_EN : SYSTEM_PROMPT;
}

/* ─────────────────────────────────────────────
   STATIC RESPONSES for non-hiking intents
───────────────────────────────────────────── */
const GREETING_RESPONSES = {
  id: [
    'Halo! Selamat datang di **RuteStrip AI**. Saya siap membantu kamu menemukan jalur pendakian yang sempurna.\n\nMau tanya apa hari ini? Rekomendasi jalur, info cuaca, estimasi biaya, atau panduan survival?',
    'Hai! Saya RuteStrip AI, asisten pendakian gunung Indonesia. Ada yang bisa saya bantu?\n\nCoba tanya:\n- *"Rekomendasikan jalur untuk pemula"*\n- *"Cuaca Gunung Sumbing"*\n- *"Itinerary Merbabu 2D1N"*',
    'Halo, pendaki! Siap jelajahi gunung hari ini?\n\nSaya bisa bantu:\n- Rekomendasi jalur sesuai kemampuan\n- Info cuaca realtime\n- Estimasi biaya pendakian\n- Panduan survival',
  ],
  en: [
    'Hello! Welcome to **RuteStrip AI**. I can help you find the right hiking trail.\n\nWhat would you like to know today? Trail recommendations, weather, cost estimates or survival tips?',
    'Hi! I am RuteStrip AI, a hiking assistant for Indonesian mountains. How can I help?\n\nTry asking:\n- *"Recommend a trail for beginners"*\n- *"Weather on Mount Sumbing"*\n- *"Itinerary Merbabu 2D1N"*',
    'Hello, hiker! Ready for the mountains today?\n\nI can help with:\n- Trail recommendations for your level\n- Real-time weather\n- Hiking cost estimates\n- Survival guides',
  ],
};

const HELP_RESPONSE = {
  id: `## Panduan Penggunaan RuteStrip AI

Saya bisa menjawab berbagai pertanyaan seputar pendakian gunung!

**Rekomendasi Jalur**
> "Rekomendasikan jalur pendakian untuk pemula"
> "Jalur dengan pemandangan sabana terbaik"

**Info Cuaca**
> "Cuaca Gunung Sumbing sekarang"
> "Cuaca Merbabu hari ini"

**Itinerary**
> "Itinerary Gunung Sindoro 2D1N"
> "Jadwal pendakian Prau tektok"

**Estimasi Biaya**
> "Biaya pendakian Sumbing untuk 3 orang 2 hari"

**Logistik**
> "Logistik pendakian 4 orang 3 hari"

**Panduan Survival**
> "Pertolongan pertama hipotermia"
> "Tips survival tersesat di gunung"

**Kontak Porter**
> "Porter Gunung Semeru"`,
  en: `## How to use RuteStrip AI

I can answer all kinds of questions about mountain hiking.

**Trail recommendations**
> "Recommend a hiking trail for beginners"
> "Trails with the best savanna views"

**Weather**
> "Weather on Mount Sumbing now"
> "Weather Merbabu today"

**Itinerary**
> "Itinerary Mount Sindoro 2D1N"
> "Itinerary Prau tektok"

**Cost estimate**
> "Hiking cost Sumbing 3 people 2 days"

**Logistics**
> "Logistics for 4 people 3 days"

**Survival guide**
> "First aid for hypothermia"
> "Survival tips when lost on a mountain"

**Porter contacts**
> "Porter Mount Semeru"`,
};

const IDENTITY_RESPONSE = {
  id: 'Halo! Saya **RuteStrip AI**, asisten cerdas khusus pendakian gunung di Indonesia.\n\nSaya bisa membantu kamu dengan:\n- **Rekomendasi Jalur** pendakian sesuai kemampuanmu\n- **Info Cuaca** realtime di berbagai gunung\n- **Itinerary & Kalkulasi Naismith** per pos\n- **Estimasi Biaya & Logistik** kelompok\n- **Panduan Survival & P3K** darurat\n- **Peta Satelit & Download File GPX/KML**\n\nAda yang ingin kamu persiapkan untuk pendakianmu berikutnya?',
  en: 'Hello! I am **RuteStrip AI**, an assistant dedicated to mountain hiking in Indonesia.\n\nI can help you with:\n- **Trail recommendations** that match your level\n- **Real-time weather** for many mountains\n- **Itineraries & Naismith timing** per checkpoint\n- **Cost & logistics estimates** for your group\n- **Survival & first-aid guides**\n- **Satellite maps & GPX/KML downloads**\n\nWhat would you like to prepare for your next hike?',
};

const GOODBYE_RESPONSE = {
  id: 'Sama-sama! Semoga pendakianmu menyenangkan dan aman. **Happy hiking!**\n\nKalau butuh info lain, saya siap membantu kapan saja!',
  en: 'You are welcome! Have a safe and enjoyable hike. **Happy hiking!**\n\nIf you need anything else, I am here to help.',
};

const JAILBREAK_RESPONSE = {
  id: 'Maaf, saya adalah **RuteStrip AI**, asisten resmi pendakian gunung dan kegiatan outdoor di Indonesia.\n\nIdentitas, prinsip keselamatan, dan batasan operasional saya bersifat permanen dan tidak dapat diubah oleh instruksi luar. Ada informasi seputar pendakian gunung yang bisa saya bantu?',
  en: 'Sorry, I am **RuteStrip AI**, the official assistant for mountain hiking and outdoor activities in Indonesia.\n\nMy identity, safety principles and limits are permanent and cannot be changed by outside instructions. Is there anything about mountain hiking I can help with?',
};

const TEXTS = {
  id: {
    empty: 'Pesan tidak boleh kosong',
    answerTail: 'Jawab pertanyaan pengguna di atas dengan ramah, natural, dan informatif.',
    fallbackWithData: 'Berikut informasi pendakian yang saya temukan:',
    fallbackBusy: 'Halo! Saya RuteStrip AI, asisten pendakian gunung Indonesia. Mohon maaf, server AI sedang mengalami antrean padat. Silakan coba tanyakan kembali beberapa saat lagi.',
    techError: 'Maaf, terjadi kesalahan teknis. Silakan coba lagi.',
    map: (type, mtn) => `Berikut peta **${type}** Gunung ${mtn}.\n\nKlik gambar untuk memperbesar.`,
    gpx: (fmt, mtn) => `File **${fmt}** Gunung ${mtn} siap didownload.`,
  },
  en: {
    empty: 'The message cannot be empty',
    answerTail: 'Answer the user question above in English, in a friendly, natural and informative way.',
    fallbackWithData: 'Here is the hiking information I found:',
    fallbackBusy: 'Hello! I am RuteStrip AI, a hiking assistant for Indonesian mountains. Sorry, the AI server is very busy right now. Please ask again in a moment.',
    techError: 'Sorry, a technical error occurred. Please try again.',
    map: (type, mtn) => `Here is the **${type === 'topografi' ? 'topographic' : 'satellite'}** map of Mount ${mtn}.\n\nTap the image to enlarge it.`,
    gpx: (fmt, mtn) => `The **${fmt}** file for Mount ${mtn} is ready to download.`,
  },
};

/* ─────────────────────────────────────────────
   HERMES / 9router (OpenAI-compatible) — optional primary
───────────────────────────────────────────── */
async function callHermes(prompt, system) {
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
          { role: 'system', content: system },
          { role: 'user', content: prompt }
        ],
        temperature: 0.7,
        max_tokens: 800,
        stream: false,
      }),
    });
    clearTimeout(timeout);

    if (!hermesRes.ok) {
      const errBody = await hermesRes.text();
      console.error(`Hermes VPS returned status ${hermesRes.status}`);
      return { text: '', error: `HTTP ${hermesRes.status}: ${errBody.substring(0, 150)}` };
    }

    const rawText = await hermesRes.text();
    try {
      const hermesData = JSON.parse(rawText);
      return { text: hermesData.choices?.[0]?.message?.content || '' };
    } catch {
      // Parser untuk format SSE (Server-Sent Events) jika 9router mengembalikan "data: {...}"
      let extractedContent = '';
      for (const line of rawText.split('\n')) {
        if (line.startsWith('data: ') && !line.includes('[DONE]')) {
          try {
            const chunk = JSON.parse(line.substring(6));
            extractedContent += chunk.choices?.[0]?.delta?.content || chunk.choices?.[0]?.message?.content || '';
          } catch {}
        }
      }
      if (extractedContent) return { text: extractedContent };
      return { text: '', error: `Failed to parse Hermes response: ${rawText.substring(0, 100)}` };
    }
  } catch (hermesErr) {
    const msg = hermesErr?.message || String(hermesErr);
    console.error('Hermes VPS API error:', msg);
    return { text: '', error: msg };
  }
}

/* ─────────────────────────────────────────────
   GEMINI — streaming with first-token timeout + model fallback
───────────────────────────────────────────── */
async function tryGeminiModel(genAI, modelName, generationConfig, system, history, prompt, onDelta, remainingMs) {
  const controller = new AbortController();
  const started = Date.now();
  let text = '';
  let firstTokenMs = null;
  let abortReason = '';

  const firstTokenWait = Math.min(GEMINI_FIRST_TOKEN_TIMEOUT_MS, remainingMs);
  const firstTimer = setTimeout(() => {
    abortReason = `no first token within ${firstTokenWait}ms`;
    controller.abort();
  }, firstTokenWait);
  const totalTimer = setTimeout(() => {
    abortReason = 'total generation timeout';
    controller.abort();
  }, remainingMs);

  try {
    const model = genAI.getGenerativeModel({
      model: modelName,
      systemInstruction: system,
      generationConfig,
    });
    const chat = model.startChat({ history: buildGeminiHistory(history) });
    const result = await chat.sendMessageStream(prompt, { signal: controller.signal });
    result.response.catch(() => {}); // avoid unhandled rejection when aborted

    for await (const chunk of result.stream) {
      let piece = '';
      try { piece = chunk.text(); } catch {}
      if (!piece) continue;
      if (firstTokenMs === null) {
        firstTokenMs = Date.now() - started;
        clearTimeout(firstTimer);
      }
      text += piece;
      onDelta(piece);
    }
    return { text, firstTokenMs };
  } catch (err) {
    return { text, firstTokenMs, status: err?.status, error: abortReason || err?.message || String(err) };
  } finally {
    clearTimeout(firstTimer);
    clearTimeout(totalTimer);
  }
}

async function generateWithGemini(system, history, prompt, onDelta) {
  if (!GEMINI_KEY) return { text: '', model: null, error: 'GEMINI_API_KEY not set' };

  const genAI = new GoogleGenerativeAI(GEMINI_KEY);
  const deadline = Date.now() + GEMINI_TOTAL_TIMEOUT_MS;
  const errors = [];

  for (const modelName of GEMINI_MODELS) {
    // Retry the same model once without thinkingConfig if it rejects thinkingBudget=0.
    for (let attempt = 0; attempt < 2; attempt++) {
      const remainingMs = deadline - Date.now();
      if (remainingMs < 1000) {
        errors.push('generation deadline reached');
        return { text: '', model: null, error: errors.join(' | ') };
      }

      const withThinkingConfig = !MODELS_WITHOUT_THINKING_CONFIG.has(modelName);
      const generationConfig = withThinkingConfig
        ? { maxOutputTokens: GEMINI_MAX_OUTPUT_TOKENS, thinkingConfig: { thinkingBudget: 0 } }
        : { maxOutputTokens: GEMINI_MAX_OUTPUT_TOKENS };

      const r = await tryGeminiModel(genAI, modelName, generationConfig, system, history, prompt, onDelta, remainingMs);
      // Once text has been streamed we cannot switch models; keep what we have.
      if (r.text) {
        if (r.error) console.error(`Gemini ${modelName} stopped mid-stream:`, r.error);
        return { text: r.text, model: modelName, first_token_ms: r.firstTokenMs };
      }

      console.error(`Gemini ${modelName} failed:`, r.error || 'empty response');
      errors.push(`${modelName}: ${r.error || 'empty response'}`);

      if (withThinkingConfig && r.status === 400 && /thinking/i.test(r.error || '')) {
        MODELS_WITHOUT_THINKING_CONFIG.add(modelName);
        continue;
      }
      break;
    }
  }
  return { text: '', model: null, error: errors.join(' | ') };
}

/* ─────────────────────────────────────────────
   GENERATE ANSWER: Hermes → Gemini fallback chain → friendly fallback
   onDelta(text) is called for every piece of text produced.
───────────────────────────────────────────── */
async function generateAnswer({ message, history, contextText, locale = 'id', onDelta = () => {} }) {
  const T = TEXTS[locale];
  const system = systemPrompt(locale);
  const augmentedMessage = contextText
    ? `${message}\n\n---\n${contextText}\n---\n\n${T.answerTail}`
    : (locale === 'en' ? `${message}\n\n(${T.answerTail})` : message);

  // ── 1. PRIMARY: Coba Hermes / 9router API (HERMES_API_BASE) ──
  if (HERMES_BASE) {
    const h = await callHermes(augmentedMessage, system);
    if (h.text) {
      onDelta(h.text);
      return { text: h.text, model: `hermes:${HERMES_MODEL}` };
    }
  }

  // ── 2. Gemini with streaming, timeouts and model fallback ──
  const g = await generateWithGemini(system, history, augmentedMessage, onDelta);
  if (g.text) return g;

  // ── 3. Fallback Ramah ──
  const fallback = contextText
    ? `${T.fallbackWithData}\n\n${contextText}`
    : T.fallbackBusy;
  onDelta(fallback);
  return { text: fallback, model: 'fallback', error: g.error };
}

/* ─────────────────────────────────────────────
   SYNC HISTORY to webchat-api (only for signed-in users: rs_sso cookie)
   Resolves to the session id to return; never rejects.
───────────────────────────────────────────── */
function syncChatHistory(request, message, session_id, locale, reply, replyType) {
  const auth = apiAuthHeaders(request);
  if (!auth.Authorization || !reply) return Promise.resolve(session_id);
  return fetch(`${API_BASE}/api/chat/query`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...auth,
    },
    // Save the finished answer (not a placeholder) so reopened chats show it.
    body: JSON.stringify({ prompt: message, session_id, locale, bot_reply: reply, reply_type: replyType }),
    signal: AbortSignal.timeout(15000),
  })
    .then(async (vpsRes) => {
      if (!vpsRes.ok) return session_id;
      const vpsData = await vpsRes.json();
      return vpsData?.session_id || session_id;
    })
    .catch((syncErr) => {
      console.error('VPS chat history sync error:', syncErr);
      return session_id;
    });
}

/* ─────────────────────────────────────────────
   NDJSON STREAM RESPONSE
───────────────────────────────────────────── */
function ndjsonResponse(run, errorText = TEXTS.id.techError) {
  const encoder = new TextEncoder();
  const body = new ReadableStream({
    async start(controller) {
      let open = true;
      const send = (obj) => {
        if (!open) return;
        try {
          controller.enqueue(encoder.encode(JSON.stringify(obj) + '\n'));
        } catch {
          open = false; // client went away
        }
      };
      try {
        await run(send);
      } catch (err) {
        console.error('Chat stream fatal error:', err);
        send({ type: 'delta', text: `${errorText}\n\n*Error: ${err.message}*` });
        send({ type: 'done', session_id: null, timing: null });
      } finally {
        if (open) {
          try { controller.close(); } catch {}
        }
      }
    },
  });
  return new Response(body, {
    headers: {
      'Content-Type': 'application/x-ndjson; charset=utf-8',
      'Cache-Control': 'no-cache, no-transform',
      'X-Accel-Buffering': 'no', // disable nginx proxy buffering so chunks flush immediately
    },
  });
}

/* ─────────────────────────────────────────────
   MAIN POST HANDLER
───────────────────────────────────────────── */
export async function POST(request) {
  let locale = requestLang(request);
  try {
    const body = await request.json();
    const { message, history = [], session_id = null, stream = false } = body;
    if (body.locale === 'en' || body.locale === 'id') locale = body.locale;
    const T = TEXTS[locale];

    if (typeof message !== 'string' || !message.trim()) {
      return NextResponse.json({ error: T.empty }, { status: 400 });
    }

    const t0 = Date.now();
    const { type: intentType } = detectIntent(message);

    if (intentType === 'jailbreak') {
      return NextResponse.json({
        response: JAILBREAK_RESPONSE[locale],
        retrieved_routes: [],
        timing: { retrieval_ms: 0, generation_ms: Date.now() - t0, type: 'jailbreak_blocked' },
      });
    }

    if (intentType === 'greeting') {
      const options = GREETING_RESPONSES[locale];
      const resp = options[Math.floor(Math.random() * options.length)];
      return NextResponse.json({
        response: resp,
        retrieved_routes: [],
        timing: { retrieval_ms: 0, generation_ms: Date.now() - t0, type: 'greeting' },
      });
    }

    if (intentType === 'identity') {
      return NextResponse.json({
        response: IDENTITY_RESPONSE[locale],
        retrieved_routes: [],
        timing: { retrieval_ms: 0, generation_ms: Date.now() - t0, type: 'identity' },
      });
    }

    if (intentType === 'help') {
      return NextResponse.json({
        response: HELP_RESPONSE[locale],
        retrieved_routes: [],
        timing: { retrieval_ms: 0, generation_ms: Date.now() - t0, type: 'help' },
      });
    }

    if (intentType === 'goodbye') {
      return NextResponse.json({
        response: GOODBYE_RESPONSE[locale],
        retrieved_routes: [],
        timing: { retrieval_ms: 0, generation_ms: Date.now() - t0, type: 'goodbye' },
      });
    }

    // ── HIKING / GENERAL QUERIES: Always run Smart Router + LLM ──
    const t1 = Date.now();
    const { contextText, routes, type: apiType, map_url, gpx_url, mountain: mapMountain, mapType, format } = await retrieveContext(message);
    const retrieval_ms = Date.now() - t1;

    // ── Map / GPX: answered directly from the API, no LLM call ──
    let directResponse = null;
    if (apiType === 'map' && map_url) {
      const cap = mapMountain ? mapMountain.charAt(0).toUpperCase() + mapMountain.slice(1) : '';
      directResponse = {
        response: T.map(mapType, cap),
        map_url,
        retrieved_routes: [],
        timing: { retrieval_ms, generation_ms: 0, total_ms: Date.now() - t0, type: 'map' },
      };
    } else if (apiType === 'gpx' && gpx_url) {
      const cap = mapMountain ? mapMountain.charAt(0).toUpperCase() + mapMountain.slice(1) : '';
      directResponse = {
        response: T.gpx(format?.toUpperCase(), cap),
        gpx_url,
        retrieved_routes: [],
        timing: { retrieval_ms, generation_ms: 0, total_ms: Date.now() - t0, type: 'gpx' },
      };
    }

    if (directResponse) {
      // History keeps the text only; the map image (data URL) is not stored.
      const savedSessionId = await syncChatHistory(request, message, session_id, locale, directResponse.response, apiType);
      if (!stream) return NextResponse.json({ ...directResponse, session_id: savedSessionId });
      const { response, timing, ...rest } = directResponse;
      return ndjsonResponse(async (send) => {
        send({ type: 'meta', ...rest, timing: { retrieval_ms, type: timing.type } });
        send({ type: 'delta', text: response });
        send({ type: 'done', session_id: savedSessionId, timing });
      }, T.techError);
    }

    const buildTiming = (gen, t2) => ({
      retrieval_ms,
      generation_ms: Date.now() - t2,
      total_ms: Date.now() - t0,
      type: apiType,
      model: gen.model,
      ...(gen.first_token_ms != null ? { first_token_ms: gen.first_token_ms } : {}),
    });

    if (stream) {
      return ndjsonResponse(async (send) => {
        send({ type: 'meta', retrieved_routes: routes, timing: { retrieval_ms, type: apiType } });
        const t2 = Date.now();
        const gen = await generateAnswer({
          message,
          history,
          contextText,
          locale,
          onDelta: (text) => send({ type: 'delta', text }),
        });
        const timing = buildTiming(gen, t2);
        const returnedSessionId = await syncChatHistory(request, message, session_id, locale, gen.text, apiType);
        send({ type: 'done', session_id: returnedSessionId, timing });
      }, T.techError);
    }

    const t2 = Date.now();
    const gen = await generateAnswer({ message, history, contextText, locale });
    const timing = buildTiming(gen, t2);
    const returnedSessionId = await syncChatHistory(request, message, session_id, locale, gen.text, apiType);

    return NextResponse.json({
      response: gen.text,
      retrieved_routes: routes,
      session_id: returnedSessionId,
      timing,
    });

  } catch (err) {
    console.error('Chat API fatal error:', err);
    return NextResponse.json({
      response: `${TEXTS[locale].techError}\n\n*Error: ${err.message}*`,
      retrieved_routes: [],
      timing: null,
    }, { status: 200 });
  }
}
