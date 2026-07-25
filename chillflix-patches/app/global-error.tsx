"use client"

import { siteConfig } from "@/config"

/**
 * Replaces the entire document when the root layout crashes.
 * Keep real site title/description + noindex so Google never indexes this UI
 * as the homepage (that produced the "Something went wrong" SERP snippet).
 */
export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string }
  reset: () => void
}) {
  return (
    <html lang="en" className="dark">
      <head>
        <title>{siteConfig.share.siteTitle}</title>
        <meta name="description" content={siteConfig.share.siteDescription} />
        <meta name="robots" content="noindex, nofollow" />
      </head>
      <body className="min-h-screen bg-background font-sans antialiased">
        <div className="container flex min-h-screen flex-col items-center justify-center gap-4 py-16 text-center">
          <h1 className="text-2xl font-semibold">Something went wrong</h1>
          <p className="max-w-md text-sm text-muted-foreground">
            A part of the page failed to load. You can refresh or return home —
            other areas of the site should still work after a reload.
          </p>
          <div className="flex flex-wrap items-center justify-center gap-3">
            <button
              type="button"
              onClick={() => reset()}
              className="inline-flex h-10 items-center justify-center rounded-md border border-input bg-background px-4 text-sm font-medium hover:bg-accent"
            >
              Try again
            </button>
            <a
              href="/"
              className="inline-flex h-10 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
            >
              Go home
            </a>
          </div>
          {error.digest ? (
            <p className="text-xs text-muted-foreground">Error ID: {error.digest}</p>
          ) : null}
        </div>
      </body>
    </html>
  )
}
