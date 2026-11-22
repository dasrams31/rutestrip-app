@extends('layouts.app')

@section('title', __('Aplikasi Android belum tersedia'))
@section('robots', 'noindex, nofollow')

@section('content')
@include('partials.rs-kit')
<div class="rs-app">
    @include('partials.rs-head', [
        'title' => __('Aplikasi Android belum tersedia'),
        'lead' => $legacy
            ? __('Berkas untuk HP lama (32-bit) sedang tidak ada di server. Coba lagi nanti.')
            : __('Berkas pemasangnya sedang tidak ada di server. Coba lagi nanti.'),
        'width' => 'max-w-3xl',
        'seed' => 23,
    ])

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
        <div class="rs-card p-5 sm:p-7">
            <p class="text-rs-ink leading-relaxed">{{ __('Sambil menunggu, semua jalur tetap bisa dicari dan dibuka dari situs ini.') }}</p>
            <div class="mt-5 flex flex-col min-[420px]:flex-row gap-3">
                <a href="{{ route('home') }}" class="rs-btn">{{ __('Cari jalur di situs') }}</a>
                @if($legacy)
                <a href="{{ route('app.download') }}" class="rs-btn-ghost">{{ __('Unduh versi 64-bit') }}</a>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
