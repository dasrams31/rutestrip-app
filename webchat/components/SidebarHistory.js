'use client';

import { useState, useEffect, useCallback } from 'react';
import Link from 'next/link';

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

  return (
    <>
      {/* Mobile Backdrop Overlay */}
      {isOpen && (
        <div className="sidebar-backdrop" onClick={onClose} aria-hidden="true" />
      )}

      {/* Sidebar Slider Drawer */}
      <aside className={`sidebar-drawer ${isOpen ? 'open' : ''}`}>
        {/* Header */}
        <div className="sidebar-header">
          <button
            onClick={() => {
              if (onNewChat) onNewChat();
              if (window.innerWidth < 768) onClose();
            }}
            className="sidebar-new-chat-btn"
          >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.125rem', height: '1.125rem' }}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" />
            </svg>
            <span>+ Chat Baru</span>
          </button>

          <button onClick={onClose} className="sidebar-close-btn" title="Tutup Menu Sidebar">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {/* Sessions List Section */}
        <div className="sidebar-history-body chat-scroll">
          <div className="sidebar-section-title">Riwayat Sesi Percakapan</div>

          {loading ? (
            <div className="sidebar-loading">
              <svg className="spin-anim" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem', color: '#10b981' }}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              <span>Memuat sesi obrolan...</span>
            </div>
          ) : sessionsList.length > 0 ? (
            <div className="sidebar-history-list">
              {sessionsList.map((item) => {
                const isActive = activeSessionId === item.session_id;
                const timeStr = item.created_at
                  ? new Date(item.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
                  : '';
                const dateStr = item.created_at
                  ? new Date(item.created_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })
                  : '';

                return (
                  <div
                    key={item.session_id}
                    className={`sidebar-history-item ${isActive ? 'active' : ''}`}
                    onClick={() => {
                      if (onSelectSession) onSelectSession(item);
                      if (window.innerWidth < 768) onClose();
                    }}
                    style={{ position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'space-between', cursor: 'pointer' }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.625rem', flex: 1, minWidth: 0 }}>
                      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} className="sidebar-item-icon" style={{ flexShrink: 0 }}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                      </svg>
                      <div className="sidebar-item-content" style={{ minWidth: 0 }}>
                        <span className="sidebar-item-title" style={{ whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                          {item.title || 'Percakapan Baru'}
                        </span>
                        <div style={{ fontSize: '0.6875rem', color: '#94a3b8', display: 'flex', gap: '0.375rem', marginTop: '0.125rem' }}>
                          {dateStr && <span>{dateStr}</span>}
                          {timeStr && <span>• {timeStr}</span>}
                        </div>
                      </div>
                    </div>

                    <button
                      onClick={(e) => handleDeleteSession(e, item.session_id)}
                      disabled={deletingId === item.session_id}
                      className="sidebar-delete-single-btn"
                      title="Hapus Sesi Ini"
                      style={{
                        background: 'none',
                        border: 'none',
                        color: '#94a3b8',
                        padding: '0.25rem',
                        borderRadius: '0.375rem',
                        cursor: 'pointer',
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                      }}
                    >
                      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '0.875rem', height: '0.875rem' }}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </button>
                  </div>
                );
              })}
            </div>
          ) : (
            <div className="sidebar-empty">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5} style={{ width: '2rem', height: '2rem', color: '#94a3b8', margin: '0 auto 0.5rem' }}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
              </svg>
              <span>Belum ada riwayat sesi percakapan</span>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="sidebar-footer">
          {/* User Profile Badge */}
          {currentUser && (
            <div className="sidebar-user-card">
              <div className="sidebar-user-avatar">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.125rem', height: '1.125rem', color: '#047857' }}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
              </div>
              <div className="sidebar-user-info">
                <div className="sidebar-user-name">{currentUser.full_name || currentUser.username}</div>
                <div className="sidebar-user-email">{currentUser.email}</div>
              </div>
            </div>
          )}

          {/* Quick Actions */}
          <div className="sidebar-footer-actions">
            {sessionsList.length > 0 && (
              <button
                onClick={handleClearAllHistory}
                disabled={clearing}
                className="sidebar-action-btn danger"
              >
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '0.875rem', height: '0.875rem' }}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                <span>Hapus Semua Sesi</span>
              </button>
            )}

            <Link href="/system" className="sidebar-action-btn">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '0.875rem', height: '0.875rem' }}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
              </svg>
              <span>System Monitor</span>
            </Link>

            {/* Install PWA Button */}
            <button
              onClick={() => {
                if (typeof window !== 'undefined' && window.deferredPwaPrompt) {
                  window.deferredPwaPrompt.prompt();
                  window.deferredPwaPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                      console.log('User accepted the PWA install prompt');
                    }
                    window.deferredPwaPrompt = null;
                  });
                } else {
                  alert('Untuk menginstall aplikasi PWA di HP:\n• Android (Chrome): Ketik titik tiga (⋮) -> Pilih "Tambahkan ke Layar Utama" / "Install App"\n• iPhone (Safari): Ketik icon Share -> Pilih "Add to Home Screen"');
                }
              }}
              className="sidebar-action-btn"
              style={{ color: '#059669', fontWeight: 600 }}
            >
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '0.875rem', height: '0.875rem' }}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
              <span>Install App PWA 📱</span>
            </button>

            <button onClick={onLogout} className="sidebar-action-btn danger">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '0.875rem', height: '0.875rem' }}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
              <span>Logout</span>
            </button>
          </div>
        </div>
      </aside>
    </>
  );
}

