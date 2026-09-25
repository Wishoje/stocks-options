<script setup>
import { computed, ref } from 'vue'
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
  sourceLabel: String,
  snapshotAsOf: String,
  sourceTimestampStatus: String,
  marketOpen: { type: Boolean, default: null },
  rawCount: { type: Number, default: 0 },
  validCount: { type: Number, default: 0 },
  displayCount: { type: Number, default: 0 },
  hasChartData: Boolean,
  focusActivity: Boolean,
  autoBucket: Boolean,
  selectedValue: [String, Number],
  selectedOptions: { type: Array, default: () => [] },
  emptyTitle: { type: String, default: 'No intraday strike readings' },
  emptyMessage: { type: String, default: 'No usable values are available for this chart.' },
  detailsLabel: { type: String, default: 'Complete returned strike data' },
})

const emit = defineEmits([
  'update:focusActivity',
  'update:autoBucket',
  'update:selectedValue',
  'resetZoom',
  'download',
])

const detailsOpen = ref(false)

const sessionLabel = computed(() => {
  if (props.marketOpen === true) return 'Market open'
  if (props.marketOpen === false) return 'Stored session'
  return null
})

const sourceTimeKind = computed(() => String(props.sourceTimestampStatus || '').toLowerCase() === 'legacy'
  ? 'Legacy response time'
  : 'As of')

const formattedSnapshotAsOf = computed(() => {
  if (!props.snapshotAsOf) return null
  const date = new Date(props.snapshotAsOf)
  if (Number.isNaN(date.getTime())) return String(props.snapshotAsOf)
  return `${new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/New_York',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  }).format(date)} ET`
})

const sourceTone = computed(() => {
  const status = String(props.sourceTimestampStatus || '').toLowerCase()
  const label = String(props.sourceLabel || '').toLowerCase()
  if (props.marketOpen !== true) return 'neutral'
  if (!props.snapshotAsOf || ['legacy', 'unknown', 'incomplete'].includes(status)) return 'warning'
  if (label.includes('delayed') || label.includes('unavailable') || label.includes('legacy')) return 'warning'
  return 'positive'
})
</script>

<template>
  <div class="gex-ui intraday-strike-panel" data-theme="dark" data-density="compact">
    <UiPanel :title="title" :subtitle="subtitle" tone="data" :data-testid="id">
      <template #actions>
        <div class="gex-row">
          <UiBadge v-if="symbol" tone="data">{{ symbol }}</UiBadge>
          <UiBadge tone="neutral">Intraday</UiBadge>
          <UiBadge v-if="sourceLabel" :tone="sourceTone">{{ sourceLabel }}</UiBadge>
          <UiBadge v-if="sessionLabel" :tone="marketOpen === true ? 'positive' : 'neutral'">{{ sessionLabel }}</UiBadge>
          <UiHelpDialog :id="`${id}-guide`" :title="helpTitle" trigger-label="Reading guide">
            <slot name="help" />
          </UiHelpDialog>
        </div>
      </template>

      <p v-if="snapshotAsOf || sourceTimestampStatus" class="intraday-strike-panel__source">
        {{ sourceTimeKind }}: {{ formattedSnapshotAsOf || 'Unavailable' }}
      </p>
      <slot name="metrics" />
      <div v-if="$slots.notice" class="intraday-strike-panel__notice">
        <slot name="notice" />
      </div>

      <section class="intraday-strike-frame" :aria-label="`${title} chart controls and plot`">
        <div class="intraday-strike-frame__heading">
          <div>
            <h3>Strike distribution</h3>
            <p>{{ displayCount }} displayed / {{ validCount }} numeric strikes / {{ rawCount }} raw readings</p>
          </div>
          <div class="intraday-strike-frame__actions">
            <UiButton :disabled="!hasChartData" @click="emit('resetZoom')">Reset zoom</UiButton>
            <UiButton :disabled="!hasChartData" @click="emit('download')">Download PNG</UiButton>
          </div>
        </div>

        <div class="intraday-strike-frame__controls">
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

        <div v-if="selectedOptions.length" class="intraday-strike-frame__selection">
          <UiSelect
            label="Inspect strike"
            :model-value="selectedValue"
            :options="selectedOptions"
            @update:model-value="emit('update:selectedValue', $event)"
          />
          <div class="intraday-strike-frame__selected" aria-live="polite">
            <slot name="selection" />
          </div>
        </div>

        <UiStatus
          v-if="!hasChartData"
          state="sparse"
          :title="emptyTitle"
          :message="emptyMessage"
        />
        <div v-else class="intraday-strike-frame__canvas">
          <slot name="chart" />
        </div>
        <p v-if="hasChartData" class="intraday-strike-frame__hint">Click a bar to inspect it. Pan horizontally, use the mouse wheel, or pinch to zoom. Reset zoom restores the displayed range.</p>
      </section>

      <details
        class="intraday-strike-frame__details"
        :data-testid="`${id}-readings`"
        @toggle="detailsOpen = $event.currentTarget.open"
      >
        <summary>{{ detailsLabel }} / {{ rawCount }} readings</summary>
        <slot v-if="detailsOpen" name="details" />
      </details>
    </UiPanel>
  </div>
