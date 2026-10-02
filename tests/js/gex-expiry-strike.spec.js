import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import GexExpiryStrike from '@/Components/GexExpiryStrike.vue'
vi.mock('axios', () => ({ default: { get: vi.fn() } }))
let serial = 0
const wrappers = []
function fixture(count = 24, dates = 10) {
  const symbol = 'T' + (++serial)
  const expirations = Array.from({ length: dates }, (_, i) => `2026-10-${String(i + 1).padStart(2, '0')}`)
  const strikes = Array.from({ length: count }, (_, i) => ({ strike: 100 + i, call_gex: dates * 100, put_gex: dates * 200, net_gex: -dates * 100,
    absolute_net_gex: dates * 100, dominant_expiry: expirations[0], wall_expiry_concentration: 1 / dates, expiring_next_ratio: 1 / dates,
    expirations: expirations.map(expiration => ({ expiration, data_date: '2026-09-30', call_gex: 100, put_gex: 200, net_gex: -100, contribution_pct: 100 / dates })) }))
  const source = { symbol, timeframe: '14d', data_date: '2026-09-30', expiration_dates: expirations,
    view_context: { view: 'next_session', session_date: '2026-10-01', source_anchor: '2026-09-30' },
    strike_data: strikes.map(row => ({ strike: row.strike, call_gex: row.call_gex * 100, put_gex: row.put_gex * 100, net_gex: row.net_gex * 100 })) }
  return { source, result: { ...source, schema_version: 'gex-expiry-strike.v1', strikes, reference_price: { value: 105 }, next_expiry_dates: [expirations[0]],
    summary: { net_gex: -count * dates * 100, put_wall: 100, call_wall: null, cell_count: count * dates } } }
}
function render(source, enabled = true) {
  const wrapper = mount(GexExpiryStrike, { props: { levels: source, scopeLabel: '2W', enabled } }); wrappers.push(wrapper); return wrapper
}
beforeEach(() => axios.get.mockReset())
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); vi.restoreAllMocks() })
describe('GEX expiration map', () => {
  it('bounds rendered cells while making all strikes and expirations reachable without extra requests', async () => {
    const { source, result } = fixture(); axios.get.mockResolvedValue({ data: result })
    const wrapper = render(source); expect(wrapper.text()).toContain('Loading'); await flushPromises()
    expect(wrapper.findAll('.heat-cell')).toHaveLength(21 * 8)
    expect(wrapper.find('table').exists()).toBe(false)
    await wrapper.get('select').setValue('123')
    expect(wrapper.get('.inspector').text()).toContain('Strike 123')
    const later = wrapper.findAll('button').find(button => button.text() === 'Later expiries'); await later.trigger('click')
    expect(wrapper.findAll('.axis-date').at(-1).text()).toBe('Oct 10')
    const lastCell = wrapper.findAll('.heat-cell').at(-1); await lastCell.trigger('click')
    expect(wrapper.get('.inspector').text()).toContain('Oct 10')
    expect(axios.get).toHaveBeenCalledTimes(1)
    expect(wrapper.emitted('reading-inspected').length).toBeGreaterThan(0)
  })
  it('supports arrow-key inspection and keeps the selection in view', async () => {
    const { source, result } = fixture(); axios.get.mockResolvedValue({ data: result }); const wrapper = render(source); await flushPromises()
    await wrapper.get('[data-selected="true"]').trigger('keydown', { key: 'ArrowRight' })
    expect(wrapper.get('.inspector').text()).toContain('Oct 2')
    expect(wrapper.get('.mobile-reading').text()).toContain('Oct 2')
    expect(wrapper.get('.mobile-reading strong').text()).toBe('−100')
    expect(wrapper.findAll('.heat-cell[tabindex="0"]')).toHaveLength(1)
  })
  it('paginates the complete table only after expansion', async () => {
    const { source, result } = fixture(); axios.get.mockResolvedValue({ data: result }); const wrapper = render(source); await flushPromises()
    wrapper.get('details').element.open = true; await wrapper.get('details').trigger('toggle')
    expect(wrapper.findAll('tbody tr')).toHaveLength(50)
    await wrapper.findAll('button').find(button => button.text() === 'Next readings').trigger('click')
    expect(wrapper.text()).toContain('Page 2 of 5')
  })
  it('distinguishes measured zero from absent cells and null concentration', async () => {
    const { source, result } = fixture(1, 2)
    result.strikes[0].expirations.pop(); Object.assign(result.strikes[0].expirations[0], { net_gex: 0, call_gex: 0, put_gex: 0, contribution_pct: null })
    Object.assign(result.strikes[0], { net_gex: 0, call_gex: 0, put_gex: 0, dominant_expiry: null, wall_expiry_concentration: null, expiring_next_ratio: null })
    Object.assign(source.strike_data[0], { net_gex: 0, call_gex: 0, put_gex: 0 })
    axios.get.mockResolvedValue({ data: result }); const wrapper = render(source); await flushPromises()
    expect(wrapper.findAll('.heat-cell').map(cell => cell.text())).toEqual(['=', '—'])
    expect(wrapper.get('.hero-number').text()).toBe('0')
    expect(wrapper.findAll('.concentrations strong').map(el => el.text())).toEqual(['—', '—'])
    await wrapper.findAll('.heat-cell')[1].trigger('click'); expect(wrapper.text()).toContain('No contracts at this strike/expiry combination')
  })
  it('does not fetch an inactive tab and clears the old symbol during selection changes', async () => {
    const first = fixture(), second = fixture(); let resolveSecond
    axios.get.mockResolvedValueOnce({ data: first.result }).mockImplementationOnce(() => new Promise(resolve => { resolveSecond = resolve }))
    const wrapper = render(first.source, false); await flushPromises(); expect(axios.get).not.toHaveBeenCalled()
    await wrapper.setProps({ enabled: true }); await flushPromises(); expect(wrapper.find('.heatmap').exists()).toBe(true)
    await wrapper.setProps({ levels: second.source }); expect(wrapper.find('.heatmap').exists()).toBe(false)
    resolveSecond({ data: second.result }); await flushPromises(); expect(wrapper.text()).toContain(second.source.symbol)
  })
  it('rejects stale scope and same-scope exposure mismatches, with retry', async () => {
    const { source, result } = fixture(); const bad = structuredClone(result); bad.strikes[0].net_gex -= 9
    axios.get.mockResolvedValueOnce({ data: bad }).mockResolvedValueOnce({ data: result })
    const wrapper = render(source); await flushPromises(); expect(wrapper.get('[role="alert"]').text()).toContain('Refresh the dashboard')
    await wrapper.findAll('button').find(button => button.text() === 'Retry expiration map').trigger('click'); await flushPromises()
    expect(wrapper.find('.heatmap').exists()).toBe(true)
  })
  it('discards a response from a previous symbol even if cancellation arrives late', async () => {
    const first = fixture(), second = fixture(); let resolveFirst
    axios.get.mockImplementationOnce(() => new Promise(resolve => { resolveFirst = resolve })).mockResolvedValueOnce({ data: second.result })
    const wrapper = render(first.source); await wrapper.setProps({ levels: second.source }); await flushPromises()
    resolveFirst({ data: first.result }); await flushPromises()
    expect(wrapper.text()).toContain(second.source.symbol); expect(wrapper.text()).not.toContain(first.source.symbol)
  })
})
