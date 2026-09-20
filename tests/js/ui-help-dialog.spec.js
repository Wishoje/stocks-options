import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import UiHelpDialog from '@/Components/UI/UiHelpDialog.vue'

describe('UiHelpDialog', () => {
  let wrapper

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    document.body.innerHTML = ''
    document.body.style.overflow = ''
  })

  function mountDialog() {
    const host = document.createElement('div')
    document.body.append(host)
    wrapper = mount(UiHelpDialog, {
      attachTo: host,
      props: {
        id: 'test-reading-guide',
        title: 'How to read this panel',
      },
      slots: {
        default: '<p>Use the headline first.</p><a href="/learn">Learn more</a>',
      },
    })
    return wrapper
  }

  it('opens in a modal dialog, locks page scrolling and restores trigger focus', async () => {
    document.body.style.overflow = 'clip'
    const view = mountDialog()
    const trigger = view.get('button')
    trigger.element.focus()

    expect(trigger.attributes()).toMatchObject({
      'aria-haspopup': 'dialog',
      'aria-controls': 'test-reading-guide',
      'aria-expanded': 'false',
    })

    await trigger.trigger('click')
    const dialog = document.querySelector('#test-reading-guide')
    const close = dialog.querySelector('.gex-help-dialog__close')

    expect(dialog.getAttribute('role')).toBe('dialog')
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(dialog.getAttribute('aria-labelledby')).toBe('test-reading-guide-title')
    expect(dialog.textContent).toContain('How to read this panel')
    expect(document.body.style.overflow).toBe('hidden')
    expect(document.activeElement).toBe(close)
    expect(trigger.attributes('aria-expanded')).toBe('true')

    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await view.vm.$nextTick()

    expect(document.querySelector('#test-reading-guide')).toBeNull()
    expect(document.body.style.overflow).toBe('clip')
    expect(document.activeElement).toBe(trigger.element)
    expect(trigger.attributes('aria-expanded')).toBe('false')
  })

  it('traps keyboard focus and closes from the backdrop', async () => {
    const view = mountDialog()
    const trigger = view.get('button')
    await trigger.trigger('click')

    const dialog = document.querySelector('#test-reading-guide')
    const close = dialog.querySelector('.gex-help-dialog__close')
    const link = dialog.querySelector('a')

    link.focus()
    link.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }))
    expect(document.activeElement).toBe(close)

    close.focus()
    close.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true }))
    expect(document.activeElement).toBe(link)

    document.querySelector('.gex-help-dialog__backdrop').click()
    await view.vm.$nextTick()
    expect(document.querySelector('#test-reading-guide')).toBeNull()
    expect(document.activeElement).toBe(trigger.element)
  })

  it('restores the existing page scroll setting when unmounted while open', async () => {
    document.body.style.overflow = 'auto'
    const view = mountDialog()
    await view.get('button').trigger('click')
    expect(document.body.style.overflow).toBe('hidden')

    view.unmount()
    wrapper = null
    expect(document.body.style.overflow).toBe('auto')
  })
})
