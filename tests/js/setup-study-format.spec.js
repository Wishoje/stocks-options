import { describe, expect, it } from 'vitest'
import {
  studyAdversePercent, studyCsv, studyCsvCell, studyDollars, studyFavorablePercent,
  studyMoveDollars, studyPercentMilestone,
} from '@/Support/setupStudy'

const plan = changes => ({
  id: 'nvda-original', symbol: 'NVDA', direction: 'bullish', status: 'completed', horizon: 'intraday',
  signal_at: '2026-10-26T14:00:00Z', available_at: '2026-10-26T14:05:00Z', signal_price: 217.68,
  entry_low: 217.5, entry_high: 217.8, invalidation: 216, target: 220,
  qualified: true, followup_complete: true, confirmation_count: 2,
  metrics: { mfe_pct: .6753, mae_pct: .275, mfe_r: 1.47, mae_r: .6, observations: 12,
    strict_outcome: 'session_ended', strict_at: '2026-10-26T20:00:00Z', entry_revisited: true },
  source: { record_id: 'saved-original' }, evidence: { observed_closes: 12 }, ...changes,
})

function exported(rows) {
  const lines = studyCsv(rows).split('\r\n').map(line =>
    (line.match(/"(?:[^"]|"")*"/g) || []).map(cell => cell.slice(1, -1).replaceAll('""', '"')))
  return { headers: lines[0], rows: lines.slice(1).map(values => Object.fromEntries(lines[0].map((key, index) => [key, values[index]]))) }
}

describe('stock percentage and dollar move formatting', () => {
  it('formats favorable and adverse magnitudes with clear signs and normalizes rounded zero', () => {
    expect(studyFavorablePercent(.6753)).toBe('+0.68%')
    expect(studyFavorablePercent(-.6753)).toBe('+0.68%')
    expect(studyAdversePercent(.6753)).toBe('-0.68%')
    expect(studyAdversePercent(-.6753)).toBe('-0.68%')
    for (const value of [0, -0, .0001, -.0001]) {
      expect(studyFavorablePercent(value)).toBe('0%')
      expect(studyAdversePercent(value)).toBe('0%')
    }
  })

  it('calculates the NVDA dollar move from the archived percentage and signal stock price', () => {
    const row = plan()
    expect(studyMoveDollars(row, 'favorable')).toBeCloseTo(217.68 * .6753 / 100, 10)
    expect(studyDollars(studyMoveDollars(row, 'favorable'))).toBe('+$1.47')
    expect(studyDollars(studyMoveDollars(row, 'adverse'))).toBe('-$0.60')
    expect(studyDollars(studyMoveDollars({ ...row, direction: 'bearish' }, 'favorable'))).toBe('+$1.47')
    expect(studyMoveDollars({ ...row, metrics: { mae_pct: -.275 } }, 'adverse')).toBeCloseTo(-.59862, 8)
    expect(studyDollars(-0)).toBe('$0.00')
    expect(studyDollars(-.001)).toBe('$0.00')
    expect(studyDollars(0)).toBe('$0.00')
  })

  it('leaves missing or invalid measurements unknown and never substitutes R for percentages', () => {
    for (const value of [null, undefined, '', ' ', false, true, NaN, Infinity, -Infinity, {}, []]) {
      expect(studyFavorablePercent(value)).toBe('—')
      expect(studyAdversePercent(value)).toBe('—')
      expect(studyDollars(value)).toBe('—')
    }
    const onlyR = plan({ metrics: { mfe_r: 1.47, mae_r: .6, observations: 12 } })
    expect(studyMoveDollars(onlyR, 'favorable')).toBeNull()
    expect(studyMoveDollars(onlyR, 'adverse')).toBeNull()
    for (const signal_price of [null, 0, -100, Infinity, 'bad']) {
      expect(studyMoveDollars(plan({ signal_price }), 'favorable')).toBeNull()
    }
    expect(studyMoveDollars(plan(), 'unknown')).toBeNull()
    expect(studyMoveDollars(plan({ metrics: { mfe_pct: 0, observations: 0 } }), 'favorable')).toBeNull()
    expect(studyMoveDollars(plan({ metrics: { mfe_pct: 0, observations: 1 } }), 'favorable')).toBe(0)
  })

  it('derives percentage milestones only from actual observed percentages', () => {
    expect(studyPercentMilestone(plan(), .5)).toBe(true)
    expect(studyPercentMilestone(plan(), 1)).toBe(false)
    expect(studyPercentMilestone(plan({ followup_complete: false }), .5)).toBe(true)
    expect(studyPercentMilestone(plan({ metrics: { mfe_pct: 0, observations: 1 } }), .5)).toBe(false)
    expect(studyPercentMilestone(plan({ metrics: { mfe_pct: .7 } }), .5)).toBe(true)
    for (const metrics of [{ mfe_r: 5 }, { mfe_pct: null }, { mfe_pct: NaN }, { mfe_pct: -.7 }, { mfe_pct: .7, observations: 0 }]) {
      expect(studyPercentMilestone(plan({ metrics }), .5)).toBeNull()
    }
    expect(studyPercentMilestone(plan(), 0)).toBeNull()
  })
})

describe('readable setup study CSV', () => {
  it('leads with plain labels, Eastern times, and observed stock percentage and dollar moves', () => {
    const { headers, rows: [row] } = exported([plan()])
    expect(headers.slice(0, 4)).toEqual(['Symbol', 'Direction', 'Status', 'Result'])
    expect(row.Direction).toBe('Bullish')
    expect(row.Status).toBe('Review complete')
    expect(row.Result).toBe('Session ended')
    expect(row['Signal time (ET)']).toContain('10:00 AM ET')
    expect(row['Available time (ET)']).toContain('10:05 AM ET')
    expect(row['Best favorable stock move (%)']).toBe('0.6753')
    expect(row['Best favorable move ($)']).toBe('1.47')
    expect(row['Biggest move against idea (%)']).toBe('-0.275')
    expect(row['Adverse move ($)']).toBe('-0.6')
    expect(row['Favorable stock move of 0.5% observed']).toBe('true')
    expect(row['Favorable stock move of 1% observed']).toBe('false')
    expect(row['Follow-up complete']).toBe('true')
    const advanced = headers.findIndex(header => header.startsWith('Advanced: '))
    expect(advanced).toBe(22)
    expect(headers.slice(advanced).every(header => header.startsWith('Advanced: '))).toBe(true)
    expect(row['Advanced: Plan ID']).toBe('nvda-original')
    expect(row['Advanced: Best close R']).toBe('1.47')
    expect(row['Advanced: Source (JSON)']).toContain('saved-original')
    expect(row['Advanced: Evidence (JSON)']).toContain('observed_closes')
  })

  it('exports target-before-invalidation only for a verified completed follow-up', () => {
    const hit = plan({ metrics: { ...plan().metrics, strict_outcome: 'target_reached' } })
    const rows = exported([
      hit,
      plan({ metrics: { ...plan().metrics, strict_outcome: 'invalidated' } }),
      { ...hit, followup_complete: false },
      { ...hit, needs_update: true },
      { ...hit, metrics: { ...hit.metrics, evidence_gap: true } },
      plan({ metrics: { strict_outcome: null } }),
    ]).rows
    expect(rows.map(row => row['Target reached before invalidation'])).toEqual(['true', 'false', '', '', '', ''])
  })

  it('shows the verified NVDA invalidation as a primary result alongside its positive stock price', () => {
    const { rows: [row] } = exported([plan({ metrics: { ...plan().metrics, strict_outcome: 'invalidated' } })])
    expect(row.Symbol).toBe('NVDA')
    expect(row.Status).toBe('Review complete')
    expect(row.Result).toBe('Invalidated')
    expect(row['Signal price']).toBe('217.68')
    expect(row['Target reached before invalidation']).toBe('false')
    const statuses = ['watching', 'conditions_met', 'in_progress', 'completed', 'needs_update']
    const rows = exported(statuses.map(status => plan({ status, followup_complete: status === 'completed' }))).rows
    expect(rows.map(item => item.Status)).toEqual(['Waiting for confirmation', 'Conditions met', 'Still being tracked', 'Review complete', 'Needs review'])
    expect(rows.map(item => item.Result)).toEqual(['', '', '', 'Session ended', 'Needs review'])
  })

  it('keeps no-data percentage and dollar cells blank while retaining advanced R values', () => {
    const onlyR = plan({ metrics: { mfe_r: 1.47, mae_r: .6, observations: 0 } })
    const { rows: [row] } = exported([onlyR])
    for (const key of ['Best favorable stock move (%)', 'Best favorable move ($)', 'Biggest move against idea (%)',
      'Adverse move ($)', 'Favorable stock move of 0.5% observed', 'Favorable stock move of 1% observed']) {
      expect(row[key]).toBe('')
    }
    expect(row['Advanced: Best close R']).toBe('1.47')
    expect(studyCsv([plan({ metrics: { mfe_pct: NaN, mae_pct: Infinity, mfe_r: NaN } })])).not.toMatch(/NaN|Infinity|undefined/)
  })

  it('neutralizes formula text, preserves numeric negative cells, and exports only supplied rows', () => {
    const { rows: [row], headers } = exported([plan({ symbol: '  +SUM(1,2)', id: '=HYPERLINK("https://example.com")' })])
    expect(row.Symbol).toBe("'  +SUM(1,2)")
    expect(row['Advanced: Plan ID']).toBe("'=HYPERLINK(\"https://example.com\")")
    expect(row['Adverse move ($)']).toBe('-0.6')
    expect(studyCsvCell(-1.47)).toBe('"-1.47"')
    expect(studyCsvCell('-1.47')).toBe('"\'-1.47"')
    expect(studyCsv([]).split('\r\n')).toHaveLength(1)
    expect(exported([plan()]).rows).toHaveLength(1)
    expect(headers).not.toContain('Plan ID')
  })
})
