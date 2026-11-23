<!DOCTYPE html>
@php
    $appLocale = app()->getLocale();
    $isEn = $appLocale === 'en';
@endphp
<html lang="{{ $appLocale }}" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'RuteStrip') - {{ __('Sistem Rekomendasi Rute Pendakian') }}</title>
    @php
        $seoTitle = trim($__env->yieldContent('title', 'RuteStrip')) . ' - RuteStrip';
        $seoDescription = trim($__env->yieldContent('meta_description'))
            ?: __('RuteStrip: cari jalur pendakian gunung di Pulau Jawa dengan bahasa sehari-hari, lalu bandingkan jarak, total tanjakan, estimasi waktu, dan peta jalurnya dari file GPX asli.');
        // Same URL for both languages; the English version is ?lang=en (hreflang below).
        $seoBaseUrl = trim($__env->yieldContent('canonical')) ?: url()->current();
        $seoWithLang = fn (string $url, string $lang) => $lang === 'id'
            ? $url
            : $url . (str_contains($url, '?') ? '&' : '?') . 'lang=' . $lang;
        $seoCanonical = $isEn && request()->query('lang') === 'en' ? $seoWithLang($seoBaseUrl, 'en') : $seoBaseUrl;
        // Branded 1200x630 card (python/tools/build_og_image.py). A page may
        // override it with @section('og_image') plus og_image_width/height.
        $seoImage = trim($__env->yieldContent('og_image')) ?: asset('images/og/rutestrip-og.png');
        $seoImageWidth = trim($__env->yieldContent('og_image_width', '1200'));
        $seoImageHeight = trim($__env->yieldContent('og_image_height', '630'));
        $seoImageAlt = trim($__env->yieldContent('og_image_alt', __('RuteStrip - cari jalur pendakian gunung di Pulau Jawa')));
        // Account/admin/upload pages and POSTed search results stay out of search engines.
        $seoPrivate = request()->isMethod('post') || request()->is(
            'admin*', 'dashboard*', 'profile*', 'favorites*', 'history*', 'login', 'register',
            'routes/create', 'routes-batch', 'evaluation*', 'routes/*/versions/*', 'user/*', 'settings*', 'akun*'
        );
        $seoRobots = trim($__env->yieldContent('robots')) ?: ($seoPrivate ? 'noindex, nofollow' : 'index, follow, max-image-preview:large');
    @endphp
    <meta name="description" content="{{ \Illuminate\Support\Str::limit($seoDescription, 300, '') }}">
    <meta name="robots" content="{{ $seoRobots }}">
    <link rel="canonical" href="{{ $seoCanonical }}">
    @unless($seoPrivate)
    <link rel="alternate" hreflang="id" href="{{ $seoBaseUrl }}">
    <link rel="alternate" hreflang="en" href="{{ $seoWithLang($seoBaseUrl, 'en') }}">
    <link rel="alternate" hreflang="x-default" href="{{ $seoBaseUrl }}">
    @endunless
    <meta property="og:site_name" content="RuteStrip">
    <meta property="og:locale" content="{{ $isEn ? 'en_US' : 'id_ID' }}">
    <meta property="og:locale:alternate" content="{{ $isEn ? 'id_ID' : 'en_US' }}">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:title" content="{{ $seoTitle }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit($seoDescription, 300, '') }}">
    <meta property="og:url" content="{{ $seoCanonical }}">
    <meta property="og:image" content="{{ $seoImage }}">
    @if(str_starts_with($seoImage, 'https://'))
    <meta property="og:image:secure_url" content="{{ $seoImage }}">
    @endif
    @if(str_ends_with(strtolower(parse_url($seoImage, PHP_URL_PATH) ?? ''), '.png'))
    <meta property="og:image:type" content="image/png">
    @endif
    <meta property="og:image:width" content="{{ $seoImageWidth }}">
    <meta property="og:image:height" content="{{ $seoImageHeight }}">
    <meta property="og:image:alt" content="{{ $seoImageAlt }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seoTitle }}">
    <meta name="twitter:description" content="{{ \Illuminate\Support\Str::limit($seoDescription, 200, '') }}">
    <meta name="twitter:image" content="{{ $seoImage }}">
    <meta name="twitter:image:alt" content="{{ $seoImageAlt }}">
    @if(config('services.seo.google_verification'))
    <meta name="google-site-verification" content="{{ config('services.seo.google_verification') }}">
    @endif
    @if(config('services.seo.bing_verification'))
    <meta name="msvalidate.01" content="{{ config('services.seo.bing_verification') }}">
    @endif
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'RuteStrip',
        'url' => url('/'),
        'inLanguage' => ['id', 'en'],
        'description' => __('Sistem rekomendasi jalur pendakian gunung di Pulau Jawa berbasis data GPX dan AI.'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @stack('head')

    <!-- Icons & PWA (python/tools/build_icons.py) -->
    <link rel="icon" type="image/svg+xml" href="/images/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/icons/favicon-32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/icons/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#053b30">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="RuteStrip">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <script>
        // Web app install prompt (Chrome/Edge): keep the event so "Pasang versi web (PWA)" on the
        // settings page can offer it. The bottom banner (#rs-install) points to the Android app instead.
        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            window.__rsInstallEvent = e;
            window.dispatchEvent(new Event('rs:installable'));
        });
    </script>

    {{-- Everything below is served from this site, no CDN: Brave Shields, ad blockers and some
         networks block cdn.tailwindcss.com, jsDelivr, unpkg and Google Fonts, which broke the layout.
         Copied from node_modules with `npm run build:vendor`; Tailwind is built with `npm run build:css`. --}}

    <!-- Alpine.js 3.17.5 (menus, panels, likes, route conditions) -->
    <script defer src="/vendor/alpine/cdn.min.js?v=3.17.5"></script>

    <!-- Leaflet 1.9.4 for maps -->
    <link rel="stylesheet" href="/vendor/leaflet/leaflet.css?v=1.9.4">
    <script src="/vendor/leaflet/leaflet.js?v=1.9.4"></script>

    <!-- Fonts (public/fonts, @font-face in css/site.css): Inter for text; Barlow Condensed (SIL OFL) for headings, drawn from road and trail signs (DESIGN.md). -->
    <link rel="preload" href="/fonts/inter/inter-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/barlow-condensed/barlow-condensed-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>

    <style>
        :root {
            --rs-ink: #14231d; --rs-muted: #4b5d55; --rs-z9: #053b30; --rs-z8: #0b4a3c; --rs-z7: #047857;
            --rs-paper: #f6f4ec; --rs-line: #dedbcd; --rs-sun: #FCD34D;
            /* Height taken by the bottom banner (cookie notice or install prompt); floating buttons sit above it. */
            --rs-banner-h: 0px; --rs-fab-lift: 0px;
        }
        [x-cloak] { display: none !important; }

        * {
            font-family: 'Inter', system-ui, sans-serif;
        }

        /* Headings: condensed and bold like a trail sign. */
        .rs-display, .rs-display * { font-family: 'Barlow Condensed', 'Roboto Condensed', 'Arial Narrow', sans-serif-condensed, 'Inter', system-ui, sans-serif; font-weight: 800; letter-spacing: -0.005em; }
        .rs-num { font-variant-numeric: tabular-nums; }

        /* Kuning fajar = the next step (search, upload, confirm). Bordered like a trail sign. */
        .rs-sign { background: var(--rs-sun); color: var(--rs-z9); border: 2px solid var(--rs-z9); }
        .rs-sign:hover { background: #fde68a; }

        /* Keyboard focus: dark ring on light surfaces, white ring on zamrud surfaces. */
        :focus-visible { outline: 3px solid var(--rs-z9); outline-offset: 2px; }
        .rs-on-dark :focus-visible { outline-color: #fff; }
        .rs-on-dark .rs-on-light :focus-visible { outline-color: var(--rs-z9); }

        /* Busy state of search buttons: both labels share one grid cell so the width never jumps. */
        .rs-swap { display: inline-grid; place-items: center; align-items: center; }
        .rs-swap > * { grid-area: 1 / 1; }
        .rs-swap > [hidden] { display: block; visibility: hidden; }

        /* Older helpers still used by some pages. */
        .glass-card { background: #fff; border: 1px solid var(--rs-line); }
        .gradient-text { color: var(--rs-z9); }
        .mountain-card { transition: border-color .2s ease; }
        .floating, .pulse-glow { animation: none; }
        .score-badge { background: var(--rs-z7); }

        /* Leaflet panes/controls use z-index 400-1000; keep them inside the map so they
           never paint over the fixed navbar (z-50) or the mobile tab bar (z-40). */
        .leaflet-container { isolation: isolate; font: inherit; }

        /* Anchors (#ulasan) and focused fields scroll into view below the fixed navbar. */
        html { scroll-padding-top: 5rem; }

        /* Notched phones in landscape (viewport-fit=cover): keep content out of the cutout. */
        .safe-x { padding-left: env(safe-area-inset-left, 0px); padding-right: env(safe-area-inset-right, 0px); }

        /* Mobile tab bar and the bottom banner: keep page content and the footer clear of both. */
        .tabbar-safe { padding-bottom: env(safe-area-inset-bottom, 0px); }
        body { padding-bottom: var(--rs-banner-h); }
        @media (max-width: 767.98px) {
            body.has-tabbar { padding-bottom: calc(4rem + env(safe-area-inset-bottom, 0px) + var(--rs-banner-h)); }
            html { scroll-padding-bottom: calc(4.5rem + env(safe-area-inset-bottom, 0px)); }
        }

        /* Bottom banner dock: above the tab bar on phones, a centred card on wider screens. */
        .rs-dock { position: fixed; z-index: 45; left: .75rem; right: .75rem; bottom: calc(4.5rem + env(safe-area-inset-bottom, 0px)); }
        @media (min-width: 768px) {
            .rs-dock { left: 50%; right: auto; bottom: 1rem; width: min(46rem, calc(100% - 2rem)); transform: translateX(-50%); }
        }
        .rs-dock > [hidden] { display: none; }
        @media (prefers-reduced-motion: no-preference) {
            .rs-dock > :not([hidden]) { animation: rs-rise .3s ease-out; }
            @keyframes rs-rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
        }
        @media print {
            body, body.has-tabbar { padding-bottom: 0; }
            .rs-dock { display: none; }
        }
    </style>
    @stack('styles')

    {{-- Tailwind v3 build of the classes in the views (tailwind.config.js -> public/css/site.css).
         Linked last, where the Play CDN used to inject its styles, so utilities still win over the page styles above. --}}
    <link rel="stylesheet" href="/css/site.css?v={{ filemtime(public_path('css/site.css')) }}">
</head>
<body class="has-tabbar safe-x min-h-screen bg-rs-paper text-rs-ink">
    <a href="#konten" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[60] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:font-semibold focus:text-rs-z9">{{ __('Langsung ke isi halaman') }}</a>

    <!-- Navigation -->
    @php
        $webchatUrl = config('services.webchat.url');
        // Android app site: features, how to use it and the APK. /install stays the direct APK link.
        $appSiteUrl = 'https://app.rutestrip.ai.id';
        $navLinks = [
            ['href' => route('search.index'), 'label' => __('Cari Rute'), 'icon' => 'search', 'active' => request()->routeIs('search.*', 'home')],
            ['href' => route('routes.index'), 'label' => __('Semua Rute'), 'icon' => 'map', 'active' => request()->routeIs('routes.index', 'routes.show', 'routes.similar', 'routes.provenance')],
            ['href' => $webchatUrl, 'label' => __('AI Chat'), 'icon' => 'chat', 'active' => false],
        ];
        $uploadActive = request()->routeIs('routes.create', 'routes.batch');
        $navUser = Auth::user();
        $navInitial = $navUser ? mb_strtoupper(mb_substr(trim($navUser->name) ?: '?', 0, 1)) : '';
        $navShortName = $navUser ? \Illuminate\Support\Str::limit(\Illuminate\Support\Str::before(trim($navUser->name), ' '), 16) : '';
        $hasSettings = Route::has('settings');
        // Account links shared by the desktop dropdown and the mobile menu.
        $accountLinks = $navUser ? array_values(array_filter([
            ['href' => route('user.dashboard'), 'label' => __('Dashboard'), 'icon' => 'home'],
            ['href' => route('user.favorites'), 'label' => __('Rute Favorit'), 'icon' => 'heart-outline'],
            ['href' => route('account.submissions'), 'label' => __('Kiriman jalurku'), 'icon' => 'clipboard-list'],
            ['href' => route('user.history'), 'label' => __('Riwayat Pencarian'), 'icon' => 'clock'],
            $hasSettings
                ? ['href' => route('settings'), 'label' => __('Pengaturan'), 'icon' => 'cog']
                : (Route::has('user.profile') ? ['href' => route('user.profile'), 'label' => __('Edit Profil'), 'icon' => 'user'] : null),
        ])) : [];
    @endphp
    <nav x-data="{ mobileOpen: false }" @keydown.escape.window="mobileOpen = false" @click.outside="mobileOpen = false"
         class="fixed top-0 left-0 right-0 z-50 safe-x bg-white border-b border-rs-line print:static">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16 gap-3">
                <!-- Logo -->
                <a href="{{ route('search.index') }}" class="flex items-center gap-2.5 min-w-0 rounded-lg">
                    <img src="/images/logo.svg" alt="" width="40" height="40" class="w-9 h-9 rounded-lg flex-shrink-0">
                    <span class="rs-display uppercase text-2xl leading-none text-rs-z9">RuteStrip</span>
                </a>

                <!-- Desktop links -->
                <div class="hidden lg:flex items-center gap-1">
                    @foreach($navLinks as $link)
                    <a href="{{ $link['href'] }}"
                       @if($link['active']) aria-current="page" @endif
                       class="relative px-3.5 h-11 rounded-lg text-[15px] font-semibold transition-colors inline-flex items-center gap-2
                              {{ $link['active'] ? 'text-rs-z9' : 'text-rs-muted hover:text-rs-z9 hover:bg-rs-paper' }}">
                        <x-icon :name="$link['icon']" class="w-4 h-4 hidden xl:block" />
                        <span>{{ $link['label'] }}</span>
                        @if($link['active'])<span class="absolute left-3.5 right-3.5 -bottom-[11px] h-[3px] rounded-sm bg-rs-z7" aria-hidden="true"></span>@endif
                    </a>
                    @endforeach

                    {{-- Android app site (features and the APK): a plain link, not the yellow main action. --}}
                    {{-- Between lg and xl the row has no room for the label: icon with a tooltip and an accessible name. --}}
                    <a href="{{ $appSiteUrl }}" rel="noopener" data-nav-app aria-label="{{ __('Unduh aplikasi Android') }}" title="{{ __('Unduh aplikasi Android') }}"
                       class="px-3 xl:px-3.5 min-w-[44px] h-11 rounded-lg text-[15px] font-semibold transition-colors inline-flex items-center justify-center gap-2 text-rs-muted hover:text-rs-z9 hover:bg-rs-paper">
                        <x-icon name="download" class="w-5 h-5 xl:w-4 xl:h-4" />
                        <span class="hidden xl:inline">{{ __('Unduh aplikasi') }}</span>
                    </a>

                    <!-- Upload Dropdown -->
                    <div class="relative ml-2" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                        <button type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="true"
                                class="h-11 px-4 bg-rs-z9 text-white rounded-lg text-sm font-semibold hover:bg-rs-z8 transition-colors inline-flex items-center gap-2
                                       {{ $uploadActive ? 'ring-2 ring-rs-z7 ring-offset-2' : '' }}">
                            <x-icon name="upload" class="w-4 h-4" />
                            <span>{{ __('Upload GPX') }}</span>
                            <x-icon name="chevron-down" class="w-3.5 h-3.5 transition-transform" x-bind:class="{ 'rotate-180': open }" />
                        </button>
                        <div x-show="open" x-cloak x-transition.origin.top.right
                             class="absolute right-0 mt-2 w-60 bg-white rounded-xl shadow-lg border border-rs-line overflow-hidden">
                            <a href="{{ route('routes.create') }}" class="flex items-center gap-3 px-4 py-3 hover:bg-rs-paper transition-colors">
                                <x-icon name="document-add" class="w-5 h-5 text-rs-z7 flex-shrink-0" />
                                <span>
                                    <span class="block text-sm font-semibold text-rs-ink">{{ __('Satu file GPX') }}</span>
                                    <span class="block text-xs text-rs-muted">{{ __('Dari satu pendakian') }}</span>
                                </span>
                            </a>
                            <a href="{{ route('routes.batch') }}" class="flex items-center gap-3 px-4 py-3 hover:bg-rs-paper transition-colors border-t border-rs-line">
                                <x-icon name="upload" class="w-5 h-5 text-rs-z7 flex-shrink-0" />
                                <span>
                                    <span class="block text-sm font-semibold text-rs-ink">{{ __('Banyak file sekaligus') }}</span>
                                    <span class="block text-xs text-rs-muted">{{ __('Beberapa jalur dalam satu kali unggah') }}</span>
                                </span>
                            </a>
                        </div>
                    </div>

                    <x-lang-switch class="ml-2" />

                    <div class="w-px h-6 bg-rs-line mx-2"></div>

                    @auth
                    <!-- User menu -->
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                        <button type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="true"
                                class="flex items-center gap-2 pl-1 pr-2.5 h-11 rounded-lg border border-rs-line bg-white hover:border-rs-z7 transition-colors">
                            <span class="w-8 h-8 rounded-md bg-rs-z7 text-white text-sm font-bold flex items-center justify-center">{{ $navInitial }}</span>
                            <span class="text-sm font-semibold text-rs-ink max-w-[6rem] xl:max-w-[9rem] truncate" title="{{ $navUser->name }}">{{ $navShortName }}</span>
                            <x-icon name="chevron-down" class="w-3.5 h-3.5 text-rs-muted transition-transform" x-bind:class="{ 'rotate-180': open }" />
                        </button>
                        <div x-show="open" x-cloak x-transition.origin.top.right
                             class="absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-lg border border-rs-line py-2">
                            <div class="px-4 py-2.5 border-b border-rs-line">
                                <p class="text-sm font-semibold text-rs-ink truncate">{{ $navUser->name }}</p>
                                <p class="text-xs text-rs-muted truncate">{{ $navUser->email }}</p>
                            </div>
                            @foreach($accountLinks as $link)
                            <a href="{{ $link['href'] }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-rs-ink hover:bg-rs-paper"><x-icon :name="$link['icon']" class="w-4 h-4 text-rs-muted" />{{ $link['label'] }}</a>
                            @endforeach
                            @if($navUser->isAdmin())
                            <div class="border-t border-rs-line my-1"></div>
                            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-rs-ink hover:bg-rs-paper"><x-icon name="shield-check" class="w-4 h-4 text-rs-z7" />{{ __('Admin Panel') }}</a>
                            @endif
                            <div class="border-t border-rs-line my-1"></div>
                            <form action="{{ route('logout') }}" method="POST">
                                @csrf
                                <button type="submit" class="flex items-center gap-3 w-full px-4 py-2.5 text-sm text-[#9f1239] hover:bg-rose-50"><x-icon name="logout" class="w-4 h-4" />{{ __('Keluar') }}</button>
                            </form>
                        </div>
                    </div>
                    @else
                    <a href="{{ route('login') }}" class="px-3.5 h-11 inline-flex items-center rounded-lg text-sm font-semibold text-rs-ink hover:bg-rs-paper">{{ __('Masuk') }}</a>
                    <a href="{{ route('register') }}" class="px-4 h-11 inline-flex items-center rounded-lg text-sm font-semibold text-rs-z7 border-2 border-rs-z7 hover:bg-rs-mist">{{ __('Daftar') }}</a>
                    @endauth
                </div>

                <!-- Mobile: language + avatar shortcut + menu toggle -->
                <div class="flex items-center gap-2 lg:hidden">
                    <x-lang-switch class="hidden min-[400px]:inline-flex" />
                    @auth
                    <a href="{{ route('user.dashboard') }}" class="w-10 h-10 rounded-lg bg-rs-z7 text-white text-sm font-bold flex items-center justify-center" aria-label="{{ __('Dashboard') }} {{ $navUser->name }}">{{ $navInitial }}</a>
                    @endauth
                    <button type="button" @click="mobileOpen = !mobileOpen" :aria-expanded="mobileOpen" aria-controls="mobile-menu"
                            class="h-11 pl-2.5 pr-3 inline-flex items-center gap-1.5 rounded-lg text-rs-ink border border-rs-line hover:bg-rs-paper text-sm font-semibold">
                        <x-icon name="menu" class="w-5 h-5" x-show="!mobileOpen" />
                        <x-icon name="x" class="w-5 h-5" x-show="mobileOpen" x-cloak />
                        <span>{{ __('Menu') }}</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile menu panel -->
        <div id="mobile-menu" x-show="mobileOpen" x-cloak x-transition.opacity
             class="lg:hidden border-t border-rs-line bg-white max-h-[calc(100vh-4rem)] supports-[height:100dvh]:max-h-[calc(100dvh-4rem)] overflow-y-auto overscroll-contain shadow-lg">
            <div class="px-4 py-3 space-y-1">
                <div class="flex items-center justify-between px-3 pb-2">
                    <span class="text-sm font-semibold text-rs-muted inline-flex items-center gap-1.5"><x-icon name="translate" class="w-4 h-4" />{{ __('Bahasa') }}</span>
                    <x-lang-switch />
                </div>
                @foreach($navLinks as $link)
                <a href="{{ $link['href'] }}" @if($link['active']) aria-current="page" @endif
                   class="flex items-center gap-3 px-3 py-3 rounded-lg text-base font-semibold {{ $link['active'] ? 'bg-rs-mist text-rs-z9' : 'text-rs-ink hover:bg-rs-paper' }}">
                    <x-icon :name="$link['icon']" class="w-5 h-5 {{ $link['active'] ? 'text-rs-z7' : 'text-rs-muted' }}" />
                    {{ $link['label'] }}
                </a>
                @endforeach
                <a href="{{ $appSiteUrl }}" rel="noopener" data-nav-app class="flex items-center gap-3 px-3 py-3 rounded-lg text-base font-semibold text-rs-ink hover:bg-rs-paper">
                    <x-icon name="download" class="w-5 h-5 text-rs-muted" />
                    {{ __('Unduh aplikasi') }}
                    <span class="ml-auto text-sm font-medium text-rs-muted">Android</span>
                </a>

                <p class="px-3 pt-3 pb-1 text-sm font-semibold text-rs-muted">{{ __('Upload GPX') }}</p>
                <div class="grid grid-cols-2 gap-2">
                    <a href="{{ route('routes.create') }}" class="flex items-center gap-2 px-3 py-3 rounded-lg border text-sm font-semibold {{ request()->routeIs('routes.create') ? 'border-rs-z7 bg-rs-mist text-rs-z9' : 'border-rs-line text-rs-ink hover:bg-rs-paper' }}">
                        <x-icon name="document-add" class="w-5 h-5 text-rs-z7 flex-shrink-0" /> {{ __('Satu file') }}
                    </a>
                    <a href="{{ route('routes.batch') }}" class="flex items-center gap-2 px-3 py-3 rounded-lg border text-sm font-semibold {{ request()->routeIs('routes.batch') ? 'border-rs-z7 bg-rs-mist text-rs-z9' : 'border-rs-line text-rs-ink hover:bg-rs-paper' }}">
                        <x-icon name="upload" class="w-5 h-5 text-rs-z7 flex-shrink-0" /> {{ __('Banyak file') }}
                    </a>
                </div>
            </div>

            <div class="border-t border-rs-line px-4 py-3">
                @auth
                <div class="flex items-center gap-3 px-3 py-2">
                    <span class="w-10 h-10 rounded-lg bg-rs-z7 text-white font-bold flex items-center justify-center flex-shrink-0">{{ $navInitial }}</span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-rs-ink truncate">{{ $navUser->name }}</p>
                        <p class="text-xs text-rs-muted truncate">{{ $navUser->email }}</p>
                    </div>
                </div>
                <div class="mt-1 space-y-1">
                    @foreach($accountLinks as $link)
                    <a href="{{ $link['href'] }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-rs-ink hover:bg-rs-paper"><x-icon :name="$link['icon']" class="w-5 h-5 text-rs-muted" />{{ $link['label'] }}</a>
                    @endforeach
                    @if($navUser->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-rs-ink hover:bg-rs-paper"><x-icon name="shield-check" class="w-5 h-5 text-rs-z7" />{{ __('Admin Panel') }}</a>
                    @endif
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="flex items-center gap-3 w-full px-3 py-2.5 rounded-lg text-sm text-[#9f1239] hover:bg-rose-50"><x-icon name="logout" class="w-5 h-5" />{{ __('Keluar') }}</button>
                    </form>
                </div>
                @else
                <div class="grid grid-cols-2 gap-2">
                    <a href="{{ route('login') }}" class="px-4 py-3 rounded-lg text-center text-sm font-semibold text-rs-ink border border-rs-line hover:bg-rs-paper">{{ __('Masuk') }}</a>
                    <a href="{{ route('register') }}" class="px-4 py-3 rounded-lg text-center text-sm font-semibold text-white bg-rs-z7 hover:bg-rs-z9">{{ __('Daftar') }}</a>
                </div>
                @endauth
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main id="konten" class="pt-20 min-h-screen print:pt-0">
        <!-- Flash Messages -->
        @if(session('success'))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div class="bg-white border border-rs-line border-l-4 border-l-rs-z7 rounded-lg p-4 flex items-start gap-3" role="status">
                <x-icon name="check" class="w-5 h-5 text-rs-z7 flex-shrink-0 mt-0.5" />
                <p class="text-rs-ink font-medium">{{ session('success') }}</p>
            </div>
        </div>
        @endif

        @if(session('warning'))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div class="bg-amber-50 border border-amber-200 border-l-4 border-l-amber-500 rounded-lg p-4 flex items-start gap-3" role="status">
                <x-icon name="exclamation" class="w-5 h-5 text-amber-700 flex-shrink-0 mt-0.5" />
                <p class="text-amber-900 font-medium">{{ session('warning') }}</p>
            </div>
        </div>
        @endif

        {{-- The landing shows a search error right under its search box instead. --}}
        @if($errors->any() && ! (request()->routeIs('home', 'search.index') && $errors->keys() === ['query']))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div class="bg-rose-50 border border-rose-200 border-l-4 border-l-[#9f1239] rounded-lg p-4 flex items-start gap-3" role="alert">
                <x-icon name="exclamation" class="w-5 h-5 text-[#9f1239] flex-shrink-0 mt-0.5" />
                <ul class="text-[#881337] font-medium space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        @endif

        @yield('content')
    </main>

    <!-- Footer -->
    @php
        $paper = [
            'cite' => 'R. D. P. Wijaya & A. N. Rahman, SISTEMASI Vol. 15 No. 4, 2026',
            'url' => 'https://sistemasi.ftik.unisi.ac.id/index.php/stmsi/article/view/6087',
            'doi' => '10.32520/stmsi.v15i4.6087',
        ];
        $footerLinks = [
            [route('search.index'), __('Cari Rute')],
            [route('routes.index'), __('Semua Rute')],
            [$webchatUrl, __('RuteStrip AI Chat')],
            [route('routes.create'), __('Upload GPX')],
            [route('architecture'), __('Cara kerja sistem')],
            [route('cookies.policy'), __('Kebijakan cookie')],
        ];
    @endphp
    <footer class="rs-on-dark relative overflow-hidden bg-rs-z9 text-[#d1fae5] mt-20 print:hidden">
        {{-- Contour lines as the top edge of the footer (the site motif, drawn once here). --}}
        <svg class="block w-full h-8 sm:h-10 text-[#a7f3d0]" viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <g fill="none" stroke="currentColor" stroke-opacity=".28" vector-effect="non-scaling-stroke">
                <path d="M-20 14 C 200 2, 380 30, 620 16 S 1040 0, 1240 18 S 1400 26, 1460 12" stroke-width="1.2"/>
                <path d="M-20 28 C 220 16, 400 44, 640 30 S 1060 14, 1250 32 S 1410 40, 1460 26" stroke-width="2"/>
                <path d="M-20 42 C 240 30, 420 58, 660 44 S 1080 28, 1260 46 S 1420 54, 1460 40" stroke-width="1.2"/>
            </g>
        </svg>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 pb-8 sm:pt-10">
            <div class="grid gap-8 lg:grid-cols-12 lg:gap-10">
                <div class="lg:col-span-5">
                    <a href="{{ route('search.index') }}" class="inline-flex items-center gap-3 rounded-lg">
                        <img src="/images/logo.svg" alt="" width="40" height="40" class="w-10 h-10 rounded-lg">
                        <span class="rs-display uppercase text-3xl leading-none text-white">RuteStrip</span>
                    </a>
                    <p class="mt-3 text-sm leading-relaxed max-w-sm">
                        {{ __('Cari jalur pendakian gunung di Pulau Jawa dengan kalimat sehari-hari, lalu bandingkan jarak, tanjakan, dan waktunya dari file GPX. Data jalur untuk perencanaan, bukan alat navigasi.') }}
                    </p>
                    <div class="mt-4 flex items-center gap-2">
                        <a href="https://github.com/RamsNotes31" target="_blank" rel="noopener" aria-label="GitHub RuteStrip"
                           class="w-11 h-11 rounded-lg border border-white/20 hover:border-white/60 flex items-center justify-center transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                            </svg>
                        </a>
                        <a href="https://instagram.com/ramadanadipa_" target="_blank" rel="noopener" aria-label="Instagram RuteStrip"
                           class="w-11 h-11 rounded-lg border border-white/20 hover:border-white/60 flex items-center justify-center transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                            </svg>
                        </a>
                        <a href="https://t.me/rutestrip_hikingbot" target="_blank" rel="noopener" aria-label="{{ __('Bot Telegram RuteStrip') }}"
                           class="w-11 h-11 rounded-lg border border-white/20 hover:border-white/60 flex items-center justify-center transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/>
                            </svg>
                        </a>
                    </div>
                </div>

                <nav class="lg:col-span-4" aria-label="{{ __('Tautan di kaki halaman') }}">
                    <h2 class="text-sm font-semibold text-white">{{ __('Jelajahi') }}</h2>
                    <ul class="mt-1 grid grid-cols-2 gap-x-4 text-sm">
                        @foreach($footerLinks as [$href, $label])
                        <li><a href="{{ $href }}" class="inline-flex items-center min-h-[44px] hover:text-white hover:underline underline-offset-4">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                </nav>

                <div class="lg:col-span-3">
                    <h2 class="text-sm font-semibold text-white">{{ __('Publikasi') }}</h2>
                    <p class="mt-3 text-sm leading-relaxed">
                        <a href="{{ $paper['url'] }}" target="_blank" rel="noopener" class="underline decoration-white/40 underline-offset-4 hover:decoration-white hover:text-white">{{ $paper['cite'] }}<span class="sr-only"> {{ __('(tab baru)') }}</span></a>,
                        SINTA 3,
                        <a href="https://doi.org/{{ $paper['doi'] }}" target="_blank" rel="noopener" class="[overflow-wrap:anywhere] underline decoration-white/40 underline-offset-4 hover:decoration-white hover:text-white">DOI {{ $paper['doi'] }}<span class="sr-only"> {{ __('(tab baru)') }}</span></a>
                    </p>
                </div>
            </div>

            <div class="mt-8 pt-6 border-t border-white/15 flex flex-col md:flex-row md:items-start md:justify-between gap-2 text-xs text-[#b7cfc4]">
                <p>© 2026 Aeternum Kreasikan Bersama. RuteStrip, {{ __('dibuat untuk pendaki Indonesia') }}.</p>
                <p>
                    {{ __('Sebagian data jalur') }} &copy; <a href="https://www.openstreetmap.org/copyright" class="underline hover:text-white" target="_blank" rel="noopener">OpenStreetMap contributors</a> (ODbL); {{ __('elevasi: NASA SRTM.') }}
                </p>
            </div>
        </div>
    </footer>

    <!-- Mobile tab bar (< 768 px) -->
    @php
        $tabs = [
            ['href' => route('search.index'), 'label' => __('Cari'), 'icon' => 'search', 'active' => request()->routeIs('home', 'search.*')],
            ['href' => route('routes.index'), 'label' => __('Rute'), 'icon' => 'map', 'active' => request()->routeIs('routes.index', 'routes.show', 'routes.similar', 'routes.provenance')],
            ['href' => $webchatUrl, 'label' => __('AI Chat'), 'icon' => 'chat', 'active' => false, 'highlight' => true],
            ['href' => route('routes.create'), 'label' => __('Upload'), 'icon' => 'upload', 'active' => $uploadActive],
            $navUser
                ? ['href' => route('user.dashboard'), 'label' => __('Akun'), 'icon' => 'user-circle', 'active' => request()->routeIs('user.*', 'settings*', 'admin.*')]
                : ['href' => route('login'), 'label' => __('Masuk'), 'icon' => 'user-circle', 'active' => request()->routeIs('login', 'register')],
        ];
    @endphp
    <nav class="md:hidden print:hidden fixed bottom-0 inset-x-0 z-40 bg-white border-t border-rs-line tabbar-safe safe-x"
         aria-label="{{ __('Navigasi utama') }}">
        <ul class="grid grid-cols-5 h-16">
            @foreach($tabs as $tab)
            <li class="min-w-0">
                <a href="{{ $tab['href'] }}" @if($tab['active']) aria-current="page" @endif
                   class="relative h-full flex flex-col items-center justify-center gap-1 px-1 text-[11px] font-semibold leading-none transition-colors
                          {{ $tab['active'] ? 'text-rs-z9' : 'text-rs-muted hover:text-rs-z9' }}">
                    @if($tab['active'])<span class="absolute top-0 inset-x-4 h-[3px] rounded-b-sm bg-rs-z7" aria-hidden="true"></span>@endif
                    @if(!empty($tab['highlight']))
                    <span class="w-10 h-7 rounded-md bg-rs-z7 text-white flex items-center justify-center">
                        <x-icon :name="$tab['icon']" class="w-[18px] h-[18px]" />
                    </span>
                    @else
                    <x-icon :name="$tab['icon']" class="w-6 h-6" />
                    @endif
                    <span class="truncate max-w-full">{{ $tab['label'] }}</span>
                </a>
            </li>
            @endforeach
        </ul>
    </nav>

    {{-- Bottom banners: at most one at a time (cookie notice first, install prompt on a later page).
         While one is open, --rs-banner-h holds the space it takes so floating buttons can move above it. --}}
    @php
        // The landing has its own Android app section, so the card stays away there.
        $installBlocked = request()->routeIs('home', 'search.index', 'login', 'register', 'admin.login', 'routes.create', 'routes.batch', 'routes.versions.create', 'app.download*');
    @endphp
    <div id="rs-banner-dock" class="rs-dock" data-no-install="{{ $installBlocked ? '1' : '0' }}">
        <section id="rs-cookie" hidden aria-label="{{ __('Pemberitahuan cookie') }}"
                 class="rounded-xl border-2 border-rs-z9 bg-white px-3 py-2.5 sm:px-5 sm:py-3 shadow-lg flex items-center gap-3 sm:gap-5">
            <p class="text-sm text-rs-ink leading-snug flex-1 min-w-0">
                {{ __('Kami hanya memakai cookie yang dibutuhkan situs, seperti untuk login dan bahasa.') }}
                <a href="{{ route('cookies.policy') }}" class="font-semibold text-rs-z7 underline underline-offset-2 whitespace-nowrap">{{ __('Detail cookie') }}</a>
            </p>
            <button type="button" data-cookie-ok class="rs-sign shrink-0 min-h-[44px] rounded-lg px-4 text-sm font-bold">{{ __('Mengerti') }}</button>
        </section>

        {{-- Android app card (APK via /install). The web app (PWA) install now lives in Pengaturan. --}}
        <section id="rs-install" hidden aria-label="{{ __('Aplikasi Android RuteStrip') }}"
                 class="rounded-xl border-2 border-rs-z9 bg-white p-3 sm:px-5 sm:py-3 shadow-lg flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-5">
            <div class="flex items-center gap-3 flex-1 min-w-0">
                <img src="/images/icons/icon-192.png" alt="" width="44" height="44" class="w-10 h-10 rounded-lg shrink-0">
                <div class="min-w-0">
                    <p class="font-semibold text-rs-ink">{{ __('Aplikasi Android RuteStrip') }}</p>
                    <p class="text-sm text-rs-muted leading-snug">{{ __('Rekam jejak GPS dan buka jalur tersimpan, juga saat tanpa sinyal.') }}</p>
                </div>
            </div>
            <div class="flex gap-2 shrink-0">
                <a href="{{ route('app.download') }}" data-install-yes class="rs-sign flex-[2] sm:flex-none inline-flex items-center justify-center min-h-[44px] rounded-lg px-4 sm:px-5 text-sm font-bold whitespace-nowrap">{{ __('Unduh aplikasi') }}</a>
                <button type="button" data-install-no class="flex-1 sm:flex-none min-h-[44px] rounded-lg px-4 text-sm font-semibold text-rs-z9 border border-rs-line hover:bg-rs-paper">{{ __('Nanti') }}</button>
            </div>
        </section>
    </div>

    @include('components.floating-assistants')
    @stack('scripts')
    <script>
        // Installable app (PWA). The worker never caches HTML, POSTs, auth or API responses.
        // Registered on HTTPS (production) only, so local development is never served by a worker.
        if ('serviceWorker' in navigator && location.protocol === 'https:') {
            window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });
        }

        // Search forms: loading text while the routes are ranked, and no double submits.
        // The submitter itself is not disabled, so an example button still sends its own value.
        (function () {
            var forms = document.querySelectorAll('[data-search-form]');
            var setBusy = function (form, busy) {
                form.setAttribute('aria-busy', busy ? 'true' : 'false');
                form.querySelectorAll('[data-search-submit]').forEach(function (btn) {
                    var idle = btn.querySelector('[data-idle]'), wait = btn.querySelector('[data-busy]');
                    if (idle && wait) { idle.hidden = busy; wait.hidden = !busy; }
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
            window.addEventListener('pageshow', function () {
                forms.forEach(function (form) { delete form.dataset.busy; setBusy(form, false); });
            });
        })();

        // Bottom banners. Cookie notice until "Mengerti" (rs_cookie_ok, 1 year). Android app card:
        // Android browsers only (the download is an APK), never in the installed web app, never on
        // login/upload pages, not in the first 5 seconds, not on the same page view as the cookie
        // notice, and 14 days of quiet after "Nanti" or after the download link is pressed.
        (function () {
            var root = document.documentElement;
            var dock = document.getElementById('rs-banner-dock');
            if (!dock) return;
            var cookieBox = document.getElementById('rs-cookie');
            var installBox = document.getElementById('rs-install');
            var DAY = 864e5, QUIET = 'rs_install_dismissed';
            var store = {
                get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
                set: function (k, v) { try { localStorage.setItem(k, String(v)); } catch (e) {} }
            };
            var sync = function () {
                var open = !cookieBox.hidden || !installBox.hidden;
                var h = open ? (dock.offsetHeight + 8) + 'px' : '0px';
                root.style.setProperty('--rs-banner-h', h);
                // Floating buttons sit in the bottom corners (~112px wide); only lift them
                // when the banner reaches a corner (phones), not when it is centred (desktop).
                var r = dock.getBoundingClientRect();
                var hitsCorner = r.left < 112 || r.right > window.innerWidth - 112;
                root.style.setProperty('--rs-fab-lift', open && hitsCorner ? h : '0px');
            };
            if ('ResizeObserver' in window) new ResizeObserver(sync).observe(dock);
            window.addEventListener('resize', sync);
            var show = function (box) { box.hidden = false; sync(); };
            var hide = function (box) { box.hidden = true; sync(); };

            var cookieOpen = !/(?:^|;\s*)rs_cookie_ok=1(?:;|$)/.test(document.cookie);
            if (cookieOpen) show(cookieBox);
            cookieBox.querySelector('[data-cookie-ok]').addEventListener('click', function () {
                document.cookie = 'rs_cookie_ok=1; Max-Age=31536000; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
                hide(cookieBox);
            });

            var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
            var quietUntil = (+store.get(QUIET) || 0) + 14 * DAY;
            var android = /Android/i.test(navigator.userAgent);
            if (cookieOpen || standalone || !android || dock.dataset.noInstall === '1' || Date.now() < quietUntil) return;

            setTimeout(function () { if (cookieBox.hidden) show(installBox); }, 5000);
            // The link itself starts the download; the card just steps aside for 14 days.
            installBox.querySelector('[data-install-yes]').addEventListener('click', function () {
                store.set(QUIET, Date.now());
                hide(installBox);
            });
            installBox.querySelector('[data-install-no]').addEventListener('click', function () {
                store.set(QUIET, Date.now());
                hide(installBox);
            });
        })();
    </script>
</body>
</html>
