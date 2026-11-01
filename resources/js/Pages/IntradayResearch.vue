<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import IntradayResearchCase from '@/Components/IntradayResearchCase.vue'
import { defaultIntradayFilters, intradayFilterError, intradayFilterParams, intradayVariantLabels, intradayVariants } from '@/Support/intradayResearch'
import { swingNumber, swingPercent, swingSignedPercent, swingTime } from '@/Support/swingResearch'

const props = defineProps({ localDemoAvailable: Boolean })
const search = typeof window === 'undefined' ? '' : window.location.search
const filters = reactive(defaultIntradayFilters(new Date(), search))
const demonstration = ref(props.localDemoAvailable && new URLSearchParams(search).get('demo') === '1')
const report = ref(null), loading = ref(false), refreshing = ref(false), error = ref(''), message = ref(''), refreshWarnings = ref([])
const filterError = computed(() => intradayFilterError(filters))
const busy = computed(() => loading.value || refreshing.value)
const profiles = computed(() => intradayVariants.map(key => ({ key, label: report.value?.variants?.[key]?.label || intradayVariantLabels[key], description: report.value?.variants?.[key]?.description || '', summary: report.value?.summary?.find(item => item.variant === key) || {} })))
const groups = computed(() => report.value?.groups || [])
const warnings = computed(() => [...new Set([...(report.value?.warnings || []), ...refreshWarnings.value])])
const caseFor = (group, key) => group.cases?.find(item => item.variant === key)
const targetRate = summary => Number(summary.target_rate?.eligible) > 0 ? swingPercent(summary.target_rate.rate) : '—'
const rateCount = summary => Number(summary.target_rate?.eligible) > 0 ? `${swingNumber(summary.target_rate.hits, 0)} of ${swingNumber(summary.target_rate.eligible, 0)} completed cases` : 'No eligible completed cases'
let request = 0, controller, refreshController, disposed = false
function requestError(cause, fallback) {
  if ([401, 403].includes(cause?.response?.status)) return 'Intraday experiments are available to the owner account only.'
  if (cause?.response?.status === 429) return 'Please wait a moment before trying again.'
  return cause?.response?.data?.message || fallback
}
async function load() {
  if (filterError.value) return
  const run = ++request, expectedDemo = demonstration.value
  controller?.abort(); controller = new AbortController()
  loading.value = true; error.value = ''; report.value = null
  try {
    const { data } = await axios.get('/api/intraday-research', { params: { ...intradayFilterParams(filters), ...(expectedDemo ? { demo: 1 } : {}) }, signal: controller.signal, timeout: 30000 })
    if (disposed || run !== request) return
    if (!Array.isArray(data?.groups) || !Array.isArray(data?.summary) || !data.variants || Boolean(data.demonstration) !== expectedDemo) throw new Error('Invalid report')
    report.value = data
    await nextTick()
    if (window.location.hash.startsWith('#intraday-case-')) document.getElementById(decodeURIComponent(window.location.hash.slice(1)))?.scrollIntoView?.({ block: 'center' })
  } catch (cause) {
    if (!disposed && run === request && cause?.code !== 'ERR_CANCELED') error.value = requestError(cause, 'Intraday experiments could not load. Try again.')
  } finally { if (!disposed && run === request) loading.value = false }
}
function applyFilters() {
  filters.symbol = filters.symbol.trim().toUpperCase(); message.value = ''; refreshWarnings.value = []; load()
}
function toggleExamples() {
  if (!props.localDemoAvailable || busy.value || filterError.value) return
  demonstration.value = !demonstration.value; applyFilters()
}
async function refreshRecords() {
  if (demonstration.value || report.value?.demonstration || busy.value || filterError.value || !report.value?.enabled || !report.value?.prepared) return
  refreshing.value = true; error.value = ''; message.value = ''; refreshWarnings.value = []
  refreshController = new AbortController()
  try {
    const { data } = await axios.post('/api/intraday-research/refresh', intradayFilterParams(filters), { signal: refreshController.signal, timeout: 60000 })
    if (disposed) return
    refreshWarnings.value = Array.isArray(data?.warnings) ? data.warnings : []
    message.value = data?.busy ? 'Collection is already running. Showing saved cases.' : data?.prepared === false ? 'Collection is not ready.' : 'Saved research records refreshed.'
    await load()
  } catch (cause) { if (!disposed && cause?.code !== 'ERR_CANCELED') error.value = requestError(cause, 'Records could not refresh. Try again.') }
  finally { if (!disposed) refreshing.value = false }
}
onMounted(load)
onBeforeUnmount(() => { disposed = true; request++; controller?.abort(); refreshController?.abort() })
</script>

