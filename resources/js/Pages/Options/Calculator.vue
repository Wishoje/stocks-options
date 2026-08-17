<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick  } from 'vue'
import Chart from 'chart.js/auto'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppShell from '@/Components/AppShell.vue'
import axios from 'axios'
import {
  calculateLongOption,
  closestContract,
  contractIdentity,
  contractPremium,
  groupContractsByStrike,
  longOptionPayoff,
  normalizeContract,
  selectContractState,
  switchContractType,
} from '@/Support/calculator-contracts.js'
import { attachServerDte, normalizeUnderlying } from '@/Support/calculator-market-state.js'
import {
  abortableDelay,
  calculatorProgress,
  CALCULATOR_STATUS_MAX_REQUESTS,
  expirationReadinessMap,
  isRequestCancellation,
  readyExpiryToken,
  retryDelayMs,
  terminalRunState,
  workRunFromResponse,
} from '@/Support/calculator-refresh-state.js'

const initialSymbol =
  typeof window !== 'undefined'
    ? (localStorage.getItem('calculator_last_symbol') || 'SPY')
    : 'SPY'
const symbol         = ref(initialSymbol)
const underlyingQuote = ref(normalizeUnderlying(null))
const stockPrice     = ref(null)
const optionType     = ref('call') // 'call' | 'put'
const selectedOption = ref(null)
const contracts      = ref(1)
const loading        = ref(true)
const error          = ref('')
const expirations    = ref([])
const selectedExpiry = ref(null)
const chainData      = ref([])
const entryPrice     = ref(null) // per-share price YOU paid (or want)
const entryAuto      = ref(true)
const snapshotAt     = ref(null)
const refreshingLive = ref(false)
const refreshRun     = ref(null)
const refreshState   = ref('idle')
const refreshMessage = ref('')
const refreshProgress = ref(null)
const expiryReadiness = ref({})
const pollRequestCount = ref(0)

// scenario + view modes
const decayMode      = ref('breakeven')    // 'flat' | 'breakeven' | 'target'
const targetPrice    = ref(null)
const decayViewMode  = ref('compact') // 'compact' | 'full'

// strike band for chain list
const strikeBandMode = ref('wide') // 'near' | 'wide' | 'all'

let chart = null
let decayChart = null
let requestSequence = 0
let requestController = null
let mounted = false
const lastKnownGood = new Map()
const chartRef = ref(null)
const decayChartRef = ref(null)

// ---------- helpers ----------
const safeNumber = (val) => {
  if (val === null || val === undefined) return 0
  const num = typeof val === 'string' ? parseFloat(val) : Number(val)
  return isNaN(num) ? 0 : num
}

const positiveNumber = (val) => {
  if (val === null || val === undefined || val === '') return null
  const number = typeof val === 'string' ? Number.parseFloat(val) : Number(val)

  return Number.isFinite(number) && number > 0 ? number : null
}

const safePremium = (opt) => contractPremium(opt)
// ----- Black–Scholes helpers -----

const needsFollowUpPrime = (payload) => {
  if (!payload) return true

  const exp = payload.expirations || []
  const chain = payload.chain || []
  const status = payload.status || ''

  if (status === 'no_options' && payload.catalog_state === 'complete') return false

  if (!exp.length || !chain.length) return true
  if (status === 'no_snapshot' || status === 'no_expiry_snapshot') return true
  if (status === 'partial') return true

  return false
}

const requestKey = (sym = symbol.value, expiry = selectedExpiry.value) => (
  `${String(sym ?? '').trim().toUpperCase()}|${expiry ?? '*'}`
)

const beginRequestContext = () => {
  requestController?.abort()
  requestController = new AbortController()
  requestSequence += 1

  return {
    sequence: requestSequence,
    signal: requestController.signal,
    symbol: symbol.value,
    expiry: selectedExpiry.value,
  }
}

const resetRefreshObserver = ({ clearReadiness = false } = {}) => {
  requestController?.abort()
  requestController = null
  requestSequence += 1
  refreshingLive.value = false
  loading.value = false
  refreshRun.value = null
  refreshState.value = 'idle'
  refreshMessage.value = ''
  refreshProgress.value = null
  pollRequestCount.value = 0
  if (clearReadiness) expiryReadiness.value = {}
}

const currentRequest = (context) => mounted
  && !context.signal.aborted
  && context.sequence === requestSequence
  && context.symbol === symbol.value

const requestConfig = (context, config = {}) => ({
  ...config,
  signal: context.signal,
})

const rememberCurrentChain = () => {
  if (!chainData.value.length || !selectedExpiry.value) return

  lastKnownGood.set(requestKey(), {
    chain: chainData.value,
    expirations: expirations.value,
    underlying: underlyingQuote.value,
    snapshotAt: snapshotAt.value,
  })
}

const restoreKnownChain = () => {
  const known = lastKnownGood.get(requestKey())
  if (!known) {
    chainData.value = []
    selectedOption.value = null
    return false
  }

  chainData.value = known.chain
  expirations.value = known.expirations
  underlyingQuote.value = known.underlying
  stockPrice.value = known.underlying.price
  snapshotAt.value = known.snapshotAt

  return true
}

const clearKnownChainsForSymbol = (sym) => {
  const prefix = `${String(sym ?? '').trim().toUpperCase()}|`
  for (const key of lastKnownGood.keys()) {
    if (key.startsWith(prefix)) lastKnownGood.delete(key)
  }
}

const riskFreeRate = 0.04 // rough guess; tweak if you want

const normCdf = (x) => {
  // Abramowitz–Stegun approximation for N(x)
  const k = 1 / (1 + 0.2316419 * Math.abs(x))
  const kSum =
    k *
    (0.31938153 +
      k * (-0.356563782 +
      k * (1.781477937 +
      k * (-1.821255978 +
      k * 1.330274429))))
  const oneOverRootTwoPi = 1 / Math.sqrt(2 * Math.PI)
  const approx = 1 - oneOverRootTwoPi * Math.exp(-0.5 * x * x) * kSum
  return x >= 0 ? approx : 1 - approx
}

const bsPrice = (S, K, T, r, sigma, type) => {
  if (T <= 0 || sigma <= 0 || S <= 0 || K <= 0) {
    // at expiration: pure intrinsic
    const intrinsic =
      type === 'call'
        ? Math.max(S - K, 0)
        : Math.max(K - S, 0)
    return intrinsic
  }

  const sqrtT = Math.sqrt(T)
  const d1 = (Math.log(S / K) + (r + 0.5 * sigma * sigma) * T) / (sigma * sqrtT)
  const d2 = d1 - sigma * sqrtT

  if (type === 'call') {
    return S * normCdf(d1) - K * Math.exp(-r * T) * normCdf(d2)
  } else {
    return K * Math.exp(-r * T) * normCdf(-d2) - S * normCdf(-d1)
  }
}

