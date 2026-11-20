@extends('layouts.app')

@section('title', __('Cari Jalur Pendakian Gunung di Jawa'))
@section('canonical', url('/'))
@section('meta_description', __('Cari jalur pendakian gunung di Pulau Jawa dengan bahasa sehari-hari, misalnya "jalur landai untuk pemula" atau "pendakian singkat tektok". RuteStrip membandingkan jarak, total tanjakan, estimasi waktu, dan kemiringan dari data GPX.'))

@php
    $isEn = app()->getLocale() === 'en';
    $num = fn ($value, int $decimals = 0) => number_format((float) $value, $decimals, $isEn ? '.' : ',', $isEn ? ',' : '.');
    $hoursLabel = function (float $hours) {
        $h = (int) floor($hours);
        $m = (int) round(($hours - $h) * 60);
        if ($m === 60) { $h++; $m = 0; }
        return __(':h j :m mnt', ['h' => $h, 'm' => $m]);
    };
    $webchatUrl = config('services.webchat.url');
    $paperUrl = 'https://sistemasi.ftik.unisi.ac.id/index.php/stmsi/article/view/6087';
    $paperDoi = '10.32520/stmsi.v15i4.6087';
    $notebookUrl = 'https://s.peka.ai.id/rutestrip-notebook';
    $examples = [
        __('Jalur landai untuk pemula'),
        __('Pendakian singkat 2-3 jam'),
        __('Rute menantang dengan elevasi tinggi'),
        __('Trek panjang untuk pendaki berpengalaman'),
    ];
    $stats = is_array($stats ?? null) ? $stats : [];
    $featured = is_array($featured ?? null) ? $featured : [];
    $routeCount = (int) ($stats['routes'] ?? 0);
    $difficultyLevel = fn ($level) => match ($level) { 'Mudah' => 1, 'Sedang' => 2, 'Sulit' => 3, default => 4 };

    // Real GPX track as an SVG path (equirectangular projection into a w x h box) plus its first point.
    $trackGeo = function (array $track, int $w = 320, int $h = 200, int $pad = 22) {
        if (count($track) < 2) return null;
        $lats = array_column($track, 0); $lngs = array_column($track, 1);
        $minLat = min($lats); $maxLat = max($lats); $minLng = min($lngs); $maxLng = max($lngs);
        $kx = cos(deg2rad(($minLat + $maxLat) / 2));
        $spanX = max(($maxLng - $minLng) * $kx, 1e-6); $spanY = max($maxLat - $minLat, 1e-6);
        $scale = min(($w - 2 * $pad) / $spanX, ($h - 2 * $pad) / $spanY);
        $ox = ($w - $spanX * $scale) / 2; $oy = ($h - $spanY * $scale) / 2;
        $pts = array_map(fn ($p) => [round($ox + ($p[1] - $minLng) * $kx * $scale, 1), round($oy + ($maxLat - $p[0]) * $scale, 1)], $track);
        $d = collect($pts)->map(fn ($p, $i) => ($i ? 'L' : 'M') . $p[0] . ' ' . $p[1])->implode(' ');
        return ['d' => $d, 'start' => $pts[0]];
    };

    // Topographic contour motif, generated deterministically (seeded LCG, no randomness between requests).
    $rng = function (int $seed) {
        $s = $seed & 0x7fffffff;
        return function () use (&$s) {
            $s = ($s * 1103515245 + 12345) & 0x7fffffff;
            return $s / 0x7fffffff;
        };
    };
    // Catmull-Rom spline through the points, written as cubic Bezier segments.
    $smooth = function (array $p, bool $closed, int $prec = 0) {
        $n = count($p);
        $at = fn ($i) => $closed ? $p[(($i % $n) + $n) % $n] : $p[max(0, min($n - 1, $i))];
        $f = fn ($v) => $prec ? round($v, $prec) : (int) round($v);
        $d = 'M' . $f($p[0][0]) . ' ' . $f($p[0][1]);
        for ($i = 0, $last = $closed ? $n : $n - 1; $i < $last; $i++) {
            [$x0, $y0] = $at($i - 1); [$x1, $y1] = $at($i); [$x2, $y2] = $at($i + 1); [$x3, $y3] = $at($i + 2);
            $d .= 'C' . $f($x1 + ($x2 - $x0) / 6) . ' ' . $f($y1 + ($y2 - $y0) / 6) . ' '
                . $f($x2 - ($x3 - $x1) / 6) . ' ' . $f($y2 - ($y3 - $y1) / 6) . ' ' . $f($x2) . ' ' . $f($y2);
        }
        return $d . ($closed ? 'Z' : '');
    };
    // Nested closed contour rings around one summit; every 5th ring is an index contour (as on printed maps).
    $rings = function (float $cx, float $cy, int $n, float $r0, float $step, int $seed, float $squash = 0.72, int $prec = 0) use ($rng, $smooth) {
        $rand = $rng($seed);
        $harm = [];
        foreach ([2, 3, 5] as $h) $harm[] = [$h, 0.04 + $rand() * 0.06, $rand() * 2 * M_PI, ($rand() - 0.5) * 0.3];
        $tilt = $rand() * M_PI;
        $out = [];
        for ($k = 0; $k < $n; $k++) {
            $r = $r0 + $k * $step;
            $pts = [];
            for ($j = 0; $j < 28; $j++) {
                $t = 2 * M_PI * $j / 28;
                $f = 1;
                foreach ($harm as [$h, $a, $ph, $dr]) $f += $a * (1 + $k * 0.05) * sin($h * $t + $ph + $k * $dr);
                $x = $r * $f * cos($t); $y = $r * $f * sin($t) * $squash;
                $pts[] = [$cx + $x * cos($tilt) - $y * sin($tilt), $cy + $x * sin($tilt) + $y * cos($tilt)];
            }
            $out[] = ['d' => $smooth($pts, true, $prec), 'index' => $k % 5 === 4];
        }
        return $out;
    };
    // Open contour lines running across a band (section dividers and the trail line).
    $band = function (float $w, float $y0, int $n, float $gap, int $seed, int $seg = 16) use ($rng, $smooth) {
        $rand = $rng($seed);
        $waves = [];
        for ($i = 0; $i < 3; $i++) $waves[] = [3 + $rand() * 9, 1 + $i + $rand(), $rand() * 2 * M_PI, ($rand() - 0.5) * 0.35];
        $out = [];
        for ($k = 0; $k < $n; $k++) {
            $pts = [];
            for ($j = 0; $j <= $seg; $j++) {
                $x = -20 + ($w + 40) * $j / $seg;
                $y = $y0 + $k * $gap;
                foreach ($waves as [$a, $fr, $ph, $dr]) $y += $a * sin(2 * M_PI * $fr * $x / $w + $ph + $k * $dr);
                $pts[] = [$x, $y];
            }
            $out[] = $smooth($pts, false);
        }
        return $out;
    };

    $heroRings = array_merge(
        $rings(1190, 250, 14, 16, 30, 7),
        $rings(240, 860, 10, 22, 34, 19),
        $rings(700, -60, 6, 60, 36, 31),
    );
    $chatRings = $rings(80, 760, 12, 30, 40, 43);
    $uploadRings = $rings(1180, 330, 11, 18, 32, 57);
    $cardRings = $rings(250, 40, 9, 14, 22, 71, 0.8, 1);
    $dividerTop = $band(1440, 22, 5, 15, 83);
    $dividerChat = $band(1440, 18, 4, 16, 97);

    // Trail line beside the method steps: a winding vertical path (viewBox 40 x 1000).
    $trailRand = $rng(113);
    $trailPts = [];
    for ($y = 0; $y <= 1000; $y += 50) $trailPts[] = [20 + 9 * sin($y / 95 + $trailRand() * 0.6), $y];
    $trailPath = $smooth($trailPts, false, 1);

    $hero = $featured[0] ?? null;
    $heroGeo = $hero ? $trackGeo($hero['track'] ?? [], 320, 220, 24) : null;
    $heroGain = $hero['gain'] ?? null;
    $naismith = $hero && $heroGain ? $hero['distance'] / 5 + $heroGain / 600 : null;

    $statItems = array_values(array_filter([
        $routeCount > 0 ? ['value' => $num($routeCount), 'label' => __('jalur dengan data GPX')] : null,
        ($stats['mountains'] ?? 0) > 0 ? ['value' => $num($stats['mountains']), 'label' => __('gunung')] : null,
        ($stats['provinces'] ?? 0) > 0 ? ['value' => $num($stats['provinces']), 'label' => __('provinsi')] : null,
        ($stats['km'] ?? 0) > 0 ? ['value' => $num($stats['km']), 'label' => __('km total panjang jalur')] : null,
    ]));
    $hasFlash = session('success') || session('warning') || (isset($errors) && $errors->any());
