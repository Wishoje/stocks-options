const finiteNumber = (value) => {
    if (value === null || value === undefined || value === '') return null

    const number = typeof value === 'string' ? Number.parseFloat(value) : Number(value)

    return Number.isFinite(number) ? number : null
}

const strictFiniteNumber = (value) => {
    if (!['number', 'string'].includes(typeof value)) return null
    if (value === null || value === undefined || value === '') return null
    if (typeof value === 'string' && value.trim() === '') return null

    const number = Number(value)

    return Number.isFinite(number) ? number : null
}

export const validContractQuantity = (value) => {
    const quantity = strictFiniteNumber(value)

    return Number.isInteger(quantity) && quantity > 0 ? quantity : null
}

export const contractPremiumQuote = (contract) => {
    if (!contract) return null

    for (const [source, candidate] of [
        ['mid', contract.mid],
        ['mark', contract.mark],
        ['mid_price', contract.mid_price],
        ['mid_price', contract.midPrice],
        ['price', contract.price],
        ['last', contract.last],
        ['fmv', contract.fmv],
    ]) {
        const value = finiteNumber(candidate)
        if (value !== null && value > 0) return { value, source }
    }

    const bid = finiteNumber(contract.bid ?? contract.bid_price ?? contract.b)
    const ask = finiteNumber(contract.ask ?? contract.ask_price ?? contract.a)

    if (bid !== null && bid > 0 && ask !== null && ask > 0) {
        return { value: (bid + ask) / 2, source: 'bid_ask_midpoint' }
    }
    if (bid !== null && bid > 0) return { value: bid, source: 'bid' }
    if (ask !== null && ask > 0) return { value: ask, source: 'ask' }

    return null
}

export const contractPremium = (contract) => contractPremiumQuote(contract)?.value ?? null

export const contractIdentity = (contract) => {
    if (!contract) return null

    const providerIdentity = String(contract.contract_symbol ?? contract.ticker ?? '').trim()
    if (providerIdentity) return providerIdentity

    const expiration = String(contract.expiration_date ?? contract.expiry ?? '').slice(0, 10)
    const strike = finiteNumber(contract.strike)
    const type = String(contract.type ?? '').toLowerCase()
    const symbol = String(contract.symbol ?? '').trim().toUpperCase()
    if (!expiration || strike === null || !['call', 'put'].includes(type)) return null

    return [symbol || '*', expiration, strike.toString(), type].join('|')
}

const providerContractParts = (contract) => {
    const identity = String(contract?.contract_symbol ?? contract?.ticker ?? '').trim()
    const match = identity.match(/^((?:O:)?)(.+?)(\d{6})([CP])(\d{8})$/i)
    if (!match) return null

    return {
        prefix: match[1].toUpperCase(),
        root: match[2].toUpperCase(),
        expirationCode: match[3],
        typeCode: match[4].toUpperCase(),
        strikeCode: match[5],
    }
}

const compositeContractParts = (contract) => {
    const identity = String(contract?.contract_symbol ?? '').trim()
    const parts = identity.split('|')
    if (parts.length !== 4) return null

    const providerOrder = ['call', 'put'].includes(parts[2]?.toLowerCase())
    const type = String(providerOrder ? parts[2] : parts[3]).toLowerCase()
    const strike = finiteNumber(providerOrder ? parts[3] : parts[2])
    const symbol = String(parts[0] ?? '').trim().toUpperCase()
    const expiration = String(parts[1] ?? '').slice(0, 10)
    if (!symbol || !expiration || strike === null || !['call', 'put'].includes(type)) return null

    return { symbol, expiration, strike }
}

export const contractFamilyIdentity = (contract) => {
    if (!contract) return null

    const provider = providerContractParts(contract)
    if (provider) {
        return [
            'occ',
            provider.root,
            provider.expirationCode,
            provider.strikeCode,
        ].join('|')
    }

    const composite = compositeContractParts(contract)
    if (composite) {
        return [
            'family',
            composite.symbol,
            composite.expiration,
            composite.strike.toString(),
            '*',
        ].join('|')
    }

    const expiration = String(contract.expiration_date ?? contract.expiry ?? '').slice(0, 10)
    const strike = finiteNumber(contract.strike)
    const family = String(
        contract.deliverable_id
        ?? contract.contract_family
        ?? contract.root_symbol
        ?? contract.option_root
        ?? contract.symbol
        ?? '',
    ).trim().toUpperCase()
    if (!family || !expiration || strike === null) return null

    const multiplier = finiteNumber(contract.shares_per_contract ?? contract.multiplier)

    return ['family', family, expiration, strike.toString(), multiplier ?? '*'].join('|')
}

