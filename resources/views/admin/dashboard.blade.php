@extends('layouts.app')

@section('title', __('Panel admin'))

@php
    $isEn = app()->getLocale() === 'en';
    $n = fn ($v, int $d = 0) => number_format((float) $v, $d, $isEn ? '.' : ',', $isEn ? ',' : '.');
    $integrityTotal = max(array_sum($chartStats['integrity']), 1);
    $integrity = [
        ['key' => 'verified', 'label' => __('Hash cocok'), 'value' => $chartStats['integrity']['verified'], 'bar' => '#047857', 'chip' => 'rs-chip-ok'],
        ['key' => 'invalid', 'label' => __('Isi berubah'), 'value' => $chartStats['integrity']['invalid'], 'bar' => '#b91c1c', 'chip' => 'rs-chip-bad'],
        ['key' => 'failed', 'label' => __('Gagal diperiksa'), 'value' => $chartStats['integrity']['failed'], 'bar' => '#b45309', 'chip' => 'rs-chip-warn'],
    ];
    $latestRoute = $routes->count() > 0 ? $routes->first() : null;
    $toolGroups = [
        __('Dataset') => array_values(array_filter([
            ['href' => route('admin.export.csv'), 'label' => __('Unduh dataset lengkap (CSV)'), 'note' => __('Semua kolom, termasuk metadata dan embedding.'), 'icon' => 'document-text'],
            ['href' => route('admin.export.embeddings'), 'label' => __('Unduh embedding saja (CSV)'), 'note' => __('Satu baris per jalur, 384 kolom dimensi.'), 'icon' => 'chip'],
            ['href' => route('routes.create'), 'label' => __('Upload satu GPX'), 'note' => __('Tambah jalur, hash, dan versi pertama.'), 'icon' => 'document-add'],
            ['href' => route('routes.batch'), 'label' => __('Upload GPX sekaligus'), 'note' => __('Banyak file dalam satu kali kirim.'), 'icon' => 'upload'],
            ['href' => route('routes.index'), 'label' => __('Daftar jalur publik'), 'note' => __('Lihat jalur seperti pengunjung melihatnya.'), 'icon' => 'map'],
        ])),
        __('Penelitian') => array_values(array_filter([
            ['href' => route('admin.evaluation.provenance'), 'label' => __('Evaluasi provenance GPX'), 'note' => __('Ringkasan versi, verifikasi hash, dan pemulihan.'), 'icon' => 'shield-check'],
            ['href' => route('admin.paper-outputs.provenance'), 'label' => __('Tabel untuk naskah'), 'note' => __('Pratinjau tabel dan unduhan CSV.'), 'icon' => 'clipboard-list'],
            ['href' => route('admin.paper-readiness'), 'label' => __('Cek kesiapan dataset'), 'note' => __(':passed dari :total kriteria terpenuhi.', ['passed' => $paperReadiness['passed'], 'total' => $paperReadiness['total']]), 'icon' => 'check-badge'],
            ['href' => route('evaluation.sus.create'), 'label' => __('Kuesioner SUS'), 'note' => __('Form kegunaan untuk responden.'), 'icon' => 'users'],
            $latestRoute ? ['href' => route('routes.provenance', $latestRoute), 'label' => __('Contoh riwayat file GPX'), 'note' => \Illuminate\Support\Str::limit($latestRoute->name, 40), 'icon' => 'clock'] : null,
        ])),
    ];
    $downloads = [
        ['href' => route('admin.evaluation.provenance.export-versions'), 'label' => __('Versi GPX')],
        ['href' => route('admin.paper-outputs.provenance.export-integrity'), 'label' => __('Integritas')],
        ['href' => route('admin.paper-outputs.provenance.export-version-recovery'), 'label' => __('Pemulihan versi')],
        ['href' => route('admin.evaluation.provenance.export-summary'), 'label' => __('Ringkasan evaluasi')],
        ['href' => route('admin.paper-outputs.provenance.export-black-box-testing'), 'label' => __('Black box')],
        ['href' => route('admin.paper-outputs.provenance.export-sus-responses'), 'label' => __('Jawaban SUS')],
        ['href' => route('admin.paper-readiness.export'), 'label' => __('Kesiapan dataset')],
    ];
    $bulkStatus = fn ($s) => match ($s) {
        'completed' => ['label' => __('Selesai'), 'chip' => 'rs-chip-ok'],
        'completed_with_errors' => ['label' => __('Selesai, ada yang gagal'), 'chip' => 'rs-chip-warn'],
        'failed' => ['label' => __('Gagal'), 'chip' => 'rs-chip-bad'],
        'running' => ['label' => __('Berjalan'), 'chip' => 'rs-chip-plain'],
        default => ['label' => __('Antre'), 'chip' => 'rs-chip-plain'],
    };
    $embeddingData = $routes->getCollection()->mapWithKeys(fn ($r) => [$r->id => [
        'narrative' => $r->narrative_text,
        'embedding' => $r->sbert_embedding,
    ]]);
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'eyebrow' => __('Panel admin'),
        'title' => __('Dataset jalur dan alat penelitian.'),
        'lead' => __('Unduh data, periksa keaslian file GPX, dan siapkan tabel untuk naskah. Hanya admin yang melihat halaman ini.'),
        'actions' => [
            ['href' => route('admin.export.csv'), 'label' => __('Unduh dataset lengkap (CSV)'), 'style' => 'sign', 'icon' => 'document-text'],
            ['href' => route('routes.create'), 'label' => __('Upload GPX'), 'icon' => 'upload'],
        ],
        'width' => 'max-w-7xl',
        'seed' => 103,
        'tabs' => \App\Support\AdminNav::tabs('dashboard'),
    ])

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {{-- Dataset numbers, straight from the database. --}}
        <section class="rs-card -mt-5 sm:-mt-7 relative" aria-label="{{ __('Ringkasan dataset') }}">
            <dl class="grid grid-cols-2 lg:grid-cols-4 gap-px bg-[#ece9de] rounded-xl overflow-hidden">
                @foreach([
                    [__('jalur di dataset'), $n($stats['total_routes'])],
                    [__('km total panjang jalur'), $n($stats['total_distance'], 1)],
                    [__('m rata-rata total tanjakan'), $n($stats['avg_elevation'])],
                    [__('jalur sudah punya embedding'), $n($stats['routes_with_embedding'])],
                ] as [$label, $value])
                <div class="flex flex-col-reverse justify-end bg-white p-4 sm:p-6 min-w-0">
                    <dt class="mt-1 text-sm rs-muted">{{ $label }}</dt>
                    <dd class="rs-display rs-num text-4xl sm:text-5xl leading-none text-[#053b30] [overflow-wrap:anywhere]">{{ $value }}</dd>
                </div>
                @endforeach
            </dl>
        </section>

        {{-- Tools, grouped by what the admin is doing (not one tile per link). --}}
        <div class="mt-10 grid grid-cols-1 lg:grid-cols-2 gap-8">
            @foreach($toolGroups as $group => $tools)
            <section class="min-w-0" aria-labelledby="tools-{{ $loop->index }}">
                <h2 id="tools-{{ $loop->index }}" class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ $group }}</h2>
                <ul class="mt-3 rs-card divide-y divide-[#ece9de]">
                    @foreach($tools as $tool)
                    <li>
                        <a href="{{ $tool['href'] }}" class="flex items-center gap-4 px-4 sm:px-5 py-3.5 min-w-0 hover:bg-[#faf9f4]">
                            <x-icon :name="$tool['icon']" class="w-5 h-5 text-[#047857] shrink-0" />
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold text-[#053b30]">{{ $tool['label'] }}</span>
                                <span class="block text-sm rs-muted truncate">{{ $tool['note'] }}</span>
                            </span>
                            <x-icon name="chevron-right" class="w-4 h-4 text-[#7c8c84] shrink-0" />
                        </a>
                    </li>
                    @endforeach
                </ul>
            </section>
            @endforeach
        </div>

        {{-- GPX integrity --}}
        <section class="mt-12" aria-labelledby="integrity-title">
            <h2 id="integrity-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Keaslian file GPX') }}</h2>
            <p class="mt-2 rs-muted max-w-2xl">{{ __('Setiap versi file disimpan dengan hash SHA-256. Pemeriksaan menghitung ulang hash dan membandingkannya.') }}</p>

            <div class="mt-5 lg:grid lg:grid-cols-12 lg:gap-8">
                <div class="lg:col-span-7 rs-card p-5 sm:p-6 min-w-0">
                    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div><dt class="text-sm rs-muted">{{ __('Versi GPX') }}</dt><dd class="rs-display rs-num text-3xl">{{ $n($provenanceStats['total_versions']) }}</dd></div>
                        <div><dt class="text-sm rs-muted">{{ __('Log pemeriksaan') }}</dt><dd class="rs-display rs-num text-3xl">{{ $n($provenanceStats['verification_logs']) }}</dd></div>
                        <div><dt class="text-sm rs-muted">{{ __('Pemulihan versi') }}</dt><dd class="rs-display rs-num text-3xl">{{ $n($provenanceStats['restore_events']) }}</dd></div>
                        <div><dt class="text-sm rs-muted">{{ __('Isi berubah') }}</dt><dd class="rs-display rs-num text-3xl {{ $provenanceStats['invalid_versions'] ? 'text-[#b91c1c]' : '' }}">{{ $n($provenanceStats['invalid_versions']) }}</dd></div>
                    </dl>

                    <div class="mt-6 space-y-3" role="list" aria-label="{{ __('Status versi GPX') }}">
                        @foreach($integrity as $row)
                        <div role="listitem">
                            <div class="flex justify-between gap-3 text-sm">
                                <span class="rs-chip {{ $row['chip'] }}">{{ $row['label'] }}</span>
                                <span class="rs-num font-semibold">{{ $n($row['value']) }}</span>
                            </div>
                            <div class="mt-1.5 h-2.5 rounded-sm bg-[#ece9de] overflow-hidden"><div class="h-full" style="width: {{ round($row['value'] / $integrityTotal * 100, 1) }}%; background: {{ $row['bar'] }}"></div></div>
                        </div>
                        @endforeach
                    </div>
                </div>

                <div class="lg:col-span-5 mt-6 lg:mt-0 space-y-6 min-w-0">
                    <form action="{{ route('admin.bulk-provenance-action') }}" method="POST" class="rs-card p-5 sm:p-6"
                          onsubmit="return confirm(@js(__('Periksa ulang semua versi GPX yang aktif sekarang?')))">
                        @csrf
                        <input type="hidden" name="action" value="verify_active">
                        <h3 class="font-semibold">{{ __('Periksa ulang semua file aktif') }}</h3>
                        <p class="mt-1 text-sm rs-muted">{{ __('Berjalan di antrean Laravel supaya tidak timeout. Pastikan worker hidup:') }} <code class="text-xs bg-[#faf9f4] border rs-rule rounded px-1.5 py-0.5">php artisan queue:work</code></p>
                        <button type="submit" class="rs-btn rs-btn-sm mt-4">{{ __('Jalankan pemeriksaan') }}</button>
                    </form>

                    <div class="rs-card p-5 sm:p-6">
                        <h3 class="font-semibold">{{ __('Versi yang isinya berubah') }}</h3>
                        @if($issueRoutes['invalid']->isEmpty())
                        <p class="mt-1 text-sm rs-muted">{{ __('Tidak ada. Semua file yang diperiksa masih cocok.') }}</p>
                        @else
                        <ul class="mt-2 space-y-1">
                            @foreach($issueRoutes['invalid'] as $version)
                            <li class="min-w-0">
                                <a href="{{ route('routes.provenance', $version->route) }}" class="inline-flex min-h-[44px] items-center font-semibold text-[#047857] hover:text-[#053b30] [overflow-wrap:anywhere]">
                                    {{ \Illuminate\Support\Str::limit($version->route?->name, 40) }} v{{ $version->version_number }}
                                </a>
                            </li>
                            @endforeach
                        </ul>
                        <a href="{{ route('routes.index', ['provenance_filter' => 'invalid']) }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-[#047857]">{{ __('Lihat semua di daftar jalur') }}</a>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mt-6 rs-card overflow-hidden">
                <h3 class="px-5 pt-4 pb-3 font-semibold border-b rs-rule">{{ __('Pemeriksaan massal terakhir') }}</h3>
                @if($recentBulkActions->isEmpty())
                <p class="px-5 py-4 text-sm rs-muted">{{ __('Belum pernah dijalankan.') }}</p>
                @else
                <div class="rs-table-wrap">
                    <table class="rs-table">
                        <thead><tr><th>{{ __('Waktu') }}</th><th>{{ __('Status') }}</th><th>{{ __('Berhasil') }}</th><th>{{ __('Gagal') }}</th><th>{{ __('Pesan terakhir') }}</th></tr></thead>
                        <tbody>
                            @foreach($recentBulkActions as $bulk)
                            @php $bs = $bulkStatus($bulk->status); @endphp
                            <tr>
                                <td class="whitespace-nowrap">{{ $bulk->created_at?->translatedFormat('j M Y, H:i') }}</td>
                                <td><span class="rs-chip {{ $bs['chip'] }}">{{ $bs['label'] }}</span></td>
                                <td class="rs-num">{{ $bulk->processed_count }}</td>
                                <td class="rs-num">{{ $bulk->failed_count }}</td>
                                <td class="min-w-[12rem] rs-muted [overflow-wrap:anywhere]">{{ $bulk->last_error ?: '-' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </section>

        {{-- Readiness + CSV downloads --}}
        <section class="mt-12 lg:grid lg:grid-cols-12 lg:gap-8" aria-labelledby="ready-title">
            <div class="lg:col-span-7 rs-card p-5 sm:p-6">
                <h2 id="ready-title" class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('Dataset Readiness Checker') }}</h2>
                <p class="mt-2 rs-muted">{{ __(':passed dari :total kriteria terpenuhi.', ['passed' => $paperReadiness['passed'], 'total' => $paperReadiness['total']]) }}</p>
                <div class="mt-4 h-3 rounded-sm bg-[#ece9de] overflow-hidden"><div class="h-full bg-[#047857]" style="width: {{ $paperReadiness['score'] }}%"></div></div>
                <p class="mt-2 text-sm font-semibold">
                    <span class="rs-chip {{ $paperReadiness['status'] === 'ready' ? 'rs-chip-ok' : 'rs-chip-warn' }}">{{ $paperReadiness['status'] === 'ready' ? __('Siap') : __('Belum lengkap') }}</span>
                    <span class="ml-2 rs-num">{{ $n($paperReadiness['score'], 2) }}%</span>
                </p>
                <a href="{{ route('admin.paper-readiness') }}" class="rs-btn rs-btn-sm mt-4">{{ __('Buka daftar periksa') }}</a>
            </div>
            <div class="lg:col-span-5 mt-6 lg:mt-0">
                <h2 class="font-semibold">{{ __('Unduh tabel CSV') }}</h2>
                <ul class="mt-3 flex flex-wrap gap-2">
                    @foreach($downloads as $dl)
                    <li><a href="{{ $dl['href'] }}" class="rs-btn-ghost rs-btn-sm">{{ $dl['label'] }}</a></li>
                    @endforeach
                </ul>
            </div>
        </section>

        {{-- Routes table --}}
        <section class="mt-12" aria-labelledby="routes-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="routes-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Dataset jalur') }}</h2>
                <p class="text-sm rs-muted">{{ __(':count jalur', ['count' => $n($routes->total())]) }}</p>
            </div>

            <div class="mt-4 rs-card overflow-hidden">
                <div class="rs-table-wrap">
                    <table class="rs-table">
                        <thead>
                            <tr>
                                <th>ID</th><th>{{ __('Nama jalur') }}</th><th>{{ __('Jarak') }}</th><th>{{ __('Tanjakan') }}</th><th>{{ __('Estimasi') }}</th><th>{{ __('Kemiringan') }}</th><th>{{ __('Embedding') }}</th><th><span class="sr-only">{{ __('Aksi') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($routes as $route)
                            <tr>
                                <td class="rs-num rs-muted">{{ $route->id }}</td>
                                <td class="min-w-[12rem]">
                                    <span class="font-semibold">{{ \Illuminate\Support\Str::limit($route->name, 40) }}</span>
                                    <span class="block text-xs rs-muted">{{ $route->created_at->translatedFormat('j M Y') }}</span>
                                </td>
                                <td class="rs-num whitespace-nowrap">{{ $route->distance_km !== null ? $n($route->distance_km, 2) . ' km' : '-' }}</td>
                                <td class="rs-num whitespace-nowrap">{{ $route->elevation_gain_m !== null ? $n($route->elevation_gain_m) . ' m' : '-' }}</td>
                                <td class="rs-num whitespace-nowrap">{{ $route->naismith_duration_hour !== null ? $n($route->naismith_duration_hour, 1) . ' ' . __('jam') : '-' }}</td>
                                <td class="rs-num whitespace-nowrap">{{ $route->average_grade_pct !== null ? $n($route->average_grade_pct, 1) . '%' : '-' }}</td>
                                <td class="whitespace-nowrap">
                                    @if($route->sbert_embedding)
                                    <button type="button" class="inline-flex items-center gap-1 min-h-[44px] font-semibold text-[#047857] hover:text-[#053b30]"
                                            data-embedding-id="{{ $route->id }}" data-embedding-name="{{ $route->name }}">{{ __('Lihat 384 dimensi') }}</button>
                                    @else
                                    <span class="rs-chip rs-chip-plain">{{ __('Belum ada') }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap"><a href="{{ route('routes.show', $route) }}" class="inline-flex items-center min-h-[44px] font-semibold text-[#047857] hover:text-[#053b30]">{{ __('Buka') }}</a></td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="text-center py-10">
                                    <p class="font-semibold">{{ __('Belum ada data jalur.') }}</p>
                                    <a href="{{ route('routes.create') }}" class="rs-btn rs-btn-sm mt-3">{{ __('Upload GPX pertama') }}</a>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($routes->hasPages())
                <div class="px-5 py-4 border-t rs-rule">{{ $routes->links() }}</div>
                @endif
            </div>
        </section>
    </div>
</div>

{{-- Embedding viewer: native <dialog> closes with Escape and keeps focus inside. --}}
<dialog id="embeddingModal" class="w-[min(56rem,calc(100vw-2rem))] max-h-[85vh] p-0 rounded-xl border border-[#dcd8c9] backdrop:bg-black/50" aria-labelledby="modalTitle">
    <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-[#dcd8c9] bg-[#053b30] text-white">
        <div class="min-w-0">
            <h2 id="modalTitle" class="font-semibold">{{ __('Embedding SBERT') }}</h2>
            <p class="text-sm text-[#d1fae5] [overflow-wrap:anywhere]" id="modalRouteName">-</p>
        </div>
        <form method="dialog"><button class="w-11 h-11 inline-flex items-center justify-center rounded-lg hover:bg-white/10 focus-visible:outline focus-visible:outline-[3px] focus-visible:outline-white" aria-label="{{ __('Tutup') }}"><x-icon name="x" class="w-5 h-5" /></button></form>
    </div>
    <div class="p-5 overflow-y-auto max-h-[calc(85vh-5rem)] text-[#14231d]">
        <h3 class="font-semibold">{{ __('Kalimat ringkasan jalur (masukan SBERT)') }}</h3>
        <p class="mt-2 text-sm bg-[#faf9f4] border border-[#dcd8c9] rounded-lg p-4 [overflow-wrap:anywhere]" id="modalNarrative">-</p>
        <h3 class="mt-5 font-semibold">{{ __('Vektor embedding (384 dimensi)') }}</h3>
        <pre class="mt-2 text-xs bg-[#14231d] text-[#d1fae5] rounded-lg p-4 overflow-x-auto max-h-80"><code id="modalEmbedding">-</code></pre>
    </div>
</dialog>

<script>
(function () {
    const data = @json($embeddingData);
    const empty = { narrative: @js(__('Tidak ada kalimat ringkasan.')), embedding: @js(__('Tidak ada embedding.')) };
    const modal = document.getElementById('embeddingModal');
    document.querySelectorAll('[data-embedding-id]').forEach((btn) => btn.addEventListener('click', () => {
        const route = data[btn.dataset.embeddingId] || {};
        document.getElementById('modalRouteName').textContent = btn.dataset.embeddingName;
        document.getElementById('modalNarrative').textContent = route.narrative || empty.narrative;
        document.getElementById('modalEmbedding').textContent = Array.isArray(route.embedding)
            ? route.embedding.map((v, i) => `[${i}] ${Number(v).toFixed(6)}`).join('\n')
            : empty.embedding;
        modal.showModal();
    }));
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.close(); });
})();
</script>
@endsection
