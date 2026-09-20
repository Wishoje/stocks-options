<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ posts: Array, settings: Object, defaultDate: String, connectionConfigured: Boolean, publishingEnabled: Boolean, scheduleEnabled: Boolean, localReview: Boolean })
const page = usePage()
const selectedId = ref(props.posts[0]?.id ?? null)
const selected = computed(() => props.posts.find(post => post.id === selectedId.value))
const generation = useForm({ session_date: props.defaultDate, slot: 'primary' })
const preferences = useForm({ second_symbol: props.settings.second_symbol, paused: props.settings.paused })
const editor = useForm({ body: '', alt_text: '' })
const confirming = ref(false)
const busy = ref(false)
const errors = computed(() => Object.values(page.props.errors ?? {}))
watch(() => [selected.value?.id, selected.value?.updated_at], () => {
  editor.body = selected.value?.body ?? ''
  editor.alt_text = selected.value?.alt_text ?? ''
  editor.defaults({ body: editor.body, alt_text: editor.alt_text })
  confirming.value = false
}, { immediate: true })
watch(() => props.posts, posts => {
  if (!posts.some(post => post.id === selectedId.value)) selectedId.value = posts[0]?.id ?? null
})
const editable = computed(() => ['draft', 'approved'].includes(selected.value?.status))
const imageUrl = computed(() => selected.value?.image_path ? `/admin/social/${selected.value.id}/image?v=${selected.value.image_sha256}` : null)
const weightedLength = computed(() => {
  let links = 0
  const plain = editor.body.replace(/https?:\/\/[^\s]+/gu, () => { links++; return '' })
  return links * 23 + [...plain].reduce((n, ch) => n + (ch.codePointAt(0) < 128 ? 1 : 2), 0)
})
function action(url) {
  busy.value = true
  router.post(url, {}, { preserveScroll: true, onFinish: () => { busy.value = false; confirming.value = false } })
}
function refresh() { router.reload({ only: ['posts', 'settings', 'flash'], preserveScroll: true }) }
</script>

