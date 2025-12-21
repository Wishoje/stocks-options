<template>
  <div class="grid gap-4 lg:grid-cols-2">
    <div
      v-for="p in plans"
      :key="p.key"
      class="relative rounded-2xl border border-white/10 bg-white/5 p-6 shadow-xl shadow-black/20"
      :class="p.featured ? 'ring-1 ring-cyan-500/40 bg-gradient-to-b from-white/10 to-white/5' : ''"
    >
      <div
        v-if="p.badge"
        class="absolute -top-3 right-4 rounded-full bg-cyan-500/20 px-3 py-1 text-xs text-cyan-200 border border-cyan-500/30"
      >
        {{ p.badge }}
      </div>

      <div class="flex items-start justify-between gap-4">
        <div>
          <div class="text-lg font-semibold">{{ p.name }}</div>
          <div class="mt-1 text-sm text-white/60">{{ p.tagline }}</div>
        </div>
      </div>

      <div class="mt-5 flex items-end gap-2">
        <div class="text-4xl font-bold tracking-tight">
          {{ priceLabel(p) }}
        </div>
        <div v-if="unitLabel(p)" class="pb-1 text-sm text-white/60">{{ unitLabel(p) }}</div>
      </div>

      <div v-if="p.subline" class="mt-2 text-sm text-white/60">{{ p.subline }}</div>
      <div v-if="p.note" class="mt-2 text-xs text-white/50">{{ p.note }}</div>

      <div class="mt-5">
        <button
          @click="onSelect(p)"
          class="w-full rounded-xl px-4 py-2.5 text-sm font-semibold"
          :class="p.featured
            ? 'bg-gradient-to-r from-cyan-500 to-blue-600 shadow-lg shadow-cyan-500/20 hover:opacity-95'
            : 'bg-white/10 hover:bg-white/15'"
        >
          {{ p.cta || 'Select' }}
        </button>
      </div>

      <ul class="mt-6 space-y-3 text-sm">
        <li v-for="f in p.features || []" :key="f" class="flex gap-2 text-white/80">
          <svg class="mt-0.5 h-4 w-4 text-cyan-300" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.5 7.5a1 1 0 01-1.42 0l-3.5-3.5a1 1 0 011.42-1.42l2.79 2.79 6.79-6.79a1 1 0 011.42 0z" clip-rule="evenodd"/>
          </svg>
          <span>{{ f }}</span>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  plans: { type: Array, required: true },
})

const emit = defineEmits(['select'])

function fmt(n) {
  if (n === null || n === undefined) return ''
  const num = Number(n)
  if (Number.isNaN(num)) return String(n)
  return num % 1 === 0 ? num.toFixed(0) : num.toFixed(2)
}

function priceLabel(p) {
  // new format
  if (p.price !== undefined) return `$${fmt(p.price)}`
  // legacy format
  if (p.priceMonthly === 0) return 'Free'
  return `$${fmt(p.priceMonthly)}`
}

function unitLabel(p) {
  if (p.unit) return p.unit
  return p.priceMonthly === 0 ? '' : '/mo'
}

function onSelect(p) {
  emit('select', p)
}
</script>
