<script setup>
import { inject } from 'vue'
import { WALL_ALERTS } from '@/Support/wallAlerts'
import WallAlertDetails from '@/Components/WallAlertDetails.vue'
import WallAlertInbox from '@/Components/WallAlertInbox.vue'
const alerts = inject(WALL_ALERTS, null)
</script>

<template>
  <template v-if="alerts">
    <WallAlertInbox v-if="alerts.owner.value" />
    <Teleport to="body">
      <div :class="['wall-alert-notifications', { 'has-alerts': alerts.notifications.value.length }]" aria-live="polite" aria-relevant="additions" aria-label="Wall alerts">
        <article v-for="item in alerts.notifications.value" :key="item.alert_id" class="wall-alert-toast" :class="{ testing: item.status === 'testing' }" :aria-label="item.preview ? 'Test wall alert' : `${item.symbol} wall alert`">
          <button type="button" class="dismiss" :aria-label="`Dismiss ${item.preview ? 'test' : item.symbol} wall alert`" @click="alerts.dismiss(item.alert_id)">×</button>
          <WallAlertDetails :item="item" @open="!item.preview && alerts.markRead(item.alert_id)" />
        </article>
      </div>
    </Teleport>
  </template>
</template>

<style scoped>
.wall-alert-notifications{pointer-events:none}.wall-alert-notifications.has-alerts{pointer-events:auto}
.wall-alert-notifications{position:fixed;right:20px;bottom:20px;z-index:55;width:min(390px,calc(100vw - 28px));max-height:calc(100dvh - 110px);overflow-y:auto;overscroll-behavior:contain;display:grid;gap:10px;padding:4px;scrollbar-width:thin}.wall-alert-toast{position:relative;padding:17px 35px 17px 17px;border:1px solid #536382;border-left:3px solid #7ee0b0;border-radius:11px;background:#1c2433;color:#edf3fc;box-shadow:0 12px 35px #0008;min-width:0}.wall-alert-toast.testing{border-left-color:#b5bcff}.dismiss{position:absolute;top:7px;right:7px;width:28px;height:28px;color:#c4d2e6;background:transparent;border:0;font-size:1.2rem;cursor:pointer}button:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}@media(max-width:640px){.wall-alert-notifications{right:14px;bottom:14px}}
</style>
