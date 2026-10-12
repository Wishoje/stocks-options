import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WallSetupPanel from '@/Components/WallSetupPanel.vue'

const at = '2026-09-30T14:30:00Z'
const setup = (extra = {}) => ({ side: 'put', strike: 100, pattern: 'continuation', direction: 'bearish',
  status: 'conditions_met', status_at_observation: 'conditions_met', observed_at: at, evidence_grade: 'strong',
  reason: 'The price sequence, entry zone and target room passed at this recorded close.',
  entry_zone: { low: 99.85, high: 99.95 }, invalidation: { price: 100.05, close_side: 'above' },
  target: { strike: 99, side: 'put', observed_at: '2026-09-30T14:25:00Z' }, target_room_ratio: 4.25,
  confirmed_at: at, expires_at: '2026-09-30T14:45:00Z',
  evidence_checks: [{ key: 'confirmation', label: 'Ordered price confirmation', passed: true }], ...extra })
const interaction = () => ({ state: 'session_review', events: [{ id: 'event', observed_at: at }],
  setups: { symbol: 'SPY', session: '2026-09-30', timeframe: '14d', as_of: at,
    current: { put: setup({ status: 'expired', reason: 'This session has ended.' }), call: { side: 'call', strike: 110, status: 'watch' } },
    readings: [setup(), setup({ side: 'call', strike: 110, pattern: null, direction: null, status: 'watch' })] } })

describe('Conditional wall setups', () => {
  it('keeps expired assessments distinct from recorded eligibility and explains grades', () => {
    const w = mount(WallSetupPanel, { props: { interaction: interaction() } })
    expect(w.text()).toContain('Expired')
    expect(w.text()).toContain('not currently active')
    expect(w.text()).toContain('Based on delayed five-minute closes')
    expect(w.get('[aria-label="Put wall setup"]').text()).toContain('99.85–99.95')
    expect(w.text()).toContain('100.05')
    expect(w.text()).toContain('4.25×')
    expect(w.text()).toContain('strong evidence')
    expect(w.get('.setup-guide').attributes('open')).toBeUndefined()
    expect(w.text()).toContain('not established a trading edge or a win probability')
    expect(w.find('.ready').exists()).toBe(false)
  })
  it('inspects recorded assessments and returns to latest without a network request', async () => {
    const w = mount(WallSetupPanel, { props: { interaction: interaction() } })
    await w.get('select').setValue(at)
    expect(w.text()).toContain('Inspecting · 10:30 AM ET')
    expect(w.get('.ready').text()).toContain('Conditions met · 10:30 AM ET')
    expect(w.text()).not.toContain('not currently active')
    await w.get('select').setValue('')
    expect(w.text()).toContain('Expired')
  })
  it('follows a selected price event and clears inspection on a scope change', async () => {
    const payload = interaction()
    const w = mount(WallSetupPanel, { props: { interaction: payload } })
    await w.setProps({ selectedEventId: 'event' })
    expect(w.get('select').element.value).toBe(at)
    expect(w.find('.ready').exists()).toBe(true)
    await w.setProps({ interaction: { ...payload, setups: { ...payload.setups, symbol: 'QQQ', timeframe: '7d', readings: [], current: { put: { side: 'put', strike: 350, status: 'watch' } } } } })
    expect(w.text()).toContain('QQQ')
    expect(w.text()).toContain('Put wall 350')
    expect(w.find('select').exists()).toBe(false)
    expect(w.text()).not.toContain('99.85')
  })
  it('does not invent a target or offer current entry for blocked or missed setups', () => {
    const payload = interaction()
    payload.setups.current.put = setup({ status: 'no_setup', target: null, target_room_ratio: null, reason: 'No recorded target supports this direction.' })
    payload.setups.current.call = setup({ side: 'call', status: 'entry_missed' })
    const w = mount(WallSetupPanel, { props: { interaction: payload } })
    expect(w.text()).toContain('No setup')
    expect(w.text()).toContain('Entry missed')
    expect(w.text()).toContain('Requires a recorded level')
    expect(w.find('.ready').exists()).toBe(false)
  })
  it('renders a simple waiting state before price observations and marks synthetic examples', () => {
    const payload = { state: 'demonstration', setups: { symbol: 'SPY', session: '2026-09-30', readings: [], current: {} } }
    const w = mount(WallSetupPanel, { props: { interaction: payload } })
    expect(w.text()).toContain('Local synthetic example')
    expect(w.findAll('.setup-card')).toHaveLength(2)
    expect(w.find('.setup-levels').exists()).toBe(false)
    expect(w.text()).not.toMatch(/incomplete|unverified|missing inputs/i)
  })
})
