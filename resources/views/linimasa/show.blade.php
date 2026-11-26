@extends('layouts.app')

@php
    $typeLabels = ['status' => __('Status'), 'photo' => __('Foto'), 'recording' => __('Rekaman'), 'story' => __('Cerita')];
    $name = $post['author']['name'];
    $isEn = app()->getLocale() === 'en';
    $num = fn ($v, int $d = 0) => number_format((float) $v, $d, $isEn ? '.' : ',', $isEn ? ',' : '.');
    $summary = filled($post['body'])
        ? \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', $post['body']), 200)
        : match (true) {
            $post['type'] === 'recording' && isset($post['stats']['distance_km']) => __('Rekaman pendakian :km km dari :name di RuteStrip.', ['km' => $num($post['stats']['distance_km'], 1), 'name' => $name]),
            $post['photo_url'] !== null => __('Foto dari :name di Linimasa RuteStrip.', ['name' => $name]),
            default => __('Kiriman :name di Linimasa RuteStrip.', ['name' => $name]),
        };
    // Open Graph size of the re-encoded JPEG (read from the stored file).
    $ogSize = $model->photo_path ? @getimagesize(\Illuminate\Support\Facades\Storage::disk('public')->path($model->photo_path)) : null;
    $mapData = [
        'track' => $post['track'],
        'place' => $post['place'] ? [$post['place']['lat'], $post['place']['lng']] : null,
    ];
    $hasMap = $post['track'] || $post['place'];
@endphp

@section('title', __(':type dari :name', ['type' => $typeLabels[$post['type']] ?? __('account.linimasa.posts'), 'name' => $name]))
@section('meta_description', $summary)
@section('og_type', 'article')
@section('robots', 'noindex, nofollow')
@if($post['photo_url'])
@section('og_image', $post['photo_url'])
@section('og_image_alt', __('Foto dari :name', ['name' => $name]))
@if($ogSize)
@section('og_image_width', (string) $ogSize[0])
@section('og_image_height', (string) $ogSize[1])
@endif
@endif

