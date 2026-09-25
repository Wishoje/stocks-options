import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import WallFoundation from '@/Pages/WallFoundation.vue'

vi.mock('axios', () => ({ default: { get: vi.fn() } }))
const wrappers = []
function report(dataset = 'production_capture') {
  return {
    dataset, source_note: 'Recorded source, not a live feed.', captured_at: '2026-09-25T07:00:00Z',
    generated_at: '2026-09-25T07:05:00Z', findings: [],
    snapshots: ['SPY', 'QQQ', 'TSLA'].map(symbol => ({
      schema_version: 'wall-foundation.v1', scope: { symbol, expiry_set: ['2026-10-02'] },
      provenance: { source_date: symbol === 'TSLA' && dataset === 'local_review' ? null : '2026-09-24' },
      analysis_session: '2026-09-24', quality: { state: 'partial', reasons: ['missing_contract_inputs'], source_inputs_complete: false },
      measurements: { raw_net_gex: -1000000, available_input_net_gex_per_1pct: -10000, put_wall: 550, call_wall: 560, legacy_hvl: 555 },
      reconciliation: { strike_count: 2, call_minus_put_matches_net: true },
      unavailable_reasons: { gamma_flip: 'A scenario curve is required.' },
    })),
    storage: { ready: true, audit_records: 3, outcome_eligible_records: 0, payload_bytes_for_selected_scope: 2048,
      planning: { symbols: 3, interval_minutes: 5, observations_per_session: 234, estimated_payload_bytes_per_session: 159744, retention_days: 180 } },
    provider_access: { future_entitlements: 'Future entitlements are not verified.' },
  }
}
function deferred() { let resolve; const promise = new Promise(r => { resolve = r }); return { promise, resolve } }
function start() {
  const wrapper = mount(WallFoundation, { props: { productionCaptureAvailable: true }, global: {
    stubs: { AppLayout: { template: '<div><slot /></div>' }, UiLoading: { template: '<div>Loading audit</div>' } },
  } })
  wrappers.push(wrapper)
  return wrapper
}
describe('Wall foundation review', () => {
  beforeEach(() => { axios.get.mockReset(); axios.get.mockResolvedValue({ data: report() }) })
  afterEach(() => wrappers.splice(0).forEach(w => w.unmount()))

  it('shows explicit units and unavailability without a setup grade', async () => {
    const wrapper = start(); await flushPromises()
    expect(wrapper.text()).toContain('Legacy net GEX · original units')
    expect(wrapper.text()).toContain('USD per 1% move')
    expect(wrapper.text()).toContain('Actionable output unavailable')
    expect(wrapper.text()).toContain('Modeled gamma flip: Unavailable')
    expect(wrapper.text()).toContain('Eligible historical outcomes0')
    expect(axios.get).toHaveBeenCalledTimes(1)
  })

  it('switches inspected symbols without additional requests', async () => {
    const wrapper = start(); await flushPromises()
    await wrapper.findAll('.symbol-card')[2].trigger('click')
    expect(wrapper.text()).toContain('TSLA · scope and readiness')
    expect(axios.get).toHaveBeenCalledTimes(1)
  })

  it('cancels an old audit when scope changes and never paints its late response', async () => {
    const old = deferred(); axios.get.mockReturnValueOnce(old.promise)
    const wrapper = start()
    await wrapper.get('[aria-label="Data source"]').setValue('local_review')
    await flushPromises()
    expect(axios.get.mock.calls[0][1].signal.aborted).toBe(true)
    old.resolve({ data: { ...report(), source_note: 'OBSOLETE AUDIT' } }); await flushPromises()
    expect(wrapper.text()).not.toContain('OBSOLETE AUDIT')
  })

  it('shows a recoverable read error', async () => {
    axios.get.mockRejectedValueOnce({ response: { data: { message: 'Capture is unavailable' } } })
    const wrapper = start(); await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('Capture is unavailable')
    await wrapper.get('[role="alert"] button').trigger('click'); await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('aborts outstanding reads when leaving the page', () => {
    axios.get.mockReturnValue(deferred().promise)
    const wrapper = start()
    wrapper.unmount()
    expect(axios.get.mock.calls[0][1].signal.aborted).toBe(true)
  })
})
