<script setup>
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import InputError from '@/Components/InputError.vue'

const props = defineProps({
  user: { type: Object, required: true },
})

const form = useForm({
  _method: 'PUT',
  name: props.user.name,
  email: props.user.email,
  photo: null,
})

const verificationLinkSent = ref(false)
const photoPreview = ref(null)
const photoInput = ref(null)

const clearPhotoFileInput = () => {
  if (photoInput.value?.value) photoInput.value.value = null
}

const updateProfileInformation = () => {
  form.photo = photoInput.value?.files?.[0] ?? null

  form.post(route('user-profile-information.update'), {
    errorBag: 'updateProfileInformation',
    preserveScroll: true,
    onSuccess: clearPhotoFileInput,
  })
}

const selectNewPhoto = () => photoInput.value?.click()

const updatePhotoPreview = () => {
  const photo = photoInput.value?.files?.[0]
  if (!photo) return

  const reader = new FileReader()
  reader.onload = event => { photoPreview.value = event.target?.result ?? null }
  reader.readAsDataURL(photo)
}

const deletePhoto = () => {
  router.delete(route('current-user-photo.destroy'), {
    preserveScroll: true,
    onSuccess: () => {
      photoPreview.value = null
      clearPhotoFileInput()
    },
  })
}
</script>

<template>
  <section class="account-settings-card" aria-labelledby="profile-settings-heading">
    <header class="account-settings-card__header">
      <div>
        <p class="account-settings-card__eyebrow">Identity</p>
        <h2 id="profile-settings-heading">Profile information</h2>
        <p>Update the name and email address attached to this account.</p>
      </div>
    </header>

    <form class="account-settings-form" novalidate @submit.prevent="updateProfileInformation">
      <div v-if="$page.props.jetstream.managesProfilePhotos" class="account-form-field account-form-field--wide">
        <label for="photo">Profile photo</label>
        <input
          id="photo"
          ref="photoInput"
          type="file"
          class="sr-only"
          accept="image/*"
          :aria-invalid="form.errors.photo ? 'true' : 'false'"
          :aria-describedby="form.errors.photo ? 'profile-photo-error' : undefined"
          @change="updatePhotoPreview"
        >

        <div class="account-photo-control">
          <img
            v-if="!photoPreview"
            :src="user.profile_photo_url"
            :alt="`${user.name}'s current profile photo`"
          >
          <span
            v-else
            role="img"
            aria-label="New profile photo preview"
            :style="`background-image: url('${photoPreview}')`"
          />
          <div class="account-form-actions account-form-actions--start">
            <button type="button" class="account-button account-button--secondary" @click="selectNewPhoto">
              Choose photo
            </button>
            <button
              v-if="user.profile_photo_path"
              type="button"
              class="account-button account-button--quiet"
              @click="deletePhoto"
            >
              Remove photo
            </button>
          </div>
        </div>
        <InputError id="profile-photo-error" :message="form.errors.photo" />
      </div>

      <div class="account-form-field">
        <label for="name">Name</label>
        <input
          id="name"
          v-model="form.name"
          type="text"
          required
          autocomplete="name"
          :aria-invalid="form.errors.name ? 'true' : 'false'"
          :aria-describedby="form.errors.name ? 'profile-name-error' : undefined"
        >
        <InputError id="profile-name-error" :message="form.errors.name" />
      </div>

      <div class="account-form-field">
        <label for="email">Email address</label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          required
          autocomplete="username"
          :aria-invalid="form.errors.email ? 'true' : 'false'"
          :aria-describedby="form.errors.email ? 'profile-email-error' : undefined"
        >
        <InputError id="profile-email-error" :message="form.errors.email" />

        <div
          v-if="$page.props.jetstream.hasEmailVerification && user.email_verified_at === null"
          class="account-inline-notice"
          data-tone="warning"
        >
          <p>Your email address has not been verified.</p>
          <Link
            :href="route('verification.send')"
            method="post"
            as="button"
            type="button"
            class="account-text-action"
            @click="verificationLinkSent = true"
          >
            Send another verification email
          </Link>
          <p v-if="verificationLinkSent" role="status" aria-live="polite">
            A new verification link has been requested.
          </p>
        </div>
      </div>

      <div class="account-form-footer account-form-field--wide">
        <p v-if="form.recentlySuccessful" class="account-save-status" role="status" aria-live="polite">
          Profile saved.
        </p>
        <button
          type="submit"
          class="account-button account-button--primary"
          :disabled="form.processing"
        >
          {{ form.processing ? 'Saving…' : 'Save profile' }}
        </button>
      </div>
    </form>
  </section>
</template>