export const contractFamilyLabel = (contract) => {
    const provider = providerContractParts(contract)
    if (provider) return provider.root

    const composite = compositeContractParts(contract)
    if (composite) return composite.symbol

    return String(
        contract?.root_symbol
        ?? contract?.option_root
        ?? contract?.symbol
        ?? 'Contract',
    ).trim().toUpperCase()
}

export const normalizeContract = (contract) => {
    if (!contract) return null

    const type = String(contract.type ?? '').toLowerCase()
    const strike = finiteNumber(contract.strike)
    const expiration = String(contract.expiration_date ?? contract.expiry ?? '').slice(0, 10)
    if (!['call', 'put'].includes(type) || strike === null || strike <= 0 || !expiration) return null

    const premiumQuote = contractPremiumQuote(contract)
    const premium = premiumQuote?.value ?? null
    const iv = finiteNumber(contract.iv ?? contract.implied_volatility)
    const rawDte = finiteNumber(contract.dte)
    const normalized = {
        ...contract,
        contract_symbol: contractIdentity(contract),
        expiration_date: expiration,
        expiry: expiration,
        strike,
        type,
        premium,
        premium_source: premiumQuote?.source ?? null,
        iv: iv !== null && iv > 0 ? iv : null,
        dte: rawDte !== null && rawDte >= 0 ? Math.trunc(rawDte) : null,
    }

    return normalized.contract_symbol ? normalized : null
}

export const closestContract = (chain, type, spot) => {
    const targetType = String(type ?? '').toLowerCase()
    const targetSpot = finiteNumber(spot)
    if (!['call', 'put'].includes(targetType) || targetSpot === null || targetSpot <= 0) return null

    return (Array.isArray(chain) ? chain : [])
        .map(normalizeContract)
        .filter((contract) => contract?.type === targetType)
        .sort((left, right) => {
            const distance = Math.abs(left.strike - targetSpot) - Math.abs(right.strike - targetSpot)
            if (distance !== 0) return distance
            if (left.strike !== right.strike) return left.strike - right.strike

            return left.contract_symbol.localeCompare(right.contract_symbol)
        })[0] ?? null
}

export const groupContractsByStrike = (chain) => {
    const normalized = (Array.isArray(chain) ? chain : [])
        .map(normalizeContract)
        .filter(Boolean)
        .sort((left, right) => {
            if (left.strike !== right.strike) return left.strike - right.strike

            const family = String(contractFamilyIdentity(left) ?? '')
                .localeCompare(String(contractFamilyIdentity(right) ?? ''))
            if (family !== 0) return family
            if (left.type !== right.type) return left.type === 'call' ? -1 : 1

            return left.contract_symbol.localeCompare(right.contract_symbol)
        })
    const groups = new Map()

    normalized.forEach((contract) => {
        const family = contractFamilyIdentity(contract) ?? `identity|${contract.contract_symbol}`
        const baseKey = `${contract.strike}|${family}`
        let key = baseKey
        let group = groups.get(key)

        if (group?.[contract.type]) {
            key = `${baseKey}|${contract.type}|${contract.contract_symbol}`
            group = groups.get(key)
        }
        if (!group) {
            group = {
                key,
                strike: contract.strike,
                family,
                family_label: contractFamilyLabel(contract),
                call: null,
                put: null,
            }
            groups.set(key, group)
        }

        group[contract.type] = contract
    })

    const rows = [...groups.values()]
    const strikeCounts = rows.reduce((counts, row) => {
        counts.set(row.strike, (counts.get(row.strike) ?? 0) + 1)
        return counts
    }, new Map())

    return rows.map((row) => ({
        ...row,
        show_family: (strikeCounts.get(row.strike) ?? 0) > 1,
    }))
}

