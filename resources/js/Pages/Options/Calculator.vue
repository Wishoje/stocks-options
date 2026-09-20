<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import Chart from 'chart.js/auto'
import '../../../css/calculator-refresh.css'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppShell from '@/Components/AppShell.vue'
import axios from 'axios'
import {
  calculateLongOption,
  closestContract,
  contractIdentity,
  contractPremium,
  groupContractsByStrike,
  longOptionProfitPotential,
  longOptionPayoff,
  normalizeContract,
  selectContractState,
  switchContractType,
  validContractQuantity,
} from '@/Support/calculator-contracts.js'
import {
  attachServerDte,
  calculatorUnderlyingPrice,
  normalizeUnderlying,
  positiveCalculatorPrice,
} from '@/Support/calculator-market-state.js'
import { createCalculatorChartScheduler } from '@/Support/calculator-chart-scheduler.js'
import {
  clampScenarioIndex,
  moveScenarioIndex,
  nearestScenarioIndex,
  scenarioValuePixel,
} from '@/Support/calculator-scenario-inspection.js'
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
const manualUnderlying = ref(false)
const manualUnderlyingPrice = ref('')
const calculationUnderlyingPrice = computed(() => calculatorUnderlyingPrice(
  underlyingQuote.value, manualUnderlying.value, manualUnderlyingPrice.value,
))
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
const selectedPayoffIndex = ref(null)
const selectedDecayIndex = ref(null)
const exactContractsOpen = ref(false)
const responseMetadata = ref({})
const rawExpirations = ref([])

// ---------- helpers ----------
const safeNumber = (val) => {
  if (val === null || val === undefined) return 0
  const num = typeof val === 'string' ? parseFloat(val) : Number(val)
  return isNaN(num) ? 0 : num
}

const positiveNumber = positiveCalculatorPrice

const safePremium = (opt) => contractPremium(opt)
// ----- Black-Scholes helpers -----

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
    responseMetadata: responseMetadata.value,
    rawExpirations: rawExpirations.value,
  })
}

const restoreKnownChain = () => {
  const known = lastKnownGood.get(requestKey())
  if (!known) {
    chainData.value = []
    selectedOption.value = null
    snapshotAt.value = null
    responseMetadata.value = {}
    rawExpirations.value = []
    return false
  }

  chainData.value = known.chain
  expirations.value = known.expirations
  underlyingQuote.value = known.underlying
  stockPrice.value = known.underlying.price
  snapshotAt.value = known.snapshotAt
  responseMetadata.value = known.responseMetadata ?? {}
  rawExpirations.value = known.rawExpirations ?? []

  return true
}

const clearKnownChainsForSymbol = (sym) => {
  const prefix = `${String(sym ?? '').trim().toUpperCase()}|`
  for (const key of lastKnownGood.keys()) {
    if (key.startsWith(prefix)) lastKnownGood.delete(key)
  }
}

const riskFreeRate = 0.04 // Model assumption disclosed beside the time-decay chart.

const normCdf = (x) => {
  // Abramowitz-Stegun approximation for N(x)
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
  // fallback: the entry is the current pricing input
  return safePremium(selectedOption.value)
})

const contractsInvalid = computed(() => validContractQuantity(contracts.value) === null)
const entryPriceInvalid = computed(() => (
  !entryAuto.value && positiveNumber(entryPrice.value) === null
))

