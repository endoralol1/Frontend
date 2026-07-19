"use client"

import { BarChart3, Check } from "lucide-react"

import type { ChatPoll } from "@/lib/chat-types"
import { useTranslations } from "@/lib/i18n/client"
import { cn } from "@/lib/utils"

type ChatPollCardProps = {
    poll: ChatPoll
    canVote: boolean
    voting: boolean
    onVote: (optionIds: string[]) => void
}

export function ChatPollCard({ poll, canVote, voting, onVote }: ChatPollCardProps) {
    // HARDENED_POLL_CARD: never crash if a partial poll payload arrives.
    const { t } = useTranslations()
    const options = Array.isArray(poll?.options) ? poll.options : []
    const userOptionIds = Array.isArray(poll?.userOptionIds) ? poll.userOptionIds : []
    const hasVoted = userOptionIds.length > 0
    // Guests can see live results, but cannot vote.
    const showResults = !canVote || hasVoted || Boolean(poll?.isClosed)

    function toggleOption(optionId: string) {
        if (!canVote || voting || poll?.isClosed) return
        onVote([optionId])
    }

    return (
        <div className="mt-1 rounded-xl border border-border/60 bg-muted/20 p-3">
            <div className="mb-3 flex items-start gap-2">
                <BarChart3 className="mt-0.5 size-4 shrink-0 text-primary" />
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium text-foreground">{poll?.question || ""}</p>
                    <p className="mt-1 text-[11px] text-muted-foreground">
                        {poll?.allowMultiple ? t("chat.poll.multipleChoice") : t("chat.poll.singleChoice")}
                        {poll?.isClosed ? ` · ${t("chat.poll.closed")}` : ""}
                        {(poll?.totalVotes ?? 0) > 0
                            ? ` · ${t("chat.poll.totalVotes", { count: poll.totalVotes })}`
                            : ""}
                    </p>
                </div>
            </div>

            <div className="space-y-2">
                {options.map((option) => {
                    const selected = userOptionIds.includes(option.id)
                    const percentage =
                        (poll?.totalVotes ?? 0) > 0
                            ? Math.round((option.voteCount / poll.totalVotes) * 100)
                            : 0

                    return (
                        <button
                            key={option.id}
                            type="button"
                            disabled={!canVote || voting || Boolean(poll?.isClosed)}
                            onClick={() => toggleOption(option.id)}
                            className={cn(
                                "relative w-full overflow-hidden rounded-lg border px-3 py-2 text-left transition-colors",
                                selected
                                    ? "border-primary/60 bg-primary/10"
                                    : "border-border/50 bg-background/60",
                                canVote && !poll?.isClosed
                                    ? "hover:border-border hover:bg-muted/40"
                                    : "cursor-default"
                            )}
                        >
                            {showResults ? (
                                <div
                                    className="absolute inset-y-0 left-0 bg-primary/10"
                                    style={{ width: `${percentage}%` }}
                                />
                            ) : null}
                            <div className="relative flex items-start justify-between gap-3">
                                <div className="flex min-w-0 flex-1 items-start gap-2">
                                    {selected ? (
                                        <Check className="mt-0.5 size-3.5 shrink-0 text-primary" />
                                    ) : (
                                        <span className="mt-0.5 size-3.5 shrink-0 rounded-full border border-border/80" />
                                    )}
                                    <span className="whitespace-normal break-words text-sm leading-snug">
                                        {option.label}
                                    </span>
                                </div>
                                {showResults ? (
                                    <span className="shrink-0 pt-0.5 text-xs tabular-nums text-muted-foreground">
                                        {percentage}% ({option.voteCount})
                                    </span>
                                ) : null}
                            </div>
                        </button>
                    )
                })}
            </div>

            {canVote && !poll?.isClosed && poll?.allowMultiple && !hasVoted ? (
                <p className="mt-2 text-[11px] text-muted-foreground">{t("chat.poll.tapToVote")}</p>
            ) : null}

            {poll?.isClosed ? (
                <p className="mt-2 text-[11px] text-muted-foreground">{t("chat.poll.closedHint")}</p>
            ) : null}
        </div>
    )
}
