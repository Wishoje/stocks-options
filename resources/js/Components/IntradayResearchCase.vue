<script setup>
import { computed } from 'vue'
import { swingNumber, swingPercent, swingSignedPercent, swingTime } from '@/Support/swingResearch'
import { intradayStatusLabel } from '@/Support/intradayResearch'
const props = defineProps({ item: Object, profile: { type: Object, required: true } })
const plan = computed(() => props.item?.state || {})
const metrics = computed(() => plan.value.metrics || {})
const status = computed(() => metrics.value.needs_update ? 'needs_review' : plan.value.outcome || props.item?.status || plan.value.status)
const fields = computed(() => [
  { label: 'Frozen entry area', value: `${swingNumber(plan.value.entry_low)}–${swingNumber(plan.value.entry_high)}` },
  { label: 'Original invalidation', value: swingNumber(plan.value.invalidation) },
  { label: 'Original target', value: swingNumber(plan.value.target) },
  { label: 'Original signal close', value: swingTime(plan.value.original_signal_at) },
  { label: 'Original source available', value: swingTime(plan.value.original_available_at) },
  { label: 'Private watch recorded', value: swingTime(plan.value.created_at) },
  ...(props.profile.key === 'baseline'
    ? [{ label: 'Original entry window ends', value: swingTime(plan.value.original_entry_expires_at) }]
    : [{ label: 'Eligible bar starts at or after', value: swingTime(plan.value.entry_not_before) }, { label: 'Entry window ends', value: swingTime(plan.value.entry_deadline) }]),
  { label: 'Recorded conditions met', value: swingTime(plan.value.signal_at), note: plan.value.signal_price != null ? `Observed close ${swingNumber(plan.value.signal_price)}` : null },
  { label: props.profile.key === 'baseline' ? 'Study control · no alert' : 'Condition event recorded / received', value: props.profile.key === 'baseline' ? 'Study only' : swingTime(props.item?.notification_recorded_at) },
  { label: 'Tracking ends', value: swingTime(plan.value.review_until) },
  { label: 'Reviewed through', value: swingTime(plan.value.evaluated_through || plan.value.last_observed_at) },
  { label: 'Recorded outcome', value: plan.value.outcome ? `${intradayStatusLabel(plan.value.outcome)} · ${swingTime(plan.value.outcome_at)}` : 'Pending' },
  { label: 'Move at recorded outcome', value: swingSignedPercent(metrics.value.outcome_move_pct) },
  { label: 'Best / adverse observed move', value: `${swingPercent(metrics.value.mfe_pct)} / ${swingPercent(metrics.value.mae_pct)}` },
])
</script>
<template>
  <section v-if="item" :id="`intraday-case-${item.id}`" class="paper-case" tabindex="-1">
    <h4>{{ profile.label }}</h4>
    <span class="case-state" :class="{ review: ['needs_review', 'needs_update'].includes(status) }">{{ intradayStatusLabel(status) }}</span>
    <p><span v-if="plan.direction">{{ plan.direction === 'bullish' ? 'Bullish' : 'Bearish' }} · </span>{{ plan.reason }}</p>
    <dl><div v-for="field in fields" :key="field.label"><dt>{{ field.label }}</dt><dd>{{ field.value }}<small v-if="field.note">{{ field.note }}</small></dd></div></dl>
  </section>
  <div v-else class="paper-case missing"><h4>{{ profile.label }}</h4><p>No comparable case was recorded.</p></div>
</template>
<style scoped>
.paper-case{padding:20px;scroll-margin-top:30px;background:#1c2232;border:1px solid #45516a;border-radius:10px}.paper-case h4{font-size:.9rem;font-weight:650;margin:0 0 12px}.case-state{display:inline-block;font-size:.7rem;border:1px solid #676281;color:#ddd4fb;background:#302e45;border-radius:5px;padding:4px 7px}.case-state.review{border-color:#a88b54;color:#e7d09f;background:#3b352a}.paper-case>p{font-size:.75rem;line-height:1.65;color:#bac8df;margin:10px 0}.paper-case dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:20px 0 0}.paper-case dt{font-size:.7rem;line-height:1.5;color:#b4c2da}.paper-case dd{font-size:.8rem;font-variant-numeric:tabular-nums;margin:5px 0 0}.paper-case dd small{display:block;margin-top:4px;font-size:.7rem;color:#afbdd4}.missing{border-style:dashed}.paper-case:focus{outline:2px solid #bcb0ff;outline-offset:3px}@media(max-width:480px){.paper-case{padding:16px}.paper-case dl{gap:15px 11px}}
</style>
