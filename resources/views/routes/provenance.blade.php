@extends('layouts.app')

@section('title', __('Provenance GPX') . ' - ' . $route->name)

@php
    $isAdmin = auth()->check() && auth()->user()->isAdmin();
    $active = $route->activeGpxVersion;
    $status = fn (?string $s) => match ($s) {
        'verified' => ['label' => __('Cocok, tidak berubah'), 'class' => 'rs-chip-ok', 'icon' => 'check'],
        'invalid' => ['label' => __('Isi file berubah'), 'class' => 'rs-chip-bad', 'icon' => 'exclamation'],
        'verification_failed' => ['label' => __('Gagal diperiksa'), 'class' => 'rs-chip-warn', 'icon' => 'exclamation'],
        default => ['label' => __('Belum diperiksa'), 'class' => 'rs-chip-plain', 'icon' => 'clock'],
    };
    $when = fn ($date) => $date ? $date->translatedFormat('j M Y, H:i') : '-';
    $headActions = $isAdmin ? [
        ['href' => route('routes.versions.create', $route), 'label' => __('Unggah versi baru'), 'style' => 'sign', 'icon' => 'upload'],
        ['href' => route('routes.provenance.export-verification-logs', $route), 'label' => __('Unduh log pemeriksaan (CSV)'), 'icon' => 'document-text'],
    ] : [];
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => ['href' => route('routes.show', $route), 'label' => __('Kembali ke halaman jalur')],
        'eyebrow' => __('Riwayat file GPX'),
        'title' => $route->name,
        'lead' => __('Setiap kali file GPX jalur ini diganti, versi lamanya tetap disimpan. Sidik file (hash) dipakai untuk memastikan isinya tidak berubah diam-diam.'),
        'actions' => $headActions,
        'titleClass' => 'text-[clamp(1.9rem,7.5vw,3.5rem)]',
        'seed' => 71,
    ])

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 sm:pt-10">
        {{-- Summary: the active file first, because that is what the route page uses. --}}
        <section class="rs-card p-5 sm:p-7 lg:grid lg:grid-cols-12 lg:gap-8" aria-labelledby="active-title">
            <div class="lg:col-span-7 min-w-0">
                <p class="text-sm font-semibold rs-muted" id="active-title">{{ __('File yang dipakai sekarang') }}</p>
                @if($active)
                    @php $s = $status($active->verification_status); @endphp
                    <p class="rs-display mt-1 text-5xl leading-none text-[#053b30]">v{{ $active->version_number }}</p>
                    <p class="mt-3"><span class="rs-chip {{ $s['class'] }}"><x-icon :name="$s['icon']" class="w-4 h-4" />{{ $s['label'] }}</span></p>
                    <p class="mt-2 rs-muted">{{ __($active->verification_status_explanation) }}</p>
                @else
                    <p class="rs-display mt-1 text-4xl leading-none text-[#053b30]">{{ __('Belum ada') }}</p>
                    <p class="mt-2 rs-muted">{{ __('Jalur ini belum punya catatan versi file.') }}</p>
                @endif
            </div>
            <div class="lg:col-span-5 mt-6 lg:mt-0 pt-5 lg:pt-0 border-t lg:border-t-0 lg:border-l rs-rule lg:pl-8 flex flex-col justify-center gap-4">
                <dl class="flex gap-8">
                    <div><dt class="text-sm rs-muted">{{ __('Jumlah versi') }}</dt><dd class="rs-display rs-num text-4xl leading-none mt-1">{{ $route->gpxVersions->count() }}</dd></div>
                    @if($active)
                    <div><dt class="text-sm rs-muted">{{ __('Diunggah') }}</dt><dd class="mt-2 font-semibold">{{ $active->created_at->translatedFormat('j M Y') }}</dd></div>
                    @endif
                </dl>
                @if($active)
                <a href="{{ route('routes.provenance.download-version', [$route, $active]) }}" class="rs-btn w-full sm:w-auto self-start">
                    <x-icon name="arrow-up" class="w-5 h-5 rotate-180" /> {{ __('Unduh GPX yang dipakai') }}
                </a>
                @endif
            </div>
        </section>

        <section class="mt-10" aria-labelledby="history-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="history-title" class="rs-display uppercase text-[clamp(2rem,7vw,3rem)] leading-none text-[#053b30]">{{ __('Semua versi') }}</h2>
                <p class="text-sm rs-muted">{{ __('Yang terbaru di paling atas.') }}</p>
            </div>

            @if($route->gpxVersions->isEmpty())
            <div class="mt-5 rs-card p-8 text-center">
                <p class="font-semibold">{{ __('Belum ada catatan versi untuk jalur ini.') }}</p>
                <p class="mt-1 rs-muted">{{ __('Catatan muncul setelah file GPX diunggah atau diganti.') }}</p>
            </div>
            @else
            {{-- A trail line down the left, one summit marker per version (landing motif). --}}
            <ol class="mt-6 relative border-l-2 border-dashed border-[#7c8c84] ml-2 sm:ml-3 space-y-6">
                @foreach($route->gpxVersions as $version)
                @php $s = $status($version->verification_status); @endphp
                <li class="relative pl-6 sm:pl-8">
                    <svg class="absolute -left-[9px] top-5 w-4 h-4" viewBox="0 0 26 22" aria-hidden="true"><path d="M13 2 L24 20 H2 Z" fill="{{ $version->is_active ? '#053b30' : '#fff' }}" stroke="#053b30" stroke-width="2.5" stroke-linejoin="round"/></svg>
                    <article class="rs-card p-5 sm:p-6 min-w-0">
                        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('Versi :number', ['number' => $version->version_number]) }}</h3>
                                    @if($version->is_active)<span class="rs-chip rs-chip-ok">{{ __('Dipakai sekarang') }}</span>@endif
                                    <span class="rs-chip {{ $s['class'] }}"><x-icon :name="$s['icon']" class="w-4 h-4" />{{ $s['label'] }}</span>
                                </div>
                                <p class="mt-2 text-sm rs-muted [overflow-wrap:anywhere]">
                                    {{ __('Diunggah :date', ['date' => $when($version->created_at)]) }}@if($version->uploader) {{ __('oleh :name', ['name' => $version->uploader->name]) }}@endif
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-2 shrink-0">
                                <a href="{{ route('routes.provenance.download-version', [$route, $version]) }}" class="rs-btn-ghost rs-btn-sm">{{ __('Unduh GPX') }}</a>
                                @if($isAdmin)
                                <form action="{{ route('routes.provenance.verify', [$route, $version]) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="rs-btn-ghost rs-btn-sm">{{ __('Periksa ulang') }}</button>
                                </form>
                                @unless($version->is_active)
                                <form action="{{ route('routes.provenance.restore', [$route, $version]) }}" method="POST" onsubmit="return confirm(@js(__('Pulihkan versi GPX ini sebagai versi aktif?')))">
                                    @csrf
                                    <button type="submit" class="rs-btn rs-btn-sm">{{ __('Pakai versi ini') }}</button>
                                </form>
                                @endunless
                                @endif
                            </div>
                        </div>

                        <dl class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4 text-[0.9375rem]">
                            <div class="min-w-0">
                                <dt class="text-sm font-semibold rs-muted">{{ __('Catatan perubahan') }}</dt>
                                <dd class="mt-1 [overflow-wrap:anywhere]">{{ $version->change_log ?: __('Tidak ada catatan perubahan.') }}</dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-sm font-semibold rs-muted">{{ __('Terakhir diperiksa') }}</dt>
                                <dd class="mt-1">{{ $when($version->verified_at) }}</dd>
                                <dd class="mt-1 text-sm rs-muted">{{ __($version->verification_status_explanation) }}</dd>
                            </div>
                            <div class="min-w-0 md:col-span-2">
                                <dt class="text-sm font-semibold rs-muted">{{ __('Sidik file (SHA-256)') }}</dt>
                                <dd class="mt-1"><code class="block text-xs sm:text-sm text-[#14231d] bg-[#faf9f4] border rs-rule rounded-md px-3 py-2 break-all">{{ $version->file_hash }}</code></dd>
                            </div>
                            <div class="min-w-0 md:col-span-2">
                                <dt class="text-sm font-semibold rs-muted">{{ __('Lokasi file') }}</dt>
                                <dd class="mt-1 text-sm break-all">{{ $version->gpx_file_path }}</dd>
                            </div>
                        </dl>

                        @if($version->verificationLogs->isNotEmpty())
                        <details class="mt-5 border-t rs-rule pt-3 group">
                            <summary class="flex items-center gap-2 min-h-[44px] cursor-pointer font-semibold text-[#047857] list-none [&::-webkit-details-marker]:hidden">
                                <x-icon name="chevron-right" class="w-4 h-4 transition-transform group-open:rotate-90" />
                                {{ __('Catatan pemeriksaan (:count)', ['count' => $version->verificationLogs->count()]) }}
                            </summary>
                            <ul class="mt-2 divide-y divide-[#ece9de] text-sm">
                                @foreach($version->verificationLogs as $log)
                                @php $ls = $status($log->status); @endphp
                                <li class="py-3 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-1 sm:gap-4">
                                    <div class="min-w-0">
                                        <span class="rs-chip {{ $ls['class'] }}">{{ $ls['label'] }}</span>
                                        <span class="ml-1 rs-muted [overflow-wrap:anywhere]">{{ $log->message }}</span>
                                    </div>
                                    <span class="shrink-0 rs-muted">{{ $when($log->verified_at) }}</span>
                                </li>
                                @endforeach
                            </ul>
                        </details>
                        @endif
                    </article>
                </li>
                @endforeach
            </ol>
            @endif
        </section>
    </div>
</div>
@endsection
