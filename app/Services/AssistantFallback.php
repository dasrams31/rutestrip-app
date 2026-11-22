<?php

namespace App\Services;

use App\Models\HikingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Answers for "Asisten RuteStrip" when no Gemini model replies (no key, quota,
 * timeouts). Instead of a bare "try again later" the visitor gets:
 *  - a short written answer when the question matches a known topic (how search
 *    ranks results, difficulty, walking time, weather, uploads, the Android app...),
 *  - and/or the trails whose name matches words in the question,
 *  - otherwise a pointer to the search page.
 * Everything here is fixed text or comes from the routes table: nothing is invented.
 */
class AssistantFallback
{
    /** Each topic: words that point to it, and the answer in Indonesian / English. */
    private function topics(): array
    {
        $search = route('search.index');
        $routes = route('routes.index');
        $upload = route('routes.create');

        return [
            'ranking' => [
                'words' => ['urut', 'mengurutkan', 'urutan', 'peringkat', 'ranking', 'rank', 'rekomendasi', 'merekomendasikan', 'cara kerja', 'bagaimana rutestrip', 'sbert', 'hasil pencarian', 'order', 'sort', 'recommend', 'how does'],
                'id'    => "Hasil pencarian diurutkan dari yang paling cocok dengan kalimatmu. Setiap jalur punya ringkasan dari data GPX-nya (jarak, total naik, kemiringan, waktu jalan). Ringkasan itu dan kalimat pencarianmu diubah jadi vektor makna (SBERT), lalu dibandingkan kemiripannya. Skornya ditambah kalau kata seperti **landai**, **pemula**, **curam**, atau **singkat** cocok dengan angka GPX jalur, dan kalau nama gunung atau basecamp yang kamu tulis cocok.\n\nCoba langsung di [halaman cari]({$search}).",
                'en'    => "Results are ordered by how well each trail matches your sentence. Every trail has a summary built from its GPX data (distance, climb, grade, walking time). That summary and your query are turned into meaning vectors (SBERT) and compared. The score goes up when words like **gentle**, **beginner**, **steep** or **short** match the trail's GPX numbers, and when the mountain or basecamp name you typed matches.\n\nTry it on the [search page]({$search}).",
            ],
            'difficulty' => [
                'words' => ['kesulitan', 'sulit', 'susah', 'berat', 'mudah', 'gampang', 'difficulty', 'difficult', 'hard', 'easy'],
                'id'    => "Tingkat kesulitan dihitung dari total naik dan jarak jalur (rating Shenandoah), lalu dinaikkan kalau rata-rata kemiringan 25% atau lebih dan kalau puncaknya 3.000 m atau lebih. Hasilnya: Mudah, Sedang, Sulit, atau Sangat Sulit. Tanjakan 1.200 m atau lebih paling ringan dianggap Sulit.",
                'en'    => "Difficulty comes from the trail's total climb and distance (the Shenandoah rating), raised when the average grade is 25% or more and when the summit is 3,000 m or higher. The result is Easy, Moderate, Hard or Very hard. A climb of 1,200 m or more is at least Hard.",
            ],
            'time' => [
                'words' => ['waktu', 'lama', 'durasi', 'berapa jam', 'naismith', 'estimasi', 'duration', 'how long', 'hours'],
                'id'    => "Waktu jalan memakai rumus Naismith: jarak dibagi 5 km/jam, ditambah 1 jam untuk setiap 600 m naik. Itu waktu bergerak, belum termasuk istirahat dan beban carrier, jadi siapkan waktu lebih.",
                'en'    => "Walking time uses Naismith's rule: distance at 5 km/h plus 1 hour for every 600 m of climb. That is moving time only, without breaks or a heavy pack, so plan for more.",
            ],
            'weather' => [
                'words' => ['cuaca', 'hujan', 'prakiraan', 'angin', 'suhu', 'badai', 'weather', 'rain', 'forecast', 'wind'],
                'id'    => "Di halaman setiap jalur ada cuaca terkini di basecamp dan puncak, prakiraan tiap 3 jam untuk 48 jam ke depan (suhu, peluang hujan, angin), dan ringkasan 3 hari. Datanya dari Open-Meteo. Buka jalurnya dari [daftar jalur]({$routes}).",
                'en'    => "Every trail page shows the current weather at the basecamp and summit, a forecast every 3 hours for the next 48 hours (temperature, chance of rain, wind) and a 3-day outlook, from Open-Meteo. Open a trail from the [trail list]({$routes}).",
            ],
            'upload' => [
                'words' => ['upload', 'unggah', 'gpx', 'kirim jalur', 'tambah jalur', 'kiriman', 'rekaman', 'admin', 'disetujui', 'submit', 'add a trail'],
                'id'    => "Kamu bisa menambah jalur dengan [mengunggah file GPX]({$upload}) setelah masuk. Rekaman pendakian dari aplikasi Android juga bisa diajukan jadi jalur: server langsung memprosesnya, lalu jalur tampil di pencarian setelah disetujui admin.",
                'en'    => "You can add a trail by [uploading a GPX file]({$upload}) once signed in. Hikes recorded in the Android app can be submitted as trails too: the server processes them right away and they show in search after an admin approves them.",
            ],
            'app' => [
                'words' => ['aplikasi', 'android', 'apk', 'unduh', 'download', 'install', 'pasang', 'hp', 'app'],
                'id'    => "RuteStrip punya aplikasi Android: rekam jalur dengan GPS (tetap jalan saat layar mati), simpan jalur offline, putar ulang 3D, dan Rombongan untuk chat, posisi teman, serta SOS tanpa sinyal. Unduh di " . url('/install') . " atau lihat fiturnya di https://app.rutestrip.ai.id.",
                'en'    => "RuteStrip has an Android app: GPS recording that keeps going with the screen off, offline saved trails, 3D replay, and Rombongan for chat, teammates' positions and SOS without signal. Download it at " . url('/install') . " or see the features at https://app.rutestrip.ai.id.",
            ],
            'offline' => [
                'words' => ['offline', 'sinyal', 'tanpa internet', 'rombongan', 'mesh', 'sos', 'signal', 'no internet'],
                'id'    => "Tanpa sinyal, pakai aplikasi Android: simpan jalur offline sebelum berangkat, dan aktifkan Rombongan supaya HP satu rombongan saling terhubung lewat Bluetooth dan Wi-Fi untuk chat, posisi teman, dan SOS. Unduh di " . url('/install') . '.',
                'en'    => "With no signal, use the Android app: save the trail offline before you go, and turn on Rombongan so the group's phones connect over Bluetooth and Wi-Fi for chat, positions and SOS. Download it at " . url('/install') . '.',
            ],
            'account' => [
                'words' => ['akun', 'daftar', 'masuk', 'login', 'kata sandi', 'password', 'register', 'account', 'sign in'],
                'id'    => "Satu akun RuteStrip dipakai di situs, aplikasi Android, dan chat. Daftar di " . route('register') . ', masuk di ' . route('login') . ', dan ubah profil atau kata sandi di ' . route('settings') . '.',
                'en'    => "One RuteStrip account works on the site, the Android app and the chat. Sign up at " . route('register') . ', sign in at ' . route('login') . ', and change your profile or password at ' . route('settings') . '.',
            ],
            'data' => [
                'words' => ['sumber data', 'data jalur', 'lisensi', 'openstreetmap', 'dataset', 'license', 'data source'],
                'id'    => "Sebagian jalur berasal dari relasi pendakian OpenStreetMap (© kontributor OpenStreetMap, ODbL) dengan elevasi NASA SRTM; sisanya dari koleksi GPX penelitian dan unggahan pengguna. Lokasi dari Nominatim, cuaca dan elevasi DEM dari Open-Meteo. Data jalur untuk perencanaan, bukan alat navigasi.",
                'en'    => "Some trails come from OpenStreetMap hiking relations (© OpenStreetMap contributors, ODbL) with NASA SRTM elevation; the rest from the research GPX collection and user uploads. Locations from Nominatim, weather and DEM elevation from Open-Meteo. Trail data is for planning, not navigation.",
            ],
            'paper' => [
                'words' => ['paper', 'jurnal', 'penelitian', 'artikel', 'skripsi', 'research', 'journal'],
                'id'    => "Metodenya dibahas di artikel SISTEMASI Vol. 15 No. 4 (2026), DOI https://doi.org/10.32520/stmsi.v15i4.6087.",
                'en'    => "The method is described in the SISTEMASI article, Vol. 15 No. 4 (2026), DOI https://doi.org/10.32520/stmsi.v15i4.6087.",
            ],
        ];
    }

