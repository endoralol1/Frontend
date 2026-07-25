"use client"

import type { ReactNode } from "react"

import { ChatProvider } from "@/components/chat-widget"
import { TicketProvider } from "@/components/support-ticket-widget"

/**
 * Full chat+ticket tree. Prefer DeferredCommunityShell's selective imports so a
 * disabled feature never downloads its chunk.
 */
export function CommunityProviders({ children }: { children: ReactNode }) {
  return (
    <ChatProvider>
      <TicketProvider>{children}</TicketProvider>
    </ChatProvider>
  )
}
