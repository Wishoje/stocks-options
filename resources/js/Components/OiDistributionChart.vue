<script setup>
import OptionDistributionDonut from './OptionDistributionDonut.vue'

defineProps({
  callOi: { type: [Number, String], default: null },
  putOi: { type: [Number, String], default: null },
})

const emit = defineEmits(['reading-inspected'])
</script>

<template>
  <OptionDistributionDonut
    title="Open-interest distribution"
    subtitle="Call and put contracts across the selected expiration scope."
    total-label="Total OI"
    :call-value="callOi"
    :put-value="putOi"
    @reading-inspected="emit('reading-inspected')"
  />
</template>
