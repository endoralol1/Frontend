"use client"

import { useState } from "react"
import { BarChart3, Check } from "lucide-react"

import type { ChatPoll, ChatPollVoter } from "@/lib/chat-types"
import { useTranslations } from "@/lib/i18n/client"
import { cn } from "@/lib/utils"

type ChatPollCardProps = {
    poll: ChatPoll
    canVote: boolean
    voting: boolean
    onVote: (optionIds: string[]) => void
}

const MAX_VISIBLE_VOTERS = 7

function initials(label: string) {
    const parts = label.trim().split(/\s+/).filter(Boolean)
    if (parts.length === 0) return "?"
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
    return `${parts[0][0] ?? ""}${parts[1][0] ?? ""}`.toUpperCase()
}

function VoterAvatar({ voter, className }: { voter: ChatPollVoter; className?: string }) {
    const [failed, setFailed] = useState(false)
    const label = voter.name || voter.username

    if (voter.avatarUrl && !failed) {
        return (
            // eslint-disable-next-line @next/next/no-img-element
            <img
                src={voter.avatarUrl}
                alt={label}
                title={label}
                className={cn(
                    "size-5 rounded-full object-cover ring-2 ring-background",
                    className
                )}
                onError={() => setFailed(true)}
            />
        )
    }

    return (
        <span
            title={label}
            className={cn(
                "flex size-5 items-center justify-center rounded-full bg-primary/20 text-[9px] font-semibold text-primary ring-2 ring-background",
                className
            )}
        >
            {initials(label)}
        </span>
    )
}

function OptionVoters({ voters, voteCount }: { voters: ChatPollVoter[]; voteCount: number }) {
    if (voteCount <= 0) return null

    const list = Array.isArray(voters) ? voters : []
    const visible = list.slice(0, MAX_VISIBLE_VOTERS)
    const remaining = Math.max(voteCount - visible.length, 0)
    const namePreview = list
        .slice(0, 4)
        .map((voter) => voter.name || voter.username)
        .filter(Boolean)
    const namesTitle = [
        ...list.map((voter) => voter.name || voter.username),
        remaining > 0 && list.length >= MAX_VISIBLE_VOTERS ? `+${remaining} more` : null,
    ]
        .filter(Boolean)
        .join(", ")

    return (
        <div className="relative mt-1.5 flex min-w-0 items-center gap-2" title={namesTitle}>
            {visible.length > 0 ? (
                <div className="flex shrink-0 -space-x-1.5">
                    {visible.map((voter) => (
                        <VoterAvatar key={voter.id} voter={voter} />
                    ))}
                </div>
            ) : null}
            <p className="min-w-0 truncate text-[10px] leading-tight text-muted-foreground">
                {namePreview.length > 0 ? (
                    <>
                        {namePreview.join(", ")}
                        {voteCount > namePreview.length ? ` +${voteCount - namePreview.length}` : ""}
                    </>
                ) : (
                    <>{voteCount} vote{voteCount === 1 ? "" : "s"}</>
                )}
            </p>
        </div>
    )
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
        <div className="mt-1.5 w-full min-w-0 rounded-xl border border-border/60 bg-gradient-to-b from-muted/35 to-muted/10 p-2.5 shadow-sm shadow-black/10">
            <div className="mb-2.5 flex items-start gap-2">
                <div className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-primary/15 text-primary">
                    <BarChart3 className="size-3.5" />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-semibold leading-snug text-foreground">
                        {poll?.question || ""}
                    </p>
                    <p className="mt-1 text-[11px] leading-snug text-muted-foreground">
                        {poll?.allowMultiple ? t("chat.poll.multipleChoice") : t("chat.poll.singleChoice")}
                        {poll?.isClosed ? ` · ${t("chat.poll.closed")}` : ""}
                        {(poll?.totalVotes ?? 0) > 0
                            ? ` · ${t("chat.poll.totalVotes", { count: poll.totalVotes })}`
                            : ""}
                    </p>
                </div>
            </div>

            <div className="space-y-1.5">
                {options.map((option) => {
                    const selected = userOptionIds.includes(option.id)
                    const percentage =
                        (poll?.totalVotes ?? 0) > 0
                            ? Math.round((option.voteCount / poll.totalVotes) * 100)
                            : 0
                    const voters = Array.isArray(option.voters) ? option.voters : []

                    return (
                        <button
                            key={option.id}
                            type="button"
                            title={
                                showResults
                                    ? `${option.label} — ${percentage}% (${option.voteCount})`
                                    : option.label
                            }
                            disabled={!canVote || voting || Boolean(poll?.isClosed)}
                            onClick={() => toggleOption(option.id)}
                            className={cn(
                                "relative w-full overflow-hidden rounded-lg border text-left transition-all duration-200",
                                "px-2.5 py-2",
                                selected
                                    ? "border-primary/55 bg-primary/10 ring-1 ring-primary/25"
                                    : "border-border/45 bg-background/70",
                                canVote && !poll?.isClosed
                                    ? "hover:border-primary/35 hover:bg-muted/45"
                                    : "cursor-default"
                            )}
                        >
                            {showResults ? (
                                <div
                                    className="absolute inset-y-0 left-0 bg-primary/15 transition-[width] duration-300"
                                    style={{ width: `${percentage}%` }}
                                />
                            ) : null}

                            <div
                                className={cn(
                                    "relative flex items-center gap-2",
                                    showResults ? "pr-11" : "pr-0"
                                )}
                            >
                                {selected ? (
                                    <Check className="size-3.5 shrink-0 text-primary" />
                                ) : (
                                    <span className="size-3.5 shrink-0 rounded-full border border-border/80 bg-background/40" />
                                )}
                                <span className="min-w-0 flex-1 text-[13px] font-medium leading-snug text-foreground">
                                    {option.label}
                                </span>
                            </div>

                            {showResults ? (
                                <>
                                    <span className="absolute right-2 top-2 text-[11px] font-semibold tabular-nums text-foreground/85">
                                        {percentage}%
                                    </span>
                                    <OptionVoters voters={voters} voteCount={option.voteCount} />
                                </>
                            ) : null}
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
