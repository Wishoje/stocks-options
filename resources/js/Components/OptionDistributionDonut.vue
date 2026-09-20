<script setup>
import { computed, ref, watch } from 'vue'
import { Doughnut } from 'vue-chartjs'
import { ArcElement, Chart, Tooltip } from 'chart.js'
import UiPanel from './UI/UiPanel.vue'
import UiStatus from './UI/UiStatus.vue'
import { numeric } from './UI/numbers.js'

Chart.register(ArcElement, Tooltip)

const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, required: true },
  totalLabel: { type: String, required: true },
  callValue: { type: [Number, String], default: null },
  putValue: { type: [Number, String], default: null },
})

const emit = defineEmits(['reading-inspected'])

const selectedSide = ref('call')
const callNumeric = computed(() => numeric(props.callValue))
const putNumeric = computed(() => numeric(props.putValue))
const inputsAvailable = computed(() => callNumeric.value !== null && putNumeric.value !== null)
const inputsValid = computed(() => inputsAvailable.value && callNumeric.value >= 0 && putNumeric.value >= 0)
const total = computed(() => inputsValid.value ? callNumeric.value + putNumeric.value : null)
const hasDistribution = computed(() => total.value !== null && total.value > 0)
const callShare = computed(() => hasDistribution.value ? callNumeric.value / total.value * 100 : (total.value === 0 ? 0 : null))
const putShare = computed(() => hasDistribution.value ? putNumeric.value / total.value * 100 : (total.value === 0 ? 0 : null))
const selectedValue = computed(() => selectedSide.value === 'call' ? callNumeric.value : putNumeric.value)
const selectedShare = computed(() => selectedSide.value === 'call' ? callShare.value : putShare.value)

function compactUnsigned(value) {
  if (value === null) return 'Unavailable'
  return new Intl.NumberFormat('en-US', {
    notation: Math.abs(value) >= 1000 ? 'compact' : 'standard',
    maximumFractionDigits: Math.abs(value) >= 1000 ? 1 : 0,
  }).format(value)
}

function percent(value) {
  return value === null ? 'Unavailable' : `${value.toFixed(1)}%`
}

function select(side) {
  if (!['call', 'put'].includes(side)) return
  const value = side === 'call' ? callNumeric.value : putNumeric.value
  if (!hasDistribution.value || value === null) return
  selectedSide.value = side
  emit('reading-inspected')
}

function onSideKeydown(event, side) {
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return
  event.preventDefault()
  const next = event.key === 'ArrowLeft' || event.key === 'Home' ? 'call' : 'put'
  select(next)
  event.currentTarget.parentElement?.querySelector(`[data-side="${next}"]`)?.focus()
}

watch([callNumeric, putNumeric], () => {
  if (selectedSide.value === 'call' && callNumeric.value === null && putNumeric.value !== null) selectedSide.value = 'put'
  if (selectedSide.value === 'put' && putNumeric.value === null && callNumeric.value !== null) selectedSide.value = 'call'
})

const chartData = computed(() => ({
  labels: ['Calls', 'Puts'],
  datasets: [{
    data: hasDistribution.value ? [callNumeric.value, putNumeric.value] : [],
    backgroundColor: ['#76c8f1', '#f09589'],
    borderColor: ['#b6e5fa', '#ffc0b7'],
    borderWidth: selectedSide.value === 'call' ? [3, 1] : [1, 3],
    hoverBorderWidth: 3,
    offset: selectedSide.value === 'call' ? [4, 0] : [0, 4],
  }],
}))

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  cutout: '72%',
  animation: false,
  events: ['mousemove', 'mouseout', 'click', 'touchstart'],
  onClick(_event, elements) {
    const index = elements?.[0]?.index
    if (index === 0 || index === 1) select(index === 0 ? 'call' : 'put')
  },
  plugins: {
    legend: { display: false },
    tooltip: {
      displayColors: true,
      callbacks: {
        label(context) {
          const share = context.dataIndex === 0 ? callShare.value : putShare.value
          return `${context.label}: ${compactUnsigned(context.raw)} contracts (${percent(share)})`
        },
      },
    },
  },
}))

defineExpose({ callNumeric, putNumeric, total, callShare, putShare, selectedSide, chartData, chartOptions })
</script>