@section('content')
@include('partials.rs-kit')
@include('linimasa.partials.scripts')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => auth()->check()
            ? ['href' => route('linimasa'), 'label' => __('Linimasa')]
            : ['href' => route('profiles.show', $post['author']['id']), 'label' => __('Profil :name', ['name' => $name])],
        'title' => __(':type dari :name', ['type' => $typeLabels[$post['type']] ?? __('account.linimasa.posts'), 'name' => $name]),
        'titleClass' => 'text-[clamp(2rem,7vw,3.25rem)]',
        'width' => 'max-w-3xl',
        'seed' => 37 + ($post['id'] % 50),
    ])

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
        @if($hasMap)
        {{-- Track or marked spot on the map (Leaflet, vendored). The line drawing stays when scripts are off. --}}
        <div class="rs-card overflow-hidden">
            <div id="post-map" class="relative h-72 sm:h-96 bg-[#eef4ef]" data-map='@json($mapData)'>
                @if($post['track'])
                <div class="absolute inset-0 flex items-center" data-map-fallback>
                    @include('linimasa.partials.track', ['track' => $post['track'], 'label' => __('Gambar jalur rekaman :name', ['name' => $name])])
                </div>
                @endif
            </div>
            <p class="px-4 py-2 text-xs rs-muted border-t border-[#ece9de]">{{ __('Peta') }} &copy; OpenStreetMap contributors</p>
        </div>
        @endif

        @include('linimasa.partials.card', ['p' => $post, 'full' => true, 'headingTag' => 'h2'])

        {{-- Comments, oldest first. --}}
        <section id="komentar" class="rs-card p-4 sm:p-6 scroll-mt-24" aria-labelledby="comments-title">
            <h2 id="comments-title" class="rs-display uppercase text-3xl leading-none text-[#053b30]">
                {{ __('Komentar') }} <span class="rs-num text-[#4b5d55]">{{ $post['comments_count'] }}</span>
            </h2>

            @if(empty($comments))
            <p class="mt-3 rs-muted">{{ __('Belum ada komentar.') }}</p>
            @else
            <ol class="mt-4 divide-y divide-[#ece9de]">
                @foreach($comments as $c)
                <li id="komentar-{{ $c['id'] }}" class="py-4 first:pt-0 scroll-mt-24 flex gap-3">
                    <a href="{{ route('profiles.show', $c['author']['id']) }}" tabindex="-1" aria-hidden="true" class="shrink-0 rounded-lg">
                        @include('linimasa.partials.avatar', ['name' => $c['author']['name'], 'size' => 'sm'])
                    </a>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm">
                            <a href="{{ route('profiles.show', $c['author']['id']) }}" class="font-semibold hover:underline [overflow-wrap:anywhere]">{{ $c['author']['name'] }}</a>
                            <span class="rs-muted">&middot; <time datetime="{{ $c['created_at'] }}">{{ \Illuminate\Support\Carbon::parse($c['created_at'])->locale(app()->getLocale())->diffForHumans() }}</time></span>
                        </p>
                        <p class="mt-1 whitespace-pre-wrap [overflow-wrap:anywhere] leading-relaxed">{{ $c['body'] }}</p>
                        @auth
                        <div class="mt-1 flex flex-wrap gap-x-4">
                            @if($c['can_delete'])
                            <form method="POST" action="{{ route('comments.destroy', $c['id']) }}" data-confirm="{{ __('Hapus komentar ini?') }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="inline-flex items-center min-h-[44px] text-sm font-semibold text-[#991b1b] underline underline-offset-4">{{ __('Hapus') }}</button>
                            </form>
                            @endif
                            @unless($c['is_mine'])
                            <form method="POST" action="{{ route('comments.report', $c['id']) }}" data-confirm="{{ __('Laporkan komentar ini ke admin?') }}">
                                @csrf
                                <button type="submit" class="inline-flex items-center min-h-[44px] text-sm font-semibold text-[#4b5d55] underline underline-offset-4 hover:text-[#053b30]">{{ __('Laporkan') }}</button>
                            </form>
                            @endunless
                        </div>
                        @endauth
                    </div>
                </li>
                @endforeach
            </ol>
            @if($moreComments)
            <a href="{{ $moreComments }}" class="rs-btn-ghost rs-btn-sm w-full mt-2">{{ __('Komentar berikutnya') }}</a>
            @endif
            @endif

            @auth
            <form method="POST" action="{{ route('posts.comments.store', $post['id']) }}" class="mt-5 space-y-3">
                @csrf
                <label for="comment-body" class="rs-label">{{ __('Tulis komentar') }}</label>
                <textarea id="comment-body" name="body" rows="3" maxlength="{{ \App\Services\SocialService::COMMENT_MAX }}" required
                          class="rs-input" @error('body') aria-invalid="true" aria-describedby="comment-body-error" @enderror>{{ old('body') }}</textarea>
                @error('body')
                <p id="comment-body-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                @enderror
                <button type="submit" class="rs-btn w-full sm:w-auto" data-busy-label="{{ __('Mengirim...') }}">{{ __('Kirim komentar') }}</button>
            </form>
            @else
            <div class="mt-5 rounded-lg border border-[#dcd8c9] bg-[#faf9f4] p-4">
                <p class="rs-muted">{{ __('Masuk ke akun RuteStrip untuk menyukai, menyimpan, dan mengomentari kiriman ini.') }}</p>
                <a href="{{ route('posts.login', $post['id']) }}" class="rs-sign mt-3 w-full sm:w-auto">{{ __('Masuk untuk berkomentar') }}</a>
            </div>
            @endauth
        </section>
    </div>
</div>
@endsection

@if($hasMap)
@push('scripts')
<script>
(function () {
    var el = document.getElementById('post-map');
    if (!el || typeof L === 'undefined') return;
    var data;
    try { data = JSON.parse(el.dataset.map); } catch (e) { return; }
    var fallback = el.querySelector('[data-map-fallback]');
    if (fallback) fallback.remove();
    var map = L.map(el, { zoomControl: true, scrollWheelZoom: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 17,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);
    if (data.track && data.track.length > 1) {
        L.polyline(data.track, { color: '#ffffff', weight: 8, opacity: 0.85, lineJoin: 'round', lineCap: 'round' }).addTo(map);
        var line = L.polyline(data.track, { color: '#047857', weight: 5, opacity: 1, lineJoin: 'round', lineCap: 'round' }).addTo(map);
        L.circleMarker(data.track[0], { radius: 7, color: '#053b30', weight: 3, fillColor: '#ffffff', fillOpacity: 1 }).addTo(map);
        L.circleMarker(data.track[data.track.length - 1], { radius: 7, color: '#ffffff', weight: 2, fillColor: '#053b30', fillOpacity: 1 }).addTo(map);
        map.fitBounds(line.getBounds(), { padding: [24, 24] });
    }
    if (data.place) {
        L.circleMarker(data.place, { radius: 9, color: '#ffffff', weight: 3, fillColor: '#047857', fillOpacity: 1 }).addTo(map);
        if (!data.track) map.setView(data.place, 14);
    }
})();
</script>
@endpush
@endif
