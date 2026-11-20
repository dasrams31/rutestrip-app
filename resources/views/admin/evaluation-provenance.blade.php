@extends('layouts.app')

@section('title', __('Evaluasi provenance GPX'))

@php
    $chip = fn (?string $s) => match ($s) {
        'verified' => ['label' => __('Hash cocok'), 'class' => 'rs-chip-ok'],
        'invalid' => ['label' => __('Isi berubah'), 'class' => 'rs-chip-bad'],
        'verification_failed' => ['label' => __('Gagal diperiksa'), 'class' => 'rs-chip-warn'],
        default => ['label' => __('Belum diperiksa'), 'class' => 'rs-chip-plain'],
    };
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => ['href' => route('admin.dashboard'), 'label' => __('Kembali ke panel admin')],
        'eyebrow' => __('Modul evaluasi penelitian'),
        'title' => __('Evaluasi provenance GPX'),
        'lead' => __('Ringkasan pengelolaan versi dan pemeriksaan keaslian file GPX, untuk tabel hasil di naskah.'),
        'actions' => [
            ['href' => route('admin.evaluation.provenance.export-summary'), 'label' => __('Unduh ringkasan (CSV)'), 'style' => 'sign', 'icon' => 'document-text'],
            ['href' => route('admin.evaluation.provenance.export-versions'), 'label' => __('Unduh versi GPX (CSV)'), 'icon' => 'document-text'],
            ['href' => route('admin.paper-outputs.provenance'), 'label' => __('Tabel untuk naskah'), 'icon' => 'clipboard-list'],
        ],
        'width' => 'max-w-7xl',
        'seed' => 109,
    ])

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-8 sm:pt-10">
        <section aria-labelledby="metrics-title">
            <h2 id="metrics-title" class="sr-only">{{ __('Metrik') }}</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-px bg-[#ece9de] rs-card overflow-hidden">
                @foreach($metrics as $name => $metric)
                <div class="flex flex-col-reverse justify-end bg-white p-5 min-w-0">
                    <dt class="mt-2 min-w-0">
                        <span class="block font-semibold text-sm [overflow-wrap:anywhere]">{{ $name }}</span>
                        <span class="block mt-1 text-xs rs-muted leading-relaxed [overflow-wrap:anywhere]">{{ $metric['notes'] }}</span>
                    </dt>
                    <dd class="rs-display rs-num text-4xl leading-none text-[#053b30] [overflow-wrap:anywhere]">{{ $metric['value'] }}</dd>
                </div>
                @endforeach
            </dl>
        </section>

        <div class="mt-10 xl:grid xl:grid-cols-3 xl:gap-8">
            <section class="xl:col-span-2 min-w-0" aria-labelledby="versions-title">
                <h2 id="versions-title" class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('Daftar versi GPX') }}</h2>
                <p class="mt-1 text-sm rs-muted">{{ __('Data utama untuk tabel versi GPX.') }}</p>
                <div class="mt-4 rs-card overflow-hidden">
                    <div class="rs-table-wrap">
                        <table class="rs-table">
                            <thead><tr><th>{{ __('Jalur') }}</th><th>{{ __('Versi') }}</th><th>{{ __('Status') }}</th><th>{{ __('Aktif') }}</th><th>Hash</th><th>{{ __('Dibuat') }}</th></tr></thead>
                            <tbody>
                                @forelse($versions as $version)
                                @php $c = $chip($version->verification_status); @endphp
                                <tr>
                                    <td class="min-w-[11rem] font-semibold">
                                        @if($version->route)
                                        <a href="{{ route('routes.provenance', $version->route) }}" class="hover:text-[#047857] underline decoration-[#dcd8c9] underline-offset-4">{{ \Illuminate\Support\Str::limit($version->route->name, 36) }}</a>
                                        @else - @endif
                                    </td>
                                    <td class="rs-num">v{{ $version->version_number }}</td>
                                    <td class="whitespace-nowrap"><span class="rs-chip {{ $c['class'] }}">{{ $c['label'] }}</span></td>
                                    <td>{{ $version->is_active ? __('Ya') : __('Tidak') }}</td>
                                    <td><code class="text-xs">{{ $version->short_hash }}</code></td>
                                    <td class="whitespace-nowrap rs-muted">{{ $version->created_at->translatedFormat('j M Y, H:i') }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="6" class="text-center py-8 rs-muted">{{ __('Belum ada versi GPX.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($versions->hasPages())
                    <div class="px-5 py-4 border-t rs-rule">{{ $versions->links() }}</div>
                    @endif
                </div>
            </section>

            <section class="mt-10 xl:mt-0 min-w-0" aria-labelledby="logs-title">
                <h2 id="logs-title" class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('Pemeriksaan terbaru') }}</h2>
                <p class="mt-1 text-sm rs-muted">{{ __('Hasil hitung ulang hash yang paling baru.') }}</p>
                <ul class="mt-4 rs-card divide-y divide-[#ece9de]">
                    @forelse($recentLogs as $log)
                    @php $c = $chip($log->status); @endphp
                    <li class="p-4 min-w-0">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="rs-chip {{ $c['class'] }}">{{ $c['label'] }}</span>
                            <span class="text-xs rs-muted">{{ $log->verified_at?->translatedFormat('j M Y, H:i') }}</span>
                        </div>
                        <p class="mt-2 font-semibold text-sm [overflow-wrap:anywhere]">{{ \Illuminate\Support\Str::limit($log->gpxVersion?->route?->name, 40) }} <span class="rs-muted font-normal">v{{ $log->gpxVersion?->version_number }}</span></p>
                        <p class="mt-0.5 text-xs rs-muted [overflow-wrap:anywhere]">{{ $log->message }}</p>
                    </li>
                    @empty
                    <li class="p-6 text-center rs-muted">{{ __('Belum ada log pemeriksaan.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</div>
@endsection
