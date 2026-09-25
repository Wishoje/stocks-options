<script setup>
import { computed, ref, watch } from 'vue'
import UiBadge from './UI/UiBadge.vue'
import UiHelpDialog from './UI/UiHelpDialog.vue'
import UiMetric from './UI/UiMetric.vue'
import UiPanel from './UI/UiPanel.vue'
import UiStatus from './UI/UiStatus.vue'
import { numeric } from './UI/numbers.js'

const props = defineProps({
  rows: { type: Array, default: () => [] },
  dataDate: { type: String, default: null },
  symbol: { type: String, required: true },
  requestSort: { type: String, default: null },
})

const emit = defineEmits(['reading-inspected'])

const sortKey = ref('api_order')
const sortDirection = ref('ascending')
const selectedId = ref('')

const fieldLabels = {
  exp_date: 'Expiry',
  strike: 'Strike',
  z_score: 'Z-score',
  vol_oi: 'Volume / open interest',
  meta: 'Metadata',
  call_vol: 'Call volume',
  put_vol: 'Put volume',
  total_vol: 'Total volume',
  premium_usd: 'Total premium',
  call_prem: 'Call premium',
  put_prem: 'Put premium',
  mu: '30-day baseline mean',
  sigma: '30-day baseline deviation',
  baseline_mu: '30-day baseline mean',
  baseline_sigma: '30-day baseline deviation',
  history_samples: 'History samples',
  confidence: 'Baseline confidence',
  baseline_excludes_today: 'Baseline excludes the observation day',
}

const requestSortLabels = {
  z_score: 'Z-score',
  premium: 'premium',
  vol_oi: 'Vol/OI',
}

function objectValue(value) {
  if (value && typeof value === 'object' && !Array.isArray(value)) return value
  if (typeof value !== 'string' || !value.trim()) return {}
  try {
    const parsed = JSON.parse(value)
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {}
  } catch {
    return {}
  }
}

const records = computed(() => props.rows.map((rawRow, sourceIndex) => {
  const raw = rawRow && typeof rawRow === 'object' ? rawRow : {}
  const expiry = raw.exp_date == null ? 'missing-expiry' : String(raw.exp_date)
  const strike = raw.strike == null ? 'missing-strike' : String(raw.strike)
  return {
    id: `${expiry}|${strike}|${sourceIndex}`,
    raw,
    meta: objectValue(raw.meta),
    sourceIndex,
  }
}))

const recordIds = computed(() => records.value.map(record => record.id).join('\u001f'))

function syncSelectedRecord() {
  if (records.value.some(record => record.id === selectedId.value)) return
  selectedId.value = records.value[0]?.id ?? ''
}

function resetLocalSort() {
  sortKey.value = 'api_order'
  sortDirection.value = 'ascending'
}

watch(recordIds, syncSelectedRecord, { immediate: true })
watch(() => props.rows, resetLocalSort, { flush: 'sync' })
watch(() => props.requestSort, resetLocalSort)

function sortableValue(record, field) {
  if (field === 'api_order') return record.sourceIndex
  if (field === 'exp_date') return record.raw.exp_date == null || record.raw.exp_date === ''
    ? null
    : String(record.raw.exp_date)
  if (field === 'premium_usd' || field === 'total_vol') return numeric(record.meta[field])
  return numeric(record.raw[field])
}

function compareRecords(a, b) {
  const av = sortableValue(a, sortKey.value)
  const bv = sortableValue(b, sortKey.value)
  if (av == null && bv == null) return a.sourceIndex - b.sourceIndex
  if (av == null) return 1
  if (bv == null) return -1
  const result = typeof av === 'string' ? av.localeCompare(bv) : av - bv
  if (result === 0) return a.sourceIndex - b.sourceIndex
  return result * (sortDirection.value === 'ascending' ? 1 : -1)
}

const sortedRecords = computed(() => [...records.value].sort(compareRecords))
const selectedRecord = computed(() => records.value.find(record => record.id === selectedId.value) ?? records.value[0] ?? null)

function defaultDirection(field) {
  return field === 'api_order' || field === 'exp_date' || field === 'strike' ? 'ascending' : 'descending'
}

function sortBy(field) {
  if (sortKey.value === field) {
    sortDirection.value = sortDirection.value === 'ascending' ? 'descending' : 'ascending'
    return
  }
  sortKey.value = field
  sortDirection.value = defaultDirection(field)
}

