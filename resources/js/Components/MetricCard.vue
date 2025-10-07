<template>
  <div class="bg-gray-800 rounded-xl p-4 ring-1 ring-white/5">
    <div class="flex items-center justify-between">
      <div class="text-sm text-gray-400 flex items-center gap-1">
        {{ title }}
        <span v-if="tip" class="text-gray-500 cursor-help" :title="tip">ⓘ</span>
      </div>
    </div>
    <div class="mt-1 text-2xl font-semibold tabular-nums">{{ value }}</div>
    <div v-if="sub" class="text-[11px] text-gray-500 mt-1">{{ sub }}</div>
  </div>
</template>
<script setup>
defineProps({ title:String, value:[String,Number], sub:String, tip:String })
</script>
