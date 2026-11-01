<script setup>
import { computed } from 'vue'
import { swingNumber, swingPercent, swingStatusLabel, swingTime } from '@/Support/swingResearch'
const props = defineProps({ item: Object, profile: { type: Object, required: true } })
const plan = computed(() => props.item?.state || {})
const levels = computed(() => plan.value.levels || {})
const status = computed(() => props.item?.metrics?.needs_update ? 'needs_review'
  : props.item?.status === 'finished' ? plan.value.outcome || props.item?.metrics?.strict_outcome || 'completed' : props.item?.status)
const fields = computed(() => [
  { label: 'Fixed entry area', value: `${swingNumber(levels.value.entry_low)}–${swingNumber(levels.value.entry_high)}` },
  { label: 'Fixed invalidation · daily close', value: `${levels.value.invalidation_side || ''} ${swingNumber(levels.value.invalidation)}` },
  { label: 'Fixed target', value: swingNumber(levels.value.target) },
  ...(props.profile.key === 'earlier_exit' ? [{ label: 'Original full target', value: swingNumber(props.item?.original_target) }] : []),
  { label: 'Latest observed close', value: swingNumber(props.item?.latest_price?.close), note: swingTime(props.item?.latest_price?.date) },
  { label: 'Current distance to entry area', value: swingPercent(props.item?.latest_price?.distance_to_entry_pct) },
  { label: 'Conditions first met', value: swingTime(plan.value.qualified_at || props.item?.signal_recorded_at), note: plan.value.qualified_price != null ? `Close ${swingNumber(plan.value.qualified_price)}` : null },
  { label: 'Entry deadline / tracking deadline', value: `${swingTime(plan.value.entry_deadline)} / ${swingTime(plan.value.deadline)}` },
  { label: 'Reviewed through', value: swingTime(plan.value.reviewed_through) },
  { label: 'Best / adverse observed price move', value: `${swingPercent(props.item?.metrics?.mfe_pct)} / ${swingPercent(props.item?.metrics?.mae_pct)}` },
])
</script>
<template>
  <section v-if="item" :id="`swing-case-${item.id}`" class="paper-case" tabindex="-1">
    <h4>{{ profile.label }}</h4>
    <span class="case-state" :class="{ review: ['needs_review', 'needs_update'].includes(status) }">{{ swingStatusLabel(status) }}</span>
    <p><span v-if="plan.direction">{{ plan.direction === 'bullish' ? 'Bullish' : 'Bearish' }} · </span>{{ plan.reason }}</p>
    <dl><div v-for="field in fields" :key="field.label"><dt>{{ field.label }}</dt><dd>{{ field.value }}<small v-if="field.note">{{ field.note }}</small></dd></div></dl>
  </section>
  <div v-else class="paper-case missing"><h4>{{ profile.label }}</h4><p>No comparable case was recorded.</p></div>
</template>
<style scoped>
.paper-case{padding:18px;scroll-margin-top:30px;background:#1c2232;border:1px solid #45516a;border-radius:10px}.paper-case h4{font-size:.84rem;font-weight:650;margin:0 0 12px}.case-state{display:inline-block;font-size:.67rem;border:1px solid #676281;color:#ddd4fb;background:#302e45;border-radius:5px;padding:4px 7px}.case-state.review{border-color:#a88b54;color:#e7d09f;background:#3b352a}.paper-case>p{font-size:.7rem;line-height:1.55;color:#bac8df;margin:8px 0}.paper-case dl{display:grid;gap:14px;margin:17px 0 0}.paper-case dt{font-size:.68rem;line-height:1.5;color:#b4c2da}.paper-case dd{font-size:.79rem;font-variant-numeric:tabular-nums;margin:5px 0 0}.paper-case dd small{display:block;margin-top:4px;font-size:.67rem;color:#afbdd4}.missing{border-style:dashed}.paper-case:focus{outline:2px solid #bcb0ff;outline-offset:3px}@media(max-width:760px){.paper-case dl{grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}}@media(max-width:480px){.paper-case{padding:16px}.paper-case dl{gap:15px 11px}}
</style>
