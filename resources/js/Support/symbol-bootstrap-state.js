import { retryDelayMs } from '@/Support/calculator-refresh-state.js'

const FAST_READY_STATES = new Set(['fast_ready', 'filling', 'full_ready', 'fill_failed'])
const FULL_READY_STATES = new Set(['full_ready', 'completed', 'complete', 'succeeded'])
const TERMINAL_STATES = new Set([
  'full_ready',
  'completed',
  'complete',
  'succeeded',
  'no_options',
  'fast_failed',
  'fill_failed',
  'failed',
  'cancelled',
  'expired',
])

const objectOrNull = value => value && typeof value === 'object' ? value : null

export function bootstrapPayload(payload) {
  const root = objectOrNull(payload) || {}

  return objectOrNull(root.bootstrap)
    || objectOrNull(root.run?.bootstrap)
    || objectOrNull(root.work_run?.bootstrap)
    || null
}

export function symbolPreparationState(payload, httpStatus = 200) {
  const root = objectOrNull(payload) || {}
  const bootstrap = bootstrapPayload(root)

  if (bootstrap) {
    const state = String(bootstrap.state || bootstrap.phase || root.status || 'queued').toLowerCase()
    const fastReady = bootstrap.fast_ready === true || FAST_READY_STATES.has(state)
    const fullReady = bootstrap.full_ready === true || FULL_READY_STATES.has(state)
    const noOptions = bootstrap.no_options === true || state === 'no_options'
    const retryable = bootstrap.retryable === true || root.retryable === true
    const terminal = bootstrap.terminal === true
      || root.terminal === true
      || root.run?.terminal === true
      || root.work_run?.terminal === true
      || (TERMINAL_STATES.has(state) && !retryable)
    const partial = fastReady && !fullReady

    return {
      mode: 'bootstrap',
      state,
      fastReady,
      fullReady,
      noOptions,
      partial,
      partialFailed: partial && terminal,
      filling: partial && !terminal,
      terminal,
      shouldPoll: !fullReady && !terminal,
      retryable,
      statusUrl: bootstrap.status_url
        || root.status_url
        || root.run?.status_url
        || root.work_run?.status_url
        || null,
      runId: bootstrap.run_id
        || root.run_id
        || root.run?.run_id
        || root.work_run?.run_id
        || null,
      coverage: objectOrNull(bootstrap.coverage),
      bootstrap,
    }
  }

  const state = String(root.status || (httpStatus === 200 ? 'ready' : 'queued')).toLowerCase()
  const ready = state === 'ready'

  return {
    mode: 'legacy',
    state,
    fastReady: ready,
    fullReady: ready,
    noOptions: false,
    partial: false,
    partialFailed: false,
    filling: false,
    terminal: ready,
    shouldPoll: !ready,
    retryable: false,
    statusUrl: root.status_url || root.run?.status_url || null,
    runId: root.run_id || root.run?.run_id || null,
    coverage: null,
    bootstrap: null,
  }
}

export function selectionWarmupPlan(payload, httpStatus = 200) {
  const preparation = symbolPreparationState(payload, httpStatus)

  return {
    startCalculator: true,
    startIntraday: preparation.fastReady && !preparation.noOptions,
    startPrime: !preparation.fastReady && !preparation.noOptions,
    preparation,
  }
}

export function bootstrapPollDelayMs(response, fallbackMs = 5_000) {
  const bootstrap = bootstrapPayload(response?.data)
  const retryAfterSeconds = response?.data?.retry_after_seconds
    ?? response?.data?.run?.retry_after_seconds
    ?? response?.data?.work_run?.retry_after_seconds
    ?? bootstrap?.retry_after_seconds

  return retryDelayMs({
    data: { retry_after_seconds: retryAfterSeconds },
    headers: response?.headers,
  }, fallbackMs)
}

export function ownsPreparationPoll(owner, currentSymbol, currentGeneration) {
  return owner?.symbol === currentSymbol && owner?.generation === currentGeneration
}
