{{--
    Activity entries as plain sentences, newest first.
    Params: entries (ActivityLog collection), empty (text when there are none).
--}}
@if($entries->isEmpty())
<p class="px-5 py-6 text-sm rs-muted">{{ $empty }}</p>
@else
<ol class="divide-y divide-[#ece9de]">
    @foreach($entries as $entry)
    @php $bad = in_array($entry->action, ['auth.failed', 'auth.admin_denied', 'community.message_reported'], true); @endphp
    <li class="flex gap-3 px-4 sm:px-5 py-3 min-w-0">
        {{-- Trail-blaze tick: zamrud for normal entries, red only for sign-in trouble and reports (always with the word in the chip). --}}
        <span class="mt-1.5 w-1 h-5 rounded-sm shrink-0 {{ $bad ? 'bg-[#b91c1c]' : 'bg-[#047857]' }}" aria-hidden="true"></span>
        <div class="min-w-0 flex-1">
            <p class="[overflow-wrap:anywhere]">
                @if($entry->user)
                <a href="{{ route('admin.users.show', $entry->user) }}" class="font-semibold text-[#047857] hover:text-[#053b30] underline-offset-2 hover:underline">{{ $entry->sentence() }}</a>
                @else
                <span class="font-semibold">{{ $entry->sentence() }}</span>
                @endif
            </p>
            <p class="mt-0.5 text-xs rs-muted flex flex-wrap gap-x-3 gap-y-1">
                <time datetime="{{ $entry->created_at?->toIso8601String() }}" title="{{ $entry->created_at?->translatedFormat('j M Y, H:i') }}">{{ $entry->created_at?->diffForHumans() }}</time>
                <span class="rs-chip {{ $bad ? 'rs-chip-bad' : 'rs-chip-plain' }} !py-0 !text-xs">{{ $entry->label() }}</span>
            </p>
        </div>
    </li>
    @endforeach
</ol>
@endif
