<template>
  <div class="bg-gray-800/60 border border-gray-700 rounded-xl p-6 text-sm">
    <div class="flex items-start gap-3">
      <svg class="w-5 h-5 animate-pulse text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h6l2 3h10v11a2 2 0 01-2 2H5a2 2 0 01-2-2V3z"/>
      </svg>
      <div>
        <div class="font-semibold mb-1">
          Preparing {{ symbol }} data…
          <span v-if="phase==='queued'" class="ml-2 text-[11px] text-gray-400">(queued)</span>
          <span v-else-if="phase==='fetching'" class="ml-2 text-[11px] text-gray-400">(fetching)</span>
        </div>
        <p class="text-gray-300">
          We’re pulling option chains and building analytics. This usually takes a moment for new symbols.
        </p>
        <div class="mt-3 flex items-center gap-3">
          <div class="h-1 w-40 bg-gray-700 rounded overflow-hidden">
            <div class="h-full w-1/3 animate-[progress_1.2s_linear_infinite] bg-cyan-500"></div>
          </div>
          <button @click="$emit('forceRefresh')" class="text-xs px-2 py-1 rounded bg-gray-700 hover:bg-gray-600">
            Refresh now
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({ symbol: String, phase: String })
</script>

<style scoped>
@keyframes progress { 0%{transform:translateX(-100%)} 100%{transform:translateX(300%)} }
</style>
