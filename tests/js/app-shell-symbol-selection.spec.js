import { flushPromises, mount } from '@vue/test-utils'
import axios from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

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
