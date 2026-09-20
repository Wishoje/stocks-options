export const DEFAULT_PLAN = 'earlybird'
export const DEFAULT_BILLING = 'monthly'
export const ALLOWED_BILLING = Object.freeze(['monthly', 'yearly'])

export function normalizePlanSelection(input = {}) {
  const plan = input.plan === DEFAULT_PLAN ? input.plan : DEFAULT_PLAN
  const billing = ALLOWED_BILLING.includes(input.billing) ? input.billing : DEFAULT_BILLING

  return { plan, billing }
}

export function selectionFromUrl(url, base = 'https://gexoptions.com') {
  const parsed = new URL(url || '/', base)

  return normalizePlanSelection({
    plan: parsed.searchParams.get('plan'),
    billing: parsed.searchParams.get('billing'),
  })
}

export function selectionForPage(url, fallback = {}, base = 'https://gexoptions.com') {
  const parsed = new URL(url || '/', base)

  return normalizePlanSelection({
    plan: parsed.searchParams.has('plan') ? parsed.searchParams.get('plan') : fallback?.plan,
    billing: parsed.searchParams.has('billing') ? parsed.searchParams.get('billing') : fallback?.billing,
  })
}

export function journeyUrl(destination, selection = {}) {
  const { plan, billing } = normalizePlanSelection(selection)
  const path = destination === 'checkout'
    ? '/checkout'
    : destination === 'login'
      ? '/login'
      : '/register'

  const params = new URLSearchParams({ plan, billing })
  return `${path}?${params.toString()}`
}

export function yearlySavings(display = {}) {
  const monthly = display?.monthly?.amount_minor
  const yearly = display?.yearly?.amount_minor

  if (!Number.isInteger(monthly) || monthly < 0 || !Number.isInteger(yearly) || yearly < 0) return null
  return Math.max(0, ((monthly * 12) - yearly) / 100)
}
