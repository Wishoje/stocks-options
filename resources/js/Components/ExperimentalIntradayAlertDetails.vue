<script setup>
import { router } from '@inertiajs/vue3'
import { intradayCaseUrl, intradayVariantLabels } from '@/Support/intradayResearch'
import { swingNumber, swingTime } from '@/Support/swingResearch'
const props = defineProps({ item: { type: Object, required: true }, markRead: Function })
const emit = defineEmits(['open'])
async function open() {
  await props.markRead?.(props.item.id)
  emit('open')
  router.visit(intradayCaseUrl(props.item))
}
</script>
<template>
  <div class="experimental-details">
    <span class="experimental-tag">Experimental intraday · Conditions met</span>
    <h3>{{ item.symbol }} <span>{{ item.label || intradayVariantLabels[item.variant] }}</span></h3>
    <p>Recorded conditions met <time :datetime="item.signal_at">{{ swingTime(item.signal_at) }}</time><span v-if="item.qualified_price != null"> · Close {{ swingNumber(item.qualified_price) }}</span></p>
    <p>Recorded / received <time :datetime="item.signal_recorded_at">{{ swingTime(item.signal_recorded_at) }}</time></p>
    <p>Observations are delayed 15 minutes. This paper event does not guarantee an entry at the current price. Outcomes are reviewed in Intraday experiments.</p>
    <dl><div><dt>Frozen entry</dt><dd>{{ swingNumber(item.entry_low) }}–{{ swingNumber(item.entry_high) }}</dd></div><div><dt>Target</dt><dd>{{ swingNumber(item.target) }}</dd></div><div><dt>Invalidation</dt><dd>{{ item.invalidation_side || item.side }} {{ swingNumber(item.invalidation) }}</dd></div></dl>
    <a :href="intradayCaseUrl(item)" @click.prevent="open">Review paper case</a>
  </div>
</template>
<style scoped>
.experimental-tag{display:inline-block;padding:3px 6px;border:1px solid #a68bd4;border-radius:4px;color:#ddc7ff;font-size:.65rem;font-weight:650}.experimental-details h3{font-size:.95rem;font-weight:700;margin:9px 0 6px}.experimental-details h3 span{font-size:.75rem;font-weight:500;margin-left:6px;color:#d5dcef}.experimental-details p{font-size:.72rem;color:#b9c7db;line-height:1.6;margin:6px 0}.experimental-details dl{display:flex;gap:12px;flex-wrap:wrap;margin:12px 0 8px}.experimental-details dt{font-size:.64rem;color:#a9bcd4}.experimental-details dd{font-size:.73rem;margin:4px 0 0;font-variant-numeric:tabular-nums}.experimental-details a{display:inline-flex;min-height:36px;align-items:center;font-size:.77rem;color:#c9c4ff;text-decoration:underline;text-underline-offset:3px}.experimental-details a:focus-visible{outline:2px solid #c6bcff;outline-offset:3px}
</style>
