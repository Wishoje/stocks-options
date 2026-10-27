<script setup>
import { computed, inject, nextTick, onScopeDispose, ref, watch } from 'vue'
import { WALL_ALERTS } from '@/Support/wallAlerts'
import WallAlertDetails from '@/Components/WallAlertDetails.vue'
import WallStatusFilters from '@/Components/WallStatusFilters.vue'
import { activeWallRows, filterWallRows, wallInboxRows } from '@/Support/wallLifecycle'

const alerts = inject(WALL_ALERTS)
const open = ref(false), trigger = ref(null), panel = ref(null), closeButton = ref(null)
const selectedStatus = ref('all'), symbol = ref(''), direction = ref('all'), unread = ref(false)
const entries = computed(() => activeWallRows(wallInboxRows(alerts.history.value, alerts.activity?.value, alerts.clock?.value || Date.now(), !!alerts.error.value)))
const visibleEntries = computed(() => filterWallRows(entries.value, { status: selectedStatus.value, symbol: symbol.value, direction: direction.value, unread: unread.value }))
const example = computed(() => alerts.notifications.value.find(item => item.preview))
const status = computed(() => !alerts.settings.value.enabled ? 'Alerts off' : alerts.error.value ? 'Updates paused' : 'Alerts on')
let previousOverflow = ''

async function show() {
  if (open.value) return
  previousOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  open.value = true
  await nextTick()
  closeButton.value?.focus()
}
function close(restoreFocus = true) {
  if (!open.value) return
  open.value = false
  document.body.style.overflow = previousOverflow
  if (restoreFocus) nextTick(() => trigger.value?.focus())
}
function openAssessment(item) {
  if (!item.preview) alerts.markRead(item.alert_id)
  close(false)
}
function keydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault(); event.stopPropagation(); close(); return
  }
  if (event.key !== 'Tab') return
  const elements = [...(panel.value?.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex="0"]') || [])]
  const first = elements[0], last = elements.at(-1), active = document.activeElement
  if (event.shiftKey && (active === first || !panel.value?.contains(active))) {
    event.preventDefault(); last?.focus()
  } else if (!event.shiftKey && (active === last || !panel.value?.contains(active))) {
    event.preventDefault(); first?.focus()
  }
}
watch(alerts.owner, () => { close(false); selectedStatus.value = 'all'; symbol.value = ''; direction.value = 'all'; unread.value = false })
onScopeDispose(() => close(false))
</script>

