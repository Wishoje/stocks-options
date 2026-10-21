<script setup>
import { inject } from 'vue'
import { WALL_ALERTS } from '@/Support/wallAlerts'
import { wallActivityDashboardUrl } from '@/Pages/Scanner/scannerUtils'
const alerts = inject(WALL_ALERTS, null)
const number = value => Number(value).toLocaleString('en-US', { maximumFractionDigits: 3 })
const time = value => new Date(value).toLocaleTimeString('en-US', { timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit' })
</script>

<template>
  <template v-if="alerts">
    <a v-if="alerts.settings.value.enabled" class="wall-alert-indicator" href="/scanner?scan=activity">{{ alerts.error.value ? 'Wall alerts paused' : 'Wall alerts on' }}</a>
    <Teleport to="body">
      <div class="wall-alert-notifications" aria-live="polite" aria-relevant="additions" aria-label="Wall alerts">
        <article v-for="item in alerts.notifications.value" :key="item.alert_id" class="wall-alert-toast" :class="{ testing: item.status === 'testing' }" :aria-label="item.preview ? 'Test wall alert' : `${item.symbol} wall alert`">
          <button type="button" class="dismiss" :aria-label="`Dismiss ${item.preview ? 'test' : item.symbol} wall alert`" @click="alerts.dismiss(item.alert_id)">×</button>
          <strong>{{ item.preview ? 'Test alert · SPY' : `${item.symbol} · ${item.status === 'conditions_met' ? 'Setup conditions met' : 'Testing a wall'}` }}</strong>
          <p v-if="item.preview">Example notification. No market event.</p>
          <template v-else><p>{{ item.side === 'put' ? 'Put' : 'Call' }} wall {{ number(item.strike) }} · {{ time(item.observed_at) }} ET</p><p class="context">{{ item.status === 'conditions_met' ? 'Check current price against the recorded entry area.' : 'A wall test is an area to watch. Entry confirmation is pending.' }}</p></template>
          <a :href="item.preview ? '/scanner?scan=activity' : wallActivityDashboardUrl(item)">{{ item.preview ? 'Open Wall activity' : 'Open chart' }} <span aria-hidden="true">→</span></a>
        </article>
      </div>
    </Teleport>
  </template>
</template>

<style scoped>
.wall-alert-indicator{font-size:.73rem;color:#b5d6ff;padding:5px 8px;border:1px solid #43536a;border-radius:6px;white-space:nowrap;text-decoration:none}.wall-alert-notifications{position:fixed;right:20px;bottom:20px;z-index:55;width:min(370px,calc(100vw - 28px));display:grid;gap:10px;pointer-events:none}.wall-alert-toast{position:relative;padding:17px 35px 17px 17px;border:1px solid #536382;border-left:3px solid #7ee0b0;border-radius:11px;background:#1c2433;color:#edf3fc;box-shadow:0 12px 35px #0008;pointer-events:auto}.wall-alert-toast.testing{border-left-color:#b5bcff}strong{font-size:.9rem;display:block}p{font-size:.8rem;color:#d5e1f2;margin:8px 0;line-height:1.5}.context{font-size:.73rem;color:#bac9dc}a{font-size:.8rem;color:#b5d6ff}.dismiss{position:absolute;top:7px;right:7px;width:28px;height:28px;color:#c4d2e6;background:transparent;border:0;font-size:1.2rem;cursor:pointer}a:focus-visible,button:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}@media(max-width:640px){.wall-alert-notifications{right:14px;bottom:14px}.wall-alert-indicator{font-size:.65rem;padding:4px}}
</style>
