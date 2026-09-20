<template>
  <MarketingSeo
    :title="page.props.seo?.title || 'Contact GexOptions'"
    :description="page.props.seo?.description || 'Contact GexOptions about product access, billing, or market-data questions.'"
    :canonical="page.props.seo?.canonical || 'https://gexoptions.com/contact'"
    :image="page.props.seo?.image"
    :image-alt="page.props.seo?.image_alt"
    :image-width="page.props.seo?.image_width"
    :image-height="page.props.seo?.image_height"
  />

  <MarketingLayout>
    <div class="contact-page">
      <section class="contact-page__intro">
        <p>Support</p>
        <h1>Tell us what you need help with.</h1>
        <span>Include the account, view, and date involved when they are relevant. Please do not send passwords or payment details.</span>
      </section>

      <div class="contact-page__grid">
        <aside class="contact-card" aria-labelledby="contact-direct-heading">
          <p class="contact-card__eyebrow">Direct support</p>
          <h2 id="contact-direct-heading">Email the team</h2>
          <a href="mailto:support@gexoptions.com">support@gexoptions.com</a>
          <p>Use the form or email address for product, access, billing, and data questions.</p>

          <div class="contact-card__help">
            <h3>Helpful details</h3>
            <ul>
              <li>Your account email</li>
              <li>The page, symbol, and timeframe involved</li>
              <li>The visible error text and when it occurred</li>
            </ul>
          </div>
        </aside>

        <section class="contact-form-card" aria-labelledby="contact-form-heading">
          <div class="contact-form-card__heading">
            <div>
              <p class="contact-card__eyebrow">Message</p>
              <h2 id="contact-form-heading">Send a support request</h2>
            </div>
            <span v-if="sent" class="contact-form-card__sent" role="status">Sent</span>
          </div>

          <p v-if="sent" class="contact-form-card__success" role="status" aria-live="polite">
            Your message was sent. We will reply to the email you provided.
          </p>

          <div
            v-if="errorSummary"
            class="contact-form-card__error"
            role="alert"
            tabindex="-1"
            ref="errorSummaryElement"
          >
            {{ errorSummary }}
          </div>

          <form novalidate @submit.prevent="submit">
            <div class="contact-field">
              <label for="contact-name">Name</label>
              <input
                id="contact-name"
                ref="nameInput"
                v-model="form.name"
                type="text"
                autocomplete="name"
                required
                maxlength="120"
                :aria-invalid="Boolean(form.errors.name)"
                :aria-describedby="form.errors.name ? 'contact-name-error' : undefined"
              />
              <span v-if="form.errors.name" id="contact-name-error" class="contact-field__error">{{ form.errors.name }}</span>
            </div>

            <div class="contact-field">
              <label for="contact-email">Email</label>
              <input
                id="contact-email"
                ref="emailInput"
                v-model="form.email"
                type="email"
                autocomplete="email"
                required
                maxlength="190"
                :aria-invalid="Boolean(form.errors.email)"
                :aria-describedby="form.errors.email ? 'contact-email-error' : undefined"
              />
              <span v-if="form.errors.email" id="contact-email-error" class="contact-field__error">{{ form.errors.email }}</span>
            </div>

            <div class="contact-field">
              <label for="contact-message">How can we help?</label>
              <textarea
                id="contact-message"
                ref="messageInput"
                v-model="form.message"
                rows="6"
                required
                maxlength="5000"
                :aria-invalid="Boolean(form.errors.message)"
                :aria-describedby="form.errors.message ? 'contact-message-error' : 'contact-message-help'"
              />
              <span id="contact-message-help" class="contact-field__help">Do not include passwords, card numbers, or API keys.</span>
              <span v-if="form.errors.message" id="contact-message-error" class="contact-field__error">{{ form.errors.message }}</span>
            </div>

            <button type="submit" :disabled="form.processing">
              <span v-if="form.processing" class="contact-form-card__spinner" aria-hidden="true" />
              {{ form.processing ? 'Sending…' : 'Send message' }}
            </button>
          </form>
        </section>
      </div>
    </div>
  </MarketingLayout>
</template>

<script setup>
import { useForm, usePage } from '@inertiajs/vue3'
import { computed, nextTick, ref } from 'vue'
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import MarketingSeo from '@/Components/Marketing/MarketingSeo.vue'

const page = usePage()
const sent = computed(() => page.props.flash?.status === 'contact-sent')
const errorSummary = computed(() => {
  const first = form.errors.name || form.errors.email || form.errors.message
  return first ? `Please correct the form: ${first}` : ''
})

const nameInput = ref(null)
const emailInput = ref(null)
const messageInput = ref(null)
const errorSummaryElement = ref(null)

const form = useForm({
  name: '',
  email: '',
  message: '',
})

function focusFirstError(errors) {
  const target = errors.name
    ? nameInput.value
    : errors.email
      ? emailInput.value
      : errors.message
        ? messageInput.value
        : errorSummaryElement.value
  nextTick(() => target?.focus())
}

function submit() {
  form.post(route('contact.submit'), {
    preserveScroll: true,
    onSuccess: () => form.reset(),
    onError: focusFirstError,
  })
}
</script>

