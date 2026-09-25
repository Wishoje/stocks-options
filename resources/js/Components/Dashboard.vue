<template>
  <div class="min-h-screen bg-gray-950 text-white">
    <section ref="dashboardContext" class="gex-ui gex-dashboard-context" data-theme="dark" data-density="compact" aria-label="Dashboard data context">
      <div class="gex-dashboard-context__primary">
        <div class="gex-dashboard-heading">
          <div class="gex-dashboard-heading__copy">
            <small>Market dashboard</small>
            <h1>GEX Levels &amp; Analytics</h1>
          </div>
          <button
            ref="symbolPickerTrigger"
            type="button"
            class="gex-symbol-button gex-number"
            aria-haspopup="dialog"
            aria-controls="dashboard-symbol-dialog"
            :aria-expanded="showSymbolPicker ? 'true' : 'false'"
            @click="openSymbolPicker"
          >
            {{ userSymbol }}
            <svg
              class="gex-symbol-button__chevron"
              viewBox="0 0 20 20"
              fill="none"
              aria-hidden="true"
            >
              <path d="m6 8 4 4 4-4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </button>
          <UiBadge
            v-if="dataMode === 'intraday' && intradayTransition"
            tone="warning"
          >
            Updating selection
          </UiBadge>
        </div>

        <div class="gex-dashboard-controls">
          <fieldset class="gex-control-group">
            <legend>Dataset</legend>
            <div class="gex-segmented" aria-label="Dataset mode">
              <button type="button" :aria-pressed="dataMode === 'eod'" @click="setMode('eod')">End of day</button>
              <button type="button" :aria-pressed="dataMode === 'intraday'" @click="setMode('intraday')">Intraday</button>
            </div>
          </fieldset>

          <fieldset v-if="dataMode === 'eod' && ['overview', 'strikes'].includes(activeTab)" class="gex-control-group">
            <legend>Expiry scope</legend>
            <div class="gex-segmented" aria-label="EOD expiry timeframe">
              <button
                v-for="tf in visibleTimeframeOptions"
                :key="tf.value"
                type="button"
                :aria-pressed="gexTf === tf.value"
                @click="chooseTimeframe(tf.value)"
              >
                {{ tf.label }}
              </button>
            </div>
          </fieldset>

          <div v-else-if="dataMode === 'eod' && activeTab === 'positioning'" class="gex-positioning-scope" role="note">
            <strong>Positioning uses local scopes:</strong>
            DEX snapshot window · {{ pinDays }} trading-day pressure · selectable skew bucket
          </div>
          <div v-else-if="dataMode === 'eod' && activeTab === 'volatility'" class="gex-positioning-scope" role="note">
            <strong>Volatility uses local scopes:</strong>
            all returned term expiries · 1-month VRP proxy · next 5 sessions
          </div>
          <div v-else-if="dataMode === 'eod' && activeTab === 'ua'" class="gex-positioning-scope" role="note">
            <strong>Unusual activity uses its own scope:</strong>
            latest completed activity snapshot · all expiries or one selected expiry
          </div>
        </div>

        <div
          v-if="dataMode === 'eod'"
          class="gex-dashboard-freshness"
          :data-state="activeTab === 'ua' ? (uaDate ? 'fresh' : 'stale') : (!levels?.data_date ? 'loading' : Number(levels?.data_age_days || 0) > 0 ? 'stale' : 'fresh')"
          aria-live="polite"
        >
          <template v-if="activeTab === 'ua'">
            <strong>{{ uaDate ? `Activity ${uaDate}` : (uaLoading ? 'Loading activity snapshot' : 'Activity date unavailable') }}</strong>
            <span>Independent latest completed activity snapshot</span>
          </template>
          <template v-else>
            <strong>{{ levels?.data_date ? `EOD ${levels.data_date}` : (preparing.active ? `Preparing ${userSymbol}` : eodLoading ? 'Loading EOD snapshot' : 'EOD date unavailable') }}</strong>
            <span v-if="levels?.data_age_days > 0">{{ levels.data_age_days }} day<span v-if="levels.data_age_days !== 1">s</span> old</span>
            <span v-else-if="levels?.data_date">Completed-session snapshot</span>
          </template>
        </div>
        <div
          v-else
          class="gex-dashboard-freshness"
          :data-state="intradayFreshnessState"
          aria-live="polite"
        >
          <template v-if="intradayTransition">
            <strong>Loading {{ userSymbol }}</strong>
            <span>Previous-symbol readings are hidden</span>
          </template>
          <template v-else>
            <strong>{{ intradaySourceLabel }}</strong>
            <span v-if="intradaySnapshotAsOf">{{ intradaySourceTimeKind }} {{ intradayAsOfEtLabel }} ET</span>
            <span v-else>Provider update time unavailable</span>
          </template>
          <UiButton :disabled="intradayLoading || intradayRefreshing || intradayTransition" @click="manualRefresh">
            {{ intradayLoading || intradayTransition ? 'Loading…' : (intradayRefreshing ? 'Refreshing…' : 'Refresh') }}
          </UiButton>
        </div>
      </div>

      <section v-if="dataMode === 'eod' && ['overview', 'strikes'].includes(activeTab)" class="gex-eod-view" aria-label="EOD analysis view">
        <div class="gex-segmented" aria-label="EOD analysis view options">
          <button type="button" :aria-pressed="eodView === 'latest_eod'" @click="eodView = 'latest_eod'">Latest EOD snapshot</button>
          <button type="button" :aria-pressed="eodView === 'next_session'" @click="eodView = 'next_session'">Next-session preparation</button>
        </div>
        <a class="gex-small" :href="`/ai-export?timeframe=${gexTf}&view=${eodView}`">Export this view</a>
        <p class="gex-small gex-muted" aria-live="polite">
          <template v-if="eodLoading">Loading the selected expiry scope…</template>
          <template v-else-if="levels?.view_context">
            {{ eodView === 'next_session' ? 'Preparing for' : 'Snapshot expiry scope for' }} <strong>{{ levels.view_context.session_date }}</strong>
            · Source EOD <strong>{{ levels.view_context.source_date || 'unavailable' }}</strong>.
            {{ eodView === 'next_session' ? 'Earlier expirations excluded. Recorded EOD inputs; not live session values.' : 'Includes expirations active on the source date.' }}
          </template>
        </p>
      </section>

      <section
        v-if="dataMode === 'eod' && ['overview', 'strikes'].includes(activeTab) && scopedExpirationDates.length"
        class="gex-expiry-scope"
        aria-label="Included expiration dates"
      >
        <p class="gex-expiry-scope__label">
          {{ scopedExpirationDates.length }} {{ scopedExpirationDates.length === 1 ? 'expiration' : 'expirations' }} included in {{ selectedTimeframeLabel }}
        </p>
        <div class="gex-expiry-scope__items">
          <UiBadge v-for="d in scopedExpirationDates" :key="d" tone="data">{{ d }}</UiBadge>
        </div>
      </section>

      <div class="gex-dashboard-context__secondary">
        <nav aria-label="Dashboard views">
          <div class="gex-tabs" role="tablist" aria-label="Dashboard views">
            <button
              v-for="(tab, index) in dashboardTabItems"
              :id="`dashboard-content-tabs-${tab.value}`"
              :key="tab.value"
              :ref="el => dashboardTabButtons[index] = el"
              type="button"
              class="gex-tab"
              role="tab"
              aria-controls="dashboard-content-tabs-panel"
              :aria-selected="activeTab === tab.value"
              :tabindex="activeTab === tab.value ? 0 : -1"
              @click="activate(tab.value)"
              @keydown="onDashboardTabKey($event, index)"
            >
              {{ tab.label }}
            </button>
          </div>
        </nav>
        <div class="gex-dashboard-scope gex-small gex-muted">
          <span>{{ dataMode === 'eod' ? 'End-of-day analysis' : 'Stored intraday snapshots' }}</span>
          <span aria-hidden="true">·</span>
          <span>{{ userSymbol }}</span>
        </div>
      </div>
    </section>

    <FirstUseGuide
      v-if="showOnboarding"
      :symbol="userSymbol"
      :mode="dataMode"
      :tab-label="activeGuideTabLabel"
      :timeframe="dataMode === 'eod' && ['overview', 'strikes'].includes(activeTab) ? selectedTimeframeLabel : ''"
      @continue="startGuidedView"
      @dismiss="dismissOnboarding"
    />

    <!-- Body -->
    <div
      id="dashboard-content-tabs-panel"
      class="p-4 space-y-6"
      role="tabpanel"
      :aria-labelledby="`dashboard-content-tabs-${activeTab}`"
      tabindex="0"
    >
      <!-- Loading / Error -->
      <ui-error-block v-if="topError" :message="'Failed to load data'" :detail="topError"
                     :onRetry="() => dataMode === 'eod' ? fetchGexLevelsEOD(userSymbol, gexTf) : refreshIntraday()" />
      <UiLoading
        v-else-if="primaryViewLoading"
        :key="`${userSymbol}:${dataMode}:${activeTab}:${gexTf}:${eodView}`"
        :title="`${preparing.active ? 'Preparing' : 'Loading'} ${userSymbol} · ${activeGuideTabLabel}${dataMode === 'eod' ? ` · ${selectedTimeframeLabel}` : ' · Intraday'}`"
        :preparing="preparing.active"
        :message="preparing.active ? 'Your options data is being prepared. This view will update automatically; you can keep navigating.' : 'Fetching the selected snapshot, metrics, and chart readings.'"
        retry
        @retry="dataMode === 'eod' ? fetchGexLevelsEOD(userSymbol, gexTf) : manualRefresh()"
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
          v-if="dataMode==='intraday' && intradayTransition"
          class="gex-ui"
          data-theme="dark"
          data-density="compact"
        >
          <UiStatus
            state="loading"
            :title="`Loading ${userSymbol} intraday data`"
            message="The previous symbol is hidden while its replacement snapshot is verified."
          />
        </div>
        <div
          v-else-if="dataMode==='intraday' && marketOpen === false"
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
                <span v-if="intradaySnapshotAsOf">{{ intradaySourceTimeKind }} {{ intradayAsOfEtLabel }} ET.</span>
                <span v-else>Provider update time is unavailable.</span>
                Live updates resume {{ intradayNextOpenLabel }}.
              </template>
              <template v-else>
                The market is closed. The first live snapshot can be collected {{ intradayNextOpenLabel }}.
              </template>
            </div>
          </div>
        </div>
        <div
          v-if="dataMode==='intraday' && activeTab==='strikes' && intradayError && intradayHasData"
          class="gex-ui"
          data-theme="dark"
          data-density="compact"
        >
          <UiStatus
            state="error"
            title="Intraday refresh failed"
            :message="`${intradayError} The last aligned snapshot remains visible below.`"
            retry
            @retry="manualRefresh"
          />
        </div>
        <!-- OVERVIEW (EOD) -->
        <section
          v-show="activeTab==='overview' && dataMode==='eod'"
          class="gex-ui gex-overview-view"
          data-theme="dark"
          data-density="compact"
          aria-label="End-of-day overview"
        >
          <QScorePanel
            :symbol="userSymbol"
            :snapshot-date="levels?.data_date ?? null"
            :active="activeTab === 'overview' && dataMode === 'eod'"
          />
          <OverviewMetrics :levels="levels" :scope-label="selectedTimeframeLabel" />

          <div class="gex-overview-distributions">
            <OiDistributionChart
              :call-oi="levels?.call_open_interest_total ?? null"
              :put-oi="levels?.put_open_interest_total ?? null"
              @reading-inspected="recordFirstUsefulReading"
            />
            <VolDistributionChart
              :call-vol="levels?.call_volume_total ?? null"
              :put-vol="levels?.put_volume_total ?? null"
              @reading-inspected="recordFirstUsefulReading"
            />
          </div>
        </section>

        <!-- POSITIONING (EOD) -->
        <Suspense v-if="positioningMounted && dataMode==='eod'">
          <section v-show="activeTab==='positioning'" class="gex-ui gex-positioning-view" data-theme="dark" data-density="compact" aria-label="End-of-day positioning">
            <div class="gex-positioning-view__grid">
              <DexTile :symbol="userSymbol" :active="activeTab === 'positioning'" @reading-inspected="recordFirstUsefulReading" />
              <ExpiryPressureTile :symbol="userSymbol" :days="pinDays" :active="activeTab === 'positioning'" @reading-inspected="recordFirstUsefulReading" />
            </div>
            <SkewTile :symbol="userSymbol" :active="activeTab === 'positioning'" @reading-inspected="recordFirstUsefulReading" />
          </section>
          <template #fallback><UiLoading :title="`Loading ${userSymbol} positioning`" message="Loading dealer delta, expiry pressure, and put-versus-call pricing." /></template>
        </Suspense>
        <section
          v-else-if="activeTab==='positioning' && dataMode==='eod'"
          class="gex-ui"
          data-theme="dark"
          data-density="compact"
        >
          <UiStatus
            v-if="['idle', 'pending'].includes(tabState('positioning'))"
            state="preparing"
            :title="`Preparing ${userSymbol} positioning`"
            message="DEX, pressure, and skew will appear as their current snapshots become available."
          />
          <UiStatus
            v-else
            state="error"
            title="Positioning unavailable"
            :message="tabStatus.positioning.err || 'Data is not ready yet.'"
            retry
            @retry="ensureTabReady('positioning')"
          />
        </section>

        <!-- VOLATILITY -->
        <section
          v-if="activeTab==='volatility' && dataMode==='eod' && tabState('volatility')==='ready'"
          class="gex-ui gex-volatility-view"
          data-theme="dark"
          data-density="compact"
          aria-label="End-of-day volatility"
        >
          <div class="gex-volatility-view__grid">
            <UiStatus
              v-if="volErrors.term"
              state="error"
              title="Term structure unavailable"
              :message="volErrors.term"
              retry
              @retry="ensureVolatility"
            />
            <UiStatus
              v-else-if="volState.term === 'loading' || volState.term === 'pending'"
              :state="volState.term === 'pending' ? 'preparing' : 'loading'"
              title="Loading term structure"
              message="All returned forward expiries will appear when this snapshot is ready."
            />
            <TermTile v-else :items="term.items || []" :date="term.date" @reading-inspected="recordFirstUsefulReading" />

            <UiStatus
              v-if="volErrors.vrp"
              state="error"
              title="Variance risk premium unavailable"
              :message="volErrors.vrp"
              retry
              @retry="ensureVolatility"
            />
            <UiStatus
              v-else-if="volState.vrp === 'loading' || volState.vrp === 'pending'"
              :state="volState.vrp === 'pending' ? 'preparing' : 'loading'"
              layout="metrics"
              title="Loading variance risk premium"
              message="The 1-month implied and 20-session realized volatility comparison is being prepared."
            />
            <VRPTile
              v-else
              :date="vrp.date"
              :iv1m="vrp.iv1m"
              :rv20="vrp.rv20"
              :vrp="vrp.vrp"
              :z="vrp.z"
              :source-meta="vrp.source_meta"
            />
          </div>

          <UiStatus
            v-if="volErrors.season"
            state="error"
            title="Five-session seasonality unavailable"
            :message="volErrors.season"
            retry
            @retry="ensureVolatility"
          />
          <UiStatus
            v-else-if="volState.season === 'loading' || volState.season === 'pending'"
            :state="volState.season === 'pending' ? 'preparing' : 'loading'"
            title="Loading five-session seasonality"
            message="The historical tendency for the next five sessions is being prepared."
          />
          <Seasonality5Tile
            v-else-if="season"
            :date="season.date"
            :d1="season.d1" :d2="season.d2" :d3="season.d3" :d4="season.d4" :d5="season.d5"
            :cum5="season.cum5" :z="season.z" :note="seasonNote"
            @reading-inspected="recordFirstUsefulReading"
          />
          <UiStatus
            v-else
            state="sparse"
            title="Seasonality is unavailable"
            :message="seasonNote || 'No five-session seasonality data was returned for this symbol.'"
          />

          <UiStatus
            v-if="volErr"
            state="warning"
            title="Some volatility context is incomplete"
            :message="volErr"
          />
        </section>
        <section
          v-else-if="activeTab==='volatility' && dataMode==='eod'"
          class="gex-ui"
          data-theme="dark"
          data-density="compact"
        >
          <UiStatus
            v-if="['idle', 'pending'].includes(tabState('volatility'))"
            state="preparing"
            :title="`Preparing ${userSymbol} volatility`"
            message="Term structure, variance risk premium, and seasonality will appear independently as their snapshots become ready."
          />
          <UiStatus
            v-else
            state="error"
            title="Volatility unavailable"
            :message="tabStatus.volatility.err || 'Data is not ready yet.'"
            retry
            @retry="ensureVolatility"
          />
        </section>

        <!-- UA -->
        <section
          v-if="activeTab==='ua' && tabState('ua')==='ready'"
          class="gex-ui gex-ua-view"
          data-theme="dark"
          data-density="compact"
        >
          <UiPanel
            title="Unusual activity filters"
            :subtitle="`Screen ${userSymbol} contracts from the latest completed activity snapshot. Z-score and volume/OI are alternative signals; the other filters narrow the result.`"
            tone="data"
          >
            <template #actions>
              <div class="gex-row">
                <UiBadge v-if="uaDate" tone="data">Snapshot {{ uaDate }}</UiBadge>
                <UiBadge>{{ uaRows.length }} result<span v-if="uaRows.length !== 1">s</span></UiBadge>
              </div>
            </template>

            <form class="gex-ua-filter-form" @submit.prevent="ensureUA">
              <div class="gex-ua-filter-form__primary">
                <UiSelect v-model="uaExp" label="Expiry" :options="uaExpiryOptions" />
                <label class="gex-field" for="ua-top-per-expiry">
                  Top per expiry
                  <input id="ua-top-per-expiry" v-model.number="uaTop" class="gex-input gex-number" type="number" min="1" max="20" step="1">
                </label>
                <UiSelect v-model="uaSort" label="Rank results by" :options="uaSortOptions" />
                <div class="gex-ua-filter-form__actions">
                  <UiButton type="submit" variant="primary" :disabled="uaLoading">
                    {{ uaLoading ? 'Applying…' : 'Apply filters' }}
                  </UiButton>
                  <UiButton
                    type="button"
                    :aria-expanded="showAdvanced ? 'true' : 'false'"
                    aria-controls="ua-advanced-filters"
                    @click="showAdvanced = !showAdvanced"
                  >
                    {{ showAdvanced ? 'Hide advanced' : 'Advanced filters' }}
                  </UiButton>
                </div>
              </div>

              <div class="gex-ua-active-filters" aria-label="Current unusual activity filters">
                <span>Current screen</span>
                <UiBadge v-if="uaFiltersDirty" tone="warning">Changes waiting to be applied</UiBadge>
                <UiBadge tone="data">Z ≥ {{ uaFilterView.minZ }} or Vol/OI ≥ {{ uaFilterView.minVolOI }}</UiBadge>
                <UiBadge>Volume ≥ {{ uaFilterView.minVol }}</UiBadge>
                <UiBadge>
                  {{ uaFilterView.minPrem > 0
                    ? `Post-screen premium ≥ ${fmtUsd(uaFilterView.minPrem)}`
                    : uaEffectiveMinPremium != null
                      ? `Post-screen premium ≥ ${fmtUsd(uaEffectiveMinPremium)} automatic floor`
                      : 'Automatic premium floor' }}
                </UiBadge>
                <UiBadge>{{ uaFilterView.nearPct > 0 ? `Within ±${uaFilterView.nearPct}% of spot` : 'Any strike distance' }}</UiBadge>
                <UiBadge>{{ uaFilterView.side === 'call' ? 'Call-led only' : uaFilterView.side === 'put' ? 'Put-led only' : 'Both sides' }}</UiBadge>
                <UiBadge>Limit {{ uaFilterView.limit }}</UiBadge>
              </div>

              <div v-if="showAdvanced" id="ua-advanced-filters" class="gex-ua-advanced">
                <div class="gex-ua-advanced__heading">
                  <div>
                    <h3>Advanced thresholds</h3>
                    <p>Use zero to remove a threshold. A zero premium uses the server's symbol-specific floor.</p>
                  </div>
                  <div class="gex-row">
                    <UiButton type="button" @click="presetConservative">Conservative preset</UiButton>
                    <UiButton type="button" @click="presetAggressive">Broader preset</UiButton>
                  </div>
                </div>
                <div class="gex-ua-advanced__grid">
                  <label class="gex-field" for="ua-min-z">
                    Minimum z-score
                    <input id="ua-min-z" v-model.number="uaMinZ" class="gex-input gex-number" type="number" min="0" step="0.1">
                  </label>
                  <label class="gex-field" for="ua-min-vol-oi">
                    Minimum volume / OI
                    <input id="ua-min-vol-oi" v-model.number="uaMinVolOI" class="gex-input gex-number" type="number" min="0" step="0.1">
                  </label>
                  <label class="gex-field" for="ua-min-volume">
                    Minimum volume
                    <input id="ua-min-volume" v-model.number="uaMinVol" class="gex-input gex-number" type="number" min="0" step="1">
                  </label>
                  <label class="gex-field" for="ua-min-premium">
                    Minimum premium after signal screen ($)
                    <input id="ua-min-premium" v-model.number="uaMinPrem" class="gex-input gex-number" type="number" min="0" step="1000">
                  </label>
                  <label class="gex-field" for="ua-near-spot">
                    Distance from spot (±%)
                    <input id="ua-near-spot" v-model.number="uaNearPct" class="gex-input gex-number" type="number" min="0" step="1">
                  </label>
                  <UiSelect v-model="uaSide" label="Leading side" :options="uaSideOptions" />
                </div>
              </div>
            </form>
          </UiPanel>

          <UiStatus v-if="errors.ua" state="error" title="Unusual activity failed to load" :message="errors.ua" retry @retry="ensureUA" />
          <UiStatus
            v-else-if="uaLoading"
            state="loading"
            title="Applying unusual activity filters"
            message="Keeping the current screen in place until the updated activity response is ready."
          />
          <template v-else>
            <UiStatus
              v-if="!uaDate"
              state="sparse"
              title="No unusual activity snapshot is available"
              message="Try another symbol or return after the next completed activity calculation."
            />
            <UnusualActivityTable :rows="uaRows || []" :dataDate="uaDate" :symbol="userSymbol" :request-sort="uaFilterView.sort" @reading-inspected="recordFirstUsefulReading" />
            <div v-if="uaDate" class="gex-ua-show-more">
              <span class="gex-small gex-muted">Increase the per-expiry and overall limits without changing the active thresholds.</span>
              <UiButton :disabled="uaTop >= 20 && uaLimit >= 200" @click="showMore">
                {{ uaTop >= 20 && uaLimit >= 200 ? 'Maximum rows shown' : 'Show more activity' }}
              </UiButton>
            </div>
          </template>
        </section>
        <section
          v-else-if="activeTab==='ua'"
          class="gex-ui"
          data-theme="dark"
          data-density="compact"
        >
          <UiStatus
            v-if="['idle', 'pending'].includes(tabState('ua'))"
            state="preparing"
            layout="table"
            :title="`Preparing ${userSymbol} unusual activity`"
            message="The latest completed activity screen will appear here when it is ready."
          />
          <UiStatus
            v-else
            state="error"
            title="Unusual activity unavailable"
            :message="tabStatus.ua.err || 'Data is not ready yet.'"
            retry
            @retry="ensureUA"
          />
        </section>

        <!-- STRIKES -->
        <section
          v-if="strikesMounted && !intradayTransition && (dataMode==='eod' || activeTab==='strikes')"
          v-show="activeTab==='strikes'"
          :class="dataMode === 'eod' ? 'gex-ui gex-strikes-view' : 'gex-ui gex-intraday-strikes-view'"
          data-theme="dark"
          data-density="compact"
        >
          <!-- EOD: OI + Net GEX + ΔVol (EOD) -->
          <template v-if="dataMode==='eod'">
            <div ref="netGexSection" class="gex-strikes-view__primary">
              <NetGexChart
                eod
                :strikeData="levels?.strike_data || []"
                :symbol="userSymbol"
                :timeframe="selectedTimeframeLabel"
                :snapshot-date="levels?.data_date || levels?.date || null"
                :snapshot-name="`net-gex-${userSymbol}-${gexTf}-${levels?.data_date || levels?.date || 'date-unavailable'}`"
                @reading-inspected="recordFirstUsefulReading"
              />
            </div>
            <StrikeDeltaChart
              :strikeData="strikeSeriesForDelta"
              :symbol="userSymbol"
              :timeframe="selectedTimeframeLabel"
              :snapshot-date="levels?.data_date || levels?.date || null"
              :comparison-basis="strikeComparisonBasis"
              :comparison-date="strikeComparisonDate"
              :comparison-gap-trading-days="strikeComparisonGap"
              :comparison-is-stale="strikeComparisonIsStale"
              height-class="h-80 md:h-96 xl:h-[26rem]"
              :snapshot-name="`delta-oi-${userSymbol}-${gexTf}-${levels?.data_date || levels?.date || 'date-unavailable'}-${strikeComparisonBasis}-${strikeComparisonDate || 'no-comparison'}`"
              @reading-inspected="recordFirstUsefulReading"
            />
            <VolumeDeltaChart
              eod
              :strikeData="strikeSeriesForDelta"
              :symbol="userSymbol"
              :timeframe="selectedTimeframeLabel"
              :snapshot-date="levels?.data_date || levels?.date || null"
              :comparison-basis="strikeComparisonBasis"
              :comparison-date="strikeComparisonDate"
              :comparison-gap-trading-days="strikeComparisonGap"
              :comparison-is-stale="strikeComparisonIsStale"
              height-class="h-80 md:h-96 xl:h-[26rem]"
              :snapshot-name="`delta-vol-${userSymbol}-${gexTf}-${levels?.data_date || levels?.date || 'date-unavailable'}-${strikeComparisonBasis}-${strikeComparisonDate || 'no-comparison'}`"
              @reading-inspected="recordFirstUsefulReading"
            />
          </template>

          <!-- Intraday strike ratios and premium -->
          <template v-else>
            <UiPanel
              v-if="!intradaySnapshotAvailable"
              title="Live strikes"
              subtitle="A completed intraday strike snapshot is not available yet."
              tone="data"
            >
              <UiStatus
                :state="intradayLoading ? 'loading' : (marketOpen === true ? 'preparing' : (marketOpen === false ? 'closed' : 'sparse'))"
                :title="`No completed intraday strike snapshot for ${userSymbol}`"
                :message="marketOpen === true
                  ? 'The dashboard will retry according to the current refresh state. Returned pre-snapshot rows are not presented as live activity.'
                  : (marketOpen === false
                    ? `The first live strike snapshot can be collected ${intradayNextOpenLabel}.`
                    : 'Market-session status is unavailable. Returned pre-snapshot rows are not presented as live activity.')"
              />
              <details
                class="gex-intraday-diagnostic"
                @toggle="intradayUnavailableDetailsOpen = $event.currentTarget.open"
              >
                <summary>Returned pre-snapshot diagnostic rows · {{ levels?.strike_data?.length || 0 }}</summary>
                <div v-if="intradayUnavailableDetailsOpen">
                  <p class="gex-small gex-muted">These rows can contain EOD open-interest scaffolding. They are preserved exactly and are not labeled as intraday volume, ratios, or premium.</p>
                  <pre>{{ JSON.stringify(levels?.strike_data || [], null, 2) }}</pre>
                </div>
              </details>
            </UiPanel>
            <template v-else>
              <VolOverOiChart
                :strikeData="levels?.strike_data || []"
                :symbol="userSymbol"
                :source-label="intradaySourceLabel"
                :snapshot-as-of="intradaySnapshotAsOf"
                :source-timestamp-status="intradaySourceTimestampStatus"
                :market-open="marketOpen"
                :snapshot-name="`intraday-vol-oi-${userSymbol}-${intradaySnapshotAsOf || 'time-unavailable'}`"
                @reading-inspected="recordFirstUsefulReading"
              />
              <PcrByStrikeChart
                :strikeData="levels?.strike_data || []"
                :symbol="userSymbol"
                :source-label="intradaySourceLabel"
                :snapshot-as-of="intradaySnapshotAsOf"
                :source-timestamp-status="intradaySourceTimestampStatus"
                :market-open="marketOpen"
                :snapshot-name="`intraday-pcr-${userSymbol}-${intradaySnapshotAsOf || 'time-unavailable'}`"
                @reading-inspected="recordFirstUsefulReading"
              />
              <PremiumByStrikeChart
                :strikeData="levels?.strike_data || []"
                :symbol="userSymbol"
                :source-label="intradaySourceLabel"
                :snapshot-as-of="intradaySnapshotAsOf"
                :source-timestamp-status="intradaySourceTimestampStatus"
                :market-open="marketOpen"
                :snapshot-name="`intraday-premium-${userSymbol}-${intradaySnapshotAsOf || 'time-unavailable'}`"
                @reading-inspected="recordFirstUsefulReading"
              />
            </template>
          </template>
        </section>


        <!-- FLOW (Intraday) -->
        <section
          v-if="intradayFlowMounted && dataMode==='intraday' && activeTab==='flow'"
          class="gex-ui gex-intraday-view"
          data-theme="dark"
          data-density="compact"
        >
          <IntradayFlowPanel
            :symbol="userSymbol"
            :data-symbol="intradayDataSymbol || ''"
            :totals="{
              call_volume_total: levels?.call_volume_total,
              put_volume_total: levels?.put_volume_total,
              pcr_volume: levels?.pcr_volume,
              premium_total: levels?.premium_total,
            }"
            :rows="levels?.strike_data || []"
            :summary="levels?.intraday_summary || {}"
            :snapshot-meta="levels?.intraday_snapshot_meta || {}"
            :response-meta="levels?.intraday_response_meta || {}"
            :source-age-seconds="intradaySourceAge"
            :source-label="intradaySourceLabel"
            :next-open-label="intradayNextOpenLabel"
            :loading="intradayLoading"
            :refreshing="intradayRefreshing"
            :error="intradayError || ''"
            @refresh="manualRefresh"
            @retry="manualRefresh"
            @reading-inspected="recordFirstUsefulReading"
          />
        </section>
      </template>
    </div>

    <!-- Symbol Picker Modal -->
    <teleport to="body">
      <div
        v-if="showSymbolPicker"
        ref="symbolPickerDialog"
        class="gex-ui gex-dashboard-dialog"
        data-theme="dark"
        data-density="compact"
        @click.self="closeSymbolPicker()"
        @keydown="handleSymbolPickerKeydown"
      >
        <div id="dashboard-symbol-dialog" class="gex-dashboard-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="symbol-picker-title">
          <h2 id="symbol-picker-title">Select dashboard symbol</h2>
          <label for="dashboard-symbol-input">
            Ticker symbol
            <input
              id="dashboard-symbol-input"
              ref="symbolPickerInput"
              v-model="symbolSearch"
              maxlength="15"
              autocomplete="off"
              placeholder="SPY, QQQ, AAPL…"
              @keyup.enter="pickSymbol(symbolSearch)"
            />
          </label>
          <div class="gex-row" style="justify-content:flex-end; margin-top:16px">
            <UiButton @click="closeSymbolPicker()">Cancel</UiButton>
            <UiButton variant="primary" @click="pickSymbol(symbolSearch)">Open symbol</UiButton>
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
import { rateLimitDelayMs } from '@/Support/market-read-cooldown.js'
import { coalesceDashboardRequest } from '@/Support/dashboard-request-scope.js'
import { dashboardStateFromSearch, dashboardUrl } from '@/Support/dashboard-url-state.js'
import {
  bootstrapPollDelayMs,
  bootstrapPreparationNotice,
  ownsPreparationPoll,
  symbolPreparationState,
} from '@/Support/symbol-bootstrap-state.js'
import UiBadge from './UI/UiBadge.vue'
import UiButton from './UI/UiButton.vue'
import UiPanel from './UI/UiPanel.vue'
import UiSelect from './UI/UiSelect.vue'
import UiStatus from './UI/UiStatus.vue'
import OverviewMetrics from './OverviewMetrics.vue'
import FirstUseGuide from './FirstUseGuide.vue'
import { recordFirstUsefulReading as recordFirstUsefulReadingEvent } from '@/Support/first-use.js'

