import { onBeforeUnmount, onMounted, ref } from 'vue'

export const reducedMotionQuery = '(prefers-reduced-motion: reduce)'

export function activateMarketingMotion(root, dependencies = {}) {
  if (!root) return () => {}

  const windowRef = dependencies.window ?? globalThis.window
  const Observer = dependencies.IntersectionObserver ?? windowRef?.IntersectionObserver
  const preference = windowRef?.matchMedia?.(reducedMotionQuery)
  let observer = null

  const revealItems = () => Array.from(root.querySelectorAll('[data-reveal]'))
  const showAll = () => {
    observer?.disconnect()
    observer = null
    root.classList.remove('mk-motion-ready')
    revealItems().forEach(item => { item.dataset.revealState = 'visible' })
  }

  const observe = () => {
    observer?.disconnect()
    observer = null

    const items = revealItems()
    if (preference?.matches || typeof Observer !== 'function' || !items.length) {
      showAll()
      return
    }

    items.forEach(item => { item.dataset.revealState = 'pending' })
    root.classList.add('mk-motion-ready')
    try {
      observer = new Observer(entries => {
        entries.forEach(entry => {
          if (!entry.isIntersecting) return
          entry.target.dataset.revealState = 'visible'
          observer?.unobserve(entry.target)
        })
      }, {
        threshold: 0.12,
        rootMargin: '0px 0px -8% 0px',
      })
      items.forEach(item => observer.observe(item))
    } catch {
      showAll()
    }
  }

  const handlePreferenceChange = () => {
    if (preference.matches) showAll()
    else observe()
  }

  preference?.addEventListener?.('change', handlePreferenceChange)
  observe()

  return () => {
    preference?.removeEventListener?.('change', handlePreferenceChange)
    showAll()
  }
}

export function useMarketingMotion() {
  const motionRoot = ref(null)
  let stop = () => {}

  onMounted(() => { stop = activateMarketingMotion(motionRoot.value) })
  onBeforeUnmount(() => stop())

  return motionRoot
}