const calculationBlockedMessage = computed(() => {
  if (contractsInvalid.value) return 'Enter a positive whole number of contracts to view calculations.'
  if (entryPriceInvalid.value) return 'Enter a finite entry price greater than zero to view calculations.'
  if (!selectedOption.value) return 'Select a priced contract to view calculations.'

  return 'A valid contract price is required to view calculations.'
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

const profitPotential = computed(() => longOptionProfitPotential({
  selectedContract: selectedOption.value,
  entryPrice: effectivePremium.value,
  contracts: contracts.value,
}))

const profitPotentialLabel = computed(() => {
  if (!profitPotential.value) return '\u2014'
  if (profitPotential.value.unlimited) return 'Unlimited'
  if (profitPotential.value.maximum_profit <= 0) return '$0'

  return `$${formatMoney(profitPotential.value.maximum_profit)}`
})

const rewardRiskLabel = computed(() => {
  if (!profitPotential.value) return '\u2014'
  if (profitPotential.value.unlimited) return 'Unlimited'
  if (!Number.isFinite(profitPotential.value.reward_to_risk)) return '\u2014'

  return `${profitPotential.value.reward_to_risk.toFixed(2)} : 1`
})

const formatPrice = (val) => {
  if (val === null || val === undefined || val === '') return '\u2014'
  const n = typeof val === 'string' ? Number.parseFloat(val) : Number(val)
  if (!Number.isFinite(n)) return '\u2014'
  return n.toFixed(2)
}

const formatMoney = (val) => {
  if (val === null || val === undefined || !Number.isFinite(Number(val))) return '\u2014'

  return Number(val).toLocaleString()
}

const priceRange = computed(() => {
  // Expiration payoff is a function of a hypothetical future price, not a quote.
  const center = calculationUnderlyingPrice.value ?? positiveNumber(selectedOption.value?.strike)
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
  if (!calculationReady.value || calculationUnderlyingPrice.value === null) return 'N/A'
  const be = breakeven.value
  const pct = ((be / calculationUnderlyingPrice.value) - 1) * 100
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

const payoffDefaultIndex = computed(() => nearestScenarioIndex(
  payoffTableRows.value,
  calculationUnderlyingPrice.value ?? breakeven.value,
))

const resolvedPayoffIndex = computed(() => {
  const rows = payoffTableRows.value
  if (!rows.length) return null

  return selectedPayoffIndex.value === null
    ? payoffDefaultIndex.value
    : clampScenarioIndex(selectedPayoffIndex.value, rows.length)
})

const selectedPayoffRow = computed(() => (
  resolvedPayoffIndex.value === null ? null : payoffTableRows.value[resolvedPayoffIndex.value]
))

// ---------- DTE + scenario ----------
const daysToExpiration = computed(() => {
  const rawDte = selectedOption.value?.dte
  if (rawDte === null || rawDte === undefined || rawDte === '') return null
  const dte = Number(rawDte)

  return Number.isInteger(dte) && dte >= 0 ? dte : null
})

const decayUnderlying = computed(() => {
  const underlying = calculationUnderlyingPrice.value
  if (!calculationReady.value || underlying === null) return null

  if (decayMode.value === 'flat') {
    return underlying
  }

  if (decayMode.value === 'breakeven') {
    return breakeven.value > 0 ? breakeven.value : underlying
  }

  // 'target'
  return positiveNumber(targetPrice.value)
})

const timeDecayTitle = computed(() => {
  const S = decayUnderlying.value
  if (!selectedOption.value || !S) return 'Time Decay'

  if (decayMode.value === 'flat') {
    return `Flat @ ${manualUnderlying.value ? 'Manual Underlying' : 'Spot'} ($${S.toFixed(2)})`
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
 *   - current resolved contract price for today's option value + IV fit
 *   - entryPrice / effectivePremium for P&L
 */
const timeDecayRows = computed(() => {
  if (!calculationReady.value) return []

  const dte = daysToExpiration.value
  if (dte === null || dte <= 0) return []

  const Sspot = calculationUnderlyingPrice.value
  const S = decayUnderlying.value
  if (!S || S <= 0 || !Sspot || Sspot <= 0) return []

  const K = safeNumber(selectedOption.value.strike)
  const entry = effectivePremium.value
  const c = tradeSummary.value.contracts

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

const resolvedDecayIndex = computed(() => {
  if (!timeDecayRows.value.length) return null

  return selectedDecayIndex.value === null
    ? 0
    : clampScenarioIndex(selectedDecayIndex.value, timeDecayRows.value.length)
})

const selectedDecayRow = computed(() => (
  resolvedDecayIndex.value === null ? null : timeDecayRows.value[resolvedDecayIndex.value]
))

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

  const selected = selectedDecayRow.value
  const visible = [first, ...middle, last, selected].filter(Boolean)
  const dte = new Set()

  return visible
    .filter((row) => {
      if (dte.has(row.dte)) return false
      dte.add(row.dte)
      return true
    })
    .sort((left, right) => rows.indexOf(left) - rows.indexOf(right))
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

const formatExact = (value) => {
  if (value === null || value === undefined || value === '') return '\u2014'

  return String(value)
}

const formatSourceTime = (value) => {
  if (!value) return 'Unavailable'
  const text = String(value)
  if (!/(?:z|[+-]\d{2}:?\d{2})$/i.test(text)) return `${text} (timezone unavailable)`
  const date = new Date(text)
  if (Number.isNaN(date.getTime())) return text

  return new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/New_York',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    timeZoneName: 'short',
  }).format(date)
}

const quoteState = computed(() => {
  if (manualUnderlying.value) return calculationUnderlyingPrice.value === null ? 'invalid' : 'manual'
  if (!underlyingQuote.value.usable) return 'unavailable'
  if (underlyingQuote.value.status === 'stale') return 'stale'

  return 'live'
})

const quoteStateLabel = computed(() => ({
  invalid: 'Manual price invalid',
  manual: 'Manual scenario',
  unavailable: 'Quote unavailable',
  stale: 'Delayed or stale quote',
  live: 'Accepted market quote',
}[quoteState.value]))

const selectedContractLabel = computed(() => {
  if (!selectedOption.value) return `No ${optionType.value} selected`

  return `${selectedOption.value.expiry} \u00b7 $${formatPrice(selectedOption.value.strike)} \u00b7 ${selectedOption.value.type.toUpperCase()}`
})

const premiumSourceLabel = computed(() => ({
  mid: 'Mid',
  mid_price: 'Mid',
  bid_ask_midpoint: 'Mid',
  mark: 'Mark',
  price: 'Price',
  last: 'Last',
  fmv: 'FMV',
  bid: 'Bid fallback',
  ask: 'Ask fallback',
}[selectedOption.value?.premium_source] ?? 'Pricing input'))

const selectPayoffIndex = (value, { focusChart = true } = {}) => {
  const next = clampScenarioIndex(value, payoffTableRows.value.length)
  selectedPayoffIndex.value = next
  if (focusChart && next !== null) highlightChartPoint(chart, next)
}

const selectDecayIndex = (value, { focusChart = true } = {}) => {
  const next = clampScenarioIndex(value, timeDecayRows.value.length)
  selectedDecayIndex.value = next
  if (focusChart && next !== null) highlightChartPoint(decayChart, next)
}

const handleScenarioKeydown = (event, kind) => {
  const payoff = kind === 'payoff'
  const rows = payoff ? payoffTableRows.value : timeDecayRows.value
  const current = payoff ? resolvedPayoffIndex.value : resolvedDecayIndex.value
  const direction = {
    ArrowLeft: 'previous',
    ArrowUp: 'previous',
    ArrowRight: 'next',
    ArrowDown: 'next',
    Home: 'first',
    End: 'last',
  }[event.key]
  if (!direction || !rows.length) return

  event.preventDefault()
  const next = moveScenarioIndex(current, direction, rows.length)
  if (payoff) selectPayoffIndex(next)
  else selectDecayIndex(next)
}

const handleExpiryClick = async (value) => {
  if (selectedExpiry.value === value) return // no-op if same
  resetRefreshObserver()
  rememberCurrentChain()
  selectedExpiry.value = value
  selectedOption.value = null
  selectedPayoffIndex.value = null
  selectedDecayIndex.value = null
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
    responseMetadata.value = Object.fromEntries(Object.entries(data ?? {}).filter(([key]) => (
      !['chain', 'expirations'].includes(key)
    )))
    rawExpirations.value = responseExpirations
    return false
  }

  if (!publishable) return false

  responseMetadata.value = Object.fromEntries(Object.entries(data ?? {}).filter(([key]) => (
    !['chain', 'expirations'].includes(key)
  )))
  rawExpirations.value = responseExpirations

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
  }
}

const selectOption = (opt) => {
  if (!opt) return

  localStorage.setItem('calculator_last_symbol', symbol.value)

  const previousIdentity = contractIdentity(selectedOption.value)
  const next = selectContractState({
    contract: opt,
    entryMode: entryAuto.value ? 'auto' : 'manual',
    entryPrice: entryPrice.value,
  })

  selectedOption.value = next.selectedOption
  optionType.value = next.optionType
  entryAuto.value = next.entryMode === 'auto'

  // Always follow the selected contract price while automatic mode is on.
  if (entryAuto.value) {
    entryPrice.value = next.entryPrice
  }
  if (previousIdentity !== contractIdentity(next.selectedOption)) {
    selectedPayoffIndex.value = null
    selectedDecayIndex.value = null
  }
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
  selectedPayoffIndex.value = null
  selectedDecayIndex.value = null
}

const useLiveEntryPrice = () => {
  entryAuto.value = true
  entryPrice.value = contractPremium(selectedOption.value)
}

// ---------- charts ----------
const highlightChartPoint = (instance, index) => {
  if (!instance || index === null) return
  const active = [{ datasetIndex: 0, index }]
  instance.setActiveElements?.(active)
  instance.tooltip?.setActiveElements?.(active, { x: 0, y: 0 })
  instance.update?.('none')
}

const calculatorReferencesPlugin = {
  id: 'calculatorReferences',
  afterDatasetsDraw(instance, _args, options) {
    const { ctx, chartArea } = instance
    if (!ctx || !chartArea || !Array.isArray(options?.references)) return

    options.references.forEach((reference) => {
      const x = scenarioValuePixel(
        reference.value,
        options.domainStart,
        options.domainEnd,
        chartArea.left,
        chartArea.right,
      )
      if (!Number.isFinite(x)) return

      ctx.save()
      ctx.strokeStyle = reference.color
      ctx.fillStyle = reference.color
      ctx.lineWidth = 1
      ctx.setLineDash([4, 4])
      ctx.beginPath()
      ctx.moveTo(x, chartArea.top)
      ctx.lineTo(x, chartArea.bottom)
      ctx.stroke()
      ctx.setLineDash([])
      ctx.font = '11px system-ui, sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText(reference.label, x, chartArea.top + 12)
      ctx.restore()
    })
  },
}

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

  const references = [
    calculationUnderlyingPrice.value === null ? null : {
      value: calculationUnderlyingPrice.value,
      label: manualUnderlying.value ? 'Scenario' : 'Current',
      color: '#7dd3fc',
    },
    breakeven.value === null ? null : {
      value: breakeven.value,
      label: 'Breakeven',
      color: '#fbbf24',
    },
  ].filter(Boolean)

  chart = new Chart(ctx, {
    type: 'line',
    plugins: [calculatorReferencesPlugin],
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
          tension: 0,
          pointRadius: 2,
          pointHoverRadius: 6,
          pointHitRadius: 14,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      interaction: { mode: 'nearest', intersect: false },
      onClick: (_event, elements) => {
        if (elements?.[0]) selectPayoffIndex(elements[0].index, { focusChart: false })
      },
      plugins: {
        legend: { display: false },
        calculatorReferences: {
          references,
          domainStart: priceRange.value[0],
          domainEnd: priceRange.value[priceRange.value.length - 1],
        },
        tooltip: {
          callbacks: {
            title: (items) => `Underlying $${payoffTableRows.value[items[0]?.dataIndex]?.price?.toFixed(2) ?? '\u2014'}`,
            label: (item) => `P&L $${Number(item.raw).toLocaleString(undefined, { maximumFractionDigits: 2 })}`,
          },
        },
      },
      scales: {
        x: {
          grid: { color: 'rgba(148, 163, 184, .08)' },
          ticks: { color: '#8fa1b3', maxTicksLimit: 7 },
          title: { display: true, text: 'Stock Price at Expiration', color: '#8fa1b3' },
        },
        y: {
          grid: { color: 'rgba(148, 163, 184, .12)' },
          ticks: { color: '#8fa1b3' },
          title: { display: true, text: 'P&L ($)', color: '#8fa1b3' },
        },
      },
    },
  })
  highlightChartPoint(chart, resolvedPayoffIndex.value)
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
          tension: 0,
          pointRadius: 2,
          pointHoverRadius: 6,
          pointHitRadius: 14,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      interaction: { mode: 'nearest', intersect: false },
      onClick: (_event, elements) => {
        if (elements?.[0]) selectDecayIndex(elements[0].index, { focusChart: false })
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            title: (items) => `${timeDecayRows.value[items[0]?.dataIndex]?.dte ?? '\u2014'} days to expiration`,
            label: (item) => `P&L $${Number(item.raw).toLocaleString(undefined, { maximumFractionDigits: 2 })}`,
          },
        },
      },
      scales: {
        x: {
          grid: { color: 'rgba(148, 163, 184, .08)' },
          ticks: { color: '#8fa1b3', maxTicksLimit: 7 },
          title: { display: true, text: 'Days to Expiration', color: '#8fa1b3' },
        },
        y: {
          grid: { color: 'rgba(148, 163, 184, .12)' },
          ticks: { color: '#8fa1b3' },
          title: { display: true, text: 'P&L ($)', color: '#8fa1b3' },
        },
      },
    },
  })
  highlightChartPoint(decayChart, resolvedDecayIndex.value)
}

