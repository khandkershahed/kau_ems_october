<script setup>
import { computed, ref, onMounted } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { useThemeStore } from '@/stores/theme';

const props = defineProps({
    status: {
        type: Number,
        default: 404,
    },
    message: {
        type: String,
        default: null,
    },
});

const page = usePage();
const themeStore = useThemeStore();
const showTechDetails = ref(false);
const copied = ref(false);

onMounted(() => {
    themeStore.initTheme();
});

const authUser = computed(() => page.props.auth?.user || null);

const currentPath = computed(() => {
    if (page.url) return page.url;
    if (typeof window !== 'undefined' && window.location?.pathname) {
        return window.location.pathname;
    }
    return '/';
});

// Configuration map for each supported HTTP error status
const errorConfig = computed(() => {
    const code = Number(props.status) || 404;

    const configs = {
        404: {
            title: 'Page Not Found',
            banglaTitle: 'পাতাটি পাওয়া যায়নি',
            description: 'The requested page or administrative resource could not be found. It may have been moved, renamed, or is temporarily unavailable.',
            badge: '404 • Resource Not Found',
            badgeClass: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300 border-emerald-300 dark:border-emerald-800',
            glowColor: 'from-emerald-500/25 to-teal-500/10 dark:from-emerald-500/20 dark:to-teal-500/5',
            accentText: 'text-emerald-600 dark:text-emerald-400',
            iconType: 'search',
        },
        500: {
            title: 'Internal Server Error',
            banglaTitle: 'সার্ভার অভ্যন্তরীণ বিভ্রাট',
            description: 'An unexpected server condition occurred while processing this request. Our technical staff has been notified to investigate.',
            badge: '500 • Critical Server Exception',
            badgeClass: 'bg-rose-100 text-rose-800 dark:bg-rose-950/70 dark:text-rose-300 border-rose-300 dark:border-rose-800',
            glowColor: 'from-rose-500/25 to-amber-500/10 dark:from-rose-500/20 dark:to-amber-500/5',
            accentText: 'text-rose-600 dark:text-rose-400',
            iconType: 'server',
        },
        403: {
            title: 'Access Forbidden',
            banglaTitle: 'অননুমোদিত প্রবেশাধিকার',
            description: 'You do not have the required administrative role or clearance to access this resource. Please contact the system administrator.',
            badge: '403 • Clearance Required',
            badgeClass: 'bg-amber-100 text-amber-800 dark:bg-amber-950/70 dark:text-amber-300 border-amber-300 dark:border-amber-800',
            glowColor: 'from-amber-500/25 to-orange-500/10 dark:from-amber-500/20 dark:to-orange-500/5',
            accentText: 'text-amber-600 dark:text-amber-400',
            iconType: 'shield',
        },
        401: {
            title: 'Authentication Required',
            banglaTitle: 'অনুমোদনের জন্য লগইন প্রয়োজন',
            description: 'Your active session could not be verified or has timed out. Please sign in with your institutional credentials to proceed.',
            badge: '401 • Unauthenticated Session',
            badgeClass: 'bg-cyan-100 text-cyan-800 dark:bg-cyan-950/70 dark:text-cyan-300 border-cyan-300 dark:border-cyan-800',
            glowColor: 'from-cyan-500/25 to-emerald-500/10 dark:from-cyan-500/20 dark:to-emerald-500/5',
            accentText: 'text-cyan-600 dark:text-cyan-400',
            iconType: 'key',
        },
        419: {
            title: 'Session Token Expired',
            banglaTitle: 'নিরাপত্তা সেশনের মেয়াদ শেষ',
            description: 'Your CSRF security token has expired due to page inactivity. Please refresh the page or sign in again to continue safely.',
            badge: '419 • Security Token Timeout',
            badgeClass: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-950/70 dark:text-yellow-300 border-yellow-300 dark:border-yellow-800',
            glowColor: 'from-yellow-500/25 to-emerald-500/10 dark:from-yellow-500/20 dark:to-emerald-500/5',
            accentText: 'text-yellow-600 dark:text-yellow-400',
            iconType: 'refresh',
        },
        503: {
            title: 'Service Temporarily Unavailable',
            banglaTitle: 'সিস্টেম রক্ষণাবেক্ষণ চলছে',
            description: 'The Employee Management System is temporarily unavailable for scheduled maintenance or database optimization. Please check back shortly.',
            badge: '503 • System Maintenance',
            badgeClass: 'bg-blue-100 text-blue-800 dark:bg-blue-950/70 dark:text-blue-300 border-blue-300 dark:border-blue-800',
            glowColor: 'from-blue-500/25 to-teal-500/10 dark:from-blue-500/20 dark:to-teal-500/5',
            accentText: 'text-blue-600 dark:text-blue-400',
            iconType: 'tool',
        },
    };

    return configs[code] || configs[404];
});

