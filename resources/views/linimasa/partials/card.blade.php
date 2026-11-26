{{--
    One Linimasa post (array from App\Services\SocialPresenter::post). Params: p, full (bool:
    the single post page shows the whole text and leaves out the "open" links), headingTag.
    Guests see the post and its counts; the forms are for signed-in hikers only.
--}}
@php
    $me = auth()->user();
    $full = $full ?? false;
    $isEn = app()->getLocale() === 'en';
    $num = fn ($v, int $d = 0) => number_format((float) $v, $d, $isEn ? '.' : ',', $isEn ? ',' : '.');
    $typeLabels = ['status' => __('Status'), 'photo' => __('Foto'), 'recording' => __('Rekaman'), 'story' => __('Cerita')];
    $created = \Illuminate\Support\Carbon::parse($p['created_at'])->locale(app()->getLocale());
    $expires = $p['expires_at'] ? \Illuminate\Support\Carbon::parse($p['expires_at'])->locale(app()->getLocale()) : null;
    $postUrl = route('posts.show', $p['id']);
    $profileUrl = route('profiles.show', $p['author']['id']);
    $body = (string) $p['body'];
    $long = ! $full && mb_strlen($body) > 600;
    $stats = $p['stats'];
    $moving = $stats['moving_s'] ?? null;
    $headingTag = $headingTag ?? 'h2';
