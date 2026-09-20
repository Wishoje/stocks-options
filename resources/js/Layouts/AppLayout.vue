<script setup>
import { nextTick, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import ApplicationMark from '@/Components/ApplicationMark.vue';
import Banner from '@/Components/Banner.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';

defineProps({
    title: String,
});

const showingNavigationDropdown = ref(false);
const mobileNavigationButton = ref(null);
const mobileNavigationMenu = ref(null);

const closeNavigation = (restoreFocus = false) => {
    if (!showingNavigationDropdown.value) return;
    showingNavigationDropdown.value = false;
    if (restoreFocus) nextTick(() => mobileNavigationButton.value?.focus());
};

const toggleNavigation = async () => {
    const opening = !showingNavigationDropdown.value;
    showingNavigationDropdown.value = opening;
    if (!opening) return;
    await nextTick();
    mobileNavigationMenu.value?.querySelector('a, button')?.focus();
};

const closeNavigationOnEscape = (event) => {
    if (event.key === 'Escape' && showingNavigationDropdown.value) {
        event.preventDefault();
        closeNavigation(true);
    }
};

const switchToTeam = (team) => {
    router.put(route('current-team.update'), {
        team_id: team.id,
    }, {
        preserveState: false,
    });
};

async function dispatchLastSymbol() {
  try {
    const saved = typeof window !== 'undefined'
      ? (localStorage.getItem('calculator_last_symbol') || '').trim().toUpperCase()
      : ''

    const fallback = async () => {
      const { data } = await axios.get('/api/watchlist')
      return data?.[0]?.symbol || 'SPY'
    }

    const sym = saved || await fallback()

    window.dispatchEvent(new CustomEvent('select-symbol', { detail: { symbol: sym } }))
  } catch {}
}

const logout = () => {
    router.post(route('logout'));
};

onMounted(() => document.addEventListener('keydown', closeNavigationOnEscape));
onUnmounted(() => document.removeEventListener('keydown', closeNavigationOnEscape));
</script>

<template>
    <div class="dashboard-app-layout">
        <Head :title="title" />

        <Banner />

        <div class="dashboard-app-layout__canvas">
            <a class="dashboard-skip-link" href="#application-page-content">Skip to page content</a>
            <nav class="gex-ui dashboard-topbar" aria-label="Primary navigation" data-theme="dark" data-density="compact">
                <!-- Primary Navigation Menu -->
                <div class="dashboard-topbar__inner">
                    <div class="dashboard-topbar__row">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="dashboard-topbar__brand">
                                <Link :href="route('dashboard')" aria-label="GEX Options dashboard">
                                    <ApplicationMark class="dashboard-topbar__mark" />
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div class="dashboard-topbar__links">
                                <NavLink :href="route('dashboard')" :active="route().current('dashboard')">
                                    Dashboard
                                </NavLink>
                                <NavLink 
                                    :href="route('options.calculator')" 
                                    :active="route().current('options.calculator')"
                                    @click="dispatchLastSymbol"
                                    >
                                    Options Calculator
                                </NavLink>
                                <NavLink
                                    :href="route('options.scanner')"
                                    :active="route().current('options.scanner')"
                                >
                                    Scanner
                                </NavLink>
                                <NavLink
                                    :href="route('options.ai-export')"
                                    :active="route().current('options.ai-export')"
                                >
                                    AI Export
                                </NavLink>
                                <NavLink
                                    v-if="[3,4].includes(Number($page.props.auth.user.id))"
                                    :href="route('eod.health')"
                                    :active="route().current('eod.health')"
                                >
                                    EOD Health
                                </NavLink>
                            </div>
                        </div>

                        <div class="dashboard-topbar__account">
                            <div class="ms-3 relative">
                                <!-- Teams Dropdown -->
                                <Dropdown
                                    v-if="$page.props.jetstream.hasTeamFeatures"
                                    align="right"
                                    width="60"
                                    :content-classes="['dashboard-topbar__dropdown-content']"
                                >
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button type="button" class="dashboard-topbar__menu-trigger" aria-haspopup="menu">
                                                {{ $page.props.auth.user.current_team.name }}

                                                <svg class="ms-2 -me-0.5 size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <div class="w-60">
                                            <!-- Team Management -->
                                            <div class="block px-4 py-2 text-xs text-gray-400">
                                                Manage Team
                                            </div>

                                            <!-- Team Settings -->
                                            <DropdownLink :href="route('teams.show', $page.props.auth.user.current_team)">
                                                Team Settings
                                            </DropdownLink>

                                            <DropdownLink v-if="$page.props.jetstream.canCreateTeams" :href="route('teams.create')">
                                                Create New Team
                                            </DropdownLink>

                                            <!-- Team Switcher -->
                                            <template v-if="$page.props.auth.user.all_teams.length > 1">
                                                <div class="border-t border-gray-200 dark:border-gray-600" />

                                                <div class="block px-4 py-2 text-xs text-gray-400">
                                                    Switch Teams
                                                </div>

                                                <template v-for="team in $page.props.auth.user.all_teams" :key="team.id">
                                                    <form @submit.prevent="switchToTeam(team)">
                                                        <DropdownLink as="button">
                                                            <div class="flex items-center">
                                                                <svg v-if="team.id == $page.props.auth.user.current_team_id" class="me-2 size-5 text-green-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                                </svg>

                                                                <div>{{ team.name }}</div>
                                                            </div>
                                                        </DropdownLink>
                                                    </form>
                                                </template>
                                            </template>
                                        </div>
                                    </template>
                                </Dropdown>
                            </div>

                            <!-- Settings Dropdown -->
                            <div class="ms-3 relative">
                                <Dropdown align="right" width="48" :content-classes="['dashboard-topbar__dropdown-content']">
                                    <template #trigger>
                                        <button v-if="$page.props.jetstream.managesProfilePhotos" class="dashboard-topbar__avatar-button" aria-haspopup="menu" :aria-label="`Open account menu for ${$page.props.auth.user.name}`">
                                            <img class="size-8 rounded-full object-cover" :src="$page.props.auth.user.profile_photo_url" :alt="$page.props.auth.user.name">
                                        </button>

                                        <span v-else class="inline-flex rounded-md">
                                            <button type="button" class="dashboard-topbar__menu-trigger" aria-haspopup="menu">
                                                {{ $page.props.auth.user.name }}

                                                <svg class="ms-2 -me-0.5 size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <!-- Account Management -->
                                        <div class="block px-4 py-2 text-xs text-gray-400">
                                            Manage Account
                                        </div>

                                        <DropdownLink :href="route('profile.show')">
                                            Profile
                                        </DropdownLink>

                                        <div class="border-t border-gray-200 dark:border-gray-600" />

                                        <!-- Authentication -->
                                        <form @submit.prevent="logout">
                                            <DropdownLink as="button">
                                                Log Out
                                            </DropdownLink>
                                        </form>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="dashboard-topbar__mobile-toggle">
                            <button
                                ref="mobileNavigationButton"
                                type="button"
                                class="dashboard-topbar__mobile-button"
                                aria-label="Toggle navigation menu"
                                aria-controls="mobile-primary-navigation"
                                :aria-expanded="showingNavigationDropdown ? 'true' : 'false'"
                                @click="toggleNavigation"
                            >
                                <svg
                                    class="size-5"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{'hidden': showingNavigationDropdown, 'inline-flex': ! showingNavigationDropdown }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{'hidden': ! showingNavigationDropdown, 'inline-flex': showingNavigationDropdown }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    v-show="showingNavigationDropdown"
                    id="mobile-primary-navigation"
                    ref="mobileNavigationMenu"
                    class="dashboard-topbar__mobile-menu"
                >
                    <div class="pt-2 pb-3 space-y-1">
                        <ResponsiveNavLink :href="route('dashboard')" :active="route().current('dashboard')" @click="closeNavigation()">
                            Dashboard
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            :href="route('options.calculator')"
                            :active="route().current('options.calculator')"
                            @click="dispatchLastSymbol(); closeNavigation()"
                        >
                            Options Calculator
                        </ResponsiveNavLink>

                        <!-- NEW: Scanner (mobile) -->
                        <ResponsiveNavLink
                            :href="route('options.scanner')"
                            :active="route().current('options.scanner')"
                            @click="closeNavigation()"
                        >
                            Scanner
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('options.ai-export')"
                            :active="route().current('options.ai-export')"
                            @click="closeNavigation()"
                        >
                            AI Export
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="[3,4].includes(Number($page.props.auth.user.id))"
                            :href="route('eod.health')"
                            :active="route().current('eod.health')"
                            @click="closeNavigation()"
                        >
                            EOD Health
                        </ResponsiveNavLink>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div class="pt-4 pb-1 border-t border-gray-200 dark:border-gray-600">
                        <div class="flex items-center px-4">
                            <div v-if="$page.props.jetstream.managesProfilePhotos" class="shrink-0 me-3">
                                <img class="size-10 rounded-full object-cover" :src="$page.props.auth.user.profile_photo_url" :alt="$page.props.auth.user.name">
                            </div>

                            <div>
                                <div class="font-medium text-base text-gray-800 dark:text-gray-200">
                                    {{ $page.props.auth.user.name }}
                                </div>
                                <div class="font-medium text-sm text-gray-500">
                                    {{ $page.props.auth.user.email }}
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.show')" :active="route().current('profile.show')">
                                Profile
                            </ResponsiveNavLink>

                            <!-- Authentication -->
                            <form method="POST" @submit.prevent="logout">
                                <ResponsiveNavLink as="button">
                                    Log Out
                                </ResponsiveNavLink>
                            </form>

                            <!-- Team Management -->
                            <template v-if="$page.props.jetstream.hasTeamFeatures">
                                <div class="border-t border-gray-200 dark:border-gray-600" />

                                <div class="block px-4 py-2 text-xs text-gray-400">
                                    Manage Team
                                </div>

                                <!-- Team Settings -->
                                <ResponsiveNavLink :href="route('teams.show', $page.props.auth.user.current_team)" :active="route().current('teams.show')">
                                    Team Settings
                                </ResponsiveNavLink>

                                <ResponsiveNavLink v-if="$page.props.jetstream.canCreateTeams" :href="route('teams.create')" :active="route().current('teams.create')">
                                    Create New Team
                                </ResponsiveNavLink>

                                <!-- Team Switcher -->
                                <template v-if="$page.props.auth.user.all_teams.length > 1">
                                    <div class="border-t border-gray-200 dark:border-gray-600" />

                                    <div class="block px-4 py-2 text-xs text-gray-400">
                                        Switch Teams
                                    </div>

                                    <template v-for="team in $page.props.auth.user.all_teams" :key="team.id">
                                        <form @submit.prevent="switchToTeam(team)">
                                            <ResponsiveNavLink as="button">
                                                <div class="flex items-center">
                                                    <svg v-if="team.id == $page.props.auth.user.current_team_id" class="me-2 size-5 text-green-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    <div>{{ team.name }}</div>
                                                </div>
                                            </ResponsiveNavLink>
                                        </form>
                                    </template>
                                </template>
                            </template>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header v-if="$slots.header" class="dashboard-page-heading">
                <div class="max-w-7xl mx-auto px-4 py-3 sm:px-6 sm:py-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main id="application-page-content" class="dashboard-page-content" tabindex="-1">
                <slot />
            </main>
        </div>
    </div>
</template>
