<template>
  <div class="min-h-screen bg-gray-950 text-white">
    <!-- Trading Terminal Header -->
    <header class="border-b border-gray-800 bg-gray-900/95 backdrop-blur-sm sticky top-0 z-50">
      <div class="px-4 py-3 flex items-center justify-between">
        <!-- Left: Title + Symbol -->
        <div class="flex items-center gap-3">
          <h1 class="text-xl font-bold tracking-tight">GEX Levels & Analytics</h1>
          <div class="flex items-center gap-2">
            <span class="text-lg font-mono text-cyan-400">{{ userSymbol }}</span>
            <button @click="showSymbolPicker = true" class="text-xs text-gray-400 hover:text-white">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
          </div>
        </div>

        <!-- Center: EOD Timeframe Picker -->
        <div v-if="dataMode === 'eod'" class="hidden md:flex items-center gap-2">
          <span class="text-xs text-gray-400 uppercase tracking-wider">Timeframe</span>
          <div class="flex rounded-lg overflow-hidden border border-gray-700">
            <button
              v-for="tf in timeframeOptions"
              :key="tf.value"
              @click="gexTf = tf.value"
              class="px-3 py-1.5 text-xs font-medium transition"
              :class="gexTf === tf.value ? 'bg-cyan-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'"
            >
              {{ tf.label }}
            </button>
          </div>
        </div>

        <!-- Right: Mode Toggle + Freshness -->
        <div class="flex items-center gap-3">
          <!-- Mode Toggle -->
          <div class="flex rounded-lg overflow-hidden border border-gray-700">
            <button
              @click="setMode('eod')"
              class="px-4 py-1.5 text-sm font-medium transition flex items-center gap-1.5"
              :class="dataMode === 'eod' ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              EOD
            </button>
            <button
              @click="setMode('intraday')"
              class="px-4 py-1.5 text-sm font-medium transition flex items-center gap-1.5"
              :class="dataMode === 'intraday' ? 'bg-green-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
              Live
              <span v-if="dataMode === 'intraday'" class="text-[10px] opacity-80">15m</span>
            </button>
          </div>

          <!-- Freshness -->
          <div class="text-xs text-gray-400">
            <span v-if="dataMode === 'eod' && levels?.date_prev">
              EOD: {{ levels.date_prev }}
            </span>
            <span v-else-if="dataMode === 'intraday'" class="flex items-center gap-1">
              <span class="text-green-400 font-medium">LIVE</span>
              <span v-if="lastUpdated">• {{ fromNow(lastUpdated) }}</span>
              <button @click="manualRefresh" class="ml-1 text-cyan-400 hover:text-cyan-300">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
              </button>
            </span>
          </div>
        </div>
      </div>
    </header>

    <!-- Expiration Chips (EOD only) -->
    <div v-if="dataMode === 'eod' && levels?.expiration_dates?.length" class="px-4 py-2 border-b border-gray-800 bg-gray-900/50">
      <div class="flex flex-wrap gap-1.5">
        <span
          v-for="d in levels.expiration_dates"
          :key="d"
          class="px-2 py-0.5 rounded text-xs font-mono bg-gray-800 text-cyan-300 border border-gray-700"
        >
          {{ d }}
        </span>
      </div>
    </div>

    <!-- Tabs -->
    <div class="sticky top-[65px] z-40 bg-gray-900/95 backdrop-blur-sm border-b border-gray-800">
      <nav class="flex gap-1 px-4 py-2 overflow-x-auto no-scrollbar">
        <button
          v-for="t in currentTabs"
          :key="t.key"
          @click="activate(t.key)"
          class="px-5 py-2 rounded-lg text-sm font-medium transition whitespace-nowrap flex items-center gap-2"
          :class="activeTab === t.key
            ? 'bg-gradient-to-r from-cyan-600 to-blue-600 text-white shadow-lg shadow-cyan-500/20'
            : 'text-gray-400 hover:text-white hover:bg-gray-800'"
        >
          <component :is="t.icon" class="w-4 h-4" />
          {{ t.label }}
          <span v-if="t.badge" class="ml-1 px-1.5 py-0.5 text-[10px] rounded-full bg-white/20">
            {{ t.badge }}
          </span>
        </button>
      </nav>
    </div>

    <!-- Body -->
    <div class="p-4 space-y-6">
      <!-- Loading / Error -->
      <ui-error-block v-if="topError" :message="'Failed to load data'" :detail="topError"
                     :onRetry="() => dataMode === 'eod' ? fetchGexLevelsEOD(userSymbol, gexTf) : refreshIntraday()" />
      <ui-spinner v-else-if="dataMode==='intraday' ? (!firstIntradayLoadDone && intradayLoading) : loading" />


      <template v-else>
        <!-- OVERVIEW (EOD) -->
        <section v-show="activeTab==='overview' && dataMode==='eod'" class="space-y-4">
          <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
            <h3 class="text-lg font-semibold mb-3 flex items-center gap-2">
              <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
              </svg>
              Q-Score
            </h3>
            <QScorePanel :symbol="userSymbol" />
          </div>

          <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
            <MetricCard title="HVL" :value="levels?.hvl" />
            <MetricCard title="Call OI %" :value="fmtPct(levels?.call_interest_percentage)" />
            <MetricCard title="Put OI %" :value="fmtPct(levels?.put_interest_percentage)" />
            <MetricCard title="Total OI" :value="num(levels?.call_open_interest_total) + num(levels?.put_open_interest_total)" />
            <MetricCard title="Total Vol" :value="num(levels?.call_volume_total) + num(levels?.put_volume_total)" />
            <MetricCard title="ΔOI" :value="levels?.total_oi_delta" />
            <MetricCard title="ΔVol" :value="levels?.total_volume_delta" />
          </div>

          <div class="bg-gradient-to-r from-gray-800 to-gray-900 rounded-xl p-4 text-center">
            <h3 class="text-sm font-semibold text-gray-400">PCR (Volume)</h3>
            <p class="text-2xl font-bold text-cyan-400">{{ levels?.pcr_volume ?? '—' }}</p>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
              <h4 class="font-semibold mb-2">OI Distribution</h4>
              <OiDistributionChart
                :call-oi="num(levels?.call_open_interest_total)"
                :put-oi="num(levels?.put_open_interest_total)" />
            </div>
            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
              <h4 class="font-semibold mb-2">Volume Distribution</h4>
              <VolDistributionChart
                :call-vol="num(levels?.call_volume_total)"
                :put-vol="num(levels?.put_volume_total)" />
            </div>
          </div>
        </section>

        <!-- POSITIONING (EOD) -->
        <Suspense>
          <section v-show="activeTab==='positioning' && dataMode==='eod'" class="space-y-4">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
              <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
                <h4 class="font-semibold mb-2">Dealer Positioning</h4>
                <component :is="busy.positioning ? uiSkeletonCard : DexTile" :symbol="userSymbol" />
              </div>
              <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
                <h4 class="font-semibold mb-2">Expiry Pressure ({{ pinDays }}D)</h4>
                <component :is="busy.positioning ? uiSkeletonCard : ExpiryPressureTile" :symbol="userSymbol" :days="pinDays" />
              </div>
            </div>
            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
              <h4 class="font-semibold mb-2">IV Skew</h4>
              <component :is="busy.positioning ? uiSkeletonCard : SkewTile" :symbol="userSymbol" />
            </div>
          </section>
        </Suspense>

        <!-- VOLATILITY -->
        <section v-show="activeTab==='volatility' && dataMode==='eod'" class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
              <div class="flex items-center justify-between mb-2">
                <h4 class="font-semibold">Term Structure</h4>
                <span v-if="term?.date" class="text-xs text-gray-400">as of {{ term.date }}</span>
              </div>
              <ui-error-block v-if="errors.volatility" :message="'Failed to load volatility data'"
                            :detail="errors.volatility" :onRetry="ensureVolatility" />
              <ui-skeleton-card v-else-if="!loaded.volatility" />
              <TermTile v-else :items="term.items || []" :date="term.date" />
            </div>

            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
              <div class="flex items-center justify-between mb-2">
                <h4 class="font-semibold">Variance Risk Premium</h4>
                <span v-if="vrp?.date" class="text-xs text-gray-400">as of {{ vrp.date }}</span>
              </div>
              <ui-error-block v-if="errors.volatility" :message="'Failed to load volatility data'"
                            :detail="errors.volatility" :onRetry="ensureVolatility" />
              <ui-skeleton-card v-else-if="!loaded.volatility" />
              <VRPTile v-else :date="vrp.date" :iv1m="vrp.iv1m" :rv20="vrp.rv20" :vrp="vrp.vrp" :z="vrp.z" />
            </div>
          </div>

          <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
            <h4 class="font-semibold mb-2">Seasonality (5D)</h4>
            <ui-error-block v-if="errors.volatility" :message="'Failed to load seasonality'"
                          :detail="errors.volatility" :onRetry="ensureVolatility" />
            <ui-skeleton-card v-else-if="!loaded.volatility" />
            <template v-else>
              <Seasonality5Tile
                v-if="season"
                :date="season.date"
                :d1="season.d1" :d2="season.d2" :d3="season.d3" :d4="season.d4" :d5="season.d5"
                :cum5="season.cum5" :z="season.z" :note="seasonNote" />
              <div v-else class="text-sm text-gray-400">No seasonality data.</div>
            </template>
            <div v-if="volErr" class="text-red-400 text-sm mt-2">Vol metrics error: {{ volErr }}</div>
          </div>
        </section>

        <!-- UA -->
        <section v-show="activeTab==='ua'" class="space-y-4">
          <div class="flex items-center gap-2 text-xs">
            <label>Expiry</label>
            <select v-model="uaExp" class="px-2 py-1 bg-gray-700 rounded text-sm">
              <option value="ALL">All</option>
              <option v-for="d in (levels?.expiration_dates || [])" :key="d" :value="d">{{ d }}</option>
            </select>

            <label>Top</label>
            <input type="number" v-model.number="uaTop" class="w-16 bg-gray-700 rounded px-2 py-1">
            <label>Sort</label>
            <select v-model="uaSort" class="bg-gray-700 rounded px-2 py-1">
              <option value="z_score">Z-Score</option>
              <option value="premium">Premium ($)</option>
              <option value="vol_oi">Vol/OI</option>
            </select>

            <button @click="ensureUA" class="ml-auto px-3 py-1.5 bg-gray-700 hover:bg-gray-600 rounded">Apply</button>
            <button @click="showAdvanced = !showAdvanced" class="px-3 py-1.5 bg-gray-700 hover:bg-gray-600 rounded">
              {{ showAdvanced ? 'Hide' : 'Advanced' }}
            </button>
          </div>

          <div v-if="showAdvanced" class="flex flex-wrap items-center gap-2 text-xs mb-1">
            <label>min Z</label>
            <input type="number" step="0.1" v-model.number="uaMinZ" class="w-16 bg-gray-700 rounded px-2 py-1">
            <label>min Vol/OI</label>
            <input type="number" step="0.1" v-model.number="uaMinVolOI" class="w-16 bg-gray-700 rounded px-2 py-1">
            <label>min Vol</label>
            <input type="number" v-model.number="uaMinVol" class="w-20 bg-gray-700 rounded px-2 py-1">
            <label>min $</label>
            <input type="number" v-model.number="uaMinPrem" class="w-24 bg-gray-700 rounded px-2 py-1" placeholder="premium">
            <label>near ±%</label>
            <input type="number" v-model.number="uaNearPct" class="w-16 bg-gray-700 rounded px-2 py-1" placeholder="10">
            <label>Side</label>
            <select v-model="uaSide" class="bg-gray-700 rounded px-2 py-1">
              <option value="">Both</option><option value="call">Call-led</option><option value="put">Put-led</option>
            </select>
            <div class="ml-auto flex gap-2">
              <button @click="presetConservative" class="px-2 py-1 bg-gray-700 rounded">Conservative</button>
              <button @click="presetAggressive" class="px-2 py-1 bg-gray-700 rounded">Aggressive</button>
            </div>
          </div>

          <ui-error-block v-if="errors.ua" :message="'Failed to load UA'" :detail="errors.ua" :onRetry="ensureUA" />
          <ui-spinner v-else-if="uaLoading" />
          <template v-else>
            <div v-if="!uaDate" class="text-sm text-gray-400 mb-2">No UA data yet for today.</div>
            <UnusualActivityTable :rows="uaRows || []" :dataDate="uaDate" :symbol="userSymbol" />
            <div class="flex justify-center mt-3">
              <button @click="showMore" class="text-xs px-3 py-1.5 bg-gray-700 hover:bg-gray-600 rounded">Show more</button>
            </div>
          </template>
        </section>

        <!-- STRIKES -->
        <section v-show="activeTab==='strikes'" class="space-y-4">
          <!-- EOD: OI + Net GEX + ΔVol (EOD) -->
          <template v-if="dataMode==='eod'">
            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
              <h4 class="font-semibold mb-3">ΔOI by Strike (EOD)</h4>
              <StrikeDeltaChart :strikeData="levels?.strike_data || []" />
            </div>

            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
              <h4 class="font-semibold mb-3">ΔVol by Strike (EOD)</h4>
              <VolumeDeltaChart :strikeData="levels?.strike_data || []" />
            </div>

            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
              <h4 class="font-semibold mb-3">Net GEX by Strike (EOD)</h4>
              <NetGexChart :strikeData="levels?.strike_data || []" />
            </div>
          </template>

          <!-- Intraday: only ΔVol (Live) -->
          <template v-else>
            <div class="space-y-4">
              <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
                <h4 class="font-semibold mb-3">Vol / OI (Live) by Strike</h4>
                <div class="h-[60vh]">
                  <VolOverOiChart :strikeData="normalizeStrikes(levels?.strike_data)" />
                </div>
              </div>

              <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
                <h4 class="font-semibold mb-3">PCR (Live) by Strike</h4>
                <div class="h-[60vh]">
                  <PcrByStrikeChart :strikeData="normalizeStrikes(levels?.strike_data)" />
                </div>
              </div>

              <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
                <h4 class="font-semibold mb-3">Premium (Live) by Strike</h4>
                <div class="h-[60vh]">
                  <PremiumByStrikeChart :strikeData="normalizeStrikes(levels?.strike_data)" />
                </div>
              </div>
            </div>
          </template>
        </section>


        <!-- FLOW (Intraday) -->
        <section v-show="activeTab==='flow' && dataMode==='intraday'" class="space-y-4">
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <MetricCard
              title="Call Vol"
              :value="num(levels?.call_volume_total)"
              sub="contracts"
              :class="levels?.call_volume_total > levels?.put_volume_total ? 'text-green-400' : ''"
            />
            <MetricCard
              title="Put Vol"
              :value="num(levels?.put_volume_total)"
              sub="contracts"
              :class="levels?.put_volume_total > levels?.call_volume_total ? 'text-red-400' : ''"
            />
            <MetricCard
              title="PCR"
              :value="levels?.pcr_volume"
              sub="puts ÷ calls"
              :class="levels?.pcr_volume > 1 ? 'text-red-400' : 'text-green-400'"
            />
            <MetricCard
              title="Premium"
              :value="fmtUsd(estimatePremium(levels))"
              sub="notional"
            />
          </div>

          <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
            <h4 class="font-semibold mb-3 flex items-center gap-2">
              <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
              </svg>
              Live Flow by Strike
            </h4>
            <VolumeDeltaChart
              :strikeData="(levels?.strike_data || []).map(r => ({
                strike: r.strike,
                call_vol_delta: r.call_vol_delta ?? r.call_volume_delta ?? 0,
                put_vol_delta:  r.put_vol_delta  ?? r.put_volume_delta  ?? 0,
              }))"
            />
          </div>
        </section>
      </template>
    </div>

    <!-- Symbol Picker Modal -->
    <teleport to="body">
      <div v-if="showSymbolPicker" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-gray-900 rounded-xl border border-gray-700 max-w-md w-full p-6">
          <h3 class="text-lg font-semibold mb-4">Select Symbol</h3>
          <input
            v-model="symbolSearch"
            @keyup.enter="pickSymbol(symbolSearch)"
            placeholder="SPY, QQQ, AAPL..."
            class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-cyan-500"
          />
          <div class="mt-4 flex justify-end gap-2">
            <button @click="showSymbolPicker = false" class="px-4 py-2 text-gray-400 hover:text-white">Cancel</button>
            <button @click="pickSymbol(symbolSearch)" class="px-4 py-2 bg-cyan-600 text-white rounded-lg hover:bg-cyan-700">
              Go
            </button>
          </div>
        </div>
      </div>
    </teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, h, defineComponent } from 'vue'
