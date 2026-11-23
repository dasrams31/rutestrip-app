@extends('layouts.app')

@section('title', __('Dashboard'))

@php
    $isEn = app()->getLocale() === 'en';
    $fmt = fn ($n) => number_format((float) $n, 0, $isEn ? '.' : ',', $isEn ? ',' : '.');
    $level = fn ($l) => match ($l) { 'Mudah' => 1, 'Sedang' => 2, 'Sulit' => 3, default => 4 };
    $statItems = [
        ['id' => 'stat-favorites', 'value' => $stats['favorites'], 'label' => __('jalur favorit'), 'href' => route('user.favorites')],
        ['id' => 'stat-searches', 'value' => $stats['searches'], 'label' => __('pencarian tersimpan'), 'href' => route('user.history')],
        ['id' => 'stat-comments', 'value' => $stats['comments'], 'label' => __('komentar ditulis'), 'href' => null],
        ['id' => 'stat-ratings', 'value' => $stats['ratings'], 'label' => __('nilai bintang diberikan'), 'href' => null],
    ];
    $routeTotal = \App\Models\HikingRoute::count();
    $headActions = array_values(array_filter([
        ['href' => route('search.index'), 'label' => __('Cari jalur'), 'style' => 'sign', 'icon' => 'search'],
        Route::has('settings') ? ['href' => route('settings'), 'label' => __('Pengaturan akun'), 'icon' => 'cog'] : null,
        $user->isAdmin() ? ['href' => route('admin.dashboard'), 'label' => __('Panel admin'), 'icon' => 'shield-check'] : null,
    ]));
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'eyebrow' => __('Dashboard'),
        'title' => __('Halo, :name.', ['name' => \Illuminate\Support\Str::limit(\Illuminate\Support\Str::before(trim($user->name) . ' ', ' '), 20, '')]),
        'lead' => __('Jalur yang kamu simpan dan pencarianmu ada di sini.'),
        'actions' => $headActions,
        'seed' => 79,
    ])

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        {{-- Numbers from your own account, refreshed while the page is open. --}}
        <section class="rs-card -mt-5 sm:-mt-7 relative" aria-labelledby="stats-title">
            <h2 id="stats-title" class="sr-only">{{ __('Ringkasan akunmu') }}</h2>
            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-[#ece9de] rounded-xl overflow-hidden">
                @foreach($statItems as $item)
                <div class="flex flex-col-reverse justify-end bg-white p-4 sm:p-6 min-w-0">
                    <dt class="mt-1 text-sm rs-muted">
                        @if($item['href'])<a href="{{ $item['href'] }}" class="underline decoration-[#dcd8c9] underline-offset-4 hover:text-[#053b30]">{{ $item['label'] }}</a>@else{{ $item['label'] }}@endif
                    </dt>
                    <dd class="rs-display rs-num text-4xl sm:text-5xl leading-none text-[#053b30] transition-colors" id="{{ $item['id'] }}">{{ $fmt($item['value']) }}</dd>
                </div>
                @endforeach
            </dl>
        </section>
        <p class="mt-2 text-xs rs-muted text-right" aria-live="polite"><span id="last-update">{{ __('Angka diperbarui otomatis selama halaman ini terbuka.') }}</span></p>

        <div class="mt-8 lg:grid lg:grid-cols-12 lg:gap-8">
            {{-- Favourites --}}
            <section class="lg:col-span-7 min-w-0" aria-labelledby="fav-title">
                <div class="flex items-end justify-between gap-3">
                    <h2 id="fav-title" class="rs-display uppercase text-3xl sm:text-4xl leading-none text-[#053b30]">{{ __('Jalur favorit') }}</h2>
                    <a href="{{ route('user.favorites') }}" class="inline-flex items-center min-h-[44px] font-semibold text-[#047857] hover:text-[#053b30] shrink-0">{{ __('Lihat semua') }}</a>
                </div>

                @if($recentFavorites->count() > 0)
                <ul class="mt-3 rs-card divide-y divide-[#ece9de]">
                    @foreach($recentFavorites as $route)
                    <li>
                        <a href="{{ route('routes.show', $route) }}" class="flex items-center gap-4 p-4 sm:px-5 min-w-0 hover:bg-[#faf9f4]">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-[#053b30] truncate" title="{{ $route->name }}">{{ $route->name }}</p>
                                <p class="mt-1 text-sm rs-muted flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span class="rs-num">{{ $route->formatted_distance }}</span>
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="rs-diff" aria-hidden="true">@for($i = 1; $i <= 4; $i++)<i class="{{ $i <= $level($route->difficulty_level) ? 'on' : '' }}"></i>@endfor</span>
                                        {{ __($route->difficulty_level) }}
                                    </span>
                                </p>
                            </div>
                            <x-icon name="chevron-right" class="w-5 h-5 text-[#7c8c84] shrink-0" />
                        </a>
                    </li>
                    @endforeach
                </ul>
                @else
                <div class="mt-3 rs-card p-6">
                    <p class="font-semibold">{{ __('Belum ada jalur favorit.') }}</p>
                    <p class="mt-1 rs-muted">{{ __('Ketuk ikon hati di halaman jalur untuk menyimpannya di sini.') }}</p>
                    <a href="{{ route('routes.index') }}" class="rs-btn mt-4">{{ __('Lihat semua jalur') }}</a>
                </div>
                @endif
            </section>

            {{-- Recent searches --}}
            <section class="lg:col-span-5 min-w-0 mt-10 lg:mt-0" aria-labelledby="search-title">
                <div class="flex items-end justify-between gap-3">
                    <h2 id="search-title" class="rs-display uppercase text-3xl sm:text-4xl leading-none text-[#053b30]">{{ __('Pencarian terakhir') }}</h2>
                    <a href="{{ route('user.history') }}" class="inline-flex items-center min-h-[44px] font-semibold text-[#047857] hover:text-[#053b30] shrink-0">{{ __('Semua') }}</a>
                </div>

                @if($recentSearches->count() > 0)
                <ul class="mt-3 rs-card divide-y divide-[#ece9de]">
                    @foreach($recentSearches as $search)
                    <li>
                        <form action="{{ route('search.submit') }}" method="POST">
                            @csrf
                            <input type="hidden" name="query" value="{{ $search->query }}">
                            <button type="submit" class="w-full text-left flex items-center gap-3 p-4 sm:px-5 min-w-0 hover:bg-[#faf9f4]" title="{{ __('Cari lagi') }}: {{ $search->query }}">
                                <x-icon name="search" class="w-4 h-4 text-[#047857] shrink-0" />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold truncate">{{ $search->query }}</span>
                                    <span class="block mt-0.5 text-sm rs-muted">{{ __(':count hasil', ['count' => $search->results_count]) }} · {{ $search->created_at->diffForHumans() }}</span>
                                </span>
                            </button>
                        </form>
                    </li>
                    @endforeach
                </ul>
                @else
                <div class="mt-3 rs-card p-6">
                    <p class="font-semibold">{{ __('Belum ada riwayat pencarian.') }}</p>
                    <p class="mt-1 rs-muted">{{ __('Coba tulis "jalur landai untuk pemula" di halaman cari.') }}</p>
                </div>
                @endif
            </section>
        </div>

        {{-- Next steps --}}
        <section class="mt-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" aria-label="{{ __('Lanjut ke') }}">
            <a href="{{ route('routes.index') }}" class="rs-card p-5 flex items-center gap-4 hover:border-[#047857] min-w-0">
                <x-icon name="map" class="w-7 h-7 text-[#047857] shrink-0" />
                <span class="min-w-0">
                    <span class="block font-semibold">{{ __('Semua jalur') }}</span>
                    <span class="block text-sm rs-muted">{{ __(':count jalur dengan data GPX', ['count' => $fmt($routeTotal)]) }}</span>
                </span>
            </a>
            <a href="{{ route('routes.create') }}" class="rs-card p-5 flex items-center gap-4 hover:border-[#047857] min-w-0">
                <x-icon name="upload" class="w-7 h-7 text-[#047857] shrink-0" />
                <span class="min-w-0">
                    <span class="block font-semibold">{{ __('Upload GPX') }}</span>
                    <span class="block text-sm rs-muted">{{ __('Tambahkan jalur dari file GPX') }}</span>
                </span>
            </a>
            <a href="{{ route('account.submissions') }}" class="rs-card p-5 flex items-center gap-4 hover:border-[#047857] min-w-0 sm:col-span-2 lg:col-span-1">
                <x-icon name="clipboard-list" class="w-7 h-7 text-[#047857] shrink-0" />
                <span class="min-w-0">
                    <span class="block font-semibold">{{ __('Kiriman jalurku') }}</span>
                    <span class="block text-sm rs-muted">{{ __('Status upload dan rekaman dari aplikasi') }}</span>
                </span>
            </a>
        </section>

        {{-- What only the Android app does (GPS and phone-to-phone radios); the web covers the rest. --}}
        <section class="mt-10 rs-card p-5 sm:p-7 md:grid md:grid-cols-12 md:gap-8" aria-labelledby="app-only-title" data-app-only>
            <div class="md:col-span-5">
                <h2 id="app-only-title" class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('Di aplikasi Android') }}</h2>
                <p class="mt-2 rs-muted">{{ __('Fitur berikut memakai GPS, Bluetooth, dan Wi-Fi di HP, jadi hanya tersedia di aplikasi.') }}</p>
                <a href="https://app.rutestrip.ai.id" rel="noopener" class="mt-3 inline-flex items-center gap-1.5 min-h-[44px] font-semibold text-[#047857] underline decoration-[#047857]/40 underline-offset-4 hover:text-[#053b30]">
                    {{ __('Lihat semua fitur aplikasi') }}
                </a>
            </div>
            <ul class="mt-4 md:mt-0 md:col-span-7 space-y-3">
                @foreach([
                    ['location-marker', __('Rekam jejak GPS selama mendaki, tetap berjalan saat layar mati.')],
                    ['users', __('Rombongan tanpa internet: obrolan, posisi teman, dan SOS antar-HP lewat Bluetooth atau Wi-Fi.')],
                    ['mountain', __('Putar ulang rekaman pendakianmu sendiri dalam 3D.')],
                ] as [$icon, $text])
                <li class="flex items-start gap-3">
                    <x-icon :name="$icon" class="w-5 h-5 mt-0.5 text-[#047857] shrink-0" />
                    <span>{{ $text }}</span>
                </li>
                @endforeach
            </ul>
        </section>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const url = @js(route('user.dashboard.stats'));
    const updated = @js(__('Diperbarui pukul :time'));
    const ids = { favorites: 'stat-favorites', searches: 'stat-searches', comments: 'stat-comments', ratings: 'stat-ratings' };

    function refresh() {
        if (document.hidden) return; // no polling while the tab is in the background
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then((r) => r.ok ? r.json() : Promise.reject(r.status))
            .then((data) => {
                Object.entries(ids).forEach(([key, id]) => {
                    const el = document.getElementById(id);
                    const value = Number(data[key] ?? 0).toLocaleString(document.documentElement.lang);
                    if (el && el.textContent !== value) {
                        el.textContent = value;
                        el.style.color = '#047857';
                        setTimeout(() => { el.style.color = ''; }, 800);
                    }
                });
                document.getElementById('last-update').textContent = updated.replace(':time', data.timestamp);
            })
            .catch(() => {});
    }
    setInterval(refresh, 10000);
    setTimeout(refresh, 2000);
})();
</script>
@endpush
@endsection
