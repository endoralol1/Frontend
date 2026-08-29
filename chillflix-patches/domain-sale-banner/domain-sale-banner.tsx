"use client"

import { useEffect, useState } from "react"

const STORAGE_KEY = "chillflix.domainSaleBanner.dismissed"

/**
 * Soft notice under the home hero for domain purchase inquiries.
 * Dismissible via X (persists in localStorage).
 * Set ENABLED to true to show again later.
 */
const ENABLED = false

export function DomainSaleBanner() {
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    if (!ENABLED) return
    try {
      if (window.localStorage.getItem(STORAGE_KEY) === "1") return
    } catch {
      // ignore
    }
    setVisible(true)
  }, [])

  if (!ENABLED || !visible) return null

  function dismiss() {
    try {
      window.localStorage.setItem(STORAGE_KEY, "1")
    } catch {
      // ignore
    }
    setVisible(false)
  }

  return (
    <aside
      className="mx-auto w-full max-w-6xl px-4 pt-5 md:px-6 md:pt-6"
      aria-label="Domain for sale"
    >
      <div className="relative overflow-hidden rounded-2xl border border-[rgba(199,162,78,0.28)] bg-[linear-gradient(105deg,rgba(199,162,78,0.14)_0%,rgba(20,19,17,0.92)_42%,rgba(12,11,10,0.96)_100%)] px-4 py-3.5 shadow-[0_10px_32px_rgba(0,0,0,0.28)] md:px-5 md:py-4">
        <div
          className="pointer-events-none absolute inset-y-0 left-0 w-1 bg-[#c7a24e]"
          aria-hidden
        />
        <button
          type="button"
          onClick={dismiss}
          className="absolute right-2.5 top-2.5 z-10 inline-flex size-8 items-center justify-center rounded-full text-[#c7a24e]/80 transition hover:bg-black/30 hover:text-[#f3e6c2]"
          aria-label="Dismiss domain for sale notice"
        >
          <svg viewBox="0 0 24 24" className="size-4" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
            <path d="M6 6l12 12M18 6L6 18" />
          </svg>
        </button>
        <div className="flex flex-col gap-2 pr-8 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
          <div className="min-w-0 pl-2">
            <p className="text-[0.7rem] font-semibold uppercase tracking-[0.14em] text-[#c7a24e]">
              Domain for sale
            </p>
            <p className="mt-1 text-sm leading-relaxed text-[#ece6d8] md:text-[0.95rem]">
              Interested in buying{" "}
              <span className="font-semibold text-white">chillflix.lol</span>? Contact{" "}
              <span className="rounded-md bg-black/35 px-1.5 py-0.5 font-mono text-[0.92em] font-semibold text-[#f3e6c2]">
                saltybureksmesom
              </span>{" "}
              on Discord.
            </p>
          </div>
          <div className="shrink-0 pl-2 sm:pl-0">
            <a
              href="https://discord.gg/6r5KTZgqXV"
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-2 rounded-full border border-[rgba(199,162,78,0.35)] bg-[rgba(199,162,78,0.12)] px-3.5 py-2 text-xs font-semibold text-[#f3e6c2] transition hover:border-[rgba(199,162,78,0.55)] hover:bg-[rgba(199,162,78,0.2)] hover:text-white"
            >
              <svg
                viewBox="0 0 24 24"
                aria-hidden
                className="size-4 fill-current"
              >
                <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z" />
              </svg>
              Open Discord
            </a>
          </div>
        </div>
      </div>
    </aside>
  )
}
