@props(['dark' => false])
@php
    $current = app()->getLocale();
    $base = $dark
        ? 'text-slate-400 hover:text-white'
        : 'text-slate-500 hover:text-slate-900';
    $active = $dark ? 'bg-slate-700 text-white' : 'bg-emerald-600 text-white';
@endphp
<div {{ $attributes->merge(['class' => 'inline-flex items-center rounded-lg p-0.5 text-xs font-semibold ' . ($dark ? 'bg-slate-800/80' : 'bg-slate-100')]) }}
     role="group" aria-label="{{ __('Bahasa') }}">
    @foreach(['id' => 'ID', 'en' => 'EN'] as $code => $label)
    <a href="{{ route('lang.switch', $code) }}" rel="nofollow" hreflang="{{ $code }}" lang="{{ $code }}"
       title="{{ $code === 'id' ? 'Bahasa Indonesia' : 'English' }}"
       @if($current === $code) aria-current="true" @endif
       class="px-2.5 py-1 rounded-md transition-colors {{ $current === $code ? $active : $base }}">{{ $label }}</a>
    @endforeach
</div>