function selectSort(event) {
  const field = event?.target?.value || 'api_order'
  sortKey.value = field
  sortDirection.value = defaultDirection(field)
}

function toggleSortDirection() {
  sortDirection.value = sortDirection.value === 'ascending' ? 'descending' : 'ascending'
}

function ariaSort(field) {
  return sortKey.value === field ? sortDirection.value : 'none'
}

function selectRecord(record) {
  if (!record || !records.value.some(item => item.id === record.id)) return
  selectedId.value = record.id
  emit('reading-inspected')
}

function formatNumber(value, digits = 2) {
  const number = numeric(value)
  if (number == null) return 'Unavailable'
  return number.toLocaleString('en-US', {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  })
}

function formatCount(value) {
  const number = numeric(value)
  if (number == null) return 'Unavailable'
  return number.toLocaleString('en-US', { maximumFractionDigits: 2 })
}

function formatCompactCount(value) {
  const number = numeric(value)
  if (number == null) return 'Unavailable'
  return new Intl.NumberFormat('en-US', {
    notation: Math.abs(number) >= 1000 ? 'compact' : 'standard',
    maximumFractionDigits: Math.abs(number) >= 1000 ? 1 : 0,
  }).format(number)
}

function formatStrike(value) {
  const number = numeric(value)
  if (number == null) return 'Unavailable'
  return number.toLocaleString('en-US', { maximumFractionDigits: 4 })
}

function formatZ(value) {
  const number = numeric(value)
  if (number == null) return 'Unavailable'
  const sign = number > 0 ? '+' : number < 0 ? '-' : ''
  return `${sign}${Math.abs(number).toFixed(2)} sigma`
}

function formatRatio(value) {
  const number = numeric(value)
  return number == null ? 'Unavailable' : `${number.toFixed(2)}x`
}

function formatCurrency(value, compact = false) {
  const number = numeric(value)
  if (number == null) return 'Unavailable'
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    notation: compact && Math.abs(number) >= 1000 ? 'compact' : 'standard',
    maximumFractionDigits: compact ? 1 : 2,
  }).format(number)
}

function formatBoolean(value) {
  if (value === true || value === 1 || value === '1') return 'Yes'
  if (value === false || value === 0 || value === '0') return 'No'
  return 'Unavailable'
}

function formatReturnedField(key, value) {
  if (value == null || value === '') return 'Unavailable'
  if (key === 'strike') return formatStrike(value)
  if (key === 'z_score') return formatZ(value)
  if (key === 'vol_oi') return formatRatio(value)
  if (['premium_usd', 'call_prem', 'put_prem'].includes(key)) return formatCurrency(value)
  if (['call_vol', 'put_vol', 'total_vol', 'history_samples'].includes(key)) return formatCount(value)
  if (['mu', 'sigma', 'baseline_mu', 'baseline_sigma'].includes(key)) return formatNumber(value)
  if (key === 'baseline_excludes_today') return formatBoolean(value)
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (typeof value === 'object') {
    try { return JSON.stringify(value) } catch { return String(value) }
  }
  return String(value)
}

function labelForField(key) {
  if (fieldLabels[key]) return fieldLabels[key]
  return String(key).replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase())
}

const selectedTopLevelFields = computed(() => {
  if (!selectedRecord.value) return []
  const fields = Object.entries(selectedRecord.value.raw)
    .filter(([key]) => key !== 'meta')
    .map(([key, value]) => ({ key, label: labelForField(key), value: formatReturnedField(key, value) }))
  if (Object.prototype.hasOwnProperty.call(selectedRecord.value.raw, 'meta')) {
    const rawMeta = selectedRecord.value.raw.meta
    fields.push({
      key: 'meta',
      label: fieldLabels.meta,
      value: rawMeta == null ? 'Unavailable' : `${Object.keys(selectedRecord.value.meta).length} returned fields`,
    })
  }
  return fields
})

const selectedMetaFields = computed(() => {
  if (!selectedRecord.value) return []
  return Object.entries(selectedRecord.value.meta).map(([key, value]) => ({
    key,
    label: labelForField(key),
    value: formatReturnedField(key, value),
  }))
})

function dominantSide(record) {
  const calls = numeric(record?.meta?.call_vol)
  const puts = numeric(record?.meta?.put_vol)
  if (calls == null || puts == null) return 'Side unavailable'
  if (calls === puts) return 'Balanced'
  return calls > puts ? 'Call-led' : 'Put-led'
}

