@extends('layouts.app')

@section('title', __('Linimasa'))

@php
    $me = auth()->user();
    // Story viewer data: only what the dialog shows, rendered with textContent (never as HTML).
    $storyData = array_map(fn ($g) => [
        'name' => $g['author']['name'],
        'profile' => route('profiles.show', $g['author']['id']),
        'items' => array_map(fn ($s) => [
            'body' => $s['body'],
            'photo' => $s['photo_url'],
            'when' => \Illuminate\Support\Carbon::parse($s['created_at'])->locale(app()->getLocale())->diffForHumans(),
            'url' => $s['share_url'],
        ], $g['items']),
    ], $stories);
    $myStories = ! empty($stories) && (int) $stories[0]['author']['id'] === (int) $me->id;
@endphp

@section('content')
@include('partials.rs-kit')
@include('linimasa.partials.scripts')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'title' => __('Linimasa'),
        'lead' => __('Catatan, foto, dan rekaman pendakian dari kamu dan pendaki yang kamu ikuti.'),
        'width' => 'max-w-5xl',
        'seed' => 211,
        'tabs' => [
            ['href' => route('linimasa', ['tab' => 'mengikuti']), 'label' => __('Mengikuti'), 'active' => $tab === 'mengikuti'],
            ['href' => route('linimasa', ['tab' => 'semua']), 'label' => __('Semua'), 'active' => $tab === 'semua'],
        ],
        'tabsLabel' => __('Pilih isi linimasa'),
    ])

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 lg:grid lg:grid-cols-12 lg:gap-8">
        <div class="lg:col-span-8 min-w-0">
            @if($firstPage)
            {{-- Stories: active 24 h posts of you and the people you follow. --}}
            <section aria-labelledby="stories-title" class="-mx-4 sm:mx-0">
                <h2 id="stories-title" class="sr-only">{{ __('Cerita 24 jam') }}</h2>
                <ul class="flex gap-4 overflow-x-auto px-4 sm:px-1.5 pt-1.5 pb-2" data-stories>
                    @unless($myStories)
                    <li class="shrink-0">
                        <a href="#tulis" data-new-story class="w-[4.5rem] flex flex-col items-center gap-1.5 rounded-lg text-center">
                            <span class="w-14 h-14 rounded-lg border-2 border-dashed border-[#7c8c84] bg-white text-[#047857] inline-flex items-center justify-center" aria-hidden="true">
                                <x-icon name="plus" class="w-6 h-6" />
                            </span>
                            <span class="text-xs font-semibold leading-tight">{{ __('Cerita baru') }}</span>
                        </a>
                    </li>
                    @endunless
                    @foreach($stories as $i => $group)
                    <li class="shrink-0">
                        <a href="{{ $group['items'][0]['share_url'] }}" data-story="{{ $i }}" class="w-[4.5rem] flex flex-col items-center gap-1.5 rounded-lg text-center">
                            <span class="p-0.5">@include('linimasa.partials.avatar', ['name' => $group['author']['name'], 'size' => 'md', 'ring' => true])</span>
                            <span class="text-xs font-semibold leading-tight max-w-full truncate">{{ (int) $group['author']['id'] === (int) $me->id ? __('Ceritamu') : \Illuminate\Support\Str::before(trim($group['author']['name']), ' ') }}</span>
                            <span class="sr-only">{{ __(':count cerita', ['count' => count($group['items'])]) }}</span>
                        </a>
                    </li>
                    @endforeach
                </ul>
            </section>

            {{-- Composer: status or photo, an optional trail, or a 24 h story. --}}
            <section id="tulis" class="mt-4 rs-card p-4 sm:p-5 scroll-mt-24" aria-labelledby="composer-title">
                <h2 id="composer-title" class="rs-display uppercase text-2xl leading-none text-[#053b30]">{{ __('Tulis kiriman') }}</h2>
                <form method="POST" action="{{ route('posts.store') }}" enctype="multipart/form-data" class="mt-3 space-y-4" data-composer>
                    @csrf
                    <div>
                        <label for="post-body" class="sr-only">{{ __('Isi kiriman') }}</label>
                        <textarea id="post-body" name="body" rows="3" maxlength="{{ \App\Services\SocialService::BODY_MAX }}"
                                  placeholder="{{ __('Kondisi jalur, cuaca di pos, atau cerita pendakianmu') }}"
                                  class="rs-input" @error('body') aria-invalid="true" aria-describedby="post-body-error" @enderror>{{ old('body') }}</textarea>
                        @error('body')
                        <p id="post-body-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="min-w-0">
                            <span class="rs-label">{{ __('Foto') }} <span class="font-normal rs-muted">({{ __('opsional') }})</span></span>
                            <label class="rs-btn-ghost rs-btn-sm w-full cursor-pointer has-[:focus-visible]:outline has-[:focus-visible]:outline-[3px] has-[:focus-visible]:outline-[#053b30] has-[:focus-visible]:outline-offset-2">
                                <x-icon name="photograph" class="w-5 h-5 shrink-0" />
                                <span class="truncate" data-photo-name data-empty="{{ __('Pilih foto') }}">{{ __('Pilih foto') }}</span>
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="sr-only" data-photo-input
                                       aria-describedby="post-photo-hint @error('photo') post-photo-error @enderror">
                            </label>
                            <p id="post-photo-hint" class="rs-hint">{{ __('JPG, PNG, atau WebP sampai 8 MB. Lokasi dan data kamera di dalam file dihapus.') }}</p>
                            @error('photo')
                            <p id="post-photo-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                            @enderror
                        </div>
                        <div class="min-w-0">
                            <label for="post-route" class="rs-label">{{ __('Tautkan jalur') }} <span class="font-normal rs-muted">({{ __('opsional') }})</span></label>
                            <select id="post-route" name="route_id" class="rs-input" @error('route_id') aria-invalid="true" @enderror>
                                <option value="">{{ __('Tanpa jalur') }}</option>
                                @foreach($routes as $route)
                                <option value="{{ $route->id }}" @selected((string) old('route_id') === (string) $route->id)>{{ $route->name }}</option>
                                @endforeach
                            </select>
                            @error('route_id')
                            <p class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <label class="inline-flex items-center gap-3 min-h-[44px] cursor-pointer">
                            <input type="checkbox" name="story" value="1" class="rs-check" data-story-check @checked(old('story'))>
                            <span class="text-[0.9375rem]">{{ __('Jadikan cerita, hilang setelah 24 jam') }}</span>
                        </label>
                        <button type="submit" class="rs-sign w-full sm:w-auto" data-busy-label="{{ __('Mengirim...') }}">{{ __('Kirim ke linimasa') }}</button>
                    </div>
                </form>
            </section>
            @endif

            <section class="mt-6" aria-labelledby="feed-title">
                <h2 id="feed-title" class="sr-only">{{ $tab === 'semua' ? __('Semua kiriman') : __('Kiriman dari yang kamu ikuti') }}</h2>
                @if(empty($cards))
                <div class="rs-card p-6 sm:p-8">
                    @if($tab === 'semua')
                    <p class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('Belum ada kiriman') }}</p>
                    <p class="mt-3 rs-muted max-w-prose">{{ __('Belum ada pendaki yang mengirim ke linimasa. Kiriman pertama bisa datang dari kotak di atas.') }}</p>
                    @else
                    <p class="rs-display uppercase text-3xl leading-none text-[#053b30]">{{ __('Linimasamu masih sepi') }}</p>
                    <p class="mt-3 rs-muted max-w-prose">{{ __('Di sini muncul kirimanmu dan kiriman pendaki yang kamu ikuti. Lihat tab Semua untuk menemukan pendaki lain.') }}</p>
                    <a href="{{ route('linimasa', ['tab' => 'semua']) }}" class="rs-btn mt-5 w-full sm:w-auto">{{ __('Buka tab Semua') }}</a>
                    @endif
                </div>
                @else
                <ul class="space-y-4" data-feed>
                    @include('linimasa.partials.cards', ['cards' => $cards, 'more' => $more])
                </ul>
                @endif
            </section>
        </div>

        {{-- Your profile at a glance (wide screens; phones reach it from the account menu). --}}
        <aside class="hidden lg:block lg:col-span-4" aria-labelledby="me-title">
            <div class="rs-card p-5 lg:sticky lg:top-24">
                <div class="flex items-center gap-3">
                    @include('linimasa.partials.avatar', ['name' => $me->name, 'size' => 'lg'])
                    <div class="min-w-0">
                        <h2 id="me-title" class="font-semibold text-lg leading-snug [overflow-wrap:anywhere]">{{ $me->name }}</h2>
                        <a href="{{ route('profiles.show', $me->id) }}" class="inline-flex items-center min-h-[44px] text-sm font-semibold text-[#047857] underline underline-offset-4 hover:text-[#053b30]">{{ __('Lihat profilmu') }}</a>
                    </div>
                </div>
                @if(filled($me->bio))
                <p class="mt-2 text-sm [overflow-wrap:anywhere]">{{ $me->bio }}</p>
                @else
                <p class="mt-2 text-sm rs-muted">{{ __('Belum ada bio.') }} <a href="{{ route('settings') }}#profile" class="font-semibold text-[#047857] underline underline-offset-4">{{ __('Tulis bio') }}</a></p>
                @endif
                <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
                    <div><dt class="text-xs rs-muted">{{ __('account.linimasa.posts') }}</dt><dd class="rs-display rs-num text-2xl text-[#053b30]">{{ $profile['posts_count'] }}</dd></div>
                    <div><dt class="text-xs rs-muted"><a href="{{ route('profiles.followers', $me->id) }}" class="hover:underline">{{ __('Pengikut') }}</a></dt><dd class="rs-display rs-num text-2xl text-[#053b30]">{{ $profile['followers_count'] }}</dd></div>
                    <div><dt class="text-xs rs-muted"><a href="{{ route('profiles.following', $me->id) }}" class="hover:underline">{{ __('Mengikuti') }}</a></dt><dd class="rs-display rs-num text-2xl text-[#053b30]">{{ $profile['following_count'] }}</dd></div>
                </dl>
                <a href="{{ route('account.saved') }}" class="rs-btn-ghost rs-btn-sm w-full mt-4"><x-icon name="bookmark" class="w-4 h-4 shrink-0" /><span>{{ __('Kiriman disimpan') }}</span></a>
            </div>
        </aside>
    </div>
