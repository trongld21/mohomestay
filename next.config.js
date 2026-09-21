/** @type {import('next').NextConfig} */
const nextConfig = {
  distDir: process.env.NEXT_BUILD_DIR || ".next",
  images: {
    remotePatterns: [
      {
        protocol: 'https',
        hostname: 'images.unsplash.com',
      },

    ],
  },
  reactStrictMode: true,
  swcMinify: true,
  compress: true,
}

module.exports = nextConfig