<template>
  <AppLayout title="Social posts">
    <main class="social-page">
      <header class="social-header">
        <div><p class="eyebrow">GEX OPTIONS · PUBLISHING DESK</p><h1>Daily levels, ready to share.</h1><p class="muted">Prepare the evidence, review the message, then approve it for its scheduled slot.</p></div>
        <div class="header-actions"><span class="mode" :class="{ live: publishingEnabled && !settings.paused }">{{ publishingEnabled ? (settings.paused ? 'Paused' : 'Approved posts only') : 'Draft mode · posting off' }}</span><button @click="refresh">Refresh drafts</button></div>
      </header>

      <div v-if="page.props.flash?.status" class="notice" role="status">{{ page.props.flash.status }}</div>
      <div v-if="localReview" class="notice">Local historical review · {{ defaultDate }}. Uses the site's existing review clock and recorded data. Historical drafts cannot be published.</div>
      <div v-if="errors.length" class="notice error" role="alert"><p v-for="error in errors" :key="error">{{ error }}</p></div>

      <section class="social-settings" aria-label="Schedule and connection">
        <form class="panel" @submit.prevent="preferences.put('/admin/social/settings', { preserveScroll: true })">
          <h2>Two symbols. One morning routine.</h2>
          <div class="slots"><div><strong>SPY</strong><span>8:45 AM ET</span></div><div><label for="second-symbol">Second symbol</label><select id="second-symbol" v-model="preferences.second_symbol"><option>QQQ</option><option>TSLA</option></select><span>9:00 AM ET</span></div></div>
          <p class="muted small">Trading days only · 2W expiry scope · previous completed session. New York time follows daylight saving time.</p>
          <label class="check"><input v-model="preferences.paused" type="checkbox"> Pause scheduled generation and publishing</label>
          <button :disabled="preferences.processing" type="submit">Save preferences</button>
        </form>
        <div class="panel connection">
          <p class="eyebrow">@GexOptions</p><h2>{{ connectionConfigured ? 'Credentials configured' : 'Drafts work without X' }}</h2>
          <p class="muted">{{ connectionConfigured ? 'Check the account connection before enabling publishing. This check does not send a post.' : 'Preview, edit, and download your images now. Add the four OAuth 1.0a credentials on the server to connect X.' }}</p>
          <p class="small muted">Daily draft scheduler: <strong>{{ scheduleEnabled ? 'Enabled · prepares at 8:30 AM ET' : 'Off · generate manually below' }}</strong></p>
          <button :disabled="!connectionConfigured || busy" @click="action('/admin/social/verify')">Check X connection</button>
        </div>
      </section>

      <form class="generation panel" @submit.prevent="generation.post('/admin/social/generate', { preserveScroll: true })">
        <div><h2>Prepare a draft</h2><p class="muted small">A missing or stale snapshot blocks the draft. It never substitutes made-up levels.</p></div>
        <label>Session date<input v-model="generation.session_date" type="date" required></label>
        <label>Symbol<select v-model="generation.slot"><option value="primary">SPY</option><option value="secondary">{{ settings.second_symbol }}</option></select></label>
        <button class="primary" :disabled="generation.processing">{{ generation.processing ? 'Preparing…' : 'Generate draft' }}</button>
      </form>

      <div class="workspace">
        <aside class="panel drafts" aria-label="Recent drafts">
          <h2>Recent drafts</h2><p v-if="!posts.length" class="muted">Your first draft will appear here.</p>
          <button v-for="post in posts" :key="post.id" class="draft-item" :class="{ selected: post.id === selectedId }" :aria-pressed="post.id === selectedId" @click="selectedId = post.id">
            <span><strong>{{ post.symbol }}</strong><span class="status" :data-status="post.status">{{ post.status.replaceAll('_', ' ') }}</span></span><small>{{ post.session_date }} · {{ post.slot === 'primary' ? '8:45' : '9:00' }} AM ET</small>
          </button>
        </aside>
        <section v-if="selected" class="draft-detail" aria-label="Selected draft">
          <div v-if="selected.issue" class="notice error" role="status">{{ selected.issue }}</div>
          <div class="panel preview">
            <div class="panel-heading"><div><p class="eyebrow">{{ selected.symbol }} · {{ selected.session_date }}</p><h2>The image your audience will see</h2></div><a v-if="imageUrl" class="button" :href="imageUrl+'&download=1'">Download PNG</a></div>
            <img v-if="imageUrl" :src="imageUrl" :alt="selected.alt_text" width="1600" height="1000">
            <div v-else class="empty-preview"><h3>{{ selected.status === 'generating' ? 'Preparing your chart' : 'No complete image yet' }}</h3><p>Refresh after generation, or regenerate when the required snapshot is available.</p></div>
            <div v-if="selected.snapshot" class="source"><span>EOD {{ selected.snapshot.data_date }} · {{ selected.snapshot.expiration_dates?.length }} expiries · 2W scope</span><a :href="`/admin/social/${selected.id}/snapshot`">Download source JSON</a></div>
          </div>
          <form v-if="selected.body" class="panel editor" @submit.prevent="editor.put(`/admin/social/${selected.id}`, { preserveScroll: true })">
            <div class="panel-heading"><h2>Post text</h2><span :class="{ invalid: weightedLength > 280 }">{{ weightedLength }} / 280</span></div>
            <label for="post-body" class="sr-only">Post text</label><textarea id="post-body" v-model="editor.body" rows="8" :disabled="!editable" />
            <label for="post-alt">Image description</label><textarea id="post-alt" v-model="editor.alt_text" rows="3" maxlength="1000" :disabled="!editable" />
            <p class="muted small">Links use campaign tracking. Editing a draft removes its previous approval. Downloaded images can also be posted manually.</p>
            <div class="editor-actions">
              <button type="submit" :disabled="!editable || editor.processing || !editor.isDirty || weightedLength > 280">Save draft</button>
              <button type="button" class="primary" :disabled="selected.status !== 'draft' || !imageUrl || editor.isDirty || busy" @click="action(`/admin/social/${selected.id}/approve`)">Approve draft</button>
              <button v-if="selected.status === 'approved'" type="button" :disabled="editor.processing" @click="editor.put(`/admin/social/${selected.id}`, { preserveScroll: true })">Return to draft</button>
              <button v-if="publishingEnabled && selected.status === 'approved'" type="button" :disabled="settings.paused || busy || editor.isDirty" @click="confirming = !confirming">Publish in time slot</button>
              <a v-if="selected.x_post_id" class="button" :href="`https://x.com/GexOptions/status/${selected.x_post_id}`" target="_blank" rel="noopener noreferrer">View on X</a>
            </div>
            <div v-if="confirming" class="notice"><p>Send this approved text and image to @GexOptions? The server will check the date and scheduled window again.</p><button type="button" class="primary" :disabled="busy" @click="action(`/admin/social/${selected.id}/publish`)">Confirm publish</button><button type="button" @click="confirming=false">Cancel</button></div>
            <p v-if="!publishingEnabled" class="muted small">Approving saves your review. Nothing can be posted while publishing is off.</p>
          </form>
        </section>
        <section v-else class="panel empty-preview"><h2>Your morning post starts here.</h2><p class="muted">Choose a session and generate SPY or {{ settings.second_symbol }}. Review the chart and text before approving.</p></section>
      </div>
    </main>
  </AppLayout>
</template>

