<script setup>
import OptionDistributionDonut from './OptionDistributionDonut.vue'

defineProps({
  callVol: { type: [Number, String], default: null },
  putVol: { type: [Number, String], default: null },
})

const emit = defineEmits(['reading-inspected'])
</script>

<template>
  <OptionDistributionDonut
    title="Option-volume distribution"
    subtitle="Call and put volume across the selected expiration scope."
    total-label="Total volume"
    :call-value="callVol"
    :put-value="putVol"
    @reading-inspected="emit('reading-inspected')"
  />
</template>