const impliedVolBS = (price, S, K, T, r, type) => {
  // super simple bisection; good enough for UI
  if (price <= 0 || S <= 0 || K <= 0 || T <= 0) return 0.0

  let low = 0.01
  let high = 5.0
  let mid = 0.3

  for (let i = 0; i < 50; i++) {
    mid = 0.5 * (low + high)
    const est = bsPrice(S, K, T, r, mid, type)
    if (!Number.isFinite(est)) break

    if (est > price) {
      high = mid
    } else {
      low = mid
    }

    if (Math.abs(est - price) < 0.01) {
      break
    }
  }

  return mid
}


// Effective premium PER SHARE used as *entry price*
const effectivePremium = computed(() => {
  if (!entryAuto.value) return positiveNumber(entryPrice.value)
  // fallback: you “entered” at current mid
  return safePremium(selectedOption.value)
})

// ---------- main metrics ----------
const tradeSummary = computed(() => {
  return calculateLongOption({
    selectedContract: selectedOption.value,
    entryPrice: effectivePremium.value,
    contracts: contracts.value,
  })
})

const calculationReady = computed(() => tradeSummary.value !== null)

const totalCost = computed(() => {
  return tradeSummary.value?.cost ?? null
})

const breakeven = computed(() => {
  return tradeSummary.value?.breakeven ?? null
})

const maxLoss = computed(() => tradeSummary.value?.max_loss ?? null)

const cost = computed(() => tradeSummary.value?.cost ?? null)

const formatPrice = (val) => {
  if (val === null || val === undefined || val === '') return '\u2014'
  const n = typeof val === 'string' ? Number.parseFloat(val) : Number(val)
  if (!Number.isFinite(n)) return '—'
  return n.toFixed(2)
}

const formatMoney = (val) => {
  if (val === null || val === undefined || !Number.isFinite(Number(val))) return '\u2014'

  return Number(val).toLocaleString()
}

const priceRange = computed(() => {
  const center = underlyingQuote.value.usable ? stockPrice.value : null
  if (center === null) return []
  const width = center * 0.4
  const prices = []
  for (let i = 0; i <= 50; i++) {
    prices.push(center - width + (i * (width * 2)) / 50)
  }
  return prices
})

const profitData = computed(() => {
  if (!calculationReady.value) return []

  return priceRange.value.map((price) => longOptionPayoff({
    selectedContract: selectedOption.value,
    entryPrice: effectivePremium.value,
    contracts: contracts.value,
    underlyingPrice: price,
  }))
})

const moveNeeded = computed(() => {
  if (!calculationReady.value || stockPrice.value === null) return 'N/A'
  const be = breakeven.value
  const pct = ((be / stockPrice.value) - 1) * 100
  return (pct > 0 ? '+' : '') + Number(pct).toFixed(1) + '%'
})

// payoff table at expiration
const payoffTableRows = computed(() => {
  if (!calculationReady.value) return []

  return priceRange.value.map((p, idx) => {
    const pnl = profitData.value[idx] ?? 0
    const roi = totalCost.value > 0 ? (pnl / totalCost.value) * 100 : 0
    return {
      price: Number(p.toFixed(2)),
      pnl,
      roi,
    }
  })
})

// ---------- DTE + scenario ----------
const daysToExpiration = computed(() => {
  const rawDte = selectedOption.value?.dte
  if (rawDte === null || rawDte === undefined || rawDte === '') return null
  const dte = Number(rawDte)

  return Number.isInteger(dte) && dte >= 0 ? dte : null
})

const decayUnderlying = computed(() => {
  if (!calculationReady.value || stockPrice.value === null) return null

  if (decayMode.value === 'flat') {
    return stockPrice.value
  }

  if (decayMode.value === 'breakeven') {
    return breakeven.value > 0 ? breakeven.value : stockPrice.value
  }

  // 'target'
  const t = safeNumber(targetPrice.value)
  if (t > 0) return t

  // fallback to spot if target is not set
  return stockPrice.value
})

const timeDecayTitle = computed(() => {
  const S = decayUnderlying.value
  if (!selectedOption.value || !S) return 'Time Decay'

  if (decayMode.value === 'flat') {
    return `Flat @ Spot ($${S.toFixed(2)})`
  }
  if (decayMode.value === 'breakeven') {
    return `Flat @ Breakeven ($${S.toFixed(2)})`
  }
  return `Flat @ Target ($${S.toFixed(2)})`
})

/**
 * Time-decay table:
 * Approximate theoretical value if:
 *  - price = decayUnderlying (spot / breakeven / target)
 *  - implied vol is held constant
 *  - time to expiry shrinks from today's DTE down to 0
 *
 * Uses:
 *   - current mid for today's option value + IV fit
 *   - entryPrice / effectivePremium for P&L
 */
const timeDecayRows = computed(() => {
  if (!calculationReady.value) return []

  const dte = daysToExpiration.value
  if (dte === null || dte <= 0) return []

  const Sspot = stockPrice.value
  const S = decayUnderlying.value
  if (!S || S <= 0 || !Sspot || Sspot <= 0) return []

  const K = safeNumber(selectedOption.value.strike)
  const entry = effectivePremium.value
  const c = Math.max(1, safeNumber(contracts.value || 1))

  const currentMid = safePremium(selectedOption.value)
  if (currentMid === null || currentMid <= 0 || K <= 0 || entry === null) return []

  const Tyears = dte / 365
  const type = selectedOption.value.type

  // Prefer the selected contract's provider IV. Fit it only when the provider omitted it.
  let iv = positiveNumber(selectedOption.value.iv)
    ?? impliedVolBS(currentMid, Sspot, K, Tyears, riskFreeRate, type)

  // Fallback: if IV fails, bail out (no rows) instead of spewing nonsense
  if (!Number.isFinite(iv) || iv <= 0) {
    return []
  }

  const rows = []

  // daysRemaining: from "today" (dte) down to expiration (0)
  for (let daysRemaining = dte; daysRemaining >= 0; daysRemaining--) {
    const tau = daysRemaining / 365

    const theoPerShare = bsPrice(S, K, tau, riskFreeRate, iv, type)
    const pnl = (theoPerShare - entry) * 100 * c
    const roi = totalCost.value > 0 ? (pnl / totalCost.value) * 100 : 0

    rows.push({
      dte: daysRemaining,
      price: theoPerShare,
      pnl,
      roi,
    })
  }

  return rows
})