import axios from 'axios'

// Components
import MetricCard from './MetricCard.vue'
import StrikeDeltaChart from './StrikeDeltaChart.vue'
import VolumeDeltaChart from './VolumeDeltaChart.vue'
import NetGexChart from './NetGexChart.vue'
import OiDistributionChart from './OiDistributionChart.vue'
import VolDistributionChart from './VolDistributionChart.vue'
import Seasonality5Tile from './Seasonality5Tile.vue'
import VolOverOiChart from './VolOverOiChart.vue'
import PcrByStrikeChart from './PcrByStrikeChart.vue'
import PremiumByStrikeChart from './PremiumByStrikeChart.vue'
import TermTile from './TermTile.vue'
import VRPTile from './VRPTile.vue'
const SkewTile = defineAsyncComponent(() => import('./SkewTile.vue'))
const DexTile = defineAsyncComponent(() => import('./DexTile.vue'))
import QScorePanel from './QScorePanel.vue'
const ExpiryPressureTile = defineAsyncComponent(() => import('./ExpiryPressureTile.vue'))
import UnusualActivityTable from './UnusualActivityTable.vue'
import uiSpinner from './Spinner.vue'
import uiSkeletonCard from './SkeletonCard.vue'
import uiErrorBlock from './ErrorBlock.vue'
import { defineAsyncComponent } from 'vue'

