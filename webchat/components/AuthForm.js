'use client';

import { useEffect, useRef, useState } from 'react';
import Image from 'next/image';
import { loadAltcha } from '@/lib/altcha';

export default function AuthForm({ onAuthSuccess }) {
  const [mode, setMode] = useState('login'); // 'login' | 'register'
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [successMsg, setSuccessMsg] = useState('');

  // Login Form state
  const [loginUser, setLoginUser] = useState('');
  const [loginPass, setLoginPass] = useState('');

  // Register Form state
  const [regFullName, setRegFullName] = useState('');
  const [regUsername, setRegUsername] = useState('');
  const [regEmail, setRegEmail] = useState('');
  const [regPass, setRegPass] = useState('');

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
    loadAltcha().catch(() => setError('Gagal memuat verifikasi anti-robot. Muat ulang halaman.'));
    return () => {
      widget?.removeEventListener('statechange', onStateChange);
      setAltchaPayload('');
    };
  }, [mode]);

  const handleLogin = async (e) => {
    e.preventDefault();
    if (!loginUser.trim() || !loginPass.trim()) {
      setError('Mohon isi username/email dan password.');
      return;
    }

    setLoading(true);
    setError('');
    setSuccessMsg('');

    try {
      const res = await fetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          username_or_email: loginUser.trim(),
          password: loginPass,
        }),
      });

      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.error || data.message || 'Login gagal. Cek kembali akun Anda.');
      }

      if (data.status === 'success' && data.user) {
        const token = data.user.token;
        localStorage.setItem('rutestrip_auth_token', token);
        localStorage.setItem('user_token', token);
        localStorage.setItem('token', token);
        localStorage.setItem('rutestrip_user_profile', JSON.stringify(data.user));
        setSuccessMsg('Login berhasil! Mengalihkan ke WebChat AI...');
        setTimeout(() => {
          onAuthSuccess(data.user);
        }, 500);
      } else {
        throw new Error('Respon login dari server tidak valid.');
      }
    } catch (err) {
      setError(err.message || 'Terjadi kesalahan saat login.');
    } finally {
      setLoading(false);
    }
  };

  const handleRegister = async (e) => {
    e.preventDefault();
    if (!regUsername.trim() || !regEmail.trim() || !regPass.trim()) {
      setError('Mohon lengkapi Username, Email, dan Password.');
      return;
    }
    if (!altchaPayload) {
      setError('Centang "Saya bukan robot" dan tunggu hingga terverifikasi.');
      altchaRef.current?.verify?.();
      return;
    }

    setLoading(true);
    setError('');
    setSuccessMsg('');

    try {
      const res = await fetch('/api/auth/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          username: regUsername.trim(),
          email: regEmail.trim(),
          password: regPass,
          full_name: regFullName.trim() || regUsername.trim(),
          altcha: altchaPayload,
          website: honeypot,
        }),
      });

      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.error || data.message || 'Registrasi gagal. Username atau Email mungkin sudah terdaftar.');
      }

      if (data.status === 'success' && data.user) {
        const token = data.user.token;
        localStorage.setItem('rutestrip_auth_token', token);
        localStorage.setItem('user_token', token);
        localStorage.setItem('token', token);
        localStorage.setItem('rutestrip_user_profile', JSON.stringify(data.user));
        setSuccessMsg('Registrasi berhasil! Selamat datang di RuteStrip AI...');
        setTimeout(() => {
          onAuthSuccess(data.user);
        }, 500);
      } else {
        throw new Error('Respon registrasi dari server tidak valid.');
      }
    } catch (err) {
      setError(err.message || 'Terjadi kesalahan saat registrasi.');
      // A payload is single-use: solve a fresh challenge for the next attempt.
      setAltchaPayload('');
      altchaRef.current?.reset?.();
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="auth-overlay">
      <div className="auth-card shadow-2xl">
        {/* Brand Header */}
        <div className="auth-brand">
          <div className="auth-logo-wrap">
            <img
              src="/logo.svg"
              alt="RuteStrip AI Logo"
              width={48}
              height={48}
              style={{ width: '48px', height: '48px', borderRadius: '12px', objectFit: 'cover' }}
            />
          </div>
          <h1 className="auth-title">RuteStrip AI</h1>
          <p className="auth-subtitle">Asisten Pintar Pendakian Gunung Indonesia</p>
        </div>

        {/* Tab Switcher */}
        <div className="auth-tabs">
          <button
            type="button"
            className={`auth-tab-btn ${mode === 'login' ? 'active' : ''}`}
            onClick={() => {
              setMode('login');
              setError('');
              setSuccessMsg('');
            }}
            style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '0.375rem' }}
          >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1rem', height: '1rem' }}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
            </svg>
            <span>Login Akun</span>
          </button>
          <button
            type="button"
            className={`auth-tab-btn ${mode === 'register' ? 'active' : ''}`}
            onClick={() => {
              setMode('register');
              setError('');
              setSuccessMsg('');
            }}
            style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '0.375rem' }}
          >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1rem', height: '1rem' }}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
            </svg>
            <span>Register Baru</span>
          </button>
        </div>

        {/* Alert Error / Success */}
        {error && (
          <div className="auth-alert alert-error" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.125rem', height: '1.125rem', flexShrink: 0 }}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>{error}</span>
          </div>
        )}
        {successMsg && (
          <div className="auth-alert alert-success" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1.125rem', height: '1.125rem', flexShrink: 0 }}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{successMsg}</span>
          </div>
        )}

        {/* Login Form */}
        {mode === 'login' ? (
          <form onSubmit={handleLogin} className="auth-form-body">
            <div className="auth-field">
              <label htmlFor="loginUser">Username atau Email</label>
              <input
                id="loginUser"
                type="text"
                placeholder="Masukkan username / email Anda"
                value={loginUser}
                onChange={(e) => setLoginUser(e.target.value)}
                required
              />
            </div>

            <div className="auth-field">
              <label htmlFor="loginPass">Password</label>
              <input
                id="loginPass"
                type="password"
                placeholder="Masukkan password Anda"
                value={loginPass}
                onChange={(e) => setLoginPass(e.target.value)}
                required
              />
            </div>

            <button type="submit" disabled={loading} className="auth-submit-btn" style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '0.5rem' }}>
              <span>{loading ? 'Memproses Login...' : 'Masuk ke RuteStrip AI'}</span>
              {!loading && (
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1rem', height: '1rem' }}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
              )}
            </button>
          </form>
        ) : (
          /* Register Form */
          <form onSubmit={handleRegister} className="auth-form-body">
            <div className="auth-field">
              <label htmlFor="regFullName">Nama Lengkap (Opsional)</label>
              <input
                id="regFullName"
                type="text"
                placeholder="Contoh: Pendaki Sejati"
                value={regFullName}
                onChange={(e) => setRegFullName(e.target.value)}
              />
            </div>

            <div className="auth-field">
              <label htmlFor="regUsername">Username</label>
              <input
                id="regUsername"
                type="text"
                placeholder="Contoh: pendaki123"
                value={regUsername}
                onChange={(e) => setRegUsername(e.target.value)}
                required
              />
            </div>

            <div className="auth-field">
              <label htmlFor="regEmail">Alamat Email</label>
              <input
                id="regEmail"
                type="email"
                placeholder="nama@email.com"
                value={regEmail}
                onChange={(e) => setRegEmail(e.target.value)}
                required
              />
            </div>

            <div className="auth-field">
              <label htmlFor="regPass">Password</label>
              <input
                id="regPass"
                type="password"
                placeholder="Minimal 6 karakter"
                value={regPass}
                onChange={(e) => setRegPass(e.target.value)}
                required
              />
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
              ref={altchaRef}
              className="auth-altcha"
              challenge="/api/auth/altcha"
              language="id"
              auto="onfocus"
            />

            <button type="submit" disabled={loading} className="auth-submit-btn" style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '0.5rem' }}>
              <span>{loading ? 'Mendaftarkan Akun...' : 'Daftar Akun Baru'}</span>
              {!loading && (
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '1rem', height: '1rem' }}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                </svg>
              )}
            </button>
          </form>
        )}

        <div className="auth-footer-note" style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '0.375rem' }}>
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2} style={{ width: '0.875rem', height: '0.875rem', color: '#10b981' }}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
          </svg>
          <span>Sesi Anda diautentikasi aman melalui API RuteStrip Server.</span>
        </div>
      </div>
    </div>
  );
}
