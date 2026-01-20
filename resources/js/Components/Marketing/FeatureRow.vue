<template>
  <div class="overflow-hidden rounded-3xl border border-white/10 bg-white/5">
    <div class="grid gap-0 lg:grid-cols-12">
      <div class="p-6 lg:col-span-5" :class="flip ? 'lg:order-2' : 'lg:order-1'">
        <div class="text-xs text-cyan-200/90 font-semibold tracking-wide">{{ kicker }}</div>
        <div class="mt-2 text-xl font-semibold text-white/90">{{ title }}</div>
        <div class="mt-2 text-sm text-white/60">{{ desc }}</div>

        <div v-if="learn?.href" class="mt-2">
          <a :href="learn.href" class="text-sm text-cyan-300 hover:text-cyan-200">
            {{ learn.label }} →
          </a>
        </div>

        <ul v-if="bullets?.length" class="mt-4 space-y-2 text-sm text-white/70">
          <li v-for="b in bullets" :key="b" class="flex gap-2">
            <span class="text-cyan-200">✓</span>
            <span>{{ b }}</span>
          </li>
        </ul>
      </div>

      <div
        class="lg:col-span-7 border-t border-white/10 lg:border-t-0"
        :class="flip ? 'lg:order-1 lg:border-r lg:border-white/10' : 'lg:order-2 lg:border-l lg:border-white/10'"
      >
        <div class="bg-black/25 p-3 lg:p-4">
          <img :src="src" :alt="alt" class="w-full rounded-2xl border border-white/10" loading="lazy" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  flip: { type: Boolean, default: false },
  kicker: { type: String, default: '' },
  title: { type: String, required: true },
  desc: { type: String, default: '' },
  bullets: { type: Array, default: () => [] },
  src: { type: String, required: true },
  alt: { type: String, default: '' },
  learn: {
    type: Object,
    default: () => ({
      label: '',
      href: '',
    }),
  },
})
</script>
