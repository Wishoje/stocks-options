<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppShell from '@/Components/AppShell.vue'
import DialogModal from '@/Components/DialogModal.vue'
import NetGexChart from '@/Components/NetGexChart.vue'
import UiBadge from '@/Components/UI/UiBadge.vue'
import UiButton from '@/Components/UI/UiButton.vue'
import UiMetric from '@/Components/UI/UiMetric.vue'
import UiStatus from '@/Components/UI/UiStatus.vue'
import UiTabs from '@/Components/UI/UiTabs.vue'
import {
  SCANNER_TIMEFRAMES,
  closestStrikeGex,
  compareWallHits,
  hotResultItems,
  nearestWallDistance,
  scannerChunks,
  scannerNumber,
  scannerStateFromSearch,
  scannerSymbol,
  scannerUrl,
  validNearPoints,
  volumeDashboardUrl,
  wallDashboardUrl,
  wallHitCounts,
  wallTagsFor,
} from '@/Pages/Scanner/scannerUtils.js'

const initialState = scannerStateFromSearch(typeof window === 'undefined' ? '' : window.location.search)
const scannerMode = ref(initialState.mode)
const resultDensity = ref(initialState.density)
const limit = ref(initialState.limit)
const days = ref(initialState.days)
const appliedVolumeScope = ref({ limit: initialState.limit, days: initialState.days })
const pendingVolumeScope = ref(null)
const wallDraftNearPct = ref(initialState.nearPct)
const wallDraftNearPts = ref(initialState.nearPts)
const appliedWallScope = ref({ nearPct: initialState.nearPct, nearPts: initialState.nearPts, sort: initialState.sort })
const pendingWallScope = ref(null)

const hotSymbols = ref([])
const hotItems = ref([])
const hotResponse = ref(null)
const universeMeta = ref({ tradeDate: null, source: null, backendSource: null, count: 0, availableCount: 0, totalVol: null, avgPcr: null, windowStart: null, windowEnd: null, requestedDays: null, effectiveDays: null, lookbackApplied: null, scope: null })
const loading = ref(false)
const refreshing = ref(false)
const error = ref('')
const rawVolumeOpen = ref(false)

const watchlist = ref([])
const watchlistBusy = ref({})
const watchlistActionError = ref('')
const scannerUniverse = ref([])

const wallHits = ref([])
const wallResponses = ref([])
const wallLoading = ref(false)
const wallRefreshing = ref(false)
const wallError = ref('')
const wallUpdatedAt = ref(null)
const rawWallOpen = ref(false)
const wallDetailOpen = ref(false)
const wallDetailLoading = ref(false)
const wallDetailError = ref('')
const wallDetail = ref(null)
const rawDetailOpen = ref(false)

let disposed = false
let hotSequence = 0
let wallSequence = 0
let detailSequence = 0
let universeSequence = 0
let hotController = null
let wallController = null
let detailController = null
let universeController = null
let wallDetailTrigger = null
let focusRestoreTimer = null

const modeItems = [
  { value: 'gex', label: 'GEX wall scanner' },
  { value: 'volume', label: 'Volume scanner' },
]

const wallLabelMeta = {
  eod_put: { kind: 'Put wall', timeframe: 'End-of-day', timeframeShort: 'EOD' },
  eod_call: { kind: 'Call wall', timeframe: 'End-of-day', timeframeShort: 'EOD' },
  intraday_put: { kind: 'Put wall', timeframe: 'Intraday', timeframeShort: 'Live' },
  intraday_call: { kind: 'Call wall', timeframe: 'Intraday', timeframeShort: 'Live' },
}

const watchlistSymbols = computed(() => new Set(watchlist.value.map(item => scannerSymbol(item?.symbol)).filter(Boolean)))
const scannerUniverseSymbols = computed(() => [...new Set(scannerUniverse.value.map(item => scannerSymbol(item?.symbol)).filter(Boolean))])
const volumeRows = computed(() => hotResultItems(hotItems.value, hotSymbols.value))
const watchlistHitCount = computed(() => {
  const universe = new Set(hotSymbols.value.map(scannerSymbol))
  return [...watchlistSymbols.value].filter(symbol => universe.has(symbol)).length
})
const usingFallback = computed(() => universeMeta.value.source === 'fallback_db')
const lookbackSelectable = computed(() => hotResponse.value == null || universeMeta.value.lookbackApplied === true)
const volumeScopeLabel = computed(() => {
  if (universeMeta.value.lookbackApplied) return `${appliedVolumeScope.value.days}-day EOD fallback window`
  if (universeMeta.value.windowStart && universeMeta.value.windowEnd) return `Stored source window ${universeMeta.value.windowStart} to ${universeMeta.value.windowEnd}`
  return 'Source-defined current-session ranking'
})
const volumeErrorMessage = computed(() => {
  if (!error.value) return ''
  if (!volumeRows.value.length) return error.value
  return `${error.value} Retaining the applied top ${appliedVolumeScope.value.limit} results (${volumeScopeLabel.value}).`
})
const wallUniverseSymbols = computed(() => scannerUniverseSymbols.value.length ? scannerUniverseSymbols.value : [...watchlistSymbols.value])
const wallUniverseSource = computed(() => scannerUniverseSymbols.value.length ? 'Global watchlists' : 'Your watchlist fallback')
const wallRequestCount = computed(() => scannerChunks(wallUniverseSymbols.value).length)
const wallDraftValidationMessage = computed(() => {
  const percent = scannerNumber(wallDraftNearPct.value)
  const points = wallDraftNearPts.value === '' || wallDraftNearPts.value == null ? null : scannerNumber(wallDraftNearPts.value)
  if (percent == null || percent < 0 || percent > 100) return 'Enter a percent from 0 to 100.'
  if (points == null && wallDraftNearPts.value !== '' && wallDraftNearPts.value != null) return 'Enter a non-negative dollar distance or leave it blank.'
  if (points != null && points < 0) return 'Enter a non-negative dollar distance or leave it blank.'
  return ''
})
const wallDraftValid = computed(() => wallDraftValidationMessage.value === '')
const wallDraftDirty = computed(() => (
  scannerNumber(wallDraftNearPct.value) !== appliedWallScope.value.nearPct
  || validNearPoints(wallDraftNearPts.value) !== appliedWallScope.value.nearPts
))
const wallCoverage = computed(() => {
  const coverageRows = wallResponses.value.map(response => response?.coverage).filter(coverage => coverage && typeof coverage === 'object')
  if (!coverageRows.length) return null
  const fields = ['requested_symbols', 'requested_timeframes', 'requested_pairs', 'latest_rows', 'stale_rows', 'invalid_rows', 'no_wall_rows', 'invalid_or_no_wall_rows', 'usable_rows', 'unmatched_usable_rows', 'matched_rows']
  return Object.fromEntries(fields.map(field => [field, coverageRows.reduce((sum, coverage) => sum + (scannerNumber(coverage[field]) ?? 0), 0)]))
})

const wallHitsByTimeframe = computed(() => wallHits.value.reduce((groups, hit) => {
  const timeframe = String(hit?.timeframe || 'N/A').toLowerCase()
  if (!groups[timeframe]) groups[timeframe] = []
  groups[timeframe].push(hit)
  return groups
}, {}))

