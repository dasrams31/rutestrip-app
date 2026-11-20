'use client';

import { useState, useEffect, useCallback, useRef } from 'react';
import Link from 'next/link';
import { MAIN_SITE_URL, SETTINGS_URL } from '@/lib/i18n';
import Icon from './Icon';
import { userInitial } from './AccountMenu';
import { useI18n } from './I18nProvider';

export default function SidebarHistory({
  isOpen,
  onClose,
  currentUser,
  activeSessionId,
  onSelectSession,
  onNewChat,
  onLogout,
  onInstall,
  onUnauthorized,
}) {
  const { t, tag } = useI18n();
  const [sessionsList, setSessionsList] = useState([]);
  const [loading, setLoading] = useState(false);
  const [clearing, setClearing] = useState(false);
  const [deletingId, setDeletingId] = useState(null);
  const closeBtnRef = useRef(null);

  // The rs_sso cookie travels with these same-origin requests.
  const fetchSessions = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch('/api/chat/sessions', { cache: 'no-store' });
      if (res.status === 401) { onUnauthorized?.(); return; }
      const data = await res.json();
      if (res.ok && data.sessions) setSessionsList(data.sessions);
    } catch (err) {
      console.error('Fetch sessions error:', err);
    } finally {
      setLoading(false);
    }
  }, [onUnauthorized]);

  useEffect(() => {
    if (isOpen) fetchSessions();
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
    if (!confirm(t('sidebar.confirmDelete'))) return;
    setDeletingId(sessionId);
    try {
      const res = await fetch(`/api/chat/sessions/${encodeURIComponent(sessionId)}`, { method: 'DELETE' });
      if (res.status === 401) { onUnauthorized?.(); return; }
      if (res.ok) {
        setSessionsList((prev) => prev.filter((s) => s.session_id !== sessionId));
        if (activeSessionId === sessionId && onNewChat) onNewChat();
      }
    } catch (err) {
      console.error('Delete session error:', err);
    } finally {
      setDeletingId(null);
    }
  };

  const handleClearAllHistory = async () => {
    if (!confirm(t('sidebar.confirmClearAll'))) return;
    setClearing(true);
    try {
      const res = await fetch('/api/chat/sessions', { method: 'DELETE' });
      if (res.status === 401) { onUnauthorized?.(); return; }
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

  const name = currentUser?.name || t('common.user');

  return (
    <>
      {isOpen && <div className="sidebar-backdrop" onClick={onClose} aria-hidden="true" />}

      <aside
        id="chat-sidebar"
        className={`sidebar-drawer ${isOpen ? 'open' : ''}`}
        aria-label={t('sidebar.aria')}
        inert={!isOpen}
      >
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
            aria-label={t('sidebar.close')}
            title={t('sidebar.close')}
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
            <span>{t('sidebar.newChat')}</span>
          </button>
        </div>

        <div className="sidebar-history-body chat-scroll">
          <div className="sidebar-section-head">
            <h2 className="sidebar-section-title">{t('sidebar.history')}</h2>
            {sessionsList.length > 0 && (
              <button
                type="button"
                onClick={handleClearAllHistory}
                disabled={clearing}
                className="sidebar-clear-btn"
              >
                {t('sidebar.clearAll')}
              </button>
            )}
          </div>

          {loading ? (
            <div className="sidebar-loading" role="status">
              <Icon name="refresh" size={20} className="spin-anim sidebar-loading-icon" />
              <span>{t('sidebar.loading')}</span>
            </div>
          ) : sessionsList.length > 0 ? (
            <ul className="sidebar-history-list">
              {sessionsList.map((item) => {
                const isActive = activeSessionId === item.session_id;
                const title = item.title || t('sidebar.untitled');
                const when = item.created_at ? new Date(item.created_at) : null;
                const meta = when && !Number.isNaN(when.getTime())
                  ? [
                    when.toLocaleDateString(tag, { day: 'numeric', month: 'short' }),
                    when.toLocaleTimeString(tag, { hour: '2-digit', minute: '2-digit' }),
                  ].join(' · ')
                  : '';

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
                      aria-label={t('sidebar.delete', { title })}
                      title={t('sidebar.deleteTitle')}
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
              <span>{t('sidebar.empty')}</span>
            </div>
          )}
        </div>

        <div className="sidebar-footer">
          {currentUser && (
            <div className="sidebar-user-card">
              <span className="user-avatar" aria-hidden="true">{userInitial(name)}</span>
              <div className="sidebar-user-info">
                <div className="sidebar-user-name">{name}</div>
                {currentUser.email && <div className="sidebar-user-email">{currentUser.email}</div>}
              </div>
              <a
                href={SETTINGS_URL}
                className="sidebar-icon-btn"
                aria-label={t('account.settings')}
                title={t('account.settings')}
              >
                <Icon name="cog" size={18} />
              </a>
              <button
                type="button"
                onClick={onLogout}
                className="sidebar-icon-btn danger"
                aria-label={t('account.logout')}
                title={t('account.logout')}
              >
                <Icon name="logout" size={18} />
              </button>
            </div>
          )}

          <nav className="sidebar-links-row" aria-label={t('sidebar.more')}>
            <a href={MAIN_SITE_URL} className="sidebar-link-chip" title={t('header.mainSiteTitle')}>
              <Icon name="home" size={15} />
              <span>{t('header.mainSite')}</span>
            </a>
            <Link href="/system" className="sidebar-link-chip" title={t('header.systemTitle')}>
              <Icon name="chart-bar" size={15} />
              <span>{t('header.system')}</span>
            </Link>
            <button type="button" onClick={onInstall} className="sidebar-link-chip">
              <Icon name="device-mobile" size={15} />
              <span>{t('sidebar.install')}</span>
            </button>
          </nav>
        </div>
      </aside>
    </>
  );
}
