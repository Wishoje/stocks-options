<script setup>
import { computed } from 'vue'
import UiBadge from './UI/UiBadge.vue'
import UiExposureChart from './UI/UiExposureChart.vue'
import { numeric } from './UI/numbers.js'

const props = defineProps({
  items: { type: Array, default: () => [] },
  today: { type: String, default: '' },
  modelValue: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'reading-inspected'])

// Copy and sort for presentation. Keep every source object and preserve null as
// unavailable; numeric zero remains a real reading.
const rows = computed(() => (props.items || [])
  .map((source, sourceIndex) => ({
    ...source,
    sourceIndex,
    date: source?.exp_date ?? '',
    value: numeric(source?.dex_total),
  }))
  .sort((a, b) => String(a.date).localeCompare(String(b.date))))

const pastCount = computed(() => rows.value.filter(row => props.today && row.date < props.today).length)
const todayCount = computed(() => rows.value.filter(row => props.today && row.date === props.today).length)
const futureCount = computed(() => rows.value.filter(row => !props.today || row.date > props.today).length)
</script>

<template>
  <section aria-label="DEX by expiry">
    <div class="gex-row" style="margin-bottom: 12px">
      <UiBadge v-if="pastCount" tone="neutral">{{ pastCount }} expired</UiBadge>
      <UiBadge v-if="todayCount" tone="data">{{ todayCount }} today</UiBadge>
      <UiBadge tone="neutral">{{ futureCount }} future</UiBadge>
    </div>

    <UiExposureChart
      :items="rows"
      :model-value="modelValue"
      unit="share equivalents"
      @update:model-value="emit('update:modelValue', $event)"
      @reading-inspected="emit('reading-inspected')"
    />
  </section>
</template>
