import { flushPromises, shallowMount } from '@vue/test-utils'
import axios from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Dashboard from '@/Components/Dashboard.vue'

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), defaults: { headers: { common: {} } } } }))
const wrappers = []
const response = data => ({ status: 200, data, headers: {} })
const calls = url => axios.get.mock.calls.filter(([path]) => path === url)
async function render(search) {
  window.history.replaceState({}, '', '/dashboard?' + search)
  const wrapper = shallowMount(Dashboard, { global: { renderStubDefaultSlot: true } })
  wrappers.push(wrapper)
  await flushPromises()
  return wrapper
}
async function tab(wrapper, label) {
  await wrapper.findAll('[role="tab"]').find(button => button.text().startsWith(label)).trigger('click')
  await flushPromises()
}
beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date('2026-09-30T14:00:00Z'))
  HTMLElement.prototype.scrollIntoView = vi.fn()
  localStorage.setItem('gex_onboarding_v1', 'seen')
  axios.get.mockReset(); axios.post.mockReset()
  axios.get.mockImplementation((url, options = {}) => {
    const { symbol = 'SPY', timeframe = '14d', view = 'latest_eod' } = options.params || {}
    if (url === '/api/gex-levels') return Promise.resolve(response({ symbol, timeframe, data_date: '2026-09-29', view_context: { view, session_date: '2026-09-29' }, strike_data: [{ strike: 100, net_gex: 10 }], expiration_dates: ['2026-10-02'] }))
    if (url === '/api/dex') return Promise.resolve(response({ symbol }))
    if (url === '/api/intraday/summary' || url === '/api/intraday/strikes') return Promise.resolve(response({ open: true, snapshot_available: true, refresh_eligible: false, asof: '2026-09-30T14:00:00Z', items: [] }))
    throw new Error('Unexpected request: ' + url)
  })
})
afterEach(() => {
  wrappers.splice(0).forEach(wrapper => wrapper.unmount())
  vi.clearAllTimers(); vi.useRealTimers()
  window.history.replaceState({}, '', '/')
})

describe('Wall navigation', () => {
  it('opens Wall tracking independently, without requesting or polling flow data', async () => {
    const wrapper = await render('symbol=TSLA&mode=intraday&tab=walls&timeframe=7d')
    expect(wrapper.findComponent({ name: 'IntradayWallTracker' }).props('symbol')).toBe('TSLA')
    expect(wrapper.findComponent({ name: 'IntradayFlowPanel' }).exists()).toBe(false)
    expect(wrapper.text()).toContain('Intraday wall tracking')
    await vi.advanceTimersByTimeAsync(90_000); await flushPromises()
    expect(axios.get).not.toHaveBeenCalled()
    expect(axios.post).not.toHaveBeenCalled()
    wrapper.vm.userSymbol = 'QQQ'
    await vi.advanceTimersByTimeAsync(300); await flushPromises()
    expect(wrapper.findComponent({ name: 'IntradayWallTracker' }).props('symbol')).toBe('QQQ')
    expect(axios.get).not.toHaveBeenCalled()
  })

  it('moves summary to full EOD analysis, then tracking, and returns with the original EOD scope', async () => {
    const wrapper = await render('symbol=QQQ&mode=eod&tab=overview&timeframe=7d&view=next_session')
    let walls = wrapper.findComponent({ name: 'WallLevels' })
    expect(walls.props('summaryOnly')).toBe(true)
    expect(walls.props('intelligenceEnabled')).toBe(false)
    expect(wrapper.findComponent({ name: 'GammaProfile' }).exists()).toBe(false)
    walls.vm.$emit('open-analysis'); await flushPromises()
    expect(wrapper.vm.activeTab).toBe('positioning')
    walls = wrapper.findComponent({ name: 'WallLevels' })
    expect(walls.props('summaryOnly')).toBe(false)
    expect(walls.props('intelligenceEnabled')).toBe(true)
    const gamma = wrapper.findComponent({ name: 'GammaProfile' })
    expect(gamma.props('levels')).toMatchObject({ symbol: 'QQQ', timeframe: '7d', view_context: { view: 'next_session' } })
    expect(wrapper.get('.gex-eod-view').exists()).toBe(true)
    walls.vm.$emit('open-tracking'); await flushPromises()
    expect(window.location.search).toContain('mode=intraday&tab=walls')
    expect(wrapper.findComponent({ name: 'GammaProfile' }).exists()).toBe(false)
    expect(wrapper.vm.userSymbol).toBe('QQQ')
    expect(calls('/api/intraday/summary')).toHaveLength(0)
    wrapper.findComponent({ name: 'IntradayWallTracker' }).vm.$emit('open-eod'); await flushPromises()
    expect(wrapper.vm.activeTab).toBe('positioning')
    expect(wrapper.vm.gexTf).toBe('7d')
    expect(wrapper.vm.eodView).toBe('next_session')
    expect(wrapper.vm.userSymbol).toBe('QQQ')
  })

  it('keeps the strike chart focused and provides an analysis link', async () => {
    const wrapper = await render('symbol=SPY&mode=eod&tab=strikes')
    expect(wrapper.findComponent({ name: 'WallLevels' }).exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'GexExpiryStrike' }).props('enabled')).toBe(true)
    expect(wrapper.findComponent({ name: 'NetGexChart' }).props('perOnePercent')).toBe(true)
    const button = wrapper.findAllComponents({ name: 'UiButton' }).find(item => item.text() === 'Explore wall analysis')
    button.vm.$emit('click'); await flushPromises()
    expect(wrapper.vm.activeTab).toBe('positioning')
    expect(wrapper.findComponent({ name: 'GexExpiryStrike' }).props('enabled')).toBe(false)
    expect(wrapper.findComponent({ name: 'WallLevels' }).props('intelligenceEnabled')).toBe(true)
  })

  it('starts flow when requested, stops its work on tracking, and supports Back restoration', async () => {
    const wrapper = await render('symbol=SPY&mode=intraday&tab=walls')
    await tab(wrapper, 'Live Flow')
    expect(calls('/api/intraday/summary')).toHaveLength(1)
    expect(wrapper.findComponent({ name: 'IntradayWallTracker' }).exists()).toBe(false)
    await tab(wrapper, 'Wall tracking')
    const count = axios.get.mock.calls.length
    await vi.advanceTimersByTimeAsync(90_000); await flushPromises()
    expect(axios.get.mock.calls.length).toBe(count)
    window.history.replaceState({}, '', '/dashboard?symbol=QQQ&mode=intraday&tab=walls&timeframe=30d')
    window.dispatchEvent(new PopStateEvent('popstate'))
    await vi.advanceTimersByTimeAsync(300); await flushPromises()
    expect(wrapper.findComponent({ name: 'IntradayWallTracker' }).props('symbol')).toBe('QQQ')
    expect(wrapper.vm.gexTf).toBe('30d')
  })
})
