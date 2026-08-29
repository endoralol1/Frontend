"use client"

import { Radio } from "lucide-react"

import { IptvPlayer, type IptvSourceType } from "@/components/iptv-player"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from "@/components/ui/dialog"

export type IptvChannelSelection = {
  id: number | string
  name: string
  country: string
  url?: string
  playPageUrl?: string
}

type IptvPlayerModalProps = {
  channel: IptvChannelSelection | null
  sourceType: IptvSourceType
  onClose: () => void
}

export function IptvPlayerModal({ channel, sourceType, onClose }: IptvPlayerModalProps) {
  const isOpen = Boolean(channel)

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent
        overlayClassName="bg-[rgba(0,0,0,0.82)] backdrop-blur-sm"
        className="w-[min(96vw,960px)] max-w-[96vw] gap-0 overflow-hidden border border-white/10 bg-[linear-gradient(180deg,#141414_0%,#090909_100%)] p-0 shadow-[0_24px_80px_rgba(0,0,0,0.65)] sm:rounded-2xl"
      >
        <DialogTitle className="sr-only">
          {channel ? `Watch ${channel.name}` : "IPTV player"}
        </DialogTitle>
        <DialogDescription className="sr-only">
          {channel
            ? `Live stream for ${channel.name}${channel.country ? ` (${channel.country})` : ""}`
            : "IPTV live stream player"}
        </DialogDescription>

        {channel ? (
          <div className="space-y-4 p-4 sm:p-6">
            <div className="flex items-start gap-3 pr-8">
              <div className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full border border-white/10 bg-white/[0.04]">
                <Radio className="size-4 text-white/80" />
              </div>
              <div className="min-w-0 space-y-1">
                <h2 className="line-clamp-2 text-lg font-semibold tracking-tight text-white">
                  {channel.name}
                </h2>
                {channel.country ? (
                  <p className="text-sm text-white/55">{channel.country}</p>
                ) : null}
              </div>
            </div>

            <IptvPlayer
              key={`${sourceType}:${channel.id}`}
              channelId={String(channel.id)}
              sourceType={sourceType}
              channelName={channel.name}
              channelCountry={channel.country}
              freeTvUrl={channel.url}
              livePlayPageUrl={channel.playPageUrl}
            />
          </div>
        ) : null}
      </DialogContent>
    </Dialog>
  )
}
