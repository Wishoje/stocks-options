<template>
  <div class="rounded-3xl border border-white/10 bg-black/20 overflow-hidden">
    <div class="flex items-center justify-between border-b border-white/10 px-5 py-3">
      <div class="text-base font-semibold text-white/90">{{ title }}</div>

      <span
        v-if="pill"
        class="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[11px] text-white/70"
      >
        {{ pill }}
      </span>
    </div>

    <div class="p-5">
      <!-- bigger layout -->
      <div class="grid gap-5 lg:items-start">
        <ul class="space-y-2.5 text-sm text-white/70">
          <li v-for="b in bullets" :key="b" class="flex gap-2.5">
            <span class="mt-2 h-1.5 w-1.5 rounded-full bg-cyan-300/80" />
            <span>{{ b }}</span>
          </li>
        </ul>

        <div class="overflow-hidden rounded-2xl border border-white/10 bg-black/30">
          <img
            v-if="thumb"
            :src="thumb"
            :alt="thumbAlt || title"
            class="w-full h-[320px] object-cover"
            loading="lazy"
          />
          <div v-else class="h-[260px] flex items-center justify-center text-xs text-white/40">
            (add image)
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  pill: { type: String, default: '' },
  bullets: { type: Array, default: () => [] },
  thumb: { type: String, default: '' },
  thumbAlt: { type: String, default: '' },
})
</script>
