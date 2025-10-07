<template>
  <div class="bg-gray-800 rounded-2xl ring-1 ring-white/5 p-4">
    <div class="flex items-center justify-between mb-2">
      <div class="flex items-center gap-2">
        <slot name="icon" />
        <h3 class="font-semibold">{{ title }}</h3>
        <span v-if="subtitle" class="text-[11px] text-gray-400">{{ subtitle }}</span>
      </div>
      <span v-if="asOf" class="text-xs text-gray-400">{{ asOf }}</span>
    </div>
    <slot />
  </div>
</template>
<script setup>
defineProps({ title:String, subtitle:String, asOf:String })
</script>