<template>
  <UiPanel class="gex-distribution" :title="title" :subtitle="subtitle" tone="data">
    <div v-if="inputsValid && hasDistribution" class="gex-distribution__layout">
      <div class="gex-distribution__visual">
        <Doughnut
          :data="chartData"
          :options="chartOptions"
          aria-hidden="true"
        />
        <div class="gex-distribution__center" aria-hidden="true">
          <strong class="gex-number">{{ compactUnsigned(total) }}</strong>
          <span>{{ totalLabel }}</span>
        </div>
      </div>

      <div>
        <div class="gex-distribution__choices" role="group" :aria-label="`${title} series`">
          <button
            v-for="side in ['call', 'put']"
            :key="side"
            type="button"
            :data-side="side"
            :data-tone="side === 'call' ? 'data' : 'negative'"
            :aria-pressed="selectedSide === side"
            @click="select(side)"
            @keydown="onSideKeydown($event, side)"
          >
            <span><i aria-hidden="true" />{{ side === 'call' ? 'Calls' : 'Puts' }}</span>
            <strong class="gex-number">{{ compactUnsigned(side === 'call' ? callNumeric : putNumeric) }}</strong>
            <small class="gex-number">{{ percent(side === 'call' ? callShare : putShare) }}</small>
          </button>
        </div>

        <output class="gex-distribution__selected" aria-live="polite">
          <span>{{ selectedSide === 'call' ? 'Calls' : 'Puts' }}</span>
          <strong class="gex-number">{{ compactUnsigned(selectedValue) }} contracts</strong>
          <small class="gex-number">{{ percent(selectedShare) }} of {{ totalLabel.toLowerCase() }}</small>
        </output>
      </div>
    </div>

    <template v-else>
      <UiStatus
        v-if="!inputsValid"
        state="sparse"
        :title="`${title} unavailable`"
        :message="inputsAvailable ? 'The response contains an invalid negative total.' : 'Both call and put totals are required to calculate the distribution.'"
      />
      <UiStatus
        v-else
        state="empty"
        :title="`No ${totalLabel.toLowerCase()} reported`"
        message="Call and put totals are both zero for the selected expiry scope."
      />

      <dl class="gex-distribution__fallback">
        <div><dt>Calls</dt><dd class="gex-number">{{ compactUnsigned(callNumeric) }}</dd></div>
        <div><dt>Puts</dt><dd class="gex-number">{{ compactUnsigned(putNumeric) }}</dd></div>
      </dl>
    </template>

    <div
      v-if="inputsValid"
      class="gex-distribution__ratio"
      role="img"
      :aria-label="`Calls ${percent(callShare)}; puts ${percent(putShare)}`"
    >
      <i data-side="call" :style="{ width: `${callShare}%` }" aria-hidden="true" />
      <i data-side="put" :style="{ width: `${putShare}%` }" aria-hidden="true" />
    </div>
    <p class="gex-distribution__scope">Every contract in the selected expiration scope is included in these totals.</p>
  </UiPanel>
</template>

<style scoped>
.gex-distribution__layout {
  display: grid;
  grid-template-columns: minmax(150px, .72fr) minmax(190px, 1.28fr);
  align-items: center;
  gap: 20px;
}

.gex-distribution__visual {
  position: relative;
  height: 190px;
  min-width: 0;
}

.gex-distribution__center {
  position: absolute;
  inset: 50% auto auto 50%;
  display: grid;
  justify-items: center;
  max-width: 94px;
  transform: translate(-50%, -50%);
  pointer-events: none;
}

.gex-distribution__center strong {
  overflow: hidden;
  max-width: 100%;
  color: var(--gex-text);
  font-size: 20px;
  line-height: 1.1;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.gex-distribution__center span {
  margin-top: 3px;
  color: var(--gex-muted);
  font-size: 10px;
  text-align: center;
}

.gex-distribution__choices {
  display: grid;
  gap: 8px;
}

.gex-distribution__choices button {
  --series-color: var(--gex-data);
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 2px 12px;
  width: 100%;
  min-height: 52px;
  padding: 9px 11px;
  border: 1px solid var(--gex-border);
  border-radius: 9px;
  background: transparent;
  color: var(--gex-text);
  text-align: left;
  cursor: pointer;
  transition: border-color var(--gex-duration) var(--gex-ease), background-color var(--gex-duration) var(--gex-ease);
}

.gex-distribution__choices button[data-side="put"] { --series-color: var(--gex-negative); }
.gex-distribution__choices button:hover { border-color: color-mix(in srgb, var(--series-color) 55%, var(--gex-border)); }
.gex-distribution__choices button[aria-pressed="true"] {
  border-color: color-mix(in srgb, var(--series-color) 72%, var(--gex-border));
  background: color-mix(in srgb, var(--series-color) 8%, var(--gex-surface));
  box-shadow: inset 3px 0 var(--series-color);
}

.gex-distribution__choices button span {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  color: var(--gex-muted);
  font-size: 11px;
}

.gex-distribution__choices button span i {
  width: 8px;
  height: 8px;
  border-radius: 2px;
  background: var(--series-color);
}

.gex-distribution__choices button strong { font-size: 14px; }
.gex-distribution__choices button small { color: var(--series-color); text-align: right; }

.gex-distribution__selected {
  display: grid;
  gap: 2px;
  margin-top: 12px;
  padding: 11px 12px;
  border-radius: 9px;
  background: var(--gex-raised);
}

.gex-distribution__selected span,
.gex-distribution__selected small { color: var(--gex-muted); font-size: 11px; }
.gex-distribution__selected strong { color: var(--gex-text); font-size: 13px; }

.gex-distribution__ratio {
  display: flex;
  height: 6px;
  overflow: hidden;
  margin-top: 16px;
  border-radius: 999px;
  background: var(--gex-border);
}

.gex-distribution__ratio i { display: block; height: 100%; }
.gex-distribution__ratio i[data-side="call"] { background: var(--gex-data); }
.gex-distribution__ratio i[data-side="put"] { background: var(--gex-negative); }

.gex-distribution__scope {
  margin-top: 9px;
  color: var(--gex-muted);
  font-size: 11px;
}

.gex-distribution__fallback {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin: 12px 0 0;
}

.gex-distribution__fallback div {
  padding: 10px 12px;
  border: 1px solid var(--gex-border);
  border-radius: 8px;
  background: var(--gex-raised);
}

.gex-distribution__fallback dt { color: var(--gex-muted); font-size: 11px; }
.gex-distribution__fallback dd { margin: 2px 0 0; color: var(--gex-text); font-size: 14px; }

@media (max-width: 560px) {
  .gex-distribution__layout { grid-template-columns: 1fr; }
  .gex-distribution__visual { height: 180px; }
}
</style>