<template>
  <button ref="trigger" type="button" class="wall-inbox-trigger" aria-haspopup="dialog" aria-controls="wall-alert-inbox"
    :aria-expanded="open" :aria-label="`Recent alerts${alerts.unreadCount.value ? `, ${alerts.unreadCount.value} unread` : ''}`" @click="show">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg>
    <span class="wall-inbox-label">Alerts</span>
    <span v-if="alerts.unreadCount.value" class="wall-inbox-badge" aria-hidden="true">{{ alerts.unreadCount.value > 99 ? '99+' : alerts.unreadCount.value }}</span>
  </button>
  <Teleport to="body">
    <div v-if="open" class="wall-inbox-overlay" @keydown="keydown">
      <div class="wall-inbox-backdrop" aria-hidden="true" @click="close()" />
      <section id="wall-alert-inbox" ref="panel" class="wall-inbox-panel" role="dialog" aria-modal="true" aria-labelledby="wall-inbox-title" tabindex="-1">
        <header>
          <div><p class="eyebrow">Today · Eastern time</p><h2 id="wall-inbox-title">Recent alerts</h2></div>
          <button ref="closeButton" type="button" class="close" aria-label="Close recent alerts" @click="close()">×</button>
        </header>
        <div class="wall-inbox-body">
          <div class="wall-inbox-tools"><span :class="['monitoring', { off: !alerts.settings.value.enabled || alerts.error.value }]">{{ status }}</span>
            <button v-if="alerts.unreadCount.value" type="button" @click="alerts.markRead()">Mark all read</button></div>
          <p class="intro">Follow today’s developing and active setups. Open an assessment to check its recorded levels against current price.</p>
          <WallStatusFilters v-model="selectedStatus" v-model:symbol="symbol" v-model:direction="direction" v-model:unread="unread" :rows="entries" inbox current-only />
          <p v-if="!alerts.inboxPersistent.value" class="storage-note" role="status">Your browser cannot save alerts. They will stay here for this visit.</p>
          <p v-if="!alerts.settings.value.enabled" class="notice">New alerts are turned off. Your saved alerts remain below. <a href="/scanner?scan=activity">Manage alerts in Scanner</a></p>
          <p v-else-if="alerts.error.value" class="notice" role="status">New alerts are paused until wall activity updates. Saved alerts remain available.</p>
          <p v-if="entries.length && !visibleEntries.length" class="notice" role="status">No alerts match these filters. Your selection stays in place as updates arrive.</p>
          <ol v-if="visibleEntries.length" class="wall-inbox-list" aria-label="Today's recorded wall alerts">
            <li v-for="item in visibleEntries" :key="item.alert_id" :class="['wall-inbox-item', { unread: !item.read_at }]">
              <span v-if="!item.read_at" class="unread-label">Unread</span>
              <WallAlertDetails :item="item" archived @open="openAssessment(item)" />
            </li>
          </ol>
          <div v-else-if="!entries.length" class="wall-inbox-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h16v16H4zM4 14h5l1 2h4l1-2h5M8 8h8M8 11h5" /></svg>
            <h3>{{ alerts.history.value.length ? 'No active setup alerts' : 'No alerts received today' }}</h3><p>New setup alerts appear here while their scenarios are developing or in progress. Keep the site open to receive them.</p></div>
          <article v-if="example" class="wall-inbox-example" aria-label="Test wall alert example"><WallAlertDetails :item="example" @open="openAssessment(example)" /></article>
          <footer><a href="/scanner?scan=activity">Open Wall activity</a><button v-if="alerts.settings.value.enabled" type="button" @click="alerts.preview()">Preview example</button></footer>
          <p class="retention">Up to 100 alerts · Resets at midnight ET. Viewing a saved assessment marks it as read.</p>
        </div>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.wall-inbox-trigger{position:relative;display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:42px;padding:7px 10px;border:1px solid #43536a;border-radius:8px;background:#1c2433;color:#d9e6fa;font-size:.78rem;white-space:nowrap;cursor:pointer}.wall-inbox-trigger svg{width:18px;height:18px}.wall-inbox-badge{padding:2px 5px;border-radius:8px;background:#b5bcff;color:#151b29;font-size:.66rem;font-weight:750;min-width:18px}.wall-inbox-overlay{position:fixed;inset:0;z-index:120;display:flex;justify-content:flex-end}.wall-inbox-backdrop{position:absolute;inset:0;background:#0009}.wall-inbox-panel{position:relative;display:flex;flex-direction:column;width:min(470px,100%);height:100vh;height:100dvh;background:#171d29;border-left:1px solid #43536a;color:#edf3fc;box-shadow:-18px 0 60px #0006;min-width:0}.wall-inbox-panel header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:22px;border-bottom:1px solid #39485d;flex-shrink:0}.eyebrow{font-size:.7rem;color:#b7c6db;margin:0 0 5px}h2{font-size:1.2rem;font-weight:700;margin:0}.wall-inbox-panel button{border:1px solid #536382;border-radius:7px;background:#26334a;color:#edf3fc;padding:7px 10px;font-size:.76rem;cursor:pointer}.wall-inbox-panel .close{width:38px;height:38px;padding:0;font-size:1.4rem;flex-shrink:0}.wall-inbox-body{padding:20px 22px;overflow-y:auto;overscroll-behavior:contain;min-height:0;scrollbar-width:thin}.wall-inbox-tools{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:32px}.monitoring{color:#a5e5c4;font-size:.75rem}.monitoring.off{color:#c7cddc}.intro,.notice,.retention,.storage-note{font-size:.76rem;line-height:1.6;color:#bac9dc;margin:12px 0 18px}.notice,.storage-note{padding:10px;border:1px solid #43536a;border-radius:7px}.wall-inbox-list{list-style:none;display:grid;gap:12px;padding:0;margin:18px 0}.wall-inbox-item,.wall-inbox-example{position:relative;min-width:0;padding:16px;border:1px solid #3b495f;border-radius:9px;background:#1c2433}.wall-inbox-item.unread{border-left:3px solid #b5bcff}.unread-label{display:block;color:#c6caff;font-size:.66rem;font-weight:650;margin-bottom:7px}.wall-inbox-example{border:1px dashed #8e9dbe;margin-top:16px}.wall-inbox-empty{padding:36px 6px;text-align:center}.wall-inbox-empty svg{width:34px;height:34px;color:#91a6c3;margin:0 auto 14px}.wall-inbox-empty h3{font-weight:650;font-size:.95rem;margin:0 0 8px}.wall-inbox-empty p{font-size:.8rem;line-height:1.6;color:#bac9dc;margin:0 auto;max-width:300px}footer{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding-top:16px;border-top:1px solid #39485d}a{color:#b5d6ff;font-size:.8rem;display:inline-flex;align-items:center;min-height:36px}.retention{font-size:.69rem;margin:14px 0 0}button:hover{border-color:#a6b4e9;background:#32415c}button:focus-visible,a:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}@media(max-width:600px){.wall-inbox-label{display:none}.wall-inbox-trigger{min-width:42px;padding:7px}.wall-inbox-body{padding:16px}.wall-inbox-panel header{padding:18px 16px}.wall-inbox-item,.wall-inbox-example{padding:14px}}
</style>
