'use client';

import { useEffect, useRef, useState } from 'react';
import { loadAltcha } from '@/lib/altcha';
import { SETTINGS_URL } from '@/lib/i18n';
import Icon from './Icon';
import { LanguageSwitch, useI18n } from './I18nProvider';

/*
 * Sign in / sign up with the shared RuteStrip account (Laravel, rutestrip.ai.id).
 * The Next API routes set the HttpOnly rs_sso cookie; nothing is stored in JS.
 */
export default function AuthForm({ onAuthSuccess, initialError = '' }) {
  const { t, lang } = useI18n();
  const [mode, setMode] = useState('login'); // 'login' | 'register'
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(initialError);
  const [fieldErrors, setFieldErrors] = useState({});
  const [successMsg, setSuccessMsg] = useState('');

  const [loginEmail, setLoginEmail] = useState('');
  const [loginPass, setLoginPass] = useState('');

  const [regName, setRegName] = useState('');
  const [regEmail, setRegEmail] = useState('');
  const [regPass, setRegPass] = useState('');
  const [regPass2, setRegPass2] = useState('');

  // Anti-robot check: ALTCHA proof-of-work payload + honeypot field
  const altchaRef = useRef(null);
  const [altchaPayload, setAltchaPayload] = useState('');
  const [honeypot, setHoneypot] = useState('');

  useEffect(() => {
    if (mode !== 'register') return undefined;
    const widget = altchaRef.current;
    const onStateChange = (ev) => {
      setAltchaPayload(ev.detail?.state === 'verified' ? ev.detail.payload || '' : '');
    };
    widget?.addEventListener('statechange', onStateChange);
    loadAltcha().catch(() => setError(t('auth.altchaLoadFailed')));
    return () => {
      widget?.removeEventListener('statechange', onStateChange);
      setAltchaPayload('');
    };
    // The widget is re-created (key={lang}) when the language changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [mode, lang]);

  const switchMode = (next) => {
    setMode(next);
    setError('');
    setFieldErrors({});
    setSuccessMsg('');
  };

  const submit = async (url, payload, successKey) => {
    setLoading(true);
    setError('');
    setFieldErrors({});
    setSuccessMsg('');
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });
      let data = {};
      try { data = await res.json(); } catch {}
      if (!res.ok || !data.user) {
        setFieldErrors(data.errors && typeof data.errors === 'object' ? data.errors : {});
        setError(data.error || t('auth.genericError'));
        return false;
      }
      setSuccessMsg(t(successKey));
      setTimeout(() => onAuthSuccess(data.user), 300);
      return true;
    } catch {
      setError(t('auth.networkError'));
      return false;
    } finally {
      setLoading(false);
    }
  };

  const handleLogin = async (e) => {
    e.preventDefault();
    if (!loginEmail.trim() || !loginPass) {
      setError(t('auth.loginNeedFields'));
      return;
    }
    await submit('/api/auth/login', { email: loginEmail.trim(), password: loginPass }, 'auth.loginSuccess');
  };

  const handleRegister = async (e) => {
    e.preventDefault();
    if (!regName.trim() || !regEmail.trim() || !regPass) {
      setError(t('auth.registerNeedFields'));
      return;
    }
    if (regPass !== regPass2) {
      setError(t('auth.passwordMismatch'));
      setFieldErrors({ password_confirmation: [t('auth.passwordMismatch')] });
      return;
    }
    if (!altchaPayload) {
      setError(t('auth.altchaRequired'));
      altchaRef.current?.verify?.();
      return;
    }
    const ok = await submit('/api/auth/register', {
      name: regName.trim(),
      email: regEmail.trim(),
      password: regPass,
      password_confirmation: regPass2,
      altcha: altchaPayload,
      website: honeypot,
    }, 'auth.registerSuccess');
    if (!ok) {
      // A payload is single-use: solve a fresh challenge for the next attempt.
      setAltchaPayload('');
      altchaRef.current?.reset?.();
    }
  };

  const fieldError = (name) => {
    const list = fieldErrors[name];
    const msg = Array.isArray(list) ? list[0] : typeof list === 'string' ? list : '';
    return msg ? <p className="auth-field-error" id={`err-${name}`}>{msg}</p> : null;
  };
  const invalid = (name) => (fieldErrors[name] ? { 'aria-invalid': true, 'aria-describedby': `err-${name}` } : {});

  return (
    <div className="auth-overlay">
      <div className="auth-card">
        <div className="auth-topbar">
          <LanguageSwitch showLabel={false} className="lang-switch-sm" />
        </div>

        <div className="auth-brand">
          <div className="auth-logo-wrap">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/logo.svg" alt="" width={48} height={48} className="auth-logo" />
          </div>
          <h1 className="auth-title">RuteStrip AI</h1>
          <p className="auth-subtitle">{t('brand.tagline')}</p>
        </div>

        <div className="auth-tabs" role="tablist" aria-label={t('auth.tabsAria')}>
          <button
            type="button"
            role="tab"
            aria-selected={mode === 'login'}
            className={`auth-tab-btn ${mode === 'login' ? 'active' : ''}`}
            onClick={() => switchMode('login')}
          >
            <Icon name="key" size={16} strokeWidth={2} />
            <span>{t('auth.tabLogin')}</span>
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={mode === 'register'}
            className={`auth-tab-btn ${mode === 'register' ? 'active' : ''}`}
            onClick={() => switchMode('register')}
          >
            <Icon name="user-add" size={16} strokeWidth={2} />
            <span>{t('auth.tabRegister')}</span>
          </button>
        </div>

        {error && (
          <div className="auth-alert alert-error" role="alert">
            <Icon name="warning" size={18} strokeWidth={2} />
            <span>{error}</span>
          </div>
        )}
        {successMsg && (
          <div className="auth-alert alert-success" role="status">
            <Icon name="check-circle" size={18} strokeWidth={2} />
            <span>{successMsg}</span>
          </div>
        )}

        {mode === 'login' ? (
          <form onSubmit={handleLogin} className="auth-form-body" noValidate>
            <div className="auth-field">
              <label htmlFor="loginEmail">{t('auth.email')}</label>
              <input
                id="loginEmail"
                type="email"
                inputMode="email"
                autoComplete="email"
                placeholder={t('auth.emailPh')}
                value={loginEmail}
                onChange={(e) => setLoginEmail(e.target.value)}
                required
                {...invalid('email')}
              />
              {fieldError('email')}
            </div>

            <div className="auth-field">
              <label htmlFor="loginPass">{t('auth.password')}</label>
              <input
                id="loginPass"
                type="password"
                autoComplete="current-password"
                placeholder={t('auth.passwordPh')}
                value={loginPass}
                onChange={(e) => setLoginPass(e.target.value)}
                required
                {...invalid('password')}
              />
              {fieldError('password')}
            </div>

            <button type="submit" disabled={loading} className="auth-submit-btn">
              <span>{loading ? t('auth.loggingIn') : t('auth.loginSubmit')}</span>
              {!loading && <Icon name="arrow-right" size={16} strokeWidth={2} />}
            </button>
          </form>
        ) : (
          <form onSubmit={handleRegister} className="auth-form-body" noValidate>
            <div className="auth-field">
              <label htmlFor="regName">{t('auth.name')}</label>
              <input
                id="regName"
                type="text"
                autoComplete="name"
                placeholder={t('auth.namePh')}
                value={regName}
                onChange={(e) => setRegName(e.target.value)}
                required
                {...invalid('name')}
              />
              {fieldError('name')}
            </div>

            <div className="auth-field">
              <label htmlFor="regEmail">{t('auth.email')}</label>
              <input
                id="regEmail"
                type="email"
                inputMode="email"
                autoComplete="email"
                placeholder={t('auth.emailPh')}
                value={regEmail}
                onChange={(e) => setRegEmail(e.target.value)}
                required
                {...invalid('email')}
              />
              {fieldError('email')}
            </div>

            <div className="auth-field">
              <label htmlFor="regPass">{t('auth.password')}</label>
              <input
                id="regPass"
                type="password"
                autoComplete="new-password"
                placeholder={t('auth.newPasswordPh')}
                value={regPass}
                onChange={(e) => setRegPass(e.target.value)}
                required
                {...invalid('password')}
              />
              {fieldError('password')}
            </div>

            <div className="auth-field">
              <label htmlFor="regPass2">{t('auth.passwordConfirm')}</label>
              <input
                id="regPass2"
                type="password"
                autoComplete="new-password"
                placeholder={t('auth.passwordConfirmPh')}
                value={regPass2}
                onChange={(e) => setRegPass2(e.target.value)}
                required
                {...invalid('password_confirmation')}
              />
              {fieldError('password_confirmation')}
            </div>

            <div className="auth-hp" aria-hidden="true">
              <label htmlFor="regWebsite">Website</label>
              <input
                id="regWebsite"
                name="website"
                type="text"
                tabIndex={-1}
                autoComplete="off"
                value={honeypot}
                onChange={(e) => setHoneypot(e.target.value)}
              />
            </div>

            <altcha-widget
              key={lang}
              ref={altchaRef}
              className="auth-altcha"
              challenge="/api/auth/altcha"
              language={lang}
              auto="onfocus"
            />
            {fieldError('altcha')}

            <button type="submit" disabled={loading} className="auth-submit-btn">
              <span>{loading ? t('auth.registering') : t('auth.registerSubmit')}</span>
              {!loading && <Icon name="check" size={16} strokeWidth={2} />}
            </button>
          </form>
        )}

        <div className="auth-footer">
          <a href={SETTINGS_URL} className="auth-link">
            {t('auth.forgot')}
          </a>
          <p className="auth-footer-note">
            <Icon name="shield-check" size={14} />
            <span>{t('auth.sharedNote')}</span>
          </p>
        </div>
      </div>
    </div>
  );
}