</div>

{{-- Story viewer: one story at a time, Escape or the close button ends it. --}}
<dialog id="story-viewer" aria-labelledby="story-name" class="w-[min(100vw-2rem,28rem)] max-h-[calc(100dvh-2rem)] p-0 rounded-xl border-0 bg-white text-[#14231d]">
    <div class="flex items-center gap-3 px-4 py-3 border-b border-[#ece9de]">
        <div class="min-w-0 flex-1">
            <a id="story-name" href="#" class="font-semibold hover:underline [overflow-wrap:anywhere]"></a>
            <p class="text-sm text-[#4b5d55]"><span data-story-when></span> <span aria-hidden="true">&middot;</span> <span data-story-pos></span></p>
        </div>
        <button type="button" data-story-close class="w-11 h-11 rounded-lg inline-flex items-center justify-center hover:bg-[#f6f4ec]" aria-label="{{ __('Tutup cerita') }}">
            <x-icon name="x" class="w-5 h-5" />
        </button>
    </div>
    <img data-story-photo alt="" class="hidden w-full max-h-[60dvh] object-contain bg-[#eef4ef]">
    <p data-story-body class="px-4 py-4 whitespace-pre-wrap [overflow-wrap:anywhere] text-[1.0625rem] leading-relaxed"></p>
    <div class="flex items-center gap-2 px-4 pb-4">
        <button type="button" data-story-prev class="rs-btn-ghost rs-btn-sm">{{ __('Sebelumnya') }}</button>
        <a data-story-open href="#" class="ml-auto inline-flex items-center min-h-[44px] px-1 text-sm font-semibold whitespace-nowrap text-[#047857] underline underline-offset-4">{{ __('Buka kiriman') }}</a>
        <button type="button" data-story-next class="rs-btn rs-btn-sm">{{ __('Berikutnya') }}</button>
    </div>
