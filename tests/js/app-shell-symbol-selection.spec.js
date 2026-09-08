import { flushPromises, mount } from '@vue/test-utils'
import axios from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import AppShell from '@/Components/AppShell.vue'

vi.mock('axios', () => ({
  default: {
    delete: vi.fn(),
    get: vi.fn(),
    post: vi.fn(),
  },
}))

vi.mock('@/Components/LeftPanel.vue', () => ({
  default: {
    emits: ['select'],
    template: '<button data-test="select-symbol" @click="$emit(\'select\', \'AAPL\')">AAPL</button>',
  },
}))

function statusResponse(data, status) {
  return { data, status, headers: {} }
}

function deferred() {
  let resolve
  let reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}

async function mountShell(status, prime = null) {
  axios.get.mockImplementation((url) => {
    if (url === '/api/watchlist') return Promise.resolve({ data: [] })
    if (url === '/api/symbol/status') return Promise.resolve(status)
    return Promise.reject(new Error(`Unexpected GET ${url}`))
  })
  axios.post.mockImplementation((url) => {
    if (url === '/api/prime') return Promise.resolve(prime || { data: {} })
    if (url === '/api/prime-calculator' || url === '/api/intraday/pull') {
      return Promise.resolve({ data: {} })
    }
    return Promise.reject(new Error(`Unexpected POST ${url}`))
  })

  const wrapper = mount(AppShell)
  await flushPromises()
  const dispatch = vi.spyOn(window, 'dispatchEvent')
  await wrapper.get('[data-test="select-symbol"]').trigger('click')
  await flushPromises()

  return { dispatch, wrapper }
}

describe('AppShell symbol selection', () => {
  beforeEach(() => {
    axios.delete.mockReset()
    axios.get.mockReset()
    axios.post.mockReset()
  })

  it('checks readiness first and starts intraday plus calculator for a ready symbol', async () => {
    const { dispatch, wrapper } = await mountShell(statusResponse({ status: 'ready' }, 200))

    expect(axios.get).toHaveBeenCalledWith('/api/symbol/status', expect.objectContaining({
      params: { symbol: 'AAPL', timeframe: '14d' },
    }))
    expect(axios.post).toHaveBeenCalledWith('/api/prime-calculator', { symbol: 'AAPL' })
    expect(axios.post).toHaveBeenCalledWith('/api/intraday/pull', { symbols: ['AAPL'] })
    expect(axios.post).not.toHaveBeenCalledWith('/api/prime', expect.anything())

    const statusOrder = axios.get.mock.invocationCallOrder[1]
    const firstWarmupOrder = Math.min(...axios.post.mock.invocationCallOrder)
    expect(statusOrder).toBeLessThan(firstWarmupOrder)

    const event = dispatch.mock.calls.find(([item]) => item.type === 'select-symbol')?.[0]
    expect(event?.detail).toMatchObject({ symbol: 'AAPL', symbolStatus: { status: 'ready' } })
    wrapper.unmount()
  })

  it('hands a missing symbol to prime and does not start premature intraday work', async () => {
    const prime = statusResponse({
      status: 'pending',
      run_id: 'run-1',
      status_url: '/api/work-runs/run-1',
      bootstrap: { state: 'queued', fast_ready: false, full_ready: false },
    }, 202)
    const { dispatch, wrapper } = await mountShell(
      statusResponse({ status: 'missing' }, 404),
      prime,
    )

    expect(axios.post).toHaveBeenCalledWith('/api/prime-calculator', { symbol: 'AAPL' })
    expect(axios.post).toHaveBeenCalledWith('/api/prime', { symbol: 'AAPL', timeframe: '14d' })
    expect(axios.post).not.toHaveBeenCalledWith('/api/intraday/pull', expect.anything())

    const event = dispatch.mock.calls.find(([item]) => item.type === 'select-symbol')?.[0]
    expect(event?.detail).toMatchObject({
      symbol: 'AAPL',
      symbolStatus: { status: 'missing' },
      bootstrapStart: prime.data,
    })
    wrapper.unmount()
  })
})

