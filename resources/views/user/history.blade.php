@extends('layouts.app')

@section('title', __('Riwayat Pencarian'))

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => ['href' => route('user.dashboard'), 'label' => __('Kembali ke dashboard')],
        'title' => __('Riwayat pencarian'),
        'lead' => __('Semua yang pernah kamu cari. Ketuk salah satu untuk mencarinya lagi.'),
        'width' => 'max-w-4xl',
        'seed' => 89,
    ])

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 sm:pt-10">
        @if($histories->count() > 0)
        <ul class="rs-card divide-y divide-[#ece9de]">
            @foreach($histories as $history)
            <li>
                <form action="{{ route('search.submit') }}" method="POST">
                    @csrf
                    <input type="hidden" name="query" value="{{ $history->query }}">
                    <button type="submit" class="w-full text-left flex items-start sm:items-center gap-3 sm:gap-4 px-4 sm:px-6 py-4 min-w-0 hover:bg-[#faf9f4] group">
                        <x-icon name="search" class="w-5 h-5 mt-0.5 sm:mt-0 text-[#047857] shrink-0" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-[#053b30] [overflow-wrap:anywhere] group-hover:underline underline-offset-4">{{ $history->query }}</span>
                            <span class="block mt-0.5 text-sm rs-muted">{{ __(':count rute', ['count' => $history->results_count]) }} · {{ $history->created_at->diffForHumans() }}</span>
                        </span>
                        <span class="hidden sm:inline text-sm font-semibold text-[#047857] shrink-0">{{ __('Cari lagi') }}</span>
                    </button>
                </form>
            </li>
            @endforeach
        </ul>

        <div class="mt-6">
            {{ $histories->links() }}
        </div>
        @else
        <div class="rs-card p-6 sm:p-10">
            <x-icon name="search" class="w-8 h-8 text-[#047857]" />
            <h2 class="rs-display mt-3 uppercase text-3xl leading-none text-[#053b30]">{{ __('Belum ada riwayat pencarian') }}</h2>
            <p class="mt-2 rs-muted">{{ __('Coba tulis "jalur landai untuk pemula" di halaman cari.') }}</p>
            <a href="{{ route('search.index') }}" class="rs-sign mt-5">{{ __('Mulai cari jalur') }}</a>
        </div>
        @endif
    </div>
</div>
@endsection
