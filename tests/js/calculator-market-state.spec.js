import { describe, expect, it } from 'vitest'

import {
    attachServerDte,
    normalizeUnderlying,
    serverDte,
} from '@/Support/calculator-market-state.js'

describe('calculator market state', () => {
    it('accepts an explicitly usable exact $100 quote', () => {
        expect(normalizeUnderlying({
            symbol: 'spy',
            status: 'live',
            usable_for_calculation: true,
            price: '100.00',
            source: 'massive',
        })).toMatchObject({
            symbol: 'SPY',
            status: 'live',
            usable: true,
            price: 100,
            source: 'massive',
        })
    })

    it.each([
        [{ status: 'unavailable', usable: false, price: null }],
        [{ status: 'unavailable', usable: false, price: 100 }],
        [{ status: 'live', usable: false, price: 100 }],
        [{ status: 'live', usable: true, price: 0 }],
    ])('never turns an unavailable quote into zero or a fallback price', (quote) => {
        expect(normalizeUnderlying(quote)).toMatchObject({
            usable: false,
            price: null,
        })
    })

    it('supports a positive legacy quote during a rolling deployment', () => {
        expect(normalizeUnderlying({ price: 595.25 })).toMatchObject({
            status: 'live',
            usable: true,
            price: 595.25,
        })
    })

    it('uses only server-provided DTE values', () => {
        const contract = { expiry: '2026-09-18' }
        const expirations = [{ value: '2026-09-18', dte: 33 }]

        expect(serverDte({ ...contract, dte: 34 }, expirations)).toBe(34)
        expect(serverDte(contract, expirations)).toBe(33)
        expect(serverDte(contract, [{ value: '2026-09-18' }])).toBeNull()
        expect(attachServerDte([contract], expirations)[0].dte).toBe(33)
    })
})
