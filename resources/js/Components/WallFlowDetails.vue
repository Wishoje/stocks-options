<script setup>
import { computed } from 'vue'
import { compact, dateLabel } from './UI/numbers'
const props = defineProps({ flow: Object })
const side = computed(() => props.flow?.side === 'put' ? 'Put' : 'Call')
const state = computed(() => ({ building: 'Building', stable: 'Stable', unwinding: 'Unwinding' }[props.flow?.wall_build_state] || 'Compare daily OI'))
const explanation = computed(() => ({
  building: `More ${side.value.toLowerCase()} contracts remain open at this strike.`,
  stable: `${side.value} open interest is within ${props.flow?.stable_band_pct}% of the prior session.`,
  unwinding: `Fewer ${side.value.toLowerCase()} contracts remain open at this strike.`,
}[props.flow?.wall_build_state] || 'Daily changes appear when the same strike and expirations can be compared.'))
const tone = value => value > 0 ? 'positive' : value < 0 ? 'negative' : 'neutral'
const contracts = value => value == null ? '—' : compact(value)
const total = value => contracts(value).replace(/^\+/, '')
const pct = value => value == null ? null : `${value > 0 ? '+' : ''}${Number(value).toLocaleString('en-US', { maximumFractionDigits: 1 })}%`
const periods = computed(() => [
  { label: 'Daily OI change', ...props.flow?.daily },
  { label: 'Five-session OI change', ...props.flow?.five_session },
])
const drivers = computed(() => (props.flow?.expiry_contributions ?? []).filter(row => row.change_1d != null && row.change_1d !== 0).slice(0, 3))
</script>

<template>
  <section v-if="flow" class="wall-flow" :aria-label="`${side} wall build and unwind`">
    <div class="flow-heading"><h4>{{ side }} open interest</h4><span class="flow-state" :data-state="flow.wall_build_state">{{ state }}</span></div>
    <p class="flow-explanation">{{ explanation }}</p>
    <dl class="flow-metrics">
      <div v-for="period in periods" :key="period.label">
        <dt>{{ period.label }}</dt>
        <dd :data-tone="tone(period.change)">{{ contracts(period.change) }}<small v-if="period.change != null"> contracts</small></dd>
        <p v-if="period.comparable"><strong v-if="pct(period.change_pct)">{{ pct(period.change_pct) }} · </strong><span v-else-if="period.baseline_oi === 0">From zero OI · </span>since {{ dateLabel(period.baseline_date) }}</p>
        <p v-else>Awaiting a matching comparison</p>
      </div>
    </dl>
    <p class="flow-current">{{ side }} OI at {{ flow.strike }}: <strong>{{ total(flow.open_interest) }}</strong> contracts · {{ dateLabel(flow.data_date) }}</p>
    <div v-if="drivers.length" class="flow-drivers" aria-label="Largest daily OI changes by expiry">
      <p>Largest daily changes by expiration</p>
      <div v-for="row in drivers" :key="row.expiry"><span>{{ dateLabel(row.expiry) }}</span><strong :data-tone="tone(row.change_1d)">{{ contracts(row.change_1d) }}</strong></div>
    </div>
    <details class="flow-details">
      <summary>How to read OI changes and activity</summary>
      <div class="flow-guide">
        <p><strong>Building / Stable / Unwinding</strong> describe {{ side.toLowerCase() }} open interest at this strike. Daily growth above +{{ flow.stable_band_pct }}% is Building; a decline below −{{ flow.stable_band_pct }}% is Unwinding. Changes within ±{{ flow.stable_band_pct }}%, including the boundaries, are Stable.</p>
        <p>Read daily change alongside five-session change. A daily increase with a five-session decline means a recent pickup within a longer decline. The label always follows the daily comparison.</p>
        <p>Both comparisons keep the strike, option side and expiration basket fixed. Five sessions means five trading sessions earlier. The exposure history below displays five dates, so its oldest bar is one session more recent than this OI baseline. From an observed zero baseline, new OI is Building and the percentage is left blank.</p>
        <p>OI counts contracts still open. It does not reveal who bought or sold them. Building does not mean the wall will hold; Unwinding does not mean it will break. These labels describe EOD positioning, and are separate from GEX changes and intraday price events.</p>
        <dl class="flow-activity"><div><dt>EOD call volume</dt><dd>{{ total(flow.activity?.call_volume) }}</dd></div><div><dt>EOD put volume</dt><dd>{{ total(flow.activity?.put_volume) }}</dd></div></dl>
        <p>Volume is traded contracts at this strike across the selected expirations for {{ dateLabel(flow.data_date) }}. It can include opening and closing trades, so it does not determine the OI label.</p>
        <div class="flow-table" tabindex="0" aria-label="Open-interest changes by expiration">
          <table><caption>{{ side }} OI by expiration · contracts</caption><thead><tr><th scope="col">Expiration</th><th scope="col">Current OI</th><th scope="col">Daily Δ</th><th scope="col">5-session Δ</th></tr></thead><tbody><tr v-for="row in flow.expiry_contributions" :key="row.expiry"><th scope="row">{{ row.expiry }}</th><td>{{ total(row.open_interest) }}</td><td :data-tone="tone(row.change_1d)">{{ contracts(row.change_1d) }}</td><td :data-tone="tone(row.change_5d)">{{ contracts(row.change_5d) }}</td></tr></tbody></table>
        </div>
      </div>
    </details>
  </section>
