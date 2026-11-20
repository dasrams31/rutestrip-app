@extends('layouts.app')

@section('title', __('account.admin.title'))

@section('content')
@component('partials.rs-auth-shell', [
    'title' => __('account.admin.heading'),
    'lead' => __('account.admin.subtitle'),
    'perks' => false,
    'seed' => 37,
])
<div class="rs-card p-5 sm:p-8">
    <form method="POST" action="{{ route('admin.login.submit') }}" class="space-y-5">
        @csrf

        <div>
            <label for="email" class="rs-label">{{ __('account.admin.email') }}</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}"
                   class="rs-input" autocomplete="email" inputmode="email" required
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')
                <p id="email-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
            @enderror
        </div>

        <div>
            <label for="password" class="rs-label">{{ __('account.login.password') }}</label>
            <input type="password" name="password" id="password"
                   class="rs-input" autocomplete="current-password" required
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            @error('password')
                <p id="password-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
            @enderror
        </div>

        <label class="flex items-center gap-3 min-h-[44px] cursor-pointer">
            <input type="checkbox" name="remember" value="1" class="rs-check" @checked(old('remember'))>
            <span class="text-[0.9375rem]">{{ __('account.login.remember') }}</span>
        </label>

        <button type="submit" class="rs-sign w-full text-base">
            <x-icon name="shield-check" class="w-5 h-5" /> {{ __('account.admin.submit') }}
        </button>
    </form>
</div>

<div class="mt-6 space-y-1 text-[0.9375rem]">
    <a href="{{ route('login') }}" class="inline-flex items-center gap-2 min-h-[44px] font-semibold text-[#047857] underline decoration-2 underline-offset-4 hover:text-[#053b30]">{{ __('account.admin.user_link') }}</a>
    <div class="border-t rs-rule pt-2">
        <a href="{{ route('search.index') }}" class="inline-flex items-center gap-2 min-h-[44px] rs-muted hover:text-[#053b30]">
            <x-icon name="arrow-left" class="w-4 h-4" /> {{ __('account.login.back_home') }}
        </a>
    </div>
    <p class="flex items-center gap-2 pt-2 text-sm rs-muted"><x-icon name="lock-closed" class="w-4 h-4 shrink-0" /> {{ __('account.admin.footer') }}</p>
</div>
@endcomponent
@endsection
