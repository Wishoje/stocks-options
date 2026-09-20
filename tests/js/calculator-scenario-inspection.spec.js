import { describe, expect, it } from 'vitest'

import {
    clampScenarioIndex,
    moveScenarioIndex,
    nearestScenarioIndex,
    scenarioValuePixel,
} from '@/Support/calculator-scenario-inspection.js'

describe('calculator scenario inspection', () => {
    it('keeps keyboard and select indices inside the exact returned row range', () => {
        expect(clampScenarioIndex(-4, 3)).toBe(0)
        expect(clampScenarioIndex(9, 3)).toBe(2)
        expect(clampScenarioIndex(1, 3)).toBe(1)
        expect(clampScenarioIndex(0, 0)).toBeNull()
    })

    it('moves deterministically with arrow, home, and end semantics', () => {
        expect(moveScenarioIndex(1, 'previous', 3)).toBe(0)
        expect(moveScenarioIndex(1, 'next', 3)).toBe(2)
        expect(moveScenarioIndex(0, 'previous', 3)).toBe(0)
        expect(moveScenarioIndex(2, 'next', 3)).toBe(2)
        expect(moveScenarioIndex(1, 'first', 3)).toBe(0)
        expect(moveScenarioIndex(1, 'last', 3)).toBe(2)
    })

    it('chooses the closest exact scenario without changing any row value', () => {
        const rows = [
            { price: 90.125, pnl: -500 },
            { price: 100.375, pnl: 0 },
            { price: 110.625, pnl: 525.75 },
        ]

        expect(nearestScenarioIndex(rows, 104)).toBe(1)
        expect(nearestScenarioIndex(rows, 109)).toBe(2)
        expect(rows[2]).toEqual({ price: 110.625, pnl: 525.75 })
    })

    it('maps a between-grid reference to its exact linear x coordinate', () => {
        expect(scenarioValuePixel(100.5, 100, 102, 20, 220)).toBe(70)
        expect(scenarioValuePixel(101, 100, 102, 20, 220)).toBe(120)
        expect(scenarioValuePixel(99, 100, 102, 20, 220)).toBeNull()
        expect(scenarioValuePixel(100, 100, 100, 20, 220)).toBeNull()
    })
})
