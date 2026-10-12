import { mount, flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import IntradayVolatility from '@/Components/IntradayVolatility.vue'
vi.mock('axios', () => ({ default: { get: vi.fn() } }))
let serial = 0
const wrappers = []
function fixture() {
  const symbol = 'IV' + (++serial)
  const readings = [0, 1, 2].map(i => ({ recorded_at: `2026-10-02T14:${i}0:00+00:00`, iv: 20 + i, call_iv: 19 + i, put_iv: 22 + i,
    skew: 3, iv_change: i, skew_change: 0, iv_baseline_at: '2026-10-02T14:00:00+00:00', skew_baseline_at: '2026-10-02T14:00:00+00:00' }))
  return { schema_version: 'intraday-volatility.v1', symbol, session: '2026-10-02', expiry: '2026-10-09', expiries: ['2026-10-02', '2026-10-09'], readings, status: 'recorded' }
}
function render(symbol) { const w = mount(IntradayVolatility, { props: { symbol } }); wrappers.push(w); return w }
beforeEach(() => { axios.get.mockReset() })
afterEach(() => { wrappers.splice(0).forEach(w => w.unmount()); vi.useRealTimers() })
describe('Intraday IV and skew', () => {
  it('shows units, dated collected times and comparable baseline with accessible inspection', async () => {
    const data = fixture(); axios.get.mockResolvedValue({ data })
    const w = render(data.symbol); await flushPromises()
    expect(w.get('[data-testid=iv-level]').text()).toBe('22.00%')
    expect(w.get('[data-testid=iv-skew]').text()).toBe('+3.00 pp')
    expect(w.text()).toContain('+2.00 pp since 10:00 AM ET')
    expect(w.text()).toContain('Recorded 2026-10-02')
    await w.get('input[type=range]').setValue('0')
    expect(w.get('[data-testid=iv-level]').text()).toBe('20.00%')
    expect(w.text()).toContain('First reading in this comparison')
    expect(w.emitted('reading-inspected')).toHaveLength(1)
    await w.findAll('button').find(b => b.text() === 'Put vs call skew').trigger('click')
    expect(w.get('svg').attributes('aria-label')).toContain('percentage points')
    expect(axios.get).toHaveBeenCalledTimes(1)
    expect(w.text()).toContain('does not identify buying or selling')
  })
  it('does not mix expirations or retain the wrong symbol after a late response', async () => {
    const data = fixture(); axios.get.mockResolvedValueOnce({ data })
    const w = render(data.symbol); await flushPromises()
    let settle
    axios.get.mockImplementationOnce(() => new Promise(resolve => { settle = resolve }))
    await w.get('select').setValue('2026-10-02')
    const pending = axios.get.mock.calls.at(-1)[1]
    expect(pending.params.expiry).toBe('2026-10-02')
    expect(w.find('[data-testid=iv-level]').exists()).toBe(false)
    const next = fixture(); axios.get.mockResolvedValueOnce({ data: next })
    await w.setProps({ symbol: next.symbol }); await flushPromises()
    settle({ data: { ...data, expiry: '2026-10-02' } }); await flushPromises()
    expect(pending.signal.aborted).toBe(true)
    expect(w.text()).toContain(next.symbol)
    expect(w.text()).toContain('Expiration 2026-10-09')
  })
  it('connects long pauses visually while keeping trailing nulls and real zeros intact', async () => {
    const data = fixture(); data.readings[1].iv = null; data.readings[2].recorded_at = '2026-10-02T15:00:00+00:00'
    data.readings[2].iv = null; data.readings[2].skew = 0
    axios.get.mockResolvedValue({ data }); const w = render(data.symbol); await flushPromises()
    expect(w.get('[data-testid=iv-level]').text()).toBe('—')
    expect(w.get('[data-testid=iv-skew]').text()).toBe('0.00 pp')
    expect(w.get('.iv-line').attributes('d')).not.toContain('L')
    expect(w.find('.iv-connector').exists()).toBe(false)
    expect(w.findAll('.iv-point')).toHaveLength(1)
    await w.findAll('button').find(b => b.text() === 'Put vs call skew').trigger('click')
    expect(w.get('.iv-line').attributes('d').match(/M/g)).toHaveLength(2)
    expect(w.get('.iv-connector').attributes('d').match(/L/g)).toHaveLength(1)
    expect(w.findAll('.iv-point')).toHaveLength(3)
    expect(w.get('svg').attributes('aria-label')).toContain('values between them are not observations')
  })
  it('bridges only recorded endpoints around a null without adding readings or changing the export', async () => {
    const data = fixture(); data.readings[1].iv = null
    const original = JSON.stringify(data)
    axios.get.mockResolvedValue({ data }); const w = render(data.symbol); await flushPromises()
    expect(w.get('.iv-line').attributes('d').match(/M/g)).toHaveLength(2)
    expect(w.get('.iv-line').attributes('d')).not.toContain('L')
    const points = w.findAll('.iv-point').map(p => `${p.attributes('cx')},${p.attributes('cy')}`)
    expect(points).toHaveLength(2)
    expect(w.get('.iv-connector').attributes('d')).toBe(`M${points[0]} L${points[1]}`)
    expect(w.text()).toContain('3 readings')
    await w.get('input[type=range]').setValue('1')
    expect(w.get('[data-testid=iv-level]').text()).toBe('—')
    expect(w.find('.iv-cursor').exists()).toBe(false)
    expect(JSON.stringify(data)).toBe(original)
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    const originalCreate = URL.createObjectURL, originalRevoke = URL.revokeObjectURL
    const create = vi.fn().mockReturnValue('blob:iv-review')
    URL.createObjectURL = create; URL.revokeObjectURL = vi.fn()
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    try {
      await w.findAll('button').find(b => b.text() === 'Export IV & skew JSON').trigger('click')
      const exported = await new Promise(resolve => {
        const reader = new FileReader(); reader.onload = () => resolve(reader.result)
        reader.readAsText(create.mock.calls[0][0])
      })
      expect(JSON.parse(exported)).toEqual(data)
      await vi.advanceTimersByTimeAsync(1000)
    } finally {
      if (originalCreate) URL.createObjectURL = originalCreate; else delete URL.createObjectURL
      if (originalRevoke) URL.revokeObjectURL = originalRevoke; else delete URL.revokeObjectURL
      click.mockRestore(); vi.useRealTimers()
    }
    await w.findAll('button').find(b => b.text() === 'Put vs call skew').trigger('click')
    expect(w.find('.iv-connector').exists()).toBe(false)
    expect(w.get('.iv-line').attributes('d').match(/L/g)).toHaveLength(2)
  })
  it('does not extend a single valid metric into leading or trailing null readings', async () => {
    const data = fixture(); data.readings[0].iv = null; data.readings[2].iv = null
    data.readings.forEach(row => { row.skew = null })
    axios.get.mockResolvedValue({ data }); const w = render(data.symbol); await flushPromises()
    expect(w.findAll('.iv-point')).toHaveLength(1)
    expect(w.get('.iv-line').attributes('d')).not.toContain('L')
    expect(w.find('.iv-connector').exists()).toBe(false)
    await w.findAll('button').find(b => b.text() === 'Put vs call skew').trigger('click')
    expect(w.get('.iv-line').attributes('d')).toBe('')
    expect(w.findAll('.iv-point')).toHaveLength(0)
    expect(w.find('.iv-connector').exists()).toBe(false)
    expect(w.text()).toContain('Choose another expiration to compare this metric')
  })
  it('only refreshes the active latest session and cancels when removed', async () => {
    vi.useFakeTimers(); const data = fixture(); axios.get.mockResolvedValue({ data })
    const w = render(data.symbol); await flushPromises()
    await vi.advanceTimersByTimeAsync(120000); expect(axios.get).toHaveBeenCalledTimes(2)
    axios.get.mockResolvedValue({ data: { ...data, session: '2026-10-01' } })
    await w.get('input[type=date]').setValue('2026-10-01'); await flushPromises()
    expect(axios.get.mock.calls.at(-1)[1].params.session).toBe('2026-10-01')
    const calls = axios.get.mock.calls.length
    await vi.advanceTimersByTimeAsync(240000); expect(axios.get).toHaveBeenCalledTimes(calls)
    w.unmount(); wrappers.splice(wrappers.indexOf(w), 1)
    await vi.advanceTimersByTimeAsync(240000); expect(axios.get).toHaveBeenCalledTimes(calls)
  })
  it('rejects mismatched API scopes and allows retry without affecting other panels', async () => {
    const data = fixture(); axios.get.mockResolvedValueOnce({ data: { ...data, symbol: 'WRONG' } })
    const w = render(data.symbol); await flushPromises()
    expect(w.get('[role=alert]').text()).toContain('could not load')
    expect(w.find('.iv-metrics').exists()).toBe(false)
    axios.get.mockResolvedValueOnce({ data }); await w.findAll('button').find(b => b.text() === 'Refresh IV & skew').trigger('click'); await flushPromises()
    expect(w.find('.iv-metrics').exists()).toBe(true)
  })
  it('supports empty sessions and a single reading without claiming a session change', async () => {
    const data = fixture(); data.readings = data.readings.slice(0, 1)
    axios.get.mockResolvedValueOnce({ data }); const w = render(data.symbol); await flushPromises()
    expect(w.text()).toContain('One reading so far')
    expect(w.get('input[type=range]').attributes('disabled')).toBeDefined()
    axios.get.mockResolvedValueOnce({ data: { ...data, readings: [], session: '2026-10-01' } })
    await w.get('input[type=date]').setValue('2026-10-01'); await flushPromises()
    expect(w.text()).toContain('No IV comparison recorded')
  })
})