// Components
import StrikeDeltaChart from './StrikeDeltaChart.vue'
import VolumeDeltaChart from './VolumeDeltaChart.vue'
import NetGexChart from './NetGexChart.vue'
import OiDistributionChart from './OiDistributionChart.vue'
import VolDistributionChart from './VolDistributionChart.vue'
import Seasonality5Tile from './Seasonality5Tile.vue'
import VolOverOiChart from './VolOverOiChart.vue'
import PcrByStrikeChart from './PcrByStrikeChart.vue'
import PremiumByStrikeChart from './PremiumByStrikeChart.vue'
import IntradayFlowPanel from './IntradayFlow/IntradayFlowPanel.vue'
import TermTile from './TermTile.vue'
import VRPTile from './VRPTile.vue'
const SkewTile = defineAsyncComponent(() => import('./SkewTile.vue'))
const DexTile = defineAsyncComponent(() => import('./DexTile.vue'))
import QScorePanel from './QScorePanel.vue'
const ExpiryPressureTile = defineAsyncComponent(() => import('./ExpiryPressureTile.vue'))
import UnusualActivityTable from './UnusualActivityTable.vue'
import UiLoading from './UI/UiLoading.vue'
import uiErrorBlock from './ErrorBlock.vue'

const props = defineProps({
  accountId: { type: [Number, String], default: null },
  eodViewDefault: { type: String, default: 'latest_eod' },
})

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

