/** @type {import('next').NextConfig} */
const nextConfig = {
  distDir: process.env.NEXT_DIST_DIR || ".next",
  assetPrefix: process.env.NEXT_PUBLIC_ASSET_PREFIX || undefined,
  eslint: {
    ignoreDuringBuilds: true,
  },
  typescript: {
    ignoreBuildErrors: true,
  },
  reactStrictMode: true,
  experimental: {
    instrumentationHook: true,
    // Build worker OOMs at its default heap on this VPS; compile in-process so
    // NODE_OPTIONS --max-old-space-size applies to the whole build.
    webpackBuildWorker: false,
    // Runtime image cache is large and must never be traced into production builds.
    outputFileTracingExcludes: {
      "*": ["./data/media-image-cache/**/*"],
    },
    serverActions: {
      bodySizeLimit: "120mb",
    },
    staleTimes: {
      dynamic: 180,
      static: 60,
    },
  },
  async redirects() {
    return [
      {
        source: "/admin/stream-source",
        destination: "/admin/stream-sources",
        permanent: false,
      },
      {
        source: "/admin/stream-source/:path*",
        destination: "/admin/stream-sources/:path*",
        permanent: false,
      },
    ]
  },
  async headers() {
    return [
      {
        source: "/embed-ads/:path*",
        headers: [
          { key: "Cache-Control", value: "no-store, no-cache, must-revalidate" },
          { key: "Access-Control-Allow-Origin", value: "*" },
        ],
      },
      {
        source: "/sw.js",
        headers: [
          { key: "Cache-Control", value: "no-store, no-cache, must-revalidate" },
          { key: "Service-Worker-Allowed", value: "/" },
        ],
      },
      {
        source: "/_next/static/:path*",
        headers: [{ key: "Access-Control-Allow-Origin", value: "*" }],
      },
      {
        source: "/iptv-play-sw.js",
        headers: [
          { key: "Cache-Control", value: "no-store, no-cache, must-revalidate" },
        ],
      },
      {
        source: "/iptv-sw.js",
        headers: [
          { key: "Cache-Control", value: "no-store, no-cache, must-revalidate" },
        ],
      },
      {
        source: "/iptv/embed.html",
        headers: [
          { key: "Cache-Control", value: "no-store, no-cache, must-revalidate" },
        ],
      },
    ]
  },
  images: {
    formats: ["image/webp"],
    minimumCacheTTL: 60 * 60 * 24,
    remotePatterns: [
      {
        protocol: "https",
        hostname: "wsrv.nl",
        pathname: "/**",
      },
      {
        protocol: 'https',
        hostname: 'image.tmdb.org',
        pathname: '/t/p/**',
      },
      {
        protocol: 'https',
        hostname: 'music.cinevo.site',
        pathname: '/**',
      },
      {
        protocol: 'https',
        hostname: 'c.saavncdn.com',
        pathname: '/**',
      },
      {
        protocol: 'https',
        hostname: 'www.jiosaavn.com',
        pathname: '/**',
      }, {
        protocol: "https",
        hostname: "cdns-images.dzcdn.net",
        pathname: "/**",
      },
      {
        protocol: "https",
        hostname: "cdn-images.dzcdn.net",
        pathname: "/**",
      },
      {
        protocol: "https",
        hostname: "e-cdns-images.dzcdn.net",
        pathname: "/**",
      },
      {
        protocol: "https",
        hostname: "is1-ssl.mzstatic.com",
        pathname: "/**",
      },
      {
        protocol: "https",
        hostname: "i.ytimg.com",
        pathname: "/**",
      },
      {
        protocol: "https",
        hostname: "img.youtube.com",
        pathname: "/**",
      },
      {
        protocol: "https",
        hostname: "i.scdn.co",
        pathname: "/**",
      },
      {
        protocol: "https",
        hostname: "ui-avatars.com",
        pathname: "/**",
      },
    ],
  },
}

export default nextConfig
