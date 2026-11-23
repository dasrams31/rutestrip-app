@extends('layouts.app')

@section('title', __('Arsitektur Sistem'))
@section('meta_description', __('Cara RuteStrip menyarankan jalur pendakian: file GPX dibaca, diringkas jadi kalimat, lalu dicocokkan dengan permintaanmu memakai SBERT dan cosine similarity.'))

@php
    $paperUrl = 'https://sistemasi.ftik.unisi.ac.id/index.php/stmsi/article/view/6087';
    $steps = [
        [
            'title' => __('File GPX dibaca'),
            'text' => __('Dari titik-titik di file GPX kami hitung jarak, total tanjakan, kemiringan rata-rata, dan estimasi waktu dengan rumus Naismith. Koordinatnya dipakai untuk menggambar peta.'),
            'term' => __('Ekstraksi fitur GPX'),
        ],
        [
            'title' => __('Angka jadi kalimat'),
            'text' => __('Angka-angka itu ditulis ulang jadi ringkasan jalur dalam kalimat biasa, lalu digabung dengan cerita dari pengunggah: vegetasi, sumber air, pemandangan.'),
            'term' => __('Narasi jalur'),
        ],
        [
            'title' => __('Kalimat dirapikan'),
            'text' => __('Tautan dan karakter aneh dibuang, semua huruf jadi kecil, kata sambung yang tidak penting dibuang. Kata seperti "tidak" dan kata sifat tetap dipertahankan, dan kata tidak dipotong ke kata dasarnya.'),
            'term' => __('Cleaning, case folding, stopword selektif, tanpa stemming'),
        ],
        [
            'title' => __('Kalimat jadi deretan angka'),
            'text' => __('Setiap ringkasan diubah jadi 384 angka yang mewakili maknanya, jadi "landai" dan "tidak curam" bisa dianggap mirip walau hurufnya beda.'),
            'term' => 'SBERT · paraphrase-multilingual-MiniLM-L12-v2',
        ],
        [
            'title' => __('Dicocokkan dengan permintaanmu'),
            'text' => __('Kalimat yang kamu tulis diubah dengan cara yang sama, lalu dibandingkan dengan setiap jalur. Yang maknanya paling dekat tampil paling atas.'),
            'term' => 'Cosine similarity · Top-N',
        ],
    ];
    $stack = [
        ['name' => 'Laravel 12', 'role' => __('Situs, akun, dan penyimpanan data jalur')],
        ['name' => 'Python 3.11', 'role' => __('Membaca GPX dan membuat embedding')],
        ['name' => 'Sentence Transformers (SBERT)', 'role' => __('Model bahasa untuk mengukur kemiripan makna')],
        ['name' => 'Leaflet.js + OpenStreetMap', 'role' => __('Peta jalur interaktif')],
    ];
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'eyebrow' => __('Cara kerja RuteStrip'),
        'title' => __('Dari file GPX sampai saran jalur.'),
        'lead' => __('Setiap file GPX diringkas menjadi kalimat, lalu kalimat pencarianmu dicocokkan dengan ringkasan itu memakai model bahasa SBERT.'),
        'seed' => 97,
    ])

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-8 sm:pt-12 lg:grid lg:grid-cols-12 lg:gap-12">
        {{-- Steps on a winding trail line with summit markers, the landing's "Cara kerja" motif. --}}
        <section class="lg:col-span-7" aria-labelledby="steps-title">
            <h2 id="steps-title" class="rs-display uppercase text-[clamp(2rem,7vw,3rem)] leading-none text-[#053b30]">{{ __('Lima tahap') }}</h2>
            <ol class="mt-6 relative border-l-2 border-dashed border-[#7c8c84] ml-2 space-y-8">
                @foreach($steps as $step)
                <li class="relative pl-7 sm:pl-9">
                    <svg class="absolute -left-[10px] top-1 w-[18px] h-[18px]" viewBox="0 0 26 22" aria-hidden="true"><path d="M13 2 L24 20 H2 Z" fill="{{ $loop->last ? '#053b30' : '#fff' }}" stroke="#053b30" stroke-width="2.5" stroke-linejoin="round"/></svg>
                    <p class="text-sm font-semibold text-[#047857]">{{ __('Tahap :n', ['n' => $loop->iteration]) }}</p>
                    <h3 class="rs-display uppercase text-2xl sm:text-3xl leading-none mt-1">{{ $step['title'] }}</h3>
                    <p class="mt-2 max-w-xl leading-relaxed">{{ $step['text'] }}</p>
                    <p class="mt-2 text-sm rs-muted">{{ __('Istilah teknis:') }} <span class="font-semibold text-[#14231d] [overflow-wrap:anywhere]">{{ $step['term'] }}</span></p>
                </li>
                @endforeach
            </ol>
        </section>

        <aside class="lg:col-span-5 mt-12 lg:mt-0 space-y-10">
            <section class="rs-card p-5 sm:p-7" aria-labelledby="formula-title">
                <h2 id="formula-title" class="rs-display uppercase text-2xl sm:text-3xl leading-none text-[#053b30]">{{ __('Rumus pencocokannya') }}</h2>
                <p class="mt-4 rounded-lg bg-[#053b30] text-white px-4 py-4 text-center font-semibold text-base sm:text-lg [overflow-wrap:anywhere]">
                    Sim(Q, D) = (Q · D) / (‖Q‖ × ‖D‖)
                </p>
                <dl class="mt-4 space-y-2 text-[0.9375rem]">
                    <div class="flex gap-3"><dt class="font-semibold w-6 shrink-0">Q</dt><dd>{{ __('deretan angka dari kalimat yang kamu tulis') }}</dd></div>
                    <div class="flex gap-3"><dt class="font-semibold w-6 shrink-0">D</dt><dd>{{ __('deretan angka dari ringkasan satu jalur') }}</dd></div>
                    <div class="flex gap-3"><dt class="font-semibold w-6 shrink-0">Sim</dt><dd>{{ __('dari -1 sampai 1; makin dekat ke 1, makin mirip maknanya') }}</dd></div>
                </dl>
            </section>

            <section aria-labelledby="stack-title">
                <h2 id="stack-title" class="rs-display uppercase text-2xl sm:text-3xl leading-none text-[#053b30]">{{ __('Dibangun dengan') }}</h2>
                <dl class="mt-4 rs-card divide-y divide-[#ece9de]">
                    @foreach($stack as $item)
                    <div class="px-5 py-3.5">
                        <dt class="font-semibold">{{ $item['name'] }}</dt>
                        <dd class="text-sm rs-muted">{{ $item['role'] }}</dd>
                    </div>
                    @endforeach
                </dl>
            </section>

            <section class="border-l-4 border-[#047857] pl-5" aria-labelledby="paper-title">
                <h2 id="paper-title" class="font-semibold">{{ __('Artikel jurnal') }}</h2>
                <p class="mt-1 rs-muted">{{ __('Metode ini ditulis lengkap di artikel jurnal Sistemasi.') }}</p>
                <a href="{{ $paperUrl }}" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-2 min-h-[44px] font-semibold text-[#047857] underline decoration-2 underline-offset-4 hover:text-[#053b30]">
                    {{ __('Baca artikelnya') }} <x-icon name="external-link" class="w-4 h-4" />
                </a>
            </section>
        </aside>
    </div>
</div>
@endsection
