<script setup>
defineProps({
  label: { type: String, required: true },
  value: [String, Number],
  unit: String,
  context: String,
  tone: { type: String, default: 'neutral' },
  prominence: { type: String, default: 'standard' },
})
</script>
<template>
  <article
    class="gex-panel gex-metric"
    :data-tone="tone === 'neutral' ? undefined : tone"
    :data-prominence="prominence === 'primary' ? 'primary' : undefined"
    :aria-label="label"
  >
    <div class="gex-metric-label">{{ label }}</div>
    <div class="gex-metric-value gex-number"><span>{{ value == null || value === '' ? 'Unavailable' : value }}</span><small v-if="unit && value != null && value !== ''">{{ unit }}</small></div>
    <p v-if="context" class="gex-metric-context">{{ context }}</p>
  </article>
</template>
