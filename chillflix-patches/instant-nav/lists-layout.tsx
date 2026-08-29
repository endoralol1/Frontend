import { Suspense } from "react"

import { PaginatedListQueryRefresh } from "@/components/paginated-list-query-refresh"

/**
 * Soft-cached list routes so Movies/TV tab switches can reuse the client
 * router cache (see next.config experimental.staleTimes). Pagination still
 * refreshes via PaginatedListQueryRefresh when ?page= changes.
 */
export const revalidate = 60

export default function ListsLayout({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <Suspense fallback={null}>
      <PaginatedListQueryRefresh>{children}</PaginatedListQueryRefresh>
    </Suspense>
  )
}
