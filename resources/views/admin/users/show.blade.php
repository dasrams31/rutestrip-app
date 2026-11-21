@extends('layouts.app')

@section('title', $user->name . ' | ' . __('Pengguna'))

@php
    $isEn = app()->getLocale() === 'en';
    $n = fn ($v) => number_format((float) $v, 0, $isEn ? '.' : ',', $isEn ? ',' : '.');
    $isLastAdmin = $user->isAdmin() && $adminCount <= 1;
    $isMe = $user->is(auth()->user());
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => ['href' => route('admin.users.index'), 'label' => __('Semua pengguna')],
        'eyebrow' => $user->isAdmin() ? __('Admin') : __('Pendaki'),
        'title' => $user->name,
        'lead' => $user->email,
        'width' => 'max-w-7xl',
        'seed' => 37 + $user->id,
        'tabs' => \App\Support\AdminNav::tabs('users'),
    ])

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-8 lg:grid lg:grid-cols-12 lg:gap-8">
        <div class="lg:col-span-4 space-y-6 min-w-0">
            <section class="rs-card p-5 sm:p-6" aria-labelledby="acct-title">
                <h2 id="acct-title" class="font-semibold">{{ __('Akun') }}</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="rs-muted">{{ __('Daftar') }}</dt><dd class="text-right">{{ $user->created_at?->translatedFormat('j M Y, H:i') ?? '-' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="rs-muted">{{ __('Terakhir masuk') }}</dt><dd class="text-right">{{ $user->last_login_at?->translatedFormat('j M Y, H:i') ?? __('Belum tercatat') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="rs-muted">{{ __('Jumlah masuk tercatat') }}</dt><dd class="rs-num">{{ $n($user->login_count) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="rs-muted">{{ __('Bahasa') }}</dt><dd>{{ strtoupper($user->locale ?? 'id') }}</dd></div>
                </dl>
            </section>

            <section class="rs-card overflow-hidden" aria-labelledby="count-title">
                <h2 id="count-title" class="px-5 pt-4 font-semibold">{{ __('Yang sudah dilakukan') }}</h2>
                <dl class="mt-3 grid grid-cols-2 gap-px bg-[#ece9de] border-t rs-rule">
                    @foreach([
                        [__('jalur diunggah'), $user->uploads_count],
                        [__('jalur disukai'), $user->likes_count],
                        [__('ulasan'), $user->reviews_count],
                        [__('pesan obrolan'), $user->messages_count],
                        [__('pencarian tersimpan'), $user->search_histories_count],
                    ] as [$label, $value])
                    <div class="flex flex-col-reverse justify-end bg-white p-4 min-w-0">
                        <dt class="mt-1 text-sm rs-muted">{{ $label }}</dt>
                        <dd class="rs-display rs-num text-3xl leading-none text-[#053b30]">{{ $n($value) }}</dd>
                    </div>
                    @endforeach
                </dl>
            </section>

            <section class="rs-card p-5 sm:p-6" aria-labelledby="role-title">
                <h2 id="role-title" class="font-semibold">{{ __('Peran') }}</h2>
                @if($user->isAdmin())
                <p class="mt-1 text-sm rs-muted">{{ __('Admin bisa membuka panel ini, menghapus jalur, dan menghapus pesan obrolan.') }}</p>
                @if($isLastAdmin)
                <p class="mt-3 text-sm"><span class="rs-chip rs-chip-warn">{{ __('Satu-satunya admin') }}</span></p>
                <p class="mt-2 text-sm rs-muted">{{ __('Peran ini tidak bisa dicabut sebelum ada admin lain.') }}</p>
                @else
                <form method="POST" action="{{ route('admin.users.role', $user) }}" class="mt-4"
                      data-confirm="{{ $isMe ? __('Cabut peran admin kamu sendiri? Kamu akan langsung keluar dari panel ini.') : __('Cabut peran admin :name?', ['name' => $user->name]) }}">
                    @csrf
                    <input type="hidden" name="role" value="user">
                    <button type="submit" class="rs-btn-ghost rs-btn-sm">{{ $isMe ? __('Cabut peran admin saya') : __('Cabut admin') }}</button>
                </form>
                @endif
                @else
                <p class="mt-1 text-sm rs-muted">{{ __('Pendaki biasa. Jadikan admin hanya untuk orang yang ikut mengelola situs.') }}</p>
                <form method="POST" action="{{ route('admin.users.role', $user) }}" class="mt-4"
                      data-confirm="{{ __('Jadikan :name admin? Dia bisa membuka panel ini, menghapus jalur, dan menghapus pesan.', ['name' => $user->name]) }}">
                    @csrf
                    <input type="hidden" name="role" value="admin">
                    <button type="submit" class="rs-btn rs-btn-sm">{{ __('Jadikan admin') }}</button>
                </form>
                @endif
                @error('role')
                <p class="rs-error" role="alert"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" />{{ $message }}</p>
                @enderror
            </section>
        </div>

        <section class="lg:col-span-8 mt-8 lg:mt-0 min-w-0" aria-labelledby="log-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="log-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Aktivitas terakhir') }}</h2>
                <a href="{{ route('admin.logs', ['user' => $user->id]) }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-[#047857] hover:text-[#053b30]">{{ __('Buka di log') }}</a>
            </div>
            <div class="mt-4 rs-card overflow-hidden">
                @include('admin.partials.feed', ['entries' => $logs->getCollection(), 'empty' => __('Belum ada aktivitas tercatat untuk akun ini. Catatan dimulai sejak fitur log dipasang.')])
                @if($logs->hasPages())
                <div class="px-5 py-4 border-t rs-rule">{{ $logs->links() }}</div>
                @endif
            </div>
        </section>
    </div>
</div>
@include('admin.partials.busy')
@endsection
