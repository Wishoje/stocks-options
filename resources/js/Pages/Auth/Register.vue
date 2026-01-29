<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const page = usePage()
const serverErrors = computed(() => page.props.errorBags?.default || {})

const form = useForm({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  terms: false,
})

const localErrors = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  terms: '',
})

function fieldError(name) {
  const fromBag = serverErrors.value[name]
  const bagMessage = Array.isArray(fromBag) ? fromBag[0] : fromBag
  return localErrors[name] || form.errors[name] || bagMessage || ''
}

function getQueryParam(key) {
  // Inertia page.url includes query string
  const url = new URL(page.url, window.location.origin)
  return url.searchParams.get(key)
}

function validate() {
  localErrors.name = ''
  localErrors.email = ''
  localErrors.password = ''
  localErrors.password_confirmation = ''
  localErrors.terms = ''

  let ok = true
  if (!form.name || !form.name.trim()) {
    localErrors.name = 'Name is required.'
    ok = false
  }

  if (!form.email || !form.email.trim()) {
    localErrors.email = 'Email is required.'
    ok = false
  } else if (!/^\S+@\S+\.\S+$/.test(form.email)) {
    localErrors.email = 'Enter a valid email.'
    ok = false
  }

  if (!form.password) {
    localErrors.password = 'Password is required.'
    ok = false
  } else if (form.password.length < 8) {
    localErrors.password = 'Password must be at least 8 characters.'
    ok = false
  }

  if (!form.password_confirmation) {
    localErrors.password_confirmation = 'Confirm your password.'
    ok = false
  } else if (form.password !== form.password_confirmation) {
    localErrors.password_confirmation = 'Passwords do not match.'
    ok = false
  }

  if (page.props.jetstream?.hasTermsAndPrivacyPolicyFeature && !form.terms) {
    localErrors.terms = 'You must accept the terms.'
    ok = false
  }

  return ok
}

const submit = () => {
  if (!validate()) return

  form.post(route('register'), {
    onSuccess: () => {
      if (typeof window.gtag === 'function') {
        window.gtag('event', 'sign_up', { method: 'email' })
      }

      const plan = getQueryParam('plan')
      const billing = getQueryParam('billing')

      if (plan && billing) {
        window.location.assign(`/checkout?plan=${encodeURIComponent(plan)}&billing=${encodeURIComponent(billing)}`)
      } else {
        router.visit(route('pricing')) // <-- IMPORTANT: don’t send new users to dashboard
      }
    },
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}
</script>

<template>
    <Head title="Register" />

    <AuthenticationCard>
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="name" value="Name" />
                <TextInput
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="mt-1 block w-full"
                    required
                    autofocus
                    autocomplete="name"
                />
                <InputError class="mt-2" :message="fieldError('name')" />
            </div>

            <div class="mt-4">
                <InputLabel for="email" value="Email" />
                <TextInput
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="mt-1 block w-full"
                    required
                    autocomplete="username"
                />
                <InputError class="mt-2" :message="fieldError('email')" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Password" />
                <TextInput
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="new-password"
                />
                <InputError class="mt-2" :message="fieldError('password')" />
            </div>

            <div class="mt-4">
                <InputLabel for="password_confirmation" value="Confirm Password" />
                <TextInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="new-password"
                />
                <InputError class="mt-2" :message="fieldError('password_confirmation')" />
            </div>

            <div v-if="$page.props.jetstream.hasTermsAndPrivacyPolicyFeature" class="mt-4">
                <InputLabel for="terms">
                    <div class="flex items-center">
                        <Checkbox id="terms" v-model:checked="form.terms" name="terms" required />

                        <div class="ms-2">
                            I agree to the <a target="_blank" :href="route('terms.show')" class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800">Terms of Service</a> and <a target="_blank" :href="route('policy.show')" class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800">Privacy Policy</a>
                        </div>
                    </div>
                    <InputError class="mt-2" :message="fieldError('terms')" />
                </InputLabel>
            </div>

            <div class="flex items-center justify-end mt-4">
                <Link :href="route('login')" class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800">
                    Already registered?
                </Link>

                <PrimaryButton class="ms-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Register
                </PrimaryButton>
            </div>
        </form>
    </AuthenticationCard>
</template>
