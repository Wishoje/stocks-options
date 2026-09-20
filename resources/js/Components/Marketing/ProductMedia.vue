<template>
  <figure class="mk-card mk-media" :class="{ 'mk-media--chart-detail': chartDetail }" :data-media-id="media.id" :data-capture-status="media.status">
    <template v-if="isReady">
      <picture class="mk-media__picture">
        <source v-if="media.mobile?.src && !chartDetail" media="(max-width: 640px)" :srcset="media.mobile.src" />
        <img
          class="mk-media__image"
          :src="media.desktop.src"
          :alt="media.alt"
          :width="media.desktop.width"
          :height="media.desktop.height"
          :loading="priority ? 'eager' : 'lazy'"
          :fetchpriority="priority ? 'high' : 'auto'"
          decoding="async"
        />
      </picture>
      <button
        class="mk-button mk-button--secondary mk-media__expand"
        type="button"
        aria-haspopup="dialog"
        :aria-controls="dialogId"
        :aria-expanded="dialogOpen"
        @click="openDialog"
      >
        Expand <span class="sr-only">{{ media.title }} image</span>
      </button>
    </template>

    <div v-else class="mk-media__pending">
      <div class="mk-media__pending-inner">
        <span class="mk-media__status">Verified capture pending</span>
        <h3>{{ media.title }}</h3>
        <p>{{ media.description }}</p>
        <p class="mk-meta">{{ media.context }}</p>
      </div>
    </div>

    <figcaption class="mk-media__caption"><span v-if="chartDetail">Chart detail · Expand for the full view. </span>{{ media.caption }}</figcaption>
  </figure>

  <Teleport to="body">
    <div v-if="dialogOpen" class="mk-dialog-backdrop" @mousedown.self="closeDialog">
      <section
        :id="dialogId"
        ref="dialog"
        class="mk-dialog"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="dialogTitleId"
        tabindex="-1"
        @keydown="handleDialogKeydown"
      >
        <header class="mk-dialog__head">
          <h2 :id="dialogTitleId">{{ media.title }}</h2>
          <button ref="closeButton" class="mk-button mk-button--secondary" type="button" @click="closeDialog">Close</button>
        </header>
        <div class="mk-dialog__body">
          <img class="mk-media__image" :src="media.desktop.src" :alt="media.alt" />
          <p class="mk-copy">{{ media.caption }}</p>
        </div>
      </section>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue'

const props = defineProps({
  media: { type: Object, required: true },
  priority: { type: Boolean, default: false },
  chartDetail: { type: Boolean, default: false },
})

const dialogOpen = ref(false)
const dialog = ref(null)
const closeButton = ref(null)
const instanceId = useId()
const dialogId = computed(() => `${props.media.id}-${instanceId}-dialog`)
const dialogTitleId = computed(() => `${dialogId.value}-title`)
let returnFocus = null
let previousOverflow = ''

const isReady = computed(() => props.media.status === 'ready' && Boolean(props.media.desktop?.src))

async function openDialog(event) {
  if (!isReady.value) return
  returnFocus = event.currentTarget
  dialogOpen.value = true
  previousOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  await nextTick()
  closeButton.value?.focus()
}

function closeDialog() {
  if (!dialogOpen.value) return
  dialogOpen.value = false
  document.body.style.overflow = previousOverflow
  nextTick(() => returnFocus?.focus())
}

function handleDialogKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    closeDialog()
    return
  }

  if (event.key !== 'Tab') return
  const focusable = [...dialog.value.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')]
  if (!focusable.length) return
  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

onBeforeUnmount(() => {
  if (dialogOpen.value) document.body.style.overflow = previousOverflow
})
</script>