axios.defaults.withCredentials = true
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'

// Timeframe options
const timeframeOptions = [
  { value: '0d', label: '0DTE' },
  { value: '1d', label: '1DTE' },
  { value: '7d', label: '1W' },
  { value: '14d', label: '2W' },
  { value: '30d', label: '1M' },
  { value: '90d', label: '3M' },
]

// Enhanced tabs with icons
const tabsEOD = [
  { key: 'overview', label: 'Overview', icon: defineComponent({ render: () => h('svg', { class: 'w-4 h-4', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' })]) }) },
  { key: 'positioning', label: 'Positioning', icon: defineComponent({ render: () => h('svg', { class: 'w-4 h-4', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z' })]) }) },
  { key: 'volatility', label: 'Volatility', icon: defineComponent({ render: () => h('svg', { class: 'w-4 h-4', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6' })]) }) },
  { key: 'ua', label: 'Unusual Activity', icon: defineComponent({ render: () => h('svg', { class: 'w-4 h-4', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z' })]) }) },
  { key: 'strikes', label: 'Strikes', icon: defineComponent({ render: () => h('svg', { class: 'w-4 h-4', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z' })]) }) },
]

const tabsIntraday = [
  { key: 'flow', label: 'Live Flow', icon: defineComponent({ render: () => h('svg', { class: 'w-4 h-4', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M13 10V3L4 14h7v7l9-11h-7z' })]) }) },
  { key: 'ua', label: 'Unusual Activity', icon: defineComponent({ render: () => h('svg', { class: 'w-4 h-4', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z' })]) }) },
  { key: 'strikes', label: 'Live Strikes', icon: defineComponent({ render: () => h('svg', { class: 'w-4 h-4', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z' })]) }) },
]

const currentTabs = computed(() => dataMode.value === 'eod' ? tabsEOD : tabsIntraday)

// State
const activeTab = ref('overview')
const eodLevels = ref(null)
const intradayLevels = ref(null)
const levels = computed(() => dataMode.value === 'eod' ? eodLevels.value : intradayLevels.value)
// Separate loading states
const eodLoading = ref(false)
const intradayLoading = ref(false)
const intradayRefreshing = ref(false)
const firstIntradayLoadDone = ref(false)
const loading = computed(() => dataMode.value === 'eod' ? eodLoading.value : intradayLoading.value)

// Separate error states
const eodError = ref('')
const intradayError = ref('')
const topError = computed(() => dataMode.value === 'eod' ? eodError.value : intradayError.value)

const lastUpdated = ref(null)
const dataMode = ref('eod')
const refreshTimer = ref(null)
const pinDays = 3
const busy = ref({ positioning: false })
const errors = ref({ volatility: '', ua: '' })
const term = ref({ date: null, items: [] })
const vrp = ref({ date: null, iv1m: null, rv20: null, vrp: null, z: null })
const season = ref(null)
const seasonNote = ref('')
const loaded = ref({ volatility: false, ua: false })
const uaRows = ref([])
const uaDate = ref(null)
const uaExp = ref('ALL')
const uaLoading = ref(false)
const uaTop = ref(5)
const uaLimit = ref(50)
const uaMinZ = ref(2.5)
const uaMinVolOI = ref(2.0)
const uaMinVol = ref(500)
const uaNearPct = ref(10)
const uaSide = ref('')
const uaSort = ref('z_score')
const uaMinPrem = ref(0)
const showAdvanced = ref(false)
const watchlistItems = ref([])
const pinMap = ref({})
const uaMap = ref({})
const symbol = ref('SPY')
const gexTf = ref('14d')
const userSymbol = symbol
const cache = new Map()
const cacheTerm = new Map()
const cacheVRP = new Map()
const cacheSeas = new Map()
const cacheUA = new Map()
const TTL_MS = 300_000
const volErr = ref(null)
const inflight = new Map()


// Symbol picker
const showSymbolPicker = ref(false)
const symbolSearch = ref('')

function pickSymbol(sym) {
  const s = sym.trim().toUpperCase()
  if (s) userSymbol.value = s
  showSymbolPicker.value = false
  symbolSearch.value = ''
}

// Controllers
const controllers = { gex: null, term: null, vrp: null, season: null, ua: null }

function withInflight(key, fn) {
  const hit = inflight.get(key)
  if (hit) return hit
  const p = fn().finally(() => inflight.delete(key))
  inflight.set(key, p)
  return p
}

// Watchers
watch(dataMode, (mode) => {
  if (mode === 'intraday' && activeTab.value === 'overview') activeTab.value = 'flow'
  if (mode === 'eod' && activeTab.value === 'flow') activeTab.value = 'overview'
})

function activate(key) {
  activeTab.value = key
  if (key === 'volatility' && dataMode.value === 'eod' && !loaded.value.volatility) ensureVolatility()
  if (key === 'ua' && !loaded.value.ua) ensureUA()
}

function setMode(mode) {
  if (dataMode.value === mode) return
  dataMode.value = mode
}

// Data refresh on mode/tab change
watch([dataMode, activeTab], async ([mode, tab], [oldMode, oldTab]) => {
  // Mode change
  if (mode !== oldMode) {
    if (mode === 'intraday') {
      await axios.post('/api/intraday/pull', { symbols: [userSymbol.value] }).catch(() => {})
      await refreshIntraday()
      startAutoRefresh()
    } else {
      stopRefresh()
      await fetchGexLevelsEOD(userSymbol.value, gexTf.value)
    }
  }

  // Tab change - only refresh if data might be stale
  if (tab !== oldTab) {
    if (tab === 'ua' && !loaded.value.ua) ensureUA()
    if (tab === 'volatility' && mode === 'eod' && !loaded.value.volatility) ensureVolatility()
    if (tab === 'strikes') {
      if (mode === 'intraday') await refreshIntraday()
      if (mode === 'eod') await fetchGexLevelsEOD(userSymbol.value, gexTf.value)
    }
  }
}, { immediate: false })

// Utils
function fmtUsd(v) {
  if (v == null || isNaN(v)) return '—'
  return Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(v)
}
function estimatePremium(levelsObj) {
  return Number(levelsObj?.premium_total || 0)
}
function num(v) { return Number(v || 0) }
function fmtPct(v) { return (v === null || v === undefined) ? '—' : `${v}%` }
function fromNow(ts) {
  if (!ts) return ''
  const then = new Date(ts).getTime()
  const diff = Math.round((then - Date.now()) / 1000)
  const abs = Math.abs(diff)
  const steps = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]]
  for (const [unit, s] of steps) {
    const amt = Math.trunc(diff / s)
    if (abs >= s || unit === 'second') {
      return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(amt, unit)
    }
  }
  return ''
}
function cancel(type) {
  try { controllers[type]?.abort() } catch {}
  controllers[type] = new AbortController()
  return controllers[type]
}
function ensureController(type) {
  if (!controllers[type] || controllers[type].signal.aborted) {
    controllers[type] = new AbortController()
  }
  return controllers[type]
}

// Data loaders
async function fetchGexLevelsEOD(sym, tf = gexTf.value) {
  const key = `gex|${sym}|${tf}`
  const hit = cache.get(key)
  if (hit && Date.now() - hit.t < TTL_MS) {
    eodLevels.value = hit.data
    lastUpdated.value = new Date().toISOString()
    return
  }

  eodLoading.value = true
  eodError.value = ''
  eodLevels.value = null

  try {
    await withInflight(`gex:${key}`, async () => {
      const ctl = ensureController('gex')
      const { data } = await axios.get('/api/gex-levels', {
        params: { symbol: sym, timeframe: tf },
        signal: ctl.signal
      })
      eodLevels.value = data || {}
      cache.set(key, { t: Date.now(), data: eodLevels.value })
      uaExp.value = 'ALL'
      lastUpdated.value = new Date().toISOString()
    })
  } catch (e) {
    if (e.name !== 'CanceledError' && e.code !== 'ERR_CANCELED') {
      eodError.value = e?.response?.data?.error || e.message
    }
  } finally {
    if (!controllers.gex?.signal.aborted) eodLoading.value = false
  }
}

async function loadTermAndVRP(sym) {
  errors.value.volatility = ''
  const termCtl = ensureController('term')
  const vrpCtl = ensureController('vrp')
  const tKey = `term|${sym}`; const vKey = `vrp|${sym}`
  const tHit = getCache(cacheTerm, tKey, 60_000)
  const vHit = getCache(cacheVRP, vKey, 60_000)
  if (tHit && vHit) { term.value = tHit; vrp.value = vHit; return }
  try {
    const t = await withInflight(`term:${sym}`, () =>
      axios.get('/api/iv/term', { params: { symbol: sym }, signal: termCtl.signal })
    )
    term.value = { date: t.data?.date ?? null, items: Array.isArray(t.data?.items) ? t.data.items : [] }
    setCache(cacheTerm, tKey, term.value)
    const v = await withInflight(`vrp:${sym}`, () =>
      axios.get('/api/vrp', { params: { symbol: sym }, signal: vrpCtl.signal })
    )
    vrp.value = { date: v.data?.date ?? null, iv1m: v.data?.iv1m ?? null, rv20: v.data?.rv20 ?? null, vrp: v.data?.vrp ?? null, z: v.data?.z ?? null }
    setCache(cacheVRP, vKey, vrp.value)
  } catch (e) {
    if (e.name !== 'CanceledError' && e.code !== 'ERR_CANCELED') {
      errors.value.volatility = e?.response?.data || e.message
      volErr.value = errors.value.volatility
    }
  }
}

async function loadSeasonality(sym) {
  const ctl = ensureController('season')
  const sKey = `seas|${sym}`
  const sHit = getCache(cacheSeas, sKey, 300_000)
  if (sHit) { season.value = sHit.variant; seasonNote.value = sHit.note; return }
  try {
    const { data } = await withInflight(`season:${sym}`, () =>
      axios.get('/api/seasonality/5d', { params: { symbol: sym }, signal: ctl.signal })
    )
    season.value = data?.variant || null
    seasonNote.value = data?.note || ''
    setCache(cacheSeas, sKey, data || {})
  } catch (e) {
    if (e.name !== 'CanceledError' && e.code !== 'ERR_CANCELED') {
      errors.value.volatility = errors.value.volatility || e.message
    }
  }
}

async function loadUA(sym, exp = null) {
  const ctl = ensureController('ua')
  uaLoading.value = true
  errors.value.ua = ''
  const uaUrl = dataMode.value === 'intraday' ? '/api/intraday/ua' : '/api/ua'
  const k = ['ua', sym, exp ?? 'ALL', uaTop.value, uaMinZ.value, uaMinVolOI.value, uaMinVol.value, uaMinPrem.value, uaNearPct.value || 0, uaSide.value || '', uaSort.value, uaLimit.value].join('|')
  const hit = getCache(cacheUA, k, 60_000)
  if (hit) {
    uaDate.value = hit.data_date || null
    uaRows.value = hit.items || []
    uaLoading.value = false
    return
  }
  try {
    const { data } = await withInflight(`ua:${k}`, () =>
      axios.get(uaUrl, {
        params: {
          symbol: sym, exp, per_expiry: uaTop.value, limit: uaLimit.value,
          min_z: uaMinZ.value, min_vol_oi: uaMinVolOI.value, min_vol: uaMinVol.value,
          min_premium: uaMinPrem.value, near_spot_pct: uaNearPct.value || 0,
          only_side: uaSide.value || null, with_premium: true, sort: uaSort.value
        },
        signal: ctl.signal
      })
    )
    uaDate.value = data?.data_date || null
    uaRows.value = data?.items || []
    setCache(cacheUA, k, data || {})
  } catch (e) {
    if (e.name !== 'CanceledError' && e.code !== 'ERR_CANCELED') {
      errors.value.ua = e?.response?.data || e.message
      uaDate.value = null
      uaRows.value = []
    }
  } finally {
    if (!ctl.signal.aborted) uaLoading.value = false
  }
}

// Lazy triggers
async function ensureVolatility() {
  errors.value.volatility = ''
  loaded.value.volatility = false
  await Promise.all([loadTermAndVRP(userSymbol.value), loadSeasonality(userSymbol.value)])
  if (!errors.value.volatility) loaded.value.volatility = true
}
async function ensureUA() {
  errors.value.ua = ''
  loaded.value.ua = false
  await loadUA(userSymbol.value, uaExp.value === 'ALL' ? null : uaExp.value)
  loaded.value.ua = true
}

// Presets / paging
function presetConservative() { uaMinZ.value = 3.0; uaMinVolOI.value = 0.75; uaMinVol.value = 1000; uaNearPct.value = 5; uaSide.value = '' }
function presetAggressive() { uaMinZ.value = 2.0; uaMinVolOI.value = 0.25; uaMinVol.value = 0; uaNearPct.value = 0; uaSide.value = '' }
function showMore() { uaTop.value = Math.min(uaTop.value + 3, 20); uaLimit.value = Math.min(uaLimit.value + 30, 200); ensureUA() }

// Lifecycle
function onSymbolPicked(e) {
  const sym = String(e.detail?.symbol || '').trim().toUpperCase()
  if (!sym) return
  userSymbol.value = sym
  loaded.value = { volatility: false, ua: false }
  if (dataMode.value === 'intraday') {
    refreshIntraday()
  } else {
    fetchGexLevelsEOD(sym, gexTf.value)
  }
}

onMounted(() => {
  loadWatchlist()
  window.addEventListener('select-symbol', onSymbolPicked)
  fetchGexLevelsEOD(userSymbol.value, gexTf.value)
})

onUnmounted(() => {
  window.removeEventListener('select-symbol', onSymbolPicked)
  Object.keys(controllers).forEach(cancel)
  stopRefresh()
})

function stopRefresh() {
  if (refreshTimer.value) {
    clearInterval(refreshTimer.value)
    refreshTimer.value = null
  }
}

function startAutoRefresh() {
  stopRefresh()
  refreshTimer.value = setInterval(refreshIntraday, 30_000)
}

async function refreshIntraday() {
  const sym = userSymbol.value
  await axios.post('/api/intraday/pull', { symbols: [sym] }).catch(() => {})

  const ctl = ensureController('gex') // or a new 'intraday' controller
  const soft = firstIntradayLoadDone.value
  if (!soft) intradayLoading.value = true; else intradayRefreshing.value = true
  intradayError.value = ''

  try {
    const [comp] = await Promise.all([
      axios.get('/api/intraday/strikes', { params: { symbol: sym }, signal: ctl.signal }),
    ])

    // IMPORTANT: mutate existing object to avoid remounts
    const next = {
      call_volume_total: comp.data?.totals?.call_vol ?? 0,
      put_volume_total:  comp.data?.totals?.put_vol  ?? 0,
      pcr_volume:        comp.data?.totals?.pcr_vol  ?? null,
      premium_total:     comp.data?.totals?.premium ?? 0,
      strike_data:       (comp.data?.items || []).map(r => ({
        strike: r.strike,
        call_vol_delta: r.call_vol,
        put_vol_delta:  r.put_vol,
        oi_call_eod:    r.oi_call_eod,
        oi_put_eod:     r.oi_put_eod,
        vol_oi:         r.vol_oi,
        pcr:            r.pcr,
        premium_call:   r.call_prem,
        premium_put:    r.put_prem,
      })),
      strike_gex_live: (comp.data?.items || []).map(r => ({
        strike: r.strike,
        net_gex: r.net_gex_live,
        net_gex_delta: r.net_gex_delta,
      })),
    }

    if (!intradayLevels.value) intradayLevels.value = {}
    Object.assign(intradayLevels.value, next) // mutate, don’t replace

    lastUpdated.value = comp.data?.asof || new Date().toISOString()
    firstIntradayLoadDone.value = true
  } catch (e) {
    intradayError.value = e?.response?.data?.error || e.message
  } finally {
    intradayLoading.value = false
    intradayRefreshing.value = false
  }
}

async function manualRefresh() {
  try {
    await axios.post('/api/intraday/pull', { symbols: [userSymbol.value] })
    await refreshIntraday()
  } catch (e) {
    topError.value = e?.response?.data?.error || e.message
  }
}

let symbolTimer
watch(userSymbol, (s) => {
  clearTimeout(symbolTimer)
  symbolTimer = setTimeout(() => {
    if (dataMode.value === 'eod') {
      fetchGexLevelsEOD(s, gexTf.value)
    } else {
      refreshIntraday()
    }
    if (loaded.value.ua && activeTab.value === 'ua') ensureUA()
  }, 250)
})

watch(uaExp, () => { if (activeTab.value === 'ua') ensureUA() })
watch([userSymbol], () => {
  busy.value.positioning = true
  requestAnimationFrame(() => setTimeout(() => { busy.value.positioning = false }, 250))
})

watch(gexTf, tf => {
  if (dataMode.value === 'eod')
    fetchGexLevelsEOD(userSymbol.value, tf)
  else {
    refreshIntraday()
  }
})

watch(dataMode, async () => {
  if (dataMode.value === 'intraday') {
    try {
      await axios.post('/api/intraday/pull', { symbols: [userSymbol.value] })
    } catch {}
    refreshIntraday()
    startAutoRefresh()
  } else {
    stopRefresh()
    fetchGexLevelsEOD(userSymbol.value, gexTf.value)
  }
})

// Cache helpers
function setCache(map, key, data) { map.set(key, { t: Date.now(), data }) }
function getCache(map, key, ttl) {
  const h = map.get(key)
  return (h && Date.now() - h.t < ttl) ? h.data : null
}

async function loadWatchlist() {
  try {
    const { data } = await axios.get('/api/watchlist')
    watchlistItems.value = Array.isArray(data) ? data : []
    await Promise.all([loadPinBatch(), loadUABadge(watchlistItems.value.map(i => i.symbol))])
  } catch {}
}

async function loadPinBatch() {
  const syms = [...new Set(watchlistItems.value.map(i => i.symbol))]
  if (!syms.length) { pinMap.value = {}; return }
  try {
    const { data } = await axios.get('/api/expiry-pressure/batch', { params: { symbols: syms, days: pinDays } })
    pinMap.value = data?.items || {}
  } catch { pinMap.value = {} }
}

async function loadUABadge(symbols) {
  const uniq = [...new Set(symbols)]
  const out = {}
  await Promise.all(uniq.map(async (s) => {
    try {
      const { data } = await axios.get('/api/ua', { params: { symbol: s } })
      out[s] = { data_date: data?.data_date || null, count: (data?.items || []).length }
    } catch { out[s] = { data_date: null, count: 0 } }
  }))
  uaMap.value = out
}

function n(v, d = 0) { return Number.isFinite(Number(v)) ? Number(v) : d }

// --- Normalizers for chart inputs ---
function toNetGexSeries(arr) {
  // Accept {strike, net_gex, net_gex_delta} OR {strike, net_gex_live, net_gex_delta}
  return (arr || []).map(r => ({
    strike: n(r.strike),
    netGex: n(r.net_gex ?? r.net_gex_live ?? 0),
    netGexDelta: n(r.net_gex_delta ?? 0),
  }))
}

function toVolOiSeries(arr) {
  // Accept {strike, vol_oi} OR {strike, volOI}
  return (arr || []).map(r => ({
    strike: n(r.strike),
    volOi: n(r.vol_oi ?? r.volOI ?? 0),
  }))
}

function toPcrSeries(arr) {
  // Accept {strike, pcr} (null allowed -> will be filtered in the chart)
  return (arr || []).map(r => ({
    strike: n(r.strike),
    pcr: (r.pcr === null || r.pcr === undefined) ? null : Number(r.pcr),
  }))
}

function toPremiumSeries(arr) {
  // Accept {call_prem, put_prem} or camelCase
  return (arr || []).map(r => ({
    strike: n(r.strike),
    premiumCall: n(r.call_prem ?? r.premium_call ?? 0),
    premiumPut:  n(r.put_prem  ?? r.premium_put  ?? 0),
  }))
}

const normalizeStrikes = (arr = []) => arr.map(r => ({
  strike: r.strike,

  // volume deltas (your feed sometimes uses *_vol_delta)
  call_volume_delta: r.call_volume_delta ?? r.call_vol_delta ?? 0,
  put_volume_delta:  r.put_volume_delta  ?? r.put_vol_delta  ?? 0,

  // OI deltas (not in your payload — will be 0 unless you compute them)
  call_oi_delta: r.call_oi_delta ?? r.call_open_interest_delta ?? 0,
  put_oi_delta:  r.put_oi_delta  ?? r.put_open_interest_delta  ?? 0,

  // other fields used by tiles
  vol_oi: r.vol_oi ?? 0,
  pcr: r.pcr ?? null,
  premium_call: r.premium_call ?? 0,
  premium_put: r.premium_put ?? 0,
}));
</script>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>