const initialDashboardState = dashboardStateFromSearch(
  typeof window === 'undefined' ? '' : window.location.search,
  { view: props.eodViewDefault },
)
const dataMode = ref(initialDashboardState.mode)
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
const dashboardTabItems = computed(() => tabMeta.value.map(tab => ({
  value: tab.key,
  label: tab.state === 'pending'
    ? `${tab.label} · Preparing`
    : tab.state === 'error'
      ? `${tab.label} · Unavailable`
      : tab.label,
})))
const dashboardTabButtons = ref([])

function onDashboardTabKey(event, index) {
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return
  event.preventDefault()
  const last = dashboardTabItems.value.length - 1
  const next = event.key === 'Home'
    ? 0
    : event.key === 'End'
      ? last
      : (index + (event.key === 'ArrowRight' ? 1 : -1) + dashboardTabItems.value.length) % dashboardTabItems.value.length
  const tab = dashboardTabItems.value[next]
  if (!tab) return
  activate(tab.value)
  nextTick(() => dashboardTabButtons.value[next]?.focus())
}

// State
const symbol = ref(initialDashboardState.symbol)
const gexTf = ref(initialDashboardState.timeframe)
const eodView = ref(initialDashboardState.view)
const userSymbol = symbol
const getDefaultTab = (mode) => mode === 'intraday' ? 'flow' : 'strikes'
const activeTab = ref(initialDashboardState.tab)
const activeGuideTabLabel = computed(() =>
  dashboardTabItems.value.find(item => item.value === activeTab.value)?.label ?? activeTab.value
)
const lastTabByMode = reactive({
  eod: initialDashboardState.mode === 'eod' ? initialDashboardState.tab : 'strikes',
  intraday: initialDashboardState.mode === 'intraday' ? initialDashboardState.tab : 'flow',
})
const positioningMounted = ref(initialDashboardState.mode === 'eod' && initialDashboardState.tab === 'positioning')
const intradayFlowMounted = ref(initialDashboardState.mode === 'intraday' && initialDashboardState.tab === 'flow')
const intradayUnavailableDetailsOpen = ref(false)
const eodStrikesMounted = ref(initialDashboardState.mode === 'eod' && initialDashboardState.tab === 'strikes')
const intradayStrikesMounted = ref(initialDashboardState.mode === 'intraday' && initialDashboardState.tab === 'strikes')
const strikesMounted = computed({
  get: () => dataMode.value === 'eod' ? eodStrikesMounted.value : intradayStrikesMounted.value,
  set: value => {
    if (dataMode.value === 'eod') eodStrikesMounted.value = Boolean(value)
    else intradayStrikesMounted.value = Boolean(value)
  },
})
const eodLevels = ref(null)
const intradayLevels = ref(null)
const intradayDataSymbol = ref(null)
const levels = computed(() => dataMode.value === 'eod'
  ? eodLevels.value
  : (intradayDataSymbol.value === userSymbol.value ? intradayLevels.value : null))
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
const selectedTimeframeLabel = computed(() => timeframeOptions.find(tf => tf.value === gexTf.value)?.label ?? gexTf.value.toUpperCase())
const scopedExpirationDates = computed(() => {
  const mapped = levels.value?.timeframe_expirations?.[gexTf.value]
  if (Array.isArray(mapped)) return mapped
  return Array.isArray(levels.value?.expiration_dates) ? levels.value.expiration_dates : []
})
const uaExpiryOptions = computed(() => [
  { value: 'ALL', label: 'All activity expiries' },
  ...uaExpirationDates.value
    .map(date => ({ value: date, label: date })),
])
const uaSortOptions = [
  { value: 'z_score', label: 'Z-score' },
  { value: 'premium', label: 'Premium after signal screen' },
  { value: 'vol_oi', label: 'Volume / OI' },
]
const uaSideOptions = [
  { value: '', label: 'Calls and puts' },
  { value: 'call', label: 'Call-led only' },
  { value: 'put', label: 'Put-led only' },
]

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
const topError = computed(() => dataMode.value === 'eod'
  ? (['overview', 'strikes'].includes(activeTab.value) ? eodError.value : '')
  : (activeTab.value !== 'ua' && !intradayHasData.value ? intradayError.value : ''))
