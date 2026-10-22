<template>
  <section v-if="profile" class="intraday-gamma" aria-label="Intraday gamma context">
    <header><h3>Gamma context</h3><span>{{ time(reading.observed_at) }} ET · Same 1W expiries as the walls</span></header>
    <div class="intraday-gamma__values">
      <div><span>Modeled regime</span><strong :class="profile.regime">{{ regime(profile.current_sign) }}</strong><small>At the recorded price {{ number(reading.spot) }}</small></div>
      <div><span>{{ multiple ? `Nearest of ${profile.crossings.length} flips` : 'Gamma flip' }}</span><strong>{{ nearest ? number(nearest.price) : 'No flip in range' }}</strong><small>{{ nearest ? `${regime(nearest.below_sign)} below · ${regime(nearest.above_sign)} above` : `Tested ${number(profile.grid.lower_price)}–${number(profile.grid.upper_price)}` }}</small></div>
      <div><span>Price vs. nearest flip</span><strong>{{ distanceLabel }}</strong><small>{{ nearest ? `${number(Math.abs(reading.spot - nearest.price))} points ${reading.spot >= nearest.price ? 'above' : 'below'}` : 'No sign change found within ±10%' }}</small></div>
      <div><span>Flip movement</span><strong>{{ movement.label }}</strong><small>{{ movement.detail }}</small></div>
    </div>
    <details>
      <summary>How to use gamma context</summary>
      <p>The walls show strikes with concentrated exposure. A gamma flip is an underlying-price level where the model’s total net gamma changes sign across all selected contracts. It is separate from the put wall, call wall and HVL.</p>
      <p>Use the regime to add context when price approaches a wall or flip. A change in modeled gamma does not confirm a price break or a trade entry. Prior-session open interest and reference IV remain fixed; price and time change.</p>
      <p>Only the ±10% price range is tested. More than one flip can exist; the nearest is shown above. Narrow crossings between samples may not be resolved. Changes compare consecutive gamma readings within the same comparison window.</p>
      <table v-if="multiple"><caption>Modeled gamma flips at {{ time(reading.observed_at) }} ET</caption><thead><tr><th>Price</th><th>Below</th><th>Above</th></tr></thead><tbody><tr v-for="crossing in profile.crossings" :key="crossing.price"><td>{{ number(crossing.price) }}</td><td>{{ regime(crossing.below_sign) }}</td><td>{{ regime(crossing.above_sign) }}</td></tr></tbody></table>
    </details>
  </section>
</template>

<script setup>
import { computed } from 'vue'
const props = defineProps({ reading: { type: Object, default: null }, readings: { type: Array, default: () => [] } })
const valid = row => {
  const context = row?.gamma_context
  return context?.status === 'ready' && context.model_version === 'intraday-fixed-reference-iv-gamma-profile.v1'
    && context.basis_key === row.basis_key && context.scope_key === row.scope_key
    && context.observed_at === row.observed_at && context.reference_price === row.spot
    && Array.isArray(context.crossings) && context.grid
}
const profile = computed(() => valid(props.reading) ? props.reading.gamma_context : null)
const nearest = computed(() => profile.value?.nearest_crossing)
const multiple = computed(() => profile.value?.flip_status === 'multiple_crossings')
const number = value => Number.isFinite(value) ? new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(value) : '—'
const regime = sign => sign > 0 ? 'Positive gamma' : sign < 0 ? 'Negative gamma' : 'Balanced gamma'
const time = value => new Date(value).toLocaleTimeString('en-US', { timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit' })
const distanceLabel = computed(() => nearest.value ? `${number(profile.value.distance_to_nearest_flip_pct)}% ${props.reading.spot >= nearest.value.price ? 'above' : 'below'}` : 'No nearby flip')
const movement = computed(() => {
  if (!profile.value || !nearest.value) return { label: 'No flip in range', detail: 'Within ±10% of this reading’s price' }
  if (multiple.value) return { label: 'Multiple flips', detail: 'Open the guide to see every crossing' }
  const comparable = []
  for (const row of [...props.readings].reverse()) {
    if (!valid(row) || row.basis_key !== props.reading.basis_key || row.scope_key !== props.reading.scope_key
      || row.gamma_context.model_version !== profile.value.model_version || row.gamma_context.flip_status !== 'single_crossing'
      || Date.parse(row.observed_at) > Date.parse(props.reading.observed_at)) break
    comparable.unshift(row)
  }
  if (comparable.length < 2) return { label: 'First gamma reading', detail: 'Movement compares matching recorded inputs' }
  const first = comparable[0], change = nearest.value.price - first.gamma_context.nearest_crossing.price
  return { label: Math.abs(change) < .005 ? 'Unchanged' : `${change > 0 ? 'Up' : 'Down'} ${number(Math.abs(change))} points`, detail: `Since ${time(first.observed_at)} ET · Same comparison window` }
})
</script>

<style scoped>
.intraday-gamma{margin-top:18px;border:1px solid var(--ui-border,#394357);border-radius:12px;padding:18px 20px;background:var(--ui-surface-raised,#202536);color:var(--ui-text,#dce8fc)}
header{display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:16px}h3{font-size:14px;font-weight:700;margin:0}header span,small{font-size:12px;color:var(--ui-text-muted,#a7b9d5)}
.intraday-gamma__values{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px}.intraday-gamma__values>div{display:flex;flex-direction:column;gap:8px}.intraday-gamma__values span{font-size:12px;color:var(--ui-text-muted,#b8c8df)}strong{font-size:21px;line-height:1.25;font-weight:650;color:#c0bbff}.positive_gamma{color:#93dfb5}.negative_gamma{color:#eda28c}.balanced{color:#d1d9e6}
details{border-top:1px solid var(--ui-border,#394357);margin-top:18px;padding-top:12px;font-size:12px;line-height:1.65}summary{cursor:pointer;font-weight:600}p{margin:12px 0 0;max-width:105ch}table{margin-top:12px;width:100%;text-align:left}caption{text-align:left}th,td{padding:6px 10px;border-bottom:1px solid var(--ui-border,#394357)}
@media(max-width:850px){.intraday-gamma__values{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:430px){.intraday-gamma{padding:14px}.intraday-gamma__values{gap:18px 12px}strong{font-size:18px}}
</style>