const goBack = () => {
    if (window.history.length > 1) {
        window.history.back();
    } else if (authUser.value) {
        window.location.href = '/dashboard';
    } else {
        window.location.href = '/login';
    }
};

const copyErrorReport = async () => {
    const report = `[KAU EMS Error Report]
Status: ${props.status}
Title: ${errorConfig.value.title}
Message: ${props.message || 'N/A'}
Timestamp: ${new Date().toISOString()}
Path: ${currentPath.value}
User: ${authUser.value ? `${authUser.value.name} (${authUser.value.email || 'No email'})` : 'Guest'}`;

    try {
        await navigator.clipboard.writeText(report);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2500);
    } catch (e) {
        console.error('Clipboard copy failed:', e);
    }
};
</script>

<template>
    <Head :title="`${status} - ${errorConfig.title}`" />

    <div class="relative min-h-screen flex flex-col justify-between text-slate-900 dark:text-slate-100 transition-colors duration-300 selection:bg-emerald-500 selection:text-white overflow-x-hidden">

        <!-- Ambient Background Mesh -->
        <div class="fixed inset-0 -z-10 bg-slate-50 dark:bg-gray-950 transition-colors duration-500 overflow-hidden">
            <!-- Subtle Radial Gradient Background -->
            <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-emerald-100/40 via-slate-50 to-slate-100/60 dark:from-emerald-950/20 dark:via-gray-950 dark:to-gray-950"></div>

            <!-- Soft Top-Left Ambient Orb -->
            <div class="absolute -top-32 -left-32 w-80 h-80 rounded-full bg-emerald-400/15 dark:bg-emerald-600/10 blur-[90px] pointer-events-none"></div>

            <!-- Soft Bottom-Right Ambient Orb -->
            <div class="absolute -bottom-32 -right-32 w-80 h-80 rounded-full bg-teal-400/15 dark:bg-teal-600/10 blur-[90px] pointer-events-none"></div>

            <!-- Dynamic Center Spotlight Accent -->
            <div
                class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[450px] sm:w-[550px] h-[450px] sm:h-[550px] rounded-full bg-gradient-to-tr blur-[120px] opacity-50 pointer-events-none transition-all duration-700"
                :class="errorConfig.glowColor"
            ></div>
        </div>

        <!-- Top Navigation Bar -->
        <header class="w-full max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-4 sm:py-6 flex items-center justify-between z-10">
            <!-- University Brand -->
            <Link
                :href="authUser ? route('dashboard') : '/login'"
                class="flex items-center gap-3 group focus:outline-none focus:ring-2 focus:ring-emerald-500 rounded-lg p-1"
                title="Khulna Agricultural University EMS"
            >
                <img
                    src="/logo.png"
                    alt="KAU Logo"
                    class="h-9 w-9 sm:h-11 sm:w-11 object-contain drop-shadow-sm group-hover:scale-105 transition-transform"
                />
                <div class="flex flex-col text-left">
                    <span class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white tracking-tight leading-tight">
                        Khulna Agricultural University
                    </span>
                    <span class="text-[10px] sm:text-xs text-emerald-700 dark:text-emerald-400 font-medium">
                        Employee Management System (EMS)
                    </span>
                </div>
            </Link>

            <!-- Top Actions: Status Badge & Theme Toggle -->
            <div class="flex items-center gap-2 sm:gap-3">
                <!-- User Status Pill -->
                <div
                    v-if="authUser"
                    class="hidden sm:flex items-center gap-1.5 px-3 py-1 rounded-full text-xs bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 font-medium"
                >
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="max-w-[140px] truncate">{{ authUser.name }}</span>
                </div>
                <div
                    v-else
                    class="hidden sm:flex items-center gap-1.5 px-3 py-1 rounded-full text-xs bg-slate-100 dark:bg-gray-800 border border-slate-200 dark:border-gray-700 text-slate-600 dark:text-slate-300"
                >
                    <span>Guest Session</span>
                </div>

                <ThemeToggle />
            </div>
        </header>

        <!-- Main Content Hero Container -->
        <main class="w-full max-w-xl sm:max-w-2xl mx-auto px-4 sm:px-6 my-auto py-6 sm:py-8 z-10 flex flex-col items-center">

            <!-- Glassmorphism Card Container (Strict Vertical Flex Layout) -->
            <div class="w-full bg-white/90 dark:bg-gray-900/90 backdrop-blur-2xl rounded-3xl p-6 sm:p-10 shadow-[0_12px_40px_rgb(0,0,0,0.08)] dark:shadow-[0_12px_40px_rgb(0,0,0,0.35)] border border-slate-200/80 dark:border-gray-800/80 flex flex-col items-center text-center transition-all duration-300">

                <!-- Giant Gradient Error Code (Top Hero) -->
                <div class="w-full flex justify-center leading-none select-none my-1">
                    <span class="text-7xl sm:text-8xl md:text-9xl font-black tracking-tight font-mono bg-clip-text text-transparent bg-gradient-to-br from-slate-900 via-slate-700 to-emerald-700 dark:from-white dark:via-slate-200 dark:to-emerald-400">
                        {{ status }}
                    </span>
                </div>

                <!-- Status Badge Pill (Centered directly below the number) -->
                <div class="w-full flex justify-center mb-4 mt-1">
                    <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold border shadow-sm" :class="errorConfig.badgeClass">
                        <!-- Icon based on type -->
                        <!-- Search Icon (404) -->
                        <svg v-if="errorConfig.iconType === 'search'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <!-- Server Icon (500) -->
                        <svg v-else-if="errorConfig.iconType === 'server'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                        </svg>
                        <!-- Shield Icon (403) -->
                        <svg v-else-if="errorConfig.iconType === 'shield'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        <!-- Key Icon (401) -->
                        <svg v-else-if="errorConfig.iconType === 'key'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                        <!-- Refresh Icon (419) -->
                        <svg v-else-if="errorConfig.iconType === 'refresh'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <!-- Tool Icon (503) -->
                        <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>

                        <span>{{ errorConfig.badge }}</span>
                    </span>
                </div>

                <!-- Primary Titles -->
                <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                    {{ errorConfig.title }}
                </h2>

                <p class="text-xs sm:text-sm font-semibold text-emerald-700 dark:text-emerald-400 mt-1">
                    {{ errorConfig.banglaTitle }}
                </p>

                <!-- Detailed Description -->
                <p class="text-sm sm:text-base text-slate-600 dark:text-slate-300 max-w-lg mx-auto mt-3 leading-relaxed">
                    {{ errorConfig.description }}
                </p>

                <!-- System Notice Alert if exception message exists -->
                <div
                    v-if="message"
                    class="w-full max-w-md mx-auto mt-4 p-3 rounded-xl bg-slate-100 dark:bg-gray-800/80 border border-slate-200 dark:border-gray-700 text-xs font-mono text-slate-700 dark:text-slate-300 text-left overflow-x-auto"
                >
                    <span class="font-bold text-slate-500 dark:text-slate-400">System Notice:</span> {{ message }}
                </div>

                <!-- Action Buttons (Responsive Grid/Row) -->
                <div class="w-full mt-7 flex flex-col sm:flex-row items-center justify-center gap-3">

                    <!-- Primary Action (Dashboard if logged in, Login if guest) -->
                    <Link
                        v-if="authUser"
                        :href="route('dashboard')"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-sm font-semibold shadow-md hover:shadow-lg shadow-emerald-600/20 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 min-h-[44px]"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                        <span>Return to Dashboard</span>
                    </Link>

                    <Link
                        v-else
                        href="/login"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-sm font-semibold shadow-md hover:shadow-lg shadow-emerald-600/20 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 min-h-[44px]"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                        </svg>
                        <span>Sign In to KAU Portal</span>
                    </Link>

                    <!-- Secondary Action: Go Back -->
                    <button
                        type="button"
                        @click="goBack"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-slate-700 dark:text-slate-200 text-sm font-semibold transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-slate-400 border border-slate-200 dark:border-gray-700 min-h-[44px]"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        <span>Previous Page</span>
                    </button>

                    <!-- Refresh button for 419 session expired or 503 maintenance -->
                    <button
                        v-if="status === 419 || status === 503"
                        type="button"
                        @click="() => window.location.reload()"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold transition-all duration-200 shadow-md min-h-[44px]"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <span>Reload Page</span>
                    </button>
                </div>

                <!-- Helpful Quick Links for Authenticated Users -->
                <div v-if="authUser" class="w-full mt-7 pt-5 border-t border-slate-200/80 dark:border-gray-800/80">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-400 mb-2.5">
                        Quick Administrative Navigation
                    </p>
                    <div class="flex flex-wrap items-center justify-center gap-2 text-xs">
                        <!-- <Link
                            :href="route('employees.history')"
                            class="px-3 py-1.5 rounded-lg bg-slate-50 dark:bg-gray-800/90 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 text-slate-700 dark:text-slate-300 hover:text-emerald-700 dark:hover:text-emerald-400 border border-slate-200 dark:border-gray-700 transition"
                        >
                            Staff Directory
                        </Link> -->
                        <!-- <Link
                            :href="route('reports.salary.index')"
                            class="px-3 py-1.5 rounded-lg bg-slate-50 dark:bg-gray-800/90 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 text-slate-700 dark:text-slate-300 hover:text-emerald-700 dark:hover:text-emerald-400 border border-slate-200 dark:border-gray-700 transition"
                        >
                            Salary Reports
                        </Link>
                        <Link
                            :href="route('general-provident-fund.index')"
                            class="px-3 py-1.5 rounded-lg bg-slate-50 dark:bg-gray-800/90 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 text-slate-700 dark:text-slate-300 hover:text-emerald-700 dark:hover:text-emerald-400 border border-slate-200 dark:border-gray-700 transition"
                        >
                            GPF Statements
                        </Link>
                        <Link
                            :href="route('profile.edit')"
                            class="px-3 py-1.5 rounded-lg bg-slate-50 dark:bg-gray-800/90 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 text-slate-700 dark:text-slate-300 hover:text-emerald-700 dark:hover:text-emerald-400 border border-slate-200 dark:border-gray-700 transition"
                        >
                            My Profile
                        </Link> -->
                    </div>
                </div>

                <!-- Technical Details Accordion (Collapsible) -->
                <div class="w-full mt-6 pt-4 border-t border-slate-200/60 dark:border-gray-800/60">
                    <button
                        type="button"
                        @click="showTechDetails = !showTechDetails"
                        class="text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200 inline-flex items-center gap-1.5 transition cursor-pointer"
                    >
                        <span>{{ showTechDetails ? 'Hide Diagnostics' : 'Show Diagnostics & Trace' }}</span>
                        <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="{ 'rotate-180': showTechDetails }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div
                        v-if="showTechDetails"
                        class="w-full mt-3 p-4 rounded-xl bg-slate-900 text-slate-100 text-left font-mono text-[11px] leading-relaxed shadow-inner border border-slate-800 transition-all"
                    >
                        <div class="flex items-center justify-between pb-2 mb-2 border-b border-slate-800 text-slate-400 text-[10px] uppercase tracking-wider">
                            <span>Diagnostic Trace Data</span>
                            <button
                                type="button"
                                @click="copyErrorReport"
                                class="inline-flex items-center gap-1 text-emerald-400 hover:text-emerald-300 transition cursor-pointer"
                            >
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                <span>{{ copied ? 'Copied to Clipboard!' : 'Copy Diagnostics' }}</span>
                            </button>
                        </div>
                        <div><span class="text-slate-500">HTTP Status:</span> <span class="text-emerald-400 font-bold">{{ status }}</span></div>
                        <div><span class="text-slate-500">Endpoint:</span> <span class="text-slate-300">{{ currentPath }}</span></div>
                        <div><span class="text-slate-500">Timestamp:</span> <span class="text-slate-300">{{ new Date().toLocaleString() }}</span></div>
                        <div><span class="text-slate-500">Auth State:</span> <span class="text-slate-300">{{ authUser ? `Authenticated (${authUser.name})` : 'Unauthenticated (Guest)' }}</span></div>
                        <div v-if="message" class="mt-1 pt-1 border-t border-slate-800 text-rose-300">
                            <span class="text-slate-500">Exception Message:</span> {{ message }}
                        </div>
                    </div>
                </div>

            </div>

        </main>

        <!-- Institutional Footer -->
        <footer class="w-full max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-4 sm:py-6 text-center text-xs text-slate-500 dark:text-slate-400 z-10 flex flex-col sm:flex-row items-center justify-between gap-2 border-t border-slate-200/60 dark:border-gray-800/60">
            <div>
                © {{ new Date().getFullYear() }} Khulna Agricultural University. All rights reserved.
            </div>
            <div class="flex items-center gap-3 text-[11px]">
                <span>ICT Cell Helpdesk</span>
                <span>•</span>
                <a href="mailto:support@kau.edu.bd" class="hover:text-emerald-600 dark:hover:text-emerald-400 underline transition">
                    support@kau.edu.bd
                </a>
            </div>
        </footer>

    </div>
</template>
