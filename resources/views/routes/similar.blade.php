@extends('layouts.app')

@section('title', __('Rute Serupa') . ' - ' . $route->name)

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
    $similarWord = fn (float $score) => match (true) {
        $score >= 85 => __('Sangat mirip'),
        $score >= 70 => __('Mirip'),
        default => __('Agak mirip'),
    };
    // Track drawn as SVG (no map tiles needed for a list of up to 20 routes).
    $trackPath = function ($coords, int $w = 320, int $h = 160, int $pad = 16) {
        $coords = is_array($coords) ? array_values(array_filter($coords, fn ($p) => is_array($p) && isset($p[0], $p[1]))) : [];
        $n = count($coords);
        if ($n < 2) return null;
        $step = max(1, (int) ceil($n / 100));
        $track = [];
        foreach ($coords as $i => $p) if ($i % $step === 0 || $i === $n - 1) $track[] = [(float) $p[0], (float) $p[1]];
        $lats = array_column($track, 0); $lngs = array_column($track, 1);
        $kx = cos(deg2rad((min($lats) + max($lats)) / 2));
        $spanX = max((max($lngs) - min($lngs)) * $kx, 1e-6); $spanY = max(max($lats) - min($lats), 1e-6);
        $scale = min(($w - 2 * $pad) / $spanX, ($h - 2 * $pad) / $spanY);
        $ox = ($w - $spanX * $scale) / 2; $oy = ($h - $spanY * $scale) / 2;
        $pts = array_map(fn ($p) => [round($ox + ($p[1] - min($lngs)) * $kx * $scale, 1), round($oy + (max($lats) - $p[0]) * $scale, 1)], $track);
        return ['d' => collect($pts)->map(fn ($p, $i) => ($i ? 'L' : 'M') . $p[0] . ' ' . $p[1])->implode(' '), 'start' => $pts[0]];
    };
@endphp

@include('partials.rs-kit')

@push('styles')
<style>
    .rs-more > summary { list-style: none; cursor: pointer; }
    .rs-more > summary::-webkit-details-marker { display: none; }
    .rs-more[open] .rs-more-icon { transform: rotate(180deg); }
</style>
@endpush

