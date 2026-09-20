import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import FirstUseGuide from '@/Components/FirstUseGuide.vue'
import { loginValidationErrors } from '@/Support/auth-validation.js'
import {
  hasUsableDashboardReading,
  recordFirstUsefulReading,
} from '@/Support/first-use.js'

vi.mock('axios', () => ({
  default: {
    post: vi.fn(),
  },
}))

describe('authentication validation', () => {
  it('requires a password at login without imposing registration length rules', () => {
    expect(loginValidationErrors({ email: 'trader@example.com', password: 'short' })).toEqual({
      email: '',
      password: '',
    })
    expect(loginValidationErrors({ email: 'trader@example.com', password: '' }).password).toBe('Password is required.')
  })
})

describe('first-use guide', () => {
  beforeEach(() => {
    window.gtag = vi.fn()
    window.localStorage.clear()
    axios.post.mockReset()
    axios.post.mockResolvedValue({ data: { recorded: true, first: true } })
  })

  it('shows the current context and leaves navigation choices to the user', async () => {
    const wrapper = mount(FirstUseGuide, {
      props: {
        symbol: 'IWM',
        mode: 'intraday',
        tabLabel: 'Flow',
        timeframe: '',
      },
    })

    expect(wrapper.text()).toContain('IWM')
    expect(wrapper.text()).toContain('Intraday')
    expect(wrapper.text()).toContain('Flow')
    expect(wrapper.text()).not.toContain('SPY')

    await wrapper.get('[data-variant="primary"]').trigger('click')
    expect(wrapper.emitted('continue')).toHaveLength(1)
    expect(wrapper.props()).toMatchObject({ symbol: 'IWM', mode: 'intraday', tabLabel: 'Flow' })
    wrapper.unmount()
  })

  it('records exactly once only when an explicit inspection reports usable ready data', () => {
    expect(hasUsableDashboardReading([{ strike: 500, net_gex: null }])).toBe(false)
    expect(hasUsableDashboardReading([{ strike: 500, net_gex: 0 }])).toBe(true)
    expect(hasUsableDashboardReading([{ strike: 500, call_vol: 42, put_vol: 17 }])).toBe(true)
    expect(hasUsableDashboardReading([{ strike: 500, net_gex_live: -1200 }])).toBe(true)

    expect(recordFirstUsefulReading({ validated: true, loading: true })).toBe(false)
    expect(recordFirstUsefulReading({ validated: true, error: 'Unavailable' })).toBe(false)
    expect(recordFirstUsefulReading({ rows: [{ net_gex: null }] })).toBe(false)
    expect(window.gtag).not.toHaveBeenCalled()

    expect(recordFirstUsefulReading({ validated: true })).toBe(true)
    expect(recordFirstUsefulReading({ validated: true })).toBe(false)
    expect(window.gtag).toHaveBeenCalledTimes(1)
    expect(window.gtag).toHaveBeenCalledWith('event', 'first_useful_reading', expect.objectContaining({
      surface: 'dashboard',
      state: 'ready',
    }))
  })

  it('submits the fixed account event only after a validated explicit inspection', async () => {
    expect(recordFirstUsefulReading({ accountId: 4101, validated: true, loading: true })).toBe(false)
    expect(recordFirstUsefulReading({ accountId: 4101, validated: true, error: 'Unavailable' })).toBe(false)
    expect(recordFirstUsefulReading({ accountId: 4101, rows: [{ net_gex: null }] })).toBe(false)
    expect(axios.post).not.toHaveBeenCalled()

    recordFirstUsefulReading({ accountId: 4101, validated: true })
    recordFirstUsefulReading({ accountId: 4101, validated: true })
    expect(axios.post).toHaveBeenCalledTimes(1)
    expect(axios.post).toHaveBeenCalledWith('/product-events/first-useful-reading', {
      interaction: 'reading_inspected',
      surface: 'dashboard',
    })

    await flushPromises()

    recordFirstUsefulReading({ accountId: 4101, validated: true })
    expect(axios.post).toHaveBeenCalledTimes(1)
  })

  it('retries an unrecorded response and confirms only an explicit recorded result', async () => {
    axios.post
      .mockResolvedValueOnce({ status: 204, data: '' })
      .mockResolvedValueOnce({ data: { recorded: false } })
      .mockResolvedValueOnce({ data: { recorded: true, first: false } })

    recordFirstUsefulReading({ accountId: 4201, validated: true })
    await flushPromises()
    recordFirstUsefulReading({ accountId: 4201, validated: true })
    await flushPromises()
    recordFirstUsefulReading({ accountId: 4201, validated: true })
    await flushPromises()
    recordFirstUsefulReading({ accountId: 4201, validated: true })

    expect(axios.post).toHaveBeenCalledTimes(3)
  })

  it('bounds failed server attempts', async () => {
    axios.post.mockRejectedValue(new Error('temporary failure'))

    for (let attempt = 0; attempt < 5; attempt += 1) {
      recordFirstUsefulReading({ accountId: 4301, validated: true })
      await flushPromises()
    }

    expect(axios.post).toHaveBeenCalledTimes(3)
  })
})
