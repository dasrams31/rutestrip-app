/**
 * Format API response into human-readable markdown.
 * Field names are based on ACTUAL API responses from RuteStrip Bot.
 */
export function formatApiResponse(result) {
  if (!result || !result.data) return 'Maaf, tidak ada data yang ditemukan.';

  const { type, data, mountain, mode, topic } = result;

  switch (type) {

    /* ─────────────────────────────────────────────────────
       REKOMENDASI  →  { query, total, results: [...] }
       Each result: { mountain_route, similarity_score, stats: {...}, narrative }
    ───────────────────────────────────────────────────── */
    case 'rekomendasi': {
      const items = data.results || data.recommendations || [];
      if (!items.length) return 'Tidak ditemukan rekomendasi jalur yang sesuai.';

      let text = '## 🏔️ Rekomendasi Jalur Pendakian\n\n';

      items.slice(0, 5).forEach((r, i) => {
        // ✅ Real field: mountain_route
        const name  = r.mountain_route || r.name || r.rute || 'Jalur';
        const pct   = r.similarity_score != null
          ? ` *(${(r.similarity_score * 100).toFixed(0)}% match)*`
          : '';

        text += `**${i + 1}. ${name}**${pct}\n`;

        // ✅ Stats nested: r.stats.distance_km, r.stats.elevation_gain_m, etc.
        const s = r.stats || {};
        if (s.difficulty)             text += `⚡ Kesulitan: **${s.difficulty}**\n`;
        if (s.distance_km)            text += `📏 Jarak: **${s.distance_km} km**\n`;
        if (s.elevation_gain_m)       text += `📊 Elevation gain: **${s.elevation_gain_m} m**\n`;
        if (s.naismith_duration_hour) text += `⏱️ Estimasi: **${s.naismith_duration_hour.toFixed(1)} jam**\n`;
        if (s.average_grade_pct != null) text += `📐 Grade: **${s.average_grade_pct.toFixed(1)}%**\n`;
        if (s.min_elevation && s.max_elevation) {
          text += `🗻 Elevasi: **${s.min_elevation}m – ${s.max_elevation}m**\n`;
        }

        // ✅ Narrative (full description string)
        if (r.narrative) {
          const narr = r.narrative.slice(0, 220);
          text += `> ${narr}${r.narrative.length > 220 ? '...' : ''}\n`;
        }

        text += '\n';
      });
      return text;
    }

    /* ─────────────────────────────────────────────────────
       CUACA  →  { mountain, weather_info: ["string", ...] }
    ───────────────────────────────────────────────────── */
    case 'cuaca': {
      const cap = mountain
        ? mountain.charAt(0).toUpperCase() + mountain.slice(1)
        : '';
      let text = `## ☁️ Cuaca${cap ? ` – ${cap}` : ''}\n\n`;

      // ✅ weather_info is an array of pre-formatted strings
      if (Array.isArray(data.weather_info) && data.weather_info.length) {
        data.weather_info.forEach(line => { text += `${line}\n`; });
      } else {
        // Fallback for flat object style
        const d = data.cuaca || data.weather || data;
        if (d.temperature || d.suhu)    text += `🌡️ Suhu: **${d.temperature || d.suhu}°C**\n`;
        if (d.wind_speed  || d.angin)   text += `💨 Angin: **${d.wind_speed || d.angin} km/h**\n`;
        if (d.condition   || d.kondisi) text += `🌤️ Kondisi: **${d.condition || d.kondisi}**\n`;
        if (d.humidity    || d.kelembaban) text += `💧 Kelembaban: **${d.humidity || d.kelembaban}%**\n`;
        if (d.alert       || d.peringatan) text += `\n⚠️ **Peringatan:** ${d.alert || d.peringatan}\n`;
      }
      return text;
    }

    /* ─────────────────────────────────────────────────────
       ITINERARY  →  { mountain, mode, itinerary: "string" }
    ───────────────────────────────────────────────────── */
    case 'itinerary': {
      const cap = mountain
        ? mountain.charAt(0).toUpperCase() + mountain.slice(1)
        : '';
      let text = `## ⏱️ Itinerary${cap ? ` – ${cap}` : ''}`;
      if (mode) text += ` (${mode.toUpperCase()})`;
      text += '\n\n';

      // ✅ itinerary field is a pre-formatted string
      if (typeof data.itinerary === 'string') {
        text += data.itinerary;
      } else if (Array.isArray(data.itinerary)) {
        data.itinerary.forEach(item => {
          text += `**${item.pos || item.name || item.checkpoint}**`;
          if (item.time || item.jam) text += ` – ${item.time || item.jam}`;
          text += '\n';
          if (item.description || item.keterangan) text += `${item.description || item.keterangan}\n`;
          text += '\n';
        });
      } else {
        text += JSON.stringify(data, null, 2);
      }
      return text;
    }

    /* ─────────────────────────────────────────────────────
       LOGISTIK  →  { people, days, logistik_info: "string" }
    ───────────────────────────────────────────────────── */
    case 'logistik': {
      // Try known string field names first
      const str = data.logistik_info || data.info || null;
      if (typeof str === 'string') {
        return `## 🧳 Kalkulator Logistik\n\n${str}`;
      }
      // Try nested object
      const d = (typeof data.logistik === 'object' && data.logistik) ? data.logistik : data;
      let text = '## 🧳 Kalkulator Logistik\n\n';
      if (d.air || d.water)     text += `💧 Air: **${d.air || d.water}**\n`;
      if (d.gas)                text += `🔥 Gas: **${d.gas}**\n`;
      if (d.p3k || d.firstaid) text += `🏥 P3K: **${d.p3k || d.firstaid}**\n`;
      if (d.tenda || d.tent)   text += `⛺ Tenda: **${d.tenda || d.tent}**\n`;
      if (d.makanan || d.food) text += `🍱 Makanan: **${d.makanan || d.food}**\n`;
      // Fallback: dump raw
      if (text === '## 🧳 Kalkulator Logistik\n\n') {
        text += JSON.stringify(data, null, 2);
      }
      return text;
    }

    /* ─────────────────────────────────────────────────────
       BIAYA  →  { mountain, people, days, budget_info: "string" }
    ───────────────────────────────────────────────────── */
    case 'biaya': {
      // ✅ budget_info is a pre-formatted string
      if (typeof data.budget_info === 'string') {
        return `## 💰 Estimasi Biaya Pendakian\n\n${data.budget_info}`;
      }
      const d = data.biaya || data;
      let text = '## 💰 Estimasi Biaya Pendakian\n\n';
      if (d.total)   text += `**Total: Rp ${parseInt(d.total).toLocaleString('id-ID')}**\n\n`;
      if (d.simaksi) text += `🎫 Simaksi: Rp ${parseInt(d.simaksi).toLocaleString('id-ID')}\n`;
      if (d.ojek)    text += `🛵 Ojek: Rp ${parseInt(d.ojek).toLocaleString('id-ID')}\n`;
      if (d.parkir)  text += `🅿️ Parkir: Rp ${parseInt(d.parkir).toLocaleString('id-ID')}\n`;
      if (d.konsumsi || d.makan) {
        text += `🍱 Konsumsi: Rp ${parseInt(d.konsumsi || d.makan).toLocaleString('id-ID')}\n`;
      }
      return text;
    }

    /* ─────────────────────────────────────────────────────
       SURVIVAL  →  { topic, guide: "string" }
    ───────────────────────────────────────────────────── */
    case 'survival': {
      const cap = topic ? topic.charAt(0).toUpperCase() + topic.slice(1) : 'Survival';
      let text = `## 🚑 Panduan – ${cap}\n\n`;

      // ✅ guide is a pre-formatted string
      if (typeof data.guide === 'string') {
        text += data.guide;
      } else {
        const d = data.panduan || data;
        if (typeof d === 'string') text += d;
        else if (d.langkah || d.steps) {
          const steps = d.langkah || d.steps;
          (Array.isArray(steps) ? steps : [steps]).forEach((s, i) => {
            text += `${i + 1}. ${s}\n`;
          });
        } else {
          text += JSON.stringify(d, null, 2);
        }
      }
      return text;
    }

    /* ─────────────────────────────────────────────────────
       PORTER  →  { mountain, porter_info: "string" | [...] }
    ───────────────────────────────────────────────────── */
    case 'porter': {
      const strP = data.porter_info || data.info || null;
      if (typeof strP === 'string') {
        return `## 🧳 Kontak Porter & Basecamp\n\n${strP}`;
      }
      const d = data.porter || data.basecamp || data;
      let text = '## 🧳 Kontak Porter & Basecamp\n\n';
      if (typeof d === 'string') {
        text += d;
      } else if (Array.isArray(d)) {
        d.forEach(item => {
          text += `**${item.name || item.nama || item.mountain_route || 'Porter'}**\n`;
          if (item.phone || item.telp) text += `📞 ${item.phone || item.telp}\n`;
          if (item.tarif) text += `💰 Tarif: Rp ${parseInt(item.tarif).toLocaleString('id-ID')}\n`;
          text += '\n';
        });
      } else {
        text += JSON.stringify(d, null, 2);
      }
      return text;
    }

    default:
      return typeof data === 'string' ? data : JSON.stringify(data, null, 2);
  }
}

/**
 * Extract route chips from rekomendasi response.
 * Uses real field name "mountain_route".
 */
export function extractRoutes(result) {
  if (!result || !result.data) return [];
  if (result.type !== 'rekomendasi') return [];
  const items = result.data.results || result.data.recommendations || [];
  return items.slice(0, 5).map(r => ({
    id: r.id || r.slug || r.mountain_route || r.mountain || String(Math.random()),
    // ✅ Use real field: mountain_route
    name: r.mountain_route || r.name || r.mountain || r.rute || 'Jalur',
    similarity_score: r.similarity_score != null ? r.similarity_score : 0,
  }));
}
