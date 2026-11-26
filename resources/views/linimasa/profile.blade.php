@extends('layouts.app')

@section('title', __('Profil :name', ['name' => $user->name]))
@section('meta_description', filled($user->bio) ? $user->bio : __(':name di Linimasa RuteStrip.', ['name' => $user->name]))
@section('robots', 'noindex, nofollow')

@section('content')
@include('partials.rs-kit')
@include('linimasa.partials.scripts')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => auth()->check() ? ['href' => route('linimasa'), 'label' => __('Linimasa')] : null,
        'eyebrow' => __('Profil pendaki'),
        'title' => $user->name,
        'titleClass' => 'text-[clamp(2.1rem,8vw,3.5rem)]',
        'lead' => $user->bio,
        'width' => 'max-w-3xl',
        'seed' => 17 + ($user->id % 60),
    ])

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
        {{-- Counts and the one action on this page (follow, or edit your own bio). --}}
        <section class="rs-card p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4" aria-label="{{ __('Ringkasan profil') }}">
            <dl class="grid grid-cols-3 gap-2 text-center flex-1">
                <div>
                    <dt class="text-sm rs-muted min-h-[44px] flex items-center justify-center">{{ __('account.linimasa.posts') }}</dt>
                    <dd class="rs-display rs-num text-3xl text-[#053b30]">{{ $profile['posts_count'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm"><a href="{{ route('profiles.followers', $user->id) }}" class="inline-flex items-center min-h-[44px] font-semibold text-[#047857] underline underline-offset-4 hover:text-[#053b30]">{{ __('Pengikut') }}</a></dt>
                    <dd class="rs-display rs-num text-3xl text-[#053b30]" data-followers-count="{{ $user->id }}">{{ $profile['followers_count'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm"><a href="{{ route('profiles.following', $user->id) }}" class="inline-flex items-center min-h-[44px] font-semibold text-[#047857] underline underline-offset-4 hover:text-[#053b30]">{{ __('Mengikuti') }}</a></dt>
                    <dd class="rs-display rs-num text-3xl text-[#053b30]">{{ $profile['following_count'] }}</dd>
                </div>
            </dl>
            <div class="flex flex-col items-stretch sm:items-end gap-2">
                @if($profile['is_me'])
                <a href="{{ route('settings') }}#profile" class="rs-btn-ghost rs-btn-sm">{{ filled($user->bio) ? __('Ubah bio') : __('Tulis bio') }}</a>
                @elseif(auth()->check())
                @include('linimasa.partials.follow-button', ['userId' => $user->id, 'following' => $profile['is_following'], 'main' => true, 'name' => $user->name])
                @if($profile['follows_me'])
                <span class="rs-chip rs-chip-plain self-center sm:self-end">{{ __('Mengikutimu') }}</span>
                @endif
                @endif
            </div>
        </section>

        <section aria-labelledby="posts-title">
            <h2 id="posts-title" class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('account.linimasa.posts') }}</h2>
            @if(empty($cards))
            <div class="mt-4 rs-card p-6">
                <p class="rs-muted">{{ $profile['is_me'] ? __('Kamu belum mengirim apa pun ke linimasa.') : __(':name belum mengirim apa pun ke linimasa.', ['name' => $user->name]) }}</p>
                @if($profile['is_me'])
                <a href="{{ route('linimasa') }}#tulis" class="rs-btn mt-4 w-full sm:w-auto">{{ __('Tulis kiriman pertama') }}</a>
                @endif
            </div>
            @else
            <ul class="mt-4 space-y-4" data-feed>
                @include('linimasa.partials.cards', ['cards' => $cards, 'more' => $more])
            </ul>
            @endif
        </section>
    </div>
</div>
@endsection
