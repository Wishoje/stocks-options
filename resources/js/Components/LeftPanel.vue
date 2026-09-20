<template>
  <section
    ref="panelRoot"
    class="watchlist-panel"
    :aria-labelledby="titleId"
    @focusout="handleFocusOut"
  >
    <header class="watchlist-panel__header">
      <div>
        <p class="watchlist-panel__eyebrow">Market workspace</p>
        <h2 :id="titleId">Watchlist</h2>
      </div>

      <button
        type="button"
        class="watchlist-icon-button"
        :aria-label="refreshing || loading ? 'Refreshing watchlist' : 'Refresh watchlist'"
        :disabled="refreshing || loading"
        @click="emit('refresh')"
      >
        <svg
          class="watchlist-icon-button__icon"
          :class="{ 'is-spinning': refreshing || loading }"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
          />
        </svg>
      </button>
    </header>

    <div class="watchlist-search">
      <label class="watchlist-search__label" :for="searchId">Add a symbol</label>
      <div class="watchlist-search__control">
        <svg class="watchlist-search__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" />
        </svg>
        <input
          :id="searchId"
          v-model="q"
          data-watchlist-search
          type="text"
          role="combobox"
          autocomplete="off"
          spellcheck="false"
          placeholder="Symbol or company"
          aria-autocomplete="list"
          :aria-controls="listboxId"
          :aria-expanded="suggestionsVisible ? 'true' : 'false'"
          :aria-activedescendant="activeOptionId"
          :aria-describedby="searchMessage ? searchStatusId : undefined"
          @input="onInput"
          @keydown.down.prevent="move(1)"
          @keydown.up.prevent="move(-1)"
          @keydown.enter.prevent="choose(activeIndex)"
          @keydown.esc.prevent="dismissSuggestions"
        >
        <span v-if="searchBusy || addBusy" class="watchlist-spinner" aria-hidden="true" />
      </div>

      <ul
        v-if="suggestionsVisible"
        :id="listboxId"
        class="watchlist-suggestions"
        role="listbox"
        :aria-label="`Symbol suggestions for ${q.trim()}`"
      >
        <li
          v-for="(suggestion, index) in suggestions"
          :id="optionId(index)"
          :key="suggestion.symbol"
          role="option"
          :aria-selected="index === activeIndex ? 'true' : 'false'"
          :class="{ 'is-active': index === activeIndex }"
          @mouseenter="activeIndex = index"
          @mousedown.prevent
          @click="choose(index)"
        >
          <span class="watchlist-suggestions__symbol">{{ suggestion.symbol }}</span>
          <span class="watchlist-suggestions__company">
            <span>{{ suggestion.name || 'Company name unavailable' }}</span>
            <small v-if="suggestion.exchange">{{ suggestion.exchange }}</small>
          </span>
        </li>
      </ul>

      <p
        v-if="searchMessage"
        :id="searchStatusId"
        class="watchlist-search__status"
        :class="{ 'is-error': searchErr || addError }"
        :role="searchErr || addError ? 'alert' : 'status'"
      >
        {{ searchMessage }}
      </p>
    </div>

    <div class="watchlist-panel__meta" aria-live="polite">
      <span>{{ watchlist.length }} {{ watchlist.length === 1 ? 'symbol' : 'symbols' }}</span>
      <span v-if="refreshing">Updating…</span>
      <span v-else>Saved list</span>
    </div>

    <div v-if="error" class="watchlist-notice is-error" role="alert">
      <div>
        <strong>Watchlist unavailable</strong>
        <p>{{ error }}</p>
      </div>
      <button type="button" class="watchlist-text-button" :disabled="refreshing || loading" @click="emit('refresh')">
        Retry
      </button>
    </div>

    <div class="watchlist-items" :aria-busy="loading || refreshing ? 'true' : 'false'">
      <div v-if="loading && watchlist.length === 0" class="watchlist-loading" role="status">
        <span class="watchlist-spinner" aria-hidden="true" />
        Loading saved symbols…
      </div>

      <ul v-else-if="watchlist.length" class="watchlist-list" aria-label="Saved symbols">
        <li
          v-for="item in watchlist"
          :key="item.id"
          class="watchlist-row"
          :class="{ 'is-selected': normalizedSelectedSymbol === normalizeSymbol(item.symbol) }"
          :data-watchlist-symbol="normalizeSymbol(item.symbol)"
        >
          <button
            type="button"
            class="watchlist-row__select"
            :aria-current="normalizedSelectedSymbol === normalizeSymbol(item.symbol) ? 'true' : undefined"
            :aria-label="selectLabel(item)"
            :disabled="isRemoving(item.id)"
            @click="selectFromList(item.symbol)"
          >
            <span class="watchlist-row__symbol">{{ item.symbol }}</span>
            <span class="watchlist-row__badges">
              <span
                v-if="pinMap[item.symbol]?.headline_pin != null"
                class="watchlist-badge"
                :data-level="pinBadgeLevel(pinMap[item.symbol].headline_pin)"
                :title="`Expiry pin score ${pinMap[item.symbol].headline_pin}`"
              >
                Pin {{ pinMap[item.symbol].headline_pin }}
              </span>
              <span
                v-if="uaMap[item.symbol]?.count > 0"
                class="watchlist-badge is-activity"
                :title="activityTitle(item.symbol)"
              >
                UA {{ uaMap[item.symbol].count }}
              </span>
              <span
                v-if="selectingSymbol === normalizeSymbol(item.symbol)"
                class="watchlist-row__progress"
              >
                <span class="watchlist-spinner" aria-hidden="true" />
                Loading
              </span>
            </span>
          </button>

          <button
            type="button"
            class="watchlist-row__remove"
            :aria-label="isRemoving(item.id) ? `Removing ${item.symbol}` : `Remove ${item.symbol} from watchlist`"
            :disabled="isRemoving(item.id)"
            @click="emit('remove', item.id)"
          >
            <span v-if="isRemoving(item.id)" class="watchlist-spinner" aria-hidden="true" />
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </li>
      </ul>

      <div v-else-if="!error" class="watchlist-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 5h14v14H5zM8 9h8M8 12h5M8 15h7" />
        </svg>
        <strong>No saved symbols</strong>
        <p>Search above to build your watchlist.</p>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, getCurrentInstance, nextTick, onBeforeUnmount, ref } from 'vue'
