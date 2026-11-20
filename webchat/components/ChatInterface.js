'use client';

import { useState, useRef, useEffect, useCallback } from 'react';
import Image from 'next/image';
import AuthForm from './AuthForm';
import SidebarHistory from './SidebarHistory';

/* ── Suggestion cards config ── */
const SUGGESTIONS = [
  {
    icon: (
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
      </svg>
    ),
    title: 'Rekomendasi Jalur',
    desc: 'Cari jalur sesuai preferensi',
    query: 'Rekomendasikan jalur pendakian untuk pemula',
    hoverClass: '',
  },
  {
    icon: (
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 00-9.78 2.096A4.001 4.001 0 003 15z" />
      </svg>
    ),
    title: 'Cuaca Gunung',
    desc: 'Prakiraan cuaca realtime',
    query: 'Cuaca gunung Sumbing hari ini',
    hoverClass: 'blue-hover',
  },
  {
    icon: (
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
      </svg>
    ),
    title: 'Peta Satelit',
    desc: 'Foto satelit & topografi',
    query: 'Foto satelit gunung Merbabu',
    hoverClass: 'purple-hover',
  },
  {
    icon: (
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
      </svg>
    ),
    title: 'Itinerary',
    desc: 'Jadwal pendakian Naismith',
    query: 'Itinerary gunung Sindoro 2D1N',
    hoverClass: 'amber-hover',
  },
  {
    icon: (
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
    ),
    title: 'Estimasi Biaya',
    desc: 'Kalkulasi budget pendakian',
    query: 'Biaya pendakian Sumbing 4 orang 2 hari',
    hoverClass: '',
  },
  {
    icon: (
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
      </svg>
    ),
    title: 'Logistik',
    desc: 'Checklist perlengkapan',
    query: 'Logistik pendakian 4 orang 3 hari',
    hoverClass: 'blue-hover',
  },
  {
    icon: (
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
      </svg>
    ),
    title: 'Panduan Survival',
    desc: 'P3K & keselamatan',
    query: 'Panduan survival hipotermia di gunung',
    hoverClass: 'amber-hover',
  },
  {
    icon: (
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
      </svg>
    ),
    title: 'Kontak Porter',
    desc: 'Porter & guide lokal',
    query: 'Porter gunung Semeru',
    hoverClass: 'purple-hover',
  },
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
function formatMessage(content) {
  if (!content) return '';

  // Escape HTML — but only the dangerous characters, keep > for blockquotes
  let html = content
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;');

  // Code inline
  html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
  // Bold
  html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
  // Italic (single star, not double)
  html = html.replace(/(?<!\*)\*(?!\*)([^*]+)(?<!\*)\*(?!\*)/g, '<em>$1</em>');
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
  // Double newline → paragraph break
  html = html.replace(/\n\n/g, '</p><p class="mt-1">');
  // Single newline → line break
  html = html.replace(/\n/g, '<br>');

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
      alert('Untuk menginstall aplikasi PWA di HP Android Anda:\n1. Ketik ikon titik tiga (⋮) di pojok kanan atas Chrome\n2. Pilih "Tambahkan ke Layar Utama" / "Install Aplikasi"\n3. Aplikasi RuteStrip AI siap dibuka dari Home Screen HP Anda! 📱');
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
            <button onClick={handleInstallPwa} className="pwa-install-btn">
              Install 🚀
            </button>
            <button onClick={dismissPwaBanner} className="pwa-dismiss-btn" title="Tutup Notifikasi">
              ✕
            </button>
          </div>
        </div>
      )}

      {/* ── ChatGPT Style History Sidebar Drawer ── */}

      <SidebarHistory
        isOpen={isSidebarOpen}
        onClose={() => setIsSidebarOpen(false)}
        currentUser={currentUser}
        activeSessionId={activeSessionId}
        onSelectSession={handleSelectSession}
        onNewChat={handleNewChat}
        onLogout={() => {
          localStorage.removeItem('rutestrip_auth_token');
          localStorage.removeItem('user_token');
          localStorage.removeItem('token');
          localStorage.removeItem('rutestrip_user_profile');
          setCurrentUser(null);
        }}
      />

      {/* ── Header ── */}
      <header className="header">
        <div className="header-left">
          {/* Sidebar Drawer Toggle Button */}
          <button
            onClick={() => setIsSidebarOpen(prev => !prev)}
            className="btn-sidebar-toggle"
            title="Buka Riwayat Obrolan"
            aria-label="Toggle Sidebar Riwayat"
          >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>

          {/* Back to main website button */}
          <a
            href="https://rutestrip.ai.id"
            className="btn-back-web btn-hidden-mobile"
            title="Kembali ke Website Utama (rutestrip.ai.id)"
          >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{width:'0.875rem',height:'0.875rem'}}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            <span>Utama</span>
          </a>

          <div className="divider-v btn-hidden-mobile" />

          <div className="header-logo-wrap">
            <div className="logo-img-wrap">
              <Image
                src="/logo.svg"
                alt="RuteStrip AI"
                width={36}
                height={36}
                className="logo-img"
                priority
              />
              <div className="online-dot" aria-hidden="true" />
            </div>
            <div>
              <h1 className="header-title">RuteStrip AI</h1>
              <p className="header-subtitle">Asisten Pendakian</p>
            </div>
          </div>
        </div>

        <div className="header-right">
          {/* Logged in User Badge */}
          {currentUser && (
            <div className="user-badge-header">
              <span className="user-badge-name">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '0.875rem', height: '0.875rem' }}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                <span>{currentUser.full_name || currentUser.username}</span>
              </span>
              <button
                onClick={() => {
                  localStorage.removeItem('rutestrip_auth_token');
                  localStorage.removeItem('rutestrip_user_profile');
                  setCurrentUser(null);
                }}
                className="btn-logout-header btn-hidden-mobile"
                title="Keluar dari akun Anda"
              >
                Logout
              </button>
            </div>
          )}
          {/* Telegram Channel button */}
          <a
            href="https://t.me/rutestrip"
            target="_blank"
            rel="noopener noreferrer"
            className="btn-telegram-nav channel-link btn-hidden-mobile"
            title="Gabung Channel Official RuteStrip di Telegram"
          >
            <svg fill="currentColor" viewBox="0 0 24 24" style={{width:'0.875rem',height:'0.875rem'}}>
              <path d="M12 0C5.37 0 0 5.37 0 12s5.37 12 12 12 12-5.37 12-12S18.63 0 12 0zm5.56 8.16l-2.13 10.02c-.16.73-.59.91-1.2.57l-3.26-2.4-1.57 1.51c-.17.17-.32.32-.66.32l.23-3.32 6.04-5.46c.26-.23-.06-.36-.4-.14l-7.47 4.7-3.22-1.01c-.7-.22-.71-.7.15-1.04l12.59-4.85c.58-.22 1.09.13.9 1.1z"/>
            </svg>
            <span>Channel</span>
          </a>

          {/* Telegram Bot button */}
          <a
            href="https://t.me/rutestrip_bot"
            target="_blank"
            rel="noopener noreferrer"
            className="btn-telegram-nav btn-hidden-mobile"
            title="Buka RuteStrip Bot di Telegram"
          >
            <svg fill="currentColor" viewBox="0 0 24 24" style={{width:'0.875rem',height:'0.875rem'}}>
              <path d="M12 0C5.37 0 0 5.37 0 12s5.37 12 12 12 12-5.37 12-12S18.63 0 12 0zm5.56 8.16l-2.13 10.02c-.16.73-.59.91-1.2.57l-3.26-2.4-1.57 1.51c-.17.17-.32.32-.66.32l.23-3.32 6.04-5.46c.26-.23-.06-.36-.4-.14l-7.47 4.7-3.22-1.01c-.7-.22-.71-.7.15-1.04l12.59-4.85c.58-.22 1.09.13.9 1.1z"/>
            </svg>
            <span>Bot</span>
          </a>

          {/* System Monitor button */}
          <a
            href="/system"
            className="btn-ghost btn-hidden-mobile"
            title="Buka System Status & Monitoring AI Token Dashboard"
          >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{width:'0.875rem',height:'0.875rem'}}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
            <span>Status</span>
          </a>

          {/* Pipeline toggle */}
          <button
            id="btn-pipeline"
            className={`btn-ghost btn-hidden-mobile${showPipeline ? ' active' : ''}`}
            onClick={() => setShowPipeline(p => !p)}
            aria-label="Toggle pipeline info"
            aria-pressed={showPipeline}
          >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
              <path strokeLinecap="round" strokeLinejoin="round"
                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <span>Pipeline</span>
          </button>

          {/* Print / Export PDF */}
          <button
            id="btn-export"
            className="btn-ghost btn-hidden-mobile print-hide"
            onClick={() => window.print()}
            aria-label="Export PDF"
          >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
              <path strokeLinecap="round" strokeLinejoin="round"
                d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
            <span>Export PDF</span>
          </button>

          {/* New chat */}
          <button
            id="btn-new-chat"
            className="btn-primary"
            onClick={handleNewChat}
            aria-label="Chat baru"
          >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            <span>Chat Baru</span>
          </button>
        </div>
      </header>

      {/* ── Pipeline Bar ── */}
      <div
        className={`pipeline-bar${showPipeline ? '' : ' hidden'}`}
        role="complementary"
        aria-label="RAG Pipeline Info"
      >
        <div className="pipeline-inner">
          <span className="pipeline-step slate">Query → Preprocessing</span>
          <span className="pipeline-arrow" aria-hidden>→</span>
          <span className="pipeline-step emerald">SBERT 384d</span>
          <span className="pipeline-arrow" aria-hidden>→</span>
          <span className="pipeline-step amber">Cosine Similarity</span>
          <span className="pipeline-arrow" aria-hidden>→</span>
          <span className="pipeline-step purple">Context Augmentation</span>
          <span className="pipeline-arrow" aria-hidden>→</span>
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
                    className={`suggestion-card ${s.hoverClass}`}
                    onClick={() => sendMessage(s.query)}
                    aria-label={s.query}
                  >
                    <span className="suggestion-icon" aria-hidden="true">{s.icon}</span>
                    <div>
                      <p className="suggestion-card-title">{s.title}</p>
                      <p className="suggestion-card-desc">{s.desc}</p>
                    </div>
                  </button>
                ))}
              </div>

              {/* ── Telegram Community & Bot Promo Banner ── */}
              <div className="telegram-banner">
                <div className="telegram-banner-left">
                  <span className="telegram-banner-icon" aria-hidden="true" style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.5rem', height: '1.5rem', color: '#059669' }}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                  </span>
                  <div>
                    <h3 className="telegram-banner-title">Gabung Komunitas Telegram RuteStrip!</h3>
                    <p className="telegram-banner-desc">Dapatkan update info jalur, cuaca realtime, & diskusi pendaki di Telegram.</p>
                  </div>
                </div>
                <div className="telegram-banner-actions">
                  <a
                    href="https://t.me/rutestrip"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="telegram-banner-btn channel-btn"
                  >
                    <svg fill="currentColor" viewBox="0 0 24 24" style={{width:'0.875rem',height:'0.875rem'}}>
                      <path d="M12 0C5.37 0 0 5.37 0 12s5.37 12 12 12 12-5.37 12-12S18.63 0 12 0zm5.56 8.16l-2.13 10.02c-.16.73-.59.91-1.2.57l-3.26-2.4-1.57 1.51c-.17.17-.32.32-.66.32l.23-3.32 6.04-5.46c.26-.23-.06-.36-.4-.14l-7.47 4.7-3.22-1.01c-.7-.22-.71-.7.15-1.04l12.59-4.85c.58-.22 1.09.13.9 1.1z"/>
                    </svg>
                    <span>Channel @rutestrip</span>
                  </a>
                  <a
                    href="https://t.me/rutestrip_bot"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="telegram-banner-btn"
                  >
                    <svg fill="currentColor" viewBox="0 0 24 24" style={{width:'0.875rem',height:'0.875rem'}}>
                      <path d="M12 0C5.37 0 0 5.37 0 12s5.37 12 12 12 12-5.37 12-12S18.63 0 12 0zm5.56 8.16l-2.13 10.02c-.16.73-.59.91-1.2.57l-3.26-2.4-1.57 1.51c-.17.17-.32.32-.66.32l.23-3.32 6.04-5.46c.26-.23-.06-.36-.4-.14l-7.47 4.7-3.22-1.01c-.7-.22-.71-.7.15-1.04l12.59-4.85c.58-.22 1.09.13.9 1.1z"/>
                    </svg>
                    <span>Bot @rutestrip_bot</span>
                  </a>
                </div>
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
                    {msg.map_url && (
                      <div className="map-img-wrap">
                        <a href={msg.map_url} target="_blank" rel="noopener noreferrer" title="Buka peta ukuran penuh">
                          {/* eslint-disable-next-line @next/next/no-img-element */}
                          <img
                            src={msg.map_url}
                            alt="Peta Satelit"
                            className="map-img"
                            loading="lazy"
                          />
                          <div className="map-img-overlay">🔍 Klik untuk perbesar</div>
                        </a>
                      </div>
                    )}

                    {/* GPX download button */}
                    {msg.gpx_url && (
                      <a
                        href={msg.gpx_url}
                        download
                        className="gpx-download-btn"
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{width:'1rem',height:'1rem'}}>
                          <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
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
                          📍
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
                          <button className="quick-action" onClick={() => sendMessage(`Cuaca ${r.name}`)} title={`Cek cuaca ${r.name}`}>☁️ Cuaca</button>
                          <button className="quick-action" onClick={() => sendMessage(`Foto satelit ${r.name}`)} title={`Peta ${r.name}`}>🗺️ Peta</button>
                          <button className="quick-action" onClick={() => sendMessage(`Itinerary ${r.name}`)} title={`Itinerary ${r.name}`}>📋 Itinerary</button>
                        </span>
                      ))}
                    </div>
                  )}

                  {/* Quick actions for map responses */}
                  {msg.role === 'assistant' && msg.timing?.type === 'map' && (
                    <div className="quick-actions">
                      <button className="quick-action" onClick={() => sendMessage(`Cuaca ${msg.content.match(/Gunung\s+(\S+)/i)?.[1] || ''}`)}>☁️ Cek Cuaca</button>
                      <button className="quick-action" onClick={() => sendMessage(`Itinerary ${msg.content.match(/Gunung\s+(\S+)/i)?.[1] || ''}`)}>📋 Itinerary</button>
                      <button className="quick-action" onClick={() => sendMessage(`Porter ${msg.content.match(/Gunung\s+(\S+)/i)?.[1] || ''}`)}>📞 Porter</button>
                    </div>
                  )}

                  {/* Timing */}
                  {msg.role === 'assistant' && msg.timing && (
                    <div className="timing-row">
                      {msg.timing.retrieval_ms > 0 && <span>⏱ {msg.timing.retrieval_ms}ms</span>}
                      {msg.timing.generation_ms > 0 && <span>• ✨ {msg.timing.generation_ms}ms</span>}
                      {msg.timing.type && <span>• {msg.timing.type}</span>}
                    </div>
                  )}

                  {/* Timestamp */}
                  <div className="time-stamp">{msg.time}</div>
                </div>

                {/* User avatar */}
                {msg.role === 'user' && (
                  <div className="avatar avatar-user" aria-label="User">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
                      <circle cx="12" cy="4" r="2"/>
                      <path strokeLinecap="round" strokeLinejoin="round"
                        d="M12 6v4m-3 2l3-2 3 2m-6 0l-2 8h2l1-4m4 4h2l-2-8m-1 0l1 4"/>
                    </svg>
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
        <form className="input-form" onSubmit={handleSubmit} aria-label="Chat form">
          <div className="input-wrap">
            {/* Voice button */}
            <button
              type="button"
              id="btn-voice"
              className={`voice-btn${isListening ? ' listening' : ''}`}
              onClick={toggleSpeech}
              aria-label={isListening ? 'Stop voice input' : 'Start voice input'}
              aria-pressed={isListening}
            >
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round"
                  d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/>
              </svg>
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
              <svg className="spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round"
                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
            ) : (
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round"
                  d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
              </svg>
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
    </div>
  );
}
