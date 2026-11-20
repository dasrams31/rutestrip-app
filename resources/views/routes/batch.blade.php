@extends('layouts.app')

@section('title', __('Batch Upload GPX'))

@php
    // Same limit the controller enforces: PHP drops files beyond max_file_uploads.
    $maxFiles = min(50, (int) ini_get('max_file_uploads') ?: 20);
    $postMax = preg_replace('/^(\d+)\s*([KMG])$/i', '$1 $2B', (string) ini_get('post_max_size'));
    $batch = session('batch_results');
    $failed = is_array($batch) ? ($batch['failed'] ?? []) : [];
@endphp

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    @include('partials.rs-head', [
        'back' => ['href' => route('routes.index'), 'label' => __('Kembali ke daftar jalur')],
        'eyebrow' => __('Upload GPX sekaligus'),
        'title' => __('Banyak jejak, sekali unggah.'),
        'lead' => __('Pilih sampai :max file GPX. Nama jalur diambil dari nama filenya, jadi rapikan dulu nama file sebelum diunggah.', ['max' => $maxFiles]),
        'actions' => [['href' => route('routes.create'), 'label' => __('Satu file saja? Unggah satuan'), 'icon' => 'document-add']],
        'width' => 'max-w-5xl',
        'seed' => 61,
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
        @if(count($failed) > 0)
        <section class="mb-8 rs-card p-5 sm:p-6" style="border-color:#fecaca" aria-labelledby="failed-title">
            <h2 id="failed-title" class="flex items-center gap-2 font-semibold text-[#991b1b]"><x-icon name="exclamation" class="w-5 h-5 shrink-0" /> {{ __('File yang belum bisa diproses') }}</h2>
            <ul class="mt-3 divide-y divide-[#f1e4e4]">
                @foreach($failed as $item)
                <li class="py-3 min-w-0">
                    <p class="font-semibold [overflow-wrap:anywhere]">{{ $item['file'] }}</p>
                    <p class="text-sm text-[#991b1b] [overflow-wrap:anywhere]">{{ $item['error'] }}</p>
                </li>
                @endforeach
            </ul>
        </section>
        @endif

        <div class="lg:grid lg:grid-cols-12 lg:gap-10">
            <form action="{{ route('routes.batch.store') }}" method="POST" enctype="multipart/form-data" class="lg:col-span-8 rs-card p-5 sm:p-8 space-y-6" id="batchForm">
                @csrf

                <div>
                    <p class="rs-label" id="gpx_files_label">{{ __('File GPX') }} <span class="text-[#b91c1c]" aria-hidden="true">*</span></p>
                    <div class="relative">
                        <input type="file" name="gpx_files[]" id="gpx_files" accept=".gpx,.xml" class="peer sr-only" multiple required
                               aria-labelledby="gpx_files_label" aria-describedby="gpx_files_hint">
                        <label for="gpx_files" id="dropzone"
                               class="rs-dropzone relative flex flex-col items-center justify-center w-full min-h-[11rem] px-4 py-6 overflow-hidden rounded-xl border-2 border-dashed cursor-pointer text-center transition-colors bg-[#faf9f4] hover:bg-[#ecfdf5]
                                      peer-focus-visible:outline peer-focus-visible:outline-[3px] peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[#053b30]
                                      {{ $errors->has('gpx_files') || $errors->has('gpx_files.*') ? 'border-[#b91c1c]' : 'border-[#7c8c84]' }}">
                            @include('partials.rs-contours', ['seed' => 9, 'tone' => 'light', 'class' => 'absolute inset-0 w-full h-full pointer-events-none', 'peaks' => [[980, 210, 10], [120, 40, 5]]])
                            <span class="relative flex flex-col items-center" id="dropzone-content">
                                <x-icon name="upload" class="w-9 h-9 text-[#047857]" />
                                <span class="mt-3 font-semibold text-[#053b30]" id="dropzone-title">{{ __('Pilih file GPX') }}</span>
                                <span class="mt-1 text-sm rs-muted" id="dropzone-sub">
                                    <span class="hidden sm:inline">{{ __('atau seret semua filenya ke kotak ini') }}</span>
                                    <span class="sm:hidden">{{ __('Bisa pilih beberapa file sekaligus') }}</span>
                                </span>
                            </span>
                        </label>
                    </div>
                    <p id="gpx_files_hint" class="rs-hint">
                        {{ __('File .gpx atau .xml, paling besar 10 MB per file, maksimal :max file.', ['max' => $maxFiles]) }}
                        @if($postMax){{ __('Total semua file paling besar :size.', ['size' => $postMax]) }}@endif
                    </p>
                    @error('gpx_files')
                        <p class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span class="[overflow-wrap:anywhere]">{{ $message }}</span></p>
                    @enderror
                    @error('gpx_files.*')
                        <p class="rs-error"><x-icon name="exclamation" class="w-4 h-4 mt-0.5 shrink-0" /><span class="[overflow-wrap:anywhere]">{{ $message }}</span></p>
                    @enderror
                </div>

                {{-- Selected files: names wrap or truncate, never push the page sideways. --}}
                <div class="hidden" id="files-list-container">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <h3 class="font-semibold">{{ __('File yang dipilih') }}</h3>
                        <p class="text-sm rs-muted" aria-live="polite"><span id="file-count" class="rs-num font-semibold text-[#14231d]"></span> · <span id="file-total" class="rs-num"></span></p>
                    </div>
                    <p id="files-warning" class="rs-error hidden" role="alert"></p>
                    <ul class="mt-3 max-h-72 overflow-y-auto rounded-lg border rs-rule divide-y divide-[#ece9de] bg-[#faf9f4]" id="files-list"></ul>
                </div>

                <div class="rounded-lg bg-[#faf9f4] border rs-rule p-4 text-[0.9375rem]">
                    <p class="font-semibold">{{ __('Sebelum mengunggah') }}</p>
                    <ul class="mt-2 space-y-1.5 rs-muted">
                        <li>{{ __('Nama jalur diambil dari nama file, tanpa .gpx.') }}</li>
                        <li>{{ __('Banyak file bisa butuh beberapa menit. Biarkan halaman ini terbuka.') }}</li>
                        <li>{{ __('File yang gagal dilewati, yang berhasil tetap tersimpan.') }}</li>
                    </ul>
                </div>

                <div id="progress-section" class="hidden rounded-lg border-2 border-[#047857] bg-[#ecfdf5] p-4" role="status" aria-live="polite">
                    <p class="font-semibold text-[#053b30] flex items-center gap-2">
                        <svg class="w-5 h-5 animate-spin motion-reduce:animate-none shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="#a7f3d0" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="#047857" stroke-width="3" stroke-linecap="round"/></svg>
                        <span id="progress-text"></span>
                    </p>
                    <p class="mt-1 text-sm rs-muted">{{ __('Jangan tutup atau muat ulang halaman ini sampai selesai.') }}</p>
                </div>

                <div class="pt-2 border-t rs-rule">
                    <p class="text-sm rs-muted mt-4">{{ __('Semua jalur akan tampil dengan tanda') }} <span class="font-semibold text-[#14231d] [overflow-wrap:anywhere]">{{ __('Diunggah oleh :name', ['name' => auth()->user()->name]) }}</span></p>
                    <button type="submit" id="submit-btn" class="rs-sign w-full sm:w-auto mt-4 text-base">
                        <x-icon name="upload" class="w-5 h-5" /> <span id="btn-text">{{ __('Unggah semua file') }}</span>
                    </button>
                </div>
            </form>

            <aside class="lg:col-span-4 mt-8 lg:mt-0">
                <div class="lg:sticky lg:top-24 border-l-4 border-[#047857] pl-5">
                    <h2 class="rs-display uppercase text-2xl leading-none text-[#053b30]">{{ __('Tips nama file') }}</h2>
                    <p class="mt-3">{{ __('Nama file jadi nama jalur. Contoh yang enak dibaca:') }}</p>
                    <p class="mt-2 font-semibold [overflow-wrap:anywhere]">Gunung Prau via Patak Banteng.gpx</p>
                    <p class="mt-4 text-sm rs-muted">{{ __('Setiap jalur bisa diberi cerita singkat nanti, dari halaman jalurnya.') }}</p>
                </div>
            </aside>
        </div>
    @endguest
    </div>
</div>

@auth
<script>
(function () {
    const MAX_FILES = {{ $maxFiles }};
    const MAX_BYTES = 10 * 1048576;
    const i18n = {
        selected: @js(__(':count file dipilih')),
        change: @js(__('Ketuk untuk ganti pilihan')),
        uploading: @js(__('Mengunggah :count file dan menghitung jalurnya...')),
        button: @js(__('Unggah semua file')),
        busy: @js(__('Sedang mengunggah...')),
        tooMany: @js(__('Kamu memilih :count file. Maksimal :max file sekali unggah, sisanya unggah di putaran berikutnya.')),
        bad: @js(__(':count file akan dilewati karena bukan .gpx/.xml atau lebih dari 10 MB.')),
        notGpx: @js(__('Bukan .gpx')),
        tooBig: @js(__('Lebih dari 10 MB')),
    };
    const fmt = (s, v) => s.replace(/:(\w+)/g, (m, k) => (k in v ? v[k] : m));
    const lang = document.documentElement.lang;
    const formatSize = (bytes) => bytes >= 1048576
        ? (bytes / 1048576).toLocaleString(lang, { maximumFractionDigits: 1 }) + ' MB'
        : Math.max(1, Math.round(bytes / 1024)).toLocaleString(lang) + ' KB';

    const input = document.getElementById('gpx_files');
    const dropzone = document.getElementById('dropzone');
    const title = document.getElementById('dropzone-title');
    const sub = document.getElementById('dropzone-sub');
    const subDefault = sub.innerHTML;
    const titleDefault = title.textContent;
    const container = document.getElementById('files-list-container');
    const list = document.getElementById('files-list');
    const count = document.getElementById('file-count');
    const total = document.getElementById('file-total');
    const warning = document.getElementById('files-warning');
    const form = document.getElementById('batchForm');
    const btn = document.getElementById('submit-btn');
    const btnText = document.getElementById('btn-text');
    const progress = document.getElementById('progress-section');
    const progressText = document.getElementById('progress-text');

    function render() {
        const files = Array.from(input.files || []);
        list.textContent = '';
        if (!files.length) {
            container.classList.add('hidden');
            title.textContent = titleDefault;
            sub.innerHTML = subDefault;
            return;
        }
        let bytes = 0, bad = 0;
        files.forEach((file) => {
            bytes += file.size;
            const ext = file.name.split('.').pop().toLowerCase();
            const problem = !['gpx', 'xml'].includes(ext) ? i18n.notGpx : (file.size > MAX_BYTES ? i18n.tooBig : '');
            if (problem) bad++;

            const li = document.createElement('li');
            li.className = 'flex items-center gap-3 px-3 py-2.5 min-w-0';
            const name = document.createElement('span');
            name.className = 'min-w-0 flex-1 truncate text-[0.9375rem]';
            name.textContent = file.name;
            name.title = file.name;
            const meta = document.createElement('span');
            meta.className = 'shrink-0 whitespace-nowrap text-sm ' + (problem ? 'font-semibold text-[#b91c1c]' : 'rs-muted');
            meta.textContent = problem || formatSize(file.size);
            li.append(name, meta);
            list.appendChild(li);
        });

        container.classList.remove('hidden');
        count.textContent = fmt(i18n.selected, { count: files.length.toLocaleString(lang) });
        total.textContent = formatSize(bytes);
        title.textContent = fmt(i18n.selected, { count: files.length });
        sub.textContent = i18n.change;

        const notes = [];
        if (files.length > MAX_FILES) notes.push(fmt(i18n.tooMany, { count: files.length, max: MAX_FILES }));
        if (bad) notes.push(fmt(i18n.bad, { count: bad }));
        warning.textContent = notes.join(' ');
        warning.classList.toggle('hidden', !notes.length);
    }

    input.addEventListener('change', render);

    ['dragenter', 'dragover'].forEach((type) => dropzone.addEventListener(type, (e) => { e.preventDefault(); dropzone.classList.add('is-drag'); }));
    ['dragleave', 'drop'].forEach((type) => dropzone.addEventListener(type, (e) => { e.preventDefault(); dropzone.classList.remove('is-drag'); }));
    dropzone.addEventListener('drop', (e) => {
        if (!e.dataTransfer || !e.dataTransfer.files.length) return;
        input.files = e.dataTransfer.files;
        render();
    });

    form.addEventListener('submit', function () {
        btn.disabled = true;
        btnText.textContent = i18n.busy;
        progressText.textContent = fmt(i18n.uploading, { count: (input.files || []).length });
        progress.classList.remove('hidden');
    });

    // Back/forward cache: never leave the form stuck in the uploading state.
    window.addEventListener('pageshow', function () {
        btn.disabled = false;
        btnText.textContent = i18n.button;
        progress.classList.add('hidden');
    });

    render();
})();
</script>
@endauth
@endsection
