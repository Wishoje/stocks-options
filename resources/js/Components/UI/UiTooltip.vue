<script setup>
import { getCurrentInstance, ref } from 'vue'
defineProps({ label: { type:String, default:'More information' } })
const open=ref(false), pinned=ref(false), tooltipId=`gex-tooltip-${getCurrentInstance()?.uid ?? 'help'}`
function show() { open.value=true }
function hide() { if(!pinned.value) open.value=false }
function toggle() { pinned.value=!pinned.value;open.value=pinned.value }
function close() { pinned.value=false;open.value=false }
function onKey(event) { if(event.key==='Escape'){ event.stopPropagation();close() } }
</script>
<template>
  <span class="gex-tooltip" @mouseenter="show" @mouseleave="hide" @focusin="show" @focusout="hide" @keydown="onKey">
    <button type="button" class="gex-tooltip-trigger" :aria-label="label" :aria-controls="tooltipId" :aria-expanded="open" @click="toggle" @keydown="onKey">?</button>
    <Transition name="gex-tooltip"><span v-if="open" :id="tooltipId" class="gex-tooltip-content" role="tooltip"><slot /></span></Transition>
  </span>
</template>
