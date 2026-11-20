{{--
    Floating buttons on every page (included once from layouts/app):
    - left:  "RuteStrip AI Chat" (the web chat, embedded; guests are asked to sign up / log in first)
    - right: "Asisten RuteStrip" (Q&A chatbot about this project, POST /assistant, no login needed)
    Only one panel is open at a time; Escape or the backdrop closes it. Hidden in print.
--}}
@php
    $faChatUrl  = rtrim((string) config('services.webchat.url'), '/') . '/chat';
    $faUser     = auth()->user();
    // Pages whose form submit button sits low on small screens: no floating buttons below 768 px.
    $faHideMobile = request()->routeIs('routes.create', 'routes.batch', 'routes.versions.create', 'settings', 'login', 'register', 'admin.login');
    $faBack     = request()->getRequestUri();
    $faConfig   = [
        'endpoint' => route('assistant.ask'),
        'loggedIn' => (bool) $faUser,
        'max'      => \App\Http\Controllers\AssistantController::MAX_INPUT,
        'turns'    => \App\Http\Controllers\AssistantController::HISTORY_TURNS,
        't'        => [
            'offline' => __('Gagal terhubung. Periksa koneksi lalu coba lagi.'),
            'expired' => __('Sesi berakhir. Muat ulang halaman lalu coba lagi.'),
            'invalid' => __('Pertanyaan tidak valid atau terlalu panjang (maks. :max karakter).', ['max' => \App\Http\Controllers\AssistantController::MAX_INPUT]),
            'failed'  => __('Asisten sedang tidak tersedia. Coba lagi nanti.'),
        ],
    ];
    $faChips = [__('Apa itu RuteStrip?'), __('Cara upload GPX'), __('Metode yang dipakai'), __('Jalur termudah?')];
