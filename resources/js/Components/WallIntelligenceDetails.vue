<script setup>
import { computed } from 'vue'
import { compact } from './UI/numbers'
import WallFlowDetails from './WallFlowDetails.vue'
const props = defineProps({ reading: Object, reference: Object })
const fixed = (value, digits = 1) => value == null ? '—' : Number(value).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits })
const signed = value => value == null ? '—' : `${value > 0 ? '+' : ''}${fixed(value)}%`
const exposure = value => value == null ? '—' : compact(value)
const share = (value, digits = 0) => value == null ? '—' : value > 0 && value < (digits === 0 ? 1 : .1) ? (digits === 0 ? '<1' : '<0.1') : fixed(value, digits)
const tone = value => value > 0 ? 'positive' : value < 0 ? 'negative' : 'neutral'
const points = computed(() => [...(props.reading?.history ?? [])].reverse().map(point => ({ ...point, net_gex: point.display_net_gex ?? point.net_gex })))
const scopeChanged = computed(() => points.value.some(point => point.display_status === 'expiry_scope_changed'))
const referenceOnly = computed(() => points.value.some(point => point.display_status === 'current_reference'))
const previousPoint = computed(() => props.reading?.history?.[1])
const comparisonNote = computed(() => props.reading?.gex_magnitude_change_1d_pct != null
  ? `Since ${shortDate(props.reading.comparison_date)}`
  : previousPoint.value?.display_status === 'expiry_scope_changed' ? 'Expiration set changed' : 'No like-for-like comparison')
const pointLabel = point => ({ current: 'Current level', current_reference: 'Current level', comparable: 'Same expirations', expiry_scope_changed: 'Different expirations', no_comparison: 'Not compared' }[point.display_status] || '')
const maxGex = computed(() => Math.max(1, ...points.value.map(p => Math.abs(p.net_gex ?? 0))))
const distanceLabel = computed(() => props.reading?.signed_distance_pct > 0 ? 'above close' : props.reading?.signed_distance_pct < 0 ? 'below close' : 'from close')
const shortDate = value => value ? new Date(`${value}T12:00:00Z`).toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' }) : '—'
</script>

<template>
  <div v-if="reading" class="wall-intelligence" aria-label="Wall analysis">
    <dl class="wall-metrics">
      <div><dt>Vs. nearby strikes</dt><dd class="accent">{{ fixed(reading.relative_magnitude) }}<small v-if="reading.relative_magnitude != null">×</small></dd><span>{{ reading.neighbor_strikes.length }} nearby strikes compared</span></div>
      <div><dt>Distance from close</dt><dd>{{ fixed(reading.distance_pct, 2) }}<small v-if="reading.distance_pct != null">%</small></dd><span>{{ distanceLabel }}<template v-if="reference?.value"> · ${{ fixed(reference.value, 2) }}</template></span></div>
      <div><dt>GEX magnitude change</dt><dd :data-tone="tone(reading.gex_magnitude_change_1d_pct)">{{ signed(reading.gex_magnitude_change_1d_pct) }}</dd><span>{{ comparisonNote }}<template v-if="reading.gex_sign_changed"> · sign changed</template></span></div>
      <div><dt>Top-three streak</dt><dd>{{ reading.top_three_streak_sessions ?? '—' }}<small v-if="reading.top_three_streak_sessions">{{ reading.top_three_streak_sessions === 1 ? ' session' : ' sessions' }}</small></dd><span>Comparable sessions only · up to 5</span></div>
    </dl>
    <WallFlowDetails :flow="reading.wall_flow" />
    <div class="expiry-head"><div><h4>What drives this wall</h4><p>{{ reading.distinct_expiry_count }} contributing {{ reading.distinct_expiry_count === 1 ? 'expiry' : 'expiries' }}</p></div><strong v-if="reading.dominant_expiry">{{ share(reading.dominant_expiry_share_pct) }}%<small>{{ shortDate(reading.dominant_expiry) }}</small></strong></div>
    <div class="expiry-bars" aria-label="Largest expiry contributions">
      <div v-for="row in reading.expiry_contributions.slice(0, 3)" :key="row.expiry" class="expiry-row">
        <span>{{ shortDate(row.expiry) }}</span><div class="expiry-track"><i :data-tone="tone(row.net_gex)" :style="{ width: `${row.share_pct ?? 0}%` }"></i></div><b>{{ share(row.share_pct) }}%</b>
      </div>
    </div>
    <p class="metric-note">Share of absolute expiry exposure at this strike.</p>
    <details class="wall-analysis-details">
      <summary>Expiry breakdown and five-session history</summary>
      <div class="analysis-content">
        <h4>Five-session exposure at strike {{ reading.strike }}</h4><p class="metric-note">Tracks this selected strike using the current expiration set. It does not track which strike was the leading wall each day.</p>
        <p v-if="scopeChanged" class="history-context">The expiration set differs on some earlier dates. Those days are marked “Different expirations” rather than compared with the current basket.</p>
        <div class="history-bars" aria-label="Five-session net GEX history">
          <div v-for="point in points" :key="point.date" class="history-day">
            <b :data-tone="tone(point.net_gex)">{{ exposure(point.net_gex) }}</b>
            <div class="history-track"><i v-if="point.net_gex != null" :data-tone="tone(point.net_gex)" :style="{ height: `${Math.max(2, Math.abs(point.net_gex) / maxGex * 100)}%` }"></i><span v-else>—</span></div>
            <span>{{ shortDate(point.date) }}</span>
            <span v-if="pointLabel(point)" class="history-status">{{ pointLabel(point) }}</span>
          </div>
        </div>
        <p class="metric-note">Bar height shows exposure magnitude; color and sign show positive or negative net GEX. A dash means not compared, never zero exposure.</p>
        <p v-if="referenceOnly" class="metric-note">Current level matches the headline. It is shown for reference; daily change and streak require a like-for-like comparison.</p>
        <p v-if="reading.oi_change_1d != null" class="oi-change">Combined call + put open-interest change since {{ shortDate(reading.comparison_date) }} <strong>{{ exposure(reading.oi_change_1d) }}</strong> contracts</p>
        <p v-else class="oi-change">Open-interest change is shown when the two sessions can be compared like for like.</p>
        <div class="expiry-table-scroll" tabindex="0" aria-label="Expiry contribution table">
          <table><caption>All expiry contributions · USD per 1% move</caption><thead><tr><th scope="col">Expiry</th><th scope="col">Net GEX</th><th scope="col">Share</th></tr></thead><tbody><tr v-for="row in reading.expiry_contributions" :key="row.expiry"><th scope="row">{{ row.expiry }}</th><td :data-tone="tone(row.net_gex)">{{ exposure(row.net_gex) }}</td><td>{{ share(row.share_pct, 1) }}%</td></tr></tbody></table>
        </div>
      </div>
    </details>
  </div>
