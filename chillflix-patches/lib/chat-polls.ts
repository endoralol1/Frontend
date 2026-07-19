import { randomUUID } from "crypto"

import type { ChatPoll, ChatPollOption, ChatPollVoter } from "@/lib/chat-types"
import { execute, query, queryOne } from "@/lib/db"
import { notifyChatMessageCreated, notifyChatMessageUpdated } from "@/lib/chat-sse-notify"
import { getChatSettings } from "@/lib/chat-settings"

const POLL_MIN_OPTIONS = 2
const POLL_MAX_OPTIONS = 10
const POLL_MAX_QUESTION_LENGTH = 300
const POLL_MAX_OPTION_LENGTH = 120

export function validatePollInput(
    question: unknown,
    options: unknown,
    allowMultiple: unknown
): { ok: true; question: string; options: string[]; allowMultiple: boolean } | { ok: false; error: string } {
    if (typeof question !== "string") {
        return { ok: false, error: "Poll question is required." }
    }

    const trimmedQuestion = question.trim()
    if (!trimmedQuestion) {
        return { ok: false, error: "Poll question cannot be empty." }
    }

    if (trimmedQuestion.length > POLL_MAX_QUESTION_LENGTH) {
        return {
            ok: false,
            error: `Poll question is too long (max ${POLL_MAX_QUESTION_LENGTH} characters).`,
        }
    }

    if (!Array.isArray(options)) {
        return { ok: false, error: "Poll options must be a list." }
    }

    const cleanedOptions = options
        .map((option) => (typeof option === "string" ? option.trim() : ""))
        .filter(Boolean)

    if (cleanedOptions.length < POLL_MIN_OPTIONS) {
        return { ok: false, error: `Add at least ${POLL_MIN_OPTIONS} poll options.` }
    }

    if (cleanedOptions.length > POLL_MAX_OPTIONS) {
        return { ok: false, error: `Polls can have at most ${POLL_MAX_OPTIONS} options.` }
    }

    for (const option of cleanedOptions) {
        if (option.length > POLL_MAX_OPTION_LENGTH) {
            return {
                ok: false,
                error: `Each option can be at most ${POLL_MAX_OPTION_LENGTH} characters.`,
            }
        }
    }

    const unique = new Set(cleanedOptions.map((option) => option.toLowerCase()))
    if (unique.size !== cleanedOptions.length) {
        return { ok: false, error: "Poll options must be unique." }
    }

    return {
        ok: true,
        question: trimmedQuestion,
        options: cleanedOptions,
        allowMultiple: Boolean(allowMultiple),
    }
}

type DbPollRow = {
    id: string
    message_id: string
    question: string
    allow_multiple: number
    is_closed: number
}

type DbPollOptionRow = {
    id: string
    poll_id: string
    label: string
    sort_order: number
    vote_count: number
}

async function getPollRowsForMessages(messageIds: string[]) {
    if (messageIds.length === 0) {
        return new Map<string, DbPollRow>()
    }

    const placeholders = messageIds.map(() => "?").join(", ")
    const rows = await query<DbPollRow[]>(
        `SELECT id, message_id, question, allow_multiple, is_closed
         FROM chat_polls
         WHERE message_id IN (${placeholders})`,
        messageIds
    )

    return new Map(rows.map((row) => [row.message_id, row]))
}

async function getPollOptionsForPolls(pollIds: string[]) {
    if (pollIds.length === 0) {
        return new Map<string, DbPollOptionRow[]>()
    }

    const placeholders = pollIds.map(() => "?").join(", ")
    const rows = await query<DbPollOptionRow[]>(
        `SELECT id, poll_id, label, sort_order, vote_count
         FROM chat_poll_options
         WHERE poll_id IN (${placeholders})
         ORDER BY sort_order ASC, created_at ASC`,
        pollIds
    )

    const grouped = new Map<string, DbPollOptionRow[]>()
    for (const row of rows) {
        const list = grouped.get(row.poll_id) ?? []
        list.push(row)
        grouped.set(row.poll_id, list)
    }

    return grouped
}

