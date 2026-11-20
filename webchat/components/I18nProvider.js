'use client';

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { normalizeLang, translate, localeTag, writeLangCookie } from '@/lib/i18n';
import Icon from './Icon';

const I18nContext = createContext(null);

/*
 * initialLang comes from the rs_lang cookie (read by the root layout on the
 * server, so the first render is already in the right language).
 * hasLangCookie tells whether that was an explicit choice; without one the
 * signed-in user's saved locale may still apply (docs/SSO.md).
 */
export function I18nProvider({ initialLang, hasLangCookie, cookieDomain, cookieSecure, children }) {
  const [lang, setLangState] = useState(normalizeLang(initialLang));
  const [explicit, setExplicit] = useState(Boolean(hasLangCookie));

  const setLang = useCallback((next, { persist = true } = {}) => {
    const value = normalizeLang(next);
    setLangState(value);
    if (typeof document !== 'undefined') document.documentElement.lang = value;
    if (persist) {
      writeLangCookie(value, { domain: cookieDomain, secure: cookieSecure });
      setExplicit(true);
    }
  }, [cookieDomain, cookieSecure]);

  // ?lang=en|id wins over the cookie and is stored like on rutestrip.ai.id
  useEffect(() => {
    const url = new URL(window.location.href);
    const fromQuery = url.searchParams.get('lang');
    if (fromQuery === 'id' || fromQuery === 'en') {
      setLang(fromQuery);
      url.searchParams.delete('lang');
      window.history.replaceState(null, '', url.pathname + url.search + url.hash);
    }
  }, [setLang]);

  /** Use the account's saved locale when the visitor never picked a language. */
  const applyUserLocale = useCallback((locale) => {
    if (!explicit && (locale === 'id' || locale === 'en')) setLang(locale, { persist: false });
  }, [explicit, setLang]);

  const value = useMemo(() => ({
    lang,
    setLang,
    applyUserLocale,
    tag: localeTag(lang),
    t: (key, vars) => translate(lang, key, vars),
  }), [lang, setLang, applyUserLocale]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n() {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error('useI18n must be used inside <I18nProvider>');
  return ctx;
}

/** Two-button ID / EN switch. */
export function LanguageSwitch({ className = '', showLabel = true }) {
  const { lang, setLang, t } = useI18n();
  return (
    <div className={`lang-switch${className ? ` ${className}` : ''}`} role="group" aria-label={t('lang.label')}>
      {showLabel && (
        <span className="lang-switch-label">
          <Icon name="translate" size={14} />
          <span>{t('lang.label')}</span>
        </span>
      )}
      <span className="lang-switch-options">
        {['id', 'en'].map((code) => (
          <button
            key={code}
            type="button"
            className={`lang-switch-btn${lang === code ? ' active' : ''}`}
            aria-pressed={lang === code}
            onClick={() => setLang(code)}
            title={t(`lang.${code}`)}
            lang={code}
          >
            {code.toUpperCase()}
          </button>
        ))}
      </span>
    </div>
  );
}
