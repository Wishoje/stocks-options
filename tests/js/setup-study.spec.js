import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import SetupStudy from '@/Pages/SetupStudy.vue'
import {
  defaultStudyFilters, studyAdverseR, studyCsv, studyFilterParams, studyNumber, studyR, studyRate, studyTime,
} from '@/Support/setupStudy'

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const wrappers = []
const plan = () => ({
  id: 'plan-1', symbol: 'SPY', direction: 'bullish', horizon: 'intraday',
  signal_at: '2026-10-26T14:00:00Z', available_at: '2026-10-26T14:05:00Z',
  status: 'completed', outcome: 'window_closed', signal_price: 100, entry_low: 99.5, entry_high: 100.5,
  invalidation: 98, target: 104, qualified: true, followup_complete: true, needs_update: false, confirmation_count: 3,
  metrics: { mfe_r: 0.8, mae_r: 0.3, mfe_pct: 1.6, mae_pct: 0.6, reached_05r: true, reached_1r: false, entry_revisited: true, strict_outcome: 'window_closed' },
  evidence: { observations: 8, first_close: 100, source_complete: true },
  source: { type: 'intraday_wall_setup', record_id: 123, source_date: '2026-10-26' },
})
const report = (overrides = {}) => ({
  filters: { cohort: 'intraday', from: '2026-10-13', to: '2026-10-26', symbol: '', direction: '' },
  generated_at: '2026-10-26T20:00:00Z', last_collected_at: '2026-10-26T19:59:00Z', collection_enabled: true,
  summary: { total: 3, qualified: 2, watching: 1, in_progress: 1, completed: 1, needs_update: 0,
    strict_target: { hits: 0, eligible: 1, rate: 0 }, favorable_05r: { hits: 1, eligible: 1, rate: 100 },
    favorable_1r: { hits: 0, eligible: 1, rate: 0 }, entry_revisit: { hits: 1, eligible: 1, rate: 100 },
    favorable_05pct: { hits: 1, eligible: 1, rate: 100 }, favorable_1pct: { hits: 1, eligible: 1, rate: 100 },
    after_publication_target: { hits: 0, eligible: 1, rate: 0 }, resolved_before_publication: 0, publication_timing_unknown: 0,
    avg_mfe_r: 0.8, avg_mae_r: 0.3, avg_mfe_pct: 1.6, avg_mae_pct: 0.6 },
  rows: [plan()], warnings: [], methodology: { followup: 'Completed observed closes only' }, ...overrides,
})
function start(props = {}) { const wrapper = mount(SetupStudy, { props }); wrappers.push(wrapper); return wrapper }
function deferred() { let resolve; const promise = new Promise(done => { resolve = done }); return { promise, resolve } }