const primaryViewLoading = computed(() => dataMode.value === 'eod'
  ? ['overview', 'strikes'].includes(activeTab.value) && !eodLevels.value && (eodLoading.value || preparing.value.active)
  : activeTab.value !== 'ua' && !intradayHasData.value && (intradayLoading.value || intradayTransition.value))

function recordFirstUsefulReading() {
  recordFirstUsefulReadingEvent({
    accountId: props.accountId,
    validated: true,
  })
}

const lastUpdated = ref(null)
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
const uaExpirationDates = ref([])
const uaEffectiveMinPremium = ref(null)
const uaAppliedServerFilters = ref(null)
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
const uaAppliedFilters = ref(null)
function activityFilterSnapshot(exp = null) {
  return {
    exp: exp || 'ALL',
    top: uaTop.value,
    limit: uaLimit.value,
    minZ: uaMinZ.value,
    minVolOI: uaMinVolOI.value,
    minVol: uaMinVol.value,
    minPrem: uaMinPrem.value,
    nearPct: uaNearPct.value || 0,
    side: uaSide.value || '',
    sort: uaSort.value,
  }
}
const uaSelectedFilters = computed(() => activityFilterSnapshot(uaExp.value === 'ALL' ? null : uaExp.value))
const uaFilterView = computed(() => uaAppliedFilters.value || uaSelectedFilters.value)
const uaFiltersDirty = computed(() => uaAppliedFilters.value !== null
  && JSON.stringify(uaAppliedFilters.value) !== JSON.stringify(uaSelectedFilters.value))

