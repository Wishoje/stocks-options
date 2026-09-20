import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

describe('dashboard mobile overflow containment', () => {
  it('constrains the tab nav while preserving the tab strip as its own scroller', () => {
    const css = readFileSync(resolve(process.cwd(), 'resources/css/dashboard-refresh.css'), 'utf8')

    expect(css).toMatch(/\.gex-dashboard-context__secondary > nav\s*\{[^}]*min-width:\s*0;[^}]*max-width:\s*100%;[^}]*\}/s)

    const mobileRules = css.slice(css.indexOf('@media (max-width: 600px)'))
    expect(mobileRules).toMatch(/\.gex-dashboard-context__secondary > nav\s*\{\s*width:\s*100%;\s*\}/)
    expect(mobileRules).toMatch(/\.gex-dashboard-context__secondary \.gex-tabs\s*\{[^}]*width:\s*100%;[^}]*overflow-x:\s*auto;[^}]*\}/s)
  })
})
