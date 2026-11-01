import { defineComponent, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import { useSwingResearchAlerts } from '@/composables/useSwingResearchAlerts'

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
const wrappers = []
const alert = (id = 1, overrides = {}) => ({ id, symbol: 'SPY', horizon: 'short', variant: 'nearer_entry', label: 'Closer entry', experimental: true, status: 'qualified', signal_recorded_at: '2026-10-20T20:00:00Z', alert_read_at: null, ...overrides })
function start(owner = ref(19)) {
  let alerts
  const wrapper = mount(defineComponent({ setup() { alerts = useSwingResearchAlerts(owner); return {} }, template: '<div />' }))
  wrappers.push(wrapper)
  return { alerts, owner, wrapper }
}
const response = items => ({ data: { items, unread_count: items.filter(item => !item.alert_read_at).length, truncated: false } })

describe('private experimental swing alerts', () => {
  beforeEach(() => {
    vi.useFakeTimers(); localStorage.clear()
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' })
    Object.defineProperty(navigator, 'onLine', { configurable: true, value: true })
    axios.get.mockReset(); axios.post.mockReset()
    axios.get.mockResolvedValue(response([])); axios.post.mockResolvedValue({ data: { read: true } })
  })
  afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); vi.clearAllTimers() })

  it('never requests research data for a non-owner and polls eligible owners only while visible and online', async () => {
    const { owner } = start(ref(null)); await flushPromises()
    await vi.advanceTimersByTimeAsync(60000); expect(axios.get).not.toHaveBeenCalled()
    owner.value = 19; await flushPromises(); expect(axios.get).toHaveBeenCalledTimes(1)
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' })
    await vi.advanceTimersByTimeAsync(60000); expect(axios.get).toHaveBeenCalledTimes(1)
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' })
    Object.defineProperty(navigator, 'onLine', { configurable: true, value: false })
    await vi.advanceTimersByTimeAsync(60000); expect(axios.get).toHaveBeenCalledTimes(1)
    Object.defineProperty(navigator, 'onLine', { configurable: true, value: true }); window.dispatchEvent(new Event('online'))
    await flushPromises(); expect(axios.get).toHaveBeenCalledTimes(2)
  })

  it('recovers missed persisted cases silently, notifies once per new case and keeps later outcomes', async () => {
    axios.get.mockResolvedValue(response([alert()]))
    const { alerts } = start(); await flushPromises()
    expect(alerts.history.value).toHaveLength(1); expect(alerts.unreadCount.value).toBe(1); expect(alerts.notifications.value).toEqual([])
    axios.get.mockResolvedValue(response([alert(), alert(2)]))
    await vi.advanceTimersByTimeAsync(60000)
    expect(alerts.notifications.value.map(item => item.id)).toEqual([2])
    alerts.dismiss(2); await vi.advanceTimersByTimeAsync(60000); expect(alerts.notifications.value).toEqual([])
    axios.get.mockResolvedValue(response([alert(1, { status: 'target_reached' }), alert(2, { status: 'invalidated' })]))
    await vi.advanceTimersByTimeAsync(60000)
    expect(alerts.history.value.map(item => item.status)).toEqual(['target_reached', 'invalidated'])
    expect(alerts.unreadCount.value).toBe(2); expect(alerts.notifications.value).toEqual([])
    await alerts.markRead(2)
    expect(axios.post).toHaveBeenCalledWith('/api/swing-research/alerts/2/read', {}, expect.objectContaining({ signal: expect.any(AbortSignal) }))
    expect(alerts.unreadCount.value).toBe(1)
  })

  it('aborts and clears all research state on account changes, ignores stale responses and stops on unmount', async () => {
    let resolve
    axios.get.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    const { alerts, owner, wrapper } = start(); const firstSignal = axios.get.mock.calls[0][1].signal
    owner.value = null; await flushPromises(); expect(firstSignal.aborted).toBe(true)
    resolve(response([alert()])); await flushPromises(); expect(alerts.history.value).toEqual([])
    owner.value = 20; await flushPromises(); expect(alerts.notifications.value).toEqual([])
    const calls = axios.get.mock.calls.length
    wrapper.unmount(); await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(calls)
    await alerts.markRead(1); expect(axios.post).not.toHaveBeenCalled()
  })

  it('does not replay dismissed cases after remount and leaves saved history available on refresh errors', async () => {
    const first = start(); await flushPromises()
    axios.get.mockResolvedValue(response([alert(5)])); await vi.advanceTimersByTimeAsync(60000)
    expect(first.alerts.notifications.value).toHaveLength(1); first.alerts.dismiss(5); first.wrapper.unmount()
    const second = start(); await flushPromises(); expect(second.alerts.notifications.value).toEqual([])
    axios.get.mockRejectedValue(new Error('offline')); await vi.advanceTimersByTimeAsync(60000)
    expect(second.alerts.history.value).toHaveLength(1); expect(second.alerts.error.value).toContain('could not refresh')
  })

  it('keeps a confirmed server read when an older pending poll returns and never posts after disposal', async () => {
    axios.get.mockResolvedValueOnce(response([alert(), alert(2)]))
    const { alerts, wrapper } = start(); await flushPromises()
    let resolve
    axios.get.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    const refresh = alerts.refresh(); await alerts.markRead(1)
    resolve(response([alert(), alert(2)])); await refresh
    expect(alerts.history.value[0].alert_read_at).toBeTruthy(); expect(alerts.unreadCount.value).toBe(1)
    wrapper.unmount(); await alerts.markRead(2)
    expect(axios.post).toHaveBeenCalledTimes(1)
  })
})
