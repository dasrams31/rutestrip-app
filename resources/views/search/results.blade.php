@extends('layouts.app')

@section('title', __('Hasil Pencarian'))

@php
    $isEn = app()->getLocale() === 'en';
    $num = fn ($value, int $decimals = 0) => number_format((float) $value, $decimals, $isEn ? '.' : ',', $isEn ? ',' : '.');
    $hoursLabel = function ($hours) {
        $hours = (float) $hours;
        $h = (int) floor($hours);
        $m = (int) round(($hours - $h) * 60);
        if ($m === 60) { $h++; $m = 0; }
        return __(':h j :m mnt', ['h' => $h, 'm' => $m]);
    };
    // Plain words instead of a percentage: how close a route is to what was asked.
    $matchWord = fn (float $score) => match (true) {
        $score >= 70 => __('Sangat cocok'),
        $score >= 50 => __('Cocok'),
        $score >= 30 => __('Lumayan cocok'),
        default => __('Sedikit mirip'),
    };
    $difficultyLevel = fn ($level) => match ($level) { 'Mudah' => 1, 'Sedang' => 2, 'Sulit' => 3, default => 4 };
    $intents = $intents ?? [];
    $plan = $plan ?? [];
    $sortBy = $plan['sort'] ?? null;
    // "A", "A dan B", "A, B dan C"
    $joinList = fn (array $items) => count($items) > 1
        ? implode(', ', array_slice($items, 0, -1)) . ' ' . __('dan') . ' ' . end($items)
        : implode('', $items);
    // What the query filtered on, and what decided the order (shown above the cards).
    $mountainNames = $joinList(array_column(($plan['mountain_filter'] ?? false) ? $plan['mountains'] : [], 'label'));
    $placeNames = $joinList($plan['region_labels'] ?? []);
    $filterLine = match (true) {
        $mountainNames !== '' && $placeNames !== '' => __('Hanya jalur :mountains di :places.', ['mountains' => $mountainNames, 'places' => $placeNames]),
        $mountainNames !== '' => __('Hanya jalur :mountains.', ['mountains' => $mountainNames]),
        $placeNames !== '' => __('Hanya jalur di :places.', ['places' => $placeNames]),
        default => null,
    };
    $sortLine = match ($sortBy) {
        'gentle' => __('Diurutkan dari yang paling landai.'),
        'steep' => __('Diurutkan dari yang paling curam.'),
        'easy' => __('Diurutkan dari yang paling mudah.'),
        'hard' => __('Diurutkan dari yang paling sulit.'),
        'short' => __('Diurutkan dari waktu jalan yang paling singkat (Naismith, belum termasuk istirahat).'),
        'long' => __('Diurutkan dari waktu jalan yang paling lama (Naismith, belum termasuk istirahat).'),
        default => null,
    };
    $intentWords = ['gentle' => __('landai'), 'steep' => __('curam'), 'easy' => __('mudah'), 'hard' => __('sulit'), 'short' => __('singkat'), 'long' => __('panjang')];
    $alsoLine = ! empty($plan['also'])
        ? __('Jalur yang juga :what didahulukan.', ['what' => $joinList(array_map(fn ($i) => $intentWords[$i], $plan['also']))])
        : null;
    $suggestions = [
        __('Jalur landai untuk pemula'),
        __('Pendakian singkat 2-3 jam'),
        __('Jalur curam dan menantang'),
    ];
    $hasFlash = session('success') || session('warning') || (isset($errors) && $errors->any());
@endphp

@include('partials.rs-kit')

@push('styles')
<style>
    .rs-more > summary { list-style: none; cursor: pointer; }
    .rs-more > summary::-webkit-details-marker { display: none; }
    .rs-more[open] .rs-more-icon { transform: rotate(180deg); }
    .result-map .leaflet-control-container { display: none; }
</style>
@endpush

