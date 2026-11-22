@extends('layouts.app')

@section('title', __('Kiriman jalurku'))

@php
    $isEn = app()->getLocale() === 'en';
    $num = fn ($value, int $decimals = 0) => number_format((float) $value, $decimals, $isEn ? '.' : ',', $isEn ? ',' : '.');
    // Every status carries a word, never colour alone (rs-chip).
    $statuses = [
        \App\Models\HikingRoute::PENDING => ['chip' => 'rs-chip-warn', 'label' => __('Menunggu admin'), 'icon' => 'clock'],
        \App\Models\HikingRoute::PUBLISHED => ['chip' => 'rs-chip-ok', 'label' => __('Tampil'), 'icon' => 'check'],
        \App\Models\HikingRoute::REJECTED => ['chip' => 'rs-chip-bad', 'label' => __('Ditolak'), 'icon' => 'x'],
    ];
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => ['href' => route('user.dashboard'), 'label' => __('Dashboard')],
        'title' => __('Kiriman jalurku'),
        'lead' => __('Jalur yang kamu unggah dari situs dan rekaman dari aplikasi Android, lengkap dengan status pemeriksaan admin.'),
        'actions' => [
            ['href' => route('routes.create'), 'label' => __('Upload GPX'), 'style' => 'sign', 'icon' => 'upload'],
        ],
        'width' => 'max-w-4xl',
        'seed' => 53,
    ])

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-10">
        @if($submissions->total() > 0)
        <ul class="rs-card divide-y divide-[#ece9de]" data-submissions>
            @foreach($submissions as $item)
            @php $status = $statuses[$item->review_status] ?? $statuses[\App\Models\HikingRoute::PENDING]; @endphp
            <li class="p-4 sm:px-6 sm:py-5" data-submission="{{ $item->review_status }}">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2 sm:gap-4">
                    <div class="min-w-0">
                        <h2 class="font-semibold text-lg text-[#053b30] break-words">{{ $item->name }}</h2>
                        <p class="mt-1 text-sm rs-muted flex flex-wrap gap-x-3 gap-y-1">
                            <span>{{ $item->source === 'recording' ? __('Rekaman aplikasi') : __('Upload GPX') }}</span>
                            <span>{{ __('Dikirim :date', ['date' => $item->created_at?->translatedFormat('d M Y')]) }}</span>
                            @if($item->distance_km)<span class="rs-num">{{ $num($item->distance_km, 1) }} km</span>@endif
                        </p>
                    </div>
                    <span class="rs-chip {{ $status['chip'] }} self-start shrink-0"><x-icon :name="$status['icon']" class="w-4 h-4" />{{ $status['label'] }}</span>
                </div>

                @if($item->review_status === \App\Models\HikingRoute::PUBLISHED)
                <a href="{{ route('routes.show', $item) }}" class="mt-2 inline-flex items-center gap-1.5 min-h-[44px] font-semibold text-[#047857] underline decoration-[#047857]/40 underline-offset-4 hover:text-[#053b30]">
                    {{ __('Buka halaman jalur') }}<span class="sr-only">: {{ $item->name }}</span>
                </a>
                @elseif($item->review_status === \App\Models\HikingRoute::REJECTED)
                <div class="mt-3 rounded-lg border border-[#fecaca] bg-[#fef2f2] px-3 py-2.5 text-sm text-[#7f1d1d]">
                    @if(filled($item->review_note))
                    <p><span class="font-semibold">{{ __('Catatan admin') }}:</span> <span class="break-words">{{ $item->review_note }}</span></p>
                    @else
                    <p>{{ __('Admin tidak menulis alasan penolakan.') }}</p>
                    @endif
                </div>
                @else
                <p class="mt-2 text-sm rs-muted">{{ __('Belum tampil di pencarian sampai admin memeriksanya.') }}</p>
                @endif
            </li>
            @endforeach
        </ul>
        <div class="mt-6">{{ $submissions->links() }}</div>
        @else
        <div class="rs-card p-6 sm:p-8">
            <h2 class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('Belum ada kiriman') }}</h2>
            <p class="mt-3 rs-muted max-w-prose">{{ __('Unggah file GPX dari pendakianmu, atau rekam jalur dengan aplikasi Android lalu kirim dari sana. Semuanya muncul di halaman ini.') }}</p>
            <div class="mt-5 flex flex-col min-[420px]:flex-row gap-3">
                <a href="{{ route('routes.create') }}" class="rs-btn">{{ __('Unggah file GPX') }}</a>
                <a href="https://app.rutestrip.ai.id" rel="noopener" class="rs-btn-ghost">{{ __('Aplikasi Android') }}</a>
            </div>
        </div>
        @endif

        <p class="mt-6 text-sm rs-muted max-w-prose">
            {{ __('Upload GPX langsung tampil. Rekaman dari aplikasi diperiksa admin dulu, lalu tampil di pencarian setelah disetujui.') }}
        </p>
    </div>
</div>
@endsection
