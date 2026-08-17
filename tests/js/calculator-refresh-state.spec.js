import { describe, expect, it, vi } from 'vitest'

import {
    abortableDelay,
    calculatorProgress,
    expirationReadinessMap,
    readyExpiryToken,
    retryDelayMs,
    terminalRunState,
    workRunFromResponse,
} from '@/Support/calculator-refresh-state.js'

describe('calculator refresh state', () => {
    it('normalizes 200 and 202 work-start responses', () => {
        const expected = {
            run_id: 'run-1',
            status_url: '/api/work-runs/run-1',
            status: 'pending',
            terminal: false,
            retry_after_seconds: 2,
        }

        expect(workRunFromResponse({ status: 202, data: {
            run_id: 'run-1',
            status_url: '/api/work-runs/run-1',
            status: 'pending',
            terminal: false,
            retry_after_seconds: 2,
        } })).toEqual(expected)
        expect(workRunFromResponse({ status: 200, data: {
            coalesced: true,
            work_run: expected,
        } })).toEqual(expected)
    })

    it('honors Retry-After while bounding unsafe delays', () => {
        expect(retryDelayMs({ headers: { 'retry-after': '3' } })).toBe(3_000)
        expect(retryDelayMs({ headers: { 'retry-after': '60' } })).toBe(60_000)
        expect(retryDelayMs({ data: { retry_after_seconds: 0 } })).toBe(250)
        expect(retryDelayMs({ data: { retry_after_seconds: 999 } })).toBe(60_000)
        expect(retryDelayMs({ data: { retry_after_seconds: 4 }, headers: { 'retry-after': '9' } })).toBe(9_000)
    })

    it('honors an HTTP-date Retry-After header', () => {
        vi.useFakeTimers()
        vi.setSystemTime(new Date('2026-08-16T12:00:00Z'))

        expect(retryDelayMs({
            headers: { 'retry-after': 'Sun, 16 Aug 2026 12:01:00 GMT' },
        })).toBe(60_000)

        vi.useRealTimers()
    })

    it('extracts lightweight per-expiry progress and stable tokens', () => {
        const progress = calculatorProgress({
            status: 'running',
            calculator: {
                expected_count: 2,
                completed_count: 1,
                failed_count: 0,
                expirations: [
                    { expiration: '2026-08-21', readiness: 'ready', publication_id: 22 },
                    { expiration: '2026-08-28', readiness: 'pending' },
                ],
            },
        })

        expect(progress).toMatchObject({ expected_count: 2, completed_count: 1 })
        expect(progress.readiness['2026-08-21'].readiness).toBe('ready')
        expect(readyExpiryToken(progress)).toBe('2026-08-21:22')
    })

    it('recognizes terminal success and failure', () => {
        expect(terminalRunState({ status: 'completed', terminal: true })).toBe('completed')
        expect(terminalRunState({
            status: 'completed',
            terminal: true,
            calculator: { status: 'capped' },
        })).toBe('failed')
        expect(terminalRunState({ status: 'failed', terminal: true })).toBe('failed')
        expect(terminalRunState({ status: 'running', terminal: false })).toBeNull()
    })

    it('normalizes readiness from map, list, and expiration payloads', () => {
        expect(expirationReadinessMap({
            expiry_readiness: {
                '2026-08-21': { status: 'ready' },
            },
            expirations: [
                { value: '2026-08-28', readiness: 'pending' },
            ],
        })).toEqual({
            '2026-08-21': 'ready',
            '2026-08-28': 'pending',
        })
    })

    it('cancels a pending delay without leaving a timer alive', async () => {
        vi.useFakeTimers()
        const controller = new AbortController()
        const pending = abortableDelay(90_000, controller.signal)

        controller.abort()

        await expect(pending).rejects.toMatchObject({ name: 'AbortError' })
        expect(vi.getTimerCount()).toBe(0)
        vi.useRealTimers()
    })
})
