<template>
  <div class="mk-tabs">
    <div class="mk-tab-list" role="tablist" :aria-label="ariaLabel" @keydown="handleKeys">
      <button
        v-for="(item, index) in items"
        :id="`${id}-tab-${item.id}`"
        :key="item.id"
        :ref="element => setTabRef(element, index)"
        class="mk-tab"
        type="button"
        role="tab"
        :aria-selected="index === selectedIndex"
        :aria-controls="`${id}-panel-${item.id}`"
        :tabindex="index === selectedIndex ? 0 : -1"
        @click="select(index)"
      >
        {{ item.label }}
      </button>
    </div>

    <section
      v-for="(item, index) in items"
      :id="`${id}-panel-${item.id}`"
      :key="`${item.id}-panel`"
      role="tabpanel"
      :aria-labelledby="`${id}-tab-${item.id}`"
      :hidden="index !== selectedIndex"
      tabindex="0"
    >
      <ProductMedia v-if="index === selectedIndex" :media="item.media" :priority="priority" />
      <div class="mk-preview-copy">
        <h3 class="mk-subheading">{{ item.title }}</h3>
        <p class="mk-copy">{{ item.summary }}</p>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { trackEvent } from '@/lib/ga'
import ProductMedia from './ProductMedia.vue'

const props = defineProps({
  id: { type: String, default: 'product-preview' },
  items: { type: Array, required: true },
  priority: { type: Boolean, default: false },
  ariaLabel: { type: String, default: 'Product previews' },
})

const selectedIndex = ref(0)
const tabRefs = []

function setTabRef(element, index) {
  if (element) tabRefs[index] = element
}

function select(index, moveFocus = false) {
  selectedIndex.value = index
  if (moveFocus) tabRefs[index]?.focus()
  trackEvent('product_preview_select', {
    source: 'marketing',
    surface: props.id,
    state: props.items[index].id,
  })
}

function handleKeys(event) {
  let next = selectedIndex.value
  if (event.key === 'ArrowRight') next = (next + 1) % props.items.length
  else if (event.key === 'ArrowLeft') next = (next - 1 + props.items.length) % props.items.length
  else if (event.key === 'Home') next = 0
  else if (event.key === 'End') next = props.items.length - 1
  else return
  event.preventDefault()
  select(next, true)
}
</script>
