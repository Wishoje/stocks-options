const finiteIndex = (value) => {
    const number = Number(value)

    return Number.isInteger(number) ? number : null
}

export const clampScenarioIndex = (value, length) => {
    const size = Math.max(0, Number.isFinite(Number(length)) ? Math.trunc(Number(length)) : 0)
    if (size === 0) return null

    const index = finiteIndex(value)
    if (index === null) return 0

    return Math.min(size - 1, Math.max(0, index))
}

export const moveScenarioIndex = (value, direction, length) => {
    const current = clampScenarioIndex(value, length)
    if (current === null) return null

    if (direction === 'first') return 0
    if (direction === 'last') return Math.max(0, Math.trunc(Number(length)) - 1)

    const step = direction === 'previous' ? -1 : 1

    return clampScenarioIndex(current + step, length)
}

export const nearestScenarioIndex = (rows, target, field = 'price') => {
    if (!Array.isArray(rows) || rows.length === 0) return null

    const targetNumber = Number(target)
    if (!Number.isFinite(targetNumber)) return 0

    let bestIndex = 0
    let bestDistance = Number.POSITIVE_INFINITY

    rows.forEach((row, index) => {
        const value = Number(row?.[field])
        if (!Number.isFinite(value)) return

        const distance = Math.abs(value - targetNumber)
        if (distance < bestDistance) {
            bestDistance = distance
            bestIndex = index
        }
    })

    return bestIndex
}

/** Maps an exact scenario value onto a chart area without snapping to a category. */
export const scenarioValuePixel = (value, domainStart, domainEnd, pixelStart, pixelEnd) => {
    const input = Number(value)
    const start = Number(domainStart)
    const end = Number(domainEnd)
    const left = Number(pixelStart)
    const right = Number(pixelEnd)

    if (![input, start, end, left, right].every(Number.isFinite) || end <= start || right < left) {
        return null
    }
    if (input < start || input > end) return null

    return left + ((input - start) / (end - start)) * (right - left)
}