@section('content')
<div class="rs-app">
    {{-- Header: what was asked, and a box to ask again. --}}
    <section class="rs-on-dark relative overflow-hidden bg-rs-z9 text-white {{ $hasFlash ? 'mt-4' : '-mt-4' }}">
        @include('partials.rs-contours', ['seed' => 5, 'tone' => 'dark', 'class' => 'absolute inset-0 w-full h-full pointer-events-none'])
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-5 pb-8 sm:pt-8 sm:pb-12">
            <a href="{{ route('search.index') }}" class="inline-flex items-center gap-2 min-h-[44px] -ml-1 px-1 text-sm font-semibold text-[#a7f3d0] hover:text-white">
                <x-icon name="arrow-left" class="w-4 h-4 shrink-0" /> <span>{{ __('Kembali ke beranda') }}</span>
            </a>
            <p class="mt-1 text-sm sm:text-base font-semibold text-[#a7f3d0]">{{ __('Hasil pencarian untuk') }}</p>
            <h1 class="rs-display mt-1 text-[clamp(2rem,7.5vw,3.5rem)] leading-[0.95] [text-wrap:balance] max-w-4xl break-words">&ldquo;{{ $query }}&rdquo;</h1>
            @if(!isset($message) && $results->isNotEmpty())
            <p class="mt-3 max-w-2xl text-base sm:text-lg leading-relaxed text-[#d1fae5]">
                {{ __($results->count() === 1 ? 'Ada 1 jalur yang cocok.' : 'Ini :count jalur yang paling cocok. Yang paling atas paling pas.', ['count' => $results->count()]) }}
            </p>
            @endif

            <form action="{{ route('search.submit') }}" method="POST" role="search" class="mt-6 max-w-3xl" data-search-form>
                @csrf
                <label for="query-top" class="block text-sm font-semibold text-white mb-2">{{ __('Mau cari yang lain?') }}</label>
                <div class="rs-on-light flex flex-col sm:flex-row gap-2 rounded-xl bg-white p-2">
                    <div class="relative flex-1 min-w-0">
                        <x-icon name="search" class="w-5 h-5 text-rs-muted absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                        <input type="text" id="query-top" name="query" value="{{ $query }}" required minlength="3" maxlength="500"
                               autocomplete="off" enterkeyhint="search"
                               placeholder="{{ __('Misalnya: jalur landai untuk pemula') }}"
                               class="w-full min-h-[52px] pl-11 pr-3 rounded-lg border-0 bg-transparent text-rs-ink placeholder-[#5b6b64] text-base">
                    </div>
                    <button type="submit" data-search-submit class="rs-sign rs-swap place-items-center min-h-[52px] rounded-lg px-6 text-base font-bold">
                        <span data-idle>{{ __('Cari lagi') }}</span>
                        <span data-busy hidden>{{ __('Mencari jalur...') }}</span>
                    </button>
                </div>
                <p class="sr-only" role="status" aria-live="polite" data-search-status data-text="{{ __('Mencari jalur yang cocok, tunggu sebentar.') }}"></p>
            </form>
        </div>
    </section>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
        @if(isset($message))
        {{-- No routes in the database at all. --}}
        <div class="rs-card p-6 sm:p-10 text-center max-w-2xl mx-auto">
            <p class="rs-display uppercase text-3xl text-rs-z9">{{ __('Belum ada jalur untuk dicari.') }}</p>
            <p class="mt-2 text-rs-muted">{{ $message }}</p>
            <a href="{{ route('routes.create') }}" class="rs-sign mt-6">{{ __('Upload file GPX') }}</a>
        </div>
        @elseif($results->isEmpty())
        {{-- Nothing close enough to the question. --}}
        <div class="rs-card p-6 sm:p-10 max-w-2xl mx-auto">
            <p class="rs-display uppercase text-3xl text-rs-z9">{{ __('Belum ketemu jalur yang pas.') }}</p>
            <p class="mt-2 text-rs-muted leading-relaxed">{{ __('Coba tulis dengan cara lain. Sebut nama gunung, berapa lama mau jalan, atau seberapa curam jalurnya.') }}</p>
            <form action="{{ route('search.submit') }}" method="POST" class="mt-5 flex flex-wrap gap-2">
                @csrf
                @foreach($suggestions as $suggestion)
                <button type="submit" name="query" value="{{ $suggestion }}" class="inline-flex items-center min-h-[44px] rounded-lg border border-rs-line bg-white px-3.5 text-sm font-semibold text-rs-z9 hover:border-rs-z7">{{ $suggestion }}</button>
                @endforeach
            </form>
            <a href="{{ route('routes.index') }}" class="mt-5 inline-flex items-center min-h-[44px] font-semibold text-rs-z7 underline underline-offset-4">{{ __('Lihat semua jalur') }}</a>
        </div>
        @else
        @if($filterLine || $sortLine)
        <div class="mb-6 space-y-1 text-sm sm:text-base text-rs-ink" data-search-plan>
            @if($filterLine)
            <div class="flex flex-wrap items-center gap-x-4">
                <p class="font-semibold">{{ $filterLine }}</p>
                <form action="{{ route('search.submit') }}" method="POST" data-search-form>
                    @csrf
                    <input type="hidden" name="query" value="{{ $query }}">
                    <input type="hidden" name="all_routes" value="1">
                    <button type="submit" class="inline-flex items-center min-h-[44px] font-semibold text-rs-z7 underline underline-offset-4 hover:text-rs-z9">{{ __('Cari di semua jalur') }}</button>
                </form>
            </div>
            @endif
            @if($sortLine)
            <p>{{ $sortLine }}@if($alsoLine) {{ $alsoLine }}@endif</p>
            @endif
        </div>
        @endif

        @if(!empty($notice))
        <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 border-l-4 border-l-amber-600 px-4 py-3 text-sm text-amber-950 flex items-start gap-3" role="note">
            <x-icon name="exclamation" class="w-5 h-5 shrink-0 text-amber-700 mt-0.5" />
            <p>{{ $notice }}</p>
        </div>
        @endif

        <ol class="grid md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
            @foreach($results as $index => $route)
            @php
                // Track preview: at most ~150 points, 5 decimals (~1 m), keeps the page light.
                $trackPreview = [];
                $rawCoords = is_array($route->route_coordinates) ? array_values($route->route_coordinates) : [];
                $rawCount = count($rawCoords);
                if ($rawCount >= 2) {
                    $keep = min($rawCount, 150);
                    $step = ($rawCount - 1) / ($keep - 1);
                    for ($i = 0; $i < $keep; $i++) {
                        $pt = $rawCoords[(int) round($i * $step)];
                        if (is_array($pt) && isset($pt[0], $pt[1]) && is_numeric($pt[0]) && is_numeric($pt[1])) {
                            $trackPreview[] = [round((float) $pt[0], 5), round((float) $pt[1], 5)];
                        }
                    }
                }
                $score = (float) $route->similarity_score;
                $level = $difficultyLevel($route->difficulty_level);
                $grade = $num($route->average_grade_pct, 1) . '%';
                $gain = $num($route->elevation_gain_m);
                // One sentence on why it showed up, from the numbers that set the order.
                $why = match ($sortBy) {
                    'gentle', 'steep' => __('Kemiringan rata-rata :grade, total naik :gain m.', ['grade' => $grade, 'gain' => $gain]),
                    'easy', 'hard' => __('Total naik :gain m sepanjang :km km.', ['gain' => $gain, 'km' => $num($route->distance_km, 1)]),
                    'short' => __('Sekitar :time jalan kaki sampai puncak.', ['time' => $hoursLabel($route->naismith_duration_hour)]),
                    'long' => __('Sekitar :time jalan kaki, panjang jalur :km km.', ['time' => $hoursLabel($route->naismith_duration_hour), 'km' => $num($route->distance_km, 1)]),
                    default => null,
                };
                $gpxStatus = $route->activeGpxVersion?->verification_status;
            @endphp
            <li class="rs-card overflow-hidden flex flex-col min-w-0 relative group hover:border-rs-z7 transition-colors">
                <div class="relative h-40 shrink-0 bg-rs-mist overflow-hidden">
                    @if(count($trackPreview) >= 2)
                    <div class="result-map absolute inset-0 pointer-events-none" data-track='@json($trackPreview)' aria-hidden="true"></div>
                    <span class="absolute bottom-1 right-2 z-[1] text-[10px] leading-none text-rs-ink/70 bg-white/80 px-1 rounded pointer-events-none">&copy; OpenStreetMap</span>
                    @else
                    <div class="absolute inset-0 flex items-center justify-center text-rs-z7"><x-icon name="mountain" class="w-10 h-10" /></div>
                    @endif
                    <span class="absolute top-3 left-3 z-[1] min-w-[2.5rem] h-10 px-2 rounded-lg bg-white border-2 border-rs-z9 rs-display rs-num text-2xl leading-none text-rs-z9 flex items-center justify-center">
                        <span class="sr-only">{{ __('Urutan') }}</span>{{ $index + 1 }}
                    </span>
                </div>

                <div class="p-5 flex-1 flex flex-col">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="rs-chip {{ $score >= 50 ? 'rs-chip-ok' : 'rs-chip-plain' }}" title="{{ __('Skor :score dari 100', ['score' => $num($score)]) }}">
                            {{ $matchWord($score) }}<span class="sr-only">, {{ __('skor :score dari 100', ['score' => $num($score)]) }}</span>
                        </span>
                        <span class="inline-flex items-center gap-2 text-sm font-semibold text-rs-ink">
                            <span class="rs-diff" aria-hidden="true">@for($i = 1; $i <= 4; $i++)<i class="{{ $i <= $level ? 'on' : '' }}"></i>@endfor</span>
                            <span><span class="sr-only">{{ __('Tingkat kesulitan') }}:</span> {{ __($route->difficulty_level) }}</span>
                        </span>
                    </div>

                    <h2 class="mt-3 rs-display uppercase text-[1.75rem] leading-none text-rs-z9 break-words">
                        <a href="{{ route('routes.show', $route) }}" class="after:absolute after:inset-0 after:content-[''] group-hover:text-rs-z7">{{ $route->name }}</a>
                    </h2>

                    @if($gpxStatus && $gpxStatus !== 'verified')
                    <p class="mt-2"><span class="rs-chip {{ $gpxStatus === 'invalid' ? 'rs-chip-bad' : 'rs-chip-warn' }}">{{ $gpxStatus === 'invalid' ? __('File GPX bermasalah') : __('File GPX belum diperiksa') }}</span></p>
                    @endif

                    @if($why)
                    <p class="mt-3 text-sm text-rs-ink leading-relaxed">{{ $why }}</p>
                    @elseif($route->narrative_text)
                    <p class="mt-3 text-sm text-rs-muted leading-relaxed">{{ \Illuminate\Support\Str::limit($route->narrative_text, 130) }}</p>
                    @endif

                    <div class="mt-auto"></div>
                    <dl class="mt-4 pt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-rs-line">
                        <div><dt class="text-xs text-rs-muted">{{ __('Jarak') }}</dt><dd class="rs-display rs-num text-xl text-rs-ink">{{ $num($route->distance_km, 1) }} km</dd></div>
                        <div><dt class="text-xs text-rs-muted">{{ __('Total naik') }}</dt><dd class="rs-display rs-num text-xl text-rs-ink">{{ $gain }} m</dd></div>
                        <div><dt class="text-xs text-rs-muted">{{ $route->duration_label }}</dt><dd class="rs-display rs-num text-xl text-rs-ink">{{ $hoursLabel($route->naismith_duration_hour) }}</dd></div>
                        <div><dt class="text-xs text-rs-muted">{{ __('Kemiringan rata-rata') }}</dt><dd class="rs-display rs-num text-xl text-rs-ink">{{ $grade }}</dd></div>
                    </dl>
                    <p class="mt-4 text-xs text-rs-muted truncate">{{ __('Diunggah oleh') }} <span class="font-semibold text-rs-ink">{{ $route->uploader_name }}</span></p>
                </div>
            </li>
            @endforeach
        </ol>

        <details class="rs-more mt-10 max-w-3xl rounded-lg border border-rs-line bg-white">
            <summary class="flex items-center justify-between gap-3 min-h-[48px] px-4 py-3 font-semibold text-rs-z9">
                <span>{{ __('Cara kami mencocokkan (untuk yang penasaran)') }}</span>
                <x-icon name="chevron-down" class="rs-more-icon w-5 h-5 shrink-0 transition-transform" />
            </summary>
            <div class="px-4 pb-4 text-sm text-rs-muted leading-relaxed space-y-2">
                <p>{{ __('Label cocok berasal dari skor 0 sampai 100: Sangat cocok mulai 70, Cocok mulai 50, Lumayan cocok mulai 30.') }}</p>
                <p>{{ __('Kalimatmu diubah menjadi 384 angka oleh model bahasa SBERT multibahasa (paraphrase-multilingual-MiniLM-L12-v2), lalu dibandingkan dengan gambaran setiap jalur memakai cosine similarity: 40% dari gambaran utuh dan 60% dari kalimat yang paling mirip.') }}</p>
                <p>{{ __('Kalau kamu menyebut nama gunung atau daerah, hanya jalur di sana yang ditampilkan. Kata seperti landai, mudah, curam, singkat, atau panjang menentukan urutan dari statistik GPX, dan kemiripan kalimat hanya dipakai kalau angkanya sama.') }}</p>
                @isset($searchTime)
                <p>{{ __('Pencarian ini selesai dalam :ms milidetik.', ['ms' => $num($searchTime)]) }}</p>
                @endisset
            </div>
        </details>
        @endif
    </div>
