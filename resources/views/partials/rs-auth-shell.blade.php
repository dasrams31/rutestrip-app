{{--
    Shell for the sign-in pages: a zamrud panel with contour lines (headline, what an
    account gives you) next to the form on map paper. On phones the panel shrinks to a
    short band above the form, so the form is reached without scrolling past a poster.
    Use with @component('partials.rs-auth-shell', ['eyebrow' =>, 'title' =>, 'lead' =>, 'perks' => bool, 'seed' => int]).
--}}
@include('partials.rs-kit')
@php
    $hasFlash = session('success') || session('warning') || (isset($errors) && $errors->any());
    $perks = ($perks ?? true) ? [
        __('account.perks.favorites'),
        __('account.perks.history'),
        __('account.perks.upload'),
        __('account.perks.chat'),
    ] : [];
@endphp
<div class="rs-app">
    <div class="lg:grid lg:grid-cols-12 lg:min-h-[calc(100vh-4rem)]">
        <section class="rs-on-dark relative overflow-hidden bg-[#053b30] text-white lg:col-span-5 {{ $hasFlash ? 'mt-4' : '-mt-4' }}">
            @include('partials.rs-contours', ['seed' => $seed ?? 11, 'tone' => 'dark', 'class' => 'absolute inset-0 w-full h-full pointer-events-none', 'peaks' => [[900, 120, 13], [140, 420, 9]]])
            <div class="relative max-w-xl mx-auto lg:mr-0 lg:ml-auto px-4 sm:px-8 lg:px-12 pt-6 pb-7 sm:pt-10 sm:pb-10 lg:pt-20 lg:pb-16">
                @if(!empty($eyebrow))
                <p class="text-sm sm:text-base font-semibold text-[#a7f3d0]">{{ $eyebrow }}</p>
                @endif
                <h1 class="rs-display mt-2 uppercase text-[clamp(2.1rem,9vw,3.25rem)] lg:text-[3.75rem] leading-[0.95] [text-wrap:balance]">{{ $title }}</h1>
                @if(!empty($lead))
                <p class="mt-3 sm:mt-4 text-base sm:text-lg leading-relaxed text-[#d1fae5] max-w-md">{{ $lead }}</p>
                @endif
                @if($perks)
                <div class="hidden lg:block mt-10 border-t border-white/15 pt-6">
                    <p class="font-semibold text-[#a7f3d0]">{{ __('account.perks.title') }}</p>
                    <ul class="mt-4 space-y-3">
                        @foreach($perks as $perk)
                        <li class="flex gap-3 items-start text-[#ecfdf5]">
                            {{-- Summit marker, the same triangle used on the landing trail line. --}}
                            <svg class="w-4 h-4 mt-1 shrink-0" viewBox="0 0 26 22" aria-hidden="true"><path d="M13 2 L24 20 H2 Z" fill="#a7f3d0" stroke="#a7f3d0" stroke-width="2" stroke-linejoin="round"/></svg>
                            <span>{{ $perk }}</span>
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif
            </div>
        </section>

        <div class="lg:col-span-7 px-4 sm:px-8 pt-6 pb-12 sm:pt-10 lg:py-20">
            <div class="w-full max-w-md mx-auto">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
