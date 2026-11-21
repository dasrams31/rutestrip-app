@extends('layouts.app')

@section('title', __('Semua Jalur Pendakian Gunung di Jawa'))
@section('meta_description', __('Daftar jalur pendakian gunung di Pulau Jawa lengkap dengan peta GPX, jarak, total tanjakan, estimasi waktu Naismith, dan tingkat kemiringan.'))
@if(request('page') > 1 || request()->filled('provenance_filter'))
@section('robots', 'noindex, follow')
@endif

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
    $difficultyLevel = fn ($level) => match ($level) { 'Mudah' => 1, 'Sedang' => 2, 'Sulit' => 3, default => 4 };
    // Small map thumbnails: at most 80 points per track.
    $thin = function ($coords, int $max = 80) {
        $coords = is_array($coords) ? array_values(array_filter($coords, fn ($p) => is_array($p) && isset($p[0], $p[1]) && is_numeric($p[0]) && is_numeric($p[1]))) : [];
        $n = count($coords);
        if ($n < 2) return [];
        $step = max(1, (int) ceil($n / $max));
        $out = [];
        foreach ($coords as $i => $p) {
            if ($i % $step === 0 || $i === $n - 1) $out[] = [round((float) $p[0], 5), round((float) $p[1], 5)];
        }
        return $out;
    };
    $filterOptions = [
        '' => __('Semua'),
        'verified' => __('Terverifikasi'),
        'invalid' => __('Bermasalah'),
    ];
@endphp

@include('partials.rs-kit')

