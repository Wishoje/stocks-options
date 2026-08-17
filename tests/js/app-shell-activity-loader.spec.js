import { describe, expect, it, vi } from 'vitest'

import { loadUnusualActivityBadges } from '@/Support/app-shell-activity-loader.js'

describe('AppShell unusual-activity loader', () => {
    it('bounds request concurrency while retaining every symbol result', async () => {
        let active = 0
        let maximumActive = 0
        const request = vi.fn(async (symbol) => {
            active += 1
            maximumActive = Math.max(maximumActive, active)
            await new Promise((resolve) => setTimeout(resolve, 5))
            active -= 1

            return {
                data_date: '2026-08-17',
                items: Array.from({ length: symbol.length }, () => ({})),
            }
        })

        const symbols = ['SPY', 'QQQ', 'AAPL', 'TSLA', 'NVDA', 'AMD', 'META']
        const result = await loadUnusualActivityBadges(symbols, request, { concurrency: 3 })

        expect(maximumActive).toBe(3)
        expect(request).toHaveBeenCalledTimes(symbols.length)
        expect(Object.keys(result)).toEqual(symbols)
        expect(result.SPY).toEqual({ data_date: '2026-08-17', count: 3 })
        expect(result.AAPL).toEqual({ data_date: '2026-08-17', count: 4 })
    })

    it('deduplicates symbols and preserves the empty badge for individual errors', async () => {
        const request = vi.fn(async (symbol) => {
            if (symbol === 'QQQ') throw new Error('upstream timeout')

            return { data_date: '2026-08-16', items: [{}, {}] }
        })

        const result = await loadUnusualActivityBadges(
            ['SPY', 'QQQ', 'SPY', 'AAPL'],
            request,
            { concurrency: 2 },
        )

        expect(request).toHaveBeenCalledTimes(3)
        expect(result).toEqual({
            SPY: { data_date: '2026-08-16', count: 2 },
            QQQ: { data_date: null, count: 0 },
            AAPL: { data_date: '2026-08-16', count: 2 },
        })
    })

    it('stops queued work when its owning component aborts the load', async () => {
        const controller = new AbortController()
        const started = []
        const request = vi.fn((symbol, signal) => new Promise((resolve, reject) => {
            started.push(symbol)
            signal.addEventListener('abort', () => reject(signal.reason), { once: true })
        }))

        const pending = loadUnusualActivityBadges(
            ['SPY', 'QQQ', 'AAPL', 'TSLA'],
            request,
            { concurrency: 2, signal: controller.signal },
        )

        await vi.waitFor(() => expect(started).toHaveLength(2))
        controller.abort()

        await expect(pending).rejects.toMatchObject({ name: 'AbortError' })
        expect(started).toEqual(['SPY', 'QQQ'])
    })
})
