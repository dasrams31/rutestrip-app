'use client';

import { useState, useEffect, useCallback } from 'react';
import Link from 'next/link';

export default function SystemDashboardPage() {
  const [statusData, setStatusData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [lastUpdated, setLastUpdated] = useState('');
  const [error, setError] = useState(null);

  const fetchStatus = useCallback(async (isManual = false) => {
    if (isManual) setRefreshing(true);
    try {
      const res = await fetch('/api/proxy/status', { cache: 'no-store' });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      setStatusData(data);
      setError(null);
      setLastUpdated(new Date().toLocaleTimeString('id-ID'));
    } catch (err) {
      console.error('Fetch status error:', err);
      setError('Gagal memperbarui status sistem VPS');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    fetchStatus();
    if (!autoRefresh) return;
    const interval = setInterval(() => {
      fetchStatus();
    }, 4000);
    return () => clearInterval(interval);
  }, [autoRefresh, fetchStatus]);

  const vps = statusData?.vps || {
    cpu_percent: 0,
    memory_percent: 0,
    memory_used_mb: 0,
    memory_total_mb: 0,
    disk_percent: 0,
    disk_used_gb: 0,
    disk_total_gb: 0,
    uptime_hours: 0,
  };

  const services = statusData?.services || {
    fastapi_api: 'OFFLINE',
    rutestrip_bot: 'OFFLINE',
    hermes_gateway: 'OFFLINE',
    router_9router: 'OFFLINE',
    website_rutestrip: 'ONLINE',
  };

  const botStats = statusData?.bot_stats || {
    total_subscribers: 0,
    gpx_database_count: 55,
    active_cronjobs: 6,
  };

  const aiTokens = statusData?.ai_tokens || {
    total_tokens_registered: 0,
    total_ai_requests: 0,
    active_providers: [],
  };

  const isAllOnline =
    services.fastapi_api === 'ONLINE' &&
    services.rutestrip_bot === 'ONLINE' &&
    services.hermes_gateway === 'ONLINE';

  return (
    <div className="sys-container">
      {/* ── Navbar ── */}
      <header className="sys-header">
        <Link href="/chat" className="sys-brand">
          <img
            src="/logo.svg"
            alt="RuteStrip AI Logo"
            width={36}
            height={36}
            style={{ width: '36px', height: '36px', borderRadius: '10px', objectFit: 'cover' }}
          />
          <div style={{ display: 'flex', alignItems: 'center' }}>
            <span className="sys-title-gradient">RuteStrip</span>
            <span className="sys-tag">System Monitor</span>
          </div>
        </Link>

        {/* Action Controls */}
        <div className="sys-controls">
          <button
            onClick={() => setAutoRefresh(!autoRefresh)}
            className={`sys-btn-auto${autoRefresh ? ' active' : ''}`}
          >
            <span className={autoRefresh ? 'sys-dot-pulse' : ''} style={{ display: 'inline-block' }} />
            <span>Auto Refresh: {autoRefresh ? '3s' : 'Off'}</span>
          </button>

          <button
            onClick={() => fetchStatus(true)}
            disabled={refreshing}
            className="sys-btn-icon"
            title="Segarkan data sekarang"
          >
            <svg
              style={{ width: '16px', height: '16px' }}
              className={refreshing ? 'spin-anim' : ''}
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
              />
            </svg>
          </button>

          <Link href="/chat" className="sys-btn-chat">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1rem', height: '1rem' }}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
            <span>Chat Assistant</span>
          </Link>
        </div>
      </header>

      {/* ── Main Body ── */}
      <main className="sys-main">
        {/* Banner Summary Header */}
        <div className="sys-banner">
          <div>
            <div className="sys-status-pill">
              <span
                style={{
                  width: '8px',
                  height: '8px',
                  borderRadius: '50%',
                  backgroundColor: isAllOnline ? '#34d399' : '#f59e0b',
                  boxShadow: isAllOnline ? '0 0 8px #34d399' : '0 0 8px #f59e0b',
                }}
              />
              <span>{isAllOnline ? 'All Systems Operational' : 'Degraded System Performance'}</span>
            </div>
            <h1 className="sys-banner-title">RuteStrip Monitoring & AI Engine Control (v1.7.0)</h1>
            <p className="sys-banner-sub">
              Real-time telemetri hardware VPS, Hermes LLM Gateway, 9Router AI Token Pool, serta status microservices REST API v1.7.0 RuteStrip.
            </p>
          </div>

          <div className="sys-meta-box">
            <div>
              <div style={{ fontSize: '0.6875rem', color: '#94a3b8' }}>API Version & Build</div>
              <div style={{ fontSize: '0.875rem', fontWeight: 700, color: '#34d399' }}>
                v1.7.0 Stable (FastAPI + SBERT)
              </div>
            </div>
            <div style={{ width: '1px', height: '24px', backgroundColor: '#334155' }} />
            <div>
              <div style={{ fontSize: '0.6875rem', color: '#94a3b8' }}>Terakhir Diperbarui</div>
              <div style={{ fontSize: '0.875rem', fontWeight: 700, fontFamily: 'monospace', color: '#34d399' }}>
                {lastUpdated || 'Memuat...'}
              </div>
            </div>
          </div>
        </div>

        {error && (
          <div
            style={{
              background: 'rgba(120, 53, 15, 0.4)',
              border: '1px solid rgba(245, 158, 11, 0.4)',
              color: '#fde047',
              padding: '0.875rem 1.25rem',
              borderRadius: '0.75rem',
              fontSize: '0.875rem',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
            }}
          >
            <span>⚠️ {error}</span>
            <button
              onClick={() => fetchStatus(true)}
              style={{ color: '#fde047', textDecoration: 'underline', background: 'none', border: 'none', cursor: 'pointer' }}
            >
              Coba lagi
            </button>
          </div>
        )}

        {/* ── Section 1: VPS Resource Metrics (4 Cards) ── */}
        <section className="sys-grid-4">
          {/* CPU Card */}
          <div className="sys-card">
            <div className="sys-card-header">
              <span className="sys-card-label">CPU Load</span>
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem', color: '#34d399' }}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m16-6h2m-2 6h2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
              </svg>
            </div>
            <div>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between' }}>
                <span className="sys-card-val">{vps.cpu_percent}%</span>
                <span
                  className={
                    vps.cpu_percent > 85
                      ? 'sys-badge-offline'
                      : vps.cpu_percent > 60
                      ? 'sys-badge-warning'
                      : 'sys-badge-online'
                  }
                >
                  {vps.cpu_percent > 85 ? 'HIGH' : vps.cpu_percent > 60 ? 'ELEVATED' : 'NORMAL'}
                </span>
              </div>
              <div className="sys-bar-track">
                <div
                  className={
                    vps.cpu_percent > 85
                      ? 'sys-bar-fill-red'
                      : vps.cpu_percent > 60
                      ? 'sys-bar-fill-amber'
                      : 'sys-bar-fill-emerald'
                  }
                  style={{ width: `${Math.min(100, Math.max(5, vps.cpu_percent))}%` }}
                />
              </div>
            </div>
          </div>

          {/* RAM Card */}
          <div className="sys-card">
            <div className="sys-card-header">
              <span className="sys-card-label">RAM Usage</span>
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem', color: '#38bdf8' }}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
            </div>
            <div>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between' }}>
                <span className="sys-card-val">{vps.memory_percent}%</span>
                <span style={{ fontSize: '0.75rem', color: '#94a3b8', fontFamily: 'monospace' }}>
                  {vps.memory_used_mb} / {vps.memory_total_mb} MB
                </span>
              </div>
              <div className="sys-bar-track">
                <div
                  className="sys-bar-fill-cyan"
                  style={{ width: `${Math.min(100, Math.max(5, vps.memory_percent))}%` }}
                />
              </div>
            </div>
          </div>

          {/* Storage Card */}
          <div className="sys-card">
            <div className="sys-card-header">
              <span className="sys-card-label">Disk Storage</span>
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem', color: '#a855f7' }}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
              </svg>
            </div>
            <div>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between' }}>
                <span className="sys-card-val">{vps.disk_percent}%</span>
                <span style={{ fontSize: '0.75rem', color: '#94a3b8', fontFamily: 'monospace' }}>
                  {vps.disk_used_gb} / {vps.disk_total_gb} GB
                </span>
              </div>
              <div className="sys-bar-track">
                <div
                  className="sys-bar-fill-indigo"
                  style={{ width: `${Math.min(100, Math.max(5, vps.disk_percent))}%` }}
                />
              </div>
            </div>
          </div>

          {/* VPS Uptime Card */}
          <div className="sys-card">
            <div className="sys-card-header">
              <span className="sys-card-label">VPS Uptime</span>
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem', color: '#34d399' }}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <div>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between' }}>
                <span className="sys-card-val" style={{ color: '#34d399' }}>
                  {vps.uptime_hours} <span style={{ fontSize: '1rem', color: '#94a3b8', fontWeight: 400 }}>Jam</span>
                </span>
                <span className="sys-badge-online">ONLINE</span>
              </div>
              <div style={{ fontSize: '0.6875rem', color: '#94a3b8', fontFamily: 'monospace', marginTop: '0.25rem' }}>
                Ubuntu Linux Server
              </div>
            </div>
          </div>
        </section>

        {/* ── Section 2: Core Microservices Status Grid ── */}
        <section style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <h2 style={{ fontSize: '1.125rem', fontWeight: 700, color: '#ffffff', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              <span style={{ width: '10px', height: '10px', borderRadius: '50%', backgroundColor: '#34d399', display: 'inline-block' }} />
              Microservices & Engine Status
            </h2>
            <span style={{ fontSize: '0.75rem', color: '#94a3b8' }}>5 Services Active</span>
          </div>

          <div className="sys-grid-3">
            {/* FastAPI Service */}
            <div className="sys-card">
              <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                  <div style={{ padding: '0.625rem', borderRadius: '0.75rem', background: 'rgba(19, 78, 74, 0.6)', color: '#2dd4bf', border: '1px solid rgba(45, 212, 191, 0.3)' }}>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                  </div>
                  <div>
                    <h3 style={{ fontSize: '0.9375rem', fontWeight: 700, color: '#ffffff', margin: 0 }}>FastAPI REST Engine v1.7.0</h3>
                    <p style={{ fontSize: '0.75rem', color: '#94a3b8', margin: 0 }}>Backend API, SBERT & Multi-Session</p>
                  </div>
                </div>
                <span className={services.fastapi_api === 'ONLINE' ? 'sys-badge-online' : 'sys-badge-offline'}>
                  {services.fastapi_api}
                </span>
              </div>
              <div style={{ marginTop: '1rem', paddingTop: '0.75rem', borderTop: '1px solid rgba(51, 65, 85, 0.5)', display: 'flex', justifyContent: 'space-between', fontSize: '0.75rem', color: '#94a3b8' }}>
                <span>Port: <code style={{ color: '#2dd4bf' }}>8000</code></span>
                <div style={{ display: 'flex', gap: '0.75rem' }}>
                  <a href="http://127.0.0.1:8010/docs" target="_blank" rel="noopener noreferrer" style={{ color: '#34d399', textDecoration: 'none', fontWeight: 600 }}>
                    Swagger ↗
                  </a>
                  <a href="http://127.0.0.1:8010/redoc" target="_blank" rel="noopener noreferrer" style={{ color: '#38bdf8', textDecoration: 'none' }}>
                    ReDoc ↗
                  </a>
                </div>
              </div>
            </div>

            {/* Hermes LLM Gateway */}
            <div className="sys-card">
              <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                  <div style={{ padding: '0.625rem', borderRadius: '0.75rem', background: 'rgba(6, 95, 70, 0.6)', color: '#34d399', border: '1px solid rgba(52, 211, 153, 0.3)' }}>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m16-6h2m-2 6h2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                    </svg>
                  </div>
                  <div>
                    <h3 style={{ fontSize: '0.9375rem', fontWeight: 700, color: '#ffffff', margin: 0 }}>Hermes LLM Gateway</h3>
                    <p style={{ fontSize: '0.75rem', color: '#94a3b8', margin: 0 }}>Hermes-First LLM Engine</p>
                  </div>
                </div>
                <span className={services.hermes_gateway === 'ONLINE' ? 'sys-badge-online' : 'sys-badge-offline'}>
                  {services.hermes_gateway}
                </span>
              </div>
              <div style={{ marginTop: '1rem', paddingTop: '0.75rem', borderTop: '1px solid rgba(51, 65, 85, 0.5)', display: 'flex', justifyContent: 'space-between', fontSize: '0.75rem', color: '#94a3b8' }}>
                <span>Port: <code style={{ color: '#34d399' }}>20128</code></span>
                <span>OpenAI Spec</span>
              </div>
            </div>

            {/* 9Router Multi-Account Pool */}
            <div className="sys-card">
              <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                  <div style={{ padding: '0.625rem', borderRadius: '0.75rem', background: 'rgba(88, 28, 135, 0.6)', color: '#c084fc', border: '1px solid rgba(192, 132, 252, 0.3)' }}>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                    </svg>
                  </div>
                  <div>
                    <h3 style={{ fontSize: '0.9375rem', fontWeight: 700, color: '#ffffff', margin: 0 }}>9Router AI Balancer</h3>
                    <p style={{ fontSize: '0.75rem', color: '#94a3b8', margin: 0 }}>Token Multi-Account Pool</p>
                  </div>
                </div>
                <span className={services.router_9router === 'ONLINE' ? 'sys-badge-online' : 'sys-badge-offline'}>
                  {services.router_9router}
                </span>
              </div>
              <div style={{ marginTop: '1rem', paddingTop: '0.75rem', borderTop: '1px solid rgba(51, 65, 85, 0.5)', display: 'flex', justifyContent: 'space-between', fontSize: '0.75rem', color: '#94a3b8' }}>
                <span>Active Accounts: <code style={{ color: '#c084fc' }}>{aiTokens.active_providers?.length || 0}</code></span>
                <span>Auto Rotator</span>
              </div>
            </div>

            {/* Telegram Pendakian Bot */}
            <div className="sys-card">
              <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                  <div style={{ padding: '0.625rem', borderRadius: '0.75rem', background: 'rgba(30, 58, 138, 0.6)', color: '#60a5fa', border: '1px solid rgba(96, 165, 250, 0.3)' }}>
                    <svg fill="currentColor" viewBox="0 0 24 24" style={{ width: '1.25rem', height: '1.25rem' }}>
                      <path d="M12 0C5.37 0 0 5.37 0 12s5.37 12 12 12 12-5.37 12-12S18.63 0 12 0zm5.56 8.16l-2.13 10.02c-.16.73-.59.91-1.2.57l-3.26-2.4-1.57 1.51c-.17.17-.32.32-.66.32l.23-3.32 6.04-5.46c.26-.23-.06-.36-.4-.14l-7.47 4.7-3.22-1.01c-.7-.22-.71-.7.15-1.04l12.59-4.85c.58-.22 1.09.13.9 1.1z"/>
                    </svg>
                  </div>
                  <div>
                    <h3 style={{ fontSize: '0.9375rem', fontWeight: 700, color: '#ffffff', margin: 0 }}>Telegram Pendakian Bot</h3>
                    <p style={{ fontSize: '0.75rem', color: '#94a3b8', margin: 0 }}>@rutestrip_bot Interface</p>
                  </div>
                </div>
                <span className={services.rutestrip_bot === 'ONLINE' ? 'sys-badge-online' : 'sys-badge-offline'}>
                  {services.rutestrip_bot}
                </span>
              </div>
              <div style={{ marginTop: '1rem', paddingTop: '0.75rem', borderTop: '1px solid rgba(51, 65, 85, 0.5)', display: 'flex', justifyContent: 'space-between', fontSize: '0.75rem', color: '#94a3b8' }}>
                <span>Adapter: <code style={{ color: '#60a5fa' }}>Hermes TG</code></span>
                <a href="https://t.me/rutestrip_bot" target="_blank" rel="noopener noreferrer" style={{ color: '#60a5fa', textDecoration: 'none' }}>
                  Open Telegram ↗
                </a>
              </div>
            </div>

            {/* Web Assistant UI */}
            <div className="sys-card" style={{ gridColumn: 'span 2' }}>
              <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                  <div style={{ padding: '0.625rem', borderRadius: '0.75rem', background: 'rgba(6, 95, 70, 0.6)', color: '#34d399', border: '1px solid rgba(52, 211, 153, 0.3)' }}>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem' }}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                    </svg>
                  </div>
                  <div>
                    <h3 style={{ fontSize: '0.9375rem', fontWeight: 700, color: '#ffffff', margin: 0 }}>Web AI Chat Assistant</h3>
                    <p style={{ fontSize: '0.75rem', color: '#94a3b8', margin: 0 }}>https://chat.rutestrip.ai.id</p>
                  </div>
                </div>
                <span className="sys-badge-online">ONLINE</span>
              </div>
              <div style={{ marginTop: '1rem', paddingTop: '0.75rem', borderTop: '1px solid rgba(51, 65, 85, 0.5)', display: 'flex', justifyContent: 'space-between', fontSize: '0.75rem', color: '#94a3b8' }}>
                <span>Vercel Edge Platform</span>
                <Link href="/chat" style={{ color: '#34d399', textDecoration: 'none', fontWeight: 600 }}>
                  Buka Chat UI →
                </Link>
              </div>
            </div>
          </div>
        </section>

        {/* ── Section 3: AI Provider Tokens & 9Router Pool ── */}
        <section className="sys-card" style={{ gap: '1rem' }}>
          <div style={{ display: 'flex', flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '0.75rem', borderBottom: '1px solid rgba(51, 65, 85, 0.6)', paddingBottom: '0.75rem' }}>
            <div>
              <h2 style={{ fontSize: '1.125rem', fontWeight: 700, color: '#ffffff', display: 'flex', alignItems: 'center', gap: '0.5rem', margin: 0 }}>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem', color: '#c084fc' }}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                </svg>
                9Router AI Token Pool & Account Diagnostics
              </h2>
              <p style={{ fontSize: '0.75rem', color: '#94a3b8', margin: '0.25rem 0 0 0' }}>
                Rotasi otomatis multi-akun AI API (Antigravity & Gemini CLI) untuk respon instan tanpa rate limit.
              </p>
            </div>
            <div className="sys-meta-box" style={{ padding: '0.5rem 1rem' }}>
              <div>
                <div style={{ fontSize: '0.625rem', color: '#94a3b8', textTransform: 'uppercase' }}>Total Request AI</div>
                <div style={{ fontSize: '1rem', fontWeight: 800, color: '#c084fc', fontFamily: 'monospace' }}>
                  {aiTokens.total_ai_requests.toLocaleString()}
                </div>
              </div>
              <div style={{ width: '1px', height: '20px', backgroundColor: '#334155' }} />
              <div>
                <div style={{ fontSize: '0.625rem', color: '#94a3b8', textTransform: 'uppercase' }}>Akun Terdaftar</div>
                <div style={{ fontSize: '1rem', fontWeight: 800, color: '#34d399', fontFamily: 'monospace' }}>
                  {aiTokens.active_providers?.length || 0} Akun
                </div>
              </div>
            </div>
          </div>

          {/* Tokens Table */}
          <div className="sys-table-wrap">
            <table className="sys-table">
              <thead>
                <tr>
                  <th>Provider</th>
                  <th>Email Akun</th>
                  <th>Project ID</th>
                  <th>Status Akun</th>
                  <th>Diagnosa / Catatan</th>
                </tr>
              </thead>
              <tbody style={{ fontFamily: 'monospace' }}>
                {aiTokens.active_providers && aiTokens.active_providers.length > 0 ? (
                  aiTokens.active_providers.map((p, idx) => {
                    const isError = p.status?.includes('ERROR') || p.status?.includes('WARNING');
                    const isInactive = p.status === 'INACTIVE';
                    return (
                      <tr key={p.id || idx}>
                        <td>
                          <span style={{ padding: '0.2rem 0.5rem', borderRadius: '0.25rem', background: '#1e293b', border: '1px solid #334155', color: '#f1f5f9', fontWeight: 600 }}>
                            {p.provider}
                          </span>
                        </td>
                        <td style={{ fontFamily: 'sans-serif', fontWeight: 600, color: '#ffffff' }}>{p.email}</td>
                        <td style={{ color: '#94a3b8' }}>
                          <code>{p.project_id || '-'}</code>
                        </td>
                        <td>
                          <span className={isError ? 'sys-badge-warning' : isInactive ? 'sys-badge-offline' : 'sys-badge-online'}>
                            {p.status}
                          </span>
                        </td>
                        <td style={{ fontFamily: 'sans-serif' }}>
                          {p.last_error ? (
                            <span style={{ color: '#fbbf24', fontFamily: 'monospace', fontSize: '0.6875rem' }}>
                              ⚠️ {p.last_error}
                            </span>
                          ) : (
                            <span style={{ color: '#34d399', fontSize: '0.6875rem' }}>
                              ✓ Normal / Operational
                            </span>
                          )}
                        </td>
                      </tr>
                    );
                  })
                ) : (
                  <tr>
                    <td colSpan={5} style={{ textAlign: 'center', padding: '1.5rem', color: '#64748b' }}>
                      Tidak ada akun provider terdeteksi.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>

        {/* ── Section 4: Bot Community Stats & Endpoints ── */}
        <section className="sys-grid-2-1">
          {/* Stats Overview */}
          <div className="sys-card" style={{ gap: '1rem' }}>
            <h2 style={{ fontSize: '1rem', fontWeight: 700, color: '#ffffff', display: 'flex', alignItems: 'center', gap: '0.5rem', margin: 0 }}>
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem', color: '#2dd4bf' }}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
              </svg>
              Community & Database Stats
            </h2>

            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '0.75rem', borderRadius: '0.75rem', background: 'rgba(2, 6, 23, 0.6)', border: '1px solid #1e293b' }}>
                <span style={{ fontSize: '0.75rem', color: '#94a3b8' }}>Total Chat Users / Subscribers</span>
                <span style={{ fontSize: '0.875rem', fontWeight: 800, color: '#34d399', fontFamily: 'monospace' }}>
                  {botStats.total_subscribers} Users
                </span>
              </div>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '0.75rem', borderRadius: '0.75rem', background: 'rgba(2, 6, 23, 0.6)', border: '1px solid #1e293b' }}>
                <span style={{ fontSize: '0.75rem', color: '#94a3b8' }}>Database Jalur GPX</span>
                <span style={{ fontSize: '0.875rem', fontWeight: 800, color: '#2dd4bf', fontFamily: 'monospace' }}>
                  {botStats.gpx_database_count} Gunung
                </span>
              </div>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '0.75rem', borderRadius: '0.75rem', background: 'rgba(2, 6, 23, 0.6)', border: '1px solid #1e293b' }}>
                <span style={{ fontSize: '0.75rem', color: '#94a3b8' }}>Active Background Cronjobs</span>
                <span style={{ fontSize: '0.875rem', fontWeight: 800, color: '#38bdf8', fontFamily: 'monospace' }}>
                  {botStats.active_cronjobs} Active Jobs
                </span>
              </div>
            </div>
          </div>

          {/* Endpoints Directory Table */}
          <div className="sys-card" style={{ gap: '0.75rem' }}>
            <h2 style={{ fontSize: '1rem', fontWeight: 700, color: '#ffffff', display: 'flex', alignItems: 'center', gap: '0.5rem', margin: 0 }}>
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.25rem', height: '1.25rem', color: '#34d399' }}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
              </svg>
              Active System Endpoints
            </h2>

            <div className="sys-table-wrap">
              <table className="sys-table">
                <thead>
                  <tr>
                    <th>Service Name</th>
                    <th>Public Endpoint URL</th>
                    <th>Port</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody style={{ fontFamily: 'monospace' }}>
                  <tr>
                    <td style={{ fontFamily: 'sans-serif', fontWeight: 600, color: '#ffffff' }}>Monitoring Dashboard</td>
                    <td style={{ color: '#34d399' }}>
                      <Link href="/system" style={{ color: '#34d399', textDecoration: 'none' }}>https://chat.rutestrip.ai.id/system</Link>
                    </td>
                    <td>9000</td>
                    <td>
                      <span className="sys-badge-online">ACTIVE</span>
                    </td>
                  </tr>
                  <tr>
                    <td style={{ fontFamily: 'sans-serif', fontWeight: 600, color: '#ffffff' }}>Interactive Swagger UI (v1.7.0)</td>
                    <td style={{ color: '#2dd4bf' }}>
                      <a href="http://127.0.0.1:8010/docs" target="_blank" rel="noreferrer" style={{ color: '#2dd4bf', textDecoration: 'none' }}>http://127.0.0.1:8010/docs</a>
                    </td>
                    <td>8000</td>
                    <td>
                      <span className="sys-badge-online">ACTIVE</span>
                    </td>
                  </tr>
                  <tr>
                    <td style={{ fontFamily: 'sans-serif', fontWeight: 600, color: '#ffffff' }}>ReDoc API Documentation</td>
                    <td style={{ color: '#38bdf8' }}>
                      <a href="http://127.0.0.1:8010/redoc" target="_blank" rel="noreferrer" style={{ color: '#38bdf8', textDecoration: 'none' }}>http://127.0.0.1:8010/redoc</a>
                    </td>
                    <td>8000</td>
                    <td>
                      <span className="sys-badge-online">ACTIVE</span>
                    </td>
                  </tr>
                  <tr>
                    <td style={{ fontFamily: 'sans-serif', fontWeight: 600, color: '#ffffff' }}>OpenAPI Raw Specification</td>
                    <td style={{ color: '#c084fc' }}>
                      <a href="http://127.0.0.1:8010/openapi.json" target="_blank" rel="noreferrer" style={{ color: '#c084fc', textDecoration: 'none' }}>http://127.0.0.1:8010/openapi.json</a>
                    </td>
                    <td>8000</td>
                    <td>
                      <span className="sys-badge-online">ACTIVE</span>
                    </td>
                  </tr>
                  <tr>
                    <td style={{ fontFamily: 'sans-serif', fontWeight: 600, color: '#ffffff' }}>GitLab API Documentation</td>
                    <td style={{ color: '#f43f5e' }}>
                      <a href="https://gitlab.com/RamsNotes31/rutestrip-bot/-/blob/main/API_DOCUMENTATION.md" target="_blank" rel="noreferrer" style={{ color: '#f43f5e', textDecoration: 'none' }}>GitLab API Docs v1.7.0 ↗</a>
                    </td>
                    <td>443</td>
                    <td>
                      <span className="sys-badge-online">VERIFIED</span>
                    </td>
                  </tr>
                  <tr>
                    <td style={{ fontFamily: 'sans-serif', fontWeight: 600, color: '#ffffff' }}>AI Web Assistant UI</td>
                    <td style={{ color: '#34d399' }}>
                      <Link href="/chat" style={{ color: '#34d399', textDecoration: 'none' }}>https://chat.rutestrip.ai.id</Link>
                    </td>
                    <td>443</td>
                    <td>
                      <span className="sys-badge-online">ACTIVE</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      </main>

      {/* ── Footer ── */}
      <footer style={{ borderTop: '1px solid rgba(51, 65, 85, 0.6)', backgroundColor: '#020617', padding: '1rem', textAlign: 'center', fontSize: '0.75rem', color: '#64748b', marginTop: 'auto' }}>
        <p>© 2026 RuteStrip AI Engine — Realtime Monitoring System & Multi-Account AI Rotator.</p>
      </footer>
    </div>
  );
}
