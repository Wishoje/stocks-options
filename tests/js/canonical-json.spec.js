import { describe, expect, it } from 'vitest'
import { canonicalize, canonicalJson } from './support/canonical-json.js'

describe('canonical JSON regression artifacts', () => {
    it('sorts object keys recursively without changing meaningful array order', () => {
        const value = {
            z: 1,
            payload: {
                symbols: ['SPY', 'QQQ'],
                aggregate: { volume: 25, open_interest: 40 },
            },
            a: 2,
        }

        expect(canonicalJson(value)).toBe(
            '{"a":2,"payload":{"aggregate":{"open_interest":40,"volume":25},"symbols":["SPY","QQQ"]},"z":1}',
        )
    })

    it('makes configured unordered option rows deterministic', () => {
        const first = {
            chain: [
                { expiration: '2026-07-24', strike: 600, option_type: 'put', volume: 12 },
                { expiration: '2026-07-17', strike: 590, option_type: 'call', volume: 20 },
                { expiration: '2026-07-17', strike: 590, option_type: 'put', volume: 18 },
            ],
        }
        const second = { chain: [first.chain[2], first.chain[0], first.chain[1]] }
        const options = {
            unorderedArrays: {
                chain: ['expiration', 'strike', 'option_type'],
            },
        }

        expect(canonicalJson(first, options)).toBe(canonicalJson(second, options))
    })

    it('keeps aggregate changes visible after canonicalization', () => {
        const baseline = { status: 'ok', totals: { call_volume: 100, put_volume: 80 } }
        const candidate = { status: 'ok', totals: { call_volume: 99, put_volume: 80 } }

        expect(canonicalJson(candidate)).not.toBe(canonicalJson(baseline))
    })

    it('normalizes dates and negative zero while rejecting invalid numeric data', () => {
        expect(canonicalize({ at: new Date('2026-07-16T12:00:00Z'), value: -0 })).toEqual({
            at: '2026-07-16T12:00:00.000Z',
            value: 0,
        })

        expect(() => canonicalize({ total: Number.NaN })).toThrow(/non-finite number at total/)
    })
})
