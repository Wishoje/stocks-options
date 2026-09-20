import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@inertiajs/vue3', () => ({
  Link: {
    inheritAttrs: false,
    props: ['href'],
    template: '<a :href="href" v-bind="$attrs"><slot /></a>',
  },
}))

import ApplicationMark from '@/Components/ApplicationMark.vue'
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue'

describe('brand logo contracts', () => {
  it('uses a tightly cropped intrinsic SVG canvas', () => {
    const source = readFileSync(resolve(process.cwd(), 'public/marketing/gexoptions_logo.svg'), 'utf8')
    const root = source.match(/^<svg[^>]+>/)?.[0] || ''

    expect(root).toContain('viewBox="368.5 304.5 798 316"')
    expect(root).not.toMatch(/\s(?:width|height)="/)
  })

  it('exposes the same intrinsic logo dimensions in authenticated shells', () => {
    const authLogo = mount(AuthenticationCardLogo, {
      global: { mocks: { route: () => '/' } },
    })
    const authLink = authLogo.get('a')
    const authImage = authLogo.get('img')

    expect(authLink.attributes('aria-label')).toBe('GEX Options home')
    expect(authImage.attributes()).toMatchObject({
      src: '/marketing/gexoptions_logo.svg',
      alt: 'GEX Options',
      width: '798',
      height: '316',
    })

    const appMark = mount(ApplicationMark).get('img')
    expect(appMark.attributes()).toMatchObject({
      src: '/marketing/gexoptions_logo.svg',
      alt: 'GEX Options',
      width: '798',
      height: '316',
    })
  })
})
