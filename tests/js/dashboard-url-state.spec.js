import { describe, expect, it } from 'vitest'
import { dashboardStateFromSearch, dashboardUrl } from '@/Support/dashboard-url-state.js'

describe('dashboard URL state', () => {
  it('restores a validated EOD context and preserves all six timeframes', () => {
    for (const timeframe of ['0d', '1d', '7d', '14d', '30d', '90d']) {
      expect(dashboardStateFromSearch(`?symbol=qqq&mode=eod&tab=positioning&timeframe=${timeframe}`)).toEqual({
        symbol: 'QQQ', mode: 'eod', tab: 'positioning', timeframe,
      })
    }
  })

  it('uses mode-compatible defaults for invalid or incompatible query values', () => {
    expect(dashboardStateFromSearch('?symbol=%20%20&mode=intraday&tab=positioning&timeframe=year')).toEqual({
      symbol: 'SPY', mode: 'intraday', tab: 'flow', timeframe: '14d',
    })
  })

  it('updates dashboard context without dropping unrelated query parameters or hashes', () => {
    expect(dashboardUrl('https://gex-profile.test/dashboard?campaign=review#chart', {
      symbol: 'aapl', mode: 'intraday', tab: 'strikes', timeframe: '7d',
    })).toBe('/dashboard?campaign=review&symbol=AAPL&mode=intraday&tab=strikes&timeframe=7d#chart')
  })
})
