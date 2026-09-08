import { describe, expect, it, vi } from 'vitest'
import { createCalculatorChartScheduler } from '@/Support/calculator-chart-scheduler.js'

describe('calculator chart scheduler', () => {
    it('coalesces 100 invalidations into one render of the latest state', () => {
        let frame
        let value = 0
        const seen = []
        const requestFrame = vi.fn((callback) => { frame = callback; return 0 })
        const scheduler = createCalculatorChartScheduler(() => seen.push(value), { requestFrame })

        for (value = 0; value < 100; value++) scheduler.schedule()

        expect(requestFrame).toHaveBeenCalledTimes(1)
        expect(seen).toEqual([])
        frame()
        expect(seen).toEqual([100])
        scheduler.schedule()
        expect(requestFrame).toHaveBeenCalledTimes(2)
        frame()
        expect(seen).toEqual([100, 100])
    })

    it('cancels pending work on disposal, including handle zero and a late callback', () => {
        let frame
        const render = vi.fn()
        const cancelFrame = vi.fn()
        const requestFrame = vi.fn((callback) => { frame = callback; return 0 })
        const scheduler = createCalculatorChartScheduler(render, { requestFrame, cancelFrame })

        scheduler.schedule()
        scheduler.dispose()
        scheduler.dispose()
        frame()
        scheduler.schedule()

        expect(cancelFrame).toHaveBeenCalledExactlyOnceWith(0)
        expect(requestFrame).toHaveBeenCalledTimes(1)
        expect(render).not.toHaveBeenCalled()
    })
})
