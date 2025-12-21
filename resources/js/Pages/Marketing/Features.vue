<template>
  <MarketingLayout>
    <section class="mx-auto max-w-7xl px-4 pt-14 sm:px-6 lg:px-8">
      <div class="grid gap-10 lg:grid-cols-2 lg:items-center">
        <div>
          <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
            Features built for fast decision-making
          </h1>
          <p class="mt-4 text-sm text-white/60">
            Designed around the exact workflow your screenshots show: scan → validate → execute.
          </p>

          <div class="mt-7 flex gap-3">
            <a href="#levels" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm hover:bg-white/10">Levels</a>
            <a href="#flow" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm hover:bg-white/10">Flow</a>
            <a href="#vol" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm hover:bg-white/10">Volatility</a>
          </div>
        </div>

        <div class="relative">
          <div class="absolute -inset-6 rounded-[32px] bg-gradient-to-br from-cyan-500/20 to-blue-600/10 blur-2xl" />
          <div class="relative overflow-hidden rounded-[28px] border border-white/10 bg-white/5 shadow-2xl shadow-black/40">
            <img src="/marketing/hero.png" alt="Feature preview" class="w-full" />
          </div>
        </div>
      </div>
    </section>

    <FeatureSection
      id="levels"
      title="Levels & Positioning"
      desc="Understand the market map before you trade: key levels, walls, positioning, and pin risk."
      :shots="[
        { title: 'Dealer Positioning', src: '/marketing/dex.png' },
        { title: 'Expiry Pressure / Pin Risk', src: '/marketing/pin.png' },
      ]"
    />

    <FeatureSection
      id="flow"
      title="Flow & Unusual Activity"
      desc="See what’s moving the tape with strike-based intraday flow and unusual options activity."
      :shots="[
        { title: 'Live Flow by Strike', src: '/marketing/hero.png' },
        { title: 'Unusual Activity Scanner', src: '/marketing/ua.png' },
      ]"
    />

    <FeatureSection
      id="vol"
      title="Volatility Suite"
      desc="Context matters: term structure + VRP + seasonality to frame risk and regime."
      :shots="[
        { title: 'Term Structure', src: '/marketing/term.png' },
        { title: 'Q-Score (example)', src: '/marketing/qscore.png' },
      ]"
    />

    <section class="mx-auto max-w-7xl px-4 pt-12 sm:px-6 lg:px-8">
      <div class="rounded-3xl border border-white/10 bg-gradient-to-br from-white/10 to-white/5 p-8 text-center">
        <h3 class="text-2xl font-semibold">Ready to try it?</h3>
        <p class="mt-2 text-sm text-white/60">Create an account now—payments can be added when you’re ready.</p>
        <div class="mt-6 flex justify-center gap-3">
          <Link :href="route('register')" class="rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 px-5 py-3 text-sm font-semibold shadow-lg shadow-cyan-500/20 hover:opacity-95">
            Start Free
          </Link>
          <Link :href="route('pricing')" class="rounded-xl border border-white/10 bg-white/5 px-5 py-3 text-sm font-semibold hover:bg-white/10">
            View Pricing
          </Link>
        </div>
      </div>
    </section>
  </MarketingLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import MarketingLayout from '@/Layouts/MarketingLayout.vue'

const FeatureSection = {
  props: ['id', 'title', 'desc', 'shots'],
  template: `
    <section :id="id" class="mx-auto max-w-7xl px-4 pt-16 sm:px-6 lg:px-8">
      <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
          <h2 class="text-2xl font-semibold tracking-tight">{{ title }}</h2>
          <p class="mt-2 text-sm text-white/60 max-w-2xl">{{ desc }}</p>
        </div>
      </div>

      <div class="mt-8 grid gap-4 lg:grid-cols-2">
        <div v-for="s in shots" :key="s.title" class="overflow-hidden rounded-2xl border border-white/10 bg-white/5">
          <div class="border-b border-white/10 px-4 py-2 text-xs text-white/70">{{ s.title }}</div>
          <img :src="s.src" class="w-full" loading="lazy" />
        </div>
      </div>
    </section>
  `
}
</script>