function sideTone(record) {
  const side = dominantSide(record)
  if (side === 'Call-led') return 'positive'
  if (side === 'Put-led') return 'negative'
  if (side === 'Side unavailable') return 'warning'
  return 'neutral'
}

function zTone(value) {
  const number = numeric(value)
  if (number == null) return 'warning'
  return number >= 4 ? 'warning' : 'data'
}

function highest(field, fromMeta = false) {
  const values = records.value
    .map(record => numeric(fromMeta ? record.meta[field] : record.raw[field]))
    .filter(value => value != null)
  return values.length ? Math.max(...values) : null
}

const highestZ = computed(() => highest('z_score'))
const highestVolOi = computed(() => highest('vol_oi'))
const pricedRows = computed(() => records.value.filter(record => numeric(record.meta.premium_usd) != null))
const representedPremium = computed(() => pricedRows.value.length
  ? pricedRows.value.reduce((total, record) => total + numeric(record.meta.premium_usd), 0)
  : null)

const sideCounts = computed(() => records.value.reduce((counts, record) => {
  const side = dominantSide(record)
  if (side === 'Call-led') counts.call += 1
  else if (side === 'Put-led') counts.put += 1
  else if (side === 'Balanced') counts.balanced += 1
  else counts.missing += 1
  return counts
}, { call: 0, put: 0, balanced: 0, missing: 0 }))

const selectedCallVolume = computed(() => numeric(selectedRecord.value?.meta?.call_vol))
const selectedPutVolume = computed(() => numeric(selectedRecord.value?.meta?.put_vol))
const selectedVolumeSplit = computed(() => {
  const calls = selectedCallVolume.value
  const puts = selectedPutVolume.value
  if (calls == null || puts == null) return null
  const total = calls + puts
  if (total <= 0) return { call: 0, put: 0, total }
  return { call: calls / total * 100, put: puts / total * 100, total }
})

const requestSortLabel = computed(() => requestSortLabels[props.requestSort] ?? null)

defineExpose({
  records,
  sortedRecords,
  selectedRecord,
  selectedTopLevelFields,
  selectedMetaFields,
  sortKey,
  sortDirection,
  highestZ,
  highestVolOi,
  representedPremium,
  sideCounts,
  sortBy,
  selectRecord,
})
</script>

