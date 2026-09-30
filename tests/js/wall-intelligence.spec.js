import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import axios from 'axios'
import WallLevels from '@/Components/WallLevels.vue'
vi.mock('axios', () => ({ default: { get: vi.fn() } }))
let sequence = 0
const levels = (symbol = 'SPY') => ({ symbol, timeframe: '14d', data_date: `2026-09-${String(++sequence).padStart(2, '0')}`, expiration_dates: ['2026-10-02'], view_context: { view: 'latest_eod', session_date: '2026-09-30' }, put_support: 100, put_wall_2: 95, strike_data: [{ strike: 100, net_gex: -10000 }, { strike: 95, net_gex: -5000 }] })
const wall = (strike = 100) => ({ strike, net_gex: strike === 100 ? -100 : -50, relative_magnitude: 2.5, neighbor_strikes: [95, 105], distance_pct: 1.25, signed_distance_pct: -1.25, gex_magnitude_change_1d_pct: 20, comparison_date: '2026-09-29', top_three_streak_sessions: 3, distinct_expiry_count: 1, dominant_expiry: '2026-10-02', dominant_expiry_share_pct: 100, expiry_contributions: [{ expiry: '2026-10-02', share_pct: 100, net_gex: -100 }], history: [{ date: '2026-09-30', net_gex: -100 }], oi_change_1d: 23 })
const result = source => ({ ...source, reference_price: { value: 101.25 }, walls: { put: [wall(), wall(95)], call: [] } })
const render = source => mount(WallLevels, { props: { levels: source, symbol: source.symbol, intelligenceEnabled: true } })
beforeEach(() => axios.get.mockReset())

describe('Wall intelligence', () => {
  it('shows matching metrics, keeps detailed tables collapsed, and updates additional wall selection', async () => {
    const source = levels()
    axios.get.mockResolvedValue({ data: result(source) })
    const wrapper = render(source)
    expect(wrapper.text()).toContain('Comparing wall')
    await flushPromises()
    expect(wrapper.text()).toContain('2.5×')
    expect(wrapper.text()).toContain('1.25%')
    expect(wrapper.text()).toContain('3 sessions')
    expect(wrapper.get('.wall-analysis-details').attributes('open')).toBeUndefined()
    expect(wrapper.text()).not.toMatch(/missing inputs|unverified|snapshot/i)
    await wrapper.get('[aria-label="Put wall levels"]').findAll('button')[1].trigger('click')
    expect(wrapper.get('.wall-price').text()).toBe('95')
    expect(axios.get).toHaveBeenCalledTimes(1)
    expect(axios.get.mock.calls[0][1].params).toMatchObject({ symbol: 'SPY', timeframe: '14d', view: 'latest_eod' })
    wrapper.unmount()
  })
  it('ignores late responses after a symbol switch and aborts the old request', async () => {
    const spy = levels(), qqq = levels('QQQ')
    let first, second
    axios.get.mockImplementationOnce(() => new Promise(resolve => { first = resolve }))
      .mockImplementationOnce(() => new Promise(resolve => { second = resolve }))
    const wrapper = render(spy)
    const signal = axios.get.mock.calls[0][1].signal
    await wrapper.setProps({ levels: qqq, symbol: 'QQQ' })
    expect(signal.aborted).toBe(true)
    first({ data: result(spy) }); await flushPromises()
    expect(wrapper.find('.wall-intelligence').exists()).toBe(false)
    second({ data: result(qqq) }); await flushPromises()
    expect(wrapper.find('.wall-intelligence').exists()).toBe(true)
    wrapper.unmount()
  })
  it('rejects mismatched scopes and corrected GEX instead of attaching stale details', async () => {
    const source = levels()
    const response = result(source)
    response.walls.put[0].net_gex = -999
    axios.get.mockResolvedValue({ data: response })
    const wrapper = render(source); await flushPromises()
    expect(wrapper.text()).toContain('Refresh the dashboard')
    expect(wrapper.find('.wall-intelligence').exists()).toBe(false)
    wrapper.unmount()
  })
  it('offers retry after a failure and cancels when removed', async () => {
    const source = levels()
    axios.get.mockRejectedValueOnce(new Error('network')).mockResolvedValueOnce({ data: result(source) })
    const wrapper = render(source); await flushPromises()
    expect(wrapper.text()).toContain('Your levels remain available')
    await wrapper.get('.wall-analysis-status button').trigger('click'); await flushPromises()
    expect(wrapper.find('.wall-intelligence').exists()).toBe(true)
    const signal = axios.get.mock.calls[1][1].signal
    wrapper.unmount(); expect(signal.aborted).toBe(true)
  })
  it('keeps null and zero readings visually distinct', async () => {
    const source = levels(), response = result(source)
    Object.assign(response.walls.put[0], { relative_magnitude: 0, distance_pct: null, gex_magnitude_change_1d_pct: 0, top_three_streak_sessions: null })
    axios.get.mockResolvedValue({ data: response })
    const wrapper = render(source); await flushPromises()
    const metrics = wrapper.get('.wall-metrics').findAll('dd').map(node => node.text())
    expect(metrics).toEqual(['0.0×', '—', '0.0%', '—'])
    wrapper.unmount()
  })

  it('shows current reference exposure and explains changed expiration baskets without invented history', async () => {
    const source = levels(), response = result(source)
    Object.assign(response.walls.put[0], {
      gex_magnitude_change_1d_pct: null, top_three_streak_sessions: null, oi_change_1d: null,
      history: [
        { date: '2026-09-30', net_gex: null, display_net_gex: -100, display_status: 'current_reference', comparable: false },
        { date: '2026-09-29', net_gex: null, display_net_gex: null, display_status: 'expiry_scope_changed', comparable: false },
        { date: '2026-09-28', net_gex: null, display_net_gex: null, display_status: 'no_comparison', comparable: false },
      ],
    })
    axios.get.mockResolvedValue({ data: response })
    const wrapper = render(source); await flushPromises()
    const days = wrapper.findAll('.history-day')
    expect(days[0].text()).toContain('Not compared')
    expect(days[1].text()).toContain('Different expirations')
    expect(days[1].find('i').exists()).toBe(false)
    expect(days[2].text()).toContain('Current level')
    expect(days[2].find('i').exists()).toBe(true)
    expect(days[2].get('b').text()).toBe(wrapper.get('.wall-reading strong').text())
    expect(wrapper.text()).toContain('Expiration set changed')
    expect(wrapper.text()).toContain('shown for reference')
    expect(wrapper.text()).toContain('A dash means not compared, never zero exposure')
    expect(wrapper.text()).toContain('They are not the length of the history chart')
    expect(wrapper.text()).toContain('not a count of successful price holds')
    wrapper.unmount()
  })
})