<style scoped>
.contact-page { width: min(100%, 1180px); margin-inline: auto; padding: clamp(4rem, 9vw, 7rem) 1rem 5rem; color: #f8fafc; }
.contact-page__intro { max-width: 740px; }
.contact-page__intro > p, .contact-card__eyebrow { margin: 0 0 .65rem; color: #67e8f9; font-size: .7rem; font-weight: 800; letter-spacing: .18em; text-transform: uppercase; }
.contact-page__intro h1 { margin: 0; font-size: clamp(2.3rem, 6vw, 4rem); line-height: 1.04; letter-spacing: -.04em; }
.contact-page__intro span { display: block; max-width: 650px; margin-top: 1rem; color: #9eacbf; font-size: .92rem; line-height: 1.65; }
.contact-page__grid { display: grid; grid-template-columns: .72fr 1.28fr; gap: 1rem; margin-top: 2.5rem; }
.contact-card, .contact-form-card { border: 1px solid rgba(148, 163, 184, .16); border-radius: 1.35rem; background: rgba(8, 15, 29, .82); padding: clamp(1.25rem, 3vw, 2rem); box-shadow: 0 20px 60px rgba(0, 0, 0, .2); }
.contact-card h2, .contact-form-card h2 { margin: 0; color: #f1f5f9; font-size: 1.35rem; }
.contact-card > a { display: inline-block; margin-top: 1rem; color: #67e8f9; font-size: .9rem; font-weight: 700; }
.contact-card > p:not(.contact-card__eyebrow) { margin: .4rem 0 0; color: #8290a4; font-size: .76rem; }
.contact-card__help { margin-top: 2rem; border-top: 1px solid rgba(148, 163, 184, .14); padding-top: 1.25rem; }
.contact-card__help h3 { margin: 0; color: #cbd5e1; font-size: .78rem; }
.contact-card__help ul { display: grid; gap: .55rem; margin: .8rem 0 0; padding-left: 1.1rem; color: #94a3b8; font-size: .76rem; line-height: 1.5; }
.contact-form-card__heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.contact-form-card__sent { border: 1px solid rgba(52, 211, 153, .3); border-radius: 999px; background: rgba(16, 185, 129, .12); padding: .3rem .65rem; color: #a7f3d0; font-size: .7rem; font-weight: 800; }
.contact-form-card__success, .contact-form-card__error { margin: 1rem 0 0; border-radius: .7rem; padding: .7rem .8rem; font-size: .76rem; }
.contact-form-card__success { border: 1px solid rgba(52, 211, 153, .25); background: rgba(16, 185, 129, .09); color: #a7f3d0; }
.contact-form-card__error { border: 1px solid rgba(251, 113, 133, .28); background: rgba(225, 29, 72, .09); color: #fecdd3; }
.contact-form-card form { display: grid; gap: 1rem; margin-top: 1.35rem; }
.contact-field { display: grid; gap: .4rem; }
.contact-field label { color: #cbd5e1; font-size: .76rem; font-weight: 700; }
.contact-field input, .contact-field textarea { width: 100%; border: 1px solid rgba(148, 163, 184, .22); border-radius: .75rem; background: rgba(2, 6, 23, .68); padding: .72rem .8rem; color: #f8fafc; font-size: .86rem; transition: border-color .15s ease, box-shadow .15s ease; }
.contact-field textarea { resize: vertical; min-height: 8rem; }
.contact-field input:focus, .contact-field textarea:focus { border-color: #22d3ee; outline: 0; box-shadow: 0 0 0 3px rgba(34, 211, 238, .12); }
.contact-field input[aria-invalid="true"], .contact-field textarea[aria-invalid="true"] { border-color: #fb7185; }
.contact-field__error { color: #fda4af; font-size: .7rem; }
.contact-field__help { color: #718096; font-size: .68rem; }
.contact-form-card form > button { display: flex; align-items: center; justify-content: center; gap: .5rem; border-radius: .78rem; background: linear-gradient(110deg, #22d3ee, #38bdf8); padding: .76rem 1rem; color: #082f49; font-size: .84rem; font-weight: 800; transition: filter .15s ease, transform .15s ease; }
.contact-form-card form > button:hover:not(:disabled) { filter: brightness(1.07); transform: translateY(-1px); }
.contact-form-card form > button:focus-visible { outline: 2px solid #e0f2fe; outline-offset: 3px; }
.contact-form-card form > button:disabled { cursor: wait; opacity: .7; }
.contact-form-card__spinner { width: .85rem; height: .85rem; border: 2px solid rgba(8, 47, 73, .3); border-top-color: #082f49; border-radius: 999px; animation: contact-spin .7s linear infinite; }
@keyframes contact-spin { to { transform: rotate(360deg); } }
@media (max-width: 760px) { .contact-page__grid { grid-template-columns: 1fr; } }
@media (prefers-reduced-motion: reduce) {
  .contact-field input, .contact-field textarea, .contact-form-card form > button { transition: none; }
  .contact-form-card__spinner { animation-duration: 1.4s; }
}
</style>