const chartScheduler = createCalculatorChartScheduler(() => {
  if (mounted) {
    renderChart()
    renderDecayChart()
  }
})

// The derived series include every calculation dependency. Canvas refs cover
// loading/empty-state transitions; post-flush ensures the DOM is ready first.
watch(
  [profitData, timeDecayRows, chartRef, decayChartRef],
  () => chartScheduler.schedule(),
  { flush: 'post' },
)

const onEntryPriceInput = (event) => {
  entryAuto.value = false
  entryPrice.value = event.target.value
}

const onUnderlyingPriceInput = (event) => {
  manualUnderlying.value = true
  manualUnderlyingPrice.value = event.target.value
}

const useQuotedUnderlying = () => {
  manualUnderlying.value = false
  manualUnderlyingPrice.value = ''
}
// No watcher on selectedExpiry; handleExpiryClick and loadChain own this transition.

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
  selectedPayoffIndex.value = null
  selectedDecayIndex.value = null
  exactContractsOpen.value = false
  responseMetadata.value = {}
  rawExpirations.value = []
  underlyingQuote.value = normalizeUnderlying(null)
  stockPrice.value     = null
  useQuotedUnderlying()
  entryAuto.value      = true
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
  chartScheduler.dispose()
  resetRefreshObserver({ clearReadiness: true })
  window.removeEventListener('select-symbol', handleSelectSymbol)
  chart?.destroy()
  decayChart?.destroy()
  chart = null
  decayChart = null
})
</script>

