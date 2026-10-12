import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import DexByStrike from '@/Components/DexByStrike.vue'
vi.mock('axios', () => ({ default: { get: vi.fn() } }))
let serial = 0
const wrappers = []
function fixture(count = 70) {
  const symbol = 'D' + (++serial)
  const strikes = Array.from({ length: count }, (_, i) => ({ strike: 100 + i, call_dex: i * 100, put_dex: -1000, net_dex: i * 100 - 1000, call_gex: 1000, put_gex: 2000, net_gex: -1000 }))
  const source = { symbol, timeframe: '14d', data_date: '2026-09-30', expiration_dates: ['2026-10-02'],
    view_context: { view: 'next_session', session_date: '2026-10-01', source_anchor: '2026-09-30' },
    strike_data: strikes.map(row => ({ strike: row.strike, call_gex: 100000, put_gex: 200000, net_gex: -100000 })) }
  return { source, result: { ...source, schema_version: 'dex-by-strike.v1', dex_unit: 'share equivalents', strikes,
    reference_price: { value: 110, date: '2026-09-30' },
    summary: { net_dex: strikes.reduce((sum, row) => sum + row.net_dex, 0), strike_count: count,
      largest_positive: count > 11 ? { strike: 99 + count, net_dex: (count - 1) * 100 - 1000 } : null, largest_negative: { strike: 100, net_dex: -1000 } } } }
}
function render(source, enabled = true) {
  const wrapper = mount(DexByStrike, { props: { levels: source, scopeLabel: '2W', enabled } }); wrappers.push(wrapper); return wrapper
}
beforeEach(() => axios.get.mockReset())
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); vi.restoreAllMocks() })
describe('DEX by strike', () => {
  it('shows bounded bars with matching DEX/GEX details and makes every strike reachable', async () => {
    const { source, result } = fixture(); axios.get.mockResolvedValue({ data: result })
    const wrapper = render(source); await flushPromises()
    expect(wrapper.findAll('.dex-bar')).toHaveLength(31)
    expect(wrapper.get('.inspector h3').text()).toBe('110')
    expect(wrapper.get('.selected-dex').text()).toBe('0')
    expect(wrapper.get('.gex-comparison').text()).toContain('−1K')
    expect(wrapper.text()).toContain('share equivalents')
    expect(wrapper.text()).toContain('USD per 1% underlying move')
    await wrapper.get('select').setValue('169')
    expect(wrapper.get('.inspector h3').text()).toBe('169')
    expect(wrapper.findAll('.dex-bar').at(-1).attributes('aria-pressed')).toBe('true')
    expect(wrapper.emitted('reading-inspected')[0][0]).toMatchObject({ indicator: 'dex_by_strike', strike: 169 })
    await wrapper.findAll('button').find(button => button.text() === 'Near close').trigger('click')
    expect(wrapper.get('.inspector h3').text()).toBe('110')
    expect(axios.get).toHaveBeenCalledTimes(1)
  })
  it('supports keyboard navigation across chart pages and the complete table without more requests', async () => {
    const { source, result } = fixture(); axios.get.mockResolvedValue({ data: result })
    const wrapper = render(source); await flushPromises()
    await wrapper.get('.dex-bar[tabindex="0"]').trigger('keydown', { key: 'End' })
    expect(wrapper.get('.inspector h3').text()).toBe('169')
    await wrapper.get('.dex-bar[tabindex="0"]').trigger('keydown', { key: 'ArrowLeft' })
    expect(wrapper.get('.inspector h3').text()).toBe('168')
    expect(wrapper.find('table').exists()).toBe(false)
    wrapper.get('.all-readings').element.open = true; await wrapper.get('.all-readings').trigger('toggle')
    expect(wrapper.findAll('tbody tr')).toHaveLength(50)
    await wrapper.findAll('button').find(button => button.text() === 'Next readings').trigger('click')
    expect(wrapper.findAll('tbody tr')).toHaveLength(20)
    await wrapper.findAll('tbody button')[0].trigger('click')
    expect(wrapper.get('.inspector h3').text()).toBe('150')
    expect(axios.get).toHaveBeenCalledTimes(1)
  })
  it('keeps unknown DEX distinct from genuine zero and leaves GEX visible', async () => {
    const { source, result } = fixture(2)
    Object.assign(result.strikes[1], { net_dex: null, call_dex: null, put_dex: null })
    result.strikes[0].net_dex = 0
    axios.get.mockResolvedValue({ data: result }); const wrapper = render(source); await flushPromises()
    expect(wrapper.get('.selected-dex').text()).toBe('—')
    expect(wrapper.get('.gex-comparison strong').text()).toBe('−1K')
    await wrapper.get('select').setValue('100')
    expect(wrapper.get('.selected-dex').text()).toBe('0')
  })
  it('fetches only on the active tab and clears old results on symbol changes', async () => {
    const first = fixture(), second = fixture(); let finish
    axios.get.mockResolvedValueOnce({ data: first.result }).mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    const wrapper = render(first.source, false); await flushPromises(); expect(axios.get).not.toHaveBeenCalled()
    await wrapper.setProps({ enabled: true }); await flushPromises()
    expect(wrapper.find('.inspector').exists()).toBe(true)
    await wrapper.setProps({ levels: second.source })
    expect(wrapper.find('.inspector').exists()).toBe(false)
    finish({ data: second.result }); await flushPromises()
    expect(wrapper.text()).toContain(second.source.symbol)
    expect(axios.get.mock.calls[1][1].params).toMatchObject({ symbol: second.source.symbol, timeframe: '14d', view: 'next_session', session_date: '2026-10-01' })
  })
  it('rejects a same-scope GEX mismatch and retries explicitly', async () => {
    const { source, result } = fixture(); const bad = structuredClone(result); bad.strikes[0].call_gex += 5
    axios.get.mockResolvedValueOnce({ data: bad }).mockResolvedValueOnce({ data: result })
    const wrapper = render(source); await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('Refresh the dashboard')
    expect(wrapper.find('.plot').exists()).toBe(false)
    await wrapper.findAll('button').find(button => button.text() === 'Retry delta exposure').trigger('click'); await flushPromises()
    expect(wrapper.find('.plot').exists()).toBe(true)
  })
  it('rejects duplicate strikes and the wrong expiry basket', async () => {
    const { source, result } = fixture(); const duplicate = structuredClone(result); duplicate.strikes[1] = duplicate.strikes[0]
    axios.get.mockResolvedValueOnce({ data: duplicate }).mockResolvedValueOnce({ data: { ...result, expiration_dates: ['2026-10-09'] } })
    const wrapper = render(source); await flushPromises()
    expect(wrapper.find('.plot').exists()).toBe(false)
    await wrapper.findAll('button').find(button => button.text() === 'Retry delta exposure').trigger('click'); await flushPromises()
    expect(wrapper.find('.plot').exists()).toBe(false)
  })
  it('discards a previous-symbol response even when cancellation arrives late', async () => {
    const first = fixture(), second = fixture(); let finish
    axios.get.mockImplementationOnce(() => new Promise(resolve => { finish = resolve })).mockResolvedValueOnce({ data: second.result })
    const wrapper = render(first.source); const signal = axios.get.mock.calls[0][1].signal
    await wrapper.setProps({ levels: second.source }); await flushPromises()
    expect(signal.aborted).toBe(true)
    finish({ data: first.result }); await flushPromises()
    expect(wrapper.text()).toContain(second.source.symbol)
    expect(wrapper.text()).not.toContain(first.source.symbol)
  })
})
