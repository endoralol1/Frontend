"use client"

import type { ReactNode } from "react"

import { ChatProvider } from "@/components/chat-widget"
import { TicketProvider } from "@/components/support-ticket-widget"

/** Heavy chat/ticket tree — loaded only after first paint via DeferredCommunityShell. */
export function CommunityProviders({ children }: { children: ReactNode }) {
  return (
    <ChatProvider>
      <TicketProvider>{children}</TicketProvider>
    </ChatProvider>
  )
}
