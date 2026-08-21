import { describe, expect, it } from 'vitest'

import {
  bootstrapPayload,
  bootstrapPollDelayMs,
  ownsPreparationPoll,
  selectionWarmupPlan,
  symbolPreparationState,
} from '@/Support/symbol-bootstrap-state.js'

describe('symbol bootstrap state', () => {
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