function applyUaPayload(data, requestedFilters) {
  const payload = data && typeof data === 'object' ? data : {}
  const hasReportedExpirations = Array.isArray(payload.expiration_dates)
  uaDate.value = payload.data_date || null
  uaRows.value = Array.isArray(payload.items) ? payload.items : []
  uaExpirationDates.value = hasReportedExpirations
    ? [...new Set(payload.expiration_dates.filter(Boolean).map(String))].sort()
    : [...new Set(uaRows.value.map(row => row?.exp_date).filter(Boolean).map(String))].sort()
  uaEffectiveMinPremium.value = Number.isFinite(Number(payload.effective_min_premium))
    ? Number(payload.effective_min_premium)
    : (requestedFilters.minPrem > 0 ? Number(requestedFilters.minPrem) : null)
  uaAppliedServerFilters.value = payload.applied_filters && typeof payload.applied_filters === 'object'
    ? payload.applied_filters
    : null
  uaAppliedFilters.value = requestedFilters

  if (hasReportedExpirations && requestedFilters.exp !== 'ALL' && !uaExpirationDates.value.includes(requestedFilters.exp)) {
    uaExp.value = 'ALL'
  }
}
const showOnboarding = ref(false)
const dashboardContext = ref(null)
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
let eodRateLimitUntil = 0
let eodRateLimitTimer = null
const bootstrapControllers = new Map()
const bootstrapInflight = new Map()
const inflight = new Map()
const marketOpen = ref(null)
const inflightIntraday = new Map()
const cacheIntraday = new Map()
const INTRADAY_TTL_MS = 60_000 // 1 minute cache window
const INTRADAY_PENDING_RETRY_MS = 5_000
const INTRADAY_PENDING_MAX_RETRIES = 12
const intradaySnapshotAsOf = ref(null)
const intradaySnapshotAvailable = ref(false)
const intradayNextOpen = ref(null)
const intradaySourceAge = ref(null)
const intradayReceivedAt = ref(null)
const intradayIngestionCompletedAt = ref(null)
const intradayRefreshEligible = ref(null)
const intradayTradeDate = ref(null)
const intradayMarketSession = ref(null)
const intradaySourceTimestampStatus = ref('unknown')
const intradaySourceIsLegacy = computed(() => String(intradaySourceTimestampStatus.value).toLowerCase() === 'legacy')
const intradaySourceLabel = computed(() => {
  if (marketOpen.value === null) return intradayLoading.value ? 'Loading session state' : 'Session state unavailable'
  if (marketOpen.value === false) return 'Market Closed'
  if (intradaySourceIsLegacy.value) {
    if (intradaySourceAge.value === null) return 'Legacy response time'
    return intradaySourceAge.value < 90
      ? 'Recent legacy response'
      : `Legacy response (${Math.floor(intradaySourceAge.value / 60)}m old)`
  }
  if (intradaySourceAge.value === null) return 'Provider update time unavailable'
  return intradaySourceAge.value < 90 ? 'Live' : `Delayed (${Math.floor(intradaySourceAge.value / 60)}m)`
})
const intradaySourceTimeKind = computed(() => intradaySourceIsLegacy.value ? 'Legacy response time' : 'Provider source as of')
const intradayFreshnessState = computed(() => {
  if (intradayTransition.value) return 'loading'
  if (marketOpen.value === null) return intradayLoading.value ? 'loading' : 'stale'
  if (marketOpen.value === false) return 'closed'
  if (intradaySourceIsLegacy.value || intradaySourceAge.value === null) return 'stale'
  return intradaySourceAge.value < 90 ? 'fresh' : 'stale'
})
const intradayNextOpenLabel = computed(() => intradayNextOpen.value
  ? `at ${formatEtDateTime(intradayNextOpen.value)} ET`
  : 'at the next trading session')
