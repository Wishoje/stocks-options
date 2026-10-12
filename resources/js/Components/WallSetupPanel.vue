<script setup>
import { computed, ref, watch } from 'vue'
const props = defineProps({ interaction: { type: Object, required: true }, selectedEventId: String })
const at = ref('')
const data = computed(() => props.interaction.setups)
const times = computed(() => [...new Set((data.value?.readings || []).map(row => row.observed_at))].reverse())
watch(() => [data.value?.symbol, data.value?.session, data.value?.timeframe], () => { at.value = '' })
watch(() => props.selectedEventId, id => {
  at.value = props.interaction.events?.find(event => event.id === id)?.observed_at || ''
}, { immediate: true })
watch(times, values => { if (at.value && !values.includes(at.value)) at.value = '' })
const cards = computed(() => ['put', 'call'].map(side => at.value
  ? [...(data.value?.readings || [])].reverse().find(row => row.side === side && row.observed_at === at.value) || { side, status: 'watch', reason: 'No setup assessment at this recorded time.' }
  : data.value?.current?.[side] || { side, status: 'watch' }))
const time = value => value ? new Date(value).toLocaleTimeString('en-US', { timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit' }) : '—'
const number = value => Number.isFinite(value) ? value.toLocaleString('en-US', { maximumFractionDigits: 3 }) : '—'
const status = value => ({ watch: 'Watching', conditions_met: 'Conditions met', entry_missed: 'Entry missed', invalidated: 'Invalidated', expired: 'Expired', no_setup: 'No setup', target_reached: 'Reference target reached', awaiting_update: 'Waiting for next assessment' }[value] || 'Watching')
const pattern = card => card.pattern ? `${card.direction === 'bullish' ? 'Bullish' : 'Bearish'} ${card.pattern === 'continuation' ? 'break and retest' : card.pattern === 'reclaim' ? 'reclaim and retest' : card.direction === 'bullish' ? 'bounce' : 'rejection'}` : 'Watch for a price sequence'
const trigger = card => ({ continuation: 'Two closes through the wall, a later retest, a separate close back through it, then a further close at least one band width beyond the failed retest.', reversal: 'A touch followed by two consecutive completed closes away from the wall on the original side.', reclaim: 'Two closes reclaim the wall, followed by a later retest and a separate close away on the reclaimed side.' }[card.pattern] || 'A completed bounce, rejection, reclaim, or break-and-retest sequence.')
</script>

<template>
  <section v-if="data" class="wall-setups" aria-label="Conditional wall setups">
    <header><div><p class="eyebrow">CONDITIONAL SETUPS</p><h3>What would confirm the next setup?</h3><p>Follow the trigger, check the room to the next level, and know what ends the scenario.</p></div>
      <span class="timing">{{ data.symbol }} · {{ data.session }} · {{ at ? 'Inspecting' : 'Latest assessment' }}<template v-if="at || data.as_of"> · {{ time(at || data.as_of) }} ET</template></span></header>
    <p class="recorded-note">Based on delayed five-minute closes. “Conditions met” applies at the recorded time; check current price before using a setup.</p>
    <label v-if="times.length" class="setup-selector">Inspect setup time<select v-model="at" aria-label="Inspect setup time"><option value="">Latest assessment</option><option v-for="value in times" :key="value" :value="value">{{ time(value) }} ET</option></select></label>
    <div class="setup-grid">
      <article v-for="card in cards" :key="card.side" :class="['setup-card', card.side]" :aria-label="`${card.side === 'put' ? 'Put' : 'Call'} wall setup`">
        <div class="card-top"><strong>{{ card.side === 'put' ? 'Put' : 'Call' }} wall {{ number(card.strike) }}</strong><span v-if="card.pattern && card.evidence_grade" class="grade">{{ card.evidence_grade }} evidence</span></div>
        <h4>{{ pattern(card) }}</h4><p :class="['setup-status', { ready: card.status === 'conditions_met' }]">{{ status(card.status) }}<small v-if="card.observed_at"> · {{ time(card.observed_at) }} ET</small></p>
        <p class="setup-reason">{{ card.reason }}</p>
        <p v-if="!at && card.status_at_observation === 'conditions_met' && card.status !== 'conditions_met'" class="past-status">Conditions were met at the last recorded assessment. This scenario is not currently active.</p>
        <dl v-if="card.pattern" class="setup-levels">
          <div><dt>{{ card.confirmed_at ? 'Entry zone' : 'Conditional entry zone' }}</dt><dd>{{ number(card.entry_zone?.low) }}–{{ number(card.entry_zone?.high) }}</dd><small>Underlying price · after confirmation</small></div>
          <div><dt>Invalidation</dt><dd>{{ number(card.invalidation?.price) }}</dd><small>A five-minute close {{ card.invalidation?.close_side }} this boundary</small></div>
          <div><dt>Reference target</dt><dd>{{ number(card.target?.strike) }}</dd><small>{{ card.target ? `Recorded ${card.target.side} wall · ${time(card.target.observed_at)} ET` : 'Requires a recorded level in this direction' }}</small></div>
          <div><dt>Room to target</dt><dd>{{ Number.isFinite(card.target_room_ratio) ? `${number(card.target_room_ratio)}×` : '—' }}</dd><small>Versus invalidation distance · 1.5× minimum</small></div>
        </dl>
        <details><summary>Trigger and evidence</summary><p><b>Price trigger.</b> {{ trigger(card) }}</p><p v-if="card.expires_at">This sequence expires by {{ time(card.expires_at) }} ET, or when the wall comparison changes.</p>
          <ul><li v-for="check in card.evidence_checks || []" :key="check.key"><span aria-hidden="true">{{ check.passed ? '✓' : '○' }}</span> {{ check.label }} <b>{{ check.passed ? 'Recorded' : 'Pending' }}</b></li></ul>
          <p v-if="card.confirmed_at">Price sequence completed at {{ time(card.confirmed_at) }} ET. Entry, invalidation and target levels remain fixed for this sequence.</p>
        </details>
      </article>
    </div>
    <details class="setup-guide"><summary>How to use these setup cards</summary>
      <p><b>Start with the status.</b> Watching means a condition still needs to happen. Conditions met means the completed price sequence, entry zone and target room passed at the displayed time. Entry missed means price already closed beyond that zone. Invalidated or Expired ends that scenario.</p>
      <p><b>Read levels as underlying prices.</b> These are not option-contract prices. The entry zone runs from one to three touch-band widths beyond the wall in the scenario direction. A close beyond the opposite band edge invalidates it. The band is 0.05% of the wall price, with a one-cent minimum.</p>
      <p><b>Check the target room.</b> The nearest ranked wall in the scenario direction is chosen when the price sequence confirms. Room is measured from the far edge of the entry zone and must be at least 1.5 times the distance to invalidation. A closer obstacle cannot be skipped to make the ratio look better. This ratio is price geometry; it excludes fees, slippage and option returns.</p>
      <p><b>Read the evidence separately.</b> Strong means all three checks are recorded: the ordered price sequence, the same leading wall across at least three readings and ten minutes, and sufficient room to a recorded target. Moderate means two checks; Weak means zero or one. A strong grade can remain on an expired or invalidated scenario because it describes the recorded evidence, not present readiness.</p>
      <p>These initial rules have not established a trading edge or a win probability. Levels freeze at confirmation, entries expire after 15 minutes or at the session close, and a missed entry does not reopen without a new price sequence. Use Inspect setup time to review earlier assessments.</p>
    </details>
    <p class="setup-footnote">{{ props.interaction.state === 'demonstration' ? 'Local synthetic example' : 'Recorded scenarios' }} · Evidence and rules are included in Export timeline JSON.</p>
  </section>
</template>

<style scoped>
.wall-setups{margin-top:22px;padding:22px;border:1px solid var(--gex-border,#42475d);border-radius:14px;background:var(--gex-surface,#1b1e29);color:var(--gex-text,#e8ecfa);min-width:0}header,.card-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.eyebrow{font-size:11px;letter-spacing:.12em;color:#a7b9df}h3{font-size:20px;font-weight:650;margin:5px 0}p{font-size:13px;line-height:1.6;color:#c4cce0;margin:8px 0}.timing{font-size:12px;color:#c4cce0}.recorded-note{padding:10px 12px;background:var(--gex-raised,#25283a);border-radius:7px}.setup-selector{display:flex;align-items:center;gap:12px;font-size:13px;margin:18px 0}select{background:#25293a;color:#e8ecfa;border:1px solid #505873;border-radius:7px;padding:9px;min-height:40px;max-width:100%}.setup-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.setup-card{border:1px solid #41465e;border-top:2px solid #e2a08e;border-radius:10px;padding:18px;min-width:0}.setup-card.call{border-top-color:#96deb0}.card-top{font-size:13px}.grade{text-transform:capitalize;border:1px solid #535a72;border-radius:20px;padding:4px 9px;font-size:11px;color:#b9d3fc}h4{font-size:17px;font-weight:600;margin:16px 0 5px}.setup-status{font-size:21px;font-weight:650;color:#b9c4ff}.setup-status.ready{color:#9ce2b4}.setup-status small{font-size:12px;font-weight:400;color:#c4cce0}.setup-reason{min-height:42px}.past-status{border-left:2px solid #b9c4ff;padding-left:10px}.setup-levels{display:grid;grid-template-columns:1fr 1fr;gap:18px 14px;margin:18px 0;border-top:1px solid #41465e;padding-top:16px}.setup-levels dt{font-size:12px;color:#c4cce0}.setup-levels dd{font-size:20px;margin:5px 0;font-variant-numeric:tabular-nums;color:#f1f4ff}.setup-levels small{display:block;font-size:11px;color:#b9c5dd;line-height:1.5}summary{font-size:13px;cursor:pointer;font-weight:600;padding:12px 0}details{border-top:1px solid #41465e;margin-top:16px}ul{list-style:none;padding:0;margin:12px 0}li{display:flex;align-items:baseline;gap:8px;font-size:12px;line-height:1.6;margin:8px 0;color:#c4cce0}li b{margin-left:auto;white-space:nowrap;font-size:11px;color:#c9d7f3}.setup-guide p{max-width:1050px}.setup-footnote{font-size:11px}select:focus-visible,summary:focus-visible{outline:2px solid #b5acff;outline-offset:3px}@media(max-width:720px){.setup-grid{grid-template-columns:1fr}.wall-setups{padding:14px}.setup-card{padding:14px}.setup-selector{flex-wrap:wrap}.setup-levels dd{font-size:18px}}
</style>