    public function answer(string $message, bool $en): string
    {
        $text   = ' ' . Str::lower(Str::squish($message)) . ' ';
        $best   = null;
        $bestHits = 0;
        foreach ($this->topics() as $topic) {
            $hits = 0;
            foreach ($topic['words'] as $w) {
                // Whole words (or phrases) only: "hp" must not match inside "kemiringan".
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u', $text)) {
                    $hits++;
                }
            }
            if ($hits > $bestHits) {
                [$best, $bestHits] = [$topic, $hits];
            }
        }

        $parts = [];
        if ($best) {
            $parts[] = $en ? $best['en'] : $best['id'];
        }

        $routes = $this->matchingRoutes($text);
        if ($routes) {
            $lines = array_map(fn (HikingRoute $r) => sprintf(
                '- [%s](%s): %s km, +%d m, %s',
                Str::squish((string) $r->name),
                route('routes.show', $r),
                number_format((float) $r->distance_km, 1, $en ? '.' : ',', ''),
                (int) $r->elevation_gain_m,
                __($r->difficulty_level)
            ), $routes);
            $parts[] = ($en ? 'Trails on RuteStrip that match:' : 'Jalur di RuteStrip yang cocok:') . "\n" . implode("\n", $lines);
        }

        if (! $parts) {
            $parts[] = $en
                ? 'I can answer with full sentences again in a moment. Meanwhile, describe the trail you want on the [search page](' . route('search.index') . '), for example "gentle trail for beginners near Malang", or browse [all trails](' . route('routes.index') . ').'
                : 'Sebentar lagi aku bisa menjawab lengkap lagi. Sementara itu, tulis jalur yang kamu cari di [halaman cari](' . route('search.index') . '), misalnya "jalur landai untuk pemula dekat Malang", atau lihat [semua jalur](' . route('routes.index') . ').';
        }

        return implode("\n\n", $parts);
    }

    /** Up to 3 trails whose name shares a distinctive word with the question (e.g. "Merbabu", "Selo"). */
    private function matchingRoutes(string $text): array
    {
        $names = Cache::remember('assistant:fallback:names', 600, fn () => HikingRoute::query()
            ->select(['id', 'name'])->get()->map(fn ($r) => [$r->id, Str::lower((string) $r->name)])->all());

        $common = ['gunung', 'mt', 'via', 'jalur', 'puncak', 'bukit', 'pendakian', 'ke', 'to', 'dan', 'and', 'the', 'di', 'basecamp', 'kawah', 'mount'];
        $words  = array_filter(preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [], fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, $common, true));
        if (! $words) {
            return [];
        }

        $scored = [];
        foreach ($names as [$id, $name]) {
            $score = 0;
            foreach ($words as $w) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u', $name)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scored[$id] = $score;
            }
        }
        if (! $scored) {
            return [];
        }
        arsort($scored);

        $ids = array_slice(array_keys($scored), 0, 3);

        return HikingRoute::whereIn('id', $ids)->get()->sortBy(fn ($r) => array_search($r->id, $ids, true))->values()->all();
    }
}
