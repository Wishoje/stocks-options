import { flushPromises, mount } from '@vue/test-utils'
import axios from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import LeftPanel from '@/Components/LeftPanel.vue'

vi.mock('axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

function deferred() {
  let resolve
  let reject
  const promise = new Promise((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

describe('LeftPanel watchlist', () => {
  beforeEach(() => {
    axios.get.mockReset()
    axios.post.mockReset()
  })

  it('renders every saved row with a separate accessible select and remove action', async () => {
    const wrapper = mount(LeftPanel, {
      props: {
        watchlist: [
          { id: 1, symbol: 'AAPL' },
          { id: 2, symbol: 'QQQ' },
          { id: 3, symbol: 'NVDA' },
        ],
        selectedSymbol: 'qqq',
        pinMap: { QQQ: { headline_pin: 72 } },
        uaMap: { QQQ: { count: 4, data_date: '2026-09-11' } },
        removingIds: new Set([3]),
      },
    })

    expect(wrapper.get('[role="combobox"]').attributes()).toMatchObject({
      'aria-autocomplete': 'list',
      'aria-expanded': 'false',
    })
    expect(wrapper.findAll('.watchlist-row')).toHaveLength(3)
    expect(wrapper.get('[data-watchlist-symbol="QQQ"]').classes()).toContain('is-selected')
    expect(wrapper.get('button[aria-label="Selected QQQ dashboard"]').attributes('aria-current')).toBe('true')
    expect(wrapper.text()).toContain('Pin 72')
    expect(wrapper.text()).toContain('UA 4')
    expect(wrapper.get('button[aria-label="Removing NVDA"]').attributes()).toHaveProperty('disabled')

    await wrapper.get('button[aria-label="Open AAPL dashboard"]').trigger('click')
    expect(wrapper.emitted('select')).toEqual([['AAPL']])
    expect(wrapper.emitted('remove')).toBeUndefined()
    expect(localStorage.getItem('calculator_last_symbol')).toBe('AAPL')

    await wrapper.get('button[aria-label="Remove QQQ from watchlist"]').trigger('click')
    expect(wrapper.emitted('remove')).toEqual([[2]])
  })

  it('aborts an obsolete search and only renders the latest response', async () => {
    vi.useFakeTimers()
    const first = deferred()
    const second = deferred()
    axios.get.mockImplementation((url, { params }) => {
      if (url !== '/api/symbols') throw new Error(`Unexpected GET ${url}`)
      return params.q === 'aa' ? first.promise : second.promise
    })

    const wrapper = mount(LeftPanel)
    const input = wrapper.get('[role="combobox"]')

    await input.setValue('aa')
    await vi.advanceTimersByTimeAsync(200)
    const firstSignal = axios.get.mock.calls[0][1].signal

    await input.setValue('aapl')
    expect(firstSignal.aborted).toBe(true)
    await vi.advanceTimersByTimeAsync(200)

    second.resolve({
      data: { items: [{ symbol: 'AAPL', name: 'Apple Inc.', exchange: 'NASDAQ' }] },
    })
    await flushPromises()
    first.resolve({ data: { items: [{ symbol: 'AA', name: 'Alcoa' }] } })
    await flushPromises()

    expect(wrapper.findAll('[role="option"]')).toHaveLength(1)
    expect(wrapper.get('[role="option"]').text()).toContain('AAPL')
    expect(wrapper.text()).not.toContain('Alcoa')
    expect(input.attributes('aria-expanded')).toBe('true')
  })

  it('supports keyboard selection while preserving the add request sequence', async () => {
    vi.useFakeTimers()
    axios.get.mockImplementation((url) => {
      if (url === '/api/symbols') {
        return Promise.resolve({
          data: {
            items: [
              { symbol: 'AAPL', name: 'Apple Inc.' },
              { symbol: 'TSLA', name: 'Tesla Inc.' },
            ],
          },
        })
      }
      if (url === '/sanctum/csrf-cookie') return Promise.resolve({ data: {} })
      throw new Error(`Unexpected GET ${url}`)
    })
    axios.post.mockResolvedValue({ data: {} })

    const wrapper = mount(LeftPanel)
    const input = wrapper.get('[role="combobox"]')
    await input.setValue('t')
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()

    await input.trigger('keydown', { key: 'ArrowDown' })
    expect(input.attributes('aria-activedescendant')).toContain('option-1')
    await input.trigger('keydown', { key: 'Enter' })
    await flushPromises()

    expect(axios.get.mock.calls.map(([url]) => url)).toEqual([
      '/api/symbols',
      '/sanctum/csrf-cookie',
    ])
    expect(axios.post).toHaveBeenCalledTimes(1)
    expect(axios.post).toHaveBeenCalledWith('/api/watchlist', { symbol: 'TSLA' })
    expect(wrapper.emitted('refresh')).toHaveLength(1)
    expect(wrapper.emitted('select')).toEqual([['TSLA']])
    expect(localStorage.getItem('calculator_last_symbol')).toBe('TSLA')
    expect(input.attributes('aria-expanded')).toBe('false')
  })

  it('keeps existing rows visible while reporting refresh and removal errors', () => {
    const wrapper = mount(LeftPanel, {
      props: {
        watchlist: [{ id: 1, symbol: 'SPY' }],
        refreshing: true,
        error: 'We could not remove that symbol. Try again.',
      },
    })

    expect(wrapper.get('.watchlist-items').attributes('aria-busy')).toBe('true')
    expect(wrapper.get('[role="alert"]').text()).toContain('We could not remove that symbol')
    expect(wrapper.findAll('.watchlist-row')).toHaveLength(1)
    expect(wrapper.get('button[aria-label="Refreshing watchlist"]').attributes()).toHaveProperty('disabled')
  })
})
