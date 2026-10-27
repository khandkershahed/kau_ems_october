<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    metrics: {
        type: Object,
        default: () => ({}),
    },
});

// Currency formatter for Bangladesh Taka (৳)
const formatCurrency = (amount) => {
    const num = Number(amount) || 0;
    return '৳ ' + num.toLocaleString('en-BD', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });
};

// Format short month name
const monthNames = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
];

const currentMonthName = computed(() => {
    const m = props.metrics?.currentMonth || (new Date().getMonth() + 1);
    return monthNames[m - 1] || 'Current Month';
});

// Payroll Generation Completion Percentage
const payrollCompletionRate = computed(() => {
    const active = props.metrics?.activeEmployees || 0;
    const generated = props.metrics?.currentMonthSalary?.generatedBills || 0;
    if (!active) return 0;
    return Math.min(100, Math.round((generated / active) * 100));
});

// Calculate Max Value for SVG Trend Chart
const trendMax = computed(() => {
    const trend = props.metrics?.monthlyTrend || [];
    if (!trend.length) return 1000;
    const max = Math.max(...trend.map(t => t.disbursed || 0));
    return max > 0 ? max * 1.15 : 1000;
});

// Compute SVG Chart Points
const trendPoints = computed(() => {
    const trend = props.metrics?.monthlyTrend || [];
    if (!trend.length) return [];
    
    const max = trendMax.value;
    const width = 500;
    const height = 150;
    const padding = 30;
    const effectiveWidth = width - (padding * 2);
    const effectiveHeight = height - (padding * 2);

    return trend.map((t, idx) => {
        const x = padding + (idx * (effectiveWidth / Math.max(1, trend.length - 1)));
        const y = height - padding - ((t.disbursed / max) * effectiveHeight);
        return {
            ...t,
            x,
            y,
        };
    });
});

const trendPolyline = computed(() => {
    return trendPoints.value.map(p => `${p.x},${p.y}`).join(' ');
});

const trendAreaPolygon = computed(() => {
    if (!trendPoints.value.length) return '';
    const first = trendPoints.value[0];
    const last = trendPoints.value[trendPoints.value.length - 1];
    const baseline = 150 - 30;
    return `${first.x},${baseline} ${trendPolyline.value} ${last.x},${baseline}`;
});

// Status Badge Styling Helper
const getStatusBadge = (status) => {
    switch ((status || '').toLowerCase()) {
        case 'approved':
            return {
                label: 'Approved',
                bg: 'bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
                dot: 'bg-emerald-500',
            };
        case 'review':
            return {
                label: 'In Review',
                bg: 'bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border-amber-200 dark:border-amber-800',
                dot: 'bg-amber-500 animate-pulse',
            };
        default:
            return {
                label: 'Pending',
                bg: 'bg-blue-100 dark:bg-blue-950/60 text-blue-800 dark:text-blue-300 border-blue-200 dark:border-blue-800',
                dot: 'bg-blue-500',
            };
    }
};
</script>

