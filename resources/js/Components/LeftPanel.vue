<template>
  <div class="p-4 space-y-6">
    <!-- Search -->
    <div>
      <label class="block text-sm font-semibold mb-1">Search</label>
      <input
        v-model="q"
        @input="onInput"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="choose(activeIndex)"
        type="text"
        placeholder="Type a symbol or company…"
        class="w-full px-3 py-2 bg-gray-800 rounded outline-none"
      />
      <ul v-if="suggestions.length" class="mt-2 bg-gray-800 rounded shadow divide-y divide-gray-700">
        <li
          v-for="(s, i) in suggestions" :key="s.symbol"
          :class="['px-3 py-2 cursor-pointer flex justify-between items-center', i===activeIndex?'bg-gray-700':'']"
          @mouseenter="activeIndex = i"
          @click="choose(i)"
        >
          <span class="font-mono">{{ s.symbol }}</span>
          <span class="text-gray-400 truncate max-w-[220px] text-right">{{ s.name }}</span>
        </li>
      </ul>
      <p v-if="searchErr" class="text-xs text-red-400 mt-1">Search unavailable.</p>
    </div>

    <!-- Watchlist -->
    <div>
      <div class="flex items-center justify-between mb-2">
        <h3 class="text-lg font-bold">Watchlist</h3>
        <button class="text-sm opacity-70 hover:opacity-100" @click="$emit('refresh')">↻</button>
      </div>

      <div v-if="!watchlist.length" class="text-sm text-gray-400 mt-3">No symbols yet.</div>

      <ul class="mt-3 space-y-1">
        <li v-for="w in watchlist" :key="w.id"
            class="flex items-center justify-between bg-gray-800 rounded px-3 py-2">
          <button class="font-mono hover:underline" @click="selectFromList(w.symbol)">
            {{ w.symbol }}
          </button>
          <div class="flex items-center gap-2">
            <slot name="chips" :symbol="w.symbol" />
            <button class="text-red-400 hover:text-red-300" @click="$emit('remove', w.id)">×</button>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import axios from 'axios'

const props = defineProps({ watchlist: { type: Array, default: () => [] } })
const emit  = defineEmits(['select','add','remove','refresh'])

const q = ref('')
const suggestions = ref([])
const activeIndex = ref(0)
const searchErr = ref(false)
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
    // requires auth; will 401 if not logged in
    await axios.get('/sanctum/csrf-cookie')
    await axios.post('/api/watchlist', { symbol: String(pick.symbol || '').toUpperCase() })
    emit('refresh')          // reload list
    emit('select', pick.symbol) // parent can react
    window.dispatchEvent(new CustomEvent('select-symbol', { detail: { symbol: pick.symbol } }))
  } catch (e) {
    // if not logged in, this is where to redirect or toast
    if (e?.response?.status === 401) window.location.href = '/login'
    // swallow other errors for now
  } finally {
    q.value = pick.symbol
    suggestions.value = []
  }
}

function selectFromList(sym) {
  emit('select', sym)
  window.dispatchEvent(new CustomEvent('select-symbol', { detail: { symbol: sym } }))
}
</script>
