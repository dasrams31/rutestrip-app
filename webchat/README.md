# 🏔️ RuteStrip AI – Chatbot Website

Website chatbot AI pendakian gunung berbasis **Next.js**, siap deploy ke **Vercel**.  
Menggunakan REST API dari [RuteStrip Bot](https://gitlab.com/RamsNotes31/rutestrip-bot).

## ⚡ Quickstart Lokal

```bash
# Install dependencies
npm install

# Jalankan dev server
npm run dev
```

Buka [http://localhost:3000](http://localhost:3000)

## 🚀 Deploy ke Vercel

### Cara 1: Vercel CLI
```bash
npm install -g vercel
vercel --prod
```

### Cara 2: Push ke GitHub → Import di Vercel
1. Push project ini ke GitHub/GitLab
2. Buka [vercel.com/new](https://vercel.com/new)
3. Import repo → otomatis detect Next.js
4. Deploy!

## 🌐 API Endpoints yang Digunakan

Base URL: nilai `RUTESTRIP_API_BASE` (default `http://127.0.0.1:8010`, lihat `../webchat-api`).

| Endpoint | Fungsi | Contoh Query |
|---|---|---|
| `/api/rekomendasi` | AI SBERT Recommendation | "jalur pemula merbabu" |
| `/api/cuaca` | Forecast Cuaca Live | "cuaca sumbing" |
| `/api/map/satellite` | Peta Satelit/Topografi | - |
| `/api/gpx` | Download GPX/KML | "gpx ciremai" |
| `/api/itinerary` | Naismith Itinerary | "itinerary sumbing 2d1n" |
| `/api/logistik` | Kalkulator Logistik | "logistik 4 2" |
| `/api/biaya` | Estimasi Biaya | "biaya sumbing 4 2" |
| `/api/survival` | Panduan Survival | "survival hipotermia" |
| `/api/porter` | Kontak Porter | "porter merbabu" |

## 🗂️ Struktur Project

```
rutestrip-chat/
├── app/
│   ├── layout.js           # Root layout (Inter font, SEO metadata)
│   ├── page.js             # Redirect ke /chat
│   ├── globals.css         # Design system (emerald/slate palette)
│   ├── chat/
│   │   └── page.js         # Halaman chat
│   └── api/
│       └── chat/
│           └── route.js    # Server-side API proxy (Edge Runtime)
├── components/
│   └── ChatInterface.js    # Komponen chat utama
├── lib/
│   ├── api.js              # API wrapper + smart router
│   └── formatter.js        # Response formatter (API → Markdown)
├── public/
│   └── logo.svg            # Logo RuteStrip
├── next.config.mjs
└── vercel.json
```

## 🎨 Desain

- **Font**: Inter (Google Fonts)
- **Warna**: Emerald (#059669) + Slate palette
- **Logo**: Mountain SVG dengan gradient emerald-teal
- Sama persis dengan rutestripV2

## 📋 Fitur

- ✅ Chat interface full-screen
- ✅ Smart API routing (detect keyword query)
- ✅ 4 quick suggestion cards
- ✅ Pipeline info toggle (SBERT → Gemini)
- ✅ Voice input (Web Speech API, Bahasa Indonesia)
- ✅ Markdown rendering
- ✅ Route chips dengan similarity score
- ✅ Export PDF
- ✅ Responsive mobile/desktop
- ✅ Server-side proxy (CORS-safe)
- ✅ Edge Runtime (ultra-fast on Vercel)
