export const APP_SHELL_UA_CONCURRENCY = 4
export const ACTIVITY_BADGE_TTL_MS = 60_000

// Cache only the displayed summary, scoped to the signed-in account. Writing
// each completed symbol lets a browser reload reuse even a partially loaded list.
export function createActivityBadgeCache(accountId) {
    if (accountId === null || accountId === undefined) return null
    const prefix = `gex:activity-badge:v1:${accountId}:`
    return {
        get(symbol) {
            try {
                const entry = JSON.parse(sessionStorage.getItem(prefix + symbol))
                if (entry && Number.isFinite(entry.at) && entry.at <= Date.now()
                    && Date.now() - entry.at < ACTIVITY_BADGE_TTL_MS
                    && typeof entry.badge?.data_date === 'string'
                    && Number.isInteger(entry.badge?.count) && entry.badge.count >= 0) return entry.badge
                sessionStorage.removeItem(prefix + symbol)
            } catch { /* Storage may be disabled or contain an old entry. */ }
            return null
        },
        set(symbol, badge) {
            try {
                sessionStorage.setItem(prefix + symbol, JSON.stringify({ at: Date.now(), badge }))
            } catch { /* Badge loading still works without browser storage. */ }
        },
    }
}

function throwIfAborted(signal) {
    if (!signal?.aborted) return

    if (typeof signal.throwIfAborted === 'function') {
        signal.throwIfAborted()
    }

    const error = new Error('The activity load was aborted.')
    error.name = 'AbortError'
    throw error
}

async function mapWithConcurrency(items, concurrency, signal, callback) {
    if (items.length === 0) return []

    const limit = Math.max(1, Math.min(items.length, Math.floor(concurrency) || 1))
    const results = new Array(items.length)
    let nextIndex = 0

    async function worker() {
        while (nextIndex < items.length) {
            throwIfAborted(signal)

            const index = nextIndex
            nextIndex += 1
            results[index] = await callback(items[index], signal)
        }
    }

    await Promise.all(Array.from({ length: limit }, () => worker()))
    throwIfAborted(signal)

    return results
}

/**
 * Load every watchlist symbol while limiting the number of simultaneous UA calls.
 * Individual request failures retain the existing empty-badge behavior.
 */
export async function loadUnusualActivityBadges(
    symbols,
    request,
    {
        concurrency = APP_SHELL_UA_CONCURRENCY,
        signal,
        cache = null,
    } = {},
) {
    let rateLimited = false
    const uniqueSymbols = [...new Set(symbols.filter(Boolean))]
    const entries = await mapWithConcurrency(
        uniqueSymbols,
        concurrency,
        signal,
        async (symbol, requestSignal) => {
            const cached = cache?.get(symbol)
            if (cached) return [symbol, cached]
            if (rateLimited) return [symbol, { data_date: null, count: 0 }]
            try {
                const data = await request(symbol, requestSignal)
                throwIfAborted(requestSignal)
                const badge = {
                    data_date: data?.data_date || null,
                    count: Array.isArray(data?.items) ? data.items.length : 0,
                }
                if (badge.data_date && Array.isArray(data?.items)) cache?.set(symbol, badge)
                return [symbol, badge]
            } catch (error) {
                throwIfAborted(requestSignal)
                if (error?.response?.status === 429) rateLimited = true

                return [symbol, { data_date: null, count: 0 }]
            }
        },
    )

    return Object.fromEntries(entries)
}
