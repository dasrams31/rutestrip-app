@extends('layouts.app')

@section('title', __('account.settings.title'))

@php
    $currentLocale = old('locale', $user->locale ?: 'id');
    $sections = [
        ['id' => 'profile', 'label' => __('account.settings.profile'), 'icon' => 'user'],
        ['id' => 'password', 'label' => __('account.settings.password'), 'icon' => 'lock-closed'],
        ['id' => 'language', 'label' => __('account.settings.language'), 'icon' => 'translate'],
        ['id' => 'kiriman', 'label' => __('Kiriman jalurku'), 'icon' => 'clipboard-list'],
        ['id' => 'pwa', 'label' => __('Versi web (PWA)'), 'icon' => 'device-mobile'],
    ];
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app">
    @include('partials.rs-head', [
        'back' => ['href' => route('user.dashboard'), 'label' => __('account.settings.back')],
        'title' => __('account.settings.title'),
        'lead' => __('account.settings.subtitle'),
        'width' => 'max-w-5xl',
        'seed' => 41,
    ])

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-10 lg:grid lg:grid-cols-12 lg:gap-10">
        {{-- Section index: chips on phones, a sticky list beside the forms on wide screens. --}}
        <nav class="lg:col-span-3 mb-6 lg:mb-0" aria-label="{{ __('account.settings.nav') }}">
            <ul class="flex flex-wrap gap-2 lg:flex-col lg:gap-1 lg:sticky lg:top-24">
                @foreach($sections as $section)
                <li>
                    <a href="#{{ $section['id'] }}" class="inline-flex items-center gap-2 min-h-[44px] px-3 rounded-lg border rs-rule bg-white lg:bg-transparent lg:border-transparent lg:w-full font-semibold text-[0.9375rem] hover:bg-white hover:border-[#dcd8c9]">
                        <x-icon :name="$section['icon']" class="w-4 h-4 text-[#047857] shrink-0" /> {{ $section['label'] }}
                    </a>
                </li>
                @endforeach
            </ul>
        </nav>

        <div class="lg:col-span-9 space-y-6">
            {{-- Profile --}}
            <section id="profile" class="rs-card p-5 sm:p-7 scroll-mt-24" aria-labelledby="profile-heading">
                <h2 id="profile-heading" class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('account.settings.profile') }}</h2>
                <p class="mt-2 rs-muted">{{ __('account.settings.profile_help') }}</p>

                <form method="POST" action="{{ route('settings.update') }}" class="mt-5 space-y-5">
                    @csrf
                    <input type="hidden" name="section" value="profile">

                    <div>
                        <label for="name" class="rs-label">{{ __('account.settings.name') }}</label>
                        <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" autocomplete="name" required maxlength="255"
                               class="rs-input" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                        @error('name')
                            <p id="name-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div>
                        <label for="email" class="rs-label">{{ __('account.settings.email') }}</label>
                        <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" autocomplete="email" inputmode="email" required maxlength="255"
                               class="rs-input" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                        @error('email')
                            <p id="email-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <button type="submit" class="rs-btn w-full sm:w-auto">{{ __('account.settings.save_profile') }}</button>
                </form>
            </section>

            {{-- Password --}}
            <section id="password" class="rs-card p-5 sm:p-7 scroll-mt-24" aria-labelledby="password-heading">
                <h2 id="password-heading" class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('account.settings.password') }}</h2>
                <p class="mt-2 rs-muted">{{ __('account.settings.password_help') }}</p>

                <form method="POST" action="{{ route('settings.update') }}" class="mt-5 space-y-5">
                    @csrf
                    <input type="hidden" name="section" value="password">
                    {{-- Lets password managers attach the new password to the right account --}}
                    <input type="email" name="username" value="{{ $user->email }}" autocomplete="username" class="hidden" tabindex="-1" aria-hidden="true" readonly>

                    <div>
                        <label for="current_password" class="rs-label">{{ __('account.settings.current_password') }}</label>
                        <input type="password" name="current_password" id="current_password" autocomplete="current-password" required
                               class="rs-input" @error('current_password') aria-invalid="true" aria-describedby="current_password-error" @enderror>
                        @error('current_password')
                            <p id="current_password-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div class="min-w-0">
                            <label for="new_password" class="rs-label">{{ __('account.settings.new_password') }}</label>
                            <input type="password" name="password" id="new_password" autocomplete="new-password" required minlength="8"
                                   aria-describedby="new_password_hint @error('password') new_password-error @enderror"
                                   class="rs-input" @error('password') aria-invalid="true" @enderror>
                            <p id="new_password_hint" class="rs-hint">{{ __('account.settings.new_password_hint') }}</p>
                            @error('password')
                                <p id="new_password-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                            @enderror
                        </div>
                        <div class="min-w-0">
                            <label for="password_confirmation" class="rs-label">{{ __('account.settings.password_confirmation') }}</label>
                            <input type="password" name="password_confirmation" id="password_confirmation" autocomplete="new-password" required minlength="8" class="rs-input">
                        </div>
                    </div>

                    <button type="submit" class="rs-btn w-full sm:w-auto">{{ __('account.settings.save_password') }}</button>
                </form>
            </section>

            {{-- Language --}}
            <section id="language" class="rs-card p-5 sm:p-7 scroll-mt-24" aria-labelledby="language-heading">
                <h2 id="language-heading" class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('account.settings.language') }}</h2>
                <p class="mt-2 rs-muted">{{ __('account.settings.language_help') }}</p>

                <form method="POST" action="{{ route('settings.update') }}" class="mt-5">
                    @csrf
                    <input type="hidden" name="section" value="locale">

                    <fieldset class="grid grid-cols-1 min-[420px]:grid-cols-2 gap-3">
                        <legend class="sr-only">{{ __('account.settings.language') }}</legend>
                        @foreach (['id' => __('account.settings.language_id'), 'en' => __('account.settings.language_en')] as $code => $label)
                            <label class="flex items-center gap-3 min-h-[52px] px-4 rounded-lg border-2 cursor-pointer bg-white border-[#dcd8c9] hover:border-[#7c8c84] has-[:checked]:border-[#047857] has-[:checked]:bg-[#ecfdf5] has-[:focus-visible]:outline has-[:focus-visible]:outline-[3px] has-[:focus-visible]:outline-[#053b30] has-[:focus-visible]:outline-offset-2">
                                <input type="radio" name="locale" value="{{ $code }}" class="rs-check" @checked($currentLocale === $code)>
                                <span class="font-semibold" lang="{{ $code }}">{{ $label }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    @error('locale')
                        <p class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                    @enderror

                    <button type="submit" class="rs-btn w-full sm:w-auto mt-5">{{ __('account.settings.save_language') }}</button>
                </form>
            </section>

            {{-- Own uploads and app recordings with their review status (account.submissions). --}}
            <section id="kiriman" class="rs-card p-5 sm:p-7 scroll-mt-24" aria-labelledby="kiriman-heading">
                <h2 id="kiriman-heading" class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('Kiriman jalurku') }}</h2>
                <p class="mt-2 rs-muted">{{ __('Lihat status jalur yang kamu unggah dari situs atau kirim dari aplikasi Android: menunggu admin, tampil, atau ditolak beserta catatannya.') }}</p>
                <a href="{{ route('account.submissions') }}" class="rs-btn-ghost w-full sm:w-auto mt-5">
                    <x-icon name="clipboard-list" class="w-5 h-5 shrink-0" /><span>{{ __('Buka kiriman jalurku') }}</span>
                </a>
            </section>

            {{-- Web app (PWA) install. The browser's beforeinstallprompt event is kept by the
                 layout (window.__rsInstallEvent, "rs:installable"); this section offers it. --}}
            <section id="pwa" class="rs-card p-5 sm:p-7 scroll-mt-24" aria-labelledby="pwa-heading" data-pwa>
                <h2 id="pwa-heading" class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('Pasang versi web (PWA)') }}</h2>
                <p class="mt-2 rs-muted">{{ __('Versi web bisa dipasang ke layar utama HP atau komputer tanpa berkas APK. Isinya sama dengan situs ini dan tetap butuh internet.') }}</p>

                <div class="mt-5" aria-live="polite">
                    <button type="button" data-pwa-install hidden class="rs-btn-ghost w-full sm:w-auto">
                        <x-icon name="device-mobile" class="w-5 h-5 shrink-0" /><span>{{ __('Pasang versi web') }}</span>
                    </button>
                    <p data-pwa-state="none" class="text-[0.9375rem]">{{ __('Browser ini belum menawarkan pemasangan. Buka halaman ini di Chrome atau Edge, atau pakai menu browser lalu pilih Tambahkan ke layar utama.') }}</p>
                    <p data-pwa-state="ios" hidden class="text-[0.9375rem]">{{ __('Di iPhone atau iPad: ketuk Bagikan lalu Tambah ke Layar Utama.') }}</p>
                    <p data-pwa-state="installed" hidden class="text-[0.9375rem]">{{ __('Versi web sudah terpasang di perangkat ini.') }}</p>
                    <p data-pwa-state="done" hidden class="text-[0.9375rem]">{{ __('Terpasang. Buka RuteStrip dari layar utama.') }}</p>
                </div>

                <p class="mt-5 pt-4 border-t rs-rule text-sm rs-muted">
                    {{ __('Di HP Android, aplikasi RuteStrip merekam jejak GPS dan menyimpan jalur untuk dibuka tanpa sinyal.') }}
                    <a href="{{ route('app.download') }}" class="inline-flex items-center min-h-[44px] font-semibold text-[#047857] underline underline-offset-4">{{ __('Unduh aplikasi Android') }}</a>
                </p>
            </section>

            <p class="pt-2 text-sm rs-muted" data-copyright>© 2026 Aeternum Kreasikan Bersama</p>
        </div>
    </div>
</div>

@push('scripts')
<script>
    (function () {
        var box = document.querySelector('[data-pwa]');
        if (!box) return;
        var btn = box.querySelector('[data-pwa-install]');
        var state = function (name) {
            box.querySelectorAll('[data-pwa-state]').forEach(function (p) { p.hidden = p.dataset.pwaState !== name; });
            btn.hidden = name !== 'prompt';
        };
        var ua = navigator.userAgent;
        var ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
        var refresh = function () {
            if (standalone) return state('installed');
            if (window.__rsInstallEvent) return state('prompt');
            state(ios ? 'ios' : 'none');
        };
        refresh();
        window.addEventListener('rs:installable', refresh);
        window.addEventListener('appinstalled', function () { window.__rsInstallEvent = null; state('done'); });

        btn.addEventListener('click', function () {
            var e = window.__rsInstallEvent;
            if (!e) return refresh();
            window.__rsInstallEvent = null; // a prompt event can be used once
            Promise.resolve(e.prompt()).then(function () { return e.userChoice; }).then(function (choice) {
                if (choice && choice.outcome === 'accepted') state('done'); else refresh();
            }).catch(refresh);
        });
    })();
</script>
@endpush
@endsection
