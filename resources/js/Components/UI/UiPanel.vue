<script setup>
defineProps({ title: { type: String, required: true }, subtitle: String, tone: { type:String, default:'neutral' } })
</script>
<template>
  <section class="gex-panel" :data-tone="tone === 'neutral' ? undefined : tone" :aria-label="title">
    <header class="gex-panel-header"><div><h2>{{ title }}</h2><p v-if="subtitle">{{ subtitle }}</p></div><slot name="actions" /></header>
    <div class="gex-panel-body"><slot /></div>
  </section>
</template>