@endphp
<style>
    .rs-fab { position: fixed; z-index: 45; width: 56px; height: 56px; border-radius: 9999px;
              bottom: calc(4rem + env(safe-area-inset-bottom, 0px) + 12px); }
    .rs-fab-left { left: calc(12px + env(safe-area-inset-left, 0px)); }
    .rs-fab-right { right: calc(12px + env(safe-area-inset-right, 0px)); }
    .rs-panel { position: fixed; z-index: 70; inset: 0; display: flex; flex-direction: column; background: #fff;
                padding-top: env(safe-area-inset-top, 0px); padding-bottom: env(safe-area-inset-bottom, 0px); }
    .rs-panel-sheet { inset: auto 0 0 0; border-radius: 1.25rem 1.25rem 0 0; box-shadow: 0 -12px 40px rgba(15, 23, 42, .18); }
    html.rs-fa-lock, html.rs-fa-lock body { overflow: hidden; }
    @media (max-width: 767.98px) { .rs-fab-hide-sm { display: none !important; } }
    @media (min-width: 768px) {
        .rs-fab { bottom: 24px; }
        .rs-fab-left { left: 24px; }
        .rs-fab-right { right: 24px; }
        .rs-fa-open .rs-fab { z-index: 68; }
        .rs-panel { inset: auto; bottom: 96px; width: 380px; height: min(600px, calc(100vh - 176px)); padding: 0;
                    border: 1px solid #e2e8f0; border-radius: 1rem; overflow: hidden; box-shadow: 0 24px 60px -12px rgba(15, 23, 42, .35); }
        .rs-panel-left { left: 24px; }
        .rs-panel-right { right: 24px; }
        .rs-panel-sheet { height: auto; }
    }
    .rs-md p + p, .rs-md p + ul, .rs-md p + ol, .rs-md ul + p, .rs-md ol + p { margin-top: .5rem; }
    .rs-md ul { list-style: disc; padding-left: 1.25rem; margin-top: .25rem; }
    .rs-md ol { list-style: decimal; padding-left: 1.25rem; margin-top: .25rem; }
    .rs-md li + li { margin-top: .125rem; }
    .rs-md a { color: #047857; text-decoration: underline; text-underline-offset: 2px; word-break: break-word; }
    .rs-md code { font-size: .8em; background: #f1f5f9; border-radius: .25rem; padding: 0 .25rem; }
    .rs-dot { animation: rs-dot 1.2s infinite ease-in-out; }
    .rs-dot:nth-child(2) { animation-delay: .15s; }
    .rs-dot:nth-child(3) { animation-delay: .3s; }
    @keyframes rs-dot { 0%, 80%, 100% { opacity: .25; } 40% { opacity: 1; } }
    @media (prefers-reduced-motion: reduce) { .rs-dot { animation: none; opacity: .6; } }
    @media print { .rs-fa { display: none !important; } }
</style>
<script>
    window.rsAssistants = function () {
        var cfg = @json($faConfig);
        var KEY = 'rs_assistant_v1';

        function esc(s) {
            return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        function link(url, label) {
            var internal = url === location.origin || url.indexOf(location.origin + '/') === 0;
            return '<a href="' + url + '" rel="noopener"' + (internal ? '' : ' target="_blank"') + '>' + label + '</a>';
        }
        // Escaped text + **bold**, `code`, [label](https://...) and bare https:// links.
        function inline(s) {
            var keep = [];
            var put = function (html) { return '\u0001' + (keep.push(html) - 1) + '\u0002'; };
            s = esc(s);
            s = s.replace(/\[([^\]\n]{1,200})\]\((https?:\/\/[^\s()<>"]+)\)/g, function (m, label, url) { return put(link(url, label)); });
            s = s.replace(/https?:\/\/[^\s<>"\u0001\u0002]+/g, function (url) {
                var tail = (url.match(/[.,;:!?)\]]+$/) || [''])[0];
                if (tail) url = url.slice(0, -tail.length);
                return put(link(url, url)) + tail;
            });
            s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
            s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
            return s.replace(/\u0001(\d+)\u0002/g, function (m, i) { return keep[+i]; });
        }
        // Tiny markdown subset: paragraphs, "- " and "1. " lists, headings as bold.
        function render(src) {
            var html = '', list = null, para = [];
            var flush = function () { if (para.length) { html += '<p>' + para.join('<br>') + '</p>'; para = []; } };
            var closeList = function () { if (list) { html += '</' + list + '>'; list = null; } };
            String(src || '').replace(/\r/g, '').split('\n').forEach(function (raw) {
                var line = raw.replace(/\s+$/, ''), m;
                if ((m = line.match(/^\s*[-*•]\s+(.*)$/)) || (m = line.match(/^\s*\d+[.)]\s+(.*)$/))) {
                    var type = /^\s*\d/.test(line) ? 'ol' : 'ul';
                    flush();
                    if (list !== type) { closeList(); html += '<' + type + '>'; list = type; }
                    html += '<li>' + inline(m[1]) + '</li>';
                } else if (!line.trim()) {
                    flush(); closeList();
                } else {
                    closeList();
                    var h = line.match(/^#{1,6}\s+(.*)$/);
                    para.push(h ? '<strong>' + inline(h[1]) + '</strong>' : inline(line));
                }
            });
            flush(); closeList();
            return html;
        }

        return {
            open: null,
            chatLoaded: false,
            chatReady: false,
            messages: [],
            input: '',
            sending: false,
            lastFab: null,
            max: cfg.max,
            render: render,

            init: function () {
                try {
                    var saved = JSON.parse(sessionStorage.getItem(KEY) || '[]');
                    if (Array.isArray(saved)) {
                        this.messages = saved.filter(function (m) {
                            return m && (m.role === 'user' || m.role === 'assistant') && typeof m.text === 'string';
                        }).slice(-30);
                    }
                } catch (e) {}
                // Back from "Daftar / Masuk" in the AI Chat panel: open it again.
                if (location.hash === '#ai-chat') {
                    history.replaceState(null, '', location.pathname + location.search);
                    if (cfg.loggedIn) this.$nextTick(() => this.show('chat', this.$refs.chatFab));
                }
            },
            toggle: function (which, fab) {
                this.open === which ? this.close() : this.show(which, fab);
            },
            show: function (which, fab) {
                this.lastFab = fab || null;
                this.open = which;
                if (which === 'chat') this.chatLoaded = true;
                document.documentElement.classList.toggle('rs-fa-lock', window.matchMedia('(max-width: 767.98px)').matches);
                this.$nextTick(() => {
                    var panel = this.panel();
                    var target = which === 'assistant' ? this.$refs.input : (panel && panel.querySelector('[data-autofocus]'));
                    (target || panel) && (target || panel).focus();
                    if (which === 'assistant') this.scrollDown();
                });
            },
            close: function () {
                if (!this.open) return;
                this.open = null;
                document.documentElement.classList.remove('rs-fa-lock');
                var fab = this.lastFab;
                this.lastFab = null;
                if (fab) this.$nextTick(function () { fab.focus(); });
            },
            panel: function () {
                return this.open ? document.getElementById(this.open === 'chat' ? 'rs-ai-chat-panel' : 'rs-assistant-panel') : null;
            },
            // Keep Tab / Shift+Tab inside the open panel.
            trap: function (e) {
                var panel = this.panel();
                if (!panel) return;
                var items = Array.prototype.filter.call(
                    panel.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled]), iframe'),
                    function (el) { return el.getClientRects().length > 0; }
                );
                if (!items.length) return;
                var first = items[0], last = items[items.length - 1];
                if (e.shiftKey && (document.activeElement === first || document.activeElement === panel)) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            },
            scrollDown: function () {
                this.$nextTick(() => { var box = this.$refs.log; if (box) box.scrollTop = box.scrollHeight; });
            },
            resize: function () {
                var t = this.$refs.input;
                if (!t) return;
                t.style.height = 'auto';
                t.style.height = Math.min(t.scrollHeight, 128) + 'px';
                t.style.overflowY = t.scrollHeight > 128 ? 'auto' : 'hidden';
            },
            save: function () {
                try { sessionStorage.setItem(KEY, JSON.stringify(this.messages.slice(-30))); } catch (e) {}
            },
            clear: function () {
                this.messages = [];
                this.save();
                this.$refs.input && this.$refs.input.focus();
            },
            send: async function (preset) {
                var text = (typeof preset === 'string' ? preset : this.input)
                    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '').trim().slice(0, cfg.max);
                if (!text || this.sending) return;

                var history = this.messages.filter(function (m) { return !m.error; })
                    .slice(-cfg.turns).map(function (m) { return { role: m.role, text: m.text }; });
                this.messages.push({ role: 'user', text: text });
                this.input = '';
                this.sending = true;
                this.save();
                this.scrollDown();
                this.$nextTick(() => this.resize());

                var reply = cfg.t.failed, error = true;
                try {
                    var token = document.querySelector('meta[name="csrf-token"]');
                    var res = await fetch(cfg.endpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': token ? token.content : ''
                        },
                        body: JSON.stringify({ message: text, history: history })
                    });
                    var data = null;
                    try { data = await res.json(); } catch (e) {}
                    if (res.status === 419) reply = cfg.t.expired;
                    else if (data && typeof data.reply === 'string' && data.reply) { reply = data.reply; error = !res.ok || !!data.fallback; }
                    else if (res.status === 422) reply = cfg.t.invalid;
                } catch (e) {
                    reply = cfg.t.offline;
                }

                this.messages.push({ role: 'assistant', text: reply, error: error });
                this.sending = false;
                this.save();
                this.scrollDown();
                this.$nextTick(() => this.$refs.input && this.$refs.input.focus());
            }
        };
    };
