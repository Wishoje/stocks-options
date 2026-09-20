import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import PricingTable from '@/Components/Marketing/PricingTable.vue'

let wrapper

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
})

const offer = {
  plan: 'earlybird',
  label: 'Early Bird',
  trial_days: 7,
  display: {
    currency: 'USD',
    monthly: { amount_minor: 2999, interval: 'month' },
    yearly: { amount_minor: 29900, interval: 'year' },
  },
}

describe('pricing table', () => {
  it('renders only configured offer values and emits the selected billing interval', async () => {
    wrapper = mount(PricingTable, {
      props: {
        offer,
        modelValue: 'monthly',
        features: ['Dashboard access'],
        ctaLabel: 'Continue',
      },
    })

    expect(wrapper.text()).toContain('$29.99')
    expect(wrapper.text()).toContain('$299')
    expect(wrapper.text()).toContain('Save $60.88')
    expect(wrapper.text()).toContain('Dashboard access')

    const intervalButtons = wrapper.findAll('[role="radio"]')
    await intervalButtons[1].trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([['yearly']])

    await wrapper.get('.pricing-card__cta').trigger('click')
    expect(wrapper.emitted('select')).toEqual([['monthly']])
  })

  it('exposes one named radio group with a roving tab stop and complete keyboard navigation', async () => {
    wrapper = mount(PricingTable, {
      attachTo: document.body,
      props: {
        offer,
        modelValue: 'monthly',
      },
    })

    const group = wrapper.get('[role="radiogroup"]')
    expect(group.attributes('aria-labelledby')).toBe('pricing-billing-interval-label')
    expect(wrapper.get('#pricing-billing-interval-label').text()).toBe('Billing interval')

    const assertSelection = expected => {
      const radios = wrapper.findAll('[role="radio"]')
      const selectedIndex = expected === 'monthly' ? 0 : 1
      expect(radios[selectedIndex].attributes()).toMatchObject({
        'aria-checked': 'true',
        tabindex: '0',
      })
      expect(radios[1 - selectedIndex].attributes()).toMatchObject({
        'aria-checked': 'false',
        tabindex: '-1',
      })
    }

    const useKey = async (fromIndex, key, expected) => {
      const radios = wrapper.findAll('[role="radio"]')
      radios[fromIndex].element.focus()
      await radios[fromIndex].trigger('keydown', { key })
      expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([expected])
      expect(document.activeElement).toBe(wrapper.findAll('[role="radio"]')[expected === 'monthly' ? 0 : 1].element)
      await wrapper.setProps({ modelValue: expected })
      assertSelection(expected)
    }

    assertSelection('monthly')
    await useKey(0, 'ArrowRight', 'yearly')
    await useKey(1, 'ArrowDown', 'monthly')
    await useKey(0, 'ArrowLeft', 'yearly')
    await useKey(1, 'ArrowUp', 'monthly')
    await useKey(0, 'End', 'yearly')
    await useKey(1, 'Home', 'monthly')
  })

  it('shows an unavailable state instead of inventing prices', () => {
    wrapper = mount(PricingTable, {
      props: {
        offer: { plan: 'earlybird', label: 'Early Bird', trial_days: 7, display: { currency: 'USD' } },
        modelValue: 'monthly',
      },
    })

    expect(wrapper.text()).toContain('Price unavailable')
    expect(wrapper.text()).not.toContain('$29.99')
    expect(wrapper.text()).not.toContain('$299')
    expect(wrapper.get('.pricing-card__cta').attributes('disabled')).toBeDefined()
    expect(wrapper.findAll('[role="radio"]').every(radio => radio.attributes('tabindex') === '-1')).toBe(true)
  })
})
