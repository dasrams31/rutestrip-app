'use client';

import { useState, useEffect, useCallback, useRef } from 'react';
import Link from 'next/link';
import Icon from './Icon';
import { TELEGRAM_CHANNEL_URL, TELEGRAM_BOT_URL } from './TelegramFab';

export default function SidebarHistory({
  isOpen,
  onClose,
  currentUser,
  activeSessionId,
  onSelectSession,
  onNewChat,
  onLogout,
}) {
  const [sessionsList, setSessionsList] = useState([]);
  const [loading, setLoading] = useState(false);
  const [clearing, setClearing] = useState(false);
  const [deletingId, setDeletingId] = useState(null);
  const closeBtnRef = useRef(null);

  const getAuthToken = () => {
    if (typeof window === 'undefined') return null;
    let token = localStorage.getItem('rutestrip_auth_token') || localStorage.getItem('user_token') || localStorage.getItem('token');
    if (!token) {
      try {
        const profile = JSON.parse(localStorage.getItem('rutestrip_user_profile') || '{}');
        if (profile?.token) token = profile.token;
      } catch {}
    }
    return token;
  };

  const fetchSessions = useCallback(async () => {
    const token = getAuthToken();
    if (!token) return;

    setLoading(true);
    try {
      const res = await fetch('/api/chat/sessions', {
        headers: { Authorization: `Bearer ${token}` },
      });
      const data = await res.json();
      if (res.ok && data.sessions) {
        setSessionsList(data.sessions);
      }
    } catch (err) {
      console.error('Fetch sessions error:', err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isOpen) {
      fetchSessions();
    }
  }, [isOpen, fetchSessions]);

  // Keyboard: move focus into the drawer when it opens, close it on Escape.
  useEffect(() => {
    if (isOpen) closeBtnRef.current?.focus();
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;
    const onKey = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [isOpen, onClose]);

  const handleDeleteSession = async (e, sessionId) => {
    e.stopPropagation();
    if (!confirm('Apakah Anda yakin ingin menghapus sesi percakapan ini?')) return;
    const token = getAuthToken();
    if (!token) return;

    setDeletingId(sessionId);
    try {
      const res = await fetch(`/api/chat/sessions/${sessionId}`, {
        method: 'DELETE',
        headers: { Authorization: `Bearer ${token}` },
      });
      if (res.ok) {
        setSessionsList((prev) => prev.filter((s) => s.session_id !== sessionId));
        if (activeSessionId === sessionId && onNewChat) {
          onNewChat();
        }
      }
    } catch (err) {
      console.error('Delete session error:', err);
    } finally {
      setDeletingId(null);
    }
  };

  const handleClearAllHistory = async () => {
    if (!confirm('Apakah Anda yakin ingin menghapus seluruh sesi percakapan?')) return;
    const token = getAuthToken();
    if (!token) return;

    setClearing(true);
    try {
      const res = await fetch('/api/chat/sessions', {
        method: 'DELETE',
        headers: { Authorization: `Bearer ${token}` },
      });
      if (res.ok) {
        setSessionsList([]);
        if (onNewChat) onNewChat();
      }
    } catch (err) {
      console.error('Clear all history error:', err);
    } finally {
      setClearing(false);
    }
  };

  const closeOnMobile = () => {
    if (typeof window !== 'undefined' && window.innerWidth < 768) onClose();
  };

  const handleInstallPwa = () => {
    if (typeof window !== 'undefined' && window.deferredPwaPrompt) {
      window.deferredPwaPrompt.prompt();
      window.deferredPwaPrompt.userChoice.then((choiceResult) => {
        if (choiceResult.outcome === 'accepted') {
          console.log('User accepted the PWA install prompt');
        }
        window.deferredPwaPrompt = null;
      });
    } else {
      alert('Untuk menginstall aplikasi PWA di HP:\n• Android (Chrome): Ketuk titik tiga (⋮) -> Pilih "Tambahkan ke Layar Utama" / "Install App"\n• iPhone (Safari): Ketuk ikon Share -> Pilih "Add to Home Screen"');
    }
  };

  return (
    <>
      {/* Backdrop */}
      {isOpen && (
        <div className="sidebar-backdrop" onClick={onClose} aria-hidden="true" />
      )}

      {/* Drawer */}
      <aside
        id="chat-sidebar"
        className={`sidebar-drawer ${isOpen ? 'open' : ''}`}
        aria-label="Riwayat percakapan dan menu"
        inert={!isOpen}
      >
        {/* Header */}
        <div className="sidebar-header">
          <div className="sidebar-brand">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/logo.svg" alt="" width={28} height={28} className="sidebar-brand-logo" />
            <span>RuteStrip AI</span>
          </div>
          <button
            ref={closeBtnRef}
            type="button"
            onClick={onClose}
            className="sidebar-close-btn"
            aria-label="Tutup menu"
            title="Tutup menu"
          >
            <Icon name="x" size={18} />
          </button>
        </div>

        <div className="sidebar-top">
          <button
            type="button"
            onClick={() => {
              if (onNewChat) onNewChat();
              closeOnMobile();
            }}
            className="sidebar-new-chat-btn"
          >
            <Icon name="plus" size={16} strokeWidth={2} />
            <span>Chat Baru</span>
          </button>
        </div>

        {/* Sessions */}
        <div className="sidebar-history-body chat-scroll">
          <h2 className="sidebar-section-title">Riwayat Percakapan</h2>

          {loading ? (
            <div className="sidebar-loading" role="status">
              <Icon name="refresh" size={20} className="spin-anim sidebar-loading-icon" />
              <span>Memuat sesi obrolan...</span>
            </div>
          ) : sessionsList.length > 0 ? (
            <ul className="sidebar-history-list">
              {sessionsList.map((item) => {
                const isActive = activeSessionId === item.session_id;
                const title = item.title || 'Percakapan Baru';
                const timeStr = item.created_at
                  ? new Date(item.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
                  : '';
                const dateStr = item.created_at
                  ? new Date(item.created_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })
                  : '';
                const meta = [dateStr, timeStr].filter(Boolean).join(' · ');

                return (
                  <li key={item.session_id} className={`sidebar-history-item ${isActive ? 'active' : ''}`}>
                    <button
                      type="button"
                      className="sidebar-item-main"
                      aria-current={isActive ? 'true' : undefined}
                      onClick={() => {
                        if (onSelectSession) onSelectSession(item);
                        closeOnMobile();
                      }}
                    >
                      <Icon name="chat" size={16} className="sidebar-item-icon" />
                      <span className="sidebar-item-content">
                        <span className="sidebar-item-title">{title}</span>
                        {meta && <span className="sidebar-item-time">{meta}</span>}
                      </span>
                    </button>

                    <button
                      type="button"
                      onClick={(e) => handleDeleteSession(e, item.session_id)}
                      disabled={deletingId === item.session_id}
                      className="sidebar-delete-single-btn"
                      aria-label={`Hapus sesi: ${title}`}
                      title="Hapus sesi ini"
                    >
                      <Icon name="trash" size={14} />
                    </button>
                  </li>
                );
              })}
            </ul>
          ) : (
            <div className="sidebar-empty">
              <Icon name="inbox" size={32} strokeWidth={1.5} className="sidebar-empty-icon" />
              <span>Belum ada riwayat percakapan</span>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="sidebar-footer">
          <nav className="sidebar-links" aria-label="Tautan">
            <a href="https://rutestrip.ai.id" className="sidebar-action-btn">
              <Icon name="home" size={16} />
              <span>Situs Utama</span>
            </a>
            <Link href="/system" className="sidebar-action-btn">
              <Icon name="chart-bar" size={16} />
              <span>Status Sistem</span>
            </Link>
            <a href={TELEGRAM_CHANNEL_URL} target="_blank" rel="noopener noreferrer" className="sidebar-action-btn">
              <Icon name="telegram" size={16} />
              <span>Channel Telegram</span>
            </a>
            <a href={TELEGRAM_BOT_URL} target="_blank" rel="noopener noreferrer" className="sidebar-action-btn">
              <Icon name="telegram" size={16} />
              <span>Bot Telegram</span>
            </a>
            <button type="button" onClick={handleInstallPwa} className="sidebar-action-btn accent">
              <Icon name="device-mobile" size={16} />
              <span>Install Aplikasi</span>
            </button>
          </nav>

          {currentUser && (
            <div className="sidebar-user-card">
              <div className="sidebar-user-avatar" aria-hidden="true">
                <Icon name="user" size={18} />
              </div>
              <div className="sidebar-user-info">
                <div className="sidebar-user-name">{currentUser.full_name || currentUser.username}</div>
                {currentUser.email && <div className="sidebar-user-email">{currentUser.email}</div>}
              </div>
            </div>
          )}

          <div className="sidebar-footer-actions">
            {sessionsList.length > 0 && (
              <button
                type="button"
                onClick={handleClearAllHistory}
                disabled={clearing}
                className="sidebar-action-btn danger"
              >
                <Icon name="trash" size={16} />
                <span>Hapus Semua Riwayat</span>
              </button>
            )}
            <button type="button" onClick={onLogout} className="sidebar-action-btn danger">
              <Icon name="logout" size={16} />
              <span>Keluar</span>
            </button>
          </div>
        </div>
      </aside>
    </>
  );
}
