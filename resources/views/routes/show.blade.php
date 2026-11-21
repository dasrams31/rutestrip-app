@extends('layouts.app')

@section('title', __('Jalur Pendakian :name', ['name' => $route->name]))
@section('og_type', 'article')
@section('canonical', route('routes.show', $route))
@php
    $seoParts = array_filter([
        $route->distance_km ? number_format($route->distance_km, 1, ',', '.') . ' km' : null,
        $route->elevation_gain_m ? __('total tanjakan :m m', ['m' => number_format($route->elevation_gain_m, 0, ',', '.')]) : null,
        $route->naismith_duration_hour ? __('estimasi :h jam (Naismith)', ['h' => number_format($route->naismith_duration_hour, 1, ',', '.')]) : null,
        $route->average_grade_pct ? __('kemiringan rata-rata :pct%', ['pct' => number_format($route->average_grade_pct, 1, ',', '.')]) : null,
    ]);
    $seoStart = is_array($route->route_coordinates) && count($route->route_coordinates) ? $route->route_coordinates[0] : null;
@endphp
@section('meta_description', __('Jalur pendakian :name: :facts. Lihat peta jalur, profil, dan unduh file GPX di RuteStrip.', ['name' => $route->name, 'facts' => implode(', ', $seoParts)]))
@push('head')
<script type="application/ld+json">{!! json_encode(array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'TouristAttraction',
    'name' => __('Jalur Pendakian :name', ['name' => $route->name]),
    'description' => $route->description ?: $route->narrative_text,
    'url' => route('routes.show', $route),
    'touristType' => __('Pendaki gunung'),
    'isAccessibleForFree' => true,
    'geo' => $seoStart ? ['@type' => 'GeoCoordinates', 'latitude' => $seoStart[0], 'longitude' => $seoStart[1]] : null,
    'containedInPlace' => ['@type' => 'Place', 'name' => __('Pulau Jawa, Indonesia')],
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'RuteStrip', 'item' => url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => __('Jalur Pendakian'), 'item' => route('routes.index')],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $route->name, 'item' => route('routes.show', $route)],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

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
    $level = match ($route->difficulty_level) { 'Mudah' => 1, 'Sedang' => 2, 'Sulit' => 3, default => 4 };
    // Small track drawing (SVG, no map tiles) for the similar-route list.
    $trackPath = function ($coords, int $w = 64, int $h = 64, int $pad = 6) {
        $coords = is_array($coords) ? array_values(array_filter($coords, fn ($p) => is_array($p) && isset($p[0], $p[1]))) : [];
        $n = count($coords);
        if ($n < 2) return null;
        $step = max(1, (int) ceil($n / 60));
        $track = [];
        foreach ($coords as $i => $p) if ($i % $step === 0 || $i === $n - 1) $track[] = [(float) $p[0], (float) $p[1]];
        $lats = array_column($track, 0); $lngs = array_column($track, 1);
        $kx = cos(deg2rad((min($lats) + max($lats)) / 2));
        $spanX = max((max($lngs) - min($lngs)) * $kx, 1e-6); $spanY = max(max($lats) - min($lats), 1e-6);
        $scale = min(($w - 2 * $pad) / $spanX, ($h - 2 * $pad) / $spanY);
        $ox = ($w - $spanX * $scale) / 2; $oy = ($h - $spanY * $scale) / 2;
        return collect($track)->map(fn ($p, $i) => ($i ? 'L' : 'M') . round($ox + ($p[1] - min($lngs)) * $kx * $scale, 1) . ' ' . round($oy + (max($lats) - $p[0]) * $scale, 1))->implode(' ');
    };
    $similarWord = fn (float $score) => match (true) {
        $score >= 85 => __('Sangat mirip'),
        $score >= 70 => __('Mirip'),
        default => __('Agak mirip'),
    };
    $version = $route->activeGpxVersion;
    $statusWord = match ($version?->verification_status) {
        'verified' => __('Terverifikasi'),
        'invalid' => __('Bermasalah'),
        default => __('Belum diperiksa'),
    };
    $reviewCount = $route->ratings->count();
    $hasPractical = $route->basecamp_name || $route->contact_phone || $route->entry_fee || $route->facilities || $route->best_season || $route->tips;
    $hasFlash = session('success') || session('warning') || (isset($errors) && $errors->any());
@endphp

@include('partials.rs-kit')

