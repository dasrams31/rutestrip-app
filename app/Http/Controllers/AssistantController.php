<?php

namespace App\Http\Controllers;

use App\Models\HikingRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Asisten RuteStrip", the right floating button on every page
 * (resources/views/components/floating-assistants.blade.php): a small Gemini
 * chatbot that answers questions about this project for everyone
 * (POST /assistant, JSON {message, history} -> {reply}). The left button is the
 * community chat between hikers, see CommunityChatController.
 *
 * Gemini is called like webchat/app/api/chat/route.js: ordered model list with
 * fallback, first-token and total timeouts, thinkingBudget 0.
 */
class AssistantController extends Controller
{
    public const MAX_INPUT = 800;
    public const HISTORY_TURNS = 6;
    public const PER_MINUTE = 8;
    public const PER_DAY = 60;
    private const MAX_OUTPUT_TOKENS = 1024;
    private const MAX_ROUTES_IN_PROMPT = 400;
    private const API = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function ask(Request $request): JsonResponse
    {
        $en  = app()->getLocale() === 'en';
        $ip  = (string) $request->ip();
        $keys = ['assistant:min:' . $ip => [self::PER_MINUTE, 60], 'assistant:day:' . $ip => [self::PER_DAY, 86400]];

        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return response()->json([
                    'reply' => __('Terlalu banyak pertanyaan. Tunggu sebentar lalu coba lagi.'),
                    'error' => 'throttled',
                ], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
            }
        }

        $data = $request->validate([
            'message'        => ['required', 'string', 'max:' . self::MAX_INPUT],
            'history'        => ['sometimes', 'nullable', 'array', 'max:50'],
            'history.*.role' => ['required', 'string', 'in:user,assistant,model'],
            'history.*.text' => ['required', 'string', 'max:6000'],
        ]);

        $message = self::clean($data['message']);
        if ($message === '') {
            throw ValidationException::withMessages(['message' => __('validation.required', ['attribute' => 'message'])]);
        }

        foreach ($keys as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }

        $contents   = $this->contents($data['history'] ?? [], $message);
        $reply      = $this->generate($this->systemPrompt($en), $contents);

        if ($reply === null) {
            return response()->json([
                'reply'    => __('Maaf, Asisten RuteStrip sedang tidak bisa menjawab. Coba lagi beberapa saat lagi, atau cari jalur langsung di :url', ['url' => route('search.index')]),
                'fallback' => true,
            ]);
        }

