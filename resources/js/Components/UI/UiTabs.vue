<script setup>
import { computed, ref, watch } from 'vue'
const props = defineProps({ id: { type: String, required: true }, label: { type: String, required: true }, modelValue: { type: String, required: true }, items: { type: Array, required: true } })
const emit = defineEmits(['update:modelValue'])
const buttons = ref([])
const enabled = computed(() => props.items.map((item, i) => item.disabled ? -1 : i).filter(i => i >= 0))
const selectedIndex = computed(() => {
  const index = props.items.findIndex(item => item.value === props.modelValue && !item.disabled)
  return index >= 0 ? index : enabled.value[0] ?? -1
})
watch([() => props.modelValue, () => props.items], () => {
  const fallback = props.items[selectedIndex.value]
  if (fallback && fallback.value !== props.modelValue) emit('update:modelValue', fallback.value)
}, { immediate: true, deep: true })
function onKey(event, index) {
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return
  event.preventDefault()
  if (!enabled.value.length) return
  const position = Math.max(0, enabled.value.indexOf(index))
  const next = event.key === 'Home' ? enabled.value[0] : event.key === 'End' ? enabled.value.at(-1) : enabled.value[(position + (event.key === 'ArrowRight' ? 1 : -1) + enabled.value.length) % enabled.value.length]
  emit('update:modelValue', props.items[next].value)
  buttons.value[next]?.focus()
}
</script>
<template>
  <div class="gex-tabs" role="tablist" :aria-label="label">
    <button v-for="(item,index) in items" :key="item.value" :ref="el => buttons[index] = el" type="button" class="gex-tab" role="tab"
      :id="`${id}-${item.value}`" :aria-controls="`${id}-panel`" :aria-selected="selectedIndex === index" :tabindex="selectedIndex === index ? 0 : -1" :disabled="item.disabled"
      @click="emit('update:modelValue',item.value)" @keydown="onKey($event,index)">{{ item.label }}</button>
  </div>
</template>
