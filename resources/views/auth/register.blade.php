@extends('layouts.app')

@section('title', __('account.register.title'))

@section('content')
@component('partials.rs-auth-shell', [
    'eyebrow' => __('account.register.title'),
    'title' => __('account.register.heading'),
    'lead' => __('account.register.subtitle'),
    'seed' => 23,
])
<div class="rs-card p-5 sm:p-8">
    <form method="POST" action="{{ route('register.submit') }}" class="space-y-5">
        @csrf

        <div>
            <label for="name" class="rs-label">{{ __('account.register.name') }}</label>
            <input type="text" name="name" id="name" value="{{ old('name') }}" maxlength="255"
                   class="rs-input" placeholder="{{ __('account.register.name_placeholder') }}" autocomplete="name" required
                   @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
            @error('name')
                <p id="name-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
            @enderror
        </div>

        <div>
            <label for="email" class="rs-label">{{ __('account.register.email') }}</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}" maxlength="255"
                   class="rs-input" placeholder="{{ __('nama@email.com') }}" autocomplete="email" inputmode="email" required
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')
                <p id="email-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
            @enderror
        </div>

        <div>
            <label for="password" class="rs-label">{{ __('account.register.password') }}</label>
            <input type="password" name="password" id="password"
                   class="rs-input" autocomplete="new-password" minlength="8" required
                   aria-describedby="password-hint @error('password') password-error @enderror"
                   @error('password') aria-invalid="true" @enderror>
            <p id="password-hint" class="rs-hint">{{ __('account.register.password_placeholder') }}</p>
            @error('password')
                <p id="password-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="rs-label">{{ __('account.register.password_confirmation') }}</label>
            <input type="password" name="password_confirmation" id="password_confirmation"
                   class="rs-input" placeholder="{{ __('account.register.confirmation_placeholder') }}" autocomplete="new-password" minlength="8" required>
        </div>

        {{-- Honeypot: hidden from people, bots that fill every field are rejected --}}
        <div class="absolute -left-[9999px] w-px h-px overflow-hidden" aria-hidden="true">
            <label for="website">Website</label>
            <input type="text" name="website" id="website" value="" tabindex="-1" autocomplete="off">
        </div>

        {{-- Anti-robot check (self-hosted ALTCHA proof-of-work, runs in the browser) --}}
        <div>
            <p class="rs-label" id="altcha-label">{{ __('account.register.robot_label') }}</p>
            <altcha-widget class="rs-altcha" challenge="{{ route('altcha.challenge') }}" language="{{ app()->getLocale() === 'en' ? 'en' : 'id' }}" auto="onfocus" aria-describedby="altcha-hint"></altcha-widget>
            <p id="altcha-hint" class="rs-hint">{{ __('account.register.robot_hint') }}</p>
            @error('altcha')
                <p class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
            @enderror
        </div>

        <button type="submit" class="rs-sign w-full text-base">
            <x-icon name="user-add" class="w-5 h-5" /> {{ __('account.register.submit') }}
        </button>
    </form>
</div>

<p class="mt-6 flex flex-wrap items-center gap-x-2 min-h-[44px] text-[0.9375rem]">
    <span class="rs-muted">{{ __('account.register.has_account') }}</span>
    <a href="{{ route('login') }}" class="inline-flex items-center min-h-[44px] font-semibold text-[#047857] underline decoration-2 underline-offset-4 hover:text-[#053b30]">{{ __('account.register.login') }}</a>
</p>
@endcomponent
@endsection

@push('styles')
<style>
    .rs-altcha {
        display: block;
        min-height: 4.5rem;
        --altcha-max-width: 100%;
        --altcha-border-radius: 8px;
        --altcha-border-width: 1.5px;
        --altcha-border-color: #7c8c84;
        --altcha-color-base: #ffffff;
        --altcha-color-primary: #047857;
        --altcha-color-primary-content: #ffffff;
        --altcha-color-success: #047857;
        --altcha-color-success-content: #ffffff;
        --altcha-color-base-content: #14231d;
        --altcha-checkbox-outline-color: #053b30;
    }
</style>
@endpush

@push('scripts')
<script type="module" src="{{ asset('js/altcha-init.js') }}"></script>
@endpush
