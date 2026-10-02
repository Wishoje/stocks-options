import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import IntradayWallTracker from '@/Components/IntradayWallTracker.vue'
vi.mock('axios', () => ({ default: { get: vi.fn() } }))

const reading = (minute, put = 95, call = 105) => ({ observed_at: `2026-09-30T14:${minute}:00Z`, spot: 100,
  walls: { put: put == null ? [] : [{ strike: put, net_gex: -1000 }], call: [{ strike: call, net_gex: 1200 }] },
  net_gex: 200, provenance: { oi_date: '2026-09-29' } })
const payload = (symbol = 'SPY') => ({ symbol, schema_version: 'intraday-walls.v1', dataset: 'intraday_capture',
  timeframe: '14d',
  session: '2026-09-30', sessions: ['2026-09-30', '2026-09-29'], local_demo_available: true,
  segments: [{ id: 1, start_reason: 'first_observation', observations: [reading('00'), reading('05', 95, 110)],
    migration: { put: { amount: 0 }, call: { amount: 5 } } }],
  model_description: 'Prior-session OI and IV held fixed.' })
const render = () => mount(IntradayWallTracker, { props: { symbol: 'SPY' } })
beforeEach(() => { axios.get.mockReset(); window.history.replaceState({}, '', '/') })
afterEach(() => { vi.useRealTimers() })

