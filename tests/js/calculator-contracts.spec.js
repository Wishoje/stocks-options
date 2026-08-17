import { describe, expect, it } from 'vitest'

import {
    calculateLongOption,
    closestContract,
    contractFamilyIdentity,
    contractIdentity,
    groupContractsByStrike,
    longOptionPayoff,
    selectContractState,
    switchContractType,
} from '@/Support/calculator-contracts.js'

const call = {
    symbol: 'SPY',
    contract_symbol: 'O:SPY260918C00100000',
    expiration_date: '2026-09-18',
    strike: 100,
    type: 'call',
    mid: 5,
    iv: 0.2,
}

const put = {
    symbol: 'SPY',
    contract_symbol: 'O:SPY260918P00100000',
    expiration_date: '2026-09-18',
    strike: 100,
    type: 'put',
    mid: 8,
    iv: 0.45,
}

describe('calculator contract state', () => {
    it('switches type, identity, premium, IV, and payoff inputs atomically', () => {
        const switched = switchContractType({
            chain: [call, put],
            selectedContract: call,
            targetType: 'put',
            entryMode: 'auto',
            entryPrice: 5,
        })

        expect(switched.optionType).toBe('put')
        expect(switched.selectedOption.contract_symbol).toBe(put.contract_symbol)
        expect(switched.selectedOption.type).toBe('put')
        expect(switched.selectedOption.premium).toBe(8)
        expect(switched.selectedOption.iv).toBe(0.45)
        expect(switched.entryPrice).toBe(8)

        expect(calculateLongOption({
            selectedContract: switched.selectedOption,
            entryPrice: switched.entryPrice,
        })).toMatchObject({
            type: 'put',
            cost: 800,
            max_loss: -800,
            breakeven: 92,
            premium: 8,
            iv: 0.45,
        })
        expect(longOptionPayoff({
            selectedContract: switched.selectedOption,
            entryPrice: switched.entryPrice,
            underlyingPrice: 80,
        })).toBe(1200)

        const switchedBack = switchContractType({
            chain: [call, put],
            selectedContract: switched.selectedOption,
            targetType: 'call',
            entryMode: 'auto',
            entryPrice: switched.entryPrice,
        })
        expect(calculateLongOption({
            selectedContract: switchedBack.selectedOption,
            entryPrice: switchedBack.entryPrice,
        })).toMatchObject({ cost: 500, breakeven: 105, type: 'call' })
    })

    it('preserves a clearly manual entry across a valid counterpart switch', () => {
        const switched = switchContractType({
            chain: [call, put],
            selectedContract: call,
            targetType: 'put',
            entryMode: 'manual',
            entryPrice: 3,
        })

        expect(switched.entryMode).toBe('manual')
        expect(switched.entryPrice).toBe(3)
        expect(switched.selectedOption.premium).toBe(8)
        expect(calculateLongOption({
            selectedContract: switched.selectedOption,
            entryPrice: switched.entryPrice,
        })).toMatchObject({ cost: 300, breakeven: 97 })
        expect(longOptionPayoff({
            selectedContract: switched.selectedOption,
            entryPrice: switched.entryPrice,
            underlyingPrice: 80,
        })).toBe(1700)
    })

    it('clears the selected contract when the exact counterpart is absent', () => {
        const switched = switchContractType({
            chain: [call],
            selectedContract: call,
            targetType: 'put',
            entryMode: 'auto',
        })

        expect(switched.optionType).toBe('put')
        expect(switched.selectedOption).toBeNull()
        expect(switched.entryPrice).toBeNull()
        expect(calculateLongOption({
            selectedContract: switched.selectedOption,
            entryPrice: switched.entryPrice,
        })).toBeNull()
    })

    it('chooses the closest requested-type strike instead of rounding or falling back to the first row', () => {
        const chain = [
            { ...put, strike: 80, contract_symbol: 'PUT-80' },
            { ...call, strike: 104, contract_symbol: 'CALL-104' },
            { ...call, strike: 101, contract_symbol: 'CALL-101' },
        ]

        expect(closestContract(chain, 'call', 102)?.contract_symbol).toBe('CALL-101')
    })

    it('uses strike then stable identity as deterministic distance tie breakers', () => {
        const chain = [
            { ...call, strike: 103, contract_symbol: 'CALL-103-B' },
            { ...call, strike: 101, contract_symbol: 'CALL-101' },
            { ...call, strike: 103, contract_symbol: 'CALL-103-A' },
        ]

        expect(closestContract(chain, 'call', 102)?.contract_symbol).toBe('CALL-101')
    })

    it('prefers provider identity and otherwise creates a stable composite identity', () => {
        expect(contractIdentity(call)).toBe(call.contract_symbol)
        expect(contractIdentity({
            symbol: 'spy',
            expiry: '2026-09-18',
            strike: '100.00',
            type: 'CALL',
        })).toBe('SPY|2026-09-18|100|call')
    })

    it('updates automatic entry on selection and retains manual entry', () => {
        expect(selectContractState({ contract: call })).toMatchObject({
            optionType: 'call',
            entryMode: 'auto',
            entryPrice: 5,
        })
        expect(selectContractState({
            contract: put,
            entryMode: 'manual',
            entryPrice: 3,
        })).toMatchObject({
            optionType: 'put',
            entryMode: 'manual',
            entryPrice: 3,
        })
    })

    it('keeps adjusted same-strike contracts in distinct deterministic rows', () => {
        const adjustedCall = {
            ...call,
            contract_symbol: 'O:SPY1260918C00100000',
            mid: 3,
        }
        const adjustedPut = {
            ...put,
            contract_symbol: 'O:SPY1260918P00100000',
            mid: 4,
        }
        const contracts = [adjustedPut, call, adjustedCall, put]
        const rows = groupContractsByStrike(contracts)

        expect(rows).toHaveLength(2)
        expect(rows.map((row) => [
            row.family_label,
            row.call?.contract_symbol,
            row.put?.contract_symbol,
        ])).toEqual([
            ['SPY', call.contract_symbol, put.contract_symbol],
            ['SPY1', adjustedCall.contract_symbol, adjustedPut.contract_symbol],
        ])
        expect(rows.every((row) => row.show_family)).toBe(true)
        expect(groupContractsByStrike([...contracts].reverse()).map((row) => row.key))
            .toEqual(rows.map((row) => row.key))
        expect(contractFamilyIdentity(call)).not.toBe(contractFamilyIdentity(adjustedCall))
    })

    it('switches only to the exact provider-family counterpart', () => {
        const adjustedCall = {
            ...call,
            contract_symbol: 'O:SPY1260918C00100000',
            mid: 3,
        }
        const adjustedPut = {
            ...put,
            contract_symbol: 'O:SPY1260918P00100000',
            mid: 4,
        }

        expect(switchContractType({
            chain: [put, adjustedPut, adjustedCall, call],
            selectedContract: adjustedCall,
            targetType: 'put',
        }).selectedOption?.contract_symbol).toBe(adjustedPut.contract_symbol)

        expect(switchContractType({
            chain: [put, adjustedCall, call],
            selectedContract: adjustedCall,
            targetType: 'put',
        }).selectedOption).toBeNull()
    })

    it('groups and switches ticker-null backend identities as one family', () => {
        const fallbackCall = {
            ...call,
            symbol: undefined,
            contract_symbol: 'SPY|2026-09-18|call|100.000000',
        }
        const fallbackPut = {
            ...put,
            symbol: undefined,
            contract_symbol: 'SPY|2026-09-18|put|100.000000',
        }
        const rows = groupContractsByStrike([fallbackPut, fallbackCall])

        expect(rows).toHaveLength(1)
        expect(rows[0]).toMatchObject({
            family_label: 'SPY',
            call: { contract_symbol: fallbackCall.contract_symbol },
            put: { contract_symbol: fallbackPut.contract_symbol },
        })
        expect(switchContractType({
            chain: [fallbackPut, fallbackCall],
            selectedContract: fallbackCall,
            targetType: 'put',
        }).selectedOption?.contract_symbol).toBe(fallbackPut.contract_symbol)
    })
})
