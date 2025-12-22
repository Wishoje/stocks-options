<template>
  <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 transition">
    <div class="border-b border-white/10 px-4 py-3">
      <div class="text-[11px] text-cyan-200/90 font-semibold tracking-wide">{{ kicker }}</div>
      <div class="mt-1 text-sm font-semibold text-white/90">{{ title }}</div>
      <div class="mt-1 text-xs text-white/60">{{ desc }}</div>
    </div>

    <div class="bg-black/25 p-3">
      <img :src="src" :alt="alt" class="w-full rounded-xl border border-white/10" loading="lazy" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  kicker: { type: String, default: '' },
  title: { type: String, required: true },
  desc: { type: String, default: '' },
  src: { type: String, required: true },
  alt: { type: String, default: '' },
})
</script>
