'use client';

import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';

export const TELEGRAM_CHANNEL_URL = 'https://t.me/rutestrip';
export const TELEGRAM_BOT_URL = 'https://t.me/rutestrip_hikingbot';

const LINKS = [
  { href: TELEGRAM_CHANNEL_URL, icon: 'megaphone', label: 'Channel', sub: '@rutestrip' },
  { href: TELEGRAM_BOT_URL, icon: 'bot', label: 'Bot', sub: '@rutestrip_hikingbot' },
];

/*
 * Floating Telegram button. Rendered inside the chat footer and positioned
 * just above it, so it never covers the composer or the send button.
 */
export default function TelegramFab() {
  const [open, setOpen] = useState(false);
  const wrapRef = useRef(null);

  useEffect(() => {
    if (!open) return;
    const onPointer = (e) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('pointerdown', onPointer);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('pointerdown', onPointer);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div className={`tg-fab print-hide${open ? ' open' : ''}`} ref={wrapRef}>
      <ul id="tg-fab-menu" className="tg-fab-menu" aria-label="Telegram RuteStrip" hidden={!open}>
        {LINKS.map((l) => (
          <li key={l.href}>
            <a
              href={l.href}
              target="_blank"
              rel="noopener noreferrer"
              className="tg-fab-item"
              onClick={() => setOpen(false)}
              aria-label={`${l.label} Telegram ${l.sub}`}
            >
              <span className="tg-fab-label">
                <strong>{l.label}</strong>
                <span>{l.sub}</span>
              </span>
              <span className="tg-fab-item-icon" aria-hidden="true">
                <Icon name={l.icon} size={18} />
              </span>
            </a>
          </li>
        ))}
      </ul>

      <button
        type="button"
        className="tg-fab-btn"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        aria-controls="tg-fab-menu"
        aria-label={open ? 'Tutup menu Telegram' : 'Telegram RuteStrip: channel dan bot'}
        title="Telegram RuteStrip"
      >
        {open ? <Icon name="x" size={20} strokeWidth={2} /> : <Icon name="telegram-plane" size={26} />}
      </button>
    </div>
  );
}