const intradayHasData = computed(() => {
  return intradayDataSymbol.value === userSymbol.value && intradaySnapshotAvailable.value
})
const intradayAsOfEtLabel = computed(() => formatEtDateTime(lastUpdated.value))

// Symbol picker
const showSymbolPicker = ref(false)
const symbolSearch = ref('')
const symbolPickerTrigger = ref(null)
const symbolPickerInput = ref(null)
const symbolPickerDialog = ref(null)
let dashboardHistoryReady = false
let restoringDashboardHistory = false
const dashboardUrlEnabled = typeof window !== 'undefined' && window.location.pathname === '/dashboard'

function currentDashboardState() {
  return {
    symbol: userSymbol.value,
    mode: dataMode.value,
    tab: activeTab.value,
    timeframe: gexTf.value,
    view: eodView.value,
  }
}

function syncDashboardUrl(action = 'replace') {
  if (!dashboardUrlEnabled || restoringDashboardHistory) return
  const url = dashboardUrl(window.location.href, currentDashboardState())
  const method = action === 'push' ? 'pushState' : 'replaceState'
  window.history[method](currentDashboardState(), '', url)
}

async function restoreDashboardLocation() {
  if (!dashboardUrlEnabled) return
  const state = dashboardStateFromSearch(window.location.search, currentDashboardState())
  restoringDashboardHistory = true
  lastTabByMode[state.mode] = state.tab
  dataMode.value = state.mode
  activeTab.value = state.tab
  gexTf.value = state.timeframe
  eodView.value = state.view
  userSymbol.value = state.symbol
  if (state.mode === 'eod' && state.tab === 'positioning') positioningMounted.value = true
  if (state.mode === 'intraday' && state.tab === 'flow') intradayFlowMounted.value = true
  if (state.tab === 'strikes') strikesMounted.value = true
  await nextTick()
  restoringDashboardHistory = false
}

function openSymbolPicker() {
  symbolSearch.value = userSymbol.value
  showSymbolPicker.value = true
  nextTick(() => symbolPickerInput.value?.select())
}

function closeSymbolPicker({ restoreFocus = true } = {}) {
  showSymbolPicker.value = false
  symbolSearch.value = ''
  if (restoreFocus) nextTick(() => symbolPickerTrigger.value?.focus())
}

function handleSymbolPickerKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    closeSymbolPicker()
    return
  }
  if (event.key !== 'Tab') return

  const focusable = [...(symbolPickerDialog.value?.querySelectorAll(
    'button:not([disabled]), input:not([disabled]), [href], [tabindex]:not([tabindex="-1"])',
  ) || [])].filter(element => element.getClientRects().length > 0 || import.meta.env.MODE === 'test')
  if (!focusable.length) return

  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

function chooseTimeframe(timeframe) {
  if (gexTf.value === timeframe) return
  gexTf.value = timeframe
  syncDashboardUrl('push')
}

const intradayTransition = computed(() =>
  dataMode.value === 'intraday' &&
  intradayDataSymbol.value &&
  userSymbol.value !== intradayDataSymbol.value
)

function pickSymbol(sym) {
  const s = String(sym || '').trim().toUpperCase().replace(/[^A-Z0-9.^-]/g, '').slice(0, 15)
  if (s) {
    kickoffSymbolWarm(s, gexTf.value)
    userSymbol.value = s
    syncDashboardUrl('push')
  }
  closeSymbolPicker()
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
  clearTimeout(eodRateLimitTimer)
  eodRateLimitTimer = null
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
  if (!active) {
    activeTab.value = getDefaultTab(dataMode.value)
    syncDashboardUrl('replace')
  }
})

watch(activeTab, tab => {
  lastTabByMode[dataMode.value] = tab
  if (dataMode.value === 'eod' && tab === 'positioning') positioningMounted.value = true
  if (dataMode.value === 'intraday' && tab === 'flow') intradayFlowMounted.value = true
  if (tab === 'strikes') strikesMounted.value = true
})

function activate(key) {
  if (activeTab.value === key) {
    ensureActiveTab()
    return
  }
  activeTab.value = key
  if (dataMode.value === 'eod' && key === 'positioning') positioningMounted.value = true
  if (dataMode.value === 'intraday' && key === 'flow') intradayFlowMounted.value = true
  if (key === 'strikes') strikesMounted.value = true
  syncDashboardUrl('push')
}

