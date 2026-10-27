<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import Modal from '@/Components/Modal.vue';
import { useArchiveSalaryStore } from '@/stores/archiveSalary';
import { storeToRefs } from 'pinia';
import { Link } from '@inertiajs/vue3';

const archiveStore = useArchiveSalaryStore();
const { writeProgress, selectedYear, selectedMonth } = storeToRefs(archiveStore);

// Log filter state: 'all' | 'created' | 'updated' | 'error'
const logFilter = ref('all');
const autoScroll = ref(true);
const terminalRef = ref(null);

// Computed percentage
const progressPercentage = computed(() => {
    if (!writeProgress.value.total) return 0;
    return Math.min(100, Math.round((writeProgress.value.current / writeProgress.value.total) * 100));
});

// Month label helper
const monthLabel = computed(() => {
    const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    return `${months[(selectedMonth.value || 1) - 1]} ${selectedYear.value}`;
});

// Filtered logs
const filteredLogs = computed(() => {
    const list = writeProgress.value.logs || [];
    if (logFilter.value === 'all') return list;
    return list.filter(l => l.type === logFilter.value);
});

// Auto-scroll when logs change
watch(
    () => writeProgress.value.logs.length,
    async () => {
        if (autoScroll.value && terminalRef.value) {
            await nextTick();
            terminalRef.value.scrollTop = terminalRef.value.scrollHeight;
        }
    }
);

// Close confirmation if busy
const handleClose = () => {
    if (writeProgress.value.isProcessing) {
        if (confirm('A write process is currently running. Do you want to stop the writing queue and close?')) {
            archiveStore.cancelWriteQueue();
            archiveStore.closeWriteModal();
        }
        return;
    }
    archiveStore.closeWriteModal();
};

// Format money helper
const formatMoney = (val) => {
    const num = parseFloat(val);
    if (isNaN(num)) return '0.00';
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};
</script>