async function getUserVotesForPolls(pollIds: string[], userId?: string) {
    const votes = new Map<string, string[]>()
    if (!userId || pollIds.length === 0) {
        return votes
    }

    const placeholders = pollIds.map(() => "?").join(", ")
    const rows = await query<Array<{ poll_id: string; option_id: string }>>(
        `SELECT poll_id, option_id
         FROM chat_poll_votes
         WHERE user_id = ? AND poll_id IN (${placeholders})`,
        [userId, ...pollIds]
    )

    for (const row of rows) {
        const list = votes.get(row.poll_id) ?? []
        list.push(row.option_id)
        votes.set(row.poll_id, list)
    }

    return votes
}


const POLL_VOTERS_PREVIEW_LIMIT = 40

async function getVotersForPolls(pollIds: string[]) {
    const votersByOption = new Map<string, ChatPollVoter[]>()
    if (pollIds.length === 0) {
        return votersByOption
    }

    const placeholders = pollIds.map(() => "?").join(", ")
    const rows = await query<
        Array<{
            option_id: string
            user_id: string
            username: string
            name: string | null
            avatar_url: string | null
        }>
    >(
        `SELECT v.option_id,
                u.id AS user_id,
                u.username,
                u.name,
                u.avatar_url
         FROM chat_poll_votes v
         INNER JOIN users u ON u.id = v.user_id
         WHERE v.poll_id IN (${placeholders})
         ORDER BY v.created_at ASC`,
        pollIds
    )

    for (const row of rows) {
        const list = votersByOption.get(row.option_id) ?? []
        if (list.length >= POLL_VOTERS_PREVIEW_LIMIT) {
            continue
        }
        list.push({
            id: row.user_id,
            username: row.username || "user",
            name: row.name || row.username || "User",
            avatarUrl: row.avatar_url ?? null,
        })
        votersByOption.set(row.option_id, list)
    }

    return votersByOption
}

function serializePoll(
    poll: DbPollRow,
    options: DbPollOptionRow[],
    userOptionIds: string[],
    votersByOptionId: Map<string, ChatPollVoter[]> = new Map()
): ChatPoll {
    const serializedOptions: ChatPollOption[] = options.map((option) => ({
        id: option.id,
        label: option.label,
        voteCount: Number(option.vote_count ?? 0),
        voters: votersByOptionId.get(option.id) ?? [],
    }))

    const totalVotes = serializedOptions.reduce((sum, option) => sum + option.voteCount, 0)

    return {
        id: poll.id,
        question: poll.question,
        allowMultiple: Boolean(poll.allow_multiple),
        isClosed: Boolean(poll.is_closed),
        options: serializedOptions,
        totalVotes,
        userOptionIds,
    }
}


async function loadChatMessageById(messageId: string, viewerUserId?: string) {
    // DYNAMIC_IMPORT_GET_CHAT_MESSAGE: avoid circular import with lib/chat.ts
    const { getChatMessageById } = await import("@/lib/chat")
    return getChatMessageById(messageId, viewerUserId)
}

export async function attachPollsToMessages<T extends { id: string; messageType?: string }>(
    messages: T[],
    viewerUserId?: string
): Promise<Array<T & { poll: ChatPoll | null }>> {
    const pollMessageIds = messages
        .filter((message) => message.messageType === "poll")
        .map((message) => message.id)

    if (pollMessageIds.length === 0) {
        return messages.map((message) => ({ ...message, poll: null }))
    }

    const pollByMessageId = await getPollRowsForMessages(pollMessageIds)
    const pollIds = [...pollByMessageId.values()].map((poll) => poll.id)
    const optionsByPollId = await getPollOptionsForPolls(pollIds)
    const votesByPollId = await getUserVotesForPolls(pollIds, viewerUserId)
    const votersByOptionId = await getVotersForPolls(pollIds)

    return messages.map((message) => {
        if (message.messageType !== "poll") {
            return { ...message, poll: null }
        }

        const poll = pollByMessageId.get(message.id)
        if (!poll) {
            return { ...message, poll: null }
        }

        const options = optionsByPollId.get(poll.id) ?? []
        const userOptionIds = votesByPollId.get(poll.id) ?? []

        return {
            ...message,
            poll: serializePoll(poll, options, userOptionIds, votersByOptionId),
        }
    })
}