<template>
  <AppLayout title="Options Calculator">
    <template #header>
      <div class="calculator-app-header">
        <div><span>Strategy workspace</span><h2>Options Calculator</h2></div>
        <span class="calculator-app-header__delay">Quote source and timing are shown with the selected contract</span>
      </div>
    </template>
    <div class="calculator-page">
      <AppShell>
        <main class="calculator-workspace" aria-labelledby="calculator-title">
          <section class="calculator-hero" aria-describedby="calculator-intro">
            <div>
              <span class="calculator-eyebrow">Long option planner</span>
              <h1 id="calculator-title">Build the trade, then inspect every outcome</h1>
              <p id="calculator-intro">Choose an expiration and contract, set the position assumptions, and compare expiration payoff with a constant-IV time-decay estimate.</p>
            </div>
            <div class="calculator-hero__actions">
              <div class="calculator-context-chip" :data-state="quoteState"><span>{{ symbol }}</span><strong>{{ quoteStateLabel }}</strong></div>
              <button type="button" class="calculator-button calculator-button--primary" data-testid="calculator-refresh" :disabled="refreshingLive || loading" @click="refreshLiveData">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 6v5h-5M4 18v-5h5M6.1 9A7 7 0 0 1 18.4 6.6L20 11M4 13l1.6 4.4A7 7 0 0 0 17.9 15" /></svg>
                {{ refreshingLive ? 'Refreshing...' : 'Refresh Live Data' }}
              </button>
            </div>
          </section>
          <section v-if="error" class="calculator-state calculator-state--error" role="alert"><strong>Calculator unavailable</strong><span>{{ error }}</span></section>
          <section v-else-if="loading" class="calculator-loading" role="status" aria-live="polite">
            <span class="calculator-loading__mark" aria-hidden="true"></span>
            <div><strong>Preparing {{ symbol }} calculator</strong><p>Loading the expiration catalog and latest publishable chain.</p></div>
          </section>
          <div v-else class="calculator-content">
            <div v-if="refreshState === 'no_options'" class="calculator-state" data-testid="calculator-no-options" role="status"><strong>No contracts available</strong><span>{{ refreshMessage }}</span></div>
            <div v-else-if="['starting', 'running'].includes(refreshState)" class="calculator-state calculator-state--data" data-testid="calculator-refresh-running" role="status" aria-live="polite">
              <div><strong>Preparing updated calculator data in the background.</strong><span v-if="refreshProgress?.expected_count">{{ refreshProgress.completed_count }} of {{ refreshProgress.expected_count }} expirations ready.</span></div>
              <span class="calculator-state__pulse" aria-hidden="true"></span>
            </div>
            <div v-else-if="refreshState === 'slow'" class="calculator-state calculator-state--warning" data-testid="calculator-refresh-slow" role="status">
              <div><strong>Refresh is taking longer than usual</strong><span>{{ refreshMessage }}</span></div><button type="button" class="calculator-button" @click="refreshLiveData">Continue checking</button>
            </div>
            <div v-else-if="['failed', 'rate_limited', 'unauthorized', 'forbidden'].includes(refreshState)" class="calculator-state calculator-state--error" data-testid="calculator-refresh-failed" role="alert">
              <div><strong>Live refresh did not complete</strong><span>{{ refreshMessage }}</span></div>
              <button v-if="!['unauthorized', 'forbidden'].includes(refreshState)" type="button" class="calculator-button" @click="refreshLiveData">Retry refresh</button>
            </div>
            <div v-if="manualUnderlying && calculationUnderlyingPrice !== null" class="calculator-state calculator-state--data" data-testid="calculator-manual-underlying" role="status">
              <strong>Manual stock scenario active</strong><span>Using your manual underlying scenario price of &#36;{{ formatPrice(calculationUnderlyingPrice) }}. This is a hypothetical input, not a live quote.</span>
            </div>
            <div v-else-if="!underlyingQuote.usable" class="calculator-state calculator-state--warning" role="status">
              <strong>Underlying quote unavailable</strong><span>A trustworthy underlying quote is unavailable. Expiration payoffs remain available using hypothetical stock prices. Enter an underlying scenario price to enable time-decay calculations.</span>
            </div>
            <div v-else-if="!manualUnderlying && underlyingQuote.status === 'stale'" class="calculator-state calculator-state--warning" role="status">
              <strong>Accepted stale quote</strong><span>Using a stale quote from {{ underlyingQuote.source || 'the market-data provider' }}<template v-if="underlyingQuote.asof"> (as of {{ underlyingQuote.asof }})</template>.</span>
            </div>
            <section class="calculator-panel calculator-expiries" aria-labelledby="calculator-expiry-heading">
              <div class="calculator-panel__header">
                <div><span class="calculator-step">1</span><div><h2 id="calculator-expiry-heading">Choose an expiration</h2><p>Each date keeps its own readiness state.</p></div></div>
                <dl class="calculator-provenance">
                  <div><dt>Quote source</dt><dd>{{ underlyingQuote.source || 'Unavailable' }}</dd></div>
                  <div><dt>Quote time</dt><dd>{{ formatSourceTime(underlyingQuote.asof) }}</dd></div>
                  <div><dt>Chain snapshot</dt><dd>{{ formatSourceTime(snapshotAt) }}</dd></div>
                </dl>
              </div>
              <div class="calculator-expiry-list" role="list" aria-label="Available expirations">
                <button v-for="exp in expirations" :key="exp.value" type="button" class="calculator-expiry" :class="{ 'calculator-expiry--selected': selectedExpiry === exp.value }" :aria-pressed="selectedExpiry === exp.value" @click="handleExpiryClick(exp.value)">
                  <span>{{ exp.label }}</span><small :data-readiness="expirationStatus(exp)">{{ expirationStatusLabel(exp) }}</small>
                </button>
                <span v-if="!expirations.length" class="calculator-empty-inline">No expirations loaded yet.</span>
              </div>
            </section>
            <div class="calculator-builder">
              <section class="calculator-panel calculator-chain" aria-labelledby="calculator-chain-heading">
                <div class="calculator-panel__header">
                  <div><span class="calculator-step">2</span><div><h2 id="calculator-chain-heading">Live Chain</h2><p>Select the exact call or put contract used by every result.</p></div></div>
                  <fieldset class="calculator-segmented">
                    <legend>Strike range</legend>
                    <button type="button" :aria-pressed="strikeBandMode === 'near'" @click="strikeBandMode = 'near'">Near (±15%)</button>
                    <button type="button" :aria-pressed="strikeBandMode === 'wide'" @click="strikeBandMode = 'wide'">Wide (±40%)</button>
                    <button type="button" :aria-pressed="strikeBandMode === 'all'" @click="strikeBandMode = 'all'">All</button>
                  </fieldset>
                </div>
                <div class="calculator-chain__selection" aria-live="polite"><span>Selected contract</span><strong>{{ selectedContractLabel }}</strong></div>
                <div class="calculator-table-wrap calculator-chain__table">
                  <table class="calculator-table">
                    <caption class="sr-only">Live Chain</caption>
                    <thead><tr><th scope="col">Strike</th><th scope="col">Call price</th><th scope="col">Put price</th></tr></thead>
                    <tbody>
                      <tr v-for="row in groupedStrikes" :key="row.key" :data-contract-family="row.family" :data-selected="selectedOption && [row.call, row.put].some((contract) => contractIdentity(contract) === contractIdentity(selectedOption))">
                        <td><span class="calculator-number">&#36;{{ formatPrice(row.strike) }}</span><small v-if="row.show_family">{{ row.family_label }} contract</small></td>
                        <td :data-contract-symbol="row.call?.contract_symbol ?? null" :title="row.call?.contract_symbol ?? ''" :tabindex="row.call ? 0 : -1" :role="row.call ? 'button' : null" :aria-pressed="row.call ? contractIdentity(row.call) === contractIdentity(selectedOption) : null" :aria-label="row.call ? 'Select call at strike $' + formatPrice(row.strike) + ', price $' + formatPrice(safePremium(row.call)) : 'Call unavailable'" :data-active="contractIdentity(row.call) === contractIdentity(selectedOption)" :class="{ 'font-bold': contractIdentity(row.call) === contractIdentity(selectedOption) }" @click="selectOption(row.call)" @keydown.enter.prevent="selectOption(row.call)" @keydown.space.prevent="selectOption(row.call)">
                          <span v-if="row.call" class="calculator-quote calculator-quote--call">&#36;{{ formatPrice(safePremium(row.call)) }}</span><span v-else aria-label="Unavailable">—</span>
                        </td>
                        <td :data-contract-symbol="row.put?.contract_symbol ?? null" :title="row.put?.contract_symbol ?? ''" :tabindex="row.put ? 0 : -1" :role="row.put ? 'button' : null" :aria-pressed="row.put ? contractIdentity(row.put) === contractIdentity(selectedOption) : null" :aria-label="row.put ? 'Select put at strike $' + formatPrice(row.strike) + ', price $' + formatPrice(safePremium(row.put)) : 'Put unavailable'" :data-active="contractIdentity(row.put) === contractIdentity(selectedOption)" :class="{ 'font-bold': contractIdentity(row.put) === contractIdentity(selectedOption) }" @click="selectOption(row.put)" @keydown.enter.prevent="selectOption(row.put)" @keydown.space.prevent="selectOption(row.put)">
                          <span v-if="row.put" class="calculator-quote calculator-quote--put">&#36;{{ formatPrice(safePremium(row.put)) }}</span><span v-else aria-label="Unavailable">—</span>
                        </td>
                      </tr>
                      <tr v-if="!groupedStrikes.length"><td colspan="3" class="calculator-empty-cell">No chain rows yet. Click "Refresh Live Data" and wait a few seconds.</td></tr>
                    </tbody>
                  </table>
                </div>
              </section>
              <aside class="calculator-panel calculator-setup" aria-labelledby="calculator-setup-heading">
                <div class="calculator-panel__header"><div><span class="calculator-step">3</span><div><h2 id="calculator-setup-heading">Set the position</h2><p>Inputs recalculate locally and issue no market-data request.</p></div></div></div>
                <div class="calculator-setup__body">
                  <div class="calculator-underlying-heading">
                    <h3>{{ symbol }} @
                      <span v-if="calculationUnderlyingPrice !== null">&#36;{{ formatPrice(calculationUnderlyingPrice) }}<small v-if="manualUnderlying">(manual scenario)</small></span>
                      <span v-else-if="manualUnderlying">Invalid manual scenario</span><span v-else>Quote unavailable</span>
                    </h3>
                    <span class="calculator-badge" :data-state="quoteState">{{ quoteStateLabel }}</span>
                  </div>
                  <fieldset class="calculator-option-type">
                    <legend>Position type</legend>
                    <button type="button" :aria-pressed="optionType === 'call'" data-tone="positive" @click="switchOptionType('call')">Long Call</button>
                    <button type="button" :aria-pressed="optionType === 'put'" data-tone="negative" @click="switchOptionType('put')">Long Put</button>
                  </fieldset>
                  <div v-if="selectedOption" class="calculator-contract-card">
                    <span>Selected</span><strong>{{ selectedOption.expiry }} {{ selectedOption.strike }} {{ selectedOption.type.toUpperCase() }}</strong>
                    <dl>
                      <div><dt>Bid</dt><dd>{{ selectedOption.bid == null ? '—' : '$' + formatPrice(selectedOption.bid) }}</dd></div>
                      <div><dt class="sr-only">Pricing input</dt><dd>{{ premiumSourceLabel }}: {{ selectedOption.premium == null ? '—' : '$' + formatPrice(selectedOption.premium) }}</dd></div>
                      <div><dt>Ask</dt><dd>{{ selectedOption.ask == null ? '—' : '$' + formatPrice(selectedOption.ask) }}</dd></div>
                      <div><dt>IV</dt><dd>{{ selectedOption.iv == null ? '—' : (selectedOption.iv * 100).toFixed(1) + '%' }}</dd></div>
                      <div><dt>DTE</dt><dd>{{ selectedOption.dte ?? '—' }}</dd></div>
                    </dl>
                    <details class="calculator-inline-details"><summary>Exact selected contract fields</summary><pre>{{ JSON.stringify(selectedOption, null, 2) }}</pre></details>
                  </div>
                  <div v-else class="calculator-state calculator-state--warning"><span>No {{ optionType }} contract exists at the selected strike and expiration. Select another contract.</span></div>
                  <div class="calculator-fields">
                    <label class="calculator-field" for="calculator-contracts">
                      <span>Contracts</span>
                      <input id="calculator-contracts" data-testid="calculator-contracts" :value="contracts" type="text" min="1" inputmode="numeric" pattern="[0-9]*" :aria-invalid="contractsInvalid" :aria-describedby="contractsInvalid ? 'calculator-contracts-error' : 'calculator-contracts-help'" @input="contracts = $event.target.value" />
                      <small id="calculator-contracts-help">Each standard contract controls 100 shares.</small>
                    </label>
                    <p v-if="contractsInvalid" id="calculator-contracts-error" class="calculator-field-error" data-testid="calculator-contracts-invalid">Enter a positive whole number of contracts. Calculations are paused until this is corrected.</p>
                    <label class="calculator-field" for="calculator-entry-price">
                      <span>Entry price per share <em :data-state="entryAuto ? 'live' : 'manual'">({{ entryAuto ? premiumSourceLabel.toLowerCase() : 'manual' }})</em></span>
                      <input id="calculator-entry-price" data-testid="calculator-entry-price" :value="entryPrice ?? ''" type="text" min="0" step="0.01" inputmode="decimal" placeholder="Leave blank to use the current contract price" :aria-invalid="entryPriceInvalid" :aria-describedby="entryPriceInvalid ? 'calculator-entry-error' : null" @input="onEntryPriceInput" />
                    </label>
                    <p v-if="entryPriceInvalid" id="calculator-entry-error" class="calculator-field-error" data-testid="calculator-entry-invalid">Enter a finite price greater than zero. Your value is retained and calculations remain paused.</p>
                    <button type="button" class="calculator-text-button" :disabled="!selectedOption || selectedOption.premium === null" @click="useLiveEntryPrice">Use current contract price for entry</button>
                    <label class="calculator-field" for="calculator-underlying-price">
                      <span>Underlying scenario price <em :data-state="manualUnderlying ? 'manual' : 'live'">({{ manualUnderlying ? 'manual' : 'quote' }})</em></span>
                      <input id="calculator-underlying-price" data-testid="calculator-underlying-price" :value="manualUnderlying ? manualUnderlyingPrice : (stockPrice ?? '')" type="number" min="0" step="any" inputmode="decimal" placeholder="Enter a hypothetical stock price" :aria-invalid="manualUnderlying && calculationUnderlyingPrice === null" @input="onUnderlyingPriceInput" />
                    </label>
                    <p v-if="manualUnderlying && calculationUnderlyingPrice === null" class="calculator-field-error" data-testid="calculator-underlying-invalid">Enter a finite price greater than zero. No automatic quote is used while this manual input is invalid.</p>
                    <button v-if="manualUnderlying" type="button" class="calculator-text-button" data-testid="calculator-use-quoted-underlying" @click="useQuotedUnderlying">Use automatic underlying quote</button>
                  </div>
                  <section class="calculator-trade-summary" aria-labelledby="calculator-summary-heading">
                    <h3 id="calculator-summary-heading">Trade Summary</h3>
                    <dl>
                      <div><dt>Breakeven</dt><dd>{{ breakeven === null ? '—' : '$' + formatPrice(breakeven) }}</dd></div>
                      <div><dt>Max Loss</dt><dd data-tone="negative">{{ maxLoss === null ? '—' : '$' + formatMoney(Math.abs(maxLoss)) }}</dd></div>
                      <div><dt>Cost</dt><dd>{{ cost === null ? '—' : '$' + formatMoney(cost) }}</dd></div>
                      <div><dt>Move Needed</dt><dd data-tone="data">{{ moveNeeded }}</dd></div>
                    </dl>
                  </section>
                </div>
              </aside>
            </div>
            <section class="calculator-results" aria-labelledby="calculator-results-heading">
              <div class="calculator-results__heading">
                <div><span class="calculator-step">4</span><div><h2 id="calculator-results-heading">Inspect the outcomes</h2><p>Chart selection stays visible and matches the exact row in each table.</p></div></div>
                <span class="calculator-badge" data-state="estimated">Time curve is estimated, not a quote</span>
              </div>
              <div class="calculator-metrics">
                <article class="calculator-metric" data-tone="data"><span>Breakeven</span><strong>{{ breakeven === null ? '—' : '$' + formatPrice(breakeven) }}</strong><small>At expiration</small></article>
                <article class="calculator-metric" data-tone="negative"><span>Maximum risk</span><strong>{{ maxLoss === null ? '—' : '$' + formatMoney(Math.abs(maxLoss)) }}</strong><small>Premium paid</small></article>
                <article class="calculator-metric" :data-tone="optionType === 'call' ? 'positive' : 'warning'"><span>{{ optionType === 'call' ? 'Profit potential' : 'Maximum profit' }}</span><strong>{{ profitPotentialLabel }}</strong><small>{{ optionType === 'call' ? 'Long call at expiration' : 'If the underlying reaches $0' }}</small></article>
                <article class="calculator-metric" data-tone="warning"><span>Reward / risk</span><strong>{{ rewardRiskLabel }}</strong><small>{{ optionType === 'call' ? 'Upside is unbounded' : 'Maximum expiration profit / premium' }}</small></article>
              </div>
              <p v-if="!calculationReady" class="calculator-state calculator-state--warning">{{ calculationBlockedMessage }}</p>
              <div v-else class="calculator-chart-grid">
                <article class="calculator-panel calculator-chart-panel">
                  <div class="calculator-chart-heading">
                    <div><h3>P&amp;L vs Price (at Expiration)</h3><p>Exact intrinsic-value payoff across 51 stock-price scenarios.</p></div>
                    <div v-if="selectedPayoffRow" class="calculator-chart-value" aria-live="polite"><span>Selected scenario</span><strong>&#36;{{ selectedPayoffRow.price.toFixed(2) }}</strong><small :data-sign="selectedPayoffRow.pnl >= 0 ? 'positive' : 'negative'">{{ selectedPayoffRow.pnl >= 0 ? '+' : '' }}&#36;{{ selectedPayoffRow.pnl.toFixed(2) }}</small></div>
                  </div>
                  <p v-if="calculationUnderlyingPrice === null" class="calculator-chart-note" data-testid="calculator-hypothetical-payoff">Hypothetical range centered on the selected strike (&#36;{{ formatPrice(selectedOption.strike) }}), not a current stock quote.</p>
                  <label class="calculator-inspector-select"><span>Inspect price</span><select :value="resolvedPayoffIndex" @change="selectPayoffIndex($event.target.value)"><option v-for="(row, index) in payoffTableRows" :key="row.price" :value="index">&#36;{{ row.price.toFixed(2) }} · {{ row.pnl >= 0 ? '+' : '' }}&#36;{{ row.pnl.toFixed(2) }}</option></select></label>
                  <div class="calculator-canvas"><canvas ref="chartRef" data-testid="calculator-payoff-chart" tabindex="0" role="img" :aria-label="'Expiration payoff chart for ' + selectedContractLabel + '. Use arrow keys to inspect exact scenarios.'" @keydown="handleScenarioKeydown($event, 'payoff')"></canvas></div>
                  <div class="calculator-chart-legend" aria-label="Chart references"><span><i data-reference="current"></i>{{ manualUnderlying ? 'Scenario price' : 'Current price' }}</span><span><i data-reference="breakeven"></i>Breakeven</span><span>Arrow keys inspect points</span></div>
                </article>
                <article class="calculator-panel calculator-chart-panel">
                  <div class="calculator-chart-heading">
                    <div>
                      <h3>P&amp;L vs Time</h3>
                      <p>Scenario: {{ timeDecayTitle }} · DTE: {{ daysToExpiration ?? 'Unavailable' }}<template v-if="daysToExpiration !== null"> day<span v-if="daysToExpiration !== 1">s</span></template></p>
                    </div>
                    <div v-if="selectedDecayRow" class="calculator-chart-value" aria-live="polite"><span>{{ selectedDecayRow.dte }} days left</span><strong>&#36;{{ selectedDecayRow.price.toFixed(2) }}</strong><small :data-sign="selectedDecayRow.pnl >= 0 ? 'positive' : 'negative'">{{ selectedDecayRow.pnl >= 0 ? '+' : '' }}&#36;{{ selectedDecayRow.pnl.toFixed(2) }}</small></div>
                  </div>
                  <fieldset class="calculator-scenario-controls">
                    <legend>Underlying scenario</legend>
                    <button type="button" :aria-pressed="decayMode === 'flat'" @click="decayMode = 'flat'">Flat @ Spot</button>
                    <button type="button" :aria-pressed="decayMode === 'breakeven'" @click="decayMode = 'breakeven'">Flat @ Breakeven</button>
                    <button type="button" :aria-pressed="decayMode === 'target'" @click="decayMode = 'target'">Flat @ Target</button>
                    <input v-if="decayMode === 'target'" :value="targetPrice ?? ''" type="number" min="0" step="0.1" inputmode="decimal" placeholder="Target" aria-label="Target underlying price" @input="targetPrice = $event.target.value" />
                  </fieldset>
                  <p v-if="calculationUnderlyingPrice === null" class="calculator-chart-note" data-testid="calculator-charts-paused">Time-decay calculations require an accepted underlying quote or a valid manual underlying scenario price.</p>
                  <p v-else-if="decayMode === 'target' && decayUnderlying === null" class="calculator-chart-note">Enter a finite target price greater than zero.</p>
                  <template v-else>
                    <label class="calculator-inspector-select"><span>Inspect day</span><select :value="resolvedDecayIndex" @change="selectDecayIndex($event.target.value)"><option v-for="(row, index) in timeDecayRows" :key="row.dte" :value="index">{{ row.dte }} DTE · &#36;{{ row.price.toFixed(2) }} · {{ row.pnl >= 0 ? '+' : '' }}&#36;{{ row.pnl.toFixed(2) }}</option></select></label>
                    <div class="calculator-canvas"><canvas ref="decayChartRef" data-testid="calculator-decay-chart" tabindex="0" role="img" :aria-label="'Time-decay estimate for ' + selectedContractLabel + '. Use arrow keys to inspect exact days.'" @keydown="handleScenarioKeydown($event, 'decay')"></canvas></div>
                  </template>
                  <p class="calculator-assumption">Black–Scholes estimate at one-day intervals. It holds the selected provider IV constant, or fits IV from the current contract price when IV is absent, and uses a 4% risk-free rate. It assumes zero dividends and European exercise, so it does not model American early exercise. It does not forecast future IV or the underlying price.</p>
                </article>
              </div>
              <div class="calculator-exact-grid">
                <details class="calculator-panel calculator-details">
                  <summary><span><strong>Daily option-price, P&amp;L, and ROI</strong><small>{{ timeDecayRows.length }} exact day{{ timeDecayRows.length === 1 ? '' : 's' }}</small></span><span>Open table</span></summary>
                  <div class="calculator-details__body">
                    <div class="calculator-table-tools"><p>{{ timeDecayTitle }}. Every calculation row remains available.</p><fieldset class="calculator-segmented"><legend>Rows</legend><button type="button" :aria-pressed="decayViewMode === 'compact'" @click="decayViewMode = 'compact'">Compact</button><button type="button" :aria-pressed="decayViewMode === 'full'" @click="decayViewMode = 'full'">Full</button></fieldset></div>
                    <div class="calculator-table-wrap calculator-results-table">
                      <table class="calculator-table">
                        <thead><tr><th scope="col">Days to Exp</th><th scope="col">Option Price (&#36;)</th><th scope="col">P&amp;L (&#36;)</th><th scope="col">ROI (%)</th></tr></thead>
                        <tbody data-testid="calculator-time-decay-rows">
                          <tr v-for="row in visibleTimeDecayRows" :key="row.dte" :data-selected="selectedDecayRow?.dte === row.dte" @click="selectDecayIndex(timeDecayRows.findIndex((candidate) => candidate.dte === row.dte))">
                            <td>{{ row.dte }}</td><td>&#36;{{ row.price.toFixed(2) }}</td><td :data-sign="row.pnl >= 0 ? 'positive' : 'negative'">&#36;{{ row.pnl.toFixed(0) }}</td><td>{{ row.roi.toFixed(1) }}%</td>
                          </tr>
                          <tr v-if="decayViewMode === 'compact' && hiddenTimeDecayCount > 0"><td colspan="4" class="calculator-empty-cell">… {{ hiddenTimeDecayCount }} more day<span v-if="hiddenTimeDecayCount !== 1">s</span> hidden. Switch to <strong>Full</strong> view to see all.</td></tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </details>
                <details class="calculator-panel calculator-details">
                  <summary><span><strong>Payoff Table (at Expiration)</strong><small>{{ payoffTableRows.length }} exact scenarios</small></span><span>Open table</span></summary>
                  <div class="calculator-details__body">
                    <p v-if="calculationReady && calculationUnderlyingPrice === null" class="calculator-chart-note">Hypothetical prices centered on the selected strike; a live underlying quote is not required for expiration payoff.</p>
                    <div class="calculator-table-wrap calculator-results-table">
                      <table class="calculator-table">
                        <thead><tr><th scope="col">Stock Price</th><th scope="col">P&amp;L (&#36;)</th><th scope="col">ROI (%)</th></tr></thead>
                        <tbody data-testid="calculator-payoff-rows">
                          <tr v-for="(row, index) in payoffTableRows" :key="row.price" :data-selected="resolvedPayoffIndex === index" @click="selectPayoffIndex(index)">
                            <td>&#36;{{ row.price.toFixed(2) }}</td><td :data-sign="row.pnl >= 0 ? 'positive' : 'negative'">&#36;{{ row.pnl.toFixed(0) }}</td><td>{{ row.roi.toFixed(1) }}%</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </details>
              </div>
              <details class="calculator-panel calculator-details calculator-raw" data-testid="calculator-exact-market-data" @toggle="exactContractsOpen = $event.currentTarget.open">
                <summary><span><strong>Exact market-data inputs</strong><small>{{ chainData.length }} contracts · provider fields retained</small></span><span>Open raw data</span></summary>
                <div v-if="exactContractsOpen" class="calculator-details__body">
                  <dl class="calculator-provenance calculator-provenance--raw">
                    <div><dt>Quote reason</dt><dd>{{ underlyingQuote.reason || 'Unavailable' }}</dd></div>
                    <div><dt>Live age limit</dt><dd>{{ formatExact(underlyingQuote.live_max_age_seconds) }} seconds</dd></div>
                    <div><dt>Stale usable limit</dt><dd>{{ formatExact(underlyingQuote.stale_usable_max_age_seconds) }} seconds</dd></div>
                    <div><dt>Snapshot</dt><dd>{{ snapshotAt || 'Unavailable' }}</dd></div>
                  </dl>
                  <h3>Response metadata</h3><pre data-testid="calculator-response-metadata">{{ JSON.stringify(responseMetadata, null, 2) }}</pre>
                  <h3>Raw expiration publications</h3><pre data-testid="calculator-raw-expirations">{{ JSON.stringify(rawExpirations, null, 2) }}</pre>
                  <h3>Normalized contracts with retained provider fields</h3><pre>{{ JSON.stringify(chainData, null, 2) }}</pre>
                </div>
              </details>
            </section>
          </div>
        </main>
      </AppShell>
    </div>
  </AppLayout>
</template>
