<script setup>
import { inject } from 'vue'
import { WALL_ALERTS } from '@/Support/wallAlerts'
import { wallActivityDashboardUrl } from '@/Pages/Scanner/scannerUtils'
import { wallSetupTitle } from '@/utils/wallDecision'
const alerts = inject(WALL_ALERTS, null)
const number = value => Number.isFinite(value) ? value.toLocaleString('en-US', { maximumFractionDigits: 6 }) : '—'
const time = value => new Date(value).toLocaleTimeString('en-US', { timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit' })
const hasLevels = item => item.status === 'conditions_met'
  && [item.setup?.entry_zone?.low, item.setup?.entry_zone?.high, item.setup?.invalidation?.price,
    item.setup?.target?.strike].every(value => Number.isFinite(value) && value > 0)
  && item.setup.entry_zone.low < item.setup.entry_zone.high
  && ['above', 'below'].includes(item.setup.invalidation.close_side)
</script>

<template>
  <template v-if="alerts">
    <a v-if="alerts.settings.value.enabled" class="wall-alert-indicator" href="/scanner?scan=activity">{{ alerts.error.value ? 'Wall alerts paused' : 'Wall alerts on' }}</a>
    <Teleport to="body">
      <div :class="['wall-alert-notifications', { 'has-alerts': alerts.notifications.value.length }]" aria-live="polite" aria-relevant="additions" aria-label="Wall alerts">
        <article v-for="item in alerts.notifications.value" :key="item.alert_id" class="wall-alert-toast" :class="{ testing: item.status === 'testing' }" :aria-label="item.preview ? 'Test wall alert' : `${item.symbol} wall alert`">
          <button type="button" class="dismiss" :aria-label="`Dismiss ${item.preview ? 'test' : item.symbol} wall alert`" @click="alerts.dismiss(item.alert_id)">×</button>
          <strong>{{ item.preview ? 'Test alert · ' : '' }}{{ item.symbol }} · {{ item.status === 'conditions_met' ? wallSetupTitle(item.setup) : 'Testing a wall' }}</strong>
          <p v-if="item.preview" class="example">Example notification. No market event.</p>
          <p class="wall-alert-meta">{{ item.side === 'put' ? 'Put' : 'Call' }} wall {{ number(item.strike) }} · <time :datetime="item.observed_at">{{ time(item.observed_at) }} ET</time></p>
          <template v-if="hasLevels(item)">
            <p class="assessment-status">Conditions met at recorded close {{ number(item.close) }}</p>
            <dl aria-label="Recorded setup levels">
              <div><dt>Entry area</dt><dd>{{ number(item.setup.entry_zone.low) }}–{{ number(item.setup.entry_zone.high) }}</dd></div>
              <div><dt>Invalid if 5m close</dt><dd>{{ item.setup.invalidation.close_side }} {{ number(item.setup.invalidation.price) }}</dd></div>
              <div><dt>Reference target</dt><dd>{{ number(item.setup.target.strike) }}</dd></div>
            </dl>
            <p class="context">{{ item.preview ? 'Synthetic underlying prices for preview only.' : 'Underlying prices · delayed at least 15 min. Check current price against the entry area.' }}</p>
          </template>
          <p v-else class="context">{{ item.status === 'conditions_met' ? 'Conditions passed at the recorded close. View the assessment for its levels.' : 'Entry confirmation is pending. A wall test alone does not confirm a setup.' }}</p>
          <a :href="item.preview ? '/scanner?scan=activity' : wallActivityDashboardUrl(item)">{{ item.preview ? 'Open Wall activity' : 'View assessment' }} <span aria-hidden="true">→</span></a>
        </article>
      </div>
    </Teleport>
  </template>
</template>

<style scoped>
.wall-alert-notifications{pointer-events:none}.wall-alert-notifications.has-alerts{pointer-events:auto}
.wall-alert-meta{font-size:.76rem;color:#c0cfe3}.assessment-status{font-size:.75rem;color:#a5e5c4}.example{font-size:.75rem;color:#edda98}dl{display:grid;gap:8px;margin:12px 0;padding:12px 0;border-block:1px solid #3a4b61}dl div{display:flex;justify-content:space-between;align-items:baseline;gap:12px}dt{font-size:.73rem;color:#bac9dc;flex-shrink:0}dd{margin:0;text-align:right;font-size:.8rem;font-weight:650;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.wall-alert-indicator{font-size:.73rem;color:#b5d6ff;padding:5px 8px;border:1px solid #43536a;border-radius:6px;white-space:nowrap;text-decoration:none}.wall-alert-notifications{position:fixed;right:20px;bottom:20px;z-index:55;width:min(390px,calc(100vw - 28px));max-height:calc(100dvh - 110px);overflow-y:auto;overscroll-behavior:contain;display:grid;gap:10px;padding:4px;scrollbar-width:thin}.wall-alert-toast{position:relative;padding:17px 35px 17px 17px;border:1px solid #536382;border-left:3px solid #7ee0b0;border-radius:11px;background:#1c2433;color:#edf3fc;box-shadow:0 12px 35px #0008;min-width:0}.wall-alert-toast.testing{border-left-color:#b5bcff}strong{font-size:.9rem;display:block}p{font-size:.8rem;color:#d5e1f2;margin:8px 0;line-height:1.5}.context{font-size:.73rem;color:#bac9dc}a{font-size:.8rem;color:#b5d6ff}.dismiss{position:absolute;top:7px;right:7px;width:28px;height:28px;color:#c4d2e6;background:transparent;border:0;font-size:1.2rem;cursor:pointer}a:focus-visible,button:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}@media(max-width:640px){.wall-alert-notifications{right:14px;bottom:14px}.wall-alert-indicator{font-size:.65rem;padding:4px}}
</style>