// compact vs full view for table
const visibleTimeDecayRows = computed(() => {
  const rows = timeDecayRows.value
  if (decayViewMode.value === 'full' || rows.length <= 16) {
    return rows
  }

  // keep: today (0), a small middle window, and expiry
  const first = rows[0]
  const last = rows[rows.length - 1]

  const windowSize = 6
  const midIndex = Math.floor(rows.length / 2)
  const start = Math.max(1, midIndex - Math.floor(windowSize / 2))
  const end = Math.min(rows.length - 2, start + windowSize - 1)

  const middle = rows.slice(start, end + 1)

  return [first, ...middle, last]
})

const hiddenTimeDecayCount = computed(() => {
  const total = timeDecayRows.value.length
  const visible = visibleTimeDecayRows.value.length
  return Math.max(total - visible, 0)
})

// ---------- chain display ----------
const strikesAroundPrice = computed(() => {
  const center = stockPrice.value
  if (!center || chainData.value.length === 0) return chainData.value

  if (strikeBandMode.value === 'all') {
    return chainData.value
  }

  const pct = strikeBandMode.value === 'near' ? 0.15 : 0.4
  const lo = center * (1 - pct)
  const hi = center * (1 + pct)
  const filtered = chainData.value.filter((o) => o.strike >= lo && o.strike <= hi)
  return filtered.length ? filtered : chainData.value
})

const groupedStrikes = computed(() => {
  return groupContractsByStrike(strikesAroundPrice.value)
})

const handleExpiryClick = async (value) => {
  if (selectedExpiry.value === value) return // no-op if same
  resetRefreshObserver()
  rememberCurrentChain()
  selectedExpiry.value = value
  selectedOption.value = null
  entryPrice.value = null
  entryAuto.value = true
  restoreKnownChain()

  const context = beginRequestContext()
  await loadChain({ context, startRefresh: true })
}

// ---------- API ----------
const mergeReadiness = (payload) => {
  expiryReadiness.value = {
    ...expiryReadiness.value,
    ...expirationReadinessMap(payload),
  }
}

const expirationStatus = (expiration) => {
  const value = String(expiration?.value ?? expiration ?? '').slice(0, 10)
  return expiryReadiness.value[value] ?? 'ready'
}

const expirationStatusLabel = (expiration) => {
  const status = expirationStatus(expiration)
  if (status === 'ready') return 'Ready'
  if (status === 'failed') return 'Unavailable'
  if (['preparing', 'pending', 'processing', 'running'].includes(status)) return 'Preparing'
  return 'Pending'
}

const publishableChainResponse = (data) => {
  const status = String(data?.status ?? 'ok').toLowerCase()
  const responseChain = Array.isArray(data?.chain) ? data.chain : []
  const canonicalReady = data?.selected_chain_state === 'ready'
    && data?.publication?.state === 'ready'
    && data?.publication?.source === 'canonical'

  return responseChain.length > 0 && (
    canonicalReady
    || !['partial', 'preparing', 'pending', 'no_snapshot', 'no_expiry_snapshot', 'failed'].includes(status)
  )
}

const publishChain = (data) => {
  const status = String(data?.status ?? 'ok').toLowerCase()
  const responseChain = Array.isArray(data?.chain) ? data.chain : []
  const publishable = publishableChainResponse(data)
  const responseExpirations = Array.isArray(data?.expirations) ? data.expirations : []

  mergeReadiness(data)
  const inferredReadiness = {}
  responseExpirations.forEach((item) => {
    const expiration = String(item?.value ?? item?.expiration ?? item?.expiration_date ?? '').slice(0, 10)
    if (expiration && !Object.hasOwn(expiryReadiness.value, expiration)) {
      inferredReadiness[expiration] = publishable ? 'ready' : 'pending'
    }
  })
  expiryReadiness.value = { ...expiryReadiness.value, ...inferredReadiness }
  if (responseExpirations.length && (publishable || !expirations.value.length)) {
    expirations.value = responseExpirations
  }
  const chainExpiries = [...new Set(responseChain
    .map((contract) => String(contract?.expiration_date ?? contract?.expiry ?? '').slice(0, 10))
    .filter(Boolean))]
  const resolvedExpiry = data.resolved_expiry
    ? String(data.resolved_expiry).slice(0, 10)
    : (chainExpiries.length === 1 ? chainExpiries[0] : null)
  const selectionBeforeResolution = selectedExpiry.value
  if (resolvedExpiry) selectedExpiry.value = resolvedExpiry
  if (!selectedExpiry.value && responseExpirations.length) {
    selectedExpiry.value = responseExpirations[0].value
  }

  if (!publishable
    && resolvedExpiry
    && selectionBeforeResolution
    && resolvedExpiry !== selectionBeforeResolution) {
    restoreKnownChain()
  }

  if (status === 'no_options' && data?.catalog_state === 'complete') {
    clearKnownChainsForSymbol(data?.underlying?.symbol ?? symbol.value)
    chainData.value = []
    expirations.value = responseExpirations
    selectedExpiry.value = null
    selectedOption.value = null
    snapshotAt.value = null
    underlyingQuote.value = normalizeUnderlying(data.underlying)
    stockPrice.value = underlyingQuote.value.price
    entryPrice.value = null
    entryAuto.value = true
    return false
  }

  if (!publishable) return false

  const nextUnderlying = normalizeUnderlying(data.underlying)
  underlyingQuote.value = nextUnderlying
  stockPrice.value = nextUnderlying.price
  chainData.value = attachServerDte(responseChain, responseExpirations)
    .map(normalizeContract)
    .filter(Boolean)
  loading.value = false
  snapshotAt.value = data.snapshot_at || null
  error.value = ''

  const previousIdentity = contractIdentity(selectedOption.value)
  const refreshedSelection = previousIdentity
    ? chainData.value.find((contract) => contractIdentity(contract) === previousIdentity)
    : null
  const opt = previousIdentity
    ? refreshedSelection
    : closestContract(chainData.value, optionType.value, stockPrice.value)

  if (opt) {
    selectOption(opt)
  } else {
    selectedOption.value = null
    entryPrice.value = null
    entryAuto.value = true
  }

  rememberCurrentChain()
  return true
}

const adoptRun = (response) => {
  const run = workRunFromResponse(response)
  if (!run) return false

  refreshRun.value = run
  refreshState.value = run.terminal ? 'idle' : 'running'
  refreshMessage.value = ''
  return true
}