</template>

<style scoped>
.intraday-strike-panel {
  min-width: 0;
}

.intraday-strike-panel__source {
  margin: -2px 0 14px;
  color: var(--gex-muted);
  font-size: 12px;
}

.intraday-strike-panel__notice {
  margin-top: 12px;
  border: 1px solid color-mix(in srgb, var(--gex-warning) 42%, var(--gex-border));
  border-radius: 10px;
  padding: 10px 12px;
  background: color-mix(in srgb, var(--gex-warning) 7%, transparent);
  color: var(--gex-muted);
  font-size: 12px;
}

.intraday-strike-panel__notice :deep(strong) {
  color: var(--gex-warning);
}

.intraday-strike-frame {
  min-width: 0;
  margin-top: 20px;
  border: 1px solid var(--gex-border);
  border-radius: 12px;
  padding: 16px;
  background: color-mix(in srgb, var(--gex-raised) 42%, transparent);
}

.intraday-strike-frame__heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}

.intraday-strike-frame__heading p,
.intraday-strike-frame__hint {
  margin-top: 3px;
  color: var(--gex-muted);
  font-size: 12px;
}

.intraday-strike-frame__actions,
.intraday-strike-frame__controls {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 9px;
}

.intraday-strike-frame__controls {
  margin-top: 13px;
  color: var(--gex-muted);
  font-size: 12px;
}

.intraday-strike-frame__selection {
  display: grid;
  grid-template-columns: minmax(150px, 220px) minmax(0, 1fr);
  align-items: end;
  gap: 14px;
  margin-top: 14px;
  border-top: 1px solid var(--gex-border);
  padding-top: 14px;
}

.intraday-strike-frame__selected {
  min-height: 38px;
  color: var(--gex-muted);
  font-size: 12px;
}

.intraday-strike-frame__selected :deep(strong) {
  color: var(--gex-text);
}

.intraday-strike-frame__canvas {
  margin-top: 14px;
}

.intraday-strike-frame__hint {
  margin-top: 10px;
}

.intraday-strike-frame__details {
  margin-top: 12px;
}

@media (max-width: 600px) {
  .intraday-strike-frame {
    padding: 12px;
  }

  .intraday-strike-frame__actions,
  .intraday-strike-frame__controls {
    width: 100%;
  }

  .intraday-strike-frame__actions > *,
  .intraday-strike-frame__controls > :deep(*) {
    flex: 1 1 auto;
  }

  .intraday-strike-frame__selection {
    grid-template-columns: 1fr;
  }
}

@media (prefers-reduced-motion: reduce) {
  .intraday-strike-panel *,
  .intraday-strike-panel *::before,
  .intraday-strike-panel *::after {
    scroll-behavior: auto !important;
    transition-duration: 0.01ms !important;
  }
}
</style>
