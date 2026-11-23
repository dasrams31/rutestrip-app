@extends('layouts.app')

@section('title', __('Upload GPX'))

@php
    $computed = [
        __('Jarak total jalur, dalam km'),
        __('Total tanjakan, dalam meter'),
        __('Estimasi waktu tempuh dengan rumus Naismith'),
        __('Kemiringan rata-rata jalur'),
        __('Ringkasan jalur supaya bisa ditemukan lewat pencarian biasa'),
    ];
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => ['href' => route('routes.index'), 'label' => __('Kembali ke daftar jalur')],
        'eyebrow' => __('Upload GPX'),
        'title' => __('Tambahkan jalur dari pendakianmu.'),
        'lead' => __('Unggah satu file GPX dari jam GPS atau aplikasi pelacak. Jarak, tanjakan, waktu tempuh, dan tingkat kesulitan dihitung dari file itu.'),
        'actions' => [['href' => route('routes.batch'), 'label' => __('Unggah banyak file'), 'icon' => 'upload']],
        'width' => 'max-w-5xl',
        'seed' => 53,
    ])

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 sm:pt-10">
    @guest
        <div class="rs-card p-6 sm:p-10 max-w-2xl">
            <x-icon name="lock-closed" class="w-8 h-8 text-[#047857]" />
            <h2 class="rs-display mt-3 uppercase text-3xl sm:text-4xl leading-none text-[#053b30]">{{ __('Masuk dulu untuk mengunggah GPX') }}</h2>
            <p class="mt-3 rs-muted max-w-lg">{{ __('Setiap jalur yang diunggah ditandai dengan nama pengunggahnya. Masuk atau buat akun gratis, nanti kamu kembali ke halaman ini.') }}</p>
            <div class="mt-6 flex flex-col min-[420px]:flex-row gap-3">
                <a href="{{ route('login') }}" class="rs-sign">{{ __('Masuk') }}</a>
                <a href="{{ route('register') }}" class="rs-btn-ghost">{{ __('Buat akun gratis') }}</a>
            </div>
        </div>
    @else
        <div class="lg:grid lg:grid-cols-12 lg:gap-10">
            <form action="{{ route('routes.store') }}" method="POST" enctype="multipart/form-data" class="lg:col-span-8 rs-card p-5 sm:p-8 space-y-6" id="uploadForm">
                @csrf

                {{-- 1. The file --}}
                <div>
                    <p class="rs-label" id="gpx_file_label">{{ __('File GPX') }} <span class="text-[#b91c1c]" aria-hidden="true">*</span></p>
                    <div class="relative">
                        <input type="file" name="gpx_file" id="gpx_file" accept=".gpx,.xml" class="peer sr-only" required
                               aria-labelledby="gpx_file_label" aria-describedby="gpx_file_hint @error('gpx_file') gpx_file-error @enderror">
                        <label for="gpx_file" id="dropzone" data-dropzone
                               class="rs-dropzone relative flex flex-col items-center justify-center w-full min-h-[11rem] px-4 py-6 overflow-hidden rounded-xl border-2 border-dashed cursor-pointer text-center transition-colors bg-[#faf9f4] hover:bg-[#ecfdf5]
                                      peer-focus-visible:outline peer-focus-visible:outline-[3px] peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[#053b30]
                                      @error('gpx_file') border-[#b91c1c] @else border-[#7c8c84] @enderror">
                            @include('partials.rs-contours', ['seed' => 5, 'tone' => 'light', 'class' => 'absolute inset-0 w-full h-full pointer-events-none', 'peaks' => [[960, 200, 9]]])
                            <span class="relative flex flex-col items-center" id="dropzone-content">
                                <x-icon name="upload" class="w-9 h-9 text-[#047857]" />
                                <span class="mt-3 font-semibold text-[#053b30]">{{ __('Pilih file GPX') }}</span>
                                <span class="hidden sm:block mt-1 text-sm rs-muted">{{ __('atau seret filenya ke kotak ini') }}</span>
                            </span>
                            <span class="relative hidden flex-col items-center w-full max-w-full min-w-0" id="file-selected">
                                <x-icon name="check" class="w-9 h-9 text-[#047857]" />
                                <span class="mt-3 max-w-full font-semibold text-[#053b30] [overflow-wrap:anywhere] line-clamp-2" id="file-name"></span>
                                <span class="mt-1 text-sm rs-muted"><span id="file-size"></span> · {{ __('Ketuk untuk ganti file') }}</span>
                            </span>
                        </label>
                    </div>
                    <p id="gpx_file_hint" class="rs-hint">{{ __('File .gpx atau .xml, paling besar 10 MB.') }}</p>
                    <p id="file-client-error" class="rs-error hidden" role="alert"></p>
                    @error('gpx_file')
                        <p id="gpx_file-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                    @enderror
                </div>

                {{-- 2. Name --}}
                <div>
                    <label for="name" class="rs-label">{{ __('Nama jalur') }} <span class="text-[#b91c1c]" aria-hidden="true">*</span></label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" maxlength="255" required
                           placeholder="{{ __('Contoh: Gunung Semeru via Ranu Pani') }}"
                           class="rs-input" aria-describedby="name_hint @error('name') name-error @enderror" @error('name') aria-invalid="true" @enderror>
                    <p id="name_hint" class="rs-hint">{{ __('Terisi otomatis dari nama file. Ganti kalau perlu.') }}</p>
                    @error('name')
                        <p id="name-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                    @enderror
                </div>

                {{-- 3. Description --}}
                <div>
                    <label for="description" class="rs-label">{{ __('Cerita singkat jalurnya') }} <span class="font-normal rs-muted">({{ __('opsional') }})</span></label>
                    <textarea name="description" id="description" rows="4" maxlength="2000" class="rs-input"
                              aria-describedby="description_hint @error('description') description-error @enderror"
                              placeholder="{{ __('Contoh: hutan pinus sampai pos 2, ada sumber air di pos 2, sabana menjelang puncak.') }}">{{ old('description') }}</textarea>
                    <p id="description_hint" class="rs-hint">{{ __('Tulis yang berguna buat pendaki lain: vegetasi, sumber air, pemandangan, kondisi medan.') }}</p>
                    @error('description')
                        <p id="description-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                    @enderror
                </div>

                {{-- 4. Change note --}}
                <div>
                    <label for="change_log" class="rs-label">{{ __('Catatan untuk riwayat file') }} <span class="font-normal rs-muted">({{ __('opsional') }})</span></label>
                    <textarea name="change_log" id="change_log" rows="2" maxlength="1000" class="rs-input"
                              aria-describedby="change_log_hint @error('change_log') change_log-error @enderror"
                              placeholder="{{ __('Contoh: direkam sendiri pakai jam GPS, Agustus 2026.') }}">{{ old('change_log') }}</textarea>
                    <p id="change_log_hint" class="rs-hint">{{ __('Tampil di halaman riwayat file GPX jalur ini.') }}</p>
                    @error('change_log')
                        <p id="change_log-error" class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                    @enderror
                </div>

                @include('partials.clean-track-toggle')

                <div class="pt-2 border-t rs-rule">
                    <p class="text-sm rs-muted mt-4">{{ __('Jalur ini akan tampil dengan tanda') }} <span class="font-semibold text-[#14231d] [overflow-wrap:anywhere]">{{ __('Diunggah oleh :name', ['name' => auth()->user()->name]) }}</span></p>
                    <button type="submit" class="rs-sign w-full sm:w-auto mt-4 text-base" id="submit-btn">
                        <x-icon name="upload" class="w-5 h-5" /> <span id="btn-text">{{ __('Unggah dan hitung jalurnya') }}</span>
                    </button>
                </div>
            </form>

            <aside class="lg:col-span-4 mt-8 lg:mt-0">
                <div class="lg:sticky lg:top-24 border-l-4 border-[#047857] pl-5">
                    <h2 class="rs-display uppercase text-2xl leading-none text-[#053b30]">{{ __('Yang kami hitung dari filemu') }}</h2>
                    <ul class="mt-4 space-y-3">
                        @foreach($computed as $item)
                        <li class="flex gap-3 items-start">
                            <svg class="w-3.5 h-3.5 mt-1.5 shrink-0" viewBox="0 0 26 22" aria-hidden="true"><path d="M13 2 L24 20 H2 Z" fill="#fff" stroke="#053b30" stroke-width="2.5" stroke-linejoin="round"/></svg>
                            <span>{{ $item }}</span>
                        </li>
                        @endforeach
                    </ul>
                    <p class="mt-5 text-sm rs-muted">{{ __('Prosesnya biasanya beberapa detik. Setelah selesai, kamu langsung dibawa ke halaman jalurnya.') }}</p>
                </div>
            </aside>
        </div>
    @endguest
    </div>
</div>

@auth
<script>
(function () {
    const i18n = {
        tooBig: @js(__('File ini lebih dari 10 MB. Pilih file yang lebih kecil.')),
        wrongType: @js(__('File ini bukan .gpx atau .xml.')),
        uploading: @js(__('Sedang mengunggah dan menghitung...')),
        idle: @js(__('Unggah dan hitung jalurnya')),
    };
    const input = document.getElementById('gpx_file');
    const dropzone = document.getElementById('dropzone');
    const content = document.getElementById('dropzone-content');
    const selected = document.getElementById('file-selected');
    const nameEl = document.getElementById('file-name');
    const sizeEl = document.getElementById('file-size');
    const clientError = document.getElementById('file-client-error');
    const routeName = document.getElementById('name');
    let autoName = '';

    const formatSize = (bytes) => bytes >= 1048576
        ? (bytes / 1048576).toLocaleString(document.documentElement.lang, { maximumFractionDigits: 1 }) + ' MB'
        : Math.max(1, Math.round(bytes / 1024)).toLocaleString(document.documentElement.lang) + ' KB';

    function showError(message) {
        clientError.textContent = message || '';
        clientError.classList.toggle('hidden', !message);
    }

    function render() {
        const file = input.files && input.files[0];
        if (!file) {
            content.classList.remove('hidden');
            selected.classList.add('hidden');
            selected.classList.remove('flex');
            showError('');
            return;
        }
        content.classList.add('hidden');
        selected.classList.remove('hidden');
        selected.classList.add('flex');
        nameEl.textContent = file.name;
        nameEl.title = file.name;
        sizeEl.textContent = formatSize(file.size);

        const ext = file.name.split('.').pop().toLowerCase();
        showError(!['gpx', 'xml'].includes(ext) ? i18n.wrongType : (file.size > 10 * 1048576 ? i18n.tooBig : ''));

        // Fill the route name from the file name, unless the hiker already typed one.
        const fromFile = file.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
        if (routeName && (routeName.value.trim() === '' || routeName.value === autoName)) {
            routeName.value = fromFile.slice(0, 255);
            autoName = routeName.value;
        }
    }

    input.addEventListener('change', render);

    ['dragenter', 'dragover'].forEach((type) => dropzone.addEventListener(type, (e) => {
        e.preventDefault();
        dropzone.classList.add('is-drag');
    }));
    ['dragleave', 'drop'].forEach((type) => dropzone.addEventListener(type, (e) => {
        e.preventDefault();
        dropzone.classList.remove('is-drag');
    }));
    dropzone.addEventListener('drop', (e) => {
        if (!e.dataTransfer || !e.dataTransfer.files.length) return;
        const dt = new DataTransfer();
        dt.items.add(e.dataTransfer.files[0]);
        input.files = dt.files;
        render();
    });

    document.getElementById('uploadForm').addEventListener('submit', function () {
        const btn = document.getElementById('submit-btn');
        btn.disabled = true;
        document.getElementById('btn-text').textContent = i18n.uploading;
    });

    // Back/forward cache: never leave the button stuck on "uploading".
    window.addEventListener('pageshow', function () {
        document.getElementById('submit-btn').disabled = false;
        document.getElementById('btn-text').textContent = i18n.idle;
    });

    render();
})();
</script>
@endauth
@endsection