const orderedTimeframes = computed(() => [
  ...SCANNER_TIMEFRAMES.filter(timeframe => wallHitsByTimeframe.value[timeframe]?.length),
  ...Object.keys(wallHitsByTimeframe.value).filter(timeframe => !SCANNER_TIMEFRAMES.includes(timeframe) && wallHitsByTimeframe.value[timeframe]?.length),
])

const sortedWallHitsByTimeframe = computed(() => Object.fromEntries(
  Object.entries(wallHitsByTimeframe.value).map(([timeframe, rows]) => [timeframe, [...rows].sort((left, right) => compareWallHits(left, right, appliedWallScope.value.sort))]),
))

const wallTradeDates = computed(() => [...new Set(wallHits.value.map(hit => hit?.trade_date).filter(Boolean))].sort().reverse())
const modeTitle = computed(() => scannerMode.value === 'volume' ? 'Most active options underlyings' : 'Symbols trading near major GEX walls')
const modeSubtitle = computed(() => scannerMode.value === 'volume'
  ? 'Compare activity, put/call balance, and watchlist membership without losing the source ranking.'
  : 'Scan the shared watchlist universe across four horizons, then inspect the wall profile or continue in the matching dashboard view.')
const wallStatusTitle = computed(() => {
  if (wallLoading.value) return 'Scanning the wall universe'
  if (wallRefreshing.value) return 'Refreshing wall matches'
  if (wallHits.value.length) return `${wallHits.value.length} wall ${wallHits.value.length === 1 ? 'match' : 'matches'}`
  if (!wallUniverseSymbols.value.length) return 'No scanner universe yet'
  if (!wallCoverage.value) return 'No fresh usable wall matches returned'
  if (wallCoverage.value.latest_rows === 0) return 'No wall snapshots returned'
  if (wallCoverage.value.usable_rows === 0 && wallCoverage.value.stale_rows > 0) return 'Wall snapshots are outside the freshness window'
  if (wallCoverage.value.usable_rows === 0) return 'No fresh usable wall snapshots'
  return 'No walls matched the applied thresholds'
})
const wallStatusMessage = computed(() => {
  if (!wallUniverseSymbols.value.length) return 'Add a symbol to your watchlist to create a fallback scanner universe.'
  if (wallLoading.value || wallRefreshing.value) return `${wallUniverseSymbols.value.length} symbols across ${SCANNER_TIMEFRAMES.length} timeframes in ${wallRequestCount.value} ${wallRequestCount.value === 1 ? 'request' : 'requests'}.`
  if (wallHits.value.length) return `${wallUniverseSource.value}; authoritative wall source ${wallTradeDates.value.join(', ')}. ${wallCoverage.value?.usable_rows ?? wallHits.value.length} fresh usable rows checked.`
  const threshold = `${formatDecimal(appliedWallScope.value.nearPct, 1)}%${appliedWallScope.value.nearPts != null ? ` or $${formatDecimal(appliedWallScope.value.nearPts, 2)}` : ''}`
  if (!wallCoverage.value) return `No fresh usable matches were returned within ${threshold}; this server did not report coverage details.`
  if (wallCoverage.value.latest_rows === 0) return `${wallCoverage.value.requested_pairs} symbol/timeframe pairs were requested, but no latest snapshot rows were found.`
  if (wallCoverage.value.usable_rows === 0) return `${wallCoverage.value.latest_rows} latest rows: ${wallCoverage.value.stale_rows} stale and ${wallCoverage.value.invalid_or_no_wall_rows} invalid or without walls.`
  return `${wallCoverage.value.usable_rows} fresh usable rows were checked; none were within ${threshold}.`
})

function isInWatchlist(symbol) { return watchlistSymbols.value.has(scannerSymbol(symbol)) }
function formatInteger(value) {
  const number = scannerNumber(value)
  return number == null ? 'Unavailable' : new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(number)
}
function formatCompact(value) {
  const number = scannerNumber(value)
  if (number == null) return 'Unavailable'
  if (number === 0) return '0'
  const absolute = Math.abs(number)
  const options = { maximumFractionDigits: 2, minimumFractionDigits: 0 }
  if (absolute >= 1e9) return `${number < 0 ? '-' : ''}${new Intl.NumberFormat('en-US', options).format(absolute / 1e9)}B`
  if (absolute >= 1e6) return `${number < 0 ? '-' : ''}${new Intl.NumberFormat('en-US', options).format(absolute / 1e6)}M`
  if (absolute >= 1e3) return `${number < 0 ? '-' : ''}${new Intl.NumberFormat('en-US', options).format(absolute / 1e3)}K`
  return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(number)
}
function formatDecimal(value, digits = 2) {
  const number = scannerNumber(value)
  return number == null ? 'Unavailable' : number.toFixed(digits)
}
function formatPrice(value) {
  const number = scannerNumber(value)
  return number == null ? 'Unavailable' : new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(number)
}
function pcrTone(value) {
  const number = scannerNumber(value)
  if (number == null) return 'neutral'
  if (number > 1.2) return 'negative'
  if (number < 0.7) return 'positive'
  return 'warning'
}
function pcrTag(value) {
  const number = scannerNumber(value)
  if (number == null) return 'PCR unavailable'
  if (number > 1.2) return 'Put-heavy'
  if (number < 0.7) return 'Call-heavy'
  return 'Balanced'
}
function formatScanTime(value) {
  if (!value) return 'Not scanned yet'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', timeZoneName: 'short' }).format(date)
}
function safeJson(value) {
  try { return JSON.stringify(value, null, 2) } catch { return 'Response could not be serialized.' }
}
function wallTags(hit) { return wallTagsFor(hit, wallLabelMeta) }
function orderedWallTags(hit) {
  return [...wallTags(hit)].sort((left, right) => {
    if (appliedWallScope.value.sort === 'call' || appliedWallScope.value.sort === 'put') {
      const leftPreferred = left.side === appliedWallScope.value.sort ? 0 : 1
      const rightPreferred = right.side === appliedWallScope.value.sort ? 0 : 1
      if (leftPreferred !== rightPreferred) return leftPreferred - rightPreferred
    }
    return (left.distancePct ?? Infinity) - (right.distancePct ?? Infinity) || left.key.localeCompare(right.key)
  })
}
function primaryWallTag(hit) { return orderedWallTags(hit)[0] || null }
function timeframeHitCounts(timeframe) {
  return (wallHitsByTimeframe.value[timeframe] || []).reduce((counts, hit) => {
    const row = wallHitCounts(hit)
    counts.call += row.call
    counts.put += row.put
    return counts
  }, { call: 0, put: 0 })
}
function wallTagLabel(symbol, tag, horizon) {
  const strike = tag.strike == null ? 'strike unavailable' : `strike ${tag.strike}`
  const percent = tag.distancePct == null ? 'percent distance unavailable' : `${tag.distancePct.toFixed(2)} percent away`
  const points = tag.distancePts == null ? 'point distance unavailable' : `${tag.distancePts.toFixed(2)} points away`
  return `Inspect ${symbol} ${horizon || 'unknown horizon'} ${tag.timeframe} ${tag.kind}, ${strike}, ${percent}, ${points}`
}

function syncScannerUrl() {
  if (typeof window === 'undefined') return
  const next = scannerUrl(window.location.href, {
    mode: scannerMode.value, density: resultDensity.value, limit: appliedVolumeScope.value.limit, days: appliedVolumeScope.value.days,
    nearPct: appliedWallScope.value.nearPct, nearPts: appliedWallScope.value.nearPts, sort: appliedWallScope.value.sort,
  })
  window.history.replaceState(window.history.state, '', next)
}

