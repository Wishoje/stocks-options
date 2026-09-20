<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    setTimeout(() => passwordInput.value?.focus(), 250);
};

const deleteUser = () => {
    if (form.processing) return;

    form.delete(route('current-user.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    if (form.processing) return;

    confirmingUserDeletion.value = false;

    form.reset();
    form.clearErrors();
};
</script>

<template>
  <section class="account-settings-card account-settings-card--danger" aria-labelledby="delete-account-settings-heading">
    <header class="account-settings-card__header">
      <div>
        <p class="account-settings-card__eyebrow">Account control</p>
        <h2 id="delete-account-settings-heading">Delete account</h2>
        <p>Permanently remove your account and its saved application data.</p>
      </div>
    </header>

    <div class="account-settings-card__body">
      <p>
        Download anything you need before continuing. Every saved subscription must be fully ended before the account can be deleted.
      </p>

      <div class="account-form-footer">
        <button type="button" class="account-button account-button--danger" @click="confirmUserDeletion">
          Delete account
        </button>
      </div>
    </div>

    <DialogModal
      :show="confirmingUserDeletion"
      labelledby="delete-account-dialog-title"
      :closeable="!form.processing"
      @close="closeModal"
    >
      <template #title>
        <h2 id="delete-account-dialog-title">Delete account permanently?</h2>
      </template>

      <template #content>
        <p>
          This cannot be undone. Enter your password to confirm permanent account deletion.
        </p>

        <InputError id="delete-account-dialog-error" :message="form.errors.account" class="mt-3" />

        <div class="account-form-field account-dialog-field mt-4">
          <label for="delete-account-password">Current password</label>
          <input
            id="delete-account-password"
            ref="passwordInput"
            v-model="form.password"
            type="password"
            autocomplete="current-password"
            :disabled="form.processing"
            :aria-invalid="form.errors.password ? 'true' : 'false'"
            :aria-describedby="form.errors.password ? 'delete-account-password-error' : undefined"
            @keyup.enter.prevent="deleteUser"
          >
          <InputError id="delete-account-password-error" :message="form.errors.password" />
        </div>
      </template>

      <template #footer>
        <button
          type="button"
          class="account-button account-button--quiet"
          :disabled="form.processing"
          @click="closeModal"
        >
          Keep account
        </button>
        <button
          type="button"
          class="account-button account-button--danger ms-3"
          :disabled="form.processing"
          @click="deleteUser"
        >
          {{ form.processing ? 'Deleting…' : 'Delete permanently' }}
        </button>
      </template>
    </DialogModal>
  </section>
</template>
