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
    props: [
      'watchlist',
      'pinMap',
      'uaMap',
      'selectedSymbol',
      'selectingSymbol',
      'loading',
      'refreshing',
      'error',
      'removingIds',
    ],
    emits: ['select', 'add', 'remove', 'refresh'],
    template: `
      <div
        data-left-panel
        :data-selected="selectedSymbol"
        :data-selecting="selectingSymbol"
        :data-loading="String(loading)"
        :data-refreshing="String(refreshing)"
        :data-error="error"
      >
        <input data-watchlist-search>
      </div>
    `,
  },
}))

function deferred() {
  let resolve
  const promise = new Promise((yes) => { resolve = yes })
  return { promise, resolve }
}

describe('AppShell watchlist state', () => {
  beforeEach(() => {
    axios.delete.mockReset()
    axios.get.mockReset()
    axios.post.mockReset()
    axios.post.mockResolvedValue({ data: {} })
  })

  it('shows a recoverable error without replacing the current shell', async () => {
    axios.get.mockRejectedValue(new Error('network unavailable'))
    const wrapper = mount(AppShell)
    await flushPromises()

    const panel = wrapper.get('[data-left-panel]')
    expect(panel.attributes('data-loading')).toBe('false')
    expect(panel.attributes('data-error')).toContain('could not load your saved symbols')
    expect(wrapper.get('#dashboard-main-content').exists()).toBe(true)
    wrapper.unmount()
  })

  it('marks a selected symbol immediately and exposes bounded warmup progress', async () => {
    const status = deferred()
    axios.get.mockImplementation((url) => {
      if (url === '/api/watchlist') return Promise.resolve({ data: [] })
      if (url === '/api/symbol/status') return status.promise
      throw new Error(`Unexpected GET ${url}`)
    })

    const wrapper = mount(AppShell)
    await flushPromises()
    const pending = wrapper.vm.handleSelectSymbol('aapl')
    await flushPromises()

    expect(wrapper.get('[data-left-panel]').attributes()).toMatchObject({
      'data-selected': 'AAPL',
      'data-selecting': 'AAPL',
    })

    status.resolve({ data: { status: 'ready' }, status: 200 })
    await pending
    await flushPromises()
    expect(wrapper.get('[data-left-panel]').attributes('data-selecting')).toBe('')
    expect(axios.post).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('focuses the drawer and restores its trigger after Escape', async () => {
    axios.get.mockResolvedValue({ data: [] })
    const wrapper = mount(AppShell, { attachTo: document.body })
    await flushPromises()
    const trigger = wrapper.get('[aria-controls="mobile-watchlist-drawer"]')

    await trigger.trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="dialog"]').exists()).toBe(true)
    expect(document.activeElement).toBe(wrapper.get('[role="dialog"] [data-watchlist-search]').element)

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(document.activeElement).toBe(trigger.element)
    wrapper.unmount()
  })

  it('keeps the selected row in sync with the dashboard symbol picker', async () => {
    axios.get.mockResolvedValue({ data: [] })
    const wrapper = mount(AppShell)
    await flushPromises()

    window.dispatchEvent(new CustomEvent('dashboard-symbol-changed', {
      detail: { symbol: 'qqq' },
    }))
    await wrapper.vm.$nextTick()

    expect(wrapper.get('[data-selected]').attributes('data-selected')).toBe('QQQ')
    wrapper.unmount()
  })
})