async function loadHotOptions() {
  const requestedScope = { limit: limit.value, days: days.value }
  const sequence = ++hotSequence
  hotController?.abort()
  const controller = new AbortController()
  hotController = controller
  error.value = ''
  loading.value = volumeRows.value.length === 0
  refreshing.value = volumeRows.value.length > 0
  pendingVolumeScope.value = requestedScope
  try {
    const { data } = await axios.get('/api/hot-options', { params: requestedScope, signal: controller.signal })
    if (disposed || sequence !== hotSequence || controller.signal.aborted) return
    hotResponse.value = data ?? null
    hotItems.value = Array.isArray(data?.items) ? data.items : []
    hotSymbols.value = hotItems.value.length ? hotItems.value.map(item => item?.symbol) : (Array.isArray(data?.symbols) ? data.symbols : [])
    universeMeta.value = {
      tradeDate: data?.trade_date ?? null,
      source: data?.source ?? null,
      backendSource: data?.meta?.source ?? null,
      count: data?.meta?.count ?? hotSymbols.value.length,
      availableCount: data?.meta?.available_count ?? hotSymbols.value.length,
      totalVol: data?.meta?.total_vol ?? null,
      avgPcr: data?.meta?.avg_pcr ?? null,
      windowStart: data?.meta?.window_start ?? null,
      windowEnd: data?.meta?.window_end ?? null,
      requestedDays: data?.meta?.requested_days ?? requestedScope.days,
      effectiveDays: data?.meta?.effective_days ?? null,
      lookbackApplied: data?.meta?.lookback_control_applied ?? null,
      scope: data?.meta?.scope ?? null,
    }
    const effectiveDays = [5, 10, 20].includes(Number(data?.meta?.effective_days)) ? Number(data.meta.effective_days) : null
    appliedVolumeScope.value = {
      limit: scannerNumber(data?.limit) ?? requestedScope.limit,
      days: data?.meta?.lookback_control_applied === true ? requestedScope.days : effectiveDays,
    }
  } catch (requestError) {
    if (!controller.signal.aborted && sequence === hotSequence) error.value = requestError?.response?.data?.error || requestError?.response?.data?.message || requestError?.message || 'The volume universe could not be loaded.'
  } finally {
    if (sequence === hotSequence) {
      loading.value = false
      refreshing.value = false
      pendingVolumeScope.value = null
      if (hotController === controller) hotController = null
    }
  }
}

async function loadWatchlist() {
  try {
    const { data } = await axios.get('/api/watchlist')
    if (!disposed) watchlist.value = Array.isArray(data) ? data : []
  } catch { /* AppShell owns the primary watchlist error state. */ }
}
async function loadScannerUniverse() {
  const sequence = ++universeSequence
  universeController?.abort()
  const controller = new AbortController()
  universeController = controller
  try {
    const { data } = await axios.get('/api/watchlist/universe', { signal: controller.signal })
    if (!disposed && sequence === universeSequence && !controller.signal.aborted) scannerUniverse.value = Array.isArray(data) ? data : []
  } catch { /* The personal watchlist remains the documented fallback. */ }
  finally {
    if (universeController === controller) universeController = null
  }
}

async function loadWallHits() {
  const sequence = ++wallSequence
  wallController?.abort()
  wallController = null
  const chunks = scannerChunks(wallUniverseSymbols.value)
  wallError.value = ''
  if (!wallDraftValid.value) {
    wallLoading.value = false
    wallRefreshing.value = false
    pendingWallScope.value = null
    return
  }
  if (!chunks.length) {
    wallHits.value = []
    wallResponses.value = []
    wallUpdatedAt.value = null
    wallLoading.value = false
    wallRefreshing.value = false
    pendingWallScope.value = null
    return
  }
  const requestedScope = {
    nearPct: scannerNumber(wallDraftNearPct.value),
    nearPts: validNearPoints(wallDraftNearPts.value),
  }
  const controller = new AbortController()
  wallController = controller
  wallLoading.value = wallHits.value.length === 0
  wallRefreshing.value = wallHits.value.length > 0
  pendingWallScope.value = requestedScope
  try {
    const responses = []
    for (const symbols of chunks) {
      const { data } = await axios.post('/api/scanner/walls', {
        symbols,
        near_pct: requestedScope.nearPct,
        near_pts: requestedScope.nearPts,
        timeframes: SCANNER_TIMEFRAMES,
      }, { signal: controller.signal })
      if (disposed || sequence !== wallSequence || controller.signal.aborted) return
      responses.push(data ?? null)
    }
    wallResponses.value = responses
    wallHits.value = responses.flatMap(response => Array.isArray(response?.items) ? response.items : [])
    wallUpdatedAt.value = new Date().toISOString()
    appliedWallScope.value = { ...requestedScope, sort: appliedWallScope.value.sort }
  } catch (requestError) {
    if (!controller.signal.aborted && sequence === wallSequence) wallError.value = requestError?.response?.data?.error || requestError?.response?.data?.message || requestError?.message || `Scan failed (${requestError?.response?.status || 'network'}).`
  } finally {
    if (sequence === wallSequence) {
      wallLoading.value = false
      wallRefreshing.value = false
      pendingWallScope.value = null
      if (wallController === controller) wallController = null
    }
  }
}

function openVolumeDashboard(symbol) {
  const normalized = scannerSymbol(symbol)
  if (normalized) window.location.assign(volumeDashboardUrl(normalized))
}
function openWallDashboard(hit, tag = null) {
  const normalized = scannerSymbol(hit?.symbol)
  if (normalized) window.location.assign(wallDashboardUrl(normalized, tag || primaryWallTag(hit), hit?.timeframe))
}

async function openWallDetail(hit, tag, event) {
  const sequence = ++detailSequence
  detailController?.abort()
  if (focusRestoreTimer != null) window.clearTimeout(focusRestoreTimer)
  focusRestoreTimer = null
  const controller = new AbortController()
  detailController = controller
  wallDetailTrigger = event?.currentTarget || document.activeElement
  rawDetailOpen.value = false
  wallDetailOpen.value = true
  wallDetailLoading.value = true
  wallDetailError.value = ''
  wallDetail.value = { symbol: hit?.symbol ?? null, timeframe: hit?.timeframe ?? null, spot: hit?.spot ?? null, tradeDate: hit?.trade_date ?? null, tag, strikeData: [], dataDate: null, wallGex: null, rawResponse: null }
  try {
    const { data } = await axios.get('/api/gex-levels', { params: { symbol: hit?.symbol, timeframe: hit?.timeframe || '30d' }, signal: controller.signal })
    if (disposed || sequence !== detailSequence || controller.signal.aborted || !wallDetailOpen.value) return
    const strikeData = Array.isArray(data?.strike_data) ? data.strike_data : []
    wallDetail.value = { ...wallDetail.value, strikeData, dataDate: data?.data_date ?? null, wallGex: closestStrikeGex(strikeData, tag?.strike), rawResponse: data ?? null }
  } catch (requestError) {
    if (!controller.signal.aborted && sequence === detailSequence && wallDetailOpen.value) wallDetailError.value = requestError?.response?.data?.error || `Failed to load the GEX profile (${requestError?.response?.status || 'network'}).`
  } finally {
    if (sequence === detailSequence) {
      wallDetailLoading.value = false
      if (detailController === controller) detailController = null
    }
  }
}

