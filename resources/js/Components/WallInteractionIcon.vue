<template>
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path :d="paths[status] || paths.watching" />
  </svg>
</template>

<script setup>
defineProps({ status: String })
// Simple line symbols stay legible at chart-marker size without an icon package.
const paths = {
  approaching: 'M4 12h12m-5-5 5 5-5 5M20 5v14',
  touch: 'M17 12a5 5 0 1 1-10 0 5 5 0 0 1 10 0M12 11v2m-1-1h2',
  testing: 'M4 7l5 5-5 5m16-10-5 5 5 5M12 5v14',
  bounce: 'M4 6l7 12 8-12m-6 0h6v6',
  rejection: 'M4 18l7-12 8 12m-6 0h6v-6',
  break: 'M4 12h16M6 19 18 5m-6 0h6v6',
  acceptance_above: 'M6 17V5m-4 4 4-4 4 4m3 6 3 3 5-6',
  acceptance_below: 'M6 5v12m-4-4 4 4 4-4m3 2 3 3 5-6',
  reclaim: 'M10 5 4 11l6 6M4 11h10a5 5 0 0 1 5 5v3',
  failed_reclaim: 'M9 5 4 10l5 5M4 10h10a5 5 0 0 1 5 5m-7 0 7 7m0-7-7 7',
  confirmed_breakout: 'M5 12l7-7 7 7M5 19l7-7 7 7',
  confirmed_breakdown: 'M5 5l7 7 7-7M5 12l7 7 7-7',
  watching: 'M17 12a5 5 0 1 1-10 0 5 5 0 0 1 10 0',
}
</script>
