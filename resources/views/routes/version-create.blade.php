@extends('layouts.app')

@section('title', __('Upload Versi GPX Baru') . ' - ' . $route->name)

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => ['href' => route('routes.provenance', $route), 'label' => __('Kembali ke riwayat file')],
        'eyebrow' => __('Versi baru file GPX'),
        'title' => $route->name,
        'lead' => __('Versi aktif sekarang: :version. File lama tidak dihapus, tetap ada di riwayat dan bisa dipulihkan.', ['version' => $route->activeGpxVersion ? 'v' . $route->activeGpxVersion->version_number : __('belum ada')]),
        'titleClass' => 'text-[clamp(1.9rem,7.5vw,3.25rem)]',
        'width' => 'max-w-3xl',
        'seed' => 67,
    ])

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 sm:pt-10">
        <form action="{{ route('routes.versions.store', $route) }}" method="POST" enctype="multipart/form-data" class="rs-card p-5 sm:p-8 space-y-6" id="versionForm">
            @csrf

            <div>
                <p class="rs-label" id="gpx_file_label">{{ __('File GPX versi baru') }} <span class="text-[#b91c1c]" aria-hidden="true">*</span></p>
                <div class="relative">
                    <input type="file" name="gpx_file" id="gpx_file" accept=".gpx,.xml" class="peer sr-only" required
                           aria-labelledby="gpx_file_label" aria-describedby="gpx_file_hint @error('gpx_file') gpx_file-error @enderror">
                    <label for="gpx_file" id="dropzone"
                           class="rs-dropzone relative flex flex-col items-center justify-center w-full min-h-[9rem] px-4 py-5 overflow-hidden rounded-xl border-2 border-dashed cursor-pointer text-center transition-colors bg-[#faf9f4] hover:bg-[#ecfdf5]
                                  peer-focus-visible:outline peer-focus-visible:outline-[3px] peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[#053b30]
                                  @error('gpx_file') border-[#b91c1c] @else border-[#7c8c84] @enderror">
                        <x-icon name="upload" class="w-8 h-8 text-[#047857]" />
                        <span class="mt-2 max-w-full font-semibold text-[#053b30] [overflow-wrap:anywhere] line-clamp-2" id="file-name">{{ __('Pilih file GPX') }}</span>
                        <span class="mt-1 text-sm rs-muted" id="file-sub"><span class="hidden sm:inline">{{ __('atau seret filenya ke kotak ini') }}</span></span>
                    </label>
                </div>
                <p id="gpx_file_hint" class="rs-hint">{{ __('File .gpx atau .xml, paling besar 10 MB.') }}</p>
                @error('gpx_file')
                    <p id="gpx_file-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                @enderror
            </div>

            <div>
                <label for="change_log" class="rs-label">{{ __('Apa yang berubah?') }} <span class="text-[#b91c1c]" aria-hidden="true">*</span></label>
                <textarea name="change_log" id="change_log" rows="4" required class="rs-input"
                          aria-describedby="change_log_hint @error('change_log') change_log-error @enderror" @error('change_log') aria-invalid="true" @enderror
                          placeholder="{{ __('Contoh: memperbaiki track dari pos 2 ke puncak dan membuang titik GPS yang melompat.') }}">{{ old('change_log') }}</textarea>
                <p id="change_log_hint" class="rs-hint">{{ __('Wajib diisi, supaya alasan perubahan tercatat di riwayat file.') }}</p>
                @error('change_log')
                    <p id="change_log-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                @enderror
            </div>

            @include('partials.clean-track-toggle')

            <div class="rounded-lg bg-[#faf9f4] border rs-rule p-4 text-[0.9375rem]">
                <p class="font-semibold">{{ __('Yang terjadi setelah diunggah') }}</p>
                <p class="mt-1 rs-muted">{{ __('Angka jalur dihitung ulang, sidik file (hash SHA-256) dibuat, dan versi baru langsung jadi versi aktif.') }}</p>
            </div>

            <button type="submit" class="rs-sign w-full sm:w-auto text-base">
                <x-icon name="upload" class="w-5 h-5" /> {{ __('Unggah sebagai versi baru') }}
            </button>
        </form>
    </div>
</div>

<script>
(function () {
    const input = document.getElementById('gpx_file');
    const dropzone = document.getElementById('dropzone');
    const nameEl = document.getElementById('file-name');
    const sub = document.getElementById('file-sub');
    const change = @js(__('Ketuk untuk ganti file'));
    function render() {
        const file = input.files && input.files[0];
        if (!file) return;
        nameEl.textContent = file.name;
        nameEl.title = file.name;
        sub.textContent = change;
    }
    input.addEventListener('change', render);
    ['dragenter', 'dragover'].forEach((t) => dropzone.addEventListener(t, (e) => { e.preventDefault(); dropzone.classList.add('is-drag'); }));
    ['dragleave', 'drop'].forEach((t) => dropzone.addEventListener(t, (e) => { e.preventDefault(); dropzone.classList.remove('is-drag'); }));
    dropzone.addEventListener('drop', (e) => {
        if (!e.dataTransfer || !e.dataTransfer.files.length) return;
        const dt = new DataTransfer();
        dt.items.add(e.dataTransfer.files[0]);
        input.files = dt.files;
        render();
    });
})();
</script>
@endsection
