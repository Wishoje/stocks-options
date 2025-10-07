<template>
  <span class="inline-flex items-center gap-1 text-gray-400">
    <slot />
    <span class="text-gray-500 cursor-help select-none"
          :title="tip">ⓘ</span>
  </span>
</template>
<script setup>
defineProps({ tip: { type: String, required: true } })
</script>