export const counterpartForType = (chain, selectedContract, targetType) => {
    const selected = normalizeContract(selectedContract)
    const type = String(targetType ?? '').toLowerCase()
    if (!selected || !['call', 'put'].includes(type)) return null
    if (selected.type === type) return selected

    const matches = (Array.isArray(chain) ? chain : [])
        .map(normalizeContract)
        .filter((contract) => contract
            && contract.type === type
            && contract.expiration_date === selected.expiration_date
            && contract.strike === selected.strike)
        .sort((left, right) => left.contract_symbol.localeCompare(right.contract_symbol))

    const selectedProvider = providerContractParts(selected)
    if (selectedProvider) {
        const expectedIdentity = [
            selectedProvider.prefix,
            selectedProvider.root,
            selectedProvider.expirationCode,
            type === 'call' ? 'C' : 'P',
            selectedProvider.strikeCode,
        ].join('')
        const exact = matches.find((contract) => (
            String(contract.contract_symbol).toUpperCase() === expectedIdentity
        ))

        return exact ?? null
    }

    const family = contractFamilyIdentity(selected)
    const familyMatches = family === null
        ? []
        : matches.filter((contract) => contractFamilyIdentity(contract) === family)

    return familyMatches.length === 1 ? familyMatches[0] : null
}

export const selectContractState = ({ contract, entryMode = 'auto', entryPrice = null }) => {
    const selectedOption = normalizeContract(contract)
    const manual = entryMode === 'manual'

    return {
        optionType: selectedOption?.type ?? null,
        selectedOption,
        entryMode: manual ? 'manual' : 'auto',
        entryPrice: manual ? entryPrice : selectedOption?.premium ?? null,
    }
}

export const switchContractType = ({
    chain,
    selectedContract,
    targetType,
    entryMode = 'auto',
    entryPrice = null,
}) => {
    const counterpart = counterpartForType(chain, selectedContract, targetType)
    const state = selectContractState({
        contract: counterpart,
        entryMode,
        entryPrice,
    })

    return {
        ...state,
        optionType: String(targetType ?? '').toLowerCase(),
    }
}

export const calculateLongOption = ({ selectedContract, entryPrice, contracts = 1 }) => {
    const selected = normalizeContract(selectedContract)
    const premium = strictFiniteNumber(entryPrice)
    const quantity = validContractQuantity(contracts)
    if (!selected || premium === null || premium <= 0 || quantity === null) return null

    const cost = premium * 100 * quantity
    const breakeven = selected.type === 'call'
        ? selected.strike + premium
        : selected.strike - premium

    return {
        contract_symbol: selected.contract_symbol,
        type: selected.type,
        strike: selected.strike,
        expiration_date: selected.expiration_date,
        premium,
        iv: selected.iv,
        contracts: quantity,
        cost,
        max_loss: -cost,
        breakeven,
    }
}

/**
 * Expiration profit potential for a long option. Calls have unbounded upside;
 * puts reach their best expiration outcome when the underlying reaches zero.
 */
export const longOptionProfitPotential = ({ selectedContract, entryPrice, contracts = 1 }) => {
    const summary = calculateLongOption({ selectedContract, entryPrice, contracts })
    if (!summary) return null

    if (summary.type === 'call') {
        return {
            unlimited: true,
            maximum_profit: null,
            reward_to_risk: null,
        }
    }

    const maximumProfit = Math.max(
        0,
        (summary.strike - summary.premium) * 100 * summary.contracts,
    )

    return {
        unlimited: false,
        maximum_profit: maximumProfit,
        reward_to_risk: summary.cost > 0 && maximumProfit > 0
            ? maximumProfit / summary.cost
            : null,
    }
}

export const longOptionPayoff = ({ selectedContract, entryPrice, contracts = 1, underlyingPrice }) => {
    const summary = calculateLongOption({ selectedContract, entryPrice, contracts })
    const underlying = finiteNumber(underlyingPrice)
    if (!summary || underlying === null || underlying < 0) return null

    const intrinsic = summary.type === 'call'
        ? Math.max(underlying - summary.strike, 0)
        : Math.max(summary.strike - underlying, 0)

    return (intrinsic - summary.premium) * 100 * summary.contracts
}