import axios from 'axios'

const props = defineProps({
  watchlist: { type: Array, default: () => [] },
  pinMap: { type: Object, default: () => ({}) },
  uaMap: { type: Object, default: () => ({}) },
  selectedSymbol: { type: String, default: '' },
  selectingSymbol: { type: String, default: '' },
  loading: { type: Boolean, default: false },
  refreshing: { type: Boolean, default: false },
  error: { type: String, default: '' },
  removingIds: { type: [Array, Set], default: () => [] },
})

const emit = defineEmits(['select', 'add', 'remove', 'refresh'])
const instanceUid = getCurrentInstance()?.uid ?? 0
const titleId = `watchlist-title-${instanceUid}`
const searchId = `watchlist-search-${instanceUid}`
const listboxId = `watchlist-suggestions-${instanceUid}`
const searchStatusId = `watchlist-search-status-${instanceUid}`
const panelRoot = ref(null)
const q = ref('')
const suggestions = ref([])
const activeIndex = ref(0)
const searchErr = ref(false)
const addError = ref('')
const searchBusy = ref(false)
const addBusy = ref(false)
let searchTimer = null
let searchController = null
let searchSequence = 0

const suggestionsVisible = computed(() => suggestions.value.length > 0)
const activeOptionId = computed(() => suggestionsVisible.value ? optionId(activeIndex.value) : undefined)
const normalizedSelectedSymbol = computed(() => normalizeSymbol(props.selectedSymbol))
const searchMessage = computed(() => {
  if (addError.value) return addError.value
  if (searchErr.value) return 'Symbol search is unavailable. Try again.'
  if (!searchBusy.value && q.value.trim() && suggestions.value.length === 0) return 'No matching symbols.'
  return ''
})

