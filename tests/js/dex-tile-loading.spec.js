import { flushPromises, mount } from '@vue/test-utils'
import axios from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import DexTile from '@/Components/DexTile.vue'

vi.mock('axios', () => ({ default: { get: vi.fn() } }))

function deferred() {
  let resolve
  let reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}

function dexResponse(total = 1234, date = '2026-09-04') {
  return { data: { total, data_date: date, by_expiry: [{ exp_date: '2026-09-11', dex_total: total }] } }
}

function gexResponse(strength = 0.82, sign = 1) {
  return { data: { regime_strength: strength, gamma_sign: sign } }
}

describe('DexTile request and retry ownership', () => {
  let wrapper

  beforeEach(() => {
    vi.useFakeTimers()
    axios.get.mockReset()
    axios.get.mockImplementation((url) => Promise.resolve(url === '/api/dex' ? dexResponse() : gexResponse()))
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.clearAllTimers()
    vi.useRealTimers()
  })

  function start(symbol = 'AAPL') {
    wrapper = mount(DexTile, {
      props: { symbol },
      global: { stubs: { DexByExpiryDiverging: true } },
    })
    return wrapper
  }

  function requests(path) {
    return axios.get.mock.calls.filter(([url]) => url === path)
  }

  it('keeps the complete DEX and GEX fields unchanged without scheduling retries', async () => {
    start()
    await flushPromises()

    expect(wrapper.vm.dex).toBe(1234)
    expect(wrapper.vm.byExpiry).toEqual(dexResponse().data.by_expiry)
    expect(wrapper.vm.dataDate).toBe('2026-09-04')
    expect(wrapper.vm.strength).toBe(0.82)
    expect(wrapper.vm.gammaSign).toBe(1)
    expect(wrapper.text()).toContain('82%')
    expect(axios.get).toHaveBeenCalledTimes(2)
    expect(requests('/api/gex-levels')[0][1].params).toEqual({ symbol: 'AAPL', timeframe: '14d' })

    await vi.advanceTimersByTimeAsync(12000)
    expect(axios.get).toHaveBeenCalledTimes(2)
  })

  it('keeps the four-second retry while mounted and stops when data becomes ready', async () => {
    let dexRequests = 0
    axios.get.mockImplementation((url) => {
      if (url === '/api/dex') return Promise.resolve(dexResponse(1234, ++dexRequests === 1 ? null : '2026-09-04'))
      return Promise.resolve(gexResponse())
    })
    start()
    await flushPromises()
    await vi.advanceTimersByTimeAsync(3999)
    expect(axios.get).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(1)
    expect(axios.get).toHaveBeenCalledTimes(4)
    expect(wrapper.vm.dataDate).toBe('2026-09-04')
    await vi.advanceTimersByTimeAsync(12000)
    expect(axios.get).toHaveBeenCalledTimes(4)
  })

  it('makes zero further requests after unmount with a missing-date retry scheduled', async () => {
    axios.get.mockImplementation((url) => Promise.resolve(url === '/api/dex' ? dexResponse(1234, null) : gexResponse()))
    start()
    await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(2)

    wrapper.unmount()
    wrapper = null
    await vi.advanceTimersByTimeAsync(12000)
    expect(axios.get).toHaveBeenCalledTimes(2)
    expect(vi.getTimerCount()).toBe(0)
  })

  it.each(['resolve', 'reject'])('does not start GEX or retry when a DEX request %ss after unmount', async (settlement) => {
    const dex = deferred()
    axios.get.mockImplementation((url) => url === '/api/dex' ? dex.promise : Promise.resolve(gexResponse()))
    start()
    const signal = requests('/api/dex')[0][1].signal
    wrapper.unmount()
    wrapper = null
    expect(signal?.aborted).toBe(true)

    if (settlement === 'resolve') dex.resolve(dexResponse(1234, null))
    else dex.reject(new Error('request canceled'))
    await flushPromises()
    await vi.advanceTimersByTimeAsync(12000)
    expect(axios.get).toHaveBeenCalledTimes(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('does not apply a late GEX response or recreate a retry after unmount', async () => {
    const gex = deferred()
    axios.get.mockImplementation((url) => url === '/api/dex' ? Promise.resolve(dexResponse(1234, null)) : gex.promise)
    const vm = start().vm
    await flushPromises()
    const signal = requests('/api/gex-levels')[0][1].signal
    wrapper.unmount()
    wrapper = null
    expect(signal?.aborted).toBe(true)
    gex.resolve(gexResponse())
    await flushPromises()
    await vi.advanceTimersByTimeAsync(12000)

    expect(vm.strength).toBeNull()
    expect(vm.gammaSign).toBeNull()
    expect(axios.get).toHaveBeenCalledTimes(2)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('does not apply obsolete DEX or issue its GEX follow-up for a different symbol', async () => {
    const stale = deferred()
    axios.get.mockImplementation((url, { params }) => {
      if (url === '/api/dex' && params.symbol === 'AAPL') return stale.promise
      return Promise.resolve(url === '/api/dex' ? dexResponse(9999) : gexResponse(0.4, -1))
    })
    start()
    const signal = requests('/api/dex')[0][1].signal
    await wrapper.setProps({ symbol: 'QQQ' })
    await flushPromises()
    expect(signal?.aborted).toBe(true)

    stale.resolve(dexResponse(-12, null))
    await flushPromises()
    await vi.advanceTimersByTimeAsync(12000)

    expect(wrapper.vm.dex).toBe(9999)
    expect(wrapper.vm.byExpiry).toEqual(dexResponse(9999).data.by_expiry)
    expect(wrapper.vm.dataDate).toBe('2026-09-04')
    expect(wrapper.vm.strength).toBe(0.4)
    expect(wrapper.vm.gammaSign).toBe(-1)
    expect(axios.get).toHaveBeenCalledTimes(3)
    expect(requests('/api/gex-levels')).toHaveLength(1)
    expect(requests('/api/gex-levels')[0][1].params.symbol).toBe('QQQ')
  })

  it('does not replace the new symbol regime with a late old GEX response', async () => {
    const stale = deferred()
    axios.get.mockImplementation((url, { params }) => {
      if (url === '/api/gex-levels' && params.symbol === 'AAPL') return stale.promise
      return Promise.resolve(url === '/api/dex' ? dexResponse(params.symbol === 'AAPL' ? 1234 : 9999) : gexResponse(0.4, -1))
    })
    start()
    await flushPromises()
    const signal = requests('/api/gex-levels')[0][1].signal
    await wrapper.setProps({ symbol: 'QQQ' })
    await flushPromises()
    expect(signal?.aborted).toBe(true)

    stale.resolve(gexResponse(0.99, 1))
    await flushPromises()
    expect(wrapper.vm.dex).toBe(9999)
    expect(wrapper.vm.strength).toBe(0.4)
    expect(wrapper.vm.gammaSign).toBe(-1)
    expect(axios.get).toHaveBeenCalledTimes(4)
  })
})
