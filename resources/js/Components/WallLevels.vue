<script setup>
import { computed, ref, watch } from 'vue'
import { compact } from './UI/numbers'
import { wallReadings } from '@/lib/wall-levels'
import WallIntelligenceDetails from './WallIntelligenceDetails.vue'
import { useWallIntelligence } from '@/composables/useWallIntelligence'

const props = defineProps({ levels: { type: Object, default: null }, symbol: String, scopeLabel: String, showChartLink: Boolean, intelligenceEnabled: Boolean, summaryOnly: Boolean, showAnalysisLink: Boolean, showTrackingLink: Boolean })
const { data: intelligence, loading: intelligenceLoading, error: intelligenceError, retry: retryIntelligence } = useWallIntelligence(() => props.levels, () => props.intelligenceEnabled)
defineEmits(['open-chart', 'open-analysis', 'open-tracking'])
const selection = ref({ put: 0, call: 0 })
watch(() => props.levels, () => { selection.value = { put: 0, call: 0 } })
const sides = computed(() => ['put', 'call'].map(side => {
  const readings = wallReadings(props.levels, side)
  return { side, title: side === 'put' ? 'Put wall' : 'Call wall', readings, selected: props.summaryOnly ? readings[0] : readings[selection.value[side]] ?? readings[0] }
}))
const price = value => value == null ? 'Not available' : value.toLocaleString('en-US', { maximumFractionDigits: 2 })
const exposure = value => value == null ? 'Not available' : compact(value)
const tone = value => value == null || value === 0 ? 'neutral' : value > 0 ? 'positive' : 'negative'
</script>

<template>
  <section class="wall-levels gex-ui" data-theme="dark" aria-label="Wall levels">
    <header class="walls-heading">
      <div><p class="walls-eyebrow">{{ symbol }} · {{ scopeLabel }} · END OF DAY</p><h2>Wall levels</h2><p class="walls-muted">Find the key price levels shaped by options positioning.</p></div>
      <div class="walls-actions">
        <button v-if="showAnalysisLink" type="button" class="walls-button" @click="$emit('open-analysis')">Explore wall analysis</button>
        <button v-if="showTrackingLink" type="button" class="walls-button" @click="$emit('open-tracking')">Track during the session</button>
        <span v-if="levels?.data_date" class="walls-chip">Data as of {{ levels.data_date }}</span>
        <button v-if="showChartLink" type="button" class="walls-button" @click="$emit('open-chart')">Explore strike chart <span aria-hidden="true">→</span></button>
      </div>
    </header>
    <div v-if="intelligenceLoading" class="wall-analysis-status" role="status">Comparing wall concentration and expiry contributions…</div>
    <div v-else-if="intelligenceError" class="wall-analysis-status" role="status">{{ intelligenceError }} <button type="button" class="walls-button" @click="retryIntelligence">Retry wall details</button></div>
    <div class="walls-grid" :aria-busy="intelligenceLoading">
      <article v-for="group in sides" :key="group.side" class="wall-card" :data-side="group.side">
        <div class="wall-title"><h3>{{ group.title }}</h3><span class="walls-muted">{{ summaryOnly || selection[group.side] === 0 ? 'Primary level' : 'Additional level' }}</span></div>
        <strong class="wall-price">{{ price(group.selected?.strike) }}</strong>
        <p v-if="!summaryOnly" class="walls-muted wall-description">{{ group.side === 'put' ? 'Ranked by negative net GEX, from largest magnitude.' : 'Ranked by positive net GEX, from largest magnitude.' }}</p>
        <div v-if="!summaryOnly && group.readings.length" class="wall-choices" :aria-label="`${group.title} levels`">
          <button v-for="(reading, index) in group.readings" :key="reading.strike" type="button" :aria-pressed="selection[group.side] === index" @click="selection[group.side] = index"><span>{{ index === 0 ? 'Primary' : `Level ${index + 1}` }}</span> {{ price(reading.strike) }}</button>
        </div>
        <div class="wall-reading" aria-live="polite">
          <div><span class="walls-muted">Net GEX at this strike</span><strong :data-tone="tone(group.selected?.net)">{{ exposure(group.selected?.net) }}</strong></div>
          <span class="walls-unit">USD per 1% underlying move</span>
        </div>
        <div v-if="!summaryOnly" class="wall-legs"><span>Call GEX <b>{{ exposure(group.selected?.call) }}</b></span><span>Put GEX <b>{{ exposure(group.selected?.put) }}</b></span></div>
        <WallIntelligenceDetails
          v-if="!summaryOnly"
          :reading="intelligence?.walls?.[group.side]?.find(wall => wall.strike === group.selected?.strike)"
          :reference="intelligence?.reference_price"
        />
      </article>
    </div>
    <footer class="walls-footer">
      <p>{{ summaryOnly ? 'Start with these levels. Explore wall analysis for concentration, expiry contributions, and daily changes.' : 'Compare exposure across expiries, then track how the modeled walls move during the session.' }}</p>
      <details v-if="!summaryOnly">
        <summary>How to read these levels</summary>
        <div class="walls-detail">
          <p>Levels follow the selected dashboard symbol, expiry view, and timeframe. Data as of {{ levels?.data_date || 'not available' }}<template v-if="levels?.view_context?.session_date"> · Analysis session {{ levels.view_context.session_date }}</template>.</p>
          <p>Net GEX is call GEX minus put GEX. Values show estimated exposure in USD per 1% underlying move.</p>
          <ol class="wall-reading-steps">
            <li><strong>Choose the expiration scope.</strong> 1DTE and 2W select which option expirations contribute to the levels. They are not the length of the history chart. Check the included dates above; changing scope can change the leading walls.</li>
            <li><strong>Locate the levels.</strong> The put wall is the strike with the largest negative net GEX; the call wall has the largest positive net GEX. Primary, Level 2 and Level 3 rank exposure magnitude, not proximity to price. Either wall can be above or below the current price.</li>
            <li v-if="intelligence"><strong>Check concentration and distance.</strong> “Vs. nearby strikes” compares exposure magnitude with the average of the nearest two strikes on either side. 5× means five times that average, not five times more likely to hold. Distance uses the displayed EOD close, not a live quote.</li>
            <li v-if="intelligence"><strong>See which expirations drive the wall.</strong> A 70% share means that expiry contributes 70% of the absolute expiry exposure at this strike. Opposite signs do not cancel in this percentage. Recheck the wall as the dominant expiration approaches.</li>
            <li v-if="intelligence"><strong>Read the five-session history.</strong> Each bar follows the selected strike with the current expiration set. “Different expirations” means the earlier basket does not match; a dash never means zero exposure. The current bar matches the headline. This chart does not show the leading wall's past locations.</li>
            <li v-if="intelligence"><strong>Compare only matching sessions.</strong> A +46.5% magnitude change means absolute exposure grew 46.5%, even if GEX is negative. It is not a price forecast or proof of new buying. A top-three streak counts consecutive comparable sessions in that side's top three, capped at five; it is not a count of successful price holds.</li>
            <li><strong>Watch the price response separately.</strong> Use these levels as areas to monitor. Intraday Wall tracking shows modeled wall movement through a session; the EOD history shows daily exposure at one strike.</li>
          </ol>
          <p>Wall levels identify exposure concentrations. A hold, break, or trade entry requires separate price confirmation.</p>
        </div>
      </details>
    </footer>
  </section>