const preferredTf = (target) => {
  const avail = timeframeAvailability.value
  if (avail && !avail.has(target)) {
    const first = [...avail][0]
    return first || gexTf.value
  }
  return target
}

function scrollToDashboardContext() {
  nextTick(() => {
    if (!disposed && dashboardContext.value) {
      const reduceMotion = typeof window !== 'undefined'
        && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
      dashboardContext.value.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' })
    }
  })
}

function startGuidedView() {
  showOnboarding.value = false
  localStorage.setItem('gex_onboarding_v1', 'seen')
  scrollToDashboardContext()
}

function dismissOnboarding() {
  showOnboarding.value = false
  const today = new Date().toISOString().slice(0, 10)
  // Mark as skipped for today only; will reappear tomorrow unless user completes CTA
  localStorage.setItem('gex_onboarding_v1', `skipped:${today}`)
}

function setMode(mode) {
  if (!['eod', 'intraday'].includes(mode) || dataMode.value === mode) return
  lastTabByMode[dataMode.value] = activeTab.value
  dataMode.value = mode
  activeTab.value = lastTabByMode[mode] || getDefaultTab(mode)
  if (mode === 'eod' && activeTab.value === 'positioning') positioningMounted.value = true
  if (mode === 'intraday' && activeTab.value === 'flow') intradayFlowMounted.value = true
  if (activeTab.value === 'strikes') strikesMounted.value = true
  syncDashboardUrl('push')
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
  const view = eodView.value
  const isCurrent = () => !disposed && generation === eodLoadGeneration
    && userSymbol.value === sym
    && dataMode.value === 'eod' && eodView.value === view
  const key = `gex|${sym}|${tf}|${view}`
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
  if (Date.now() < eodRateLimitUntil) {
    eodLoading.value = false
    eodError.value = 'Data requests are temporarily paused. The selected view will retry automatically shortly.'
    scheduleRateLimitedGexRetry()
    return
  }
  clearTimeout(eodRateLimitTimer)
  eodRateLimitTimer = null
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
        params: { symbol: sym, timeframe: tf, view },
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
      const msg = payload?.error || payload?.message || e.message || ''
      const status = e?.response?.status
      if (status === 429) {
        eodRateLimitUntil = Math.max(eodRateLimitUntil, Date.now() + rateLimitDelayMs(e.response) + 500)
        eodError.value = opts?.rateLimitRetry
          ? `Data requests are still temporarily limited. Please wait ${Math.ceil((eodRateLimitUntil - Date.now()) / 1000)} seconds, then select Retry.`
          : 'Data requests are temporarily paused. The selected view will retry automatically shortly.'
        if (!opts?.rateLimitRetry) scheduleRateLimitedGexRetry()
        return
      }
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

function scheduleRateLimitedGexRetry() {
  if (eodRateLimitTimer || disposed) return
  const owner = pageGeneration
  eodRateLimitTimer = setTimeout(() => {
    eodRateLimitTimer = null
    if (disposed || owner !== pageGeneration || dataMode.value !== 'eod') return
    // Read the current selection, never a symbol/timeframe captured before a
    // switch. A second 429 stops here instead of creating an automatic loop.
    fetchGexLevelsEOD(userSymbol.value, gexTf.value, { applyTf: true, rateLimitRetry: true })
  }, Math.max(1, eodRateLimitUntil - Date.now()))
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
        vrp.value = {
          date: response.data?.date ?? null,
          iv1m: response.data?.iv1m ?? null,
          rv20: response.data?.rv20 ?? null,
          vrp: response.data?.vrp ?? null,
          z: response.data?.z ?? null,
          ...(response.data?.source_meta != null ? { source_meta: response.data.source_meta } : {}),
        }
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
  const requestedFilters = activityFilterSnapshot(exp)
  const k = ['ua', mode, sym, requestedFilters.exp, requestedFilters.top, requestedFilters.minZ, requestedFilters.minVolOI, requestedFilters.minVol, requestedFilters.minPrem, requestedFilters.nearPct, requestedFilters.side, requestedFilters.sort, requestedFilters.limit].join('|')
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
    applyUaPayload(hit, requestedFilters)
    loaded.value.ua = true
    uaLoading.value = false
    return
  }
  const params = {
    symbol: sym, exp, per_expiry: requestedFilters.top, limit: requestedFilters.limit,
    min_z: requestedFilters.minZ, min_vol_oi: requestedFilters.minVolOI, min_vol: requestedFilters.minVol,
    min_premium: requestedFilters.minPrem, near_spot_pct: requestedFilters.nearPct,
    only_side: requestedFilters.side || null, with_premium: true, sort: requestedFilters.sort,
    include_scope: true,
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
    applyUaPayload(data, requestedFilters)
    loaded.value.ua = true
    setCache(cacheUA, k, data || {})
  } catch (e) {
    if (!isCurrent()) return
    if (isPreparing(e)) {
      tabStatus.ua.state = 'pending'
      pollActiveTab('ua', ensureUA, isCurrent)
    } else {
      errors.value.ua = e?.response?.data?.error || e?.message || 'The activity request failed.'
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

  if (evt.type === 'select-symbol-start') {
    if (next !== userSymbol.value) { userSymbol.value = next; syncDashboardUrl('push') }
    return
  }

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
  syncDashboardUrl('push')
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
  showOnboarding.value = onboarding === 'new'

  // Make the current URL shareable and restore it with browser Back/Forward.
  dashboardHistoryReady = dashboardUrlEnabled
  if (dashboardUrlEnabled) {
    syncDashboardUrl('replace')
    window.addEventListener('popstate', restoreDashboardLocation)
  }

  if (dataMode.value === 'eod') {
    fetchGexLevelsEOD(userSymbol.value, gexTf.value, { applyTf: true })
  } else {
    refreshIntraday({ force: false })
    startAutoRefresh()
  }
  ensureActiveTab()

  // listen for watchlist / scanner clicks
  window.addEventListener('select-symbol', handleSelectSymbolEvent)
  window.addEventListener('select-symbol-start', handleSelectSymbolEvent)
})


onUnmounted(() => {
  disposed = true
  window.removeEventListener('select-symbol', handleSelectSymbolEvent)
  window.removeEventListener('select-symbol-start', handleSelectSymbolEvent)
  window.removeEventListener('popstate', restoreDashboardLocation)
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
      }
      // Keep the bounded observer for bootstrap runs too; server work continues
      // independently if the user returns to this view later.

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
          eodError.value = state.noOptions ? `No options data is available for ${sym}. Choose another symbol.` : `Could not prepare ${sym}. Please retry.`
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

function ownsField(payload, field) {
  return payload && typeof payload === 'object' && Object.prototype.hasOwnProperty.call(payload, field)
}

function zonedTimestamp(value) {
  if (typeof value !== 'string') return null
  const timestamp = value.trim()
  return /(?:Z|[+-]\d{2}:?\d{2})$/i.test(timestamp) ? timestamp : null
}

function legacyResponseTime(summaryPayload, strikesPayload) {
  return zonedTimestamp(summaryPayload?.asof) ?? zonedTimestamp(strikesPayload?.asof)
}

function intradayResponseMeta(summaryPayload = {}, strikesPayload = {}) {
  const compositeOrSummary = field => {
    if (ownsField(strikesPayload, field)) return strikesPayload[field]
    if (ownsField(summaryPayload, field)) return summaryPayload[field]
    return null
  }
  const sourceContractPresent = ['source_asof', 'source_timestamp_complete', 'source_timestamp_status']
    .some(field => ownsField(strikesPayload, field) || ownsField(summaryPayload, field))
  const sourceAsOf = sourceContractPresent
    ? compositeOrSummary('source_asof')
    : legacyResponseTime(summaryPayload, strikesPayload)
  const rawAsOf = compositeOrSummary('asof')
  const marketSession = compositeOrSummary('market_session')
  const snapshotContractPresent = ownsField(strikesPayload, 'snapshot_available')
    || ownsField(summaryPayload, 'snapshot_available')

  return {
    open: typeof compositeOrSummary('open') === 'boolean' ? compositeOrSummary('open') : null,
    sourceAsOf,
    rawAsOf,
    capturedAt: compositeOrSummary('captured_at'),
    receivedAt: compositeOrSummary('received_at'),
    ingestionCompletedAt: compositeOrSummary('ingestion_completed_at'),
    snapshotAvailable: snapshotContractPresent ? compositeOrSummary('snapshot_available') : Boolean(rawAsOf),
    refreshEligible: compositeOrSummary('refresh_eligible'),
    refreshReason: compositeOrSummary('refresh_reason'),
    tradeDate: compositeOrSummary('trade_date'),
    marketSession,
    nextOpen: marketSession?.next_open_at ?? null,
    sourceTimestampStatus: compositeOrSummary('source_timestamp_status')
      ?? (sourceAsOf ? (sourceContractPresent ? 'complete' : 'legacy') : 'unknown'),
    sourceTimestampComplete: compositeOrSummary('source_timestamp_complete')
      ?? (sourceAsOf ? true : null),
  }
}

function applyIntradayResponseMeta(meta, now = Date.now()) {
  const sourceMs = meta?.sourceAsOf ? new Date(meta.sourceAsOf).getTime() : Number.NaN
  marketOpen.value = typeof meta?.open === 'boolean' ? meta.open : null
  intradaySnapshotAsOf.value = meta?.sourceAsOf ?? null
  intradaySnapshotAvailable.value = Boolean(meta?.snapshotAvailable)
  intradayNextOpen.value = meta?.nextOpen ?? null
  intradayReceivedAt.value = meta?.receivedAt ?? null
  intradayIngestionCompletedAt.value = meta?.ingestionCompletedAt ?? null
  intradayRefreshEligible.value = typeof meta?.refreshEligible === 'boolean' ? meta.refreshEligible : null
  intradayTradeDate.value = meta?.tradeDate ?? null
  intradayMarketSession.value = meta?.marketSession ?? null
  intradaySourceTimestampStatus.value = meta?.sourceTimestampStatus ?? 'unknown'
  intradaySourceAge.value = Number.isFinite(sourceMs)
    ? Math.max(0, Math.floor((now - sourceMs) / 1000))
    : null
  lastUpdated.value = meta?.sourceAsOf ?? null
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
  intradayError.value = ''

  // 1) Use in-memory cache if recent and not forced
  const cached = cacheIntraday.get(sym)
  if (!force && cached && now - cached.t < INTRADAY_TTL_MS) {
    if (!intradayLevels.value) intradayLevels.value = {}
    Object.assign(intradayLevels.value, cached.payload)
    intradayDataSymbol.value = sym
    applyIntradayResponseMeta(cached.meta ?? {
      open: marketOpen.value,
      sourceAsOf: cached.asof ?? null,
      snapshotAvailable: cached.available ?? Boolean(cached.asof),
      nextOpen: cached.nextOpen ?? null,
    }, now)
    clearIntradayPendingRetry()
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
  const p = (async () => {
    try {
      // Step A: lightweight summary to check freshness
      const summaryResp = await axios.get('/api/intraday/summary', {
        params: { symbol: sym },
        signal: ctl.signal,
      })
      const sumData = summaryResp.data || {}
      if (userSymbol.value !== sym || dataMode.value !== 'intraday' || ctl.signal.aborted) return

      marketOpen.value = typeof sumData.open === 'boolean' ? sumData.open : null

      const summarySourceContractPresent = ['source_asof', 'source_timestamp_complete', 'source_timestamp_status']
        .some(field => ownsField(sumData, field))
      const freshnessAsOf = summarySourceContractPresent ? sumData.source_asof : sumData.asof
      const asofMs = freshnessAsOf ? new Date(freshnessAsOf).getTime() : null
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
      const responseMeta = intradayResponseMeta(sumData, compData)
      const rawStrikeRows = Array.isArray(compData.items) ? compData.items : []
      const totals = compData.totals && typeof compData.totals === 'object' ? compData.totals : {}
      const snapshotMeta = Object.fromEntries(Object.entries(compData).filter(([field]) => field !== 'items'))

      const next = {
        call_volume_total: totals.call_vol ?? null,
        put_volume_total: totals.put_vol ?? null,
        pcr_volume: totals.pcr_vol ?? null,
        premium_total: totals.premium ?? null,
        intraday_summary: sumData,
        intraday_snapshot_meta: snapshotMeta,
        intraday_response_meta: responseMeta,
        strike_data: rawStrikeRows.map(r => ({ ...r })),
        strike_gex_live: rawStrikeRows.map(r => ({
          ...r,
          net_gex: r.net_gex ?? r.net_gex_live ?? null,
          net_gex_delta: r.net_gex_delta ?? null,
        })),
      }

      if (!intradayLevels.value) intradayLevels.value = {}
      Object.assign(intradayLevels.value, next)

      intradayDataSymbol.value = sym
      applyIntradayResponseMeta(responseMeta)
      firstIntradayLoadDone.value = true

      // Step D: never retain the placeholder read made while a new symbol's
      // queued ingest is still running. Poll briefly, then fall back to the
      // normal 30-second refresh interval.
      if (responseMeta.snapshotAvailable) {
        clearIntradayPendingRetry()
        cacheIntraday.set(sym, {
          t: Date.now(),
          asof: responseMeta.sourceAsOf,
          available: responseMeta.snapshotAvailable,
          nextOpen: responseMeta.nextOpen,
          meta: responseMeta,
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
  uaExpirationDates.value = []
  uaEffectiveMinPremium.value = null
  uaAppliedServerFilters.value = null
  uaLoading.value = false
  uaAppliedFilters.value = null
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
  eodError.value = ''
  eodLoading.value = dataMode.value === 'eod'
  intradayLoading.value = dataMode.value === 'intraday'
  intradayRefreshing.value = false
  intradayError.value = ''
  intradayUnavailableDetailsOpen.value = false
  for (const candidate of bootstrapStartResponses.keys()) {
    if (candidate !== s) bootstrapStartResponses.delete(candidate)
  }

  const owner = pageGeneration
  busy.value.positioning = false
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
    window.dispatchEvent(new CustomEvent('dashboard-symbol-changed', {
      detail: { symbol: s },
    }))
  }
})

watch([userSymbol, dataMode, activeTab, gexTf, eodView], () => {
  if (dashboardHistoryReady && !restoringDashboardHistory) syncDashboardUrl('replace')
})

watch(uaExp, () => { if (!symbolTimer && activeTab.value === 'ua') ensureUA() })

watch([gexTf, eodView], ([tf]) => {
  if (dataMode.value === 'eod') fetchGexLevelsEOD(userSymbol.value, tf)
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
const strikeSeriesForDelta = computed(() => {
  return Array.isArray(levels.value?.strike_data) ? levels.value.strike_data : []
})
const strikeComparisonBasis = computed(() => {
  if (levels.value?.date_prev) return 'daily'
  if (levels.value?.date_prev_week) return 'weekly'
  return 'unavailable'
})
const strikeComparisonDate = computed(() => strikeComparisonBasis.value === 'daily'
  ? (levels.value?.date_prev ?? null)
  : (levels.value?.date_prev_week ?? null))
const strikeComparisonGap = computed(() => strikeComparisonBasis.value === 'daily'
  ? (levels.value?.date_prev_gap_trading_days ?? null)
  : (levels.value?.date_prev_week_gap_trading_days ?? null))
const strikeComparisonIsStale = computed(() => strikeComparisonBasis.value === 'daily'
  ? Boolean(levels.value?.date_prev_is_stale)
  : (strikeComparisonGap.value != null && Number(strikeComparisonGap.value) > 5))
</script>

<style scoped>
.gex-eod-view { display:flex; flex-wrap:wrap; align-items:center; gap:12px; padding:12px 0; }
.gex-eod-view .gex-segmented { flex-wrap:wrap; }
.gex-eod-view p { flex:1 1 320px; margin:0; }

.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
