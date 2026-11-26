{{--
    Initial-based avatar (no photos): the same zamrud square as the navbar account button.
    Params: name, size ('sm' 36 px | 'md' 44 px | 'lg' 64 px), ring (bool: has an active story).
--}}
@php
    $initial = mb_strtoupper(mb_substr(trim((string) ($name ?? '')) ?: '?', 0, 1));
    $sizeClass = ['sm' => 'w-9 h-9 text-sm', 'md' => 'w-11 h-11 text-base', 'lg' => 'w-16 h-16 text-2xl'][$size ?? 'md'];
@endphp
<span class="{{ $sizeClass }} shrink-0 rounded-lg bg-[#047857] text-white font-bold inline-flex items-center justify-center {{ !empty($ring) ? 'ring-[3px] ring-[#047857] ring-offset-2 ring-offset-white' : '' }}" aria-hidden="true">{{ $initial }}</span>
