{{--
    A page of post cards plus the "Muat lagi" link. Also the whole response when the
    link is fetched (X-Requested-With), so new cards replace the link in place.
    Params: cards (presented posts), more (next page URL or null).
--}}
@foreach($cards as $card)
<li>@include('linimasa.partials.card', ['p' => $card])</li>
@endforeach
@if($more)
<li data-more-wrap class="pt-2">
    <a href="{{ $more }}" data-more class="rs-btn-ghost w-full" data-loading="{{ __('Memuat kiriman...') }}" data-failed="{{ __('Gagal memuat. Ketuk untuk mencoba lagi.') }}">{{ __('Muat lagi') }}</a>
</li>
@endif
