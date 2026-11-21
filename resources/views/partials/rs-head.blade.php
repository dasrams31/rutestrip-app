{{--
    Page header band on dark zamrud with contour lines, the same voice as the landing hero.
    Params: title, lead?, eyebrow?, back? ['href' => , 'label' => ], actions? [['href', 'label',
    'style' => 'sign'|'ghost', 'icon'?]], seed?, peaks?, width? (max-w class), titleClass?,
    tabs? [['href', 'label', 'active' => bool, 'count'?, 'count_label'?]] (App\Support\AdminNav::tabs()).
--}}
@php
    $hasFlash = session('success') || session('warning') || (isset($errors) && $errors->any());
    $width = $width ?? 'max-w-6xl';
@endphp
<section class="rs-on-dark relative overflow-hidden bg-[#053b30] text-white {{ $hasFlash ? 'mt-4' : '-mt-4' }}">
    @include('partials.rs-contours', ['seed' => $seed ?? 7, 'tone' => 'dark', 'class' => 'absolute inset-0 w-full h-full pointer-events-none', 'peaks' => $peaks ?? null])
    <div class="relative {{ $width }} mx-auto px-4 sm:px-6 lg:px-8 pt-5 pb-8 sm:pt-8 sm:pb-12">
        @if(!empty($back))
        <a href="{{ $back['href'] }}" class="inline-flex items-center gap-2 min-h-[44px] -ml-1 px-1 text-sm font-semibold text-[#a7f3d0] hover:text-white">
            <x-icon name="arrow-left" class="w-4 h-4 shrink-0" /> <span>{{ $back['label'] }}</span>
        </a>
        @endif
        @if(!empty($eyebrow))
        <p class="{{ empty($back) ? 'mt-3' : 'mt-1' }} text-sm sm:text-base font-semibold text-[#a7f3d0]">{{ $eyebrow }}</p>
        @endif
        <h1 class="rs-display mt-2 uppercase {{ $titleClass ?? 'text-[clamp(2.1rem,8.5vw,3.75rem)]' }} leading-[0.95] [text-wrap:balance] max-w-4xl break-words">{{ $title }}</h1>
        @if(!empty($lead))
        <p class="mt-4 max-w-2xl text-base sm:text-lg leading-relaxed text-[#d1fae5]">{{ $lead }}</p>
        @endif
        @if(!empty($actions))
        <div class="mt-6 flex flex-col min-[420px]:flex-row min-[420px]:flex-wrap gap-3">
            @foreach($actions as $action)
            <a href="{{ $action['href'] }}" class="{{ ($action['style'] ?? 'ghost') === 'sign' ? 'rs-sign' : 'rs-btn-ghost' }}">
                @if(!empty($action['icon']))<x-icon :name="$action['icon']" class="w-5 h-5 shrink-0" />@endif
                <span>{{ $action['label'] }}</span>
            </a>
            @endforeach
        </div>
        @endif
        @if(!empty($tabs))
        {{-- Section tabs (admin): scroll sideways inside their own row on narrow phones. --}}
        <nav class="mt-6 -mx-4 sm:mx-0 px-4 sm:px-0 overflow-x-auto" aria-label="{{ $tabsLabel ?? __('Bagian panel admin') }}">
            <ul class="flex gap-1 min-w-max border-b border-white/20">
                @foreach($tabs as $tab)
                <li>
                    <a href="{{ $tab['href'] }}" @if($tab['active']) aria-current="page" @endif
                       class="inline-flex items-center gap-2 min-h-[44px] px-3 -mb-px border-b-[3px] text-sm sm:text-base font-semibold whitespace-nowrap {{ $tab['active'] ? 'border-white text-white' : 'border-transparent text-[#a7f3d0] hover:text-white hover:border-white/40' }}">
                        <span>{{ $tab['label'] }}</span>
                        @if(!empty($tab['count']))
                        <span class="rs-num inline-flex items-center justify-center min-w-[1.5rem] px-1.5 rounded-md bg-white text-[#053b30] text-xs font-bold" aria-hidden="true">{{ $tab['count'] }}</span>
                        <span class="sr-only">({{ $tab['count_label'] ?? $tab['count'] }})</span>
                        @endif
                    </a>
                </li>
                @endforeach
            </ul>
        </nav>
        @endif
    </div>
</section>