</script>

<div class="rs-fa print:hidden" x-data="rsAssistants()" :class="{ 'rs-fa-open': open }"
     @keydown.escape.window="close()">

    {{-- Backdrop: closes the open panel --}}
    <div x-show="open" x-cloak x-transition.opacity @click="close()" aria-hidden="true"
         class="fixed inset-0 z-[65] bg-slate-900/40 md:bg-transparent"></div>

    {{-- Left: RuteStrip AI Chat --}}
    <button type="button" x-ref="chatFab" @click="toggle('chat', $el)"
            :aria-expanded="(open === 'chat').toString()" aria-controls="rs-ai-chat-panel" aria-haspopup="dialog"
            aria-label="{{ __('Buka RuteStrip AI Chat') }}" title="RuteStrip AI Chat"
            class="rs-fab rs-fab-left {{ $faHideMobile ? 'rs-fab-hide-sm' : '' }} flex items-center justify-center bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-lg shadow-emerald-900/25 ring-1 ring-white/40 hover:from-emerald-600 hover:to-teal-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-300 transition-colors">
        <x-icon name="chat" class="w-7 h-7" x-show="open !== 'chat'" />
        <x-icon name="x" class="w-6 h-6" x-show="open === 'chat'" x-cloak />
    </button>

    {{-- Right: Asisten RuteStrip --}}
    <button type="button" x-ref="assistantFab" @click="toggle('assistant', $el)"
            :aria-expanded="(open === 'assistant').toString()" aria-controls="rs-assistant-panel" aria-haspopup="dialog"
            aria-label="{{ __('Buka Asisten RuteStrip') }}" title="{{ __('Asisten RuteStrip') }}"
            class="rs-fab rs-fab-right {{ $faHideMobile ? 'rs-fab-hide-sm' : '' }} flex items-center justify-center bg-white text-emerald-700 border border-emerald-200 shadow-lg shadow-emerald-900/20 hover:bg-emerald-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-300 transition-colors">
        <x-icon name="robot" class="w-7 h-7" x-show="open !== 'assistant'" />
        <x-icon name="x" class="w-6 h-6" x-show="open === 'assistant'" x-cloak />
    </button>

    {{-- AI Chat panel --}}
    @auth
    <section id="rs-ai-chat-panel" x-show="open === 'chat'" x-cloak x-transition.opacity
             role="dialog" aria-modal="true" aria-labelledby="rs-ai-chat-title" tabindex="-1" @keydown.tab="trap($event)"
             class="rs-panel rs-panel-left" data-panel="chat-app">
        <header class="flex items-center gap-2 px-3 py-2.5 bg-gradient-to-r from-emerald-600 to-teal-600 text-white flex-shrink-0">
            <span class="w-8 h-8 rounded-full bg-white/15 flex items-center justify-center flex-shrink-0"><x-icon name="chat" class="w-5 h-5" /></span>
            <h2 id="rs-ai-chat-title" class="font-semibold text-sm flex-1 min-w-0 truncate">RuteStrip AI Chat</h2>
            <a href="{{ $faChatUrl }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-white/90 hover:bg-white/15 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                <x-icon name="external-link" class="w-4 h-4" /><span>{{ __('Buka di tab baru') }}</span>
            </a>
            <button type="button" @click="close()" data-autofocus aria-label="{{ __('Tutup') }}"
                    class="w-9 h-9 inline-flex items-center justify-center rounded-lg hover:bg-white/15 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                <x-icon name="x" class="w-5 h-5" />
            </button>
        </header>
        <div class="relative flex-1 min-h-0 bg-slate-50">
            <div x-show="!chatReady" class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-sm text-slate-500" role="status">
                <span class="w-8 h-8 rounded-full border-2 border-emerald-200 border-t-emerald-600 animate-spin" aria-hidden="true"></span>
                {{ __('Memuat AI Chat...') }}
            </div>
            {{-- Loaded on first open, then kept so the conversation survives closing. Not sandboxed:
                 the chat needs scripts, its own cookies, forms, popups and top navigation. --}}
            <template x-if="chatLoaded">
                <iframe src="{{ $faChatUrl }}?embed=1&amp;lang={{ app()->getLocale() }}" title="RuteStrip AI Chat" @load="chatReady = true"
                        allow="clipboard-write" referrerpolicy="strict-origin-when-cross-origin"
                        class="absolute inset-0 w-full h-full border-0 bg-white"></iframe>
            </template>
        </div>
    </section>
    @else
    <section id="rs-ai-chat-panel" x-show="open === 'chat'" x-cloak x-transition.opacity
             role="dialog" aria-modal="true" aria-labelledby="rs-ai-chat-title" aria-describedby="rs-ai-chat-desc" tabindex="-1" @keydown.tab="trap($event)"
             class="rs-panel rs-panel-sheet rs-panel-left" data-panel="chat-guest">
        <div class="flex items-start gap-3 p-5 pb-3">
            <span class="w-11 h-11 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center flex-shrink-0"><x-icon name="chat" class="w-6 h-6" /></span>
            <div class="flex-1 min-w-0">
                <h2 id="rs-ai-chat-title" class="font-semibold text-slate-900">RuteStrip AI Chat</h2>
                <p class="mt-0.5 text-sm font-medium text-emerald-700">{{ __('Buat akun dulu untuk memakai AI Chat') }}</p>
            </div>
            <button type="button" @click="close()" aria-label="{{ __('Tutup') }}"
                    class="w-9 h-9 -mr-2 -mt-1 inline-flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400">
                <x-icon name="x" class="w-5 h-5" />
            </button>
        </div>
        <p id="rs-ai-chat-desc" class="px-5 text-sm text-slate-600 leading-relaxed">
            {{ __('Satu akun gratis untuk situs RuteStrip dan AI Chat: rencana pendakian, cuaca, porter, dan peta satelit.') }}
        </p>
        <div class="grid grid-cols-2 gap-2 p-5 pt-4">
            <a href="{{ route('assistant.chat.login', ['to' => 'register', 'back' => $faBack]) }}" data-autofocus
               class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-300">
                <x-icon name="user-add" class="w-4 h-4" />{{ __('Daftar') }}
            </a>
            <a href="{{ route('assistant.chat.login', ['back' => $faBack]) }}"
               class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                <x-icon name="login" class="w-4 h-4" />{{ __('Masuk') }}
            </a>
        </div>
        <p class="px-5 pb-5 -mt-1 text-xs text-slate-500">{{ __('Setelah masuk, Anda kembali ke halaman ini.') }}</p>
    </section>
    @endauth

    {{-- Asisten RuteStrip panel --}}
    <section id="rs-assistant-panel" x-show="open === 'assistant'" x-cloak x-transition.opacity
             role="dialog" aria-modal="true" aria-labelledby="rs-assistant-title" tabindex="-1" @keydown.tab="trap($event)"
             class="rs-panel rs-panel-right">
        <header class="flex items-center gap-2 px-3 py-2.5 border-b border-slate-100 bg-white flex-shrink-0">
            <span class="w-9 h-9 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100 flex items-center justify-center flex-shrink-0"><x-icon name="robot" class="w-5 h-5" /></span>
            <div class="flex-1 min-w-0">
                <h2 id="rs-assistant-title" class="font-semibold text-sm text-slate-900 truncate">{{ __('Asisten RuteStrip') }}</h2>
                <p class="text-xs text-slate-500 truncate">{{ __('Tanya seputar RuteStrip') }}</p>
            </div>
            <button type="button" @click="clear()" x-show="messages.length" aria-label="{{ __('Hapus percakapan') }}" title="{{ __('Hapus percakapan') }}"
                    class="w-9 h-9 inline-flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400">
                <x-icon name="trash" class="w-5 h-5" />
            </button>
            <button type="button" @click="close()" aria-label="{{ __('Tutup') }}"
                    class="w-9 h-9 inline-flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400">
                <x-icon name="x" class="w-5 h-5" />
            </button>
        </header>

        <div x-ref="log" class="flex-1 min-h-0 overflow-y-auto overscroll-contain px-3 py-4 space-y-3 bg-slate-50" role="log" aria-live="polite" aria-relevant="additions">
            <div class="flex justify-start">
                <div class="max-w-[85%] rounded-2xl rounded-bl-md border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-700 leading-relaxed">
                    {{ __('Halo! Saya bisa menjawab pertanyaan tentang RuteStrip: fitur, cara upload GPX, metode pencarian, dan jalur yang tersedia.') }}
                </div>
            </div>
            <div x-show="!messages.length" class="flex flex-wrap gap-2 pt-1">
                @foreach($faChips as $chip)
                <button type="button" @click="send(@js($chip))"
                        class="rounded-full border border-emerald-200 bg-white px-3 py-1.5 text-xs font-medium text-emerald-800 hover:bg-emerald-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400">{{ $chip }}</button>
                @endforeach
            </div>
            <template x-for="(m, i) in messages" :key="i">
                <div class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                    <template x-if="m.role === 'user'">
                        <div class="max-w-[85%] rounded-2xl rounded-br-md bg-emerald-600 px-3.5 py-2 text-sm text-white whitespace-pre-wrap break-words" x-text="m.text"></div>
                    </template>
                    <template x-if="m.role !== 'user'">
                        <div class="rs-md max-w-[90%] rounded-2xl rounded-bl-md border px-3.5 py-2.5 text-sm leading-relaxed break-words"
                             :class="m.error ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-slate-200 bg-white text-slate-700'"
                             x-html="render(m.text)"></div>
                    </template>
                </div>
            </template>
            <div x-show="sending" x-cloak class="flex justify-start" role="status">
                <div class="rounded-2xl rounded-bl-md border border-slate-200 bg-white px-3.5 py-3 text-slate-500 inline-flex items-center gap-1">
                    <span class="sr-only">{{ __('Asisten sedang mengetik') }}</span>
                    <span class="rs-dot w-1.5 h-1.5 rounded-full bg-current" aria-hidden="true"></span>
                    <span class="rs-dot w-1.5 h-1.5 rounded-full bg-current" aria-hidden="true"></span>
                    <span class="rs-dot w-1.5 h-1.5 rounded-full bg-current" aria-hidden="true"></span>
                </div>
            </div>
        </div>

        <form @submit.prevent="send()" class="flex-shrink-0 border-t border-slate-100 bg-white px-3 pt-2.5 pb-2">
            <div class="flex items-end gap-2">
                <label for="rs-assistant-input" class="sr-only">{{ __('Pertanyaan untuk Asisten RuteStrip') }}</label>
                <textarea id="rs-assistant-input" x-ref="input" x-model="input" rows="1" maxlength="{{ \App\Http\Controllers\AssistantController::MAX_INPUT }}"
                          @input="resize()" @keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); send(); }"
                          placeholder="{{ __('Tulis pertanyaan...') }}"
                          class="flex-1 resize-none rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-base md:text-sm text-slate-800 placeholder-slate-400 focus:border-emerald-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-200"
                          style="max-height: 8rem"></textarea>
                <button type="submit" :disabled="sending || !input.trim()" aria-label="{{ __('Kirim') }}"
                        class="w-11 h-11 flex-shrink-0 inline-flex items-center justify-center rounded-xl bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-slate-200 disabled:text-slate-400 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-300">
                    <x-icon name="arrow-right" class="w-5 h-5" />
                </button>
            </div>
            <div class="mt-1.5 flex items-center justify-between gap-2 text-[11px] text-slate-400">
                <span>{{ __('Jawaban dibuat AI dan bisa keliru.') }}</span>
                <span x-show="input.length > max - 200" x-cloak x-text="input.length + '/' + max"></span>
            </div>
        </form>
    </section>
</div>
