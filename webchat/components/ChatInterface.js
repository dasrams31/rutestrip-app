'use client';

import { useState, useRef, useEffect, useCallback } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import { MAIN_SITE_URL } from '@/lib/i18n';
import AuthForm from './AuthForm';
import SidebarHistory from './SidebarHistory';
import Icon from './Icon';
import TelegramFab from './TelegramFab';
import ImageLightbox from './ImageLightbox';
import BottomTabBar from './BottomTabBar';
import { UserMenu, AccountSheet } from './AccountMenu';
import { useI18n } from './I18nProvider';

/* ── Suggestion card look (text comes from the dictionary) ── */
const SUGGESTION_STYLE = [
  { icon: 'map',             hoverClass: '' },
  { icon: 'cloud',           hoverClass: 'blue-hover' },
  { icon: 'layers',          hoverClass: 'purple-hover' },
  { icon: 'calendar',        hoverClass: 'amber-hover' },
  { icon: 'currency',        hoverClass: '' },
  { icon: 'clipboard-check', hoverClass: 'blue-hover' },
  { icon: 'shield-check',    hoverClass: 'amber-hover' },
  { icon: 'users',           hoverClass: 'purple-hover' },
];

/* Mountain name in a bot reply: "Gunung Merbabu" / "Mount Merbabu" */
const mountainIn = (text) => text?.match(/(?:Gunung|Mount)\s+([A-Za-z-]+)/i)?.[1] || '';

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

const hhmm = (d = new Date()) =>
  `${d.getHours().toString().padStart(2, '0')}:${d.getMinutes().toString().padStart(2, '0')}`;

