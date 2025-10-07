<template>
  <Card title="VRP" :asOf="date ? `EOD: ${date}` : ''"
        subtitle="IV(1M) – RV(20)">
    <InfoTip
      tip="IV(1M): ATM implied vol ~21 trading days forward.
        RV(20): past 20d realized (annualized).
        VRP > 0 ⇒ IV rich vs realized (edge to selling premium).
        VRP < 0 ⇒ IV cheap vs realized (edge to buying).">
      <span class="text-[11px] text-gray-400">Positive = IV rich.</span>
    </InfoTip>

    <div class="grid grid-cols-3 gap-3 text-center mt-3">
      <div><div class="text-xs text-gray-400">IV(1M)</div><div class="text-lg">{{ pct(iv1m) }}</div></div>
      <div><div class="text-xs text-gray-400">RV(20)</div><div class="text-lg">{{ pct(rv20) }}</div></div>
      <div><div class="text-xs text-gray-400">VRP</div><div class="text-lg">{{ pct(vrp) }}</div></div>
    </div>

    <div class="mt-3 text-center">
      <span class="px-3 py-1 rounded-full text-sm" :class="badgeClass" :title="hint">{{ badgeText }}</span>
      <div class="text-xs text-gray-400 mt-1">z: {{ num(z) }}</div>
    </div>

    <HowTo>
      <ul class="list-disc ml-4 space-y-1">
        <li><b>VRP ≥ +1σ</b>: favor short credit (iron condors, short strangles, credit spreads), calendars/diagonals where carry is positive.</li>
        <li><b>VRP ≤ −1σ</b>: favor long debit (calls/puts), broken-wing butterflies, long calendars to own gamma/vega.</li>
        <li>Combine with <i>Term</i>: upward term (contango) + positive VRP → classic premium-selling environment.</li>
        <li>Combine with <i>Momentum</i>: if trend is weak, short premium is safer; if trend is strong, prefer defined risk.</li>
      </ul>
      <p><b>Example:</b> IV(1M)=24%, RV(20)=14% → VRP=+10%. z=+1.4 ⇒ consider 20–30Δ iron condor 2–4w out; take profit at 30–50%.</p>
    </HowTo>
  </Card>
</template>

<script setup>
import { computed } from 'vue'
import Card from '../Components/Card.vue'
import InfoTip from '../Components/InfoTip.vue'
import HowTo from '../Components/HowTo.vue'

const props = defineProps({ date:String, iv1m:Number, rv20:Number, vrp:Number, z:Number })
const pct = v => v==null ? '—' : `${(v*100).toFixed(1)}%`
const num = v => v==null ? '—' : v.toFixed(2)
const badgeText  = computed(()=> props.z>=1 ? 'SELL premium' : (props.z<=-1 ? 'BUY premium' : 'Neutral'))
const badgeClass = computed(()=> props.z>=1 ? 'bg-green-700' : (props.z<=-1 ? 'bg-blue-700' : 'bg-gray-700'))
const hint = computed(()=> props.z>=1 ? 'IV rich vs realized → sell credit / positive carry'
  : (props.z<=-1 ? 'IV cheap vs realized → own gamma/vega' : 'Little edge; be selective'))
</script>
