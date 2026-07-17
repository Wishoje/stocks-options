function compareScalar(left, right) {
    if (left === right) return 0
    if (left === null) return -1
    if (right === null) return 1

    const leftValue = typeof left === 'string' ? left : JSON.stringify(left)
    const rightValue = typeof right === 'string' ? right : JSON.stringify(right)

    return leftValue < rightValue ? -1 : 1
}

function valueAtPath(value, path) {
    return path.split('.').reduce((current, segment) => current?.[segment], value)
}

function compareByFields(fields) {
    return (left, right) => {
        for (const field of fields) {
            const result = compareScalar(valueAtPath(left, field), valueAtPath(right, field))
            if (result !== 0) return result
        }

        return compareScalar(JSON.stringify(left), JSON.stringify(right))
    }
}

function normalizeNumber(value, path) {
    if (!Number.isFinite(value)) {
        throw new TypeError(`Cannot canonicalize a non-finite number at ${path || '<root>'}`)
    }

    return Object.is(value, -0) ? 0 : value
}

function walk(value, path, unorderedArrays) {
    if (value instanceof Date) {
        if (Number.isNaN(value.getTime())) {
            throw new TypeError(`Cannot canonicalize an invalid date at ${path || '<root>'}`)
        }

        return value.toISOString()
    }

    if (typeof value === 'number') return normalizeNumber(value, path)

    if (Array.isArray(value)) {
        const normalized = value.map((item, index) => walk(item, `${path}[${index}]`, unorderedArrays))
        const fields = unorderedArrays[path]

        return fields ? [...normalized].sort(compareByFields(fields)) : normalized
    }

    if (value && typeof value === 'object') {
        return Object.keys(value)
            .filter((key) => value[key] !== undefined)
            .sort()
            .reduce((result, key) => {
                const childPath = path ? `${path}.${key}` : key
                result[key] = walk(value[key], childPath, unorderedArrays)
                return result
            }, {})
    }

    return value
}

export function canonicalize(value, options = {}) {
    return walk(value, '', options.unorderedArrays ?? {})
}

export function canonicalJson(value, options = {}) {
    return JSON.stringify(canonicalize(value, options))
}
