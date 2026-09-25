<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import UiBadge from './UI/UiBadge.vue'
import UiHelpDialog from './UI/UiHelpDialog.vue'
import UiMetric from './UI/UiMetric.vue'
import UiPanel from './UI/UiPanel.vue'
import UiStatus from './UI/UiStatus.vue'
import { numeric } from './UI/numbers.js'

const props = defineProps({
  date: String,
  d1: [Number, String],
  d2: [Number, String],
  d3: [Number, String],
  d4: [Number, String],
  d5: [Number, String],
  cum5: [Number, String],
  z: [Number, String],
  note: String,
})

const emit = defineEmits(['reading-inspected'])

const selectedDay = ref(0)
const dayList = ref(null)

const dailyItems = computed(() => [props.d1, props.d2, props.d3, props.d4, props.d5].map((rawValue, index) => ({
  day: index + 1,
  label: `D${index + 1}`,
  raw_value: rawValue,
  value: numeric(rawValue),
  percent: numeric(rawValue) == null ? null : numeric(rawValue) * 100,
})))
const cumulativeValue = computed(() => numeric(props.cum5))
const zValue = computed(() => numeric(props.z))
const hasData = computed(() => [...dailyItems.value.map(item => item.value), cumulativeValue.value].some(value => value != null))
const selectedItem = computed(() => dailyItems.value[selectedDay.value] ?? null)
const maxMagnitude = computed(() => Math.max(0.1, ...dailyItems.value.map(item => Math.abs(item.percent ?? 0))))

watch(() => [props.d1, props.d2, props.d3, props.d4, props.d5], () => {
  if (dailyItems.value[selectedDay.value]?.value != null) return
  const firstAvailable = dailyItems.value.findIndex(item => item.value != null)
  selectedDay.value = firstAvailable >= 0 ? firstAvailable : 0
}, { immediate: true })

function percent(value, { signed = true } = {}) {
  const number = numeric(value)
  if (number == null) return null
  const scaled = number * 100
  const magnitude = Math.abs(scaled).toFixed(1)
  if (Number(magnitude) === 0) return '0.0'
  const sign = signed ? (scaled > 0 ? '+' : '−') : (scaled < 0 ? '−' : '')
  return `${sign}${magnitude}`
}

function sigma(value) {
  const number = numeric(value)
  if (number == null) return null
  if (number === 0) return '0.00σ'
  return `${number > 0 ? '+' : '−'}${Math.abs(number).toFixed(2)}σ`
}

function valueTone(value) {
  const number = numeric(value)
  if (number == null || Number(Math.abs(number * 100).toFixed(1)) === 0) return 'data'
  return number > 0 ? 'positive' : 'negative'
}

const regime = computed(() => {
  if (zValue.value == null) {
    return {
      label: 'Signal unavailable',
      tone: 'warning',
      context: 'A historical z-score is needed to classify this seasonal sample.',
    }
  }
  if (zValue.value >= 1) {
    return {
      label: 'Bullish tailwind',
      tone: 'positive',
      context: 'The seasonal five-session return is at least one standard deviation above its unconditional distribution.',
    }
  }
  if (zValue.value <= -1) {
    return {
      label: 'Bearish headwind',
      tone: 'negative',
      context: 'The seasonal five-session return is at least one standard deviation below its unconditional distribution.',
    }
  }
  return {
    label: 'Neutral seasonal',
    tone: 'neutral',
    context: 'The seasonal five-session return is within one standard deviation of its unconditional distribution.',
  }
})

function barStyle(item) {
  if (item.percent == null) return {}
  const width = (Math.abs(item.percent) / maxMagnitude.value) * 50
  return {
    left: item.percent < 0 ? `${50 - width}%` : '50%',
    width: `${width}%`,
  }
}

function selectDay(index, { focus = false } = {}) {
  if (!dailyItems.value[index]) return
  selectedDay.value = index
  if (dailyItems.value[index].value != null) emit('reading-inspected')
  if (focus) nextTick(() => dayList.value?.querySelectorAll('button')?.[index]?.focus())
}

