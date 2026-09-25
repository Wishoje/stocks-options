<script setup>
import UiButton from './UiButton.vue'
import UiLoading from './UiLoading.vue'
defineProps({ state: { type: String, default: 'ready' }, title: { type: String, required: true }, message: String, retry: Boolean, layout: { type: String, default: 'chart' } })
defineEmits(['retry'])
</script>
<template>
  <UiLoading v-if="state === 'loading' || state === 'preparing'" :title="title" :message="message" :preparing="state === 'preparing'" :layout="layout" :retry="retry" @retry="$emit('retry')" />
  <div v-else class="gex-status" :data-state="state" :role="state === 'error' ? 'alert' : 'status'"><div><strong>{{ title }}</strong><span class="gex-small">{{ message }}</span></div><UiButton v-if="retry" @click="$emit('retry')">Retry</UiButton></div>
</template>
