const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL || 'https://chat.rutestrip.ai.id';

export default function sitemap() {
  const now = new Date();
  return [
    { url: `${SITE_URL}/chat`, lastModified: now, changeFrequency: 'weekly', priority: 1 },
    { url: `${SITE_URL}/system`, lastModified: now, changeFrequency: 'monthly', priority: 0.4 },
  ];
}
