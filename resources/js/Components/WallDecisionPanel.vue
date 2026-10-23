<script setup>
import { computed, ref, watch } from 'vue'
import WallInteractionPanel from '@/Components/WallInteractionPanel.vue'
import WallSetupPanel from '@/Components/WallSetupPanel.vue'
import WallSetupHistoryPanel from '@/Components/WallSetupHistoryPanel.vue'
import { findWallAssessment, savedWallNextCondition, wallDecisionCards } from '@/utils/wallDecision'

const props = defineProps({ interaction: { type: Object, required: true }, selectedEventId: String, latestReading: Object, detailsOnly: Boolean })
const emit = defineEmits(['inspect'])
const advancedOpen = ref(false)
const selectedEvent = computed(() => findWallAssessment(props.interaction, props.selectedEventId))
const closed = computed(() => props.interaction.state === 'session_review')
const qualifiedCount = computed(() => props.interaction.setup_reviews?.summary?.qualified || 0)
const hasReview = computed(() => Number.isFinite(props.interaction.setup_reviews?.summary?.qualified))
const price = computed(() => selectedEvent.value ? selectedEvent.value.close : props.latestReading?.spot)
const priceTime = computed(() => selectedEvent.value?.observed_at || props.latestReading?.observed_at)
const time = value => value ? new Date(value).toLocaleTimeString('en-US', { timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit' }) : '—'
const number = value => Number.isFinite(value) ? value.toLocaleString('en-US', { maximumFractionDigits: 3 }) : '—'
const reactionLabel = status => ({
  approaching: 'Price is approaching', testing: 'Price is testing the level', touch: 'Price touched the level',
  bounce: 'Price bounced higher', rejection: 'Price moved lower after a touch', break: 'Price closed through the level',
  acceptance_above: 'Two closes above the level', acceptance_below: 'Two closes below the level',
  reclaim: 'Price returned across the level', failed_reclaim: 'Retest did not reclaim the level',
  confirmed_breakout: 'Price moved higher after a retest', confirmed_breakdown: 'Price moved lower after a retest',
}[status] || 'No price reaction recorded yet')

const cards = computed(() => wallDecisionCards(props.interaction, props.latestReading, selectedEvent.value))
const readyCards = computed(() => cards.value.filter(card => card.ready))
const headline = computed(() => selectedEvent.value ? 'Reviewing a recorded price reaction'
  : closed.value ? (hasReview.value ? (qualifiedCount.value ? `Session ended · ${qualifiedCount.value} setup${qualifiedCount.value === 1 ? '' : 's'} met the conditions` : 'Session ended · No setup confirmed') : 'Session ended · No active setup')
    : props.interaction.state === 'awaiting_update' ? 'Waiting for the next price assessment'
      : readyCards.value.length ? 'Setup conditions met at the recorded close' : 'No confirmed setup yet')
const description = computed(() => selectedEvent.value ? `Inspecting ${time(selectedEvent.value.observed_at)} ET. Return to latest to see the current assessment.`
  : closed.value ? 'These are past reactions, with no active entry. Use the current session’s walls for your next assessment.'
    : readyCards.value.length ? 'Review the levels below. These conditions describe a delayed price close; check current price before acting.'
      : 'Use these levels as areas to watch. A touch alone does not confirm a hold or a break.')
function nextWatch(card) {
  if (props.interaction.state === 'awaiting_update' && !selectedEvent.value) return 'Wait for the next completed five-minute assessment. The last setup is not active.'
  if (savedWallNextCondition(card)) return savedWallNextCondition(card).text
  if (card.pending) {
    const zone = card.setup.entry_zone
    if (card.setup.confirmed_at && Number.isFinite(zone?.low) && Number.isFinite(zone?.high)) return `The price sequence passed. A completed five-minute close still needs to land in ${number(zone.low)}–${number(zone.high)}.`
    const bullish = card.setup.direction === 'bullish'
    const threshold = bullish ? zone?.low : zone?.high
    const side = bullish ? 'above' : 'below'
    const beyond = Number.isFinite(threshold) ? `${side} ${number(threshold)}` : 'away from the wall'
    return ({ reversal: `Watch for two consecutive five-minute closes ${beyond} after the touch.`,
      continuation: `Watch for two closes ${beyond}, a later retest of ${number(card.strike)}, then a close continuing ${side}.`,
      reclaim: `Watch for two closes back ${beyond}, then a retest of ${number(card.strike)} and a close ${side}.` }[card.setup.pattern])
      || 'Wait for the price sequence to finish.'
  }
  if (!Number.isFinite(card.strike)) return 'Waiting for a wall level to assess.'
  if (!Number.isFinite(price.value)) return 'Waiting for the next completed-price assessment.'
  const tolerance = card.reaction?.tolerance
  if (Number.isFinite(tolerance) && Math.abs(price.value - card.strike) <= tolerance * 3) return `Price is near ${number(card.strike)}. Wait for completed five-minute closes to confirm a move away or a move through it. A touch alone is not an entry.`
  if (selectedEvent.value) return `This reaction alone does not confirm an entry at ${number(card.strike)}. Check the next completed five-minute closes.`
  return `Watch only if price returns to ${number(card.strike)}. There is no confirmed entry at this wall now.`
}
function distance(strike) {
  if (!Number.isFinite(price.value) || !Number.isFinite(strike)) return null
  const amount = price.value - strike
  return amount === 0 ? 'Price is at this wall' : `Price is ${number(Math.abs(amount))} ${amount > 0 ? 'above' : 'below'} this wall`
}
watch(() => [props.interaction.symbol, props.interaction.session, props.interaction.timeframe].join('|'), () => { advancedOpen.value = false })
watch(() => selectedEvent.value?.id, id => { if (id) advancedOpen.value = true }, { immediate: true })
</script>

<template>
  <section class="wall-decision" aria-label="Wall reaction and next conditions">
    <template v-if="!detailsOnly">
    <header><div><h3>What to watch at the walls</h3><p v-if="Number.isFinite(price)">{{ selectedEvent ? 'Recorded close' : 'Delayed price' }} <b>{{ number(price) }}</b> · {{ time(priceTime) }} ET</p></div>
      <span v-if="interaction.as_of" class="as-of">{{ closed ? 'Session ended' : 'Price assessed' }} · {{ time(interaction.as_of) }} ET</span>
    </header>
    <div class="decision-summary" :class="{ qualified: readyCards.length }" role="status">
      <strong>{{ headline }}</strong><p>{{ description }}</p>
    </div>
    <div v-if="selectedEvent" class="review-context"><span>{{ selectedEvent.side === 'put' ? 'Put' : 'Call' }} wall {{ number(selectedEvent.strike) }} · {{ time(selectedEvent.observed_at) }} ET</span>
      <button type="button" @click="emit('inspect', null)">Back to latest</button></div>
    <div class="decision-cards">
      <article v-for="card in cards" :key="card.side" :class="['decision-card', card.side]">
        <div class="wall-level"><span>{{ card.side === 'put' ? 'Put' : 'Call' }} wall</span><strong>{{ number(card.strike) }}</strong></div>
        <p v-if="distance(card.strike)" class="price-distance">{{ distance(card.strike) }}</p>
        <div class="reaction"><h4>{{ card.isEarlier ? 'Earlier price response' : 'Price response' }}</h4>
          <strong>{{ reactionLabel(card.reaction?.status) }}</strong>
          <p v-if="card.reaction?.observed_at">{{ time(card.reaction.observed_at) }} ET<template v-if="Number.isFinite(card.reaction.close)"> · Close {{ number(card.reaction.close) }}</template></p>
          <p v-else-if="!card.reaction">Waiting for a completed price assessment at this wall.</p>
        </div>
        <div v-if="card.ready" class="next-condition ready"><h4>{{ selectedEvent ? 'Conditions met at this time' : 'Setup conditions met' }}</h4>
          <p>{{ card.setup.direction === 'bullish' ? 'Higher-price' : 'Lower-price' }} scenario · {{ time(card.setup.observed_at) }} ET</p>
          <dl><div><dt>Entry area</dt><dd>{{ number(card.setup.entry_zone?.low) }}–{{ number(card.setup.entry_zone?.high) }}</dd></div>
            <div><dt>Invalid if 5m close</dt><dd>{{ card.setup.invalidation?.close_side }} {{ number(card.setup.invalidation?.price) }}</dd></div>
            <div><dt>Reference target</dt><dd>{{ number(card.setup.target?.strike) }}</dd></div></dl>
        </div>
        <div v-else-if="!closed || selectedEvent" class="next-condition"><h4>{{ selectedEvent ? 'Condition being watched at this time' : 'What to watch next' }}</h4><p>{{ nextWatch(card) }}</p></div>
      </article>
    </div>
    </template>
    <details class="simple-guide"><summary>What is this used for?</summary>
      <p><b>Find an area to monitor.</b> The chart shows where modeled option exposure is concentrated. A wall is not a guaranteed support or resistance level.</p>
      <p><b>See how price responds.</b> A touch only means price reached the area. Later five-minute closes show whether price moved away or crossed it.</p>
      <p><b>Wait for the conditions.</b> A setup needs the required price sequence, an entry area, an invalidation level and enough room to another recorded level. No confirmed setup means those checks have not passed together.</p>
      <p>Put and call identify the exposure side. Either wall can be above or below price. The later results describe recorded price paths, without assuming a trade was placed.</p>
    </details>
    <details class="advanced-review" :open="advancedOpen" @toggle="advancedOpen = $event.target.open">
      <summary><span>Earlier reactions and setup details <span class="disclosure" aria-hidden="true">{{ advancedOpen ? '▾' : '▸' }}</span></span><small v-if="qualifiedCount">{{ qualifiedCount }} setup{{ qualifiedCount === 1 ? '' : 's' }} with conditions met</small></summary>
      <template v-if="advancedOpen">
        <WallInteractionPanel :interaction="interaction" :selected-event-id="selectedEventId" @inspect="emit('inspect', $event)" />
        <WallSetupPanel v-if="interaction.setups" :interaction="interaction" :selected-event-id="selectedEventId" />
        <WallSetupHistoryPanel v-if="interaction.setup_reviews" :reviews="interaction.setup_reviews" />
      </template>
    </details>
    <p class="decision-footnote">Completed five-minute closes · Prices delayed at least 15 minutes · Full evidence is included in Export timeline JSON.</p>
  </section>
</template>

<style scoped>
.wall-decision{margin-top:22px;padding:22px;border:1px solid #43536a;border-radius:14px;background:#171d29;color:#edf3fc;min-width:0}header{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}h3{font-size:1.2rem;font-weight:700;margin:0 0 6px}p{font-size:.86rem;line-height:1.6;color:#bfccde;margin:6px 0}.as-of{font-size:.75rem;color:#bdcce1}.decision-summary{margin:18px 0;padding:14px 16px;border-radius:9px;background:#242c3e;border-left:3px solid #a6b4e9}.decision-summary strong{font-size:1rem;color:#dbe5ff}.decision-summary p{margin-bottom:0}.decision-summary.qualified{border-left-color:#7ee0b0}.decision-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.decision-card{padding:18px;border:1px solid #3b495f;border-top:2px solid #ef9d8f;border-radius:10px;background:#1c2433;min-width:0}.decision-card.call{border-top-color:#7ee0b0}.decision-card:only-child{grid-column:1/-1}.wall-level{display:flex;justify-content:space-between;align-items:center;gap:16px}.wall-level span{font-size:.85rem;color:#c4d2e6}.wall-level strong{font-size:1.8rem;line-height:1.1;font-weight:700}.put .wall-level strong{color:#ef9d8f}.call .wall-level strong{color:#7ee0b0}h4{font-size:.73rem;text-transform:uppercase;letter-spacing:.05em;color:#aabbd3;font-weight:600;margin:0 0 8px}.reaction{margin-top:20px}.reaction>strong{font-size:1rem;color:#e3eaff}.reaction p{font-size:.76rem}.next-condition{margin-top:17px;padding-top:15px;border-top:1px solid #3b495f}.next-condition p{margin-bottom:0}.ready h4{color:#7ee0b0}.ready dl{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:14px 0 0}.ready dt{font-size:.73rem;color:#bac9dc}.ready dd{font-size:.9rem;font-weight:600;margin:5px 0 0;font-variant-numeric:tabular-nums}.ready small{font-weight:400;font-size:.75rem}.simple-guide,.advanced-review{border-top:1px solid #3b495f;margin-top:16px}summary{cursor:pointer;padding:15px 0;font-size:.83rem;font-weight:600}.simple-guide p{max-width:960px;margin:8px 0}.advanced-review summary{display:flex;justify-content:space-between;align-items:center;gap:14px;list-style:revert}.advanced-review summary small{font-size:.73rem;font-weight:400;color:#b6c7df}.decision-footnote{font-size:.72rem;margin:10px 0 0}.review-context{display:flex;align-items:center;justify-content:space-between;gap:14px;margin:14px 0;font-size:.8rem}button{border:1px solid #536382;border-radius:7px;background:#26334a;color:#edf3fc;padding:8px 12px;font-size:.8rem}button:focus-visible,summary:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}@media(max-width:650px){.wall-decision{padding:14px}.decision-cards{grid-template-columns:1fr}.ready dl{grid-template-columns:1fr}.advanced-review summary{align-items:flex-start;flex-direction:column;gap:4px}}
</style>
