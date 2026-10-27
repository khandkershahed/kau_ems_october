<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import Sidebar from '@/Components/Sidebar.vue';
import { useThemeStore } from '@/stores/theme';
import { useNotificationStore } from '@/stores/notifications';
import { useNavigationStore } from '@/stores/navigation';

const sidebarOpen = ref(false); // For mobile offcanvas
const sidebarCollapsed = ref(false); // For desktop mini-sidebar
const themeStore = useThemeStore();
const notificationStore = useNotificationStore();
const navigationStore = useNavigationStore();

const desktopSearchInput = ref(null);
const mobileSearchInput = ref(null);
const searchDropdownRef = ref(null);
const isFocused = ref(false);

const onSearchFocus = () => {
    isFocused.value = true;
    navigationStore.isSearchOpen = true;
};

const onSearchBlur = () => {
    // Delay hiding so clicks on items can fire first
    setTimeout(() => {
        isFocused.value = false;
    }, 200);
};

const openMobileSearch = async () => {
    navigationStore.openSearch();
    await nextTick();
    mobileSearchInput.value?.focus();
};

const selectResult = (item) => {
    if (!item || !item.href) return;
    navigationStore.closeSearch();
    isFocused.value = false;
    router.visit(item.href);
};

const handleKeydown = (e) => {
    // Global hotkey Ctrl+K or / to focus search
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        desktopSearchInput.value?.focus();
        navigationStore.openSearch();
        return;
    }

    if (!navigationStore.isSearchOpen && !isFocused.value) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        navigationStore.nextResult();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        navigationStore.prevResult();
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const results = navigationStore.searchResults;
        if (results.length > 0 && results[navigationStore.selectedIndex]) {
            selectResult(results[navigationStore.selectedIndex]);
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        navigationStore.closeSearch();
        desktopSearchInput.value?.blur();
    }
};

