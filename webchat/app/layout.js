import { Inter } from 'next/font/google';
import './globals.css';

const inter = Inter({
  subsets: ['latin'],
  weight: ['300', '400', '500', '600', '700', '800'],
  variable: '--font-inter',
  display: 'swap',
});

export const metadata = {
  metadataBase: new URL('https://chat.rutestrip.ai.id'),
  title: 'RuteStrip AI – Asisten Pendakian Gunung Indonesia',
  description:
    'Asisten pintar berbasis AI untuk pendakian gunung Indonesia. Dapatkan rekomendasi jalur, cuaca realtime, itinerary, estimasi biaya, dan panduan survival.',
  keywords: 'pendakian gunung, RuteStrip AI, chat.rutestrip.ai.id, AI chatbot, jalur pendakian, SBERT, rekomendasi gunung Indonesia',
  alternates: { canonical: '/chat' },
  robots: { index: true, follow: true, googleBot: { index: true, follow: true, 'max-image-preview': 'large' } },
  // Ownership codes from Google Search Console / Bing Webmaster Tools (set at build time)
  verification: {
    google: process.env.NEXT_PUBLIC_GOOGLE_SITE_VERIFICATION || undefined,
    other: process.env.NEXT_PUBLIC_BING_SITE_VERIFICATION
      ? { 'msvalidate.01': process.env.NEXT_PUBLIC_BING_SITE_VERIFICATION }
      : undefined,
  },
  icons: {
    icon: [
      { url: '/favicon.ico', sizes: 'any' },
      { url: '/favicon.png', type: 'image/png' },
      { url: '/logo.svg', type: 'image/svg+xml' },
    ],
    shortcut: '/favicon.ico',
    apple: '/logo.png',
  },
  openGraph: {
    title: 'RuteStrip AI – Asisten Pendakian Gunung Indonesia',
    description: 'Asisten pintar berbasis AI untuk pendakian gunung Indonesia. Cek jalur, cuaca realtime, itinerary, & survival!',
    url: 'https://chat.rutestrip.ai.id',
    siteName: 'RuteStrip AI',
    locale: 'id_ID',
    type: 'website',
    images: [
      {
        url: 'https://chat.rutestrip.ai.id/og-image.jpg',
        secureUrl: 'https://chat.rutestrip.ai.id/og-image.jpg',
        width: 1200,
        height: 630,
        type: 'image/jpeg',
        alt: 'RuteStrip AI – Asisten Pendakian Gunung Indonesia',
      },
    ],
  },
  twitter: {
    card: 'summary_large_image',
    title: 'RuteStrip AI – Asisten Pendakian Gunung Indonesia',
    description: 'Asisten pintar berbasis AI untuk pendakian gunung Indonesia.',
    images: ['https://chat.rutestrip.ai.id/og-image.jpg'],
  },
};

export const viewport = {
  width: 'device-width',
  initialScale: 1,
  themeColor: '#059669',
};

export default function RootLayout({ children }) {
  return (
    <html lang="id" className={inter.variable}>
      <head>
        <link rel="manifest" href="/manifest.json" />
        <link rel="icon" href="/favicon.ico" sizes="any" />
        <link rel="icon" href="/favicon.png" type="image/png" />
        <link rel="icon" href="/logo.svg" type="image/svg+xml" />
        <link rel="shortcut icon" href="/favicon.ico" />
        <link rel="apple-touch-icon" href="/logo.svg" />
        <meta name="apple-mobile-web-app-capable" content="yes" />
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
        <meta name="apple-mobile-web-app-title" content="RuteStrip AI" />
        <meta name="theme-color" content="#059669" />
        <meta property="og:image" content="https://chat.rutestrip.ai.id/og-image.jpg" />
        <meta property="og:image:secure_url" content="https://chat.rutestrip.ai.id/og-image.jpg" />
        <meta property="og:image:type" content="image/jpeg" />
        <meta property="og:image:width" content="1200" />
        <meta property="og:image:height" content="630" />
      </head>
      <body>
        {children}
        <script
          dangerouslySetInnerHTML={{
            __html: `
              if ('serviceWorker' in navigator) {
                window.addEventListener('load', function() {
                  navigator.serviceWorker.register('/sw.js').then(
                    function(reg) { console.log('PWA ServiceWorker registered with scope:', reg.scope); },
                    function(err) { console.log('PWA ServiceWorker registration failed:', err); }
                  );
                });
              }
            `,
          }}
        />
      </body>
    </html>
  );
}

