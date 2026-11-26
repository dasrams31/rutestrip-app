{{--
    Follow / unfollow as a plain CSRF form (POST to follow, DELETE to stop); scripts.blade.php
    sends it with fetch and swaps the state in place. Params: userId, following (bool), main (bool:
    the dawn-yellow main action of the page), name (for the accessible label).
--}}
@php
    $offClass = !empty($main) ? 'rs-sign' : 'rs-btn';
@endphp
<form method="POST" action="{{ route('profiles.follow', $userId) }}" data-social="follow" data-user="{{ $userId }}" class="shrink-0 {{ !empty($main) ? 'w-full sm:w-auto' : '' }}">
    @csrf
    <input type="hidden" name="_method" value="{{ $following ? 'DELETE' : 'POST' }}">
    <button type="submit" aria-pressed="{{ $following ? 'true' : 'false' }}"
            data-on-class="rs-btn-ghost" data-off-class="{{ $offClass }}"
            data-label-on="{{ __('Mengikuti') }}" data-label-off="{{ __('Ikuti') }}"
            class="{{ $following ? 'rs-btn-ghost' : $offClass }} rs-btn-sm min-w-[7rem] {{ !empty($main) ? 'w-full sm:w-auto' : '' }}">
        <span data-label>{{ $following ? __('Mengikuti') : __('Ikuti') }}</span>
        <span class="sr-only">{{ $name ?? '' }}</span>
    </button>
</form>