export async function getPollForMessage(messageId: string, viewerUserId?: string) {
    const poll = await queryOne<DbPollRow>(
        `SELECT id, message_id, question, allow_multiple, is_closed
         FROM chat_polls
         WHERE message_id = ?
         LIMIT 1`,
        [messageId]
    )

    if (!poll) return null

    const options = await query<DbPollOptionRow[]>(
        `SELECT id, poll_id, label, sort_order, vote_count
         FROM chat_poll_options
         WHERE poll_id = ?
         ORDER BY sort_order ASC, created_at ASC`,
        [poll.id]
    )

    const votes = await getUserVotesForPolls([poll.id], viewerUserId)
    const votersByOptionId = await getVotersForPolls([poll.id])
    return serializePoll(poll, options, votes.get(poll.id) ?? [], votersByOptionId)
}

export async function createChatPoll(
    userId: string,
    input: { question: string; options: string[]; allowMultiple: boolean }
) {
    const settings = await getChatSettings()
    if (!settings.enabled) {
        return { error: "Community chat is currently disabled.", status: 403 as const }
    }

    const messageId = randomUUID()
    const pollId = randomUUID()
    const now = Date.now()

    await execute(
        `INSERT INTO chat_messages (id, user_id, body, message_type, created_at, updated_at, like_count, dislike_count)
         VALUES (?, ?, ?, 'poll', ?, ?, 0, 0)`,
        [messageId, userId, input.question, now, now]
    )

    await execute(
        `INSERT INTO chat_polls (id, message_id, question, allow_multiple, is_closed, created_at, closed_at)
         VALUES (?, ?, ?, ?, 0, ?, NULL)`,
        [pollId, messageId, input.question, input.allowMultiple ? 1 : 0, now]
    )

    for (let index = 0; index < input.options.length; index += 1) {
        await execute(
            `INSERT INTO chat_poll_options (id, poll_id, label, sort_order, vote_count, created_at)
             VALUES (?, ?, ?, ?, 0, ?)`,
            [randomUUID(), pollId, input.options[index], index, now]
        )
    }

    const message = await loadChatMessageById(messageId, userId)
    if (!message) {
        throw new Error("Failed to load created poll message")
    }

    notifyChatMessageCreated(message)
    return { message }
}