@section('content')
@inject('conditionsService', 'App\Services\RouteConditionsService')
<div class="rs-app">
    @include('partials.rs-head', [
        'eyebrow' => $routes->total() > 0 ? __($routes->total() === 1 ? '1 jalur dengan file GPX asli' : ':count jalur dengan file GPX asli', ['count' => $num($routes->total())]) : null,
        'title' => __('Semua Rute Pendakian'),
        'lead' => __('Pilih satu jalur untuk melihat peta, cuaca terkini, profil tanjakan, dan ulasan pendaki lain.'),
        'seed' => 9,
        'width' => 'max-w-7xl',
    ])

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        {{-- One segmented row (fits 360 px): which GPX files to show. --}}
        <nav class="flex flex-col min-[480px]:flex-row min-[480px]:items-center gap-2" aria-label="{{ __('Status file GPX') }}">
            <span class="text-sm font-semibold text-rs-muted">{{ __('Status file GPX') }}</span>
            <span class="inline-flex w-full min-[480px]:w-auto rounded-lg border border-rs-line bg-white p-1 gap-1">
                @foreach($filterOptions as $value => $label)
                @php $on = ($filter ?? '') === $value; @endphp
                <a href="{{ route('routes.index', $value ? ['provenance_filter' => $value] : []) }}" @if($on) aria-current="page" @endif
                   class="flex-1 min-[480px]:flex-none inline-flex items-center justify-center min-h-[44px] px-3 rounded-md text-sm font-semibold transition-colors {{ $on ? 'bg-rs-z9 text-white' : 'text-rs-ink hover:bg-rs-paper' }}">
                    {{ $label }}
                </a>
                @endforeach
            </span>
        </nav>

        @if($routes->isEmpty())
        <div class="mt-8 rs-card p-6 sm:p-10 text-center max-w-2xl mx-auto">
            @if($filter)
            <p class="rs-display uppercase text-3xl text-rs-z9">{{ __('Tidak ada jalur dengan status ini.') }}</p>
            <a href="{{ route('routes.index') }}" class="rs-btn-ghost mt-5">{{ __('Tampilkan semua jalur') }}</a>
            @else
            <p class="rs-display uppercase text-3xl text-rs-z9">{{ __('Belum ada jalur.') }}</p>
            <p class="mt-2 text-rs-muted">{{ __('Jalur muncul di sini setelah ada yang mengunggah file GPX. File pertama bisa dari pendakianmu.') }}</p>
            <a href="{{ route('routes.create') }}" class="rs-sign mt-5">{{ __('Upload file GPX') }}</a>
            @endif
        </div>
        @else
        <ul class="mt-6 grid sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
            @foreach($routes as $route)
            @php
                $track = $thin($route->route_coordinates);
                $cardLocation = $conditionsService->cachedLocation($route);
                $cardPlace = $cardLocation ? implode(', ', array_filter([$cardLocation['regency'] ?? null, $cardLocation['province'] ?? null])) : null;
                $cardBasecamp = $conditionsService->basecampName($route);
                $level = $difficultyLevel($route->difficulty_level);
                $gpxStatus = $route->activeGpxVersion?->verification_status;
            @endphp
            <li class="rs-card overflow-hidden flex flex-col min-w-0 relative group hover:border-rs-z7 transition-colors">
                <div class="relative h-36 shrink-0 bg-rs-mist overflow-hidden">
                    @if($track)
                    <div class="route-thumb absolute inset-0 pointer-events-none" data-track='@json($track)' aria-hidden="true"></div>
                    <span class="absolute bottom-1 right-2 z-[1] text-[10px] leading-none text-rs-ink/70 bg-white/80 px-1 rounded pointer-events-none">&copy; OpenStreetMap</span>
                    @else
                    <div class="absolute inset-0 flex items-center justify-center text-rs-z7"><x-icon name="mountain" class="w-10 h-10" /></div>
                    @endif
                </div>

                <div class="p-5 flex-1 flex flex-col">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span class="inline-flex items-center gap-2 font-semibold text-rs-ink">
                            <span class="rs-diff" aria-hidden="true">@for($i = 1; $i <= 4; $i++)<i class="{{ $i <= $level ? 'on' : '' }}"></i>@endfor</span>
                            <span><span class="sr-only">{{ __('Tingkat kesulitan') }}:</span> {{ __($route->difficulty_level) }}</span>
                        </span>
                        <span class="inline-flex items-center gap-1 text-rs-muted" title="{{ __('Jumlah suka') }}">
                            <x-icon name="heart" class="w-4 h-4 text-rs-z7" /> <span class="sr-only">{{ __('Jumlah suka') }}:</span>{{ $num($route->likes_count) }}
                        </span>
                    </div>

                    <h2 class="mt-2 rs-display uppercase text-[1.75rem] leading-none text-rs-z9 break-words">
                        <a href="{{ route('routes.show', $route) }}" class="after:absolute after:inset-0 after:content-[''] group-hover:text-rs-z7">{{ $route->name }}</a>
                    </h2>
                    @if($cardBasecamp || $cardPlace)
                    <p class="mt-2 text-sm text-rs-muted break-words">
                        @if($cardBasecamp)<span class="font-semibold text-rs-ink">{{ $cardBasecamp }}</span>@endif
                        @if($cardBasecamp && $cardPlace) · @endif
                        @if($cardPlace){{ $cardPlace }}@endif
                    </p>
                    @endif
                    @if($gpxStatus && $gpxStatus !== 'verified')
                    <p class="mt-2"><span class="rs-chip {{ $gpxStatus === 'invalid' ? 'rs-chip-bad' : 'rs-chip-warn' }}">{{ $gpxStatus === 'invalid' ? __('File GPX bermasalah') : __('File GPX belum diperiksa') }}</span></p>
                    @endif

                    <p class="mt-3 text-sm text-rs-ink leading-snug">{{ __($route->difficulty_hint) }}</p>

                    <div class="mt-auto"></div>
                    <dl class="mt-4 pt-4 grid grid-cols-3 gap-3 border-t border-rs-line">
                        <div class="min-w-0"><dt class="text-xs text-rs-muted">{{ __('Jarak') }}</dt><dd class="rs-display rs-num text-xl text-rs-ink whitespace-nowrap">{{ $num($route->distance_km, 1) }} km</dd></div>
                        <div class="min-w-0"><dt class="text-xs text-rs-muted">{{ __('Total naik') }}</dt><dd class="rs-display rs-num text-xl text-rs-ink whitespace-nowrap">{{ $num($route->elevation_gain_m) }} m</dd></div>
                        <div class="min-w-0"><dt class="text-xs text-rs-muted">{{ $route->duration_label }}</dt><dd class="rs-display rs-num text-xl text-rs-ink whitespace-nowrap">{{ $hoursLabel($route->naismith_duration_hour) }}</dd></div>
                    </dl>
                    <div class="mt-3 flex items-center justify-between gap-3 text-xs text-rs-muted">
                        <p class="min-w-0 truncate">
                            @if($route->max_elevation_m){{ __('Puncak') }} <span class="font-semibold text-rs-ink">± {{ $num($route->max_elevation_m) }} {{ __('mdpl') }}</span> · @endif
                            {{ __('Diunggah oleh') }} <span class="font-semibold text-rs-ink">{{ $route->uploader_name }}</span>
                        </p>
                        @auth
                        @if(Auth::user()->isAdmin())
                        <form action="{{ route('routes.destroy', $route) }}" method="POST" class="relative z-[2] shrink-0"
                              onsubmit="return confirm(@js(__('Yakin ingin menghapus rute ini?')))">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="w-11 h-11 inline-flex items-center justify-center rounded-lg text-[#9f1239] hover:bg-rose-50" aria-label="{{ __('Hapus Rute') }} {{ $route->name }}">
                                <x-icon name="trash" class="w-5 h-5" />
                            </button>
                        </form>
                        @endif
                        @endauth
                    </div>
                </div>
            </li>
            @endforeach
        </ul>

        <div class="mt-8">
            {{ $routes->links() }}
        </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
// Map thumbnails: created only when a card scrolls near the viewport.
(function () {
    var nodes = document.querySelectorAll('.route-thumb[data-track]');
    if (!nodes.length || typeof L === 'undefined') return;
    function initMap(el) {
        if (el.dataset.ready) return;
        el.dataset.ready = '1';
        var coords;
        try { coords = JSON.parse(el.dataset.track); } catch (e) { return; }
        if (!coords || coords.length < 2) return;
        var map = L.map(el, {
            zoomControl: false, attributionControl: false, dragging: false, touchZoom: false,
            doubleClickZoom: false, scrollWheelZoom: false, boxZoom: false, keyboard: false, tap: false, zoomSnap: 0.25
        });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 17 }).addTo(map);
        L.polyline(coords, { color: '#ffffff', weight: 6, opacity: 0.8, lineCap: 'round', lineJoin: 'round' }).addTo(map);
        var line = L.polyline(coords, { color: '#047857', weight: 3.5, opacity: 1, lineCap: 'round', lineJoin: 'round' }).addTo(map);
        map.fitBounds(line.getBounds(), { padding: [16, 16] });
    }
    if (!('IntersectionObserver' in window)) { nodes.forEach(initMap); return; }
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) { io.unobserve(entry.target); initMap(entry.target); }
        });
    }, { rootMargin: '200px 0px' });
    nodes.forEach(function (el) { io.observe(el); });
})();
</script>
@endpush
@endsection