const loadChain = async (opts = {}) => {
  const context = opts.context ?? beginRequestContext()
  const startRefresh = opts.startRefresh ?? false
  const followRun = opts.followRun ?? true
  const keepLoading = opts.keepLoading ?? (chainData.value.length > 0 || expirations.value.length > 0)
  if (!keepLoading) loading.value = true
  try {
    const response = await axios.get('/api/option-chain', requestConfig(context, {
      params: { symbol: context.symbol, expiry: context.expiry },
    }))
    if (!currentRequest(context)) return null

    const { data } = response
    const published = publishChain(data)
    const hasRun = adoptRun(data)
    if (hasRun) refreshRun.value.request_key = requestKey(context.symbol, context.expiry)
    const noOptions = data?.status === 'no_options' && data?.catalog_state === 'complete'
    const refreshContext = context.expiry === null
      ? context
      : { ...context, expiry: selectedExpiry.value ?? context.expiry }

    if (followRun && hasRun && !refreshRun.value.terminal) {
      loading.value = !chainData.value.length && !expirations.value.length
      await pollWorkRun(context)
    } else if (noOptions) {
      loading.value = false
      refreshState.value = 'no_options'
      refreshMessage.value = `No options are available for ${context.symbol}.`
    } else if (startRefresh && needsFollowUpPrime(data)) {
      loading.value = !chainData.value.length && !expirations.value.length
      await startCalculatorRefresh(refreshContext)
    } else if (!published && !chainData.value.length) {
      loading.value = false
      refreshState.value = 'idle'
    }

    return data
  } catch (e) {
    if (isRequestCancellation(e) || !currentRequest(context)) return null
    console.error(e)
    if (!chainData.value.length) error.value = 'Failed to load chain'
    return null
  } finally {
    if (currentRequest(context) && refreshState.value !== 'starting' && refreshState.value !== 'running') {
      loading.value = false
    }

    await nextTick()

    if (currentRequest(context)) {
      renderChart()
      renderDecayChart()
    }
  }
}

const selectOption = (opt) => {
  if (!opt) return

  localStorage.setItem('calculator_last_symbol', symbol.value)

  const next = selectContractState({
    contract: opt,
    entryMode: entryAuto.value ? 'auto' : 'manual',
    entryPrice: entryPrice.value,
  })

  selectedOption.value = next.selectedOption
  optionType.value = next.optionType
  entryAuto.value = next.entryMode === 'auto'

  // ✅ always follow selected contract price while auto mode is on
  if (entryAuto.value) {
    entryPrice.value = next.entryPrice
  }

  renderChart()
  renderDecayChart()
}

const switchOptionType = (targetType) => {
  if (targetType === optionType.value && selectedOption.value?.type === targetType) return

  const next = switchContractType({
    chain: chainData.value,
    selectedContract: selectedOption.value,
    targetType,
    entryMode: entryAuto.value ? 'auto' : 'manual',
    entryPrice: entryPrice.value,
  })

  optionType.value = next.optionType
  selectedOption.value = next.selectedOption
  entryAuto.value = next.entryMode === 'auto'
  entryPrice.value = next.entryPrice
}

const useLiveEntryPrice = () => {
  entryAuto.value = true
  entryPrice.value = contractPremium(selectedOption.value)
}

// ---------- charts ----------
const renderChart = () => {
  if (!chartRef.value || !calculationReady.value || profitData.value.length === 0) {
    if (chart) {
      chart.destroy()
      chart = null
    }
    return
  }
  const ctx = chartRef.value.getContext('2d')
  if (chart) chart.destroy()

  chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: priceRange.value.map((p) => p.toFixed(1)),
      datasets: [
        {
          label: 'P&L vs Price (Expiration)',
          data: profitData.value,
          borderColor: selectedOption.value.type === 'call' ? '#10b981' : '#ef4444',
          backgroundColor:
            selectedOption.value.type === 'call'
              ? 'rgba(16, 185, 129, 0.15)'
              : 'rgba(239, 68, 68, 0.15)',
          fill: true,
          tension: 0.4,
        },
      ],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { title: { display: true, text: 'Stock Price at Expiration' } },
        y: { title: { display: true, text: 'P&L ($)' } },
      },
    },
  })
}

const renderDecayChart = () => {
  const rows = timeDecayRows.value
  if (!decayChartRef.value || rows.length === 0) {
    if (decayChart) {
      decayChart.destroy()
      decayChart = null
    }
    return
  }

  const ctx = decayChartRef.value.getContext('2d')
  if (decayChart) decayChart.destroy()

  decayChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: rows.map((r) => r.dte),
      datasets: [
        {
          label: 'P&L vs Time',
          data: rows.map((r) => r.pnl),
          borderColor: selectedOption.value.type === 'call' ? '#22c55e' : '#f97316',
          backgroundColor:
            selectedOption.value.type === 'call'
              ? 'rgba(34, 197, 94, 0.15)'
              : 'rgba(249, 115, 22, 0.15)',
          fill: true,
          tension: 0.3,
        },
      ],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { title: { display: true, text: 'Days to Expiration' } },
        y: { title: { display: true, text: 'P&L ($)' } },
      },
    },
  })
}

// ---------- watchers ----------
watch(
  [
    selectedOption,
    optionType,
    contracts,
    () => stockPrice.value,
    entryPrice,
    decayMode,
    targetPrice,
  ],
  () => {
    renderChart()
    renderDecayChart()
  }
)

const onEntryPriceInput = () => {
  entryAuto.value = false
}
// NO watcher on selectedExpiry – we control it via handleExpiryClick + loadChain

// ---------- symbol selection handler ----------
const mergeProgressReadiness = (progress) => {
  const additions = {}
  Object.entries(progress?.readiness ?? {}).forEach(([expiration, item]) => {
    additions[expiration] = item.readiness
  })
  expiryReadiness.value = { ...expiryReadiness.value, ...additions }
}

const stableRequestFailure = (errorResponse) => {
  const status = Number(errorResponse?.status ?? 0)
  const retrySeconds = errorResponse
    ? Math.max(1, Math.round(retryDelayMs(errorResponse) / 1_000))
    : null

  if (status === 401) {
    refreshState.value = 'unauthorized'
    refreshMessage.value = 'Your session expired. Sign in again before refreshing.'
  } else if (status === 403) {
    refreshState.value = 'forbidden'
    refreshMessage.value = 'Your plan does not include calculator refreshes.'
  } else if (status === 429) {
    refreshState.value = 'rate_limited'
    refreshMessage.value = `Refresh capacity is busy. Try again in about ${retrySeconds} second${retrySeconds === 1 ? '' : 's'}.`
  } else {
    refreshState.value = 'failed'
    refreshMessage.value = errorResponse?.data?.message || 'The calculator refresh could not be started.'
  }

  loading.value = false
  refreshingLive.value = false
}

