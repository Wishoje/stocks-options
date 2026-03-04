<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'

const loading = ref(false)
const error = ref('')
const profile = ref('broad')
const date = ref('')
const onlyIssues = ref(false)

const payload = ref({
  date: null,
  latest_available_date: null,
  thresholds: {
    profile: 'broad',
    min_expirations: 2,
    min_strikes: 12,
    min_strike_ratio: 0.45,
  },
  summary: {
    total: 0,
    covered: 0,
    missing: 0,
    alert: 0,
    warn: 0,
    ok: 0,
  },
  items: [],
})

const rows = computed(() => {
  const items = Array.isArray(payload.value.items) ? payload.value.items : []
  if (!onlyIssues.value) {
    return items
  }
  return items.filter((r) => r.status !== 'ok')
})

function badgeClass(status) {
  switch (status) {
    case 'missing':
      return 'bg-red-100 text-red-800 border border-red-300'
    case 'alert':
      return 'bg-orange-100 text-orange-800 border border-orange-300'
    case 'warn':
      return 'bg-amber-100 text-amber-800 border border-amber-300'
    default:
      return 'bg-emerald-100 text-emerald-800 border border-emerald-300'
  }
}

function rowClass(status) {
  switch (status) {
    case 'missing':
      return 'bg-red-50'
    case 'alert':
      return 'bg-orange-50'
    case 'warn':
      return 'bg-amber-50'
    default:
      return ''
  }
}

function statusLabel(status) {
  switch (status) {
    case 'missing':
      return 'Missing'
    case 'alert':
      return 'Alert'
    case 'warn':
      return 'Warn'
    default:
      return 'OK'
  }
}

function fmtRatio(v) {
  if (v == null || !Number.isFinite(Number(v))) return '--'
  return Number(v).toFixed(4)
}

function reasonLabel(reason) {
  if (!reason) return '--'
  return String(reason).replaceAll('_', ' ')
}

function fetchMetaChips(meta) {
  if (!meta || typeof meta !== 'object') return []

  const chips = []

  if (meta.status) {
    chips.push(`fetch: ${meta.status}`)
  }
  if (meta.provider) {
    chips.push(`provider: ${meta.provider}`)
  }
  if (meta.finnhub_status && meta.finnhub_status !== 'not_attempted') {
    chips.push(`finnhub: ${meta.finnhub_status}`)
  }
  if (meta.massive_status && meta.massive_status !== 'not_attempted') {
    chips.push(`massive: ${meta.massive_status}`)
  }
  if (meta.massive_http_status) {
    chips.push(`massive_http: ${meta.massive_http_status}`)
  }
  if (meta.finnhub_http_status) {
    chips.push(`finnhub_http: ${meta.finnhub_http_status}`)
  }
  if (meta.rows_kept != null) {
    chips.push(`rows_kept: ${meta.rows_kept}`)
  }

  return chips
}