@section('content')
<div class="rs-app">
    @include('partials.rs-head', [
        'back' => ['href' => route('routes.show', $route), 'label' => __('Kembali ke :name', ['name' => $route->name])],
        'eyebrow' => __('Jalur yang mirip dengan'),
        'title' => $route->name,
        'lead' => $similarRoutes->count() > 0
            ? __($similarRoutes->count() === 1 ? 'Ada 1 jalur yang jarak, tanjakan, dan suasananya mirip.' : 'Ada :count jalur yang jarak, tanjakan, dan suasananya mirip. Yang paling mirip di atas.', ['count' => $similarRoutes->count()])
            : null,
        'seed' => $route->id % 29 + 3,
    ])

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        @if($similarRoutes->count() > 0)
        <ol class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
            @foreach($similarRoutes as $similar)
            @php
                $geo = $trackPath($similar->route_coordinates);
                $level = $difficultyLevel($similar->difficulty_level);
                $score = (float) $similar->similarity_score;
            @endphp
            <li class="rs-card overflow-hidden flex flex-col min-w-0 relative group hover:border-rs-z7 transition-colors">
                <div class="h-32 bg-rs-mist flex items-center justify-center">
                    @if($geo)
                    <svg viewBox="0 0 320 160" class="w-full h-full" aria-hidden="true" focusable="false">
                        <path d="{{ $geo['d'] }}" fill="none" stroke="#047857" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>
                        <circle cx="{{ $geo['start'][0] }}" cy="{{ $geo['start'][1] }}" r="5" fill="#fff" stroke="#047857" stroke-width="3"/>
                    </svg>
                    @else
                    <x-icon name="mountain" class="w-10 h-10 text-rs-z7" />
                    @endif
                </div>
                <div class="p-5 flex-1 flex flex-col">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span class="rs-chip {{ $score >= 70 ? 'rs-chip-ok' : 'rs-chip-plain' }}" title="{{ __('Skor :score dari 100', ['score' => $num($score)]) }}">{{ $similarWord($score) }}</span>
                        <span class="inline-flex items-center gap-2 font-semibold text-rs-ink">
                            <span class="rs-diff" aria-hidden="true">@for($i = 1; $i <= 4; $i++)<i class="{{ $i <= $level ? 'on' : '' }}"></i>@endfor</span>
                            <span><span class="sr-only">{{ __('Tingkat kesulitan') }}:</span> {{ __($similar->difficulty_level) }}</span>
                        </span>
                    </div>
                    <h2 class="mt-3 rs-display uppercase text-[1.75rem] leading-none text-rs-z9 break-words">
                        <a href="{{ route('routes.show', $similar) }}" class="after:absolute after:inset-0 after:content-[''] group-hover:text-rs-z7">{{ $similar->name }}</a>
                    </h2>
                    @if($similar->description || $similar->narrative_text)
                    <p class="mt-2 text-sm text-rs-muted line-clamp-2">{{ \Illuminate\Support\Str::limit($similar->description ?: $similar->narrative_text, 110) }}</p>
                    @endif
                    <div class="mt-auto"></div>
                    <dl class="mt-4 pt-4 grid grid-cols-3 gap-3 border-t border-rs-line">
                        <div class="min-w-0"><dt class="text-xs text-rs-muted">{{ __('Jarak') }}</dt><dd class="rs-display rs-num text-xl text-rs-ink whitespace-nowrap">{{ $num($similar->distance_km, 1) }} km</dd></div>
                        <div class="min-w-0"><dt class="text-xs text-rs-muted">{{ __('Total naik') }}</dt><dd class="rs-display rs-num text-xl text-rs-ink whitespace-nowrap">{{ $num($similar->elevation_gain_m) }} m</dd></div>
                        <div class="min-w-0"><dt class="text-xs text-rs-muted">{{ __('Waktu jalan') }}</dt><dd class="rs-display rs-num text-xl text-rs-ink whitespace-nowrap">{{ $hoursLabel($similar->naismith_duration_hour) }}</dd></div>
                    </dl>
                </div>
            </li>
            @endforeach
        </ol>

        <details class="rs-more mt-10 max-w-3xl rounded-lg border border-rs-line bg-white">
            <summary class="flex items-center justify-between gap-3 min-h-[48px] px-4 py-3 font-semibold text-rs-z9">
                <span>{{ __('Bagaimana kemiripan dihitung') }}</span>
                <x-icon name="chevron-down" class="rs-more-icon w-5 h-5 shrink-0 transition-transform" />
            </summary>
            <div class="px-4 pb-4 text-sm text-rs-muted leading-relaxed space-y-2">
                <p>{{ __('Gambaran setiap jalur diubah menjadi 384 angka oleh model bahasa SBERT, lalu dibandingkan dengan cosine similarity, (A · B) / (|A| x |B|). Skornya 0 sampai 100: Sangat mirip mulai 85, Mirip mulai 70. Jalur dengan skor 30 ke bawah tidak ditampilkan.') }}</p>
            </div>
        </details>
        @else
        <div class="rs-card p-6 sm:p-10 text-center max-w-2xl mx-auto">
            <p class="rs-display uppercase text-3xl text-rs-z9">{{ __('Belum ada jalur yang mirip.') }}</p>
            <p class="mt-2 text-rs-muted">{{ __('Jalur ini cukup berbeda dari jalur lain di database, atau ringkasannya belum dibuat.') }}</p>
            <a href="{{ route('routes.index') }}" class="rs-btn-ghost mt-5">{{ __('Lihat semua jalur') }}</a>
        </div>
        @endif
    </div>
</div>
@endsection