<template>
  <AppLayout title="Intraday experiments" :research-demo="demonstration">
    <main class="intraday-research gex-ui" data-theme="dark">
      <nav class="research-nav" aria-label="Private research"><Link href="/setup-study">Setup study</Link><Link href="/swing-research">Swing experiments</Link><Link href="/intraday-research" aria-current="page">Intraday experiments</Link></nav>
      <header class="research-heading"><div><p class="eyebrow">PRIVATE · PAPER RESEARCH</p><h1>Intraday experiments</h1><p>Compare recorded-close conditions with a later retest of the same frozen entry area.</p></div><div class="actions"><button type="button" :disabled="busy || !!filterError" @click="load">{{ loading ? 'Loading…' : 'Reload view' }}</button><button type="button" :disabled="busy || demonstration || !report?.enabled || !report?.prepared || !!filterError" @click="refreshRecords">{{ refreshing ? 'Refreshing…' : 'Refresh records' }}</button></div></header>
      <p class="research-note">Observations are delayed 15 minutes. These paper cases track underlying stock or ETF prices without assuming a trade fill. A recorded conditions-met event does not guarantee an entry at the current price. Only the experimental retest can issue one conditions-met alert; outcomes remain in this study.</p>
      <button v-if="localDemoAvailable" type="button" class="demo-toggle" :disabled="busy || !!filterError" :aria-pressed="demonstration" @click="toggleExamples">{{ demonstration ? 'Return to saved research' : 'Show synthetic examples' }}</button>
      <div v-if="demonstration" class="demo-notice" role="status"><strong>Synthetic examples</strong><p>Invented prices and outcomes show how this page works. This view does not collect records or issue experimental alerts.</p></div>
      <form class="panel research-filters" @submit.prevent="applyFilters">
        <label>Symbol<input v-model="filters.symbol" placeholder="All symbols" maxlength="10" :disabled="busy" /></label>
        <label>From<input v-model="filters.from" type="date" :disabled="busy" /></label><label>To<input v-model="filters.to" type="date" :disabled="busy" /></label>
        <button type="submit" :disabled="busy || !!filterError">Apply filters</button><p v-if="filterError" class="filter-error" role="alert">{{ filterError }}</p>
      </form>
      <p v-if="message" class="message" role="status">{{ message }}</p>
      <div v-if="error" class="panel error" role="alert"><p>{{ error }}</p><button type="button" :disabled="busy || !!filterError" @click="load">Try again</button></div>
      <p v-for="(warning, index) in warnings" :key="index" class="warning" role="status">{{ warning }}</p>
      <div v-if="loading" class="panel empty" role="status">Loading intraday research…</div>
      <template v-else-if="report">
        <p v-if="!report.prepared" class="warning">Research storage is not ready. Saved cases will appear after local setup is complete.</p>
        <p v-else-if="!report.enabled && !demonstration" class="warning">Collection is paused. Existing paper cases remain available for review.</p>
        <p v-if="report.collection?.collected_at" class="collection-note">Last collection {{ swingTime(report.collection.collected_at) }} · {{ swingNumber(report.collection.symbols, 0) }} of {{ swingNumber(report.collection.total_symbols, 0) }} symbols · {{ swingNumber(report.collection.groups, 0) }} groups</p>
        <div class="profile-grid" aria-label="Rule profile comparison">
          <section v-for="profile in profiles" :key="profile.key" class="panel profile">
            <p class="profile-label">{{ profile.key === 'baseline' ? 'CONTROL · STUDY ONLY · NO ALERTS' : 'EXPERIMENTAL · CONDITIONS-MET ALERT ONLY' }}</p><h2>{{ profile.label }}</h2><p class="profile-description">{{ profile.description }}</p>
            <dl class="counts">
              <div><dt>Recorded</dt><dd>{{ swingNumber(profile.summary.total, 0) }}</dd></div><div><dt>Conditions met</dt><dd>{{ swingNumber(profile.summary.qualified, 0) }}</dd></div><div><dt>Waiting</dt><dd>{{ swingNumber(profile.summary.waiting, 0) }}</dd></div>
              <div><dt>In progress</dt><dd>{{ swingNumber(profile.summary.in_progress, 0) }}</dd></div><div><dt>Complete</dt><dd>{{ swingNumber(profile.summary.completed, 0) }}</dd></div><div><dt>Needs review</dt><dd>{{ swingNumber(profile.summary.needs_review, 0) }}</dd></div>
              <div><dt>No entry</dt><dd>{{ swingNumber(profile.summary.not_qualified, 0) }}</dd></div>
            </dl>
            <div class="target-rate"><span>Target reached before invalidation</span><strong>{{ targetRate(profile.summary) }}</strong><p>{{ rateCount(profile.summary) }}</p></div>
            <dl class="outcomes">
              <div><dt>Move at recorded outcome</dt><dd>{{ swingSignedPercent(profile.summary.avg_outcome_move_pct) }}</dd><small>Average directional underlying price move · completed cases</small></div>
              <div><dt>Target / invalidated / ended</dt><dd>{{ swingNumber(profile.summary.target_reached, 0) }} / {{ swingNumber(profile.summary.invalidated, 0) }} / {{ swingNumber(profile.summary.ended, 0) }}</dd></div>
              <div><dt>Average best / adverse price move</dt><dd>{{ swingPercent(profile.summary.avg_mfe_pct) }} / {{ swingPercent(profile.summary.avg_mae_pct) }}</dd></div>
            </dl>
          </section>
        </div>
        <p class="method-note">Target rates use eligible completed cases. Waiting, active and incomplete cases are shown separately. Each profile has its own conditions-met close and observation window. These counts describe recorded paper observations and do not establish trading profit or a better profile.</p>
        <section class="cases" aria-labelledby="intraday-cases-title"><header><h2 id="intraday-cases-title">Paired paper cases</h2><p>{{ groups.length }} symbol and session groups</p></header>
          <div v-if="!groups.length" class="panel empty"><h3>{{ demonstration ? 'No synthetic examples match these filters' : 'No recorded cases in this view' }}</h3><p>{{ demonstration ? 'Choose another symbol or date range.' : 'Choose another period or refresh records to collect available saved evidence.' }}</p></div>
          <article v-for="group in groups" :key="group.id" class="case-group">
            <header><h3>{{ group.symbol }} <span>{{ group.session }}</span></h3><p>Source close {{ swingTime(group.source_signal_at) }} · Source available {{ swingTime(group.source_available_at) }} · Private watch recorded {{ swingTime(group.created_at) }}</p></header>
            <div class="case-grid"><IntradayResearchCase v-for="profile in profiles" :key="profile.key" :profile="profile" :item="caseFor(group, profile.key)" /></div>
          </article>
        </section>
        <details class="panel methodology"><summary>How the comparison works</summary><p>The recorded-close control keeps the original recorded conditions and is study only. The experimental profile opens a new 15-minute research entry window after the private watch is recorded. It requires a whole five-minute bar to start at or after that anchor and close back inside the frozen entry area. The original invalidation, target and session end remain fixed.</p><p>Saved observations remain delayed 15 minutes. The study evaluates all available later observations before recording an alert. Historical qualifying bars and cases that have already ended do not become new alerts. Each still-open experimental case can record one conditions-met event. Approaching, testing and outcome changes do not issue experimental popups.</p><p>Recorded conditions met is the observed bar close. Condition event recorded / received is when the event was saved after the delayed evidence became available. A later outcome belongs to the paper case and does not change the original alert.</p></details>
      </template>
    </main>
  </AppLayout>