async function load() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await axios.get('/api/eod/health', {
      params: {
        profile: profile.value,
        date: date.value || undefined,
      },
    })
    payload.value = data
  } catch (e) {
    error.value = e?.response?.data?.error || 'Unable to load EOD health data.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <AppLayout title="EOD Health">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">EOD Health</h2>
    </template>

    <div class="py-6">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
        <div class="bg-white shadow sm:rounded-lg p-4">
          <div class="flex flex-wrap gap-3 items-end">
            <div>
              <label class="block text-xs font-medium text-gray-600">Profile</label>
              <select
                v-model="profile"
                class="mt-1 rounded-md border-gray-300 text-sm"
              >
                <option value="broad">Broad</option>
                <option value="core">Core</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-600">Date</label>
              <input
                v-model="date"
                type="date"
                class="mt-1 rounded-md border-gray-300 text-sm"
              />
            </div>

            <button
              type="button"
              class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-60"
              :disabled="loading"
              @click="load"
            >
              {{ loading ? 'Loading...' : 'Refresh' }}
            </button>

            <label class="inline-flex items-center gap-2 text-sm text-gray-700 ml-2">
              <input v-model="onlyIssues" type="checkbox" class="rounded border-gray-300" />
              Show only issues
            </label>
          </div>

          <div class="mt-3 text-sm text-gray-600">
            <span class="font-medium">Target date:</span> {{ payload.date || '--' }}
            <span class="mx-2">|</span>
            <span class="font-medium">Latest available:</span> {{ payload.latest_available_date || '--' }}
            <span class="mx-2">|</span>
            <span class="font-medium">Thresholds:</span>
            exp >= {{ payload.thresholds?.min_expirations ?? '--' }},
            strikes >= {{ payload.thresholds?.min_strikes ?? '--' }},
            ratio >= {{ payload.thresholds?.min_strike_ratio ?? '--' }}
          </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
          <div class="bg-white shadow sm:rounded-lg p-3">
            <div class="text-xs text-gray-500">Total</div>
            <div class="text-xl font-semibold">{{ payload.summary?.total ?? 0 }}</div>
          </div>
          <div class="bg-white shadow sm:rounded-lg p-3">
            <div class="text-xs text-gray-500">Covered</div>
            <div class="text-xl font-semibold">{{ payload.summary?.covered ?? 0 }}</div>
          </div>
          <div class="bg-white shadow sm:rounded-lg p-3">
            <div class="text-xs text-red-600">Missing</div>
            <div class="text-xl font-semibold text-red-700">{{ payload.summary?.missing ?? 0 }}</div>
          </div>
          <div class="bg-white shadow sm:rounded-lg p-3">
            <div class="text-xs text-orange-600">Alert</div>
            <div class="text-xl font-semibold text-orange-700">{{ payload.summary?.alert ?? 0 }}</div>
          </div>
          <div class="bg-white shadow sm:rounded-lg p-3">
            <div class="text-xs text-amber-600">Warn</div>
            <div class="text-xl font-semibold text-amber-700">{{ payload.summary?.warn ?? 0 }}</div>
          </div>
          <div class="bg-white shadow sm:rounded-lg p-3">
            <div class="text-xs text-emerald-600">OK</div>
            <div class="text-xl font-semibold text-emerald-700">{{ payload.summary?.ok ?? 0 }}</div>
          </div>
        </div>

        <div v-if="error" class="bg-red-50 border border-red-300 text-red-700 rounded-md p-3 text-sm">
          {{ error }}
        </div>

        <div class="bg-white shadow sm:rounded-lg overflow-hidden">
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 text-gray-700">
                <tr>
                  <th class="text-left px-3 py-2">Symbol</th>
                  <th class="text-left px-3 py-2">Status</th>
                  <th class="text-left px-3 py-2">Reasons</th>
                  <th class="text-right px-3 py-2">Rows</th>
                  <th class="text-right px-3 py-2">Exp</th>
                  <th class="text-right px-3 py-2">Strikes</th>
                  <th class="text-right px-3 py-2">Calls</th>
                  <th class="text-right px-3 py-2">Puts</th>
                  <th class="text-right px-3 py-2">Prev Strikes</th>
                  <th class="text-right px-3 py-2">Ratio vs Prev</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in rows"
                  :key="row.symbol"
                  :class="rowClass(row.status)"
                  class="border-t border-gray-100"
                >
                  <td class="px-3 py-2 font-medium">{{ row.symbol }}</td>
                  <td class="px-3 py-2">
                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium" :class="badgeClass(row.status)">
                      {{ statusLabel(row.status) }}
                    </span>
                  </td>
                  <td class="px-3 py-2 max-w-[360px]">
                    <div class="flex flex-wrap gap-1">
                      <span
                        v-for="reason in (row.reasons || [])"
                        :key="reason"
                        class="inline-flex px-2 py-0.5 rounded bg-gray-100 text-gray-700 text-xs"
                      >
                        {{ reasonLabel(reason) }}
                      </span>
                    </div>
                    <div v-if="fetchMetaChips(row.last_fetch_meta).length" class="flex flex-wrap gap-1 mt-1">
                      <span
                        v-for="chip in fetchMetaChips(row.last_fetch_meta)"
                        :key="chip"
                        class="inline-flex px-2 py-0.5 rounded bg-indigo-100 text-indigo-700 text-xs"
                      >
                        {{ chip }}
                      </span>
                    </div>
                  </td>
                  <td class="px-3 py-2 text-right">{{ row.rows_n ?? '--' }}</td>
                  <td class="px-3 py-2 text-right">{{ row.expirations_n ?? '--' }}</td>
                  <td class="px-3 py-2 text-right">{{ row.strikes_n ?? '--' }}</td>
                  <td class="px-3 py-2 text-right">{{ row.call_strikes_n ?? '--' }}</td>
                  <td class="px-3 py-2 text-right">{{ row.put_strikes_n ?? '--' }}</td>
                  <td class="px-3 py-2 text-right">{{ row.prev_strikes_n ?? '--' }}</td>
                  <td class="px-3 py-2 text-right">{{ fmtRatio(row.strike_ratio_vs_prev_day) }}</td>
                </tr>
                <tr v-if="!loading && rows.length === 0">
                  <td colspan="10" class="px-3 py-4 text-center text-gray-500">No rows</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
