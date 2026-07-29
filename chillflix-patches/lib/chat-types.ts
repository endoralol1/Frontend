import type { UserRole } from "@/lib/permissions"

export const CHAT_MAX_BODY_LENGTH = 500

export type ChatMessageType = "text" | "poll"

export type ChatMessageUser = {
    id: string
    username: string
    name: string
    avatarUrl: string | null
    role: UserRole
}

export type ChatPollVoter = {
    id: string
    username: string
    name: string
    avatarUrl: string | null
}

export type ChatPollOption = {
    id: string
    label: string
    voteCount: number
    /** Voters for this option (oldest first). UI may show a subset. */
    voters: ChatPollVoter[]
}

export type ChatPoll = {
    id: string
    question: string
    allowMultiple: boolean
    isClosed: boolean
    options: ChatPollOption[]
    totalVotes: number
    userOptionIds: string[]
}

export type ChatMessage = {
    id: string
    body: string
    messageType: ChatMessageType
    createdAt: number
    updatedAt: number
    editedAt: number | null
    user: ChatMessageUser
    likeCount: number
    dislikeCount: number
    userReaction: "like" | "dislike" | null
    replyTo: ChatMessageReplyPreview | null
    poll: ChatPoll | null
}

export type ChatMessageReplyPreview = {
    id: string
    body: string
    userName: string
}

export type ChatMessagePreview = {
    body: string
    userName: string
    createdAt: number
}
