<template>
  <div class="min-h-screen bg-[#070A12] text-white">
    <!-- Background glow -->
    <div class="pointer-events-none fixed inset-0 -z-10">
      <div class="absolute -top-40 left-1/2 h-[520px] w-[520px] -translate-x-1/2 rounded-full bg-cyan-500/15 blur-[120px]" />
      <div class="absolute -bottom-40 left-1/3 h-[520px] w-[520px] rounded-full bg-blue-500/15 blur-[120px]" />
    </div>

    <!-- Nav -->
    <header class="sticky top-0 z-50 border-b border-white/10 bg-[#070A12]/70 backdrop-blur">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">
          <Link :href="route('home')" class="flex items-center gap-2">
            <div class="h-9 w-9 rounded-xl bg-gradient-to-br from-cyan-500 to-blue-600 shadow-lg shadow-cyan-500/20" />
            <div class="leading-tight">
              <div class="font-semibold tracking-tight">GEXOptions</div>
              <div class="text-xs text-white/60 -mt-0.5">Analytics Terminal</div>
            </div>
          </Link>

          <nav class="hidden items-center gap-8 md:flex">
            <Link :href="route('features')" class="text-sm text-white/70 hover:text-white">Features</Link>
            <Link :href="route('pricing')" class="text-sm text-white/70 hover:text-white">Pricing</Link>
          </nav>

          <div class="flex items-center gap-3">
            <Link
              v-if="!$page.props.auth?.user"
              :href="route('login')"
              class="hidden rounded-lg px-3 py-2 text-sm text-white/70 hover:text-white md:inline-flex"
            >
              Log in
            </Link>

            <Link
              v-if="$page.props.auth?.user"
              :href="route('dashboard')"
              class="rounded-lg bg-white/10 px-3 py-2 text-sm hover:bg-white/15"
            >
              Open App
            </Link>

            <Link
              v-else
              :href="route('register')"
              class="rounded-lg bg-gradient-to-r from-cyan-500 to-blue-600 px-3 py-2 text-sm font-semibold shadow-lg shadow-cyan-500/20 hover:opacity-95"
            >
              Start Free
            </Link>
          </div>
        </div>
      </div>
    </header>

    <main>
      <slot />
    </main>

    <!-- Footer -->
    <footer class="border-t border-white/10 mt-20">
      <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
          <div class="text-sm text-white/60">
            © {{ new Date().getFullYear() }} GEX Levels & Analytics. All rights reserved.
          </div>
          <div class="flex items-center gap-6 text-sm">
            <Link :href="route('features')" class="text-white/60 hover:text-white">Features</Link>
            <Link :href="route('pricing')" class="text-white/60 hover:text-white">Pricing</Link>
            <a href="#" class="text-white/40 hover:text-white/70">Support (coming soon)</a>
          </div>
        </div>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
</script>
