<template>
  <div class="min-h-screen bg-[#070A12] text-white flex flex-col">
    <!-- Background glow -->
    <div class="pointer-events-none fixed inset-0 -z-10">
      <div class="absolute -top-40 left-1/2 h-[520px] w-[520px] -translate-x-1/2 rounded-full bg-cyan-500/15 blur-[120px]" />
      <div class="absolute -bottom-40 left-1/3 h-[520px] w-[520px] rounded-full bg-blue-500/15 blur-[120px]" />
    </div>

    <!-- Nav -->
    <header class="sticky top-0 z-50 border-b border-white/10 bg-[#070A12]/70 backdrop-blur">
      <div class="w-full px-2 sm:px-4">
        <div class="flex h-24 items-center justify-between">
          <!-- LEFT -->
          <Link :href="route('home')" class="flex items-center gap-4 overflow-visible">
            <img
              src="/marketing/gexoptions_logo.svg"
              alt="GEX Options"
              class="h-24 sm:h-28 md:h-32 w-auto -my-3"
            />
            <div class="hidden md:block text-xs text-white/50 leading-tight">
              Analytics Terminal
            </div>
          </Link>

          <!-- RIGHT -->
          <div class="flex items-center gap-8">
            <nav class="hidden items-center gap-8 md:flex">
              <Link :href="route('features')" class="text-sm text-white/70 hover:text-white">Features</Link>
              <Link :href="route('contact')" class="text-sm text-white/70 hover:text-white">Contact</Link>

              <template v-if="page.props.auth?.user">
                <Link :href="route('pricing')" class="text-sm text-white/70 hover:text-white">
                  Pricing
                </Link>

                <button
                  type="button"
                  @click="goCheckout"
                  class="text-sm rounded-lg bg-white/10 px-3 py-2 hover:bg-white/15"
                >
                  Finish Checkout
                </button>

                <button type="button" @click="logout" class="text-sm text-white/70 hover:text-white">
                  Logout
                </button>
              </template>

              <template v-else>
                <Link :href="route('login')" class="text-sm text-white/70 hover:text-white">Login</Link>
                <Link :href="route('register')" class="text-sm rounded-lg bg-white/10 px-3 py-2 hover:bg-white/15">
                  Start Free
                </Link>
              </template>
            </nav>
          </div>
        </div>
      </div>
    </header>

    <main class="flex-1">
      <slot />
    </main>

    <footer class="mt-20 border-t border-white/10">
      <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
          <div class="text-sm text-white/60">
            &copy; {{ year }} GEX Options, Inc. All rights reserved.
          </div>
          <div class="flex items-center gap-6 text-sm">
            <Link :href="route('features')" class="text-white/60 hover:text-white">Features</Link>
            <Link :href="route('pricing')" class="text-white/60 hover:text-white">Pricing</Link>
            <Link :href="route('contact')" class="text-white/60 hover:text-white">Contact</Link>
            <a href="mailto:support@gexlevels.com" class="text-white/40 hover:text-white/70">support@gexoptions.com</a>
          </div>
        </div>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { Link, router, usePage } from '@inertiajs/vue3'

const page = usePage()
const year = new Date().getFullYear()

function logout() {
  // Jetstream logout route is POST
  router.post(route('logout'))
}

function goCheckout() {
  window.location.assign('/checkout?plan=earlybird&billing=monthly')
}
</script>
