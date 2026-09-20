import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import UnusualActivityTable from '@/Components/UnusualActivityTable.vue'

function signal(overrides = {}) {
  return {
    exp_date: '2026-09-18',
    strike: 450,
    z_score: 3.25,
    vol_oi: 0,
    extra_top_level: 'preserved',
    meta: {
      call_vol: 0,
      put_vol: 0,
      total_vol: 0,
      premium_usd: 0,
      call_prem: 0,
      put_prem: 0,
      mu: 0,
      sigma: 0,
      history_samples: 0,
      confidence: 'low',
      baseline_excludes_today: false,
      extra_diagnostic: 'kept',
    },
    ...overrides,
  }
}

afterEach(() => document.body.replaceChildren())

describe('UnusualActivityTable', () => {
  it('keeps API order, every returned field, compact primary readings, and collapsed detail', () => {
    const rows = Object.freeze([
      Object.freeze(signal()),
      Object.freeze(signal({
        exp_date: '2026-10-16',
        strike: 500,
        z_score: '5.125',
        vol_oi: '2.5',
        meta: Object.freeze({
          call_vol: 1000,
          put_vol: 250,
          total_vol: 1250,
          premium_usd: 1250000.75,
          call_prem: 1000000.5,
          put_prem: 250000.25,
          mu: 120.25,
          sigma: 14.5,
          history_samples: 29,
          confidence: 'normal',
          baseline_excludes_today: true,
          extra_diagnostic: 'second-row-value',
        }),
      })),
    ])
    const before = JSON.stringify(rows)

    const wrapper = mount(UnusualActivityTable, {
      props: { rows, dataDate: '2026-09-11', symbol: 'spy', requestSort: 'premium' },
    })

    expect(wrapper.vm.records.map(record => record.raw)).toEqual(rows)
    expect(wrapper.vm.sortedRecords.map(record => record.raw.strike)).toEqual([450, 500])
    expect(wrapper.vm.sortKey).toBe('api_order')
    expect(wrapper.text()).toContain('API rank: premium')
    expect(wrapper.text()).toContain('Highest Z-score+5.13 sigma')
    expect(wrapper.text()).toContain('Highest Vol/OI2.50x')
    expect(wrapper.text()).toContain('Premium represented$1.3M')
    expect(wrapper.get('[data-testid="ua-selected-signal"]').text()).toContain('2026-09-18')
    expect(wrapper.get('[data-testid="ua-results-disclosure"]').attributes('open')).toBeUndefined()
    expect(wrapper.get('[data-testid="ua-selected-fields"]').attributes('open')).toBeUndefined()
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)

    const topFields = Object.fromEntries(wrapper.vm.selectedTopLevelFields.map(field => [field.key, field.value]))
    const metaFields = Object.fromEntries(wrapper.vm.selectedMetaFields.map(field => [field.key, field.value]))
    expect(topFields).toMatchObject({
      exp_date: '2026-09-18',
      strike: '450',
      z_score: '+3.25 sigma',
      vol_oi: '0.00x',
      extra_top_level: 'preserved',
      meta: '12 returned fields',
    })
    expect(metaFields).toMatchObject({
      call_vol: '0',
      premium_usd: '$0.00',
      history_samples: '0',
      baseline_excludes_today: 'No',
      extra_diagnostic: 'kept',
    })
    expect(JSON.stringify(rows)).toBe(before)
  })

  it('sorts only after an explicit column choice, keeps missing last, and resets for a new API result', async () => {
    const missing = signal({ exp_date: '2026-09-20', strike: 300, z_score: null, vol_oi: null, meta: {} })
    const low = signal({ exp_date: '2026-09-18', strike: 100, z_score: 2, meta: { total_vol: 50, premium_usd: 100 } })
    const high = signal({ exp_date: '2026-09-19', strike: 200, z_score: 5, meta: { total_vol: 25, premium_usd: 500 } })
    const rows = [missing, low, high]
    const wrapper = mount(UnusualActivityTable, { props: { rows, symbol: 'SPY', requestSort: 'premium' } })

    expect(wrapper.vm.sortedRecords.map(record => record.raw.strike)).toEqual([300, 100, 200])

    const zButton = wrapper.findAll('thead button').find(button => button.text().startsWith('Z-score'))
    await zButton.trigger('click')
    expect(wrapper.vm.sortKey).toBe('z_score')
    expect(wrapper.vm.sortDirection).toBe('descending')
    expect(wrapper.vm.sortedRecords.map(record => record.raw.strike)).toEqual([200, 100, 300])
    expect(zButton.element.closest('th').getAttribute('aria-sort')).toBe('descending')

    await zButton.trigger('click')
    expect(wrapper.vm.sortedRecords.map(record => record.raw.strike)).toEqual([100, 200, 300])
    expect(zButton.element.closest('th').getAttribute('aria-sort')).toBe('ascending')

    await wrapper.setProps({ rows: [high, missing, low], requestSort: 'vol_oi' })
    expect(wrapper.vm.sortKey).toBe('api_order')
    expect(wrapper.vm.sortedRecords.map(record => record.raw.strike)).toEqual([200, 300, 100])
    expect(wrapper.text()).toContain('API rank: Vol/OI')

    await wrapper.get('.ua-mobile-sort select').setValue('premium_usd')
    expect(wrapper.vm.sortDirection).toBe('descending')
    expect(wrapper.vm.sortedRecords.map(record => record.raw.strike)).toEqual([200, 100, 300])
    await wrapper.get('.ua-mobile-sort .gex-button').trigger('click')
    expect(wrapper.vm.sortedRecords.map(record => record.raw.strike)).toEqual([100, 200, 300])
  })

  it('distinguishes numeric zero from missing volume, premium, z-score, and Vol/OI', async () => {
    const zero = signal({ z_score: 0 })
    const missing = signal({
      exp_date: '2026-09-19',
      strike: 451,
      z_score: null,
      vol_oi: null,
      meta: {
        call_vol: null,
        put_vol: null,
        total_vol: null,
        premium_usd: null,
      },
    })
    const wrapper = mount(UnusualActivityTable, { props: { rows: [zero, missing], symbol: 'IWM' } })

    const selected = wrapper.get('[data-testid="ua-selected-signal"]')
    expect(selected.text()).toContain('0.00 sigma')
    expect(selected.text()).toContain('0.00x')
    expect(selected.text()).toContain('Balanced')
    expect(selected.text()).toContain('$0')
    expect(wrapper.text()).toContain('1 of 2 returned rows include premium')
    expect(wrapper.vm.highestZ).toBe(0)
    expect(wrapper.vm.highestVolOi).toBe(0)
    expect(wrapper.vm.representedPremium).toBe(0)

    await wrapper.findAll('.ua-select')[1].trigger('click')
    expect(wrapper.get('[data-testid="ua-selected-signal"]').text()).toContain('Side unavailable')
    expect(wrapper.get('[data-testid="ua-selected-signal"]').text()).toContain('Call and put volume are both required')
    expect(wrapper.vm.selectedMetaFields.find(field => field.key === 'total_vol').value).toBe('Unavailable')
    expect(wrapper.vm.selectedMetaFields.find(field => field.key === 'premium_usd').value).toBe('Unavailable')
  })

  it('selects any returned row for complete inspection without changing the response objects', async () => {
    const rows = [
      signal(),
      signal({
        exp_date: '2026-12-18',
        strike: 600,
        z_score: 4.75,
        meta: JSON.stringify({ call_vol: 10, put_vol: 20, custom_nested_field: 'visible' }),
      }),
    ]
    const before = JSON.stringify(rows)
    const wrapper = mount(UnusualActivityTable, { props: { rows, symbol: 'QQQ' } })

    expect(wrapper.emitted('reading-inspected')).toBeUndefined()
    await wrapper.findAll('.ua-select')[1].trigger('click')
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)
    expect(wrapper.get('[data-testid="ua-selected-signal"]').text()).toContain('2026-12-18')
    expect(wrapper.get('[data-testid="ua-selected-signal"]').text()).toContain('Put-led')
    expect(wrapper.findAll('.ua-select')[1].attributes('aria-label')).toContain('expiry 2026-12-18, strike 600')
    expect(wrapper.vm.selectedMetaFields).toContainEqual(expect.objectContaining({
      key: 'custom_nested_field',
      value: 'visible',
    }))
    expect(JSON.stringify(rows)).toBe(before)
  })

  it('shows a clear empty state with and without a completed snapshot date', async () => {
    const wrapper = mount(UnusualActivityTable, {
      props: { rows: [], symbol: 'AAPL', dataDate: '2026-09-11' },
    })
    expect(wrapper.get('.gex-status').text()).toContain('2026-09-11 snapshot returned no contracts')
    expect(wrapper.find('[data-testid="ua-selected-signal"]').exists()).toBe(false)

    await wrapper.setProps({ dataDate: null })
    expect(wrapper.get('.gex-status').text()).toContain('No completed unusual activity snapshot')
  })

  it('opens a contained reading guide and restores focus when Escape closes it', async () => {
    const wrapper = mount(UnusualActivityTable, {
      attachTo: document.body,
      props: { rows: [signal()], symbol: 'SPY' },
    })
    const guideButton = wrapper.findAll('button').find(button => button.text() === 'Reading guide')

    await guideButton.trigger('click')
    await flushPromises()
    const dialog = document.body.querySelector('[role="dialog"]')
    expect(dialog?.textContent).toContain('winsorized 30-day baseline')
    expect(document.activeElement?.getAttribute('aria-label')).toContain('Close')

    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(document.body.querySelector('[role="dialog"]')).toBeNull()
    expect(document.activeElement).toBe(guideButton.element)
    wrapper.unmount()
  })
})
