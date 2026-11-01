<script setup>
import { inject } from 'vue'
import { INTRADAY_RESEARCH_ALERTS } from '@/Support/intradayResearch'
import ExperimentalIntradayAlertDetails from '@/Components/ExperimentalIntradayAlertDetails.vue'
const alerts = inject(INTRADAY_RESEARCH_ALERTS, null)
defineEmits(['open'])
</script>
<template>
  <section v-if="alerts?.owner.value" class="experimental-history" aria-labelledby="experimental-intraday-alerts-title" :aria-busy="alerts.loading.value">
    <h3 id="experimental-intraday-alerts-title">Experimental intraday alerts <span v-if="alerts.unreadCount.value">{{ alerts.unreadCount.value }} unread</span></h3>
    <p>Private paper research · Last 14 days. Each experimental case has one conditions-met event. Opening a case saves its read status. The control profile is study only.</p>
    <p v-if="alerts.error.value" role="status">{{ alerts.error.value }}</p>
    <p v-if="alerts.truncated.value" role="status">Some research alerts are outside this list. Open Intraday experiments to review recorded cases.</p>
    <p v-if="alerts.loading.value && !alerts.history.value.length" role="status">Loading experimental alerts…</p>
    <p v-else-if="!alerts.history.value.length">No experimental cases have met their conditions in this period.</p>
    <ol v-else aria-label="Experimental intraday alerts"><li v-for="item in alerts.history.value" :key="item.id" :class="{ unread: !item.alert_read_at }">
      <span v-if="!item.alert_read_at" class="unread-label">Unread</span>
      <ExperimentalIntradayAlertDetails :item="item" :mark-read="alerts.markRead" @open="$emit('open')" />
    </li></ol>
    <a href="/intraday-research">Open Intraday experiments</a>
  </section>
</template>
<style scoped>
.experimental-history{border-top:1px solid #596080;margin-top:24px;padding-top:22px}.experimental-history h3{font-size:.97rem;font-weight:650;margin:0}.experimental-history h3 span{display:inline-block;font-size:.65rem;color:#d9c6ff;margin-left:7px}.experimental-history>p{font-size:.73rem;color:#bac9dc;line-height:1.65;margin:12px 0}.experimental-history ol{list-style:none;padding:0;margin:16px 0;display:grid;gap:12px}.experimental-history li{border:1px solid #514965;border-radius:9px;background:#242234;padding:15px}.experimental-history li.unread{border-left:3px solid #c5a3f3}.unread-label{font-size:.64rem;display:block;color:#d7bdff;margin-bottom:7px}.experimental-history>a{display:inline-flex;min-height:36px;align-items:center;font-size:.77rem;color:#c9c4ff}.experimental-history a:focus-visible{outline:2px solid #c6bcff;outline-offset:3px}
</style>
