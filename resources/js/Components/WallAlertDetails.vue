<script setup>
import { wallActivityDashboardUrl } from '@/Pages/Scanner/scannerUtils'
import { wallSetupTitle } from '@/utils/wallDecision'
import WallStatusBadge from '@/Components/WallStatusBadge.vue'

defineProps({ item: { type: Object, required: true }, archived: Boolean })
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
  <WallStatusBadge v-if="item.status_group" :status="item.status_group" :outcome="item.lifecycle?.outcome" />
  <strong>{{ item.preview ? 'Test alert · ' : '' }}{{ item.symbol }} · {{ item.status === 'conditions_met' ? wallSetupTitle(item.setup) : 'Testing a wall' }}</strong>
  <p v-if="item.additional_confirmations?.length" class="context">Same levels also confirmed at {{ item.additional_confirmations.map(row => time(row.observed_at) + ' ET').join(', ') }}.</p>
  <p v-if="item.origin === 'watchlist'" class="context">From your watchlist</p>
  <p v-if="item.lifecycle?.reason" class="context">{{ item.lifecycle.reason }}</p>
  <p v-if="item.lifecycle?.entry_window_closed && item.status_group === 'in_progress'" class="context">Entry window closed. Following the original target and invalidation until session close.</p>
  <p v-if="item.status_group === 'needs_update'" class="context">Open the assessment to check its latest state. The recorded levels remain below.</p>
  <p v-if="item.preview" class="example">Example notification. No market event.</p>
  <p class="wall-alert-meta">{{ item.side === 'put' ? 'Put' : 'Call' }} wall {{ number(item.strike) }} · <time :datetime="item.observed_at">{{ time(item.observed_at) }} ET</time></p>
  <template v-if="hasLevels(item)">
    <p class="assessment-status">Conditions met at recorded close {{ number(item.close) }}</p>
    <dl aria-label="Recorded setup levels">
      <div><dt>Entry area</dt><dd>{{ number(item.setup.entry_zone.low) }}–{{ number(item.setup.entry_zone.high) }}</dd></div>
      <div><dt>Invalid if 5m close</dt><dd>{{ item.setup.invalidation.close_side }} {{ number(item.setup.invalidation.price) }}</dd></div>
      <div><dt>Reference target</dt><dd>{{ number(item.setup.target.strike) }}</dd></div>
    </dl>
    <p class="context">{{ item.preview ? 'Synthetic underlying prices for preview only.' : archived ? 'Recorded underlying prices. The entry area may no longer apply; check current conditions.' : 'Underlying prices · delayed at least 15 min. Check current price against the entry area.' }}</p>
  </template>
  <p v-else class="context">{{ item.status === 'conditions_met' ? 'Conditions passed at the recorded close. View the assessment for its levels.' : archived ? 'Price was testing the wall at this time. Entry confirmation was pending.' : 'Entry confirmation is pending. A wall test alone does not confirm a setup.' }}</p>
  <a :href="item.preview ? '/scanner?scan=activity' : wallActivityDashboardUrl(item)" @click="$emit('open')">{{ item.preview ? 'Open Wall activity' : archived && !item.assessment_id ? 'Open wall tracking' : 'View assessment' }} <span aria-hidden="true">→</span></a>
</template>

<style scoped>
strong{font-size:.9rem;display:block;line-height:1.5}p{font-size:.8rem;color:#d5e1f2;margin:8px 0;line-height:1.5}.wall-alert-meta{font-size:.76rem;color:#c0cfe3}.assessment-status{font-size:.75rem;color:#a5e5c4}.example{font-size:.75rem;color:#edda98}.context{font-size:.73rem;color:#bac9dc}dl{display:grid;gap:8px;margin:12px 0;padding:12px 0;border-block:1px solid #3a4b61}dl div{display:flex;justify-content:space-between;align-items:baseline;gap:12px}dt{font-size:.73rem;color:#bac9dc;flex-shrink:0}dd{margin:0;text-align:right;font-size:.8rem;font-weight:650;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}a{display:inline-flex;align-items:center;gap:7px;min-height:36px;font-size:.8rem;color:#b5d6ff}a:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}
</style>
