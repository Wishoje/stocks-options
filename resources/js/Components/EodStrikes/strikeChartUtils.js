import { numeric } from '../UI/numbers.js'

export function normalizedStrikeRows(rows = []) {
  return (Array.isArray(rows) ? rows : []).map((row, index) => ({
    ...(row && typeof row === 'object' ? row : {}),
    __row_key: `${row?.strike ?? 'missing'}-${index}`,
    __source_index: index,
    __strike: numeric(row?.strike),
  }))
}

export function chartStrikeRows(rows = []) {
  return normalizedStrikeRows(rows)
    .filter(row => row.__strike != null)
    .sort((left, right) => left.__strike - right.__strike)
}

export function focusActivityBand(rows, accessors, {
  relativeThreshold = 0.02,
  absoluteThreshold = 0,
  padding = 8,
} = {}) {
  if (!rows.length) return []
  const maxima = accessors.map(accessor => rows.reduce((maximum, row) => {
    const value = numeric(accessor(row))
    return value == null ? maximum : Math.max(maximum, Math.abs(value))
  }, 0))

  if (maxima.every(maximum => maximum === 0)) return rows
  const thresholds = maxima.map(maximum => maximum > 0
    ? Math.max(maximum * relativeThreshold, absoluteThreshold)
    : Number.POSITIVE_INFINITY)

  let firstIndex = -1
  let lastIndex = -1
  rows.forEach((row, index) => {
    const active = accessors.some((accessor, seriesIndex) => {
      const value = numeric(accessor(row))
      return value != null && Math.abs(value) >= thresholds[seriesIndex]
    })
    if (!active) return
    if (firstIndex < 0) firstIndex = index
    lastIndex = index
  })

  if (firstIndex < 0 || lastIndex < 0) return rows
  return rows.slice(
    Math.max(0, firstIndex - padding),
    Math.min(rows.length, lastIndex + padding + 1),
  )
}

export function niceStrikeStep(step) {
  if (!Number.isFinite(step) || step <= 0) return 1
  const power = 10 ** Math.floor(Math.log10(step))
  for (const unit of [1, 2, 5, 10]) {
    const candidate = unit * power
    if (step <= candidate) return candidate
  }
  return 10 * power
}

function strikeLabel(value) {
  if (value == null) return 'Unavailable'
  return Number.isInteger(value) ? String(value) : String(Number(value.toFixed(4)))
}

export function buildStrikePoints(rows, series, {
  bucket = false,
  maxBars = 100,
} = {}) {
  if (!rows.length) return []
  const numericMaxBars = Number(maxBars)
  const barLimit = Number.isFinite(numericMaxBars) && numericMaxBars > 0
    ? Math.max(1, Math.floor(numericMaxBars))
    : 100
  if (!bucket || rows.length <= barLimit) {
    return rows.map(row => ({
      label: strikeLabel(row.__strike),
      start: row.__strike,
      end: row.__strike,
      sourceCount: 1,
      sourceRows: [row],
      ...Object.fromEntries(Object.entries(series).map(([key, accessor]) => [key, numeric(accessor(row))])),
    }))
  }

  const minimum = rows[0].__strike
  const maximum = rows.at(-1).__strike
  const step = niceStrikeStep((maximum - minimum) / Math.min(barLimit, rows.length))
  const bucketCount = Math.min(
    barLimit,
    Math.max(1, Math.ceil((maximum - minimum) / step)),
  )
  const buckets = new Map()

  rows.forEach(row => {
    const bucketIndex = Math.min(
      bucketCount - 1,
      Math.max(0, Math.floor((row.__strike - minimum) / step)),
    )
    if (!buckets.has(bucketIndex)) {
      const start = minimum + bucketIndex * step
      buckets.set(bucketIndex, {
        start,
        end: start + step,
        sourceRows: [],
        totals: Object.fromEntries(Object.keys(series).map(key => [key, { sum: 0, count: 0 }])),
      })
    }
    const target = buckets.get(bucketIndex)
    target.sourceRows.push(row)
    Object.entries(series).forEach(([key, accessor]) => {
      const value = numeric(accessor(row))
      if (value == null) return
      target.totals[key].sum += value
      target.totals[key].count += 1
    })
  })

  return [...buckets.entries()]
    .sort(([left], [right]) => left - right)
    .map(([, bucketItem]) => ({
      label: `${strikeLabel(bucketItem.start)}–${strikeLabel(bucketItem.end)}`,
      start: bucketItem.start,
      end: bucketItem.end,
      sourceCount: bucketItem.sourceRows.length,
      sourceRows: bucketItem.sourceRows,
      ...Object.fromEntries(Object.entries(bucketItem.totals).map(([key, total]) => [
        key,
        total.count ? total.sum : null,
      ])),
    }))
}

export function sumAvailable(rows, accessor) {
  let count = 0
  const sum = rows.reduce((total, row) => {
    const value = numeric(accessor(row))
    if (value == null) return total
    count += 1
    return total + value
  }, 0)
  return count ? sum : null
}

export function countAvailable(rows, accessor) {
  return rows.reduce((count, row) => count + (numeric(accessor(row)) == null ? 0 : 1), 0)
}

export function strongestByMagnitude(points, accessors) {
  return points.reduce((strongest, point) => {
    let available = false
    const magnitude = accessors.reduce((total, accessor) => {
      const value = numeric(accessor(point))
      if (value != null) available = true
      return total + (value == null ? 0 : Math.abs(value))
    }, 0)
    if (!available) return strongest
    if (!strongest || magnitude > strongest.magnitude) return { point, magnitude }
    return strongest
  }, null)?.point ?? null
}

export function chartSnapshotName(...parts) {
  const name = parts
    .filter(part => part != null && String(part).trim())
    .map(part => String(part).trim().replace(/[^a-zA-Z0-9._-]+/g, '-').replace(/^-+|-+$/g, ''))
    .filter(Boolean)
    .join('-')
  return name || 'strike-chart'
}

export function downloadChartSnapshot(chart, name = 'strike-chart') {
  const source = chart?.canvas
  if (!source) return false
  const output = document.createElement('canvas')
  output.width = source.width
  output.height = source.height
  const context = output.getContext('2d')
  if (!context) return false

  context.fillStyle = 'rgb(13,18,24)'
  context.fillRect(0, 0, output.width, output.height)
  context.drawImage(source, 0, 0)
  context.save()
  context.font = `${Math.max(28, Math.round(output.width * 0.065))}px "Inter","Segoe UI",system-ui,sans-serif`
  context.fillStyle = 'rgba(255,255,255,0.13)'
  context.textAlign = 'center'
  context.textBaseline = 'top'
  context.fillText('GexOptions.com', output.width / 2, Math.max(12, output.height * 0.04))
  context.restore()

  const link = document.createElement('a')
  link.download = `${name}-${new Date().toISOString().slice(0, 10)}.png`
  const click = href => {
    link.href = href
    document.body.appendChild(link)
    link.click()
    link.remove()
  }

  if (typeof output.toBlob === 'function' && typeof URL !== 'undefined' && typeof URL.createObjectURL === 'function') {
    output.toBlob(blob => {
      if (!blob) return
      const url = URL.createObjectURL(blob)
      click(url)
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    }, 'image/png')
  } else {
    click(output.toDataURL('image/png'))
  }
  return true
}
