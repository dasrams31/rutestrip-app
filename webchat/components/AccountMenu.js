'use client';

import { useEffect, useRef, useState } from 'react';
import { SETTINGS_URL } from '@/lib/i18n';
import Icon from './Icon';
import { LanguageSwitch, useI18n } from './I18nProvider';

export function userInitial(name) {
  return (name || '').trim().charAt(0).toUpperCase() || 'U';
}

/* Name, language switch, account settings (on rutestrip.ai.id) and sign out. */
export function AccountPanel({ user, onLogout, onClose }) {
  const { t } = useI18n();
  const name = user?.name || t('common.user');
  return (
    <>
      <div className="user-menu-head">
        <span className="user-avatar user-avatar-lg" aria-hidden="true">{userInitial(name)}</span>
        <span className="user-menu-id">
          <span className="user-menu-title">{name}</span>
          {user?.email && <span className="user-menu-sub">{user.email}</span>}
        </span>
      </div>
      <div className="user-menu-lang">
        <LanguageSwitch />
      </div>
      <a href={SETTINGS_URL} className="user-menu-item" onClick={onClose}>
        <Icon name="cog" size={16} />
        <span>{t('account.settings')}</span>
        <Icon name="external" size={12} className="user-menu-item-ext" />
      </a>
      <button
        type="button"
       
        className="user-menu-item danger"
        onClick={() => { onClose?.(); onLogout(); }}
      >
        <Icon name="logout" size={16} />
        <span>{t('account.logout')}</span>
      </button>
    </>
  );
}

/* Header dropdown (desktop / tablet). */
export function UserMenu({ user, onLogout }) {
  const { t } = useI18n();
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

  const name = user?.name || t('common.user');

  return (
    <div className="user-menu" ref={wrapRef}>
      <button
        type="button"
        className="user-menu-trigger"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        aria-label={t('account.menuAria', { name })}
      >
        <span className="user-avatar" aria-hidden="true">{userInitial(name)}</span>
        <span className="user-menu-name">{name}</span>
        <Icon name="chevron-down" size={14} className="user-menu-chevron" />
      </button>

      {open && (
        <div className="user-menu-panel" aria-label={t('account.title')}>
          <AccountPanel user={user} onLogout={onLogout} onClose={() => setOpen(false)} />
        </div>
      )}
    </div>
  );
}

/* Bottom sheet for the "Akun" tab on phones; sits above the tab bar. */
export function AccountSheet({ open, user, onLogout, onClose }) {
  const { t } = useI18n();
  const closeRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    closeRef.current?.focus();
    const onKey = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;
  return (
    <>
      <div className="sheet-backdrop" onClick={onClose} aria-hidden="true" />
      <div id="account-sheet" className="account-sheet" role="dialog" aria-modal="true" aria-label={t('account.title')}>
        <div className="account-sheet-bar">
          <span className="account-sheet-title">{t('account.title')}</span>
          <button ref={closeRef} type="button" className="sidebar-close-btn" onClick={onClose} aria-label={t('account.close')}>
            <Icon name="x" size={18} />
          </button>
        </div>
        <div className="account-sheet-body">
          <AccountPanel user={user} onLogout={onLogout} onClose={onClose} />
        </div>
      </div>
    </>
  );
}
