<script setup>
import { computed } from 'vue'
import { findWallAssessment, savedWallNextCondition, wallDecisionCards, wallReactionTitle } from '@/utils/wallDecision'

const props = defineProps({ interaction: { type: Object, required: true }, latestReading: Object, selectedEventId: String })
const emit = defineEmits(['inspect', 'evidence'])
const event = computed(() => findWallAssessment(props.interaction, props.selectedEventId))
const closed = computed(() => props.interaction.state === 'session_review')
const paused = computed(() => props.interaction.state === 'awaiting_update')
const cards = computed(() => wallDecisionCards(props.interaction, props.latestReading, event.value))
const number = value => Number.isFinite(value) ? value.toLocaleString('en-US', { maximumFractionDigits: 6 }) : '—'
const time = value => value ? new Date(value).toLocaleTimeString('en-US', {
  timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit',
}) : '—'
const assessedAt = computed(() => event.value?.observed_at || props.interaction.as_of)
function next(card) {
  if (!event.value && closed.value) return 'Session ended. There is no active entry.'
  if (!event.value && paused.value) return 'Wait for the next completed five-minute assessment. The last setup is not active.'
  const saved = savedWallNextCondition(card)
  if (saved) return saved.text
  return ({
    entry_missed: 'Price moved beyond the entry area. Wait for a new price sequence.',
    expired: 'The setup window ended. Wait for a new price sequence.',
    invalidated: 'This setup ended. Wait for a new price sequence.',
    target_reached: 'A later close reached the reference target. Wait for a new price sequence.',
    no_setup: 'Wait for a new price sequence with enough room to the next level.',
  }[card.setup?.status]) || (Number.isFinite(card.strike)
    ? `Watch for price to reach ${number(card.strike)}, then assess the completed five-minute closes.`
    : 'Waiting for a wall level to assess.')
}
function progress(card) {
  if ((!event.value && (closed.value || paused.value)) || card.ready) return null
  const step = savedWallNextCondition(card)
  return Number.isInteger(step?.completed_closes) && step.required_closes > 0
    ? `${step.completed_closes} of ${step.required_closes} confirming closes recorded` : null
}
const title = computed(() => event.value ? 'At this recorded assessment' : closed.value ? 'Session ended' : 'What to watch now')
</script>

<template>
  <section class="wall-next-step" aria-label="Next wall conditions" tabindex="-1">
    <header><div><h3>{{ title }}</h3><p>{{ interaction.symbol }} · {{ interaction.session }}<template v-if="assessedAt"> · {{ event ? 'Recorded' : 'Assessed' }} {{ time(assessedAt) }} ET</template></p></div>
      <button v-if="event" type="button" @click="emit('inspect', null)">Back to latest</button>
      <button v-if="event" type="button" @click="emit('evidence')">View supporting bars</button>
      <span v-else class="delay">Five-minute closes · Prices delayed 15 min</span>
    </header>
    <div class="next-step-cards">
      <article v-for="card in cards" :key="card.side" :class="['next-step-card', card.side, { qualified: card.ready }]">
        <div class="level"><strong>{{ card.side === 'put' ? 'Put' : 'Call' }} wall {{ number(card.strike) }}</strong>
          <span v-if="card.ready">{{ card.setup.direction === 'bullish' ? 'Bullish' : 'Bearish' }} · Conditions met {{ time(card.setup.observed_at) }} ET</span></div>
        <template v-if="card.ready">
          <dl><div><dt>Entry area</dt><dd>{{ number(card.setup.entry_zone.low) }}–{{ number(card.setup.entry_zone.high) }}</dd></div>
            <div><dt>Invalid if 5m close</dt><dd>{{ card.setup.invalidation.close_side }} {{ number(card.setup.invalidation.price) }}</dd></div>
            <div><dt>Reference target</dt><dd>{{ number(card.setup.target.strike) }}</dd></div></dl>
          <p class="recorded-note">Conditions passed at this delayed close. Check current price against the entry area.</p>
        </template>
        <template v-else>
          <p class="reaction">{{ card.isEarlier ? 'Earlier: ' : '' }}{{ wallReactionTitle(card.reaction?.status) }}<template v-if="card.isEarlier && card.reaction?.observed_at"> · {{ time(card.reaction.observed_at) }} ET</template></p>
          <p v-if="progress(card)" class="progress">{{ progress(card) }}</p>
          <p class="next-condition"><b>{{ !event && closed ? 'Status' : !event && paused ? 'Updating' : 'Next' }}:</b> {{ next(card) }}</p>
        </template>
      </article>
    </div>
  </section>
</template>

<style scoped>
.wall-next-step{margin-top:20px;padding:18px;border:1px solid #43536a;border-radius:12px;background:#192131;color:#edf3fc;min-width:0}header,.level{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}h3{font-size:1rem;font-weight:700;margin:0}header p,.delay{font-size:.74rem;color:#b9c9de;margin:5px 0 0}.next-step-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}.next-step-card{padding:15px;border:1px solid #3b495f;border-top:2px solid #ef9d8f;border-radius:8px;min-width:0}.next-step-card.call{border-top-color:#7ee0b0}.next-step-card:only-child{grid-column:1/-1}.level strong{font-size:.9rem;color:#ef9d8f}.call .level strong{color:#7ee0b0}.level span{font-size:.77rem;color:#b6f0d2}.reaction{font-size:.8rem;color:#c2d0e3;margin:10px 0}.next-condition{font-size:.87rem;line-height:1.6;color:#edf3fc;margin:7px 0 0}.progress{font-size:.73rem;color:#b9c5fc;margin:7px 0}.recorded-note{font-size:.73rem;line-height:1.5;color:#b9c9de;margin:12px 0 0}dl{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:15px 0 0}dt{font-size:.73rem;color:#bfccde}dd{font-size:.86rem;font-weight:650;font-variant-numeric:tabular-nums;margin:5px 0 0;overflow-wrap:anywhere}button{font-size:.8rem;padding:8px 12px;border:1px solid #536382;border-radius:7px;background:#26334a;color:#edf3fc}button:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}@media(max-width:650px){.wall-next-step{padding:14px}.next-step-cards{grid-template-columns:1fr}dl{grid-template-columns:1fr}.level{align-items:flex-start}}
</style>
