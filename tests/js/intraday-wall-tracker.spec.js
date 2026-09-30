import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
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

describe('Intraday wall tracker', () => {
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
    await wrapper.get('[aria-label="Wall comparison window"]').setValue('0')
    expect(wrapper.get('.wall-tracker__metric--put strong').text()).toBe('95')
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
