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
    $examples = [
        __('Jalur landai untuk pemula'),
        __('Pendakian singkat 2-3 jam'),
        __('Rute menantang dengan elevasi tinggi'),
        __('Trek panjang untuk pendaki berpengalaman'),
    ];
    $difficultyClass = fn ($level) => match ($level) {
        'Mudah' => 'bg-green-100 text-green-800',
        'Sedang' => 'bg-yellow-100 text-yellow-800',
        'Sulit' => 'bg-orange-100 text-orange-800',
        default => 'bg-red-100 text-red-800',
    };
    // Track preview as an SVG path (equirectangular projection into a 320x180 box).
    $trackPath = function (array $track, int $w = 320, int $h = 180, int $pad = 18) {
        if (count($track) < 2) return '';
        $lats = array_column($track, 0); $lngs = array_column($track, 1);
        $minLat = min($lats); $maxLat = max($lats); $minLng = min($lngs); $maxLng = max($lngs);
        $kx = cos(deg2rad(($minLat + $maxLat) / 2));
        $spanX = max(($maxLng - $minLng) * $kx, 1e-6); $spanY = max($maxLat - $minLat, 1e-6);
        $scale = min(($w - 2 * $pad) / $spanX, ($h - 2 * $pad) / $spanY);
        $ox = ($w - $spanX * $scale) / 2; $oy = ($h - $spanY * $scale) / 2;
        return collect($track)->map(fn ($p, $i) => ($i ? 'L' : 'M')
            . round($ox + ($p[1] - $minLng) * $kx * $scale, 1) . ' '
            . round($oy + ($maxLat - $p[0]) * $scale, 1))->implode(' ');
    };
    $hero = $featured[0] ?? null;
@endphp

