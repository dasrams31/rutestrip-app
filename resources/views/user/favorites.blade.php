@extends('layouts.app')

@section('title', __('Rute Favorit'))

@php
    $level = fn ($l) => match ($l) { 'Mudah' => 1, 'Sedang' => 2, 'Sulit' => 3, default => 4 };
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => ['href' => route('user.dashboard'), 'label' => __('Kembali ke dashboard')],
        'title' => __('Jalur favorit'),
        'lead' => __('Jalur yang kamu simpan untuk dibuka lagi nanti.'),
        'seed' => 83,
    ])

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 sm:pt-10">
        @if($favorites->count() > 0)
        <ul class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
            @foreach($favorites as $route)
            <li class="rs-card p-5 flex flex-col min-w-0">
                <a href="{{ route('routes.show', $route) }}" class="group min-w-0">
                    <h2 class="rs-display uppercase text-2xl leading-[1.05] text-[#053b30] group-hover:text-[#047857] break-words">{{ $route->name }}</h2>
                </a>
                <p class="mt-2 inline-flex items-center gap-2 text-sm rs-muted">
                    <span class="rs-diff" aria-hidden="true">@for($i = 1; $i <= 4; $i++)<i class="{{ $i <= $level($route->difficulty_level) ? 'on' : '' }}"></i>@endfor</span>
                    {{ __($route->difficulty_level) }}
                </p>
                <dl class="mt-4 grid grid-cols-2 gap-4">
                    <div><dt class="text-xs rs-muted">{{ __('Jarak') }}</dt><dd class="rs-display rs-num text-2xl">{{ $route->formatted_distance }}</dd></div>
                    <div><dt class="text-xs rs-muted">{{ __('Estimasi') }}</dt><dd class="rs-display rs-num text-2xl">{{ $route->formatted_duration }}</dd></div>
                </dl>
                <div class="mt-auto pt-5 flex gap-2">
                    <a href="{{ route('routes.show', $route) }}" class="rs-btn rs-btn-sm flex-1">{{ __('Buka jalur') }}</a>
                    <form action="{{ route('user.favorite.toggle', $route) }}" method="POST" class="flex-1"
                          onsubmit="return confirm(@js(__('Hapus :name dari favorit?', ['name' => $route->name])))">
                        @csrf
                        <button type="submit" class="rs-btn-ghost rs-btn-sm w-full" aria-label="{{ __('Hapus :name dari favorit', ['name' => $route->name]) }}">{{ __('Hapus') }}</button>
                    </form>
                </div>
            </li>
            @endforeach
        </ul>

        <div class="mt-8">
            {{ $favorites->links() }}
        </div>
        @else
        <div class="rs-card p-6 sm:p-10 max-w-2xl">
            <x-icon name="heart-outline" class="w-8 h-8 text-[#047857]" />
            <h2 class="rs-display mt-3 uppercase text-3xl leading-none text-[#053b30]">{{ __('Belum ada jalur favorit') }}</h2>
            <p class="mt-2 rs-muted">{{ __('Ketuk ikon hati di halaman jalur untuk menyimpannya di sini.') }}</p>
            <a href="{{ route('routes.index') }}" class="rs-btn mt-5">{{ __('Lihat semua jalur') }}</a>
        </div>
        @endif
    </div>
</div>
@endsection
