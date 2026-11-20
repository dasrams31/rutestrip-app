'use client';

import { useState, useRef, useEffect, useCallback } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import AuthForm from './AuthForm';
import SidebarHistory from './SidebarHistory';
import Icon from './Icon';
import TelegramFab from './TelegramFab';
import ImageLightbox from './ImageLightbox';

const MAIN_SITE_URL = 'https://rutestrip.ai.id';

/* ── Account dropdown in the header ── */
function UserMenu({ user, onLogout }) {
  const [open, setOpen] = useState(false);
  const wrapRef = useRef(null);

  useEffect(() => {
    if (!open) return;
    const onPointer = (e) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onPointer);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onPointer);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const name = user.full_name || user.username || 'Pengguna';
  const initial = name.trim().charAt(0).toUpperCase() || 'U';

  return (
    <div className="user-menu" ref={wrapRef}>
      <button
        type="button"
        className="user-menu-trigger"
        onClick={() => setOpen(o => !o)}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Menu akun ${name}`}
      >
        <span className="user-avatar" aria-hidden="true">{initial}</span>
        <span className="user-menu-name">{name}</span>
        <Icon name="chevron-down" size={14} className="user-menu-chevron" />
      </button>

      {open && (
        <div className="user-menu-panel" role="menu" aria-label="Akun">
          <div className="user-menu-head">
            <p className="user-menu-title">{name}</p>
            {user.email && <p className="user-menu-sub">{user.email}</p>}
          </div>
          <button
            type="button"
            role="menuitem"
            className="user-menu-item danger"
            onClick={() => { setOpen(false); onLogout(); }}
          >
            <Icon name="logout" size={16} />
            <span>Keluar</span>
          </button>
        </div>
      )}
    </div>
  );
}

/* ── Suggestion cards config ── */
const SUGGESTIONS = [
  { icon: 'map',             title: 'Rekomendasi Jalur', desc: 'Cari jalur sesuai preferensi',  query: 'Rekomendasikan jalur pendakian untuk pemula', hoverClass: '' },
  { icon: 'cloud',           title: 'Cuaca Gunung',      desc: 'Prakiraan cuaca realtime',      query: 'Cuaca gunung Sumbing hari ini',               hoverClass: 'blue-hover' },
  { icon: 'layers',          title: 'Peta Satelit',      desc: 'Foto satelit & topografi',      query: 'Foto satelit gunung Merbabu',                 hoverClass: 'purple-hover' },
  { icon: 'calendar',        title: 'Itinerary',         desc: 'Jadwal pendakian Naismith',     query: 'Itinerary gunung Sindoro 2D1N',               hoverClass: 'amber-hover' },
  { icon: 'currency',        title: 'Estimasi Biaya',    desc: 'Kalkulasi budget pendakian',    query: 'Biaya pendakian Sumbing 4 orang 2 hari',      hoverClass: '' },
  { icon: 'clipboard-check', title: 'Logistik',          desc: 'Checklist perlengkapan',        query: 'Logistik pendakian 4 orang 3 hari',           hoverClass: 'blue-hover' },
  { icon: 'shield-check',    title: 'Panduan Survival',  desc: 'P3K & keselamatan',             query: 'Panduan survival hipotermia di gunung',       hoverClass: 'amber-hover' },
  { icon: 'users',           title: 'Kontak Porter',     desc: 'Porter & guide lokal',          query: 'Porter gunung Semeru',                        hoverClass: 'purple-hover' },
];

/* ── Loading stage texts ── */
const STAGES = [
  'Preprocessing query...',
  'Generating SBERT embedding...',
  'Mencari jalur relevan...',
  'Menyusun konteks...',
  'Generating response...',
];

/* ── Markdown formatter ── */
function formatInline(text) {
  return text
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/(?<!\*)\*(?!\*)([^*]+)(?<!\*)\*(?!\*)/g, '<em>$1</em>');
}

function renderTable(block) {
  const rows = block.trim().split('\n')
    .filter((line, i) => i !== 1) // drop the |---|---| separator row
    .map(line => line.trim().replace(/^\||\|$/g, '').split('|').map(c => formatInline(c.trim())));
  const [head, ...body] = rows;
  return '<div class="md-table-wrap"><table class="md-table">'
    + `<thead><tr>${head.map(c => `<th>${c}</th>`).join('')}</tr></thead>`
    + `<tbody>${body.map(r => `<tr>${r.map(c => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody>`
    + '</table></div>';
}

function formatMessage(content) {
  if (!content) return '';

  // Escape HTML — but only the dangerous characters, keep > for blockquotes
  let html = content
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;');

  // Code blocks and tables are rendered first and parked behind placeholders,
  // so the line-based rules below don't touch them. Both scroll horizontally
  // inside their own box on narrow screens.
  const blocks = [];
  const park = (out) => `\u0000${blocks.push(out) - 1}\u0000`;
  html = html.replace(/```[\w-]*[ \t]*\n?([\s\S]*?)```/g,
    (_, code) => park(`<pre class="md-pre"><code>${code.replace(/\n$/, '')}</code></pre>`));
  html = html.replace(/^[ \t]*\|.*\|[ \t]*\n[ \t]*\|[ \t:|-]+\|[ \t]*(?:\n[ \t]*\|.*\|[ \t]*)*/gm,
    (tbl) => park(renderTable(tbl)));

  html = formatInline(html);
  // H2 heading
  html = html.replace(/^## (.+)$/gm, '<h3>$1</h3>');
  // H3 heading
  html = html.replace(/^### (.+)$/gm, '<h4>$1</h4>');
  // Blockquote  (> text from narrative)
  html = html.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');
  html = html.replace(/^> (.+)$/gm, '<blockquote>$1</blockquote>');
  // Ordered list items
  html = html.replace(/^(\d+)\. (.+)$/gm, '<li class="ol-item">$2</li>');
  // Unordered list items
  html = html.replace(/^[-•] (.+)$/gm, '<li class="ul-item">$1</li>');
  // Wrap consecutive ol-items
  html = html.replace(/((?:<li class="ol-item">.*?<\/li>\s*)+)/gs, '<ol>$1</ol>');
  // Wrap consecutive ul-items
  html = html.replace(/((?:<li class="ul-item">.*?<\/li>\s*)+)/gs, '<ul>$1</ul>');
  // Newlines between list items would otherwise become <br> gaps
  html = html.replace(/<\/li>\s+(?=<li|<\/[ou]l>)/g, '</li>');
  // Double newline → paragraph break
  html = html.replace(/\n\n/g, '</p><p class="mt-1">');
  // Single newline → line break
  html = html.replace(/\n/g, '<br>');
  // Restore parked blocks, dropping the line breaks hugging them
  html = html.replace(/(?:<br>)?\u0000(\d+)\u0000(?:<br>)?/g, (_, i) => blocks[i]);

  return `<p>${html}</p>`;
}

export default function ChatInterface() {
  const [currentUser, setCurrentUser]   = useState(null);
  const [authChecking, setAuthChecking] = useState(true);
  const [messages, setMessages]         = useState([]);
  const [input, setInput]               = useState('');
  const [isLoading, setIsLoading]       = useState(false);
  const [streamStarted, setStreamStarted] = useState(false);
  const [loadingText, setLoadingText]   = useState(STAGES[0]);
  const [showPipeline, setShowPipeline] = useState(false);
  const [isListening, setIsListening]   = useState(false);
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [activeSessionId, setActiveSessionId] = useState(null);
  const [showPwaBanner, setShowPwaBanner]   = useState(false);
  const [pwaPrompt, setPwaPrompt]           = useState(null);
  const [lightbox, setLightbox]             = useState(null);
  const closeLightbox = useCallback(() => setLightbox(null), []);

  const chatRef    = useRef(null);
  const inputRef   = useRef(null);
  const recognitionRef = useRef(null);
  const stageTimer = useRef(null);

  /* ── Check Authentication Session on Mount ── */
  useEffect(() => {
    async function checkSession() {
      const token = typeof window !== 'undefined'
        ? (localStorage.getItem('rutestrip_auth_token') || localStorage.getItem('user_token') || localStorage.getItem('token'))
        : null;
      if (!token) {
        setAuthChecking(false);
        return;
      }
      try {
        const res = await fetch('/api/auth/me', {
          headers: { Authorization: `Bearer ${token}` },
        });
        const data = await res.json();
        if (res.ok && data.status === 'success' && data.user) {
          setCurrentUser(data.user);
        } else {
          localStorage.removeItem('rutestrip_auth_token');
          localStorage.removeItem('user_token');
          localStorage.removeItem('token');
          localStorage.removeItem('rutestrip_user_profile');
          setCurrentUser(null);
        }
      } catch {
        const local = localStorage.getItem('rutestrip_user_profile');
        if (local) {
          try { setCurrentUser(JSON.parse(local)); } catch {}
        }
      } finally {
        setAuthChecking(false);
      }
    }
    checkSession();

    // Check if PWA banner was dismissed previously
    const dismissed = typeof window !== 'undefined' && localStorage.getItem('rutestrip_pwa_dismissed') === 'true';

    // Listen for PWA Before Install Prompt event
    const handleBeforeInstallPrompt = (e) => {
      e.preventDefault();
      window.deferredPwaPrompt = e;
      setPwaPrompt(e);
      if (!dismissed) {
        setShowPwaBanner(true);
      }
    };

    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);

    // Also trigger banner for mobile devices if not standalone
    const isMobile = typeof window !== 'undefined' && /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    const isStandalone = typeof window !== 'undefined' && (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone);
    if (isMobile && !isStandalone && !dismissed) {
      setShowPwaBanner(true);
    }

    return () => {
      window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
    };
  }, []);

  const handleInstallPwa = () => {
    if (pwaPrompt || (typeof window !== 'undefined' && window.deferredPwaPrompt)) {
      const promptEvent = pwaPrompt || window.deferredPwaPrompt;
      promptEvent.prompt();
      promptEvent.userChoice.then((choiceResult) => {
        if (choiceResult.outcome === 'accepted') {
          setShowPwaBanner(false);
        }
        window.deferredPwaPrompt = null;
        setPwaPrompt(null);
      });
    } else {
      alert('Untuk menginstall aplikasi PWA di HP Android Anda:\n1. Ketuk ikon titik tiga (⋮) di pojok kanan atas Chrome\n2. Pilih "Tambahkan ke Layar Utama" / "Install Aplikasi"\n3. Aplikasi RuteStrip AI siap dibuka dari Home Screen HP Anda.');
    }
  };

  const dismissPwaBanner = () => {
    setShowPwaBanner(false);
    if (typeof window !== 'undefined') {
      localStorage.setItem('rutestrip_pwa_dismissed', 'true');
    }
  };



  /* ── Scroll to bottom ── */
  const scrollToBottom = useCallback(() => {
    requestAnimationFrame(() => {
      if (chatRef.current) {
        chatRef.current.scrollTo({ top: chatRef.current.scrollHeight, behavior: 'smooth' });
      }
    });
  }, []);

  useEffect(() => { scrollToBottom(); }, [messages, isLoading, scrollToBottom]);

  /* ── Speech recognition ── */
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) return;

    const rec = new SR();
    rec.lang = 'id-ID';
    rec.interimResults = true;
    rec.continuous = false;

    rec.onresult = (e) => {
      let final = '';
      for (let i = e.resultIndex; i < e.results.length; i++) {
        if (e.results[i].isFinal) final += e.results[i][0].transcript;
      }
      if (final) {
        setInput(prev => (prev + ' ' + final).trim());
        if (inputRef.current) {
          inputRef.current.style.height = 'auto';
          inputRef.current.style.height =
            Math.min(inputRef.current.scrollHeight, 112) + 'px';
        }
      }
    };
    rec.onstart = () => setIsListening(true);
    rec.onend   = () => setIsListening(false);
    rec.onerror = () => setIsListening(false);
    recognitionRef.current = rec;

    return () => { rec.abort(); };
  }, []);

  const toggleSpeech = () => {
    if (!recognitionRef.current) {
      alert('Browser Anda tidak mendukung Voice Input. Gunakan Chrome/Edge terbaru.');
      return;
    }
    if (isListening) {
      recognitionRef.current.stop();
    } else {
      recognitionRef.current.start();
    }
  };

  /* ── Select session from history ── */
  const handleSelectSession = useCallback(async (session) => {
    if (!session?.session_id) return;
    setActiveSessionId(session.session_id);

    let token = typeof window !== 'undefined'
      ? (localStorage.getItem('rutestrip_auth_token') || localStorage.getItem('user_token') || localStorage.getItem('token'))
      : null;
    if (!token && typeof window !== 'undefined') {
      try {
        const profile = JSON.parse(localStorage.getItem('rutestrip_user_profile') || '{}');
        if (profile?.token) token = profile.token;
      } catch {}
    }

    try {
      const res = await fetch(`/api/chat/sessions/${session.session_id}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const data = await res.json();
      if (res.ok && data.messages) {
        const loaded = data.messages.map((m) => ({
          role: m.sender === 'user' ? 'user' : 'assistant',
          content: m.text,
          time: m.timestamp
            ? new Date(m.timestamp).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
            : '',
        }));
        setMessages(loaded);
      }
    } catch (err) {
      console.error('Error loading session details:', err);
    }
  }, []);

  const handleNewChat = useCallback(() => {
    setActiveSessionId(null);
    setMessages([]);
  }, []);

  const closeSidebar = useCallback(() => setIsSidebarOpen(false), []);

  const handleLogout = useCallback(() => {
    localStorage.removeItem('rutestrip_auth_token');
    localStorage.removeItem('user_token');
    localStorage.removeItem('token');
    localStorage.removeItem('rutestrip_user_profile');
    setIsSidebarOpen(false);
    setCurrentUser(null);
  }, []);

  /* ── Send message ── */
  const sendMessage = useCallback(async (text) => {
    const message = (text || input).trim();
    if (!message || isLoading) return;

    const now = new Date();
    const timeStr = now.getHours().toString().padStart(2, '0') + ':'
                  + now.getMinutes().toString().padStart(2, '0');

    setMessages(prev => [...prev, { role: 'user', content: message, time: timeStr }]);
    setInput('');
    setIsLoading(true);

    // Reset textarea height
    if (inputRef.current) inputRef.current.style.height = '2.75rem';

    // Rotate loading stages
    let idx = 0;
    stageTimer.current = setInterval(() => {
      idx = (idx + 1) % STAGES.length;
      setLoadingText(STAGES[idx]);
    }, 1800);

    try {
      // Build history to send (exclude current message, last 10 turns)
      const historyToSend = messages.slice(-10).map(m => ({
        role: m.role,
        content: m.content,
      }));

      let token = typeof window !== 'undefined'
        ? (localStorage.getItem('rutestrip_auth_token') || localStorage.getItem('user_token') || localStorage.getItem('token'))
        : null;
      if (!token && typeof window !== 'undefined') {
        try {
          const profile = JSON.parse(localStorage.getItem('rutestrip_user_profile') || '{}');
          if (profile?.token) token = profile.token;
        } catch {}
      }

      const headers = { 'Content-Type': 'application/json' };
      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
      }

      const res = await fetch('/api/chat', {
        method: 'POST',
        headers,
        body: JSON.stringify({ message, history: historyToSend, session_id: activeSessionId, stream: true }),
      });

      const replyTime = new Date();
      const replyTimeStr = replyTime.getHours().toString().padStart(2, '0') + ':'
                         + replyTime.getMinutes().toString().padStart(2, '0');

      // ── Streaming (NDJSON) response ──
      if ((res.headers.get('content-type') || '').includes('application/x-ndjson') && res.body) {
        const streamId = `s-${Date.now()}-${Math.random().toString(36).slice(2)}`;
        const updateStreamMsg = (fn) =>
          setMessages(prev => prev.map(m => (m.streamId === streamId ? fn(m) : m)));
        let created = false;
        const ensureMessage = (fields = {}) => {
          if (created) return;
          created = true;
          setMessages(prev => [
            ...prev,
            {
              streamId,
              role: 'assistant',
              content: '',
              retrieved_routes: [],
              timing: null,
              time: replyTimeStr,
              map_url: null,
              gpx_url: null,
              ...fields,
            },
          ]);
        };

        const handleEvent = (evt) => {
          if (evt.type === 'meta') {
            ensureMessage({
              retrieved_routes: evt.retrieved_routes || [],
              timing: evt.timing || null,
              map_url: evt.map_url || null,
              gpx_url: evt.gpx_url || null,
            });
          } else if (evt.type === 'delta' && evt.text) {
            ensureMessage();
            setStreamStarted(true);
            updateStreamMsg(m => ({ ...m, content: m.content + evt.text }));
          } else if (evt.type === 'done') {
            ensureMessage();
            if (evt.session_id) setActiveSessionId(evt.session_id);
            if (evt.timing) updateStreamMsg(m => ({ ...m, timing: evt.timing }));
          }
        };

        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        try {
          while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop();
            for (const line of lines) {
              if (!line.trim()) continue;
              try { handleEvent(JSON.parse(line)); } catch {}
            }
          }
          buffer += decoder.decode();
          if (buffer.trim()) {
            try { handleEvent(JSON.parse(buffer)); } catch {}
          }
        } catch {
          // Connection dropped mid-stream: keep partial text, add a note.
          ensureMessage();
          updateStreamMsg(m => ({
            ...m,
            content: m.content
              ? `${m.content}\n\n*(Koneksi terputus, jawaban mungkin tidak lengkap.)*`
              : 'Maaf, terjadi kesalahan koneksi. Pastikan koneksi internet Anda stabil.',
          }));
          return;
        }

        ensureMessage();
        updateStreamMsg(m => (m.content ? m : { ...m, content: 'Maaf, terjadi kesalahan.' }));
        return;
      }

      // ── Plain JSON response (non-streaming intents / older server) ──
      const data = await res.json();
      if (data?.session_id) {
        setActiveSessionId(data.session_id);
      }

      setMessages(prev => [
        ...prev,
        {
          role: 'assistant',
          content: data.response || 'Maaf, terjadi kesalahan.',
          retrieved_routes: data.retrieved_routes || [],
          timing: data.timing || null,
          time: replyTimeStr,
          map_url: data.map_url || null,
          gpx_url: data.gpx_url || null,
        },
      ]);
    } catch {
      setMessages(prev => [
        ...prev,
        {
          role: 'assistant',
          content: 'Maaf, terjadi kesalahan koneksi. Pastikan koneksi internet Anda stabil.',
          time: new Date().getHours().toString().padStart(2, '0') + ':'
              + new Date().getMinutes().toString().padStart(2, '0'),
        },
      ]);
    } finally {
      clearInterval(stageTimer.current);
      setIsLoading(false);
      setStreamStarted(false);
      setLoadingText(STAGES[0]);
    }
  }, [input, isLoading, messages, activeSessionId]);

  const handleSubmit = (e) => {
    e.preventDefault();
    sendMessage();
  };

  const handleKeyDown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  };

  const autoResize = (e) => {
    const el = e.target;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 112) + 'px';
  };

  /* ── Auth Protection Guard ── */
  if (authChecking) {
    return (
      <div className="auth-overlay">
        <div className="auth-card" style={{ textAlign: 'center', padding: '3rem 2rem' }}>
          <div className="auth-logo-wrap" style={{ margin: '0 auto 1rem' }}>
            <img src="/logo.svg" alt="RuteStrip AI" width={48} height={48} style={{ borderRadius: '12px' }} />
          </div>
          <h2 style={{ fontSize: '1.25rem', color: '#0f172a', fontWeight: 800, margin: 0 }}>RuteStrip AI WebChat</h2>
          <p style={{ fontSize: '0.875rem', color: '#64748b', marginTop: '0.5rem' }}>Memverifikasi sesi autentikasi...</p>
        </div>
      </div>
    );
  }

  if (!currentUser) {
    return <AuthForm onAuthSuccess={(user) => setCurrentUser(user)} />;
  }

  return (
    <div className="app-shell">
      {/* ── PWA Install Notification Banner ── */}
      {showPwaBanner && (
        <div className="pwa-banner-bar msg-appear">
          <div className="pwa-banner-left">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src="/logo.svg"
              alt="RuteStrip AI Logo"
              width={32}
              height={32}
              style={{ borderRadius: '8px', objectFit: 'cover', flexShrink: 0 }}
            />
            <div className="pwa-banner-text">
              <strong>Install RuteStrip AI di HP Android</strong>
              <p>Tambahkan ke layar utama HP untuk akses cepat & pengalaman aplikasi tanpa browser!</p>
            </div>
          </div>
          <div className="pwa-banner-right">
            <button type="button" onClick={handleInstallPwa} className="pwa-install-btn">
              <Icon name="download" size={14} />
              <span>Install</span>
            </button>
            <button
              type="button"
              onClick={dismissPwaBanner}
              className="pwa-dismiss-btn"
              aria-label="Tutup notifikasi install"
              title="Tutup notifikasi"
            >
              <Icon name="x" size={14} strokeWidth={2} />
            </button>
          </div>
        </div>
      )}

      {/* ── Sidebar drawer: history, links, account ── */}
      <SidebarHistory
        isOpen={isSidebarOpen}
        onClose={closeSidebar}
        currentUser={currentUser}
        activeSessionId={activeSessionId}
        onSelectSession={handleSelectSession}
        onNewChat={handleNewChat}
        onLogout={handleLogout}
      />

      {/* ── Header ── */}
      <header className="header">
        <div className="header-left">
          <button
            type="button"
            onClick={() => setIsSidebarOpen(prev => !prev)}
            className="icon-btn"
            title="Riwayat percakapan & menu"
            aria-label={isSidebarOpen ? 'Tutup menu riwayat' : 'Buka menu riwayat'}
            aria-expanded={isSidebarOpen}
            aria-controls="chat-sidebar"
          >
            <Icon name="menu" size={20} />
          </button>

          <div className="header-brand">
            <div className="logo-img-wrap">
              <Image
                src="/logo.svg"
                alt=""
                width={36}
                height={36}
                className="logo-img"
                priority
              />
              <span className="online-dot" aria-hidden="true" />
            </div>
            <div className="header-brand-text">
              <h1 className="header-title">RuteStrip AI</h1>
              <p className="header-subtitle">Asisten Pendakian</p>
            </div>
          </div>
        </div>

        <nav className="header-nav" aria-label="Navigasi utama">
          <a href={MAIN_SITE_URL} className="nav-link" title="Kembali ke rutestrip.ai.id" aria-label="Situs Utama">
            <Icon name="home" size={16} />
            <span>Situs Utama</span>
          </a>
          <Link href="/system" className="nav-link" title="Status sistem & monitoring" aria-label="Status Sistem">
            <Icon name="chart-bar" size={16} />
            <span>Status Sistem</span>
          </Link>
        </nav>

        <div className="header-right">
          <div className="header-tools">
            <button
              type="button"
              id="btn-pipeline"
              className={`icon-btn${showPipeline ? ' active' : ''}`}
              onClick={() => setShowPipeline(p => !p)}
              aria-label="Tampilkan info pipeline RAG"
              aria-pressed={showPipeline}
              title="Info pipeline RAG"
            >
              <Icon name="clipboard-list" size={18} />
            </button>
            <button
              type="button"
              id="btn-export"
              className="icon-btn print-hide"
              onClick={() => window.print()}
              aria-label="Export percakapan ke PDF"
              title="Export PDF"
            >
              <Icon name="printer" size={18} />
            </button>
            <span className="divider-v" aria-hidden="true" />
          </div>

          <button
            type="button"
            id="btn-new-chat"
            className="btn-primary"
            onClick={handleNewChat}
            aria-label="Mulai chat baru"
            title="Chat baru"
          >
            <Icon name="plus" size={16} strokeWidth={2} />
            <span>Chat Baru</span>
          </button>

          <UserMenu user={currentUser} onLogout={handleLogout} />
        </div>
      </header>

      {/* ── Pipeline Bar ── */}
      <div
        className={`pipeline-bar${showPipeline ? '' : ' hidden'}`}
        role="complementary"
        aria-label="RAG Pipeline Info"
      >
        <div className="pipeline-inner">
          <span className="pipeline-step slate">Query Preprocessing</span>
          <Icon name="chevron-right" size={12} className="pipeline-arrow" />
          <span className="pipeline-step emerald">SBERT 384d</span>
          <Icon name="chevron-right" size={12} className="pipeline-arrow" />
          <span className="pipeline-step amber">Cosine Similarity</span>
          <Icon name="chevron-right" size={12} className="pipeline-arrow" />
          <span className="pipeline-step purple">Context Augmentation</span>
          <Icon name="chevron-right" size={12} className="pipeline-arrow" />
          <span className="pipeline-step sky">SBERT + Gemini</span>
        </div>
      </div>

      {/* ── Chat Messages ── */}
      <main className="chat-main chat-scroll" ref={chatRef} aria-live="polite">
        <div className="chat-container">

          {/* Welcome screen */}
          {messages.length === 0 && (
            <div className="welcome-screen">
              <Image
                src="/logo.svg"
                alt="RuteStrip AI"
                width={80}
                height={80}
                className="welcome-logo"
                priority
              />
              <h2 className="welcome-title">Tanya Seputar Pendakian</h2>
              <p className="welcome-subtitle">
                Tanyakan jalur, estimasi waktu, tingkat kesulitan, atau rekomendasi pendakian
              </p>

              <div className="suggestion-grid">
                {SUGGESTIONS.map((s, i) => (
                  <button
                    key={i}
                    id={`suggestion-${i}`}
                    type="button"
                    className={`suggestion-card ${s.hoverClass}`}
                    onClick={() => sendMessage(s.query)}
                    aria-label={s.query}
                  >
                    <span className="suggestion-icon" aria-hidden="true"><Icon name={s.icon} size={20} /></span>
                    <div>
                      <p className="suggestion-card-title">{s.title}</p>
                      <p className="suggestion-card-desc">{s.desc}</p>
                    </div>
                  </button>
                ))}
              </div>
            </div>
          )}

          {/* Messages */}
          {messages.map((msg, idx) => (
            <div
              key={idx}
              className={`message-row msg-appear ${msg.role}`}
            >
              <div className="message-pair">
                {/* AI avatar */}
                {msg.role === 'assistant' && (
                  <Image
                    src="/logo.svg"
                    alt="RuteStrip AI"
                    width={32}
                    height={32}
                    className="avatar avatar-ai"
                  />
                )}

                <div className="message-content-wrap">
                  {/* Bubble (skipped while a streamed reply has no text yet) */}
                  {(msg.content || msg.map_url || msg.gpx_url) && (
                  <div className={`bubble ${msg.role}`}>
                    <div
                      className="bubble-content"
                      dangerouslySetInnerHTML={{ __html: formatMessage(msg.content) }}
                    />

                    {/* Satellite map image */}
                    {msg.map_url && (() => {
                      const mtn = msg.content?.match(/Gunung\s+([A-Za-z-]+)/)?.[1];
                      const alt = mtn ? `Peta Gunung ${mtn}` : 'Peta satelit';
                      return (
                        <button
                          type="button"
                          className="map-img-wrap"
                          onClick={() => setLightbox({
                            id: Date.now(),
                            src: msg.map_url,
                            alt,
                            name: `rutestrip-peta${mtn ? `-${mtn.toLowerCase()}` : ''}`,
                          })}
                          aria-label={`${alt}, buka ukuran penuh`}
                          title="Perbesar peta"
                        >
                          {/* eslint-disable-next-line @next/next/no-img-element */}
                          <img
                            src={msg.map_url}
                            alt={alt}
                            className="map-img"
                            loading="lazy"
                            onLoad={idx === messages.length - 1 ? scrollToBottom : undefined}
                          />
                          <span className="map-img-overlay print-hide"><Icon name="zoom-in" size={14} /><span>Ketuk untuk perbesar</span></span>
                        </button>
                      );
                    })()}

                    {/* GPX download button */}
                    {msg.gpx_url && (
                      <a
                        href={msg.gpx_url}
                        download
                        className="gpx-download-btn"
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        <Icon name="download" size={16} />
                        Download {msg.gpx_url?.includes('kml') ? 'KML' : 'GPX'} File
                      </a>
                    )}
                  </div>
                  )}

                  {/* Route chips */}
                  {msg.role === 'assistant' &&
                   msg.retrieved_routes?.length > 0 && (
                    <div className="route-chips">
                      {msg.retrieved_routes.map((r, ri) => (
                        <span key={ri} className="route-chip">
                          <Icon name="map-pin" size={12} />
                          <span>{r.name}</span>
                          {r.similarity_score > 0 && (
                            <span className="route-chip-score">
                              {(r.similarity_score * 100).toFixed(0)}%
                            </span>
                          )}
                        </span>
                      ))}
                    </div>
                  )}

                  {/* Quick action buttons (contextual follow-ups) */}
                  {msg.role === 'assistant' && msg.timing?.type === 'rekomendasi' && msg.retrieved_routes?.length > 0 && (
                    <div className="quick-actions">
                      {msg.retrieved_routes.slice(0, 2).map((r, ri) => (
                        <span key={`qa-${ri}`} className="quick-action-group">
                          <button type="button" className="quick-action" onClick={() => sendMessage(`Cuaca ${r.name}`)} title={`Cek cuaca ${r.name}`}><Icon name="cloud" size={13} />Cuaca</button>
                          <button type="button" className="quick-action" onClick={() => sendMessage(`Foto satelit ${r.name}`)} title={`Peta ${r.name}`}><Icon name="map" size={13} />Peta</button>
                          <button type="button" className="quick-action" onClick={() => sendMessage(`Itinerary ${r.name}`)} title={`Itinerary ${r.name}`}><Icon name="calendar" size={13} />Itinerary</button>
                        </span>
                      ))}
                    </div>
                  )}

                  {/* Quick actions for map responses */}
                  {msg.role === 'assistant' && msg.timing?.type === 'map' && (
                    <div className="quick-actions">
                      <button type="button" className="quick-action" onClick={() => sendMessage(`Cuaca ${msg.content.match(/Gunung\s+(\S+)/i)?.[1] || ''}`)}><Icon name="cloud" size={13} />Cek Cuaca</button>
                      <button type="button" className="quick-action" onClick={() => sendMessage(`Itinerary ${msg.content.match(/Gunung\s+(\S+)/i)?.[1] || ''}`)}><Icon name="calendar" size={13} />Itinerary</button>
                      <button type="button" className="quick-action" onClick={() => sendMessage(`Porter ${msg.content.match(/Gunung\s+(\S+)/i)?.[1] || ''}`)}><Icon name="phone" size={13} />Porter</button>
                    </div>
                  )}

                  {/* Timing */}
                  {msg.role === 'assistant' && msg.timing && (
                    <div className="timing-row">
                      {msg.timing.retrieval_ms > 0 && (
                        <span className="timing-item" title="Waktu retrieval">
                          <Icon name="clock" size={11} />{msg.timing.retrieval_ms}ms
                        </span>
                      )}
                      {msg.timing.generation_ms > 0 && (
                        <span className="timing-item" title="Waktu generasi jawaban">
                          <Icon name="sparkles" size={11} />{msg.timing.generation_ms}ms
                        </span>
                      )}
                      {msg.timing.type && <span className="timing-item">{msg.timing.type}</span>}
                    </div>
                  )}

                  {/* Timestamp */}
                  <div className="time-stamp">{msg.time}</div>
                </div>

                {/* User avatar */}
                {msg.role === 'user' && (
                  <div className="avatar avatar-user" aria-hidden="true">
                    <Icon name="user" size={16} />
                  </div>
                )}
              </div>
            </div>
          ))}

          {/* Typing indicator (hidden once streamed text starts arriving) */}
          {isLoading && !streamStarted && (
            <div className="message-row assistant msg-appear">
              <div className="typing-row">
                <Image
                  src="/logo.svg"
                  alt="RuteStrip AI thinking"
                  width={32}
                  height={32}
                  className="avatar avatar-ai"
                />
                <div className="typing-bubble">
                  <div className="dot-wrap" aria-hidden="true">
                    <div className="dot" />
                    <div className="dot" />
                    <div className="dot" />
                  </div>
                  <span className="typing-label">{loadingText}</span>
                </div>
              </div>
            </div>
          )}

        </div>
      </main>

      {/* ── Input Footer ── */}
      <footer className="input-footer print-hide">
        <TelegramFab />

        <form className="input-form" onSubmit={handleSubmit} aria-label="Chat form">
          <div className="input-wrap">
            {/* Voice button */}
            <button
              type="button"
              id="btn-voice"
              className={`voice-btn${isListening ? ' listening' : ''}`}
              onClick={toggleSpeech}
              aria-label={isListening ? 'Hentikan input suara' : 'Mulai input suara'}
              title={isListening ? 'Hentikan input suara' : 'Input suara'}
              aria-pressed={isListening}
            >
              <Icon name="microphone" size={20} />
            </button>

            {/* Textarea */}
            <textarea
              id="chat-input"
              ref={inputRef}
              value={input}
              onChange={e => setInput(e.target.value)}
              onKeyDown={handleKeyDown}
              onInput={autoResize}
              disabled={isLoading}
              rows={1}
              placeholder="Ketik atau gunakan suara..."
              className="chat-textarea"
              style={{ minHeight: '2.75rem' }}
              aria-label="Pesan chat"
            />
          </div>

          {/* Send button */}
          <button
            type="submit"
            id="btn-send"
            className="send-btn"
            disabled={isLoading || !input.trim()}
            aria-label="Kirim pesan"
          >
            {isLoading ? (
              <Icon name="refresh" size={20} className="spin" />
            ) : (
              <Icon name="send" size={20} />
            )}
          </button>
        </form>

        <div className="input-footer-meta">
          <p className="powered-by">
            Powered by <span className="sbert">SBERT</span> + <span className="gemini">Gemini</span> / <span className="qwen">Qwen</span>
          </p>
          <p>Shift+Enter baris baru</p>
        </div>
      </footer>

      <ImageLightbox key={lightbox?.id || 'none'} image={lightbox} onClose={closeLightbox} />
    </div>
  );
}