const pollWorkRun = async (context) => {
  const run = refreshRun.value
  if (!run?.status_url || !currentRequest(context)) return

  refreshState.value = 'running'
  refreshingLive.value = true
  const observedExpiry = selectedExpiry.value
  let previousReady = readyExpiryToken(refreshProgress.value, observedExpiry)

  for (let request = 0; request < CALCULATOR_STATUS_MAX_REQUESTS; request += 1) {
    if (!currentRequest(context)) return

    let response
    try {
      response = await axios.get(run.status_url, requestConfig(context))
    } catch (pollError) {
      if (isRequestCancellation(pollError) || !currentRequest(context)) return

      const status = Number(pollError?.response?.status ?? 0)
      if ([401, 403].includes(status)) {
        stableRequestFailure(pollError.response)
        return
      }
      pollRequestCount.value = request + 1
      try {
        await abortableDelay(retryDelayMs(pollError.response), context.signal)
      } catch (delayError) {
        if (isRequestCancellation(delayError)) return
        throw delayError
      }
      continue
    }

    if (!currentRequest(context)) return
    pollRequestCount.value = request + 1
    refreshRun.value = { ...run, ...workRunFromResponse(response), ...response.data }

    const progress = calculatorProgress(response.data)
    refreshProgress.value = progress
    mergeProgressReadiness(progress)
    const nextReady = readyExpiryToken(progress, observedExpiry)
    let progressChainData = null
    if (nextReady && nextReady !== previousReady) {
      previousReady = nextReady
      progressChainData = await loadChain({
        context,
        startRefresh: false,
        keepLoading: true,
        followRun: false,
      })
      if (!currentRequest(context)) return
    }

    const terminal = terminalRunState(response.data)
    if (terminal) {
      if (terminal === 'completed') {
        const progressReadSettled = publishableChainResponse(progressChainData)
          || (progressChainData?.status === 'no_options' && progressChainData?.catalog_state === 'complete')
        const terminalData = progressReadSettled
          ? progressChainData
          : await loadChain({
            context,
            startRefresh: false,
            keepLoading: true,
            followRun: false,
          })
        if (!currentRequest(context)) return
        if (terminalData?.status === 'no_options' && terminalData?.catalog_state === 'complete') {
          refreshState.value = 'no_options'
          refreshMessage.value = `No options are available for ${context.symbol}.`
        } else {
          refreshState.value = 'completed'
          refreshMessage.value = 'Calculator data is ready.'
        }
      } else {
        refreshState.value = 'failed'
        refreshMessage.value = response.data?.calculator?.failure_reason
          ?? response.data?.error?.message
          ?? response.data?.message
          ?? response.data?.error_code
          ?? 'The background refresh failed. Your last complete chain is still shown.'
      }
      refreshRun.value = { ...refreshRun.value, terminal: true }
      refreshingLive.value = false
      loading.value = false
      return
    }

    try {
      await abortableDelay(retryDelayMs(response), context.signal)
    } catch (delayError) {
      if (isRequestCancellation(delayError)) return
      throw delayError
    }
  }

  if (!currentRequest(context)) return
  refreshState.value = 'slow'
  refreshMessage.value = 'The refresh is still running in the background. Continue checking when you are ready.'
  refreshingLive.value = false
  loading.value = false
}

const startCalculatorRefresh = async (context, opts = {}) => {
  const key = requestKey(context.symbol, context.expiry)
  const active = refreshRun.value
    && !refreshRun.value.terminal
    && refreshRun.value.request_key === key

  if (active) {
    await pollWorkRun(context)
    return refreshRun.value
  }

  refreshState.value = 'starting'
  refreshMessage.value = ''
  refreshProgress.value = null
  refreshingLive.value = true
  pollRequestCount.value = 0

  try {
    const payload = { symbol: context.symbol }
    if (context.expiry) payload.expiry = context.expiry
    if (opts.force) payload.force = true

    const response = await axios.post(
      '/api/prime-calculator',
      payload,
      requestConfig(context),
    )
    if (!currentRequest(context)) return null

    const run = workRunFromResponse(response)
    if (!run) {
      refreshState.value = 'failed'
      refreshMessage.value = 'The server did not return a refresh status link.'
      loading.value = false
      refreshingLive.value = false
      return null
    }

    refreshRun.value = { ...run, request_key: key }
    await pollWorkRun(context)
    return refreshRun.value
  } catch (requestError) {
    if (isRequestCancellation(requestError) || !currentRequest(context)) return null
    console.warn('Prime calculator failed', context.symbol, requestError)
    stableRequestFailure(requestError.response)
    return null
  }
}

const refreshLiveData = async () => {
  if (refreshingLive.value && refreshState.value !== 'slow') return

  const context = beginRequestContext()
  error.value = ''

  const exactKey = requestKey(context.symbol, context.expiry)
  const catalogKey = requestKey(context.symbol, null)
  const runMatchesSelection = refreshRun.value?.request_key === exactKey
    || refreshRun.value?.request_key === catalogKey
  if (refreshRun.value && !refreshRun.value.terminal && runMatchesSelection) {
    await pollWorkRun(context)
    return
  }
  if (refreshRun.value && !runMatchesSelection) refreshRun.value = null

  await startCalculatorRefresh(context, { force: true })
}

const handleSelectSymbol = async (e) => {
  const sym = e.detail.symbol || 'SPY'
  if (sym === symbol.value && chainData.value.length) return

  rememberCurrentChain()
  resetRefreshObserver({ clearReadiness: true })
  symbol.value         = sym
  selectedExpiry.value = null
  selectedOption.value = null
  chainData.value      = []
  expirations.value    = []
  snapshotAt.value     = null
  entryPrice.value     = null
  targetPrice.value    = null
  underlyingQuote.value = normalizeUnderlying(null)
  stockPrice.value     = null
  entryAuto.value      = true   // ✅ reset auto on symbol change
  error.value          = ''
  loading.value        = true

  const context = beginRequestContext()
  await loadChain({ context, startRefresh: true })
}

// ---------- mounted / unmounted ----------
onMounted(async () => {
  mounted = true
  window.addEventListener('select-symbol', handleSelectSymbol)
  const context = beginRequestContext()
  await loadChain({ context, startRefresh: true })
})

