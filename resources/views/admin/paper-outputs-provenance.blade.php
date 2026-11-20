@extends('layouts.app')

@section('title', __('Tabel paper provenance GPX'))

@php
    $isEn = app()->getLocale() === 'en';
    $n = fn ($v, int $d = 0) => number_format((float) $v, $d, $isEn ? '.' : ',', $isEn ? ',' : '.');
    $counts = [
        [__('versi GPX'), $n($totalVersions), null],
        [__('log pemeriksaan'), $n($totalVerificationLogs), null],
        [__('baris integritas'), $n($integrityRows), null],
        [__('baris pemulihan versi'), $n($recoveryRows), null],
        [__('skenario black box'), $n($blackBoxRows), null],
        [__('jawaban SUS'), $n($susRows), __('rata-rata skor :score', ['score' => $n($averageSusScore, 2)])],
        [__('query evaluasi rekomendasi'), $n($recommendationQueryRows), 'P@5 ' . number_format($averagePrecisionAt5, 4) . ' · P@10 ' . number_format($averagePrecisionAt10, 4)],
    ];
    $tables = [
        'before-after' => __('Tabel Before vs After Provenance'),
        'snapshots' => __('Evaluation Results Snapshots'),
        'integrity' => __('Tabel Integrity Verification'),
        'black-box' => __('Tabel Black Box Testing'),
        'sus' => __('Tabel SUS Usability Score'),
        'precision' => __('Tabel Precision@5 dan Precision@10'),
    ];
    $statusChip = fn (?string $s) => match (strtolower((string) $s)) {
        'verified', 'pass' => 'rs-chip-ok',
        'invalid', 'fail', 'failed' => 'rs-chip-bad',
        default => 'rs-chip-warn',
    };
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => ['href' => route('admin.evaluation.provenance'), 'label' => __('Kembali ke evaluasi provenance')],
        'eyebrow' => __('Tabel untuk naskah'),
        'title' => __('Tabel paper provenance GPX'),
        'lead' => __('Pratinjau tabel siap salin untuk bab hasil dan pembahasan, lengkap dengan unduhan CSV mentahnya.'),
        'width' => 'max-w-7xl',
        'seed' => 113,
    ])

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-8 sm:pt-10">
        <section aria-label="{{ __('Jumlah data') }}">
            <dl class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-px bg-[#ece9de] rs-card overflow-hidden">
                @foreach($counts as [$label, $value, $note])
                <div class="flex flex-col-reverse justify-end bg-white p-4 min-w-0">
                    <dt class="mt-1 text-sm rs-muted">{{ $label }}@if($note)<span class="block text-xs mt-0.5 [overflow-wrap:anywhere]">{{ $note }}</span>@endif</dt>
                    <dd class="rs-display rs-num text-3xl leading-none text-[#053b30]">{{ $value }}</dd>
                </div>
                @endforeach
            </dl>
        </section>

        {{-- Toolbar: snapshot + print, and a jump list for this long page. --}}
        <div class="mt-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4 print:hidden">
            <nav aria-label="{{ __('Lompat ke tabel') }}">
                <ul class="flex flex-wrap gap-2">
                    @foreach($tables as $id => $label)
                    <li><a href="#{{ $id }}" class="inline-flex items-center min-h-[44px] px-3 rounded-lg border rs-rule bg-white text-sm font-semibold hover:border-[#7c8c84]">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </nav>
            <div class="flex flex-wrap gap-2 shrink-0">
                <form method="POST" action="{{ route('admin.paper-outputs.provenance.capture-evaluation-snapshot') }}">
                    @csrf
                    <button type="submit" class="rs-sign rs-btn-sm">{{ __('Simpan snapshot evaluasi') }}</button>
                </form>
                <button type="button" onclick="window.print()" class="rs-btn-ghost rs-btn-sm">{{ __('Cetak tabel') }}</button>
            </div>
        </div>

        {{-- 1. Before vs after --}}
        <section id="before-after" class="mt-10 scroll-mt-24" aria-labelledby="before-after-title">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 id="before-after-title" class="rs-display uppercase text-2xl sm:text-3xl leading-none text-[#053b30]">{{ $tables['before-after'] }}</h2>
                <a href="{{ route('admin.paper-outputs.provenance.export-before-after') }}" class="rs-btn-ghost rs-btn-sm print:hidden">{{ __('Unduh CSV') }}</a>
            </div>
            <div class="mt-3 rs-card overflow-hidden"><div class="rs-table-wrap">
                <table class="rs-table">
                    <thead><tr><th>{{ __('Aspek') }}</th><th>{{ __('Sebelum') }}</th><th>{{ __('Sesudah') }}</th><th>{{ __('Kontribusi') }}</th></tr></thead>
                    <tbody>
                        @foreach($beforeAfterRows as $row)
                        <tr><td class="font-semibold min-w-[9rem]">{{ $row['aspect'] }}</td><td class="min-w-[12rem]">{{ $row['before'] }}</td><td class="min-w-[12rem]">{{ $row['after'] }}</td><td class="min-w-[12rem]">{{ $row['contribution'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div></div>
        </section>

        {{-- 2. Evaluation snapshots --}}
        <section id="snapshots" class="mt-10 scroll-mt-24" aria-labelledby="snapshots-title">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 id="snapshots-title" class="rs-display uppercase text-2xl sm:text-3xl leading-none text-[#053b30]">{{ $tables['snapshots'] }}</h2>
                <a href="{{ route('admin.paper-outputs.provenance.export-evaluation-results') }}" class="rs-btn-ghost rs-btn-sm print:hidden">{{ __('Unduh CSV') }}</a>
            </div>
            <div class="mt-3 rs-card overflow-hidden"><div class="rs-table-wrap">
                <table class="rs-table">
                    <thead><tr><th>{{ __('Metrik') }}</th><th>{{ __('Nilai') }}</th><th>{{ __('Ukuran dataset') }}</th><th>{{ __('Disimpan') }}</th></tr></thead>
                    <tbody>
                        @forelse($latestEvaluationResults as $result)
                        <tr><td class="font-semibold min-w-[12rem]">{{ $result->metric_name }}</td><td class="rs-num">{{ number_format($result->metric_value, 4) }}</td><td class="rs-num">{{ $result->dataset_size }}</td><td class="whitespace-nowrap rs-muted">{{ $result->captured_at?->translatedFormat('j M Y, H:i') }}</td></tr>
                        @empty
                        <tr><td colspan="4" class="text-center py-6 rs-muted">{{ __('Belum ada snapshot. Tekan "Simpan snapshot evaluasi" di atas.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div></div>
        </section>

        {{-- 3. Integrity --}}
        <section id="integrity" class="mt-10 scroll-mt-24" aria-labelledby="integrity-title">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 id="integrity-title" class="rs-display uppercase text-2xl sm:text-3xl leading-none text-[#053b30]">{{ $tables['integrity'] }}</h2>
                <a href="{{ route('admin.paper-outputs.provenance.export-integrity') }}" class="rs-btn-ghost rs-btn-sm print:hidden">{{ __('Unduh CSV') }}</a>
            </div>
            <div class="mt-3 rs-card overflow-hidden"><div class="rs-table-wrap">
                <table class="rs-table">
                    <thead><tr><th>{{ __('Jalur') }}</th><th>{{ __('Versi') }}</th><th>{{ __('Sumber') }}</th><th>{{ __('Status') }}</th><th>{{ __('Waktu') }}</th></tr></thead>
                    <tbody>
                        @forelse($finalIntegrityRows as $log)
                        <tr><td class="min-w-[11rem]">{{ $log->gpxVersion?->route?->name }}</td><td class="rs-num">v{{ $log->gpxVersion?->version_number }}</td><td>{{ strtoupper($log->source) }}</td><td><span class="rs-chip {{ $statusChip($log->status) }}">{{ strtoupper($log->status) }}</span></td><td class="whitespace-nowrap rs-muted">{{ $log->verified_at?->translatedFormat('j M Y, H:i') }}</td></tr>
                        @empty
                        <tr><td colspan="5" class="text-center py-6 rs-muted">{{ __('Belum ada log integritas.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div></div>
        </section>

        {{-- 4. Black box --}}
        <section id="black-box" class="mt-10 scroll-mt-24" aria-labelledby="black-box-title">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 id="black-box-title" class="rs-display uppercase text-2xl sm:text-3xl leading-none text-[#053b30]">{{ $tables['black-box'] }}</h2>
                <a href="{{ route('admin.paper-outputs.provenance.export-black-box-testing') }}" class="rs-btn-ghost rs-btn-sm print:hidden">{{ __('Unduh CSV') }}</a>
            </div>
            <div class="mt-3 rs-card overflow-hidden"><div class="rs-table-wrap">
                <table class="rs-table">
                    <thead><tr><th>ID</th><th>{{ __('Fitur') }}</th><th>{{ __('Skenario') }}</th><th>{{ __('Harapan') }}</th><th>{{ __('Hasil') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                        @forelse($finalBlackBoxRows as $row)
                        <tr><td class="font-semibold whitespace-nowrap">{{ $row->test_case_id }}</td><td class="min-w-[9rem]">{{ $row->feature }}</td><td class="min-w-[12rem]">{{ $row->scenario }}</td><td class="min-w-[12rem]">{{ $row->expected_result }}</td><td class="min-w-[12rem]">{{ $row->actual_result }}</td><td><span class="rs-chip {{ $statusChip($row->status) }}">{{ strtoupper($row->status) }}</span></td></tr>
                        @empty
                        <tr><td colspan="6" class="text-center py-6 rs-muted">{{ __('Belum ada data black box testing.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div></div>
        </section>

        {{-- 5. SUS --}}
        <section id="sus" class="mt-10 scroll-mt-24" aria-labelledby="sus-title">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 id="sus-title" class="rs-display uppercase text-2xl sm:text-3xl leading-none text-[#053b30]">{{ $tables['sus'] }}</h2>
                <div class="flex flex-wrap gap-2 print:hidden">
                    <a href="{{ route('evaluation.sus.create') }}" class="rs-btn-ghost rs-btn-sm">{{ __('Buka form SUS') }}</a>
                    <a href="{{ route('admin.paper-outputs.provenance.export-sus-responses') }}" class="rs-btn-ghost rs-btn-sm">{{ __('Unduh CSV') }}</a>
                </div>
            </div>
            <div class="mt-3 rs-card overflow-hidden"><div class="rs-table-wrap">
                <table class="rs-table">
                    <thead><tr><th>{{ __('Responden') }}</th><th>{{ __('Peran') }}</th><th>{{ __('Skor') }}</th><th>{{ __('Dikirim') }}</th><th>{{ __('Catatan') }}</th></tr></thead>
                    <tbody>
                        @forelse($finalSusRows as $response)
                        <tr><td class="min-w-[9rem]">{{ $response->respondent_name ?: __('Anonim') }}</td><td>{{ $response->respondent_role ?: '-' }}</td><td class="rs-num font-semibold">{{ number_format($response->score, 2) }}</td><td class="whitespace-nowrap rs-muted">{{ $response->submitted_at?->translatedFormat('j M Y, H:i') }}</td><td class="min-w-[12rem] [overflow-wrap:anywhere]">{{ $response->notes ?: '-' }}</td></tr>
                        @empty
                        <tr><td colspan="5" class="text-center py-6 rs-muted">{{ __('Belum ada jawaban SUS.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div></div>
        </section>

        {{-- 6. Precision@K --}}
        <section id="precision" class="mt-10 scroll-mt-24" aria-labelledby="precision-title">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 id="precision-title" class="rs-display uppercase text-2xl sm:text-3xl leading-none text-[#053b30]">{{ $tables['precision'] }}</h2>
                <a href="{{ route('admin.paper-outputs.provenance.export-recommendation-precision') }}" class="rs-btn-ghost rs-btn-sm print:hidden">{{ __('Unduh CSV') }}</a>
            </div>
            <div class="mt-3 rs-card overflow-hidden"><div class="rs-table-wrap">
                <table class="rs-table">
                    <thead><tr><th>Query</th><th>{{ __('ID jalur relevan') }}</th><th>{{ __('5 hasil teratas') }}</th><th>P@5</th><th>P@10</th></tr></thead>
                    <tbody>
                        @forelse($finalRecommendationRows as $row)
                        <tr><td class="min-w-[10rem]">{{ $row['query'] }}</td><td><code class="text-xs [overflow-wrap:anywhere]">{{ implode(', ', $row['relevant_route_ids']) }}</code></td><td class="min-w-[14rem]">{{ implode(', ', array_slice($row['top_5_route_names'], 0, 5)) }}</td><td class="rs-num font-semibold">{{ number_format($row['precision_at_5'], 4) }}</td><td class="rs-num font-semibold">{{ number_format($row['precision_at_10'], 4) }}</td></tr>
                        @empty
                        <tr><td colspan="5" class="text-center py-6 rs-muted">{{ __('Belum ada query evaluasi rekomendasi. Pastikan jalur sudah punya embedding SBERT.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div></div>
        </section>

        {{-- CSVs without a preview table here --}}
        <section class="mt-12 lg:grid lg:grid-cols-12 lg:gap-8 print:hidden" aria-labelledby="more-title">
            <div class="lg:col-span-7">
                <h2 id="more-title" class="rs-display uppercase text-2xl sm:text-3xl leading-none text-[#053b30]">{{ __('Unduhan lain') }}</h2>
                <ul class="mt-3 rs-card divide-y divide-[#ece9de]">
                    @foreach([
                        [route('admin.evaluation.provenance.export-versions'), __('Tabel versi GPX'), __('Versi per jalur, status aktif, hash, pengunggah, catatan perubahan.')],
                        [route('admin.paper-outputs.provenance.export-version-recovery'), __('Tabel pemulihan versi'), __('Bukti bahwa memulihkan versi lama mengganti versi aktif.')],
                        [route('admin.evaluation.provenance.export-summary'), __('Ringkasan evaluasi'), __('Hash Verification Accuracy dan Version Recovery Accuracy.')],
                    ] as [$href, $label, $note])
                    <li>
                        <a href="{{ $href }}" class="flex items-center gap-4 px-5 py-3.5 hover:bg-[#faf9f4] min-w-0">
                            <x-icon name="document-text" class="w-5 h-5 text-[#047857] shrink-0" />
                            <span class="min-w-0"><span class="block font-semibold">{{ $label }} (CSV)</span><span class="block text-sm rs-muted">{{ $note }}</span></span>
                        </a>
                    </li>
                    @endforeach
                </ul>
            </div>
            <div class="lg:col-span-5 mt-8 lg:mt-0 border-l-4 border-[#047857] pl-5 self-start">
                <h2 class="font-semibold">{{ __('Log pemeriksaan per jalur') }}</h2>
                <p class="mt-1 rs-muted">{{ __('Buka halaman riwayat file GPX sebuah jalur, lalu unduh log pemeriksaannya dari sana.') }}</p>
                @if($sampleRouteId)
                <a href="{{ route('routes.provenance', $sampleRouteId) }}" class="mt-2 inline-flex items-center min-h-[44px] font-semibold text-[#047857] underline decoration-2 underline-offset-4">{{ __('Buka contoh: jalur #:id', ['id' => $sampleRouteId]) }}</a>
                @else
                <p class="mt-2 text-sm rs-muted">{{ __('Belum ada jalur dengan riwayat file.') }}</p>
                @endif
            </div>
        </section>
    </div>
</div>
@endsection