export async function voteOnChatPoll(
    userId: string,
    pollId: string,
    optionIds: string[]
) {
    const settings = await getChatSettings()
    if (!settings.enabled) {
        return { error: "Community chat is currently disabled.", status: 403 as const }
    }

    const poll = await queryOne<DbPollRow>(
        `SELECT id, message_id, question, allow_multiple, is_closed
         FROM chat_polls
         WHERE id = ?
         LIMIT 1`,
        [pollId]
    )

    if (!poll) {
        return { error: "Poll not found.", status: 404 as const }
    }

    if (poll.is_closed) {
        return { error: "This poll is closed.", status: 400 as const }
    }

    const uniqueOptionIds = [...new Set(optionIds.filter(Boolean))]
    if (uniqueOptionIds.length === 0) {
        return { error: "Select at least one option.", status: 400 as const }
    }

    if (!poll.allow_multiple && uniqueOptionIds.length > 1) {
        return { error: "This poll only allows one choice.", status: 400 as const }
    }

    const placeholders = uniqueOptionIds.map(() => "?").join(", ")
    const validOptions = await query<Array<{ id: string }>>(
        `SELECT id FROM chat_poll_options WHERE poll_id = ? AND id IN (${placeholders})`,
        [pollId, ...uniqueOptionIds]
    )

    if (validOptions.length !== uniqueOptionIds.length) {
        return { error: "One or more poll options are invalid.", status: 400 as const }
    }

    const now = Date.now()

    if (poll.allow_multiple) {
        const existingVotes = await query<Array<{ option_id: string }>>(
            `SELECT option_id FROM chat_poll_votes WHERE poll_id = ? AND user_id = ?`,
            [pollId, userId]
        )
        const existingSet = new Set(existingVotes.map((vote) => vote.option_id))

        for (const optionId of uniqueOptionIds) {
            if (existingSet.has(optionId)) {
                await execute(
                    `DELETE FROM chat_poll_votes WHERE poll_id = ? AND option_id = ? AND user_id = ?`,
                    [pollId, optionId, userId]
                )
                await execute(
                    `UPDATE chat_poll_options SET vote_count = GREATEST(0, vote_count - 1) WHERE id = ?`,
                    [optionId]
                )
            } else {
                await execute(
                    `INSERT INTO chat_poll_votes (poll_id, option_id, user_id, created_at)
                     VALUES (?, ?, ?, ?)`,
                    [pollId, optionId, userId, now]
                )
                await execute(`UPDATE chat_poll_options SET vote_count = vote_count + 1 WHERE id = ?`, [
                    optionId,
                ])
            }
        }
    } else {
        const existingVotes = await query<Array<{ option_id: string }>>(
            `SELECT option_id FROM chat_poll_votes WHERE poll_id = ? AND user_id = ?`,
            [pollId, userId]
        )

        const selectedOptionId = uniqueOptionIds[0]
        const alreadySelected = existingVotes.some((vote) => vote.option_id === selectedOptionId)

        if (alreadySelected) {
            await execute(`DELETE FROM chat_poll_votes WHERE poll_id = ? AND user_id = ?`, [
                pollId,
                userId,
            ])
            await execute(
                `UPDATE chat_poll_options SET vote_count = GREATEST(0, vote_count - 1) WHERE id = ?`,
                [selectedOptionId]
            )
        } else {
            for (const vote of existingVotes) {
                await execute(
                    `UPDATE chat_poll_options SET vote_count = GREATEST(0, vote_count - 1) WHERE id = ?`,
                    [vote.option_id]
                )
            }
            await execute(`DELETE FROM chat_poll_votes WHERE poll_id = ? AND user_id = ?`, [
                pollId,
                userId,
            ])
            await execute(
                `INSERT INTO chat_poll_votes (poll_id, option_id, user_id, created_at)
                 VALUES (?, ?, ?, ?)`,
                [pollId, selectedOptionId, userId, now]
            )
            await execute(`UPDATE chat_poll_options SET vote_count = vote_count + 1 WHERE id = ?`, [
                selectedOptionId,
            ])
        }
    }

    notifyChatMessageUpdated(poll.message_id)
    const message = await loadChatMessageById(poll.message_id, userId)
    return { message }
}

export async function setChatPollClosed(pollId: string, closed: boolean) {
    const poll = await queryOne<DbPollRow>(
        `SELECT id, message_id FROM chat_polls WHERE id = ? LIMIT 1`,
        [pollId]
    )

    if (!poll) {
        return { error: "Poll not found.", status: 404 as const }
    }

    const now = Date.now()
    await execute(`UPDATE chat_polls SET is_closed = ?, closed_at = ? WHERE id = ?`, [
        closed ? 1 : 0,
        closed ? now : null,
        pollId,
    ])

    notifyChatMessageUpdated(poll.message_id)
    const message = await loadChatMessageById(poll.message_id)
    return { message }
}

export async function clearAllChatPolls() {
    await execute("DELETE FROM chat_poll_votes")
    await execute("DELETE FROM chat_poll_options")
    await execute("DELETE FROM chat_polls")
}
