@extends('layouts.app')

@section('title', __('Log') . ' | ' . __('Panel admin'))

@php
    $isEn = app()->getLocale() === 'en';
    $n = fn ($v) => number_format((float) $v, 0, $isEn ? '.' : ',', $isEn ? ',' : '.');
    $flag = \App\Http\Controllers\Admin\AdminLogController::FAILED_FLAG;
    $tz = config('app.timezone');
    $levelChip = fn ($l) => in_array($l, ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR'], true) ? 'rs-chip-bad' : 'rs-chip-warn';
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'eyebrow' => __('Panel admin'),
        'title' => __('Catatan masuk, perubahan, dan galat server.'),
        'lead' => __('Saring catatan aktivitas, lihat percobaan masuk yang gagal, dan baca galat terakhir dari laravel.log tanpa membuka server. Waktu dalam :tz.', ['tz' => $tz]),
        'width' => 'max-w-7xl',
        'seed' => 83,
        'tabs' => \App\Support\AdminNav::tabs('logs'),
    ])

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {{-- Failed sign-ins first: the one thing on this page that may need action today. --}}
        <section class="mt-8" aria-labelledby="failed-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="failed-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Gagal masuk, 24 jam') }}</h2>
                <p class="text-sm rs-muted">{{ __(':count percobaan gagal', ['count' => $n($failed['total'])]) }}</p>
            </div>
            <p class="mt-2 text-sm rs-muted max-w-3xl">{{ __('Alamat IP disimpan tanpa angka terakhir. Baris dengan :flag kali atau lebih diberi tanda, karena itu lebih mirip tebak-tebakan password daripada salah ketik.', ['flag' => $flag]) }}</p>

            @if($failed['total'] === 0)
            <p class="mt-4 rs-card px-5 py-6 text-sm rs-muted">{{ __('Tidak ada percobaan masuk yang gagal dalam 24 jam terakhir.') }}</p>
            @else
            <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-6">
                @foreach([['byIp', __('Per alamat IP'), __('Alamat')], ['byEmail', __('Per email yang dicoba'), __('Email')]] as [$key, $title, $col])
                <div class="rs-card overflow-hidden min-w-0">
                    <h3 class="px-5 pt-4 pb-3 font-semibold border-b rs-rule">{{ $title }}</h3>
                    @if(empty($failed[$key]))
                    <p class="px-5 py-4 text-sm rs-muted">{{ __('Tidak ada data.') }}</p>
                    @else
                    <div class="rs-table-wrap">
                        <table class="rs-table">
                            <thead><tr><th>{{ $col }}</th><th class="text-right">{{ __('Kali') }}</th><th>{{ __('Terakhir') }}</th></tr></thead>
                            <tbody>
                                @foreach($failed[$key] as $row)
                                <tr>
                                    <td class="min-w-[10rem] [overflow-wrap:anywhere]">
                                        {{ $row['key'] }}
                                        @if($row['count'] >= $flag)<span class="rs-chip rs-chip-bad ml-1">{{ __('Perlu dilihat') }}</span>@endif
                                    </td>
                                    <td class="rs-num text-right font-semibold">{{ $n($row['count']) }}</td>
                                    <td class="whitespace-nowrap">{{ $row['last']?->translatedFormat('j M, H:i') }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
            @endif
        </section>

        <section class="mt-12" aria-labelledby="log-title">
            <h2 id="log-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Catatan aktivitas') }}</h2>
            <p class="mt-2 text-sm rs-muted">{{ __('Disimpan :days hari, lalu dihapus otomatis.', ['days' => \App\Models\ActivityLog::KEEP_DAYS]) }}</p>

            <form method="GET" action="{{ route('admin.logs') }}" class="mt-4 rs-card p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-4 items-end" data-busy>
                <div class="lg:col-span-3">
                    <label for="action" class="rs-label">{{ __('Jenis') }}</label>
                    <select id="action" name="action" class="rs-input">
                        <option value="">{{ __('Semua jenis') }}</option>
                        @foreach(\App\Models\ActivityLog::actionLabels() as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['action'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lg:col-span-3">
                    <label for="user" class="rs-label">{{ __('Pengguna') }}</label>
                    <input id="user" name="user" type="search" value="{{ $filters['user'] ?? '' }}" maxlength="120" class="rs-input" placeholder="{{ __('nama, email, atau nomor id') }}" autocomplete="off">
                </div>
                <div class="lg:col-span-2">
                    <label for="from" class="rs-label">{{ __('Dari tanggal') }}</label>
                    <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="rs-input" @error('from') aria-invalid="true" aria-describedby="from-error" @enderror>
                    @error('from')<p id="from-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" />{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="to" class="rs-label">{{ __('Sampai tanggal') }}</label>
                    <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="rs-input" @error('to') aria-invalid="true" aria-describedby="to-error" @enderror>
                    @error('to')<p id="to-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" />{{ __('Tanggal akhir harus sama atau setelah tanggal awal.') }}</p>@enderror
                </div>
                <div class="lg:col-span-2 flex flex-col gap-2">
                    <button type="submit" class="rs-sign w-full" data-busy-label="{{ __('Menyaring') }}"><span>{{ __('Saring log') }}</span></button>
                </div>
                @if($filtered)
                <p class="sm:col-span-2 lg:col-span-12 -mt-1 text-sm">
                    <a href="{{ route('admin.logs') }}" class="inline-flex min-h-[44px] items-center font-semibold text-[#047857] hover:text-[#053b30]">{{ __('Hapus saringan') }}</a>
                </p>
                @endif
            </form>

            <div class="mt-4 rs-card overflow-hidden">
                <div class="rs-table-wrap">
                    <table class="rs-table">
                        <thead><tr><th>{{ __('Waktu') }}</th><th>{{ __('Jenis') }}</th><th>{{ __('Yang terjadi') }}</th><th>{{ __('Alamat') }}</th><th>{{ __('Perangkat') }}</th></tr></thead>
                        <tbody>
                            @forelse($logs as $log)
                            <tr>
                                <td class="whitespace-nowrap rs-num">{{ $log->created_at?->translatedFormat('j M Y, H:i') }}</td>
                                <td class="whitespace-nowrap"><span class="rs-chip {{ in_array($log->action, ['auth.failed', 'auth.admin_denied'], true) ? 'rs-chip-bad' : 'rs-chip-plain' }}">{{ $log->label() }}</span></td>
                                <td class="min-w-[16rem] [overflow-wrap:anywhere]">
                                    @if($log->user)
                                    <a href="{{ route('admin.users.show', $log->user) }}" class="font-semibold text-[#047857] hover:text-[#053b30] hover:underline underline-offset-2">{{ $log->sentence() }}</a>
                                    @else
                                    {{ $log->sentence() }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap rs-num rs-muted">{{ $log->ip ?? '-' }}</td>
                                <td class="min-w-[10rem] max-w-[18rem] text-xs rs-muted"><span class="line-clamp-2 [overflow-wrap:anywhere]" title="{{ $log->user_agent }}">{{ $log->user_agent ?? '-' }}</span></td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="py-10 text-center">
                                    <p class="font-semibold">{{ $filtered ? __('Tidak ada catatan yang cocok dengan saringan ini.') : __('Belum ada catatan aktivitas.') }}</p>
                                    @if($filtered)<a href="{{ route('admin.logs') }}" class="inline-flex min-h-[44px] items-center font-semibold text-[#047857]">{{ __('Hapus saringan') }}</a>@endif
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($logs->hasPages())
                <div class="px-5 py-4 border-t rs-rule">{{ $logs->links() }}</div>
                @endif
            </div>
        </section>

        <section class="mt-12" aria-labelledby="server-title">
            <h2 id="server-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Galat server') }}</h2>
            <p class="mt-2 text-sm rs-muted max-w-3xl">{{ __('Peringatan dan galat dari 200 baris terakhir :file, terbaru di atas. Hanya baris pertama pesan yang ditampilkan, dan nilai yang mirip password, token, atau kunci disembunyikan.', ['file' => $server['file'] ?? 'laravel.log']) }}</p>

            <div class="mt-4 rs-card overflow-hidden">
                @if($server['state'] === 'missing')
                <p class="px-5 py-6 text-sm rs-muted">{{ __('File log belum ada. Laravel membuatnya saat pertama kali ada yang perlu dicatat.') }}</p>
                @elseif($server['state'] === 'error')
                <p class="px-5 py-6 text-sm text-[#991b1b] flex gap-2" role="alert"><x-icon name="exclamation" class="w-5 h-5 shrink-0" />{{ __('File log ada tapi tidak bisa dibaca. Periksa izin folder storage/logs di server.') }}</p>
                @elseif(empty($server['entries']))
                <p class="px-5 py-6 text-sm rs-muted">{{ __('Tidak ada peringatan atau galat di bagian akhir log. Server tenang.') }}</p>
                @else
                <div class="rs-table-wrap">
                    <table class="rs-table">
                        <thead><tr><th>{{ __('Waktu') }}</th><th>{{ __('Tingkat') }}</th><th>{{ __('Pesan') }}</th></tr></thead>
                        <tbody>
                            @foreach($server['entries'] as $e)
                            <tr>
                                <td class="whitespace-nowrap rs-num">{{ $e['time'] }}</td>
                                <td><span class="rs-chip {{ $levelChip($e['level']) }}">{{ $e['level'] }}</span></td>
                                <td class="min-w-[18rem] font-mono text-xs [overflow-wrap:anywhere]">{{ $e['message'] }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </section>
    </div>
</div>
@include('admin.partials.busy')
@endsection
