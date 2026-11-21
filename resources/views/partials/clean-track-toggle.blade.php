{{-- Upload option: tidy the recording into one trailhead-to-summit track (App\Services\GpxTrackCleaner). --}}
@php $checked = old('clean_track', '1') === '1'; @endphp
<div class="rounded-lg bg-[#faf9f4] border rs-rule p-4">
    <input type="hidden" name="clean_track" value="0">
    <div class="flex items-start gap-3">
        <input type="checkbox" name="clean_track" id="clean_track" value="1" @checked($checked)
               class="mt-1 w-5 h-5 shrink-0 cursor-pointer" style="accent-color:#047857" aria-describedby="clean_track_hint">
        <div class="min-w-0">
            <label for="clean_track" class="font-semibold cursor-pointer">{{ __('Rapikan jalur otomatis') }}</label>
            <p id="clean_track_hint" class="rs-hint">{{ __('Kalau rekamannya naik lalu turun lagi, berupa loop, ada belokan yang balik ke titik sama, atau berisi beberapa track, kami simpan satu jalur saja dari basecamp sampai puncak. Titik GPS-nya tidak diubah, hanya dibuang yang tidak perlu. Nama jalur yang memuat kata loop atau lintas dibiarkan utuh.') }}</p>
        </div>
    </div>
</div>
