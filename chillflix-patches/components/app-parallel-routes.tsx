"use client"

interface AppParallelRoutesProps {
  children: React.ReactNode
  modal: React.ReactNode
}

/**
 * Keep the underlay page mounted while a detail intercept modal is open
 * (Netflix-style overlay). List bypass modals render null and leave children.
 */
export function AppParallelRoutes({ children, modal }: AppParallelRoutesProps) {
  return (
    <>
      {children}
      {modal}
    </>
  )
}