</template>

<style scoped>
.wall-reading-steps{list-style:decimal;padding-left:20px;margin:12px 0}.wall-reading-steps li{padding-left:4px;margin:10px 0}.wall-reading-steps strong{color:#dae7f8;font-weight:600}
.wall-analysis-status{padding:12px 14px;margin-bottom:16px;background:#223047;border-radius:8px;color:#bdd5f1;font-size:13px;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
.wall-levels{padding:22px;border:1px solid #364254;border-radius:14px;background:#171d28;color:#e8edf5}
.walls-heading,.wall-title,.walls-actions{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}.walls-heading{align-items:flex-start;margin-bottom:20px}.walls-eyebrow{font-size:11px;font-weight:650;letter-spacing:.09em;color:#a6b8d1;margin-bottom:5px}h2{font-size:22px;font-weight:700}h3{font-size:15px;font-weight:650}.walls-muted{color:#b6c1d1;font-size:13px;line-height:1.6}.walls-heading h2{margin-bottom:5px}.walls-chip{border:1px solid #4b607c;border-radius:20px;padding:5px 9px;font-size:11px;color:#acd0fc}.walls-button{border:1px solid #465a78;border-radius:8px;padding:9px 12px;font-size:13px;background:#253248}.walls-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.wall-card{border:1px solid #3b475b;border-top:2px solid #92c5ff;border-radius:11px;padding:19px;background:#1b2330}.wall-card[data-side=put]{border-top-color:#eca18f}.wall-title>span{font-size:11px}.wall-price{display:block;font-size:clamp(30px,3vw,40px);font-variant-numeric:tabular-nums;margin:7px 0;color:#f2f6fd}.wall-description{margin-bottom:16px}.wall-choices{display:flex;gap:7px;flex-wrap:wrap}.wall-choices button{font-size:13px;padding:7px 10px;border-radius:7px;border:1px solid #43506a;background:#222d3e;transition:background .15s,border-color .15s}.wall-choices span{font-size:10px;color:#b6c1d1;margin-right:5px}.wall-choices button[aria-pressed=true]{border-color:#97c4ff;background:#2b3b52}.wall-reading{padding-top:18px;margin-top:18px;border-top:1px solid #3b475b}.wall-reading>div{display:flex;justify-content:space-between;gap:10px;align-items:baseline}.wall-reading strong{font-size:24px;font-variant-numeric:tabular-nums}.wall-reading [data-tone=positive]{color:#8ce0b8}.wall-reading [data-tone=negative]{color:#f0a38f}.walls-unit{display:block;font-size:11px;color:#a7b6ca;margin-top:4px}.wall-legs{display:flex;gap:20px;flex-wrap:wrap;font-size:12px;color:#b6c1d1;margin-top:13px}.wall-legs b{font-weight:550;color:#dce5f3;margin-left:5px}.walls-footer{margin-top:17px;font-size:12px;line-height:1.65;color:#b6c1d1}.walls-footer summary{cursor:pointer;color:#a8cfff;margin-top:10px;min-height:28px}.walls-detail{padding:12px 15px;border:1px solid #39465b;border-radius:8px;margin-top:8px}.walls-detail p+p{margin-top:8px}button:focus-visible,summary:focus-visible{outline:2px solid #a0ccff;outline-offset:3px}@media(max-width:700px){.wall-levels{padding:16px}.walls-grid{grid-template-columns:1fr}.wall-card{padding:16px}.wall-reading strong{font-size:22px}}@media(prefers-reduced-motion:reduce){.wall-choices button{transition:none}}
</style>
