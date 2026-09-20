export function numeric(value) {
  if (value == null || value === '' || typeof value === 'boolean') return null
  if (typeof value === 'string' && !value.trim()) return null
  const number = Number(value)
  return Number.isFinite(number) ? number : null
}
export function compact(value) {
  const n = numeric(value)
  if (n == null) return 'Unavailable'
  if (n === 0) return '0'
  const magnitude = Math.abs(n), unit = magnitude >= 1e9 ? 1e9 : magnitude >= 1e6 ? 1e6 : magnitude >= 1e3 ? 1e3 : 1
  const scaled = magnitude / unit
  const display = unit === 1
    ? String(Number(magnitude < 1 ? scaled.toPrecision(3) : scaled.toFixed(2)))
    : scaled.toFixed(2)
  return `${n < 0 ? '−' : '+'}${display}${unit === 1e9 ? 'B' : unit === 1e6 ? 'M' : unit === 1e3 ? 'K' : ''}`
}
export function dateLabel(value) {
  const date = new Date(`${value}T12:00:00Z`)
  return Number.isNaN(date.getTime()) ? String(value ?? 'Unavailable') : new Intl.DateTimeFormat('en-US', { month:'short', day:'numeric', timeZone:'UTC' }).format(date)
}
