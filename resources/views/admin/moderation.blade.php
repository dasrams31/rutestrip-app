@extends('layouts.app')

@section('title', __('Moderasi') . ' | ' . __('Panel admin'))

@php
    $typeLabels = [
        'status' => __('status'),
        'photo' => __('foto'),
        'recording' => __('rekaman'),
        'story' => __('cerita'),
    ];
    // Red edge: deleting is the one irreversible choice on this page.
    $deleteClass = 'rs-btn-sm w-full min-[420px]:w-auto inline-flex items-center justify-center gap-2 px-4 rounded-lg font-bold bg-white text-[#991b1b] border-2 border-[#b91c1c] hover:bg-[#fef2f2]';
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'eyebrow' => __('Panel admin'),
        'title' => __('Laporan dari pendaki.'),
        'lead' => __('Baca isinya dulu. Hapus kalau melanggar, atau abaikan laporannya kalau isinya wajar. Yang dihapus tidak tampil lagi di mana pun.'),
        'width' => 'max-w-5xl',
        'seed' => 149,
        'tabs' => \App\Support\AdminNav::tabs('moderation'),
    ])

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        {{-- Obrolan Pendaki --}}
        <section class="mt-8" aria-labelledby="mod-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="mod-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Pesan obrolan') }}</h2>
                <p class="text-sm rs-muted">{{ __(':count laporan menunggu', ['count' => $reports->total()]) }}</p>
            </div>

            @if($reports->isEmpty())
            <div class="mt-4 rs-card px-5 py-8 text-center">
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
                                <button type="submit" class="{{ $deleteClass }}">
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

        {{-- Linimasa posts --}}
        <section class="mt-12" aria-labelledby="mod-posts-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="mod-posts-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Kiriman linimasa') }}</h2>
                <p class="text-sm rs-muted">{{ __(':count laporan menunggu', ['count' => $postReports->total()]) }}</p>
            </div>

            @if($postReports->isEmpty())
            <div class="mt-4 rs-card px-5 py-8 text-center">
                <p class="font-semibold">{{ __('Tidak ada kiriman yang dilaporkan.') }}</p>
                <p class="mt-1 text-sm rs-muted">{{ __('Kiriman muncul di sini saat pendaki melaporkannya dari linimasa atau aplikasi.') }}</p>
            </div>
            @else
            <ol class="mt-4 space-y-4">
                @foreach($postReports as $p)
                <li class="rs-card overflow-hidden" data-reported-post="{{ $p->id }}">
                    <article aria-labelledby="post-{{ $p->id }}">
                        <header class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 sm:px-5 pt-4">
                            <h3 id="post-{{ $p->id }}" class="font-semibold [overflow-wrap:anywhere]">
                                @if($p->user)
                                <a href="{{ route('admin.users.show', $p->user) }}" class="text-[#047857] hover:text-[#053b30] hover:underline underline-offset-2">{{ $p->user->name }}</a>
                                @else
                                {{ __('Akun yang sudah dihapus') }}
                                @endif
                                <span class="font-normal rs-muted">{{ __('mengirim :type', ['type' => $typeLabels[$p->type] ?? $p->type]) }}</span>
                            </h3>
                            <a href="{{ route('posts.show', $p->id) }}" class="inline-flex items-center min-h-[44px] text-sm font-semibold text-[#047857] underline underline-offset-4 hover:text-[#053b30]">{{ __('Buka kiriman') }}</a>
                        </header>
                        <div class="mx-4 sm:mx-5 mt-2 flex gap-3 items-start">
                            @if($p->photo_path)
                            <img src="{{ $p->photoUrl() }}" alt="{{ __('Foto pada kiriman') }}" width="96" height="96" loading="lazy" class="w-24 h-24 shrink-0 rounded-lg object-cover border rs-rule bg-white">
                            @endif
                            @if(filled($p->body))
                            <blockquote class="min-w-0 flex-1 border-l-4 border-[#dcd8c9] bg-[#faf9f4] rounded-r-lg px-4 py-3 whitespace-pre-wrap [overflow-wrap:anywhere]">{{ $p->body }}</blockquote>
                            @elseif(! $p->photo_path)
                            <p class="text-sm rs-muted">{{ __('Kiriman tanpa tulisan (jalur atau lokasi saja).') }}</p>
                            @endif
                        </div>
                        <p class="px-4 sm:px-5 mt-3 text-sm">
                            <span class="rs-chip rs-chip-warn">{{ __('Dilaporkan') }}</span>
                            <span class="rs-muted">{{ __(':count laporan, terakhir :time', ['count' => $p->reports_count, 'time' => $p->reported_at?->translatedFormat('j M Y, H:i')]) }}</span>
                        </p>
                        <div class="mt-4 px-4 sm:px-5 py-3 border-t rs-rule bg-[#faf9f4] flex flex-col min-[420px]:flex-row gap-3">
                            <form method="POST" action="{{ route('admin.moderation.posts.destroy', $p->id) }}"
                                  data-confirm="{{ __('Hapus kiriman dari :name? Kiriman ini tidak tampil lagi di linimasa.', ['name' => $p->user?->name ?? __('pendaki')]) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="{{ $deleteClass }}">
                                    <x-icon name="trash" class="w-4 h-4 shrink-0" /><span>{{ __('Hapus kiriman') }}</span>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.moderation.posts.dismiss', $p->id) }}">
                                @csrf
                                <button type="submit" class="rs-btn-ghost rs-btn-sm w-full min-[420px]:w-auto">{{ __('Abaikan laporan') }}</button>
                            </form>
                        </div>
                    </article>
                </li>
                @endforeach
            </ol>
            @if($postReports->hasPages())
            <div class="mt-6">{{ $postReports->links() }}</div>
            @endif
            @endif
        </section>

        {{-- Linimasa comments --}}
        <section class="mt-12" aria-labelledby="mod-comments-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="mod-comments-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Komentar linimasa') }}</h2>
                <p class="text-sm rs-muted">{{ __(':count laporan menunggu', ['count' => $commentReports->total()]) }}</p>
            </div>

            @if($commentReports->isEmpty())
            <div class="mt-4 rs-card px-5 py-8 text-center">
                <p class="font-semibold">{{ __('Tidak ada komentar yang dilaporkan.') }}</p>
                <p class="mt-1 text-sm rs-muted">{{ __('Komentar muncul di sini saat pendaki melaporkannya di halaman kiriman.') }}</p>
            </div>
            @else
            <ol class="mt-4 space-y-4">
                @foreach($commentReports as $c)
                <li class="rs-card overflow-hidden" data-reported-comment="{{ $c->id }}">
                    <article aria-labelledby="comment-{{ $c->id }}">
                        <header class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 sm:px-5 pt-4">
                            <h3 id="comment-{{ $c->id }}" class="font-semibold [overflow-wrap:anywhere]">
                                @if($c->user)
                                <a href="{{ route('admin.users.show', $c->user) }}" class="text-[#047857] hover:text-[#053b30] hover:underline underline-offset-2">{{ $c->user->name }}</a>
                                @else
                                {{ __('Akun yang sudah dihapus') }}
                                @endif
                                <span class="font-normal rs-muted">{{ __('berkomentar') }}</span>
                            </h3>
                            <a href="{{ route('posts.show', $c->post_id) }}#komentar-{{ $c->id }}" class="inline-flex items-center min-h-[44px] text-sm font-semibold text-[#047857] underline underline-offset-4 hover:text-[#053b30]">{{ __('Buka kiriman') }}</a>
                        </header>
                        <blockquote class="mx-4 sm:mx-5 mt-2 border-l-4 border-[#dcd8c9] bg-[#faf9f4] rounded-r-lg px-4 py-3 whitespace-pre-wrap [overflow-wrap:anywhere]">{{ $c->body }}</blockquote>
                        <p class="px-4 sm:px-5 mt-3 text-sm">
                            <span class="rs-chip rs-chip-warn">{{ __('Dilaporkan') }}</span>
                            <span class="rs-muted">{{ __(':count laporan, terakhir :time', ['count' => $c->reports_count, 'time' => $c->reported_at?->translatedFormat('j M Y, H:i')]) }}</span>
                        </p>
                        <div class="mt-4 px-4 sm:px-5 py-3 border-t rs-rule bg-[#faf9f4] flex flex-col min-[420px]:flex-row gap-3">
                            <form method="POST" action="{{ route('admin.moderation.comments.destroy', $c->id) }}"
                                  data-confirm="{{ __('Hapus komentar dari :name?', ['name' => $c->user?->name ?? __('pendaki')]) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="{{ $deleteClass }}">
                                    <x-icon name="trash" class="w-4 h-4 shrink-0" /><span>{{ __('Hapus komentar') }}</span>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.moderation.comments.dismiss', $c->id) }}">
                                @csrf
                                <button type="submit" class="rs-btn-ghost rs-btn-sm w-full min-[420px]:w-auto">{{ __('Abaikan laporan') }}</button>
                            </form>
                        </div>
                    </article>
                </li>
                @endforeach
            </ol>
            @if($commentReports->hasPages())
            <div class="mt-6">{{ $commentReports->links() }}</div>
            @endif
            @endif
        </section>
    </div>
</div>
@include('admin.partials.busy')
@endsection
