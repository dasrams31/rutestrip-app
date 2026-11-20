@extends('layouts.app')

@section('title', __('Kuesioner SUS'))

@php
    $scale = [
        1 => __('Sangat tidak setuju'),
        2 => __('Tidak setuju'),
        3 => __('Netral'),
        4 => __('Setuju'),
        5 => __('Sangat setuju'),
    ];
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'eyebrow' => __('Kuesioner kegunaan (System Usability Scale)'),
        'title' => __('Bagaimana rasanya memakai RuteStrip?'),
        'lead' => __('Sepuluh pernyataan pendek, kira-kira dua menit. Isi setelah mencoba mencari jalur atau melihat riwayat file GPX. Jawabanmu dipakai untuk penelitian RuteStrip.'),
        'width' => 'max-w-3xl',
        'seed' => 101,
    ])

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 sm:pt-10">
        <form action="{{ route('evaluation.sus.store') }}" method="POST" class="space-y-6">
            @csrf

            <section class="rs-card p-5 sm:p-7 grid grid-cols-1 sm:grid-cols-2 gap-5" aria-labelledby="who-title">
                <h2 id="who-title" class="sm:col-span-2 font-semibold">{{ __('Tentang kamu') }} <span class="font-normal rs-muted">({{ __('boleh dikosongkan') }})</span></h2>
                <div class="min-w-0">
                    <label for="respondent_name" class="rs-label">{{ __('Nama') }}</label>
                    <input id="respondent_name" name="respondent_name" value="{{ old('respondent_name') }}" maxlength="255" autocomplete="name" class="rs-input" placeholder="{{ __('Contoh: Responden 1') }}">
                </div>
                <div class="min-w-0">
                    <label for="respondent_role" class="rs-label">{{ __('Peran') }}</label>
                    <input id="respondent_role" name="respondent_role" value="{{ old('respondent_role') }}" maxlength="255" class="rs-input" placeholder="{{ __('Contoh: pendaki pemula') }}">
                </div>
            </section>

            <section aria-labelledby="statements-title">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <h2 id="statements-title" class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('Sepuluh pernyataan') }}</h2>
                    <p class="text-sm rs-muted">{{ __('1 = sangat tidak setuju, 5 = sangat setuju') }}</p>
                </div>

                <div class="mt-4 space-y-4">
                    @foreach($questions as $number => $question)
                    @php $invalid = $errors->has('q' . $number); @endphp
                    <fieldset class="rs-card p-4 sm:p-6 min-w-0" @if($invalid) style="border-color:#b91c1c" aria-describedby="q{{ $number }}-error" @endif>
                        <legend class="sr-only">{{ $number }}. {{ __($question) }}</legend>
                        <p class="flex gap-3 font-semibold leading-snug" aria-hidden="true">
                            <span class="rs-display rs-num text-2xl leading-none text-[#047857] w-7 shrink-0">{{ $number }}</span>
                            <span class="min-w-0">{{ __($question) }}</span>
                        </p>
                        <div class="mt-4 grid grid-cols-5 gap-1.5 sm:gap-3">
                            @foreach($scale as $score => $label)
                            <label class="relative flex flex-col items-center justify-center min-h-[52px] rounded-lg border-2 border-[#dcd8c9] bg-white cursor-pointer select-none hover:border-[#7c8c84]
                                          has-[:checked]:border-[#047857] has-[:checked]:bg-[#047857] has-[:checked]:text-white
                                          has-[:focus-visible]:outline has-[:focus-visible]:outline-[3px] has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-[#053b30]">
                                <input type="radio" name="q{{ $number }}" value="{{ $score }}" class="sr-only" @checked((int) old('q' . $number) === $score) required>
                                <span class="rs-display rs-num text-2xl leading-none">{{ $score }}</span>
                                <span class="sr-only">{{ $label }}</span>
                            </label>
                            @endforeach
                        </div>
                        <div class="mt-1.5 flex justify-between gap-4 text-xs rs-muted" aria-hidden="true">
                            <span>{{ $scale[1] }}</span><span class="text-right">{{ $scale[5] }}</span>
                        </div>
                        @if($invalid)
                        <p id="q{{ $number }}-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ __('Pilih salah satu angka untuk pernyataan ini.') }}</span></p>
                        @endif
                    </fieldset>
                    @endforeach
                </div>
            </section>

            <section class="rs-card p-5 sm:p-7">
                <label for="notes" class="rs-label">{{ __('Ada masukan lain?') }} <span class="font-normal rs-muted">({{ __('opsional') }})</span></label>
                <textarea id="notes" name="notes" rows="4" maxlength="2000" class="rs-input" placeholder="{{ __('Misalnya bagian yang membingungkan atau fitur yang kamu cari tapi tidak ada.') }}">{{ old('notes') }}</textarea>
            </section>

            <button type="submit" class="rs-sign w-full sm:w-auto text-base">{{ __('Kirim jawaban') }}</button>
        </form>
    </div>
</div>
@endsection
