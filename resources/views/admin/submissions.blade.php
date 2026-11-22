@extends('layouts.app')

@section('title', __('Kiriman jalur') . ' | ' . __('Panel admin'))

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'eyebrow' => __('Panel admin'),
        'title' => __('Rekaman pendakian dari aplikasi.'),
        'lead' => __('Rekaman sudah diproses seperti unggahan GPX biasa. Jalur baru tampil di pencarian dan rekomendasi setelah kamu setujui.'),
        'width' => 'max-w-5xl',
        'seed' => 163,
        'tabs' => \App\Support\AdminNav::tabs('submissions'),
    ])

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <section class="mt-8" aria-labelledby="sub-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="sub-title" class="rs-display uppercase text-[clamp(2rem,6vw,2.75rem)] leading-none text-[#053b30]">{{ __('Menunggu keputusan') }}</h2>
                <p class="text-sm rs-muted">{{ __(':count kiriman menunggu', ['count' => $pending->total()]) }}</p>
            </div>

            @if($pending->isEmpty())
            <div class="mt-4 rs-card px-5 py-10 text-center">
                <p class="font-semibold">{{ __('Tidak ada kiriman.') }}</p>
                <p class="mt-1 text-sm rs-muted">{{ __('Rekaman muncul di sini saat pendaki mengirimnya dari aplikasi Android.') }}</p>
            </div>
            @else
            <ol class="mt-4 space-y-4">
                @foreach($pending as $r)
                @php($pts = is_array($r->route_coordinates) ? $r->route_coordinates : [])
                <li class="rs-card overflow-hidden">
                    <article aria-labelledby="sub-{{ $r->id }}">
                        <header class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 sm:px-5 pt-4">
                            <h3 id="sub-{{ $r->id }}" class="font-semibold [overflow-wrap:anywhere]">{{ $r->name }}</h3>
                            <time class="text-sm rs-muted" datetime="{{ $r->created_at?->toIso8601String() }}">{{ $r->created_at?->translatedFormat('j M Y, H:i') }}</time>
                        </header>
                        <p class="px-4 sm:px-5 mt-1 text-sm rs-muted">
                            {{ __('Dikirim oleh') }}
                            @if($r->uploader)
                            <a href="{{ route('admin.users.show', $r->uploader) }}" class="text-[#047857] hover:text-[#053b30] hover:underline underline-offset-2">{{ $r->uploader->name }}</a>
                            @else
                            {{ __('akun yang sudah dihapus') }}
                            @endif
                        </p>
                        <dl class="px-4 sm:px-5 mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                            <div><dt class="rs-muted">{{ __('Jarak') }}</dt><dd class="font-semibold">{{ $r->distance_km !== null ? number_format($r->distance_km, 2) . ' km' : '-' }}</dd></div>
                            <div><dt class="rs-muted">{{ __('Total naik') }}</dt><dd class="font-semibold">{{ $r->elevation_gain_m !== null ? $r->elevation_gain_m . ' m' : '-' }}</dd></div>
                            <div><dt class="rs-muted">{{ __('Titik GPS') }}</dt><dd class="font-semibold">{{ count($pts) }}</dd></div>
                            <div><dt class="rs-muted">{{ __('Titik awal') }}</dt><dd class="font-semibold">
                                @if($pts)
                                <a href="https://www.openstreetmap.org/?mlat={{ $pts[0][0] }}&amp;mlon={{ $pts[0][1] }}#map=14/{{ $pts[0][0] }}/{{ $pts[0][1] }}" target="_blank" rel="noopener" class="text-[#047857] hover:underline">{{ round($pts[0][0], 4) }}, {{ round($pts[0][1], 4) }}</a>
                                @else - @endif
                            </dd></div>
                        </dl>
                        @if($r->description)
                        <blockquote class="mx-4 sm:mx-5 mt-3 border-l-4 border-[#dcd8c9] bg-[#faf9f4] rounded-r-lg px-4 py-3 whitespace-pre-wrap [overflow-wrap:anywhere]">{{ $r->description }}</blockquote>
                        @endif
                        @if($r->gpx_file_path)
                        <p class="px-4 sm:px-5 mt-3 text-sm"><a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($r->gpx_file_path) }}" class="text-[#047857] font-semibold hover:underline" download>{{ __('Unduh GPX') }}</a></p>
                        @endif
                        <div class="mt-4 px-4 sm:px-5 py-3 border-t rs-rule bg-[#faf9f4] flex flex-col sm:flex-row sm:items-end gap-3">
                            <form method="POST" action="{{ route('admin.submissions.approve', $r->id) }}">
                                @csrf
                                <button type="submit" class="rs-btn-sm w-full sm:w-auto inline-flex items-center justify-center gap-2 px-4 rounded-lg font-bold bg-[#047857] text-white hover:bg-[#053b30]">{{ __('Setujui') }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.submissions.reject', $r->id) }}" class="flex flex-col sm:flex-row gap-2 sm:items-end grow"
                                  data-confirm="{{ __('Tolak ":name"? Jalur ini tidak akan tampil.', ['name' => $r->name]) }}">
                                @csrf
                                <label class="grow text-sm">
                                    <span class="rs-muted">{{ __('Alasan (dilihat pengirim, boleh kosong)') }}</span>
                                    <input type="text" name="note" maxlength="500" class="mt-1 w-full rounded-lg border rs-rule px-3 py-2">
                                </label>
                                <button type="submit" class="rs-btn-sm w-full sm:w-auto inline-flex items-center justify-center gap-2 px-4 rounded-lg font-bold bg-white text-[#991b1b] border-2 border-[#b91c1c] hover:bg-[#fef2f2]">{{ __('Tolak') }}</button>
                            </form>
                        </div>
                    </article>
                </li>
                @endforeach
            </ol>
            @if($pending->hasPages())
            <div class="mt-6">{{ $pending->links() }}</div>
            @endif
            @endif
        </section>
    </div>
</div>
@include('admin.partials.busy')
@endsection
