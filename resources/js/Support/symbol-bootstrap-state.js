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
const TERMINAL_PARENT_STATES = new Set(['completed', 'failed', 'cancelled', 'expired'])

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
    const fastReady = typeof bootstrap.fast_ready === 'boolean'
      ? bootstrap.fast_ready
      : FAST_READY_STATES.has(state)
    const fullReady = typeof bootstrap.full_ready === 'boolean'
      ? bootstrap.full_ready
      : FULL_READY_STATES.has(state)
    const noOptions = bootstrap.no_options === true || state === 'no_options'
    const explicitlyTerminal = fullReady || bootstrap.terminal === true
      || root.terminal === true
      || root.run?.terminal === true
      || root.work_run?.terminal === true
      || [root.status, root.run?.status, root.work_run?.status]
        .some(status => TERMINAL_PARENT_STATES.has(String(status || '').toLowerCase()))
    const retryable = !explicitlyTerminal && (bootstrap.retryable === true || root.retryable === true)
    const terminal = explicitlyTerminal
      || (TERMINAL_STATES.has(state) && !retryable)
    const partial = fastReady && !fullReady
    const phases = objectOrNull(bootstrap.phases) || {}
    const coverage = objectOrNull(bootstrap.coverage)
    const expected = Number(coverage?.expected_expirations)
    const completed = Number(coverage?.completed_expirations)
    const eodReady = typeof bootstrap.eod_ready === 'boolean'
      ? bootstrap.eod_ready
      : fastReady && phases.fill?.status === 'completed'
        && coverage?.expected_expirations != null && coverage?.completed_expirations != null
        && Number.isInteger(expected) && expected >= 0 && completed === expected
    const enrichmentStatus = String(phases.enrichment?.status || '').toLowerCase()

    return {
      mode: 'bootstrap',
      state,
      fastReady,
      fullReady,
      eodReady,
      enrichmentReady: bootstrap.enrichment_ready === true || enrichmentStatus === 'completed',
      enrichmentStatus,
      intradayReady: bootstrap.intraday_ready === true,
      intradayStatus: String(phases.intraday?.status || '').toLowerCase(),
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
      runGeneration: Number.isInteger(Number(bootstrap.catalog?.generation)) && Number(bootstrap.catalog?.generation) > 0
        ? Number(bootstrap.catalog.generation)
        : null,
      coverage,
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
    eodReady: ready,
    enrichmentReady: ready,
    enrichmentStatus: '',
    intradayReady: ready,
    intradayStatus: '',
    noOptions: false,
    partial: false,
    partialFailed: false,
    filling: false,
    terminal: ready,
    shouldPoll: !ready,
    retryable: false,
    statusUrl: root.status_url || root.run?.status_url || null,
    runId: root.run_id || root.run?.run_id || null,
    runGeneration: null,
    coverage: null,
    bootstrap: null,
  }
}

export function bootstrapPreparationNotice(preparation, symbol) {
  if (!preparation?.partial || preparation.fullReady || preparation.noOptions) return null

  const terminal = preparation.terminal === true
  let title = 'Filling full data for ' + symbol
  let message = 'Fast data is ready and usable. Remaining expirations and analytics will appear as they finish.'
  if (preparation.eodReady) {
    title = 'EOD data ready for ' + symbol
    if (terminal) {
      message = preparation.enrichmentStatus === 'failed'
        ? 'The option chain is complete. Additional analytics could not be prepared.'
        : preparation.intradayStatus === 'failed'
          ? 'The option chain is complete. Intraday preparation did not complete.'
          : 'The option chain is complete, but some remaining preparation did not finish.'
    } else if (!preparation.enrichmentReady) {
      message = preparation.enrichmentStatus === 'failed'
        ? 'The option chain is complete. Additional analytics are waiting to retry.'
        : 'The option chain is complete. Additional analytics are still being prepared.'
    } else if (preparation.intradayStatus !== 'completed') {
      message = 'The option chain is complete. Intraday data is still being prepared.'
    } else {
      title = 'Finalizing ' + symbol + ' data'
      message = 'The option chain and analytics are ready. Publication is being finalized.'
    }
  } else if (terminal) {
    title = 'Partial data for ' + symbol
    message = 'Fast data remains available, but the remaining expirations or analytics could not be prepared.'
  } else if (preparation.retryable && ['fast_failed', 'fill_failed'].includes(preparation.state || preparation.phase)) {
    title = 'Retrying full data for ' + symbol
    message = 'Fast data remains available. The remaining expiration or analytics work is waiting to retry.'
  }

  const coverage = preparation.coverage
  const completed = Number(coverage?.completed_expirations ?? coverage?.expirations_completed ?? coverage?.published_expirations?.length)
  const expected = Number(coverage?.expected_expirations ?? coverage?.expirations_expected)
  return {
    title,
    message,
    warning: terminal,
    spinning: !terminal,
    coverageLabel: Number.isInteger(completed) && Number.isInteger(expected) && expected > 0
      ? completed + ' of ' + expected + ' expirations complete.'
      : '',
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
