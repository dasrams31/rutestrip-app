/** @type {import('next').NextConfig} */
const nextConfig = {
  // Allow images from the RuteStrip Bot API
  images: {
    remotePatterns: [
      {
        protocol: 'https',
        hostname: 'cement-affiliation-firm-jesus.trycloudflare.com',
        pathname: '/api/map/**',
      },
    ],
  },

  // Headers for security. Framing: only this site and rutestrip.ai.id, which
  // embeds /chat?embed=1 in its chat panel. No X-Frame-Options: DENY or
  // SAMEORIGIN would block that cross-origin parent.
  async headers() {
    return [
      {
        source: '/(.*)',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'Content-Security-Policy', value: "frame-ancestors 'self' https://rutestrip.ai.id" },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
        ],
      },
    ];
  },
};

export default nextConfig;
