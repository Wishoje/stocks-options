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
              <span
                v-if="dataMode === 'intraday' && intradayTransition"
                class="inline-flex items-center gap-1 rounded-full border border-amber-500/40 bg-amber-500/10 px-2 py-0.5 text-[11px] text-amber-100"
              >
                <svg class="w-3 h-3 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 4v2m0 12v2m8-8h-2M6 12H4m13.657-5.657l-1.414 1.414M7.757 16.243l-1.414 1.414m0-11.314l1.414 1.414M16.243 16.243l1.414 1.414" />
                </svg>
                Updating…
            </span>
          </div>
        </div>

        <!-- Center: EOD Timeframe Picker -->
        <div v-if="dataMode === 'eod'" class="hidden md:flex items-center gap-2">
          <span class="text-xs text-gray-400 uppercase tracking-wider">Timeframe</span>
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
              Intraday (Live)
              <span v-if="dataMode === 'intraday'" class="text-[10px] opacity-80">15m</span>
            </button>
          </div>

          <!-- Freshness -->
          <div class="text-xs text-gray-400">
            <span v-if="dataMode === 'eod' && levels?.data_date">
              EOD: {{ levels.data_date }}
              <span v-if="levels.data_age_days > 0" class="ml-1 text-[11px] text-amber-400">
                ({{ levels.data_age_days }}d old)
              </span>
            </span>
            <span v-else-if="dataMode === 'intraday'" class="flex items-center gap-1">
              <span class="text-green-400 font-medium">{{ marketOpen ? 'Live' : 'Market Closed' }}</span>
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
          v-for="t in tabMeta"
          :key="t.key"
          :disabled="t.state !== 'ready'"
          @click="t.state === 'ready' && activate(t.key)"
          class="px-5 py-2 rounded-lg text-sm font-medium transition whitespace-nowrap flex items-center gap-2"
          :class="activeTab === t.key && t.state === 'ready'
            ? 'bg-gradient-to-r from-cyan-600 to-blue-600 text-white shadow-lg shadow-cyan-500/20'
            : t.state === 'ready'
              ? 'text-gray-400 hover:text-white hover:bg-gray-800'
              : 'text-gray-500 bg-gray-800/60 cursor-not-allowed'"
        >
          <component :is="t.icon" class="w-4 h-4" />
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
          v-if="dataMode==='intraday' && !marketOpen"
          class="bg-slate-500/10 border border-slate-400/30 text-slate-100 text-sm px-4 py-3 rounded-lg flex items-start gap-2"
        >
          <svg class="w-4 h-4 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div>
            <div class="font-semibold">
              {{ intradayHasData ? 'Showing last completed intraday session' : `Preparing first intraday snapshot for ${userSymbol}` }}
            </div>
            <div class="text-slate-200/80">
              <template v-if="intradayHasData">
                As of {{ intradayAsOfEtLabel }} ET. Live updates resume at 9:30 AM ET.
              </template>
              <template v-else>
                No prior intraday snapshot yet. We will populate this symbol and show data here.
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
              <h4 class="font-semibold mb-3">ΔOI by Strike (EOD)</h4>
              <StrikeDeltaChart
                :strikeData="strikeSeriesForDelta"
                height-class="h-80 md:h-96 xl:h-[26rem]"
                snapshot-name="delta-oi-eod"
              />
            </div>
            <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
              <h4 class="font-semibold mb-3">ΔVol by Strike (EOD)</h4>
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
  positioning: { state: 'pending', err: '' },
  volatility:  { state: 'pending', err: '' },
  ua:          { state: 'pending', err: '' },
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
const preparing = ref({ active: false, phase: 'queued', timer: null })
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
const symbol = ref('SPY')
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
const inflight = new Map()
const marketOpen = ref(false)
const inflightIntraday = new Map()
const cacheIntraday = new Map()
const INTRADAY_TTL_MS = 60_000 // 1 minute cache window
const intradayDataSymbol = ref(null)
const intradayHasData = computed(() => {
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
const controllers = { gex_eod: null, gex_intraday: null, term: null, vrp: null, season: null, ua: null }

function withInflight(key, fn) {
  const hit = inflight.get(key)
  if (hit) return hit
  const p = fn().finally(() => inflight.delete(key))
  inflight.set(key, p)
  return p
}

// Watchers
// watch(dataMode, (mode) => {
//   if (mode === 'intraday' && activeTab.value === 'overview') activeTab.value = 'flow'
//   if (mode === 'eod' && activeTab.value === 'flow') activeTab.value = 'overview'
// })

watch(tabMeta, (tabs) => {
  const active = tabs.find(t => t.key === activeTab.value)
  if (!active || active.state !== 'ready') {
    const firstReady = tabs.find(t => t.state === 'ready')
    if (firstReady) activeTab.value = firstReady.key
  }
})

function activate(key) {
  activeTab.value = key
  if (key === 'volatility' && dataMode.value === 'eod' && !loaded.value.volatility) ensureVolatility()
  if (key === 'ua' && !loaded.value.ua) ensureUA()
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
    if (netGexSection.value) {
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
  fetchGexLevelsEOD(userSymbol.value, '0d', { applyTf: true }).then(() => scrollToNetGex())
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
  resetTabReadiness()
  ensureAllTabReadiness()
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
  Object.keys(tabStatus).forEach(k => { tabStatus[k].state = 'pending'; tabStatus[k].err = '' })
  Object.keys(tabPollers).forEach(stopTabPoll)
}

function isPreparing(err) {
  const status = err?.response?.status
  const msg = err?.response?.data?.error || err?.message || ''
  return status === 404 || status === 202 || /preparing|queued|no data|no expirations/i.test(msg)
}

function readinessProbeFor(key) {
  switch (key) {
    case 'positioning':
      if (dataMode.value !== 'eod') return null
      return () => axios.get('/api/dex', { params: { symbol: userSymbol.value } })
    case 'volatility':
      if (dataMode.value !== 'eod') return null
      return () => axios.get('/api/iv/term', { params: { symbol: userSymbol.value }, paramsSerializer: { indexes: false } })
    case 'ua': {
      const url = dataMode.value === 'intraday' ? '/api/intraday/ua' : '/api/ua'
      return () => axios.get(url, { params: { symbol: userSymbol.value, per_expiry: 1, limit: 1, sort: 'z_score', with_premium: false } })
    }
    default:
      return null
  }
}

function ensureTabReady(key) {
  const probe = readinessProbeFor(key)
  if (!probe) {
    tabStatus[key].state = 'ready'
    tabStatus[key].err = ''
    stopTabPoll(key)
    return
  }

  tabStatus[key].err = ''
  probe()
    .then(() => {
      tabStatus[key].state = 'ready'
      stopTabPoll(key)
    })
    .catch((e) => {
      if (isPreparing(e)) {
        tabStatus[key].state = 'pending'
        stopTabPoll(key)
        tabPollers[key] = setTimeout(() => ensureTabReady(key), 5000)
      } else {
        tabStatus[key].state = 'error'
        tabStatus[key].err = e?.response?.data?.error || e.message || 'Unavailable'
        stopTabPoll(key)
      }
    })
}

function ensureAllTabReadiness() {
  ['positioning', 'volatility', 'ua'].forEach(ensureTabReady)
}

// Data loaders
async function fetchGexLevelsEOD(sym, tf = gexTf.value, opts = { applyTf: true }) {
  const key = `gex|${sym}|${tf}`
  const hit = cache.get(key)
  if (hit && Date.now() - hit.t < TTL_MS) {
    eodLevels.value = hit.data
    lastUpdated.value = new Date().toISOString()
    preparing.value.active = false
    stopPreparingPoll()
    return
  }

  eodLoading.value = true
  eodError.value = ''
  eodLevels.value = null

  const ctl = ensureController('gex_eod')

  try {
    await withInflight(`gex:${key}`, async () => {
      const { data } = await axios.get('/api/gex-levels', {
        params: { symbol: sym, timeframe: tf },
        signal: ctl.signal
      })
      eodLevels.value = data || {}
      cache.set(key, { t: Date.now(), data: eodLevels.value })
      uaExp.value = 'ALL'
      lastUpdated.value = new Date().toISOString()

      if (opts?.applyTf && gexTf.value !== tf) {
        gexTf.value = tf
      }
    })
  } catch (e) {
    if (e.name !== 'CanceledError' && e.code !== 'ERR_CANCELED') {
      const payload = e?.response?.data || {}
      const msg = payload?.error || e.message || ''
      const status = e?.response?.status
      const preparingLike = /No data|No expirations|queued|fetching|preparing/i.test(String(msg))
      const available = Array.isArray(payload?.available_timeframes)
        ? payload.available_timeframes
        : Object.keys(payload?.timeframe_expirations || {})

      // If another timeframe has expirations, auto-switch to it
      if ((status === 404 || status === 202) && available.length) {
        const nextTf = available.includes('14d') ? '14d' : available[0]
        if (nextTf && nextTf !== gexTf.value) {
          return await fetchGexLevelsEOD(sym, nextTf, opts)
        }
      }

      // If it looks like a first-time symbol, go into preparing mode
      if ((status === 404 || status === 202) && preparingLike) {
        eodError.value = ''
        kickoffSymbolWarm(sym, tf)
        // only start the poller if it's not already running
        if (!preparing.value.timer) {
          await startPreparingPoll(sym, tf, async () => {
            // tiny backoff so the data that flipped "ready" actually becomes visible
            setTimeout(() => fetchGexLevelsEOD(sym, tf), 750)
          })
        }
      } else {
        eodError.value = msg
      }
    }
  } finally {
    if (ctl === controllers.gex_eod) eodLoading.value = false
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
    const tResp = await withInflight(`term:${sym}`, () =>
      axios.get('/api/iv/term', { params: { symbol: sym }, signal: termCtl.signal, validateStatus: () => true })
    )
    if (tResp.status === 200) {
      term.value = { date: tResp.data?.date ?? null, items: Array.isArray(tResp.data?.items) ? tResp.data.items : [] }
      setCache(cacheTerm, tKey, term.value)
    } else {
      term.value = { date: null, items: [] }
    }

    const vResp = await withInflight(`vrp:${sym}`, () =>
      axios.get('/api/vrp', { params: { symbol: sym }, signal: vrpCtl.signal, validateStatus: () => true })
    )
    if (vResp.status === 200) {
      vrp.value = { date: vResp.data?.date ?? null, iv1m: vResp.data?.iv1m ?? null, rv20: vResp.data?.rv20 ?? null, vrp: vResp.data?.vrp ?? null, z: vResp.data?.z ?? null }
      setCache(cacheVRP, vKey, vrp.value)
    } else {
      vrp.value = { date: null, iv1m: null, rv20: null, vrp: null, z: null }
    }
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
  const k = ['ua', sym, (exp || 'ALL'), uaTop.value, uaMinZ.value, uaMinVolOI.value, uaMinVol.value, uaMinPrem.value, uaNearPct.value || 0, uaSide.value || '', uaSort.value, uaLimit.value].join('|')
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
  if (volRetryTimer) { clearTimeout(volRetryTimer); volRetryTimer = null }
  await Promise.all([loadTermAndVRP(userSymbol.value), loadSeasonality(userSymbol.value)])
  const hasData = !!(term.value?.date || vrp.value?.date || season.value)
  if (!errors.value.volatility && hasData) {
    loaded.value.volatility = true
  } else if (!errors.value.volatility) {
    volRetryTimer = setTimeout(() => {
      if (activeTab.value === 'volatility' && dataMode.value === 'eod') ensureVolatility()
    }, 5000)
  }
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


function handleSelectSymbolEvent(evt) {
  const next = String(evt?.detail?.symbol || '').trim().toUpperCase()
  if (!next) return

  kickoffSymbolWarm(next, gexTf.value)

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
  const startSym = new URLSearchParams(window.location.search).get('symbol')
  if (startSym) {
    const cleaned = startSym.trim().toUpperCase()
    if (cleaned) userSymbol.value = cleaned
  }

  const onboarding = onboardingState()
  const checklistDismissed = !!localStorage.getItem('gex_checklist_v1_dismissed')
  showOnboarding.value = onboarding === 'new'
  // Only show checklist on first run AND if not dismissed
  checklistVisible.value = !checklistDismissed && onboarding === 'new'

  // make sure we load something on first render
  const initialTf = showOnboarding.value ? '0d' : gexTf.value
  if (showOnboarding.value) gexTf.value = '0d'
  fetchGexLevelsEOD(userSymbol.value, initialTf, { applyTf: true })
  ensureAllTabReadiness()

  // listen for watchlist / scanner clicks
  window.addEventListener('select-symbol', handleSelectSymbolEvent)
})


onUnmounted(() => {
  window.removeEventListener('select-symbol', handleSelectSymbolEvent)
  Object.keys(tabPollers).forEach(stopTabPoll)
})

function stopRefresh() {
  if (refreshTimer.value) {
    clearInterval(refreshTimer.value)
    refreshTimer.value = null
  }
}

function stopPreparingPoll() {
  if (preparing.value.timer) {
    clearTimeout(preparing.value.timer)
    preparing.value.timer = null
  }
}

async function startPreparingPoll(sym, timeframe, onReady) {
  if (preparing.value.timer) return
  preparing.value.active = true
  preparing.value.phase = 'queued'
  stopPreparingPoll()
  const check = async () => {
    try {
      const { data, status } = await axios.get('/api/symbol/status', {
        params: { symbol: sym, timeframe: timeframe || gexTf.value }
      })
      const st = data?.status || (status === 200 ? 'ready' : 'queued')
      preparing.value.phase = st
      if (st === 'ready') {
        stopPreparingPoll()
        preparing.value.active = false
        await onReady?.()
      }
    } catch {
      // keep polling; backend might still be provisioning
    }
  }
  await check()
  const tick = async () => {
    await check()
    // 5s ± 1s jitter
    const next = 5000 + Math.floor(Math.random() * 2000) - 1000
    preparing.value.timer = setTimeout(tick, Math.max(2500, next))
  }
  preparing.value.timer = setTimeout(tick, 0)
  // optional safety stop after 5 minutes:
  setTimeout(() => {
    if (preparing.value.timer) {
      stopPreparingPoll()
      preparing.value.active = false
      if (!levels.value) {
        eodError.value = `Still preparing ${sym}. Try refresh in a minute.`
      }
    }
  }, 5 * 60 * 1000)
}

function kickoffSymbolWarm(sym, timeframe = '14d') {
  if (!sym) return
  axios.get('/api/symbol/status', { params: { symbol: sym, timeframe } }).catch(() => {})
}

function startAutoRefresh() {
  stopRefresh()
  refreshTimer.value = setInterval(refreshIntraday, 30_000)
}

async function refreshIntraday({ force = false } = {}) {
  const sym = userSymbol.value
  const now = Date.now()

  // 1) Use in-memory cache if recent and not forced
  const cached = cacheIntraday.get(sym)
  if (!force && cached && now - cached.t < INTRADAY_TTL_MS) {
    if (!intradayLevels.value) intradayLevels.value = {}
    Object.assign(intradayLevels.value, cached.payload)
    lastUpdated.value = cached.asof || new Date(cached.t).toISOString()
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

      marketOpen.value = !!sumData.open

      const asofMs = sumData.asof ? new Date(sumData.asof).getTime() : null
      const isFresh = !!asofMs && (now - asofMs) < INTRADAY_TTL_MS

      // Step B: only hit heavy job if market is open AND snapshot is stale
      if (marketOpen.value && !isFresh) {
        await axios.post('/api/intraday/pull', { symbols: [sym] }).catch(() => {})
      }

      // Step C: get composite strikes snapshot
      const comp = await axios.get('/api/intraday/strikes', {
        params: { symbol: sym },
        signal: ctl.signal,
      })

      const compData = comp.data || {}
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

      lastUpdated.value = compData.asof || sumData.asof || new Date().toISOString()
      firstIntradayLoadDone.value = true

      // Step D: update cache for this symbol
      cacheIntraday.set(sym, {
        t: Date.now(),
        asof: compData.asof || sumData.asof || null,
        payload: next,
      })
    } catch (e) {
      intradayError.value = e?.response?.data?.error || e.message
    } finally {
      intradayLoading.value = false
      intradayRefreshing.value = false
    }
  })().finally(() => {
    inflightIntraday.delete(sym)
  })

  inflightIntraday.set(sym, p)
  return p
}

async function manualRefresh() {
  try {
    await axios.post('/api/intraday/pull', { symbols: [userSymbol.value] })
    await refreshIntraday()
  } catch (e) {
    if (dataMode.value === 'eod') {
      eodError.value = e?.response?.data?.error || e.message
    } else {
      intradayError.value = e?.response?.data?.error || e.message
    }
  }
}

let symbolTimer
watch(userSymbol, (s) => {
  clearTimeout(symbolTimer)
  symbolTimer = setTimeout(() => {
    if (dataMode.value === 'eod') {
      fetchGexLevelsEOD(s, gexTf.value)
    } else {
      // smart intraday load, reuse cache when possible
      refreshIntraday({ force: false })
    }
    if (loaded.value.ua && activeTab.value === 'ua') ensureUA()
  }, 250)
})

watch(userSymbol, (s) => {
  if (typeof window !== 'undefined') {
    localStorage.setItem('calculator_last_symbol', s)
  }
})

watch(userSymbol, () => {
  resetTabReadiness()
  ensureAllTabReadiness()
})

watch(uaExp, () => { if (activeTab.value === 'ua') ensureUA() })
watch([userSymbol], () => {
  busy.value.positioning = true
  requestAnimationFrame(() => setTimeout(() => { busy.value.positioning = false }, 250))
})

watch(userSymbol, () => {
  // force volatility to refresh per symbol
  errors.value.volatility = ''
  loaded.value.volatility = false
  if (activeTab.value === 'volatility' && dataMode.value === 'eod') {
    ensureVolatility()
  }
})

watch(userSymbol, () => {
  // reset UA when switching symbols so tab pulls fresh data
  errors.value.ua = ''
  loaded.value.ua = false
  uaRows.value = []
  uaDate.value = null
  if (activeTab.value === 'ua') ensureUA()
})

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
