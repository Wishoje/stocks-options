import { numeric } from '@/Components/UI/numbers'

export function wallCoverage(quality) {
  const share = numeric(quality?.missing_gamma_oi_share)
  const oi = numeric(quality?.total_open_interest)
  return oi > 0 && share != null && share >= 0 && share <= 1 ? 100 * (1 - share) : null
}

export function wallReadings(levels, side) {
  const fields = side === 'put' ? ['put_support', 'put_wall_2', 'put_wall_3'] : ['call_resistance', 'call_wall_2', 'call_wall_3']
  const strikes = [...new Set(fields.map(field => numeric(levels?.[field])).filter(value => value != null))]
  const rows = Array.isArray(levels?.strike_data) ? levels.strike_data : []
  return strikes.map(strike => {
    const row = rows.find(row => numeric(row.strike) === strike)
    const net = numeric(row?.net_gex)
    const call = numeric(row?.call_gex)
    const put = numeric(row?.put_gex)
    return { strike, net: net == null ? null : net * .01, call: call == null ? null : call * .01, put: put == null ? null : put * .01 }
  })
}

export function formatWallCoverage(value) {
  if (value == null) return 'Not available'
  if (value > 99.99 && value < 100) return '>99.99%'
  if (value > 0 && value < .01) return '<0.01%'
  return `${value.toFixed(2)}%`
}
