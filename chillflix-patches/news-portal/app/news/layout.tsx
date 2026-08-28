import type { Metadata } from "next"
import { Suspense } from "react"

import { NewsFooter } from "@/components/news/news-footer"
import "@/styles/news-portal.css"

export const metadata: Metadata = {
  title: "Daily24 News",
  description: "Global news portal — country editions in local languages.",
}

export default function NewsLayout({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <div className="n24-root">
      <link
        rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700;900&display=swap"
      />
      <Suspense fallback={null}>{children}</Suspense>
      <NewsFooter />
    </div>
  )
}
