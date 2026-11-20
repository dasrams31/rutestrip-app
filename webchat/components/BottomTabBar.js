'use client';

import { ROUTES_URL } from '@/lib/i18n';
import Icon from './Icon';
import { useI18n } from './I18nProvider';

/*
 * Phone-only app navigation (hidden from 768px and in print). It is the last
 * row of the full-height app shell, so the composer and the Telegram button
 * always sit above it.
 */
export default function BottomTabBar({ active, onChat, onHistory, onAccount }) {
  const { t } = useI18n();
  const tab = (key, icon, label, props) => (
    <li key={key}>
      {props.href ? (
        <a className="tabbar-item" href={props.href}>
          <Icon name={icon} size={22} />
          <span>{label}</span>
        </a>
      ) : (
        <button
          type="button"
          className={`tabbar-item${active === key ? ' active' : ''}`}
          aria-current={active === key ? 'page' : undefined}
          {...props}
        >
          <Icon name={icon} size={22} strokeWidth={active === key ? 2.1 : 1.75} />
          <span>{label}</span>
        </button>
      )}
    </li>
  );

  return (
    <nav className="tabbar print-hide" aria-label={t('tabs.aria')}>
      <ul>
        {tab('chat', 'chat', t('tabs.chat'), { onClick: onChat })}
        {tab('history', 'clock', t('tabs.history'), {
          onClick: onHistory,
          'aria-controls': 'chat-sidebar',
          'aria-expanded': active === 'history',
        })}
        {tab('routes', 'map', t('tabs.routes'), { href: ROUTES_URL })}
        {tab('account', 'user', t('tabs.account'), {
          onClick: onAccount,
          'aria-haspopup': 'dialog',
          'aria-expanded': active === 'account',
        })}
      </ul>
    </nav>
  );
}
