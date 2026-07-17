import { flushPromises, mount } from '@vue/test-utils'
import axios from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AiExport from '@/Pages/AiExport.vue'

vi.mock('axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

vi.mock('@/Layouts/AppLayout.vue', () => ({
  default: {
    template: '<div><slot name="header" /><slot /></div>',
  },
}))

vi.mock('@/Components/AppShell.vue', () => ({
  default: {
    template: '<div><slot /></div>',
  },
}))

vi.mock('@inertiajs/vue3', () => ({
  Link: {
    template: '<a><slot /></a>',
  },
}))

function mountPage() {
  return mount(AiExport, {
    global: {
      mocks: {
        route: () => '#',
      },
    },
  })
}

describe('AI export history', () => {
  beforeEach(() => {
    axios.get.mockReset()
  })

  it('shows the empty state only after a successful empty response', async () => {
    axios.get.mockImplementation((url) => {
      if (url === '/api/watchlist') return Promise.resolve({ data: [] })
      if (url === '/api/watchlist/eod-exports') return Promise.resolve({ data: { items: [] } })
      return Promise.reject(new Error(`Unexpected URL: ${url}`))
    })

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('No exports yet.')
    expect(wrapper.text()).not.toContain('Failed to load recent exports.')
  })

  it('shows an API error instead of a false empty state', async () => {
    axios.get.mockImplementation((url) => {
      if (url === '/api/watchlist') return Promise.resolve({ data: [] })
      if (url === '/api/watchlist/eod-exports') {
        return Promise.reject({ response: { data: { message: 'Server Error' } } })
      }
      return Promise.reject(new Error(`Unexpected URL: ${url}`))
    })

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Server Error')
    expect(wrapper.text()).not.toContain('No exports yet.')
    expect(wrapper.text()).toContain('Try again')
  })

  it('treats a malformed successful response as an error', async () => {
    axios.get.mockImplementation((url) => {
      if (url === '/api/watchlist') return Promise.resolve({ data: [] })
      if (url === '/api/watchlist/eod-exports') return Promise.resolve({ data: {} })
      return Promise.reject(new Error(`Unexpected URL: ${url}`))
    })

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Export history returned an invalid response.')
    expect(wrapper.text()).not.toContain('No exports yet.')
  })

  it('preserves the last known row when a status refresh fails', async () => {
    vi.useFakeTimers()
    axios.get.mockImplementation((url) => {
      if (url === '/api/watchlist') return Promise.resolve({ data: [] })
      if (url === '/api/watchlist/eod-exports') {
        return Promise.resolve({
          data: {
            items: [{
              id: 12,
              status: 'processing',
              symbol_count: 145,
              indicator_count: 10,
              created_at: '2026-07-17T06:24:18Z',
            }],
          },
        })
      }
      if (url === '/api/watchlist/eod-export/12') {
        return Promise.reject({ response: { data: { message: 'Status refresh failed' } } })
      }
      return Promise.reject(new Error(`Unexpected URL: ${url}`))
    })

    const wrapper = mountPage()
    await flushPromises()

    const statusButton = wrapper.findAll('button').find((button) => button.text() === 'Check status')
    await statusButton.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Status refresh failed')
    expect(wrapper.text()).toContain('#12')
    expect(wrapper.text()).toContain('processing')
    wrapper.unmount()
  })

  it('restarts polling when a retry loads a pending export', async () => {
    vi.useFakeTimers()
    let historyAttempts = 0

    axios.get.mockImplementation((url) => {
      if (url === '/api/watchlist') return Promise.resolve({ data: [] })
      if (url === '/api/watchlist/eod-exports') {
        historyAttempts += 1
        if (historyAttempts === 1) {
          return Promise.reject({ response: { data: { message: 'Temporary history failure' } } })
        }

        return Promise.resolve({
          data: {
            items: [{
              id: 12,
              status: 'processing',
              symbol_count: 145,
              indicator_count: 10,
              created_at: '2026-07-17T06:24:18Z',
            }],
          },
        })
      }
      if (url === '/api/watchlist/eod-export/12') {
        return Promise.resolve({
          data: {
            item: {
              id: 12,
              status: 'completed',
              symbol_count: 145,
              indicator_count: 10,
              created_at: '2026-07-17T06:24:18Z',
              completed_at: '2026-07-17T06:28:47Z',
              download_url: '/api/watchlist/eod-export/12/download',
            },
          },
        })
      }
      return Promise.reject(new Error(`Unexpected URL: ${url}`))
    })

    const wrapper = mountPage()
    await flushPromises()

    const retryButton = wrapper.findAll('button').find((button) => button.text() === 'Try again')
    await retryButton.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('processing')

    await vi.advanceTimersByTimeAsync(3000)
    await flushPromises()

    expect(axios.get).toHaveBeenCalledWith('/api/watchlist/eod-export/12')
    expect(wrapper.text()).toContain('completed')
    expect(wrapper.text()).toContain('Download')
    wrapper.unmount()
  })
})
