@extends('layouts.app')

@section('title', __('Kiriman disimpan'))

@section('content')
@include('partials.rs-kit')
@include('linimasa.partials.scripts')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => ['href' => route('linimasa'), 'label' => __('Linimasa')],
        'title' => __('Kiriman disimpan'),
        'lead' => __('Kiriman yang kamu simpan dari linimasa, terbaru di atas. Hanya kamu yang bisa melihat daftar ini.'),
        'width' => 'max-w-3xl',
        'seed' => 67,
    ])

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        @if(empty($cards))
        <div class="rs-card p-6 sm:p-8">
            <p class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('Belum ada yang disimpan') }}</p>
            <p class="mt-3 rs-muted max-w-prose">{{ __('Ketuk Simpan di bawah kiriman untuk menyimpan catatan jalur atau rekaman yang mau kamu baca lagi sebelum berangkat.') }}</p>
            <a href="{{ route('linimasa') }}" class="rs-btn mt-5 w-full sm:w-auto">{{ __('Buka linimasa') }}</a>
        </div>
        @else
        <ul class="space-y-4" data-feed>
            @include('linimasa.partials.cards', ['cards' => $cards, 'more' => $more])
        </ul>
        @endif
    </div>
</div>
@endsection
