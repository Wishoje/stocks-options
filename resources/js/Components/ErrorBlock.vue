<template>
  <div class="bg-red-500/10 border border-red-500/30 text-red-300 text-sm rounded-lg p-3">
    <div class="flex items-center justify-between">
      <span>{{ message }}</span>
      <button v-if="onRetry" @click="onRetry" class="ml-3 px-3 py-1 rounded bg-red-500/20 hover:bg-red-500/30">
        Retry
      </button>
    </div>
    <p v-if="detail" class="text-xs mt-1 text-red-400/90">{{ detail }}</p>
  </div>
</template>
<script setup>
defineProps({
  message: { type: String, required: true },
  detail:  { type: String, default: '' },
  onRetry: { type: Function, default: null }
})
</script>
