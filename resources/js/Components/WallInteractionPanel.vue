<template>
  <section class="wall-interaction" aria-label="Price behavior at the walls">
    <header>
      <div><p class="eyebrow">PRICE + WALL LEVEL</p><h3>What price did at the walls</h3>
        <p>See the reaction, then open the bars behind it.</p></div>
      <span class="context">{{ contextLabel }}<template v-if="interaction.as_of"> · {{ time(interaction.as_of) }} ET</template></span>
    </header>
    <p v-if="interaction.state === 'waiting_for_bars'" class="waiting" role="status">Watching the walls. Price reactions appear as completed five-minute bars are recorded.</p>
    <p v-else-if="interaction.state === 'awaiting_update'" class="waiting" role="status">Showing the last recorded price behavior. Waiting for the next completed-bar update.</p>
    <div v-if="selectedEvent" class="review-context">
      <span>Inspecting {{ time(selectedEvent.observed_at) }} ET · {{ title(selectedEvent.status) }}</span>
      <button type="button" @click="$emit('inspect', null)">Back to latest behavior</button>
    </div>
    <div class="cards">
      <article v-for="side in selectedEvent ? [selectedEvent.side] : ['put', 'call']" :key="side" :class="['behavior-card', side]">
        <div class="card-top"><span>{{ side === 'put' ? 'Put' : 'Call' }} wall <b>{{ number(card(side)?.strike) }}</b></span>
          <span v-if="card(side)?.observed_at">{{ time(card(side).observed_at) }} ET</span></div>
        <strong class="behavior-status">{{ title(card(side)?.status) }}</strong>
        <p>{{ card(side)?.reason || 'Watching for completed price bars at this wall.' }}</p>
        <div v-if="card(side)?.observed_at" class="card-facts">
          <span>Completed close <b>{{ number(card(side).close) }}</b></span>
          <span>Touch band <b>±{{ number(card(side).tolerance, 3) }}</b></span>
        </div>
      </article>
    </div>
    <div v-if="interaction.events?.length" class="events">
      <label>Inspect a price event
        <select aria-label="Inspect wall interaction" :value="selectedEvent?.id || ''" @change="selectEvent($event.target.value)">
          <option value="">Latest behavior</option>
          <option v-for="event in reversedEvents" :key="event.id" :value="event.id">{{ time(event.observed_at) }} ET · {{ event.side }} {{ number(event.strike) }} · {{ title(event.status) }}</option>
        </select>
      </label>
      <div class="event-strip" aria-label="Recent price events">
        <button v-for="event in reversedEvents.slice(0, 6)" :key="event.id" type="button" :aria-pressed="event.id === selectedEvent?.id" @click="$emit('inspect', event)">
          <span>{{ time(event.observed_at) }} · {{ event.side }} {{ number(event.strike) }}</span><strong>{{ title(event.status) }}</strong>
        </button>
      </div>
    </div>
    <div v-if="selectedEvent" class="evidence" tabindex="-1">
      <h4>{{ title(selectedEvent.status) }} · {{ selectedEvent.side }} wall {{ number(selectedEvent.strike) }}</h4>
      <p>{{ selectedEvent.reason }}</p>
      <p class="evidence-context">Completed bars in this comparison · Times in ET · Wall recorded at {{ time(selectedEvent.wall_observed_at) }}</p>
      <div class="bar-table"><table>
        <caption>Price observations supporting the selected event</caption>
        <thead><tr><th>Bar closed</th><th>Open</th><th>High</th><th>Low</th><th>Close</th><th>Close vs. wall</th></tr></thead>
        <tbody><tr v-for="bar in evidenceBars" :key="bar.id" :class="{ 'event-bar': selectedEvent.evidence_bar_ids?.includes(bar.id) }">
          <td>{{ time(new Date(bar.t + 300000).toISOString()) }}</td><td>{{ number(bar.o) }}</td><td>{{ number(bar.h) }}</td><td>{{ number(bar.l) }}</td><td>{{ number(bar.c) }}</td><td>{{ closeSide(bar.c, selectedEvent) }}</td>
        </tr></tbody>
      </table></div>
    </div>
    <details class="guide">
      <summary>How to read price behavior</summary>
      <p><b>Start with the reaction.</b> A touch says price reached the level. A bounce or rejection says a later close moved back away. A break needs a close through the level; a wick alone is not enough.</p>
      <dl>
        <div><dt>Approaching / testing</dt><dd>Price is moving close to the wall, or a completed bar closed inside its touch band.</dd></div>
        <div><dt>Acceptance above / below</dt><dd>Two consecutive five-minute bars closed beyond the band on the new side. This describes ten minutes of completed bars, not a promise that the level will hold.</dd></div>
        <div><dt>Reclaim</dt><dd>After a break, two consecutive closes returned to the original side.</dd></div>
        <div><dt>Failed reclaim</dt><dd>After acceptance, a later bar retested the band. A separate subsequent bar then closed back on the break side. Merely staying below or above does not count.</dd></div>
        <div><dt>Confirmed break</dt><dd>Acceptance, a failed retest within 30 minutes of the break, then another close at least one band width farther through it. “Confirmed” refers to this observed sequence.</dd></div>
      </dl>
      <p>Rule {{ interaction.rule_version }} uses a band of ±0.05% of the wall price, with a one-cent minimum. Approaching means within three band widths and moving closer. A bounce or rejection must follow a touch within 15 minutes.</p>
      <p>The comparison restarts when the leading wall, option inputs, price source, or session changes, or a completed bar is skipped. A wall change within a bar pauses classification. Prices are delayed at least 15 minutes; only completed regular-session bars count.</p>
      <p>These initial rules describe behavior. They have not established a trading edge, a probability, or an entry signal. Use your own trade trigger and risk limits.</p>
    </details>
    <p class="footnote">Five-minute closes · {{ interaction.state === 'demonstration' ? 'Local synthetic example' : 'Delayed price observations' }} · Events and supporting bars are included in Export timeline JSON.</p>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import { wallInteractionTitle as title } from '@/utils/wallInteraction'
