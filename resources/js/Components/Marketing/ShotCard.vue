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
          <button
            v-if="thumb"
            type="button"
            class="group relative block w-full bg-black/30 text-left"
            :class="thumbAspect"
            @click="openPreview = true"
          >
            <img
              :src="thumb"
              :alt="thumbAlt || title"
              class="h-full w-full"
              :class="[fitClass, thumbObjectPosition, paddingClass]"
              loading="lazy"
            />
            <span class="pointer-events-none absolute bottom-2 right-2 rounded-md border border-white/20 bg-black/60 px-2 py-1 text-[11px] text-white/80 opacity-0 transition group-hover:opacity-100">
              Click to zoom
            </span>
          </button>
          <div v-else class="aspect-[16/9] flex items-center justify-center text-xs text-white/40">
            (add image)
          </div>
        </div>
      </div>
    </div>
  </div>

  <Teleport to="body">
    <div
      v-if="openPreview && thumb"
      class="fixed inset-0 z-[80] flex items-center justify-center bg-black/90 p-4"
      @click.self="openPreview = false"
    >
      <button
        type="button"
        class="absolute right-4 top-4 rounded-lg border border-white/20 bg-black/70 px-3 py-1.5 text-sm text-white/90 hover:bg-black/85"
        @click="openPreview = false"
      >
        Close
      </button>
      <img
        :src="thumb"
        :alt="thumbAlt || title"
        class="max-h-[90vh] max-w-[96vw] rounded-xl border border-white/15 bg-black/40 object-contain"
      />
    </div>
  </Teleport>
</template>

<script setup>
import { computed, ref } from 'vue'

const openPreview = ref(false)

const props = defineProps({
  title: { type: String, required: true },
  pill: { type: String, default: '' },
  bullets: { type: Array, default: () => [] },
  thumb: { type: String, default: '' },
  thumbAlt: { type: String, default: '' },
  thumbFit: { type: String, default: 'contain' }, // contain | cover
  thumbObjectPosition: { type: String, default: 'object-center' },
  thumbAspect: { type: String, default: 'aspect-[16/9]' },
})

const fitClass = computed(() => (props.thumbFit === 'cover' ? 'object-cover' : 'object-contain'))
const paddingClass = computed(() => (props.thumbFit === 'contain' ? 'p-1' : ''))
</script>
