import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, provide, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import IntradayResearch from '@/Pages/IntradayResearch.vue'
import ExperimentalIntradayAlertDetails from '@/Components/ExperimentalIntradayAlertDetails.vue'
import WallAlertCenter from '@/Components/WallAlertCenter.vue'
import { WALL_ALERTS } from '@/Support/wallAlerts'
import { SWING_RESEARCH_ALERTS } from '@/Support/swingResearch'
import { defaultIntradayFilters, INTRADAY_RESEARCH_ALERTS, intradayCaseUrl, intradayVariants } from '@/Support/intradayResearch'

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['researchDemo'], template: '<div :data-demo="researchDemo"><slot /></div>' } }))
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, router: { visit: vi.fn() } }))
const wrappers = []
const state = overrides => ({ variant: 'post_publication_retest', status: 'in_progress', direction: 'bullish', reason: 'A later complete bar closed in the frozen entry area.', entry_low: 100, entry_high: 102, target: 110, invalidation: 98, original_signal_at: '2026-10-30T14:00:00Z', original_available_at: '2026-10-30T14:15:00Z', created_at: '2026-10-30T14:16:00Z', entry_not_before: '2026-10-30T14:16:00Z', entry_deadline: '2026-10-30T14:31:00Z', signal_at: '2026-10-30T14:25:00Z', signal_price: 101, review_until: '2026-10-30T20:00:00Z', outcome: null, metrics: { qualified: true, followup_complete: false, needs_update: false, mfe_pct: 1.5, mae_pct: 0.5, outcome_move_pct: null }, ...overrides })
const report = overrides => ({ prepared: true, enabled: true, demonstration: false, warnings: [], collection: {}, variants: { baseline: { label: 'Recorded-close control', description: 'Original recorded conditions.' }, post_publication_retest: { label: 'Post-publication retest', description: 'Later complete bar retest.' } }, summary: intradayVariants.map((variant, index) => ({ variant, total: 3, qualified: index + 1, waiting: 1, in_progress: index, completed: 1, needs_review: 0, target_reached: 1, invalidated: 0, ended: 0, target_rate: { hits: 1, eligible: 1, rate: 100 }, avg_mfe_pct: 1.5, avg_mae_pct: 0.5, avg_outcome_move_pct: index ? -0.25 : 1.25 })), groups: [{ id: 'SPY-2026-10-30', symbol: 'SPY', session: '2026-10-30', source_signal_at: '2026-10-30T14:00:00Z', source_available_at: '2026-10-30T14:15:00Z', created_at: '2026-10-30T14:16:00Z', cases: intradayVariants.map((variant, index) => ({ id: index + 1, variant, status: 'in_progress', state: state({ variant }), notification_recorded_at: index ? '2026-10-30T14:40:00Z' : null })) }], ...overrides })
function start(props = {}) { const wrapper = mount(IntradayResearch, { props }); wrappers.push(wrapper); return wrapper }
beforeEach(() => { window.history.replaceState({}, '', '/intraday-research'); axios.get.mockReset(); axios.post.mockReset(); axios.get.mockResolvedValue({ data: report() }); axios.post.mockResolvedValue({ data: { prepared: true, warnings: [] } }) })
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); window.history.replaceState({}, '', '/') })