const props = defineProps({ interaction: { type: Object, required: true }, selectedEventId: String })
const emit = defineEmits(['inspect'])
const selectedEvent = computed(() => props.interaction.events?.find(event => event.id === props.selectedEventId))
const reversedEvents = computed(() => [...(props.interaction.events || [])].reverse())
const contextLabel = computed(() => ({ session_review: 'Session review', awaiting_update: 'Last recorded behavior', demonstration: 'Local demonstration' }[props.interaction.state] || 'Completed five-minute bars'))
const time = value => new Date(value).toLocaleTimeString('en-US', { timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit' })
const number = (value, digits = 2) => Number.isFinite(value) ? value.toLocaleString('en-US', { maximumFractionDigits: digits }) : '—'
function card(side) {
  if (!selectedEvent.value) return props.interaction.current?.[side]
  if (selectedEvent.value.side === side) return selectedEvent.value
  return props.interaction.readings?.filter(row => row.side === side && Date.parse(row.observed_at) <= Date.parse(selectedEvent.value.observed_at)).at(-1)
}
function selectEvent(id) { emit('inspect', props.interaction.events?.find(event => event.id === id) || null) }
const evidenceBars = computed(() => {
  const event = selectedEvent.value
  if (!event) return []
  return (props.interaction.bars || []).filter(bar => bar.t >= Date.parse(event.episode_started_at) && bar.t + 300000 <= Date.parse(event.observed_at))
})
function closeSide(close, event) {
  if (close > event.strike + event.tolerance + 1e-9) return 'Above band'
  if (close < event.strike - event.tolerance - 1e-9) return 'Below band'
  return 'Inside band'
}
</script>

<style scoped>
.cards .behavior-card:only-child{grid-column:1 / -1}
.wall-interaction{margin-top:24px;padding:22px;border:1px solid #43536a;border-radius:14px;background:#141d2a;color:#edf3fc;scroll-margin-top:30px}header,.card-top,.review-context{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}h3{font-size:1.2rem;font-weight:700;margin:4px 0 6px}p{font-size:.86rem;color:#bfccde;line-height:1.6}.eyebrow{font-size:.68rem;letter-spacing:.13em;color:#95bce9}.context,.card-top{font-size:.76rem;color:#b9cadf}.context{padding:7px 10px;border:1px solid #40516b;border-radius:7px}.cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:18px}.behavior-card{padding:18px;border:1px solid #38495f;border-top:2px solid #ef9d8f;border-radius:10px;background:#1a2433;min-width:0}.behavior-card.call{border-top-color:#7ee0b0}.card-top b{margin-left:8px;font-size:1rem;color:#f1f6ff}.behavior-status{display:block;color:#b9d8ff;font-size:1.55rem;line-height:1.25;margin:15px 0 10px;font-weight:700}.card-facts{display:flex;gap:20px;flex-wrap:wrap;border-top:1px solid #38495f;margin-top:16px;padding-top:12px;font-size:.75rem;color:#bfccde}.card-facts b{color:#eaf2ff;margin-left:5px}.events{margin-top:20px}.events label{display:flex;gap:14px;align-items:center;flex-wrap:wrap;font-size:.83rem}.event-strip{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px}.event-strip button{text-align:left;padding:12px;display:grid;gap:4px}.event-strip span{font-size:.7rem;color:#c1cde0}.event-strip strong{font-size:.8rem;color:#a9ceff}button,select{border:1px solid #43536e;border-radius:7px;background:#222e40;color:#e7f0ff;padding:8px 11px;font-size:.8rem;max-width:100%}button:hover,button[aria-pressed=true]{background:#2b4260;border-color:#91bbef}button:focus-visible,select:focus-visible,summary:focus-visible{outline:2px solid #a2ceff;outline-offset:3px}.review-context,.waiting{margin-top:16px;padding:10px 12px;background:#223147;border-radius:8px;font-size:.83rem}.evidence{margin-top:18px;padding:16px;background:#1d293a;border:1px solid #56759b;border-radius:9px}.evidence h4{font-weight:650;font-size:1rem}.evidence-context{font-size:.75rem;margin-top:8px}.bar-table{max-height:280px;overflow:auto;margin-top:10px}table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:.8rem;font-variant-numeric:tabular-nums}caption{text-align:left;color:#c1cfe0;margin-bottom:8px;font-size:.72rem}th,td{padding:9px 12px;text-align:right;border-bottom:1px solid #3a4b62}th:first-child,td:first-child{text-align:left}.event-bar{background:#2e4767}.guide{margin-top:20px;border-top:1px solid #35475f}.guide summary{cursor:pointer;padding:16px 0;font-size:.87rem;font-weight:600}.guide p{margin:10px 0}.guide dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.guide dl div{padding:12px;border:1px solid #35475f;border-radius:8px}.guide dt{color:#b9d8ff;font-size:.83rem;font-weight:650;margin-bottom:6px}.guide dd{color:#bfccde;font-size:.8rem;line-height:1.55}.footnote{font-size:.74rem;margin-top:12px}@media(max-width:650px){.wall-interaction{padding:14px}.cards,.guide dl{grid-template-columns:1fr}.event-strip{grid-template-columns:repeat(2,minmax(0,1fr))}.behavior-status{font-size:1.35rem}.events select{width:100%}}
</style>
