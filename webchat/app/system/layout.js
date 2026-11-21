import { Barlow_Condensed } from 'next/font/google';

// Barlow Condensed (SIL OFL): drawn from road and trail signage, the same headline voice as rutestrip.ai.id.
const barlow = Barlow_Condensed({
  subsets: ['latin'],
  weight: ['700', '800'],
  variable: '--font-barlow',
  display: 'optional',
});

export const metadata = {
  title: 'Status RuteStrip AI',
  description: 'Keadaan terkini layanan RuteStrip AI: situs chat, data jalur dan cuaca, dan mesin jawaban.',
};

export default function SystemLayout({ children }) {
  return <div className={`sys-root ${barlow.variable}`}>{children}</div>;
}