function closeWallDetail() {
  detailSequence += 1
  detailController?.abort()
  detailController = null
  wallDetailLoading.value = false
  wallDetailOpen.value = false
  const trigger = wallDetailTrigger
  wallDetailTrigger = null
  nextTick(() => {
    focusRestoreTimer = window.setTimeout(() => {
      if (trigger?.isConnected) trigger.focus()
      focusRestoreTimer = null
    }, 220)
  })
}

async function toggleWatchlist(symbol) {
  const normalized = scannerSymbol(symbol)
  if (!normalized || watchlistBusy.value[normalized]) return
  const existing = watchlist.value.find(item => scannerSymbol(item?.symbol) === normalized)
  watchlistActionError.value = ''
  watchlistBusy.value = { ...watchlistBusy.value, [normalized]: true }
  try {
    if (existing) {
      await axios.delete(`/api/watchlist/${existing.id}`)
      watchlist.value = watchlist.value.filter(item => item !== existing)
    } else {
      const { data } = await axios.post('/api/watchlist', { symbol: normalized })
      if (data?.id) watchlist.value = [...watchlist.value, data]
      else await loadWatchlist()
    }
    window.dispatchEvent(new CustomEvent('watchlist-updated', { detail: { symbols: watchlist.value.map(item => item.symbol) } }))
    await loadScannerUniverse()
    if (scannerMode.value === 'gex') await loadWallHits()
  } catch (requestError) {
    watchlistActionError.value = requestError?.response?.data?.message || `Could not update ${normalized} in your watchlist.`
  } finally {
    watchlistBusy.value = { ...watchlistBusy.value, [normalized]: false }
  }
}

function loadMore() {
  if (!loading.value && !refreshing.value && limit.value < 500) limit.value = Math.min(limit.value + 100, 500)
}

function applyWallSort(sort) {
  if (!['nearest', 'call', 'put'].includes(sort) || appliedWallScope.value.sort === sort) return
  appliedWallScope.value = { ...appliedWallScope.value, sort }
}

watch([scannerMode, resultDensity, appliedVolumeScope, appliedWallScope], syncScannerUrl, { deep: true })
watch([limit, days], ([nextLimit, nextDays], [previousLimit, previousDays]) => {
  if (scannerMode.value !== 'volume') return
  if (nextDays !== previousDays && !lookbackSelectable.value) return
  if (nextLimit !== previousLimit || nextDays !== previousDays) loadHotOptions()
})
watch(scannerMode, mode => { if (mode === 'volume') loadHotOptions(); else loadWallHits() })

onMounted(async () => {
  syncScannerUrl()
  await Promise.all([loadWatchlist(), loadScannerUniverse(), loadHotOptions()])
  if (!disposed && scannerMode.value === 'gex') await loadWallHits()
})
onBeforeUnmount(() => {
  disposed = true
  hotController?.abort()
  wallController?.abort()
  detailController?.abort()
  universeController?.abort()
  if (focusRestoreTimer != null) window.clearTimeout(focusRestoreTimer)
})
</script>

