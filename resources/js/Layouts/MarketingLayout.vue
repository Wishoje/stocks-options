<template>
  <div class="marketing-site">
    <a class="mk-skip-link" href="#marketing-main">Skip to main content</a>

    <header ref="header" class="mk-header">
      <div class="mk-container">
        <div class="mk-header-row">
          <Link href="/" class="mk-brand mk-brand--header" aria-label="GEX Options home" @click="closeMobileMenu(false)">
            <img
              src="/marketing/gexoptions_logo.svg"
              alt="GEX Options"
              class="mk-brand__logo"
              width="798"
              height="316"
            />
            <span>Options analytics</span>
          </Link>

          <nav class="mk-nav mk-desktop-nav" aria-label="Primary navigation">
            <Link
              v-for="item in publicLinks"
              :key="item.href"
              :href="item.href"
              class="mk-nav-link"
              :aria-current="isActive(item.href) ? 'page' : undefined"
            >{{ item.label }}</Link>

            <template v-if="user">
              <Link href="/user/profile" class="mk-nav-link" :aria-current="isActive('/user/profile') ? 'page' : undefined">Profile</Link>
              <MarketingCta location="marketing_nav" source="marketing_nav" />
              <button class="mk-nav-action" type="button" @click="logout">Log out</button>
            </template>
            <template v-else>
              <Link :href="loginHref" class="mk-nav-link">Log in</Link>
              <MarketingCta location="marketing_nav" source="marketing_nav" guest-label="Start free trial" />
            </template>
          </nav>

          <button
            ref="menuButton"
            class="mk-menu-button"
            type="button"
            aria-label="Toggle navigation menu"
            aria-controls="marketing-mobile-navigation"
            :aria-expanded="mobileOpen"
            @click="toggleMobileMenu"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path v-if="!mobileOpen" d="M4 6h16M4 12h16M4 18h16" />
              <path v-else d="M6 6l12 12M18 6L6 18" />
            </svg>
          </button>
        </div>

        <div v-if="mobileOpen" id="marketing-mobile-navigation" ref="mobilePanel" class="mk-mobile-panel">
          <nav class="mk-nav" aria-label="Mobile navigation">
            <Link
              v-for="item in publicLinks"
              :key="item.href"
              :href="item.href"
              class="mk-nav-link"
              :aria-current="isActive(item.href) ? 'page' : undefined"
              @click="closeMobileMenu(false)"
            >{{ item.label }}</Link>

            <template v-if="user">
              <Link href="/user/profile" class="mk-nav-link" @click="closeMobileMenu(false)">Profile</Link>
              <MarketingCta location="marketing_mobile_nav" source="marketing_nav" />
              <button class="mk-nav-action" type="button" @click="logout">Log out</button>
            </template>
            <template v-else>
              <Link :href="loginHref" class="mk-nav-link" @click="closeMobileMenu(false)">Log in</Link>
              <MarketingCta location="marketing_mobile_nav" source="marketing_nav" guest-label="Start free trial" />
            </template>
          </nav>
        </div>
      </div>
    </header>

    <main id="marketing-main" tabindex="-1"><slot /></main>

    <footer class="mk-footer">
      <div class="mk-container mk-footer-grid">
        <div>
          <Link href="/" class="mk-brand mk-brand--footer" aria-label="GEX Options home">
            <img
              src="/marketing/gexoptions_logo.svg"
              alt="GEX Options"
              class="mk-brand__logo"
              width="798"
              height="316"
              loading="lazy"
            />
          </Link>
          <p class="mk-meta">Options analytics for market levels, positioning, and activity.</p>
          <p class="mk-meta">&copy; {{ year }} GEX Options.</p>
        </div>
        <nav class="mk-footer-links" aria-label="Footer navigation">
          <Link v-for="item in publicLinks" :key="item.href" :href="item.href">{{ item.label }}</Link>
          <Link v-for="item in legalLinks" :key="item.href" :href="item.href">{{ item.label }}</Link>
          <a href="mailto:support@gexoptions.com">support@gexoptions.com</a>
        </nav>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import MarketingCta from '@/Components/Marketing/MarketingCta.vue'
import { journeyUrl, selectionForPage } from '@/Support/marketing-journey'
import '../../css/marketing-refresh.css'

const page = usePage()
const year = new Date().getFullYear()
const mobileOpen = ref(false)
const menuButton = ref(null)
const mobilePanel = ref(null)
const header = ref(null)
const user = computed(() => page.props.auth?.user ?? null)
const loginHref = computed(() => journeyUrl('login', selectionForPage(page.url || '/', page.props.billing?.intent)))

const publicLinks = Object.freeze([
  { label: 'Home', href: '/' },
  { label: 'Features', href: '/features' },
  { label: 'Pricing', href: '/pricing' },
  { label: 'Contact', href: '/contact' },
])

const legalLinks = Object.freeze([
  { label: 'Terms', href: '/terms-of-service' },
  { label: 'Privacy', href: '/privacy-policy' },
])

function isActive(href) {
  const path = (page.url || '/').split('?')[0]
  return href === '/' ? path === '/' : path === href || path.startsWith(`${href}/`)
}

async function toggleMobileMenu() {
  mobileOpen.value = !mobileOpen.value
  if (mobileOpen.value) {
    await nextTick()
    mobilePanel.value?.querySelector('a, button')?.focus()
  }
}

function closeMobileMenu(restoreFocus = true) {
  if (!mobileOpen.value) return
  mobileOpen.value = false
  if (restoreFocus) nextTick(() => menuButton.value?.focus())
}

function handleGlobalKeydown(event) {
  if (event.key === 'Escape' && mobileOpen.value) closeMobileMenu()
}

function handleOutsidePointer(event) {
  if (mobileOpen.value && !header.value?.contains(event.target)) closeMobileMenu(false)
}

function logout() {
  closeMobileMenu(false)
  router.post('/logout')
}

watch(() => page.url, () => closeMobileMenu(false))

onMounted(() => {
  document.addEventListener('keydown', handleGlobalKeydown)
  document.addEventListener('pointerdown', handleOutsidePointer)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleGlobalKeydown)
  document.removeEventListener('pointerdown', handleOutsidePointer)
})
</script>