        return response()->json(['reply' => $reply]);
    }

    /** Drop control characters (keeps newlines and tabs) and trim. */
    public static function clean(string $text): string
    {
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';

        return trim(str_replace("\r", '', $text));
    }

    /**
     * Gemini "contents": the last HISTORY_TURNS messages, starting with the user and
     * with alternating roles, followed by the new message.
     */
    private function contents(array $history, string $message): array
    {
        $contents = [];
        foreach (array_slice($history, -self::HISTORY_TURNS) as $turn) {
            $role = ($turn['role'] ?? '') === 'user' ? 'user' : 'model';
            $text = Str::limit(self::clean((string) ($turn['text'] ?? '')), 2000);
            if ($text === '' || ($contents === [] && $role === 'model')) {
                continue;
            }
            $last = array_key_last($contents);
            if ($last !== null && $contents[$last]['role'] === $role) {
                $contents[$last]['parts'][0]['text'] .= "\n\n" . $text;
                continue;
            }
            $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
        }

        $last = array_key_last($contents);
        if ($last !== null && $contents[$last]['role'] === 'user') {
            $contents[$last]['parts'][0]['text'] .= "\n\n" . $message;
        } else {
            $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];
        }

        return $contents;
    }

    /**
     * Try each configured model in order; the next one is used on 429/5xx/timeout
     * or an empty answer. Returns null when no model answered.
     */
    private function generate(string $system, array $contents): ?string
    {
        $key    = (string) config('services.gemini.key');
        $models = (array) config('services.gemini.models', []);
        if ($key === '' || $models === []) {
            return null;
        }

        $firstToken = max(1, (int) config('services.gemini.first_token_timeout_ms', 12000)) / 1000;
        $deadline   = microtime(true) + max(1, (int) config('services.gemini.total_timeout_ms', 30000)) / 1000;

        $lastModel = end($models);
        foreach ($models as $model) {
            // A model that just answered 429 (quota) is skipped for a minute so
            // people don't wait for it to fail again; the last model is always tried.
            $cooldownKey = 'assistant:cooldown:' . $model;
            if ($model !== $lastModel && Cache::has($cooldownKey)) {
                continue;
            }

            $noThinkingKey = 'assistant:no-thinking:' . $model;
            $thinkingOff   = ! Cache::has($noThinkingKey);

            // Retry the same model once without thinkingConfig if it rejects thinkingBudget=0.
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $remaining = $deadline - microtime(true);
                if ($remaining < 1) {
                    Log::warning('Assistant: Gemini deadline reached');

                    return null;
                }

                [$text, $status, $error] = $this->callModel($key, $model, $system, $contents, $thinkingOff, min($firstToken, $remaining), $deadline);
                if ($text !== '') {
                    return $text;
                }

                if ($status === 429) {
                    // Quota/rate limit: expected now and then, skip this model briefly.
                    Cache::put($cooldownKey, true, now()->addSeconds(60));
                    Log::info("Assistant: Gemini {$model} hit its rate limit, skipped for 60 s");
                    break;
                }

                Log::warning("Assistant: Gemini {$model} failed", ['status' => $status, 'error' => Str::limit((string) $error, 300)]);

                if ($thinkingOff && $status === 400 && stripos((string) $error, 'thinking') !== false) {
                    Cache::put($noThinkingKey, true, now()->addDay());
                    $thinkingOff = false;
                    continue;
                }
                break;
            }
        }

        return null;
    }

    /**
     * One streamed request (streamGenerateContent, SSE). read_timeout is the
     * first-token (idle) timeout; the total deadline is checked while reading.
     *
     * @return array{0: string, 1: ?int, 2: ?string} [text, http status, error]
     */
    private function callModel(string $key, string $model, string $system, array $contents, bool $thinkingOff, float $firstToken, float $deadline): array
    {
        $generationConfig = ['maxOutputTokens' => self::MAX_OUTPUT_TOKENS, 'temperature' => 0.4];
        if ($thinkingOff) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->acceptJson()
                ->connectTimeout((int) max(1, min(5, ceil($firstToken))))
                ->timeout((int) max(1, ceil($deadline - microtime(true))))
                ->withOptions(['stream' => true, 'read_timeout' => $firstToken])
                ->post(self::API . rawurlencode($model) . ':streamGenerateContent?alt=sse', [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents'          => $contents,
                    'generationConfig'  => $generationConfig,
                ]);
        } catch (\Throwable $e) {
            return ['', null, $e->getMessage()];
        }

        if ($response->failed()) {
            try {
                $error = $response->body();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            return ['', $response->status(), $error];
        }

        // Keep what was streamed so far if the stream stalls or the deadline passes.
        $raw   = '';
        $error = null;
        try {
            $stream = $response->toPsrResponse()->getBody();
            while (! $stream->eof()) {
                $raw .= $stream->read(8192);
                if (microtime(true) > $deadline) {
                    $error = 'total generation timeout';
                    break;
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $text = trim(self::textFromStream($raw));

        return [$text, $response->status(), $text === '' ? ($error ?? 'empty response') : null];
    }

    /** Text from an SSE stream ("data: {...}") or a plain generateContent JSON body. */
    public static function textFromStream(string $raw): string
    {
        $chunks = [];
        if (preg_match_all('/^data:\s*(.+)$/m', $raw, $m)) {
            foreach ($m[1] as $json) {
                $chunks[] = json_decode(trim($json), true);
            }
        } else {
            $decoded = json_decode($raw, true);
            $chunks  = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
        }

        $text = '';
        foreach ($chunks as $chunk) {
            foreach ((array) ($chunk['candidates'][0]['content']['parts'] ?? []) as $part) {
                if (is_array($part) && empty($part['thought']) && isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }
        }

        return $text;
    }

    // ------------------------------------------------------------------
    // System prompt: curated project knowledge + live facts.
    // ------------------------------------------------------------------

    public function systemPrompt(bool $en): string
    {
        $facts = $this->liveFacts($en);
        $chat  = rtrim((string) config('services.webchat.url'), '/') . '/chat';
        $pages = implode("\n", [
            '- ' . ($en ? 'Search (everyday language)' : 'Cari rute (bahasa sehari-hari)') . ': ' . $facts['urls']['search'],
            '- ' . ($en ? 'All routes' : 'Semua rute') . ': ' . $facts['urls']['routes'],
            '- ' . ($en ? 'Route detail' : 'Detail rute') . ': ' . $facts['urls']['route_pattern'],
            '- ' . ($en ? 'Upload one GPX' : 'Upload satu GPX') . ': ' . $facts['urls']['upload'],
            '- ' . ($en ? 'Batch GPX upload' : 'Upload GPX banyak sekaligus') . ': ' . $facts['urls']['batch'],
            '- ' . ($en ? 'Sign up / log in' : 'Daftar / masuk') . ': ' . $facts['urls']['register'] . ' / ' . $facts['urls']['login'],
            '- ' . ($en ? 'Account settings' : 'Pengaturan akun') . ': ' . $facts['urls']['settings'],
            '- ' . ($en ? 'Dashboard, favorites, search history' : 'Dashboard, favorit, riwayat pencarian') . ': ' . $facts['urls']['dashboard'] . ', ' . $facts['urls']['favorites'] . ', ' . $facts['urls']['history'],
            '- ' . ($en ? 'System architecture' : 'Arsitektur sistem') . ': ' . $facts['urls']['architecture'],
            '- RuteStrip AI Chat: ' . $chat . ($en ? ' ("AI Chat" in the menu)' : ' (menu "AI Chat")'),
            '- ' . ($en ? 'Hiker chat ("Obrolan Pendaki")' : 'Obrolan Pendaki') . ': ' . ($en ? 'the chat button in the bottom left corner of every page (free account needed)' : 'tombol obrolan di pojok kiri bawah setiap halaman (perlu akun gratis)'),
            '- ' . ($en ? 'Telegram bot' : 'Bot Telegram') . ': https://t.me/rutestrip_hikingbot (@rutestrip_hikingbot)',
            '- ' . ($en ? 'Paper' : 'Artikel penelitian') . ': https://sistemasi.ftik.unisi.ac.id/index.php/stmsi/article/view/6087 (DOI https://doi.org/10.32520/stmsi.v15i4.6087)',
            '- ' . ($en ? 'Colab notebook' : 'Notebook Colab') . ': https://s.peka.ai.id/rutestrip-notebook',
            '- GitHub: https://github.com/dasrams31/rutestrip-app',
        ]);

        $routes = $facts['routes'] !== '' ? $facts['routes'] : ($en ? '(no routes yet)' : '(belum ada jalur)');

        if ($en) {
            return <<<TXT
You are "RuteStrip Assistant", the help chatbot on the RuteStrip website. Answer questions about RuteStrip and about hiking the mountains of Java (Indonesia) using only the knowledge below.

Voice: talk like a friendly senior mountain guide who has climbed these trails hundreds of times. Warm, calm, practical, on the hiker's side. Short sentences and everyday words. Do not use technical terms (SBERT, embedding, cosine similarity, Naismith, Shenandoah) unless the person asks how RuteStrip works; then explain them simply.

Rules:
- Answer in English. Be concise (usually 2-5 short sentences or a short list). No emoji. Plain markdown only: **bold**, "- " lists and links written as [text](https://...).
- Only talk about RuteStrip and hiking on Java. For anything else, kindly say you can only help with RuteStrip and hiking.
- Do not quote evaluation results or scores from the research. If someone asks about them, point to the article.
- Never invent routes, numbers, features or links. Routes must come from the route list below; if a route is not there, say RuteStrip does not have it yet and suggest searching or uploading a GPX.
- Point to the relevant page with its full URL.
- For detailed trip planning (itinerary, weather forecast, porters, gear, satellite map) recommend RuteStrip AI Chat ({$chat}), which needs a free account.
- Route data is for planning only, not navigation. Remind people to check trail status and permits with the local management when relevant.

About RuteStrip:
- A hiking route recommender for mountains on Java (plus Anak Krakatau). Visitors describe the route they want in everyday Indonesian or English (for example "gentle route for beginners near Malang") and get ranked recommendations.
- Method: content-based filtering. Each route has a narrative generated from its GPX statistics; the narrative and the query are embedded with SBERT (paraphrase-multilingual-MiniLM-L12-v2, 384 dimensions) and compared with cosine similarity. Hybrid scoring adds per-aspect sentence embeddings, intent words (gentle, beginner, steep, short, long...) checked against the GPX statistics, and mountain/basecamp name matching.
- GPX statistics: 3D distance, elevation gain, average grade, and Naismith time (km/5 + gain/600 hours).
- Difficulty: Shenandoah rating sqrt(gain_ft x 2 x distance_mi), x1.15 when average grade >= 25% and x1.15 when the summit is >= 3,000 m. Easy < 75 <= Moderate < 200 <= Hard < 460 <= Very hard; a climb of 1,200 m or more is at least Hard.
- Route detail page: map, elevation profile, live weather at basecamp and summit, basecamp info, administrative location, directions, likes (no login needed), reviews and ratings (login), favorites, similar routes.
- GPX upload (single or batch) for logged-in users; the uploader is recorded. Provenance: every route keeps GPX versions with SHA-256 hashes, verification and restore history.
- Accounts: one account for the website and the AI Chat (single sign-on), with a settings page (profile, password, language). Dashboard, favorites and search history. Indonesian and English interface (ID/EN switch). Installable as an app (PWA).
- RuteStrip AI Chat ({$chat}, "AI Chat" in the menu): a hiking chat assistant with weather, itinerary, porter info and a satellite map; needs a free account. Also available as the Telegram bot @rutestrip_hikingbot.
- Obrolan Pendaki (hiker chat): the chat button in the bottom left corner opens a public chat room where logged-in hikers talk to each other (real people, not AI). Needs a free account.
- Research paper: R. D. P. Wijaya & A. N. Rahman, "Mountain Hiking Route Recommendation System using Content-based Filtering with SBERT and Cosine Similarity", SISTEMASI: Jurnal Sistem Informasi, Vol. 15 No. 4 (2026), pp. 1241-1256, DOI 10.32520/stmsi.v15i4.6087, SINTA 3 accredited. The live site now has more routes than the paper.
- Dataset: routes.csv, routes.json (with track and elevation profile), embeddings.csv (SBERT vectors), the original GPX files and a data dictionary, in the dataset/ folder of the GitHub repository. Not a navigation tool.
- Data sources and licences: routes from OpenStreetMap hiking relations (c) OpenStreetMap contributors, ODbL 1.0, with NASA SRTM elevation; other routes from the RuteStrip research collection and user uploads. Locations from OpenStreetMap Nominatim, DEM elevation and weather from Open-Meteo.

Pages:
{$pages}

Live data ({$facts['count']} routes on the site now). Format: #id name | difficulty | distance | elevation gain | Naismith time. Route page = {$facts['urls']['route_pattern']} with the id.
{$routes}
TXT;
        }

        return <<<TXT
Kamu adalah "Asisten RuteStrip", chatbot bantuan di situs RuteStrip. Jawab pertanyaan tentang RuteStrip dan pendakian gunung di Pulau Jawa hanya berdasarkan pengetahuan di bawah.

Gaya bicara: seperti pemandu gunung senior yang ramah dan sudah ratusan kali naik. Hangat, tenang, praktis, dan berpihak pada pendaki. Sapa dengan "kamu". Kalimat pendek dan kata sehari-hari. Jangan pakai istilah teknis (SBERT, embedding, cosine similarity, Naismith, Shenandoah) kecuali ditanya cara kerja RuteStrip; kalau ditanya, jelaskan dengan sederhana.

Aturan:
- Jawab dalam Bahasa Indonesia. Ringkas (biasanya 2-5 kalimat pendek atau daftar pendek). Tanpa emoji. Markdown sederhana saja: **tebal**, daftar "- " dan tautan [teks](https://...).
- Hanya bahas RuteStrip dan pendakian di Pulau Jawa. Untuk topik lain, sampaikan dengan ramah bahwa kamu hanya bisa membantu soal RuteStrip dan pendakian.
- Jangan menyebut hasil evaluasi atau skor dari penelitian. Kalau ditanya, arahkan ke artikelnya.
- Jangan mengarang jalur, angka, fitur, atau tautan. Jalur harus dari daftar jalur di bawah; kalau tidak ada, katakan RuteStrip belum punya jalur itu dan sarankan mencari atau upload GPX.
- Arahkan ke halaman yang relevan beserta URL lengkapnya.
- Untuk perencanaan pendakian detail (itinerary, prakiraan cuaca, porter, perlengkapan, peta satelit) sarankan RuteStrip AI Chat ({$chat}), yang perlu akun gratis.
- Data jalur untuk perencanaan, bukan alat navigasi. Bila relevan, ingatkan untuk cek status jalur dan izin ke pengelola setempat.

Tentang RuteStrip:
- Sistem rekomendasi jalur pendakian gunung di Pulau Jawa (ditambah Gunung Anak Krakatau). Pengunjung menuliskan jalur yang dicari dengan bahasa sehari-hari (misalnya "jalur landai untuk pemula dekat Malang") dan mendapat rekomendasi berperingkat.
- Metode: content-based filtering. Setiap jalur punya narasi yang dibuat dari statistik GPX; narasi dan kueri diubah menjadi embedding SBERT (paraphrase-multilingual-MiniLM-L12-v2, 384 dimensi) lalu dibandingkan dengan cosine similarity. Skor hybrid menambahkan embedding per aspek narasi, kata niat (landai, pemula, curam, singkat, panjang, ...) yang dicocokkan dengan statistik GPX, dan pencocokan nama gunung/basecamp.
- Statistik GPX: jarak 3D, total tanjakan (elevation gain), grade rata-rata, dan estimasi waktu Naismith (jarak/5 + tanjakan/600 jam).
- Tingkat kesulitan: skor Shenandoah akar(tanjakan_ft x 2 x jarak_mil), dikali 1,15 bila grade rata-rata >= 25% dan 1,15 bila puncak >= 3.000 m. Mudah < 75 <= Sedang < 200 <= Sulit < 460 <= Sangat Sulit; tanjakan >= 1.200 m minimal Sulit.
- Halaman detail rute: peta, profil elevasi, cuaca terkini di basecamp dan puncak, info basecamp, lokasi administratif, petunjuk arah, suka (tanpa login), ulasan dan rating (perlu login), favorit, rute serupa.
- Upload GPX (satu file atau batch) untuk pengguna yang login; pengunggah dicatat. Provenance: setiap rute menyimpan versi GPX dengan hash SHA-256, riwayat verifikasi dan pemulihan versi.
- Akun: satu akun untuk situs dan AI Chat (single sign-on), dengan halaman pengaturan (profil, kata sandi, bahasa). Dashboard, favorit, dan riwayat pencarian. Tampilan Bahasa Indonesia dan Inggris (tombol ID/EN). Bisa dipasang sebagai aplikasi (PWA).
- RuteStrip AI Chat ({$chat}, menu "AI Chat"): asisten chat pendakian dengan cuaca, itinerary, info porter, dan peta satelit; perlu akun gratis. Juga ada bot Telegram @rutestrip_hikingbot.
- Obrolan Pendaki: tombol obrolan di pojok kiri bawah membuka ruang obrolan umum tempat pendaki yang sudah masuk saling ngobrol (orang sungguhan, bukan AI). Perlu akun gratis.
- Artikel penelitian: R. D. P. Wijaya & A. N. Rahman, "Mountain Hiking Route Recommendation System using Content-based Filtering with SBERT and Cosine Similarity", SISTEMASI: Jurnal Sistem Informasi, Vol. 15 No. 4 (2026), hlm. 1241-1256, DOI 10.32520/stmsi.v15i4.6087, terakreditasi SINTA 3. Situs saat ini punya lebih banyak jalur daripada di paper.
- Dataset: routes.csv, routes.json (dengan jalur dan profil elevasi), embeddings.csv (vektor SBERT), file GPX asli, dan kamus data, di folder dataset/ pada repositori GitHub. Bukan alat navigasi.
- Sumber data dan lisensi: jalur dari relasi pendakian OpenStreetMap (c) kontributor OpenStreetMap, lisensi ODbL 1.0, dengan elevasi NASA SRTM; jalur lain dari koleksi GPX penelitian RuteStrip dan unggahan pengguna. Lokasi dari OpenStreetMap Nominatim, elevasi DEM dan cuaca dari Open-Meteo.

Halaman:
{$pages}

Data terkini ({$facts['count']} jalur di situs saat ini). Format: #id nama | kesulitan | jarak | total tanjakan | waktu Naismith. Halaman rute = {$facts['urls']['route_pattern']} dengan id-nya.
{$routes}
TXT;
    }

    /** Route count, compact route list and page URLs; cached for 10 minutes per language. */
    private function liveFacts(bool $en): array
    {
        return Cache::remember('assistant:facts:' . ($en ? 'en' : 'id'), 600, function () use ($en) {
            $routes = HikingRoute::query()
                ->select(['id', 'name', 'distance_km', 'elevation_gain_m', 'naismith_duration_hour', 'average_grade_pct', 'narrative_text'])
                ->orderBy('name')
                ->get();

            $lines = $routes->take(self::MAX_ROUTES_IN_PROMPT)->map(fn (HikingRoute $r) => sprintf(
                '#%d %s | %s | %s km | +%d m | %s %s',
                $r->id,
                Str::squish((string) $r->name),
                __($r->difficulty_level),
                number_format((float) $r->distance_km, 1, '.', ''),
                (int) $r->elevation_gain_m,
                number_format((float) $r->naismith_duration_hour, 1, '.', ''),
                $en ? 'h' : 'jam'
            ));
            if ($routes->count() > self::MAX_ROUTES_IN_PROMPT) {
                $lines->push('... +' . ($routes->count() - self::MAX_ROUTES_IN_PROMPT));
            }

            return [
                'count'  => $routes->count(),
                'routes' => $lines->implode("\n"),
                'urls'   => [
                    'search'        => route('search.index'),
                    'routes'        => route('routes.index'),
                    'route_pattern' => url('/routes') . '/{id}',
                    'upload'        => route('routes.create'),
                    'batch'         => route('routes.batch'),
                    'register'      => route('register'),
                    'login'         => route('login'),
                    'settings'      => route('settings'),
                    'dashboard'     => route('user.dashboard'),
                    'favorites'     => route('user.favorites'),
                    'history'       => route('user.history'),
                    'architecture'  => route('architecture'),
                ],
            ];
        });
    }
}