<template>
  <AppLayout title="Scanner">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Options scanner</h2>
    </template>

    <div class="py-0">
      <AppShell>
        <main class="scanner-page" :data-density="resultDensity">
          <header class="scanner-hero gex-panel">
            <div class="scanner-hero__copy">
              <span class="scanner-eyebrow">Supporting tools / Scanner</span>
              <h1>{{ modeTitle }}</h1>
              <p>{{ modeSubtitle }}</p>
            </div>
            <div class="scanner-hero__controls">
              <UiTabs id="scanner-mode" v-model="scannerMode" label="Scanner mode" :items="modeItems" />
              <fieldset class="scanner-density">
                <legend>Result density</legend>
                <div class="gex-segmented">
                  <button type="button" :aria-pressed="resultDensity === 'comfortable'" @click="resultDensity = 'comfortable'">Comfortable</button>
                  <button type="button" :aria-pressed="resultDensity === 'compact'" @click="resultDensity = 'compact'">Compact</button>
                </div>
              </fieldset>
            </div>
          </header>

          <section v-if="scannerMode === 'volume'" id="scanner-mode-panel" role="tabpanel" aria-labelledby="scanner-mode-volume" class="scanner-mode-panel">
            <div class="scanner-toolbar gex-panel" aria-label="Volume scanner filters">
              <fieldset>
                <legend>Universe size</legend>
                <div class="gex-segmented">
                  <button v-for="option in [100, 200, 400]" :key="option" type="button" :aria-pressed="limit === option" @click="limit = option">Top {{ option }}</button>
                  <span v-if="![100, 200, 400].includes(limit)" class="scanner-toolbar__extended">Top {{ limit }}</span>
                </div>
              </fieldset>
              <fieldset>
                <legend>Lookback window <span v-if="!lookbackSelectable">(source-defined)</span></legend>
                <div class="gex-segmented">
                  <button v-for="option in [5, 10, 20]" :key="option" type="button" :disabled="!lookbackSelectable" :aria-pressed="lookbackSelectable ? days === option : universeMeta.effectiveDays === option" @click="days = option">{{ option }} days</button>
                </div>
              </fieldset>
              <UiButton :disabled="limit >= 500 || loading || refreshing" @click="loadMore">{{ limit >= 500 ? 'Maximum 500 loaded' : 'Load 100 more' }}</UiButton>
            </div>

            <div class="scanner-metrics">
              <UiMetric label="Results returned" :value="formatInteger(volumeRows.length)" :context="`${formatInteger(universeMeta.availableCount)} available / requested top ${appliedVolumeScope.limit}`" tone="data" prominence="primary" />
              <UiMetric label="Total option volume" :value="formatCompact(universeMeta.totalVol)" :context="universeMeta.windowStart && universeMeta.windowEnd ? `${universeMeta.windowStart} to ${universeMeta.windowEnd}` : (universeMeta.tradeDate ? `Source date ${universeMeta.tradeDate}` : 'Source date unavailable')" tone="data" prominence="primary" />
              <UiMetric label="Average put/call ratio" :value="formatDecimal(universeMeta.avgPcr, 2)" :context="universeMeta.avgPcr == null ? 'Not returned by this source' : pcrTag(universeMeta.avgPcr)" :tone="pcrTone(universeMeta.avgPcr)" />
              <UiMetric label="Watchlist matches" :value="formatInteger(watchlistHitCount)" :context="`${watchlist.length} saved symbols`" tone="positive" />
            </div>

            <div class="scanner-freshness" aria-label="Volume universe provenance">
              <UiBadge :tone="usingFallback ? 'warning' : 'data'">{{ usingFallback ? 'Local-chain fallback ranking' : (universeMeta.backendSource || universeMeta.source || 'Source unavailable') }}</UiBadge>
              <span>{{ volumeScopeLabel }}</span>
              <span>Universe date: {{ universeMeta.tradeDate || 'Unavailable' }}</span>
              <span v-if="refreshing" role="status">Loading top {{ pendingVolumeScope?.limit }}<template v-if="lookbackSelectable"> / {{ pendingVolumeScope?.days }} days</template>; applied rows remain visible.</span>
            </div>

            <UiStatus v-if="error" state="error" :title="volumeRows.length ? 'Volume refresh failed' : 'Volume scanner unavailable'" :message="volumeErrorMessage" retry @retry="loadHotOptions" />
            <UiStatus v-else-if="loading" state="loading" title="Loading the volume universe" :message="`Requesting the top ${pendingVolumeScope?.limit ?? limit} symbols${lookbackSelectable ? ` over ${pendingVolumeScope?.days ?? days} days` : ''}.`" />
            <UiStatus v-else-if="!volumeRows.length" state="sparse" title="No ranked symbols returned" message="The selected universe and lookback have no results." retry @retry="loadHotOptions" />
            <p v-if="watchlistActionError" class="scanner-action-error" role="alert">{{ watchlistActionError }}</p>

            <div v-if="!loading && volumeRows.length" class="scanner-results" aria-label="Volume scanner results">
              <article v-for="(item, index) in volumeRows" :key="`${item?.symbol || 'unknown'}-${item?.rank ?? index}`" class="scanner-result-card" :data-watchlist="isInWatchlist(item?.symbol) || undefined">
                <button type="button" class="scanner-result-card__open" :disabled="!scannerSymbol(item?.symbol)" :aria-label="scannerSymbol(item?.symbol) ? `Open ${scannerSymbol(item.symbol)} overview, rank ${item?.rank ?? index + 1}, total volume ${formatCompact(item?.total_volume)}, put call ratio ${formatDecimal(item?.put_call, 2)}, last price ${formatPrice(item?.last_price)}` : 'Symbol unavailable'" @click="openVolumeDashboard(item?.symbol)">
                  <span class="scanner-result-card__heading"><strong>{{ scannerSymbol(item?.symbol) || 'Unavailable' }}</strong><span class="scanner-rank">#{{ item?.rank ?? index + 1 }}</span></span>
                  <span class="scanner-result-card__metrics">
                    <span><small>Total volume</small><b>{{ formatCompact(item?.total_volume) }}</b></span>
                    <span><small>Put/call</small><b :data-tone="pcrTone(item?.put_call)">{{ formatDecimal(item?.put_call, 2) }}</b></span>
                    <span><small>Last price</small><b>{{ formatPrice(item?.last_price) }}</b></span>
                  </span>
                  <span class="scanner-result-card__footer"><UiBadge :tone="pcrTone(item?.put_call)">{{ pcrTag(item?.put_call) }}</UiBadge><span>Open overview <span aria-hidden="true">&rarr;</span></span></span>
                </button>
                <button type="button" class="scanner-watchlist-button" :data-active="isInWatchlist(item?.symbol) || undefined" :disabled="!scannerSymbol(item?.symbol) || watchlistBusy[scannerSymbol(item?.symbol)]" :aria-label="`${isInWatchlist(item?.symbol) ? 'Remove' : 'Add'} ${scannerSymbol(item?.symbol) || 'symbol'} ${isInWatchlist(item?.symbol) ? 'from' : 'to'} watchlist`" :aria-pressed="isInWatchlist(item?.symbol)" @click="toggleWatchlist(item?.symbol)">
                  <span v-if="watchlistBusy[scannerSymbol(item?.symbol)]" aria-hidden="true">...</span><span v-else-if="isInWatchlist(item?.symbol)" aria-hidden="true">&#10003;</span><span v-else aria-hidden="true">+</span>
                  <span>{{ isInWatchlist(item?.symbol) ? 'Saved' : 'Watch' }}</span>
                </button>
              </article>
            </div>

            <details class="scanner-raw" @toggle="rawVolumeOpen = $event.currentTarget.open">
              <summary>Exact volume scanner response</summary>
              <p>Every field returned for this ranking request. Missing values remain absent or null.</p>
              <pre v-if="rawVolumeOpen">{{ safeJson(hotResponse) }}</pre>
            </details>
          </section>

          <section v-else id="scanner-mode-panel" role="tabpanel" aria-labelledby="scanner-mode-gex" class="scanner-mode-panel">
            <div class="scanner-wall-toolbar gex-panel">
              <div class="scanner-wall-toolbar__filters">
                <label><span>Within percent</span><span class="scanner-input-wrap"><input v-model.number="wallDraftNearPct" type="number" min="0" max="100" step="0.1" inputmode="decimal" :aria-invalid="!wallDraftValid" :aria-describedby="!wallDraftValid ? 'scanner-wall-validation' : undefined"><b>%</b></span></label>
                <label><span>Or within dollars <small>(optional)</small></span><span class="scanner-input-wrap"><b>$</b><input v-model.number="wallDraftNearPts" type="number" min="0" step="0.1" inputmode="decimal" placeholder="Any" :aria-invalid="!wallDraftValid" :aria-describedby="!wallDraftValid ? 'scanner-wall-validation' : undefined"></span></label>
                <UiButton variant="primary" :disabled="wallLoading || wallRefreshing || !wallUniverseSymbols.length || !wallDraftValid" @click="loadWallHits">{{ wallLoading || wallRefreshing ? 'Scanning...' : 'Apply and scan' }}</UiButton>
                <UiBadge v-if="wallDraftDirty" tone="warning">Changes not applied</UiBadge>
              </div>
              <fieldset class="scanner-wall-sort">
                <legend>Sort displayed matches</legend>
                <div class="gex-segmented">
                  <button type="button" :aria-pressed="appliedWallScope.sort === 'nearest'" @click="applyWallSort('nearest')">Nearest</button>
                  <button type="button" :aria-pressed="appliedWallScope.sort === 'call'" @click="applyWallSort('call')">Call walls</button>
                  <button type="button" :aria-pressed="appliedWallScope.sort === 'put'" @click="applyWallSort('put')">Put walls</button>
                </div>
              </fieldset>
            </div>

            <div class="scanner-wall-context">
              <div><span class="scanner-eyebrow">Scan universe</span><strong>{{ wallUniverseSource }}</strong><small>{{ wallUniverseSymbols.length }} unique symbols / {{ wallRequestCount }} API {{ wallRequestCount === 1 ? 'request' : 'requests' }}</small></div>
              <div><span class="scanner-eyebrow">Applied scan</span><strong>{{ formatDecimal(appliedWallScope.nearPct, 1) }}%<template v-if="appliedWallScope.nearPts != null"> or ${{ formatDecimal(appliedWallScope.nearPts, 2) }}</template> / {{ appliedWallScope.sort }}</strong><small>{{ formatScanTime(wallUpdatedAt) }}; {{ wallTradeDates.length ? `wall source ${wallTradeDates.join(', ')}` : 'no source date returned' }}</small></div>
              <div class="scanner-wall-legend" aria-label="Wall legend"><UiBadge tone="positive"><i aria-hidden="true" /> Call wall</UiBadge><UiBadge tone="negative"><i aria-hidden="true" /> Put wall</UiBadge><UiBadge tone="data">EOD / Live</UiBadge></div>
            </div>

            <UiStatus v-if="wallDraftValidationMessage" id="scanner-wall-validation" state="error" title="Check the wall thresholds" :message="wallDraftValidationMessage" />
            <UiStatus v-else-if="wallError" state="error" title="Wall scan could not refresh" :message="wallHits.length ? `${wallError} Retaining results for the applied ${formatDecimal(appliedWallScope.nearPct, 1)}% threshold.` : wallError" retry @retry="loadWallHits" />
            <UiStatus v-else :state="wallLoading || wallRefreshing ? 'loading' : (wallHits.length ? 'recorded' : 'sparse')" :title="wallStatusTitle" :message="wallStatusMessage" />
            <div v-if="wallCoverage" class="scanner-coverage" aria-label="Wall snapshot coverage">
              <span><b>{{ wallCoverage.requested_pairs }}</b> requested pairs</span>
              <span><b>{{ wallCoverage.latest_rows }}</b> latest rows</span>
              <span><b>{{ wallCoverage.usable_rows }}</b> fresh usable</span>
              <span><b>{{ wallCoverage.stale_rows }}</b> stale</span>
              <span><b>{{ wallCoverage.invalid_or_no_wall_rows }}</b> invalid / no wall</span>
              <span><b>{{ wallCoverage.matched_rows }}</b> matched</span>
            </div>

            <div v-if="!wallLoading && orderedTimeframes.length" class="scanner-wall-groups">
              <section v-for="timeframe in orderedTimeframes" :key="timeframe" class="scanner-wall-group gex-panel">
                <header><div><span class="scanner-eyebrow">{{ timeframe }} horizon</span><h2>{{ wallHitsByTimeframe[timeframe].length }} matching {{ wallHitsByTimeframe[timeframe].length === 1 ? 'symbol' : 'symbols' }}</h2></div><div class="scanner-wall-group__counts"><UiBadge tone="positive">{{ timeframeHitCounts(timeframe).call }} call</UiBadge><UiBadge tone="negative">{{ timeframeHitCounts(timeframe).put }} put</UiBadge></div></header>
                <div class="scanner-wall-list">
                  <article v-for="hit in sortedWallHitsByTimeframe[timeframe]" :key="`${hit?.symbol}-${timeframe}`" class="scanner-wall-result">
                    <div class="scanner-wall-result__identity">
                      <button type="button" @click="openWallDashboard(hit)"><strong>{{ scannerSymbol(hit?.symbol) || 'Unavailable' }}</strong><span>Open {{ primaryWallTag(hit)?.session === 'intraday' ? 'live strikes' : 'EOD strikes' }} <span aria-hidden="true">&rarr;</span></span></button>
                      <dl>
                        <div><dt>Spot</dt><dd>{{ formatPrice(hit?.spot) }}</dd></div>
                        <div><dt>Nearest</dt><dd>{{ nearestWallDistance(hit)?.pct == null ? 'Unavailable' : `${formatDecimal(nearestWallDistance(hit).pct, 2)}%` }}</dd></div>
                        <div><dt>Points</dt><dd>{{ nearestWallDistance(hit)?.pts == null ? 'Unavailable' : `$${formatDecimal(nearestWallDistance(hit).pts, 2)}` }}</dd></div>
                        <div><dt>Source date</dt><dd>{{ hit?.trade_date || 'Unavailable' }}</dd></div>
                      </dl>
                    </div>
                    <div class="scanner-wall-result__tags">
                      <button v-for="tag in orderedWallTags(hit)" :key="tag.key" type="button" class="scanner-wall-tag" :data-side="tag.side" :aria-label="wallTagLabel(hit?.symbol, tag, hit?.timeframe)" @click="openWallDetail(hit, tag, $event)">
                        <span class="scanner-wall-tag__title"><b>{{ tag.kind }}</b><em>{{ tag.timeframeShort }}</em></span>
                        <span class="scanner-wall-tag__value">{{ tag.strike == null ? 'Strike unavailable' : `Strike ${formatDecimal(tag.strike, 2)}` }}</span>
                        <span class="scanner-wall-tag__distance">{{ tag.distancePct == null ? 'Percent unavailable' : `${formatDecimal(tag.distancePct, 2)}%` }} <span aria-hidden="true"> / </span> {{ tag.distancePts == null ? 'Points unavailable' : `$${formatDecimal(tag.distancePts, 2)}` }}</span>
                        <span class="scanner-wall-tag__action">Inspect wall profile <span aria-hidden="true">&rarr;</span></span>
                      </button>
                    </div>
                  </article>
                </div>
              </section>
            </div>

            <details class="scanner-raw" @toggle="rawWallOpen = $event.currentTarget.open">
              <summary>Exact wall scanner responses</summary>
              <p>Every result, wall, distance, hit type, timeframe, echoed threshold, and grouped response field from each request chunk.</p>
              <pre v-if="rawWallOpen">{{ safeJson(wallResponses) }}</pre>
            </details>
          </section>

          <DialogModal :show="wallDetailOpen" max-width="2xl" labelledby="scanner-wall-detail-title" @close="closeWallDetail">
            <template #title>
              <div class="scanner-detail-title"><div><span>{{ wallDetail?.tag?.timeframe || 'Wall profile' }}</span><strong id="scanner-wall-detail-title">{{ wallDetail?.symbol || 'Symbol unavailable' }} / {{ String(wallDetail?.timeframe || 'N/A').toUpperCase() }} / {{ wallDetail?.tag?.kind || 'Wall' }}</strong></div><UiButton @click="wallDetail ? openWallDashboard(wallDetail, wallDetail.tag) : null">Open matching dashboard</UiButton></div>
            </template>
            <template #content>
              <div class="scanner-detail">
                <div class="scanner-detail__metrics">
                  <UiMetric label="Spot" :value="formatPrice(wallDetail?.spot)" :context="wallDetail?.tradeDate ? `Wall source ${wallDetail.tradeDate}` : 'Wall source date unavailable'" tone="data" prominence="primary" />
                  <UiMetric label="Wall strike" :value="wallDetail?.tag?.strike == null ? null : formatDecimal(wallDetail.tag.strike, 2)" :context="wallDetail?.tag?.timeframe || 'Session unavailable'" :tone="wallDetail?.tag?.side === 'call' ? 'positive' : 'negative'" prominence="primary" />
                  <UiMetric label="Distance" :value="wallDetail?.tag?.distancePct == null ? null : formatDecimal(wallDetail.tag.distancePct, 2)" unit="%" :context="wallDetail?.tag?.distancePts == null ? 'Point distance unavailable' : `$${formatDecimal(wallDetail.tag.distancePts, 2)} from spot`" />
                  <UiMetric label="EOD Net GEX near wall" :value="wallDetail?.wallGex ? formatCompact(wallDetail.wallGex.netGex) : null" :context="wallDetail?.wallGex?.pctOfMax == null ? 'EOD profile has no comparable reading' : `EOD profile comparison; ${formatDecimal(wallDetail.wallGex.pctOfMax, 1)}% of maximum absolute GEX`" :tone="wallDetail?.wallGex?.netGex > 0 ? 'positive' : (wallDetail?.wallGex?.netGex < 0 ? 'negative' : 'neutral')" />
                </div>
                <UiStatus v-if="wallDetailError" state="error" title="Wall profile unavailable" :message="wallDetailError" />
                <UiStatus v-else-if="wallDetailLoading" state="loading" title="Loading the EOD Net GEX profile" message="The selected wall remains visible while the EOD strike comparison loads." />
                <UiStatus v-else-if="!wallDetail?.strikeData?.length" state="sparse" title="No EOD strike profile returned" message="The wall match remains available, but this selection has no EOD Net GEX strike rows." />
                <NetGexChart v-else :strike-data="wallDetail.strikeData" :snapshot-name="`${wallDetail.symbol || 'symbol'}-${wallDetail.timeframe || 'tf'}-wall-gex`" :eod="true" :symbol="wallDetail.symbol" :timeframe="wallDetail.timeframe" :snapshot-date="wallDetail.dataDate" height-class="h-64 md:h-72" />
                <details class="scanner-raw" @toggle="rawDetailOpen = $event.currentTarget.open"><summary>Exact wall-detail response</summary><p>Every field and strike row returned by the selected GEX profile request.</p><pre v-if="rawDetailOpen">{{ safeJson(wallDetail?.rawResponse) }}</pre></details>
              </div>
            </template>
            <template #footer><UiButton @click="closeWallDetail">Close</UiButton></template>
          </DialogModal>
        </main>
      </AppShell>
    </div>
  </AppLayout>
