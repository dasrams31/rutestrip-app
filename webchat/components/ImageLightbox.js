'use client';

import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { useI18n } from './I18nProvider';

function extFromSrc(src) {
  const m = /^data:image\/([a-z0-9+.-]+)[;,]/i.exec(src || '');
  if (m) return m[1].toLowerCase().replace('jpeg', 'jpg').replace('svg+xml', 'svg');
  const path = (src || '').split(/[?#]/)[0];
  const dot = /\.([a-z0-9]{2,4})$/i.exec(path);
  return dot ? dot[1].toLowerCase() : 'png';
}

/*
 * In-app image viewer. Chat images are often data: URLs, which browsers
 * refuse to open as a top-level page, so they are shown here instead.
 */
export default function ImageLightbox({ image, onClose }) {
  const { t } = useI18n();
  const [zoomed, setZoomed] = useState(false);
  const closeRef = useRef(null);

  useEffect(() => {
    if (!image) return;
    const prevFocus = document.activeElement;
    closeRef.current?.focus();
    const onKey = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      if (prevFocus && typeof prevFocus.focus === 'function') prevFocus.focus();
    };
  }, [image, onClose]);

  if (!image) return null;

  const filename = `${image.name || 'rutestrip-gambar'}.${extFromSrc(image.src)}`;

  const download = async () => {
    try {
      // Decode base64 data URLs locally (no fetch, so a strict CSP can't block it)
      const b64 = /^data:([^;,]+);base64,(.*)$/s.exec(image.src);
      const blob = b64
        ? new Blob([Uint8Array.from(atob(b64[2]), (ch) => ch.charCodeAt(0))], { type: b64[1] })
        : await (await fetch(image.src)).blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch {
      // Cross-origin image without CORS: let the browser handle it.
      if (!image.src.startsWith('data:')) window.open(image.src, '_blank', 'noopener');
    }
  };

  return (
    <div
      className="lightbox"
      role="dialog"
      aria-modal="true"
      aria-label={image.alt || t('lightbox.preview')}
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="lightbox-bar">
        <p className="lightbox-title">{image.alt || t('lightbox.image')}</p>
        <div className="lightbox-actions">
          <button
            type="button"
            className="lightbox-btn"
            onClick={() => setZoomed((z) => !z)}
            aria-label={zoomed ? t('lightbox.fit') : t('lightbox.zoom')}
            title={zoomed ? t('lightbox.fit') : t('lightbox.zoomTitle')}
          >
            <Icon name={zoomed ? 'zoom-out' : 'zoom-in'} size={20} />
          </button>
          <button
            type="button"
            className="lightbox-btn"
            onClick={download}
            aria-label={t('lightbox.download')}
            title={t('lightbox.downloadTitle')}
          >
            <Icon name="download" size={20} />
          </button>
          <button
            ref={closeRef}
            type="button"
            className="lightbox-btn"
            onClick={onClose}
            aria-label={t('lightbox.close')}
            title={t('lightbox.closeTitle')}
          >
            <Icon name="x" size={20} strokeWidth={2} />
          </button>
        </div>
      </div>

      <div
        className={`lightbox-stage${zoomed ? ' zoomed' : ''}`}
        onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
      >
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={image.src}
          alt={image.alt || ''}
          className="lightbox-img"
          onClick={() => setZoomed((z) => !z)}
          draggable={false}
        />
      </div>
    </div>
  );
}
