@extends('layouts.app')

@section('title', __('Aktivitas') . ' | ' . __('Panel admin'))

@php
    $isEn = app()->getLocale() === 'en';
    $n = fn ($v) => number_format((float) $v, 0, $isEn ? '.' : ',', $isEn ? ',' : '.');
    $dayLabel = fn (string $d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('j M');
    $topMax = max(array_column($top, 'total') ?: [1]);
    // Small-multiple bar charts: one series each, shared 30-day x axis, own y scale (no dual axis).
    $W = 300; $H = 72; $slot = $W / count($days); $bar = $slot - 2;
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'eyebrow' => __('Panel admin'),
        'title' => __('Apa yang terjadi 30 hari terakhir.'),
        'lead' => __(':from sampai :to. Satu batang satu hari; arahkan kursor ke batang untuk angkanya, atau buka tabel di bawah grafik.', ['from' => $start->translatedFormat('j M Y'), 'to' => $today->translatedFormat('j M Y')]),
        'width' => 'max-w-7xl',
        'seed' => 59,
        'tabs' => \App\Support\AdminNav::tabs('activity'),
    ])

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <section class="mt-8" aria-labelledby="daily-title">
            <h2 id="daily-title" class="sr-only">{{ __('Per hari, 30 hari terakhir') }}</h2>
            <ul class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
                @foreach($series as $s)
                <li class="rs-card p-4 sm:p-5 min-w-0">
                    <div class="flex items-baseline justify-between gap-3">
                        <h3 class="font-semibold">{{ $s['label'] }} <span class="block text-xs font-normal rs-muted">{{ $s['note'] }}</span></h3>
                        <p class="text-right shrink-0">
                            <span class="rs-display rs-num text-4xl leading-none text-[#053b30]">{{ $n($s['total']) }}</span>
                            <span class="block text-xs rs-muted">{{ __('dalam 30 hari') }}</span>
                        </p>
                    </div>
                    @if($s['total'] > 0)
                    <svg viewBox="0 0 {{ $W }} {{ $H + 2 }}" class="mt-4 w-full h-auto block" role="img"
                         aria-label="{{ __(':label: :total dalam 30 hari, paling banyak :max dalam sehari.', ['label' => $s['label'], 'total' => $n($s['total']), 'max' => $n($s['max'])]) }}">
                        <line x1="0" x2="{{ $W }}" y1="1" y2="1" stroke="#dcd8c9" stroke-width="1" stroke-dasharray="3 3" />
                        <line x1="0" x2="{{ $W }}" y1="{{ $H + 1 }}" y2="{{ $H + 1 }}" stroke="#7c8c84" stroke-width="1" />
                        @foreach($s['counts'] as $i => $c)
                        @php $h = $c > 0 ? max(2, round($c / $s['max'] * ($H - 2), 1)) : 0; @endphp
                        <g>
                            <title>{{ $dayLabel($days[$i]) }}: {{ $n($c) }}</title>
                            <rect x="{{ round($i * $slot, 2) }}" y="0" width="{{ round($slot, 2) }}" height="{{ $H + 2 }}" fill="transparent" />
                            @if($h > 0)
                            <rect x="{{ round($i * $slot + 1, 2) }}" y="{{ $H + 1 - $h }}" width="{{ round($bar, 2) }}" height="{{ $h }}" rx="1.5" fill="#047857" />
                            @endif
                        </g>
                        @endforeach
                    </svg>
                    <div class="mt-1 flex justify-between text-xs rs-muted rs-num">
                        <span>{{ $dayLabel($days->first()) }}</span>
                        <span>{{ __('maks. :max per hari', ['max' => $n($s['max'])]) }}</span>
                        <span>{{ __('hari ini') }}</span>
                    </div>
                    @else
                    <p class="mt-4 h-[4.5rem] flex items-center justify-center rounded-lg border border-dashed border-[#dcd8c9] text-sm rs-muted text-center px-3">{{ __('Belum ada dalam 30 hari terakhir.') }}</p>
                    @endif
                </li>
                @endforeach
            </ul>

            <details class="mt-4 rs-card overflow-hidden group">
                <summary class="flex items-center justify-between gap-3 min-h-[48px] px-5 cursor-pointer font-semibold text-[#053b30]">
                    <span>{{ __('Lihat angka per hari') }}</span>
                    <x-icon name="chevron-down" class="w-5 h-5 shrink-0 transition-transform group-open:rotate-180" />
                </summary>
                <div class="rs-table-wrap border-t rs-rule max-h-[28rem] overflow-y-auto">
                    <table class="rs-table">
                        <thead><tr><th>{{ __('Tanggal') }}</th>@foreach($series as $s)<th class="text-right">{{ $s['label'] }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach($days->reverse() as $i => $d)
                            <tr>
                                <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($d)->translatedFormat('D, j M') }}</td>
                                @foreach($series as $s)<td class="rs-num text-right">{{ $n($s['counts'][$i]) }}</td>@endforeach
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </section>

        <div class="mt-12 lg:grid lg:grid-cols-12 lg:gap-8">
            <section class="lg:col-span-5 min-w-0" aria-labelledby="top-title">
                <h2 id="top-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Paling sering dicari') }}</h2>
                <p class="mt-2 text-sm rs-muted">{{ __('Kalimat pencarian 30 hari terakhir, huruf kecil dan spasi dirapikan. Tamu dihitung per hari tanpa alamat IP.') }}</p>
                <div class="mt-4 rs-card overflow-hidden">
                    @if(empty($top))
                    <p class="px-5 py-6 text-sm rs-muted">{{ __('Belum ada pencarian dalam 30 hari terakhir.') }}</p>
                    @else
                    <ol class="divide-y divide-[#ece9de]">
                        @foreach($top as $row)
                        <li class="px-4 sm:px-5 py-3 min-w-0">
                            <div class="flex items-baseline justify-between gap-3">
                                <p class="min-w-0 font-semibold [overflow-wrap:anywhere]"><span class="rs-num rs-muted font-normal mr-1">{{ $loop->iteration }}.</span> {{ $row['query'] }}</p>
                                <p class="rs-num font-semibold shrink-0">{{ $n($row['total']) }}</p>
                            </div>
                            <div class="mt-1.5 h-2 rounded-sm bg-[#ece9de] overflow-hidden" aria-hidden="true"><div class="h-full bg-[#047857] rounded-sm" style="width: {{ round($row['total'] / $topMax * 100, 1) }}%"></div></div>
                            <p class="mt-1 text-xs rs-muted">{{ __(':users dari pengguna, :guests dari tamu', ['users' => $n($row['users']), 'guests' => $n($row['guests'])]) }}</p>
                        </li>
                        @endforeach
                    </ol>
                    @endif
                </div>
            </section>

            <section class="lg:col-span-7 mt-12 lg:mt-0 min-w-0" aria-labelledby="feed-title">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <h2 id="feed-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Baru saja') }}</h2>
                    <a href="{{ route('admin.logs') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-[#047857] hover:text-[#053b30]">{{ __('Semua catatan di log') }}</a>
                </div>
                <p class="mt-2 text-sm rs-muted">{{ __('50 catatan terakhir.') }}</p>
                <div class="mt-4 rs-card overflow-hidden">
                    @include('admin.partials.feed', ['entries' => $feed, 'empty' => __('Belum ada catatan. Catatan muncul begitu ada yang masuk, mengunggah, atau mengulas.')])
                </div>
            </section>
        </div>
    </div>
</div>
@endsection