</dialog>
@endsection

@push('scripts')
<script>
(function () {
    var groups = @json($storyData);
    var dialog = document.getElementById('story-viewer');
    var g = 0, i = 0;
    var posText = @json(__(':n dari :total'));

    function render() {
        var group = groups[g], item = group.items[i];
        var name = dialog.querySelector('#story-name');
        name.textContent = group.name;
        name.href = group.profile;
        dialog.querySelector('[data-story-when]').textContent = item.when;
        dialog.querySelector('[data-story-pos]').textContent = posText.replace(':n', i + 1).replace(':total', group.items.length);
        var img = dialog.querySelector('[data-story-photo]');
        if (item.photo) { img.src = item.photo; img.alt = @json(__('Foto cerita')); img.classList.remove('hidden'); }
        else { img.removeAttribute('src'); img.classList.add('hidden'); }
        var body = dialog.querySelector('[data-story-body]');
        body.textContent = item.body || '';
        body.classList.toggle('hidden', !item.body);
        dialog.querySelector('[data-story-open]').href = item.url;
        dialog.querySelector('[data-story-prev]').disabled = g === 0 && i === 0;
        dialog.querySelector('[data-story-next]').textContent = (g === groups.length - 1 && i === group.items.length - 1)
            ? @json(__('Selesai')) : @json(__('Berikutnya'));
    }
    function step(dir) {
        var group = groups[g];
        if (dir > 0) {
            if (i < group.items.length - 1) i++;
            else if (g < groups.length - 1) { g++; i = 0; }
            else { dialog.close(); return; }
        } else {
            if (i > 0) i--;
            else if (g > 0) { g--; i = groups[g].items.length - 1; }
        }
        render();
    }

    if (dialog && typeof dialog.showModal === 'function') {
        document.querySelectorAll('[data-story]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                g = parseInt(a.dataset.story, 10); i = 0;
                render();
                dialog.showModal();
            });
        });
        dialog.querySelector('[data-story-close]').addEventListener('click', function () { dialog.close(); });
        dialog.querySelector('[data-story-next]').addEventListener('click', function () { step(1); });
        dialog.querySelector('[data-story-prev]').addEventListener('click', function () { step(-1); });
        dialog.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight') step(1);
            if (e.key === 'ArrowLeft') step(-1);
        });
        dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });
    }

    // "Cerita baru": jump to the composer with the story box ticked.
    var newStory = document.querySelector('[data-new-story]');
    if (newStory) newStory.addEventListener('click', function () {
        var check = document.querySelector('[data-story-check]');
        if (check) check.checked = true;
        setTimeout(function () { var t = document.getElementById('post-body'); if (t) t.focus(); }, 50);
    });

    // Show the chosen photo's file name on the button.
    var input = document.querySelector('[data-photo-input]');
    if (input) input.addEventListener('change', function () {
        var label = document.querySelector('[data-photo-name]');
        label.textContent = input.files && input.files[0] ? input.files[0].name : label.dataset.empty;
    });
})();
</script>
@endpush
