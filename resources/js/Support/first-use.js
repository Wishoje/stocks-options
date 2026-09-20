import axios from 'axios'
import { trackEventOnce } from '@/lib/ga'

const confirmedServerAccounts = new Set()
const pendingServerAccounts = new Map()
const serverAttemptCounts = new Map()
const maxServerAttemptsPerAccount = 3

const readingKeys = [
  'net_gex',
  'netGex',
  'call_gex',
  'callGex',
  'put_gex',
  'putGex',
  'call_volume',
  'put_volume',
  'call_vol',
  'put_vol',
  'net_gex_live',
]

export function hasUsableDashboardReading(rows) {
  return Array.isArray(rows) && rows.some(row => readingKeys.some(key => {
    const value = row?.[key]
    return value !== null
      && value !== undefined
      && value !== ''
      && Number.isFinite(Number(value))
  }))
}

function recordFirstUsefulReadingForAccount(accountId) {
  const normalizedAccountId = String(accountId ?? '')
  if (!/^\d+$/.test(normalizedAccountId)
    || confirmedServerAccounts.has(normalizedAccountId)
    || pendingServerAccounts.has(normalizedAccountId)
    || (serverAttemptCounts.get(normalizedAccountId) ?? 0) >= maxServerAttemptsPerAccount) {
    return
  }

  serverAttemptCounts.set(
    normalizedAccountId,
    (serverAttemptCounts.get(normalizedAccountId) ?? 0) + 1,
  )

  const request = axios.post('/product-events/first-useful-reading', {
    interaction: 'reading_inspected',
    surface: 'dashboard',
  })
    .then(response => {
      if (response?.data?.recorded === true) {
        confirmedServerAccounts.add(normalizedAccountId)
      }
    })
    .catch(() => {})
    .finally(() => pendingServerAccounts.delete(normalizedAccountId))

  pendingServerAccounts.set(normalizedAccountId, request)
}

export function recordFirstUsefulReading({ accountId, rows, validated = false, loading = false, error = '' } = {}) {
  if (loading || error || (!validated && !hasUsableDashboardReading(rows))) return false

  recordFirstUsefulReadingForAccount(accountId)

  return trackEventOnce(
    'first_useful_reading',
    'dashboard-reading',
    { surface: 'dashboard', state: 'ready' },
    'local',
  )
}