onMounted(() => {
    themeStore.initTheme();
    window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
    <div class="flex h-screen overflow-hidden bg-gray-100 dark:bg-gray-900 transition-colors duration-300">
        <!-- Sidebar -->
        <Sidebar :isOpen="sidebarOpen" :isCollapsed="sidebarCollapsed" @close="sidebarOpen = false" />

        <!-- Main Content Area -->
        <div class="relative flex flex-1 flex-col overflow-y-auto overflow-x-hidden">
            <!-- Top Header -->
            <header class="sticky top-0 z-30 flex h-16 shrink-0 items-center justify-between border-b border-gray-200 bg-white px-4 shadow-sm sm:px-6 lg:px-8 dark:border-gray-700 dark:bg-gray-800 transition-colors duration-300">
                <div class="flex items-center gap-4 flex-1">
                    <!-- Hamburger Menu Button (Mobile) -->
                    <button
                        @click="sidebarOpen = !sidebarOpen"
                        class="lg:hidden text-gray-500 hover:text-green-600 focus:outline-none focus:ring-2 focus:ring-green-500 dark:text-gray-400 dark:hover:text-green-400 transition-colors rounded-md p-1"
                        aria-label="Toggle mobile menu"
                    >
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <!-- Hamburger Menu Button (Desktop) -->
                    <button
                        @click="sidebarCollapsed = !sidebarCollapsed"
                        class="hidden lg:block text-gray-500 hover:text-green-600 focus:outline-none focus:ring-2 focus:ring-green-500 dark:text-gray-400 dark:hover:text-green-400 transition-colors rounded-md p-1"
                        aria-label="Collapse sidebar"
                    >
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <!-- Desktop Fuzzy Search Bar -->
                    <div class="max-w-lg w-full relative hidden sm:block" ref="searchDropdownRef">
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>
                            <input
                                ref="desktopSearchInput"
                                v-model="navigationStore.searchQuery"
                                @focus="onSearchFocus"
                                @blur="onSearchBlur"
                                type="text"
                                class="block w-full pl-9 pr-14 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg leading-5 bg-gray-50 dark:bg-gray-700/60 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm transition-all"
                                placeholder="Search menus, reports, payroll...(Ctrl+K)"
                            >
                            <div class="absolute inset-y-0 right-0 pr-2.5 flex items-center pointer-events-none">
                                <kbd class="hidden sm:inline-block px-1.5 py-0.5 text-[10px] font-semibold text-gray-400 dark:text-gray-400 bg-gray-200 dark:bg-gray-600 rounded border border-gray-300 dark:border-gray-500">Ctrl K</kbd>
                            </div>
                        </div>

                        <!-- Desktop Search Results Dropdown -->
                        <transition
                            enter-active-class="transition duration-100 ease-out"
                            enter-from-class="transform scale-95 opacity-0"
                            enter-to-class="transform scale-100 opacity-100"
                            leave-active-class="transition duration-75 ease-in"
                            leave-from-class="transform scale-100 opacity-100"
                            leave-to-class="transform scale-95 opacity-0"
                        >
                            <div
                                v-if="(isFocused || navigationStore.isSearchOpen) && (navigationStore.searchResults.length > 0 || navigationStore.searchQuery)"
                                class="absolute left-0 mt-2 w-full rounded-xl bg-white dark:bg-gray-800 shadow-2xl border border-gray-200 dark:border-gray-700 overflow-hidden z-50 divide-y divide-gray-100 dark:divide-gray-700/50"
                            >
                                <div class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-400 bg-gray-50/50 dark:bg-gray-800/80 flex items-center justify-between">
                                    <span>{{ navigationStore.searchQuery ? 'Search Results' : 'Quick Access' }}</span>
                                    <span class="text-[10px] text-gray-400">Use ↑ ↓ to navigate, ↵ to select</span>
                                </div>

                                <div class="max-h-80 overflow-y-auto p-1.5 space-y-1">
                                    <div
                                        v-if="navigationStore.searchResults.length === 0"
                                        class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400"
                                    >
                                        <p class="font-medium">No results found for "{{ navigationStore.searchQuery }}"</p>
                                        <p class="text-xs mt-1 text-gray-400">Try searching for "Salary", "GPF", "Bank Advice", "Payscale", or "Employee"</p>
                                    </div>

                                    <button
                                        v-for="(item, idx) in navigationStore.searchResults"
                                        :key="item.routeName"
                                        @mousedown.prevent="selectResult(item)"
                                        class="w-full text-left flex items-start gap-3 px-3 py-2 rounded-lg transition-colors group text-sm"
                                        :class="[
                                            navigationStore.selectedIndex === idx
                                                ? 'bg-green-50 dark:bg-green-950/40 text-green-800 dark:text-green-300'
                                                : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100/70 dark:hover:bg-gray-700/60'
                                        ]"
                                    >
                                        <div
                                            class="p-1.5 rounded-md shrink-0 mt-0.5"
                                            :class="[
                                                navigationStore.selectedIndex === idx
                                                    ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
                                                    : 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 group-hover:text-green-600'
                                            ]"
                                            v-html="item.icon"
                                        ></div>

                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between">
                                                <span class="font-medium text-gray-900 dark:text-gray-100 group-hover:text-green-600 dark:group-hover:text-green-400 truncate">
                                                    {{ item.title }}
                                                </span>
                                                <span class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 shrink-0 ml-2">
                                                    {{ item.category }}
                                                </span>
                                            </div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">
                                                {{ item.description }}
                                            </p>
                                        </div>
                                    </button>
                                </div>
                            </div>
                        </transition>
                    </div>
                </div>

                <div class="flex items-center gap-3 sm:gap-4">
                    <!-- Mobile Search Trigger Button (only visible on mobile) -->
                    <button
                        @click="openMobileSearch"
                        class="sm:hidden text-gray-500 hover:text-green-600 dark:text-gray-400 dark:hover:text-green-400 p-1.5 rounded-lg border border-gray-200 dark:border-gray-700"
                        aria-label="Open mobile search"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </button>

                    <ThemeToggle />

                    <!-- Notifications Dropdown -->
                    <Dropdown align="mobile-center" width="notifications">
                        <template #trigger>
                            <!-- <button class="relative p-1 text-gray-500 hover:text-green-600 dark:text-gray-400 dark:hover:text-green-400 transition-colors focus:outline-none rounded-md">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                </svg> -->
                                <!-- Notification Badge -->
                                <!-- <span v-if="notificationStore.unreadCount > 0" class="absolute top-1 right-1 flex h-2.5 w-2.5">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                                </span>
                            </button> -->
                        </template>

                        <template #content>
                            <div class="px-4 py-2 border-b border-gray-100 dark:border-gray-700">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Notifications</h3>
                            </div>
                            <div class="max-h-60 overflow-y-auto">
                                <div v-if="notificationStore.latestNotifications.length === 0" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No new notifications.
                                </div>
                                <div v-for="notification in notificationStore.latestNotifications" :key="notification.id" class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors cursor-pointer" :class="{ 'bg-green-50/50 dark:bg-gray-700/50': !notification.isRead }" @click="notificationStore.markAsRead(notification.id)">
                                    <p class="text-sm text-gray-800 dark:text-gray-200" :class="{ 'font-semibold': !notification.isRead }">{{ notification.title }}</p>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 mt-1 block">{{ notification.time }}</span>
                                </div>
                            </div>
                            <Link :href="route('notifications.index')" class="block px-4 py-2 text-sm text-center text-green-600 dark:text-green-400 hover:bg-gray-50 dark:hover:bg-gray-800 font-medium transition-colors">
                                View all notifications
                            </Link>
                        </template>
                    </Dropdown>

                    <!-- User Dropdown -->
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button
                                type="button"
                                class="flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-green-600 focus:outline-none dark:bg-gray-800 dark:text-gray-400 dark:hover:text-green-400"
                            >
                                {{ $page.props.auth.user.name }}
                                <svg
                                    class="-me-0.5 ms-2 h-4 w-4"
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                            </button>
                        </template>

                        <template #content>
                            <DropdownLink :href="route('profile.edit')">
                                Profile
                            </DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">
                                Log Out
                            </DropdownLink>
                        </template>
                    </Dropdown>
                </div>
            </header>

            <!-- Mobile Full-Screen Search Modal -->
            <transition
                enter-active-class="transition duration-150 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-100 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="navigationStore.isSearchOpen"
                    class="fixed inset-0 z-50 sm:hidden bg-gray-900/60 backdrop-blur-sm p-4 flex flex-col"
                    @click.self="navigationStore.closeSearch"
                >
                    <div class="w-full bg-white dark:bg-gray-800 rounded-2xl shadow-2xl overflow-hidden border border-gray-200 dark:border-gray-700 flex flex-col max-h-[85vh]">
                        <!-- Search Bar Header -->
                        <div class="p-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2">
                            <div class="relative flex-1">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>
                                <input
                                    ref="mobileSearchInput"
                                    v-model="navigationStore.searchQuery"
                                    type="text"
                                    class="block w-full pl-9 pr-3 py-2 border-0 bg-gray-100 dark:bg-gray-700/60 text-gray-900 dark:text-gray-100 rounded-xl text-sm focus:ring-2 focus:ring-green-500"
                                    placeholder="Search menus, reports, forms..."
                                >
                            </div>
                            <button
                                @click="navigationStore.closeSearch"
                                class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                            >
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <!-- Results List -->
                        <div class="flex-1 overflow-y-auto p-2 space-y-1">
                            <div
                                v-if="navigationStore.searchResults.length === 0"
                                class="p-6 text-center text-sm text-gray-500 dark:text-gray-400"
                            >
                                <p class="font-medium">No matches found</p>
                                <p class="text-xs mt-1 text-gray-400">Try searching for "Salary", "GPF", or "Bank Advice"</p>
                            </div>

                            <button
                                v-for="(item, idx) in navigationStore.searchResults"
                                :key="item.routeName"
                                @click="selectResult(item)"
                                class="w-full text-left flex items-start gap-3 p-2.5 rounded-xl transition-colors"
                                :class="[
                                    navigationStore.selectedIndex === idx
                                        ? 'bg-green-50 dark:bg-green-950/40 text-green-800 dark:text-green-300'
                                        : 'hover:bg-gray-50 dark:hover:bg-gray-700/60 text-gray-700 dark:text-gray-200'
                                ]"
                            >
                                <div
                                    class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 shrink-0 mt-0.5 text-green-600 dark:text-green-400"
                                    v-html="item.icon"
                                ></div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <span class="font-medium text-sm truncate text-gray-900 dark:text-gray-100">{{ item.title }}</span>
                                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-500">{{ item.category }}</span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">{{ item.description }}</p>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>
            </transition>

            <!-- Page Header Slot -->
            <header class="bg-white shadow-sm dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700/50" v-if="$slots.header">
                <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Main Content -->
            <main class="flex-1 mx-auto w-full max-w-[100%] p-4 sm:px-2 lg:px-4">
                <slot />
            </main>
        </div>
    </div>
</template>