@section('content')
<div class="-mt-4 overflow-x-clip">

    {{-- ================= HERO + SEARCH ================= --}}
    <section id="cari" class="px-3 sm:px-6 lg:px-8 scroll-mt-24">
        <div class="max-w-7xl mx-auto relative overflow-hidden rounded-3xl bg-[#053b30] text-white shadow-2xl">
            {{-- Topographic contour lines (inline SVG, no image request) --}}
            <svg class="absolute inset-0 w-full h-full opacity-[0.13] pointer-events-none" viewBox="0 0 800 500" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
                <g fill="none" stroke="#a7f3d0" stroke-width="1.2">
                    <path d="M-20 420 C120 360 220 400 330 340 S560 260 820 300"/>
                    <path d="M-20 380 C110 320 230 360 340 300 S560 210 820 250"/>
                    <path d="M-20 340 C100 280 240 320 350 260 S560 160 820 200"/>
                    <path d="M-20 300 C90 240 250 280 360 220 S570 110 820 150"/>
                    <path d="M-20 260 C80 200 260 240 370 180 S580 60 820 100"/>
                    <path d="M420 120 C470 90 540 95 560 130 S520 190 470 180 S390 150 420 120Z"/>
                    <path d="M445 128 C475 110 520 112 532 135 S505 168 475 162 S430 145 445 128Z"/>
                </g>
            </svg>
            <div class="absolute -top-24 -right-24 w-80 h-80 rounded-full bg-emerald-400/20 blur-3xl pointer-events-none" aria-hidden="true"></div>

            <div class="relative grid lg:grid-cols-12 gap-10 px-5 py-10 sm:px-10 sm:py-14 lg:px-14 lg:py-16">
                <div class="lg:col-span-7 min-w-0">
                    <a href="{{ $paperUrl }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 rounded-full border border-emerald-300/30 bg-white/10 px-3 py-1.5 text-xs font-medium text-emerald-100 hover:bg-white/15 max-w-full">
                        <x-icon name="academic-cap" class="w-4 h-4 shrink-0 text-emerald-300" />
                        <span class="truncate">{{ __('Riset terbit di Jurnal Sistemasi (SINTA 3)') }}</span>
                    </a>

                    <h1 class="mt-5 text-[2rem] leading-[1.1] sm:text-5xl lg:text-[3.4rem] font-extrabold tracking-tight">
                        {{ __('Cari jalur pendakian') }}
                        <span class="block text-emerald-300">{{ __('dengan kalimat biasa.') }}</span>
                    </h1>
                    <p class="mt-4 text-base sm:text-lg text-emerald-50/85 max-w-xl leading-relaxed">
                        {{ __('Tulis yang kamu cari, misalnya "jalur landai untuk pemula". RuteStrip mencocokkannya dengan data GPX setiap jalur di Pulau Jawa: jarak, total tanjakan, kemiringan, dan estimasi waktu.') }}
                    </p>

                    <!-- Search Form -->
                    <form action="{{ route('search.submit') }}" method="POST" class="mt-7" role="search">
                        @csrf
                        <label for="hero-query" class="sr-only">{{ __('Cari jalur pendakian') }}</label>
                        <div class="flex flex-col sm:flex-row gap-3 rounded-2xl bg-white p-2 shadow-xl ring-1 ring-black/5">
                            <div class="relative flex-1 min-w-0">
                                <x-icon name="search" class="w-5 h-5 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                                <input id="hero-query" type="text" name="query" value="{{ old('query') }}" required minlength="3" maxlength="500"
                                       autocomplete="off" enterkeyhint="search"
                                       placeholder="{{ __('Contoh: jalur landai untuk pemula dengan pemandangan indah') }}"
                                       class="w-full pl-11 pr-3 py-3.5 rounded-xl border-0 text-slate-800 placeholder-slate-400 text-base focus:ring-2 focus:ring-emerald-400 focus:outline-none">
                            </div>
                            <button type="submit"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 px-6 py-3.5 font-semibold text-white transition-colors">
                                <x-icon name="search" class="w-5 h-5" />
                                <span>{{ __('Cari Rute') }}</span>
                            </button>
                        </div>

                        <!-- Example queries: submit buttons of the same form (work without JS) -->
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <span class="text-xs font-medium text-emerald-100/80 mr-1">{{ __('Coba:') }}</span>
                            @foreach($examples as $example)
                            <button type="submit" name="query" value="{{ $example }}" formnovalidate
                                    class="rounded-full border border-white/20 bg-white/10 px-3 py-1.5 text-xs sm:text-sm text-white hover:bg-white/20 hover:border-white/40 transition-colors text-left">
                                {{ $example }}
                            </button>
                            @endforeach
                        </div>
                    </form>
                </div>

                <!-- Product preview (desktop): one real route from the database -->
                @if($hero)
                <div class="hidden lg:block lg:col-span-5 self-center" aria-hidden="true">
                    <div class="relative rounded-2xl bg-white text-slate-800 shadow-2xl ring-1 ring-black/5 overflow-hidden rotate-[1.5deg]">
                        <div class="flex items-center justify-between px-5 pt-5">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                                <x-icon name="sparkles" class="w-3.5 h-3.5" /> {{ __('Contoh hasil') }}
                            </span>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $difficultyClass($hero['difficulty']) }}">{{ __($hero['difficulty']) }}</span>
                        </div>
                        <p class="px-5 mt-3 text-lg font-bold leading-snug">{{ $hero['name'] }}</p>
                        <svg viewBox="0 0 320 180" class="w-full h-44 mt-2 bg-gradient-to-b from-emerald-50 to-white">
                            <path d="{{ $trackPath($hero['track']) }}" fill="none" stroke="#059669" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>
                        </svg>
                        <dl class="grid grid-cols-3 divide-x divide-slate-100 border-t border-slate-100 text-center">
                            <div class="py-3"><dt class="text-[11px] text-slate-500">{{ __('Jarak') }}</dt><dd class="font-bold">{{ $num($hero['distance'], 1) }} km</dd></div>
                            <div class="py-3"><dt class="text-[11px] text-slate-500">{{ __('Puncak') }}</dt><dd class="font-bold">{{ $hero['summit'] ? $num($hero['summit']) . ' m' : '–' }}</dd></div>
                            <div class="py-3"><dt class="text-[11px] text-slate-500">{{ __('Estimasi') }}</dt><dd class="font-bold">{{ $hoursLabel($hero['hours']) }}</dd></div>
                        </dl>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </section>

    {{-- ================= STATS ================= --}}
    <section class="px-4 sm:px-6 lg:px-8 mt-6" aria-label="{{ __('Statistik RuteStrip') }}">
        <dl class="max-w-7xl mx-auto grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            @php
                $statTiles = [
                    ['value' => $num($stats['routes']), 'label' => __('jalur dengan data GPX'), 'icon' => 'map'],
                    ['value' => $num($stats['mountains']), 'label' => __('gunung'), 'icon' => 'mountain'],
                    $stats['provinces'] > 0
                        ? ['value' => $num($stats['provinces']), 'label' => __('provinsi di Pulau Jawa'), 'icon' => 'location-marker']
                        : ['value' => __('Jawa'), 'label' => __('cakupan wilayah'), 'icon' => 'location-marker'],
                    ['value' => $num($stats['km']) . ' km', 'label' => __('total panjang jalur'), 'icon' => 'trending-up'],
                ];
            @endphp
            @foreach($statTiles as $tile)
            <div class="rounded-2xl bg-white border border-slate-100 shadow-sm px-4 py-4 sm:px-5 sm:py-5 flex items-center gap-3 min-w-0">
                <span class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0"><x-icon :name="$tile['icon']" class="w-5 h-5" /></span>
                <div class="min-w-0">
                    <dd class="text-xl sm:text-2xl font-extrabold text-slate-900 leading-tight truncate">{{ $tile['value'] }}</dd>
                    <dt class="text-xs sm:text-sm text-slate-500 leading-snug">{{ $tile['label'] }}</dt>
                </div>
            </div>
            @endforeach
        </dl>
    </section>

    {{-- ================= FEATURED ROUTES ================= --}}
    @if(count($featured))
    <section class="px-4 sm:px-6 lg:px-8 mt-16 sm:mt-20">
        <div class="max-w-7xl mx-auto">
            <div class="flex flex-wrap items-end justify-between gap-3 mb-6">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">{{ __('Jalur pilihan') }}</p>
                    <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 mt-1">{{ __('Mulai dari jalur yang paling disukai') }}</h2>
                </div>
                <a href="{{ route('routes.index') }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-emerald-700 hover:text-emerald-800">
                    {{ __('Lihat semua :count jalur', ['count' => $num($stats['routes'])]) }} <x-icon name="arrow-right" class="w-4 h-4" />
                </a>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($featured as $item)
                <a href="{{ $item['url'] }}" class="group rounded-2xl bg-white border border-slate-100 shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition-all overflow-hidden flex flex-col">
                    <div class="relative h-40 bg-gradient-to-br from-emerald-50 to-teal-50">
                        <svg viewBox="0 0 320 180" preserveAspectRatio="xMidYMid meet" class="absolute inset-0 w-full h-full" aria-hidden="true">
                            <path d="{{ $trackPath($item['track']) }}" fill="none" stroke="#059669" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>
                        </svg>
                        <div class="lazy-map absolute inset-0 z-0 pointer-events-none" data-track='@json($item['track'])'></div>
                        <span class="absolute top-3 left-3 z-[401] rounded-full px-2.5 py-1 text-xs font-semibold shadow-sm {{ $difficultyClass($item['difficulty']) }}">{{ __($item['difficulty']) }}</span>
                        @if($item['likes'] > 0)
                        <span class="absolute top-3 right-3 z-[401] inline-flex items-center gap-1 rounded-full bg-white/95 px-2.5 py-1 text-xs font-semibold text-rose-600 shadow-sm">
                            <x-icon name="heart" class="w-3.5 h-3.5" /> {{ $num($item['likes']) }}
                        </span>
                        @endif
                    </div>
                    <div class="p-5 flex-1 flex flex-col">
                        <h3 class="font-bold text-slate-900 group-hover:text-emerald-700 transition-colors leading-snug">{{ $item['name'] }}</h3>
                        @if($item['basecamp'])
                        <p class="mt-1 text-sm text-slate-500 inline-flex items-center gap-1.5"><x-icon name="home" class="w-4 h-4 shrink-0" />{{ $item['basecamp'] }}</p>
                        @endif
                        <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
                            <div class="rounded-xl bg-slate-50 py-2"><dt class="text-[11px] text-slate-500">{{ __('Jarak') }}</dt><dd class="text-sm font-bold text-slate-800">{{ $num($item['distance'], 1) }} km</dd></div>
                            <div class="rounded-xl bg-slate-50 py-2"><dt class="text-[11px] text-slate-500">{{ __('Puncak') }}</dt><dd class="text-sm font-bold text-slate-800">{{ $item['summit'] ? $num($item['summit']) . ' m' : '–' }}</dd></div>
                            <div class="rounded-xl bg-slate-50 py-2"><dt class="text-[11px] text-slate-500">{{ __('Estimasi') }}</dt><dd class="text-sm font-bold text-slate-800">{{ $hoursLabel($item['hours']) }}</dd></div>
                        </dl>
                    </div>
                </a>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- ================= AI CHAT ================= --}}
    <section class="px-4 sm:px-6 lg:px-8 mt-16 sm:mt-20" aria-labelledby="ai-chat-title">
        <div class="max-w-7xl mx-auto rounded-3xl bg-slate-900 text-white overflow-hidden relative">
            <div class="absolute -bottom-32 -left-24 w-96 h-96 rounded-full bg-emerald-500/20 blur-3xl pointer-events-none" aria-hidden="true"></div>
            <div class="relative grid lg:grid-cols-2 gap-10 p-6 sm:p-10 lg:p-14">
                <div class="min-w-0">
                    <span class="inline-flex items-center gap-2 rounded-full bg-emerald-500/15 px-3 py-1 text-xs font-semibold text-emerald-300">
                        <x-icon name="sparkles" class="w-4 h-4" /> chat.rutestrip.ai.id
                    </span>
                    <h2 id="ai-chat-title" class="mt-4 text-2xl sm:text-4xl font-extrabold tracking-tight">{{ __('RuteStrip AI Chat') }}</h2>
                    <p class="mt-3 text-slate-300 leading-relaxed max-w-xl">
                        {{ __('Asisten pendakian yang menjawab dari data jalur RuteStrip. Tanya soal cuaca di basecamp hari ini, minta itinerary 2 hari, atau cari jalur yang cocok untuk rombonganmu.') }}
                    </p>

                    <ul class="mt-6 grid sm:grid-cols-2 gap-3">
                        @foreach([
                            ['icon' => 'cloud', 'title' => __('Cuaca live'), 'text' => __('Suhu, angin, dan hujan di basecamp dan puncak')],
                            ['icon' => 'clipboard-list', 'title' => __('Itinerary'), 'text' => __('Rencana per hari lengkap dengan estimasi waktu')],
                            ['icon' => 'users', 'title' => __('Info porter'), 'text' => __('Kapan perlu porter dan apa yang perlu disiapkan')],
                            ['icon' => 'globe', 'title' => __('Peta satelit'), 'text' => __('Lihat jalur di atas citra satelit')],
                            ['icon' => 'map', 'title' => __('Rekomendasi jalur'), 'text' => __('Jalur yang cocok dengan kemampuanmu')],
                        ] as $feature)
                        <li class="flex items-start gap-3 rounded-xl bg-white/5 border border-white/10 p-3 {{ $loop->last ? 'sm:col-span-2' : '' }}">
                            <span class="w-9 h-9 rounded-lg bg-emerald-500/15 text-emerald-300 flex items-center justify-center shrink-0"><x-icon :name="$feature['icon']" class="w-5 h-5" /></span>
                            <span class="min-w-0">
                                <span class="block font-semibold text-sm">{{ $feature['title'] }}</span>
                                <span class="block text-xs text-slate-400 mt-0.5">{{ $feature['text'] }}</span>
                            </span>
                        </li>
                        @endforeach
                    </ul>

                    <div class="mt-7 flex flex-col sm:flex-row gap-3 sm:items-center">
                        <a href="{{ $webchatUrl }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 px-6 py-3.5 font-semibold text-slate-900 transition-colors">
                            <x-icon name="chat" class="w-5 h-5" /> {{ __('Buka AI Chat') }}
                        </a>
                        <p class="text-xs text-slate-400">{{ __('Masuk dengan akun RuteStrip yang sama.') }}</p>
                    </div>
                </div>

                <!-- Illustrative conversation -->
                <figure class="min-w-0 self-center" aria-label="{{ __('Contoh percakapan') }}">
                    <div class="rounded-2xl bg-slate-800/80 border border-white/10 p-4 sm:p-5 space-y-3 shadow-2xl">
                        <div class="flex items-center gap-2 pb-3 border-b border-white/10">
                            <img src="/images/logo.svg" alt="" width="28" height="28" class="w-7 h-7 rounded-lg">
                            <span class="text-sm font-semibold">RuteStrip AI</span>
                            <span class="ml-auto text-[11px] text-slate-400">{{ __('Contoh percakapan') }}</span>
                        </div>
                        <p class="ml-auto max-w-[85%] w-fit rounded-2xl rounded-br-md bg-emerald-600 px-4 py-2.5 text-sm">
                            {{ __('Mau naik Merbabu via Selo 2 hari 1 malam, cuacanya gimana dan perlu porter?') }}
                        </p>
                        <div class="max-w-[92%] rounded-2xl rounded-bl-md bg-white/10 px-4 py-3 text-sm text-slate-100 space-y-2">
                            <p>{{ __('Cuaca basecamp sekarang berawan, angin ringan; sore hari ada peluang hujan. Bawa jas hujan.') }}</p>
                            <p class="font-semibold text-emerald-300">{{ __('Itinerary singkat') }}</p>
                            <ul class="list-disc pl-5 space-y-0.5 text-slate-200">
                                <li>{{ __('Hari 1: basecamp sampai pos camp, sekitar 5 jam') }}</li>
                                <li>{{ __('Hari 2: summit pagi, turun ke basecamp') }}</li>
                            </ul>
                            <p class="text-slate-300">{{ __('Porter opsional; berguna kalau rombongan membawa tenda dan logistik berat.') }}</p>
                        </div>
                    </div>
                </figure>
            </div>
        </div>
    </section>

    {{-- ================= HOW IT WORKS ================= --}}
    <section class="px-4 sm:px-6 lg:px-8 mt-16 sm:mt-20" aria-labelledby="how-title">
        <div class="max-w-7xl mx-auto">
            <div class="max-w-2xl">
                <p class="text-sm font-semibold text-emerald-700">{{ __('Cara kerja') }}</p>
                <h2 id="how-title" class="text-2xl sm:text-3xl font-bold text-slate-900 mt-1">{{ __('Dari kalimatmu ke daftar jalur yang cocok') }}</h2>
            </div>
            <ol class="mt-8 grid md:grid-cols-3 gap-5">
                @foreach([
                    ['tag' => 'SBERT', 'title' => __('Tulis kebutuhanmu'), 'text' => __('Kalimat bebas seperti "tektok 3 jam" diubah menjadi vektor makna oleh model SBERT multibahasa, jadi sinonim dan gaya bahasa sehari-hari tetap dipahami.')],
                    ['tag' => 'Content-based filtering', 'title' => __('Dicocokkan dengan data GPX'), 'text' => __('Setiap jalur punya deskripsi yang dibangun dari file GPX-nya: jarak, total tanjakan, dan kemiringan. Jalur diurutkan dengan cosine similarity terhadap kalimatmu.')],
                    ['tag' => 'Naismith', 'title' => __('Bandingkan, lalu berangkat'), 'text' => __('Estimasi waktu memakai aturan Naismith: 1 jam per 5 km ditambah 1 jam per 600 m tanjakan. Lengkap dengan peta, profil elevasi, dan cuaca terkini.')],
                ] as $step)
                <li class="relative rounded-2xl bg-white border border-slate-100 shadow-sm p-6">
                    <div class="flex items-center justify-between gap-3">
                        <span class="w-10 h-10 rounded-full bg-emerald-600 text-white font-bold flex items-center justify-center">{{ $loop->iteration }}</span>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600">{{ $step['tag'] }}</span>
                    </div>
                    <h3 class="mt-4 text-lg font-bold text-slate-900">{{ $step['title'] }}</h3>
                    <p class="mt-2 text-sm text-slate-600 leading-relaxed">{{ $step['text'] }}</p>
                </li>
                @endforeach
            </ol>

            <div class="mt-5 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach([
                    ['icon' => 'chart-bar', 'title' => __('Profil elevasi'), 'text' => __('Naik-turun jalur dari basecamp ke puncak')],
                    ['icon' => 'cloud', 'title' => __('Kondisi terkini'), 'text' => __('Cuaca basecamp dan puncak dari Open-Meteo')],
                    ['icon' => 'heart-outline', 'title' => __('Suka & ulasan'), 'text' => __('Tandai jalur favorit, tanpa perlu daftar')],
                    ['icon' => 'upload', 'title' => __('Upload GPX'), 'text' => __('Tambah jalurmu sendiri, dianalisis otomatis')],
                ] as $feature)
                <div class="rounded-2xl border border-slate-100 bg-white/70 p-4 flex items-start gap-3">
                    <span class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0"><x-icon :name="$feature['icon']" class="w-5 h-5" /></span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-slate-800">{{ $feature['title'] }}</span>
                        <span class="block text-xs text-slate-500 mt-0.5">{{ $feature['text'] }}</span>
                    </span>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ================= RESEARCH ================= --}}
    <section class="px-4 sm:px-6 lg:px-8 mt-16 sm:mt-20" aria-labelledby="research-title">
        <div class="max-w-7xl mx-auto rounded-3xl border border-emerald-100 bg-white shadow-sm p-6 sm:p-10 grid lg:grid-cols-12 gap-8 items-center">
            <div class="lg:col-span-8 min-w-0">
                <p class="inline-flex items-center gap-2 text-sm font-semibold text-emerald-700"><x-icon name="academic-cap" class="w-5 h-5" />{{ __('Berbasis penelitian') }}</p>
                <h2 id="research-title" class="text-2xl sm:text-3xl font-bold text-slate-900 mt-2">{{ __('Metodenya diuji dan dipublikasikan') }}</h2>
                <p class="mt-3 text-slate-600 leading-relaxed">
                    {{ __('RuteStrip dikembangkan dari artikel penelitian yang terbit di SISTEMASI: Jurnal Sistem Informasi (SINTA 3), Vol. 15 No. 4, 2026.') }}
                </p>
                <blockquote class="mt-4 border-l-4 border-emerald-500 pl-4 text-slate-800">
                    <p class="font-semibold leading-snug" lang="en">Mountain Hiking Route Recommendation System using Content-based Filtering with SBERT and Cosine Similarity</p>
                    <footer class="mt-1 text-sm text-slate-500">Rama Danadipa Putra Wijaya, Arif Nur Rahman</footer>
                </blockquote>
                <div class="mt-5 flex flex-wrap gap-3">
                    <a href="{{ $paperUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white">
                        <x-icon name="document-text" class="w-4 h-4" />{{ __('Baca artikel') }}
                    </a>
                    <a href="https://doi.org/{{ $paperDoi }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 hover:bg-slate-50 px-5 py-2.5 text-sm font-semibold text-slate-700">
                        DOI {{ $paperDoi }}
                    </a>
                    <a href="{{ route('architecture') }}" class="inline-flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-semibold text-emerald-700 hover:text-emerald-800">
                        {{ __('Arsitektur sistem') }} <x-icon name="arrow-right" class="w-4 h-4" />
                    </a>
                </div>
            </div>
            <div class="lg:col-span-4">
                <div class="rounded-2xl bg-emerald-50 border border-emerald-100 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">{{ __('Hasil evaluasi di artikel') }}</p>
                    <div class="mt-3 flex items-end gap-4">
                        <div>
                            <p class="text-3xl font-extrabold text-emerald-700">{{ $num(0.60, 2) }}</p>
                            <p class="text-xs text-slate-600">P@5 SBERT</p>
                        </div>
                        <div>
                            <p class="text-3xl font-extrabold text-slate-400">{{ $num(0.25, 2) }}</p>
                            <p class="text-xs text-slate-600">P@5 TF-IDF</p>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-slate-500 leading-relaxed">{{ __('Diukur pada 39 jalur saat penelitian. Database situs sekarang berisi :count jalur.', ['count' => $num($stats['routes'])]) }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ================= CTA ================= --}}
    <section class="px-4 sm:px-6 lg:px-8 mt-16 sm:mt-20">
        <div class="max-w-7xl mx-auto rounded-3xl bg-mountain-gradient text-white p-6 sm:p-10 flex flex-col lg:flex-row lg:items-center gap-6 justify-between">
            <div class="max-w-2xl">
                <h2 class="text-2xl sm:text-3xl font-bold">{{ __('Punya file GPX dari pendakianmu?') }}</h2>
                <p class="mt-2 text-emerald-50/90">{{ __('Upload dan RuteStrip menghitung jarak, tanjakan, kemiringan, dan estimasi waktunya. Jalurmu ikut muncul di pencarian.') }}</p>
            </div>
            <div class="flex flex-col sm:flex-row gap-3 shrink-0">
                <a href="{{ route('routes.create') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-white text-emerald-800 hover:bg-emerald-50 px-6 py-3 font-semibold">
                    <x-icon name="upload" class="w-5 h-5" /> {{ __('Upload GPX') }}
                </a>
                <a href="#cari" class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/40 hover:bg-white/10 px-6 py-3 font-semibold">
                    <x-icon name="search" class="w-5 h-5" /> {{ __('Mulai mencari') }}
                </a>
            </div>
        </div>
    </section>
</div>

@push('scripts')
<script>
// Map thumbnails are created only when a card scrolls into view (the SVG track shows until then).
document.addEventListener('DOMContentLoaded', function () {
    var maps = document.querySelectorAll('.lazy-map');
    if (!maps.length || typeof L === 'undefined') return;
    var init = function (el) {
        var track = JSON.parse(el.dataset.track || '[]');
        if (track.length < 2) return;
        var map = L.map(el, { zoomControl: false, attributionControl: false, dragging: false, scrollWheelZoom: false,
                              doubleClickZoom: false, touchZoom: false, boxZoom: false, keyboard: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 17 }).addTo(map);
        var line = L.polyline(track, { color: '#059669', weight: 4, opacity: 0.95 }).addTo(map);
        map.fitBounds(line.getBounds(), { padding: [14, 14] });
        el.previousElementSibling && el.previousElementSibling.remove();
    };
    if (!('IntersectionObserver' in window)) { maps.forEach(init); return; }
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) { io.unobserve(entry.target); init(entry.target); }
        });
    }, { rootMargin: '200px' });
    maps.forEach(function (el) { io.observe(el); });
});
</script>
@endpush
@endsection