<template>
  <UiPanel
    class="ua-panel"
    title="Unusual activity"
    subtitle="Expiry and strike combinations whose volume stands out against their own recent history"
    tone="data"
    data-testid="unusual-activity-panel"
  >
    <template #actions>
      <div class="gex-row ua-panel__actions">
        <UiBadge tone="data">{{ String(symbol).toUpperCase() }}</UiBadge>
        <UiBadge v-if="dataDate" tone="neutral">Data as of {{ dataDate }}</UiBadge>
        <UiBadge v-if="requestSortLabel" tone="neutral">API rank: {{ requestSortLabel }}</UiBadge>
        <UiHelpDialog
          id="unusual-activity-guide"
          title="How to read unusual activity"
          trigger-label="Reading guide"
        >
          <div class="gex-stack ua-guide">
            <p><strong>Z-score</strong> compares the strike-expiry row's total reported volume with its winsorized 30-day baseline. A reading of 3 means volume is three baseline deviations above its mean.</p>
            <p><strong>Vol/OI</strong> divides reported volume by current open interest with a one-contract denominator floor. A value above 1 means reported volume exceeds that adjusted denominator; zero or unavailable open interest can therefore produce a large finite ratio.</p>
            <p><strong>Call-led and put-led</strong> describe the returned volume mix. They are not directional forecasts. Premium estimates help compare the size of activity.</p>
            <p>Use the dashboard filters to narrow the results, then sort a column to compare signals.</p>
            <p>Inspect a signal to see its contract details and volume breakdown.</p>
          </div>
        </UiHelpDialog>
      </div>
    </template>

    <UiStatus
      v-if="!records.length"
      state="sparse"
      title="No unusual activity flags"
      :message="dataDate ? `The ${dataDate} data set returned no contracts for the selected filters.` : 'No completed unusual activity data is available.'"
    />

    <template v-else>
      <div class="gex-grid ua-summary">
        <UiMetric
          prominence="primary"
          label="Highest Z-score"
          :value="formatZ(highestZ)"
          :tone="zTone(highestZ)"
          context="Strongest volume signal in these results"
        />
        <UiMetric
          prominence="primary"
          label="Highest Vol/OI"
          :value="formatRatio(highestVolOi)"
          tone="data"
          context="Highest volume-to-open-interest ratio"
        />
        <UiMetric
          label="Signals found"
          :value="records.length"
          :context="`${sideCounts.call} call-led, ${sideCounts.put} put-led, ${sideCounts.balanced} balanced`"
          tone="data"
        />
        <UiMetric
          label="Premium represented"
          :value="formatCurrency(representedPremium, true)"
          :context="`${pricedRows.length} of ${records.length} returned rows include premium`"
          tone="data"
        />
      </div>

      <section
        v-if="selectedRecord"
        class="gex-panel ua-selected"
        aria-label="Selected unusual activity signal"
        aria-live="polite"
        data-testid="ua-selected-signal"
      >
        <header class="ua-selected__header">
          <div>
            <p>Selected signal</p>
            <h3 class="gex-number">
              {{ selectedRecord.raw.exp_date ?? 'Expiry unavailable' }}
              <span aria-hidden="true"> / </span>
              <span class="sr-only">at strike </span>{{ formatStrike(selectedRecord.raw.strike) }}
            </h3>
          </div>
          <div class="gex-row">
            <UiBadge :tone="sideTone(selectedRecord)">{{ dominantSide(selectedRecord) }}</UiBadge>
            <UiBadge v-if="selectedRecord.meta.confidence" :tone="selectedRecord.meta.confidence === 'low' ? 'warning' : 'data'">
              {{ selectedRecord.meta.confidence }} confidence
            </UiBadge>
          </div>
        </header>

        <dl class="ua-selected__metrics">
          <div data-primary="true" :data-tone="zTone(selectedRecord.raw.z_score)">
            <dt>Z-score</dt>
            <dd class="gex-number">{{ formatZ(selectedRecord.raw.z_score) }}</dd>
          </div>
          <div>
            <dt>Vol/OI</dt>
            <dd class="gex-number">{{ formatRatio(selectedRecord.raw.vol_oi) }}</dd>
          </div>
          <div>
            <dt>Total volume</dt>
            <dd class="gex-number">{{ formatCompactCount(selectedRecord.meta.total_vol) }}</dd>
          </div>
          <div>
            <dt>Premium</dt>
            <dd class="gex-number">{{ formatCurrency(selectedRecord.meta.premium_usd, true) }}</dd>
          </div>
        </dl>

        <div v-if="selectedVolumeSplit" class="ua-volume-split">
          <div class="ua-volume-split__heading">
            <span>Volume mix</span>
            <span class="gex-number">{{ formatCount(selectedVolumeSplit.total) }} total</span>
          </div>
          <div
            class="ua-volume-split__track"
            role="img"
            :aria-label="`Call volume ${formatCount(selectedCallVolume)}; put volume ${formatCount(selectedPutVolume)}`"
          >
            <i data-side="call" :style="{ width: `${selectedVolumeSplit.call}%` }" />
            <i data-side="put" :style="{ width: `${selectedVolumeSplit.put}%` }" />
          </div>
          <div class="ua-volume-split__legend">
            <span><i data-side="call" />Calls <strong class="gex-number">{{ formatCompactCount(selectedCallVolume) }}</strong></span>
            <span><i data-side="put" />Puts <strong class="gex-number">{{ formatCompactCount(selectedPutVolume) }}</strong></span>
          </div>
        </div>
        <p v-else class="ua-volume-split__missing">Volume mix is not available.</p>

        <details class="gex-calculation-details ua-returned-fields" data-testid="ua-selected-fields">
          <summary>Signal details</summary>
          <div class="ua-returned-fields__body">
            <section>
              <h4>Signal fields</h4>
              <dl>
                <div v-for="field in selectedTopLevelFields" :key="`top-${field.key}`">
                  <dt>{{ field.label }} <code>{{ field.key }}</code></dt>
                  <dd class="gex-number">{{ field.value }}</dd>
                </div>
              </dl>
            </section>
            <section>
              <h4>Metadata fields</h4>
              <dl v-if="selectedMetaFields.length">
                <div v-for="field in selectedMetaFields" :key="`meta-${field.key}`">
                  <dt>{{ field.label }} <code>{{ field.key }}</code></dt>
                  <dd class="gex-number">{{ field.value }}</dd>
                </div>
              </dl>
              <p v-else class="gex-muted gex-small">No metadata fields were returned.</p>
            </section>
          </div>
        </details>
      </section>

      <details class="ua-results" data-testid="ua-results-disclosure">
        <summary>All {{ records.length }} unusual activity signals</summary>
        <p class="ua-results__help">Select a column to sort the results. Use Inspect to review a signal.</p>
        <div class="ua-mobile-sort">
          <label>
            Sort displayed signals
            <select class="gex-select" :value="sortKey" @change="selectSort">
              <option value="api_order">API rank</option>
              <option value="exp_date">Expiry</option>
              <option value="strike">Strike</option>
              <option value="z_score">Z-score</option>
              <option value="vol_oi">Vol/OI</option>
              <option value="total_vol">Volume</option>
              <option value="premium_usd">Premium</option>
            </select>
          </label>
          <button
            type="button"
            class="gex-button"
            :aria-label="`Use ${sortDirection === 'ascending' ? 'descending' : 'ascending'} sort direction`"
            @click="toggleSortDirection"
          >
            {{ sortDirection === 'ascending' ? 'Ascending' : 'Descending' }}
          </button>
        </div>
        <div class="gex-table-scroll" role="region" :aria-label="`${symbol} unusual activity results`" tabindex="0">
          <table class="gex-table ua-table">
            <caption>{{ symbol }} unusual activity / {{ records.length }} returned signals</caption>
            <thead>
              <tr>
                <th scope="col" :aria-sort="ariaSort('api_order')"><button type="button" class="gex-sort" @click="sortBy('api_order')">API rank <span aria-hidden="true">{{ sortKey === 'api_order' ? (sortDirection === 'ascending' ? 'up' : 'down') : 'sort' }}</span></button></th>
                <th scope="col" :aria-sort="ariaSort('exp_date')"><button type="button" class="gex-sort" @click="sortBy('exp_date')">Expiry <span aria-hidden="true">{{ sortKey === 'exp_date' ? (sortDirection === 'ascending' ? 'up' : 'down') : 'sort' }}</span></button></th>
                <th scope="col" data-numeric="true" :aria-sort="ariaSort('strike')"><button type="button" class="gex-sort" @click="sortBy('strike')">Strike <span aria-hidden="true">{{ sortKey === 'strike' ? (sortDirection === 'ascending' ? 'up' : 'down') : 'sort' }}</span></button></th>
                <th scope="col">Side</th>
                <th scope="col" data-numeric="true" :aria-sort="ariaSort('z_score')"><button type="button" class="gex-sort" @click="sortBy('z_score')">Z-score <span aria-hidden="true">{{ sortKey === 'z_score' ? (sortDirection === 'ascending' ? 'up' : 'down') : 'sort' }}</span></button></th>
                <th scope="col" data-numeric="true" :aria-sort="ariaSort('vol_oi')"><button type="button" class="gex-sort" @click="sortBy('vol_oi')">Vol/OI <span aria-hidden="true">{{ sortKey === 'vol_oi' ? (sortDirection === 'ascending' ? 'up' : 'down') : 'sort' }}</span></button></th>
                <th scope="col" data-numeric="true" :aria-sort="ariaSort('total_vol')"><button type="button" class="gex-sort" @click="sortBy('total_vol')">Volume <span aria-hidden="true">{{ sortKey === 'total_vol' ? (sortDirection === 'ascending' ? 'up' : 'down') : 'sort' }}</span></button></th>
                <th scope="col" data-numeric="true" :aria-sort="ariaSort('premium_usd')"><button type="button" class="gex-sort" @click="sortBy('premium_usd')">Premium <span aria-hidden="true">{{ sortKey === 'premium_usd' ? (sortDirection === 'ascending' ? 'up' : 'down') : 'sort' }}</span></button></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="record in sortedRecords"
                :key="record.id"
                :data-selected="record.id === selectedId ? 'true' : undefined"
              >
                <td data-label="API rank">
                  <button
                    type="button"
                    class="ua-select"
                    :aria-pressed="record.id === selectedId"
                    :aria-label="`Inspect API rank ${record.sourceIndex + 1}, expiry ${record.raw.exp_date ?? 'unavailable'}, strike ${formatStrike(record.raw.strike)}`"
                    @click="selectRecord(record)"
                  >
                    Inspect #{{ record.sourceIndex + 1 }}
                  </button>
                </td>
                <td data-label="Expiry" class="gex-number" :aria-label="`Expiry ${record.raw.exp_date ?? 'unavailable'}`">{{ record.raw.exp_date ?? 'Unavailable' }}</td>
                <td data-label="Strike" data-numeric="true" :aria-label="`Strike ${formatStrike(record.raw.strike)}`">{{ formatStrike(record.raw.strike) }}</td>
                <td data-label="Side" :aria-label="`Side ${dominantSide(record)}`"><UiBadge :tone="sideTone(record)">{{ dominantSide(record) }}</UiBadge></td>
                <td data-label="Z-score" data-numeric="true" :data-reading-tone="zTone(record.raw.z_score)" :aria-label="`Z-score ${formatZ(record.raw.z_score)}`">{{ formatZ(record.raw.z_score) }}</td>
                <td data-label="Vol/OI" data-numeric="true" :aria-label="`Volume to open interest ${formatRatio(record.raw.vol_oi)}`">{{ formatRatio(record.raw.vol_oi) }}</td>
                <td data-label="Volume" data-numeric="true" :aria-label="`Total volume ${formatCount(record.meta.total_vol)}`">{{ formatCompactCount(record.meta.total_vol) }}</td>
                <td data-label="Premium" data-numeric="true" :aria-label="`Premium ${formatCurrency(record.meta.premium_usd)}`">{{ formatCurrency(record.meta.premium_usd, true) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>
    </template>
  </UiPanel>
</template>

<style scoped>
.ua-panel__actions { justify-content: flex-end; }
.ua-guide { gap: 14px; }
.ua-summary { grid-template-columns: repeat(4, minmax(0, 1fr)); }

.ua-selected {
  margin-top: 16px;
  overflow: hidden;
  background: color-mix(in srgb, var(--gex-data-soft) 18%, var(--gex-surface));
}

.ua-selected::before {
  content: "";
  position: absolute;
  inset: 0 auto 0 0;
  width: 3px;
  background: var(--gex-data);
}

.ua-selected__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 20px 14px;
  border-bottom: 1px solid var(--gex-border);
}

.ua-selected__header p {
  color: var(--gex-muted);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
}

.ua-selected__header h3 {
  margin-top: 3px;
  color: var(--gex-text);
  font-size: 19px;
}

.ua-selected__metrics {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  margin: 0;
  padding: 16px 20px;
}

.ua-selected__metrics > div {
  min-width: 0;
  padding: 2px 16px;
  border-left: 1px solid var(--gex-border);
}

.ua-selected__metrics > div:first-child { padding-left: 0; border-left: 0; }
.ua-selected__metrics dt { color: var(--gex-muted); font-size: 11px; }

.ua-selected__metrics dd {
  margin: 4px 0 0;
  overflow-wrap: anywhere;
  color: var(--gex-text);
  font-size: 18px;
  font-weight: 650;
}

.ua-selected__metrics [data-primary="true"] dd {
  color: var(--gex-tone, var(--gex-data));
  font-size: 23px;
}

.ua-volume-split { padding: 0 20px 16px; }

.ua-volume-split__heading,
.ua-volume-split__legend {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  color: var(--gex-muted);
  font-size: 11px;
}

.ua-volume-split__track {
  display: flex;
  height: 8px;
  overflow: hidden;
  margin: 7px 0;
  border-radius: 999px;
  background: var(--gex-raised);
}

.ua-volume-split__track i {
  min-width: 0;
  transition: width var(--gex-duration) var(--gex-ease);
}

.ua-volume-split [data-side="call"] { background: var(--gex-positive); }
.ua-volume-split [data-side="put"] { background: var(--gex-negative); }

.ua-volume-split__legend span { display: inline-flex; align-items: center; gap: 6px; }
.ua-volume-split__legend i { width: 7px; height: 7px; border-radius: 2px; }
.ua-volume-split__legend strong { color: var(--gex-text); }

.ua-volume-split__missing {
  margin: 0 20px 16px;
  color: var(--gex-warning);
  font-size: 12px;
}

.ua-returned-fields {
  margin: 0;
  padding: 0 20px 16px;
  border-top: 1px solid var(--gex-border);
}

.ua-returned-fields > summary { padding-top: 14px; }

.ua-returned-fields__body {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px;
  padding-top: 5px;
}

.ua-returned-fields h4 { margin-bottom: 7px; color: var(--gex-text); font-size: 12px; }
.ua-returned-fields dl { display: grid; margin: 0; }

.ua-returned-fields dl > div {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(90px, auto);
  gap: 12px;
  padding: 7px 0;
  border-top: 1px solid color-mix(in srgb, var(--gex-border) 70%, transparent);
}

.ua-returned-fields dt { color: var(--gex-muted); font-size: 11px; }

.ua-returned-fields dt code {
  display: block;
  margin-top: 1px;
  color: color-mix(in srgb, var(--gex-muted) 72%, transparent);
  font-size: 9px;
}

.ua-returned-fields dd {
  margin: 0;
  overflow-wrap: anywhere;
  color: var(--gex-text);
  font-size: 11px;
  text-align: right;
}

.ua-results { margin-top: 16px; }
.ua-results > summary { color: var(--gex-text); font-weight: 650; }

.ua-results__help {
  margin: 0 0 8px;
  color: var(--gex-muted);
  font-size: 11px;
}

.ua-mobile-sort { display: none; }

.ua-table { min-width: 820px; }

.ua-table caption {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}

.ua-table th:first-child,
.ua-table td:first-child { padding-left: 4px; }

.ua-table tr[data-selected="true"] {
  background: color-mix(in srgb, var(--gex-selected) 88%, transparent);
  box-shadow: inset 3px 0 var(--gex-data);
}

.ua-table td[data-reading-tone="data"] { color: var(--gex-data); font-weight: 650; }
.ua-table td[data-reading-tone="warning"] { color: var(--gex-warning); font-weight: 650; }

.ua-select {
  min-height: 36px;
  border: 1px solid var(--gex-border);
  border-radius: 7px;
  padding: 5px 8px;
  background: var(--gex-raised);
  color: var(--gex-muted);
  cursor: pointer;
  white-space: nowrap;
  transition: border-color var(--gex-duration) var(--gex-ease), color var(--gex-duration) var(--gex-ease), transform var(--gex-duration) var(--gex-ease);
}

.ua-select:hover,
.ua-select[aria-pressed="true"] { border-color: var(--gex-data); color: var(--gex-text); }
.ua-select:hover { transform: translateY(-1px); }

@container (max-width: 900px) {
  .ua-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@container (max-width: 680px) {
  .ua-selected__header { display: grid; }
  .ua-selected__metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px 0; }
  .ua-selected__metrics > div:nth-child(odd) { padding-left: 0; border-left: 0; }
  .ua-returned-fields__body { grid-template-columns: 1fr; }
  .ua-table { min-width: 0; }

  .ua-mobile-sort {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
  }

  .ua-mobile-sort label {
    display: grid;
    flex: 1;
    gap: 4px;
    color: var(--gex-muted);
    font-size: 11px;
  }

  .ua-mobile-sort .gex-select { width: 100%; }
  .ua-table thead { display: none; }

  .ua-table,
  .ua-table tbody,
  .ua-table tr,
  .ua-table td { display: block; width: 100%; }
  .ua-table tbody { display: grid; gap: 10px; padding-top: 8px; }

  .ua-table tr {
    overflow: hidden;
    border: 1px solid var(--gex-border);
    border-radius: 10px;
    background: var(--gex-surface);
  }

  .ua-table td,
  .ua-table td:first-child {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    min-height: 40px;
    padding: 8px 12px;
    text-align: right;
    white-space: normal;
  }

  .ua-table td::before {
    content: attr(data-label);
    color: var(--gex-muted);
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .05em;
    text-align: left;
    text-transform: uppercase;
  }

  .ua-table td:first-child { background: color-mix(in srgb, var(--gex-raised) 55%, transparent); }
  .ua-select { min-height: 40px; }
}

@container (max-width: 440px) {
  .ua-summary { grid-template-columns: 1fr; }
  .ua-selected__metrics { grid-template-columns: 1fr; }
  .ua-selected__metrics > div,
  .ua-selected__metrics > div:nth-child(odd) { padding: 0; border-left: 0; }
  .ua-volume-split__legend { align-items: flex-start; flex-direction: column; }
}

@media (prefers-reduced-motion: reduce) {
  .ua-volume-split__track i,
  .ua-select { transition: none; }
  .ua-select:hover { transform: none; }
}

:global(.gex-ui[data-motion="reduced"]) .ua-volume-split__track i,
:global(.gex-ui[data-motion="reduced"]) .ua-select { transition: none; }
:global(.gex-ui[data-motion="reduced"]) .ua-select:hover { transform: none; }
</style>
