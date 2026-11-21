'use client';

import { useState, useEffect, useCallback, useRef } from 'react';
import Link from 'next/link';
import Icon from '@/components/Icon';
import { LanguageSwitch, useI18n } from '@/components/I18nProvider';
import { MAIN_SITE_URL } from '@/lib/i18n';

/*
 * Status page. /api/proxy/status only returns measured values:
 * services.fastapi_api (webchat-api), services.ai_engine (the engine that writes
 * chat answers: Gemini, or Hermes when configured) and timestamp.
 */

const INTERVAL_S = 30;
const STALE_MS = INTERVAL_S * 2 * 1000;
const TIMEOUT_MS = 12000;
const TELEGRAM_URL = 'https://t.me/rutestrip_hikingbot';

/* Topographic contour rings (same recipe as the main site's rs-contours partial). */
function contourRings(seed, peaks) {
  const rand = (start) => {
    let s = start & 0x7fffffff;
    return () => {
      s = (Math.imul(s, 1103515245) + 12345) & 0x7fffffff;
      return s / 0x7fffffff;
    };
  };
  const smooth = (p) => {
    const n = p.length;
    const at = (i) => p[((i % n) + n) % n];
    const r = Math.round;
    let d = `M${r(p[0][0])} ${r(p[0][1])}`;
    for (let i = 0; i < n; i++) {
      const [x0, y0] = at(i - 1); const [x1, y1] = at(i); const [x2, y2] = at(i + 1); const [x3, y3] = at(i + 2);
      d += `C${r(x1 + (x2 - x0) / 6)} ${r(y1 + (y2 - y0) / 6)} ${r(x2 - (x3 - x1) / 6)} ${r(y2 - (y3 - y1) / 6)} ${r(x2)} ${r(y2)}`;
    }
    return `${d}Z`;
  };
  const rings = [];
  peaks.forEach(([cx, cy, count], pi) => {
    const next = rand(seed * 31 + pi * 7);
    const harm = [2, 3, 5].map((h) => [h, 0.04 + next() * 0.06, next() * 2 * Math.PI, (next() - 0.5) * 0.3]);
    const tilt = next() * Math.PI;
    for (let k = 0; k < count; k++) {
      const radius = 18 + k * 30;
      const pts = [];
      for (let j = 0; j < 24; j++) {
        const a = (2 * Math.PI * j) / 24;
        let f = 1;
        harm.forEach(([h, amp, ph, dr]) => { f += amp * (1 + k * 0.05) * Math.sin(h * a + ph + k * dr); });
        const x = radius * f * Math.cos(a);
        const y = radius * f * Math.sin(a) * 0.7;
        pts.push([cx + x * Math.cos(tilt) - y * Math.sin(tilt), cy + x * Math.sin(tilt) + y * Math.cos(tilt)]);
      }
      rings.push({ d: smooth(pts), index: k % 5 === 4 });
    }
  });
  return rings;
}

const RINGS = contourRings(11, [[1040, 150, 12], [150, 470, 8]]);

function Contours() {
  return (
    <svg className="sys-topo" viewBox="0 0 1200 400" preserveAspectRatio="xMaxYMid slice" aria-hidden="true" focusable="false">
      <g fill="none" stroke="#a7f3d0" strokeLinejoin="round">
        {RINGS.map((ring, i) => (
          <path
            key={i}
            d={ring.d}
            strokeWidth={ring.index ? 2 : 1.2}
            strokeOpacity={ring.index ? 0.3 : 0.16}
            vectorEffect="non-scaling-stroke"
          />
        ))}
      </g>
    </svg>
  );
}

const rawState = (v) => (v === 'ONLINE' ? 'up' : v === 'OFFLINE' ? 'down' : 'unknown');

const STATE_ICON = { up: 'check-circle', down: 'warning', unknown: 'clock' };