@endphp
<article id="post-{{ $p['id'] }}" class="rs-card overflow-visible scroll-mt-24" data-post="{{ $p['id'] }}" aria-labelledby="post-{{ $p['id'] }}-by">
    <header class="flex items-start gap-3 px-4 sm:px-5 pt-4">
        <a href="{{ $profileUrl }}" class="shrink-0 rounded-lg" tabindex="-1" aria-hidden="true">
            @include('linimasa.partials.avatar', ['name' => $p['author']['name'], 'size' => 'md'])
        </a>
        <div class="min-w-0 flex-1">
            <{{ $headingTag }} id="post-{{ $p['id'] }}-by" class="font-semibold leading-snug [overflow-wrap:anywhere]">
                <a href="{{ $profileUrl }}" class="text-[#14231d] hover:text-[#047857] hover:underline underline-offset-2">{{ $p['author']['name'] }}</a>
            </{{ $headingTag }}>
            <p class="text-sm rs-muted flex flex-wrap items-center gap-x-2">
                <span>{{ $typeLabels[$p['type']] ?? $p['type'] }}</span>
                <span aria-hidden="true">&middot;</span>
                @if($full)
                <time datetime="{{ $p['created_at'] }}" title="{{ $created->translatedFormat('j M Y, H:i') }} UTC">{{ $created->diffForHumans() }}</time>
                @else
                <a href="{{ $postUrl }}" class="hover:underline underline-offset-2"><time datetime="{{ $p['created_at'] }}" title="{{ $created->translatedFormat('j M Y, H:i') }} UTC">{{ $created->diffForHumans() }}</time></a>
                @endif
                @if($expires)
                <span aria-hidden="true">&middot;</span>
                <span>{{ __('hilang dalam :time', ['time' => $expires->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE])]) }}</span>
                @endif
            </p>
        </div>

        @auth
        {{-- Less common actions behind one menu: works without JavaScript (details/summary). --}}
        <details class="relative shrink-0" data-menu>
            <summary class="rs-summary w-11 h-11 -mr-2 -mt-1 rounded-lg inline-flex items-center justify-center text-[#4b5d55] hover:bg-[#f6f4ec] cursor-pointer" aria-label="{{ __('Pilihan lain untuk kiriman ini') }}">
                <x-icon name="dots-horizontal" class="w-5 h-5" />
            </summary>
            <div class="absolute right-0 z-20 mt-1 w-56 rounded-lg border border-[#dcd8c9] bg-white shadow-lg py-1">
                @unless($full)
                <a href="{{ $postUrl }}" class="flex items-center gap-2 min-h-[44px] px-4 text-sm font-semibold hover:bg-[#f6f4ec]">{{ __('Buka kiriman') }}</a>
                @endunless
                @if($p['is_mine'])
                <form method="POST" action="{{ route('posts.destroy', $p['id']) }}" data-confirm="{{ __('Hapus kiriman ini? Tidak bisa dibatalkan.') }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="w-full flex items-center gap-2 min-h-[44px] px-4 text-sm font-semibold text-[#991b1b] hover:bg-[#fef2f2]">
                        <x-icon name="trash" class="w-4 h-4" />{{ __('Hapus kiriman') }}
                    </button>
                </form>
                @else
                <form method="POST" action="{{ route('posts.report', $p['id']) }}" data-confirm="{{ __('Laporkan kiriman ini ke admin?') }}">
                    @csrf
                    <button type="submit" class="w-full flex items-center gap-2 min-h-[44px] px-4 text-sm font-semibold hover:bg-[#f6f4ec]">
                        <x-icon name="flag" class="w-4 h-4" />{{ __('Laporkan ke admin') }}
                    </button>
                </form>
                @endif
            </div>
        </details>
        @endauth
    </header>

    @if($body !== '')
    <p class="px-4 sm:px-5 mt-3 whitespace-pre-wrap [overflow-wrap:anywhere] leading-relaxed {{ $full ? 'text-[1.0625rem]' : '' }}">{{ $long ? \Illuminate\Support\Str::limit($body, 600) : $body }}</p>
    @if($long)
    <p class="px-4 sm:px-5"><a href="{{ $postUrl }}" class="inline-flex items-center min-h-[44px] text-sm font-semibold text-[#047857] underline underline-offset-4 hover:text-[#053b30]">{{ __('Baca selengkapnya') }}</a></p>
    @endif
    @endif

    @if($p['photo_url'])
    <div class="mt-3 bg-[#eef4ef]">
        @if($full)
        <img src="{{ $p['photo_url'] }}" alt="{{ __('Foto dari :name', ['name' => $p['author']['name']]) }}" class="block w-full h-auto max-h-[80vh] object-contain mx-auto" decoding="async">
        @else
        <a href="{{ $postUrl }}" class="block">
            <img src="{{ $p['photo_url'] }}" alt="{{ __('Foto dari :name', ['name' => $p['author']['name']]) }}" class="block w-full h-auto max-h-[32rem] object-cover" loading="lazy" decoding="async">
        </a>
        @endif
    </div>
    @endif

    @if($p['track'] && ! $full)
    <a href="{{ $postUrl }}" class="block mt-3" aria-label="{{ __('Buka peta rekaman') }}">
        @include('linimasa.partials.track', ['track' => $p['track'], 'label' => __('Gambar jalur rekaman :name', ['name' => $p['author']['name']])])
    </a>
    @endif

    @if($stats)
    <dl class="mx-4 sm:mx-5 mt-3 grid grid-cols-3 gap-px overflow-hidden rounded-lg border border-[#dcd8c9] bg-[#dcd8c9] text-center">
        <div class="bg-white px-2 py-2">
            <dt class="text-xs rs-muted">{{ __('Jarak') }}</dt>
            <dd class="rs-display rs-num text-xl sm:text-2xl leading-tight whitespace-nowrap text-[#053b30]">{{ isset($stats['distance_km']) ? $num($stats['distance_km'], 1) . ' km' : '-' }}</dd>
        </div>
        <div class="bg-white px-2 py-2">
            <dt class="text-xs rs-muted">{{ __('Naik') }}</dt>
            <dd class="rs-display rs-num text-xl sm:text-2xl leading-tight whitespace-nowrap text-[#053b30]">{{ isset($stats['gain_m']) ? $num($stats['gain_m']) . ' m' : '-' }}</dd>
        </div>
        <div class="bg-white px-2 py-2">
            <dt class="text-xs rs-muted">{{ __('Waktu gerak') }}</dt>
            <dd class="rs-display rs-num text-xl sm:text-2xl leading-tight whitespace-nowrap text-[#053b30]">{{ $moving !== null ? __(':h j :m mnt', ['h' => intdiv($moving, 3600), 'm' => intdiv($moving % 3600, 60)]) : '-' }}</dd>
        </div>
    </dl>
    @endif

    @if($p['place'] || $p['route'])
    <div class="px-4 sm:px-5 mt-3 flex flex-wrap gap-2">
        @if($p['place'])
        <a href="https://www.openstreetmap.org/?mlat={{ $p['place']['lat'] }}&amp;mlon={{ $p['place']['lng'] }}#map=15/{{ $p['place']['lat'] }}/{{ $p['place']['lng'] }}" target="_blank" rel="noopener nofollow"
           class="rs-chip rs-chip-plain min-h-[44px] hover:border-[#7c8c84] [overflow-wrap:anywhere]">
            <x-icon name="location-marker" class="w-4 h-4 text-[#047857] shrink-0" />
            <span>{{ $p['place']['label'] ?: $num($p['place']['lat'], 5) . ', ' . $num($p['place']['lng'], 5) }}</span>
            <span class="sr-only">{{ __('(peta OpenStreetMap, tab baru)') }}</span>
        </a>
        @endif
        @if($p['route'])
        <a href="{{ route('routes.show', $p['route']['id']) }}" class="rs-chip rs-chip-ok min-h-[44px] hover:border-[#047857] [overflow-wrap:anywhere]">
            <x-icon name="map" class="w-4 h-4 shrink-0" />
            <span>{{ $p['route']['name'] }}</span>
        </a>
        @endif
    </div>
    @endif

    {{-- Actions: icons with counts; the words show from 640 px and are read out on phones. --}}
    <footer class="mt-3 border-t border-[#ece9de] px-2 sm:px-3 grid grid-cols-4">
        @if($me)
        <form method="POST" action="{{ route('posts.like', $p['id']) }}" data-social="like">
            @csrf
            <input type="hidden" name="_method" value="{{ $p['liked'] ? 'DELETE' : 'POST' }}">
            <button type="submit" aria-pressed="{{ $p['liked'] ? 'true' : 'false' }}" class="rs-act w-full">
                <x-icon name="heart" class="rs-on w-5 h-5 text-[#b91c1c]" />
                <x-icon name="heart-outline" class="rs-off w-5 h-5" />
                <span class="rs-num" data-count>{{ $p['likes_count'] }}</span>
                <span class="sr-only sm:not-sr-only">{{ __('Suka') }}</span>
            </button>
        </form>
        @else
        <p class="rs-act">
            <x-icon name="heart-outline" class="w-5 h-5" />
            <span class="rs-num">{{ $p['likes_count'] }}</span>
            <span class="sr-only sm:not-sr-only">{{ __('Suka') }}</span>
        </p>
        @endif

        <a href="{{ $postUrl }}#komentar" class="rs-act">
            <x-icon name="chat" class="w-5 h-5" />
            <span class="rs-num">{{ $p['comments_count'] }}</span>
            <span class="sr-only sm:not-sr-only">{{ __('Komentar') }}</span>
        </a>

        @if($me)
        <form method="POST" action="{{ route('posts.save', $p['id']) }}" data-social="save">
            @csrf
            <input type="hidden" name="_method" value="{{ $p['saved'] ? 'DELETE' : 'POST' }}">
            <button type="submit" aria-pressed="{{ $p['saved'] ? 'true' : 'false' }}" class="rs-act w-full">
                <x-icon name="bookmark-solid" class="rs-on w-5 h-5 text-[#047857]" />
                <x-icon name="bookmark" class="rs-off w-5 h-5" />
                <span class="sr-only sm:not-sr-only" data-label data-label-on="{{ __('Disimpan') }}" data-label-off="{{ __('Simpan') }}">{{ $p['saved'] ? __('Disimpan') : __('Simpan') }}</span>
            </button>
        </form>
        @else
        <span aria-hidden="true"></span>
        @endif

        <a href="{{ $p['share_url'] }}" class="rs-act" data-share data-title="{{ __('Kiriman :name di RuteStrip', ['name' => $p['author']['name']]) }}">
            <x-icon name="share" class="w-5 h-5" />
            <span class="sr-only sm:not-sr-only">{{ __('Bagikan') }}</span>
        </a>
    </footer>
</article>
