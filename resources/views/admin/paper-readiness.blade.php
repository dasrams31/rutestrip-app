@extends('layouts.app')

@section('title', __('Dataset Readiness Checker'))

@php
    $isEn = app()->getLocale() === 'en';
    $ready = $paperReadiness['status'] === 'ready';
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => ['href' => route('admin.dashboard'), 'label' => __('Kembali ke panel admin')],
        'eyebrow' => __('Bukti untuk naskah'),
        'title' => __('Dataset Readiness Checker'),
        'lead' => __('Daftar periksa data sebelum tabel hasil dipakai: dataset jalur, SBERT, provenance GPX, Precision@K, black box, dan SUS.'),
        'actions' => [['href' => route('admin.paper-readiness.export'), 'label' => __('Download Readiness CSV'), 'style' => 'sign', 'icon' => 'document-text']],
        'seed' => 107,
    ])

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <section class="rs-card -mt-5 sm:-mt-7 relative" aria-label="{{ __('Ringkasan kesiapan') }}">
            <dl class="grid grid-cols-1 min-[420px]:grid-cols-3 gap-px bg-[#ece9de] rounded-xl overflow-hidden">
                <div class="flex flex-col-reverse justify-end bg-white p-4 sm:p-6">
                    <dt class="mt-2 text-sm rs-muted">{{ __('Status keseluruhan') }}</dt>
                    <dd><span class="rs-chip {{ $ready ? 'rs-chip-ok' : 'rs-chip-warn' }} text-base">{{ $ready ? __('Siap') : __('Belum lengkap') }}</span></dd>
                </div>
                <div class="flex flex-col-reverse justify-end bg-white p-4 sm:p-6">
                    <dt class="mt-1 text-sm rs-muted">{{ __('Skor') }}</dt>
                    <dd class="rs-display rs-num text-4xl leading-none text-[#053b30]">{{ number_format($paperReadiness['score'], 2, $isEn ? '.' : ',', $isEn ? ',' : '.') }}%</dd>
                </div>
                <div class="flex flex-col-reverse justify-end bg-white p-4 sm:p-6">
                    <dt class="mt-1 text-sm rs-muted">{{ __('Kriteria terpenuhi') }}</dt>
                    <dd class="rs-display rs-num text-4xl leading-none text-[#053b30]">{{ $paperReadiness['passed'] }}/{{ $paperReadiness['total'] }}</dd>
                </div>
            </dl>
        </section>

        <section class="mt-10" aria-labelledby="criteria-title">
            <h2 id="criteria-title" class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('Kriteria') }}</h2>
            <p class="mt-2 rs-muted">{{ __('Kerjakan yang masih TODO sebelum tabel hasil difinalkan.') }}</p>

            <ul class="mt-4 rs-card divide-y divide-[#ece9de]">
                @foreach($paperReadiness['items'] as $item)
                <li class="p-4 sm:px-6 sm:grid sm:grid-cols-12 sm:gap-6 min-w-0">
                    <div class="sm:col-span-5 min-w-0">
                        <p class="flex items-start gap-2">
                            <span class="rs-chip {{ $item['ready'] ? 'rs-chip-ok' : 'rs-chip-warn' }} shrink-0">{{ $item['ready'] ? 'READY' : 'TODO' }}</span>
                            <span class="font-semibold [overflow-wrap:anywhere]">{{ $item['name'] }}</span>
                        </p>
                    </div>
                    <div class="sm:col-span-3 mt-2 sm:mt-0 text-sm min-w-0 [overflow-wrap:anywhere]"><span class="sm:hidden rs-muted">{{ __('Sekarang') }}: </span>{{ $item['current'] }}</div>
                    <div class="sm:col-span-4 mt-1 sm:mt-0 text-sm rs-muted min-w-0 [overflow-wrap:anywhere]">{{ $item['notes'] }}</div>
                </li>
                @endforeach
            </ul>
        </section>

        <section class="mt-10 border-l-4 border-[#047857] pl-5 max-w-3xl">
            <h2 class="font-semibold">{{ __('Cara membaca daftar ini') }}</h2>
            <p class="mt-1 rs-muted leading-relaxed">{{ __('Daftar ini hanya memeriksa ketersediaan data di sistem. Metodologi tetap perlu dijelaskan di naskah: sumber dataset, definisi ground truth, skenario black box, profil responden SUS, dan batasan eksperimen.') }}</p>
        </section>
    </div>
</div>
@endsection
