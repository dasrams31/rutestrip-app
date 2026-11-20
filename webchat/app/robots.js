const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL || 'https://chat.rutestrip.ai.id';

export default function robots() {
  return {
    rules: [{ userAgent: '*', allow: '/', disallow: ['/api/', '/monitoring'] }],
    sitemap: `${SITE_URL}/sitemap.xml`,
    host: SITE_URL,
  };
}