</template>

<style scoped>
.history-context{font-size:12px;line-height:1.6;margin-top:10px;padding:10px 12px;border-left:2px solid #93bdfa;background:#222e40;color:#c4d7ee}.history-status{display:block;margin-top:5px;color:#b9cbe2;font-size:10px;line-height:1.35;overflow-wrap:anywhere}.history-day{min-width:0}
.wall-intelligence{margin-top:20px;color:#e8edf5}.wall-metrics{display:grid;grid-template-columns:1fr 1fr;gap:18px 12px;border-top:1px solid #39475b;border-bottom:1px solid #39475b;padding:18px 0}.wall-metrics dt{font-size:12px;color:#bfcbdb}.wall-metrics dd{font-size:24px;font-weight:650;font-variant-numeric:tabular-nums;margin:5px 0}.wall-metrics small{font-size:12px;font-weight:500}.wall-metrics span,.metric-note,.expiry-head p{font-size:11px;color:#afbed0;line-height:1.6}.accent{color:#a2ccff}[data-tone=positive]{color:#8ce0b8}[data-tone=negative]{color:#f0a38f}.expiry-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:20px 0 14px}h4{font-size:13px;font-weight:650}.expiry-head strong{font-size:23px;color:#a2ccff;text-align:right}.expiry-head small{display:block;font-size:11px;color:#afbed0;font-weight:500}.expiry-row{display:grid;grid-template-columns:50px 1fr 38px;align-items:center;gap:12px;font-size:11px;margin:9px 0}.expiry-row b{text-align:right;font-weight:550}.expiry-track{height:7px;background:#2c3849;border-radius:5px;overflow:hidden}.expiry-track i{display:block;height:100%;border-radius:5px;transition:width .2s ease}.expiry-track i[data-tone=positive],.history-track i[data-tone=positive]{background:#8ce0b8}.expiry-track i[data-tone=negative],.history-track i[data-tone=negative]{background:#f0a38f}.history-track i[data-tone=neutral]{background:#a2ccff}.wall-analysis-details{border-top:1px solid #39475b;margin-top:17px;padding-top:12px}.wall-analysis-details summary{font-size:12px;color:#a8cfff;cursor:pointer;padding:6px 0;min-height:32px}.analysis-content{padding-top:12px}.history-bars{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;margin:18px 0 10px}.history-day{text-align:center;font-size:10px}.history-day b{font-size:11px;font-variant-numeric:tabular-nums}.history-track{height:55px;display:flex;align-items:flex-end;justify-content:center;margin:8px 0;border-bottom:1px solid #61728b}.history-track i{display:block;width:55%;border-radius:3px 3px 0 0}.oi-change{font-size:12px;margin:16px 0}.oi-change strong{margin-left:7px}.expiry-table-scroll{overflow-x:auto;max-height:250px}table{width:100%;border-collapse:collapse;font-size:12px}caption{text-align:left;font-size:11px;color:#afbed0;padding:8px 0}th,td{padding:9px 5px;border-bottom:1px solid #354257;text-align:right;white-space:nowrap}th:first-child{text-align:left}th{font-weight:500;color:#b5c6dc}summary:focus-visible,.expiry-table-scroll:focus-visible{outline:2px solid #a0ccff;outline-offset:3px}@media(prefers-reduced-motion:reduce){.expiry-track i{transition:none}}@media(max-width:400px){.wall-metrics dd{font-size:21px}.wall-metrics{gap:16px 8px}.history-day b{font-size:10px}}
</style>
