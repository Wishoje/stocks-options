import { flushPromises, mount } from '@vue/test-utils'
import axios from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import DexTile from '@/Components/DexTile.vue'
import { compact } from '@/Components/UI/numbers.js'

vi.mock('axios', () => ({ default: { get: vi.fn() } }))

function dateAt(index) {
  const date = new Date(Date.UTC(2026, 7, 22 + index))
  return date.toISOString().slice(0, 10)
}

function dexPayload() {
  return {
    symbol: 'SPY',
    data_date: '2026-09-09',
    today: '2026-09-10',
    total: '123456789.123456',
    regime_strength: '0.71',
    gamma_sign: 1,
    regime_source_meta: { source: 'computed', version: 4 },
    window: { start: '2026-08-11', end: '2026-12-09' },
    by_expiry: Array.from({ length: 40 }, (_, index) => ({
      exp_date: dateAt(index),
      dex_total: index === 2 ? null : index === 3 ? 0 : index % 2 ? `-${index}.125000` : `${index}.500000`,
      source_chain_date: index === 2 ? null : '2026-09-09',
    })),
  }
}

describe('Positioning DEX parity', () => {
  beforeEach(() => {
    axios.get.mockReset()
    axios.get.mockImplementation(url => Promise.resolve(url === '/api/dex'
      ? { data: dexPayload() }
      : { data: { regime_strength: '0.81234567', gamma_sign: -1 } }))
  })

  it('retains all expiry rows and provenance while presenting rounded values in a collapsed table', async () => {
    const source = dexPayload()
    axios.get.mockImplementation(url => Promise.resolve(url === '/api/dex'
      ? { data: source }
      : { data: { regime_strength: '0.81234567', gamma_sign: -1 } }))

    const wrapper = mount(DexTile, { props: { symbol: 'SPY' } })
    await flushPromises()

    expect(wrapper.vm.dexPayload).toEqual(source)
    expect(wrapper.vm.byExpiry).toEqual(source.by_expiry)
    expect(wrapper.vm.calendarToday).toBe('2026-09-10')
    expect(wrapper.vm.windowScope).toEqual(source.window)
    expect(wrapper.vm.regimeSourceMeta).toEqual(source.regime_source_meta)
    expect(wrapper.vm.strength).toBe(source.regime_strength)
    expect(wrapper.vm.gammaSign).toBe(source.gamma_sign)
    expect(wrapper.findAll('.gex-exposure-row')).toHaveLength(40)
    expect(wrapper.findAll('[data-testid="dex-expiry-table"] tbody tr')).toHaveLength(40)
    expect(wrapper.get('[data-testid="dex-expiry-disclosure"]').attributes('open')).toBeUndefined()
    expect(wrapper.text()).toContain('40 expiry readings')
    expect(wrapper.text()).toContain('19 expired')
    expect(wrapper.text()).toContain('1 today')

    const rows = wrapper.findAll('.gex-exposure-row')
    expect(rows[2].text()).toContain('Unavailable')
    expect(rows[3].find('.gex-zero').exists()).toBe(true)
    expect(rows[0].attributes('aria-label')).toContain('share equivalents')
    expect(wrapper.emitted('reading-inspected')).toBeUndefined()

    await rows.at(-1).trigger('click')
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)
    const detail = wrapper.get('[data-testid="selected-dex-detail"]')
    expect(detail.text()).toContain(source.by_expiry.at(-1).exp_date)
    expect(detail.text()).toContain(compact(source.by_expiry.at(-1).dex_total))
    expect(detail.text()).not.toContain(source.by_expiry.at(-1).dex_total)
    expect(detail.text()).toContain('2026-09-09')
    expect(axios.get).toHaveBeenCalledTimes(1)
    expect(axios.get).not.toHaveBeenCalledWith('/api/gex-levels', expect.anything())
    wrapper.unmount()
  })
})