describe('Setup study formatting and export', () => {
  it('keeps missing measurements separate from observed zero and requires a real rate denominator', () => {
    expect(studyNumber(null)).toBe('—')
    expect(studyNumber('')).toBe('—')
    expect(studyNumber(0)).toBe('0')
    expect(studyR(null)).toBe('—')
    expect(studyR(0)).toBe('0R')
    expect(studyR(0.5)).toBe('+0.5R')
    expect(studyAdverseR(0.3)).toBe('-0.3R')
    expect(studyAdverseR(0)).toBe('0R')
    expect(studyAdverseR(null)).toBe('—')
    expect(studyRate({ hits: 0, eligible: 0, rate: 0 })).toBe('—')
    expect(studyRate({ hits: 0, eligible: 4, rate: 0 })).toBe('0%')
    expect(studyRate({ hits: 1, eligible: 4, rate: 25 })).toBe('25%')
    expect(studyTime('invalid date')).toBe('—')
  })

  it('defaults to fourteen inclusive Eastern calendar dates and normalizes server filters', () => {
    expect(defaultStudyFilters(new Date('2026-10-27T01:00:00Z'))).toEqual({
      cohort: 'intraday', from: '2026-10-13', to: '2026-10-26', symbol: '', direction: '',
    })
    expect(studyFilterParams({ cohort: 'swing_short', from: '2026-10-01', to: '2026-10-26', symbol: ' spy ', direction: 'bullish' }))
      .toEqual({ cohort: 'swing_short', from: '2026-10-01', to: '2026-10-26', symbol: 'SPY', direction: 'bullish' })
  })

  it('exports only supplied rows with quoted text and neutralizes spreadsheet formulas', () => {
    const csv = studyCsv([{ ...plan(), id: '=HYPERLINK("https://example.com")', symbol: '  +SUM(1,2)', source: { note: 'line 1\nline 2' } }])
    expect(csv.split('\r\n')).toHaveLength(2)
    expect(csv).toContain('"\'=HYPERLINK(""https://example.com"")"')
    expect(csv).toContain('"\'  +SUM(1,2)"')
    expect(csv).toContain('"0.3"')
    expect(csv).toContain('"Best favorable stock move (%)"')
    expect(csv).toContain('"Best favorable move ($)"')
    expect(csv).toContain('"Advanced: Best close R"')
    expect(csv).toContain('"Advanced: Availability timing"')
    expect(csv).toContain('"Advanced: First favorable 0.5R time (UTC)"')
    expect(csv).not.toContain('undefined')
  })
})