onBeforeUnmount(() => {
  mounted = false
  resetRefreshObserver({ clearReadiness: true })
  window.removeEventListener('select-symbol', handleSelectSymbol)
  chart?.destroy()
  decayChart?.destroy()
})
</script>

<template>
  <AppLayout title="Live Options Calculator">
    <template #header>
      <h2 class="text-2xl font-bold text-white">Live Options Calculator (15-min delay)</h2>
    </template>

    <div class="py-6">
      <AppShell>
        <div class="max-w-7xl mx-auto px-6 space-y-6">
          <!-- Error first -->
          <div v-if="error" class="text-center py-20 text-red-400">
            {{ error }}
          </div>

          <!-- Global "booting" / loading / waiting for full data -->
          <div
            v-else-if="loading"
            class="text-center py-20"
          >
            <div
              class="inline-block animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-cyan-500"
            ></div>
            <p class="mt-4 text-gray-400">
              Preparing {{ symbol }} calculator…
            </p>
          </div>

          <!-- Main UI only when fully ready -->
          <div v-else class="space-y-6">
            <div
              v-if="refreshState === 'no_options'"
              class="rounded-xl border border-slate-500/40 bg-slate-900/50 px-4 py-3 text-sm text-slate-200"
              data-testid="calculator-no-options"
            >
              {{ refreshMessage }}
            </div>
            <div
              v-else-if="['starting', 'running'].includes(refreshState)"
              class="rounded-xl border border-cyan-500/30 bg-cyan-950/30 px-4 py-3 text-sm text-cyan-100"
              data-testid="calculator-refresh-running"
            >
              Preparing updated calculator data in the background.
              <span v-if="refreshProgress?.expected_count" class="ml-1 text-cyan-300">
                {{ refreshProgress.completed_count }} of {{ refreshProgress.expected_count }} expirations ready.
              </span>
            </div>
            <div
              v-else-if="refreshState === 'slow'"
              class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/40 bg-amber-950/30 px-4 py-3 text-sm text-amber-100"
              data-testid="calculator-refresh-slow"
            >
              <span>{{ refreshMessage }}</span>
              <button class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-medium text-white" @click="refreshLiveData">
                Continue checking
              </button>
            </div>
            <div
              v-else-if="['failed', 'rate_limited', 'unauthorized', 'forbidden'].includes(refreshState)"
              class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-red-500/40 bg-red-950/30 px-4 py-3 text-sm text-red-100"
              data-testid="calculator-refresh-failed"
            >
              <span>{{ refreshMessage }}</span>
              <button
                v-if="!['unauthorized', 'forbidden'].includes(refreshState)"
                class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white"
                @click="refreshLiveData"
              >
                Retry refresh
              </button>
            </div>
            <div
              v-if="!underlyingQuote.usable"
              class="rounded-xl border border-amber-500/40 bg-amber-950/30 px-4 py-3 text-sm text-amber-200"
            >
              A trustworthy underlying quote is unavailable. Contract cost, maximum loss, and breakeven remain available, but spot-dependent payoff and time-decay charts are paused.
            </div>
            <div
              v-else-if="underlyingQuote.status === 'stale'"
              class="rounded-xl border border-amber-500/30 bg-amber-950/20 px-4 py-3 text-xs text-amber-200"
            >
              Using a stale quote from {{ underlyingQuote.source || 'the market-data provider' }}
              <span v-if="underlyingQuote.asof"> (as of {{ underlyingQuote.asof }})</span>.
            </div>

            <!-- Expiry chips -->
            <div class="flex flex-wrap gap-2 items-center">
              <span class="text-xs text-gray-400 mr-1">Expiry:</span>
              <button
                v-for="exp in expirations"
                :key="exp.value"
                @click="handleExpiryClick(exp.value)"
                :class="selectedExpiry === exp.value ? 'bg-cyan-600' : 'bg-gray-700'"
                class="px-3 py-1.5 rounded-lg text-xs font-medium"
              >
                <span>{{ exp.label }}</span>
                <span
                  class="ml-1 text-[10px] opacity-80"
                  :data-readiness="expirationStatus(exp)"
                >
                  {{ expirationStatusLabel(exp) }}
                </span>
              </button>
              <span v-if="!expirations.length" class="text-xs text-amber-300">
                No expirations loaded yet.
              </span>
            </div>

            <div class="flex justify-center mt-4">
              <button
                @click="refreshLiveData"
                :disabled="refreshingLive"
                class="px-6 py-2 bg-cyan-600 rounded-lg hover:bg-cyan-700 transition"
              >
                {{ refreshingLive ? 'Refreshing...' : 'Refresh Live Data' }}
              </button>
            </div>

            <!-- Chain Table -->
            <div
              class="bg-white/10 backdrop-blur-xl rounded-2xl border border-gray-700/50 p-6 overflow-x-auto"
            >
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-bold">Live Chain</h3>
                <div class="flex items-center gap-2">
                  <span class="text-xs text-gray-400">Strikes:</span>
                  <button
                    class="px-2.5 py-1 rounded-full text-[11px] font-medium"
                    :class="strikeBandMode === 'near' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                    @click="strikeBandMode = 'near'"
                  >
                    Near (±15%)
                  </button>
                  <button
                    class="px-2.5 py-1 rounded-full text-[11px] font-medium"
                    :class="strikeBandMode === 'wide' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                    @click="strikeBandMode = 'wide'"
                  >
                    Wide (±40%)
                  </button>
                  <button
                    class="px-2.5 py-1 rounded-full text-[11px] font-medium"
                    :class="strikeBandMode === 'all' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                    @click="strikeBandMode = 'all'"
                  >
                    All
                  </button>
                </div>
              </div>
              <div class="max-h-[55vh] overflow-y-auto pr-2">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="text-gray-400 border-b border-gray-700">
                      <th class="text-left py-2">Strike</th>
                      <th class="text-left py-2">Call</th>
                      <th class="text-left py-2">Put</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="row in groupedStrikes"
                      :key="row.key"
                      :data-contract-family="row.family"
                      class="hover:bg-gray-800/50 cursor-pointer border-b border-gray-800"
                      :class="{
                        'bg-gray-800/30': selectedOption && [row.call, row.put]
                          .some((contract) => contractIdentity(contract) === contractIdentity(selectedOption)),
                      }"
                    >
                      <td class="py-3 font-mono">
                        <div>{{ row.strike }}</div>
                        <div v-if="row.show_family" class="text-[10px] text-cyan-300">
                          {{ row.family_label }} contract
                        </div>
                      </td>

                      <td
                        @click="selectOption(row.call)"
                        :data-contract-symbol="row.call?.contract_symbol ?? null"
                        :title="row.call?.contract_symbol ?? ''"
                        class="py-3"
                        :class="
                          contractIdentity(row.call) === contractIdentity(selectedOption)
                            ? 'text-emerald-400 font-bold'
                            : 'text-gray-300'
                        "
                      >
                        <span v-if="row.call">
                          ${{ formatPrice(safePremium(row.call)) }}
                        </span>
                        <span v-else>—</span>
                      </td>

                      <td
                        @click="selectOption(row.put)"
                        :data-contract-symbol="row.put?.contract_symbol ?? null"
                        :title="row.put?.contract_symbol ?? ''"
                        class="py-3"
                        :class="
                          contractIdentity(row.put) === contractIdentity(selectedOption)
                            ? 'text-red-400 font-bold'
                            : 'text-gray-300'
                        "
                      >
                        <span v-if="row.put">
                          ${{ formatPrice(safePremium(row.put)) }}
                        </span>
                        <span v-else>—</span>
                      </td>
                    </tr>
                    <tr v-if="!groupedStrikes.length">
                      <td colspan="3" class="py-6 text-center text-sm text-amber-300">
                        No chain rows yet. Click "Refresh Live Data" and wait a few seconds.
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Controls + Summary -->
            <div class="grid lg:grid-cols-3 gap-6">
              <div class="space-y-6">
                <div
                  class="bg-white/10 backdrop-blur-xl rounded-2xl border border-gray-700/50 p-6"
                >
                  <h3 class="text-xl font-bold mb-4">
                    {{ symbol }} @
                    <span v-if="underlyingQuote.usable">${{ formatPrice(stockPrice) }}</span>
                    <span v-else class="text-amber-300">Quote unavailable</span>
                  </h3>
                  <div class="space-y-4">
                    <div class="flex gap-3">
                      <button
                        @click="switchOptionType('call')"
                        :class="optionType === 'call' ? 'bg-emerald-600' : 'bg-gray-700'"
                        class="flex-1 py-3 rounded-lg font-medium"
                      >
                        Long Call
                      </button>
                      <button
                        @click="switchOptionType('put')"
                        :class="optionType === 'put' ? 'bg-red-600' : 'bg-gray-700'"
                        class="flex-1 py-3 rounded-lg font-medium"
                      >
                        Long Put
                      </button>
                    </div>

                    <div
                      v-if="selectedOption"
                      class="bg-gray-800/50 rounded-lg p-4 space-y-2"
                    >
                      <div class="text-sm text-gray-400">Selected</div>
                      <div class="font-mono text-lg text-cyan-300">
                        {{ selectedOption.expiry }} {{ selectedOption.strike }}
                        {{ selectedOption.type.toUpperCase() }}
                      </div>
                      <div class="text-sm">
                        <span class="text-gray-400">Mid:</span>
                        <span class="font-bold text-emerald-400 ml-2">
                          ${{ formatPrice(selectedOption.premium) }}
                        </span>
                      </div>
                    </div>
                    <div v-else class="rounded-lg bg-amber-950/30 p-3 text-sm text-amber-200">
                      No {{ optionType }} contract exists at the selected strike and expiration. Select another contract.
                    </div>

                    <div>
                      <label class="text-sm text-gray-300">Contracts</label>
                      <input
                        v-model.number="contracts"
                        type="number"
                        min="1"
                        class="w-full mt-2 px-4 py-3 bg-gray-800/70 border border-gray-600 rounded-lg text-white"
                      />
                    </div>

                    <div class="mt-4">
                      <label class="text-sm text-gray-300">
                        Entry price per share
                        <span class="ml-1 text-xs" :class="entryAuto ? 'text-cyan-300' : 'text-amber-300'">
                          ({{ entryAuto ? 'live mid' : 'manual' }})
                        </span>
                      </label>
                     <input
                        v-model.number="entryPrice"
                        @input="onEntryPriceInput"
                        type="number"
                        min="0"
                        step="0.01"
                        class="w-full mt-2 px-4 py-3 bg-gray-800/70 border border-gray-600 rounded-lg text-white"
                        placeholder="Leave blank to use live mid"
                      />
                    </div>
                    <button
                      type="button"
                      class="text-xs text-cyan-400 hover:text-cyan-300"
                      @click="useLiveEntryPrice"
                      :disabled="!selectedOption || selectedOption.premium === null"
                      :class="{ 'opacity-50 cursor-not-allowed': !selectedOption || selectedOption.premium === null }"
                    >
                      Use live mid for entry price
                    </button>
                  </div>
                </div>

                <div
                  class="bg-gradient-to-br from-cyan-600/20 to-blue-700/20 backdrop-blur-xl rounded-2xl border border-cyan-500/30 p-6"
                >
                  <h4 class="text-lg font-bold text-cyan-300 mb-4">Trade Summary</h4>
                  <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                      <span class="text-gray-400">Breakeven</span>
                      <span class="font-bold text-white">{{ breakeven === null ? '\u2014' : `$${formatPrice(breakeven)}` }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-gray-400">Max Loss</span>
                      <span class="font-bold text-red-400">
                        {{ maxLoss === null ? '\u2014' : `$${formatMoney(Math.abs(maxLoss))}` }}
                      </span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-gray-400">Cost</span>
                      <span class="font-bold text-white">
                        {{ cost === null ? '\u2014' : `$${formatMoney(cost)}` }}
                      </span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-gray-400">Move Needed</span>
                      <span class="font-bold text-cyan-400">{{ moveNeeded }}</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="lg:col-span-2 space-y-6">
                <!-- charts -->
                <div class="bg-white/10 backdrop-blur-xl rounded-2xl border border-gray-700/50 p-6">
                  <p v-if="!underlyingQuote.usable" class="text-sm text-amber-300" data-testid="calculator-charts-paused">
                    Spot-dependent charts are paused until a trustworthy underlying quote is available.
                  </p>
                  <p v-else-if="!calculationReady" class="mb-4 text-sm text-amber-300">
                    Select a priced contract to view calculations.
                  </p>
                  <div v-else class="grid lg:grid-cols-2 gap-6">
                    <div>
                      <h3 class="text-xl font-bold mb-4">P&L vs Price (at Expiration)</h3>
                      <canvas ref="chartRef" class="w-full h-80"></canvas>
                    </div>

                    <div>
                      <h3 class="text-xl font-bold mb-1">P&L vs Time</h3>
                      <p class="text-xs text-gray-400 mb-3">
                        Scenario: {{ timeDecayTitle }} • DTE: {{ daysToExpiration ?? 'Unavailable' }}
                      </p>
                      <canvas ref="decayChartRef" class="w-full h-80"></canvas>
                    </div>
                  </div>
                </div>

                <!-- Time Decay Table (before payoff) -->
                <div
                  class="bg-white/10 backdrop-blur-xl rounded-2xl border border-gray-700/50 p-6"
                >
                  <div class="flex items-center justify-between mb-3">
                    <div>
                      <h3 class="text-lg font-bold">
                        {{ timeDecayTitle }}
                      </h3>
                      <span class="text-xs text-gray-400">
                        DTE: {{ daysToExpiration ?? 'Unavailable' }}<template v-if="daysToExpiration !== null">
                          day<span v-if="daysToExpiration !== 1">s</span>
                        </template>
                      </span>
                    </div>

                    <div class="flex items-center gap-2">
                      <span class="text-xs text-gray-400 mr-1">View:</span>
                      <button
                        class="px-3 py-1 rounded-full text-xs font-medium"
                        :class="decayViewMode === 'compact' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                        @click="decayViewMode = 'compact'"
                      >
                        Compact
                      </button>
                      <button
                        class="px-3 py-1 rounded-full text-xs font-medium"
                        :class="decayViewMode === 'full' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                        @click="decayViewMode = 'full'"
                      >
                        Full
                      </button>
                    </div>
                  </div>

                  <!-- Scenario controls -->
                  <div class="flex flex-wrap items-center gap-3 mb-4">
                    <span class="text-xs text-gray-400 mr-1">Scenario:</span>

                    <button
                      class="px-3 py-1 rounded-full text-xs font-medium"
                      :class="decayMode === 'flat' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                      @click="decayMode = 'flat'"
                    >
                      Flat @ Spot
                    </button>

                    <button
                      class="px-3 py-1 rounded-full text-xs font-medium"
                      :class="decayMode === 'breakeven' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                      @click="decayMode = 'breakeven'"
                    >
                      Flat @ Breakeven
                    </button>

                    <button
                      class="px-3 py-1 rounded-full text-xs font-medium"
                      :class="decayMode === 'target' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                      @click="decayMode = 'target'"
                    >
                      Flat @ Target
                    </button>

                    <input
                      v-if="decayMode === 'target'"
                      v-model.number="targetPrice"
                      type="number"
                      min="0"
                      step="0.1"
                      class="ml-2 px-3 py-1.5 bg-gray-800/70 border border-gray-600 rounded-lg text-xs text-white w-28"
                      placeholder="Target"
                    />
                  </div>

                  <p class="text-xs text-gray-400 mb-3">
                    Approximate option value and P&amp;L per day if the stock stays at the selected
                    scenario price and time value decays linearly into expiration.
                  </p>

                  <div class="max-h-80 overflow-y-auto">
                    <table class="w-full text-sm">
                      <thead>
                        <tr class="text-gray-400 border-b border-gray-700">
                          <th class="text-left py-2">Days to Exp</th>
                          <th class="text-left py-2">Option Price ($)</th>
                          <th class="text-left py-2">P&amp;L ($)</th>
                          <th class="text-left py-2">ROI (%)</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr
                          v-for="row in visibleTimeDecayRows"
                          :key="row.dte"
                          :class="[
                            'border-b border-gray-800',
                            row.pnl > 0 ? 'bg-emerald-900/20' : '',
                            row.pnl < 0 ? 'bg-red-900/10' : '',
                          ]"
                        >
                          <td class="py-2 font-mono">{{ row.dte }}</td>
                          <td class="py-2 font-mono">
                            ${{ row.price.toFixed(2) }}
                          </td>
                          <td
                            class="py-2 font-mono"
                            :class="row.pnl >= 0 ? 'text-emerald-400' : 'text-red-400'"
                          >
                            ${{ row.pnl.toFixed(0) }}
                          </td>
                          <td class="py-2 font-mono text-gray-300">
                            {{ row.roi.toFixed(1) }}%
                          </td>
                        </tr>

                        <tr
                          v-if="decayViewMode === 'compact' && hiddenTimeDecayCount > 0"
                        >
                          <td colspan="4" class="py-2 text-center text-xs text-gray-500">
                            … {{ hiddenTimeDecayCount }} more day<span v-if="hiddenTimeDecayCount !== 1">s</span> hidden.
                            Switch to <span class="font-semibold">Full</span> view to see all.
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>

                <!-- Payoff table (after time decay) -->
                <div
                  class="bg-white/10 backdrop-blur-xl rounded-2xl border border-gray-700/50 p-6"
                >
                  <h3 class="text-lg font-bold mb-4">Payoff Table (at Expiration)</h3>
                  <div class="max-h-80 overflow-y-auto">
                    <table class="w-full text-sm">
                      <thead>
                        <tr class="text-gray-400 border-b border-gray-700">
                          <th class="text-left py-2">Stock Price</th>
                          <th class="text-left py-2">P&amp;L ($)</th>
                          <th class="text-left py-2">ROI (%)</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr
                          v-for="row in payoffTableRows"
                          :key="row.price"
                          :class="[
                            'border-b border-gray-800',
                            row.pnl > 0 ? 'bg-emerald-900/20' : '',
                            row.pnl < 0 ? 'bg-red-900/10' : '',
                          ]"
                        >
                          <td class="py-2 font-mono">${{ row.price.toFixed(2) }}</td>
                          <td
                            class="py-2 font-mono"
                            :class="row.pnl >= 0 ? 'text-emerald-400' : 'text-red-400'"
                          >
                            ${{ row.pnl.toFixed(0) }}
                          </td>
                          <td class="py-2 font-mono text-gray-300">
                            {{ row.roi.toFixed(1) }}%
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>

                <!-- quick stats -->
                <div class="grid grid-cols-3 gap-4 text-center">
                  <div
                    class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700"
                  >
                    <div class="text-2xl font-bold text-red-400">
                      {{ maxLoss === null ? '\u2014' : `$${formatMoney(Math.abs(maxLoss))}` }}
                    </div>
                    <div class="text-xs text-gray-400">Max Risk</div>
                  </div>
                  <div
                    class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700"
                  >
                    <div class="text-2xl font-bold text-yellow-400">1 : Infinity</div>
                    <div class="text-xs text-gray-400">R:R (long option)</div>
                  </div>
                  <div
                    class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700"
                  >
                    <div class="text-2xl font-bold text-cyan-400">{{ moveNeeded }}</div>
                    <div class="text-xs text-gray-400">Move Needed</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </AppShell>
    </div>
  </AppLayout>
</template>
