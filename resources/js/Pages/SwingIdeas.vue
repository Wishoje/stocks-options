<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  items: { type: Array, default: () => [] }, prepared: Boolean, horizon: String, horizons: Object,
  scope: { type: String, default: 'universe' }, symbolCount: Number, completedSession: String,
  evaluatedAt: String, summary: Object, demonstration: Boolean, localDemoAvailable: Boolean,
})
const status = ref('all'), symbol = ref(''), direction = ref('all'), strategy = ref('all')
const refreshing = ref(false), refreshFailed = ref(false)
const statusOptions = [['all', 'All ideas'], ['ready', 'Ready'], ['entry_touched', 'Entry touched'], ['watching', 'Watching']]
const active = ['watching', 'ready', 'entry_touched']
const stateOf = row => row.status || row.status_group
const rows = computed(() => props.items.filter(row => row && active.includes(stateOf(row))
  && ['hold_reclaim', 'break_retest'].includes(row.pattern) && row.levels))
const matching = computed(() => rows.value.filter(row => (!symbol.value.trim()
  || row.symbol.toUpperCase().includes(symbol.value.trim().toUpperCase()))
  && (direction.value === 'all' || row.direction === direction.value)
  && (strategy.value === 'all' || row.pattern === strategy.value)))
const visible = computed(() => matching.value.filter(row => status.value === 'all' || stateOf(row) === status.value))
const count = key => matching.value.filter(row => key === 'all' || stateOf(row) === key).length
const number = value => Number.isFinite(value) ? value.toLocaleString('en-US', { maximumFractionDigits: 2 }) : '—'
const exposure = value => !Number.isFinite(value) ? '—' : new Intl.NumberFormat('en-US', {
  notation: 'compact', maximumFractionDigits: 2, signDisplay: 'exceptZero',
}).format(value)
const instant = value => {
  if (typeof value !== 'string' || !Number.isFinite(Date.parse(value))) return '—'
  return `${new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', month: 'short', day: 'numeric',
    hour: 'numeric', minute: '2-digit' }).format(new Date(value))} ET`
}
const label = row => ({ watching: 'Watching', ready: 'Ready', entry_touched: 'Entry touched' })[stateOf(row)]
const patternLabel = row => row.pattern === 'break_retest' ? 'Wall break & retest' : 'Wall reclaim'
const nextStep = row => {
  const supplied = typeof row.next_condition === 'string' ? row.next_condition : row.next_condition?.text
  if (supplied) return supplied
  if (stateOf(row) === 'entry_touched') return 'A later price reached the entry area. Follow the target, stop and holding limit below.'
  if (stateOf(row) === 'ready') return 'The wall reaction is confirmed. Watch for a later price touch inside the entry area.'
  return row.pattern === 'break_retest'
    ? `Watch for price to break ${number(row.levels.wall)}, stay beyond it, then retest and move away again.`
    : `Watch for price to test ${number(row.levels.wall)} and recover ${row.direction === 'bullish' ? 'above' : 'below'} the wall.`
}
const why = row => row.why || `This is a ranked options wall${Number.isFinite(row.evidence?.wall_relative_gex)
  ? ` with ${number(row.evidence.wall_relative_gex)}× the exposure of nearby strikes` : ''}.${Number.isFinite(row.evidence?.target_room_ratio)
  ? ` The target offers ${number(row.evidence.target_room_ratio)}× room versus the stop distance.` : ''}`
const observedPrice = row => Number.isFinite(row.latest_price?.value) && row.latest_price.value > 0
  && typeof row.latest_price.at === 'string' && Number.isFinite(Date.parse(row.latest_price.at))
