export const APP_SHELL_UA_CONCURRENCY = 4

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
    } = {},
) {
    const uniqueSymbols = [...new Set(symbols.filter(Boolean))]
    const entries = await mapWithConcurrency(
        uniqueSymbols,
        concurrency,
        signal,
        async (symbol, requestSignal) => {
            try {
                const data = await request(symbol, requestSignal)

                return [symbol, {
                    data_date: data?.data_date || null,
                    count: Array.isArray(data?.items) ? data.items.length : 0,
                }]
            } catch (error) {
                throwIfAborted(requestSignal)

                return [symbol, { data_date: null, count: 0 }]
            }
        },
    )

    return Object.fromEntries(entries)
}
