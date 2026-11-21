@extends('layouts.app')

@section('title', __('Moderasi') . ' | ' . __('Panel admin'))

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'eyebrow' => __('Panel admin'),
        'title' => __('Pesan yang dilaporkan pendaki.'),
        'lead' => __('Baca pesannya dulu. Hapus kalau melanggar, atau abaikan laporannya kalau pesannya wajar. Keduanya tercatat di log.'),
        'width' => 'max-w-5xl',
        'seed' => 149,
        'tabs' => \App\Support\AdminNav::tabs('moderation'),
    ])

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <section class="mt-8" aria-labelledby="mod-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="mod-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Menunggu keputusan') }}</h2>
                <p class="text-sm rs-muted">{{ __(':count laporan menunggu', ['count' => $reports->total()]) }}</p>
            </div>

            @if($reports->isEmpty())
            <div class="mt-4 rs-card px-5 py-10 text-center">
                <p class="font-semibold">{{ __('Tidak ada laporan.') }}</p>
                <p class="mt-1 text-sm rs-muted">{{ __('Laporan muncul di sini saat pendaki melaporkan pesan di Obrolan Pendaki.') }}</p>
            </div>
            @else
            <ol class="mt-4 space-y-4">
                @foreach($reports as $m)
                <li class="rs-card overflow-hidden">
                    <article aria-labelledby="msg-{{ $m->id }}">
                        <header class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 sm:px-5 pt-4">
                            <h3 id="msg-{{ $m->id }}" class="font-semibold [overflow-wrap:anywhere]">
                                @if($m->user)
                                <a href="{{ route('admin.users.show', $m->user) }}" class="text-[#047857] hover:text-[#053b30] hover:underline underline-offset-2">{{ $m->user->name }}</a>
                                @else
                                {{ __('Akun yang sudah dihapus') }}
                                @endif
                                <span class="font-normal rs-muted">{{ __('menulis') }}</span>
                            </h3>
                            <time class="text-sm rs-muted" datetime="{{ $m->created_at?->toIso8601String() }}">{{ $m->created_at?->translatedFormat('j M Y, H:i') }}</time>
                        </header>
                        <blockquote class="mx-4 sm:mx-5 mt-3 border-l-4 border-[#dcd8c9] bg-[#faf9f4] rounded-r-lg px-4 py-3 whitespace-pre-wrap [overflow-wrap:anywhere]">{{ $m->body }}</blockquote>
                        <p class="px-4 sm:px-5 mt-3 text-sm">
                            <span class="rs-chip rs-chip-warn">{{ __('Dilaporkan') }}</span>
                            <span class="rs-muted">{{ __('oleh :name, :time', ['name' => $m->reporter?->name ?? __('akun yang sudah dihapus'), 'time' => $m->reported_at?->translatedFormat('j M Y, H:i')]) }}</span>
                        </p>
                        <div class="mt-4 px-4 sm:px-5 py-3 border-t rs-rule bg-[#faf9f4] flex flex-col min-[420px]:flex-row gap-3">
                            <form method="POST" action="{{ route('admin.moderation.destroy', $m) }}"
                                  data-confirm="{{ __('Hapus pesan dari :name? Di obrolan pesan ini menjadi "Pesan dihapus".', ['name' => $m->user?->name ?? __('pendaki')]) }}">
                                @csrf @method('DELETE')
                                {{-- Red edge: deleting is the one irreversible choice here. --}}
                                <button type="submit" class="rs-btn-sm w-full min-[420px]:w-auto inline-flex items-center justify-center gap-2 px-4 rounded-lg font-bold bg-white text-[#991b1b] border-2 border-[#b91c1c] hover:bg-[#fef2f2]">
                                    <x-icon name="trash" class="w-4 h-4 shrink-0" /><span>{{ __('Hapus pesan') }}</span>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.moderation.dismiss', $m) }}">
                                @csrf
                                <button type="submit" class="rs-btn-ghost rs-btn-sm w-full min-[420px]:w-auto">{{ __('Abaikan laporan') }}</button>
                            </form>
                        </div>
                    </article>
                </li>
                @endforeach
            </ol>
            @if($reports->hasPages())
            <div class="mt-6">{{ $reports->links() }}</div>
            @endif
            @endif
        </section>
    </div>
</div>
@include('admin.partials.busy')
@endsection
