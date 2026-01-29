<!-- resources/js/Components/LeftPanel.vue -->
<template>
  <div class="flex flex-col h-full">
    <!-- ──────────────────────────────────────
         1. Header – same as Dashboard
         ────────────────────────────────────── -->
    <header class="border-b border-gray-800 bg-gray-900/95 backdrop-blur-sm px-4 py-3">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-bold tracking-tight">Watchlist</h2>
        <button @click="$emit('refresh')" class="text-cyan-400 hover:text-cyan-300">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
        </button>
      </div>
    </header>

    <!-- ──────────────────────────────────────
         2. Search + Suggestions
         ────────────────────────────────────── -->
    <div class="p-4 space-y-3">
      <input
        v-model="q"
        @input="onInput"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="choose(activeIndex)"
        type="text"
        placeholder="Search symbols…"
        class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm focus:outline-none focus:border-cyan-500"
      />

      <!-- Suggestions -->
      <ul v-if="suggestions.length"
          class="bg-gray-800/50 backdrop-blur rounded-lg border border-gray-700 divide-y divide-gray-700 overflow-hidden">
        <li
          v-for="(s, i) in suggestions"
          :key="s.symbol"
          :class="[
            'px-3 py-2.5 cursor-pointer flex justify-between items-center text-sm transition',
            i === activeIndex ? 'bg-gray-700' : 'hover:bg-gray-700/50'
          ]"
          @mouseenter="activeIndex = i"
          @click="choose(i)"
        >
          <span class="font-mono text-cyan-400">{{ s.symbol }}</span>
          <span class="text-gray-400 truncate max-w-[180px] text-right">{{ s.name }}</span>
        </li>
      </ul>

      <p v-if="searchErr" class="text-xs text-red-400">Search unavailable.</p>
    </div>

    <!-- ──────────────────────────────────────
         3. Watchlist items (glass cards)
         ────────────────────────────────────── -->
    <div class="flex-1 overflow-y-auto px-4 pb-4 space-y-2">
      <template v-if="watchlist.length">
        <div
          v-for="w in watchlist"
          :key="w.id"
          @click="selectFromList(w.symbol)"
          class="bg-gray-800/50 backdrop-blur rounded-xl p-3 border border-gray-700 cursor-pointer hover:bg-gray-800/70 transition flex items-center justify-between gap-3"
        >
          <!-- LEFT: Symbol -->
          <span class="font-mono text-lg text-cyan-400 truncate">{{ w.symbol }}</span>

          <!-- RIGHT: Badges + Remove -->
          <div class="flex items-center gap-2">
            <!-- Pin badge -->
            <span
              v-if="pinMap[w.symbol]?.headline_pin != null"
              class="text-[11px] px-2 py-0.5 rounded-full whitespace-nowrap"
              :class="pinBadgeClass(pinMap[w.symbol].headline_pin)"
            >
              Pin {{ pinMap[w.symbol].headline_pin }}
            </span>

            <!-- UA bell with count -->
            <span v-if="uaMap[w.symbol]?.count > 0" class="relative" title="UA today">
              <svg class="w-4 h-4 text-yellow-400" fill="currentColor" viewBox="0 0 24 24">
                <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
              </svg>
            </span>

            <!-- Remove button -->
            <button
              @click.stop="$emit('remove', w.id)"
              class="text-gray-400 hover:text-red-400 transition"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>
      </template>

      <!-- Empty state -->
      <div v-else class="text-center py-12 text-gray-500">
        <p class="text-lg">No symbols yet.</p>
        <p class="text-sm mt-1">Search above to add.</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import axios from 'axios'

defineProps({
  watchlist: { type: Array, default: () => [] },
  pinMap:    { type: Object, default: () => ({}) },
  uaMap:     { type: Object, default: () => ({}) },
})

const emit = defineEmits(['select', 'add', 'remove', 'refresh'])

// ────── Search ──────
const q           = ref('')
const suggestions = ref([])
const activeIndex = ref(0)
const searchErr   = ref(false)
let timer = null

function onInput() {
  clearTimeout(timer)
  const qq = q.value.trim()
  if (!qq) { suggestions.value = []; return }
  timer = setTimeout(async () => {
    try {
      const { data } = await axios.get('/api/symbols', { params: { q: qq } })
      suggestions.value = data?.items || []
      activeIndex.value = 0
      searchErr.value = false
    } catch {
      suggestions.value = []
      searchErr.value = true
    }
  }, 200)
}

function move(delta) {
  if (!suggestions.value.length) return
  activeIndex.value = (activeIndex.value + delta + suggestions.value.length) % suggestions.value.length
}

async function choose(i) {
  const pick = suggestions.value[i]; if (!pick) return
  try {
    await axios.get('/sanctum/csrf-cookie')
    await axios.post('/api/watchlist', { symbol: String(pick.symbol || '').toUpperCase() })
    emit('refresh')
    emit('select', pick.symbol)
    if (typeof window !== 'undefined') {
      localStorage.setItem('calculator_last_symbol', String(pick.symbol || '').toUpperCase())
    }
    window.dispatchEvent(new CustomEvent('select-symbol', { detail: { symbol: pick.symbol } }))
  } catch (e) {
    if (e?.response?.status === 401) window.location.href = '/login'
  } finally {
    q.value = pick.symbol
    suggestions.value = []
  }
}

function selectFromList(sym) {
  emit('select', sym)
  if (typeof window !== 'undefined') {
    localStorage.setItem('calculator_last_symbol', String(sym || '').toUpperCase())
  }
  window.dispatchEvent(new CustomEvent('select-symbol', { detail: { symbol: sym } }))
}

// ────── Pin badge helper (copied from AppShell) ──────
function pinBadgeClass(score) {
  if (score >= 70) return 'bg-yellow-400/20 text-yellow-300 ring-1 ring-yellow-400/30'
  if (score >= 40) return 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/20'
  return 'bg-gray-600/40 text-gray-200 ring-1 ring-gray-500/30'
}
</script>

<style scoped>
/* hide scrollbars but keep functionality */
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