describe('Intraday wall tracker', () => {
  it('previews an event without scrolling, then opens its evidence only on request', async () => {
    const event = { id: 'event', side: 'put', strike: 95, status: 'touch', observed_at: '2026-09-30T14:05:00Z',
      episode_started_at: '2026-09-30T14:00:00Z', wall_observed_at: '2026-09-30T14:00:00Z',
      close: 95.01, tolerance: .0475, evidence_bar_ids: ['bar'], reason: 'Price reached the wall.' }
    const data = { ...payload(), wall_interaction: { rule_version: 'completed-5m.v1', state: 'ready',
      events: [event], readings: [event], current: { put: event },
      bars: [{ id: 'bar', t: Date.parse(event.episode_started_at), o: 96, h: 96.1, l: 95, c: 95.01 }] } }
    axios.get.mockResolvedValue({ data })
    const scroll = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    const wrapper = mount(IntradayWallTracker, { props: { symbol: 'SPY' }, attachTo: document.body }); await flushPromises()
    const marker = wrapper.get('.wall-tracker__event')
    expect(marker.attributes('tabindex')).toBe('0')
    await marker.trigger('keydown', { key: 'Enter' }); await flushPromises()
    const summary = document.querySelector('[role="dialog"]')
    expect(summary.textContent).toContain('Price reached the wall')
    expect(document.activeElement).toBe(summary)
    expect(marker.attributes('aria-expanded')).toBe('true')
    expect(wrapper.find('.evidence').exists()).toBe(false)
    expect(scroll).not.toHaveBeenCalled()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })); await flushPromises()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.activeElement).toBe(marker.element)
    await marker.trigger('keydown', { key: ' ' }); await flushPromises()
    document.querySelector('.wall-event-summary__evidence').click(); await flushPromises()
    expect(wrapper.find('.evidence').exists()).toBe(true)
    expect(wrapper.get('.evidence').text()).toContain('Price reached the wall')
    expect(scroll).toHaveBeenCalled()
    expect(axios.get).toHaveBeenCalledTimes(1)
    await wrapper.get('.review-context button').trigger('click'); await flushPromises()
    expect(wrapper.find('.evidence').exists()).toBe(false)
    await marker.trigger('click'); await flushPromises()
    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true })); await flushPromises()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    await marker.trigger('click'); await flushPromises()
    axios.get.mockResolvedValue({ data: payload('QQQ') })
    await wrapper.setProps({ symbol: 'QQQ' }); await flushPromises()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    wrapper.unmount()
    scroll.mockRestore()
  })

  it('offers real recorded scopes with dates and opens one without changing the EOD scope', async () => {
    window.history.replaceState({}, '', '/dashboard?timeframe=7d&wall_timeframe=14d')
    axios.get.mockResolvedValueOnce({ data: { ...payload('AAPL'), session: null, sessions: [], segments: [],
      availability: { state: 'model_not_ready', message: 'Wall tracking is not ready for AAPL.' },
      available_scopes: [{ timeframe: '30d', session: '2026-09-30', readings: 45 }, { timeframe: '90d', session: '2026-09-29', readings: 1 }] } })
      .mockResolvedValueOnce({ data: { ...payload('AAPL'), timeframe: '30d' } })
    const wrapper = mount(IntradayWallTracker, { props: { symbol: 'AAPL' } }); await flushPromises()
    expect(wrapper.text()).toContain('No readings recorded for AAPL · 2W')
    expect(wrapper.text()).not.toContain('Wall tracking is not ready for AAPL.')
    const options = wrapper.findAll('.wall-tracker__recorded-scopes button')
    expect(options.map(button => button.text())).toEqual(['Open 1M45 readings · 2026-09-30', 'Open 3M1 reading · 2026-09-29'])
    expect(wrapper.find('.wall-tracker__metrics').exists()).toBe(false)
    await options[0].trigger('click'); await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(2)
    expect(axios.get.mock.calls[1][1].params).toMatchObject({ symbol: 'AAPL', timeframe: '30d' })
    expect(axios.get.mock.calls[1][1].params.session).toBeUndefined()
    expect(window.location.search).toBe('?timeframe=7d&wall_timeframe=30d')
    expect(wrapper.get('.wall-tracker__scope button[aria-pressed="true"]').text()).toBe('1M')
    expect(wrapper.find('.wall-tracker__metrics').exists()).toBe(true)
    expect(wrapper.find('.wall-tracker__recorded-scopes').exists()).toBe(false)
    wrapper.unmount()
  })

  it('can open a recorded session in the same scope when the selected session is empty', async () => {
    axios.get.mockResolvedValueOnce({ data: { ...payload(), segments: [], session: '2026-09-29',
      available_scopes: [{ timeframe: '14d', session: '2026-09-30', readings: 2 }] } })
      .mockResolvedValueOnce({ data: payload() })
    const wrapper = render(); await flushPromises()
    expect(wrapper.text()).toContain('No readings recorded for SPY · 2W · 2026-09-29')
    await wrapper.get('.wall-tracker__recorded-scopes button').trigger('click'); await flushPromises()
    expect(axios.get.mock.calls[1][1].params).toMatchObject({ timeframe: '14d', session: '2026-09-30' })
    expect(wrapper.find('.wall-tracker__metrics').exists()).toBe(true)
    wrapper.unmount()
  })

  it('changes expiry scope without changing EOD scope and rejects a late scope response', async () => {
    window.history.replaceState({}, '', '/dashboard?timeframe=7d&wall_timeframe=30d')
    axios.get.mockResolvedValueOnce({ data: { ...payload(), timeframe: '30d' } })
      .mockResolvedValueOnce({ data: payload() })
    const wrapper = render(); await flushPromises()
    expect(axios.get.mock.calls[0][1].params.timeframe).toBe('30d')
    await wrapper.findAll('.wall-tracker__scope button').find(button => button.text() === '0DTE').trigger('click')
    await flushPromises()
    expect(axios.get.mock.calls[1][1].params.timeframe).toBe('0d')
    expect(window.location.search).toContain('timeframe=7d')
    expect(window.location.search).toContain('wall_timeframe=0d')
    expect(wrapper.find('.wall-tracker__metrics').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('shows the selected symbol readiness rather than a three-symbol restriction', async () => {
    axios.get.mockResolvedValue({ data: { ...payload('NVDA'), segments: [], availability: { state: 'no_expirations', message: 'There are no option expirations in this scope for NVDA. Choose a wider expiry scope.' } } })
    const wrapper = mount(IntradayWallTracker, { props: { symbol: 'NVDA' } }); await flushPromises()
    expect(wrapper.text()).toContain('Choose a wider expiry scope')
    expect(wrapper.text()).not.toContain('SPY, QQQ and TSLA')
    wrapper.unmount()
  })
  it('shows rounded levels, real zero migration and a keyboard-accessible timeline', async () => {
    axios.get.mockResolvedValue({ data: { ...payload(), quote_delay_seconds: 900 } })
    const wrapper = render()
    expect(wrapper.text()).toContain('Loading SPY wall history')
    await flushPromises()
    expect(wrapper.text()).toContain('Quotes delayed 15 min')
    expect(wrapper.text()).toContain('Unchanged · 0 points')
    expect(wrapper.text()).toContain('↑ Up 5 points')
    expect(wrapper.find('details').attributes('open')).toBeUndefined()
    expect(wrapper.text()).not.toMatch(/missing inputs|snapshot|unverified/i)
    const slider = wrapper.get('input[type="range"]')
    expect(slider.element.value).toBe('1')
    await slider.setValue('0')
    expect(wrapper.get('.wall-tracker__metric--call strong').text()).toBe('105')
    expect(slider.attributes('aria-valuetext')).toContain('10:00 AM ET')
    wrapper.unmount()
  })

  it('keeps a single observation or absent wall distinct from no migration', async () => {
    const data = payload()
    data.segments[0].observations = [reading('00', null)]
    data.segments[0].migration = { put: { amount: null }, call: { amount: null } }
    axios.get.mockResolvedValue({ data })
    const wrapper = render(); await flushPromises()
    expect(wrapper.get('.wall-tracker__metric--put strong').text()).toBe('—')
    expect(wrapper.text()).toContain('Building the comparison')
    expect(wrapper.text()).not.toContain('Unchanged')
    expect(wrapper.get('input[type="range"]').element.disabled).toBe(true)
    wrapper.unmount()
  })

  it.each([80, 120])('keeps a lone price outside the walls visible at %s', async spot => {
    const data = payload()
    data.segments[0].observations = [{ ...reading('00'), spot }]
    axios.get.mockResolvedValue({ data })
    const wrapper = render(); await flushPromises()
    const marker = wrapper.get('.wall-tracker__price-point')
    expect(Number(marker.attributes('cy'))).toBeGreaterThan(20)
    expect(Number(marker.attributes('cy'))).toBeLessThan(225)
    expect(marker.text()).toBe(`Price ${spot}`)
    expect(wrapper.text()).toContain('1 reading this session')
    expect(wrapper.text()).toContain('lines appear after another reading')
    expect(wrapper.findAll('svg text').filter(label => label.text() === '10:00 AM ET')).toHaveLength(1)
    wrapper.unmount()
  })

  it('cancels old requests and ignores late results when the symbol changes', async () => {
    let first, second
    axios.get.mockImplementationOnce(() => new Promise(resolve => { first = resolve }))
      .mockImplementationOnce(() => new Promise(resolve => { second = resolve }))
    const wrapper = render()
    const signal = axios.get.mock.calls[0][1].signal
    await wrapper.setProps({ symbol: 'QQQ' })
    expect(signal.aborted).toBe(true)
    first({ data: payload() }); await flushPromises()
    expect(wrapper.find('.wall-tracker__metrics').exists()).toBe(false)
    second({ data: payload('QQQ') }); await flushPromises()
    expect(wrapper.find('.wall-tracker__metrics').exists()).toBe(true)
    const lastSignal = axios.get.mock.calls[1][1].signal
    wrapper.unmount(); expect(lastSignal.aborted).toBe(true)
  })

  it('rejects mismatched symbols and a demo response in recorded mode', async () => {
    axios.get.mockResolvedValueOnce({ data: payload('QQQ') }).mockResolvedValueOnce({ data: { ...payload(), dataset: 'synthetic_review' } })
    const wrapper = render(); await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    await wrapper.get('.wall-tracker__actions button').trigger('click'); await flushPromises()
    expect(wrapper.find('.wall-tracker__metrics').exists()).toBe(false)
    expect(wrapper.text()).toContain('could not load')
    wrapper.unmount()
  })

  it('labels demo data and requests demo explicitly without persisting it', async () => {
    axios.get.mockResolvedValueOnce({ data: { ...payload(), segments: [] } })
      .mockResolvedValueOnce({ data: { ...payload(), dataset: 'synthetic_review' } })
    const wrapper = render(); await flushPromises()
    expect(wrapper.text()).toContain('No wall readings have been recorded yet for SPY')
    expect(wrapper.text()).not.toContain('Tracking starts with')
    await wrapper.get('.wall-tracker__context button').trigger('click'); await flushPromises()
    expect(axios.get.mock.calls[1][1].params).toMatchObject({ demo: 1 })
    expect(wrapper.get('.wall-tracker__demo').text()).toContain('Synthetic prices and contracts')
    wrapper.unmount()
  })

  it('shows a separate comparison window after inputs change', async () => {
    const data = payload()
    data.segments.push({ ...data.segments[0], id: 2, start_reason: 'inputs_changed', observations: [reading('10', 90)], migration: { put: { amount: null }, call: { amount: null } } })
    axios.get.mockResolvedValue({ data })
    const wrapper = render(); await flushPromises()
    expect(wrapper.get('.wall-tracker__metric--put strong').text()).toBe('90')
    expect(wrapper.text()).toContain('Model inputs updated')
    expect(wrapper.text()).toContain('3 readings this session')
    expect(wrapper.text()).toContain('Showing 1 of 3 session readings')
    await wrapper.get('[aria-label="Wall comparison window"]').setValue('0')
    expect(wrapper.get('.wall-tracker__metric--put strong').text()).toBe('95')
    wrapper.unmount()
  })

  it('refreshes visible current sessions without clearing the chart or losing an inspected point', async () => {
    vi.useFakeTimers(); vi.setSystemTime(new Date('2026-09-30T14:10:00Z'))
    const data = { ...payload(), market_session_date: '2026-09-30', refresh_until: '2026-09-30T20:15:00Z' }
    let finish
    axios.get.mockResolvedValueOnce({ data }).mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    const wrapper = render(); await flushPromises()
    await wrapper.get('input[type="range"]').setValue('0')
    await vi.advanceTimersByTimeAsync(60000)
    expect(axios.get).toHaveBeenCalledTimes(2)
    expect(wrapper.find('.wall-tracker__metrics').exists()).toBe(true)
    await vi.advanceTimersByTimeAsync(60000)
    expect(axios.get).toHaveBeenCalledTimes(2) // No overlapping request.
    const updated = structuredClone(data)
    updated.segments[0].observations.push(reading('10', 90, 115))
    finish({ data: updated }); await flushPromises()
    expect(wrapper.get('input[type="range"]').element.value).toBe('0')
    expect(wrapper.get('.wall-tracker__metric--call strong').text()).toBe('105')
    expect(wrapper.text()).toContain('3 readings this session')
    await wrapper.get('input[type="range"]').setValue('2')
    const later = structuredClone(updated)
    later.segments.push({ ...later.segments[0], id: 2, start_reason: 'observation_gap', observations: [reading('30', 85, 120)] })
    axios.get.mockResolvedValue({ data: later })
    await vi.advanceTimersByTimeAsync(60000); await flushPromises()
    expect(wrapper.get('.wall-tracker__metric--call strong').text()).toBe('120')
    expect(wrapper.text()).toContain('4 readings this session')
    wrapper.unmount()
    const count = axios.get.mock.calls.length
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(count)
  })

  it('pauses hidden tabs, resumes once visible and retains the chart with rate-limit backoff', async () => {
    vi.useFakeTimers(); vi.setSystemTime(new Date('2026-09-30T14:10:00Z'))
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible')
    const data = { ...payload(), market_session_date: '2026-09-30', refresh_until: '2026-09-30T20:15:00Z' }
    axios.get.mockResolvedValueOnce({ data }).mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '180' } } }).mockResolvedValue({ data })
    const wrapper = render(); await flushPromises()
    visibility.mockReturnValue('hidden')
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(1)
    visibility.mockReturnValue('visible'); document.dispatchEvent(new Event('visibilitychange')); await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(2)
    expect(wrapper.find('.wall-tracker__metrics').exists()).toBe(true)
    expect(wrapper.text()).toContain('Please wait a moment')
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(60000); await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(3)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('does not automatically refresh a chosen historical session or local demonstration', async () => {
    vi.useFakeTimers(); vi.setSystemTime(new Date('2026-09-30T14:10:00Z'))
    const data = { ...payload(), market_session_date: '2026-09-30', refresh_until: '2026-09-30T20:15:00Z' }
    axios.get.mockResolvedValueOnce({ data }).mockResolvedValueOnce({ data: { ...data, session: '2026-09-29' } })
      .mockResolvedValueOnce({ data: { ...data, dataset: 'synthetic_review' } })
    const wrapper = render(); await flushPromises()
    await wrapper.get('[aria-label="Wall timeline session"]').setValue('2026-09-29'); await flushPromises()
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(2)
    await wrapper.get('.wall-tracker__context button').trigger('click'); await flushPromises()
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(3)
    wrapper.unmount()
  })

  it('stops automatic refresh when the server collection window ends', async () => {
    vi.useFakeTimers(); vi.setSystemTime(new Date('2026-09-30T20:14:30Z'))
    axios.get.mockResolvedValue({ data: { ...payload(), market_session_date: '2026-09-30', refresh_until: '2026-09-30T20:15:00Z' } })
    const wrapper = render(); await flushPromises()
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('requests the selected session and hides earlier session readings while loading', async () => {
    axios.get.mockResolvedValueOnce({ data: payload() }).mockImplementationOnce(() => new Promise(() => {}))
    const wrapper = render(); await flushPromises()
    await wrapper.get('[aria-label="Wall timeline session"]').setValue('2026-09-29')
    expect(axios.get.mock.calls[1][1].params.session).toBe('2026-09-29')
    expect(wrapper.find('.wall-tracker__metrics').exists()).toBe(false)
    wrapper.unmount()
  })
})
