<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'
import UiButton from './UiButton.vue'
const props = defineProps({
  title: { type: String, required: true }, message: String,
  layout: { type: String, default: 'chart' }, preparing: Boolean, retry: Boolean,
})
defineEmits(['retry'])
const slow = ref(false)
const prolonged = ref(false)
let slowTimer, longTimer
function clearTimers() { clearTimeout(slowTimer); clearTimeout(longTimer) }
watch(() => props.title, () => {
  clearTimers(); slow.value = false; prolonged.value = false
  slowTimer = setTimeout(() => { slow.value = true }, 12000)
  longTimer = setTimeout(() => { prolonged.value = true }, 60000)
}, { immediate: true })
onBeforeUnmount(clearTimers)
</script>

<template>
  <section class="gex-loading" :class="{ 'is-prolonged': prolonged }" :data-layout="layout" aria-busy="true">
    <div class="gex-loading__status" role="status" aria-live="polite" aria-atomic="true">
      <span class="gex-loading__indicator" aria-hidden="true"></span>
      <div><strong>{{ prolonged ? 'Still waiting for data' : title }}</strong>
        <p>{{ prolonged ? `${title}. You can explore another view and return to this one.` : message }}</p>
        <p v-if="slow && !prolonged">{{ preparing ? 'This view will update as data becomes available. You can keep navigating.' : 'This is taking longer than usual. You can switch tabs or choose another symbol.' }}</p>
      </div>
      <UiButton v-if="slow && retry" @click="$emit('retry')">Retry</UiButton>
    </div>
    <div class="gex-loading__preview" aria-hidden="true">
      <div class="gex-loading__metrics"><div v-for="i in 3" :key="i"><i></i><b></b><i></i></div></div>
      <div v-if="layout === 'table'" class="gex-loading__rows"><div v-for="i in 5" :key="i"><i v-for="j in 4" :key="j"></i></div></div>
      <div v-else-if="layout !== 'metrics'" class="gex-loading__chart"><span></span><span></span><span></span><span></span></div>
    </div>
  </section>
</template>

<style scoped>
.gex-loading { min-width:0; width:100%; border:1px solid #30394b; border-radius:14px; padding:clamp(16px,2vw,24px); background:#141a25; color:#e7eef9; }
.gex-loading__status { display:flex; align-items:flex-start; gap:12px; min-height:62px; }
.gex-loading__status > div { flex:1; min-width:0; }
.gex-loading__status strong { font-size:15px; font-weight:650; }
.gex-loading__status p { color:#aab8cc; font-size:13px; line-height:1.6; margin:5px 0 0; }
.gex-loading__indicator { width:9px; height:9px; margin-top:7px; flex:none; border-radius:50%; background:#80baff; box-shadow:0 0 0 5px #80baff12; animation:gex-loading-pulse 1.8s ease-in-out infinite; }
.gex-loading__preview { margin-top:18px; animation:gex-loading-pulse 1.8s ease-in-out infinite; }
.gex-loading__metrics { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
.gex-loading__metrics > div { padding:16px; border:1px solid #293244; border-radius:10px; min-height:105px; }
.gex-loading__metrics i,.gex-loading__metrics b,.gex-loading__rows i { display:block; background:#29354a; height:8px; border-radius:4px; width:55%; }
.gex-loading__metrics b { width:72%; height:23px; margin:14px 0; background:#35445d; }
.gex-loading__metrics i:last-child { width:82%; }
.gex-loading__chart { height:clamp(190px,24vw,300px); border-left:1px solid #334057; border-bottom:1px solid #334057; margin:28px 8px 15px 22px; display:flex; flex-direction:column; justify-content:space-between; }
.gex-loading__chart span { border-top:1px solid #293244; width:100%; }
.gex-loading__rows { margin-top:24px; }
.gex-loading__rows > div { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; padding:16px 0; border-bottom:1px solid #293244; }
.gex-loading__rows i { width:75%; }
.is-prolonged .gex-loading__preview,.is-prolonged .gex-loading__indicator { animation:none; }
@keyframes gex-loading-pulse { 50% { opacity:.55; } }
@media(max-width:480px) { .gex-loading__metrics { gap:6px; } .gex-loading__metrics > div { padding:10px; min-height:92px; } .gex-loading__status { flex-wrap:wrap; } }
@media(prefers-reduced-motion:reduce) { .gex-loading__preview,.gex-loading__indicator { animation:none; } }
</style>