@endphp

@push('head')
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
{{-- Barlow Condensed (SIL Open Font License 1.1): drawn from highway and trail signage, the "papan penunjuk jalur" voice from DESIGN.md. --}}
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800&display=optional" rel="stylesheet">
<script>
    // Motion is armed before first paint, only when the visitor allows it; without this class every line is drawn statically.
    (function () {
        try {
            if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                document.documentElement.classList.add('rs-motion');
            }
        } catch (e) {}
    })();
</script>
@endpush

@push('styles')
<style>
    .rs-landing { --rs-ink: #14231d; --rs-muted: #4b5d55; --rs-z9: #053b30; --rs-z8: #0b4a3c; --rs-z7: #047857; --rs-paper: #f6f4ec; --rs-sun: #FCD34D; color: var(--rs-ink); background: var(--rs-paper); }
    .rs-display, .rs-display * { font-family: 'Barlow Condensed', 'Roboto Condensed', 'Arial Narrow', sans-serif-condensed, 'Inter', system-ui, sans-serif; font-weight: 800; letter-spacing: -0.005em; }
    .rs-num { font-variant-numeric: tabular-nums; }
    .rs-landing a, .rs-landing button { -webkit-tap-highlight-color: transparent; }

    /* Focus: dark ring on light surfaces, white ring on zamrud surfaces, dark again inside the white search box. */
    .rs-landing :focus-visible { outline: 3px solid var(--rs-z9); outline-offset: 2px; }
    .rs-landing .rs-on-dark :focus-visible { outline-color: #fff; }
    .rs-landing .rs-on-dark .rs-on-light :focus-visible { outline-color: var(--rs-z9); }

    /* Kuning fajar = the action we want you to take (search, upload). Bordered like a trail sign. */
    .rs-sign { background: var(--rs-sun); color: var(--rs-z9); border: 2px solid var(--rs-z9); }
    .rs-sign:hover { background: #fde68a; }
    .rs-swap { display: inline-grid; }
    .rs-swap > * { grid-area: 1 / 1; }
    .rs-swap > [hidden] { display: block; visibility: hidden; }

    .rs-hero-topo { will-change: transform; }
    .rs-bar { width: calc(var(--v) * 100%); transform-origin: left center; }

    /* Choreography (only with html.rs-motion): lines draw in, tracks trace, markers appear where the trail reaches them. */
    .rs-motion .rs-draw, .rs-motion .rs-trace { stroke-dasharray: 100; stroke-dashoffset: 100; }
    .rs-motion .is-in .rs-draw { stroke-dashoffset: 0; transition: stroke-dashoffset 1.8s cubic-bezier(.33, 0, .2, 1) calc(var(--i, 0) * 60ms); }
    .rs-motion .is-in .rs-trace { stroke-dashoffset: 0; transition: stroke-dashoffset 2.2s cubic-bezier(.45, 0, .2, 1) var(--delay, .3s); }
    .rs-motion .rs-pop { opacity: 0; transform: scale(.5); transform-box: fill-box; transform-origin: center; }
    .rs-motion .is-in .rs-pop { opacity: 1; transform: none; transition: opacity .35s ease-out var(--delay, 0s), transform .45s ease-out var(--delay, 0s); }
    .rs-motion .rs-bar { transform: scaleX(0); }
    .rs-motion .is-in .rs-bar { transform: none; transition: transform 1.2s cubic-bezier(.2, .7, .2, 1) var(--delay, .2s); }
    @media (prefers-reduced-motion: reduce) {
        .rs-motion .rs-draw, .rs-motion .rs-trace { stroke-dashoffset: 0 !important; transition: none !important; }
        .rs-motion .rs-pop, .rs-motion .rs-bar { opacity: 1 !important; transform: none !important; transition: none !important; }
        .rs-hero-topo { transform: none !important; }
    }

    /* The landing runs straight into the footer (no strip of body gradient between them). */
    main:has(> .rs-landing:last-child) + footer { margin-top: 0; }
</style>
@endpush

@section('content')
<div class="rs-landing overflow-x-clip {{ $hasFlash ? 'mt-4' : '-mt-4' }}">

    {{-- Shared contour pattern for the route cards (one definition, reused with <use>). --}}
    <svg width="0" height="0" class="absolute" aria-hidden="true" focusable="false">
        <defs>
            <symbol id="rs-topo-card" viewBox="0 0 320 200">
                <g fill="none" stroke="currentColor" stroke-linejoin="round">
                    @foreach($cardRings as $ring)
                    <path d="{{ $ring['d'] }}" stroke-width="{{ $ring['index'] ? 1.6 : 1 }}"/>
                    @endforeach
                </g>
            </symbol>
        </defs>
    </svg>

    {{-- ================= HERO: search is the main action ================= --}}
    <section id="cari" class="rs-on-dark relative overflow-hidden bg-[#053b30] text-white" aria-labelledby="hero-title">
        <div class="rs-hero-topo absolute inset-x-0 -top-[10%] h-[120%] pointer-events-none" aria-hidden="true">
            <svg class="w-full h-full" viewBox="0 0 1440 900" preserveAspectRatio="xMaxYMin slice" data-reveal="load" focusable="false">
                <g fill="none" stroke="#a7f3d0" stroke-linejoin="round">
                    @foreach($heroRings as $ring)
                    <path class="rs-draw" pathLength="100" style="--i: {{ $loop->index % 14 }}" d="{{ $ring['d'] }}"
                          stroke-width="{{ $ring['index'] ? 2 : 1.2 }}" stroke-opacity="{{ $ring['index'] ? .3 : .17 }}"/>
                    @endforeach
                </g>
            </svg>
        </div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-10 pb-8 sm:pt-16 lg:pt-20 lg:pb-12">
            <div class="grid lg:grid-cols-12 gap-10 lg:gap-12 items-center">
                <div class="lg:col-span-7 min-w-0">
                    <p class="text-sm sm:text-base font-semibold text-[#a7f3d0]">{{ __('Jalur pendakian gunung di Pulau Jawa') }}</p>
                    <h1 id="hero-title" class="rs-display mt-3 uppercase text-[clamp(2.4rem,10.5vw,5.75rem)] leading-[0.92] [text-wrap:balance]">
                        {{ __('Bilang saja mau mendaki yang seperti apa.') }}
                    </h1>
                    <p class="mt-5 text-base sm:text-lg text-[#d1fae5] max-w-xl leading-relaxed">
                        @if($routeCount > 0)
                            {{ __('Tulis seperti bicara ke teman satu rombongan, misalnya "jalur landai untuk pemula". RuteStrip mencocokkannya dengan data GPX :count jalur: jarak, total tanjakan, kemiringan, dan estimasi waktu.', ['count' => $num($routeCount)]) }}
                        @else
                            {{ __('Tulis seperti bicara ke teman satu rombongan, misalnya "jalur landai untuk pemula". RuteStrip mencocokkannya dengan data GPX setiap jalur: jarak, total tanjakan, kemiringan, dan estimasi waktu.') }}
                        @endif
                    </p>

                    <form action="{{ route('search.submit') }}" method="POST" role="search" class="mt-7 max-w-2xl" data-search-form>
                        @csrf
                        <label for="hero-query" class="block text-sm font-semibold text-white mb-2">{{ __('Jalur seperti apa yang kamu cari?') }}</label>
                        <div class="rs-on-light flex flex-col sm:flex-row gap-2 rounded-xl bg-white p-2">
                            <div class="relative flex-1 min-w-0">
                                <x-icon name="search" class="w-5 h-5 text-[#4b5d55] absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                                <input id="hero-query" type="text" name="query" value="{{ old('query') }}" required minlength="3" maxlength="500"
                                       autocomplete="off" enterkeyhint="search"
                                       placeholder="{{ __('Contoh: jalur landai untuk pemula dengan pemandangan indah') }}"
                                       @error('query') aria-invalid="true" aria-describedby="hero-query-error" @enderror
                                       class="w-full min-h-[52px] pl-11 pr-3 rounded-lg border-0 bg-transparent text-[#14231d] placeholder-[#5b6b64] text-base">
                            </div>
                            <button type="submit" data-search-submit
                                    class="rs-sign rs-swap place-items-center min-h-[52px] rounded-lg px-6 text-base font-bold transition-colors">
                                <span data-idle>{{ __('Cari jalur') }}</span>
                                <span data-busy hidden>{{ __('Mencari jalur...') }}</span>
                            </button>
                        </div>
                        @error('query')
                        <p id="hero-query-error" class="mt-2 inline-flex items-start gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-[#9f1239]">
                            <x-icon name="exclamation" class="w-5 h-5 shrink-0" />{{ $message }}
                        </p>
                        @enderror
                        <p class="sr-only" role="status" aria-live="polite" data-search-status data-text="{{ __('Mencari jalur yang cocok, tunggu sebentar.') }}"></p>

                        {{-- Example queries: submit buttons of the same form (work without JS) --}}
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <span class="text-sm text-[#d1fae5] mr-1">{{ __('Atau coba:') }}</span>
                            @foreach($examples as $example)
                            <button type="submit" name="query" value="{{ $example }}" formnovalidate
                                    class="min-h-[44px] rounded-lg border border-white/30 px-3.5 text-sm font-medium text-white text-left hover:bg-white/10 hover:border-white/60 transition-colors">
                                {{ $example }}
                            </button>
                            @endforeach
                        </div>
                    </form>
                </div>

                {{-- One real route from the database, its GPX track traced in (desktop only; the cards below cover mobile). --}}
                @if($hero && $heroGeo)
                <a href="{{ $hero['url'] }}" class="hidden lg:block lg:col-span-5 group rounded-xl border border-white/25 bg-[#0b4a3c] p-6 hover:border-white/60 transition-colors">
                    <span class="block text-sm text-[#a7f3d0]">{{ __('Contoh dari database') }}</span>
                    <span class="rs-display block mt-1 text-4xl uppercase leading-none">{{ $hero['name'] }}</span>
                    <svg viewBox="0 0 320 220" class="mt-4 w-full h-auto" data-reveal="load" aria-hidden="true" focusable="false">
                        <path class="rs-trace" style="--delay: .7s" pathLength="100" d="{{ $heroGeo['d'] }}" fill="none" stroke="#ecfdf5" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>
                        <circle class="rs-pop" style="--delay: .5s" cx="{{ $heroGeo['start'][0] }}" cy="{{ $heroGeo['start'][1] }}" r="6" fill="#053b30" stroke="#ecfdf5" stroke-width="3"/>
                    </svg>
                    <span class="mt-2 block text-sm text-[#c7d9d0]">
                        {{ __('Jejak GPX asli') }}@if($hero['basecamp']), {{ __('mulai dari :place', ['place' => $hero['basecamp']]) }}@endif
                    </span>
                    <dl class="mt-4 grid grid-cols-3 border-t border-white/20 pt-4">
                        <div><dt class="text-xs text-[#c7d9d0]">{{ __('Jarak') }}</dt><dd class="rs-display rs-num text-2xl">{{ $num($hero['distance'], 1) }} km</dd></div>
                        <div><dt class="text-xs text-[#c7d9d0]">{{ __('Puncak') }}</dt><dd class="rs-display rs-num text-2xl">{{ $hero['summit'] ? $num($hero['summit']) . ' m' : '-' }}</dd></div>
                        <div><dt class="text-xs text-[#c7d9d0]">{{ __('Estimasi') }}</dt><dd class="rs-display rs-num text-2xl">{{ $hoursLabel($hero['hours']) }}</dd></div>
                    </dl>
                    <span class="mt-4 inline-block text-sm font-semibold underline decoration-white/40 underline-offset-4 group-hover:decoration-white">{{ __('Lihat detail jalur ini') }}</span>
                </a>
                @endif
            </div>

            {{-- Live numbers from the database; nothing is shown when a number is not available. --}}
            @if(count($statItems))
            <dl class="mt-12 lg:mt-16 grid grid-cols-2 md:grid-cols-4 border-t border-white/25" aria-label="{{ __('Isi database RuteStrip saat ini') }}">
                @foreach($statItems as $item)
                <div class="flex flex-col-reverse justify-end py-4 md:py-5 pr-3 {{ $loop->index % 2 ? 'pl-4 border-l border-white/15' : '' }} md:pl-5 md:border-l md:border-white/15 {{ $loop->first ? 'md:!pl-0 md:!border-l-0' : '' }} {{ $loop->index < 2 ? 'border-b border-white/15 md:border-b-0' : '' }}">
                    <dt class="text-sm text-[#d1fae5] leading-snug">{{ $item['label'] }}</dt>
                    <dd class="rs-display rs-num text-[2.6rem] sm:text-5xl leading-none">{{ $item['value'] }}</dd>
                </div>
                @endforeach
            </dl>
            @endif
        </div>
    </section>

    {{-- ================= FEATURED ROUTES ================= --}}
    <section class="pt-16 pb-16 sm:pt-24 sm:pb-20" aria-labelledby="featured-title">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-12 gap-4 lg:gap-10 items-end">
                <h2 id="featured-title" class="lg:col-span-7 rs-display uppercase text-[clamp(2.4rem,8vw,4rem)] leading-[0.95] text-[#053b30] [text-wrap:balance]">{{ __('Masih bingung? Mulai dari jalur ini.') }}</h2>
                <div class="lg:col-span-5 lg:pb-1">
                    <p class="text-[#4b5d55] leading-relaxed">{{ __('Satu jalur per gunung, diurutkan dari yang paling banyak disukai pendaki, lalu yang terbaru masuk database.') }}</p>
                    @if($routeCount > 0)
                    <a href="{{ route('routes.index') }}" class="mt-3 inline-flex items-center min-h-[44px] font-semibold text-[#047857] underline decoration-[#047857]/40 underline-offset-4 hover:decoration-[#047857]">
                        {{ __('Lihat semua :count jalur', ['count' => $num($routeCount)]) }}
                    </a>
                    @endif
                </div>
            </div>

            @if(count($featured))
            <ul class="mt-8 sm:mt-10 grid gap-4 sm:gap-5 md:grid-cols-2 lg:grid-cols-12 {{ count($featured) > 2 ? 'lg:grid-rows-2' : '' }}">
                @foreach($featured as $item)
                @php
                    $big = $loop->first;
                    $geo = $trackGeo($item['track'] ?? [], 320, 200, 22);
                    $level = $difficultyLevel($item['difficulty']);
                @endphp
                <li class="min-w-0 {{ $big ? 'md:col-span-2 lg:col-span-7 ' . (count($featured) > 2 ? 'lg:row-span-2' : '') : 'lg:col-span-5' }}">
                    <a href="{{ $item['url'] }}" class="group h-full flex {{ $big ? 'flex-col' : 'flex-col sm:flex-row lg:flex-row' }} rounded-lg bg-white border border-[#dedbcd] hover:border-[#047857] transition-colors overflow-hidden">
                        <div class="relative shrink-0 bg-[#eef4ef] text-[#047857] {{ $big ? 'h-56 sm:h-72 lg:flex-1 lg:min-h-[18rem]' : 'h-44 sm:h-auto sm:w-2/5' }}" data-reveal="view">
                            <svg viewBox="0 0 320 200" preserveAspectRatio="xMidYMid slice" class="absolute inset-0 w-full h-full opacity-[.16]" aria-hidden="true" focusable="false">
                                <use href="#rs-topo-card" @if($loop->index === 1) transform="matrix(-1 0 0 1 320 0)" @elseif($loop->index === 2) transform="matrix(1 0 0 -1 0 200)" @endif />
                            </svg>
                            @if($geo)
                            <svg viewBox="0 0 320 200" preserveAspectRatio="xMidYMid meet" class="absolute inset-0 w-full h-full" aria-hidden="true" focusable="false">
                                <path class="rs-trace" pathLength="100" d="{{ $geo['d'] }}" fill="none" stroke="#047857" stroke-width="{{ $big ? 3 : 3.5 }}" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
                                <circle class="rs-pop" style="--delay: .1s" cx="{{ $geo['start'][0] }}" cy="{{ $geo['start'][1] }}" r="5" fill="#fff" stroke="#047857" stroke-width="3"/>
                            </svg>
                            @endif
                        </div>
                        <div class="{{ $big ? 'p-5 sm:p-7' : 'p-5' }} flex-1 min-w-0 flex flex-col">
                            {{-- Name first in the DOM (it is the link's accessible name), difficulty shown above it. --}}
                            <h3 class="rs-display uppercase leading-none text-[#053b30] group-hover:text-[#047857] transition-colors {{ $big ? 'text-4xl sm:text-5xl' : 'text-3xl' }}">{{ $item['name'] }}</h3>
                            <div class="order-first mb-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                <span class="inline-flex items-center gap-2 text-sm font-semibold text-[#14231d]">
                                    <span class="flex items-end gap-0.5" aria-hidden="true">
                                        @for($i = 1; $i <= 4; $i++)
                                        <span class="w-1.5 rounded-[1px] {{ $i <= $level ? 'bg-[#047857]' : 'bg-[#cfd8d2]' }}" style="height: {{ 6 + $i * 3 }}px"></span>
                                        @endfor
                                    </span>
                                    <span><span class="sr-only">{{ __('Tingkat kesulitan') }}:</span> {{ __($item['difficulty']) }}</span>
                                </span>
                                @if($item['likes'] > 0)
                                <span class="inline-flex items-center gap-1 text-sm text-[#4b5d55]">
                                    <x-icon name="heart" class="w-4 h-4 text-[#047857]" />
                                    <span>{{ trans_choice('{1} disukai :count kali|[2,*] disukai :count kali', $item['likes'], ['count' => $num($item['likes'])]) }}</span>
                                </span>
                                @endif
                            </div>
                            @if($item['basecamp'])
                            <p class="mt-2 text-sm text-[#4b5d55]">{{ \Illuminate\Support\Str::startsWith(mb_strtolower($item['basecamp']), 'basecamp') ? $item['basecamp'] : __('Basecamp :place', ['place' => $item['basecamp']]) }}</p>
                            @endif
                            <dl class="mt-auto pt-4 {{ $big ? 'grid grid-cols-3 gap-3 sm:max-w-md' : 'flex flex-wrap gap-x-6 gap-y-2 [&_dd]:whitespace-nowrap' }}">
                                <div><dt class="text-xs text-[#4b5d55]">{{ __('Jarak') }}</dt><dd class="rs-display rs-num text-xl {{ $big ? 'sm:text-2xl' : 'sm:text-2xl lg:text-xl' }} text-[#14231d]">{{ $num($item['distance'], 1) }} km</dd></div>
                                <div><dt class="text-xs text-[#4b5d55]">{{ __('Puncak') }}</dt><dd class="rs-display rs-num text-xl {{ $big ? 'sm:text-2xl' : 'sm:text-2xl lg:text-xl' }} text-[#14231d]">{{ $item['summit'] ? $num($item['summit']) . ' m' : '-' }}</dd></div>
                                <div><dt class="text-xs text-[#4b5d55]">{{ __('Estimasi') }}</dt><dd class="rs-display rs-num text-xl {{ $big ? 'sm:text-2xl' : 'sm:text-2xl lg:text-xl' }} text-[#14231d]">{{ $hoursLabel($item['hours']) }}</dd></div>
                            </dl>
                        </div>
                    </a>
                </li>
                @endforeach
            </ul>
            @else
            {{-- Empty state: no route with a drawable GPX track yet. --}}
            <div class="mt-10 rounded-lg border-2 border-dashed border-[#cfd8d2] bg-white px-5 py-10 sm:px-10 text-center">
                <p class="rs-display uppercase text-3xl text-[#053b30]">{{ __('Belum ada jalur yang bisa ditampilkan.') }}</p>
                <p class="mt-2 text-[#4b5d55] max-w-lg mx-auto">{{ __('Jalur muncul di sini setelah ada file GPX dengan jejak lengkap di database. File pertama bisa dari pendakianmu.') }}</p>
                <a href="{{ route('routes.create') }}" class="rs-sign mt-5 inline-flex items-center justify-center min-h-[48px] rounded-lg px-6 font-bold">{{ __('Upload file GPX') }}</a>
            </div>
            @endif
        </div>
    </section>

    {{-- ================= HOW THE RECOMMENDATION WORKS ================= --}}
    <section class="relative bg-white" aria-labelledby="how-title">
        <svg class="block w-full h-16 sm:h-20 text-[#047857]" viewBox="0 0 1440 100" preserveAspectRatio="none" data-reveal="view" aria-hidden="true" focusable="false">
            <g fill="none" stroke="currentColor" stroke-opacity=".35">
                @foreach($dividerTop as $d)
                <path class="rs-draw" pathLength="100" style="--i: {{ $loop->index * 2 }}" d="{{ $d }}" stroke-width="{{ $loop->index === 2 ? 2 : 1.2 }}" vector-effect="non-scaling-stroke"/>
                @endforeach
            </g>
        </svg>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-8 pb-16 sm:pt-12 sm:pb-24 grid lg:grid-cols-12 gap-10 lg:gap-16">
            <div class="lg:col-span-5 lg:sticky lg:top-28 self-start">
                <h2 id="how-title" class="rs-display uppercase text-[clamp(2.4rem,8vw,4rem)] leading-[0.95] text-[#053b30] [text-wrap:balance]">{{ __('Begini kami membaca permintaanmu.') }}</h2>
                <p class="mt-4 text-[#4b5d55] leading-relaxed">{{ __('Kamu tidak perlu menebak kata kunci yang pas. Ambil contoh kalimat ini:') }}</p>
                <form action="{{ route('search.submit') }}" method="POST" class="mt-5" data-search-form>
                    @csrf
                    <p class="rs-display text-3xl sm:text-4xl text-[#14231d] border-l-4 border-[#047857] pl-4">{{ __('"jalur landai untuk pemula"') }}</p>
                    <button type="submit" name="query" value="{{ __('jalur landai untuk pemula') }}" data-search-submit
                            class="rs-swap mt-5 min-h-[48px] rounded-lg bg-[#047857] px-5 font-semibold text-white hover:bg-[#065f46] transition-colors">
                        <span data-idle>{{ __('Cari dengan kalimat ini') }}</span>
                        <span data-busy hidden>{{ __('Mencari jalur...') }}</span>
                    </button>
                    <p class="sr-only" role="status" aria-live="polite" data-search-status data-text="{{ __('Mencari jalur yang cocok, tunggu sebentar.') }}"></p>
                </form>
            </div>

            <ol class="lg:col-span-7 relative" data-reveal="view">
                <svg class="absolute left-0 top-2 bottom-2 w-10 h-[calc(100%-1rem)]" viewBox="0 0 40 1000" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <defs>
                        <mask id="rs-trail-mask" maskUnits="userSpaceOnUse" x="-10" y="-10" width="60" height="1020">
                            <path class="rs-trace" style="--delay: .2s" pathLength="100" d="{{ $trailPath }}" fill="none" stroke="#fff" stroke-width="14" vector-effect="non-scaling-stroke"/>
                        </mask>
                    </defs>
                    <path d="{{ $trailPath }}" mask="url(#rs-trail-mask)" fill="none" stroke="#047857" stroke-width="2.5" stroke-dasharray="7 6" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
                </svg>

                @php
                    $steps = [
                        [
                            'title' => __('Kalimatmu diubah menjadi makna'),
                            'text' => __('Model SBERT multibahasa (paraphrase-multilingual-MiniLM-L12-v2) mengubah kalimatmu menjadi 384 angka yang mewakili maknanya. Yang dibandingkan adalah maksud, jadi kalimat dengan kata berbeda tetap bisa bertemu jalur yang sama.'),
                            'tag' => 'SBERT',
                        ],
                        [
                            'title' => __('Dicocokkan dengan narasi setiap jalur'),
                            'text' => __('Setiap jalur punya narasi yang ditulis dari statistik GPX-nya. Kemiripannya dihitung dengan cosine similarity: 40% dari narasi utuh dan 60% dari kalimat aspek yang paling cocok.'),
                            'tag' => 'Cosine similarity',
                        ],
                        [
                            'title' => __('Kata niat dicek ke angka asli'),
                            'text' => __('Kata seperti landai, pemula, curam, singkat, atau panjang dicocokkan dengan kemiringan, durasi, dan total tanjakan setiap jalur, dengan bobot 45%. Nama gunung atau basecamp yang kamu sebut ikut dihitung dengan bobot 35%.'),
                            'tag' => __('Skor hybrid'),
                        ],
                        [
                            'title' => __('Waktu dan tingkat kesulitan dihitung dari GPX'),
                            'text' => __('Estimasi waktu memakai aturan Naismith: 1 jam per 5 km ditambah 1 jam per 600 m tanjakan. Tingkat kesulitan memakai skor Shenandoah, akar dari tanjakan (kaki) x 2 x jarak (mil), dinaikkan 15% untuk jalur sangat curam atau puncak di atas 3.000 m.'),
                            'tag' => 'Naismith, Shenandoah',
                        ],
                    ];
                @endphp
                @foreach($steps as $step)
                <li class="relative pl-14 sm:pl-16 {{ $loop->last ? '' : 'pb-10 sm:pb-14' }}">
                    <svg class="absolute left-[7px] top-0.5 w-[26px] h-[22px]" viewBox="0 0 26 22" aria-hidden="true" focusable="false">
                        <path class="rs-pop" style="--delay: {{ 0.3 + $loop->index * 0.45 }}s" d="M13 2 L24 20 H2 Z" fill="{{ $loop->last ? '#053b30' : '#fff' }}" stroke="#053b30" stroke-width="2.5" stroke-linejoin="round"/>
                    </svg>
                    <p class="text-sm font-semibold text-[#047857]">{{ $step['tag'] }}</p>
                    <h3 class="mt-1 text-xl sm:text-2xl font-bold text-[#14231d] leading-snug">{{ $step['title'] }}</h3>
                    <p class="mt-2 text-[#4b5d55] leading-relaxed max-w-2xl">{{ $step['text'] }}</p>

                    @if($loop->last && $hero && $naismith)
                    {{-- Worked example with the real numbers of the featured route. --}}
                    <div class="mt-5 rounded-lg bg-[#eef4ef] p-4 sm:p-5 max-w-2xl">
                        <p class="text-sm font-semibold text-[#14231d]">{{ __('Contoh: :route', ['route' => $hero['name']]) }}</p>
                        <p class="mt-2 rs-display rs-num text-2xl sm:text-3xl text-[#053b30] break-words">
                            {{ $num($hero['distance'], 1) }} ÷ 5 + {{ $num($heroGain) }} ÷ 600 = {{ $num($naismith, 1) }} {{ __('jam') }}
                        </p>
                        <p class="mt-1 text-sm text-[#4b5d55]">
                            {{ __(':km km dan :gain m tanjakan, sekitar :time. Tingkat kesulitannya: :level.', ['km' => $num($hero['distance'], 1), 'gain' => $num($heroGain), 'time' => $hoursLabel($naismith), 'level' => __($hero['difficulty'])]) }}
                        </p>
                    </div>
                    @endif
                </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ================= RESEARCH ================= --}}
    <section class="pt-16 pb-16 sm:pt-24 sm:pb-24" aria-labelledby="research-title">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 grid lg:grid-cols-12 gap-10 lg:gap-16 items-start">
            <div class="lg:col-span-7 min-w-0">
                <h2 id="research-title" class="rs-display uppercase text-[clamp(2.4rem,8vw,4rem)] leading-[0.95] text-[#053b30] [text-wrap:balance]">{{ __('Metodenya sudah diuji dan terbit di jurnal.') }}</h2>
                <p class="mt-4 text-[#4b5d55] leading-relaxed max-w-2xl">
                    {{ __('Artikel penelitiannya terbit di SISTEMASI: Jurnal Sistem Informasi, Vol. 15 No. 4 (2026), halaman 1241-1256, jurnal terakreditasi SINTA 3.') }}
                </p>
                <blockquote class="mt-6 border-l-4 border-[#047857] pl-4 sm:pl-5 max-w-2xl">
                    <p class="text-lg sm:text-xl font-semibold leading-snug text-[#14231d]" lang="en">Mountain Hiking Route Recommendation System using Content-based Filtering with SBERT and Cosine Similarity</p>
                    <footer class="mt-2 text-sm text-[#4b5d55]">Rama Danadipa Putra Wijaya, Arif Nur Rahman</footer>
                </blockquote>
                <div class="mt-7 flex flex-wrap gap-3">
                    <a href="{{ $paperUrl }}" target="_blank" rel="noopener" class="inline-flex items-center min-h-[48px] rounded-lg bg-[#047857] px-5 font-semibold text-white hover:bg-[#065f46] transition-colors">
                        {{ __('Baca artikel di SISTEMASI') }}<span class="sr-only"> {{ __('(tab baru)') }}</span>
                    </a>
                    <a href="https://doi.org/{{ $paperDoi }}" target="_blank" rel="noopener" class="inline-flex items-center min-h-[48px] rounded-lg border-2 border-[#047857] px-4 font-semibold text-[#047857] hover:bg-[#047857]/5 break-all">
                        DOI {{ $paperDoi }}<span class="sr-only"> {{ __('(tab baru)') }}</span>
                    </a>
                </div>
                <ul class="mt-5 space-y-1 text-[#14231d]">
                    <li>
                        <a href="{{ $notebookUrl }}" target="_blank" rel="noopener" class="inline-flex items-center min-h-[44px] font-semibold text-[#047857] underline decoration-[#047857]/40 underline-offset-4 hover:decoration-[#047857]">
                            {{ __('Jalankan ulang metode dan evaluasinya di Google Colab') }}<span class="sr-only"> {{ __('(tab baru)') }}</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('architecture') }}" class="inline-flex items-center min-h-[44px] font-semibold text-[#047857] underline decoration-[#047857]/40 underline-offset-4 hover:decoration-[#047857]">
                            {{ __('Lihat arsitektur sistem RuteStrip') }}
                        </a>
                    </li>
                </ul>
            </div>

            <figure class="lg:col-span-5 lg:mt-24 rounded-lg bg-white border border-[#dedbcd] p-5 sm:p-7" data-reveal="view">
                <figcaption class="text-sm font-semibold text-[#14231d]">{{ __('Hasil di artikel: Precision@5 pada 39 jalur') }}</figcaption>
                <div class="mt-5 space-y-6">
                    <div>
                        <div class="flex items-end justify-between gap-3">
                            <span class="font-semibold text-[#14231d] leading-tight">{{ __('SBERT dan cosine similarity') }}</span>
                            <span class="rs-display rs-num text-6xl leading-none text-[#047857]">{{ $num(0.60, 2) }}</span>
                        </div>
                        <div class="mt-2 h-3 rounded-sm bg-[#e8ece6] overflow-hidden"><div class="rs-bar h-full bg-[#047857]" style="--v: .60"></div></div>
                    </div>
                    <div>
                        <div class="flex items-end justify-between gap-3">
                            <span class="font-semibold text-[#14231d] leading-tight">{{ __('TF-IDF (pembanding)') }}</span>
                            <span class="rs-display rs-num text-5xl leading-none text-[#4b5d55]">{{ $num(0.25, 2) }}</span>
                        </div>
                        <div class="mt-2 h-3 rounded-sm bg-[#e8ece6] overflow-hidden"><div class="rs-bar h-full bg-[#4b5d55]" style="--v: .25; --delay: .4s"></div></div>
                    </div>
                </div>
                <p class="mt-6 text-sm text-[#4b5d55] leading-relaxed">
                    {{ __('Angka ini hasil evaluasi di artikel, diukur saat penelitian. Ini bukan pengukuran situs yang sedang berjalan.') }}
                    @if($routeCount > 0)
                    {{ __('Database situs sekarang berisi :count jalur, jadi peringkat di situs bisa berbeda.', ['count' => $num($routeCount)]) }}
                    @endif
                </p>
            </figure>
        </div>
    </section>

    {{-- ================= AI CHAT + ON-SITE ASSISTANT ================= --}}
    <section class="rs-on-dark relative overflow-hidden bg-[#053b30] text-white" aria-labelledby="chat-title">
        <svg class="absolute inset-0 w-full h-full pointer-events-none" viewBox="0 0 1440 900" preserveAspectRatio="xMinYMax slice" data-reveal="view" aria-hidden="true" focusable="false">
            <g fill="none" stroke="#a7f3d0" stroke-linejoin="round">
                @foreach($chatRings as $ring)
                <path class="rs-draw" pathLength="100" style="--i: {{ $loop->index }}" d="{{ $ring['d'] }}" stroke-width="{{ $ring['index'] ? 2 : 1.2 }}" stroke-opacity="{{ $ring['index'] ? .26 : .14 }}"/>
                @endforeach
            </g>
        </svg>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-24 grid lg:grid-cols-12 gap-10 lg:gap-14 items-center">
            <div class="lg:col-span-6 lg:order-2 min-w-0">
                <h2 id="chat-title" class="rs-display uppercase text-[clamp(2.4rem,8vw,4rem)] leading-[0.95] [text-wrap:balance]">{{ __('Rencanakan pendakiannya di RuteStrip AI Chat.') }}</h2>
                <p class="mt-4 text-[#d1fae5] leading-relaxed max-w-xl">
                    {{ __('Asisten pendakian yang menjawab dari data jalur RuteStrip. Tanya soal cuaca di basecamp hari ini, minta itinerary 2 hari, atau cari jalur yang cocok untuk rombonganmu.') }}
                </p>
                <dl class="mt-6 grid sm:grid-cols-2 gap-x-8 gap-y-4 max-w-xl">
                    @foreach([
                        [__('Cuaca live'), __('Suhu, angin, dan hujan di basecamp dan puncak')],
                        [__('Itinerary'), __('Rencana per hari lengkap dengan estimasi waktu')],
                        [__('Info porter'), __('Kapan perlu porter dan apa yang perlu disiapkan')],
                        [__('Peta satelit'), __('Lihat jalur di atas citra satelit')],
                    ] as [$term, $desc])
                    <div class="border-t border-white/20 pt-3">
                        <dt class="font-semibold">{{ $term }}</dt>
                        <dd class="mt-0.5 text-sm text-[#c7d9d0]">{{ $desc }}</dd>
                    </div>
                    @endforeach
                </dl>
                <div class="mt-8 flex flex-col sm:flex-row sm:items-center gap-3">
                    <a href="{{ $webchatUrl }}" class="inline-flex items-center justify-center min-h-[48px] rounded-lg bg-white px-6 font-bold text-[#053b30] hover:bg-[#ecfdf5] transition-colors">
                        {{ __('Buka RuteStrip AI Chat') }}
                    </a>
                    <p class="text-sm text-[#c7d9d0]">{{ __('Masuk dengan akun RuteStrip yang sama.') }}</p>
                </div>

                <div class="mt-10 border-t border-white/20 pt-6 max-w-xl">
                    <p class="font-semibold">{{ __('Pertanyaan soal situs ini?') }}</p>
                    <p class="mt-1 text-sm text-[#c7d9d0] leading-relaxed">
                        {{ __('Tombol robot di pojok kanan bawah membuka Asisten RuteStrip, tanpa perlu masuk. Tanyakan cara upload GPX atau metode yang dipakai. Tombol obrolan di pojok kiri bawah membuka AI Chat di halaman ini (perlu akun).') }}
                    </p>
                    <button type="button" data-open-assistant hidden
                            class="mt-4 inline-flex items-center gap-2 min-h-[44px] rounded-lg border-2 border-white/70 px-4 font-semibold text-white hover:bg-white/10 transition-colors">
                        <x-icon name="robot" class="w-5 h-5" />{{ __('Tanya Asisten RuteStrip') }}
                    </button>
                </div>
            </div>

            <figure class="lg:col-span-6 lg:order-1 min-w-0">
                <div class="rounded-xl bg-[#0b4a3c] border border-white/15 p-4 sm:p-6 space-y-3 lg:-rotate-1">
                    <div class="flex items-center gap-2 pb-3 border-b border-white/15">
                        <img src="/images/logo.svg" alt="" width="28" height="28" class="w-7 h-7 rounded-lg">
                        <span class="text-sm font-semibold">RuteStrip AI Chat</span>
                    </div>
                    <p class="ml-auto max-w-[88%] w-fit rounded-xl rounded-br-sm bg-[#047857] px-4 py-2.5 text-sm">
                        {{ __('Mau naik Merbabu via Selo 2 hari 1 malam, cuacanya gimana dan perlu porter?') }}
                    </p>
                    <div class="max-w-[94%] rounded-xl rounded-bl-sm bg-white/10 px-4 py-3 text-sm text-[#ecfdf5] space-y-2">
                        <p>{{ __('Cuaca basecamp sekarang berawan, angin ringan; sore hari ada peluang hujan. Bawa jas hujan.') }}</p>
                        <p class="font-semibold">{{ __('Itinerary singkat') }}</p>
                        <ul class="list-disc pl-5 space-y-0.5">
                            <li>{{ __('Hari 1: basecamp sampai pos camp, sekitar 5 jam') }}</li>
                            <li>{{ __('Hari 2: summit pagi, turun ke basecamp') }}</li>
                        </ul>
                        <p>{{ __('Porter opsional; berguna kalau rombongan membawa tenda dan logistik berat.') }}</p>
                    </div>
                </div>
                <figcaption class="mt-3 text-sm text-[#c7d9d0]">{{ __('Contoh percakapan. Jawaban sebenarnya memakai cuaca saat kamu bertanya.') }}</figcaption>
            </figure>
        </div>
    </section>

    {{-- ================= UPLOAD GPX ================= --}}
    <section class="relative overflow-hidden" aria-labelledby="upload-title">
        <svg class="absolute inset-0 w-full h-full pointer-events-none text-[#047857]" viewBox="0 0 1440 700" preserveAspectRatio="xMaxYMid slice" data-reveal="view" aria-hidden="true" focusable="false">
            <g fill="none" stroke="currentColor" stroke-linejoin="round">
                @foreach($uploadRings as $ring)
                <path class="rs-draw" pathLength="100" style="--i: {{ $loop->index }}" d="{{ $ring['d'] }}" stroke-width="{{ $ring['index'] ? 2 : 1.2 }}" stroke-opacity="{{ $ring['index'] ? .3 : .16 }}"/>
                @endforeach
            </g>
        </svg>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-16 pb-20 sm:pt-24 sm:pb-28">
            <div class="max-w-2xl">
                <h2 id="upload-title" class="rs-display uppercase text-[clamp(2.4rem,8vw,4.5rem)] leading-[0.95] text-[#053b30] [text-wrap:balance]">{{ __('Punya file GPX dari pendakianmu?') }}</h2>
                <p class="mt-4 text-[#14231d] text-lg leading-relaxed">
                    {{ __('Upload filenya. RuteStrip menghitung jarak, total tanjakan, kemiringan, estimasi waktu, dan tingkat kesulitannya, lalu jalurmu ikut muncul di hasil pencarian.') }}
                </p>
                <p class="mt-2 text-sm text-[#4b5d55]">{{ __('Perlu masuk dengan akun RuteStrip. Setiap versi file disimpan dengan hash SHA-256.') }}</p>
                <div class="mt-7 flex flex-col sm:flex-row gap-3">
                    <a href="{{ route('routes.create') }}" class="rs-sign inline-flex items-center justify-center min-h-[52px] rounded-lg px-6 text-base font-bold transition-colors">
                        {{ __('Upload file GPX') }}
                    </a>
                    <a href="{{ route('routes.batch') }}" class="inline-flex items-center justify-center min-h-[52px] rounded-lg border-2 border-[#047857] bg-[#f6f4ec] px-6 font-semibold text-[#047857] hover:bg-white transition-colors">
                        {{ __('Upload banyak file sekaligus') }}
                    </a>
                </div>
            </div>
        </div>
    </section>
</div>

@push('scripts')
<script>
(function () {
    var root = document.querySelector('.rs-landing');
    if (!root) return;

    // Search forms: show a loading state while SBERT ranks the routes, and block double submits.
    // The submitter is not disabled, so an example button still sends its own value.
    var forms = root.querySelectorAll('[data-search-form]');
    var setBusy = function (form, busy) {
        form.setAttribute('aria-busy', busy ? 'true' : 'false');
        form.querySelectorAll('[data-search-submit]').forEach(function (btn) {
            btn.querySelector('[data-idle]').hidden = busy;
            btn.querySelector('[data-busy]').hidden = !busy;
        });
        var status = form.querySelector('[data-search-status]');
        if (status) status.textContent = busy ? status.dataset.text : '';
    };
    forms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.busy) { e.preventDefault(); return; }
            form.dataset.busy = '1';
            setBusy(form, true);
        });
    });
    // Back/forward cache: restore the idle state.
    window.addEventListener('pageshow', function () {
        forms.forEach(function (form) { delete form.dataset.busy; setBusy(form, false); });
    });

    // "Tanya Asisten RuteStrip" opens the floating assistant (same button, same focus handling).
    var fab = document.querySelector('.rs-fab-right');
    root.querySelectorAll('[data-open-assistant]').forEach(function (btn) {
        if (!fab) return;
        btn.hidden = false;
        btn.addEventListener('click', function () { fab.click(); });
    });

    if (!document.documentElement.classList.contains('rs-motion')) return;

    // Hero lines draw in on arrival; everything else when it scrolls into view (once).
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            root.querySelectorAll('[data-reveal="load"]').forEach(function (el) { el.classList.add('is-in'); });
        });
    });
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) { entry.target.classList.add('is-in'); io.unobserve(entry.target); }
        });
    }, { rootMargin: '0px 0px -12% 0px', threshold: 0.12 });
    root.querySelectorAll('[data-reveal="view"]').forEach(function (el) { io.observe(el); });

    // Parallax on the hero contour layer (tablet and up, transform only, paused once the hero is gone).
    var hero = document.getElementById('cari');
    var layer = hero && hero.querySelector('.rs-hero-topo');
    var wide = window.matchMedia('(min-width: 768px)');
    if (!layer) return;
    var heroH = hero.offsetHeight, ticking = false;
    var update = function () {
        ticking = false;
        var y = window.scrollY;
        if (!wide.matches) { layer.style.transform = ''; return; }
        if (y > heroH) return;
        layer.style.transform = 'translate3d(0,' + (y * 0.18).toFixed(1) + 'px,0)';
    };
    window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
    window.addEventListener('resize', function () { heroH = hero.offsetHeight; update(); }, { passive: true });
})();
</script>
@endpush
@endsection
