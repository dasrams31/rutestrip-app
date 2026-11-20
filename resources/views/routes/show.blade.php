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

@push('styles')
<style>[x-cloak]{display:none !important}</style>
@endpush

@section('content')
@inject('conditionsService', 'App\Services\RouteConditionsService')
@php
    // Cache-only read: the page never waits on external APIs.
    $trailPoints = $conditionsService->points($route, false);
@endphp
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 overflow-x-clip">
    <!-- Back Link -->
    <div class="flex items-center justify-between mb-6">
        <a href="{{ route('routes.index') }}" class="inline-flex items-center text-emerald-600 hover:text-emerald-700 group font-medium">
            <svg class="w-5 h-5 mr-2 transition-transform group-hover:-translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            {{ __('Kembali ke Daftar Rute') }}
        </a>
    </div>

    <!-- MAIN GRID 2 COLUMNS FOR DESKTOP -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start"
         @if($trailPoints) x-data="routeConditions(@js(route('routes.conditions', $route)), @js($trailPoints))" x-init="load()" @endif>
        
        <!-- ============================== -->
        <!-- LEFT COLUMN: MAIN CONTENT      -->
        <!-- ============================== -->
        <div class="lg:col-span-8 space-y-6 min-w-0">

            <!-- 1. HEADER & MAIN STATS -->
            <div class="bg-white rounded-3xl shadow-xl overflow-hidden border border-slate-100">
                <!-- Cover / Header -->
                <div class="bg-mountain-gradient p-5 sm:p-8 relative">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap gap-2 mb-4">
                            <span class="inline-flex items-center px-3 sm:px-4 py-1.5 rounded-full text-sm font-bold
                                @if($route->difficulty_level === 'Mudah') bg-green-100 text-green-800
                                @elseif($route->difficulty_level === 'Sedang') bg-yellow-100 text-yellow-800
                                @elseif($route->difficulty_level === 'Sulit') bg-orange-100 text-orange-800
                                @else bg-red-100 text-red-800
                                @endif shadow-sm"
                                title="{{ __('Skor kesulitan :score: dihitung dari total tanjakan dan jarak (Shenandoah difficulty rating), ditambah faktor kecuraman (rata-rata ≥25%) dan ketinggian puncak (≥3.000 m). Mudah <75, Sedang <200, Sulit <460, Sangat Sulit ≥460; tanjakan ≥1.200 m minimal Sulit.', ['score' => number_format($route->difficulty_score, 0)]) }}">
                                {{ __($route->difficulty_level) }}
                                <span class="ml-1 font-medium opacity-70">· {{ __('skor') }} {{ number_format($route->difficulty_score, 0) }}</span>
                            </span>
                            @if($route->activeGpxVersion)
                            <span class="inline-flex items-center px-3 sm:px-4 py-1.5 rounded-full text-sm font-bold shadow-sm
                                @if($route->activeGpxVersion->verification_status === 'verified') bg-emerald-100 text-emerald-800
                                @elseif($route->activeGpxVersion->verification_status === 'invalid') bg-red-100 text-red-800
                                @else bg-amber-100 text-amber-800
                                @endif">
                                GPX v{{ $route->activeGpxVersion->version_number }}: {{ str_replace('_', ' ', ucfirst($route->activeGpxVersion->verification_status)) }}
                            </span>
                            @endif
                            </div>
                            <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-white mb-2 tracking-tight break-words">{{ $route->name }}</h1>
                            <p class="text-sm sm:text-base text-emerald-100 font-medium break-words">{{ __('Diunggah oleh') }} <span class="font-bold text-white">{{ $route->uploader_name }}</span> {{ __('pada') }} {{ $route->created_at->translatedFormat('d M Y, H:i') }}</p>
                        </div>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-px bg-slate-100">
                    <div class="bg-white p-4 sm:p-6 text-center min-w-0">
                        <div class="text-emerald-600 mb-2 flex justify-center">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        </div>
                        <p class="text-lg sm:text-2xl font-bold text-slate-800 break-words">{{ $route->formatted_distance }}</p>
                        <p class="text-xs sm:text-sm text-slate-500 font-medium">{{ __('Total Jarak') }}</p>
                    </div>
                    <div class="bg-white p-4 sm:p-6 text-center min-w-0">
                        <div class="text-teal-600 mb-2 flex justify-center">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <p class="text-lg sm:text-2xl font-bold text-slate-800 break-words">{{ $route->formatted_duration }}</p>
                        <p class="text-xs sm:text-sm text-slate-500 font-medium">{{ __('Estimasi Waktu') }}</p>
                    </div>
                    <div class="bg-white p-4 sm:p-6 text-center min-w-0">
                        <div class="text-cyan-600 mb-2 flex justify-center">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"/></svg>
                        </div>
                        <p class="text-lg sm:text-2xl font-bold text-slate-800 break-words">{{ number_format($route->elevation_gain_m) }}m</p>
                        <p class="text-xs sm:text-sm text-slate-500 font-medium">{{ __('Elevasi Gain') }}</p>
                    </div>
                    <div class="bg-white p-4 sm:p-6 text-center min-w-0">
                        <div class="text-amber-600 mb-2 flex justify-center">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        </div>
                        <p class="text-lg sm:text-2xl font-bold text-slate-800 break-words">{{ $route->formatted_grade }}</p>
                        <p class="text-xs sm:text-sm text-slate-500 font-medium">{{ __('Grade Rata-rata') }}</p>
                    </div>
                </div>
            </div>

            <!-- 2. ROUTE MAP -->
            @if($route->route_coordinates && count($route->route_coordinates) > 0)
            <div class="bg-white rounded-3xl shadow-xl overflow-hidden border border-slate-100">
                <div class="p-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center">
                    <h3 class="font-bold text-slate-800 flex items-center">
                        <svg class="w-5 h-5 text-emerald-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                        </svg>
                        {{ __('Peta Rute Pendakian') }}
                    </h3>
                    <span class="text-xs font-medium text-slate-500 bg-white px-2 py-1 rounded-lg border border-slate-200">
                        {{ __(':count titik', ['count' => count($route->route_coordinates)]) }}
                    </span>
                </div>
                <!-- Fixed size, smaller map frame for compact layout -->
                <div id="route-map" class="w-full h-[300px] sm:h-[400px]"></div>
            </div>
            @endif

            <!-- 3. DESKRIPSI & NARRATIVE (2 Cols on desktop) -->
            <div class="grid md:grid-cols-2 gap-6">
                <!-- Info Jalur -->
                @if($route->description)
                <div class="bg-white rounded-3xl shadow-xl border border-slate-100 p-6 flex flex-col h-full">
                    <h3 class="font-bold text-slate-800 mb-3 flex items-center">
                        <svg class="w-5 h-5 text-emerald-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        {{ __('Info Jalur Manual') }}
                    </h3>
                    <p class="text-slate-600 leading-relaxed text-sm flex-1">
                        {{ $route->description }}
                    </p>
                </div>
                @endif

                <!-- AI Description -->
                <div class="bg-slate-50 rounded-3xl shadow-inner border border-slate-200 p-6 flex flex-col h-full">
                    <h3 class="font-bold text-slate-800 mb-3 flex items-center">
                        <svg class="w-5 h-5 text-emerald-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        {{ __('Deskripsi AI (SBERT)') }}
                    </h3>
                    <p class="text-slate-600 leading-relaxed text-sm flex-1">
                        {{ $route->narrative_text ?? __('Belum ada deskripsi komprehensif.') }}
                    </p>
                </div>

                @if($trailPoints)
                <!-- Elevation profile (DEM heights, loaded with the conditions endpoint) -->
                <div class="bg-white rounded-3xl shadow-xl border border-slate-100 p-6 flex flex-col h-full min-w-0 {{ $route->description ? 'md:col-span-2' : '' }}">
                    <h3 class="font-bold text-slate-800 mb-1 flex items-center">
                        <x-icon name="chart-bar" class="w-5 h-5 text-emerald-500 mr-2 shrink-0" />
                        {{ __('Profil Elevasi') }}
                    </h3>
                    <p class="text-xs text-slate-500 mb-3">{{ __('Ketinggian sepanjang jalur dari basecamp ke puncak.') }}</p>

                    <div class="flex-1 min-h-[150px] flex flex-col">
                        <div x-show="loading" class="flex-1 rounded-2xl bg-slate-100 animate-pulse"></div>
                        {{-- Built as an SVG string: Alpine directives don't work inside <svg> templates. --}}
                        <div x-show="!loading && chart()" x-cloak x-html="chartSvg()"></div>
                        <div class="flex items-center gap-4 mt-2 text-[11px] text-slate-500" x-show="!loading && chart()" x-cloak>
                            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Basecamp</span>
                            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span> {{ __('Titik tertinggi') }}</span>
                        </div>
                        <p x-show="!loading && !chart()" x-cloak class="flex-1 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                            {{ __('Profil elevasi belum tersedia. Coba muat ulang halaman nanti.') }}
                        </p>
                    </div>

                    <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-xl bg-slate-50 px-2 py-2">
                            <dt class="text-[11px] text-slate-500">{{ __('Terendah') }}</dt>
                            <dd class="font-bold text-slate-800 text-sm" x-text="chart() ? num(chart().min) + ' m' : '–'">–</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-2 py-2">
                            <dt class="text-[11px] text-slate-500">{{ __('Tertinggi') }}</dt>
                            <dd class="font-bold text-slate-800 text-sm" x-text="chart() ? num(chart().max) + ' m' : '–'">–</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-2 py-2">
                            <dt class="text-[11px] text-slate-500">{{ __('Total naik') }}</dt>
                            <dd class="font-bold text-slate-800 text-sm">{{ number_format($route->elevation_gain_m, 0, ',', '.') }} m</dd>
                        </div>
                    </dl>
                    <p class="text-[11px] text-slate-400 mt-2">{{ __('Ketinggian dari model elevasi Open-Meteo (DEM 90 m) atau data GPX.') }}</p>
                </div>
                @endif
            </div>

            @if($trailPoints)
            <!-- 3b. BASECAMP, LOCATION & LIVE CONDITIONS (location + weather load after the page) -->
            <div class="space-y-6">

                <!-- Info Basecamp & Lokasi -->
                <section id="info-basecamp" class="bg-white rounded-3xl shadow-xl border border-slate-100 overflow-hidden">
                    <div class="p-4 sm:p-6 border-b border-slate-100 bg-slate-50">
                        <h3 class="font-bold text-slate-800 flex items-center">
                            <x-icon name="location-marker" class="w-5 h-5 text-emerald-500 mr-2 shrink-0" />
                            {{ __('Info Basecamp & Lokasi') }}
                        </h3>
                        <p class="text-xs text-slate-500 mt-1">{{ __('Titik awal dan titik tertinggi diambil dari jalur GPX.') }}</p>
                    </div>

                    <div class="p-4 sm:p-6 space-y-5">
                        <div class="grid sm:grid-cols-2 gap-4">
                            <!-- Basecamp / trailhead -->
                            <div class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-4 min-w-0">
                                <div class="flex items-start gap-3">
                                    <div class="w-9 h-9 rounded-full bg-white flex items-center justify-center shrink-0"><x-icon name="home" class="w-5 h-5 text-emerald-600" /></div>
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold text-emerald-700">{{ __('Basecamp / titik awal') }}</p>
                                        <p class="font-bold text-slate-800 break-words" x-text="points.basecamp.name || @js(__('Titik awal jalur'))">{{ $trailPoints['basecamp']['name'] ?? __('Titik awal jalur') }}</p>
                                        <p class="text-sm text-slate-600 font-mono break-all" x-text="coords(points.basecamp)">{{ number_format($trailPoints['basecamp']['lat'], 5, '.', '') }}, {{ number_format($trailPoints['basecamp']['lng'], 5, '.', '') }}</p>
                                        <p class="text-sm text-slate-600" x-text="ele(points.basecamp)">{{ $trailPoints['basecamp']['elevation_m'] ? '± ' . number_format($trailPoints['basecamp']['elevation_m'], 0, ',', '.') . ' ' . __('mdpl') : __('Ketinggian belum diketahui') }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Summit / highest point -->
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 min-w-0">
                                <div class="flex items-start gap-3">
                                    <div class="w-9 h-9 rounded-full bg-white flex items-center justify-center shrink-0"><x-icon name="mountain" class="w-5 h-5 text-slate-600" /></div>
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold text-slate-600">{{ __('Puncak / titik tertinggi') }}</p>
                                        <p class="font-bold text-slate-800" x-text="ele(points.summit)">{{ $trailPoints['summit']['elevation_m'] ? '± ' . number_format($trailPoints['summit']['elevation_m'], 0, ',', '.') . ' ' . __('mdpl') : __('Ketinggian belum diketahui') }}</p>
                                        <p class="text-sm text-slate-600 font-mono break-all" x-text="coords(points.summit)">{{ number_format($trailPoints['summit']['lat'], 5, '.', '') }}, {{ number_format($trailPoints['summit']['lng'], 5, '.', '') }}</p>
                                        <a :href="points.summit.osm_url" href="{{ $trailPoints['summit']['osm_url'] }}" target="_blank" rel="noopener" class="text-xs text-emerald-700 hover:underline inline-flex items-center gap-1 mt-1">{{ __('Lihat puncak di peta') }} <x-icon name="external-link" class="w-3 h-3" /></a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Administrative location of the trailhead (Nominatim, cached) -->
                        <div>
                            <p class="text-xs font-semibold text-slate-500 mb-2">{{ __('Lokasi titik awal') }}</p>
                            <dl class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                                @foreach(['village' => __('Desa/Kelurahan'), 'district' => __('Kecamatan'), 'regency' => __('Kabupaten/Kota'), 'province' => __('Provinsi')] as $key => $label)
                                <div class="rounded-xl border border-slate-100 bg-white px-3 py-2 min-w-0">
                                    <dt class="text-[11px] text-slate-500">{{ $label }}</dt>
                                    <dd class="font-semibold text-slate-800 break-words">
                                        <span x-show="loading" class="inline-block h-4 w-20 rounded bg-slate-200 animate-pulse align-middle"></span>
                                        <span x-show="!loading" x-cloak x-text="(location && location.{{ $key }}) || '–'"></span>
                                    </dd>
                                </div>
                                @endforeach
                            </dl>
                            <p x-show="!loading && !location" x-cloak class="text-xs text-slate-500 mt-2">{{ __('Lokasi administratif belum tersedia. Coba muat ulang halaman nanti.') }}</p>
                        </div>

                        <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2">
                            <a :href="points.basecamp.directions_url" href="{{ $trailPoints['basecamp']['directions_url'] }}" target="_blank" rel="noopener"
                               class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold">
                                <x-icon name="map" class="w-4 h-4" /> {{ __('Petunjuk arah ke basecamp') }}
                            </a>
                            <a :href="points.basecamp.osm_url" href="{{ $trailPoints['basecamp']['osm_url'] }}" target="_blank" rel="noopener"
                               class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 text-sm font-semibold">
                                <x-icon name="external-link" class="w-4 h-4" /> {{ __('Lihat di OpenStreetMap') }}
                            </a>
                        </div>
                        <p class="text-[11px] text-slate-400">{{ __('Koordinat dari jalur GPX; ketinggian dari GPX atau model elevasi Open-Meteo, bisa berbeda beberapa puluh meter. Konfirmasikan lokasi basecamp ke pengelola sebelum berangkat.') }}</p>
                    </div>
                </section>

            </div>
            @endif

            <!-- 4. REVIEWS SECTION -->
            <div id="ulasan" class="bg-white rounded-3xl shadow-xl overflow-hidden border border-slate-100">
                <div class="p-4 sm:p-6 border-b border-slate-100 bg-slate-50 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-bold text-slate-800 flex items-center">
                        <x-icon name="star" class="w-6 h-6 text-amber-400 mr-2" /> {{ __('Ulasan Pengguna') }}
                    </h3>
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-slate-700 text-lg">{{ $route->average_rating }}/5</span>
                        <span class="text-sm text-slate-500">{{ __('(:count ulasan)', ['count' => $route->ratings->count()]) }}</span>
                    </div>
                </div>

                <div class="p-4 sm:p-6">
                    <!-- User Reviews -->
                    @if($route->comments->count() > 0)
                        <div class="space-y-4 mb-6">
                            @foreach($route->comments->take(3) as $comment)
                            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                                <div class="flex flex-wrap items-center justify-between gap-x-2 mb-2">
                                    <span class="font-semibold text-slate-700 text-sm break-words">{{ $comment->user->name ?? __('Anonim') }}</span>
                                    <span class="text-xs text-slate-500">{{ $comment->created_at->diffForHumans() }}</span>
                                </div>
                                <p class="text-slate-600 text-sm break-words">{{ $comment->content }}</p>
                            </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-slate-500 text-center py-4 text-sm">{{ __('Belum ada ulasan pengguna. Jadilah yang pertama!') }}</p>
                    @endif

                    <!-- Review Form -->
                    @auth
                    <div class="pt-4 border-t border-slate-100">
                        <form action="{{ route('routes.review.store', $route) }}" method="POST" class="space-y-4">
                            @csrf
                            <div class="flex flex-col sm:flex-row gap-4">
                                <div class="flex-shrink-0">
                                    <div class="flex gap-1" x-data="{ rating: 0 }" role="radiogroup" aria-label="{{ __('Rating') }}">
                                        @for($i = 1; $i <= 5; $i++)
                                        <label class="cursor-pointer p-0.5" title="{{ __(':count bintang', ['count' => $i]) }}">
                                            <input type="radio" name="rating" value="{{ $i }}" class="sr-only peer" required @change="rating = {{ $i }}">
                                            <svg class="w-7 h-7 sm:w-6 sm:h-6 hover:text-yellow-400 transition-colors star-rating peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-500 rounded" :class="rating >= {{ $i }} ? 'text-yellow-400' : 'text-slate-300'" data-value="{{ $i }}" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                            </svg>
                                        </label>
                                        @endfor
                                    </div>
                                </div>
                                <div class="flex-1 flex gap-2 min-w-0">
                                    <input type="text" name="comment" maxlength="1000" class="w-full min-w-0 px-4 py-2 text-sm border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500" placeholder="{{ __('Tulis pengalaman singkat...') }}">
                                    <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-xl whitespace-nowrap">{{ __('Kirim') }}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                    @else
                    <!-- Guests: ask to log in first, then come back here -->
                    <div class="mt-2 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4">
                        <div class="flex items-start gap-3 flex-1 min-w-0">
                            <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center shrink-0"><x-icon name="chat" class="w-5 h-5 text-emerald-600" /></div>
                            <div class="min-w-0">
                                <p class="font-bold text-slate-800">{{ __('Masuk atau daftar dulu untuk memberi ulasan') }}</p>
                                <p class="text-sm text-slate-600 mt-0.5">{{ __('Bagikan rating dan pengalamanmu di jalur ini. Setelah masuk, kamu kembali ke halaman ini.') }}</p>
                            </div>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <a href="{{ route('routes.review.login', $route) }}" class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold">
                                <x-icon name="login" class="w-4 h-4" /> {{ __('Masuk') }}
                            </a>
                            <a href="{{ route('routes.review.login', [$route, 'to' => 'register']) }}" class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl border border-emerald-300 bg-white hover:bg-emerald-100 text-emerald-700 text-sm font-semibold">
                                <x-icon name="user-add" class="w-4 h-4" /> {{ __('Daftar') }}
                            </a>
                        </div>
                    </div>
                    @endauth
                </div>
            </div>

        </div>


        <!-- ============================== -->
        <!-- RIGHT COLUMN: SIDEBAR          -->
        <!-- ============================== -->
        <div class="lg:col-span-4 space-y-6 min-w-0">
            
            <!-- A. LIKE & ACTION WIDGET (likes work without login: guests use the rs_vid cookie) -->
            <div class="bg-white rounded-3xl shadow-xl overflow-hidden border border-slate-100 p-6 text-center"
                 x-data="likeWidget(@js(route('routes.like.toggle', $route)), @js((bool) $likedByViewer), @js($route->likes_count))">
                <div class="flex justify-center items-center gap-2 mb-4">
                    <x-icon name="heart" class="w-8 h-8 text-rose-500" />
                    <span class="font-bold text-3xl text-slate-800" x-text="count.toLocaleString(document.documentElement.lang)">{{ $route->likes_count }}</span>
                    <span class="text-sm text-slate-500 mt-2 font-medium">{{ __('suka') }}</span>
                </div>

                <div class="space-y-3">
                    <form action="{{ route('routes.like.toggle', $route) }}" method="POST" @submit.prevent="toggle()">
                        @csrf
                        <input type="hidden" name="liked" value="{{ $likedByViewer ? 0 : 1 }}" :value="liked ? 0 : 1">
                        <button type="submit" data-liked="{{ $likedByViewer ? 'true' : 'false' }}" :data-liked="liked.toString()"
                                aria-pressed="{{ $likedByViewer ? 'true' : 'false' }}" :aria-pressed="liked.toString()" :disabled="busy"
                                class="w-full py-3 rounded-2xl font-bold transition-all border-2 disabled:opacity-60
                                       bg-white text-slate-700 border-slate-200 hover:border-emerald-200 hover:text-emerald-700 hover:bg-emerald-50
                                       data-[liked=true]:bg-rose-50 data-[liked=true]:text-rose-600 data-[liked=true]:border-rose-200 data-[liked=true]:hover:bg-rose-100 data-[liked=true]:hover:text-rose-700 data-[liked=true]:hover:border-rose-300">
                            <span class="inline-flex items-center justify-center gap-2" x-show="liked" @unless($likedByViewer) x-cloak @endunless>
                                <x-icon name="heart" class="w-5 h-5" /> {{ __('Batal Suka') }}
                            </span>
                            <span class="inline-flex items-center justify-center gap-2" x-show="!liked" @if($likedByViewer) x-cloak @endif>
                                <x-icon name="thumb-up" class="w-5 h-5" /> {{ __('Suka jalur ini') }}
                            </span>
                        </button>
                    </form>
                    <p x-show="error" x-cloak x-text="error" class="text-xs text-red-600" role="alert"></p>
                    @guest
                    <p class="text-xs text-slate-400">{{ __('Tidak perlu masuk akun untuk menyukai jalur.') }}</p>
                    @endguest
                    @auth
                    @if(Auth::user()->isAdmin())
                    <form action="{{ route('routes.destroy', $route) }}" method="POST" onsubmit="return confirm(@js(__('Yakin ingin menghapus rute ini?')))">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full py-2 bg-red-50 text-red-600 text-sm font-bold rounded-xl mt-2 hover:bg-red-100 inline-flex items-center justify-center gap-2">
                            <x-icon name="trash" class="w-4 h-4" /> {{ __('Hapus Rute') }}
                        </button>
                    </form>
                    @endif
                    @endauth
                </div>
            </div>

            @if($trailPoints)
            <!-- Kondisi Terkini (Open-Meteo) -->
            <section id="kondisi-terkini" class="bg-white rounded-3xl shadow-xl border border-slate-100 overflow-hidden" aria-live="polite">
                <div class="p-4 sm:p-6 border-b border-slate-100 bg-slate-50 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-bold text-slate-800 flex items-center">
                        <x-icon name="cloud" class="w-5 h-5 text-sky-500 mr-2 shrink-0" />
                        {{ __('Kondisi Terkini') }}
                    </h3>
                    <span x-show="weather" x-cloak class="text-xs font-medium text-slate-500 bg-white px-2 py-1 rounded-lg border border-slate-200" x-text="weather ? @js(__('Diperbarui')) + ' ' + weather.updated_label : ''"></span>
                </div>

                <div class="p-4 sm:p-6 space-y-4">
                    <div x-show="!loading && !weather" x-cloak class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        {{ __('Data cuaca tidak tersedia saat ini. Coba muat ulang halaman beberapa saat lagi.') }}
                    </div>

                    <div x-show="loading || weather" class="grid sm:grid-cols-2 lg:grid-cols-1 gap-4">
                        @foreach(['basecamp' => __('Basecamp'), 'summit' => __('Puncak')] as $side => $label)
                        <div class="rounded-2xl border border-slate-100 bg-gradient-to-br from-sky-50 to-white p-4 min-w-0">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                                <p class="text-xs text-slate-500" x-text="ele(points.{{ $side }})"></p>
                            </div>
                            <div class="mt-2 flex flex-wrap items-end gap-x-3 gap-y-1">
                                <span x-show="loading" class="inline-block h-9 w-20 rounded-lg bg-slate-200 animate-pulse"></span>
                                <p x-show="!loading" x-cloak class="text-4xl font-extrabold text-slate-800 leading-none" x-text="num(w('{{ $side }}', 'temperature_c')) + '°C'"></p>
                                <p x-show="!loading" x-cloak class="text-sm font-semibold text-slate-600 pb-0.5" x-text="w('{{ $side }}', 'weather_text') || ''"></p>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                                @foreach([
                                    __('Terasa seperti') => "num(w('$side', 'apparent_temperature_c')) + '°C'",
                                    __('Angin') => "num(w('$side', 'wind_speed_kmh')) + ' " . __('km/jam') . "' + (w('$side', 'wind_direction') ? ' " . __('dari') . " ' + w('$side', 'wind_direction') : '')",
                                    __('Kelembapan') => "num(w('$side', 'humidity_pct')) + '%'",
                                    __('Curah hujan') => "num(w('$side', 'precipitation_mm'), 1) + ' mm'",
                                ] as $term => $expr)
                                <div class="min-w-0">
                                    <dt class="text-[11px] text-slate-500">{{ $term }}</dt>
                                    <dd class="font-semibold text-slate-800 break-words">
                                        <span x-show="loading" class="inline-block h-4 w-16 rounded bg-slate-200 animate-pulse align-middle"></span>
                                        <span x-show="!loading" x-cloak x-text="{{ $expr }}"></span>
                                    </dd>
                                </div>
                                @endforeach
                            </dl>
                        </div>
                        @endforeach
                    </div>

                    <p x-show="weather && weather.sunrise" x-cloak class="text-sm text-slate-600 flex items-center gap-2">
                        <x-icon name="sun" class="w-4 h-4 text-amber-500 shrink-0" />
                        <span x-text="weather ? @js(__('Matahari terbit')) + ' ' + weather.sunrise + ', ' + @js(__('terbenam')) + ' ' + weather.sunset + ' WIB' : ''"></span>
                    </p>

                    <div x-show="safety" x-cloak class="rounded-2xl border p-4 text-sm"
                         :class="safety && safety.level === 'caution' ? 'bg-amber-50 border-amber-200 text-amber-900' : 'bg-emerald-50 border-emerald-200 text-emerald-900'">
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

                    <p class="text-[11px] text-slate-400">
                        {{ __('Data cuaca:') }} <a href="https://open-meteo.com/" target="_blank" rel="noopener" class="underline hover:text-slate-600">Open-Meteo</a>
                        · {{ __('Lokasi:') }} <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener" class="underline hover:text-slate-600">OpenStreetMap Nominatim</a>.
                        {{ __('Prakiraan model, bukan pengamatan langsung di jalur.') }}
                    </p>
                </div>
            </section>
            @endif

            <!-- B. GPX PROVENANCE -->
            <div class="bg-white rounded-3xl shadow-xl overflow-hidden border border-slate-100 p-6">
                <h3 class="font-bold text-slate-800 mb-4 flex items-center justify-between">
                    <span>GPX Provenance</span>
                    <a href="{{ route('routes.provenance', $route) }}" class="text-xs text-emerald-600 hover:text-emerald-700 font-semibold">{{ __('Lihat riwayat') }}</a>
                </h3>

                @if($route->activeGpxVersion)
                <div class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500">{{ __('Versi aktif') }}</span>
                        <span class="font-bold text-slate-800">v{{ $route->activeGpxVersion->version_number }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500">{{ __('Status') }}</span>
                        <span class="px-2 py-1 rounded-lg text-xs font-bold
                            @if($route->activeGpxVersion->verification_status === 'verified') bg-emerald-100 text-emerald-700
                            @elseif($route->activeGpxVersion->verification_status === 'invalid') bg-red-100 text-red-700
                            @else bg-amber-100 text-amber-700
                            @endif">
                            {{ str_replace('_', ' ', strtoupper($route->activeGpxVersion->verification_status)) }}
                        </span>
                    </div>
                    <div class="rounded-2xl bg-slate-50 border border-slate-100 p-3 text-xs text-slate-600 space-y-1">
                        <p><span class="font-bold text-slate-700">GPX:</span> {{ $route->activeGpxVersion->verification_status_explanation }}</p>
                    </div>
                    <div>
                        <span class="text-slate-500 block mb-1">SHA-256</span>
                        <code class="block text-xs bg-slate-50 border border-slate-200 rounded-xl p-3 break-all text-slate-700">{{ $route->activeGpxVersion->file_hash }}</code>
                    </div>
                    <a href="{{ route('routes.provenance.download-version', [$route, $route->activeGpxVersion]) }}" class="block w-full text-center py-3 rounded-2xl bg-blue-50 text-blue-700 font-bold hover:bg-blue-100">
                        {{ __('Download GPX Aktif') }}
                    </a>
                </div>
                @else
                <p class="text-sm text-slate-500">{{ __('Belum ada metadata provenance untuk GPX ini.') }}</p>
                @endif
            </div>

            <!-- C. SIMILAR ROUTES (SIDEBAR MOVED UP) -->
            @if($similarRoutes->count() > 0)
            <div class="bg-emerald-600 rounded-3xl shadow-xl overflow-hidden text-white relative">
                <div class="absolute inset-x-0 top-0 h-32 bg-white/10 skew-y-6 origin-bottom-left blur-2xl"></div>
                
                <div class="p-5 relative z-10 border-b border-emerald-500/50">
                    <h3 class="font-extrabold text-lg flex items-center">
                        <svg class="w-5 h-5 mr-2 text-emerald-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        {{ __('Rekomendasi Serupa') }}
                    </h3>
                    <p class="text-[11px] text-emerald-100 mt-1 opacity-90 hidden sm:block">{{ __('Model Kosinus SBERT') }}</p>
                </div>

                <div class="p-3 space-y-3 relative z-10 bg-slate-50">
                    @foreach($similarRoutes as $similar)
                    <a href="{{ route('routes.show', $similar) }}" class="flex bg-white rounded-2xl p-2.5 shadow-sm hover:shadow-md border border-slate-100 transition-all gap-3 group">
                        <!-- Map Box Thumbnail -->
                        <div class="w-16 h-16 rounded-xl bg-slate-100 overflow-hidden relative flex-shrink-0 shadow-inner">
                            @if($similar->route_coordinates && count($similar->route_coordinates) > 0)
                            <div id="similar-map-{{ $similar->id }}" class="w-full h-full opacity-80 group-hover:opacity-100"></div>
                            @else
                            <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-emerald-100 to-teal-100"><x-icon name="mountain" class="w-6 h-6 text-emerald-500" /></div>
                            @endif
                        </div>
                        
                        <!-- Content -->
                        <div class="flex-1 min-w-0 flex flex-col justify-between py-1">
                            <h4 class="font-bold text-slate-800 text-sm truncate pr-2 group-hover:text-emerald-600 transition-colors">{{ $similar->name }}</h4>
                            <div class="flex items-center gap-1.5 mt-0.5">
                                <span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-lg text-xs font-black">{{ $similar->similarity_score }}%</span>
                                <span class="text-[10px] text-slate-400 font-mono hidden sm:inline-block">cos={{ number_format($similar->similarity_score / 100, 3) }}</span>
                            </div>
                        </div>
                    </a>
                    @endforeach
                    
                    <a href="{{ route('routes.similar', $route) }}" class="block w-full text-center py-2.5 mt-2 bg-emerald-100 hover:bg-emerald-200 text-emerald-800 text-xs font-bold rounded-xl transition-colors">
                        <span class="inline-flex items-center gap-1">{{ __('Lihat Semua Rekomendasi') }} <x-icon name="arrow-right" class="w-3.5 h-3.5" /></span>
                    </a>
                </div>
            </div>
            @endif

            <!-- C. BASECAMP & PRACTICAL INFO -->
            @if($route->basecamp_name || $route->contact_phone || $route->entry_fee || $route->facilities || $route->best_season || $route->tips)
            <div class="bg-white rounded-3xl shadow-xl border border-slate-100 overflow-hidden">
                <div class="p-6 border-b border-slate-100 bg-slate-50">
                    <h3 class="font-bold text-slate-800 flex items-center">
                        <svg class="w-5 h-5 text-amber-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                        {{ __('Info Praktis & Tips') }}
                    </h3>
                </div>
                <div class="p-6 space-y-5">
                    @if($route->basecamp_name)
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-full bg-purple-100 flex items-center justify-center flex-shrink-0 mt-0.5"><x-icon name="home" class="w-4 h-4 text-purple-600" /></div>
                        <div>
                            <p class="text-xs text-slate-500 font-medium">{{ __('Basecamp') }}</p>
                            <p class="font-bold text-slate-800 text-sm">{{ $route->basecamp_name }}</p>
                            @if($route->basecamp_address)
                                <p class="text-xs text-slate-600 mt-0.5">{{ $route->basecamp_address }}</p>
                            @endif
                            @if($route->basecamp_lat && $route->basecamp_lng)
                                <a href="https://www.google.com/maps?q={{ $route->basecamp_lat }},{{ $route->basecamp_lng }}" target="_blank" rel="noopener" class="text-[10px] text-emerald-600 hover:underline mt-1 inline-flex items-center gap-1"><x-icon name="map" class="w-3 h-3" /> {{ __('Buka di Google Maps') }} <x-icon name="arrow-right" class="w-3 h-3" /></a>
                            @endif
                        </div>
                    </div>
                    @endif

                    @if($route->contact_phone)
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0 mt-0.5"><x-icon name="phone" class="w-4 h-4 text-green-600" /></div>
                        <div>
                            <p class="text-xs text-slate-500 font-medium">{{ __('Kontak') }}</p>
                            <p class="font-bold text-slate-800 text-sm">{{ $route->contact_phone }}</p>
                        </div>
                    </div>
                    @endif

                    @if($route->entry_fee)
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-full bg-yellow-100 flex items-center justify-center flex-shrink-0 mt-0.5"><x-icon name="cash" class="w-4 h-4 text-yellow-600" /></div>
                        <div>
                            <p class="text-xs text-slate-500 font-medium">{{ __('Tiket Masuk') }}</p>
                            <p class="font-black text-emerald-600 text-lg leading-tight">Rp {{ number_format($route->entry_fee, 0, ',', '.') }}</p>
                        </div>
                    </div>
                    @endif

                    @if($route->facilities)
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0 mt-0.5"><x-icon name="check-badge" class="w-4 h-4 text-blue-600" /></div>
                        <div>
                            <p class="text-xs text-slate-500 font-medium">{{ __('Fasilitas') }}</p>
                            <p class="text-sm text-slate-700">{{ $route->facilities }}</p>
                        </div>
                    </div>
                    @endif

                    @if($route->best_season)
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-full bg-orange-100 flex items-center justify-center flex-shrink-0 mt-0.5"><x-icon name="calendar" class="w-4 h-4 text-orange-600" /></div>
                        <div>
                            <p class="text-xs text-slate-500 font-medium">{{ __('Musim Terbaik') }}</p>
                            <p class="font-bold text-slate-800 text-sm">{{ $route->best_season }}</p>
                        </div>
                    </div>
                    @endif

                    @if($route->tips)
                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <div class="bg-amber-50 rounded-xl p-4 border border-amber-100">
                            <p class="text-xs font-bold text-amber-800 mb-1 flex items-center"><x-icon name="light-bulb" class="w-4 h-4 mr-1 text-amber-600" /> {{ __('Tips Penting') }}</p>
                            <p class="text-xs text-amber-900 leading-relaxed">{{ $route->tips }}</p>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
            @endif

        </div>
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
            if (value === null || value === undefined) return '–';
            return Number(value).toLocaleString(document.documentElement.lang === 'en' ? 'en-US' : 'id-ID', { minimumFractionDigits: digits, maximumFractionDigits: digits });
        },
        coords(p) {
            return p ? Number(p.lat).toFixed(5) + ', ' + Number(p.lng).toFixed(5) : '–';
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
                `<line x1="${c.left}" x2="${c.w}" y1="${f(t.y)}" y2="${f(t.y)}" stroke="#e2e8f0" stroke-dasharray="3 3"/>` +
                `<text x="${c.left - 6}" y="${f(t.y + 4)}" text-anchor="end" font-size="11" fill="#64748b">${this.num(t.v)}</text>`
            ).join('');
            const label = @js(__('Profil elevasi dari :min sampai :max mdpl')).replace(':min', this.num(c.min)).replace(':max', this.num(c.max));
            return `<svg viewBox="0 0 ${c.w} ${c.h}" class="w-full h-auto" role="img" aria-label="${label}">
                <defs><linearGradient id="elev-fill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#10b981" stop-opacity="0.35"/>
                    <stop offset="100%" stop-color="#10b981" stop-opacity="0.03"/>
                </linearGradient></defs>
                ${grid}
                <path d="${c.area}" fill="url(#elev-fill)"/>
                <path d="${c.line}" fill="none" stroke="#059669" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                <circle cx="${f(c.start.x)}" cy="${f(c.start.y)}" r="5" fill="#10b981" stroke="#fff" stroke-width="2"/>
                <circle cx="${f(c.top.x)}" cy="${f(c.top.y)}" r="5" fill="#f59e0b" stroke="#fff" stroke-width="2"/>
                <text x="${c.left}" y="${c.h - 2}" font-size="11" fill="#64748b">0 km</text>
                <text x="${c.w - 2}" y="${c.h - 2}" font-size="11" fill="#64748b" text-anchor="end">${this.num(c.km, 1)} km</text>
            </svg>`;
        },
    };
};
</script>
@endif
@if($similarRoutes->count() > 0)
<script>
document.addEventListener('DOMContentLoaded', function() {
    @foreach($similarRoutes as $similar)
    @if($similar->route_coordinates && count($similar->route_coordinates) > 0)
    (function() {
        var coords = @json($similar->route_coordinates);
        if (coords && coords.length > 0 && document.getElementById('similar-map-{{ $similar->id }}')) {
            var map = L.map('similar-map-{{ $similar->id }}', {
                zoomControl: false, dragging: false, scrollWheelZoom: false, doubleClickZoom: false, touchZoom: false,
                attributionControl: false
            });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18 }).addTo(map);
            var poly = L.polyline(coords, { color: '#10b981', weight: 4, opacity: 0.9 }).addTo(map);
            map.fitBounds(poly.getBounds(), { padding: [2, 2] });
        }
    })();
    @endif
    @endforeach
});
</script>
@endif

@if($route->route_coordinates && count($route->route_coordinates) > 0)
<script>
document.addEventListener('DOMContentLoaded', function() {
    var coords = @json($route->route_coordinates);

    if (coords && coords.length > 0 && document.getElementById('route-map')) {
        var map = L.map('route-map', {
            zoomControl: true,
            scrollWheelZoom: true
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '© OpenStreetMap'
        }).addTo(map);

        // Draw route polyline
        var polyline = L.polyline(coords, {
            color: '#10b981',
            weight: 5,
            opacity: 0.9
        }).addTo(map);

        // Add start marker (green)
        L.circleMarker(coords[0], {
            radius: 8,
            fillColor: '#22c55e',
            color: '#fff',
            weight: 2,
            fillOpacity: 1
        }).addTo(map).bindPopup('Start');

        // Add end marker (red)
        L.circleMarker(coords[coords.length - 1], {
            radius: 8,
            fillColor: '#ef4444',
            color: '#fff',
            weight: 2,
            fillOpacity: 1
        }).addTo(map).bindPopup('Finish');

        map.fitBounds(polyline.getBounds(), { padding: [30, 30] });
    }
});
</script>
@endif
@endpush
@endsection