function normalizeSymbol(symbol) {
  return String(symbol || '').trim().toUpperCase()
}

function optionId(index) {
  return `${listboxId}-option-${index}`
}

function cancelSearch() {
  clearTimeout(searchTimer)
  searchTimer = null
  searchController?.abort()
  searchController = null
  searchSequence += 1
  searchBusy.value = false
}

function onInput() {
  cancelSearch()
  suggestions.value = []
  activeIndex.value = 0
  searchErr.value = false
  addError.value = ''

  const query = q.value.trim()
  if (!query) return

  const sequence = searchSequence
  searchBusy.value = true
  searchTimer = setTimeout(() => searchSymbols(query, sequence), 200)
}

async function searchSymbols(query, sequence) {
  const controller = new AbortController()
  searchController = controller

  try {
    const { data } = await axios.get('/api/symbols', {
      params: { q: query },
      signal: controller.signal,
    })
    if (sequence !== searchSequence || controller.signal.aborted || q.value.trim() !== query) return

    suggestions.value = Array.isArray(data?.items) ? data.items : []
    activeIndex.value = 0
    searchErr.value = false
  } catch {
    if (sequence !== searchSequence || controller.signal.aborted) return
    suggestions.value = []
    searchErr.value = true
  } finally {
    if (sequence === searchSequence && searchController === controller) {
      searchController = null
      searchBusy.value = false
    }
  }
}

function move(delta) {
  if (!suggestions.value.length) return
  activeIndex.value = (activeIndex.value + delta + suggestions.value.length) % suggestions.value.length
}

function dismissSuggestions() {
  cancelSearch()
  suggestions.value = []
  activeIndex.value = 0
}

async function choose(index) {
  const pick = suggestions.value[index]
  const symbol = normalizeSymbol(pick?.symbol)
  if (!pick || !symbol || addBusy.value) return

  cancelSearch()
  addBusy.value = true
  addError.value = ''

  try {
    await axios.get('/sanctum/csrf-cookie')
    await axios.post('/api/watchlist', { symbol })
    emit('refresh')
    emit('select', symbol)
    saveLastSymbol(symbol)
    q.value = symbol
    suggestions.value = []
  } catch (error) {
    if (error?.response?.status === 401 && typeof window !== 'undefined') {
      window.location.href = '/login'
      return
    }
    addError.value = `Could not add ${symbol}. Try again.`
  } finally {
    addBusy.value = false
  }
}

function selectFromList(symbol) {
  const normalized = normalizeSymbol(symbol)
  if (!normalized) return
  emit('select', normalized)
  saveLastSymbol(normalized)
  nextTick(() => {
    panelRoot.value
      ?.querySelector(`[data-watchlist-symbol="${normalized}"]`)
      ?.scrollIntoView?.({ block: 'nearest' })
  })
}

function saveLastSymbol(symbol) {
  if (typeof window !== 'undefined') {
    localStorage.setItem('calculator_last_symbol', symbol)
  }
}

function isRemoving(id) {
  return props.removingIds instanceof Set
    ? props.removingIds.has(id)
    : props.removingIds.includes(id)
}

function pinBadgeLevel(score) {
  if (Number(score) >= 70) return 'high'
  if (Number(score) >= 40) return 'medium'
  return 'low'
}

function activityTitle(symbol) {
  const activity = props.uaMap[symbol]
  const date = activity?.data_date ? ` on ${activity.data_date}` : ''
  return `${activity?.count || 0} unusual activity ${activity?.count === 1 ? 'item' : 'items'}${date}`
}

function selectLabel(item) {
  const selected = normalizedSelectedSymbol.value === normalizeSymbol(item.symbol)
  return `${selected ? 'Selected' : 'Open'} ${item.symbol} dashboard`
}

function handleFocusOut(event) {
  const next = event.relatedTarget
  if (next && panelRoot.value?.contains(next)) return
  dismissSuggestions()
}

onBeforeUnmount(cancelSearch)
</script>
