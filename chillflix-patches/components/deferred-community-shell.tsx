"use client"

import {
  createContext,
  useContext,
  useEffect,
  useState,
  type ComponentType,
  type ReactNode,
} from "react"

import { useSiteFeatures } from "@/components/site-features"
import { scheduleAfterLoad } from "@/lib/schedule-after-load"

const CommunityReadyContext = createContext(false)

export function useCommunityReady() {
  return useContext(CommunityReadyContext)
}

type CommunityProvidersProps = {
  children: ReactNode
}

/**
 * Keep chat/ticket JS off the critical path.
 * When admin has both disabled, never download those chunks.
 * When only one is enabled, import that provider only.
 */
export function DeferredCommunityShell({ children }: { children: ReactNode }) {
  const { chatEnabled, ticketsEnabled } = useSiteFeatures()
  const [Providers, setProviders] = useState<ComponentType<CommunityProvidersProps> | null>(
    null
  )

  useEffect(() => {
    if (!chatEnabled && !ticketsEnabled) {
      setProviders(null)
      return
    }

    let cancelled = false

    const cancelSchedule = scheduleAfterLoad(() => {
      void (async () => {
        const [chatMod, ticketMod] = await Promise.all([
          chatEnabled
            ? import("@/components/chat-widget")
            : Promise.resolve(null),
          ticketsEnabled
            ? import("@/components/support-ticket-widget")
            : Promise.resolve(null),
        ])

        if (cancelled) return

        setProviders(() => {
          function SelectiveCommunityProviders({
            children: nested,
          }: CommunityProvidersProps) {
            let tree: ReactNode = nested
            if (ticketMod) {
              tree = <ticketMod.TicketProvider>{tree}</ticketMod.TicketProvider>
            }
            if (chatMod) {
              tree = <chatMod.ChatProvider>{tree}</chatMod.ChatProvider>
            }
            return <>{tree}</>
          }
          return SelectiveCommunityProviders
        })
      })()
    }, { timeoutMs: 2_500, delayMs: 900 })

    return () => {
      cancelled = true
      cancelSchedule()
    }
  }, [chatEnabled, ticketsEnabled])

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