const visit = selection => router.get('/swing-ideas', {
  horizon: props.horizon, scope: props.scope, ...(props.demonstration ? { demo: 1 } : {}), ...selection,
}, { preserveScroll: true })
let timer = null, cancelToken = null
const refresh = () => {
  if (props.demonstration || refreshing.value || document.visibilityState === 'hidden' || typeof router.reload !== 'function') return
  refreshing.value = true
  refreshFailed.value = false
  router.reload({ only: ['items', 'evaluatedAt', 'summary'], preserveScroll: true, preserveState: true,
    onCancelToken: token => { cancelToken = token },
    onError: () => { refreshFailed.value = true },
    onFinish: () => { refreshing.value = false; cancelToken = null },
  })
}
watch(() => [props.horizon, props.scope], () => {
  status.value = 'all'; symbol.value = ''; direction.value = 'all'; strategy.value = 'all'
})
onMounted(() => { timer = setInterval(refresh, 60_000) })
onBeforeUnmount(() => { clearInterval(timer); cancelToken?.cancel?.() })
</script>

<template>
  <AppLayout title="Swing ideas">
    <main class="swing-page">
      <header class="swing-heading">
        <div><p class="eyebrow">MULTI-DAY WALL REACTIONS</p><h1>Swing ideas</h1><p>Find a major options level. Follow the reclaim or the break and retest.</p></div>
        <Link href="/scanner?scan=activity">Looking for intraday ideas? Open Scanner →</Link>
      </header>
      <div v-if="demonstration" class="swing-intro" role="status"><strong>Local demonstration · Synthetic examples</strong><p>These examples are never saved or sent as alerts.</p><Link :href="`/swing-ideas?horizon=${horizon}&scope=${scope}`">Back to ideas →</Link></div>
      <p v-else-if="localDemoAvailable"><Link :href="`/swing-ideas?horizon=${horizon}&scope=${scope}&demo=1`">Preview local idea examples →</Link></p>
      <nav class="swing-universe" aria-label="Swing symbol coverage">
        <button type="button" :aria-pressed="scope === 'universe'" @click="visit({ scope: 'universe' })">All symbols</button>
        <button type="button" :aria-pressed="scope === 'watchlist'" @click="visit({ scope: 'watchlist' })">My watchlist</button>
      </nav>
      <p class="swing-coverage">{{ scope === 'watchlist' ? 'Ideas for your saved symbols.' : 'Ideas across all symbols followed by GexOptions.' }}{{ Number.isInteger(symbolCount) ? ` ${symbolCount} symbols in this view.` : '' }}</p>
      <nav class="swing-horizons" aria-label="Swing holding period">
        <button v-for="(profile, key) in horizons" :key="key" type="button" :aria-pressed="horizon === key" @click="visit({ horizon: key })">{{ profile.label }}</button>
      </nav>
      <div class="swing-update-row">
        <p class="swing-scope">Holding period: {{ horizons?.[horizon]?.label }} · {{ horizons?.[horizon]?.scope_label }} options context · Prices delayed at least 15 minutes</p>
        <button v-if="!demonstration" type="button" class="swing-refresh" :disabled="refreshing" @click="refresh">{{ refreshing ? 'Refreshing…' : 'Refresh ideas' }}</button>
      </div>
      <p v-if="evaluatedAt" class="swing-updated">Last assessment <time :datetime="evaluatedAt">{{ instant(evaluatedAt) }}</time></p>
      <p v-if="refreshFailed" class="swing-updated" role="status">The refresh could not finish. Your current view is still here; try again.</p>
      <div class="swing-intro"><strong>A wall is the starting point. The reaction determines the idea.</strong><p>Watching: wait for the price sequence. Ready: the reaction was confirmed. Entry touched: a later observed price reached the entry area. Check current price before acting.</p></div>
      <div class="swing-filters">
        <div class="swing-search">
          <label>Symbol<input v-model="symbol" type="search" placeholder="Search symbols" maxlength="16"></label>
          <label>Direction<select v-model="direction"><option value="all">Both directions</option><option value="bullish">Bullish</option><option value="bearish">Bearish</option></select></label>
          <label>Strategy<select v-model="strategy"><option value="all">Both strategies</option><option value="hold_reclaim">Wall reclaim</option><option value="break_retest">Wall break &amp; retest</option></select></label>
        </div>
        <div class="swing-status-filters" role="group" aria-label="Swing idea status">
          <button v-for="[key, text] in statusOptions" :key="key" type="button" :aria-pressed="status === key" @click="status = key">{{ text }} · {{ count(key) }}</button>
        </div>
      </div>
      <div v-if="!prepared" class="swing-empty"><h2>Ideas are being prepared.</h2><p>The next assessment will add qualifying wall reactions.<template v-if="scope === 'watchlist'"> Choose All symbols to explore beyond your watchlist.</template></p><Link href="/scanner?scan=activity">Open intraday Scanner →</Link></div>
      <div v-else-if="!rows.length" class="swing-empty" role="status"><h2>No active swing ideas right now.</h2><p>{{ scope === 'watchlist' ? 'Choose All symbols to explore beyond your watchlist, or try another holding period.' : 'Try another holding period. New ideas appear when a major wall has suitable entry and target levels.' }}</p></div>
      <p v-else-if="!visible.length" class="swing-empty" role="status">No ideas match these filters. Your selected filters stay in place.</p>
      <div v-else class="swing-grid">
        <article v-for="idea in visible" :key="idea.id" class="swing-card" :class="`swing-card--${stateOf(idea)}`">
          <header><div><span class="swing-symbol">{{ idea.symbol }}</span><span :class="idea.direction">{{ idea.direction === 'bullish' ? 'Bullish' : 'Bearish' }}</span></div><span class="swing-status" :class="`swing-status--${stateOf(idea)}`">{{ label(idea) }}</span></header>
          <h2 class="swing-strategy">{{ patternLabel(idea) }}</h2>
          <p class="swing-wall">{{ idea.side === 'call' ? 'Call' : 'Put' }} wall <strong>{{ number(idea.levels.wall) }}</strong></p>
          <p v-if="observedPrice(idea)" class="swing-latest-price">Delayed price <strong>{{ number(idea.latest_price.value) }}</strong> · <time :datetime="idea.latest_price.at">{{ instant(idea.latest_price.at) }}</time></p>
          <div class="swing-trigger"><span>{{ stateOf(idea) === 'entry_touched' ? 'What to follow' : 'What to watch next' }}</span><p>{{ nextStep(idea) }}</p></div>
          <dl class="swing-levels">
            <div><dt>Entry area after confirmation</dt><dd>{{ number(idea.levels.entry_low) }}–{{ number(idea.levels.entry_high) }}</dd></div>
            <div><dt>Target</dt><dd class="bullish">{{ number(idea.levels.target) }}</dd></div>
            <div><dt>Stop level · price touch</dt><dd class="bearish">{{ number(idea.levels.invalidation) }}</dd></div>
            <div v-if="stateOf(idea) === 'entry_touched'"><dt>Holding limit</dt><dd>{{ instant(idea.holding_deadline) }}</dd></div>
            <div v-else><dt>Entry deadline</dt><dd>{{ instant(idea.entry_deadline) }}</dd></div>
          </dl>
          <p v-if="idea.entry_at" class="swing-event">Entry touch recorded {{ instant(idea.entry_at) }}<template v-if="Number.isFinite(idea.entry_price)"> · {{ number(idea.entry_price) }}</template></p>
          <p v-else-if="idea.qualified_at" class="swing-event">Reaction confirmed {{ instant(idea.qualified_at) }}</p>
          <div class="swing-why"><h3>Why this level</h3><p>{{ why(idea) }}</p></div>
          <details class="swing-evidence"><summary>View the supporting numbers</summary>
            <dl>
              <div><dt>Level review</dt><dd>{{ idea.source_date }}</dd></div>
              <div v-if="Number.isFinite(idea.evidence?.wall_relative_gex)"><dt>GEX versus nearby strikes</dt><dd>{{ number(idea.evidence.wall_relative_gex) }}×</dd></div>
              <div v-if="Number.isFinite(idea.evidence?.target_room_ratio)"><dt>Target room / stop distance</dt><dd>{{ number(idea.evidence.target_room_ratio) }}×</dd></div>
              <div v-if="Number.isFinite(idea.evidence?.atr14)"><dt>Average daily range · ATR14</dt><dd>{{ number(idea.evidence.atr14) }}</dd></div>
              <div v-if="Number.isFinite(idea.evidence?.net_gex)"><dt>Net GEX · USD / 1% move</dt><dd>{{ exposure(idea.evidence.net_gex) }}</dd></div>
              <div v-if="Number.isFinite(idea.evidence?.dex_shares)"><dt>DEX · share equivalents</dt><dd>{{ exposure(idea.evidence.dex_shares) }}</dd></div>
              <div v-if="Number.isFinite(idea.evidence?.target_rolloff_pct)"><dt>Target exposure expiring during the idea</dt><dd>{{ number(idea.evidence.target_rolloff_pct) }}%</dd></div>
            </dl><p>The wall identifies concentrated exposure. The price sequence confirms the reaction; exposure alone does not establish direction.</p>
          </details>
          <p class="swing-events">Check earnings and major events before holding overnight.</p>
          <footer><Link :href="`/dashboard?symbol=${encodeURIComponent(idea.symbol)}&mode=eod&tab=positioning&timeframe=${idea.scope_label === '3M' ? '90d' : '30d'}&view=latest_eod`">Explore the wall →</Link><Link :href="`/dashboard?symbol=${encodeURIComponent(idea.symbol)}&mode=intraday&tab=walls&timeframe=7d`">See the price reaction →</Link></footer>
        </article>
      </div>
      <details class="swing-guide"><summary>How to use Swing ideas</summary>
        <p><strong>Wall reclaim:</strong> price tests a major wall and recovers to the other side. <strong>Wall break &amp; retest:</strong> price moves beyond the wall, stays there, then retests and moves away again. Bullish and bearish ideas use the matching direction of the reaction.</p>
        <p>Entry, stop and target tracking use observed price ranges. A daily closing price is not required. Entry touched records a price event, not an executed trade. A gap across the entry area does not establish an entry.</p>
        <p>Shorter ideas allow three trading sessions for entry and up to five after an entry touch. Longer ideas allow five trading sessions for entry and up to fifteen after a touch. The card shows the applicable deadline.</p>
        <p>Completed ideas leave the active list. Ideas also leave when the opportunity passes before entry or the entry window ends. Original levels stay fixed so later changes do not rewrite the idea.</p>
      </details>
    </main>
  </AppLayout>
