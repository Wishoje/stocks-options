import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { filterWallRows, wallInboxRows, wallLifecycleRows } from '@/Support/wallLifecycle'
import WallStatusFilters from '@/Components/WallStatusFilters.vue'

const now = Date.parse('2026-10-26T15:16:00Z')
const row = (id = 'a', status_group = 'in_progress') => ({ id, alert_id: id.repeat(64), symbol: 'SPY', side: 'put', strike: 100,
  session: '2026-10-26', observed_at: '2026-10-26T14:30:00Z', evaluated_through: '2026-10-26T15:00:00Z', status_group,
  setup: { direction: 'bullish' }, qualified: true })
const data = rows => ({ schema_version: 'wall-watchlist.v1', session: '2026-10-26', timeframe: '7d', state: 'ready',
  quote_delay_seconds: 900, refresh_until: '2026-10-26T20:00:00Z', items: [], lifecycle_items: rows })

describe('shared wall lifecycle presentation', () => {
  it('matches original alert identities and never substitutes a later setup on the same symbol and side', () => {
    const saved = [{ ...row('a'), status: 'conditions_met' }]
    expect(wallInboxRows(saved, data([row('a')]), now)[0].status_group).toBe('in_progress')
    expect(wallInboxRows(saved, data([row('b', 'conditions_met')]), now)[0].status_group).toBe('needs_update')
    expect(saved[0]).not.toHaveProperty('lifecycle')
  })
  it('retains proven results while stale or failed unfinished states need an update', () => {
    const rows = wallLifecycleRows(data([row('a'), row('b', 'finished')]), now + 1200000)
    expect(rows.map(r => r.status_group)).toEqual(['needs_update', 'finished'])
    expect(wallLifecycleRows(data([row('a')]), now, true)[0].status_group).toBe('needs_update')
    expect(wallLifecycleRows({ ...data([row('a')]), session: '2026-10-23' }, now)).toEqual([])
  })
  it('does not leave an elapsed entry window in conditions met between polls', () => {
    const r = { ...row('a', 'conditions_met'), entry_expires_at: '2026-10-26T14:59:00Z' }
    expect(wallLifecycleRows(data([r]), now)[0].status_group).toBe('needs_update')
  })
  it('keeps historical local demonstrations independent of the live clock', () => {
    const r = { ...row('a', 'conditions_met'), entry_expires_at: '2026-10-26T14:59:00Z' }
    expect(wallLifecycleRows({ ...data([r]), state: 'demonstration' }, now + 86400000)[0].status_group).toBe('conditions_met')
  })
  it('filters status symbol direction and unread independently', () => {
    const rows = [row('a', 'conditions_met'), { ...row('b', 'finished'), symbol: 'QQQ', read_at: now }]
    expect(filterWallRows(rows, { status: 'conditions_met', symbol: 'spy', direction: 'bullish', unread: true })).toHaveLength(1)
    expect(filterWallRows(rows, { status: 'finished', unread: true })).toEqual([])
    expect(filterWallRows(rows, { symbol: 'qq' })).toHaveLength(1)
  })
  it('keeps a selected zero-count filter visible and selected as rows change', async () => {
    const wrapper = mount(WallStatusFilters, { props: { rows: [row('a', 'conditions_met')], modelValue: 'conditions_met' } })
    await wrapper.setProps({ rows: [] })
    expect(wrapper.get('button[aria-pressed="true"]').text()).toBe('Conditions met · 0')
    expect(wrapper.findAll('button')).toHaveLength(6)
    wrapper.unmount()
  })
})