<template>
    <Modal :show="writeProgress.isOpen" @close="handleClose" max-width="4xl" :closeable="!writeProgress.isProcessing">
        <div class="p-4 sm:p-6 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded-xl transition-colors">
            <!-- Modal Header -->
            <div class="flex items-start justify-between pb-4 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-3">
                    <div
                        class="p-2.5 rounded-xl transition-all shadow-sm"
                        :class="writeProgress.isProcessing
                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-300 ring-4 ring-emerald-500/20 animate-pulse'
                            : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                    >
                        <svg v-if="writeProgress.isProcessing && !writeProgress.isPaused" class="w-6 h-6 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                        </svg>
                    </div>

                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white">
                                Writing Salary Records to Database
                            </h3>
                            <!-- State Pill -->
                            <span
                                v-if="writeProgress.isProcessing && !writeProgress.isPaused"
                                class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800"
                            >
                                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span>
                                In Progress
                            </span>
                            <span
                                v-else-if="writeProgress.isPaused"
                                class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-300 border border-amber-300 dark:border-amber-800"
                            >
                                <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                Paused
                            </span>
                            <span
                                v-else-if="writeProgress.isCancelled"
                                class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600"
                            >
                                Stopped
                            </span>
                            <span
                                v-else-if="!writeProgress.isProcessing && writeProgress.current > 0"
                                class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                Completed
                            </span>
                        </div>

                        <p class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                            Target Bill: <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ monthLabel }}</span>
                            • Writing records one by one with soft update protection.
                        </p>
                    </div>
                </div>

                <button
                    @click="handleClose"
                    :disabled="writeProgress.isProcessing"
                    class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-30 transition-colors"
                    title="Close"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Progress Bar Section -->
            <div class="mt-4 space-y-2">
                <div class="flex items-center justify-between text-xs sm:text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                        <span v-if="writeProgress.isProcessing" class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        Processing Record <span class="font-bold text-gray-900 dark:text-white">{{ writeProgress.current }}</span> of <span class="font-bold text-gray-900 dark:text-white">{{ writeProgress.total }}</span>
                    </span>
                    <span class="font-bold text-emerald-600 dark:text-emerald-400 font-mono text-sm sm:text-base">
                        {{ progressPercentage }}%
                    </span>
                </div>

                <!-- Gradient Progress Bar -->
                <div class="w-full bg-gray-200 dark:bg-gray-700 h-3 sm:h-3.5 rounded-full overflow-hidden shadow-inner relative">
                    <div
                        class="h-full bg-gradient-to-r from-emerald-600 via-teal-500 to-emerald-400 rounded-full transition-all duration-300 ease-out relative"
                        :style="{ width: `${progressPercentage}%` }"
                    >
                        <!-- Animated stripes during processing -->
                        <div
                            v-if="writeProgress.isProcessing && !writeProgress.isPaused"
                            class="absolute inset-0 bg-white/20 bg-[linear-gradient(45deg,rgba(255,255,255,0.15)_25%,transparent_25%,transparent_50%,rgba(255,255,255,0.15)_50%,rgba(255,255,255,0.15)_75%,transparent_75%,transparent)] bg-[length:1rem_1rem] animate-[move-bg_1s_linear_infinite]"
                        ></div>
                    </div>
                </div>
            </div>

            <!-- Real-time KPI Metric Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 sm:gap-3 mt-4">
                <!-- Total Queued -->
                <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-900/60 border border-gray-200 dark:border-gray-700/80 shadow-xs">
                    <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider block">Total Queued</span>
                    <p class="text-lg sm:text-xl font-bold text-gray-900 dark:text-white mt-0.5">
                        {{ writeProgress.total }}
                    </p>
                    <span class="text-[11px] text-gray-500 dark:text-gray-400">Records to write</span>
                </div>

                <!-- Newly Created -->
                <div class="p-3 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/60 shadow-xs">
                    <span class="text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider block flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Newly Created
                    </span>
                    <p class="text-lg sm:text-xl font-bold text-emerald-700 dark:text-emerald-300 mt-0.5">
                        {{ writeProgress.createdCount }}
                    </p>
                    <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium">New salary records</span>
                </div>

                <!-- Softly Updated -->
                <div class="p-3 rounded-xl bg-amber-50/70 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/60 shadow-xs">
                    <span class="text-[11px] font-semibold text-amber-700 dark:text-amber-400 uppercase tracking-wider block flex items-center gap-1" title="Already existed in DB, softly updating...">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Softly Updated
                    </span>
                    <p class="text-lg sm:text-xl font-bold text-amber-700 dark:text-amber-300 mt-0.5">
                        {{ writeProgress.updatedCount }}
                    </p>
                    <span class="text-[11px] text-amber-600 dark:text-amber-400 font-medium">Updated existing record(Manual supervision required)</span>
                </div>

                <!-- Errors / Failed -->
                <div class="p-3 rounded-xl bg-rose-50/70 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-800/60 shadow-xs">
                    <span class="text-[11px] font-semibold text-rose-700 dark:text-rose-400 uppercase tracking-wider block flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Errors
                    </span>
                    <p class="text-lg sm:text-xl font-bold text-rose-700 dark:text-rose-300 mt-0.5">
                        {{ writeProgress.errorCount }}
                    </p>
                    <span class="text-[11px] text-rose-600 dark:text-rose-400 font-medium">Failed to save</span>
                </div>
            </div>

            <!-- Active Record In-Flight Banner -->
            <div
                v-if="writeProgress.currentRecord && writeProgress.isProcessing"
                class="mt-3.5 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 dark:bg-emerald-950/40 dark:border-emerald-800/50 flex items-center justify-between gap-3 text-xs sm:text-sm"
            >
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-8 h-8 rounded-full bg-emerald-600 text-white font-bold flex items-center justify-center text-xs shrink-0">
                        {{ (writeProgress.currentRecord.employee_info?.name || '').split(' ').map(n => n[0]).slice(0, 2).join('') }}
                    </div>
                    <div class="truncate">
                        <div class="font-semibold text-gray-900 dark:text-white truncate">
                            {{ writeProgress.currentRecord.employee_info?.name }}
                        </div>
                        <div class="text-[11px] text-gray-500 dark:text-gray-400 flex items-center gap-1.5 truncate">
                            <span>ID: {{ writeProgress.currentRecord.employee_info?.service_id || 'N/A' }}</span>
                            <span>•</span>
                            <span>{{ writeProgress.currentRecord.employee_info?.designation || 'Staff' }}</span>
                            <span>•</span>
                            <span class="text-emerald-600 dark:text-emerald-400 font-medium">Net: ৳{{ formatMoney(writeProgress.currentRecord.salary_components?.net_payable) }}</span>
                        </div>
                    </div>
                </div>

                <div class="shrink-0 flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-300 font-medium">
                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>Saving...</span>
                </div>
            </div>

            <!-- Terminal Audit Log Stream -->
            <div class="mt-4">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <div class="flex items-center gap-1.5">
                        <span class="text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            Live Execution Log
                        </span>
                        <span class="text-[11px] font-mono px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                            {{ writeProgress.logs.length }} events
                        </span>
                    </div>

                    <!-- Filter pills & Auto-scroll toggle -->
                    <div class="flex items-center gap-2">
                        <div class="inline-flex rounded-lg p-0.5 bg-gray-100 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-[11px]">
                            <button
                                @click="logFilter = 'all'"
                                class="px-2 py-0.5 rounded-md transition-colors"
                                :class="logFilter === 'all' ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-semibold shadow-xs' : 'text-gray-500 hover:text-gray-800 dark:hover:text-gray-200'"
                            >
                                All
                            </button>
                            <button
                                @click="logFilter = 'created'"
                                class="px-2 py-0.5 rounded-md transition-colors"
                                :class="logFilter === 'created' ? 'bg-white dark:bg-gray-800 text-emerald-600 dark:text-emerald-400 font-semibold shadow-xs' : 'text-gray-500 hover:text-gray-800 dark:hover:text-gray-200'"
                            >
                                Created ({{ writeProgress.createdCount }})
                            </button>
                            <button
                                @click="logFilter = 'updated'"
                                class="px-2 py-0.5 rounded-md transition-colors"
                                :class="logFilter === 'updated' ? 'bg-white dark:bg-gray-800 text-amber-600 dark:text-amber-400 font-semibold shadow-xs' : 'text-gray-500 hover:text-gray-800 dark:hover:text-gray-200'"
                            >
                                Softly Updated ({{ writeProgress.updatedCount }})
                            </button>
                            <button
                                @click="logFilter = 'error'"
                                class="px-2 py-0.5 rounded-md transition-colors"
                                :class="logFilter === 'error' ? 'bg-white dark:bg-gray-800 text-rose-600 dark:text-rose-400 font-semibold shadow-xs' : 'text-gray-500 hover:text-gray-800 dark:hover:text-gray-200'"
                            >
                                Errors ({{ writeProgress.errorCount }})
                            </button>
                        </div>

                        <!-- Auto-scroll checkbox -->
                        <label class="flex items-center gap-1 text-[11px] text-gray-500 dark:text-gray-400 cursor-pointer select-none">
                            <input
                                type="checkbox"
                                v-model="autoScroll"
                                class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500 text-xs w-3.5 h-3.5"
                            />
                            <span>Auto-scroll</span>
                        </label>
                    </div>
                </div>

                <!-- Styled Dark Terminal Log Box -->
                <div
                    ref="terminalRef"
                    class="h-56 sm:h-64 overflow-y-auto rounded-xl bg-slate-950 text-slate-200 dark:bg-black/90 p-3 font-mono text-xs border border-slate-800 dark:border-gray-800 shadow-inner space-y-1.5 scroll-smooth"
                >
                    <div v-if="filteredLogs.length === 0" class="text-slate-500 text-center py-10">
                        Waiting for write events to log...
                    </div>

                    <div
                        v-for="item in filteredLogs"
                        :key="item.id"
                        class="flex items-start gap-2 leading-relaxed hover:bg-slate-900/60 p-1 rounded transition-colors"
                    >
                        <!-- Timestamp -->
                        <span class="text-slate-500 shrink-0 select-none">{{ item.time }}</span>

                        <!-- Step badge -->
                        <span v-if="item.step" class="text-slate-400 shrink-0 font-semibold select-none">
                            [#{{ item.step }}]
                        </span>

                        <!-- Action Tag -->
                        <span
                            v-if="item.type === 'created'"
                            class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-emerald-950 text-emerald-400 border border-emerald-800 shrink-0"
                        >
                            CREATED
                        </span>
                        <span
                            v-else-if="item.type === 'updated'"
                            class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-amber-950 text-amber-300 border border-amber-800 shrink-0"
                        >
                            SOFTLY UPDATED
                        </span>
                        <span
                            v-else-if="item.type === 'error'"
                            class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-rose-950 text-rose-400 border border-rose-800 shrink-0"
                        >
                            ERROR
                        </span>
                        <span
                            v-else-if="item.type === 'done'"
                            class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-teal-950 text-teal-300 border border-teal-800 shrink-0"
                        >
                            DONE
                        </span>
                        <span
                            v-else
                            class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-slate-800 text-slate-300 border border-slate-700 shrink-0"
                        >
                            INFO
                        </span>

                        <!-- Message Content -->
                        <div class="flex-1 break-words">
                            <span v-if="item.employeeName" class="font-bold text-slate-100">
                                {{ item.employeeName }} <span v-if="item.employeeCode" class="text-slate-400 font-normal">({{ item.employeeCode }})</span>:
                            </span>
                            <span :class="item.type === 'error' ? 'text-rose-300 font-semibold' : (item.type === 'updated' ? 'text-amber-200' : 'text-slate-300')">
                                {{ item.message }}
                            </span>
                            <span v-if="item.netPayable !== undefined" class="text-emerald-400 font-semibold ml-1.5">
                                [Net: ৳{{ formatMoney(item.netPayable) }}]
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer Controls -->
            <div class="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row items-center justify-between gap-3">
                <!-- Navigation to Reports if done -->
                <div class="flex items-center gap-2">
                    <Link
                        v-if="!writeProgress.isProcessing && writeProgress.current > 0"
                        :href="route('reports.salary-reports.index', { monthId: selectedMonth, salaryGenerationYear: selectedYear, reportType: 'Salary Bill' })"
                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800 dark:hover:bg-emerald-900/60 transition-colors"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        View Salary Bill
                    </Link>

                    <Link
                        v-if="!writeProgress.isProcessing && writeProgress.current > 0"
                        :href="route('reports.salary-reports.index', { monthId: selectedMonth, salaryGenerationYear: selectedYear, reportType: 'Bank Advice' })"
                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800 dark:hover:bg-emerald-900/60 transition-colors"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        View Bank Advice
                    </Link>
                </div>

                <!-- Execution Action Buttons -->
                <div class="flex items-center gap-2.5 w-full sm:w-auto justify-end">
                    <!-- Pause / Resume Button -->
                    <button
                        v-if="writeProgress.isProcessing && !writeProgress.isPaused"
                        @click="archiveStore.pauseWriteQueue"
                        class="px-3.5 py-2 rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/40 dark:hover:bg-amber-900/60 text-amber-800 dark:text-amber-200 text-xs font-semibold uppercase tracking-wider transition-colors flex items-center gap-1.5"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Pause
                    </button>

                    <button
                        v-else-if="writeProgress.isProcessing && writeProgress.isPaused"
                        @click="archiveStore.resumeWriteQueue"
                        class="px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold uppercase tracking-wider transition-colors shadow-sm flex items-center gap-1.5"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Resume
                    </button>

                    <!-- Stop Button -->
                    <button
                        v-if="writeProgress.isProcessing"
                        @click="archiveStore.cancelWriteQueue"
                        class="px-3.5 py-2 rounded-lg border border-rose-300 dark:border-rose-700 bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/40 dark:hover:bg-rose-900/60 text-rose-800 dark:text-rose-200 text-xs font-semibold uppercase tracking-wider transition-colors flex items-center gap-1.5"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
                        </svg>
                        Stop
                    </button>

                    <!-- Close / Done Button -->
                    <button
                        @click="handleClose"
                        :disabled="writeProgress.isProcessing"
                        class="px-4 py-2 rounded-lg text-xs font-semibold uppercase tracking-wider transition-all shadow-sm"
                        :class="writeProgress.isProcessing
                            ? 'bg-gray-200 dark:bg-gray-700 text-gray-400 dark:text-gray-500 cursor-not-allowed'
                            : 'bg-emerald-600 hover:bg-emerald-700 text-white'"
                    >
                        {{ writeProgress.current >= writeProgress.total && writeProgress.total > 0 ? 'Done & Close' : 'Close' }}
                    </button>
                </div>
            </div>
        </div>
    </Modal>
</template>

<style scoped>
@keyframes move-bg {
    0% { background-position: 0 0; }
    100% { background-position: 1rem 0; }
}
</style>
