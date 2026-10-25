<template>
  <div ref="container" class="mk-spy-chart">
    <svg :viewBox="`0 0 ${width} 440`" role="img" :aria-labelledby="`${id}-title ${id}-description`">
      <title :id="`${id}-title`">SPY Net GEX by strike, EOD {{ preview.data_date }}</title>
      <desc :id="`${id}-description`">Green bars show positive net exposure; coral bars show negative net exposure. Total net GEX {{ preview.total_label }} USD per 1% move. Put wall {{ preview.put_wall?.strike_label || 'not present' }}. Call wall {{ preview.call_wall?.strike_label || 'not present' }}.</desc>
      <g v-for="tick in preview.chart.y_ticks" :key="tick.y">
        <line x1="80" :x2="width - 25" :y1="tick.y" :y2="tick.y" :class="tick.y === preview.chart.zero ? 'mk-spy-chart__zero' : 'mk-spy-chart__grid'" />
        <text x="68" :y="tick.y + 4" text-anchor="end">{{ tick.label }}</text>
      </g>
      <rect v-for="bar in preview.chart.bars" :key="bar.strike" :x="x(bar.x)" :y="bar.y" :width="Math.max(.5, bar.width / 990 * plotWidth)" :height="bar.height" :class="bar.positive ? 'mk-spy-chart__positive' : 'mk-spy-chart__negative'" rx="1"><title>{{ bar.title }}</title></rect>
      <g v-for="tick in xTicks" :key="tick.x"><text :x="x(tick.x)" y="406" text-anchor="middle">{{ tick.label }}</text></g>
      <text :x="80 + plotWidth / 2" y="432" text-anchor="middle">Strike price</text>
    </svg>
  </div>
</template>
<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue'
const props = defineProps({ preview: { type: Object, required: true } })
const id = useId()
const container = ref(null)
const width = ref(1120)
const plotWidth = computed(() => width.value - 105)
const xTicks = computed(() => props.preview.chart.x_ticks.filter((_, index) => width.value >= 600 || index % 2 === 0))
const x = value => 80 + (value - 100) / 990 * plotWidth.value
let observer
onMounted(() => {
  const resize = () => { width.value = Math.max(240, container.value?.clientWidth || 1120) }
  resize()
  if (typeof ResizeObserver === 'function') {
    observer = new ResizeObserver(resize)
    observer.observe(container.value)
  }
})
onBeforeUnmount(() => observer?.disconnect())
</script>
