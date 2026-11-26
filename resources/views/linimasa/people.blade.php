@extends('layouts.app')

@php
    $title = $which === 'followers' ? __('Pengikut :name', ['name' => $user->name]) : __('Diikuti :name', ['name' => $user->name]);
    $me = auth()->user();
@endphp

@section('title', $title)
@section('robots', 'noindex, nofollow')

@section('content')
@include('partials.rs-kit')
@include('linimasa.partials.scripts')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => ['href' => route('profiles.show', $user->id), 'label' => __('Profil :name', ['name' => $user->name])],
        'title' => $which === 'followers' ? __('Pengikut') : __('Mengikuti'),
        'lead' => $which === 'followers'
            ? __(':count pendaki mengikuti :name.', ['count' => $profile['followers_count'], 'name' => $user->name])
            : __(':name mengikuti :count pendaki.', ['count' => $profile['following_count'], 'name' => $user->name]),
        'width' => 'max-w-3xl',
        'seed' => 23 + ($user->id % 60),
        'tabs' => [
            ['href' => route('profiles.followers', $user->id), 'label' => __('Pengikut'), 'active' => $which === 'followers'],
            ['href' => route('profiles.following', $user->id), 'label' => __('Mengikuti'), 'active' => $which === 'following'],
        ],
        'tabsLabel' => __('Daftar pendaki'),
    ])

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        @if(empty($people))
        <div class="rs-card p-6">
            <p class="rs-muted">{{ $which === 'followers' ? __('Belum ada yang mengikuti :name.', ['name' => $user->name]) : __(':name belum mengikuti siapa pun.', ['name' => $user->name]) }}</p>
        </div>
        @else
        <ul class="rs-card divide-y divide-[#ece9de]">
            @foreach($people as $person)
            <li class="flex items-center gap-3 px-4 sm:px-5 py-3">
                <a href="{{ route('profiles.show', $person['id']) }}" tabindex="-1" aria-hidden="true" class="shrink-0 rounded-lg">
                    @include('linimasa.partials.avatar', ['name' => $person['name'], 'size' => 'md'])
                </a>
                <div class="min-w-0 flex-1">
                    <a href="{{ route('profiles.show', $person['id']) }}" class="font-semibold hover:underline underline-offset-2 [overflow-wrap:anywhere]">{{ $person['name'] }}</a>
                    @if(filled($person['bio']))
                    <p class="text-sm rs-muted truncate">{{ $person['bio'] }}</p>
                    @endif
                </div>
                @if($me && (int) $me->id !== (int) $person['id'])
                @include('linimasa.partials.follow-button', ['userId' => $person['id'], 'following' => $person['is_following'], 'main' => false, 'name' => $person['name']])
                @endif
            </li>
            @endforeach
        </ul>
        @if($more)
        <a href="{{ $more }}" class="rs-btn-ghost w-full mt-4">{{ __('Halaman berikutnya') }}</a>
        @endif
        @endif
    </div>
</div>
@endsection
