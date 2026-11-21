{{--
    Floating buttons on every page (included once from layouts/app):
    - left:  "Obrolan Pendaki", a chat room where logged-in hikers talk to each other
             (CommunityChatController, JSON under /komunitas/chat). Guests are asked to
             sign up / log in first and come back here (#obrolan opens the panel again).
             New messages are polled every ~4 s while the panel is open; while it is
             closed a cheap last-id check every ~30 s drives the unread dot. Polling
             pauses while the tab is hidden.
    - right: "Asisten RuteStrip" (Q&A chatbot about this project, POST /assistant, no login needed)
    Only one panel is open at a time; Escape or the backdrop closes it. Hidden in print.
    The AI web chat (chat.rutestrip.ai.id) is reached from the navbar and the mobile tab bar.
    Bottom offsets add var(--rs-fab-lift): the bottom banner height when it reaches a corner (phones), else 0; set on <html> by the layout.
--}}
@php
    $faUser     = auth()->user();
    // Pages whose form submit button sits low on small screens: no floating buttons below 768 px.
    $faHideMobile = request()->routeIs('routes.create', 'routes.batch', 'routes.versions.create', 'settings', 'login', 'register', 'admin.login');
    $faBack     = request()->getRequestUri();
    $faCc       = \App\Http\Controllers\CommunityChatController::class;
    $faConfig   = [
        'endpoint' => route('assistant.ask'),
        'loggedIn' => (bool) $faUser,
        'locale'   => app()->getLocale(),
        'max'      => \App\Http\Controllers\AssistantController::MAX_INPUT,
        'turns'    => \App\Http\Controllers\AssistantController::HISTORY_TURNS,
        'community' => $faUser ? [
            'url'       => route('community.chat.index'),
            'latest'    => route('community.chat.latest'),
            'store'     => route('community.chat.store'),
            'base'      => url('/komunitas/chat'),
            'max'       => $faCc::MAX_LENGTH,
            'poll'      => 4000,
            'badgePoll' => 30000,
            'seenKey'   => 'rs_community_seen_' . $faUser->id,
            'me'        => $faUser->name,
            'admin'     => $faUser->isAdmin(),
        ] : null,
        't'        => [
            'offline' => __('Gagal terhubung. Periksa koneksi lalu coba lagi.'),
            'expired' => __('Sesi berakhir. Muat ulang halaman lalu coba lagi.'),
            'invalid' => __('Pertanyaan tidak valid atau terlalu panjang (maks. :max karakter).', ['max' => \App\Http\Controllers\AssistantController::MAX_INPUT]),
            'failed'  => __('Asisten sedang tidak tersedia. Coba lagi nanti.'),
            'justNow'       => __('baru saja'),
            'sending'       => __('mengirim...'),
            'sendFailed'    => __('Gagal terkirim.'),
            'msgInvalid'    => __('Pesan kosong atau terlalu panjang (maks. :max karakter).', ['max' => $faCc::MAX_LENGTH]),
            'authLost'      => __('Sesi berakhir. Muat ulang halaman lalu masuk lagi.'),
            'actionFailed'  => __('Gagal. Coba lagi sebentar lagi.'),
            'confirmDelete' => __('Hapus pesan ini? Pesan akan tertulis "Pesan dihapus" untuk semua orang.'),
            'confirmReport' => __('Laporkan pesan ini ke admin?'),
        ],
    ];
    $faChips = [__('Apa itu RuteStrip?'), __('Jalur untuk pemula?'), __('Cara upload GPX'), __('Cara kerja pencariannya?')];
@endphp
<style>
    .rs-fab { position: fixed; z-index: 45; width: 56px; height: 56px; border-radius: 9999px;
              bottom: calc(4rem + env(safe-area-inset-bottom, 0px) + 12px + var(--rs-fab-lift, 0px)); }
    .rs-fab-left { left: calc(12px + env(safe-area-inset-left, 0px)); }
    .rs-fab-right { right: calc(12px + env(safe-area-inset-right, 0px)); }
    .rs-panel { position: fixed; z-index: 70; inset: 0; bottom: var(--rs-fab-lift, 0px); display: flex; flex-direction: column; background: #fff;
                padding-top: env(safe-area-inset-top, 0px); padding-bottom: env(safe-area-inset-bottom, 0px); }
    .rs-panel-sheet { inset: auto 0 0 0; bottom: var(--rs-fab-lift, 0px); border-radius: 1.25rem 1.25rem 0 0; box-shadow: 0 -12px 40px rgba(15, 23, 42, .18); }
    html.rs-fa-lock, html.rs-fa-lock body { overflow: hidden; }
    @media (max-width: 767.98px) { .rs-fab-hide-sm { display: none !important; } }
    @media (min-width: 768px) {
        .rs-fab { bottom: calc(24px + var(--rs-fab-lift, 0px)); }
        .rs-fab-left { left: 24px; }
        .rs-fab-right { right: 24px; }
        .rs-fa-open .rs-fab { z-index: 68; }
        .rs-panel { inset: auto; bottom: calc(96px + var(--rs-fab-lift, 0px)); width: 380px;
                    height: min(600px, calc(100vh - 176px - var(--rs-fab-lift, 0px))); padding: 0;
                    border: 1px solid #e2e8f0; border-radius: 1rem; overflow: hidden; box-shadow: 0 24px 60px -12px rgba(15, 23, 42, .35); }
        .rs-panel-chat { height: min(560px, calc(100vh - 176px - var(--rs-fab-lift, 0px))); }
        .rs-panel-left { left: 24px; }
        .rs-panel-right { right: 24px; }
        .rs-panel-sheet { height: auto; }
    }
    /* Obrolan Pendaki: message text is server-escaped plain text with https links only. */
    .rs-cbody { white-space: pre-wrap; overflow-wrap: anywhere; }
    .rs-cbody a { text-decoration: underline; text-underline-offset: 2px; word-break: break-all; }
    .rs-cmine .rs-cbody a { color: #fff; }
    .rs-cother .rs-cbody a { color: #047857; }
    @media (hover: hover) {
        .rs-msg .rs-act { opacity: 0; transition: opacity .15s; }
        .rs-msg:hover .rs-act, .rs-msg:focus-within .rs-act { opacity: 1; }
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

        var C = cfg.community; // Obrolan Pendaki endpoints, null for guests
        var T = cfg.t;
        var gone = { deleted: true, body: null, html: null, can_delete: false, can_report: false };

        return {
            open: null,
            messages: [],
            input: '',
            sending: false,
            lastFab: null,
            max: cfg.max,
            render: render,

            // Obrolan Pendaki state
            cItems: [],
            cLoaded: false,
            cLoading: false,
            cError: null,          // 'load' | 'offline' | 'auth'
            cHasMore: false,
            cOlderLoading: false,
            cOlderError: false,
            cPollError: false,
            cUnread: false,
            cNewBelow: 0,
            cInput: '',
            cTick: 0,
            cMax: C ? C.max : 500,
            cAdmin: !!(C && C.admin),
            cTimer: null,
            cPolling: false,
            cStopped: false,
            cSeq: 0,
            cSeen: 0,

            init: function () {
                try {
                    var saved = JSON.parse(sessionStorage.getItem(KEY) || '[]');
                    if (Array.isArray(saved)) {
                        this.messages = saved.filter(function (m) {
                            return m && (m.role === 'user' || m.role === 'assistant') && typeof m.text === 'string';
                        }).slice(-30);
                    }
                } catch (e) {}
                if (C) {
                    this.cSeen = this.cReadSeen();
                    document.addEventListener('visibilitychange', () => this.cWake());
                    setInterval(() => { this.cTick++; }, 30000);
                    this.cTimer = setTimeout(() => this.cCheckUnread(), 1500);
                }
                // Back from "Daftar / Masuk" in the guest panel (#ai-chat: old links): open it again.
                if (location.hash === '#obrolan' || location.hash === '#ai-chat') {
                    history.replaceState(null, '', location.pathname + location.search);
                    if (cfg.loggedIn) this.$nextTick(() => this.show('chat', this.$refs.chatFab));
                }
            },
            toggle: function (which, fab) {
                this.open === which ? this.close() : this.show(which, fab);
            },
            show: function (which, fab) {
                var wasChat = this.open === 'chat';
                this.lastFab = fab || null;
                this.open = which;
                document.documentElement.classList.toggle('rs-fa-lock', window.matchMedia('(max-width: 767.98px)').matches);
                if (which === 'chat' && C && !wasChat) {
                    this.cUnread = false;
                    this.cLoaded ? this.cPoll() : this.cLoad();
                } else if (wasChat && C) {
                    this.cSchedule();
                }
                this.$nextTick(() => {
                    var panel = this.panel();
                    var desktop = window.matchMedia('(min-width: 768px)').matches;
                    var target = which === 'assistant' ? this.$refs.input
                        : (C ? (desktop ? this.$refs.cinput : null) : (panel && panel.querySelector('[data-autofocus]')));
                    (target || panel) && (target || panel).focus();
                    if (which === 'assistant') this.scrollDown();
                    if (which === 'chat' && C) this.cScroll();
                });
            },
            close: function () {
                if (!this.open) return;
                var was = this.open;
                this.open = null;
                document.documentElement.classList.remove('rs-fa-lock');
                if (was === 'chat' && C) { this.cMarkSeen(); this.cSchedule(); }
                var fab = this.lastFab;
                this.lastFab = null;
                if (fab) this.$nextTick(function () { fab.focus(); });
            },
            panel: function () {
                return this.open ? document.getElementById(this.open === 'chat' ? 'rs-community-panel' : 'rs-assistant-panel') : null;
            },
            // Keep Tab / Shift+Tab inside the open panel.
            trap: function (e) {
                var panel = this.panel();
                if (!panel) return;
                var items = Array.prototype.filter.call(
                    panel.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled])'),
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
            },

            // ---------------- Obrolan Pendaki ----------------
            cApi: async function (method, url, body) {
                var token = document.querySelector('meta[name="csrf-token"]');
                var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token ? token.content : '' };
                if (body) headers['Content-Type'] = 'application/json';
                var res = await fetch(url, { method: method, credentials: 'same-origin', headers: headers, body: body ? JSON.stringify(body) : undefined });
                var data = null;
                try { data = await res.json(); } catch (e) {}
                return { status: res.status, ok: res.ok, data: data };
            },
            // One timer: fast polling while the panel is open, the unread check while closed, nothing while the tab is hidden.
            cSchedule: function () {
                clearTimeout(this.cTimer);
                this.cTimer = null;
                if (!C || this.cStopped || document.hidden) return;
                var fast = this.open === 'chat';
                this.cTimer = setTimeout(() => (fast ? this.cPoll() : this.cCheckUnread()), fast ? C.poll : C.badgePoll);
            },
            cWake: function () {
                if (!C || this.cStopped) return;
                if (document.hidden) { clearTimeout(this.cTimer); this.cTimer = null; return; }
                if (this.open === 'chat') this.cLoaded ? this.cPoll() : this.cLoad();
                else this.cCheckUnread();
            },
            cAuthLost: function () {
                this.cStopped = true;
                this.cError = 'auth';
                clearTimeout(this.cTimer);
            },
            cFind: function (id) {
                return this.cItems.find(function (m) { return m.id === id; });
            },
            cLastId: function () {
                return this.cItems.reduce(function (max, m) { return typeof m.id === 'number' && m.id > max ? m.id : max; }, 0);
            },
            cFirstId: function () {
                return this.cItems.reduce(function (min, m) { return typeof m.id === 'number' && (!min || m.id < min) ? m.id : min; }, 0);
            },
            cReadSeen: function () {
                try { return parseInt(localStorage.getItem(C.seenKey) || '0', 10) || 0; } catch (e) { return 0; }
            },
            cMarkSeen: function () {
                var id = this.cLastId();
                if (id > this.cSeen) {
                    this.cSeen = id;
                    try { localStorage.setItem(C.seenKey, String(id)); } catch (e) {}
                }
            },
            cAtBottom: function () {
                var box = this.$refs.clog;
                return !box || box.scrollHeight - box.scrollTop - box.clientHeight < 80;
            },
            cScroll: function () {
                this.cNewBelow = 0;
                // Again on the next frame: the log may only just have become visible.
                var go = () => { var box = this.$refs.clog; if (box) box.scrollTop = box.scrollHeight; };
                this.$nextTick(() => { go(); requestAnimationFrame(go); });
            },
            cOnScroll: function () {
                var box = this.$refs.clog;
                if (!box) return;
                if (box.scrollTop < 60 && !this.cOlderError) this.cLoadOlder();
                if (this.cAtBottom()) this.cNewBelow = 0;
            },
            cLoad: async function () {
                if (!C || this.cLoading || this.cStopped) return;
                this.cLoading = true;
                this.cError = null;
                try {
                    var r = await this.cApi('GET', C.url);
                    if (r.status === 401) return this.cAuthLost();
                    if (!r.ok || !r.data) throw new Error('load');
                    this.cItems = r.data.messages || [];
                    this.cHasMore = !!r.data.has_more;
                    this.cLoaded = true;
                    this.cPollError = false;
                    this.cMarkSeen();
                    this.cScroll();
                } catch (e) {
                    this.cError = navigator.onLine === false ? 'offline' : 'load';
                } finally {
                    this.cLoading = false;
                    this.cSchedule();
                }
            },
            cPoll: async function () {
                if (!this.cLoaded) return this.cLoad();
                if (this.cPolling || this.cStopped) return;
                this.cPolling = true;
                try {
                    var r = await this.cApi('GET', C.url + '?after=' + this.cLastId());
                    if (r.status === 401) return this.cAuthLost();
                    if (!r.ok || !r.data) throw new Error('poll');
                    this.cPollError = false;
                    (r.data.deleted || []).forEach((id) => { var m = this.cFind(id); if (m && !m.deleted) Object.assign(m, gone); });
                    this.cMerge(r.data.messages || []);
                } catch (e) {
                    this.cPollError = true;
                } finally {
                    this.cPolling = false;
                    this.cSchedule();
                }
            },
            // Newer messages go before any message that is still sending or failed.
            cMerge: function (list) {
                if (!list.length) return;
                var stick = this.cAtBottom(), added = 0;
                var at = this.cItems.findIndex(function (m) { return m.tmp; });
                list.forEach((m) => {
                    var i = this.cItems.findIndex(function (x) { return x.id === m.id; });
                    if (i >= 0) { this.cItems.splice(i, 1, m); return; }
                    if (at >= 0) this.cItems.splice(at++, 0, m); else this.cItems.push(m);
                    if (!m.mine) added++;
                });
                if (this.open === 'chat') this.cMarkSeen();
                if (stick) this.cScroll(); else this.cNewBelow += added;
            },
            cLoadOlder: async function () {
                if (!this.cHasMore || this.cOlderLoading || !this.cLoaded) return;
                var first = this.cFirstId();
                var box = this.$refs.clog;
                if (!first || !box) return;
                var height = box.scrollHeight, top = box.scrollTop;
                this.cOlderLoading = true;
                this.cOlderError = false;
                try {
                    var r = await this.cApi('GET', C.url + '?before=' + first);
                    if (r.status === 401) return this.cAuthLost();
                    if (!r.ok || !r.data) throw new Error('older');
                    var older = (r.data.messages || []).filter((m) => !this.cFind(m.id));
                    this.cItems.unshift.apply(this.cItems, older);
                    this.cHasMore = !!r.data.has_more;
                    this.$nextTick(() => { box.scrollTop = box.scrollHeight - height + top; });
                } catch (e) {
                    this.cOlderError = true;
                } finally {
                    this.cOlderLoading = false;
                }
            },
            cCheckUnread: async function () {
                if (!C || this.cStopped) return;
                try {
                    var r = await this.cApi('GET', C.latest);
                    if (r.status === 401) return this.cAuthLost();
                    this.cSeen = Math.max(this.cSeen, this.cReadSeen()); // seen in another tab
                    if (r.ok && r.data && this.open !== 'chat') this.cUnread = r.data.last_id > this.cSeen;
                } catch (e) {
                } finally {
                    this.cSchedule();
                }
            },
            cResize: function () {
                var t = this.$refs.cinput;
                if (!t) return;
                t.style.height = 'auto';
                t.style.height = Math.min(t.scrollHeight, 128) + 'px';
                t.style.overflowY = t.scrollHeight > 128 ? 'auto' : 'hidden';
            },
            cSend: async function (retry) {
                if (!C || !this.cLoaded || this.cStopped) return;
                var item;
                if (retry) {
                    item = retry;
                    item.status = 'sending';
                    item.error = null;
                } else {
                    var text = this.cInput.replace(/\r\n?/g, '\n').replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '').trim();
                    if (!text) return;
                    this.cItems.push({
                        id: 'tmp-' + (++this.cSeq), tmp: true, mine: true, name: C.me, body: text.slice(0, this.cMax), html: null,
                        deleted: false, created_at: new Date().toISOString(), can_delete: false, can_report: false, reported: false,
                        status: 'sending', error: null
                    });
                    item = this.cItems[this.cItems.length - 1];
                    this.cInput = '';
                    this.$nextTick(() => this.cResize());
                    this.cScroll();
                }
                try {
                    var r = await this.cApi('POST', C.store, { body: item.body });
                    if (r.status === 201 && r.data && r.data.message) {
                        var i = this.cItems.indexOf(item);
                        if (this.cFind(r.data.message.id)) { if (i >= 0) this.cItems.splice(i, 1); }
                        else if (i >= 0) this.cItems.splice(i, 1, r.data.message);
                        this.cMarkSeen();
                        return;
                    }
                    item.status = 'failed';
                    item.error = r.status === 401 ? T.authLost
                        : r.status === 419 ? T.expired
                        : (r.status === 429 || r.status === 422) && r.data && r.data.message ? r.data.message
                        : r.status === 422 ? T.msgInvalid : T.sendFailed;
                } catch (e) {
                    item.status = 'failed';
                    item.error = T.offline;
                }
            },
            cDiscard: function (m) {
                var i = this.cItems.indexOf(m);
                if (i >= 0) this.cItems.splice(i, 1);
                this.$nextTick(() => { var p = this.panel(); p && p.focus(); });
            },
            cAction: async function (m, kind) {
                if (!window.confirm(kind === 'delete' ? T.confirmDelete : T.confirmReport)) return;
                m.busy = true;
                m.actionError = null;
                try {
                    var r = kind === 'delete'
                        ? await this.cApi('DELETE', C.base + '/' + m.id)
                        : await this.cApi('POST', C.base + '/' + m.id + '/report', {});
                    if (r.ok && r.data && r.data.message) Object.assign(m, r.data.message);
                    else if (r.status === 404) Object.assign(m, gone);
                    else if (r.status === 401) this.cAuthLost();
                    else m.actionError = (r.data && r.data.message && r.status < 500) ? r.data.message : T.actionFailed;
                } catch (e) {
                    m.actionError = T.offline;
                } finally {
                    m.busy = false;
                    this.$nextTick(() => { var p = this.panel(); p && p.focus(); });
                }
            },
            cRel: function (iso) {
                this.cTick; // re-render every 30 s
                var d = new Date(iso), s = Math.round((Date.now() - d.getTime()) / 1000);
                if (!iso || isNaN(s)) return '';
                if (s < 45) return T.justNow;
                var rtf = new Intl.RelativeTimeFormat(cfg.locale, { numeric: 'auto' });
                if (s < 3600) return rtf.format(-Math.max(1, Math.round(s / 60)), 'minute');
                if (s < 86400) return rtf.format(-Math.round(s / 3600), 'hour');
                if (s < 7 * 86400) return rtf.format(-Math.round(s / 86400), 'day');
                return d.toLocaleDateString(cfg.locale, { day: 'numeric', month: 'short' });
            },
            cFull: function (iso) {
                return iso ? new Date(iso).toLocaleString(cfg.locale, { dateStyle: 'medium', timeStyle: 'short' }) : '';
            }
        };
    };
</script>

<div class="rs-fa print:hidden" x-data="rsAssistants()" :class="{ 'rs-fa-open': open }"
     @keydown.escape.window="close()">

    {{-- Backdrop: closes the open panel --}}
    <div x-show="open" x-cloak x-transition.opacity @click="close()" aria-hidden="true"
         class="fixed inset-0 z-[65] bg-slate-900/40 md:bg-transparent"></div>

    {{-- Left: Obrolan Pendaki (people, not AI). Yellow dot = new messages from other hikers. --}}
    <button type="button" x-ref="chatFab" @click="toggle('chat', $el)"
            :aria-expanded="(open === 'chat').toString()" aria-controls="rs-community-panel" aria-haspopup="dialog"
            aria-label="{{ __('Buka Obrolan Pendaki') }}" title="{{ __('Obrolan Pendaki') }}"
            :aria-label="cUnread && open !== 'chat' ? @js(__('Buka Obrolan Pendaki, ada pesan baru')) : @js(__('Buka Obrolan Pendaki'))"
            class="rs-fab rs-fab-left {{ $faHideMobile ? 'rs-fab-hide-sm' : '' }} flex items-center justify-center bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-lg shadow-emerald-900/25 ring-1 ring-white/40 hover:from-emerald-600 hover:to-teal-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-300 transition-colors">
        <x-icon name="chat-alt-2" class="w-7 h-7" x-show="open !== 'chat'" />
        <x-icon name="x" class="w-6 h-6" x-show="open === 'chat'" x-cloak />
        <span x-show="cUnread && open !== 'chat'" x-cloak data-unread-dot aria-hidden="true"
              class="absolute top-0.5 right-0.5 w-4 h-4 rounded-full bg-[#FCD34D] ring-2 ring-[#053b30]"></span>
    </button>

    {{-- Right: Asisten RuteStrip --}}
    <button type="button" x-ref="assistantFab" @click="toggle('assistant', $el)"
            :aria-expanded="(open === 'assistant').toString()" aria-controls="rs-assistant-panel" aria-haspopup="dialog"
            aria-label="{{ __('Buka Asisten RuteStrip') }}" title="{{ __('Asisten RuteStrip') }}"
            class="rs-fab rs-fab-right {{ $faHideMobile ? 'rs-fab-hide-sm' : '' }} flex items-center justify-center bg-white text-emerald-700 border border-emerald-200 shadow-lg shadow-emerald-900/20 hover:bg-emerald-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-300 transition-colors">
        <x-icon name="robot" class="w-7 h-7" x-show="open !== 'assistant'" />
        <x-icon name="x" class="w-6 h-6" x-show="open === 'assistant'" x-cloak />
    </button>

    {{-- Obrolan Pendaki panel. Dark zamrud header with a contour motif (DESIGN.md), map-paper log,
         yellow only on the send / sign-up action. --}}
    @auth
    <section id="rs-community-panel" x-show="open === 'chat'" x-cloak x-transition.opacity
             role="dialog" aria-modal="true" aria-labelledby="rs-community-title" tabindex="-1" @keydown.tab="trap($event)"
             class="rs-panel rs-panel-chat rs-panel-left focus:outline-none" data-panel="community">
        <header class="relative overflow-hidden flex items-center gap-3 pl-4 pr-1.5 py-2 bg-[#053b30] text-white flex-shrink-0">
            <svg class="absolute -right-8 -top-10 w-44 h-44 text-white/10 pointer-events-none" viewBox="0 0 160 160" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M82 14c34 3 62 26 58 60s-32 66-68 62-56-30-52-62 28-63 62-60z"/>
                <path d="M81 34c23 2 42 18 40 41s-22 45-46 42-38-21-35-42 19-43 41-41z"/>
                <path d="M80 54c12 1 22 10 21 22s-12 23-24 22-20-11-18-22 10-23 21-22z"/>
            </svg>
            <div class="relative flex-1 min-w-0">
                <h2 id="rs-community-title" class="font-bold text-base leading-tight truncate">{{ __('Obrolan Pendaki') }}</h2>
                <p class="text-xs text-emerald-100 truncate">{{ __('Obrolan Umum') }} <span aria-hidden="true">&middot;</span> {{ __('ngobrol dengan sesama pendaki') }}</p>
            </div>
            <button type="button" @click="close()" aria-label="{{ __('Tutup') }}"
                    class="relative w-11 h-11 inline-flex items-center justify-center rounded-lg hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#FCD34D]">
                <x-icon name="x" class="w-5 h-5" />
            </button>
        </header>

        <div class="relative flex-1 min-h-0 bg-[#f6f4ec]">
            {{-- Loading / error / session states before the first page arrives --}}
            <div x-show="!cLoaded" class="absolute inset-0 flex flex-col items-center justify-center gap-3 px-8 text-center text-sm text-slate-700">
                <template x-if="cLoading || (!cError && !cLoaded)">
                    <div class="flex flex-col items-center gap-3" role="status">
                        <span class="w-8 h-8 rounded-full border-2 border-emerald-200 border-t-emerald-700 animate-spin motion-reduce:animate-none" aria-hidden="true"></span>
                        {{ __('Memuat obrolan...') }}
                    </div>
                </template>
                <template x-if="!cLoading && (cError === 'load' || cError === 'offline')">
                    <div class="flex flex-col items-center gap-3" role="alert">
                        <x-icon name="cloud" class="w-8 h-8 text-slate-500" />
                        <p x-text="cError === 'offline' ? @js(__('Kamu sedang offline. Sambungkan internet lalu coba lagi.')) : @js(__('Obrolan gagal dimuat.'))"></p>
                        <button type="button" @click="cLoad()"
                                class="inline-flex items-center gap-2 min-h-[44px] rounded-lg border border-[#053b30] bg-white px-4 text-sm font-semibold text-[#053b30] hover:bg-emerald-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-300">
                            <x-icon name="refresh" class="w-4 h-4" />{{ __('Coba lagi') }}
                        </button>
                    </div>
                </template>
            </div>

            <div x-ref="clog" x-show="cLoaded" @scroll.passive="cOnScroll()"
                 class="absolute inset-0 overflow-y-auto overscroll-contain px-3 py-3 space-y-3"
                 role="log" aria-live="polite" aria-relevant="additions" aria-label="{{ __('Pesan Obrolan Pendaki') }}">
                {{-- Older messages: automatic on scroll-up, button for keyboard users and short logs --}}
                <div class="flex justify-center" x-show="cHasMore || cOlderLoading || cOlderError">
                    <button type="button" @click="cOlderError = false; cLoadOlder()" :disabled="cOlderLoading"
                            class="inline-flex items-center gap-2 min-h-[44px] rounded-lg px-3 text-xs font-semibold text-[#065f46] hover:bg-white/70 disabled:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
                        <span x-show="cOlderLoading" class="w-4 h-4 rounded-full border-2 border-emerald-200 border-t-emerald-700 animate-spin motion-reduce:animate-none" aria-hidden="true"></span>
                        <span x-text="cOlderLoading ? @js(__('Memuat pesan sebelumnya...')) : (cOlderError ? @js(__('Gagal memuat. Coba lagi')) : @js(__('Muat pesan sebelumnya')))"></span>
                    </button>
                </div>
                <p x-show="cLoaded && !cHasMore && cItems.length" class="text-center text-[11px] text-slate-600">
                    {{ __('Pesan yang lebih lama dari :days hari tidak ditampilkan.', ['days' => $faCc::RETENTION_DAYS]) }}
                </p>

                {{-- Empty --}}
                <div x-show="cLoaded && !cItems.length" class="h-full flex flex-col items-center justify-center gap-3 px-6 text-center">
                    <span class="w-12 h-12 rounded-xl border border-[#053b30]/20 bg-white text-[#047857] flex items-center justify-center"><x-icon name="chat-alt-2" class="w-6 h-6" /></span>
                    <p class="text-sm font-medium text-slate-800">{{ __('Belum ada obrolan. Sapa pendaki lain duluan.') }}</p>
                </div>

                <template x-for="m in cItems" :key="m.id">
                    <div class="rs-msg flex flex-col" :class="m.mine ? 'items-end rs-cmine' : 'items-start rs-cother'">
                        <div class="flex items-baseline gap-1.5 max-w-full px-1 text-[11px] text-slate-600" :class="m.mine ? 'flex-row-reverse' : ''">
                            <span x-show="!m.mine" class="font-semibold text-[13px] text-[#065f46] truncate" x-text="m.name"></span>
                            <time :datetime="m.created_at" :title="cFull(m.created_at)"
                                  x-text="m.status === 'sending' ? @js(__('mengirim...')) : cRel(m.created_at)"></time>
                            <span x-show="m.reported" x-cloak class="font-semibold text-amber-800"
                                  x-text="cAdmin ? @js(__('Dilaporkan')) : @js(__('Sudah kamu laporkan'))"></span>
                        </div>
                        <div class="mt-0.5 flex items-center gap-0.5 w-full" :class="m.mine ? 'flex-row-reverse' : ''">
                            <div class="max-w-[82%] min-w-0 rounded-xl px-3 py-2 text-sm leading-relaxed"
                                 :class="m.deleted ? 'border border-dashed border-slate-300 bg-transparent text-slate-600 italic'
                                       : (m.mine ? 'rounded-br-sm bg-[#047857] text-white' + (m.status === 'failed' ? ' opacity-80' : '')
                                       : 'rounded-bl-sm border bg-white text-slate-800 ' + (m.reported && cAdmin ? 'border-amber-400' : 'border-[#e3dfd0]'))">
                                <span x-show="m.deleted">{{ __('Pesan dihapus') }}</span>
                                <div x-show="!m.deleted && m.html !== null" class="rs-cbody" x-html="m.html"></div>
                                <div x-show="!m.deleted && m.html === null" class="rs-cbody" x-text="m.body"></div>
                            </div>
                            <button type="button" x-show="m.can_delete" @click="cAction(m, 'delete')" :disabled="!!m.busy"
                                    aria-label="{{ __('Hapus pesan') }}" title="{{ __('Hapus pesan') }}"
                                    class="rs-act flex-shrink-0 w-11 h-11 inline-flex items-center justify-center rounded-lg text-slate-600 hover:bg-white hover:text-red-700 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
                                <x-icon name="trash" class="w-4 h-4" />
                            </button>
                            <button type="button" x-show="m.can_report && !m.reported" @click="cAction(m, 'report')" :disabled="!!m.busy"
                                    aria-label="{{ __('Laporkan pesan') }}" title="{{ __('Laporkan pesan') }}"
                                    class="rs-act flex-shrink-0 w-11 h-11 inline-flex items-center justify-center rounded-lg text-slate-600 hover:bg-white hover:text-amber-800 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
                                <x-icon name="flag" class="w-4 h-4" />
                            </button>
                        </div>
                        <p x-show="m.actionError" x-cloak class="px-1 text-xs text-red-700" role="alert" x-text="m.actionError"></p>
                        <div x-show="m.status === 'failed'" x-cloak class="flex flex-wrap items-center justify-end gap-x-1 px-1 text-xs" role="alert">
                            <span class="text-red-700" x-text="m.error"></span>
                            <button type="button" @click="cSend(m)" class="min-h-[44px] px-2 font-semibold text-[#065f46] underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 rounded">{{ __('Kirim ulang') }}</button>
                            <button type="button" @click="cDiscard(m)" class="min-h-[44px] px-2 font-semibold text-slate-700 underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 rounded">{{ __('Buang') }}</button>
                        </div>
                    </div>
                </template>
            </div>

            <button type="button" x-show="cNewBelow > 0" x-cloak @click="cScroll()"
                    class="absolute bottom-3 left-1/2 -translate-x-1/2 inline-flex items-center gap-1.5 min-h-[44px] rounded-lg bg-[#053b30] px-4 text-xs font-semibold text-white shadow-md focus:outline-none focus-visible:ring-4 focus-visible:ring-[#FCD34D]">
                <x-icon name="chevron-down" class="w-4 h-4" /><span x-text="cNewBelow + ' ' + @js(__('pesan baru'))"></span>
            </button>
        </div>

        {{-- Connection / session problems after the first load --}}
        <div x-show="cLoaded && cPollError && cError !== 'auth'" x-cloak role="status"
             class="flex items-center justify-between gap-2 border-t border-amber-200 bg-amber-50 px-3 py-1 text-xs text-amber-900 flex-shrink-0">
            <span>{{ __('Koneksi terputus. Pesan baru belum masuk.') }}</span>
            <button type="button" @click="cPoll()" class="min-h-[44px] px-2 font-semibold underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 rounded">{{ __('Coba lagi') }}</button>
        </div>
        <div x-show="cError === 'auth'" x-cloak role="alert"
             class="flex items-center justify-between gap-2 border-t border-amber-200 bg-amber-50 px-3 py-1 text-xs text-amber-900 flex-shrink-0">
            <span>{{ __('Sesi berakhir. Muat ulang halaman lalu masuk lagi.') }}</span>
            <button type="button" @click="location.reload()" class="min-h-[44px] px-2 font-semibold underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 rounded">{{ __('Muat ulang') }}</button>
        </div>

        <form @submit.prevent="cSend()" class="flex-shrink-0 border-t border-[#e3dfd0] bg-white px-3 pt-2.5 pb-2">
            <div class="flex items-end gap-2">
                <label for="rs-community-input" class="sr-only">{{ __('Pesan untuk sesama pendaki') }}</label>
                <textarea id="rs-community-input" x-ref="cinput" x-model="cInput" rows="1" maxlength="{{ $faCc::MAX_LENGTH }}"
                          :disabled="cError === 'auth'" aria-describedby="rs-community-hint"
                          @input="cResize()" @keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); cSend(); }"
                          placeholder="{{ __('Tulis pesan...') }}"
                          class="flex-1 resize-none rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base md:text-sm text-slate-800 placeholder-slate-500 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-200 disabled:bg-slate-100"
                          style="max-height: 8rem"></textarea>
                <button type="submit" :disabled="!cLoaded || cError === 'auth' || !cInput.trim()" aria-label="{{ __('Kirim pesan') }}"
                        class="w-11 h-11 flex-shrink-0 inline-flex items-center justify-center rounded-lg border-2 border-[#053b30] bg-[#FCD34D] text-[#053b30] hover:bg-[#fbbf24] disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-300">
                    <x-icon name="arrow-right" class="w-5 h-5" />
                </button>
            </div>
            <div class="mt-1.5 flex items-start justify-between gap-2 text-[11px] text-slate-600">
                <span id="rs-community-hint">{{ __('Semua pendaki yang masuk bisa membaca pesanmu. Jaga sopan ya.') }}<span class="hidden md:inline"> {{ __('Enter untuk kirim, Shift+Enter untuk baris baru.') }}</span></span>
                <span x-show="cInput.length > cMax - 100" x-cloak class="flex-shrink-0 tabular-nums" :class="cInput.length >= cMax ? 'text-red-700 font-semibold' : ''" x-text="cInput.length + '/' + cMax"></span>
            </div>
        </form>
    </section>
    @else
    <section id="rs-community-panel" x-show="open === 'chat'" x-cloak x-transition.opacity
             role="dialog" aria-modal="true" aria-labelledby="rs-community-title" aria-describedby="rs-community-desc" tabindex="-1" @keydown.tab="trap($event)"
             class="rs-panel rs-panel-sheet rs-panel-left focus:outline-none" data-panel="community-guest">
        <div class="flex items-start gap-3 p-5 pb-3">
            <span class="w-11 h-11 rounded-xl bg-[#053b30] text-white flex items-center justify-center flex-shrink-0"><x-icon name="chat-alt-2" class="w-6 h-6" /></span>
            <div class="flex-1 min-w-0">
                <h2 id="rs-community-title" class="font-bold text-slate-900">{{ __('Obrolan Pendaki') }}</h2>
                <p id="rs-community-desc" class="mt-0.5 text-sm text-slate-700 leading-relaxed">{{ __('Masuk atau daftar dulu untuk ngobrol dengan sesama pendaki') }}</p>
            </div>
            <button type="button" @click="close()" aria-label="{{ __('Tutup') }}"
                    class="w-11 h-11 -mr-2 -mt-2 inline-flex items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
                <x-icon name="x" class="w-5 h-5" />
            </button>
        </div>
        <div class="grid grid-cols-2 gap-2 px-5 pt-1 pb-4">
            <a href="{{ route('community.login', ['to' => 'register', 'back' => $faBack]) }}" data-autofocus
               class="inline-flex items-center justify-center gap-2 min-h-[44px] rounded-lg border-2 border-[#053b30] bg-[#FCD34D] px-4 py-2.5 text-sm font-semibold text-[#053b30] hover:bg-[#fbbf24] focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-300">
                <x-icon name="user-add" class="w-4 h-4" />{{ __('Daftar') }}
            </a>
            <a href="{{ route('community.login', ['back' => $faBack]) }}"
               class="inline-flex items-center justify-center gap-2 min-h-[44px] rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                <x-icon name="login" class="w-4 h-4" />{{ __('Masuk') }}
            </a>
        </div>
        <p class="px-5 pb-5 -mt-1 text-xs text-slate-600">{{ __('Akunnya gratis. Setelah masuk, kamu kembali ke halaman ini.') }}</p>
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
                    {{ __('Halo, pendaki. Lagi cari jalur yang pas, mau upload GPX, atau penasaran cara kerja RuteStrip? Tanya saja, saya bantu.') }}
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
