<script setup>
import { inject } from 'vue'
import { WALL_ALERTS } from '@/Support/wallAlerts'
const alerts = inject(WALL_ALERTS, null)
</script>

<template>
  <div v-if="alerts" class="wall-alert-controls" aria-label="Wall alert settings">
    <div class="alert-switch"><label><input type="checkbox" :checked="alerts.settings.value.enabled" @change="alerts.update({ enabled: $event.target.checked })"> Alert me to new setups</label><span>While the site is open · Recorded prices delayed 15 min</span></div>
    <div v-if="alerts.settings.value.enabled" class="alert-options">
      <label><input type="checkbox" :checked="alerts.settings.value.testing" @change="alerts.update({ testing: $event.target.checked })"> Also alert when price tests a wall</label>
      <label><input type="checkbox" :checked="alerts.settings.value.sound" @change="alerts.update({ sound: $event.target.checked })"> Sound</label>
      <button type="button" @click="alerts.preview()">Test alert</button>
    </div>
    <p v-if="alerts.settings.value.enabled">Alerts follow your saved symbols across pages. Existing events are skipped when you turn alerts on.</p>
    <p v-if="alerts.settings.value.enabled && alerts.settings.value.sound && !alerts.soundReady.value">Click Test alert to activate saved sound for this visit.</p>
    <p v-if="alerts.message.value" role="status">{{ alerts.message.value }}</p>
    <p v-if="alerts.settings.value.enabled && alerts.error.value" role="status">Alerts are paused until wall activity updates. {{ alerts.error.value }}</p>
  </div>
</template>

<style scoped>
.wall-alert-controls{margin-top:16px;padding:13px 15px;border:1px solid #43536a;border-radius:9px;background:#1c2433;color:#edf3fc;font-size:.8rem}.alert-switch,.alert-options{display:flex;align-items:center;gap:12px 22px;flex-wrap:wrap}.alert-switch{justify-content:space-between}.alert-options{margin-top:12px;padding-top:12px;border-top:1px solid #3b495f}label{display:inline-flex;align-items:center;gap:8px;cursor:pointer}input{accent-color:#b5bcff}span,p{font-size:.73rem;color:#bac9dc}p{line-height:1.5;margin:10px 0 0}button{padding:6px 10px;border:1px solid #536382;border-radius:6px;background:#26334a;color:#edf3fc;font-size:.75rem}button:hover{background:#37486a}input:focus-visible,button:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}
</style>
