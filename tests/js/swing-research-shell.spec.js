import { reactive } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@/composables/useWallAlerts', () => ({ useWallAlerts: owner => ({ owner }) }))
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<div />' }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, router: { post: vi.fn(), put: vi.fn() } }))
const wrappers = []
function start(admin, demo = false) {
  const page = reactive({ props: { setupStudyAdmin: admin, auth: { user: { id: 19, name: 'Owner', current_team: {}, all_teams: [] } }, jetstream: {} } })
  const route = name => name ? `/${name}` : { current: () => false }
  const wrapper = mount(AppLayout, { props: { title: 'Research', researchDemo: demo }, global: { config: { globalProperties: { $page: page } }, mocks: { route }, stubs: { Banner: true, ApplicationMark: true, WallAlertCenter: true, Dropdown: true, DropdownLink: true, NavLink: true, ResponsiveNavLink: true } } })
  wrappers.push(wrapper)
  return { wrapper, page }
}
beforeEach(() => { axios.get.mockReset(); axios.get.mockResolvedValue({ data: { items: [], unread_count: 0, truncated: false } }) })
afterEach(() => wrappers.splice(0).forEach(wrapper => wrapper.unmount()))
it('only gives research alerts a polling owner for the shared private access flag', async () => {
  const { page } = start(false); await flushPromises(); expect(axios.get).not.toHaveBeenCalled()
  page.props.setupStudyAdmin = true; await flushPromises()
  expect(axios.get).toHaveBeenCalledTimes(1); expect(axios.get.mock.calls[0][0]).toBe('/api/swing-research/alerts')
  const signal = axios.get.mock.calls[0][1].signal
  page.props.setupStudyAdmin = false; await flushPromises(); expect(signal.aborted).toBe(false)
  expect(axios.get).toHaveBeenCalledTimes(1)
})
it('does not request real research alerts in initial synthetic mode and aborts on entry to synthetic mode', async () => {
  const demo = start(true, true); await flushPromises(); expect(axios.get).not.toHaveBeenCalled()
  let resolve
  axios.get.mockImplementation(() => new Promise(done => { resolve = done }))
  await demo.wrapper.setProps({ researchDemo: false }); expect(axios.get).toHaveBeenCalledTimes(1)
  const signal = axios.get.mock.calls[0][1].signal
  await demo.wrapper.setProps({ researchDemo: true }); expect(signal.aborted).toBe(true)
  resolve({ data: { items: [], unread_count: 0 } }); await flushPromises()
  expect(axios.post).not.toHaveBeenCalled()
})
