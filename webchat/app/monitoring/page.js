import { redirect } from 'next/navigation';

export const metadata = {
  title: 'RuteStrip System & AI Token Monitoring Dashboard',
  description: 'Realtime monitoring status sistem VPS, Hermes AI Gateway, 9Router, & API RuteStrip.',
};

export default function MonitoringPage() {
  redirect('/system');
}
