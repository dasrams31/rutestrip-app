@extends('layouts.app')

@section('title', __('account.settings.title'))

@php
    $input = 'w-full px-4 py-3 border border-slate-200 rounded-xl text-base focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none';
    $currentLocale = old('locale', $user->locale ?: 'id');
@endphp

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6 sm:mb-8">
        <div class="min-w-0">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-800 flex items-center gap-2">
                <x-icon name="cog" class="w-7 h-7 text-emerald-600 shrink-0" /> {{ __('account.settings.title') }}
            </h1>
            <p class="text-slate-600 mt-1">{{ __('account.settings.subtitle') }}</p>
        </div>
        <a href="{{ route('user.dashboard') }}" class="inline-flex items-center gap-1 py-2 text-emerald-700 hover:underline">
            <x-icon name="arrow-left" class="w-4 h-4" /> {{ __('account.settings.back') }}
        </a>
    </div>

    {{-- Profile --}}
    <section id="profile" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 sm:p-6 mb-5" aria-labelledby="profile-heading">
        <h2 id="profile-heading" class="text-lg font-bold text-slate-800 flex items-center gap-2">
            <x-icon name="user" class="w-5 h-5 text-slate-500" /> {{ __('account.settings.profile') }}
        </h2>
        <p class="text-sm text-slate-500 mt-1 mb-4">{{ __('account.settings.profile_help') }}</p>

        <form method="POST" action="{{ route('settings.update') }}">
            @csrf
            <input type="hidden" name="section" value="profile">

            <div class="mb-4">
                <label for="name" class="block text-sm font-semibold text-slate-700 mb-2">{{ __('account.settings.name') }}</label>
                <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" autocomplete="name" required maxlength="255"
                       class="{{ $input }} @error('name') border-red-500 @enderror">
                @error('name')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-5">
                <label for="email" class="block text-sm font-semibold text-slate-700 mb-2">{{ __('account.settings.email') }}</label>
                <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" autocomplete="email" required maxlength="255"
                       class="{{ $input }} @error('email') border-red-500 @enderror">
                @error('email')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full sm:w-auto px-6 py-3 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 font-medium">
                {{ __('account.settings.save_profile') }}
            </button>
        </form>
    </section>

    {{-- Password --}}
    <section id="password" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 sm:p-6 mb-5" aria-labelledby="password-heading">
        <h2 id="password-heading" class="text-lg font-bold text-slate-800 flex items-center gap-2">
            <x-icon name="lock-closed" class="w-5 h-5 text-slate-500" /> {{ __('account.settings.password') }}
        </h2>
        <p class="text-sm text-slate-500 mt-1 mb-4">{{ __('account.settings.password_help') }}</p>

        <form method="POST" action="{{ route('settings.update') }}">
            @csrf
            <input type="hidden" name="section" value="password">
            {{-- Lets password managers attach the new password to the right account --}}
            <input type="email" name="username" value="{{ $user->email }}" autocomplete="username" class="hidden" tabindex="-1" aria-hidden="true" readonly>

            <div class="mb-4">
                <label for="current_password" class="block text-sm font-semibold text-slate-700 mb-2">{{ __('account.settings.current_password') }}</label>
                <input type="password" name="current_password" id="current_password" autocomplete="current-password" required
                       class="{{ $input }} @error('current_password') border-red-500 @enderror">
                @error('current_password')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label for="new_password" class="block text-sm font-semibold text-slate-700 mb-2">{{ __('account.settings.new_password') }}</label>
                <input type="password" name="password" id="new_password" autocomplete="new-password" required minlength="8"
                       aria-describedby="new_password_hint"
                       class="{{ $input }} @error('password') border-red-500 @enderror">
                <p id="new_password_hint" class="mt-1 text-xs text-slate-500">{{ __('account.settings.new_password_hint') }}</p>
                @error('password')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-5">
                <label for="password_confirmation" class="block text-sm font-semibold text-slate-700 mb-2">{{ __('account.settings.password_confirmation') }}</label>
                <input type="password" name="password_confirmation" id="password_confirmation" autocomplete="new-password" required minlength="8"
                       class="{{ $input }}">
            </div>

            <button type="submit" class="w-full sm:w-auto px-6 py-3 bg-slate-700 text-white rounded-xl hover:bg-slate-800 font-medium">
                {{ __('account.settings.save_password') }}
            </button>
        </form>
    </section>

    {{-- Language --}}
    <section id="language" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 sm:p-6" aria-labelledby="language-heading">
        <h2 id="language-heading" class="text-lg font-bold text-slate-800 flex items-center gap-2">
            <x-icon name="chat" class="w-5 h-5 text-slate-500" /> {{ __('account.settings.language') }}
        </h2>
        <p class="text-sm text-slate-500 mt-1 mb-4">{{ __('account.settings.language_help') }}</p>

        <form method="POST" action="{{ route('settings.update') }}">
            @csrf
            <input type="hidden" name="section" value="locale">

            <fieldset class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-5">
                <legend class="sr-only">{{ __('account.settings.language') }}</legend>
                @foreach (['id' => __('account.settings.language_id'), 'en' => __('account.settings.language_en')] as $code => $label)
                    <label class="flex items-center gap-3 px-4 py-3 border rounded-xl cursor-pointer hover:bg-slate-50 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50 {{ $currentLocale === $code ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200' }}">
                        <input type="radio" name="locale" value="{{ $code }}" class="w-4 h-4 text-emerald-600 focus:ring-emerald-500" @checked($currentLocale === $code)>
                        <span class="font-medium text-slate-700" lang="{{ $code }}">{{ $label }}</span>
                    </label>
                @endforeach
            </fieldset>
            @error('locale')
                <p class="-mt-3 mb-4 text-sm text-red-600">{{ $message }}</p>
            @enderror

            <button type="submit" class="w-full sm:w-auto px-6 py-3 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 font-medium">
                {{ __('account.settings.save_language') }}
            </button>
        </form>
    </section>
</div>
@endsection