export default function ChatInterface() {
  const { t, lang, tag, applyUserLocale } = useI18n();
  const STAGES = t('stages');

  const [currentUser, setCurrentUser]   = useState(null);
  const [authChecking, setAuthChecking] = useState(true);
  const [authNotice, setAuthNotice]     = useState('');
  const [messages, setMessages]         = useState([]);
  const [input, setInput]               = useState('');
  const [isLoading, setIsLoading]       = useState(false);
  const [streamStarted, setStreamStarted] = useState(false);
  const [stageIdx, setStageIdx]         = useState(0);
  const [showPipeline, setShowPipeline] = useState(false);
  const [isListening, setIsListening]   = useState(false);
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [isAccountOpen, setIsAccountOpen] = useState(false);
  const [activeSessionId, setActiveSessionId] = useState(null);
  const [showPwaBanner, setShowPwaBanner]   = useState(false);
  const [pwaPrompt, setPwaPrompt]           = useState(null);
  const [lightbox, setLightbox]             = useState(null);
  const closeLightbox = useCallback(() => setLightbox(null), []);

  const chatRef    = useRef(null);
  const inputRef   = useRef(null);
  const recognitionRef = useRef(null);
  const stageTimer = useRef(null);

  /* ── Session: the HttpOnly rs_sso cookie is shared with rutestrip.ai.id ── */
  useEffect(() => {
    let cancelled = false;
    async function checkSession() {
      try {
        const res = await fetch('/api/auth/me', { cache: 'no-store' });
        const data = await res.json().catch(() => ({}));
        if (cancelled) return;
        if (res.ok && data.user) {
          setCurrentUser(data.user);
          applyUserLocale(data.user.locale);
        } else if (res.status !== 401) {
          // Account server unreachable: say so instead of silently showing the form
          setAuthNotice(data.error || '');
        }
      } catch {
        // offline: the sign-in form shows its own network error on submit
      } finally {
        if (!cancelled) setAuthChecking(false);
      }
    }
    checkSession();
    return () => { cancelled = true; };
    // applyUserLocale only matters for the first check
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  /* ── PWA install prompt ── */
  useEffect(() => {
    const dismissed = localStorage.getItem('rutestrip_pwa_dismissed') === 'true';

    const handleBeforeInstallPrompt = (e) => {
      e.preventDefault();
      window.deferredPwaPrompt = e;
      setPwaPrompt(e);
      if (!dismissed) setShowPwaBanner(true);
    };
    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);

    // Also show the banner on phones when not installed yet
    const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    if (isMobile && !isStandalone && !dismissed) setShowPwaBanner(true);

    const onInstalled = () => { setShowPwaBanner(false); window.deferredPwaPrompt = null; setPwaPrompt(null); };
    window.addEventListener('appinstalled', onInstalled);

    return () => {
      window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
      window.removeEventListener('appinstalled', onInstalled);
    };
  }, []);

  const handleInstallPwa = useCallback(() => {
    const promptEvent = pwaPrompt || window.deferredPwaPrompt;
    if (promptEvent) {
      promptEvent.prompt();
      promptEvent.userChoice.then((choiceResult) => {
        if (choiceResult.outcome === 'accepted') setShowPwaBanner(false);
        window.deferredPwaPrompt = null;
        setPwaPrompt(null);
      });
    } else {
      alert(t('pwa.help'));
    }
  }, [pwaPrompt, t]);

  const dismissPwaBanner = () => {
    setShowPwaBanner(false);
    localStorage.setItem('rutestrip_pwa_dismissed', 'true');
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

  /* ── Speech recognition (follows the UI language) ── */
  useEffect(() => {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) return;

    const rec = new SR();
    rec.lang = lang === 'en' ? 'en-US' : 'id-ID';
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
  }, [lang]);

  const toggleSpeech = () => {
    if (!recognitionRef.current) {
      alert(t('chat.voiceUnsupported'));
      return;
    }
    if (isListening) recognitionRef.current.stop();
    else recognitionRef.current.start();
  };

  /* ── Signed out elsewhere (or token stale): back to the sign-in form ── */
  const handleUnauthorized = useCallback(() => {
    setIsSidebarOpen(false);
    setIsAccountOpen(false);
    setCurrentUser(null);
    setAuthNotice(t('chat.sessionExpired'));
  }, [t]);

  /* ── Select session from history ── */
  const handleSelectSession = useCallback(async (session) => {
    if (!session?.session_id) return;
    setActiveSessionId(session.session_id);
    try {
      const res = await fetch(`/api/chat/sessions/${encodeURIComponent(session.session_id)}`, { cache: 'no-store' });
      if (res.status === 401) { handleUnauthorized(); return; }
      const data = await res.json();
      if (res.ok && data.messages) {
        setMessages(data.messages.map((m) => ({
          role: m.sender === 'user' ? 'user' : 'assistant',
          content: m.text,
          time: m.timestamp
            ? new Date(m.timestamp).toLocaleTimeString(tag, { hour: '2-digit', minute: '2-digit' })
            : '',
        })));
      }
    } catch (err) {
      console.error('Error loading session details:', err);
    }
  }, [tag, handleUnauthorized]);

  const handleNewChat = useCallback(() => {
    setActiveSessionId(null);
    setMessages([]);
  }, []);

  const closeSidebar = useCallback(() => setIsSidebarOpen(false), []);
  const closeAccount = useCallback(() => setIsAccountOpen(false), []);

  /* Logout here deletes the shared cookie, so rutestrip.ai.id is signed out too. */
  const handleLogout = useCallback(async () => {
    try { await fetch('/api/auth/logout', { method: 'POST' }); } catch {}
    setIsSidebarOpen(false);
    setIsAccountOpen(false);
    setMessages([]);
    setActiveSessionId(null);
    setAuthNotice('');
    setCurrentUser(null);
  }, []);

  /* ── Send message ── */
  const sendMessage = useCallback(async (text) => {
    const message = (text || input).trim();
    if (!message || isLoading) return;

    setMessages(prev => [...prev, { role: 'user', content: message, time: hhmm() }]);
    setInput('');
    setIsLoading(true);

    if (inputRef.current) inputRef.current.style.height = '2.75rem';

    // Rotate loading stages
    let idx = 0;
    stageTimer.current = setInterval(() => {
      idx = (idx + 1) % STAGES.length;
      setStageIdx(idx);
    }, 1800);

    try {
      // Last 10 turns, without the message being sent
      const historyToSend = messages.slice(-10).map(m => ({ role: m.role, content: m.content }));

      const res = await fetch('/api/chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message, history: historyToSend, session_id: activeSessionId, stream: true, locale: lang }),
      });

      const replyTimeStr = hhmm();

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
            content: m.content ? `${m.content}\n\n*${t('chat.connLost')}*` : t('chat.connError'),
          }));
          return;
        }

        ensureMessage();
        updateStreamMsg(m => (m.content ? m : { ...m, content: t('chat.replyError') }));
        return;
      }

      // ── Plain JSON response (non-streaming intents / older server) ──
      const data = await res.json();
      if (data?.session_id) setActiveSessionId(data.session_id);

      setMessages(prev => [
        ...prev,
        {
          role: 'assistant',
          content: data.response || data.error || t('chat.replyError'),
          retrieved_routes: data.retrieved_routes || [],
          timing: data.timing || null,
          time: replyTimeStr,
          map_url: data.map_url || null,
          gpx_url: data.gpx_url || null,
        },
      ]);
    } catch {
      setMessages(prev => [...prev, { role: 'assistant', content: t('chat.connError'), time: hhmm() }]);
    } finally {
      clearInterval(stageTimer.current);
      setIsLoading(false);
      setStreamStarted(false);
      setStageIdx(0);
    }
  }, [input, isLoading, messages, activeSessionId, lang, t, STAGES.length]);

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

  /* ── Bottom tab bar (phones) ── */
  const activeTab = isSidebarOpen ? 'history' : isAccountOpen ? 'account' : 'chat';
  const goChat = () => {
    setIsSidebarOpen(false);
    setIsAccountOpen(false);
    if (activeTab === 'chat') scrollToBottom();
  };
  const toggleHistory = () => {
    setIsAccountOpen(false);
    setIsSidebarOpen((o) => !o);
  };
  const toggleAccount = () => {
    setIsSidebarOpen(false);
    setIsAccountOpen((o) => !o);
  };

  /* ── Auth guard ── */
  if (authChecking) {
    return (
      <div className="auth-overlay">
        <div className="auth-card auth-card-loading" role="status">
          <div className="auth-logo-wrap">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/logo.svg" alt="" width={48} height={48} className="auth-logo" />
          </div>
          <h2 className="auth-loading-title">RuteStrip AI</h2>
          <p className="auth-loading-text">{t('auth.checking')}</p>
        </div>
      </div>
    );
  }

  if (!currentUser) {
    return (
      <AuthForm
        key={authNotice}
        initialError={authNotice}
        onAuthSuccess={(user) => { setAuthNotice(''); setCurrentUser(user); }}
      />
    );
  }

  const suggestions = t('suggestions');

  return (
    <div className="app-shell">
      {/* ── PWA Install Notification Banner ── */}
      {showPwaBanner && (
        <div className="pwa-banner-bar msg-appear">
          <div className="pwa-banner-left">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/logo.svg" alt="" width={32} height={32} className="pwa-banner-logo" />
            <div className="pwa-banner-text">
              <strong>{t('pwa.title')}</strong>
              <p>{t('pwa.text')}</p>
            </div>
          </div>
          <div className="pwa-banner-right">
            <button type="button" onClick={handleInstallPwa} className="pwa-install-btn">
              <Icon name="download" size={14} />
              <span>{t('pwa.install')}</span>
            </button>
            <button
              type="button"
              onClick={dismissPwaBanner}
              className="pwa-dismiss-btn"
              aria-label={t('pwa.dismiss')}
              title={t('pwa.dismiss')}
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
        onInstall={handleInstallPwa}
        onUnauthorized={handleUnauthorized}
      />

      <AccountSheet open={isAccountOpen} user={currentUser} onLogout={handleLogout} onClose={closeAccount} />

      {/* ── Header ── */}
      <header className="header">
        <div className="header-left">
          <button
            type="button"
            onClick={() => setIsSidebarOpen(prev => !prev)}
            className="icon-btn header-menu-btn"
            title={t('header.menuTitle')}
            aria-label={isSidebarOpen ? t('header.menuClose') : t('header.menuOpen')}
            aria-expanded={isSidebarOpen}
            aria-controls="chat-sidebar"
          >
            <Icon name="menu" size={20} />
          </button>

          <div className="header-brand">
            <div className="logo-img-wrap">
              <Image src="/logo.svg" alt="" width={36} height={36} className="logo-img" priority />
              <span className="online-dot" aria-hidden="true" />
            </div>
            <div className="header-brand-text">
              <h1 className="header-title">RuteStrip AI</h1>
              <p className="header-subtitle">{t('brand.subtitle')}</p>
            </div>
          </div>
        </div>

        <nav className="header-nav" aria-label={t('header.navAria')}>
          <a href={MAIN_SITE_URL} className="nav-link" title={t('header.mainSiteTitle')} aria-label={t('header.mainSite')}>
            <Icon name="home" size={16} />
            <span>{t('header.mainSite')}</span>
          </a>
          <Link href="/system" className="nav-link" title={t('header.systemTitle')} aria-label={t('header.system')}>
            <Icon name="chart-bar" size={16} />
            <span>{t('header.system')}</span>
          </Link>
        </nav>

        <div className="header-right">
          <div className="header-tools">
            <button
              type="button"
              id="btn-pipeline"
              className={`icon-btn${showPipeline ? ' active' : ''}`}
              onClick={() => setShowPipeline(p => !p)}
              aria-label={t('header.pipeline')}
              aria-pressed={showPipeline}
              title={t('header.pipelineTitle')}
            >
              <Icon name="clipboard-list" size={18} />
            </button>
            <button
              type="button"
              id="btn-export"
              className="icon-btn print-hide"
              onClick={() => window.print()}
              aria-label={t('header.export')}
              title={t('header.exportTitle')}
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
            aria-label={t('header.newChatAria')}
            title={t('header.newChat')}
          >
            <Icon name="plus" size={16} strokeWidth={2} />
            <span>{t('header.newChat')}</span>
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
              <Image src="/logo.svg" alt="RuteStrip AI" width={80} height={80} className="welcome-logo" priority />
              <h2 className="welcome-title">{t('welcome.title')}</h2>
              <p className="welcome-subtitle">{t('welcome.subtitle')}</p>

              <div className="suggestion-grid">
                {suggestions.map((s, i) => (
                  <button
                    key={i}
                    id={`suggestion-${i}`}
                    type="button"
                    className={`suggestion-card ${SUGGESTION_STYLE[i]?.hoverClass || ''}`}
                    onClick={() => sendMessage(s.query)}
                    aria-label={s.query}
                  >
                    <span className="suggestion-icon" aria-hidden="true"><Icon name={SUGGESTION_STYLE[i]?.icon || 'map'} size={20} /></span>
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
            <div key={idx} className={`message-row msg-appear ${msg.role}`}>
              <div className="message-pair">
                {/* AI avatar */}
                {msg.role === 'assistant' && (
                  <Image src="/logo.svg" alt="RuteStrip AI" width={32} height={32} className="avatar avatar-ai" />
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
                      const mtn = mountainIn(msg.content);
                      const alt = mtn ? t('chat.mapAlt', { m: mtn }) : t('chat.mapAltDefault');
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
                          aria-label={t('chat.mapOpen', { alt })}
                          title={t('chat.mapZoom')}
                        >
                          {/* eslint-disable-next-line @next/next/no-img-element */}
                          <img
                            src={msg.map_url}
                            alt={alt}
                            className="map-img"
                            loading="lazy"
                            onLoad={idx === messages.length - 1 ? scrollToBottom : undefined}
                          />
                          <span className="map-img-overlay print-hide"><Icon name="zoom-in" size={14} /><span>{t('chat.mapTap')}</span></span>
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
                        {t('chat.download', { fmt: msg.gpx_url?.includes('kml') ? 'KML' : 'GPX' })}
                      </a>
                    )}
                  </div>
                  )}

                  {/* Route chips */}
                  {msg.role === 'assistant' && msg.retrieved_routes?.length > 0 && (
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
                          <button type="button" className="quick-action" onClick={() => sendMessage(t('qa.qWeather', { m: r.name }))} title={t('qa.tWeather', { m: r.name })}><Icon name="cloud" size={13} />{t('qa.weather')}</button>
                          <button type="button" className="quick-action" onClick={() => sendMessage(t('qa.qMap', { m: r.name }))} title={t('qa.tMap', { m: r.name })}><Icon name="map" size={13} />{t('qa.map')}</button>
                          <button type="button" className="quick-action" onClick={() => sendMessage(t('qa.qItinerary', { m: r.name }))} title={t('qa.qItinerary', { m: r.name })}><Icon name="calendar" size={13} />{t('qa.itinerary')}</button>
                        </span>
                      ))}
                    </div>
                  )}

                  {/* Quick actions for map responses */}
                  {msg.role === 'assistant' && msg.timing?.type === 'map' && (
                    <div className="quick-actions">
                      <button type="button" className="quick-action" onClick={() => sendMessage(t('qa.qWeather', { m: mountainIn(msg.content) }))}><Icon name="cloud" size={13} />{t('qa.checkWeather')}</button>
                      <button type="button" className="quick-action" onClick={() => sendMessage(t('qa.qItinerary', { m: mountainIn(msg.content) }))}><Icon name="calendar" size={13} />{t('qa.itinerary')}</button>
                      <button type="button" className="quick-action" onClick={() => sendMessage(t('qa.qPorter', { m: mountainIn(msg.content) }))}><Icon name="phone" size={13} />{t('qa.porter')}</button>
                    </div>
                  )}

                  {/* Timing */}
                  {msg.role === 'assistant' && msg.timing && (
                    <div className="timing-row">
                      {msg.timing.retrieval_ms > 0 && (
                        <span className="timing-item" title={t('chat.retrievalTime')}>
                          <Icon name="clock" size={11} />{msg.timing.retrieval_ms}ms
                        </span>
                      )}
                      {msg.timing.generation_ms > 0 && (
                        <span className="timing-item" title={t('chat.generationTime')}>
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
                <Image src="/logo.svg" alt={t('chat.thinking')} width={32} height={32} className="avatar avatar-ai" />
                <div className="typing-bubble">
                  <div className="dot-wrap" aria-hidden="true">
                    <div className="dot" />
                    <div className="dot" />
                    <div className="dot" />
                  </div>
                  <span className="typing-label">{STAGES[stageIdx] || STAGES[0]}</span>
                </div>
              </div>
            </div>
          )}

        </div>
      </main>

      {/* ── Input Footer ── */}
      <footer className="input-footer print-hide">
        <TelegramFab />

        <form className="input-form" onSubmit={handleSubmit} aria-label={t('chat.formAria')}>
          <div className="input-wrap">
            <button
              type="button"
              id="btn-voice"
              className={`voice-btn${isListening ? ' listening' : ''}`}
              onClick={toggleSpeech}
              aria-label={isListening ? t('chat.voiceStop') : t('chat.voiceStart')}
              title={isListening ? t('chat.voiceStop') : t('chat.voiceTitle')}
              aria-pressed={isListening}
            >
              <Icon name="microphone" size={20} />
            </button>

            <textarea
              id="chat-input"
              ref={inputRef}
              value={input}
              onChange={e => setInput(e.target.value)}
              onKeyDown={handleKeyDown}
              onInput={autoResize}
              disabled={isLoading}
              rows={1}
              placeholder={t('chat.inputPh')}
              className="chat-textarea"
              style={{ minHeight: '2.75rem' }}
              aria-label={t('chat.inputAria')}
            />
          </div>

          <button
            type="submit"
            id="btn-send"
            className="send-btn"
            disabled={isLoading || !input.trim()}
            aria-label={t('chat.send')}
          >
            {isLoading ? <Icon name="refresh" size={20} className="spin" /> : <Icon name="send" size={20} />}
          </button>
        </form>

        <div className="input-footer-meta">
          <p className="powered-by">
            Powered by <span className="sbert">SBERT</span> + <span className="gemini">Gemini</span> / <span className="qwen">Qwen</span>
          </p>
          <p className="input-hint">{t('chat.newLine')}</p>
        </div>
      </footer>

      <BottomTabBar active={activeTab} onChat={goChat} onHistory={toggleHistory} onAccount={toggleAccount} />

      <ImageLightbox key={lightbox?.id || 'none'} image={lightbox} onClose={closeLightbox} />
    </div>
  );
}
