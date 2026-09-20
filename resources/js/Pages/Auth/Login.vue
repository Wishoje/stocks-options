<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { journeyUrl } from '@/Support/marketing-journey.js';
import { loginValidationErrors } from '@/Support/auth-validation.js';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const page = usePage();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const serverErrors = computed(() => page.props.errorBags?.default || {});

const localErrors = reactive({
    email: '',
    password: '',
});

function fieldError(name) {
    const fromBag = serverErrors.value[name];
    const bagMessage = Array.isArray(fromBag) ? fromBag[0] : fromBag;
    return localErrors[name] || form.errors[name] || bagMessage || '';
}

function validate() {
    Object.assign(localErrors, loginValidationErrors(form));
    return !localErrors.email && !localErrors.password;
}

const submit = () => {
    if (!validate()) return;

    form.transform(data => ({
        ...data,
        remember: form.remember ? 'on' : '',
    })).post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Log in" />

    <AuthenticationCard
        title="Welcome back"
        description="Log in to return to your saved dashboard and billing flow."
    >
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <div v-if="status" class="mb-4 font-medium text-sm text-green-600 dark:text-green-400" role="status">
            {{ status }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="mt-1 block w-full"
                    required
                    autofocus
                    autocomplete="username"
                    :aria-invalid="Boolean(fieldError('email'))"
                    :aria-describedby="fieldError('email') ? 'email-error' : undefined"
                />
                <InputError id="email-error" class="mt-2" :message="fieldError('email')" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Password" />
                <TextInput
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    :aria-invalid="Boolean(fieldError('password'))"
                    :aria-describedby="fieldError('password') ? 'password-error' : undefined"
                />
                <InputError id="password-error" class="mt-2" :message="fieldError('password')" />
            </div>

            <div class="block mt-4">
                <label class="flex items-center">
                    <Checkbox v-model:checked="form.remember" name="remember" />
                    <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">Remember me</span>
                </label>
            </div>

            <div class="flex items-center justify-end mt-4">
                <Link v-if="canResetPassword" :href="route('password.request')" class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800">
                    Forgot your password?
                </Link>

                <PrimaryButton class="ms-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Log in
                </PrimaryButton>
            </div>
        </form>

        <template #footer>
            New to GexOptions?
            <Link :href="journeyUrl('register', page.props.billing?.intent)">Create an account</Link>
        </template>
    </AuthenticationCard>
</template>