describe('AppShell selection request ownership', () => {
  let wrapper
  let dispatch

  beforeEach(() => {
    axios.get.mockReset()
    axios.post.mockReset()
    axios.post.mockResolvedValue({ data: {} })
    dispatch = vi.spyOn(window, 'dispatchEvent')
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  async function prepare(status = () => Promise.resolve(statusResponse({ status: 'ready' }, 200))) {
    axios.get.mockImplementation((url, options) => {
      if (url === '/api/watchlist') return Promise.resolve({ data: [] })
      if (url === '/api/symbol/status') return status(options)
      throw new Error(`Unexpected GET ${url}`)
    })
    wrapper = mount(AppShell)
    await flushPromises()
  }

  function selections() {
    return dispatch.mock.calls.filter(([event]) => event.type === 'select-symbol')
      .map(([event]) => event.detail.symbol)
  }

  function statusCalls() {
    return axios.get.mock.calls.filter(([url]) => url === '/api/symbol/status')
  }

  it('coalesces duplicate pending selections but checks readiness again after completion', async () => {
    const status = deferred()
    await prepare(() => status.promise)

    const first = wrapper.vm.handleSelectSymbol('AAPL')
    const duplicate = wrapper.vm.handleSelectSymbol('AAPL')
    expect(statusCalls()).toHaveLength(1)
    expect(axios.post).not.toHaveBeenCalled()

    status.resolve(statusResponse({ status: 'ready' }, 200))
    await Promise.all([first, duplicate])
    expect(axios.post).toHaveBeenCalledTimes(2)
    expect(selections()).toEqual(['AAPL'])

    await wrapper.vm.handleSelectSymbol('AAPL')
    expect(statusCalls()).toHaveLength(2)
    expect(axios.post).toHaveBeenCalledTimes(4)
    expect(selections()).toEqual(['AAPL', 'AAPL'])
  })

  it('also coalesces duplicate selection while its warmup POST is pending', async () => {
    const warmup = deferred()
    axios.post.mockImplementation((url) => url === '/api/prime-calculator'
      ? warmup.promise : Promise.resolve({ data: {} }))
    await prepare()

    const first = wrapper.vm.handleSelectSymbol('AAPL')
    await flushPromises()
    const duplicate = wrapper.vm.handleSelectSymbol('AAPL')
    await flushPromises()
    expect(statusCalls()).toHaveLength(1)
    expect(axios.post).toHaveBeenCalledTimes(2)

    warmup.resolve({ data: {} })
    await Promise.all([first, duplicate])
    expect(selections()).toEqual(['AAPL'])
  })

  it.each(['resolve', 'reject'])('does not prime or select an obsolete status response that later %ss', async (settlement) => {
    const stale = deferred()
    await prepare(({ params }) => params.symbol === 'AAPL'
      ? stale.promise : Promise.resolve(statusResponse({ status: 'ready' }, 200)))

    const first = wrapper.vm.handleSelectSymbol('AAPL')
    const oldSignal = statusCalls()[0][1].signal
    await wrapper.vm.handleSelectSymbol('QQQ')
    expect(oldSignal?.aborted).toBe(true)

    if (settlement === 'resolve') stale.resolve(statusResponse({ status: 'missing' }, 404))
    else stale.reject(new Error('request canceled'))
    await first

    expect(axios.post).toHaveBeenCalledTimes(2)
    expect(axios.post).toHaveBeenCalledWith('/api/prime-calculator', { symbol: 'QQQ' })
    expect(axios.post).toHaveBeenCalledWith('/api/intraday/pull', { symbols: ['QQQ'] })
    expect(selections()).toEqual(['QQQ'])
  })

  it('does not emit an obsolete selection after its already-started warmup finishes', async () => {
    const warmup = deferred()
    axios.post.mockImplementation((url, data) => url === '/api/prime-calculator' && data.symbol === 'AAPL'
      ? warmup.promise : Promise.resolve({ data: {} }))
    await prepare()

    const first = wrapper.vm.handleSelectSymbol('AAPL')
    await flushPromises()
    expect(axios.post).toHaveBeenCalledTimes(2)
    await wrapper.vm.handleSelectSymbol('QQQ')
    warmup.resolve({ data: {} })
    await first

    expect(axios.post).toHaveBeenCalledTimes(4)
    expect(selections()).toEqual(['QQQ'])
  })

  it('aborts the pending status GET on unmount without starting fallback warmups', async () => {
    const status = deferred()
    await prepare(() => status.promise)
    const select = wrapper.vm.handleSelectSymbol
    const pending = select('AAPL')
    const signal = statusCalls()[0][1].signal

    wrapper.unmount()
    wrapper = null
    expect(signal?.aborted).toBe(true)
    status.resolve(statusResponse({ status: 'missing' }, 404))
    await pending
    await select('QQQ')

    expect(statusCalls()).toHaveLength(1)
    expect(axios.post).not.toHaveBeenCalled()
    expect(selections()).toEqual([])
  })

  it('does not emit after unmount when a warmup POST was already accepted', async () => {
    const warmup = deferred()
    axios.post.mockReturnValue(warmup.promise)
    await prepare()
    const pending = wrapper.vm.handleSelectSymbol('AAPL')
    await flushPromises()
    expect(axios.post).toHaveBeenCalledTimes(2)

    wrapper.unmount()
    wrapper = null
    warmup.resolve({ data: {} })
    await pending
    expect(selections()).toEqual([])
  })

  it('retains bounded prime fallback for a current status transport failure', async () => {
    await prepare(() => Promise.reject(new Error('status unavailable')))
    await wrapper.vm.handleSelectSymbol('AAPL')

    expect(axios.post).toHaveBeenCalledTimes(2)
    expect(axios.post).toHaveBeenCalledWith('/api/prime-calculator', { symbol: 'AAPL' })
    expect(axios.post).toHaveBeenCalledWith('/api/prime', { symbol: 'AAPL', timeframe: '14d' })
    expect(selections()).toEqual(['AAPL'])
  })
})
