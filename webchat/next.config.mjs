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

  // Headers for security
  async headers() {
    return [
      {
        source: '/(.*)',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'X-Frame-Options', value: 'DENY' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
        ],
      },
    ];
  },
};

export default nextConfig;
