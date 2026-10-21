import { defineComponent, provide, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import WallAlertControls from '@/Components/WallAlertControls.vue'
import WallAlertCenter from '@/Components/WallAlertCenter.vue'
import WallWatchlist from '@/Components/WallWatchlist.vue'
import { useWallAlerts } from '@/composables/useWallAlerts'
import { WALL_ALERTS } from '@/Support/wallAlerts'
import { WALL_WATCHLIST_OWNER } from '@/Support/wallWatchlistFeed'

vi.mock('axios', () => ({ default: { get: vi.fn() } }))
let id = 700, wrappers = []
const key = value => value.repeat(64)
const row = (id = 'a', at = '2026-09-30T14:15:00Z') => ({ symbol: 'QQQ', side: 'call', strike: 100, close: 100.1,
  observed_at: at, status: 'conditions_met', reaction: 'reclaim', alert_keys: { setup: key(id) }, setup: {
    direction: 'bullish', expires_at: '2026-09-30T14:40:00Z', entry_zone: { low: 100.05, high: 100.15 },
    invalidation: { price: 99.95, close_side: 'below' }, target: { strike: 101 }, evidence_checks: [],
  } })
const payload = (items = []) => ({ schema_version: 'wall-watchlist.v1', session: '2026-09-30', timeframe: '7d',
  state: 'ready', quote_delay_seconds: 900, watchlist_count: 3, assessed_count: 3, items,
  refresh_until: '2026-09-30T20:00:00Z', next_refresh_at: '2026-10-01T13:45:00Z', local_demo_available: true })

function render({ scanner = false, owner = ref(++id) } = {}) {
  let alerts
  const Host = defineComponent({
    components: { WallAlertControls, WallAlertCenter, WallWatchlist },
    setup() {
      provide(WALL_WATCHLIST_OWNER, owner)
      alerts = useWallAlerts(owner); provide(WALL_ALERTS, alerts)
      const showScanner = ref(scanner)
      return { showScanner }
    },
    template: '<div><WallAlertControls v-if="!showScanner"/><WallWatchlist v-else/><WallAlertCenter/><button class="navigate" @click="showScanner = false">Other page</button></div>',
  })
  const wrapper = mount(Host, { attachTo: document.body }); wrappers.push(wrapper)
  return { wrapper, alerts, owner }
}
const enable = async wrapper => { await wrapper.get('.alert-switch input').setValue(true); await flushPromises() }
beforeEach(() => { vi.useFakeTimers(); vi.setSystemTime(new Date('2026-09-30T14:31:00Z')); axios.get.mockReset(); localStorage.clear(); axios.get.mockResolvedValue({ data: payload() }) })
afterEach(() => { wrappers.splice(0).forEach(w=>w.unmount()); vi.clearAllTimers(); vi.useRealTimers(); vi.unstubAllGlobals() })

describe('wall alert controls and shared polling', () => {
  it('makes no request until enabled and shows a labeled test notification that can be dismissed', async () => {
    const { wrapper } = render()
    expect(axios.get).not.toHaveBeenCalled()
    await enable(wrapper)
    expect(axios.get).toHaveBeenCalledTimes(1)
    await wrapper.get('.alert-options button').trigger('click'); await flushPromises()
    expect(document.body.textContent).toContain('Example notification. No market event.')
    document.querySelector('[aria-label="Dismiss test wall alert"]').click(); await flushPromises()
    expect(document.querySelector('.wall-alert-toast')).toBeNull()
    expect(document.querySelector('.wall-alert-notifications').getAttribute('aria-live')).toBe('polite')
  })
  it('shares Scanner polling, continues on another page and stops when alerts are disabled', async () => {
    const { wrapper } = render({ scanner: true })
    await flushPromises(); expect(axios.get).toHaveBeenCalledTimes(1)
    await enable(wrapper); expect(axios.get).toHaveBeenCalledTimes(1)
    await wrapper.get('.navigate').trigger('click'); await flushPromises()
    vi.setSystemTime(new Date('2026-09-30T14:36:00Z'))
    axios.get.mockResolvedValue({ data: payload([row('b', '2026-09-30T14:20:00Z')]) })
    await vi.advanceTimersByTimeAsync(60000)
    expect(document.body.textContent).toContain('QQQ · Setup conditions met')
    const href = document.querySelector('.wall-alert-toast a').getAttribute('href')
    expect(href).toContain('symbol=QQQ'); expect(href).toContain('timeframe=7d')
    await wrapper.get('.alert-switch input').setValue(false)
    const count = axios.get.mock.calls.length
    await vi.advanceTimersByTimeAsync(60000)
    expect(axios.get).toHaveBeenCalledTimes(count)
    expect(document.querySelector('.wall-alert-toast')).toBeNull()
  })
  it('does not replay initial data or repeated setup readings, and removes expired notifications', async () => {
    axios.get.mockResolvedValue({ data: payload([row()]) })
    const { wrapper } = render(); await enable(wrapper)
    expect(document.querySelector('.wall-alert-toast')).toBeNull()
    vi.setSystemTime(new Date('2026-09-30T14:36:00Z'))
    axios.get.mockResolvedValue({ data: payload([row('b', '2026-09-30T14:20:00Z')]) })
    await vi.advanceTimersByTimeAsync(60000)
    expect(document.querySelectorAll('.wall-alert-toast')).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(100000)
    expect(document.querySelector('.wall-alert-toast')).toBeNull()
  })
  it('clears account and watchlist changes, cancels requests, and ignores late responses', async () => {
    const owner = ref(++id), { wrapper, alerts } = render({ owner }); await enable(wrapper)
    await alerts.preview(); expect(document.querySelector('.wall-alert-toast')).not.toBeNull()
    window.dispatchEvent(new Event('watchlist-updated')); await flushPromises()
    expect(document.querySelector('.wall-alert-toast')).toBeNull()
    let finish
    axios.get.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    await vi.advanceTimersByTimeAsync(60000)
    const signal = axios.get.mock.calls.at(-1)[1].signal
    owner.value = ++id; await flushPromises()
    expect(signal.aborted).toBe(true)
    expect(wrapper.get('.alert-switch input').element.checked).toBe(false)
    finish({ data: payload([row('b', '2026-09-30T14:20:00Z')]) }); await flushPromises()
    expect(document.querySelector('.wall-alert-toast')).toBeNull()
  })
  it('backs off rate limits and retries an initial network failure only once per minute', async () => {
    axios.get.mockRejectedValueOnce(new Error('network'))
    const { wrapper } = render(); await enable(wrapper)
    expect(wrapper.text()).toContain('Alerts are paused')
    axios.get.mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '180' } } })
    await vi.advanceTimersByTimeAsync(60000)
    const count = axios.get.mock.calls.length
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(count)
    await vi.advanceTimersByTimeAsync(60000)
    expect(axios.get).toHaveBeenCalledTimes(count + 1)
  })
  it('never sends notifications from local demonstration rows or while the page is hidden', async () => {
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible')
    const { wrapper } = render({ scanner: true }); await flushPromises(); await enable(wrapper)
    axios.get.mockResolvedValueOnce({ data: { ...payload([row()]), state: 'demonstration', session: '2026-09-29' } })
    await wrapper.get('.preview').trigger('click'); await flushPromises()
    expect(document.querySelector('.wall-alert-toast')).toBeNull()
    const before = axios.get.mock.calls.length
    visibility.mockReturnValue('hidden')
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(before)
  })
  it('fails quietly when sound is unsupported and preserves visual alerts', async () => {
    vi.stubGlobal('AudioContext', undefined); vi.stubGlobal('webkitAudioContext', undefined)
    const { wrapper } = render(); await enable(wrapper)
    await wrapper.findAll('.alert-options input')[1].setValue(true); await flushPromises()
    expect(wrapper.text()).toContain('On-screen alerts still work')
    await wrapper.get('.alert-options button').trigger('click'); await flushPromises()
    expect(document.querySelector('.wall-alert-toast')).not.toBeNull()
  })
})
