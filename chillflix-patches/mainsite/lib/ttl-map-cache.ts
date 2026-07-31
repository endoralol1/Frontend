import { trimMapToMax } from "@/lib/bounded-cache"

type Entry<T> = { value: T; expiresAt: number }

/**
 * Process-level TTL cache with optional in-flight coalescing.
 * Survives across requests (unlike React cache()).
 */
export function createTtlMapCache<T>(options: {
  name: string
  ttlMs: number
  maxEntries?: number
}) {
  const store = new Map<string, Entry<T>>()
  const inflight = new Map<string, Promise<T>>()
  const maxEntries = options.maxEntries ?? 5000

  function get(key: string): T | undefined {
    const hit = store.get(key)
    if (!hit) return undefined
    if (hit.expiresAt <= Date.now()) {
      store.delete(key)
      return undefined
    }
    return hit.value
  }

  function set(key: string, value: T, ttlMs = options.ttlMs) {
    store.set(key, { value, expiresAt: Date.now() + ttlMs })
    trimMapToMax(store, maxEntries)
  }

  async function getOrSet(key: string, loader: () => Promise<T>, ttlMs = options.ttlMs): Promise<T> {
    const cached = get(key)
    if (cached !== undefined) return cached

    const existing = inflight.get(key)
    if (existing) return existing

    const promise = loader()
      .then((value) => {
        set(key, value, ttlMs)
        return value
      })
      .finally(() => {
        inflight.delete(key)
      })

    inflight.set(key, promise)
    return promise
  }

  function size() {
    return store.size
  }

  function clear() {
    store.clear()
    inflight.clear()
  }

  return { get, set, getOrSet, size, clear, name: options.name }
}