@push('styles')
<style>
    .rs-more > summary { list-style: none; cursor: pointer; }
    .rs-more > summary::-webkit-details-marker { display: none; }
    .rs-more[open] .rs-more-icon { transform: rotate(180deg); }
    /* Difficulty bars on the dark header: white instead of zamrud. */
    .rs-on-dark .rs-diff i { background: rgba(255,255,255,.3); }
    .rs-on-dark .rs-diff i.on { background: #fff; }
</style>
@endpush

@section('content')
@inject('conditionsService', 'App\Services\RouteConditionsService')
@php
    // Cache-only read: the page never waits on external APIs.
    $trailPoints = $conditionsService->points($route, false);
@endphp
<div class="rs-app overflow-x-clip">
    {{-- ================= HEADER: name, the four numbers that matter, like ================= --}}
    <section class="rs-on-dark relative overflow-hidden bg-rs-z9 text-white {{ $hasFlash ? 'mt-4' : '-mt-4' }}">
        @include('partials.rs-contours', ['seed' => $route->id % 29 + 3, 'tone' => 'dark', 'class' => 'absolute inset-0 w-full h-full pointer-events-none'])
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-5 pb-7 sm:pt-8 sm:pb-10">
            <a href="{{ route('routes.index') }}" class="inline-flex items-center gap-2 min-h-[44px] -ml-1 px-1 text-sm font-semibold text-[#a7f3d0] hover:text-white">
                <x-icon name="arrow-left" class="w-4 h-4 shrink-0" /> <span>{{ __('Semua jalur') }}</span>
            </a>
            <p class="mt-2 flex items-center gap-2 text-sm sm:text-base font-semibold text-[#d1fae5]">
                <span class="rs-diff" aria-hidden="true">@for($i = 1; $i <= 4; $i++)<i class="{{ $i <= $level ? 'on' : '' }}"></i>@endfor</span>
                <span><span class="sr-only">{{ __('Tingkat kesulitan') }}:</span> {{ __($route->difficulty_level) }}</span>
            </p>
            <h1 class="rs-display mt-2 uppercase text-[clamp(2.25rem,9vw,4.5rem)] leading-[0.92] [text-wrap:balance] max-w-4xl break-words">{{ $route->name }}</h1>
            <p class="mt-3 max-w-2xl text-[#d1fae5] leading-relaxed">{{ __($route->difficulty_hint) }}</p>
            <p class="mt-1 text-sm text-[#b7cfc4] break-words">{{ __('Diunggah oleh') }} <span class="font-semibold text-white">{{ $route->uploader_name }}</span> {{ __('pada') }} {{ $route->created_at->translatedFormat('d M Y') }}</p>

            <dl class="mt-6 grid grid-cols-2 md:grid-cols-4 border-t border-white/25">
                @foreach([
                    [__('Jarak'), $num($route->distance_km, 1) . ' km'],
                    [$route->duration_label, $hoursLabel($route->naismith_duration_hour)],
                    [__('Total naik'), $num($route->elevation_gain_m) . ' m'],
                    [__('Kemiringan rata-rata'), $num($route->average_grade_pct, 1) . '%'],
                ] as [$term, $value])
                <div class="flex flex-col-reverse justify-end py-3 sm:py-4 pr-3 {{ $loop->index % 2 ? 'pl-4 border-l border-white/15' : '' }} md:pl-5 md:border-l md:border-white/15 {{ $loop->first ? 'md:!pl-0 md:!border-l-0' : '' }} {{ $loop->index < 2 ? 'border-b border-white/15 md:border-b-0' : '' }}">
                    <dt class="text-sm text-[#d1fae5]">{{ $term }}</dt>
                    <dd class="rs-display rs-num text-3xl sm:text-4xl leading-none">{{ $value }}</dd>
                </div>
                @endforeach
            </dl>
            @if($route->steepest_grade_pct !== null)
            {{-- The average hides short steep parts, so name the steepest stretch too. --}}
            <p class="mt-3 text-sm text-[#d1fae5]">
                {{ __('Bagian paling curam: sekitar :grade% sepanjang 1 km.', ['grade' => $num($route->steepest_grade_pct, 0)]) }}
            </p>
            @endif
            @if($route->naismith_duration_hour)
            {{-- Naismith assumes a fit walker without breaks; say so next to the number. --}}
            <p class="{{ $route->steepest_grade_pct !== null ? 'mt-1' : 'mt-3' }} text-sm text-[#d1fae5]">
                {{ __('Dihitung dengan rumus Naismith, belum termasuk istirahat dan beban carrier.') }}
            </p>
            @endif

            {{-- Likes work without login (guests use the rs_vid cookie). --}}
            <div class="mt-5 flex flex-wrap items-center gap-2"
                 x-data="likeWidget(@js(route('routes.like.toggle', $route)), @js((bool) $likedByViewer), @js($route->likes_count))">
                <form action="{{ route('routes.like.toggle', $route) }}" method="POST" @submit.prevent="toggle()">
                    @csrf
                    <input type="hidden" name="liked" value="{{ $likedByViewer ? 0 : 1 }}" :value="liked ? 0 : 1">
                    <button type="submit" data-liked="{{ $likedByViewer ? 'true' : 'false' }}" :data-liked="liked.toString()"
                            aria-pressed="{{ $likedByViewer ? 'true' : 'false' }}" :aria-pressed="liked.toString()" :disabled="busy"
                            class="inline-flex items-center gap-2 min-h-[48px] rounded-lg px-4 font-bold border-2 transition-colors disabled:opacity-60
                                   border-[#a7f3d0] text-white hover:bg-white/10
                                   data-[liked=true]:bg-white data-[liked=true]:border-white data-[liked=true]:text-rs-z9 data-[liked=true]:hover:bg-[#ecfdf5]">
                        <x-icon name="heart" class="w-5 h-5" />
                        <span x-show="liked" @unless($likedByViewer) x-cloak @endunless>{{ __('Batal Suka') }}</span>
                        <span x-show="!liked" @if($likedByViewer) x-cloak @endif>{{ __('Suka jalur ini') }}</span>
                        <span class="rs-num font-semibold opacity-80" aria-hidden="true">·</span>
                        <span class="rs-num"><span x-text="count.toLocaleString(document.documentElement.lang)">{{ $route->likes_count }}</span><span class="sr-only"> {{ __('suka') }}</span></span>
                    </button>
                </form>
                <a href="#ulasan" class="inline-flex items-center gap-2 min-h-[48px] rounded-lg px-4 font-semibold text-white hover:bg-white/10">
                    <x-icon name="chat" class="w-5 h-5" /> {{ $reviewCount ? __($reviewCount === 1 ? '1 ulasan' : ':count ulasan', ['count' => $reviewCount]) : __('Ulasan') }}
                </a>
                @if($version)
                <a href="{{ route('routes.provenance.download-version', [$route, $version]) }}" class="inline-flex items-center gap-2 min-h-[48px] rounded-lg px-4 font-semibold text-white hover:bg-white/10">
                    <x-icon name="arrow-up" class="w-5 h-5 rotate-180" /> {{ __('Unduh GPX') }}
                </a>
                @endif
                <p x-show="error" x-cloak x-text="error" class="w-full text-sm font-semibold text-[#fecaca]" role="alert"></p>
                @guest
                <p class="w-full text-xs text-[#b7cfc4]">{{ __('Tidak perlu masuk akun untuk menyukai jalur.') }}</p>
                @endguest
            </div>
        </div>
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-10 grid grid-cols-1 lg:grid-cols-12 gap-5 sm:gap-6 items-start"
         @if($trailPoints) x-data="routeConditions(@js(route('routes.conditions', $route)), @js($trailPoints))" x-init="load()" @endif>

        {{-- ================= MAIN COLUMN ================= --}}
        <div class="lg:col-span-8 space-y-5 sm:space-y-6 min-w-0">

            @if($route->route_coordinates && count($route->route_coordinates) > 0)
            <section class="rs-card overflow-hidden" aria-labelledby="map-title">
                <div class="px-4 sm:px-5 py-3 flex flex-wrap items-center justify-between gap-2 border-b border-rs-line">
                    <h2 id="map-title" class="rs-display uppercase text-2xl text-rs-z9 leading-none">{{ __('Peta jalur') }}</h2>
                    <p class="flex items-center gap-4 text-xs text-rs-muted">
                        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-rs-z9 ring-2 ring-white outline outline-1 outline-rs-z9"></span>{{ __('Titik awal') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-amber-500 ring-2 ring-white outline outline-1 outline-amber-700"></span>{{ __('Titik akhir') }}</span>
                    </p>
                </div>
                <div id="route-map" class="w-full h-[300px] sm:h-[420px] bg-rs-mist"></div>
            </section>
            @endif

            @if($trailPoints)
            {{-- Live conditions (Open-Meteo), loaded after the page. --}}
            <section id="kondisi-terkini" class="rs-card overflow-hidden" aria-labelledby="weather-title" aria-live="polite">
                <div class="px-4 sm:px-5 py-3 flex flex-wrap items-center justify-between gap-2 border-b border-rs-line">
                    <h2 id="weather-title" class="rs-display uppercase text-2xl text-rs-z9 leading-none">{{ __('Kondisi Terkini') }}</h2>
                    <span x-show="weather" x-cloak class="text-xs font-medium text-rs-muted" x-text="weather ? @js(__('Diperbarui')) + ' ' + weather.updated_label : ''"></span>
                </div>

                <div class="p-4 sm:p-5 space-y-4">
                    <div x-show="!loading && !weather" x-cloak class="rounded-lg border border-rs-line bg-rs-paper p-4 text-sm text-rs-ink">
                        {{ __('Data cuaca tidak tersedia saat ini. Coba muat ulang halaman beberapa saat lagi.') }}
                    </div>

                    <div x-show="loading || weather" class="grid sm:grid-cols-2 gap-3 sm:gap-4">
                        @foreach(['basecamp' => __('Basecamp'), 'summit' => __('Puncak')] as $side => $label)
                        <div class="rounded-lg bg-rs-mist p-4 min-w-0">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="text-sm font-semibold text-rs-ink">{{ $label }}</p>
                                <p class="text-xs text-rs-muted" x-text="ele(points.{{ $side }})"></p>
                            </div>
                            <div class="mt-2 flex flex-wrap items-end gap-x-3 gap-y-1">
                                <span x-show="loading" class="inline-block h-9 w-20 rounded-md bg-[#dfe7e1] motion-safe:animate-pulse"></span>
                                <p x-show="!loading" x-cloak class="rs-display rs-num text-5xl text-rs-z9 leading-none" x-text="num(w('{{ $side }}', 'temperature_c')) + '°C'"></p>
                                <p x-show="!loading" x-cloak class="text-sm font-semibold text-rs-ink pb-1" x-text="w('{{ $side }}', 'weather_text') || ''"></p>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                                @foreach([
                                    __('Terasa seperti') => "num(w('$side', 'apparent_temperature_c')) + '°C'",
                                    __('Angin') => "num(w('$side', 'wind_speed_kmh')) + ' " . __('km/jam') . "' + (w('$side', 'wind_direction') ? ' " . __('dari') . " ' + w('$side', 'wind_direction') : '')",
                                    __('Kelembapan') => "num(w('$side', 'humidity_pct')) + '%'",
                                    __('Curah hujan') => "num(w('$side', 'precipitation_mm'), 1) + ' mm'",
                                ] as $term => $expr)
                                <div class="min-w-0">
                                    <dt class="text-xs text-rs-muted">{{ $term }}</dt>
                                    <dd class="font-semibold text-rs-ink break-words">
                                        <span x-show="loading" class="inline-block h-4 w-16 rounded bg-[#dfe7e1] motion-safe:animate-pulse align-middle"></span>
                                        <span x-show="!loading" x-cloak x-text="{{ $expr }}"></span>
                                    </dd>
                                </div>
                                @endforeach
                            </dl>
                        </div>
                        @endforeach
                    </div>

                    <p x-show="weather && weather.sunrise" x-cloak class="text-sm text-rs-ink flex items-center gap-2">
                        <x-icon name="sun" class="w-4 h-4 text-rs-z7 shrink-0" />
                        <span x-text="weather ? @js(__('Matahari terbit')) + ' ' + weather.sunrise + ', ' + @js(__('terbenam')) + ' ' + weather.sunset + ' WIB' : ''"></span>
                    </p>

                    <div x-show="safety" x-cloak class="rounded-lg border border-l-4 p-4 text-sm"
                         :class="safety && safety.level === 'caution' ? 'bg-amber-50 border-amber-300 border-l-amber-600 text-amber-950' : 'bg-[#ecfdf5] border-[#a7f3d0] border-l-rs-z7 text-rs-z9'">
                        <p class="font-bold flex items-center gap-2 mb-1">
                            <x-icon name="exclamation" class="w-4 h-4 shrink-0" />
                            <span x-text="safety && safety.level === 'caution' ? @js(__('Perhatian')) : @js(__('Catatan keselamatan'))"></span>
                        </p>
                        <ul class="space-y-1 list-disc pl-5">
                            <template x-for="message in (safety ? safety.messages : [])" :key="message">
                                <li x-text="message"></li>
                            </template>
                        </ul>
                    </div>

                    <p class="text-xs text-rs-muted">
                        {{ __('Data cuaca:') }} <a href="https://open-meteo.com/" target="_blank" rel="noopener" class="underline">Open-Meteo</a>
                        · {{ __('Lokasi:') }} <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener" class="underline">OpenStreetMap Nominatim</a>.
                        {{ __('Prakiraan model, bukan pengamatan langsung di jalur.') }}
                    </p>
                </div>
            </section>

            {{-- Elevation profile (DEM heights, loaded with the conditions endpoint). --}}
            <section class="rs-card p-4 sm:p-5 min-w-0" aria-labelledby="profile-title">
                <h2 id="profile-title" class="rs-display uppercase text-2xl text-rs-z9 leading-none">{{ __('Profil Elevasi') }}</h2>
                <p class="mt-1 text-sm text-rs-muted">{{ __('Naik turunnya jalur dari titik awal sampai akhir.') }}</p>

                <div class="mt-3 min-h-[150px] flex flex-col">
                    <div x-show="loading" class="flex-1 min-h-[150px] rounded-lg bg-rs-mist motion-safe:animate-pulse" role="status"><span class="sr-only">{{ __('Memuat profil elevasi') }}</span></div>
                    {{-- Built as an SVG string: Alpine directives don't work inside <svg> templates. --}}
                    <div x-show="!loading && chart()" x-cloak x-html="chartSvg()"></div>
                    <div class="flex items-center gap-4 mt-2 text-xs text-rs-muted" x-show="!loading && chart()" x-cloak>
                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-rs-z9"></span> {{ __('Titik awal') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span> {{ __('Titik tertinggi') }}</span>
                    </div>
                    <p x-show="!loading && !chart()" x-cloak class="flex-1 rounded-lg border border-rs-line bg-rs-paper p-4 text-sm text-rs-ink">
                        {{ __('Profil elevasi belum tersedia. Coba muat ulang halaman nanti.') }}
                    </p>
                </div>

                <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-lg bg-rs-paper px-2 py-2">
                        <dt class="text-xs text-rs-muted">{{ __('Terendah') }}</dt>
                        <dd class="font-bold text-rs-ink text-sm rs-num" x-text="chart() ? num(chart().min) + ' m' : '-'">-</dd>
                    </div>
                    <div class="rounded-lg bg-rs-paper px-2 py-2">
                        <dt class="text-xs text-rs-muted">{{ __('Tertinggi') }}</dt>
                        <dd class="font-bold text-rs-ink text-sm rs-num" x-text="chart() ? num(chart().max) + ' m' : '-'">-</dd>
                    </div>
                    <div class="rounded-lg bg-rs-paper px-2 py-2">
                        <dt class="text-xs text-rs-muted">{{ __('Total naik') }}</dt>
                        <dd class="font-bold text-rs-ink text-sm rs-num">{{ $num($route->elevation_gain_m) }} m</dd>
                    </div>
                </dl>
                <p class="text-xs text-rs-muted mt-2">{{ __('Ketinggian dari model elevasi Open-Meteo (DEM 90 m) atau data GPX.') }}</p>
            </section>

            {{-- Basecamp, summit and the trailhead's administrative location. --}}
            <section id="info-basecamp" class="rs-card overflow-hidden" aria-labelledby="basecamp-title">
                <div class="px-4 sm:px-5 py-3 border-b border-rs-line">
                    <h2 id="basecamp-title" class="rs-display uppercase text-2xl text-rs-z9 leading-none">{{ __('Info Basecamp & Lokasi') }}</h2>
                    <p class="text-sm text-rs-muted mt-1">{{ __('Titik awal dan titik tertinggi diambil dari jalur GPX.') }}</p>
                </div>

                <div class="p-4 sm:p-5 space-y-5">
                    <div class="grid sm:grid-cols-2 gap-3 sm:gap-4">
                        <div class="rounded-lg bg-rs-mist p-4 min-w-0">
                            <div class="flex items-start gap-3">
                                <x-icon name="home" class="w-5 h-5 text-rs-z7 shrink-0 mt-0.5" />
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold text-rs-muted">{{ __('Basecamp / titik awal') }}</p>
                                    <p class="font-bold text-rs-ink break-words" x-text="points.basecamp.name || @js(__('Titik awal jalur'))">{{ $trailPoints['basecamp']['name'] ?? __('Titik awal jalur') }}</p>
                                    <p class="text-sm text-rs-muted rs-num break-all" x-text="coords(points.basecamp)">{{ number_format($trailPoints['basecamp']['lat'], 5, '.', '') }}, {{ number_format($trailPoints['basecamp']['lng'], 5, '.', '') }}</p>
                                    <p class="text-sm text-rs-ink" x-text="ele(points.basecamp)">{{ $trailPoints['basecamp']['elevation_m'] ? '± ' . $num($trailPoints['basecamp']['elevation_m']) . ' ' . __('mdpl') : __('Ketinggian belum diketahui') }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg bg-rs-paper border border-rs-line p-4 min-w-0">
                            <div class="flex items-start gap-3">
                                <x-icon name="mountain" class="w-5 h-5 text-rs-z7 shrink-0 mt-0.5" />
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold text-rs-muted">{{ __('Puncak / titik tertinggi') }}</p>
                                    <p class="font-bold text-rs-ink" x-text="ele(points.summit)">{{ $trailPoints['summit']['elevation_m'] ? '± ' . $num($trailPoints['summit']['elevation_m']) . ' ' . __('mdpl') : __('Ketinggian belum diketahui') }}</p>
                                    <p class="text-sm text-rs-muted rs-num break-all" x-text="coords(points.summit)">{{ number_format($trailPoints['summit']['lat'], 5, '.', '') }}, {{ number_format($trailPoints['summit']['lng'], 5, '.', '') }}</p>
                                    <a :href="points.summit.osm_url" href="{{ $trailPoints['summit']['osm_url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 min-h-[44px] text-sm font-semibold text-rs-z7 underline underline-offset-4">{{ __('Lihat puncak di peta') }} <x-icon name="external-link" class="w-3.5 h-3.5" /></a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-semibold text-rs-muted mb-2">{{ __('Lokasi titik awal') }}</p>
                        <dl class="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
                            @foreach(['village' => __('Desa/Kelurahan'), 'district' => __('Kecamatan'), 'regency' => __('Kabupaten/Kota'), 'province' => __('Provinsi')] as $key => $label)
                            <div class="rounded-lg border border-rs-line bg-white px-3 py-2 min-w-0">
                                <dt class="text-xs text-rs-muted">{{ $label }}</dt>
                                <dd class="font-semibold text-rs-ink break-words">
                                    <span x-show="loading" class="inline-block h-4 w-20 rounded bg-[#dfe7e1] motion-safe:animate-pulse align-middle"></span>
                                    <span x-show="!loading" x-cloak x-text="(location && location.{{ $key }}) || '-'"></span>
                                </dd>
                            </div>
                            @endforeach
                        </dl>
                        <p x-show="!loading && !location" x-cloak class="text-xs text-rs-muted mt-2">{{ __('Lokasi administratif belum tersedia. Coba muat ulang halaman nanti.') }}</p>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2">
                        <a :href="points.basecamp.directions_url" href="{{ $trailPoints['basecamp']['directions_url'] }}" target="_blank" rel="noopener" class="rs-btn rs-btn-sm">
                            <x-icon name="map" class="w-4 h-4" /> {{ __('Petunjuk arah ke basecamp') }}
                        </a>
                        <a :href="points.basecamp.osm_url" href="{{ $trailPoints['basecamp']['osm_url'] }}" target="_blank" rel="noopener" class="rs-btn-ghost rs-btn-sm">
                            <x-icon name="external-link" class="w-4 h-4" /> {{ __('Lihat di OpenStreetMap') }}
                        </a>
                    </div>
                    <p class="text-xs text-rs-muted">{{ __('Koordinat dari jalur GPX; ketinggian dari GPX atau model elevasi Open-Meteo, bisa berbeda beberapa puluh meter. Konfirmasikan lokasi basecamp ke pengelola sebelum berangkat.') }}</p>
                </div>
            </section>
            @endif

            @if($hasPractical)
            <section class="rs-card overflow-hidden" aria-labelledby="practical-title">
                <div class="px-4 sm:px-5 py-3 border-b border-rs-line">
                    <h2 id="practical-title" class="rs-display uppercase text-2xl text-rs-z9 leading-none">{{ __('Info Praktis & Tips') }}</h2>
                </div>
                <dl class="p-4 sm:p-5 grid sm:grid-cols-2 gap-4 text-sm">
                    @if($route->basecamp_name)
                    <div class="min-w-0">
                        <dt class="text-xs font-semibold text-rs-muted">{{ __('Basecamp') }}</dt>
                        <dd class="font-bold text-rs-ink">{{ $route->basecamp_name }}</dd>
                        @if($route->basecamp_address)<dd class="text-rs-muted">{{ $route->basecamp_address }}</dd>@endif
                        @if($route->basecamp_lat && $route->basecamp_lng)
                        <dd><a href="https://www.google.com/maps?q={{ $route->basecamp_lat }},{{ $route->basecamp_lng }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 min-h-[44px] font-semibold text-rs-z7 underline underline-offset-4">{{ __('Buka di Google Maps') }} <x-icon name="external-link" class="w-3.5 h-3.5" /></a></dd>
                        @endif
                    </div>
                    @endif
                    @if($route->contact_phone)
                    <div><dt class="text-xs font-semibold text-rs-muted">{{ __('Kontak') }}</dt><dd class="font-bold text-rs-ink">{{ $route->contact_phone }}</dd></div>
                    @endif
                    @if($route->entry_fee)
                    <div><dt class="text-xs font-semibold text-rs-muted">{{ __('Tiket Masuk') }}</dt><dd class="rs-display rs-num text-2xl text-rs-z9">Rp {{ number_format($route->entry_fee, 0, ',', '.') }}</dd></div>
                    @endif
                    @if($route->best_season)
                    <div><dt class="text-xs font-semibold text-rs-muted">{{ __('Musim Terbaik') }}</dt><dd class="font-bold text-rs-ink">{{ $route->best_season }}</dd></div>
                    @endif
                    @if($route->facilities)
                    <div class="sm:col-span-2"><dt class="text-xs font-semibold text-rs-muted">{{ __('Fasilitas') }}</dt><dd class="text-rs-ink">{{ $route->facilities }}</dd></div>
                    @endif
                    @if($route->tips)
                    <div class="sm:col-span-2 rounded-lg bg-rs-mist p-4"><dt class="text-xs font-semibold text-rs-z9">{{ __('Tips Penting') }}</dt><dd class="text-rs-ink leading-relaxed">{{ $route->tips }}</dd></div>
                    @endif
                </dl>
            </section>
            @endif

            {{-- About the route: the uploader's notes and the summary written from the GPX numbers. --}}
            <section class="rs-card p-4 sm:p-5" aria-labelledby="about-title">
                <h2 id="about-title" class="rs-display uppercase text-2xl text-rs-z9 leading-none">{{ __('Tentang jalur ini') }}</h2>
                @if($route->description)
                <div class="mt-3">
                    <p class="text-xs font-semibold text-rs-muted">{{ __('Catatan pengunggah') }}</p>
                    <p class="mt-1 text-rs-ink leading-relaxed">{{ $route->description }}</p>
                </div>
                @endif
                <div class="mt-3">
                    <p class="text-xs font-semibold text-rs-muted">{{ __('Ringkasan dari data GPX') }}</p>
                    <p class="mt-1 text-rs-ink leading-relaxed">{{ $route->narrative_text ?: __('Ringkasan belum tersedia.') }}</p>
                </div>
            </section>

            {{-- Reviews --}}
            <section id="ulasan" class="rs-card overflow-hidden" aria-labelledby="reviews-title">
                <div class="px-4 sm:px-5 py-3 border-b border-rs-line flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="reviews-title" class="rs-display uppercase text-2xl text-rs-z9 leading-none">{{ __('Ulasan Pendaki') }}</h2>
                    @if($reviewCount)
                    <p class="text-sm text-rs-muted"><span class="rs-display rs-num text-xl text-rs-ink">{{ $num($route->average_rating, 1) }}</span> / 5 · {{ __($reviewCount === 1 ? '1 ulasan' : ':count ulasan', ['count' => $reviewCount]) }}</p>
                    @endif
                </div>

                <div class="p-4 sm:p-5">
                    @if($route->comments->count() > 0)
                    <ul class="space-y-3 mb-5">
                        @foreach($route->comments->take(3) as $comment)
                        <li class="rounded-lg bg-rs-paper p-4">
                            <p class="flex flex-wrap items-baseline justify-between gap-x-2">
                                <span class="font-semibold text-rs-ink text-sm break-words">{{ $comment->user->name ?? __('Anonim') }}</span>
                                <span class="text-xs text-rs-muted">{{ $comment->created_at->diffForHumans() }}</span>
                            </p>
                            <p class="mt-1 text-rs-ink text-sm break-words leading-relaxed">{{ $comment->content }}</p>
                        </li>
                        @endforeach
                    </ul>
                    @else
                    <p class="text-rs-muted text-sm pb-4">{{ __('Belum ada ulasan. Sudah pernah lewat jalur ini? Ceritakan buat pendaki berikutnya.') }}</p>
                    @endif

                    @auth
                    <form action="{{ route('routes.review.store', $route) }}" method="POST" class="pt-4 border-t border-rs-line space-y-3">
                        @csrf
                        <fieldset>
                            <legend class="text-sm font-semibold text-rs-ink">{{ __('Nilai kamu') }}</legend>
                            <div class="mt-1 flex gap-1" x-data="{ rating: 0 }" role="radiogroup" aria-label="{{ __('Nilai kamu') }}">
                                @for($i = 1; $i <= 5; $i++)
                                <label class="cursor-pointer w-11 h-11 inline-flex items-center justify-center rounded-lg" title="{{ __(':count bintang', ['count' => $i]) }}">
                                    <input type="radio" name="rating" value="{{ $i }}" class="sr-only peer" required @change="rating = {{ $i }}">
                                    <span class="sr-only">{{ __(':count bintang', ['count' => $i]) }}</span>
                                    <svg class="w-7 h-7 transition-colors rounded peer-focus-visible:outline peer-focus-visible:outline-[3px] peer-focus-visible:outline-rs-z9" :class="rating >= {{ $i }} ? 'text-amber-500' : 'text-[#c9d1cc]'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                    </svg>
                                </label>
                                @endfor
                            </div>
                        </fieldset>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <label for="review-comment" class="sr-only">{{ __('Ceritakan pengalamanmu') }}</label>
                            <input id="review-comment" type="text" name="comment" maxlength="1000" class="rs-input flex-1 min-w-0" placeholder="{{ __('Ceritakan singkat: kondisi jalur, air, tempat camp...') }}">
                            <button type="submit" class="rs-btn">{{ __('Kirim ulasan') }}</button>
                        </div>
                    </form>
                    @else
                    <div class="rounded-lg bg-rs-mist p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4">
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-rs-ink">{{ __('Masuk atau daftar dulu untuk memberi ulasan') }}</p>
                            <p class="text-sm text-rs-muted mt-0.5">{{ __('Setelah masuk, kamu kembali ke halaman ini.') }}</p>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <a href="{{ route('routes.review.login', $route) }}" class="rs-btn rs-btn-sm flex-1 sm:flex-none">{{ __('Masuk') }}</a>
                            <a href="{{ route('routes.review.login', [$route, 'to' => 'register']) }}" class="rs-btn-ghost rs-btn-sm flex-1 sm:flex-none">{{ __('Daftar') }}</a>
                        </div>
                    </div>
                    @endauth
                </div>
            </section>
        </div>

        {{-- ================= SIDE COLUMN ================= --}}
        <aside class="lg:col-span-4 space-y-5 sm:space-y-6 min-w-0 lg:sticky lg:top-24">
            @if($similarRoutes->count() > 0)
            <section class="rs-card overflow-hidden" aria-labelledby="similar-title">
                <div class="px-4 sm:px-5 py-3 border-b border-rs-line">
                    <h2 id="similar-title" class="rs-display uppercase text-2xl text-rs-z9 leading-none">{{ __('Jalur yang mirip') }}</h2>
                    <p class="mt-1 text-sm text-rs-muted">{{ __('Jarak, tanjakan, dan suasananya paling dekat dengan jalur ini.') }}</p>
                </div>
                <ul class="divide-y divide-rs-line">
                    @foreach($similarRoutes as $similar)
                    @php $path = $trackPath($similar->route_coordinates); @endphp
                    <li>
                        <a href="{{ route('routes.show', $similar) }}" class="flex items-center gap-3 px-4 sm:px-5 py-3 hover:bg-rs-paper group">
                            <span class="w-14 h-14 rounded-lg bg-rs-mist shrink-0 flex items-center justify-center">
                                @if($path)
                                <svg viewBox="0 0 64 64" class="w-12 h-12" aria-hidden="true" focusable="false"><path d="{{ $path }}" fill="none" stroke="#047857" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/></svg>
                                @else
                                <x-icon name="mountain" class="w-6 h-6 text-rs-z7" />
                                @endif
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold text-rs-ink group-hover:text-rs-z7 break-words leading-snug">{{ $similar->name }}</span>
                                <span class="mt-0.5 block text-xs text-rs-muted">{{ $similarWord((float) $similar->similarity_score) }} · {{ $num($similar->distance_km, 1) }} km · {{ __($similar->difficulty_level) }}</span>
                            </span>
                        </a>
                    </li>
                    @endforeach
                </ul>
                <div class="px-4 sm:px-5 py-3 border-t border-rs-line">
                    <a href="{{ route('routes.similar', $route) }}" class="inline-flex items-center min-h-[44px] font-semibold text-rs-z7 underline underline-offset-4">{{ __('Lihat semua jalur yang mirip') }}</a>
                </div>
            </section>
            @endif

            {{-- File details for the curious: version, check status, hash, download. --}}
            <details class="rs-more rs-card overflow-hidden">
                <summary class="flex items-center justify-between gap-3 min-h-[52px] px-4 sm:px-5 py-3">
                    <span class="font-semibold text-rs-z9">{{ __('Data file GPX') }}</span>
                    <span class="flex items-center gap-2">
                        @if($version)
                        <span class="rs-chip {{ $version->verification_status === 'verified' ? 'rs-chip-ok' : ($version->verification_status === 'invalid' ? 'rs-chip-bad' : 'rs-chip-warn') }}">{{ $statusWord }}</span>
                        @endif
                        <x-icon name="chevron-down" class="rs-more-icon w-5 h-5 text-rs-muted transition-transform" />
                    </span>
                </summary>
                <div class="px-4 sm:px-5 pb-5 space-y-3 text-sm border-t border-rs-line pt-4">
                    @if($version)
                    <dl class="grid grid-cols-2 gap-3">
                        <div><dt class="text-xs text-rs-muted">{{ __('Versi aktif') }}</dt><dd class="font-bold text-rs-ink">v{{ $version->version_number }}</dd></div>
                        <div><dt class="text-xs text-rs-muted">{{ __('Status') }}</dt><dd class="font-bold text-rs-ink">{{ $statusWord }} <code class="text-xs font-normal text-rs-muted">{{ str_replace('_', ' ', strtoupper($version->verification_status)) }}</code></dd></div>
                    </dl>
                    <p class="text-rs-ink leading-relaxed">{{ $version->verification_status_explanation }}</p>
                    <div>
                        <p class="text-xs text-rs-muted mb-1">{{ __('Sidik file (SHA-256)') }}</p>
                        <code class="block text-xs bg-rs-paper border border-rs-line rounded-lg p-3 break-all text-rs-ink">{{ $version->file_hash }}</code>
                    </div>
                    <div class="flex flex-col gap-2">
                        <a href="{{ route('routes.provenance.download-version', [$route, $version]) }}" class="rs-btn-ghost rs-btn-sm">{{ __('Unduh file GPX') }}</a>
                        <a href="{{ route('routes.provenance', $route) }}" class="inline-flex items-center justify-center min-h-[44px] font-semibold text-rs-z7 underline underline-offset-4">{{ __('Lihat riwayat versi') }}</a>
                    </div>
                    @else
                    <p class="text-rs-muted">{{ __('Belum ada data versi untuk file GPX ini.') }}</p>
                    @endif
                </div>
            </details>

            @auth
            @if(Auth::user()->isAdmin())
            <form action="{{ route('routes.destroy', $route) }}" method="POST" onsubmit="return confirm(@js(__('Yakin ingin menghapus rute ini?')))">
                @csrf
                @method('DELETE')
                <button type="submit" class="w-full min-h-[44px] rounded-lg border border-rose-200 bg-white text-[#9f1239] text-sm font-bold hover:bg-rose-50 inline-flex items-center justify-center gap-2">
                    <x-icon name="trash" class="w-4 h-4" /> {{ __('Hapus Rute') }}
                </button>
            </form>
            @endif
            @endauth
        </aside>
    </div>
</div>

@push('scripts')
<script>
// Like / unlike without reloading; the form still works without JavaScript.
window.likeWidget = function (url, liked, count) {
    return {
        liked: liked,
        count: count,
        busy: false,
        error: '',
        async toggle() {
            if (this.busy) return;
            this.busy = true;
            this.error = '';
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ liked: this.liked ? 0 : 1 }),
                });
                if (res.status === 419) { location.reload(); return; }
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                this.liked = !!data.liked;
                this.count = Number(data.count) || 0;
            } catch (e) {
                this.error = @js(__('Gagal menyimpan. Coba lagi sebentar lagi.'));
            }
            this.busy = false;
        },
    };
};
</script>
@if($trailPoints)
<script>
// Alpine component for the basecamp/location/weather cards; data comes from
// routes.conditions after the page has loaded (external APIs are cached server-side).
window.routeConditions = function (url, points) {
    return {
        points: points,
        location: null,
        weather: null,
        safety: null,
        loading: true,
        async load() {
            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                if (data.points) this.points = data.points;
                this.location = data.location;
                this.weather = data.weather;
                this.safety = data.safety;
            } catch (e) {
                console.warn('Kondisi jalur tidak dapat dimuat', e);
            }
            this.loading = false;
        },
        num(value, digits = 0) {
            if (value === null || value === undefined) return '-';
            return Number(value).toLocaleString(document.documentElement.lang === 'en' ? 'en-US' : 'id-ID', { minimumFractionDigits: digits, maximumFractionDigits: digits });
        },
        coords(p) {
            return p ? Number(p.lat).toFixed(5) + ', ' + Number(p.lng).toFixed(5) : '-';
        },
        ele(p) {
            return p && p.elevation_m !== null && p.elevation_m !== undefined
                ? '± ' + this.num(p.elevation_m) + ' ' + @js(__('mdpl'))
                : @js(__('Ketinggian belum diketahui'));
        },
        w(side, key) {
            return this.weather && this.weather[side] ? this.weather[side][key] : null;
        },
        _chart: null,
        _chartFor: null,
        chart() {
            const profile = this.points && this.points.profile;
            if (!profile || profile.length < 2) return null;
            if (this._chartFor === profile) return this._chart;

            const w = 520, h = 190, left = 44, top = 10, bottom = 20;
            const kms = profile.map(p => p[0]), eles = profile.map(p => p[1]);
            const km = Math.max(...kms) || 1;
            let min = Math.min(...eles), max = Math.max(...eles);
            const pad = Math.max(20, (max - min) * 0.08);
            const lo = min - pad, hi = max + pad;
            const x = k => left + (k / km) * (w - left);
            const y = e => top + (1 - (e - lo) / (hi - lo)) * (h - top - bottom);
            const pts = profile.map(p => [x(p[0]), y(p[1])]);
            const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
            const base = (h - bottom).toFixed(1);
            const area = line + ' L' + pts[pts.length - 1][0].toFixed(1) + ' ' + base + ' L' + pts[0][0].toFixed(1) + ' ' + base + ' Z';
            const topIdx = eles.indexOf(max);
            const step = Math.max(50, Math.ceil((max - min) / 3 / 50) * 50);
            const ticks = [];
            for (let v = Math.ceil(lo / step) * step; v <= hi; v += step) ticks.push({ v, y: y(v) });

            this._chartFor = profile;
            this._chart = {
                w, h, left, line, area, ticks, km, min, max,
                start: { x: pts[0][0], y: pts[0][1] },
                top: { x: pts[topIdx][0], y: pts[topIdx][1] },
            };
            return this._chart;
        },
        chartSvg() {
            const c = this.chart();
            if (!c) return '';
            const f = n => n.toFixed(1);
            const grid = c.ticks.map(t =>
                `<line x1="${c.left}" x2="${c.w}" y1="${f(t.y)}" y2="${f(t.y)}" stroke="#dedbcd" stroke-dasharray="3 3"/>` +
                `<text x="${c.left - 6}" y="${f(t.y + 4)}" text-anchor="end" font-size="11" fill="#4b5d55">${this.num(t.v)}</text>`
            ).join('');
            const label = @js(__('Profil elevasi dari :min sampai :max mdpl')).replace(':min', this.num(c.min)).replace(':max', this.num(c.max));
            return `<svg viewBox="0 0 ${c.w} ${c.h}" class="w-full h-auto" role="img" aria-label="${label}">
                ${grid}
                <path d="${c.area}" fill="#047857" fill-opacity="0.12"/>
                <path d="${c.line}" fill="none" stroke="#047857" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                <circle cx="${f(c.start.x)}" cy="${f(c.start.y)}" r="5" fill="#053b30" stroke="#fff" stroke-width="2"/>
                <circle cx="${f(c.top.x)}" cy="${f(c.top.y)}" r="5" fill="#f59e0b" stroke="#fff" stroke-width="2"/>
                <text x="${c.left}" y="${c.h - 2}" font-size="11" fill="#4b5d55">0 km</text>
                <text x="${c.w - 2}" y="${c.h - 2}" font-size="11" fill="#4b5d55" text-anchor="end">${this.num(c.km, 1)} km</text>
            </svg>`;
        },
    };
};
</script>
@endif

@if($route->route_coordinates && count($route->route_coordinates) > 0)
<script>
document.addEventListener('DOMContentLoaded', function () {
    var coords = @json($route->route_coordinates);
    var el = document.getElementById('route-map');
    if (!coords || coords.length < 1 || !el || typeof L === 'undefined') return;

    // The mouse wheel scrolls the page until the map is clicked or focused, so the map never traps scrolling.
    var map = L.map(el, { zoomControl: true, scrollWheelZoom: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);
    map.on('focus', function () { map.scrollWheelZoom.enable(); });
    map.on('blur', function () { map.scrollWheelZoom.disable(); });
    el.addEventListener('click', function () { map.scrollWheelZoom.enable(); });

    L.polyline(coords, { color: '#ffffff', weight: 8, opacity: 0.85, lineJoin: 'round', lineCap: 'round' }).addTo(map);
    var line = L.polyline(coords, { color: '#047857', weight: 5, opacity: 1, lineJoin: 'round', lineCap: 'round' }).addTo(map);
    L.circleMarker(coords[0], { radius: 8, fillColor: '#053b30', color: '#fff', weight: 3, fillOpacity: 1 })
        .addTo(map).bindPopup(@js(__('Titik awal')));
    L.circleMarker(coords[coords.length - 1], { radius: 8, fillColor: '#f59e0b', color: '#fff', weight: 3, fillOpacity: 1 })
        .addTo(map).bindPopup(@js(__('Titik akhir')));
    var fit = function () {
        map.invalidateSize();
        map.fitBounds(line.getBounds(), { padding: [30, 30] });
    };
    fit();
    // The box can still change size after this runs (fonts, grid, cards loading),
    // which left the map zoomed far in. Re-fit until the user moves the map.
    var userMoved = false;
    map.once('zoomstart dragstart', function (e) { if (e.originalEvent || e.type === 'dragstart') userMoved = true; });
    el.addEventListener('mousedown', function () { userMoved = true; }, { once: true });
    el.addEventListener('touchstart', function () { userMoved = true; }, { once: true, passive: true });
    window.addEventListener('load', function () { if (!userMoved) fit(); });
    if ('ResizeObserver' in window) {
        var lastW = el.clientWidth, lastH = el.clientHeight;
        new ResizeObserver(function () {
            if (userMoved || (el.clientWidth === lastW && el.clientHeight === lastH)) return;
            lastW = el.clientWidth; lastH = el.clientHeight;
            fit();
        }).observe(el);
    }
});
</script>
@endif
@endpush
@endsection
