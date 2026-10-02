import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WallInteractionPanel from '@/Components/WallInteractionPanel.vue'

const start = Date.parse('2026-09-30T14:00:00Z')
const event = { id: 'break', side: 'put', strike: 100, status: 'break', close: 99.7, tolerance: .05,
  observed_at: '2026-09-30T14:10:00Z', episode_started_at: '2026-09-30T14:00:00Z', wall_observed_at: '2026-09-30T14:05:00Z',
  reason: 'One completed close crossed the wall; acceptance needs two consecutive closes.', evidence_bar_ids: ['bar-1'] }
const data = () => ({ state: 'ready', rule_version: 'completed-5m.v1', as_of: '2026-09-30T14:15:00Z',
  current: { put: { ...event, status: 'acceptance_below' }, call: { status: 'unknown', strike: 110 } },
  events: [event], readings: [event], bars: [0, 1, 2].map(i => ({ id: `bar-${i}`, t: start + i * 300000,
    o: 100.5, h: 100.6, l: 99.6, c: i ? 99.7 : 100.5 })) })

describe('Price behavior at the walls', () => {
  it('shows plain labels and opens evidence only when an event is selected', async () => {
    const wrapper = mount(WallInteractionPanel, { props: { interaction: data() } })
    expect(wrapper.text()).toContain('Accepted below')
    expect(wrapper.text()).toContain('Watching the level')
    expect(wrapper.find('table').exists()).toBe(false)
    await wrapper.get('select').setValue('break')
    expect(wrapper.emitted('inspect')[0][0]).toEqual(event)
    await wrapper.setProps({ selectedEventId: 'break' })
    expect(wrapper.text()).toContain('Inspecting 10:10 AM ET')
    expect(wrapper.findAll('tbody tr')).toHaveLength(2) // Never show the later bar as evidence.
    expect(wrapper.get('.event-bar').text()).toContain('Below band')
    expect(wrapper.get('.guide').attributes('open')).toBeUndefined()
  })

  it('keeps waiting and delayed-update states readable without inventing an interaction', () => {
    const payload = { ...data(), state: 'waiting_for_bars', events: [], bars: [], current: {} }
    const wrapper = mount(WallInteractionPanel, { props: { interaction: payload } })
    expect(wrapper.text()).toContain('Price reactions appear as completed five-minute bars are recorded')
    expect(wrapper.text()).not.toMatch(/incomplete|unverified|snapshot|missing inputs/i)
    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.findAll('.behavior-status').every(node => node.text() === 'Watching the level')).toBe(true)
  })

  it('does not carry the inspected event into another symbol or session payload', async () => {
    const wrapper = mount(WallInteractionPanel, { props: { interaction: data(), selectedEventId: 'break' } })
    await wrapper.setProps({ interaction: { ...data(), events: [], current: { put: { strike: 350, status: 'touch' } } } })
    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.text()).toContain('Touched the level')
    expect(wrapper.text()).not.toContain('Inspecting')
  })

  it('offers a keyboard selector for every event and explains the limits of confirmed labels', () => {
    const payload = data()
    payload.events = Array.from({ length: 15 }, (_, i) => ({ ...event, id: `event-${i}` }))
    const wrapper = mount(WallInteractionPanel, { props: { interaction: payload } })
    expect(wrapper.findAll('option')).toHaveLength(16)
    expect(wrapper.findAll('.event-strip button')).toHaveLength(6)
    expect(wrapper.get('.guide').text()).toContain('a wick alone is not enough')
    expect(wrapper.get('.guide').text()).toContain('They have not established a trading edge')
    expect(wrapper.get('.footnote').text()).toContain('Export timeline JSON')
  })
})
