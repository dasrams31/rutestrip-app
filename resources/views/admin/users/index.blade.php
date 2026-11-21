@extends('layouts.app')

@section('title', __('Pengguna') . ' | ' . __('Panel admin'))

@php
    $isEn = app()->getLocale() === 'en';
    $n = fn ($v) => number_format((float) $v, 0, $isEn ? '.' : ',', $isEn ? ',' : '.');
    $sorts = ['baru' => __('Baru daftar'), 'aktif' => __('Terakhir aktif'), 'nama' => __('Nama A sampai Z')];
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'eyebrow' => __('Panel admin'),
        'title' => __('Siapa saja yang memakai RuteStrip.'),
        'lead' => __('Jumlah akun, siapa yang baru daftar, dan siapa yang masih aktif. Semua angka diambil langsung dari database.'),
        'width' => 'max-w-7xl',
        'seed' => 211,
        'tabs' => \App\Support\AdminNav::tabs('users'),
    ])

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {{-- Accounts: total and admins lead, new and active follow in a quieter row. --}}
        <section class="mt-8 grid grid-cols-1 lg:grid-cols-12 gap-6" aria-labelledby="sum-title">
            <h2 id="sum-title" class="sr-only">{{ __('Ringkasan akun') }}</h2>
            <div class="lg:col-span-4 rs-card p-5 sm:p-6 flex flex-col justify-between min-w-0">
                <p class="text-sm rs-muted">{{ __('akun terdaftar') }}</p>
                <p class="rs-display rs-num text-6xl sm:text-7xl leading-none text-[#053b30]">{{ $n($summary['total']) }}</p>
                <p class="mt-3 text-sm">{{ __(':count di antaranya admin.', ['count' => $n($summary['admins'])]) }}</p>
            </div>
            <div class="lg:col-span-8 rs-card overflow-hidden min-w-0">
            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-[#ece9de]">
                @foreach([
                    [__('baru, 7 hari'), $summary['new_7']],
                    [__('baru, 30 hari'), $summary['new_30']],
                    [__('masuk, 7 hari'), $summary['active_7']],
                    [__('masuk, 30 hari'), $summary['active_30']],
                ] as [$label, $value])
                <div class="flex flex-col-reverse justify-end bg-white p-4 sm:p-5 min-w-0">
                    <dt class="mt-1 text-sm rs-muted">{{ $label }}</dt>
                    <dd class="rs-display rs-num text-4xl leading-none text-[#053b30]">{{ $n($value) }}</dd>
                </div>
                @endforeach
            </dl>
                <p class="border-t rs-rule bg-[#faf9f4] px-4 sm:px-5 py-3 text-sm rs-muted">
                    {{ __('Angka masuk memakai waktu masuk terakhir, yang baru dicatat sejak fitur ini dipasang. Baru :count akun yang punya catatan itu.', ['count' => $n($summary['tracked'])]) }}
                </p>
            </div>
        </section>

        <section class="mt-12" aria-labelledby="list-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="list-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Daftar pengguna') }}</h2>
                <p class="text-sm rs-muted" aria-live="polite">
                    @if($q !== '')
                    {{ __(':count akun cocok dengan ":q"', ['count' => $n($users->total()), 'q' => $q]) }}
                    @else
                    {{ __(':count akun', ['count' => $n($users->total())]) }}
                    @endif
                </p>
            </div>

            <form method="GET" action="{{ route('admin.users.index') }}" class="mt-4 flex flex-col sm:flex-row gap-3 sm:items-end" data-busy>
                <div class="flex-1 min-w-0">
                    <label for="q" class="rs-label">{{ __('Cari nama atau email') }}</label>
                    <input id="q" name="q" type="search" value="{{ $q }}" maxlength="120" class="rs-input" autocomplete="off" placeholder="{{ __('contoh: rama atau @gmail.com') }}">
                </div>
                <div class="sm:w-52">
                    <label for="sort" class="rs-label">{{ __('Urutkan') }}</label>
                    <select id="sort" name="sort" class="rs-input">
                        @foreach($sorts as $value => $label)
                        <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rs-sign" data-busy-label="{{ __('Mencari') }}">
                    <x-icon name="search" class="w-5 h-5 shrink-0" /><span>{{ __('Cari pengguna') }}</span>
                </button>
            </form>

            <div class="mt-4 rs-card overflow-hidden">
                <div class="rs-table-wrap">
                    <table class="rs-table">
                        <thead>
                            <tr>
                                <th>{{ __('Nama') }}</th><th>{{ __('Peran') }}</th><th>{{ __('Daftar') }}</th><th>{{ __('Terakhir masuk') }}</th>
                                <th class="text-right">{{ __('Unggahan') }}</th><th class="text-right">{{ __('Suka') }}</th><th class="text-right">{{ __('Ulasan') }}</th><th class="text-right">{{ __('Pesan') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($users as $u)
                            <tr>
                                <td class="min-w-[14rem]">
                                    <a href="{{ route('admin.users.show', $u) }}" class="inline-flex min-h-[44px] items-center font-semibold text-[#047857] hover:text-[#053b30] [overflow-wrap:anywhere]">{{ $u->name }}</a>
                                    <span class="block -mt-2 text-xs rs-muted [overflow-wrap:anywhere]">{{ $u->email }}</span>
                                </td>
                                <td><span class="rs-chip {{ $u->isAdmin() ? 'rs-chip-ok' : 'rs-chip-plain' }}">{{ $u->isAdmin() ? __('Admin') : __('Pendaki') }}</span></td>
                                <td class="whitespace-nowrap">{{ $u->created_at?->translatedFormat('j M Y') ?? '-' }}</td>
                                <td class="whitespace-nowrap">
                                    @if($u->last_login_at)
                                    <span title="{{ $u->last_login_at->translatedFormat('j M Y, H:i') }}">{{ $u->last_login_at->diffForHumans() }}</span>
                                    @else
                                    <span class="rs-muted">{{ __('Belum tercatat') }}</span>
                                    @endif
                                </td>
                                <td class="rs-num text-right">{{ $n($u->uploads_count) }}</td>
                                <td class="rs-num text-right">{{ $n($u->likes_count) }}</td>
                                <td class="rs-num text-right">{{ $n($u->reviews_count) }}</td>
                                <td class="rs-num text-right">{{ $n($u->messages_count) }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="py-10 text-center">
                                    @if($q !== '')
                                    <p class="font-semibold">{{ __('Tidak ada akun dengan nama atau email ":q".', ['q' => $q]) }}</p>
                                    <a href="{{ route('admin.users.index') }}" class="inline-flex min-h-[44px] items-center font-semibold text-[#047857]">{{ __('Tampilkan semua pengguna') }}</a>
                                    @else
                                    <p class="font-semibold">{{ __('Belum ada akun.') }}</p>
                                    @endif
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($users->hasPages())
                <div class="px-5 py-4 border-t rs-rule">{{ $users->links() }}</div>
                @endif
            </div>
        </section>
    </div>
</div>
@include('admin.partials.busy')
@endsection
