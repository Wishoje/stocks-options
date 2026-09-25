<script setup>
import { computed } from 'vue'
import UiBadge from '../UI/UiBadge.vue'
import UiButton from '../UI/UiButton.vue'
import UiHelpDialog from '../UI/UiHelpDialog.vue'
import UiPanel from '../UI/UiPanel.vue'
import UiSelect from '../UI/UiSelect.vue'
import UiStatus from '../UI/UiStatus.vue'

const props = defineProps({
  id: { type: String, required: true },
  title: { type: String, required: true },
  subtitle: String,
  helpTitle: { type: String, required: true },
  symbol: String,
  timeframe: String,
  snapshotDate: String,
  comparisonBasis: String,
  comparisonDate: String,
  comparisonGapTradingDays: [Number, String],
  comparisonIsStale: Boolean,
  rawCount: { type: Number, default: 0 },
  validCount: { type: Number, default: 0 },
  displayCount: { type: Number, default: 0 },
  hasChartData: Boolean,
  focusActivity: Boolean,
  autoBucket: Boolean,
  selectedValue: [String, Number],
  selectedOptions: { type: Array, default: () => [] },
  emptyTitle: { type: String, default: 'No strike readings' },
  emptyMessage: { type: String, default: 'No usable values are available for this chart.' },
  detailsLabel: { type: String, default: 'All strike readings' },
})

const emit = defineEmits([
  'update:focusActivity',
  'update:autoBucket',
  'update:selectedValue',
  'resetZoom',
  'download',
])

const comparisonLabel = computed(() => {
  if (!props.comparisonBasis) return null
  if (props.comparisonBasis === 'weekly') return 'Weekly comparison'
  if (props.comparisonBasis === 'daily') return 'Daily comparison'
  return 'Comparison unavailable'
})
const comparisonText = computed(() => {
  if (!comparisonLabel.value) return null
  if (props.comparisonBasis !== 'daily' && props.comparisonBasis !== 'weekly') {
    return 'No dated comparison source is available'
  }
  if (!props.comparisonDate) return `${comparisonLabel.value}; source date unavailable`
  const gap = props.comparisonGapTradingDays == null
    ? ''
    : ` · ${props.comparisonGapTradingDays} trading day${Number(props.comparisonGapTradingDays) === 1 ? '' : 's'} back`
  return `${comparisonLabel.value} against ${props.comparisonDate}${gap}`
})
</script>

<template>
  <UiPanel :title="title" :subtitle="subtitle" tone="data" :data-testid="id">
    <template #actions>
      <div class="gex-row">
        <UiBadge v-if="symbol" tone="data">{{ symbol }}</UiBadge>
        <UiBadge v-if="snapshotDate" tone="data">Data as of {{ snapshotDate }}</UiBadge>
        <UiBadge v-if="timeframe" tone="neutral">{{ timeframe }}</UiBadge>
        <UiBadge v-if="comparisonLabel" :tone="comparisonIsStale ? 'warning' : 'neutral'">{{ comparisonLabel }}</UiBadge>
        <UiHelpDialog :id="`${id}-guide`" :title="helpTitle" trigger-label="Reading guide">
          <slot name="help" />
        </UiHelpDialog>
      </div>
    </template>

    <slot name="metrics" />

    <section class="strike-chart-frame" :aria-label="`${title} chart controls and plot`">
      <div class="strike-chart-frame__heading">
        <div>
          <h3>Strike distribution</h3>
          <p>{{ displayCount }} displayed · {{ validCount }} numeric strikes · {{ rawCount }} raw readings</p>
          <p v-if="comparisonText" :data-stale="comparisonIsStale ? 'true' : undefined">{{ comparisonText }}</p>
        </div>
        <div class="strike-chart-frame__actions">
          <UiButton :disabled="!hasChartData" @click="emit('resetZoom')">Reset zoom</UiButton>
          <UiButton :disabled="!hasChartData" @click="emit('download')">Download PNG</UiButton>
        </div>
      </div>

      <div class="strike-chart-frame__controls">
        <label class="gex-check">
          <input
            type="checkbox"
            :checked="focusActivity"
            @change="emit('update:focusActivity', $event.target.checked)"
          />
          <span>Focus on activity</span>
        </label>
        <label class="gex-check">
          <input
            type="checkbox"
            :checked="autoBucket"
            @change="emit('update:autoBucket', $event.target.checked)"
          />
          <span>Group dense strikes</span>
        </label>
        <slot name="controls" />
      </div>

      <div v-if="selectedOptions.length" class="strike-chart-frame__selection">
        <UiSelect
          label="Inspect strike"
          :model-value="selectedValue"
          :options="selectedOptions"
          @update:model-value="emit('update:selectedValue', $event)"
        />
        <div class="strike-chart-frame__selected" aria-live="polite">
          <slot name="selection" />
        </div>
      </div>

      <UiStatus
        v-if="!hasChartData"
        state="sparse"
        :title="emptyTitle"
        :message="emptyMessage"
      />
      <div v-else class="strike-chart-frame__canvas">
        <slot name="chart" />
      </div>
      <p v-if="hasChartData" class="strike-chart-frame__hint">Click a bar to inspect it. Pan horizontally, use the mouse wheel, or pinch to zoom; Reset zoom restores the full displayed range.</p>
    </section>

    <details class="strike-chart-frame__details" :data-testid="`${id}-readings`">
      <summary>{{ detailsLabel }} · {{ rawCount }} readings</summary>
      <slot name="details" />
    </details>
  </UiPanel>
</template>

<style scoped>
.strike-chart-frame {
  min-width: 0;
  margin-top: 20px;
  border: 1px solid var(--gex-border);
  border-radius: 12px;
  padding: 16px;
  background: color-mix(in srgb, var(--gex-raised) 42%, transparent);
}

.strike-chart-frame__heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}

.strike-chart-frame__heading p,
.strike-chart-frame__hint {
  margin-top: 3px;
  color: var(--gex-muted);
  font-size: 12px;
}

.strike-chart-frame__heading p[data-stale="true"] {
  color: var(--gex-warning);
}

.strike-chart-frame__actions,
.strike-chart-frame__controls {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 9px;
}

.strike-chart-frame__controls {
  margin-top: 13px;
  color: var(--gex-muted);
  font-size: 12px;
}

.strike-chart-frame__selection {
  display: grid;
  grid-template-columns: minmax(150px, 220px) minmax(0, 1fr);
  align-items: end;
  gap: 14px;
  margin-top: 14px;
  border-top: 1px solid var(--gex-border);
  padding-top: 14px;
}

.strike-chart-frame__selected {
  min-height: 38px;
  color: var(--gex-muted);
  font-size: 12px;
}

.strike-chart-frame__selected :deep(strong) {
  color: var(--gex-text);
}

.strike-chart-frame__canvas {
  margin-top: 14px;
}

.strike-chart-frame__hint {
  margin-top: 10px;
}

.strike-chart-frame__details {
  margin-top: 12px;
}

@media (max-width: 600px) {
  .strike-chart-frame {
    padding: 12px;
  }

  .strike-chart-frame__actions,
  .strike-chart-frame__controls {
    width: 100%;
  }

  .strike-chart-frame__actions > *,
  .strike-chart-frame__controls > :deep(*) {
    flex: 1 1 auto;
  }

  .strike-chart-frame__selection {
    grid-template-columns: 1fr;
  }
}

@media (prefers-reduced-motion: reduce) {
  .strike-chart-frame *,
  .strike-chart-frame *::before,
  .strike-chart-frame *::after {
    scroll-behavior: auto !important;
  }
}
</style>
