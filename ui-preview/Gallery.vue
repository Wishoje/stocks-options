<script setup>
import { computed, ref, watch } from 'vue'
import UiPanel from '../resources/js/Components/UI/UiPanel.vue'
import UiButton from '../resources/js/Components/UI/UiButton.vue'
import UiMetric from '../resources/js/Components/UI/UiMetric.vue'
import UiTabs from '../resources/js/Components/UI/UiTabs.vue'
import UiSelect from '../resources/js/Components/UI/UiSelect.vue'
import UiStatus from '../resources/js/Components/UI/UiStatus.vue'
import UiExposureChart from '../resources/js/Components/UI/UiExposureChart.vue'
import UiHistory from '../resources/js/Components/UI/UiHistory.vue'
import UiDataTable from '../resources/js/Components/UI/UiDataTable.vue'
import UiTooltip from '../resources/js/Components/UI/UiTooltip.vue'
import UiBadge from '../resources/js/Components/UI/UiBadge.vue'
import { compact } from '../resources/js/Components/UI/numbers.js'
import { buckets, fixture, provenance, scenarios } from './fixtures.js'

const theme=ref('dark'), density=ref('comfortable'), reduced=ref(false), scenario=ref('recorded'), bucketId=ref('1w'), selected=ref(null)
const data=computed(()=>fixture(scenario.value,bucketId.value))
watch(()=>data.value.expiry, rows=>{ selected.value=rows.find(r=>r.date===selected.value)?.date || rows[0]?.date || null },{immediate:true})
const current=computed(()=>data.value.expiry.find(r=>r.date===selected.value))
const columns=[{key:'date',label:'Expiry',sortable:true},{key:'value',label:'DEX (share equivalents)',numeric:true,sortable:true,format:compact}]
const status=computed(()=>({
  recorded:['Recorded sample','40 expiry readings, including expired contracts, and 30 daily readings in each skew bucket.'],
  sparse:['Partial data','Missing readings stay unavailable. A gap in history is not a zero.'],
  zero:['Zero readings','Zero is a valid measurement and remains visible.'],
  positive:['Positive values','The chart keeps a common zero baseline.'],
  negative:['Negative values','Signed labels and position make direction clear without relying on color.'],
  empty:['No readings','There are no readings for this example selection.'],
  loading:['Loading readings','Waiting for a response. No previous symbol’s values are presented as current.'],
  preparing:['Preparing data','An initial snapshot is being prepared.'],
  stale:['Stale comparison','Synthetic example: the previous snapshot is older than the expected trading day.'],
  closed:['Market closed','Historical snapshot retained with its date; a closed market is not a failed request.'],
  error:['Unable to load readings','Synthetic request failure. Retry returns to the recorded sample.'],
}[scenario.value]))
const recorded=computed(()=>['recorded','stale','closed'].includes(scenario.value))
const scenarioTone=computed(()=>({ sparse:'warning',preparing:'warning',stale:'warning',positive:'positive',negative:'negative',error:'negative',recorded:'data',closed:'data',loading:'data' }[scenario.value] || 'neutral'))
const scenarioLabel=computed(()=>scenarios.find(item=>item.value===scenario.value)?.label || scenario.value)
</script>
<template>
  <main class="gex-ui preview" :data-theme="theme" :data-density="density" :data-motion="reduced?'reduced':'system'">
    <div class="preview-inner gex-stack">
      <div class="preview-topbar"><a class="preview-brand" href="#top" aria-label="GEX Options preview home"><span aria-hidden="true">GX</span><strong>GEX Options</strong></a><nav aria-label="Preview sections"><a class="is-active" href="#positioning">Positioning</a><a href="#states">Data states</a></nav><UiBadge tone="positive">Preview ready</UiBadge></div>
      <header id="top" class="preview-header"><div><div class="preview-eyebrow">Dashboard refresh · Batch 1</div><h1>Clearer data. Familiar context.</h1><p class="gex-muted">Shared components for the dashboard refresh, based on Positioning. Review the controls, reading order, and complete-data access before applying them to each tab.</p></div></header>
      <div class="preview-context" aria-label="Active data context"><UiBadge tone="data">SPY</UiBadge><UiBadge>EOD snapshot</UiBadge><UiBadge>Sep 9, 2026</UiBadge><UiBadge :tone="scenarioTone">{{ scenarioLabel }}</UiBadge></div>
      <div class="gex-panel preview-controls gex-row" aria-label="Preview settings">
        <UiSelect v-model="theme" label="Appearance" :options="[{value:'dark',label:'Dark'},{value:'light',label:'Light'},{value:'system',label:'System'}]"/>
        <UiSelect v-model="density" label="Density" :options="[{value:'comfortable',label:'Comfortable'},{value:'compact',label:'Compact'}]"/>
        <UiSelect v-model="scenario" label="Data example" :options="scenarios"/>
        <label class="gex-check"><input v-model="reduced" type="checkbox">Reduce motion</label>
      </div>
      <p class="gex-small gex-muted preview-note">{{ recorded ? provenance : 'Synthetic example · not live market data' }}. This component preview runs locally without a database or analytics connection.</p>
      <UiStatus :state="scenario" :title="status[0]" :message="status[1]" :retry="scenario==='error'" @retry="scenario='recorded'"/>
      <section id="positioning" class="gex-stack preview-section" aria-label="Positioning component examples">
        <div class="gex-grid">
          <UiMetric prominence="primary" tone="negative" label="Reported net dealer exposure" :value="recorded?'−4.42M':null" unit="share equivalents" context="Whole snapshot · independent of the dashboard timeframe"/>
          <UiMetric tone="data" label="Expiry readings" :value="data.expiry.length" context="Every supplied expiry remains available, including expired contracts"/>
          <UiMetric tone="data" label="History readings" :value="data.history.length" context="Rolling expiry bucket · daily snapshot dates"/>
        </div>
        <div class="preview-columns">
          <UiPanel tone="data" title="Exposure by expiry" subtitle="Share equivalents · full snapshot scope">
            <div class="preview-exposure"><UiExposureChart v-model="selected" :items="data.expiry" unit="share equivalents"/></div>
            <div class="preview-detail" aria-live="polite"><h3>{{ current ? `Selected expiry · ${current.date}` : 'No selected expiry' }}</h3><p v-if="current" class="gex-number">{{ current.value==null?'Unavailable':`${compact(current.value)} share equivalents` }}</p></div>
            <p class="gex-focus-help">Select any row with a pointer, touch, or keyboard. Signed labels and a central zero line show direction.</p>
            <details><summary>All {{ data.expiry.length }} expiry readings</summary><UiDataTable caption="Recorded expiry values" :rows="data.expiry" :columns="columns" row-key="date"/></details>
          </UiPanel>
          <UiPanel tone="data" title="Volatility skew" subtitle="25-delta put IV minus 25-delta call IV">
            <UiTabs id="skew" v-model="bucketId" label="Skew expiry bucket" :items="buckets"/>
            <div id="skew-panel" role="tabpanel" :aria-labelledby="`skew-${bucketId}`" tabindex="0" style="margin-top:18px">
              <p class="gex-small gex-muted">Selected bucket: {{ data.bucket.label }} · recorded contract: {{ data.bucket.exp }}</p>
              <div class="gex-grid preview-history-metrics">
                <UiMetric label="Put IV · 25Δ" :value="recorded?data.bucket.put:null" unit="%"/>
                <UiMetric label="Call IV · 25Δ" :value="recorded?data.bucket.call:null" unit="%"/>
                <UiMetric prominence="primary" tone="positive" label="Reported skew" :value="recorded?data.bucket.skew:null" unit="pp" :context="recorded?`Day over day: +${data.bucket.dod} pp`:'Summary unavailable in this synthetic case'"/>
              </div>
              <UiHistory :items="data.history" :scope-key="bucketId" :scope="`${data.bucket.label} bucket · ${data.history.length} snapshot dates`" explanation="Skew = put IV at 25 delta − call IV at 25 delta. IV is displayed as a percentage; its difference is in percentage points (pp).">
                <template #calculation><p>Each history date selects the expiry for that bucket at that snapshot. It is a rolling series, not the life of one fixed contract.</p><p>The recorded summary and history are separate display samples. Their latest values can differ. Rounded IV labels must not replace the reported skew.</p><p>Curvature uses three decimal places in the card while its source value and quality inputs remain in component state.</p></template>
              </UiHistory>
            </div>
          </UiPanel>
        </div>
      </section>
      <UiPanel id="states" class="preview-section" title="Status and interaction examples" subtitle="The state is named in text; color supports the meaning.">
        <div class="gex-stack"><UiStatus state="stale" title="Older comparison snapshot" message="Show the actual comparison date and trading-day gap alongside the delta."/><UiStatus state="empty" title="No options for this selection" message="Keep the selected symbol and filters visible so the next action is clear."/><div class="gex-row"><UiButton variant="primary" @click="scenario='error'">Try failure and retry</UiButton><UiButton @click="scenario='recorded'">Reset data example</UiButton><UiButton disabled>Preparing snapshot</UiButton><span>Reusable help <UiTooltip label="About complete data">Hover, focus, or select this control. Full readings remain available in keyboard-accessible details and tables.</UiTooltip></span></div><p class="gex-small gex-muted">Hover, focus, and selection transitions last 150 ms. Reduced motion disables transitions. Data values and charts do not animate into place.</p></div>
      </UiPanel>
      <footer class="gex-small gex-muted preview-foot">Batch 1 delivers the inventory, measurement audit, and shared UI components. Navigation, watchlist, and the full Positioning tab follow in Batch 2.</footer>
    </div>
  </main>
</template>
