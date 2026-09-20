<script setup>
defineOptions({ inheritAttrs: false })
const props = defineProps({ label: { type: String, required: true }, modelValue: [String, Number], options: { type: Array, required: true }, disabled: Boolean })
const emit = defineEmits(['update:modelValue'])
function update(event) {
  const option = props.options.find(item => String(item.value) === event.target.value)
  emit('update:modelValue', option ? option.value : event.target.value)
}
</script>
<template><label class="gex-field">{{ label }}<select v-bind="$attrs" class="gex-select" :value="modelValue" :disabled="disabled" @change="update"><option v-for="option in options" :key="option.value" :value="option.value" :disabled="option.disabled">{{ option.label }}</option></select></label></template>
