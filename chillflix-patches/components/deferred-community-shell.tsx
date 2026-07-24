"use client"

import {
  createContext,
  useContext,
  useEffect,
  useState,
  type ComponentType,
  type ReactNode,
} from "react"

import { scheduleAfterLoad } from "@/lib/schedule-after-load"

const CommunityReadyContext = createContext(false)

export function useCommunityReady() {
  return useContext(CommunityReadyContext)
}

/**
 * Keep chat/ticket JS (~layout TBT) off the critical path.
 * Page content stays outside this shell so it never remounts when community loads.
 */
export function DeferredCommunityShell({ children }: { children: ReactNode }) {
  const [Providers, setProviders] = useState<ComponentType<{
    children: ReactNode
  }> | null>(null)

  useEffect(() => {
    return scheduleAfterLoad(() => {
      void import("@/components/community-providers").then((module) => {
        setProviders(() => module.CommunityProviders)
      })
    }, { timeoutMs: 2_500, delayMs: 900 })
  }, [])

  if (!Providers) {
    return (
      <CommunityReadyContext.Provider value={false}>
        {children}
      </CommunityReadyContext.Provider>
    )
  }

  return (
    <CommunityReadyContext.Provider value={true}>
      <Providers>{children}</Providers>
    </CommunityReadyContext.Provider>
  )
}