<template>
    <Head title="Executive Payroll & HR Dashboard - KAU" />

    <AuthenticatedLayout>
        <div class="py-6 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-6">

            <!-- Executive Header Banner -->
            <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-green-700 via-emerald-800 to-teal-900 text-white p-6 sm:p-8 shadow-xl">
                <!-- Background university accent graphic -->
                <div class="absolute -right-10 -bottom-10 w-72 h-72 bg-white/5 rounded-full blur-2xl pointer-events-none"></div>
                <div class="absolute right-10 top-0 w-48 h-48 bg-emerald-400/10 rounded-full blur-xl pointer-events-none"></div>

                <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div class="space-y-2">
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-900/60 border border-emerald-400/30 text-xs font-semibold tracking-wide text-emerald-200">
                            <span class="inline-block w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            Khulna Agricultural University — Financial System
                        </div>
                        <h1 class="text-2xl sm:text-3xl font-bold tracking-tight text-white">
                            Executive Payroll & Workforce Command Center
                        </h1>
                        <p class="text-sm sm:text-base text-emerald-100/80 max-w-2xl">
                            Real-time compensation analytics, GPF statutory accounting, festival bonus audits, and workforce allocation for Fiscal Year {{ metrics.financialYear || '2026-2027' }}.
                        </p>
                    </div>

                    <!-- Quick Action Buttons -->
                    <div class="flex flex-wrap gap-2.5 shrink-0">
                        <Link
                            :href="route('salary-generates.index')"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white text-green-900 font-semibold text-xs sm:text-sm hover:bg-emerald-50 transition-all shadow-md active:scale-95"
                        >
                            <svg class="w-4 h-4 text-green-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Generate Salary
                        </Link>
                        <Link
                            :href="route('employees.create')"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600/80 hover:bg-emerald-500 border border-emerald-400/40 text-white font-semibold text-xs sm:text-sm transition-all shadow-md active:scale-95"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                            </svg>
                            Add Employee
                        </Link>
                        <Link
                            :href="route('reports.salary-reports.index')"
                            class="inline-flex items-center gap-2 px-3 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 text-white font-medium text-xs sm:text-sm transition-all active:scale-95"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Reports
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Administrative Notices / Action Alert -->
            <div 
                v-if="metrics.alerts?.heldIncrements > 0 || metrics.alerts?.pendingSalaryBills > 0"
                class="p-4 rounded-xl border border-amber-200 dark:border-amber-900/60 bg-amber-50 dark:bg-amber-950/30 text-amber-900 dark:text-amber-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-sm shadow-sm"
            >
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg bg-amber-200/60 dark:bg-amber-800/60 text-amber-800 dark:text-amber-200 shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div>
                        <span class="font-bold">Pending Operational Items:</span>
                        <span v-if="metrics.alerts?.pendingSalaryBills > 0" class="ml-1">
                            {{ metrics.alerts.pendingSalaryBills }} staff members pending monthly salary generation for {{ currentMonthName }} {{ metrics.currentYear }}.
                        </span>
                        <span v-if="metrics.alerts?.heldIncrements > 0" class="ml-2 font-medium text-amber-700 dark:text-amber-300">
                            ({{ metrics.alerts.heldIncrements }} annual increments currently on hold).
                        </span>
                    </div>
                </div>
                <Link 
                    :href="route('salary-generates.index')" 
                    class="shrink-0 text-xs font-bold uppercase tracking-wider underline hover:text-amber-700 dark:hover:text-amber-100"
                >
                    Review Billing &rarr;
                </Link>
            </div>

            <!-- Primary 4 KPI Metrics Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">

                <!-- 1. Workforce KPI -->
                <div class="rounded-2xl p-5 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700/60 shadow-sm transition-all hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Workforce</span>
                        <div class="p-2.5 rounded-xl bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ metrics.totalEmployees || 0 }}</span>
                        <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">
                            {{ metrics.activeEmployees || 0 }} Active
                        </span>
                    </div>
                    <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-700/50 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>Male: {{ metrics.genderBreakdown?.male || 0 }} | Female: {{ metrics.genderBreakdown?.female || 0 }}</span>
                        <Link :href="route('employees.edit')" class="text-green-600 dark:text-green-400 hover:underline font-medium">Directory</Link>
                    </div>
                </div>

                <!-- 2. Monthly Payroll KPI -->
                <div class="rounded-2xl p-5 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700/60 shadow-sm transition-all hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ currentMonthName }} Payroll
                        </span>
                        <div class="p-2.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-100 truncate">
                            {{ formatCurrency(metrics.currentMonthSalary?.totalDisbursed) }}
                        </span>
                    </div>
                    <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-700/50">
                        <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400 mb-1">
                            <span>Bills Prepared: {{ metrics.currentMonthSalary?.generatedBills || 0 }}/{{ metrics.activeEmployees || 0 }}</span>
                            <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ payrollCompletionRate }}%</span>
                        </div>
                        <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-1.5 overflow-hidden">
                            <div class="bg-emerald-500 h-1.5 rounded-full transition-all duration-500" :style="{ width: `${payrollCompletionRate}%` }"></div>
                        </div>
                    </div>
                </div>

                <!-- 3. Statutory Deductions KPI -->
                <div class="rounded-2xl p-5 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700/60 shadow-sm transition-all hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Deductions</span>
                        <div class="p-2.5 rounded-xl bg-purple-50 dark:bg-purple-950/50 text-purple-600 dark:text-purple-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-100 truncate">
                            {{ formatCurrency(metrics.currentMonthSalary?.totalDeduction) }}
                        </span>
                    </div>
                    <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-700/50 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>Tax: {{ formatCurrency(metrics.currentMonthSalary?.taxDeduction) }}</span>
                        <span>GPF: {{ formatCurrency(metrics.currentMonthSalary?.gpfDeduction) }}</span>
                    </div>
                </div>

                <!-- 4. GPF Reserve Pool KPI -->
                <div class="rounded-2xl p-5 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700/60 shadow-sm transition-all hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Provident Fund (GPF)</span>
                        <div class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-100 truncate">
                            {{ formatCurrency(metrics.gpf?.totalBalance) }}
                        </span>
                    </div>
                    <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-700/50 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>{{ metrics.gpf?.activeSubscribers || 0 }} Accounts</span>
                        <Link :href="route('gpf.index')" class="text-amber-600 dark:text-amber-400 hover:underline font-medium">Ledger</Link>
                    </div>
                </div>

            </div>

            <!-- Financial Visualizations & Breakdown Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Monthly Payroll Trend Chart (2 Cols) -->
                <div class="lg:col-span-2 rounded-2xl p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700/60 shadow-sm">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-6">
                        <div>
                            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Monthly Payroll Expenditure Trend</h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400">6-Month historical overview of net salary disbursements</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400 font-medium">
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Disbursed Amount
                            </span>
                        </div>
                    </div>

                    <!-- SVG Chart Graphic -->
                    <div class="relative w-full h-52 sm:h-60">
                        <svg viewBox="0 0 500 150" class="w-full h-full overflow-visible" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="trendGradient" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#10b981" stop-opacity="0.35" />
                                    <stop offset="100%" stop-color="#10b981" stop-opacity="0.0" />
                                </linearGradient>
                            </defs>

                            <!-- Grid Lines -->
                            <line x1="30" y1="30" x2="470" y2="30" stroke="#9ca3af" stroke-opacity="0.2" stroke-dasharray="3 3" />
                            <line x1="30" y1="75" x2="470" y2="75" stroke="#9ca3af" stroke-opacity="0.2" stroke-dasharray="3 3" />
                            <line x1="30" y1="120" x2="470" y2="120" stroke="#9ca3af" stroke-opacity="0.2" />

                            <!-- Gradient Fill Area -->
                            <polygon v-if="trendAreaPolygon" :points="trendAreaPolygon" fill="url(#trendGradient)" />

                            <!-- Trend Line -->
                            <polyline
                                v-if="trendPolyline"
                                fill="none"
                                stroke="#10b981"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                :points="trendPolyline"
                            />

                            <!-- Data Points and Values -->
                            <g v-for="pt in trendPoints" :key="pt.label">
                                <circle :cx="pt.x" :cy="pt.y" r="4.5" class="fill-white stroke-emerald-600 dark:fill-gray-900" stroke-width="2.5" />
                                <text :x="pt.x" y="142" text-anchor="middle" class="text-[10px] fill-gray-400 font-medium">
                                    {{ pt.label }}
                                </text>
                                <text :x="pt.x" :y="pt.y - 10" text-anchor="middle" class="text-[10px] fill-gray-600 dark:fill-gray-300 font-bold">
                                    {{ pt.disbursed > 0 ? (pt.disbursed >= 1000 ? Math.round(pt.disbursed / 1000) + 'k' : pt.disbursed) : '0' }}
                                </text>
                            </g>
                        </svg>
                    </div>

                    <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-700/50 flex flex-wrap items-center justify-between gap-4 text-xs text-gray-500 dark:text-gray-400">
                        <span>Latest Disbursed Month: <strong class="text-gray-900 dark:text-gray-100">{{ currentMonthName }} {{ metrics.currentYear }}</strong></span>
                        <Link :href="route('salary-generates.index')" class="text-green-600 dark:text-green-400 font-semibold hover:underline">View All Generated Bills &rarr;</Link>
                    </div>
                </div>

                <!-- Payroll Composition & Bill Audit Status (1 Col) -->
                <div class="rounded-2xl p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700/60 shadow-sm flex flex-col justify-between">
                    <div>
                        <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Monthly Compensation Heads</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Gross allowances vs statutory deductions</p>

                        <!-- Allowance vs Deduction Bars -->
                        <div class="mt-6 space-y-4">
                            <div>
                                <div class="flex justify-between text-xs mb-1 font-medium">
                                    <span class="text-emerald-700 dark:text-emerald-400">Gross Allowances</span>
                                    <span class="text-gray-900 dark:text-gray-100 font-bold">{{ formatCurrency(metrics.currentMonthSalary?.totalAllowance) }}</span>
                                </div>
                                <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                                    <div class="bg-emerald-500 h-2 rounded-full" style="width: 100%"></div>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between text-xs mb-1 font-medium">
                                    <span class="text-rose-700 dark:text-rose-400">Total Deductions</span>
                                    <span class="text-gray-900 dark:text-gray-100 font-bold">{{ formatCurrency(metrics.currentMonthSalary?.totalDeduction) }}</span>
                                </div>
                                <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                                    <div 
                                        class="bg-rose-500 h-2 rounded-full" 
                                        :style="{ 
                                            width: `${Math.min(100, Math.round(((metrics.currentMonthSalary?.totalDeduction || 0) / Math.max(1, metrics.currentMonthSalary?.totalAllowance || 1)) * 100))}%` 
                                        }"
                                    ></div>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between text-xs mb-1 font-medium">
                                    <span class="text-blue-700 dark:text-blue-400">Net Payable Amount</span>
                                    <span class="text-gray-900 dark:text-gray-100 font-bold">{{ formatCurrency(metrics.currentMonthSalary?.totalDisbursed) }}</span>
                                </div>
                                <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                                    <div 
                                        class="bg-blue-500 h-2 rounded-full" 
                                        :style="{ 
                                            width: `${Math.min(100, Math.round(((metrics.currentMonthSalary?.totalDisbursed || 0) / Math.max(1, metrics.currentMonthSalary?.totalAllowance || 1)) * 100))}%` 
                                        }"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        <!-- Bill Review Status Counts -->
                        <div class="mt-6 pt-5 border-t border-gray-100 dark:border-gray-700/50">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-3">Bill Audit Status</h3>
                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div class="p-2.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-900/40">
                                    <div class="text-lg font-bold text-emerald-700 dark:text-emerald-300">
                                        {{ metrics.currentMonthSalary?.reviewStatus?.approved || 0 }}
                                    </div>
                                    <div class="text-[10px] uppercase font-semibold text-emerald-600 dark:text-emerald-400">Approved</div>
                                </div>
                                <div class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900/40">
                                    <div class="text-lg font-bold text-amber-700 dark:text-amber-300">
                                        {{ metrics.currentMonthSalary?.reviewStatus?.review || 0 }}
                                    </div>
                                    <div class="text-[10px] uppercase font-semibold text-amber-600 dark:text-amber-400">Review</div>
                                </div>
                                <div class="p-2.5 rounded-xl bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-900/40">
                                    <div class="text-lg font-bold text-blue-700 dark:text-blue-300">
                                        {{ metrics.currentMonthSalary?.reviewStatus?.pending || 0 }}
                                    </div>
                                    <div class="text-[10px] uppercase font-semibold text-blue-600 dark:text-blue-400">Pending</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Festival Bonus Quick Snapshot -->
                    <div class="mt-6 pt-4 border-t border-gray-100 dark:border-gray-700/50 flex items-center justify-between text-xs">
                        <div class="flex items-center gap-2">
                            <span class="p-1.5 rounded-md bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7" />
                                </svg>
                            </span>
                            <span class="font-medium text-gray-700 dark:text-gray-300 truncate max-w-[150px]">{{ metrics.bonus?.title }}</span>
                        </div>
                        <span class="font-bold text-gray-900 dark:text-gray-100">{{ formatCurrency(metrics.bonus?.totalDisbursed) }}</span>
                    </div>
                </div>

            </div>

            <!-- Departmental & Workforce Distribution Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- Departmental Allocation Card -->
                <div class="rounded-2xl p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700/60 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Departmental Staffing & Payroll</h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Top university faculties, departments and administrative units</p>
                        </div>
                        <Link :href="route('department-offices.index')" class="text-xs font-semibold text-green-600 dark:text-green-400 hover:underline">Manage All</Link>
                    </div>

                    <div class="space-y-3.5 mt-4">
                        <div 
                            v-for="dept in metrics.departments" 
                            :key="dept.id"
                            class="flex items-center justify-between p-3 rounded-xl bg-gray-50 dark:bg-gray-700/40 border border-gray-100 dark:border-gray-700/50"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-8 h-8 rounded-lg bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 flex items-center justify-center font-bold text-xs shrink-0">
                                    {{ (dept.name || 'D').charAt(0) }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">{{ dept.name }}</p>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 capitalize">{{ dept.type || 'Department' }}</span>
                                </div>
                            </div>
                            <div class="text-right shrink-0 ml-3">
                                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-bold bg-gray-200/70 dark:bg-gray-600 text-gray-800 dark:text-gray-200">
                                    {{ dept.staff_count }} staff
                                </span>
                                <p class="text-xs text-emerald-600 dark:text-emerald-400 font-semibold mt-0.5">
                                    {{ formatCurrency(dept.monthly_disbursed) }}
                                </p>
                            </div>
                        </div>

                        <div v-if="!metrics.departments?.length" class="text-center py-6 text-sm text-gray-500">
                            No departments registered yet.
                        </div>
                    </div>
                </div>

                <!-- Workforce Classification & Categories -->
                <div class="rounded-2xl p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700/60 shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Workforce Classification</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Distribution by Employee Category & Contract Status</p>
                            </div>
                            <Link :href="route('user-categories.index')" class="text-xs font-semibold text-green-600 dark:text-green-400 hover:underline">Categories</Link>
                        </div>

                        <!-- Category Distribution -->
                        <div class="space-y-3 mt-4">
                            <div v-for="cat in metrics.userCategories" :key="cat.id" class="space-y-1">
                                <div class="flex justify-between text-xs font-medium">
                                    <span class="text-gray-700 dark:text-gray-300">{{ cat.name }}</span>
                                    <span class="text-gray-900 dark:text-gray-100 font-bold">{{ cat.count }} staff ({{ Math.round((cat.count / Math.max(1, metrics.totalEmployees)) * 100) }}%)</span>
                                </div>
                                <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                                    <div 
                                        class="bg-emerald-600 dark:bg-emerald-500 h-2 rounded-full" 
                                        :style="{ width: `${Math.round((cat.count / Math.max(1, metrics.totalEmployees)) * 100)}%` }"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        <!-- Job Status Chips -->
                        <div class="mt-6 pt-5 border-t border-gray-100 dark:border-gray-700/50">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-3">Employment Status Distribution</h3>
                            <div class="flex flex-wrap gap-2">
                                <div 
                                    v-for="(count, status) in metrics.jobStatusBreakdown" 
                                    :key="status"
                                    class="px-3 py-1.5 rounded-xl bg-gray-100 dark:bg-gray-700/60 text-xs font-medium text-gray-700 dark:text-gray-300 flex items-center gap-2 capitalize"
                                >
                                    <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                    <span>{{ status }}:</span>
                                    <strong class="text-gray-900 dark:text-gray-100">{{ count }}</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick System Metrics Footer -->
                    <div class="mt-6 pt-4 border-t border-gray-100 dark:border-gray-700/50 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>Institution: Khulna Agricultural University</span>
                        <span>Pay Scale: NPS 2015</span>
                    </div>
                </div>

            </div>

            <!-- Recent Salary Bills / Audit Table -->
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700/60 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700/60 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Recent Salary Disbursements & Bills</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Latest monthly compensation records prepared across university faculties</p>
                    </div>
                    <Link
                        :href="route('salary-generates.index')"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-gray-700 hover:bg-green-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 hover:text-green-600 dark:hover:text-green-400 transition-colors"
                    >
                        View Full Register &rarr;
                    </Link>
                </div>

                <!-- Responsive Table Wrapper -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-800/80 text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                <th class="py-3 px-4">Employee</th>
                                <th class="py-3 px-4">Designation & Department</th>
                                <th class="py-3 px-4">Billing Period</th>
                                <th class="py-3 px-4 text-right">Net Salary</th>
                                <th class="py-3 px-4 text-center">Status</th>
                                <th class="py-3 px-4 text-right">Generated Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                            <tr 
                                v-for="bill in metrics.recentBills" 
                                :key="bill.id"
                                class="hover:bg-gray-50/70 dark:hover:bg-gray-700/40 transition-colors"
                            >
                                <td class="py-3 px-4">
                                    <div class="font-semibold text-gray-900 dark:text-gray-100">{{ bill.name }}</div>
                                    <div class="text-xs text-gray-400">Code: {{ bill.employeeCode }}</div>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="text-gray-800 dark:text-gray-200 font-medium">{{ bill.designation }}</div>
                                    <div class="text-xs text-gray-400 truncate max-w-[200px]">{{ bill.department }}</div>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-gray-600 dark:text-gray-300">
                                    {{ monthNames[(bill.month || 1) - 1] }} {{ bill.year }}
                                </td>
                                <td class="py-3 px-4 text-right font-bold text-gray-900 dark:text-gray-100 whitespace-nowrap">
                                    {{ formatCurrency(bill.salaryTotal) }}
                                </td>
                                <td class="py-3 px-4 text-center whitespace-nowrap">
                                    <span 
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border"
                                        :class="getStatusBadge(bill.reviewStatus).bg"
                                    >
                                        <span class="w-1.5 h-1.5 rounded-full" :class="getStatusBadge(bill.reviewStatus).dot"></span>
                                        {{ getStatusBadge(bill.reviewStatus).label }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                    {{ bill.generatedDate }}
                                </td>
                            </tr>

                            <tr v-if="!metrics.recentBills?.length">
                                <td colspan="6" class="text-center py-8 text-sm text-gray-500 dark:text-gray-400">
                                    No salary bills generated yet for this period. Click "Generate Salary" to begin.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
