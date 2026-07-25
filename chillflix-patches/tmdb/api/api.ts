import { getTmdbLanguageParam } from "@/lib/i18n/tmdb-language"

import apiConfig from "./config"
import type { ListResponse } from "./types"

type FetcherOptions = {
  endpoint: string
  params?: Record<string, string | undefined>
  timeoutMs?: number
  maxAttempts?: number
}

type Fetcher = <T>(options: FetcherOptions, init?: RequestInit) => Promise<T>

/**
 * Sanitizes the given parameters by removing entries with undefined values.
 * This ensures that only valid parameters are included in the API request.
 *
 * @param {Record<string, string | undefined>} params - The parameters to be sanitized.
 * @returns {Record<string, string>} A new parameters object with undefined values removed.
 */
const sanitizeParams = (params?: Record<string, string | undefined>) => {
  return Object.fromEntries(
    Object.entries(params ?? {}).filter(([, value]) => value !== undefined)
  )
}

/**
 * Creates a URL search params string from the given parameters.
 * Merges default parameters from the API configuration with the provided parameters.
 * Undefined parameters are filtered out.
 *
 * @param {Record<string, string | undefined>} params - The parameters to include in the search string.
 * @returns {string} The URL search params string.
 */
const createSearchParams = (params: Record<string, string | undefined>) => {
  const sanitizedParams = sanitizeParams(params)
  const mergedParams: Record<string, string> = {
    ...apiConfig.defaultParams,
    ...sanitizedParams,
  } as Record<string, string>

  return new URLSearchParams(mergedParams).toString()
}

/**
 * Creates a Headers instance for the fetch request.
 * Merges default headers from the API configuration with any headers provided in the init object.
 *
 * @param {RequestInit} [init] - Optional initial settings for the fetch request, including headers.
 * @returns {Headers} The Headers instance for the fetch request.
 */
const createHeaders = (init?: RequestInit): Headers => {
  const headers = init?.headers ?? {}
  const mergedHeaders = { ...apiConfig.defaultHeaders, ...headers }
  return new Headers(mergedHeaders)
}

/**
 * Fetches data from the specified endpoint using the provided parameters and initialization options.
 * Sanitizes parameters to remove any undefined values, constructs the full URL with parameters,
 * and performs the fetch request with custom headers.
 * Throws an error if the response is not ok.
 *
 * @template T The expected type of the response JSON.
 * @param {{ endpoint: string, params?: Record<string, string | undefined> }} options - The endpoint and optional parameters for the fetch request.
 * @param {RequestInit} [init] - Optional initial settings for the fetch request.
 * @returns {Promise<T>} A promise resolving to the response JSON in the expected type.
 */
const TMDB_FETCH_TIMEOUT_MS = 8_000
const TMDB_MAX_ATTEMPTS = 3

function sleep(ms: number) {
  return new Promise<void>((resolve) => setTimeout(resolve, ms))
}

const fetcher: Fetcher = async (
  {
    endpoint,
    params,
    timeoutMs = TMDB_FETCH_TIMEOUT_MS,
    maxAttempts = TMDB_MAX_ATTEMPTS,
  },
  init
) => {
  const sanitizedParams = sanitizeParams(params)
  const _params = createSearchParams({
    language: getTmdbLanguageParam(),
    ...sanitizedParams,
  })
  const _headers = createHeaders(init)

  const _init = {
    ...init,
    next: { revalidate: 3600, ...(init?.next ?? {}) },
    headers: _headers,
  }

  const url = `${apiConfig.baseUrl}/${endpoint}?${_params}`
  const attempts = Math.max(1, maxAttempts)
  let lastError: unknown

  for (let attempt = 0; attempt < attempts; attempt += 1) {
    const controller = new AbortController()
    const timeout = setTimeout(() => controller.abort(), timeoutMs)

    try {
      const response = await fetch(url, {
        ..._init,
        signal: controller.signal,
      })

      if (!response.ok) {
        throw new Error(
          `TMDB request failed with status ${response.status}: ${response.statusText}`
        )
      }

      return await response.json()
    } catch (error) {
      lastError = error
      if (attempt < attempts - 1) {
        await sleep(400 * (attempt + 1))
      }
    } finally {
      clearTimeout(timeout)
    }
  }

  throw lastError instanceof Error
    ? lastError
    : new Error("TMDB request failed after retries")
}

export function emptyListResponse<T>(): ListResponse<T> {
  return {
    page: 1,
    results: [],
    total_pages: 0,
    total_results: 0,
  }
}

export function settleTmdbList<T>(
  result: PromiseSettledResult<ListResponse<T>>
): ListResponse<T> {
  if (result.status === "fulfilled") {
    return result.value
  }

  console.error("TMDB list request failed:", result.reason)
  return emptyListResponse<T>()
}

export const api = {
  fetcher,
}
