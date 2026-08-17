const configuredRequestLimit = Number.parseInt(
    import.meta.env?.VITE_CALCULATOR_STATUS_MAX_REQUESTS ?? '50',
    10,
)

export const CALCULATOR_STATUS_MAX_REQUESTS = Number.isFinite(configuredRequestLimit)
    ? Math.min(100, Math.max(5, configuredRequestLimit))
    : 50
export const CALCULATOR_STATUS_DEFAULT_DELAY_MS = 2_000
export const CALCULATOR_STATUS_MIN_DELAY_MS = 250
export const CALCULATOR_STATUS_MAX_DELAY_MS = 60_000

const finiteNumber = (value) => {
    if (value === null || value === undefined || value === '') return null

    const number = typeof value === 'string' ? Number.parseFloat(value) : Number(value)

    return Number.isFinite(number) ? number : null
}

const secondsFromRetryAfter = (value, now = Date.now()) => {
    const seconds = finiteNumber(value)
    if (seconds !== null) return Math.max(0, seconds)

    const timestamp = Date.parse(String(value ?? ''))
    if (!Number.isFinite(timestamp)) return null

    return Math.max(0, (timestamp - now) / 1_000)
}

export const retryDelayMs = (response, fallbackMs = CALCULATOR_STATUS_DEFAULT_DELAY_MS) => {
    const dataSeconds = finiteNumber(response?.data?.retry_after_seconds)
    const header = response?.headers?.['retry-after'] ?? response?.headers?.get?.('retry-after')
    const headerSeconds = secondsFromRetryAfter(header)
    const directedDelays = [dataSeconds, headerSeconds].filter((value) => value !== null)
    const seconds = directedDelays.length ? Math.max(...directedDelays) : null
    const requested = seconds === null ? fallbackMs : seconds * 1_000

    return Math.min(
        CALCULATOR_STATUS_MAX_DELAY_MS,
        Math.max(CALCULATOR_STATUS_MIN_DELAY_MS, Math.round(requested)),
    )
}

export const workRunFromResponse = (response) => {
    const payload = response?.data ?? response ?? {}
    const nested = payload.work_run ?? payload.refresh_run ?? {}
    const runId = nested.run_id ?? nested.id ?? payload.run_id ?? payload.id ?? null
    const statusUrl = nested.status_url ?? payload.status_url ?? null
    if (!runId || !statusUrl) return null

    return {
        run_id: String(runId),
        status_url: String(statusUrl),
        status: String(nested.status ?? payload.status ?? 'pending').toLowerCase(),
        terminal: Boolean(nested.terminal ?? payload.terminal ?? false),
        retry_after_seconds: finiteNumber(
            nested.retry_after_seconds ?? payload.retry_after_seconds,
        ),
    }
}

export const terminalRunState = (payload) => {
    const status = String(payload?.status ?? '').toLowerCase()
    const calculatorStatus = String(payload?.calculator?.status ?? '').toLowerCase()
    const terminal = payload?.terminal === true
        || ['completed', 'complete', 'succeeded', 'failed', 'cancelled', 'expired'].includes(status)

    if (!terminal) return null
    if (['failed', 'capped'].includes(calculatorStatus)) return 'failed'

    return ['completed', 'complete', 'succeeded'].includes(status) ? 'completed' : 'failed'
}

export const calculatorProgress = (payload) => {
    const calculator = payload?.calculator && typeof payload.calculator === 'object'
        ? payload.calculator
        : null
    const expirations = Array.isArray(calculator?.expirations) ? calculator.expirations : []
    const readiness = {}

    expirations.forEach((item) => {
        const expiration = String(
            item?.expiration ?? item?.expiration_date ?? item?.value ?? '',
        ).slice(0, 10)
        if (!expiration) return

        readiness[expiration] = {
            readiness: String(item?.readiness ?? 'pending').toLowerCase(),
            publication_id: item?.publication_id ?? null,
            source_asof: item?.source_asof ?? null,
            failure_code: item?.failure_code ?? null,
            failure_reason: item?.failure_reason ?? null,
        }
    })

    return {
        status: String(calculator?.status ?? payload?.status ?? '').toLowerCase(),
        expected_count: Math.max(0, Math.trunc(finiteNumber(calculator?.expected_count) ?? 0)),
        completed_count: Math.max(0, Math.trunc(finiteNumber(calculator?.completed_count) ?? 0)),
        failed_count: Math.max(0, Math.trunc(finiteNumber(calculator?.failed_count) ?? 0)),
        readiness,
    }
}

export const readyExpiryToken = (progress, selectedExpiration = null) => Object.entries(progress?.readiness ?? {})
    .filter(([expiration, item]) => item?.readiness === 'ready'
        && (selectedExpiration === null || expiration === selectedExpiration))
    .map(([expiration, item]) => [
        expiration,
        item.publication_id ?? item.source_asof ?? 'ready',
    ].join(':'))
    .sort()
    .join('|')

export const abortableDelay = (milliseconds, signal) => new Promise((resolve, reject) => {
    if (signal?.aborted) {
        reject(new DOMException('Aborted', 'AbortError'))
        return
    }

    const abort = () => {
        clearTimeout(timer)
        reject(new DOMException('Aborted', 'AbortError'))
    }
    const timer = setTimeout(() => {
        signal?.removeEventListener('abort', abort)
        resolve()
    }, milliseconds)

    signal?.addEventListener('abort', abort, { once: true })
})

export const isRequestCancellation = (error) => error?.name === 'AbortError'
    || error?.name === 'CanceledError'
    || error?.code === 'ERR_CANCELED'
    || error?.__CANCEL__ === true

export const expirationReadinessMap = (payload) => {
    const result = {}
    const direct = payload?.expiry_readiness ?? payload?.expiration_readiness

    if (Array.isArray(direct)) {
        direct.forEach((item) => {
            const expiration = String(item?.expiration ?? item?.expiration_date ?? item?.value ?? '').slice(0, 10)
            if (expiration) result[expiration] = String(item?.readiness ?? item?.status ?? 'pending').toLowerCase()
        })
    } else if (direct && typeof direct === 'object') {
        Object.entries(direct).forEach(([expiration, item]) => {
            result[String(expiration).slice(0, 10)] = String(
                typeof item === 'string' ? item : item?.readiness ?? item?.status ?? 'pending',
            ).toLowerCase()
        })
    }

    ;(Array.isArray(payload?.expirations) ? payload.expirations : []).forEach((item) => {
        const expiration = String(item?.value ?? item?.expiration ?? item?.expiration_date ?? '').slice(0, 10)
        if (expiration && (item?.readiness || item?.status)) {
            result[expiration] = String(item.readiness ?? item.status).toLowerCase()
        }
    })

    return result
}
