<template>
  <div class="min-h-screen bg-gray-950 text-white">
    <!-- First-run onboarding -->
    <div
      v-if="showOnboarding"
      class="fixed inset-0 z-[999] flex items-center justify-center bg-black/80 backdrop-blur"
    >
      <div class="w-full max-w-3xl rounded-2xl border border-cyan-500/40 bg-gray-900/95 p-8 shadow-2xl">
        <p class="text-xs uppercase tracking-[0.2em] text-cyan-300 mb-3">Quick start</p>
        <h2 class="text-3xl font-bold text-white">Welcome to GexOptions</h2>
        <p class="mt-2 text-sm text-gray-300">
          In under 5 minutes, you’ll know where dealer positioning matters today.
        </p>

        <div class="mt-6 space-y-3 text-sm text-gray-200">
          <div class="flex items-start gap-2">
            <span class="text-cyan-300">1️⃣</span>
            <div>
              <div class="font-semibold">See today’s key levels first</div>
              <div class="text-gray-400">We’ll take you straight to SPY and zoom you into Net GEX by strike.</div>
            </div>
          </div>
          <div class="flex items-start gap-2">
            <span class="text-cyan-300">2️⃣</span>
            <div>
              <div class="font-semibold">Value before settings</div>
              <div class="text-gray-400">No menus or choices—just the map dealers are hedging against today.</div>
            </div>
          </div>
        </div>

        <div class="mt-8 flex flex-wrap items-center gap-3">
          <button
            class="w-full sm:w-auto rounded-xl bg-cyan-500 px-5 py-3 text-sm font-semibold text-gray-900 hover:bg-cyan-400 transition shadow-lg shadow-cyan-500/30"
            @click="startGuidedView"
          >
            View Today’s Key Levels (SPY)
          </button>
          <button
            class="text-sm text-gray-400 hover:text-white"
            @click="dismissOnboarding"
          >
            Skip for now
          </button>
        </div>

        <p class="mt-4 text-xs text-gray-400">
          Most traders check this before the open to frame risk — not to predict direction.
        </p>
      </div>
    </div>
    <!-- Trading Terminal Header -->
    <header class="sticky top-0 z-50 border-b border-gray-800 bg-gray-900/95 backdrop-blur-sm">
      <div class="flex flex-col gap-3 px-3 py-3 sm:px-4 md:flex-row md:items-center md:justify-between">
        <div class="min-w-0">
          <div class="flex items-center gap-2 sm:gap-3">
            <h1 class="truncate text-base font-bold leading-tight tracking-tight sm:text-xl">
              GEX Levels<span class="hidden sm:inline"> & Analytics</span>
            </h1>

            <div class="flex min-w-0 items-center gap-1.5 sm:gap-2">
              <span class="truncate text-base font-mono text-cyan-400 sm:text-lg">{{ userSymbol }}</span>
              <button @click="showSymbolPicker = true" class="text-xs text-gray-400 hover:text-white">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </button>

              <span
                v-if="dataMode === 'intraday' && intradayTransition"
                class="inline-flex items-center gap-1 rounded-full border border-amber-500/40 bg-amber-500/10 px-2 py-0.5 text-[10px] text-amber-100 sm:text-[11px]"
              >
                <svg class="h-3 w-3 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 4v2m0 12v2m8-8h-2M6 12H4m13.657-5.657l-1.414 1.414M7.757 16.243l-1.414 1.414m0-11.314l1.414 1.414M16.243 16.243l1.414 1.414" />
                </svg>
                Updating...
              </span>
            </div>
          </div>

          <div v-if="dataMode === 'eod'" class="mt-2 flex items-center gap-2 md:hidden">
            <span class="text-[10px] uppercase tracking-wider text-gray-400">Timeframe</span>
            <div class="flex rounded-lg overflow-hidden border border-gray-700">
              <button
                v-for="tf in visibleTimeframeOptions"
                :key="`mobile-${tf.value}`"
                @click="gexTf = tf.value"
                class="px-2.5 py-1 text-[11px] font-medium transition"
                :class="gexTf === tf.value ? 'bg-cyan-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'"
              >
                {{ tf.label }}
              </button>
            </div>
          </div>
        </div>

        <div v-if="dataMode === 'eod'" class="hidden items-center gap-2 md:flex">
          <span class="text-xs uppercase tracking-wider text-gray-400">Timeframe</span>
          <div class="flex rounded-lg overflow-hidden border border-gray-700">
            <button
              v-for="tf in visibleTimeframeOptions"
              :key="tf.value"
              @click="gexTf = tf.value"
              class="px-3 py-1.5 text-xs font-medium transition"
              :class="gexTf === tf.value ? 'bg-cyan-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'"
            >
              {{ tf.label }}
            </button>
          </div>
        </div>

        <div class="flex items-start justify-between gap-3 md:items-center">
          <div class="flex rounded-lg overflow-hidden border border-gray-700">
            <button
              @click="setMode('eod')"
              class="flex items-center gap-1 px-3 py-1.5 text-xs font-medium transition sm:gap-1.5 sm:px-4 sm:text-sm"
              :class="dataMode === 'eod' ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'"
            >
              <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              EOD
            </button>

            <button
              @click="setMode('intraday')"
              class="flex items-center gap-1 px-3 py-1.5 text-xs font-medium transition sm:gap-1.5 sm:px-4 sm:text-sm"
              :class="dataMode === 'intraday' ? 'bg-green-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'"
            >
              <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
              <span class="hidden sm:inline">Intraday</span>
              <span class="sm:hidden">Live</span>
              <span v-if="dataMode === 'intraday'" class="text-[10px] opacity-80">15m</span>
            </button>
          </div>

          <div class="text-right text-[11px] text-gray-400 sm:text-xs">
            <span v-if="dataMode === 'eod' && levels?.data_date">
              EOD: {{ levels.data_date }}
              <span v-if="levels.data_age_days > 0" class="ml-1 text-[10px] text-amber-400 sm:text-[11px]">
                ({{ levels.data_age_days }}d old)
              </span>
            </span>
            <span v-else-if="dataMode === 'intraday'" class="flex items-center justify-end gap-1">
              <span class="font-medium" :class="marketOpen && intradaySourceAge !== null && intradaySourceAge < 90 ? 'text-green-400' : 'text-amber-300'">{{ intradaySourceLabel }}</span>
              <button @click="manualRefresh" class="ml-1 text-cyan-400 hover:text-cyan-300">
                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
    <div class="sticky top-[65px] z-40 border-b border-gray-800 bg-gray-900/95 backdrop-blur-sm">
      <nav class="flex gap-1 overflow-x-auto px-3 py-2 no-scrollbar sm:px-4">
        <button
          v-for="t in tabMeta"
          :key="t.key"
          @click="activate(t.key)"
          class="flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-xs font-medium transition sm:gap-2 sm:px-5 sm:text-sm"
          :class="activeTab === t.key
            ? 'bg-gradient-to-r from-cyan-600 to-blue-600 text-white shadow-lg shadow-cyan-500/20'
            : t.state === 'ready' || t.state === 'idle'
              ? 'text-gray-400 hover:text-white hover:bg-gray-800'
              : 'text-gray-500 bg-gray-800/60'"
        >
          <component :is="t.icon" class="h-4 w-4" />
          {{ t.label }}
          <span v-if="t.badge" class="ml-1 px-1.5 py-0.5 text-[10px] rounded-full bg-white/20">
            {{ t.badge }}
          </span>
          <span v-else-if="t.state === 'pending'" class="text-[10px] text-amber-300">Preparing…</span>
          <span v-else-if="t.state === 'error'" class="text-[10px] text-red-400">Unavailable</span>
        </button>
      </nav>
    </div>

    <!-- Body -->
    <div class="p-4 space-y-6">
      <!-- Loading / Error -->
      <ui-error-block v-if="topError" :message="'Failed to load data'" :detail="topError"
                     :onRetry="() => dataMode === 'eod' ? fetchGexLevelsEOD(userSymbol, gexTf) : refreshIntraday()" />
      <ui-spinner
        v-else-if="dataMode==='intraday'
          ? (!firstIntradayLoadDone && intradayLoading)
          : (loading && !preparing.active)"
      />

      <template v-else>
        <div
          v-if="dataMode==='eod' && preparing.active"
          class="bg-amber-500/10 border border-amber-500/30 text-amber-100 text-sm px-4 py-3 rounded-lg flex items-start gap-2"
        >
          <svg class="w-4 h-4 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 5a7 7 0 00-7 7v2a7 7 0 0014 0v-2a7 7 0 00-7-7z" />
          </svg>
          <div>
            <div class="font-semibold">Preparing {{ userSymbol }} data</div>
            <div class="text-amber-200/80">Some tabs will appear as soon as they’re ready; others are still loading the first snapshot.</div>
          </div>
        </div>
        <div
          v-if="dataMode==='eod' && preparationNotice && levels"
          role="status"
          aria-live="polite"
          class="flex items-start gap-2 rounded-lg border px-4 py-3 text-sm"
          :class="preparationNotice.warning
            ? 'border-amber-500/30 bg-amber-500/10 text-amber-100'
            : 'border-cyan-500/30 bg-cyan-500/10 text-cyan-100'"
        >
          <svg v-if="preparationNotice.spinning" class="mt-0.5 h-4 w-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <svg v-else class="mt-0.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86l-7.82 13.5A2 2 0 004.2 20h15.6a2 2 0 001.73-3l-7.82-13.5a2 2 0 00-3.42 0z" />
          </svg>
          <div>
            <div class="font-semibold">
              {{ preparationNotice.title }}
            </div>
            <div :class="preparationNotice.warning ? 'text-amber-200/80' : 'text-cyan-200/80'">
              {{ preparationNotice.message }}
              <span v-if="preparationNotice.coverageLabel"> {{ preparationNotice.coverageLabel }}</span>
            </div>
          </div>
        </div>
        <div
          v-if="dataMode==='intraday' && !marketOpen"
          class="bg-slate-500/10 border border-slate-400/30 text-slate-100 text-sm px-4 py-3 rounded-lg flex items-start gap-2"
        >
          <svg class="w-4 h-4 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div>
            <div class="font-semibold">
              {{ intradayHasData ? 'Showing last available intraday snapshot' : `No intraday snapshot for ${userSymbol} yet` }}
            </div>
            <div class="text-slate-200/80">
              <template v-if="intradayHasData">
                <span v-if="intradaySnapshotAsOf">Source as of {{ intradayAsOfEtLabel }} ET.</span>
                <span v-else>Provider update time is unavailable.</span>
                Live updates resume {{ intradayNextOpenLabel }}.
              </template>
              <template v-else>
                The market is closed. The first live snapshot can be collected {{ intradayNextOpenLabel }}.
              </template>
            </div>
          </div>
        </div>
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
        <Suspense v-if="activeTab==='positioning' && dataMode==='eod' && tabState('positioning')==='ready'">
          <section class="space-y-4">
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
        <section
          v-else-if="activeTab==='positioning' && dataMode==='eod'"
          class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700 text-sm text-gray-300"
        >
          <div v-if="tabState('positioning')==='pending'">
            Positioning is being prepared for {{ userSymbol }}… we’ll show it as soon as it’s ready.
          </div>
          <div v-else class="text-red-300">
            Positioning unavailable: {{ tabStatus.positioning.err || 'Data not ready yet.' }}
          </div>
        </section>

        <!-- VOLATILITY -->
        <section
          v-if="activeTab==='volatility' && dataMode==='eod' && tabState('volatility')==='ready'"
          class="space-y-4"
        >
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
              <div class="flex items-center justify-between mb-2">
                <h4 class="font-semibold">Term Structure</h4>
                <span v-if="term?.date" class="text-xs text-gray-400">as of {{ term.date }}</span>
              </div>
              <ui-error-block v-if="volErrors.term" :message="'Failed to load volatility data'"
                            :detail="volErrors.term" :onRetry="ensureVolatility" />
              <ui-skeleton-card v-else-if="volState.term === 'loading' || volState.term === 'pending'" />
              <TermTile v-else :items="term.items || []" :date="term.date" />
            </div>

            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
              <div class="flex items-center justify-between mb-2">
                <h4 class="font-semibold">Variance Risk Premium</h4>
                <span v-if="vrp?.date" class="text-xs text-gray-400">as of {{ vrp.date }}</span>
              </div>
              <ui-error-block v-if="volErrors.vrp" :message="'Failed to load volatility data'"
                            :detail="volErrors.vrp" :onRetry="ensureVolatility" />
              <ui-skeleton-card v-else-if="volState.vrp === 'loading' || volState.vrp === 'pending'" />
              <VRPTile v-else :date="vrp.date" :iv1m="vrp.iv1m" :rv20="vrp.rv20" :vrp="vrp.vrp" :z="vrp.z" />
            </div>
          </div>

          <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
            <h4 class="font-semibold mb-2">Seasonality (5D)</h4>
            <ui-error-block v-if="volErrors.season" :message="'Failed to load seasonality'"
                          :detail="volErrors.season" :onRetry="ensureVolatility" />
            <ui-skeleton-card v-else-if="volState.season === 'loading' || volState.season === 'pending'" />
            <template v-else>
              <Seasonality5Tile
                v-if="season"
                :date="season.date"
                :d1="season.d1" :d2="season.d2" :d3="season.d3" :d4="season.d4" :d5="season.d5"
                :cum5="season.cum5" :z="season.z" :note="seasonNote" />
              <div v-else class="text-sm text-gray-400">{{ seasonNote || 'No seasonality data.' }}</div>
            </template>
            <div v-if="volErr" class="text-red-400 text-sm mt-2">Vol metrics error: {{ volErr }}</div>
          </div>
        </section>
        <section
          v-else-if="activeTab==='volatility' && dataMode==='eod'"
          class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700 text-sm text-gray-300"
        >
          <div v-if="tabState('volatility')==='pending'">
            Volatility metrics are being prepared for {{ userSymbol }}… we’ll show them as soon as they’re ready.
          </div>
          <div v-else class="text-red-300">
            Volatility unavailable: {{ tabStatus.volatility.err || 'Data not ready yet.' }}
          </div>
        </section>

        <!-- UA -->
        <section
          v-if="activeTab==='ua' && tabState('ua')==='ready'"
          class="space-y-4"
        >
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
        <section
          v-else-if="activeTab==='ua'"
          class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700 text-sm text-gray-300"
        >
          <div v-if="tabState('ua')==='pending'">
            Unusual Activity is being prepared for {{ userSymbol }}… we’ll surface it as soon as it’s ready.
          </div>
          <div v-else class="text-red-300">
            Unusual Activity unavailable: {{ tabStatus.ua.err || 'Data not ready yet.' }}
          </div>
        </section>

        <!-- STRIKES -->
        <section v-show="activeTab==='strikes'" class="space-y-4">
          <!-- EOD: OI + Net GEX + ΔVol (EOD) -->
          <template v-if="dataMode==='eod'">
            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700" ref="netGexSection">
              <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div>
                  <h4 class="font-semibold">Net GEX by Strike (EOD)</h4>
                  <p class="text-xs text-gray-400">Zoomed to the most active band so you see where hedging bites first.</p>
                </div>
                <div class="text-xs bg-gray-900/80 border border-gray-700 rounded-lg p-3 text-gray-200">
                  <div class="font-semibold mb-1 text-white">How to read Net GEX</div>
                  <ul class="space-y-1">
                    <li>• Positive GEX → dealers hedge with price → ranges compress</li>
                    <li>• Negative GEX → dealers hedge against price → moves expand</li>
                    <li>• Large clusters → reaction zones, not targets</li>
                  </ul>
                </div>
              </div>

              <NetGexChart :strikeData="levels?.strike_data || []" />

              <div
                v-if="checklistVisible"
                class="mt-4 rounded-xl border border-cyan-500/30 bg-cyan-500/5 p-3 text-xs text-cyan-100"
              >
                <div class="font-semibold mb-2 text-white">First-day checklist</div>
                <ul class="space-y-1">
                  <li>☑️ Check today’s Net GEX near spot</li>
                  <li>☑️ Note the closest large positive / negative level</li>
                  <li>☑️ Watch how price reacts at that level</li>
                </ul>
                <div class="mt-2 text-[11px] text-cyan-200">
                  You’re not looking for predictions — just context.
                </div>
                <div class="mt-2">
                  <button
                    class="text-[11px] text-cyan-300 hover:text-cyan-100 underline"
                    @click="dismissChecklist"
                  >
                    Got it
                  </button>
                </div>
              </div>
            </div>
            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
              <div class="mb-3">
                <h4 class="font-semibold">ΔOI by Strike (EOD)</h4>
                <p v-if="levels?.date_prev" class="text-xs mt-1" :class="levels?.date_prev_is_stale ? 'text-amber-300' : 'text-gray-400'">
                  Comparing against {{ levels.date_prev }}
                  <span v-if="levels?.date_prev_gap_trading_days != null">
                    ({{ levels.date_prev_gap_trading_days }} trading day<span v-if="levels.date_prev_gap_trading_days !== 1">s</span> back)
                  </span>
                  <span v-if="levels?.date_prev_is_stale"> because the prior session snapshot for this expiry set is incomplete.</span>
                </p>
              </div>
              <StrikeDeltaChart
                :strikeData="strikeSeriesForDelta"
                height-class="h-80 md:h-96 xl:h-[26rem]"
                snapshot-name="delta-oi-eod"
              />
            </div>
            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
              <div class="mb-3">
                <h4 class="font-semibold">ΔVol by Strike (EOD)</h4>
                <p v-if="levels?.date_prev" class="text-xs mt-1" :class="levels?.date_prev_is_stale ? 'text-amber-300' : 'text-gray-400'">
                  Comparing against {{ levels.date_prev }}
                  <span v-if="levels?.date_prev_gap_trading_days != null">
                    ({{ levels.date_prev_gap_trading_days }} trading day<span v-if="levels.date_prev_gap_trading_days !== 1">s</span> back)
                  </span>
                  <span v-if="levels?.date_prev_is_stale"> because the prior session snapshot for this expiry set is incomplete.</span>
                </p>
              </div>
              <VolumeDeltaChart
                :strikeData="strikeSeriesForDelta"
                height-class="h-80 md:h-96 xl:h-[26rem]"
                snapshot-name="delta-vol-eod"
              />
            </div>
          </template>

          <!-- Intraday: only ΔVol (Live) -->
          <template v-else>
            <div class="space-y-4">
              <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
                <h4 class="font-semibold mb-3">Vol / OI (Live) by Strike</h4>
                <div class="h-1/3">
                  <VolOverOiChart
                    :strikeData="toVolOiSeries(levels?.strike_data || [])"
                  />
                </div>
              </div>

              <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
                <h4 class="font-semibold mb-3">PCR (Live) by Strike</h4>
                <div class="h-1/3">
                  <PcrByStrikeChart
                    :strikeData="toPcrSeries(levels?.strike_data || [])"
                  />
                </div>
              </div>

              <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
                <h4 class="font-semibold mb-3">Premium (Live) by Strike</h4>
                <div class="h-1/3">
                  <PremiumByStrikeChart
                    :strikeData="toPremiumSeries(levels?.strike_data || [])"
                  />
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
              snapshot-name="flow-delta-live"
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
import {
  ref, reactive, computed, watch, onMounted, onUnmounted, nextTick,
  h, defineComponent, defineAsyncComponent
} from 'vue'
import axios from 'axios'
import { coalesceDashboardRequest } from '@/Support/dashboard-request-scope.js'
import {
  bootstrapPollDelayMs,
  bootstrapPreparationNotice,
  ownsPreparationPoll,
  symbolPreparationState,
} from '@/Support/symbol-bootstrap-state.js'

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
  { key: 'strikes', label: 'Live Strikes', icon: defineComponent({ render: () => h('svg', { class: 'w-4 h-4', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z' })]) }) },
]

const currentTabs = computed(() => dataMode.value === 'eod' ? tabsEOD : tabsIntraday)
const tabStatus = reactive({
  positioning: { state: 'idle', err: '' },
  volatility:  { state: 'idle', err: '' },
  ua:          { state: 'idle', err: '' },
})
const tabPollers = { positioning: null, volatility: null, ua: null }
let volRetryTimer = null
const tabState = (key) => {
  if (!tabStatus[key]) return 'ready'
  return tabStatus[key].state
}
const tabMeta = computed(() =>
  currentTabs.value.map(t => ({
    ...t,
    state: tabState(t.key),
    err: tabStatus[t.key]?.err || '',
  }))
)

// State
const getDefaultTab = (mode) => mode === 'intraday' ? 'flow' : 'strikes'
const activeTab = ref(getDefaultTab('eod'))
const eodLevels = ref(null)
const intradayLevels = ref(null)
const levels = computed(() => dataMode.value === 'eod' ? eodLevels.value : intradayLevels.value)
// Separate loading states
const eodLoading = ref(false)
const intradayLoading = ref(false)
const intradayRefreshing = ref(false)
const firstIntradayLoadDone = ref(false)
const loading = computed(() => dataMode.value === 'eod' ? eodLoading.value : intradayLoading.value)
const timeframeAvailability = computed(() => {
  const map = levels.value?.timeframe_expirations
  if (map && typeof map === 'object') {
    const keys = Object.entries(map)
      .filter(([, dates]) => Array.isArray(dates) && dates.length)
      .map(([tf]) => tf)
    return new Set(keys)
  }
  if (Array.isArray(levels.value?.available_timeframes)) {
    return new Set(levels.value.available_timeframes)
  }
  return null
})
const visibleTimeframeOptions = computed(() => {
  const avail = timeframeAvailability.value
  if (!avail || avail.size === 0) return timeframeOptions
  const filtered = timeframeOptions.filter(tf => avail.has(tf.value))
  return filtered.length ? filtered : timeframeOptions
})

// Separate error states
const eodError = ref('')
const intradayError = ref('')
const preparing = ref({
  active: false,
  phase: 'queued',
  timer: null,
  symbol: null,
  statusUrl: null,
  fastReady: false,
  fullReady: false,
  eodReady: false,
  enrichmentReady: false,
  enrichmentStatus: '',
  intradayReady: false,
  intradayStatus: '',
  runId: null,
  runGeneration: null,
  partial: false,
  partialFailed: false,
  filling: false,
  terminal: false,
  retryable: false,
  coverage: null,
})
const preparationNotice = computed(() => bootstrapPreparationNotice(preparing.value, userSymbol.value))
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
const initialSymbol = typeof window === 'undefined' ? '' : new URLSearchParams(window.location.search).get('symbol')
const symbol = ref(initialSymbol?.trim().toUpperCase() || 'SPY')
const gexTf = ref('14d')
const userSymbol = symbol
const showOnboarding = ref(false)
const checklistVisible = ref(false)
const netGexSection = ref(null)
const cache = new Map()
const cacheTerm = new Map()
const cacheVRP = new Map()
const cacheSeas = new Map()
const cacheUA = new Map()
const TTL_MS = 300_000
const volErr = ref(null)
const volErrors = reactive({ term: '', vrp: '', season: '' })
const volState = reactive({ term: 'idle', vrp: 'idle', season: 'idle' })
let disposed = false
let pageGeneration = 0
let tabGeneration = 0
let uaLoadGeneration = 0
let uaActiveKey = null
let volatilityLoad = null
let preparedRefreshTimer = null
let positioningFrame = null
let positioningTimer = null
const bootstrapControllers = new Map()
const bootstrapInflight = new Map()
const inflight = new Map()
const marketOpen = ref(false)
const inflightIntraday = new Map()
const cacheIntraday = new Map()
const INTRADAY_TTL_MS = 60_000 // 1 minute cache window
const INTRADAY_PENDING_RETRY_MS = 5_000
const INTRADAY_PENDING_MAX_RETRIES = 12
const intradayDataSymbol = ref(null)
const intradaySnapshotAsOf = ref(null)
const intradaySnapshotAvailable = ref(false)
const intradayNextOpen = ref(null)
const intradaySourceAge = ref(null)
const intradaySourceLabel = computed(() => {
  if (!marketOpen.value) return 'Market Closed'
  if (intradaySourceAge.value === null) return 'Provider update time unavailable'
  return intradaySourceAge.value < 90 ? 'Live' : `Delayed (${Math.floor(intradaySourceAge.value / 60)}m)`
})
const intradayNextOpenLabel = computed(() => intradayNextOpen.value
  ? `at ${formatEtDateTime(intradayNextOpen.value)} ET`
  : 'at the next trading session')
const intradayHasData = computed(() => {
  if (intradayDataSymbol.value !== userSymbol.value || !intradaySnapshotAvailable.value) return false

  const rows = levels.value?.strike_data
  if (Array.isArray(rows) && rows.length > 0) return true

  const callVol = Number(levels.value?.call_volume_total || 0)
  const putVol = Number(levels.value?.put_volume_total || 0)
  return (callVol + putVol) > 0
})
const intradayAsOfEtLabel = computed(() => formatEtDateTime(lastUpdated.value))

// Symbol picker
const showSymbolPicker = ref(false)
const symbolSearch = ref('')

const intradayTransition = computed(() =>
  dataMode.value === 'intraday' &&
  intradayRefreshing.value &&
  intradayDataSymbol.value &&
  userSymbol.value !== intradayDataSymbol.value
)

function pickSymbol(sym) {
  const s = sym.trim().toUpperCase()
  if (s) {
    kickoffSymbolWarm(s, gexTf.value)
    userSymbol.value = s
  }
  showSymbolPicker.value = false
  symbolSearch.value = ''
}

// Controllers
const controllers = { gex_eod: null, gex_intraday: null, term: null, vrp: null, season: null, ua: null, readiness: null }

function withInflight(key, fn) {
  return coalesceDashboardRequest(inflight, key, fn)
}

function ownsTab(sym, mode, tab, generation = tabGeneration) {
  return !disposed && userSymbol.value === sym && dataMode.value === mode
    && activeTab.value === tab && generation === tabGeneration
}

function stopAuxiliaryWork() {
  tabGeneration += 1
  uaLoadGeneration += 1
  uaActiveKey = null
  volatilityLoad = null
  if (volRetryTimer) clearTimeout(volRetryTimer)
  volRetryTimer = null
  Object.keys(tabPollers).forEach(stopTabPoll)
  for (const type of ['term', 'vrp', 'season', 'ua', 'readiness']) {
    controllers[type]?.abort()
    controllers[type] = null
  }
  for (const key of inflight.keys()) {
    if (!key.startsWith('gex:')) inflight.delete(key)
  }
}

function stopPageWork() {
  pageGeneration += 1
  eodLoadGeneration += 1
  stopAuxiliaryWork()
  stopRefresh()
  stopPreparingPoll({ reset: true })
  clearTimeout(symbolTimer)
  clearTimeout(preparedRefreshTimer)
  preparedRefreshTimer = null
  if (positioningFrame !== null) cancelAnimationFrame(positioningFrame)
  clearTimeout(positioningTimer)
  positioningFrame = positioningTimer = null
  busy.value.positioning = false
  for (const type of Object.keys(controllers)) {
    controllers[type]?.abort()
    controllers[type] = null
  }
  inflight.clear()
  inflightIntraday.clear()
  for (const [key, entry] of bootstrapControllers) {
    if (disposed || entry.symbol !== userSymbol.value) {
      entry.controller.abort()
      bootstrapControllers.delete(key)
      bootstrapInflight.delete(key)
    }
  }
}

// Watchers
// watch(dataMode, (mode) => {
//   if (mode === 'intraday' && activeTab.value === 'overview') activeTab.value = 'flow'
//   if (mode === 'eod' && activeTab.value === 'flow') activeTab.value = 'overview'
// })

watch(tabMeta, (tabs) => {
  const active = tabs.find(t => t.key === activeTab.value)
  if (!active) activeTab.value = getDefaultTab(dataMode.value)
})

function activate(key) {
  if (activeTab.value === key) {
    ensureActiveTab()
    return
  }
  activeTab.value = key
}

const preferredTf = (target) => {
  const avail = timeframeAvailability.value
  if (avail && !avail.has(target)) {
    const first = [...avail][0]
    return first || gexTf.value
  }
  return target
}

function scrollToNetGex() {
  nextTick(() => {
    if (!disposed && netGexSection.value) {
      netGexSection.value.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }
  })
}

function startGuidedView() {
  showOnboarding.value = false
  localStorage.setItem('gex_onboarding_v1', 'seen')

  dataMode.value = 'eod'
  userSymbol.value = 'SPY'
  activeTab.value = 'strikes'

  // force a fresh load for SPY / 0d, then scroll to Net GEX
  const owner = pageGeneration
  fetchGexLevelsEOD(userSymbol.value, '0d', { applyTf: true }).then(() => {
    if (!disposed && owner === pageGeneration) scrollToNetGex()
  })
}

function dismissOnboarding() {
  showOnboarding.value = false
  const today = new Date().toISOString().slice(0, 10)
  // Mark as skipped for today only; will reappear tomorrow unless user completes CTA
  localStorage.setItem('gex_onboarding_v1', `skipped:${today}`)
}

function dismissChecklist() {
  checklistVisible.value = false
  localStorage.setItem('gex_checklist_v1_dismissed', '1')
}

function setMode(mode) {
  if (dataMode.value === mode) return
  dataMode.value = mode
  activeTab.value = getDefaultTab(mode)
}

// Data refresh on mode/tab change
watch([dataMode, activeTab], ([mode, tab], [oldMode, oldTab]) => {
  if (disposed) return
  stopAuxiliaryWork()
  ensureActiveTab()
  // Mode change
  if (mode !== oldMode) {
    if (mode === 'intraday') {
      refreshIntraday()
      startAutoRefresh()
    } else {
      stopRefresh()
      fetchGexLevelsEOD(userSymbol.value, gexTf.value)
    }
  }

  // Tab change - only refresh if data might be stale
  if (tab !== oldTab && mode === oldMode) {
    if (tab === 'strikes') {
      if (mode === 'intraday') refreshIntraday()
      if (mode === 'eod') fetchGexLevelsEOD(userSymbol.value, gexTf.value)
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
function formatEtDateTime(ts) {
  if (!ts) return '—'
  const dt = new Date(ts)
  if (Number.isNaN(dt.getTime())) return '—'
  return new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/New_York',
    month: 'short',
    day: '2-digit',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  }).format(dt)
}
function fromNow(ts) {
  if (!ts) return ''
  const then = new Date(ts).getTime()
  const diff = Math.round((then - Date.now()) / 1000)
  const skewSafe = Math.abs(diff) <= 5 ? -Math.abs(diff) : diff
  const abs = Math.abs(skewSafe)
  const steps = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]]
  for (const [unit, s] of steps) {
    const amt = Math.trunc(skewSafe / s)
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

function stopTabPoll(key) {
  if (tabPollers[key]) {
    clearTimeout(tabPollers[key])
    tabPollers[key] = null
  }
}

function resetTabReadiness() {
  Object.keys(tabStatus).forEach(k => { tabStatus[k].state = 'idle'; tabStatus[k].err = '' })
  Object.keys(tabPollers).forEach(stopTabPoll)
}

function isPreparing(err) {
  const status = err?.response?.status
  const msg = err?.response?.data?.error || err?.message || ''
  return status === 404 || status === 202 || /preparing|queued|no data|no expirations/i.test(msg)
}

function pendingResponse(response) {
  if (response?.status !== 202) return response
  const error = new Error(response.data?.error || 'Data is preparing')
  error.response = response
  throw error
}

function pollActiveTab(key, retry, isCurrent) {
  stopTabPoll(key)
  if (!isCurrent()) return
  tabPollers[key] = setTimeout(() => {
    tabPollers[key] = null
    if (isCurrent()) retry()
  }, 5000)
}

async function ensureTabReady(key) {
  if (key !== 'positioning' || activeTab.value !== key || dataMode.value !== 'eod' || disposed) return
  if (tabStatus[key].state === 'ready') return
  const sym = userSymbol.value
  const generation = tabGeneration
  const ctl = ensureController('readiness')
  const isCurrent = () => ownsTab(sym, 'eod', key, generation) && !ctl.signal.aborted
  tabStatus[key].state = 'pending'
  tabStatus[key].err = ''
  try {
    const response = await withInflight(`readiness:${key}:${sym}`, () =>
      axios.get('/api/dex', { params: { symbol: sym }, signal: ctl.signal }))
    if (!isCurrent()) return
    pendingResponse(response)
    tabStatus[key].state = 'ready'
    stopTabPoll(key)
  } catch (e) {
    if (!isCurrent()) return
    if (isPreparing(e)) {
      tabStatus[key].state = 'pending'
      pollActiveTab(key, () => ensureTabReady(key), isCurrent)
    } else {
      tabStatus[key].state = 'error'
      tabStatus[key].err = e?.response?.data?.error || e.message || 'Unavailable'
      stopTabPoll(key)
    }
  }
}

function ensureActiveTab() {
  if (disposed) return
  if (activeTab.value === 'volatility' && dataMode.value === 'eod') ensureVolatility()
  if (activeTab.value === 'ua') ensureUA()
  if (activeTab.value === 'positioning') ensureTabReady('positioning')
}

// Data loaders
let eodLoadGeneration = 0

async function fetchGexLevelsEOD(sym, tf = gexTf.value, opts = { applyTf: true }) {
  if (disposed || userSymbol.value !== sym || dataMode.value !== 'eod') return
  const generation = ++eodLoadGeneration
  const isCurrent = () => !disposed && generation === eodLoadGeneration
    && userSymbol.value === sym
    && dataMode.value === 'eod'
  const key = `gex|${sym}|${tf}`
  const hit = cache.get(key)
  if (hit && Array.isArray(hit.data?.strike_data) && Date.now() - hit.t < TTL_MS) {
    eodError.value = ''
    eodLoading.value = false
    eodLevels.value = hit.data
    lastUpdated.value = new Date().toISOString()
    preparing.value.active = false
    if (opts?.applyTf && gexTf.value !== tf) gexTf.value = tf

    const startResponse = bootstrapStartResponses.get(sym) || null
    if (startResponse) bootstrapStartResponses.delete(sym)
    await syncPreparationResponse(sym, tf, startResponse || { data: hit.data, status: 200 }, { cached: !startResponse })

    if (!isCurrent()) return
    const keepFillingPoll = preparing.value.symbol === sym
      && !preparing.value.fullReady
      && !preparing.value.terminal
      && !!preparing.value.statusUrl
    if (!keepFillingPoll) stopPreparingPoll()
    return
  }
  cache.delete(key)

  eodLoading.value = true
  eodError.value = ''
  eodLevels.value = null

  const ctl = ensureController('gex_eod')

  try {
    // Share only transport. Every caller must apply the response under its
    // own generation, including a newer caller awaiting the same request.
    const response = await withInflight(`gex:${key}`, () =>
      axios.get('/api/gex-levels', {
        params: { symbol: sym, timeframe: tf },
        signal: ctl.signal
      })
    )
    if (!isCurrent()) return

    // Axios resolves 202. It is a preparation response, not a snapshot, and
    // must never become the five-minute cached Strikes payload.
    if (response.status === 202) {
      const pending = new Error(response.data?.error || 'Initial data is preparing')
      pending.response = response
      throw pending
    }
    const { data } = response
    eodError.value = ''
    eodLevels.value = data || {}
    cache.set(key, { t: Date.now(), data: eodLevels.value })
    uaExp.value = 'ALL'
    lastUpdated.value = new Date().toISOString()
    if (preparing.value.symbol === sym && preparing.value.fastReady) {
      preparing.value.active = false
    }

    const responseState = symbolPreparationState(data, response.status)
    const startResponse = responseState.mode === 'bootstrap'
      ? response
      : bootstrapStartResponses.get(sym) || null
    if (startResponse) bootstrapStartResponses.delete(sym)
    if (startResponse) await syncPreparationResponse(sym, tf, startResponse)

    if (isCurrent() && opts?.applyTf && gexTf.value !== tf) {
      gexTf.value = tf
    }
  } catch (e) {
    if (!isCurrent()) return
    if (e.name !== 'CanceledError' && e.code !== 'ERR_CANCELED') {
      const payload = e?.response?.data || {}
      const msg = payload?.error || e.message || ''
      const status = e?.response?.status
      const responsePreparation = symbolPreparationState(payload, status)
      if (responsePreparation.mode === 'bootstrap' && responsePreparation.terminal) {
        await syncPreparationResponse(sym, tf, e.response)
      }
      const preparingLike = /No data|No expirations|queued|fetching|preparing/i.test(String(msg))
        || (responsePreparation.mode === 'bootstrap' && responsePreparation.shouldPoll)
      const available = Array.isArray(payload?.available_timeframes)
        ? payload.available_timeframes
        : Object.keys(payload?.timeframe_expirations || {})

      // If another timeframe has expirations, auto-switch to it
      if ((status === 404 || status === 202) && available.length) {
        const nextTf = available.includes('14d') ? '14d' : available[0]
        if (nextTf && nextTf !== tf && nextTf !== gexTf.value) {
          return await fetchGexLevelsEOD(sym, nextTf, opts)
        }
      }

      // If it looks like a first-time symbol, go into preparing mode
      if ((status === 404 || status === 202) && preparingLike) {
        eodError.value = ''
        let startResponse = responsePreparation.mode === 'bootstrap'
          ? e.response
          : bootstrapStartResponses.get(sym) || null
        if (!startResponse) startResponse = await kickoffSymbolWarm(sym, tf)
        bootstrapStartResponses.delete(sym)

        if (!isCurrent()) return

        // only start the poller if it's not already running
        if (!preparing.value.timer && !preparingPollController) {
          await startPreparingPoll(
            sym,
            tf,
            (event) => refreshPreparedGex(sym, tf, event),
            startResponse,
          )
        }
      } else {
        eodError.value = msg
      }
    }
  } finally {
    if (isCurrent() && ctl === controllers.gex_eod) eodLoading.value = false
  }
}

async function loadTermAndVRP(sym, isCurrent) {
  const termCtl = ensureController('term')
  const vrpCtl = ensureController('vrp')
  const tKey = `term|${sym}`; const vKey = `vrp|${sym}`
  const tHit = getCache(cacheTerm, tKey, 60_000)
  const vHit = getCache(cacheVRP, vKey, 60_000)
  // Independent requests start together. A failed term response must not
  // prevent VRP from loading, and each tile keeps its own freshness clock.
  return Promise.all([
    (async () => {
      if (tHit?.date) {
        if (isCurrent()) { term.value = tHit; volState.term = 'ready' }
        return
      }
      cacheTerm.delete(tKey)
      volState.term = 'loading'
      try {
        const response = await withInflight(`term:${sym}`, () =>
          axios.get('/api/iv/term', { params: { symbol: sym }, signal: termCtl.signal }))
        if (!isCurrent() || termCtl.signal.aborted) return
        pendingResponse(response)
        term.value = { date: response.data?.date ?? null, items: Array.isArray(response.data?.items) ? response.data.items : [] }
        volState.term = term.value.date ? 'ready' : 'pending'
        if (volState.term === 'ready') setCache(cacheTerm, tKey, term.value)
      } catch (e) {
        if (!isCurrent() || termCtl.signal.aborted) return
        volState.term = isPreparing(e) ? 'pending' : 'error'
        if (!isPreparing(e)) volErrors.term = e?.response?.data?.error || e.message
      }
    })(),
    (async () => {
      if (vHit?.date) {
        if (isCurrent()) { vrp.value = vHit; volState.vrp = 'ready' }
        return
      }
      cacheVRP.delete(vKey)
      volState.vrp = 'loading'
      try {
        const response = await withInflight(`vrp:${sym}`, () =>
          axios.get('/api/vrp', { params: { symbol: sym }, signal: vrpCtl.signal }))
        if (!isCurrent() || vrpCtl.signal.aborted) return
        pendingResponse(response)
        vrp.value = { date: response.data?.date ?? null, iv1m: response.data?.iv1m ?? null, rv20: response.data?.rv20 ?? null, vrp: response.data?.vrp ?? null, z: response.data?.z ?? null }
        volState.vrp = vrp.value.date ? 'ready' : 'pending'
        if (volState.vrp === 'ready') setCache(cacheVRP, vKey, vrp.value)
      } catch (e) {
        if (!isCurrent() || vrpCtl.signal.aborted) return
        volState.vrp = isPreparing(e) ? 'pending' : 'error'
        if (!isPreparing(e)) volErrors.vrp = e?.response?.data?.error || e.message
      }
    })(),
  ])
}

function seasonalityState(data) {
  if (data?.variant) return 'ready'
  // SeasonalityController explicitly returns 200 + variant:null + a note
  // when historical coverage is unavailable. That is a completed empty
  // result, unlike 202 or an incomplete/malformed response.
  if (data && Object.prototype.hasOwnProperty.call(data, 'variant') && data.variant === null) return 'empty'
  return 'pending'
}

async function loadSeasonality(sym, isCurrent) {
  const ctl = ensureController('season')
  const sKey = `seas|${sym}`
  const sHit = getCache(cacheSeas, sKey, 300_000)
  if (sHit && seasonalityState(sHit) !== 'pending') {
    if (isCurrent()) { season.value = sHit.variant; seasonNote.value = sHit.note; volState.season = seasonalityState(sHit) }
    return
  }
  cacheSeas.delete(sKey)
  volState.season = 'loading'
  try {
    const response = await withInflight(`season:${sym}`, () =>
      axios.get('/api/seasonality/5d', { params: { symbol: sym }, signal: ctl.signal })
    )
    if (!isCurrent() || ctl.signal.aborted) return
    const { data } = pendingResponse(response)
    season.value = data?.variant || null
    seasonNote.value = data?.note || ''
    volState.season = seasonalityState(data)
    if (volState.season !== 'pending') setCache(cacheSeas, sKey, data)
  } catch (e) {
    if (!isCurrent() || ctl.signal.aborted) return
    volState.season = isPreparing(e) ? 'pending' : 'error'
    if (!isPreparing(e)) volErrors.season = e?.response?.data?.error || e.message
  }
}

async function loadUA(sym, exp = null) {
  const mode = dataMode.value
  const owner = tabGeneration
  const generation = ++uaLoadGeneration
  const uaUrl = mode === 'intraday' ? '/api/intraday/ua' : '/api/ua'
  const k = ['ua', mode, sym, (exp || 'ALL'), uaTop.value, uaMinZ.value, uaMinVolOI.value, uaMinVol.value, uaMinPrem.value, uaNearPct.value || 0, uaSide.value || '', uaSort.value, uaLimit.value].join('|')
  const ctl = uaActiveKey === k ? ensureController('ua') : cancel('ua')
  if (uaActiveKey !== k) {
    for (const key of inflight.keys()) if (key.startsWith('ua:')) inflight.delete(key)
  }
  uaActiveKey = k
  const isCurrent = () => ownsTab(sym, mode, 'ua', owner) && generation === uaLoadGeneration && !ctl.signal.aborted
  uaLoading.value = true
  errors.value.ua = ''
  loaded.value.ua = false
  tabStatus.ua.state = 'ready'
  stopTabPoll('ua')
  const hit = getCache(cacheUA, k, 60_000)
  if (hit) {
    uaDate.value = hit.data_date || null
    uaRows.value = hit.items || []
    loaded.value.ua = true
    uaLoading.value = false
    return
  }
  const params = {
    symbol: sym, exp, per_expiry: uaTop.value, limit: uaLimit.value,
    min_z: uaMinZ.value, min_vol_oi: uaMinVolOI.value, min_vol: uaMinVol.value,
    min_premium: uaMinPrem.value, near_spot_pct: uaNearPct.value || 0,
    only_side: uaSide.value || null, with_premium: true, sort: uaSort.value,
  }
  try {
    const response = await withInflight(`ua:${k}`, () =>
      axios.get(uaUrl, {
        params,
        signal: ctl.signal
      })
    )
    if (!isCurrent()) return
    const { data } = pendingResponse(response)
    uaDate.value = data?.data_date || null
    uaRows.value = data?.items || []
    loaded.value.ua = true
    setCache(cacheUA, k, data || {})
  } catch (e) {
    if (!isCurrent()) return
    if (isPreparing(e)) {
      tabStatus.ua.state = 'pending'
      pollActiveTab('ua', ensureUA, isCurrent)
    } else {
      errors.value.ua = e?.response?.data || e.message
      uaDate.value = null
      uaRows.value = []
    }
  } finally {
    if (isCurrent()) uaLoading.value = false
  }
}

// Lazy triggers
async function ensureVolatility() {
  if (disposed || activeTab.value !== 'volatility' || dataMode.value !== 'eod') return
  if (volatilityLoad) return volatilityLoad
  const sym = userSymbol.value
  const generation = tabGeneration
  const isCurrent = () => ownsTab(sym, 'eod', 'volatility', generation)
  errors.value.volatility = ''
  Object.keys(volErrors).forEach(key => { volErrors[key] = '' })
  volErr.value = null
  loaded.value.volatility = false
  tabStatus.volatility.state = 'ready'
  if (volRetryTimer) { clearTimeout(volRetryTimer); volRetryTimer = null }
  const request = Promise.all([loadTermAndVRP(sym, isCurrent), loadSeasonality(sym, isCurrent)]).then(() => {
    if (!isCurrent()) return
    const hasData = Object.values(volState).some(state => state === 'ready' || state === 'empty')
    const hasError = Object.values(volErrors).some(Boolean)
    loaded.value.volatility = hasData || hasError
    if (!hasData && !hasError) {
      tabStatus.volatility.state = 'pending'
    }
    if (Object.values(volState).includes('pending')) {
      volRetryTimer = setTimeout(() => {
        volRetryTimer = null
        if (isCurrent()) ensureVolatility()
      }, 5000)
    }
  }).finally(() => {
    if (volatilityLoad === request) volatilityLoad = null
  })
  volatilityLoad = request
  return request
}
async function ensureUA() {
  if (disposed || activeTab.value !== 'ua') return
  return loadUA(userSymbol.value, uaExp.value === 'ALL' ? null : uaExp.value)
}

// Presets / paging
function presetConservative() { uaMinZ.value = 3.0; uaMinVolOI.value = 0.75; uaMinVol.value = 1000; uaNearPct.value = 5; uaSide.value = '' }
function presetAggressive() { uaMinZ.value = 2.0; uaMinVolOI.value = 0.25; uaMinVol.value = 0; uaNearPct.value = 0; uaSide.value = '' }
function showMore() { uaTop.value = Math.min(uaTop.value + 3, 20); uaLimit.value = Math.min(uaLimit.value + 30, 200); ensureUA() }


function handleSelectSymbolEvent(evt) {
  const next = String(evt?.detail?.symbol || '').trim().toUpperCase()
  if (!next) return

  const bootstrapStart = evt?.detail?.bootstrapStart
  const symbolStatus = evt?.detail?.symbolStatus
  const symbolStatusHttpStatus = Number(evt?.detail?.symbolStatusHttpStatus || 0)
  if (bootstrapStart) {
    bootstrapStartResponses.set(next, {
      data: bootstrapStart,
      status: 202,
      headers: {},
    })
  }

  const hintedState = symbolStatus
    ? symbolPreparationState(symbolStatus, symbolStatusHttpStatus)
    : null
  if (!bootstrapStart && hintedState?.mode === 'bootstrap') {
    bootstrapStartResponses.set(next, {
      data: symbolStatus,
      status: symbolStatusHttpStatus,
      headers: {},
    })
  }
  if (!bootstrapStart && !hintedState?.fastReady) {
    kickoffSymbolWarm(next, gexTf.value)
  }

  // If we click the same symbol again, optionally just force a refresh
  if (next === userSymbol.value) {
    if (dataMode.value === 'eod') {
      fetchGexLevelsEOD(next, gexTf.value)
    } else {
      refreshIntraday({ force: true })
    }
    return
  }

  // Normal case: update the symbol – your watcher on userSymbol will do the rest
  stopPreparingPoll({ reset: true })
  cancel('gex_eod')
  userSymbol.value = next
}

function onboardingState() {
  const val = localStorage.getItem('gex_onboarding_v1')
  if (!val) return 'new'
  if (val === 'seen') return 'seen'
  if (val.startsWith('skipped:')) {
    const skippedDate = val.split(':')[1]
    const today = new Date().toISOString().slice(0, 10)
    return skippedDate === today ? 'skipped-today' : 'new'
  }
  return 'new'
}

onMounted(() => {
  const onboarding = onboardingState()
  const checklistDismissed = !!localStorage.getItem('gex_checklist_v1_dismissed')
  showOnboarding.value = onboarding === 'new'
  // Only show checklist on first run AND if not dismissed
  checklistVisible.value = !checklistDismissed && onboarding === 'new'

  // make sure we load something on first render
  const initialTf = showOnboarding.value ? '0d' : gexTf.value
  if (showOnboarding.value) gexTf.value = '0d'
  fetchGexLevelsEOD(userSymbol.value, initialTf, { applyTf: true })

  // listen for watchlist / scanner clicks
  window.addEventListener('select-symbol', handleSelectSymbolEvent)
})


onUnmounted(() => {
  disposed = true
  window.removeEventListener('select-symbol', handleSelectSymbolEvent)
  stopPageWork()
  bootstrapStartResponses.clear()
})

function stopRefresh() {
  if (refreshTimer.value) {
    clearInterval(refreshTimer.value)
    refreshTimer.value = null
  }
  clearIntradayPendingRetry()
}

let intradayPendingTimer = null
let intradayPendingSymbol = null
let intradayPendingAttempts = 0

function clearIntradayPendingRetry() {
  if (intradayPendingTimer) clearTimeout(intradayPendingTimer)
  intradayPendingTimer = null
  intradayPendingSymbol = null
  intradayPendingAttempts = 0
}

function scheduleIntradayPendingRetry(sym) {
  if (disposed) return
  if (intradayPendingSymbol !== sym) {
    clearIntradayPendingRetry()
    intradayPendingSymbol = sym
  }
  if (intradayPendingTimer || intradayPendingAttempts >= INTRADAY_PENDING_MAX_RETRIES) return

  intradayPendingAttempts += 1
  intradayPendingTimer = setTimeout(() => {
    intradayPendingTimer = null
    if (!disposed && dataMode.value === 'intraday' && userSymbol.value === sym) {
      refreshIntraday({ force: true })
    }
  }, INTRADAY_PENDING_RETRY_MS)
}

let preparingPollGeneration = 0
let preparingPollController = null
let preparingLegacySafetyTimer = null
const bootstrapStartResponses = new Map()

function applyPreparationState(sym, state, statusUrl = state.statusUrl) {
  Object.assign(preparing.value, {
    active: (!state.fastReady || !levels.value) && !state.terminal,
    symbol: sym,
    phase: state.state,
    statusUrl,
    fastReady: state.fastReady,
    fullReady: state.fullReady,
    eodReady: state.eodReady,
    enrichmentReady: state.enrichmentReady,
    enrichmentStatus: state.enrichmentStatus,
    intradayReady: state.intradayReady,
    intradayStatus: state.intradayStatus,
    runId: state.runId,
    runGeneration: state.runGeneration,
    partial: state.partial,
    partialFailed: state.partialFailed,
    filling: state.filling,
    terminal: state.terminal,
    retryable: state.retryable,
    coverage: state.coverage,
  })
}

async function syncPreparationResponse(sym, timeframe, response, { cached = false } = {}) {
  if (disposed || userSymbol.value !== sym || dataMode.value !== 'eod') return
  const state = symbolPreparationState(response?.data, response?.status)
  if (state.mode !== 'bootstrap') return
  const current = preparing.value
  if (current.symbol === sym) {
    // A five-minute response cache cannot overwrite a newer poll result.
    if (cached) return
    if (state.runGeneration !== null && current.runGeneration !== null && state.runGeneration < current.runGeneration) return
    const sameRun = !state.runId || !current.runId || state.runId === current.runId
    if (sameRun && ((current.terminal && !state.terminal) || (current.fullReady && !state.fullReady))) return
    if (!sameRun) stopPreparingPoll()
  }
  applyPreparationState(sym, state)
  if (!state.shouldPoll) {
    stopPreparingPoll()
    return
  }
  if (!preparing.value.timer && !preparingPollController) {
    await startPreparingPoll(sym, timeframe, event => refreshPreparedGex(sym, timeframe, event), response)
  }
}

function refreshPreparedGex(sym, timeframe, event) {
  const owner = pageGeneration
  const runId = preparing.value.runId
  const runGeneration = preparing.value.runGeneration
  // Give the successful publication transaction a short moment to become
  // visible through every database/cache connection before reading it.
  clearTimeout(preparedRefreshTimer)
  preparedRefreshTimer = setTimeout(() => {
    preparedRefreshTimer = null
    if (disposed || owner !== pageGeneration || userSymbol.value !== sym || dataMode.value !== 'eod'
      || runId !== preparing.value.runId || runGeneration !== preparing.value.runGeneration) return
    // Both fast and full publication can replace an empty or partial view.
    // Invalidate every local timeframe for this symbol, not unrelated data.
    if (event?.kind === 'fast' || event?.kind === 'full') {
      for (const key of cache.keys()) {
        if (key.startsWith(`gex|${sym}|`)) cache.delete(key)
      }
    }
    if (userSymbol.value === sym && dataMode.value === 'eod') {
      fetchGexLevelsEOD(sym, gexTf.value)
    }
  }, 750)
}

function stopPreparingPoll({ reset = false } = {}) {
  preparingPollGeneration += 1
  if (preparing.value.timer) {
    clearTimeout(preparing.value.timer)
    preparing.value.timer = null
  }
  preparingPollController?.abort()
  preparingPollController = null
  if (preparingLegacySafetyTimer) {
    clearTimeout(preparingLegacySafetyTimer)
    preparingLegacySafetyTimer = null
  }

  if (reset) {
    Object.assign(preparing.value, {
      active: false,
      phase: 'queued',
      symbol: null,
      statusUrl: null,
      fastReady: false,
      fullReady: false,
      eodReady: false,
      enrichmentReady: false,
      enrichmentStatus: '',
      intradayReady: false,
      intradayStatus: '',
      runId: null,
      runGeneration: null,
      partial: false,
      partialFailed: false,
      filling: false,
      terminal: false,
      retryable: false,
      coverage: null,
    })
  }
}

async function startPreparingPoll(sym, timeframe, onReady, initialResponse = null) {
  if (disposed || userSymbol.value !== sym || dataMode.value !== 'eod') return
  if (
    preparing.value.symbol === sym
    && (preparing.value.timer || preparingPollController)
  ) return

  stopPreparingPoll()
  const owner = { symbol: sym, generation: preparingPollGeneration }
  preparingPollController = new AbortController()
  let statusUrl = null
  let seedResponse = initialResponse
  let fastRendered = false
  let fullRendered = false

  Object.assign(preparing.value, {
    active: true,
    phase: 'queued',
    symbol: sym,
    statusUrl: null,
    fastReady: false,
    fullReady: false,
    eodReady: false,
    enrichmentReady: false,
    enrichmentStatus: '',
    intradayReady: false,
    intradayStatus: '',
    runId: null,
    runGeneration: null,
    partial: false,
    partialFailed: false,
    filling: false,
    terminal: false,
    retryable: false,
    coverage: null,
  })

  const isCurrent = () => !disposed && dataMode.value === 'eod' && ownsPreparationPoll(
    owner,
    userSymbol.value,
    preparingPollGeneration,
  ) && !preparingPollController?.signal.aborted

  const armLegacySafetyStop = () => {
    if (preparingLegacySafetyTimer) return

    preparingLegacySafetyTimer = setTimeout(() => {
      if (!isCurrent()) return

      stopPreparingPoll()
      preparing.value.active = false
      if (!levels.value) {
        eodError.value = `Still preparing ${sym}. Try refresh in a minute.`
      }
    }, 5 * 60 * 1000)
  }

  const check = async () => {
    if (!isCurrent()) return

    try {
      const response = seedResponse || await axios.get(
        statusUrl || '/api/symbol/status',
        statusUrl
          ? {
              signal: preparingPollController.signal,
              validateStatus: () => true,
            }
          : {
              params: { symbol: sym, timeframe: timeframe || gexTf.value },
              signal: preparingPollController.signal,
              validateStatus: () => true,
            },
      )
      seedResponse = null
      if (!isCurrent()) return

      const state = symbolPreparationState(response?.data, response?.status)
      if (state.mode === 'bootstrap' && state.statusUrl) {
        statusUrl = state.statusUrl
      }
      if (state.mode === 'legacy') {
        armLegacySafetyStop()
      } else if (preparingLegacySafetyTimer) {
        clearTimeout(preparingLegacySafetyTimer)
        preparingLegacySafetyTimer = null
      }

      applyPreparationState(sym, state, statusUrl)

      if (state.fullReady && !fullRendered) {
        fastRendered = true
        fullRendered = true
        await onReady?.({ kind: 'full', state })
        if (!isCurrent()) return
      } else if (state.fastReady && !fastRendered) {
        fastRendered = true
        await onReady?.({ kind: 'fast', state })
        if (!isCurrent()) return
      }

      if (!state.shouldPoll) {
        if (!state.fastReady && state.terminal) {
          eodError.value = `Could not prepare ${sym}. Please retry.`
        }
        stopPreparingPoll()

        return
      }

      preparing.value.timer = setTimeout(
        check,
        bootstrapPollDelayMs(
          response,
          state.mode === 'bootstrap'
            ? 2_000
            : 5_000 + Math.floor(Math.random() * 2_000) - 1_000,
        ),
      )
    } catch (error) {
      if (!isCurrent()) return

      // A temporary status transport failure does not restart the durable run.
      preparing.value.timer = setTimeout(check, bootstrapPollDelayMs(error?.response, 5_000))
    }
  }

  // Until an additive bootstrap payload proves otherwise, retain the legacy
  // five-minute preparation safety stop.
  armLegacySafetyStop()
  await check()
}

async function kickoffSymbolWarm(sym, timeframe = '14d') {
  if (!sym || disposed) return null
  const key = `${sym}|${timeframe}`
  if (bootstrapInflight.has(key)) return bootstrapInflight.get(key)
  const controller = new AbortController()
  bootstrapControllers.set(key, { symbol: sym, controller })

  return coalesceDashboardRequest(bootstrapInflight, key, async () => {
    try {
      if (controller.signal.aborted) return null
      const response = await axios.post('/api/prime', { symbol: sym, timeframe }, { signal: controller.signal })
      if (!disposed && !controller.signal.aborted && userSymbol.value === sym) bootstrapStartResponses.set(sym, response)
      return controller.signal.aborted ? null : response
    } catch {
      return null
    } finally {
      if (bootstrapControllers.get(key)?.controller === controller) bootstrapControllers.delete(key)
    }
  })
}

function startAutoRefresh() {
  if (disposed || dataMode.value !== 'intraday') return
  if (refreshTimer.value) clearInterval(refreshTimer.value)
  refreshTimer.value = setInterval(refreshIntraday, 30_000)
}

async function refreshIntraday({ force = false } = {}) {
  if (disposed || dataMode.value !== 'intraday') return
  const sym = userSymbol.value
  const now = Date.now()

  // 1) Use in-memory cache if recent and not forced
  const cached = cacheIntraday.get(sym)
  if (!force && cached && now - cached.t < INTRADAY_TTL_MS) {
    if (!intradayLevels.value) intradayLevels.value = {}
    Object.assign(intradayLevels.value, cached.payload)
    intradayDataSymbol.value = sym
    intradaySnapshotAsOf.value = cached.asof
    intradaySnapshotAvailable.value = cached.available ?? !!cached.asof
    intradayNextOpen.value = cached.nextOpen ?? null
    intradaySourceAge.value = cached.asof ? Math.max(0, Math.floor((now - new Date(cached.asof).getTime()) / 1000)) : null
    clearIntradayPendingRetry()
    lastUpdated.value = cached.asof
    firstIntradayLoadDone.value = true
    return
  }

  // 2) Deduplicate in-flight calls for the same symbol
  const existing = inflightIntraday.get(sym)
  if (existing) return existing

  const ctl = ensureController('gex_intraday') // separate controller bucket
  const soft = firstIntradayLoadDone.value
  if (!soft) intradayLoading.value = true
  else intradayRefreshing.value = true
  intradayError.value = ''

  const p = (async () => {
    try {
      // Step A: lightweight summary to check freshness
      const summaryResp = await axios.get('/api/intraday/summary', {
        params: { symbol: sym },
        signal: ctl.signal,
      })
      const sumData = summaryResp.data || {}
      if (userSymbol.value !== sym || dataMode.value !== 'intraday' || ctl.signal.aborted) return

      marketOpen.value = !!sumData.open

      const asofMs = sumData.asof ? new Date(sumData.asof).getTime() : null
      const isFresh = !!asofMs && (now - asofMs) < INTRADAY_TTL_MS

      // New servers decide from completed ingestion, pending work and session policy.
      // Keep the legacy fallback during a rolling deployment.
      const refreshEligible = typeof sumData.refresh_eligible === 'boolean'
        ? sumData.refresh_eligible
        : !isFresh && (marketOpen.value || !asofMs)
      if (refreshEligible) {
        await axios.post('/api/intraday/pull', { symbols: [sym] }, { signal: ctl.signal }).catch(() => {})
      }
      if (disposed || userSymbol.value !== sym || dataMode.value !== 'intraday' || ctl.signal.aborted) return

      // Step C: get composite strikes snapshot
      const comp = await axios.get('/api/intraday/strikes', {
        params: { symbol: sym },
        signal: ctl.signal,
      })

      const compData = comp.data || {}
      if (userSymbol.value !== sym || dataMode.value !== 'intraday' || ctl.signal.aborted) return
      marketOpen.value = !!compData.open

      const next = {
        call_volume_total: compData?.totals?.call_vol ?? 0,
        put_volume_total:  compData?.totals?.put_vol  ?? 0,
        pcr_volume:        compData?.totals?.pcr_vol  ?? compData?.totals?.pcr_vol ?? null,
        premium_total:     compData?.totals?.premium ?? 0,
        strike_data:       (compData.items || []).map(r => ({
          strike:        r.strike,
          call_vol_delta: r.call_vol,
          put_vol_delta:  r.put_vol,
          oi_call_eod:    r.oi_call_eod,
          oi_put_eod:     r.oi_put_eod,
          vol_oi:         r.vol_oi,
          pcr:            r.pcr,
          premium_call:   r.call_prem,
          premium_put:    r.put_prem,
        })),
        strike_gex_live: (compData.items || []).map(r => ({
          strike:       r.strike,
          net_gex:      r.net_gex_live,
          net_gex_delta: r.net_gex_delta,
        })),
      }

      if (!intradayLevels.value) intradayLevels.value = {}
      Object.assign(intradayLevels.value, next)

      intradayDataSymbol.value = sym
      const snapshotAsOf = compData.asof || sumData.asof || null
      intradaySnapshotAsOf.value = snapshotAsOf
      intradaySourceAge.value = snapshotAsOf ? Math.max(0, Math.floor((Date.now() - new Date(snapshotAsOf).getTime()) / 1000)) : null
      const snapshotAvailable = compData.snapshot_available ?? sumData.snapshot_available ?? !!snapshotAsOf
      intradaySnapshotAvailable.value = snapshotAvailable
      intradayNextOpen.value = compData.market_session?.next_open_at ?? sumData.market_session?.next_open_at ?? null

      lastUpdated.value = snapshotAsOf
      firstIntradayLoadDone.value = true

      // Step D: never retain the placeholder read made while a new symbol's
      // queued ingest is still running. Poll briefly, then fall back to the
      // normal 30-second refresh interval.
      if (snapshotAvailable) {
        clearIntradayPendingRetry()
        cacheIntraday.set(sym, {
          t: Date.now(),
          asof: snapshotAsOf,
          available: snapshotAvailable,
          nextOpen: intradayNextOpen.value,
          payload: next,
        })
      } else {
        cacheIntraday.delete(sym)
        if (compData.market_session?.refresh_allowed === false || sumData.market_session?.refresh_allowed === false) {
          clearIntradayPendingRetry()
        } else {
          scheduleIntradayPendingRetry(sym)
        }
      }
    } catch (e) {
      if (userSymbol.value === sym && dataMode.value === 'intraday' && !ctl.signal.aborted) {
        intradayError.value = e?.response?.data?.error || e.message
      }
    } finally {
      if (userSymbol.value === sym && !ctl.signal.aborted) {
        intradayLoading.value = false
        intradayRefreshing.value = false
      }
    }
  })().finally(() => {
    if (inflightIntraday.get(sym) === p) inflightIntraday.delete(sym)
  })

  inflightIntraday.set(sym, p)
  return p
}

async function manualRefresh() {
  try {
    await refreshIntraday({ force: true })
  } catch (e) {
    if (dataMode.value === 'eod') {
      eodError.value = e?.response?.data?.error || e.message
    } else {
      intradayError.value = e?.response?.data?.error || e.message
    }
  }
}

let symbolTimer
function resetAuxiliaryData() {
  resetTabReadiness()
  loaded.value = { volatility: false, ua: false }
  errors.value = { volatility: '', ua: '' }
  Object.keys(volErrors).forEach(key => { volErrors[key] = '' })
  Object.keys(volState).forEach(key => { volState[key] = 'idle' })
  volErr.value = null
  term.value = { date: null, items: [] }
  vrp.value = { date: null, iv1m: null, rv20: null, vrp: null, z: null }
  season.value = null
  seasonNote.value = ''
  uaRows.value = []
  uaDate.value = null
  uaLoading.value = false
}

watch(dataMode, () => {
  stopPageWork()
  resetAuxiliaryData()
}, { flush: 'sync' })

watch(userSymbol, (s) => {
  stopPageWork()
  resetAuxiliaryData()
  uaExp.value = 'ALL'
  eodLevels.value = null
  eodLoading.value = false
  intradayLoading.value = false
  intradayRefreshing.value = false
  for (const candidate of bootstrapStartResponses.keys()) {
    if (candidate !== s) bootstrapStartResponses.delete(candidate)
  }

  const owner = pageGeneration
  busy.value.positioning = true
  positioningFrame = requestAnimationFrame(() => {
    positioningFrame = null
    positioningTimer = setTimeout(() => {
      positioningTimer = null
      if (!disposed && owner === pageGeneration) busy.value.positioning = false
    }, 250)
  })
  symbolTimer = setTimeout(() => {
    symbolTimer = null
    if (disposed || owner !== pageGeneration || userSymbol.value !== s) return
    if (dataMode.value === 'eod') {
      fetchGexLevelsEOD(s, gexTf.value)
    } else {
      // smart intraday load, reuse cache when possible
      refreshIntraday({ force: false })
      startAutoRefresh()
    }
    ensureActiveTab()
  }, 250)
}, { flush: 'sync' })

watch(userSymbol, (s) => {
  if (typeof window !== 'undefined') {
    localStorage.setItem('calculator_last_symbol', s)
  }
})

watch(uaExp, () => { if (!symbolTimer && activeTab.value === 'ua') ensureUA() })

watch(gexTf, tf => {
  if (dataMode.value === 'eod')
    fetchGexLevelsEOD(userSymbol.value, tf)
  else {
    refreshIntraday()
  }
})

watch(timeframeAvailability, (set) => {
  if (!set || set.size === 0) return
  if (set.has(gexTf.value)) return
  const preferred = set.has('14d') ? '14d' : Array.from(set)[0]
  if (preferred) gexTf.value = preferred
})

// Cache helpers
function setCache(map, key, data) { map.set(key, { t: Date.now(), data }) }
function getCache(map, key, ttl) {
  const h = map.get(key)
  return (h && Date.now() - h.t < ttl) ? h.data : null
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

function toVolOiSeries(arr = []) {
  return (arr || []).map(r => ({
    strike: n(r.strike),

    // keep volume fields under the names the chart expects
    call_vol_delta: n(r.call_vol_delta ?? r.call_volume_delta ?? 0),
    put_vol_delta:  n(r.put_vol_delta  ?? r.put_volume_delta  ?? 0),

    // keep OI
    oi_call_eod:    n(r.oi_call_eod ?? 0),
    oi_put_eod:     n(r.oi_put_eod  ?? 0),

    // keep precomputed Vol/OI as-is (null allowed)
    vol_oi: (r.vol_oi === null || r.vol_oi === undefined)
      ? null
      : Number(r.vol_oi),
  }))
}

function toPcrSeries(arr = []) {
  return (arr || []).map(r => ({
    strike: n(r.strike),

    pcr: (r.pcr === null || r.pcr === undefined) ? null : Number(r.pcr),

    // keep vols so the chart fallback can compute p/c when pcr is null
    call_vol_delta: n(r.call_vol_delta ?? r.call_volume_delta ?? 0),
    put_vol_delta:  n(r.put_vol_delta  ?? r.put_volume_delta  ?? 0),
  }))
}

function toPremiumSeries(arr = []) {
  return (arr || []).map(r => ({
    strike: n(r.strike),
    // use snake_case so PremiumByStrikeChart can see them
    premium_call: n(r.premium_call ?? r.call_prem ?? 0),
    premium_put:  n(r.premium_put  ?? r.put_prem  ?? 0),
  }))
}

const strikeSeriesForDelta = computed(() => {
  const rows = levels.value?.strike_data || []

  const hasAnyDod =
    rows.some(r =>
      (r.call_oi_delta ?? 0) !== 0 ||
      (r.put_oi_delta ?? 0) !== 0 ||
      (r.call_vol_delta ?? 0) !== 0 ||
      (r.put_vol_delta ?? 0) !== 0
    )

  if (hasAnyDod) return rows

  // fallback: use WoW deltas instead
  return rows.map(r => ({
    ...r,
    call_oi_delta: r.call_oi_wow ?? 0,
    put_oi_delta:  r.put_oi_wow ?? 0,
    call_vol_delta: r.call_vol_wow ?? 0,
    put_vol_delta:  r.put_vol_wow ?? 0,
  }))
})
</script>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