function handleDayKey(event, index) {
  let next = index
  if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % dailyItems.value.length
  else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (index - 1 + dailyItems.value.length) % dailyItems.value.length
  else if (event.key === 'Home') next = 0
  else if (event.key === 'End') next = dailyItems.value.length - 1
  else return
  event.preventDefault()
  selectDay(next, { focus: true })
}
</script>

<template>
  <UiPanel
    title="Five-session seasonality"
    subtitle="Historical average forward returns over the next five sessions"
    tone="data"
    data-testid="volatility-seasonality"
  >
    <template #actions>
      <div class="gex-row">
        <UiBadge v-if="date" tone="data">Data as of {{ date }}</UiBadge>
        <UiBadge :tone="regime.tone">{{ regime.label }}</UiBadge>
        <UiHelpDialog
          id="volatility-seasonality-guide"
          title="How to read five-session seasonality"
          trigger-label="Reading guide"
        >
          <div class="gex-stack">
            <p><strong>D1 through D5</strong> are historical average returns for each forward session. <strong>Cumulative 5D</strong> is the historical average return from the starting close through session five.</p>
            <p>The daily values and cumulative return are calculated independently from historical prices, so rounded daily values do not need to add exactly to the displayed cumulative result.</p>
            <p>The <strong>z-score</strong> compares the average cumulative return with the unconditional five-session return distribution. Values at or beyond ±1σ receive a directional label.</p>
            <p>Seasonality is a historical tendency rather than a forecast. Use it as context with volatility pricing, positioning, trend, and current events.</p>
          </div>
        </UiHelpDialog>
      </div>
    </template>

    <template v-if="hasData">
      <div class="gex-grid seasonality-summary">
        <UiMetric
          label="Cumulative 5D"
          :value="percent(cumulativeValue)"
          unit="%"
          :tone="valueTone(cumulativeValue)"
          prominence="primary"
          context="Historical average return through the fifth forward session"
        />
        <UiMetric
          label="Seasonal regime"
          :value="regime.label"
          :tone="regime.tone"
          prominence="primary"
          :context="regime.context"
        />
        <UiMetric
          label="Historical position"
          :value="sigma(zValue)"
          tone="data"
          context="Cumulative 5D return versus the unconditional distribution"
        />
      </div>

      <section class="seasonality-chart" aria-label="Average forward return by session">
        <div class="gex-chart-heading">
          <div>
            <h3>Forward return path</h3>
            <p>{{ dailyItems.filter(item => item.value != null).length }} of 5 session readings available</p>
          </div>
          <output class="gex-chart-value gex-number" aria-live="polite">
            <span>{{ selectedItem?.label ?? 'No session' }}</span>
            <strong>{{ selectedItem?.value == null ? 'Unavailable' : `${percent(selectedItem.value)}% average` }}</strong>
          </output>
        </div>

        <div class="gex-legend" aria-label="Seasonality chart legend">
          <span><i class="gex-swatch" aria-hidden="true" />Positive</span>
          <span><i class="gex-swatch" data-negative="true" aria-hidden="true" />Negative</span>
          <span><i class="gex-swatch" data-zero="true" aria-hidden="true" />Zero baseline</span>
        </div>

        <div ref="dayList" class="seasonality-days" role="group" aria-label="Forward session readings">
          <button
            v-for="(item, index) in dailyItems"
            :key="item.day"
            type="button"
            class="seasonality-day"
            :aria-pressed="selectedDay === index"
            :aria-label="`${item.label}, ${item.value == null ? 'return unavailable' : `${percent(item.value)} percent average return`}`"
            :tabindex="selectedDay === index ? 0 : -1"
            @click="selectDay(index)"
            @keydown="handleDayKey($event, index)"
          >
            <strong>{{ item.label }}</strong>
            <span class="seasonality-track" aria-hidden="true">
              <i class="seasonality-zero" />
              <i
                v-if="item.percent != null"
                class="seasonality-bar"
                :data-negative="item.percent < 0 ? 'true' : undefined"
                :data-zero="item.percent === 0 ? 'true' : undefined"
                :style="barStyle(item)"
              />
              <em v-else aria-label="No reading">—</em>
            </span>
            <span class="gex-number">{{ item.value == null ? 'Unavailable' : `${percent(item.value)}%` }}</span>
          </button>
        </div>
      </section>

      <aside v-if="note" class="seasonality-note">
        <strong>Sample context</strong>
        <span>{{ note }}</span>
      </aside>

      <details class="seasonality-calculation" data-testid="seasonality-calculation-disclosure">
        <summary>Calculation details</summary>
        <p>Each D1–D5 value averages that forward session across qualifying historical anchors. Cumulative 5D averages the full close-to-close five-session move. The sample context shows the historical period used for comparison.</p>
      </details>
    </template>

    <UiStatus
      v-else
      state="sparse"
      title="No seasonality data"
      :message="note || 'No usable five-session return readings are available for this data set.'"
    />
  </UiPanel>
