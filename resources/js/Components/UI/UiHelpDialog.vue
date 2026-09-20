<script setup>
import { nextTick, onUnmounted, ref } from 'vue'
import UiButton from './UiButton.vue'

defineProps({
  id: { type: String, required: true },
  title: { type: String, required: true },
  triggerLabel: { type: String, default: 'Reading guide' },
})

const open = ref(false)
const trigger = ref(null)
const panel = ref(null)
const closeButton = ref(null)

let bodyOverflowBeforeOpen = ''
let bodyScrollLocked = false

function triggerElement() {
  return trigger.value?.$el ?? trigger.value
}

function lockBodyScroll() {
  if (typeof document === 'undefined' || bodyScrollLocked) return
  bodyOverflowBeforeOpen = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  bodyScrollLocked = true
}

function unlockBodyScroll() {
  if (typeof document === 'undefined' || !bodyScrollLocked) return
  document.body.style.overflow = bodyOverflowBeforeOpen
  bodyScrollLocked = false
}

async function show() {
  if (open.value) return
  lockBodyScroll()
  open.value = true
  await nextTick()
  closeButton.value?.focus()
}

function close({ restoreFocus = true } = {}) {
  if (!open.value) return
  open.value = false
  unlockBodyScroll()
  if (restoreFocus) nextTick(() => triggerElement()?.focus())
}

function focusableElements() {
  if (!panel.value) return []
  return [...panel.value.querySelectorAll(
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
  )].filter(element => element.getAttribute('aria-hidden') !== 'true')
}

function handleKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    close()
    return
  }

  if (event.key !== 'Tab') return
  const focusable = focusableElements()
  if (!focusable.length) {
    event.preventDefault()
    panel.value?.focus()
    return
  }

  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  const active = document.activeElement
  if (event.shiftKey && (active === first || !panel.value?.contains(active))) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && (active === last || !panel.value?.contains(active))) {
    event.preventDefault()
    first.focus()
  }
}

onUnmounted(() => {
  unlockBodyScroll()
})
</script>

<template>
  <UiButton
    ref="trigger"
    aria-haspopup="dialog"
    :aria-controls="id"
    :aria-expanded="open"
    @click="show"
  >
    {{ triggerLabel }}
  </UiButton>

  <Teleport to="body">
    <Transition name="gex-help-dialog">
      <div
        v-if="open"
        class="gex-ui gex-help-dialog"
        data-theme="dark"
        data-density="compact"
        @keydown="handleKeydown"
      >
        <div class="gex-help-dialog__backdrop" aria-hidden="true" @click="close()" />
        <section
          :id="id"
          ref="panel"
          class="gex-help-dialog__panel"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="`${id}-title`"
          tabindex="-1"
        >
          <header class="gex-help-dialog__header">
            <div>
              <p class="gex-help-dialog__eyebrow">Reading guide</p>
              <h2 :id="`${id}-title`">{{ title }}</h2>
            </div>
            <button
              ref="closeButton"
              type="button"
              class="gex-help-dialog__close"
              :aria-label="`Close ${title}`"
              @click="close()"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12" />
              </svg>
            </button>
          </header>
          <div class="gex-help-dialog__body">
            <slot />
          </div>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.gex-help-dialog {
  position: fixed;
  inset: 0;
  z-index: 120;
  display: flex;
  justify-content: flex-end;
  background: transparent;
}

.gex-help-dialog__backdrop {
  position: absolute;
  inset: 0;
  background: rgb(0 0 0 / .68);
  backdrop-filter: blur(6px);
}

.gex-help-dialog__panel {
  position: relative;
  z-index: 1;
  width: min(460px, calc(100vw - 24px));
  height: 100vh;
  height: 100dvh;
  overflow-y: auto;
  border-left: 1px solid var(--gex-border);
  background: var(--gex-surface);
  color: var(--gex-text);
  box-shadow: -22px 0 64px rgb(0 0 0 / .44);
  overscroll-behavior: contain;
}

.gex-help-dialog__header {
  position: sticky;
  top: 0;
  z-index: 2;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  padding: 22px;
  border-bottom: 1px solid var(--gex-border);
  background: color-mix(in srgb, var(--gex-surface) 94%, transparent);
  backdrop-filter: blur(12px);
}

.gex-help-dialog__eyebrow {
  margin-bottom: 5px;
  color: var(--gex-data);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
}

.gex-help-dialog__close {
  display: inline-grid;
  flex: 0 0 auto;
  width: 38px;
  height: 38px;
  place-items: center;
  border: 1px solid var(--gex-border);
  border-radius: 9px;
  background: var(--gex-raised);
  color: var(--gex-muted);
  cursor: pointer;
  transition: border-color var(--gex-duration) var(--gex-ease), color var(--gex-duration) var(--gex-ease), transform var(--gex-duration) var(--gex-ease);
}

.gex-help-dialog__close:hover {
  border-color: var(--gex-action);
  color: var(--gex-text);
  transform: translateY(-1px);
}

.gex-help-dialog__close svg {
  width: 18px;
  height: 18px;
}

.gex-help-dialog__body {
  display: grid;
  gap: 18px;
  padding: 24px 22px 32px;
  color: var(--gex-muted);
}

.gex-help-dialog__body :deep(strong) {
  color: var(--gex-text);
}

.gex-help-dialog-enter-active,
.gex-help-dialog-leave-active {
  transition: opacity var(--gex-duration) var(--gex-ease);
}

.gex-help-dialog-enter-active .gex-help-dialog__panel,
.gex-help-dialog-leave-active .gex-help-dialog__panel {
  transition: transform var(--gex-duration) var(--gex-ease);
}

.gex-help-dialog-enter-from,
.gex-help-dialog-leave-to {
  opacity: 0;
}

.gex-help-dialog-enter-from .gex-help-dialog__panel,
.gex-help-dialog-leave-to .gex-help-dialog__panel {
  transform: translateX(100%);
}

@media (max-width: 600px) {
  .gex-help-dialog {
    align-items: flex-end;
  }

  .gex-help-dialog__panel {
    width: 100%;
    height: auto;
    max-height: 85vh;
    max-height: 85dvh;
    border-top: 1px solid var(--gex-border);
    border-left: 0;
    border-radius: 16px 16px 0 0;
    box-shadow: 0 -20px 60px rgb(0 0 0 / .48);
  }

  .gex-help-dialog-enter-from .gex-help-dialog__panel,
  .gex-help-dialog-leave-to .gex-help-dialog__panel {
    transform: translateY(100%);
  }
}

@media (prefers-reduced-motion: reduce) {
  .gex-help-dialog-enter-active,
  .gex-help-dialog-leave-active,
  .gex-help-dialog-enter-active .gex-help-dialog__panel,
  .gex-help-dialog-leave-active .gex-help-dialog__panel,
  .gex-help-dialog__close {
    transition: none;
  }

  .gex-help-dialog__close:hover {
    transform: none;
  }
}
</style>