<style scoped>
.social-page{max-width:1440px;margin:auto;padding:32px 24px 64px;color:#edf4ff}.social-header,.header-actions,.panel-heading,.editor-actions,.source{display:flex;align-items:center;justify-content:space-between;gap:16px}.social-header{margin-bottom:28px;align-items:flex-start}.eyebrow{font-size:11px;font-weight:750;letter-spacing:.13em;color:#80cce3;margin:0 0 10px}h1{font-size:clamp(26px,3vw,38px);font-weight:750;margin:0 0 10px;line-height:1.15}h2{font-size:18px;font-weight:700;margin:0 0 12px}h3{font-size:20px}.muted{color:#aabacf;line-height:1.6}.small{font-size:13px}.panel{background:#141c29;border:1px solid #2c3b50;border-radius:14px;padding:22px}.social-settings{display:grid;grid-template-columns:1.2fr 1fr;gap:20px;margin-bottom:20px}.slots{display:flex;gap:50px;margin:16px 0}.slots>div{display:flex;align-items:center;gap:14px}.slots strong{font-size:23px;color:#98caff}.slots span{color:#becce0;font-size:14px}label{display:block;font-size:13px;color:#b8cbe2}.check{display:flex;gap:10px;align-items:center;margin:16px 0}.check input{accent-color:#73ccb3}.connection{display:flex;flex-direction:column;align-items:flex-start;justify-content:center}button,.button{display:inline-flex;align-items:center;justify-content:center;border:1px solid #43536c;border-radius:8px;padding:10px 14px;font-size:13px;font-weight:650;color:#edf4ff;background:#202d40;transition:background .15s}button:hover,.button:hover{background:#2b3c53}button:focus-visible,a:focus-visible{outline:3px solid #80c7ff;outline-offset:3px}button:disabled{opacity:.45;cursor:not-allowed}.primary{background:#9acaf6;color:#0a1c30;border-color:#9acaf6}.primary:hover{background:#b4ddff}select,input[type=date],textarea{background:#0e1521;color:#eef5ff;border:1px solid #405069;border-radius:8px;padding:9px 12px;font-size:14px}textarea{width:100%;line-height:1.65;resize:vertical;margin:8px 0 18px}select:focus,input:focus,textarea:focus{outline:2px solid #83c2fa;outline-offset:2px}.generation{display:flex;align-items:center;gap:20px;margin-bottom:26px}.generation>div{flex:1}.generation label{display:grid;gap:6px}.generation h2,.generation p{margin:0}.workspace{display:grid;grid-template-columns:245px minmax(0,1fr);gap:22px}.drafts{align-self:start}.draft-item{width:100%;display:block;text-align:left;margin-top:10px;background:#111a27;padding:12px}.draft-item>span{display:flex;justify-content:space-between;align-items:center;gap:8px}.draft-item small{display:block;color:#aabacf;font-size:12px;margin-top:10px}.draft-item.selected{border-color:#91caff;background:#20344c}.status{font-size:10px;text-transform:uppercase;color:#a8c7ed}.status[data-status=published],.status[data-status=approved]{color:#7de2b6}.status[data-status=blocked],.status[data-status=needs_review]{color:#f5b79a}.draft-detail{min-width:0;display:grid;gap:20px;align-content:start}.preview img{width:100%;height:auto;border-radius:10px;border:1px solid #2c3b50;margin-top:10px}.source{font-size:12px;color:#9cafc7;margin-top:15px;flex-wrap:wrap}.source a{color:#9acaf6;text-decoration:underline}.editor-actions{justify-content:flex-start;flex-wrap:wrap}.notice{background:#173438;border:1px solid #386b67;color:#b8eddd;padding:14px 18px;border-radius:10px;margin-bottom:18px}.notice p{margin:0 0 8px}.notice.error{background:#382924;border-color:#775345;color:#ffd1b9}.notice button{margin:8px 10px 0 0}.invalid{color:#ffad96}.mode{font-size:12px;color:#b6cce6;background:#1a2a3d;border:1px solid #3c5677;border-radius:30px;padding:9px 12px;white-space:nowrap}.mode.live{color:#90e4bc}.empty-preview{padding:70px 30px;text-align:center;color:#a9bdd6}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
@media(max-width:1000px){.social-header,.generation{flex-wrap:wrap}.social-settings{grid-template-columns:1fr}.workspace{grid-template-columns:1fr}.drafts{display:flex;gap:10px;overflow:auto;align-items:center}.drafts h2{min-width:100px;margin:0}.draft-item{min-width:180px;width:auto;margin:0}.generation>div{flex-basis:100%}}
@media(max-width:600px){.social-page{padding:24px 14px}.panel{padding:16px}.slots{flex-direction:column;gap:15px}.panel-heading{align-items:flex-start;flex-wrap:wrap}.header-actions{flex-wrap:wrap}.generation label{flex:1}.generation button{width:100%}.source{display:block}.source a{display:block;margin-top:10px}}
@media(prefers-reduced-motion:reduce){button,.button{transition:none}}
</style>