</template>

<style scoped>
.seasonality-summary {
  margin-bottom: 20px;
}

.seasonality-chart {
  min-width: 0;
  border: 1px solid var(--gex-border);
  border-radius: 12px;
  padding: 16px;
  background: color-mix(in srgb, var(--gex-raised) 42%, transparent);
}

.seasonality-days {
  display: grid;
  gap: 5px;
  margin-top: 14px;
}

.seasonality-day {
  display: grid;
  grid-template-columns: 42px minmax(80px, 1fr) 94px;
  align-items: center;
  gap: 10px;
  width: 100%;
  min-height: 44px;
  border: 0;
  border-radius: 8px;
  padding: 7px 9px;
  background: transparent;
  color: var(--gex-text);
  cursor: pointer;
  text-align: left;
  transition: background-color var(--gex-duration) var(--gex-ease), box-shadow var(--gex-duration) var(--gex-ease);
}

.seasonality-day:hover {
  background: var(--gex-raised);
}

.seasonality-day[aria-pressed="true"] {
  background: var(--gex-selected);
  box-shadow: inset 3px 0 var(--gex-data), inset 0 0 0 1px color-mix(in srgb, var(--gex-data) 40%, transparent);
}

.seasonality-day > .gex-number {
  color: var(--gex-muted);
  text-align: right;
}

.seasonality-day[aria-pressed="true"] > strong,
.seasonality-day[aria-pressed="true"] > .gex-number {
  color: var(--gex-data);
}

.seasonality-track {
  position: relative;
  display: block;
  height: 22px;
  border-radius: 6px;
  background: linear-gradient(90deg, color-mix(in srgb, var(--gex-negative) 7%, transparent) 0 50%, color-mix(in srgb, var(--gex-positive) 7%, transparent) 50% 100%);
}

.seasonality-zero {
  position: absolute;
  top: -4px;
  bottom: -4px;
  left: 50%;
  width: 2px;
  background: color-mix(in srgb, var(--gex-muted) 78%, var(--gex-border));
}

.seasonality-bar {
  position: absolute;
  top: 6px;
  height: 10px;
  min-width: 3px;
  border-radius: 999px;
  background: var(--gex-positive);
}

.seasonality-bar[data-negative="true"] {
  background: var(--gex-negative);
}

.seasonality-bar[data-zero="true"] {
  left: calc(50% - 1px) !important;
  width: 3px !important;
  min-width: 0;
  background: var(--gex-data);
}

.seasonality-track em {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  border: 1px dashed var(--gex-border);
  border-radius: 6px;
  color: var(--gex-muted);
  font-size: 9px;
  font-style: normal;
}

.seasonality-note {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  margin-top: 14px;
  border-left: 2px solid var(--gex-data);
  padding: 10px 12px;
  color: var(--gex-muted);
  font-size: 12px;
}

.seasonality-note strong {
  flex: 0 0 auto;
  color: var(--gex-text);
}

.seasonality-calculation {
  margin-top: 12px;
}

.seasonality-calculation p {
  padding: 12px 14px;
  border-radius: 9px;
  background: var(--gex-raised);
  color: var(--gex-muted);
  font-size: 12px;
}

@media (max-width: 600px) {
  .seasonality-chart {
    padding: 12px;
  }

  .seasonality-day {
    grid-template-columns: 34px minmax(64px, 1fr) 76px;
    gap: 7px;
    padding-inline: 6px;
    font-size: 12px;
  }

  .seasonality-note {
    display: grid;
  }
}

@media (prefers-reduced-motion: reduce) {
  .seasonality-day {
    transition: none;
  }
}
</style>
