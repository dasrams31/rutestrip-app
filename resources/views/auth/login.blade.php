@extends('layouts.app')

@section('title', __('account.login.title'))

@section('content')
@component('partials.rs-auth-shell', [
    'eyebrow' => __('account.login.subtitle'),
    'title' => __('account.login.heading'),
    'lead' => __('account.login.footer'),
    'seed' => 11,
])
<div class="rs-card p-5 sm:p-8">
    <form method="POST" action="{{ route('login.submit') }}" class="space-y-5">
        @csrf

        <div>
            <label for="email" class="rs-label">{{ __('account.login.email') }}</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}"
                   class="rs-input" placeholder="{{ __('nama@email.com') }}" autocomplete="email" inputmode="email" required
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
            <x-icon name="login" class="w-5 h-5" /> {{ __('account.login.submit') }}
        </button>
    </form>
</div>

<div class="mt-6 space-y-1 text-[0.9375rem]">
    <p class="flex flex-wrap items-center gap-x-2 min-h-[44px]">
        <span class="rs-muted">{{ __('account.login.no_account') }}</span>
        <a href="{{ route('register') }}" class="inline-flex items-center min-h-[44px] font-semibold text-[#047857] underline decoration-2 underline-offset-4 hover:text-[#053b30]">{{ __('account.login.register') }}</a>
    </p>
    <div class="flex flex-wrap gap-x-6 border-t rs-rule pt-2">
        <a href="{{ route('search.index') }}" class="inline-flex items-center gap-2 min-h-[44px] rs-muted hover:text-[#053b30]">
            <x-icon name="arrow-left" class="w-4 h-4" /> {{ __('account.login.back_home') }}
        </a>
        <a href="{{ route('admin.login') }}" class="inline-flex items-center gap-2 min-h-[44px] rs-muted hover:text-[#053b30]">
            <x-icon name="lock-closed" class="w-4 h-4" /> {{ __('account.login.admin_link') }}
        </a>
    </div>
</div>
@endcomponent
@endsection
