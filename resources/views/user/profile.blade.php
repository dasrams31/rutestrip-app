@extends('layouts.app')

@section('title', __('Edit Profil'))

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-800 flex items-center gap-2"><x-icon name="user" class="w-7 h-7 text-emerald-600" /> {{ __('Edit Profil') }}</h1>
            <p class="text-slate-600">{{ __('Ubah nama, email, dan kata sandi akunmu.') }}</p>
        </div>
        <a href="{{ route('user.dashboard') }}" class="text-emerald-600 hover:underline inline-flex items-center gap-1"><x-icon name="arrow-left" class="w-4 h-4" /> {{ __('Kembali') }}</a>
    </div>

    @if(session('success'))
    <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl text-green-700">
        {{ session('success') }}
    </div>
    @endif

    <!-- Profile Info -->
    <div class="bg-white rounded-2xl shadow-lg p-6 mb-6 border border-slate-100">
        <h2 class="text-lg font-bold text-slate-800 mb-4">{{ __('Informasi Profil') }}</h2>

        <form method="POST" action="{{ route('user.profile.update') }}">
            @csrf
            @method('PUT')

            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">{{ __('Nama') }}</label>
                <input type="text" name="name" value="{{ old('name', $user->name) }}"
                       class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none @error('name') border-red-500 @enderror">
                @error('name')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">{{ __('Email') }}</label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}"
                       class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none @error('email') border-red-500 @enderror">
                @error('email')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="px-6 py-3 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 font-medium">
                {{ __('Simpan Perubahan') }}
            </button>
        </form>
    </div>

    <!-- Change Password -->
    <div class="bg-white rounded-2xl shadow-lg p-6 border border-slate-100">
        <h2 class="text-lg font-bold text-slate-800 mb-4">{{ __('Ubah Password') }}</h2>

        <form method="POST" action="{{ route('user.password.update') }}">
            @csrf
            @method('PUT')

            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">{{ __('Password Saat Ini') }}</label>
                <input type="password" name="current_password"
                       class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none @error('current_password') border-red-500 @enderror">
                @error('current_password')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">{{ __('Password Baru') }}</label>
                <input type="password" name="password"
                       class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none @error('password') border-red-500 @enderror">
                @error('password')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">{{ __('Konfirmasi Password Baru') }}</label>
                <input type="password" name="password_confirmation"
                       class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none">
            </div>

            <button type="submit" class="px-6 py-3 bg-slate-600 text-white rounded-xl hover:bg-slate-700 font-medium">
                {{ __('Ubah Password') }}
            </button>
        </form>
    </div>
</div>
@endsection