</template>

<style scoped>
.swing-page{max-width:1440px;margin:auto;padding:28px 24px 64px;color:#eaf0fb}.swing-heading,.swing-card header,.swing-update-row{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}.swing-heading{align-items:start}.eyebrow{font-size:.7rem;letter-spacing:.12em;color:#91b9e9;margin:0 0 8px}h1{font-size:1.8rem;font-weight:700}p{font-size:.86rem;line-height:1.65;color:#becce0;margin:8px 0}a{color:#b5caff;font-size:.82rem;line-height:1.6}.swing-horizons,.swing-universe{display:flex;gap:8px;margin:22px 0 10px;flex-wrap:wrap}button{padding:10px 15px;border:1px solid #52617e;border-radius:8px;background:#252e43;color:#e3eaff;min-height:40px;cursor:pointer}button[aria-pressed=true]{background:#3b456a;border-color:#b7b9ff}button:disabled{opacity:.6;cursor:wait}.swing-coverage,.swing-scope,.swing-updated{font-size:.76rem}.swing-refresh{font-size:.75rem;padding:7px 12px;min-height:35px}.swing-intro{background:#242b41;border-left:3px solid #a9aaff;border-radius:8px;padding:16px 20px;margin:22px 0}.swing-intro strong{font-size:.9rem}.swing-intro p{margin-bottom:0}.swing-search,.swing-status-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.swing-search{margin-bottom:12px}.swing-search label{display:grid;gap:5px;font-size:.75rem;color:#becce0}.swing-search input,.swing-search select{min-height:40px;padding:8px 11px;background:#1b2131;color:#eaf0fb;border:1px solid #52617e;border-radius:7px;max-width:100%;width:190px}.swing-status-filters button{font-size:.78rem}.swing-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:22px}.swing-card{border:1px solid #414f68;border-radius:12px;background:#1b2131;padding:22px;min-width:0}.swing-card--ready{border-top:3px solid #91dfb4}.swing-card--entry_touched{border-top:3px solid #b5b7ff}.swing-card header>div{display:flex;gap:12px;align-items:center;flex-wrap:wrap;font-size:.8rem}.swing-symbol{font-size:1.3rem;font-weight:750}.bullish{color:#91dfb4}.bearish{color:#efa48e}.swing-status{font-size:.74rem;border:1px solid #52617e;border-radius:20px;padding:5px 10px;background:#252e43;white-space:nowrap}.swing-status--ready{color:#a7e5c5;border-color:#5d927c}.swing-status--entry_touched{color:#c5c6ff;border-color:#7c7eb0}.swing-strategy{font-weight:650;font-size:1rem;margin:18px 0 5px}.swing-wall{margin:0;font-size:.85rem}.swing-wall strong{font-size:1.05rem;color:#eaf0fb;margin-left:5px}.swing-latest-price{font-size:.76rem}.swing-trigger{background:#252e43;border-radius:7px;padding:13px 16px;margin:18px 0}.swing-trigger>span{font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:#b7c7e6}.swing-trigger p{color:#e0e8fa;margin-bottom:0}.swing-levels{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:20px 0;padding-bottom:20px;border-bottom:1px solid #3b4961}dt{font-size:.73rem;color:#becce0}dd{font-size:1rem;font-weight:650;margin:5px 0 0;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}.swing-why h3{font-size:.8rem;font-weight:650}.swing-why p{font-size:.8rem}.swing-event{font-size:.75rem;color:#c8d4fc}.swing-evidence,.swing-guide{margin-top:20px;border-top:1px solid #3b4961;padding-top:16px}.swing-evidence summary,.swing-guide summary{cursor:pointer;font-size:.82rem;color:#d6e5ff;padding-block:5px}.swing-evidence dl{display:grid;gap:12px;margin:16px 0}.swing-evidence dl div{display:flex;justify-content:space-between;align-items:baseline;gap:14px}.swing-evidence dd{font-size:.8rem;text-align:right}.swing-evidence p,.swing-events{font-size:.74rem}.swing-events{margin-top:18px}.swing-card footer{display:flex;gap:16px;flex-wrap:wrap;margin-top:18px}.swing-empty{border:1px solid #43516b;border-radius:10px;padding:26px;margin-top:22px;background:#1b2131}.swing-empty h2{font-size:1.1rem;font-weight:650}.swing-guide{padding:20px;background:#1b2131;border-radius:8px;margin-top:28px}button:focus-visible,a:focus-visible,summary:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}@media(max-width:900px){.swing-grid{grid-template-columns:1fr}}@media(max-width:550px){.swing-page{padding:22px 14px 40px}.swing-card{padding:17px}.swing-levels{gap:15px}.swing-horizons button,.swing-universe button{padding:10px 13px}.swing-search label{flex:1;min-width:125px}.swing-search input,.swing-search select{width:100%}.swing-evidence dl div{align-items:start;flex-direction:column;gap:2px}.swing-evidence dd{text-align:left}}
</style>