export default function SystemStatusPage() {
  const { t, tag } = useI18n();
  const [snap, setSnap] = useState(null);   // last successful check
  const [fail, setFail] = useState(null);   // last failed check
  const [checking, setChecking] = useState(false);
  const [auto, setAuto] = useState(true);
  const [now, setNow] = useState(0);
  const [announce, setAnnounce] = useState('');
  const inFlight = useRef(false);
  const lastAttempt = useRef(0);
  const lastPhase = useRef('');

  const check = useCallback(async () => {
    if (inFlight.current) return;
    inFlight.current = true;
    setChecking(true);
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), TIMEOUT_MS);
    const started = performance.now();
    try {
      const res = await fetch('/api/proxy/status', { cache: 'no-store', signal: ctrl.signal });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      setSnap({ data, at: Date.now(), ms: Math.round(performance.now() - started), http: res.status });
      setFail(null);
    } catch (err) {
      setFail({ at: Date.now(), msg: err?.name === 'AbortError' ? `timeout ${TIMEOUT_MS / 1000} s` : (err?.message || 'network error') });
    } finally {
      clearTimeout(timer);
      inFlight.current = false;
      lastAttempt.current = Date.now();
      setChecking(false);
      setNow(Date.now());
    }
  }, []);

  // First check on load.
  useEffect(() => { check(); }, [check]);

  // Auto check every INTERVAL_S while the tab is visible; catch up when it becomes visible again.
  useEffect(() => {
    if (!auto) return undefined;
    const id = setInterval(() => { if (!document.hidden) check(); }, INTERVAL_S * 1000);
    const onVisible = () => {
      if (!document.hidden && Date.now() - lastAttempt.current >= INTERVAL_S * 1000) check();
    };
    document.addEventListener('visibilitychange', onVisible);
    return () => { clearInterval(id); document.removeEventListener('visibilitychange', onVisible); };
  }, [auto, check]);

  // Keeps "x detik lalu" honest.
  useEffect(() => {
    setNow(Date.now());
    const id = setInterval(() => setNow(Date.now()), 5000);
    return () => clearInterval(id);
  }, []);

  const toggleAuto = () => {
    const next = !auto;
    setAuto(next);
    if (next && Date.now() - lastAttempt.current >= INTERVAL_S * 1000) check();
  };

  /* ── Derive what to say ── */
  const services = snap?.data?.services;
  const lastFailed = Boolean(fail && (!snap || fail.at > snap.at));
  const firstRun = !snap && !fail;

  const rows = [
    { key: 'web', state: firstRun ? 'loading' : lastFailed ? 'down' : 'up', raw: snap && !lastFailed ? `HTTP ${snap.http}` : null },
    { key: 'data', state: firstRun ? 'loading' : lastFailed ? 'unknown' : rawState(services?.fastapi_api), raw: services?.fastapi_api, prev: rawState(services?.fastapi_api) },
    { key: 'ai', state: firstRun ? 'loading' : lastFailed ? 'unknown' : rawState(services?.ai_engine), raw: services?.ai_engine, prev: rawState(services?.ai_engine) },
  ];

  let phase = 'checking';
  let lead = 'sys.leadChecking';
  if (!firstRun) {
    if (lastFailed) {
      phase = 'down';
      lead = 'sys.leadDown';
    } else if (!services) {
      phase = 'issue';
      lead = 'sys.leadEmpty';
    } else {
      const dataUp = rows[1].state === 'up';
      const aiUp = rows[2].state === 'up';
      phase = dataUp && aiUp ? 'ok' : 'issue';
      lead = dataUp && aiUp ? 'sys.leadOk' : !dataUp && !aiUp ? 'sys.leadIssueBoth' : !dataUp ? 'sys.leadIssueData' : 'sys.leadIssueAi';
    }
  }

  const headKey = { checking: 'sys.headChecking', ok: 'sys.headOk', issue: 'sys.headIssue', down: 'sys.headDown' }[phase];
  const headIcon = { checking: 'refresh', ok: 'check-circle', issue: 'warning', down: 'x' }[phase];
  const upCount = rows.filter((r) => r.state === 'up').length;

  // Tell screen-reader users when the overall status changes (not on every refresh).
  useEffect(() => {
    if (phase === 'checking' || phase === lastPhase.current) return;
    lastPhase.current = phase;
    setAnnounce(t(headKey));
  }, [phase, headKey, t]);

  const fmtTime = (ms) => new Date(ms).toLocaleTimeString(tag, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const serverMs = snap?.data?.timestamp ? Date.parse(snap.data.timestamp) : NaN;
  const checkedAt = snap ? fmtTime(Number.isFinite(serverMs) ? serverMs : snap.at) : '';
  const ago = (ms) => {
    const s = Math.max(0, Math.round((now - ms) / 1000));
    if (s < 10) return t('sys.agoNow');
    if (s < 60) return t('sys.agoSec', { n: s });
    if (s < 3600) return t('sys.agoMin', { n: Math.floor(s / 60) });
    return t('sys.agoHour', { n: Math.floor(s / 3600) });
  };
  const stale = Boolean(snap && !lastFailed && now && now - snap.at > STALE_MS);

  const stateWord = (s) => t({ up: 'sys.stateUp', down: 'sys.stateDown', unknown: 'sys.stateUnknown' }[s]);

  return (
    <div className="sys-page">
      <header className="sys-top">
        <Link href="/chat" className="sys-brand" aria-label={t('sys.back')}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/logo.svg" alt="" width={32} height={32} />
          <span className="sys-brand-name">RuteStrip AI</span>
        </Link>
        <nav className="sys-top-nav" aria-label={t('sys.navAria')}>
          <Link href="/chat" className="sys-top-link">
            <Icon name="chat" size={18} />
            <span>{t('sys.navChat')}</span>
          </Link>
          <a href={MAIN_SITE_URL} className="sys-top-link sys-top-main" aria-label={t('header.mainSiteTitle')} title={t('header.mainSiteTitle')}>
            <Icon name="home" size={18} />
            <span className="sys-top-main-text">{t('header.mainSite')}</span>
          </a>
          <LanguageSwitch showLabel={false} className="sys-lang" />
        </nav>
      </header>

      <main className="sys-main" aria-busy={firstRun}>
        {/* Focal point: one sentence about the whole system. */}
        <section className="sys-band" aria-labelledby="sys-head">
          <Contours />
          <div className="sys-band-inner">
            <p className="sys-eyebrow">{t('sys.eyebrow')}</p>
            <div className="sys-head-row">
              <span className={`sys-head-mark is-${phase}`} aria-hidden="true">
                <Icon name={headIcon} size={28} strokeWidth={2.25} className={phase === 'checking' ? 'sys-spin' : ''} />
              </span>
              <h1 id="sys-head" className="sys-display sys-head">{t(headKey)}</h1>
            </div>
            <p className="sys-lead">{t(lead)}</p>
            {(phase === 'ok' || phase === 'issue') && services && (
              <p className="sys-count">{t('sys.count', { up: upCount, total: rows.length })}</p>
            )}
            <div className="sys-band-actions">
              {phase === 'down' ? (
                <>
                  <button type="button" className="sys-sign" onClick={check} disabled={checking}>
                    <Icon name="refresh" size={20} className={checking ? 'sys-spin' : ''} />
                    <span>{checking ? t('sys.retrying') : t('sys.retry')}</span>
                  </button>
                  <Link href="/chat" className="sys-ghost-dark">{t('sys.ctaChat')}</Link>
                </>
              ) : (
                <Link href="/chat" className="sys-sign">
                  <Icon name="chat" size={20} />
                  <span>{t('sys.ctaChat')}</span>
                </Link>
              )}
            </div>
          </div>
        </section>

        {/* When it was checked, and the controls for checking again. */}
        <div className="sys-strip">
          <p className="sys-checked">
            <Icon name="clock" size={18} />
            <span>
              {snap
                ? t(lastFailed ? 'sys.checkedAtLastOk' : 'sys.checkedAt', { time: checkedAt, ago: ago(snap.at) })
                : firstRun ? t('sys.checkingNow') : t('sys.neverOk')}
            </span>
          </p>
          <div className="sys-strip-actions">
            <button type="button" className="sys-btn-line" onClick={check} disabled={checking}>
              <Icon name="refresh" size={18} className={checking ? 'sys-spin' : ''} />
              <span>{checking ? t('sys.checking') : t('sys.checkNow')}</span>
            </button>
            <button type="button" className="sys-auto" aria-pressed={auto} onClick={toggleAuto}>
              <span className="sys-switch" aria-hidden="true"><span /></span>
              <span className="sys-auto-text">
                {t('sys.autoLabel', { n: INTERVAL_S })}
                <strong>{auto ? t('sys.autoOn') : t('sys.autoOff')}</strong>
              </span>
            </button>
          </div>
        </div>

        {(stale || (lastFailed && snap)) && (
          <p className="sys-note" role="status">
            <Icon name="warning" size={18} />
            <span>
              {lastFailed
                ? t('sys.noteFailed', { fail: fmtTime(fail.at), time: checkedAt })
                : t('sys.noteStale', { ago: ago(snap.at) })}
            </span>
          </p>
        )}

        <div className="sys-layout">
          <section className="sys-services" aria-labelledby="sys-services-title">
            <h2 id="sys-services-title" className="sys-display sys-h2">{t('sys.servicesTitle')}</h2>
            <ul className="sys-list">
              {rows.map((row) => (
                <li key={row.key} className={`sys-row is-${row.state}`}>
                  {row.state === 'loading' ? (
                    <div className="sys-skel" aria-hidden="true">
                      <span className="sys-skel-a" /><span className="sys-skel-b" />
                    </div>
                  ) : (
                    <>
                      <div className="sys-row-head">
                        <h3 className="sys-row-name">{t(`sys.svc.${row.key}.name`)}</h3>
                        <span className={`sys-chip is-${row.state}`}>
                          <Icon name={STATE_ICON[row.state]} size={16} strokeWidth={2} />
                          {stateWord(row.state)}
                        </span>
                      </div>
                      <p className="sys-row-desc">{t(`sys.svc.${row.key}.desc`)}</p>
                      {row.state === 'down' && <p className="sys-row-impact">{t(`sys.svc.${row.key}.impact`)}</p>}
                      {row.state === 'unknown' && lastFailed && (
                        <p className="sys-row-impact">
                          {snap && row.prev !== 'unknown'
                            ? t('sys.lastSeen', { state: stateWord(row.prev).toLowerCase(), time: checkedAt })
                            : t('sys.cannotCheck')}
                        </p>
                      )}
                      {row.state === 'unknown' && !lastFailed && <p className="sys-row-impact">{t('sys.noValue')}</p>}
                    </>
                  )}
                </li>
              ))}
            </ul>
            {firstRun && <p className="sys-sr">{t('sys.checkingNow')}</p>}

            <details className="sys-tech">
              <summary>
                <Icon name="chevron-right" size={18} className="sys-tech-chev" />
                <span>{t('sys.techTitle')}</span>
              </summary>
              <div className="sys-tech-body">
                {rows.map((row) => (
                  <div key={row.key} className="sys-tech-svc">
                    <p className="sys-tech-name">{t(`sys.svc.${row.key}.name`)}</p>
                    <dl className="sys-dl">
                      <div><dt>{t('sys.techPart')}</dt><dd>{t(`sys.tech.${row.key}.part`)}</dd></div>
                      <div><dt>{t('sys.techVia')}</dt><dd><code>{t(`sys.tech.${row.key}.via`)}</code></dd></div>
                      <div><dt>{t('sys.techRaw')}</dt><dd><code>{row.raw || t('sys.na')}</code></dd></div>
                    </dl>
                  </div>
                ))}
                <dl className="sys-dl sys-dl-meta">
                  <div>
                    <dt>{t('sys.techServerTime')}</dt>
                    <dd>{Number.isFinite(serverMs) ? new Date(serverMs).toLocaleString(tag) : t('sys.na')}</dd>
                  </div>
                  <div>
                    <dt>{t('sys.techDuration')}</dt>
                    <dd>{snap ? t('sys.techDurationVal', { ms: snap.ms }) : t('sys.na')}</dd>
                  </div>
                  <div>
                    <dt>{t('sys.techAuto')}</dt>
                    <dd>{t(auto ? 'sys.techAutoOn' : 'sys.techAutoOff', { n: INTERVAL_S })}</dd>
                  </div>
                  {fail && (
                    <div>
                      <dt>{t('sys.techError')}</dt>
                      <dd><code>{fail.msg}</code> ({fmtTime(fail.at)})</dd>
                    </div>
                  )}
                </dl>
                <p className="sys-tech-note">{t('sys.techNoteAi')}</p>
                <p className="sys-tech-note">{t('sys.techNoteScope')}</p>
              </div>
            </details>
          </section>

          <aside className="sys-tg" aria-labelledby="sys-tg-title">
            <div className="sys-tg-head">
              <span className="sys-tg-mark" aria-hidden="true"><Icon name="telegram" size={28} /></span>
              <div>
                <h2 id="sys-tg-title" className="sys-display sys-tg-title">{t('sys.tgTitle')}</h2>
                <p className="sys-tg-handle">@rutestrip_hikingbot</p>
              </div>
            </div>
            <p className="sys-tg-body">{t('sys.tgBody')}</p>
            <a href={TELEGRAM_URL} target="_blank" rel="noopener noreferrer" className="sys-btn-line sys-tg-link">
              <span>{t('sys.tgCta')}</span>
              <Icon name="external" size={18} />
              <span className="sys-sr">{t('sys.newTab')}</span>
            </a>
          </aside>
        </div>
      </main>

      <p className="sys-sr" aria-live="polite">{announce}</p>
    </div>
  );
}