</template>

<style scoped>
.wall-flow{margin:18px 0;padding:16px;border:1px solid #39475b;border-radius:9px;background:#192431;color:#e8edf5}.flow-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}h4{font-size:14px;font-weight:650}.flow-state{font-size:12px;font-weight:600;border:1px solid #54718c;border-radius:20px;padding:4px 9px;color:#b9d8fa}.flow-state[data-state=building]{border-color:#477860;color:#8ce0b8}.flow-state[data-state=unwinding]{border-color:#97695e;color:#f0a38f}.flow-explanation{font-size:12px;color:#c2d1e2;line-height:1.6;margin:10px 0 14px}.flow-metrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.flow-metrics dt,.flow-activity dt{font-size:12px;color:#bdccde}.flow-metrics dd{font-size:24px;font-weight:650;font-variant-numeric:tabular-nums;margin:5px 0}.flow-metrics small{font-size:11px;font-weight:400;white-space:nowrap}.flow-metrics p,.flow-current{font-size:11px;color:#b3c5db;line-height:1.6}.flow-current{margin-top:14px}.flow-metrics p strong{font-weight:550}[data-tone=positive]{color:#8ce0b8}[data-tone=negative]{color:#f0a38f}.flow-drivers{margin-top:14px;border-top:1px solid #354257;padding-top:12px;font-size:12px}.flow-drivers>p{color:#b3c5db;margin-bottom:6px}.flow-drivers>div{display:flex;justify-content:space-between;gap:16px;padding:4px 0}.flow-drivers strong{font-weight:550}.flow-details{margin-top:14px}.flow-details summary{cursor:pointer;color:#acd0fc;font-size:12px;padding:6px 0;min-height:32px}.flow-guide{font-size:12px;color:#bdccde;line-height:1.65}.flow-guide p{margin:10px 0}.flow-guide strong{font-weight:600;color:#e8edf5}.flow-activity{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:12px 0;border-top:1px solid #354257}.flow-activity dd{font-size:18px;color:#e8edf5;margin-top:5px}.flow-table{overflow:auto;max-height:250px}table{width:100%;border-collapse:collapse;font-size:11px}caption{text-align:left;padding:8px 0}th,td{padding:9px 6px;border-bottom:1px solid #354257;text-align:right;white-space:nowrap}th:first-child{text-align:left}th{font-weight:500}summary:focus-visible,.flow-table:focus-visible{outline:2px solid #a0ccff;outline-offset:3px}@media(max-width:440px){.wall-flow{padding:12px}.flow-metrics{gap:12px}.flow-metrics dd{font-size:20px}.flow-metrics small{display:block}}
</style>