describe('private intraday experiments', () => {
  it('compares two profiles with independent counts, fixed levels, delayed causal timestamps and study-only outcomes', async () => {
    const payload = report(); payload.summary[0].not_qualified = 0; payload.summary[1].not_qualified = 1
    axios.get.mockResolvedValueOnce({ data: payload })
    const wrapper = start(); await flushPromises()
    expect(wrapper.findAll('.profile')).toHaveLength(2); expect(wrapper.findAll('.paper-case')).toHaveLength(2)
    const profiles = wrapper.findAll('.profile')
    expect(profiles[0].text()).toContain('CONTROL · STUDY ONLY · NO ALERTS'); expect(profiles[0].text()).toContain('Conditions met1')
    expect(profiles[1].text()).toContain('Conditions met2'); expect(profiles[1].text()).toContain('Move at recorded outcome-0.25%')
    expect(profiles[0].text()).toContain('No entry0'); expect(profiles[1].text()).toContain('No entry1')
    expect(wrapper.text()).toContain('1 of 1 completed cases'); expect(wrapper.text()).toContain('Observations are delayed 15 minutes')
    expect(wrapper.text()).toContain('100–102'); expect(wrapper.text()).toContain('Source close Oct 30, 10:00 AM ET')
    expect(wrapper.text()).toContain('Source available Oct 30, 10:15 AM ET'); expect(wrapper.text()).toContain('Private watch recordedOct 30, 10:16 AM ET')
    expect(wrapper.findAll('.paper-case')[0].text()).not.toContain('Eligible bar starts')
    expect(wrapper.text()).toContain('Eligible bar starts at or afterOct 30, 10:16 AM ET'); expect(wrapper.text()).toContain('Entry window endsOct 30, 10:31 AM ET')
    expect(wrapper.text()).toContain('Recorded conditions metOct 30, 10:25 AM ET'); expect(wrapper.text()).toContain('Condition event recorded / receivedOct 30, 10:40 AM ET')
    expect(wrapper.text()).toContain('whole five-minute bar'); expect(wrapper.text()).toContain('Historical qualifying bars and cases that have already ended do not become new alerts')
    expect(axios.post).not.toHaveBeenCalled()
  })

  it('filters uppercase symbols and dates without introducing Swing horizons and rejects invalid ranges', async () => {
    const wrapper = start(); await flushPromises()
    expect(wrapper.find('select').exists()).toBe(false)
    await wrapper.get('input[placeholder="All symbols"]').setValue(' qqq '); await wrapper.get('form').trigger('submit'); await flushPromises()
    expect(axios.get.mock.calls.at(-1)[1].params).toMatchObject({ symbol: 'QQQ' }); expect(axios.get.mock.calls.at(-1)[1].params.horizon).toBeUndefined()
    const dates = wrapper.findAll('input[type="date"]'); await dates[0].setValue('2026-10-31'); await dates[1].setValue('2026-10-01')
    const count = axios.get.mock.calls.length; await wrapper.get('form').trigger('submit')
    expect(axios.get).toHaveBeenCalledTimes(count); expect(wrapper.text()).toContain('From date must be on or before To date.')
  })

  it('shows missing denominators, paused collection, review states and owner-access failures accurately', async () => {
    const payload = report({ enabled: false, summary: intradayVariants.map(variant => ({ variant, total: 0, target_rate: { hits: 0, eligible: 0, rate: null } })) })
    payload.groups[0].cases[1].state.metrics.needs_update = true
    axios.get.mockResolvedValueOnce({ data: payload })
    const wrapper = start(); await flushPromises()
    expect(wrapper.get('.target-rate strong').text()).toBe('—'); expect(wrapper.text()).toContain('No eligible completed cases')
    expect(wrapper.text()).toContain('Needs review'); expect(wrapper.text()).toContain('Collection is paused')
    expect(wrapper.findAll('.actions button')[1].attributes('disabled')).toBeDefined()
    axios.get.mockRejectedValueOnce({ response: { status: 403 } }); await wrapper.get('.actions button').trigger('click'); await flushPromises()
    expect(wrapper.text()).toContain('available to the owner account only')
  })

  it('refreshes saved records manually and never posts from synthetic mode', async () => {
    const wrapper = start(); await flushPromises(); await wrapper.findAll('.actions button')[1].trigger('click'); await flushPromises()
    expect(axios.post.mock.calls[0][0]).toBe('/api/intraday-research/refresh'); expect(wrapper.text()).toContain('Saved research records refreshed')
    wrapper.unmount(); window.history.replaceState({}, '', '/intraday-research?demo=1&symbol=SPY')
    axios.get.mockResolvedValue({ data: report({ demonstration: true }) }); axios.post.mockClear()
    const demo = start({ localDemoAvailable: true }); await flushPromises()
    expect(demo.attributes('data-demo')).toBe('true'); expect(axios.get.mock.calls.at(-1)[1].params).toMatchObject({ demo: 1, symbol: 'SPY' })
    await demo.findAll('.actions button')[1].trigger('click'); expect(axios.post).not.toHaveBeenCalled()
  })

  it('rejects unsolicited synthetic payloads', async () => {
    axios.get.mockResolvedValueOnce({ data: report({ demonstration: true }) })
    const wrapper = start(); await flushPromises(); expect(wrapper.text()).toContain('could not load'); expect(wrapper.find('.profile').exists()).toBe(false)
  })

  it('labels a condition event with both its recorded close and delayed receipt and links to that session', () => {
    const item = { id: 2, symbol: 'SPY', session: '2026-10-30', variant: 'post_publication_retest', signal_at: '2026-10-30T14:25:00Z', signal_recorded_at: '2026-10-30T14:40:00Z', qualified_price: 101, entry_low: 100, entry_high: 102, target: 110, invalidation: 98 }
    const wrapper = mount(ExperimentalIntradayAlertDetails, { props: { item } }); wrappers.push(wrapper)
    expect(wrapper.text()).toContain('Recorded conditions met Oct 30, 10:25 AM ET'); expect(wrapper.text()).toContain('Recorded / received Oct 30, 10:40 AM ET')
    expect(wrapper.text()).toContain('delayed 15 minutes'); expect(wrapper.text()).toContain('does not guarantee an entry at the current price')
    expect(wrapper.get('a').attributes('href')).toBe('/intraday-research?symbol=SPY&from=2026-10-30&to=2026-10-30#intraday-case-2')
    expect(intradayCaseUrl({ ...item, session: undefined })).toBe(wrapper.get('a').attributes('href'))
  })

  it('includes intraday unread events in the shared badge while keeping both research histories separate', async () => {
    const source = count => ({ owner: ref(19), history: ref([]), notifications: ref([]), unreadCount: ref(count), loading: ref(false), error: ref(''), truncated: ref(false), markRead: vi.fn(), dismiss: vi.fn() })
    const swing = source(1), intraday = source(2)
    const wall = { ...source(0), settings: ref({ enabled: true }), activity: ref(null), clock: ref(Date.now()), inboxPersistent: ref(true), preview: vi.fn() }
    const Host = defineComponent({ components: { WallAlertCenter }, setup() { provide(WALL_ALERTS, wall); provide(SWING_RESEARCH_ALERTS, swing); provide(INTRADAY_RESEARCH_ALERTS, intraday) }, template: '<WallAlertCenter />' })
    const wrapper = mount(Host, { attachTo: document.body }); wrappers.push(wrapper)
    expect(wrapper.get('.experimental-badge').text()).toBe('E 3'); expect(wrapper.get('.wall-inbox-trigger').attributes('aria-label')).toContain('3 unread experimental alerts')
    await wrapper.get('.wall-inbox-trigger').trigger('click')
    expect(document.querySelector('#wall-alert-inbox').textContent).toContain('Experimental swing alerts')
    expect(document.querySelector('#wall-alert-inbox').textContent).toContain('Experimental intraday alerts')
    expect(document.querySelector('#wall-alert-inbox').textContent).toContain('Last 14 days')
    intraday.owner.value = null; await flushPromises()
    expect(wrapper.get('.experimental-badge').text()).toBe('E 1'); expect(document.querySelector('#experimental-intraday-alerts-title')).toBeNull()
  })
})

it('defaults to 14 inclusive Eastern calendar dates', () => {
  expect(defaultIntradayFilters(new Date('2026-10-31T01:00:00Z'))).toEqual({ from: '2026-10-17', to: '2026-10-30', symbol: '' })
})
