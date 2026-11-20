@extends('layouts.app')

@section('title', __('Kebijakan Cookie'))
@section('meta_description', __('Cookie yang dipakai RuteStrip: hanya untuk login, keamanan formulir, pilihan bahasa, dan suka tanpa akun. Tanpa analitik, iklan, atau pelacak.'))

@php
    // Real values from the configuration, so this page never drifts from what the site sets.
    $host = request()->getHost();
    $sessionDomain = config('session.domain') ?: $host;
    $sharedDomain = config('services.sso.cookie_domain') ?: $host;
    $sessionMinutes = (int) config('session.lifetime', 120);
    $sessionLife = config('session.expire_on_close')
        ? __('sampai browser ditutup')
        : ($sessionMinutes % 60 === 0
            ? __(intdiv($sessionMinutes, 60) === 1 ? '1 jam setelah aktivitas terakhir' : ':count jam setelah aktivitas terakhir', ['count' => intdiv($sessionMinutes, 60)])
            : __(':count menit setelah aktivitas terakhir', ['count' => $sessionMinutes]));
    $cookies = [
        [
            'name' => config('session.cookie'),
            'what' => __('Sesi'),
            'purpose' => __('Menjaga kamu tetap masuk selama berkunjung dan mengingat isi formulir saat ada yang perlu diperbaiki.'),
            'life' => $sessionLife,
            'domain' => $sessionDomain,
        ],
        [
            'name' => 'XSRF-TOKEN',
            'what' => __('Keamanan formulir'),
            'purpose' => __('Memastikan formulir yang dikirim memang berasal dari halaman RuteStrip, bukan dari situs lain.'),
            'life' => $sessionLife,
            'domain' => $sessionDomain,
        ],
        [
            'name' => 'remember_web_…',
            'what' => __('Ingat saya'),
            'purpose' => __('Hanya dibuat kalau kamu mencentang "Ingat saya" saat masuk, supaya tidak perlu masuk lagi di browser ini.'),
            'life' => __('400 hari, atau sampai kamu keluar'),
            'domain' => $sessionDomain,
        ],
        [
            'name' => 'rs_sso',
            'what' => __('Satu akun untuk situs dan AI Chat'),
            'purpose' => __('Membawa status masukmu ke RuteStrip AI Chat (chat.rutestrip.ai.id), jadi tidak perlu masuk dua kali.'),
            'life' => __(':count hari', ['count' => \App\Services\SsoService::TTL_DAYS]),
            'domain' => $sharedDomain,
        ],
        [
            'name' => 'rs_lang',
            'what' => __('Bahasa'),
            'purpose' => __('Mengingat pilihan Bahasa Indonesia atau English, di situs ini dan di AI Chat.'),
            'life' => __('1 tahun'),
            'domain' => $sharedDomain,
        ],
        [
            'name' => 'rs_vid',
            'what' => __('Suka tanpa akun'),
            'purpose' => __('Kode acak yang mengingat jalur mana yang sudah kamu sukai tanpa perlu masuk. Kode ini tidak berisi nama atau email.'),
            'life' => __('2 tahun'),
            'domain' => $host,
        ],
        [
            'name' => 'rs_cookie_ok',
            'what' => __('Pemberitahuan cookie'),
            'purpose' => __('Mengingat bahwa kamu sudah menekan Mengerti, jadi pemberitahuan cookie tidak muncul lagi.'),
            'life' => __('1 tahun'),
            'domain' => $host,
        ],
    ];
@endphp

@include('partials.rs-kit')

@section('content')
<div class="rs-app">
    @include('partials.rs-head', [
        'eyebrow' => __('Diperbarui 21 November 2026'),
        'title' => __('Kebijakan Cookie'),
        'lead' => __('RuteStrip hanya memakai cookie yang dibutuhkan situs agar bisa berjalan. Tidak ada analitik, iklan, atau pelacak.'),
        'seed' => 17,
        'width' => 'max-w-4xl',
    ])

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12 space-y-10">
        <section aria-labelledby="list-title">
            <h2 id="list-title" class="rs-display uppercase text-3xl text-rs-z9 leading-none">{{ __('Cookie yang kami pakai') }}</h2>
            <p class="mt-2 text-rs-muted leading-relaxed">{{ __('Semuanya cookie fungsional. Tanpa cookie ini, kamu tidak bisa masuk, mengirim formulir, atau menyimpan pilihan bahasa.') }}</p>

            <ul class="mt-5 space-y-3">
                @foreach($cookies as $cookie)
                <li class="rs-card p-4 sm:p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <h3 class="font-bold text-rs-ink">{{ $cookie['what'] }}</h3>
                        <code class="text-sm text-rs-z9 bg-rs-mist rounded px-1.5 py-0.5 break-all">{{ $cookie['name'] }}</code>
                    </div>
                    <p class="mt-2 text-rs-ink leading-relaxed">{{ $cookie['purpose'] }}</p>
                    <dl class="mt-3 grid grid-cols-1 min-[420px]:grid-cols-2 gap-x-6 gap-y-1 text-sm">
                        <div class="flex gap-2"><dt class="text-rs-muted">{{ __('Berlaku') }}:</dt><dd class="text-rs-ink font-medium">{{ $cookie['life'] }}</dd></div>
                        <div class="flex gap-2 min-w-0"><dt class="text-rs-muted">{{ __('Domain') }}:</dt><dd class="text-rs-ink font-medium break-all">{{ $cookie['domain'] }}</dd></div>
                    </dl>
                </li>
                @endforeach
            </ul>
        </section>

        <section aria-labelledby="storage-title">
            <h2 id="storage-title" class="rs-display uppercase text-3xl text-rs-z9 leading-none">{{ __('Yang disimpan di browser, bukan cookie') }}</h2>
            <ul class="mt-3 space-y-2 text-rs-ink leading-relaxed list-disc pl-5">
                <li>{{ __('Kapan terakhir kamu menekan Nanti pada tawaran memasang aplikasi, supaya tawaran itu tidak muncul lagi selama 14 hari.') }}</li>
                <li>{{ __('Percakapan dengan Asisten RuteStrip selama tab masih terbuka. Begitu tab ditutup, percakapannya hilang.') }}</li>
            </ul>
            <p class="mt-3 text-sm text-rs-muted">{{ __('Data ini tidak pernah dikirim ke server RuteStrip.') }}</p>
        </section>

        <section aria-labelledby="third-title">
            <h2 id="third-title" class="rs-display uppercase text-3xl text-rs-z9 leading-none">{{ __('Layanan luar') }}</h2>
            <p class="mt-3 text-rs-ink leading-relaxed">{{ __('Untuk menampilkan halaman, browsermu mengambil huruf dari Google Fonts, kode tampilan dari cdn.tailwindcss.com, jsDelivr, dan unpkg, serta potongan peta dari OpenStreetMap. Server mereka menerima alamat IP-mu seperti permintaan web biasa. RuteStrip tidak memasang cookie atau pelacak untuk mereka.') }}</p>
        </section>

        <section aria-labelledby="control-title">
            <h2 id="control-title" class="rs-display uppercase text-3xl text-rs-z9 leading-none">{{ __('Menghapus cookie') }}</h2>
            <p class="mt-3 text-rs-ink leading-relaxed">{{ __('Kamu bisa menghapus cookie RuteStrip kapan saja lewat pengaturan browser. Kalau cookie sesi atau rs_sso dihapus, kamu keluar dari akun dan perlu masuk lagi. Kalau rs_vid dihapus, daftar jalur yang kamu sukai tanpa akun ikut terlupa.') }}</p>
        </section>
    </div>
</div>
@endsection