</template>

<style scoped>
.intraday-research{max-width:1540px;margin:auto;padding:28px 26px 64px;color:#eaf0fb}.research-nav{display:flex;gap:22px;flex-wrap:wrap;margin-bottom:25px;font-size:.8rem;color:#b7c7e3}.research-nav a{padding:8px 0;min-height:36px}.research-nav a[aria-current=page]{color:#d4d0ff;border-bottom:2px solid #a49be0}.research-heading,.actions,.cases>header,.case-group>header{display:flex;justify-content:space-between;gap:18px;flex-wrap:wrap}.research-heading{align-items:flex-start}.actions{gap:9px}.eyebrow,.profile-label{font-size:.65rem;letter-spacing:.1em;color:#b6b2d8;margin:0 0 8px}h1{font-size:1.85rem;line-height:1.2;font-weight:700;margin:0}h2,h3{font-size:1rem;font-weight:650;margin:0}p{font-size:.8rem;line-height:1.65;color:#bac8df;margin:8px 0}button{padding:10px 13px;border:1px solid #64718d;border-radius:7px;background:#2b354d;color:#edf1fc;font-size:.78rem;font-weight:600;min-height:40px}button:hover:enabled{border-color:#b4b7ed;background:#3a4560}button:disabled{opacity:.5;cursor:not-allowed}.research-note{border-left:3px solid #9d94d4;background:#25273e;padding:11px 14px;margin:21px 0;font-size:.77rem}.demo-toggle{padding:5px 0;background:none;border:0;text-decoration:underline;text-underline-offset:4px;color:#ccc6ff;font-weight:400}.demo-notice{padding:14px 17px;background:#302b47;border:1px solid #a087c5;border-radius:8px;margin:13px 0 17px}.demo-notice strong{font-size:.85rem}.demo-notice p{color:#ddd0f6;margin-bottom:0}.panel{background:#1c2232;border:1px solid #45516a;border-radius:10px}.research-filters{padding:17px;display:flex;align-items:flex-end;flex-wrap:wrap;gap:14px;margin:20px 0}.research-filters label{display:grid;gap:7px;font-size:.73rem;color:#c0cce0;flex:1;min-width:140px}.research-filters input{width:100%;border:1px solid #596983;border-radius:6px;background:#272f44;color:#edf1fc;min-height:41px;padding:9px 10px;font-size:.8rem;color-scheme:dark}.research-filters input::placeholder{color:#aebbd1}.filter-error{color:#ffb9ad;flex-basis:100%;margin:0}.error{padding:15px 18px;display:flex;justify-content:space-between;gap:15px;align-items:center;border-color:#aa736e}.error p{color:#ffbdaf}.warning{padding:11px 14px;background:#3b3426;border:1px solid #7d6e4e;border-radius:7px;color:#eadbb9}.message{color:#addcc5}.profile-grid,.case-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.profile{padding:21px}.profile-description{font-size:.76rem;min-height:3.3em}.counts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px 8px;margin:21px 0}.counts dt,.outcomes dt{font-size:.7rem;line-height:1.5;color:#b4c2da}.counts dd{font-size:1.2rem;font-weight:650;margin:5px 0 0;font-variant-numeric:tabular-nums}.target-rate{border-top:1px solid #46516c;padding-top:17px}.target-rate>span{font-size:.74rem;color:#d1d9e9}.target-rate strong{display:block;font-size:1.9rem;color:#d4cbff;margin-top:7px;font-variant-numeric:tabular-nums}.target-rate p{font-size:.72rem;margin:2px 0 15px}.outcomes{display:grid;gap:13px}.outcomes dd{font-size:.79rem;font-variant-numeric:tabular-nums;margin:5px 0 0}.outcomes small{display:block;font-size:.67rem;color:#aebbd3;margin-top:5px;line-height:1.5}.method-note{font-size:.74rem;max-width:1130px;margin:17px 0 29px}.cases>header{align-items:center;margin:0 0 17px}.cases>header p{font-size:.73rem;margin:0}.case-group{margin-bottom:24px}.case-group>header{padding:12px 2px;align-items:center}.case-group h3 span{font-size:.74rem;font-weight:400;color:#b8c4dc;margin-left:9px}.case-group>header p{font-size:.71rem;margin:0;max-width:900px}.empty{padding:35px 20px;text-align:center;font-size:.84rem;color:#cad2e4}.empty p{font-size:.78rem}.methodology{padding:18px 20px;margin-top:25px}.methodology summary{cursor:pointer;font-size:.82rem;color:#d6d4ef}.methodology p{font-size:.75rem;margin-top:15px;max-width:1100px}button:focus-visible,input:focus-visible,a:focus-visible,summary:focus-visible{outline:2px solid #bcb0ff;outline-offset:3px}@media(max-width:1000px){.intraday-research{padding:25px 20px 45px}.profile{padding:18px}}@media(max-width:760px){.profile-grid,.case-grid{grid-template-columns:1fr}.profile-description{min-height:0}.case-group{border-top:1px solid #53607b;padding-top:12px;margin-top:25px}.case-group>header{display:block}.case-group>header p{margin-top:7px}.profile-grid{gap:11px}}@media(max-width:480px){.intraday-research{padding:22px 14px 40px}.actions{width:100%}.actions button{flex:1}.research-filters{padding:14px;gap:12px}.research-filters label{min-width:120px}.research-filters button{width:100%}.error{align-items:flex-start;flex-direction:column}.research-nav{gap:15px;font-size:.76rem}}
</style>
