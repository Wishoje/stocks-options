<template>
  <span class="relative inline-block" @mouseenter="open=true" @mouseleave="open=false" @focusin="open=true" @focusout="open=false">
    <button
      type="button"
      class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-gray-700 text-gray-200 text-xs hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
      :aria-label="label"
    >?</button>

    <transition name="fade">
      <div
        v-if="open"
        class="absolute z-20 mt-2 w-64 p-3 rounded-lg bg-gray-800 text-gray-100 text-[12px] leading-5 shadow-xl ring-1 ring-black/30"
        role="tooltip"
      >
        <slot />
      </div>
    </transition>
  </span>
</template>

<script setup>
import { ref } from 'vue'
const props = defineProps({ label: { type: String, default: 'Help' } })
const open = ref(false)
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .12s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
