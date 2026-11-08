<script setup>
import { computed } from 'vue'
import { wallActivityDashboardUrl } from '@/Pages/Scanner/scannerUtils'
import { wallSetupTitle } from '@/utils/wallDecision'
import { presentIdea } from '@/Support/ideaPresentation'
import WallStatusBadge from '@/Components/WallStatusBadge.vue'

const props = defineProps({ item: { type: Object, required: true }, archived: Boolean })
const idea = computed(() => presentIdea(props.item))
defineEmits(['open'])
const number = value => Number.isFinite(value) ? value.toLocaleString('en-US', { maximumFractionDigits: 6 }) : '—'
const time = value => new Date(value).toLocaleTimeString('en-US', { timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit' })
const hasLevels = item => item.status === 'conditions_met'
  && [item.setup?.entry_zone?.low, item.setup?.entry_zone?.high, item.setup?.invalidation?.price,
    item.setup?.target?.strike].every(value => Number.isFinite(value) && value > 0)
  && item.setup.entry_zone.low < item.setup.entry_zone.high
  && ['above', 'below'].includes(item.setup.invalidation.close_side)
</script>

<template>
  <WallStatusBadge :status="idea.status_group" />
  <strong>{{ idea.preview ? 'Test alert · ' : '' }}{{ idea.symbol }} · {{ idea.status === 'conditions_met' ? wallSetupTitle(idea.setup) : 'Testing a wall' }}</strong>
  <p v-if="idea.additional_confirmations?.length" class="context">Same levels also confirmed at {{ idea.additional_confirmations.map(row => time(row.observed_at) + ' ET').join(', ') }}.</p>
  <p v-if="idea.origin === 'watchlist'" class="context">From your watchlist</p>
  <p v-if="idea.preview" class="example">Example notification. No market event.</p>
  <p class="wall-alert-meta">{{ idea.side === 'put' ? 'Put' : 'Call' }} wall {{ number(idea.strike) }} · <time :datetime="idea.observed_at">{{ time(idea.observed_at) }} ET</time></p>
  <template v-if="hasLevels(idea)">
    <p class="assessment-status">Conditions met at recorded close {{ number(idea.close) }}</p>
    <dl aria-label="Recorded setup levels">
      <div><dt>Entry area</dt><dd>{{ number(idea.setup.entry_zone.low) }}–{{ number(idea.setup.entry_zone.high) }}</dd></div>
      <div><dt>Risk boundary · 5m close</dt><dd>{{ idea.setup.invalidation.close_side }} {{ number(idea.setup.invalidation.price) }}</dd></div>
      <div><dt>Reference target</dt><dd>{{ number(idea.setup.target.strike) }}</dd></div>
    </dl>
    <p class="context">{{ idea.preview ? 'Synthetic underlying prices for preview only.' : 'Recorded idea · underlying prices delayed at least 15 min. Check current price against these reference levels.' }}</p>
  </template>
  <p v-else class="context">{{ idea.status === 'conditions_met' ? 'Conditions passed at the recorded close. View the assessment for its levels.' : archived ? 'Price was testing the wall at this time. Entry confirmation was pending.' : 'Entry confirmation is pending. A wall test alone does not confirm a setup.' }}</p>
  <a :href="idea.preview ? '/scanner?scan=activity' : wallActivityDashboardUrl(idea)" @click="$emit('open')">{{ idea.preview ? 'Open Wall activity' : archived && !idea.assessment_id ? 'Open wall tracking' : 'View assessment' }} <span aria-hidden="true">→</span></a>
</template>

<style scoped>
strong{font-size:.9rem;display:block;line-height:1.5}p{font-size:.8rem;color:#d5e1f2;margin:8px 0;line-height:1.5}.wall-alert-meta{font-size:.76rem;color:#c0cfe3}.assessment-status{font-size:.75rem;color:#a5e5c4}.example{font-size:.75rem;color:#edda98}.context{font-size:.73rem;color:#bac9dc}dl{display:grid;gap:8px;margin:12px 0;padding:12px 0;border-block:1px solid #3a4b61}dl div{display:flex;justify-content:space-between;align-items:baseline;gap:12px}dt{font-size:.73rem;color:#bac9dc;flex-shrink:0}dd{margin:0;text-align:right;font-size:.8rem;font-weight:650;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}a{display:inline-flex;align-items:center;gap:7px;min-height:36px;font-size:.8rem;color:#b5d6ff}a:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}
</style>