</template>

<style scoped>
.scanner-page{display:grid;gap:16px;color:var(--gex-text)}
.scanner-hero{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:22px;overflow:hidden}.scanner-hero:before{content:"";position:absolute;inset:0 auto 0 0;width:3px;background:linear-gradient(var(--gex-data),var(--gex-positive))}.scanner-hero__copy{max-width:680px}.scanner-hero h1{margin:4px 0 6px;font-size:clamp(20px,2.2vw,29px);line-height:1.15}.scanner-hero p{color:var(--gex-muted);font-size:13px;line-height:1.55}.scanner-eyebrow{color:var(--gex-data);font-size:10px;font-weight:750;letter-spacing:.12em;text-transform:uppercase}.scanner-hero__controls{display:grid;justify-items:end;gap:9px;flex:0 0 auto}.scanner-density{display:flex;align-items:center;gap:8px;border:0;margin:0;padding:0}.scanner-density legend,.scanner-toolbar legend,.scanner-wall-sort legend{color:var(--gex-muted);font-size:10px;font-weight:650;letter-spacing:.08em;text-transform:uppercase}.scanner-density .gex-segmented button{min-height:29px;padding:4px 8px;font-size:10px}
.scanner-mode-panel{display:grid;gap:14px}.scanner-toolbar,.scanner-wall-toolbar{display:flex;align-items:end;justify-content:space-between;flex-wrap:wrap;gap:14px;padding:14px 16px}.scanner-toolbar fieldset,.scanner-wall-sort{min-width:0;border:0;margin:0;padding:0}.scanner-toolbar legend,.scanner-wall-sort legend{margin-bottom:6px}.scanner-toolbar__extended{display:inline-flex;align-items:center;min-height:34px;padding:6px 10px;color:var(--gex-data);font-size:11px;font-weight:700}.scanner-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.scanner-freshness{display:flex;align-items:center;flex-wrap:wrap;gap:8px 16px;padding-inline:2px;color:var(--gex-muted);font-size:11px}.scanner-freshness [role=status]{color:var(--gex-data)}.scanner-action-error{border-left:3px solid var(--gex-negative);border-radius:8px;padding:10px 12px;background:var(--gex-negative-soft);color:var(--gex-negative);font-size:12px}
.scanner-results{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.scanner-result-card{position:relative;min-width:0;border:1px solid var(--gex-border);border-radius:12px;background:color-mix(in srgb,var(--gex-surface) 92%,var(--gex-raised));overflow:hidden;transition:border-color var(--gex-duration) var(--gex-ease),transform var(--gex-duration) var(--gex-ease),box-shadow var(--gex-duration) var(--gex-ease)}.scanner-result-card:hover,.scanner-result-card:focus-within{border-color:color-mix(in srgb,var(--gex-data) 58%,var(--gex-border));box-shadow:0 12px 26px rgb(0 0 0/.2);transform:translateY(-1px)}.scanner-result-card[data-watchlist=true]{box-shadow:inset 3px 0 var(--gex-positive)}.scanner-result-card__open{display:grid;gap:12px;width:100%;border:0;padding:15px 15px 52px;background:transparent;color:inherit;cursor:pointer;text-align:left}.scanner-result-card__open:disabled{cursor:default}.scanner-result-card__heading,.scanner-result-card__footer{display:flex;align-items:center;justify-content:space-between;gap:10px}.scanner-result-card__heading strong{color:var(--gex-data);font:750 18px/1 var(--gex-number-font,ui-monospace,monospace);letter-spacing:.02em}.scanner-rank{border:1px solid var(--gex-border);border-radius:999px;padding:3px 7px;background:var(--gex-raised);color:var(--gex-muted);font:700 10px/1 var(--gex-number-font,ui-monospace,monospace)}.scanner-result-card__metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}.scanner-result-card__metrics>span{min-width:0}.scanner-result-card__metrics small,.scanner-result-card__metrics b{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.scanner-result-card__metrics small{margin-bottom:3px;color:var(--gex-muted);font-size:9px;letter-spacing:.05em;text-transform:uppercase}.scanner-result-card__metrics b{color:var(--gex-text);font:650 12px/1.3 var(--gex-number-font,ui-monospace,monospace)}.scanner-result-card__metrics b[data-tone=positive]{color:var(--gex-positive)}.scanner-result-card__metrics b[data-tone=negative]{color:var(--gex-negative)}.scanner-result-card__metrics b[data-tone=warning]{color:var(--gex-warning)}.scanner-result-card__footer{color:var(--gex-data);font-size:10px}.scanner-watchlist-button{position:absolute;right:12px;bottom:11px;display:inline-flex;align-items:center;gap:5px;min-height:28px;border:1px solid var(--gex-border);border-radius:7px;padding:4px 8px;background:var(--gex-raised);color:var(--gex-muted);cursor:pointer;font-size:10px;font-weight:650}.scanner-watchlist-button[data-active=true]{border-color:color-mix(in srgb,var(--gex-positive) 54%,var(--gex-border));background:var(--gex-positive-soft);color:var(--gex-positive)}.scanner-watchlist-button:disabled{cursor:wait;opacity:.65}.scanner-page[data-density=compact] .scanner-results{grid-template-columns:repeat(4,minmax(0,1fr));gap:7px}.scanner-page[data-density=compact] .scanner-result-card__open{gap:8px;padding:11px 11px 44px}.scanner-page[data-density=compact] .scanner-result-card__heading strong{font-size:15px}.scanner-page[data-density=compact] .scanner-watchlist-button{right:8px;bottom:8px}
.scanner-wall-toolbar{align-items:center}.scanner-wall-toolbar__filters{display:flex;align-items:end;flex-wrap:wrap;gap:10px}.scanner-wall-toolbar__filters label{display:grid;gap:6px;color:var(--gex-muted);font-size:11px}.scanner-wall-toolbar__filters label small{color:color-mix(in srgb,var(--gex-muted) 76%,transparent)}.scanner-input-wrap{display:flex;align-items:center;min-height:38px;border:1px solid var(--gex-border);border-radius:8px;background:var(--gex-raised);overflow:hidden}.scanner-input-wrap:focus-within{border-color:var(--gex-action);box-shadow:0 0 0 2px color-mix(in srgb,var(--gex-action) 18%,transparent)}.scanner-input-wrap input{width:82px;border:0;padding:7px 8px;background:transparent;color:var(--gex-text);font:650 12px/1 var(--gex-number-font,ui-monospace,monospace);box-shadow:none}.scanner-input-wrap input:focus{outline:0;box-shadow:none}.scanner-input-wrap b{padding-inline:9px;color:var(--gex-muted);font-size:11px}.scanner-wall-context{display:grid;grid-template-columns:1fr 1fr auto;align-items:center;gap:12px}.scanner-wall-context>div{display:grid;gap:3px;min-width:0;border-left:2px solid var(--gex-border);padding-left:11px}.scanner-wall-context strong{color:var(--gex-text);font-size:13px}.scanner-wall-context small{color:var(--gex-muted);font-size:10px}.scanner-wall-legend{display:flex!important;flex-direction:row;flex-wrap:wrap;justify-content:end;border:0!important;padding:0!important}.scanner-wall-legend i{width:7px;height:7px;border-radius:2px;background:currentColor}.scanner-wall-groups{display:grid;gap:12px}.scanner-wall-group{overflow:hidden}.scanner-wall-group>header{display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--gex-border);padding:13px 15px;background:color-mix(in srgb,var(--gex-raised) 34%,transparent)}.scanner-wall-group h2{margin-top:2px;color:var(--gex-text);font-size:14px}.scanner-wall-group__counts{display:flex;flex-wrap:wrap;gap:6px}.scanner-wall-list{display:grid}.scanner-wall-result{display:grid;grid-template-columns:minmax(300px,.72fr) minmax(360px,1.28fr);gap:16px;padding:13px 15px;border-bottom:1px solid var(--gex-border)}.scanner-wall-result:last-child{border-bottom:0}.scanner-wall-result:hover{background:color-mix(in srgb,var(--gex-selected) 42%,transparent)}.scanner-wall-result__identity{display:grid;align-content:start;gap:11px;min-width:0}.scanner-wall-result__identity>button{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;border:0;padding:0;background:transparent;color:var(--gex-data);cursor:pointer;text-align:left}.scanner-wall-result__identity>button strong{font:750 17px/1 var(--gex-number-font,ui-monospace,monospace)}.scanner-wall-result__identity>button span{font-size:10px}.scanner-wall-result dl{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:0}.scanner-wall-result dl div{min-width:0}.scanner-wall-result dt{color:var(--gex-muted);font-size:9px;letter-spacing:.05em;text-transform:uppercase}.scanner-wall-result dd{margin:2px 0 0;overflow:hidden;color:var(--gex-text);font:600 11px/1.3 var(--gex-number-font,ui-monospace,monospace);text-overflow:ellipsis;white-space:nowrap}.scanner-wall-result__tags{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.scanner-wall-tag{display:grid;gap:4px;min-width:0;border:1px solid var(--gex-border);border-radius:9px;padding:10px;background:var(--gex-raised);color:var(--gex-text);cursor:pointer;text-align:left;transition:border-color var(--gex-duration) var(--gex-ease),background-color var(--gex-duration) var(--gex-ease)}.scanner-wall-tag[data-side=call]{border-left:3px solid var(--gex-positive)}.scanner-wall-tag[data-side=put]{border-left:3px solid var(--gex-negative)}.scanner-wall-tag:hover,.scanner-wall-tag:focus-visible{background:color-mix(in srgb,var(--gex-selected) 72%,var(--gex-raised))}.scanner-wall-tag__title{display:flex;align-items:center;justify-content:space-between;gap:8px}.scanner-wall-tag__title b{font-size:11px}.scanner-wall-tag[data-side=call] .scanner-wall-tag__title b{color:var(--gex-positive)}.scanner-wall-tag[data-side=put] .scanner-wall-tag__title b{color:var(--gex-negative)}.scanner-wall-tag__title em{border-radius:999px;padding:2px 6px;background:var(--gex-data-soft);color:var(--gex-data);font-size:9px;font-style:normal;font-weight:700}.scanner-wall-tag__value{font:650 12px/1.2 var(--gex-number-font,ui-monospace,monospace)}.scanner-wall-tag__distance{color:var(--gex-muted);font:10px/1.3 var(--gex-number-font,ui-monospace,monospace)}.scanner-wall-tag__action{margin-top:2px;color:var(--gex-data);font-size:9px}.scanner-page[data-density=compact] .scanner-wall-result{padding-block:9px}.scanner-page[data-density=compact] .scanner-wall-tag{padding:7px 9px}
.scanner-coverage{display:flex;flex-wrap:wrap;gap:6px 14px;padding:2px;color:var(--gex-muted);font-size:10px}.scanner-coverage b{color:var(--gex-text);font-family:var(--gex-number-font,ui-monospace,monospace)}
.scanner-raw{border:1px solid var(--gex-border);border-radius:10px;padding:0 13px;background:color-mix(in srgb,var(--gex-raised) 48%,transparent)}.scanner-raw summary{padding:11px 0;color:var(--gex-muted);cursor:pointer;font-size:11px;font-weight:650}.scanner-raw p{margin:0 0 10px;color:var(--gex-muted);font-size:11px}.scanner-raw pre{max-height:340px;overflow:auto;margin:0 0 13px;border:1px solid var(--gex-border);border-radius:8px;padding:12px;background:var(--gex-bg);color:var(--gex-text);font:10px/1.55 var(--gex-number-font,ui-monospace,monospace);white-space:pre-wrap;word-break:break-word}.scanner-detail-title{display:flex;align-items:center;justify-content:space-between;gap:12px}.scanner-detail-title>div{display:grid;gap:3px}.scanner-detail-title span{color:var(--gex-data);font-size:10px;letter-spacing:.08em;text-transform:uppercase}.scanner-detail-title strong{color:var(--gex-text);font-size:15px}.scanner-detail{display:grid;gap:13px;color:var(--gex-text)}.scanner-detail__metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
@media(max-width:1100px){.scanner-results,.scanner-page[data-density=compact] .scanner-results{grid-template-columns:repeat(2,minmax(0,1fr))}.scanner-wall-result{grid-template-columns:1fr}.scanner-wall-context{grid-template-columns:1fr 1fr}.scanner-wall-legend{grid-column:1/-1;justify-content:start}}
@media(max-width:760px){.scanner-hero{align-items:stretch;flex-direction:column;padding:17px}.scanner-hero__controls{justify-items:stretch}.scanner-density{justify-content:space-between}.scanner-toolbar,.scanner-wall-toolbar{align-items:stretch;flex-direction:column}.scanner-toolbar .gex-segmented,.scanner-toolbar .gex-button,.scanner-wall-sort .gex-segmented{width:100%}.scanner-toolbar .gex-segmented button,.scanner-wall-sort .gex-segmented button{flex:1}.scanner-metrics,.scanner-detail__metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.scanner-results,.scanner-page[data-density=compact] .scanner-results{grid-template-columns:1fr}.scanner-wall-toolbar__filters{display:grid;grid-template-columns:1fr 1fr;width:100%}.scanner-wall-toolbar__filters .gex-button{grid-column:1/-1}.scanner-input-wrap input{width:100%}.scanner-wall-context{grid-template-columns:1fr}.scanner-wall-legend{grid-column:auto}.scanner-wall-result dl{grid-template-columns:repeat(2,minmax(0,1fr))}.scanner-detail-title{align-items:stretch;flex-direction:column}}
@media(max-width:440px){.scanner-hero :deep(.gex-tabs){display:grid;grid-template-columns:1fr 1fr;width:100%}.scanner-toolbar .gex-segmented{display:grid;grid-template-columns:repeat(3,1fr)}.scanner-metrics,.scanner-detail__metrics{grid-template-columns:1fr}.scanner-wall-toolbar__filters{grid-template-columns:1fr}.scanner-wall-toolbar__filters .gex-button{grid-column:auto}.scanner-wall-result__tags{grid-template-columns:1fr}.scanner-result-card__metrics{gap:4px}}
@media(prefers-reduced-motion:reduce){.scanner-result-card,.scanner-wall-tag{transition:none}.scanner-result-card:hover,.scanner-result-card:focus-within{transform:none}}
</style>
