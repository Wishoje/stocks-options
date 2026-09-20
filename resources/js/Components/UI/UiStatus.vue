<script setup>
import UiButton from './UiButton.vue'
defineProps({ state: { type: String, default: 'ready' }, title: { type: String, required: true }, message: String, retry: Boolean })
defineEmits(['retry'])
</script>
<template><div class="gex-status" :data-state="state" :role="state === 'error' ? 'alert' : 'status'"><div><strong>{{ title }}</strong><span class="gex-small">{{ message }}</span></div><UiButton v-if="retry" @click="$emit('retry')">Retry</UiButton></div></template>
