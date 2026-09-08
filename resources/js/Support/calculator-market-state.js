const finiteNumber = (value) => {
    if (value === null || value === undefined || value === '') return null

    const number = typeof value === 'string' ? Number.parseFloat(value) : Number(value)

    return Number.isFinite(number) ? number : null
}

/** A user scenario must be a complete, positive finite number, never a prefix. */
export const positiveCalculatorPrice = (value) => {
    if (!['number', 'string'].includes(typeof value)) return null
    if (typeof value === 'string' && value.trim() === '') return null

    const price = Number(value)

    return Number.isFinite(price) && price > 0 ? price : null
}

/** Manual scenarios are explicit; invalid input must not fall back to a quote. */
export const calculatorUnderlyingPrice = (underlying, manual, manualPrice) => {
    if (manual) return positiveCalculatorPrice(manualPrice)

    return underlying?.usable === true ? positiveCalculatorPrice(underlying.price) : null
}

/**
 * Normalize the server's underlying quote contract without inventing a price.
 *
 * New responses explicitly include status and usable. A positive price-only
 * response remains supported during a rolling deployment, but an explicitly
 * unavailable or unusable response always wins over the numeric field.
 */
export const normalizeUnderlying = (underlying) => {
    const value = underlying && typeof underlying === 'object' ? underlying : {}
    const price = positiveCalculatorPrice(value.price)
    const status = String(value.status ?? (price === null ? 'unavailable' : 'live')).toLowerCase()
    const structured = Object.hasOwn(value, 'status')
        || Object.hasOwn(value, 'usable')
        || Object.hasOwn(value, 'usable_for_calculation')
    const explicitlyUsable = value.usable_for_calculation ?? value.usable
    const usable = price !== null
        && status !== 'unavailable'
        && status !== 'invalid'
        && (structured ? explicitlyUsable === true : true)

    return {
        symbol: String(value.symbol ?? '').trim().toUpperCase() || null,
        status,
        usable,
        price: usable ? price : null,
        source: value.source ?? null,
        asof: value.asof ?? null,
        age_seconds: finiteNumber(value.age_seconds),
        session: value.session ?? null,
    }
}

export const serverDte = (contract, expirations = []) => {
    const direct = finiteNumber(contract?.dte)
    if (direct !== null && direct >= 0) return Math.trunc(direct)

    const expiration = String(contract?.expiration_date ?? contract?.expiry ?? '').slice(0, 10)
    if (!expiration) return null

    const match = (Array.isArray(expirations) ? expirations : []).find((item) => {
        const value = String(item?.value ?? item?.expiration_date ?? item?.expiry ?? '').slice(0, 10)

        return value === expiration
    })
    const fromCatalog = finiteNumber(match?.dte)

    return fromCatalog !== null && fromCatalog >= 0 ? Math.trunc(fromCatalog) : null
}

export const attachServerDte = (contracts, expirations = []) => (
    (Array.isArray(contracts) ? contracts : []).map((contract) => ({
        ...contract,
        dte: serverDte(contract, expirations),
    }))
)
