@extends('layouts.app')

@section('title', __('Presentasi RuteStrip'))
@section('meta_description', __('Slide presentasi skripsi RuteStrip: rekomendasi jalur pendakian dengan SBERT dan Cosine Similarity, hasil di paper, dan evaluasi di situs serta aplikasi Android.'))
{{-- A personal defense deck (name and student number on the slides): reachable by link, not listed by search engines. --}}
@section('robots', 'noindex, follow')

@php
    /*
     * Slides are exported from the PowerPoint deck: images in public/images/introduction, titles and
     * text in resources/data/introduction.json, both written by python/tools/export_introduction_slides.py.
     * Design read: a slide viewer for examiners and visitors, in the site's map language,
     * dial ENERGY 2 / RHYTHM 1 / MOTION 1. The slides carry the energy, so the frame stays quiet:
     * one zamrud head, one yellow button (next slide), a short fade between slides.
     */
    $count = count($slides);
    $src = function (int $n) {
        $file = sprintf('images/introduction/slide-%02d.webp', $n);
        $path = public_path($file);

        return asset($file) . (is_file($path) ? '?v=' . filemtime($path) : '');
    };
@endphp

@include('partials.rs-kit')

@push('styles')
<style>
    /* The hidden attribute must beat display utilities (flex, md:block) on the same element. */
    .rs-app [hidden] { display: none !important; }
    /* Without JavaScript every slide is listed down the page. With it, one slide shows at a time. */
    .rs-deck.is-live .rs-slide { display: none; }
    .rs-deck.is-live .rs-slide.is-current { display: block; }
    .rs-deck.is-live .rs-slide-title { display: none; }
    @media (prefers-reduced-motion: no-preference) {
        .rs-deck.is-live .rs-slide.is-current img { animation: rs-slide-in .22s ease-out both; }
        @keyframes rs-slide-in { from { opacity: 0; } to { opacity: 1; } }
    }
    .rs-slide img { display: block; width: 100%; height: auto; aspect-ratio: 16 / 9; background: #053b30; }
    .rs-deck-bar button:disabled { opacity: .45; cursor: not-allowed; }
    /*
     * Full screen (.is-full, set by the script): the slide fills the whole screen and is never
     * cropped (object-fit: contain adds bars only when the screen is not 16:9). The control bar
     * lies over the bottom edge and fades out when the pointer rests, like a video player; it
     * stays while a control has keyboard focus. position: fixed also covers browsers without the
     * Fullscreen API (iPhone), where the stage simply takes over the window.
     */
    .rs-stage.is-full { position: fixed; inset: 0; z-index: 2147483000; width: 100vw; height: 100vh; height: 100dvh; margin: 0; padding: 0; overflow: hidden; background: #053b30; }
    .rs-stage.is-full .rs-deck, .rs-stage.is-full .rs-slide.is-current, .rs-stage.is-full figure, .rs-stage.is-full .rs-slide-frame { height: 100%; margin: 0; }
    .rs-stage.is-full .rs-slide-frame { border: 0; border-radius: 0; background: transparent; }
    .rs-stage.is-full .rs-slide img { width: 100%; height: 100%; aspect-ratio: auto; object-fit: contain; background: transparent; cursor: pointer; }
    .rs-stage.is-full .rs-slide-text { display: none; }
    /* Quiet bar: a thin strip that fades into the slide, small outlined buttons, no yellow fill.
       Behind the buttons the zamrud backdrop is at least 74%, so white text keeps 4.5:1 even
       over the light slides (checked: 5.6:1 over the paper colour). */
    .rs-stage.is-full .rs-deck-bar { position: absolute; left: 0; right: 0; bottom: 0; z-index: 2; margin: 0; justify-content: center; gap: .5rem;
        padding: 1.5rem 1rem calc(.5rem + env(safe-area-inset-bottom)); color: #fff; font-size: .875rem;
        background: linear-gradient(to top, rgba(5, 59, 48, .88) 0, rgba(5, 59, 48, .74) 70%, rgba(5, 59, 48, 0) 100%);
        transition: opacity .2s; }
    .rs-stage.is-full .rs-deck-bar button { min-height: 36px; padding: 0 .8rem; font-size: .875rem; font-weight: 600; border-width: 1px; border-radius: 6px; }
    .rs-stage.is-full .rs-deck-bar .rs-btn-ghost, .rs-stage.is-full .rs-deck-bar .rs-sign { background: transparent; color: #fff; border-color: rgba(255, 255, 255, .45); }
    /* Next slide keeps a yellow edge as the one accent; its text stays white (yellow text is only 3.9:1 here). */
    .rs-stage.is-full .rs-deck-bar .rs-sign { border-color: #FCD34D; }
    .rs-stage.is-full .rs-deck-bar button:hover { background: rgba(255, 255, 255, .12); }
    .rs-stage.is-full .rs-deck-bar svg { width: 1rem; height: 1rem; }
    @media (pointer: coarse) { .rs-stage.is-full .rs-deck-bar button { min-height: 44px; } }
    .rs-stage.is-full #rs-full { margin-left: 0; }
    .rs-stage.is-full :focus-visible { outline-color: #fff; }
    .rs-stage.is-full.is-idle { cursor: none; }
    .rs-stage.is-full.is-idle .rs-deck-bar:not(:has(:focus-visible)) { opacity: 0; pointer-events: none; }
    html.rs-no-scroll, html.rs-no-scroll body { overflow: hidden; }
    .rs-outline button[aria-current="true"] { background: #ecfdf5; border-color: #047857; }
</style>
@endpush

@section('content')
<div class="rs-app">
    @include('partials.rs-head', [
        'eyebrow' => __('S1 Sistem Informasi · Rama Danadipa Putra Wijaya'),
        'title' => __('Presentasi RuteStrip'),
        'lead' => __(':count slide: isi paper, lalu evaluasi di situs dan aplikasi Android.', ['count' => $count]),
        'seed' => 23,
        'width' => 'max-w-6xl',
    ])

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-10">
        <div class="rs-stage" id="rs-stage">
            {{-- Shown by the script; without JavaScript the slides are simply read top to bottom. --}}
            <div class="rs-deck-bar mb-4 flex flex-wrap items-center gap-3" id="rs-deck-bar" hidden>
                <button type="button" class="rs-btn-ghost" id="rs-prev">
                    <x-icon name="arrow-left" class="w-5 h-5 shrink-0" /> <span>{{ __('Sebelumnya') }}</span>
                </button>
                <button type="button" class="rs-sign" id="rs-next">
                    <span>{{ __('Berikutnya') }}</span> <x-icon name="arrow-right" class="w-5 h-5 shrink-0" />
                </button>
                <p class="rs-num font-semibold" id="rs-count" role="status" aria-live="polite"></p>
                <button type="button" class="rs-btn-ghost sm:ml-auto" id="rs-full" hidden>{{ __('Layar penuh') }}</button>
            </div>
            <ol class="rs-deck space-y-8" id="rs-deck" aria-label="{{ __('Slide presentasi') }}">
                @foreach($slides as $slide)
                <li class="rs-slide" id="slide-{{ $slide['n'] }}" data-n="{{ $slide['n'] }}">
                    <figure>
                        <div class="rs-slide-frame rs-card overflow-hidden">
                            <img src="{{ $src($slide['n']) }}" width="1920" height="1080"
                                 alt="{{ __('Slide :n dari :count', ['n' => $slide['n'], 'count' => $count]) }}: {{ $slide['title'] }}"
                                 @if($slide['n'] > 1) loading="lazy" @endif decoding="async">
                        </div>
                        <figcaption class="rs-slide-text mt-3">
                            <p class="rs-slide-title font-semibold text-rs-ink"><span class="rs-num">{{ $slide['n'] }}.</span> {{ $slide['title'] }}</p>
                            @if(!empty($slide['text']))
                            <details class="mt-1">
                                <summary class="inline-flex items-center min-h-[44px] cursor-pointer text-sm font-semibold text-rs-z7 underline underline-offset-4">{{ __('Baca teks slide ini') }}</summary>
                                <ul class="mt-2 space-y-1.5 text-sm leading-relaxed text-rs-ink list-disc pl-5 max-w-3xl">
                                    @foreach($slide['text'] as $line)
                                    <li class="[overflow-wrap:anywhere]">{{ $line }}</li>
                                    @endforeach
                                </ul>
                            </details>
                            @endif
                        </figcaption>
                    </figure>
                </li>
                @endforeach
            </ol>

        </div>

        <p class="mt-3 text-sm rs-muted hidden md:block" id="rs-keys" hidden>{{ __('Tombol panah kiri dan kanan di keyboard juga memindahkan slide.') }}</p>

        <section class="mt-10" aria-labelledby="outline-title" id="rs-outline" hidden>
            <h2 id="outline-title" class="rs-display uppercase text-3xl text-rs-z9 leading-none">{{ __('Daftar slide') }}</h2>
            <ol class="rs-outline mt-4 grid gap-2 sm:grid-cols-2">
                @foreach($slides as $slide)
                <li>
                    <button type="button" data-go="{{ $slide['n'] }}"
                            class="w-full min-h-[48px] flex items-baseline gap-3 px-3 py-2.5 text-left rounded-lg bg-white border border-rs-line hover:border-rs-z7">
                        <span class="rs-num shrink-0 w-6 text-sm font-semibold text-rs-muted">{{ $slide['n'] }}</span>
                        <span class="min-w-0 font-semibold text-rs-ink [overflow-wrap:anywhere]">{{ \Illuminate\Support\Str::limit($slide['title'], 70) }}</span>
                    </button>
                </li>
                @endforeach
            </ol>
        </section>

        <section class="mt-10 border-t border-rs-line pt-6" aria-labelledby="links-title">
            <h2 id="links-title" class="rs-display uppercase text-3xl text-rs-z9 leading-none">{{ __('Sumber') }}</h2>
            <ul class="mt-3 space-y-1">
                <li><a class="inline-flex items-center gap-2 min-h-[44px] font-semibold text-rs-z7 underline underline-offset-4" href="https://doi.org/10.32520/stmsi.v15i4.6087" rel="noopener">{{ __('Paper di SISTEMASI, Vol. 15 No. 4 (2026)') }} <x-icon name="external-link" class="w-4 h-4 shrink-0" /></a></li>
                <li><a class="inline-flex items-center gap-2 min-h-[44px] font-semibold text-rs-z7 underline underline-offset-4" href="https://s.peka.ai.id/rutestrip-notebook" rel="noopener">{{ __('Notebook Colab: perhitungan yang bisa dijalankan ulang') }} <x-icon name="external-link" class="w-4 h-4 shrink-0" /></a></li>
                <li><a class="inline-flex items-center gap-2 min-h-[44px] font-semibold text-rs-z7 underline underline-offset-4" href="{{ route('app.download') }}">{{ __('Unduh aplikasi Android RuteStrip (APK)') }} <x-icon name="download" class="w-4 h-4 shrink-0" /></a></li>
            </ul>
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var deck = document.getElementById('rs-deck');
    var slides = Array.prototype.slice.call(deck.querySelectorAll('.rs-slide'));
    if (!slides.length) return;

    var stage = document.getElementById('rs-stage');
    var bar = document.getElementById('rs-deck-bar');
    var prev = document.getElementById('rs-prev');
    var next = document.getElementById('rs-next');
    var count = document.getElementById('rs-count');
    var full = document.getElementById('rs-full');
    var outline = document.getElementById('rs-outline');
    var label = @js(__('Slide :n dari :count'));
    var current = 0;

    function show(index, moveFocus, keepUrl) {
        current = Math.max(0, Math.min(slides.length - 1, index));
        slides.forEach(function (slide, i) { slide.classList.toggle('is-current', i === current); });
        prev.disabled = current === 0;
        next.disabled = current === slides.length - 1;
        count.textContent = label.replace(':n', current + 1).replace(':count', slides.length);
        outline.querySelectorAll('[data-go]').forEach(function (button, i) {
            if (i === current) button.setAttribute('aria-current', 'true'); else button.removeAttribute('aria-current');
        });
        // Load this slide and the next one now, so moving on does not flash an empty frame.
        [current, current + 1].forEach(function (i) {
            var img = slides[i] && slides[i].querySelector('img');
            if (img) img.loading = 'eager';
        });
        if (!keepUrl && history.replaceState) history.replaceState(null, '', '#slide-' + (current + 1));
        if (moveFocus) stage.scrollIntoView({ block: 'nearest', behavior: 'auto' });
    }

    deck.classList.add('is-live');
    deck.classList.remove('space-y-8');
    bar.hidden = false;
    outline.hidden = false;
    document.getElementById('rs-keys').hidden = false;

    prev.addEventListener('click', function () { show(current - 1); });
    next.addEventListener('click', function () { show(current + 1); });
    outline.addEventListener('click', function (event) {
        var button = event.target.closest('[data-go]');
        if (button) show(Number(button.dataset.go) - 1, true);
    });

    document.addEventListener('keydown', function (event) {
        if (event.altKey || event.ctrlKey || event.metaKey) return;
        var tag = (event.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable) return;
        if (event.key === 'ArrowRight' || event.key === 'PageDown') { show(current + 1); event.preventDefault(); }
        else if (event.key === 'ArrowLeft' || event.key === 'PageUp') { show(current - 1); event.preventDefault(); }
        else if (event.key === 'Home') { show(0); event.preventDefault(); }
        else if (event.key === 'End') { show(slides.length - 1); event.preventDefault(); }
    });

    // Swipe left or right on the slide (phones and tablets).
    var startX = null, startY = null;
    deck.addEventListener('touchstart', function (event) {
        startX = event.touches[0].clientX; startY = event.touches[0].clientY;
    }, { passive: true });
    deck.addEventListener('touchend', function (event) {
        if (startX === null) return;
        var dx = event.changedTouches[0].clientX - startX;
        var dy = event.changedTouches[0].clientY - startY;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) show(current + (dx < 0 ? 1 : -1));
        startX = null;
    }, { passive: true });

    // Full screen. Real Fullscreen API where it exists; otherwise the stage takes over the window.
    var enter = @js(__('Layar penuh')), leave = @js(__('Keluar dari layar penuh'));
    var request = stage.requestFullscreen || stage.webkitRequestFullscreen;
    var exit = document.exitFullscreen || document.webkitExitFullscreen;
    var fsElement = function () { return document.fullscreenElement || document.webkitFullscreenElement; };
    var idleTimer = null;

    function wake() {
        stage.classList.remove('is-idle');
        clearTimeout(idleTimer);
        if (stage.classList.contains('is-full')) idleTimer = setTimeout(function () { stage.classList.add('is-idle'); }, 2500);
    }
    function setFull(on) {
        stage.classList.toggle('is-full', on);
        document.documentElement.classList.toggle('rs-no-scroll', on);
        full.textContent = on ? leave : enter;
        wake();
    }
    function isFull() { return stage.classList.contains('is-full'); }

    full.hidden = false;
    full.addEventListener('click', function () {
        if (request && exit) {
            if (fsElement()) exit.call(document); else request.call(stage);
        } else {
            setFull(!isFull());
        }
    });
    ['fullscreenchange', 'webkitfullscreenchange'].forEach(function (name) {
        document.addEventListener(name, function () { setFull(fsElement() === stage); });
    });
    // Pointer movement brings the bar back; arrow keys and space change slides without showing it.
    ['mousemove', 'mousedown', 'touchstart'].forEach(function (name) {
        stage.addEventListener(name, wake, { passive: true });
    });
    // In full screen a click on the slide moves on, and so does the space bar.
    deck.addEventListener('click', function (event) {
        if (isFull() && event.target.tagName === 'IMG') show(current + 1);
    });
    document.addEventListener('keydown', function (event) {
        if (!isFull()) return;
        if (event.key === 'Tab') wake();
        if (event.key === ' ' && event.target.tagName !== 'BUTTON') { show(current + 1); event.preventDefault(); }
        else if (event.key === 'Escape' && !fsElement()) setFull(false);
    });

    function fromHash() { var m = /^#slide-(\d+)$/.exec(location.hash); return m ? Number(m[1]) - 1 : null; }
    window.addEventListener('hashchange', function () { var i = fromHash(); if (i !== null && i !== current) show(i, true, true); });
    var first = fromHash();
    show(first === null ? 0 : first, false, first === null);
})();
</script>
@endpush
