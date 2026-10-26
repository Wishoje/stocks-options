<script setup>
import { wallStatusLabel, wallOutcomeLabel } from '@/Support/wallLifecycle'
defineProps({ status: { type: String, default: 'needs_update' }, outcome: String })
</script>
<template><span class="wall-status" :class="`wall-status--${status}`"><span aria-hidden="true">{{ status === 'conditions_met' ? '✓' : status === 'in_progress' ? '↗' : status === 'finished' ? '■' : status === 'watching' ? '◷' : '…' }}</span>{{ wallStatusLabel(status) }}<span v-if="status === 'finished' && wallOutcomeLabel(outcome)">· {{ wallOutcomeLabel(outcome) }}</span></span></template>
<style scoped>
.wall-status{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;padding:5px 8px;border:1px solid #56637b;border-radius:6px;background:#293143;color:#c7d2e5;font-size:.73rem;font-weight:650;line-height:1.5}.wall-status--conditions_met{color:#b3f0cd;border-color:#4f9471;background:#203c31}.wall-status--watching{color:#f5d99b;border-color:#8b7347;background:#393227}.wall-status--in_progress{color:#b6d9ff;border-color:#537eac;background:#24364b}.wall-status--finished{color:#d3c6ed;border-color:#7a668f;background:#332d43}
</style>
