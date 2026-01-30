<template>
  <Head>
    <title>Contact Us — GexOptions</title>
    <meta name="description" content="Get in touch with the GexOptions team." />
  </Head>
  <MarketingLayout>
    <section class="mx-auto max-w-5xl px-4 pt-16 sm:px-8 lg:px-12 pb-16">
      <div class="space-y-6 text-white">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-cyan-300 mb-2">Contact</p>
          <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">We’re here to help</h1>
          <p class="mt-3 text-base text-white/70 max-w-3xl">
            Questions about levels, dealer positioning, billing, or access? Email us and we’ll respond promptly.
          </p>
        </div>

        <div class="grid gap-8 lg:gap-10 md:grid-cols-2">
          <div class="rounded-3xl border border-white/10 bg-white/5 p-7 space-y-4 shadow-xl shadow-cyan-500/5">
            <h2 class="text-xl font-semibold">Email</h2>
            <p class="text-sm text-white/70">
              <a class="text-cyan-300 hover:text-cyan-200" href="mailto:support@gexoptions.com">support@gexoptions.com</a>
            </p>
            <p class="text-xs text-white/50">We typically respond within one business day.</p>
            <div class="text-xs text-white/60">
              <div class="font-semibold text-white mb-1">Helpful details</div>
              <ul class="list-disc list-inside space-y-1">
                <li>Your account email</li>
                <li>Symbol & timeframe if it’s a data question</li>
                <li>Any screenshots or error text</li>
              </ul>
            </div>
          </div>

          <div class="rounded-3xl border border-white/10 bg-white/5 p-7 shadow-xl shadow-cyan-500/5">
            <div class="flex items-start justify-between gap-3">
              <h2 class="text-xl font-semibold mb-3">Send a message</h2>
              <transition name="fade">
                <span
                  v-if="status === 'contact-sent'"
                  class="inline-flex items-center gap-1 rounded-full bg-green-500/15 border border-green-400/30 px-3 py-1 text-xs text-green-200"
                >
                  Sent ✓
                </span>
              </transition>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
              <div class="space-y-1">
                <label class="text-sm text-white/80" for="name">Name</label>
                <input
                  id="name"
                  v-model="form.name"
                  type="text"
                  class="w-full rounded-lg bg-gray-900/80 border border-white/10 px-3 py-2 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                  required
                />
              </div>
              <div class="space-y-1">
                <label class="text-sm text-white/80" for="email">Email</label>
                <input
                  id="email"
                  v-model="form.email"
                  type="email"
                  class="w-full rounded-lg bg-gray-900/80 border border-white/10 px-3 py-2 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                  required
                />
              </div>
              <div class="space-y-1">
                <label class="text-sm text-white/80" for="message">Message</label>
                <textarea
                  id="message"
                  v-model="form.message"
                  rows="5"
                  class="w-full rounded-lg bg-gray-900/80 border border-white/10 px-3 py-2 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                  required
                />
              </div>

              <div class="flex items-center gap-3">
                <button
                  type="submit"
                  :disabled="form.processing"
                  class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-gray-900 hover:bg-cyan-400 transition disabled:opacity-60"
                >
                  {{ form.processing ? 'Sending…' : 'Send message' }}
                </button>
                <p v-if="status === 'contact-sent'" class="text-xs text-green-300">Message sent. We’ll reply soon.</p>
              </div>

              <p v-if="form.errors.name || form.errors.email || form.errors.message" class="text-xs text-red-300">
                {{ form.errors.name || form.errors.email || form.errors.message }}
              </p>
            </form>
          </div>
        </div>
      </div>
    </section>
  </MarketingLayout>
</template>

<script setup>
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const page = usePage()
const status = computed(() => page.props.flash?.status)

const form = useForm({
  name: '',
  email: '',
  message: '',
})

const submit = () => {
  form.post(route('contact.submit'), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
    },
  })
}
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