describe('Setup study page', () => {
  beforeEach(() => {
    axios.get.mockReset(); axios.post.mockReset()
    axios.get.mockResolvedValue({ data: report() })
    axios.post.mockResolvedValue({ data: { collected: 1 } })
  })
  afterEach(() => wrappers.splice(0).forEach(wrapper => wrapper.unmount()))

  it('shows strict outcomes separately from favorable moves and keeps each denominator visible', async () => {
    const wrapper = start(); await flushPromises()
    expect(wrapper.get('[data-metric="strict_target"]').text()).toContain('0%')
    expect(wrapper.get('[data-metric="strict_target"] strong').text()).toBe('0 of 1')
    expect(wrapper.get('[data-metric="favorable_05pct"]').text()).toContain('100% of eligible completed plans')
    expect(wrapper.text()).toContain('Recorded scenarios, not executed trades.')
    expect(wrapper.text()).toContain('Repeated confirmations at the same level count as one plan.')
    expect(wrapper.get('.study-table').text()).toContain('+1.6%')
    expect(wrapper.get('.study-table').text()).toContain('+$1.60')
    expect(wrapper.get('.study-table').text()).not.toContain('0.5R')
    expect(axios.get).toHaveBeenCalledTimes(1)
    expect(axios.post).not.toHaveBeenCalled()
  })

  it('shows separate original and available times, fixed levels and evidence counts in plan details', async () => {
    const wrapper = start(); await flushPromises()
    expect(wrapper.find('.study-detail-content').exists()).toBe(false)
    await wrapper.get('.study-inspect').trigger('click')
    expect(wrapper.get('.study-inspect').attributes('aria-expanded')).toBe('true')
    expect(wrapper.get('.study-detail-content').text()).toContain('Original signal time')
    expect(wrapper.get('.study-detail-content').text()).toContain('10:00 AM ET')
    expect(wrapper.get('.study-detail-content').text()).toContain('10:05 AM ET')
    expect(wrapper.get('.study-detail-content').text()).toContain('99.5–100.5')
    expect(wrapper.get('.study-detail-content').text()).toContain('Confirmations · one plan3')
    expect(wrapper.get('.study-detail-content').text()).toContain('Observations8')
    expect(wrapper.get('.study-detail-content').text()).toContain('Source provenance')
    expect(wrapper.get('.study-detail-content').text()).toContain('Record Id123')
    expect(wrapper.get('.study-detail-content').text()).toContain('Distance to invalidation$2.00 · 2%')
    expect(wrapper.get('.study-advanced').attributes('open')).toBeUndefined()
    expect(wrapper.get('.study-advanced').text()).toContain('Best close in R+0.8R')
  })

  it('shows NVDA stock percentage and dollar moves alongside its invalidated outcome without implying profit', async () => {
    const nvda = { ...plan(), id: 'nvda-recorded', symbol: 'NVDA', signal_price: 217.68, outcome: 'invalidated', metrics: {
      ...plan().metrics, mfe_pct: 100 * 1.47 / 217.68, mae_pct: 100 * 0.31 / 217.68,
      mfe_r: 0.8, strict_outcome: 'invalidated', observations: 8,
    } }
    axios.get.mockResolvedValueOnce({ data: report({ rows: [nvda], summary: {
      ...report().summary, strict_target: { hits: 4, eligible: 18, rate: 22.2 },
      favorable_05pct: { hits: 12, eligible: 18, rate: 66.7 }, favorable_1pct: { hits: 6, eligible: 18, rate: 33.3 },
      avg_mfe_pct: 0.68, avg_mae_pct: 0.14,
    } }) })
    const wrapper = start(); await flushPromises()
    const row = wrapper.get('.study-table tbody tr')
    expect(row.text()).toContain('NVDA')
    expect(row.text()).toContain('Invalidated')
    expect(row.get('.study-price-move--favorable').text()).toContain('+0.68%')
    expect(row.get('.study-price-move--favorable').text()).toContain('+$1.47')
    expect(row.get('.study-price-move--adverse').text()).toContain('-0.14%')
    expect(row.get('.study-price-move--adverse').text()).toContain('-$0.31')
    expect(row.text()).toContain('0.5%: Yes · 1%: No')
    expect(row.text()).not.toMatch(/\b(?:0\.5|1|0\.8)R\b/)
    expect(wrapper.get('[data-metric="strict_target"] strong').text()).toBe('4 of 18')
    expect(wrapper.get('[data-metric="strict_target"] p').text()).toBe('22.2% of eligible completed plans')
    expect(wrapper.get('[data-metric="avg_mfe_pct"] strong').text()).toBe('+0.68%')
    expect(wrapper.get('[data-metric="avg_mae_pct"] strong').text()).toBe('-0.14%')
    expect(wrapper.text()).toContain('Counts describe recorded plans, not profit.')
    expect(wrapper.text()).toContain('up for bullish, down for bearish')
  })

  it('separates a target reached before publication from the target rate after app availability', async () => {
    const late = { ...plan(), available_at: '2026-10-26T19:55:00Z', outcome: 'target_reached', metrics: {
      ...plan().metrics, strict_outcome: 'target_reached', strict_at: '2026-10-26T19:50:00Z',
      publication_timing: 'resolved_before_publication', first_05r_at: '2026-10-26T19:40:00Z', first_1r_at: '2026-10-26T19:45:00Z',
    } }
    axios.get.mockResolvedValueOnce({ data: report({ rows: [late], summary: {
      ...report().summary, total: 3, completed: 3, strict_target: { hits: 1, eligible: 3, rate: 33.3 },
      after_publication_target: { hits: 0, eligible: 1, rate: 0 }, resolved_before_publication: 2, publication_timing_unknown: 0,
    } }) })
    const wrapper = start(); await flushPromises()
    expect(wrapper.text()).toContain('Signal-close response')
    expect(wrapper.get('[data-metric="strict_target"]').text()).toContain('33.3%')
    expect(wrapper.get('[data-metric="after_publication_target"]').text()).toContain('0%')
    expect(wrapper.get('[data-metric="after_publication_target"] dd').text()).toBe('0 of 1')
    expect(wrapper.get('.study-availability').text()).toContain('Completed plans still open when the app received them.')
    expect(wrapper.get('.study-availability').text()).toContain('Resolved before app availability2')
    expect(wrapper.get('.study-timing-late').text()).toBe('Resolved before app availability')
    await wrapper.get('.study-inspect').trigger('click')
    expect(wrapper.get('.study-advanced').text()).toContain('3:40 PM ET')
    expect(wrapper.get('.study-advanced').text()).toContain('3:45 PM ET')
    const csv = studyCsv([late])
    expect(csv).toContain('"resolved_before_publication"')
    expect(csv).toContain('"2026-10-26T19:40:00Z"')
  })

  it('applies date symbol and direction filters together and switches holding periods', async () => {
    const wrapper = start(); await flushPromises()
    await wrapper.get('[aria-label="From date"]').setValue('2026-10-01')
    await wrapper.get('[aria-label="To date"]').setValue('2026-10-26')
    await wrapper.get('[aria-label="Symbol"]').setValue(' qqq ')
    await wrapper.get('[aria-label="Direction"]').setValue('bearish')
    await wrapper.get('form').trigger('submit'); await flushPromises()
    expect(axios.get.mock.lastCall[1].params).toEqual({ cohort: 'intraday', from: '2026-10-01', to: '2026-10-26', symbol: 'QQQ', direction: 'bearish' })
    await wrapper.findAll('.study-cohorts button')[1].trigger('click'); await flushPromises()
    expect(axios.get.mock.lastCall[1].params.cohort).toBe('swing_short')
    expect(wrapper.findAll('.study-cohorts button')[1].attributes('aria-pressed')).toBe('true')
  })

  it('rejects an inverted date range without fetching', async () => {
    const wrapper = start(); await flushPromises()
    await wrapper.get('[aria-label="From date"]').setValue('2026-10-27')
    await wrapper.get('[aria-label="To date"]').setValue('2026-10-01')
    await wrapper.get('form').trigger('submit'); await flushPromises()
    expect(wrapper.text()).toContain('From date must be on or before To date.')
    expect(axios.get).toHaveBeenCalledTimes(1)
  })

  it('collects only on manual refresh and reloads the report after collection finishes', async () => {
    const collection = deferred(); axios.post.mockReturnValueOnce(collection.promise)
    const wrapper = start(); await flushPromises()
    await wrapper.get('.study-button--primary').trigger('click')
    expect(axios.post).toHaveBeenCalledWith('/api/setup-study/refresh', expect.objectContaining({ cohort: 'intraday' }), expect.objectContaining({ timeout: 60000 }))
    expect(axios.get).toHaveBeenCalledTimes(1)
    expect(wrapper.get('.study-button--primary').text()).toContain('Refreshing records')
    collection.resolve({ data: { collected: 1 } }); await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('Records refreshed from saved evidence.')
  })

  it('uses dashes for an empty denominator and gives a useful empty state', async () => {
    const empty = { hits: 0, eligible: 0, rate: 0 }
    axios.get.mockResolvedValueOnce({ data: report({ rows: [], summary: {
      total: 0, qualified: 0, watching: 0, in_progress: 0, completed: 0, needs_update: 0,
      strict_target: empty, favorable_05pct: empty, favorable_1pct: empty, entry_revisit: empty, avg_mfe_pct: null, avg_mae_pct: null,
    } }) })
    const wrapper = start(); await flushPromises()
    expect(wrapper.get('[data-metric="strict_target"] strong').text()).toBe('—')
    expect(wrapper.get('[data-metric="strict_target"]').text()).toContain('No completed setups yet')
    expect(wrapper.text()).toContain('No recorded plans in this view')
    expect(wrapper.find('.study-metrics').text()).not.toContain('0%')
    expect(wrapper.findAll('.study-actions button')[0].attributes('disabled')).toBeDefined()
  })

  it('explains partial first-pass coverage and prepares an empty study without inventing outcomes', async () => {
    const empty = { hits: 0, eligible: 0, rate: null }
    axios.get.mockResolvedValueOnce({ data: report({ rows: [], coverage: {
      collected_symbols: 7, total_symbols: 96, first_pass_complete: false,
    }, summary: {
      total: 0, qualified: 0, watching: 0, in_progress: 0, completed: 0, needs_update: 0,
      strict_target: empty, favorable_05pct: empty, favorable_1pct: empty, entry_revisit: empty,
      after_publication_target: empty, avg_mfe_pct: null, avg_mae_pct: null,
    } }) })
    const wrapper = start(); await flushPromises()
    expect(wrapper.get('.study-coverage').text()).toBe('Preparing your study: 7 of 96 symbols checked. Results are partial while the first collection finishes.')
    expect(wrapper.get('.study-empty h3').text()).toBe('Preparing your study')
    expect(wrapper.get('.study-empty').text()).toContain('saved symbols in batches')
    expect(wrapper.get('[data-metric="strict_target"] strong').text()).toBe('—')
    expect(wrapper.text()).not.toContain('No recorded plans in this view')
    expect(axios.get).toHaveBeenCalledTimes(1)
    expect(axios.post).not.toHaveBeenCalled()
  })

  it('hides first-pass preparation once complete and ignores coverage during synthetic preview', async () => {
    axios.get.mockResolvedValueOnce({ data: report({ rows: [], coverage: {
      collected_symbols: 96, total_symbols: 96, first_pass_complete: true,
    } }) })
    const saved = start(); await flushPromises()
    expect(saved.find('.study-coverage').exists()).toBe(false)
    expect(saved.get('.study-empty h3').text()).toBe('No recorded plans in this view')
    const preview = start({ localDemoAvailable: true }); await flushPromises()
    axios.get.mockResolvedValueOnce({ data: report({ rows: [], demonstration: true, coverage: {
      collected_symbols: 7, total_symbols: 96, first_pass_complete: false,
    } }) })
    await preview.get('.study-preview-toggle').trigger('click'); await flushPromises()
    expect(preview.find('.study-coverage').exists()).toBe(false)
    expect(preview.get('.study-empty h3').text()).toBe('No synthetic examples match this view')
    expect(preview.text()).not.toContain('Preparing your study')
  })

  it('shows compact pattern and symbol results with their own visible denominators', async () => {
    const group = { ...report().summary, label: 'wall_reclaim' }
    axios.get.mockResolvedValueOnce({ data: report({ breakdowns: {
      patterns: [group], symbols: [{ ...group, label: 'SPY', strict_target: { hits: 0, eligible: 0, rate: null } }],
    } }) })
    const wrapper = start(); await flushPromises()
    expect(wrapper.get('table[aria-label="By setup pattern"]').text()).toContain('Wall Reclaim')
    expect(wrapper.get('table[aria-label="By setup pattern"]').text()).toContain('0 of 1')
    expect(wrapper.get('table[aria-label="By setup pattern"]').text()).toContain('Move ≥ 0.5%')
    expect(wrapper.get('table[aria-label="By setup pattern"]').text()).not.toContain('0.5R')
    expect(wrapper.get('table[aria-label="By symbol"]').text()).toContain('SPY')
    expect(wrapper.get('table[aria-label="By symbol"]').text()).toContain('—')
  })

  it('does not label an unfinished or unobserved favorable move as a failed move', async () => {
    axios.get.mockResolvedValueOnce({ data: report({ rows: [
      { ...plan(), status: 'in_progress', followup_complete: false, metrics: { observations: 2, mfe_pct: 0.2, mae_pct: null } },
      { ...plan(), id: 'no-closes', symbol: 'QQQ', status: 'needs_update', followup_complete: false, needs_update: true,
        metrics: { observations: 0, mfe_pct: null, mae_pct: null } },
    ] }) })
    const wrapper = start(); await flushPromises()
    expect(wrapper.get('.study-table').text()).toContain('0.5%: Pending')
    expect(wrapper.get('.study-table').text()).toContain('0.5%: —')
    expect(wrapper.get('.study-table').text()).not.toContain('0.5%: No')
  })

  it('recovers from read errors and reports owner-only access without rendering a fake report', async () => {
    axios.get.mockRejectedValueOnce({ response: { status: 403 } })
    const wrapper = start(); await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('owner account only')
    expect(wrapper.find('.study-metrics').exists()).toBe(false)
    await wrapper.get('[role="alert"] button').trigger('click'); await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.find('.study-metrics').exists()).toBe(true)
  })

  it('keeps the last report after a collection failure and does not report success', async () => {
    axios.post.mockRejectedValueOnce({ response: { data: { message: 'Saved evidence is unavailable.' } } })
    const wrapper = start(); await flushPromises()
    await wrapper.get('.study-button--primary').trigger('click'); await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('Saved evidence is unavailable.')
    expect(wrapper.find('.study-metrics').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Records refreshed from saved evidence.')
    expect(axios.get).toHaveBeenCalledTimes(1)
  })

  it('shows a busy collection warning without claiming that records refreshed', async () => {
    axios.post.mockResolvedValueOnce({ data: { prepared: true, busy: true, warnings: ['Study collection is already running.'] } })
    const wrapper = start(); await flushPromises()
    await wrapper.get('.study-button--primary').trigger('click'); await flushPromises()
    expect(wrapper.text()).toContain('Study collection is already running.')
    expect(wrapper.text()).not.toContain('Records refreshed from saved evidence.')
    expect(axios.get).toHaveBeenCalledTimes(2)
  })

  it('discards an old cohort response when a newer request has completed', async () => {
    const old = deferred(); axios.get.mockReturnValueOnce(old.promise)
    const wrapper = start()
    await wrapper.findAll('.study-cohorts button')[2].trigger('click'); await flushPromises()
    expect(axios.get.mock.calls[0][1].signal.aborted).toBe(true)
    old.resolve({ data: report({ warnings: ['OBSOLETE COHORT'] }) }); await flushPromises()
    expect(wrapper.text()).not.toContain('OBSOLETE COHORT')
    expect(wrapper.find('.study-metrics').exists()).toBe(true)
  })

  it('offers synthetic preview only when the server allows local examples, with collection and CSV disabled', async () => {
    const wrapper = start({ localDemoAvailable: true }); await flushPromises()
    expect(axios.get.mock.lastCall[1].params).not.toHaveProperty('demo')
    axios.get.mockResolvedValueOnce({ data: report({ demonstration: true }) })
    await wrapper.get('.study-preview-toggle').trigger('click'); await flushPromises()
    expect(axios.get.mock.lastCall[1].params.demo).toBe(1)
    expect(wrapper.get('.study-demo').text()).toContain('Local demonstration · Synthetic examples')
    expect(wrapper.get('.study-demo').text()).toContain('These records are not saved and do not represent market results.')
    expect(wrapper.get('.study-preview-toggle').text()).toBe('Back to saved records')
    expect(wrapper.get('.study-button--primary').attributes('disabled')).toBeDefined()
    expect(wrapper.findAll('.study-actions button')[0].attributes('disabled')).toBeDefined()
    await wrapper.get('.study-button--primary').trigger('click')
    expect(axios.post).not.toHaveBeenCalled()
    await wrapper.get('.study-preview-toggle').trigger('click'); await flushPromises()
    expect(axios.get.mock.lastCall[1].params).not.toHaveProperty('demo')
    expect(wrapper.find('.study-demo').exists()).toBe(false)
    expect(wrapper.get('.study-preview-toggle').text()).toBe('Preview example')
    expect(wrapper.get('.study-button--primary').attributes('disabled')).toBeUndefined()
  })

  it('does not expose preview in production or accept synthetic data as saved records', async () => {
    axios.get.mockResolvedValueOnce({ data: report({ demonstration: true }) })
    const wrapper = start(); await flushPromises()
    expect(wrapper.find('.study-preview-toggle').exists()).toBe(false)
    expect(axios.get.mock.lastCall[1].params).not.toHaveProperty('demo')
    expect(wrapper.find('.study-metrics').exists()).toBe(false)
    expect(wrapper.get('[role="alert"]').text()).toContain('The study could not load.')
    expect(axios.post).not.toHaveBeenCalled()
  })
})