</div>

@push('scripts')
<script>
// Static track previews in the result cards. Maps are created only when a card
// scrolls near the viewport, so ten results never start at once.
(function () {
    var nodes = document.querySelectorAll('.result-map[data-track]');
    if (!nodes.length || typeof L === 'undefined') return;

    function initMap(el) {
        if (el.dataset.ready) return;
        el.dataset.ready = '1';
        var coords;
        try { coords = JSON.parse(el.dataset.track); } catch (e) { return; }
        if (!coords || coords.length < 2) return;

        var map = L.map(el, {
            zoomControl: false, attributionControl: false, dragging: false, touchZoom: false,
            doubleClickZoom: false, scrollWheelZoom: false, boxZoom: false, keyboard: false,
            tap: false, inertia: false, zoomSnap: 0.25
        });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 17 }).addTo(map);
        L.polyline(coords, { color: '#ffffff', weight: 7, opacity: 0.8, lineCap: 'round', lineJoin: 'round' }).addTo(map);
        var line = L.polyline(coords, { color: '#047857', weight: 4, opacity: 1, lineCap: 'round', lineJoin: 'round' }).addTo(map);
        L.circleMarker(coords[0], { radius: 5, color: '#ffffff', weight: 2, fillColor: '#053b30', fillOpacity: 1 }).addTo(map);
        map.fitBounds(line.getBounds(), { paddingTopLeft: [56, 20], paddingBottomRight: [20, 20] });
    }

    if (!('IntersectionObserver' in window)) {
        nodes.forEach(initMap);
        return;
    }
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                io.unobserve(entry.target);
                initMap(entry.target);
            }
        });
    }, { rootMargin: '200px 0px' });
    nodes.forEach(function (el) { io.observe(el); });
})();
</script>
@endpush
@endsection
