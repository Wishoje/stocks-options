import { describe, expect, it } from 'vitest'

import {
  bootstrapPayload,
  bootstrapPollDelayMs,
  bootstrapPreparationNotice,
  ownsPreparationPoll,
  selectionWarmupPlan,
  symbolPreparationState,
} from '@/Support/symbol-bootstrap-state.js'

describe('symbol bootstrap state', () => {
  it.each(['running', 'failed'])('distinguishes complete EOD coverage from %s analytics without full success', status => {
    const preparation = symbolPreparationState({
      status: status === 'failed' ? 'failed' : 'running',
      bootstrap: {
        state: status === 'failed' ? 'fill_failed' : 'filling',
        fast_ready: true,
        full_ready: false,
        eod_ready: true,
        enrichment_ready: false,
        phases: { fill: { status: 'completed' }, enrichment: { status } },
        coverage: { completed_expirations: 11, expected_expirations: 11 },
      },
    })
    expect(preparation).toMatchObject({ eodReady: true, fullReady: false, partial: true })
    const notice = bootstrapPreparationNotice(preparation, 'AMD')
    expect(notice.title).toBe('EOD data ready for AMD')
    expect(notice.coverageLabel).toBe('11 of 11 expirations complete.')
    expect(notice.message).not.toContain('Remaining expirations')
    if (status === 'failed') {
      expect(preparation).toMatchObject({ terminal: true, shouldPoll: false, partialFailed: true })
      expect(notice).toMatchObject({ warning: true, spinning: false })
      expect(notice.message).toContain('analytics could not be prepared')
    } else {
      expect(preparation).toMatchObject({ terminal: false, shouldPoll: true })
      expect(notice.message).toContain('analytics are still being prepared')
    }
  })

  it('does not infer full or EOD readiness from counts alone', () => {
    const preparation = symbolPreparationState({
      status: 'completed',
      bootstrap: {
        fast_ready: true,
        full_ready: false,
        coverage: { completed_expirations: 11, expected_expirations: 11 },
        phases: { fill: { status: 'running' } },
      },
    })
    expect(preparation).toMatchObject({ eodReady: false, fullReady: false, terminal: true, shouldPoll: false })
    expect(bootstrapPreparationNotice(preparation, 'AMD').title).toBe('Partial data for AMD')
  })

  it('uses terminal parent status over stale retryable bootstrap flags', () => {
    expect(symbolPreparationState({
      run: { status: 'failed' },
      bootstrap: { state: 'filling', fast_ready: true, full_ready: false, retryable: true },
    })).toMatchObject({ terminal: true, retryable: false, shouldPoll: false, filling: false, partialFailed: true })
  })

  it('keeps retryable analytics failure distinct from terminal failure and hides fully ready banners', () => {
    const preparation = symbolPreparationState({
      bootstrap: {
        state: 'fill_failed', fast_ready: true, full_ready: false, eod_ready: true, retryable: true,
        phases: { enrichment: { status: 'failed' } },
      },
    })
    expect(preparation).toMatchObject({ shouldPoll: true, terminal: false })
    expect(bootstrapPreparationNotice(preparation, 'AMD')).toMatchObject({
      warning: false, spinning: true, message: 'The option chain is complete. Additional analytics are waiting to retry.',
    })
    expect(bootstrapPreparationNotice({ partial: false, fullReady: true }, 'AMD')).toBeNull()
  })

  it('renders fast data while retaining a filling poll', () => {
    const state = symbolPreparationState({
      run_id: 'run-1',
      status_url: '/api/work-runs/run-1',
      status: 'running',
      terminal: false,
      bootstrap: {
        state: 'filling',
        fast_ready: true,
        full_ready: false,
        coverage: { completed_expirations: 1, expected_expirations: 12 },
      },
    })

    expect(state).toMatchObject({
      mode: 'bootstrap',
      state: 'filling',
      fastReady: true,
      fullReady: false,
      partial: true,
      partialFailed: false,
      filling: true,
      terminal: false,
      shouldPoll: true,
      runId: 'run-1',
      statusUrl: '/api/work-runs/run-1',
    })
    expect(state.coverage).toEqual({ completed_expirations: 1, expected_expirations: 12 })
  })

  it('stops on full readiness but keeps polling retryable fill failures', () => {
    expect(symbolPreparationState({
      bootstrap: { state: 'full_ready', fast_ready: true, full_ready: true },
    })).toMatchObject({ fastReady: true, fullReady: true, filling: false, terminal: true, shouldPoll: false })

    expect(symbolPreparationState({
      bootstrap: { state: 'fill_failed', fast_ready: true, full_ready: false, retryable: true },
    })).toMatchObject({
      fastReady: true,
      fullReady: false,
      filling: true,
      terminal: false,
      shouldPoll: true,
      retryable: true,
    })

    expect(symbolPreparationState({
      bootstrap: { state: 'fill_failed', fast_ready: true, full_ready: false, retryable: false },
    })).toMatchObject({
      fastReady: true,
      fullReady: false,
      partial: true,
      partialFailed: true,
      filling: false,
      terminal: true,
      shouldPoll: false,
      retryable: false,
    })
  })

  it('finds bootstrap summaries in compatible nested payloads', () => {
    const nested = { state: 'fast_running', fast_ready: false }

    expect(bootstrapPayload({ run: { bootstrap: nested } })).toBe(nested)
    expect(bootstrapPayload({ work_run: { bootstrap: nested } })).toBe(nested)
    expect(bootstrapPayload({ status: 'queued' })).toBeNull()
  })

  it('preserves legacy ready and preparing decisions when bootstrap is absent', () => {
    expect(symbolPreparationState({ status: 'ready' }, 200)).toMatchObject({
      mode: 'legacy', fastReady: true, fullReady: true, shouldPoll: false,
    })
    expect(symbolPreparationState({ status: 'fetching' }, 202)).toMatchObject({
      mode: 'legacy', fastReady: false, fullReady: false, shouldPoll: true,
    })
    expect(symbolPreparationState({}, 200)).toMatchObject({ fastReady: true, shouldPoll: false })
  })

  it('plans selection work without starting premature intraday requests', () => {
    expect(selectionWarmupPlan({ status: 'ready' }, 200)).toMatchObject({
      startCalculator: true,
      startIntraday: true,
      startPrime: false,
    })
    expect(selectionWarmupPlan({
      status: 'ready',
      bootstrap: { state: 'filling', fast_ready: true, full_ready: false },
    }, 200)).toMatchObject({ startIntraday: true, startPrime: false })
    expect(selectionWarmupPlan({ status: 'missing' }, 404)).toMatchObject({
      startCalculator: true,
      startIntraday: false,
      startPrime: true,
    })
    expect(selectionWarmupPlan({
      bootstrap: {
        state: 'no_options',
        fast_ready: true,
        full_ready: true,
        no_options: true,
      },
    })).toMatchObject({
      startCalculator: true,
      startIntraday: false,
      startPrime: false,
    })
  })

  it('honors retry hints and rejects stale poll ownership', () => {
    expect(bootstrapPollDelayMs({
      data: { retry_after_seconds: 2 },
      headers: { 'retry-after': '4' },
    })).toBe(4_000)
    expect(bootstrapPollDelayMs({
      data: { bootstrap: { retry_after_seconds: 3 } },
    })).toBe(3_000)
    expect(bootstrapPollDelayMs({
      data: {
        run: {
          retry_after_seconds: 6,
          bootstrap: { state: 'filling', fast_ready: true },
        },
      },
    })).toBe(6_000)
    expect(bootstrapPollDelayMs({ data: {} }, 5_000)).toBe(5_000)

    const owner = { symbol: 'SPY', generation: 7 }
    expect(ownsPreparationPoll(owner, 'SPY', 7)).toBe(true)
    expect(ownsPreparationPoll(owner, 'QQQ', 7)).toBe(false)
    expect(ownsPreparationPoll(owner, 'SPY', 8)).toBe(false)
  })
})
