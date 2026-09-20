import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import UiTabs from '../../resources/js/Components/UI/UiTabs.vue'
import UiHistory from '../../resources/js/Components/UI/UiHistory.vue'
import UiDataTable from '../../resources/js/Components/UI/UiDataTable.vue'
import UiExposureChart from '../../resources/js/Components/UI/UiExposureChart.vue'
import UiSelect from '../../resources/js/Components/UI/UiSelect.vue'
import UiMetric from '../../resources/js/Components/UI/UiMetric.vue'
import UiTooltip from '../../resources/js/Components/UI/UiTooltip.vue'
import UiBadge from '../../resources/js/Components/UI/UiBadge.vue'
import UiButton from '../../resources/js/Components/UI/UiButton.vue'
import Gallery from '../../ui-preview/Gallery.vue'
import { compact, numeric } from '../../resources/js/Components/UI/numbers.js'
import { fixture, buckets, scenarios } from '../../ui-preview/fixtures.js'

describe('data-preserving UI foundations', () => {
  it('distinguishes missing values from numeric zero and retains signs', () => {
    for (const value of [null,undefined,'',' ',false,true,NaN,Infinity,'unknown']) expect(numeric(value)).toBeNull()
    expect(numeric('0')).toBe(0)
    expect(compact(0)).toBe('0')
    expect(compact(null)).toBe('Unavailable')
    expect(compact(-4420000)).toContain('−4.42M')
    expect(compact(4420000)).toContain('+4.42M')
    expect(compact(.4)).toBe('+0.4')
    expect(compact(-.004)).toBe('−0.004')
  })
  it('keeps every recorded expiry and every date in each bucket', () => {
    for(const bucket of buckets) {
      const sample=fixture('recorded',bucket.value)
      expect(sample.expiry).toHaveLength(40)
      expect(sample.history).toHaveLength(30)
      expect(new Set(sample.expiry.map(r=>r.date)).size).toBe(40)
      expect(new Set(sample.history.map(r=>r.date)).size).toBe(30)
      expect(sample.expiry.some(r=>r.value===0)).toBe(true)
      expect(sample.expiry.some(r=>r.date<'2026-09-09')).toBe(true)
    }
  })
  it('creates independent synthetic data without changing the recorded fixture', () => {
    const original=fixture('recorded','0d')
    for(const sample of scenarios) fixture(sample.value,'0d')
    expect(fixture('recorded','0d')).toEqual(original)
    expect(fixture('zero','0d').expiry.every(r=>r.value===0)).toBe(true)
    expect(fixture('sparse','0d').history.some(r=>r.value===null)).toBe(true)
  })
  it('sorts all rows without mutating source data and keeps missing values last', async () => {
    const rows=Object.freeze([{date:'A',value:0},{date:'B',value:null},{date:'C',value:-12},{date:'D',value:20}].map(Object.freeze))
    const wrapper=mount(UiDataTable,{props:{caption:'Test readings',rows,rowKey:'date',columns:[{key:'date',label:'Date'},{key:'value',label:'Value',sortable:true,numeric:true}]}})
    const order=()=>wrapper.findAll('tbody tr').map(r=>r.findAll('td')[0].text())
    await wrapper.get('button').trigger('click')
    expect(order()).toEqual(['C','A','D','B'])
    expect(wrapper.get('[aria-sort]').attributes('aria-sort')).toBe('ascending')
    await wrapper.get('button').trigger('click')
    expect(order()).toEqual(['D','A','C','B'])
    expect(rows.map(r=>r.date)).toEqual(['A','B','C','D'])
    expect(wrapper.text()).toContain('Unavailable')
  })
  it('exposes exact signed chart values to keyboard and touch selection', async () => {
    const items=[{date:'2026-09-01',value:-1000000},{date:'2026-09-02',value:0},{date:'2026-09-03',value:null}]
    const wrapper=mount(UiExposureChart,{attachTo:document.body,props:{items,modelValue:'2026-09-01'}})
    const buttons=wrapper.findAll('button')
    expect(buttons).toHaveLength(3)
    expect(buttons[0].attributes('aria-label')).toContain('-1,000,000 share equivalents, negative')
    expect(buttons[1].find('.gex-zero').exists()).toBe(true)
    expect(buttons[2].find('.gex-bar').exists()).toBe(false)
    expect(buttons[2].text()).toContain('Unavailable')
    expect(wrapper.get('.gex-axis-track').text()).toContain('−1.00M0+1.00M')
    expect(buttons.map(button=>button.attributes('tabindex'))).toEqual(['0','-1','-1'])
    await buttons[0].trigger('keydown',{key:'ArrowDown'})
    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['2026-09-02'])
    expect(document.activeElement).toBe(buttons[1].element)
    await buttons[0].trigger('click')
    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['2026-09-01'])
    wrapper.unmount()
  })
  it('supports keyboard tab navigation and skips disabled choices', async () => {
    const wrapper=mount(UiTabs,{attachTo:document.body,props:{id:'test',label:'Test tabs',modelValue:'a',items:[{value:'a',label:'A'},{value:'b',label:'B',disabled:true},{value:'c',label:'C'}]}})
    await wrapper.get('#test-a').trigger('keydown',{key:'ArrowRight'})
    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['c'])
    expect(document.activeElement.id).toBe('test-c')
    await wrapper.get('#test-c').trigger('keydown',{key:'Home'})
    expect(document.activeElement.id).toBe('test-a')
    await wrapper.get('#test-a').trigger('keydown',{key:'ArrowLeft'})
    expect(document.activeElement.id).toBe('test-c')
    wrapper.unmount()
  })
  it('preserves numeric select option types and recovers an unavailable tab selection', async () => {
    const select=mount(UiSelect,{props:{label:'Limit',modelValue:50,options:[{value:50,label:'50'},{value:100,label:'100'}],disabled:true},attrs:{name:'limit'}})
    expect(select.get('select').attributes('disabled')).toBeDefined()
    expect(select.get('select').attributes('name')).toBe('limit')
    await select.setProps({disabled:false})
    await select.get('select').setValue('100')
    expect(select.emitted('update:modelValue')[0]).toEqual([100])
    const tabs=mount(UiTabs,{props:{id:'fallback',label:'Fallback',modelValue:'removed',items:[{value:'a',label:'A'},{value:'b',label:'B',disabled:true}]}})
    expect(tabs.emitted('update:modelValue')[0]).toEqual(['a'])
    expect(tabs.get('#fallback-a').attributes('tabindex')).toBe('0')
    expect(tabs.get('#fallback-a').attributes('aria-selected')).toBe('true')
  })
  it('uses a safe button type by default and supports native form submission', () => {
    const regular = mount(UiButton, { slots: { default: 'Regular action' } })
    const submit = mount(UiButton, { props: { type: 'submit' }, slots: { default: 'Apply filters' } })
    expect(regular.get('button').attributes('type')).toBe('button')
    expect(submit.get('button').attributes('type')).toBe('submit')
  })
  it('does not append a unit to unavailable metrics and exposes help by touch or keyboard', async () => {
    const metric=mount(UiMetric,{props:{label:'Missing',value:'',unit:'pp',tone:'warning',prominence:'primary'}})
    expect(metric.text()).toBe('MissingUnavailable')
    expect(metric.attributes('data-tone')).toBe('warning')
    expect(metric.attributes('data-prominence')).toBe('primary')
    const badge=mount(UiBadge,{props:{tone:'positive'},slots:{default:'Current'}})
    expect(badge.text()).toBe('Current')
    expect(badge.attributes('data-tone')).toBe('positive')
    const tooltip=mount(UiTooltip,{props:{label:'Explain'},slots:{default:'Exact explanation'}})
    expect(tooltip.get('button').attributes('aria-expanded')).toBe('false')
    await tooltip.get('button').trigger('click')
    expect(tooltip.get('[role=tooltip]').isVisible()).toBe(true)
    tooltip.get('button').element.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))
    await nextTick()
    expect(tooltip.get('button').attributes('aria-expanded')).toBe('false')
    expect(tooltip.find('[role=tooltip]').exists()).toBe(false)
  })
  it('keeps the history chart visible while readings and calculation details start collapsed', async () => {
    const wrapper=mount(UiHistory,{props:{items:fixture('recorded','1w').history,scopeKey:'1w',explanation:'Calculation remains available'}})
    expect(wrapper.get('h3').element.closest('details')).toBeNull()
    expect(wrapper.get('[data-testid="history-readings-disclosure"]').attributes('open')).toBeUndefined()
    expect(wrapper.get('[data-testid="history-calculation-disclosure"]').attributes('open')).toBeUndefined()
    expect(wrapper.findAll('tbody tr')).toHaveLength(30)
    expect(wrapper.findAll('.gex-history-latest-halo')).toHaveLength(1)
    await wrapper.get('input[type=range]').setValue(0)
    expect(wrapper.get('[aria-live]').text()).toContain('2026-07-30')
    await wrapper.setProps({items:fixture('recorded','0d').history,scopeKey:'0d'})
    expect(wrapper.get('[aria-live]').text()).toContain('2026-09-09')
    expect(wrapper.get('[aria-live]').text()).toContain('0 pp')
  })
  it('retains an inspected history date across polling and resets it for a new scope', async () => {
    const initial=fixture('recorded','1w').history
    const wrapper=mount(UiHistory,{props:{items:initial,scopeKey:'1w'}})
    await wrapper.get('input[type=range]').setValue(4)
    const chosen=wrapper.get('[aria-live]').text()
    await wrapper.setProps({items:initial.map(row=>({...row}))})
    expect(wrapper.get('[aria-live]').text()).toBe(chosen)
    await wrapper.setProps({items:fixture('recorded','0d').history,scopeKey:'0d'})
    expect(wrapper.get('[aria-live]').text()).toContain('2026-09-09')
  })
  it('shows gaps for missing history and finite geometry for zero or empty series', () => {
    for(const scenario of ['zero','empty','sparse','positive','negative']) {
      const wrapper=mount(UiHistory,{props:{items:fixture(scenario,'0d').history}})
      const pathNode=wrapper.find('path')
      const path=pathNode.exists() ? pathNode.attributes('d') : ''
      expect(path).not.toMatch(/NaN|Infinity/)
      if(scenario==='sparse') expect((path.match(/M/g)||[])).toHaveLength(2)
      if(scenario==='empty') { expect(wrapper.get('input').attributes('disabled')).toBeDefined(); expect(pathNode.exists()).toBe(false) }
      wrapper.unmount()
    }
  })
  it('shows isolated history readings, accessible slider values, and explicit unavailable states', async () => {
    const isolated=mount(UiHistory,{props:{items:[{date:'2026-09-01',value:null},{date:'2026-09-02',value:.004},{date:'2026-09-03',value:null}],scopeKey:'isolated'}})
    expect(isolated.findAll('.gex-history-dot')).toHaveLength(1)
    expect(isolated.get('.gex-history-dot').attributes('cx')).not.toMatch(/NaN|Infinity/)
    expect(isolated.get('input[type=range]').attributes('aria-valuetext')).toBe('2026-09-03, unavailable')
    await isolated.get('input[type=range]').setValue(1)
    expect(isolated.get('input[type=range]').attributes('aria-valuetext')).toContain('2026-09-02, +0.004 pp')
    const single=mount(UiHistory,{props:{items:[{date:'2026-09-02',value:2}]}})
    expect(Number(single.get('.gex-history-dot').attributes('cx'))).toBeGreaterThan(100)
    const empty=mount(UiHistory,{props:{items:[]}})
    expect(empty.get('.gex-chart-empty').text()).toContain('No history readings')
    const unavailable=mount(UiHistory,{props:{items:[{date:'2026-09-02',value:null}]}})
    expect(unavailable.get('.gex-chart-empty').text()).toContain('Readings unavailable for these dates')
  })
  it('keeps all readings through bucket changes and restores data on retry', async () => {
    const wrapper=mount(Gallery)
    expect(wrapper.get('.preview-topbar').text()).toContain('GEX Options')
    expect(wrapper.findAll('.preview-context .gex-badge')).toHaveLength(4)
    expect(wrapper.findAll('.gex-exposure-row')).toHaveLength(40)
    await wrapper.get('#skew-0d').trigger('click')
    expect(wrapper.get('#skew-panel').attributes('aria-labelledby')).toBe('skew-0d')
    expect(wrapper.get('input[type=range]').attributes('max')).toBe('29')
    await wrapper.findAll('select')[2].setValue('error')
    expect(wrapper.findAll('.gex-exposure-row')).toHaveLength(0)
    expect(wrapper.find('[role=alert]').exists()).toBe(true)
    await wrapper.get('[role=alert] button').trigger('click')
    expect(wrapper.findAll('.gex-exposure-row')).toHaveLength(40)
    expect(wrapper.findAll('tbody tr')).toHaveLength(70)
  })
})
