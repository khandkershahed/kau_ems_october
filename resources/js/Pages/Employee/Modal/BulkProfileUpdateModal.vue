<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { useEmployeeBulkUpdateStore } from '@/stores/employeeBulkUpdate';
import { storeToRefs } from 'pinia';

const bulkStore = useEmployeeBulkUpdateStore();

const {
    isOpen,
    isProcessing,
    isPaused,
    isCancelled,
    isCompleted,
    stats,
    progressPercent,
    currentEmployee,
    currentProcessingIndex,
    logs,
    fields,
    departmentOffices,
    payScales,
    grades,
    payrollGroups,
    userCategories,
    totalTargetsCount,
    isSelectAllFiltered,
    targetEmployees,
} = storeToRefs(bulkStore);

// Active Tab inside modal
const activeTab = ref('employment'); // 'employment' | 'compensation' | 'placement'

// Target preview drawer toggle
const showTargetPreview = ref(false);

// Log filtering and autoscroll
const logFilter = ref('all'); // 'all' | 'success' | 'error'
const autoScroll = ref(true);
const logContainerRef = ref(null);

const filteredLogs = computed(() => {
    if (logFilter.value === 'all') return logs.value;
    if (logFilter.value === 'success') return logs.value.filter(l => l.type === 'success' || l.type === 'done');
    if (logFilter.value === 'error') return logs.value.filter(l => l.type === 'error');
    return logs.value;
});

// Auto-scroll to top of new log or bottom
watch(() => logs.value.length, async () => {
    if (autoScroll.value && logContainerRef.value) {
        await nextTick();
        logContainerRef.value.scrollTop = 0;
    }
});

// Predefined Religions & Options
const religions = ['Islam', 'Christianity', 'Hinduism', 'Buddhism', 'Sikhism', 'Judaism', 'Other'];
const jobStatuses = [
    { label: 'Permanent', value: 'permanent' },
    { label: 'Contractual', value: 'contract' },
    { label: 'Temporary', value: 'temporary' },
    { label: 'Others', value: 'others' },
];

// Cascading Select Handlers
const handlePayScaleChange = () => {
    bulkStore.onPayScaleChange(fields.value.pay_scale_id.value);
};

const handleGradeChange = () => {
    bulkStore.onGradeChange(fields.value.grade_id.value);
};

// Preset quick increment date to 1st July of current year
const setIncrementDateToJulyFirst = () => {
    fields.value.incrementDate.enabled = true;
    const currentYear = new Date().getFullYear();
    fields.value.incrementDate.value = `${currentYear}-07-01`;
};

// Copy logs to clipboard
const copyLogsToClipboard = () => {
    const text = logs.value
        .map(l => `[${l.time}] [${l.type.toUpperCase()}] ${l.employeeCode ? `[#${l.employeeCode} - ${l.employeeName}] ` : ''}${l.message} ${l.changes && l.changes.length ? `(${l.changes.join(', ')})` : ''}`)
        .join('\n');
    navigator.clipboard.writeText(text);
};

// Download logs as txt file
const downloadLogs = () => {
    const text = logs.value
        .map(l => `[${l.time}] [${l.type.toUpperCase()}] ${l.employeeCode ? `[#${l.employeeCode} - ${l.employeeName}] ` : ''}${l.message} ${l.changes && l.changes.length ? `(${l.changes.join(', ')})` : ''}`)
        .join('\n');
    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `bulk_update_logs_${new Date().toISOString().slice(0, 10)}.txt`;
    link.click();
    URL.revokeObjectURL(url);
};

// Start update action
const onStartUpdate = () => {
    bulkStore.executeBulkUpdate();
};
</script>

<template>
    <Modal :show="isOpen" @close="bulkStore.closeModal" max-width="5xl">
        <div class="flex flex-col max-h-[92vh] bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 overflow-hidden rounded-xl shadow-2xl">
            <!-- ========================================================= -->
            <!-- MODAL HEADER                                              -->
            <!-- ========================================================= -->
            <div class="px-6 py-3 border-b border-slate-200 dark:border-slate-700/80 bg-slate-50 dark:bg-slate-800 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3.5">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-500 text-white shadow-md shadow-emerald-500/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2.5">
                            <h2 class="text-lg font-bold text-slate-900 dark:text-white tracking-tight">
                                Bulk Employee Profile Update
                            </h2>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border border-emerald-300/60 dark:border-emerald-700/60">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
                                {{ totalTargetsCount }} {{ totalTargetsCount === 1 ? 'Employee' : 'Employees' }} Selected
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-300 mt-0.5">
                            Select common profile settings to update across all chosen employees. Only enabled settings will be modified.
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="showTargetPreview = !showTargetPreview"
                        class="px-2.5 py-1.5 text-xs font-semibold rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 hover:bg-slate-50 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 transition cursor-pointer flex items-center gap-1.5"
                        title="View list of selected employees"
                    >
                        <svg class="w-3.5 h-3.5 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        <span>{{ showTargetPreview ? 'Hide Target List' : 'View Target List' }}</span>
                    </button>

                    <button
                        type="button"
                        @click="bulkStore.closeModal"
                        :disabled="isProcessing"
                        class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 transition disabled:opacity-40"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Target Employees Preview Expandable Drawer -->
            <div v-if="showTargetPreview" class="px-6 py-3 bg-slate-100/80 dark:bg-slate-800/90 border-b border-slate-200 dark:border-slate-700/70 max-h-36 overflow-y-auto transition-all">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-200">
                        Selected Target Employees ({{ isSelectAllFiltered ? `All ${totalTargetsCount} Matching Records` : targetEmployees.length }}):
                    </span>
                    <span v-if="isSelectAllFiltered" class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium">
                        * All records currently matching active table filters will be fetched and processed in sequence.
                    </span>
                </div>
                <div v-if="!isSelectAllFiltered" class="flex flex-wrap gap-1.5">
                    <span
                        v-for="emp in targetEmployees"
                        :key="emp.id"
                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-mono bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-200 border border-gray-200 dark:border-gray-700 shadow-2xs"
                    >
                        <span class="font-bold text-emerald-600 dark:text-emerald-400">#{{ emp.employeeCode }}</span>
                        <span class="font-sans font-medium">{{ emp.name }}</span>
                    </span>
                </div>
                <div v-else class="text-xs text-gray-500 dark:text-gray-400 py-1">
                    All filtered employees will be queried from the database and updated one by one.
                </div>
            </div>

            <!-- ========================================================= -->
            <!-- MODAL BODY (SCROLLABLE)                                   -->
            <!-- ========================================================= -->
            <div class="flex-1 overflow-y-auto p-6 space-y-6">

                <!-- 1. Real-Time Progress Panel (Visible while processing, completed, or logs exist) -->
                <div
                    v-if="isProcessing || isCompleted || logs.length > 0"
                    class="bg-gradient-to-br from-slate-900 via-gray-900 to-emerald-950/40 rounded-xl p-5 border border-emerald-500/30 text-white shadow-xl space-y-4"
                >
                    <!-- Progress Bar & Status Header -->
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span v-if="isProcessing && !isPaused" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                    Processing: {{ stats.completed }} of {{ stats.total }} ({{ progressPercent }}%)
                                </span>
                                <span v-else-if="isPaused" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                    <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                                    Queue Paused
                                </span>
                                <span v-else-if="isCompleted" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/30 text-emerald-200 border border-emerald-500/50">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                    Update Finished
                                </span>
                                <span v-else-if="isCancelled" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-500/20 text-rose-300 border border-rose-500/30">
                                    Queue Cancelled
                                </span>

                                <span v-if="currentEmployee" class="text-xs text-emerald-300 font-mono">
                                    &bull; Current: [{{ currentEmployee.employeeCode }}] {{ currentEmployee.name }}
                                </span>
                            </div>
                        </div>

                        <!-- Pause / Resume / Cancel Controls -->
                        <div v-if="isProcessing" class="inline-flex items-center gap-2">
                            <button
                                v-if="!isPaused"
                                type="button"
                                @click="bulkStore.pauseUpdate"
                                class="px-3 py-1 text-xs font-semibold rounded-lg bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border border-amber-500/40 transition cursor-pointer flex items-center gap-1"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                Pause
                            </button>
                            <button
                                v-else
                                type="button"
                                @click="bulkStore.resumeUpdate"
                                class="px-3 py-1 text-xs font-semibold rounded-lg bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 border border-emerald-500/40 transition cursor-pointer flex items-center gap-1"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                Resume
                            </button>

                            <button
                                type="button"
                                @click="bulkStore.cancelUpdate"
                                class="px-3 py-1 text-xs font-semibold rounded-lg bg-rose-500/20 hover:bg-rose-500/30 text-rose-300 border border-rose-500/40 transition cursor-pointer flex items-center gap-1"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                Stop
                            </button>
                        </div>
                    </div>

                    <!-- Progress Bar Track -->
                    <div class="w-full bg-gray-800 rounded-full h-3.5 overflow-hidden p-0.5 border border-gray-700/60 shadow-inner">
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-emerald-500 via-teal-400 to-emerald-400 transition-all duration-300 relative overflow-hidden"
                            :style="{ width: `${progressPercent}%` }"
                        >
                            <div class="absolute inset-0 bg-white/20 w-full animate-[shimmer_2s_infinite] bg-gradient-to-r from-transparent via-white/30 to-transparent"></div>
                        </div>
                    </div>

                    <!-- Statistics Cards Grid -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-1">
                        <div class="bg-gray-800/80 rounded-lg p-2.5 border border-gray-700/50 text-center">
                            <span class="text-[11px] font-medium text-gray-400 uppercase tracking-wider block">Target Total</span>
                            <span class="text-lg font-bold text-white">{{ stats.total }}</span>
                        </div>
                        <div class="bg-gray-800/80 rounded-lg p-2.5 border border-gray-700/50 text-center">
                            <span class="text-[11px] font-medium text-gray-400 uppercase tracking-wider block">Processed</span>
                            <span class="text-lg font-bold text-sky-400">{{ stats.completed }}</span>
                        </div>
                        <div class="bg-gray-800/80 rounded-lg p-2.5 border border-gray-700/50 text-center">
                            <span class="text-[11px] font-medium text-emerald-400 uppercase tracking-wider block">Succeeded</span>
                            <span class="text-lg font-bold text-emerald-400">{{ stats.successful }}</span>
                        </div>
                        <div class="bg-gray-800/80 rounded-lg p-2.5 border border-gray-700/50 text-center">
                            <span class="text-[11px] font-medium text-rose-400 uppercase tracking-wider block">Failed</span>
                            <span class="text-lg font-bold text-rose-400">{{ stats.failed }}</span>
                        </div>
                    </div>
                </div>

                <!-- 2. Settings Configuration Area -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700/70 shadow-xs overflow-hidden">
                    <!-- Section Header with Quick Actions -->
                    <div class="p-4 bg-slate-50/80 dark:bg-slate-800/90 border-b border-slate-200 dark:border-slate-700/70 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/50">
                                <strong>{{ bulkStore.enabledFieldsCount }}</strong> setting{{ bulkStore.enabledFieldsCount === 1 ? '' : 's' }} selected to apply
                            </div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 hidden sm:inline">
                                Check the box on the left of each setting to enable it for updating.
                            </span>
                        </div>

                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="bulkStore.enableAllFields"
                                :disabled="isProcessing"
                                class="px-2.5 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 rounded-md transition cursor-pointer disabled:opacity-50"
                            >
                                Enable All
                            </button>
                            <span class="text-slate-300 dark:text-slate-600">|</span>
                            <button
                                type="button"
                                @click="bulkStore.disableAllFields"
                                :disabled="isProcessing"
                                class="px-2.5 py-1 text-xs font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-md transition cursor-pointer disabled:opacity-50"
                            >
                                Deselect All
                            </button>
                            <span class="text-slate-300 dark:text-slate-600">|</span>
                            <button
                                type="button"
                                @click="bulkStore.resetForm"
                                :disabled="isProcessing"
                                class="px-2.5 py-1 text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-md transition cursor-pointer disabled:opacity-50"
                            >
                                Reset Defaults
                            </button>
                        </div>
                    </div>

                    <!-- Category Navigation Tabs -->
                    <div class="flex border-b border-slate-200 dark:border-slate-700/70 bg-slate-50/40 dark:bg-slate-800/50 px-4">
                        <button
                            type="button"
                            @click="activeTab = 'employment'"
                            class="px-4 py-3 text-xs font-bold border-b-2 transition flex items-center gap-2 cursor-pointer"
                            :class="activeTab === 'employment' ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400 bg-white dark:bg-slate-800' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300'"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                            <span>Employment & Status</span>
                            <span v-if="fields.isActive.enabled || fields.jobStatus.enabled || fields.onLeave.enabled || fields.holdIncrement.enabled" class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        </button>

                        <button
                            type="button"
                            @click="activeTab = 'compensation'"
                            class="px-4 py-3 text-xs font-bold border-b-2 transition flex items-center gap-2 cursor-pointer"
                            :class="activeTab === 'compensation' ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400 bg-white dark:bg-slate-800' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300'"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <span>Pay Scale & Compensation</span>
                            <span v-if="fields.pay_scale_id.enabled || fields.payroll_group_id.enabled || fields.incrementDate.enabled" class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        </button>

                        <button
                            type="button"
                            @click="activeTab = 'placement'"
                            class="px-4 py-3 text-xs font-bold border-b-2 transition flex items-center gap-2 cursor-pointer"
                            :class="activeTab === 'placement' ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400 bg-white dark:bg-slate-800' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300'"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                            <span>Department & Demographics</span>
                            <span v-if="fields.department_office_id.enabled || fields.user_category_id.enabled || fields.gender.enabled || fields.religion.enabled || fields.maritial_status.enabled" class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        </button>
                    </div>

                    <!-- Tab 1: Employment & Status Fields -->
                    <div v-show="activeTab === 'employment'" class="p-5 space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Active Status -->
                            <div
                                class="p-3.5 rounded-xl border transition-all"
                                :class="fields.isActive.enabled ? 'border-emerald-500/80 dark:border-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-slate-200 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/50 opacity-75'"
                            >
                                <div class="flex items-center justify-between mb-2.5">
                                    <label class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="fields.isActive.enabled"
                                            :disabled="isProcessing"
                                            class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span>Update Active Status</span>
                                    </label>
                                    <span v-if="fields.isActive.enabled" class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Enabled</span>
                                </div>
                                <div class="flex items-center gap-3 mt-1">
                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="radio"
                                            :value="true"
                                            v-model="fields.isActive.value"
                                            :disabled="!fields.isActive.enabled || isProcessing"
                                            class="text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-400">Active</span>
                                    </label>
                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="radio"
                                            :value="false"
                                            v-model="fields.isActive.value"
                                            :disabled="!fields.isActive.enabled || isProcessing"
                                            class="text-red-600 focus:ring-red-500"
                                        />
                                        <span class="text-xs font-semibold text-red-600 dark:text-red-400">Inactive</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Job Status -->
                            <div
                                class="p-3.5 rounded-xl border transition-all"
                                :class="fields.jobStatus.enabled ? 'border-emerald-500/80 dark:border-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-slate-200 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/50 opacity-75'"
                            >
                                <div class="flex items-center justify-between mb-2">
                                    <label class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="fields.jobStatus.enabled"
                                            :disabled="isProcessing"
                                            class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span>Update Job Status</span>
                                    </label>
                                    <span v-if="fields.jobStatus.enabled" class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Enabled</span>
                                </div>
                                <select
                                    v-model="fields.jobStatus.value"
                                    :disabled="!fields.jobStatus.enabled || isProcessing"
                                    class="w-full rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-900 text-xs py-2 disabled:bg-slate-100 dark:disabled:bg-slate-800"
                                >
                                    <option v-for="s in jobStatuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                                </select>
                            </div>

                            <!-- Hold Increment -->
                            <div
                                class="p-3.5 rounded-xl border transition-all"
                                :class="fields.holdIncrement.enabled ? 'border-emerald-500/80 dark:border-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-slate-200 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/50 opacity-75'"
                            >
                                <div class="flex items-center justify-between mb-2.5">
                                    <label class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="fields.holdIncrement.enabled"
                                            :disabled="isProcessing"
                                            class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span>Update Increment Status (Hold)</span>
                                    </label>
                                    <span v-if="fields.holdIncrement.enabled" class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Enabled</span>
                                </div>
                                <div class="flex items-center gap-4 mt-1">
                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="radio"
                                            :value="false"
                                            v-model="fields.holdIncrement.value"
                                            :disabled="!fields.holdIncrement.enabled || isProcessing"
                                            class="text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-400">Normal / Released</span>
                                    </label>
                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="radio"
                                            :value="true"
                                            v-model="fields.holdIncrement.value"
                                            :disabled="!fields.holdIncrement.enabled || isProcessing"
                                            class="text-amber-600 focus:ring-amber-500"
                                        />
                                        <span class="text-xs font-semibold text-amber-700 dark:text-amber-400">Withhold Increment</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Leave Status (Full Width or Expanded) -->
                            <div
                                class="p-3.5 rounded-xl border transition-all md:col-span-2"
                                :class="fields.onLeave.enabled ? 'border-emerald-500/80 dark:border-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-slate-200 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/50 opacity-75'"
                            >
                                <div class="flex items-center justify-between mb-2">
                                    <label class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="fields.onLeave.enabled"
                                            :disabled="isProcessing"
                                            class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span>Update Leave Status</span>
                                    </label>
                                    <span v-if="fields.onLeave.enabled" class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Enabled</span>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-center mt-2">
                                    <div class="sm:col-span-1">
                                        <select
                                            v-model="fields.onLeave.onLeave"
                                            :disabled="!fields.onLeave.enabled || isProcessing"
                                            class="w-full rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-900 text-xs py-2 disabled:bg-slate-100 dark:disabled:bg-slate-800"
                                        >
                                            <option :value="false">Normal (Not on Leave)</option>
                                            <option :value="true">Place On Leave</option>
                                        </select>
                                    </div>

                                    <template v-if="fields.onLeave.onLeave">
                                        <div class="sm:col-span-2">
                                            <TextInput
                                                type="text"
                                                v-model="fields.onLeave.reason"
                                                placeholder="Leave Reason (e.g. Study Leave, Medical...)"
                                                :disabled="!fields.onLeave.enabled || isProcessing"
                                                class="w-full text-xs"
                                            />
                                        </div>
                                        <div class="sm:col-span-1 flex items-center gap-3">
                                            <label class="flex items-center gap-1.5 text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    v-model="fields.onLeave.isPaid"
                                                    :disabled="!fields.onLeave.enabled || isProcessing"
                                                    class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                                />
                                                <span>Paid Leave</span>
                                            </label>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 2: Compensation & Pay Scale Fields -->
                    <div v-show="activeTab === 'compensation'" class="p-5 space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Pay Scale -->
                            <div
                                class="p-3.5 rounded-xl border transition-all"
                                :class="fields.pay_scale_id.enabled ? 'border-emerald-500/80 dark:border-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-slate-200 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/50 opacity-75'"
                            >
                                <div class="flex items-center justify-between mb-2">
                                    <label class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="fields.pay_scale_id.enabled"
                                            :disabled="isProcessing"
                                            class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span>Update Pay Scale</span>
                                    </label>
                                    <span v-if="fields.pay_scale_id.enabled" class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Enabled</span>
                                </div>
                                <select
                                    v-model="fields.pay_scale_id.value"
                                    @change="handlePayScaleChange"
                                    :disabled="!fields.pay_scale_id.enabled || isProcessing"
                                    class="w-full rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-900 text-xs py-2 disabled:bg-slate-100 dark:disabled:bg-slate-800"
                                >
                                    <option value="">-- Choose Pay Scale --</option>
                                    <option v-for="scale in payScales" :key="scale.id" :value="scale.id">
                                        {{ scale.scale_name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Grade (Helper / Filter for Payroll Group) -->
                            <div
                                class="p-3.5 rounded-xl border transition-all"
                                :class="fields.grade_id.enabled ? 'border-emerald-500/80 dark:border-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-slate-200 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/50 opacity-75'"
                            >
                                <div class="flex items-center justify-between mb-2">
                                    <label class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="fields.grade_id.enabled"
                                            :disabled="isProcessing"
                                            class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span>Grade Selector (Cascading)</span>
                                    </label>
                                    <span v-if="fields.grade_id.enabled" class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Enabled</span>
                                </div>
                                <select
                                    v-model="fields.grade_id.value"
                                    @change="handleGradeChange"
                                    :disabled="!fields.grade_id.enabled || isProcessing"
                                    class="w-full rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-900 text-xs py-2 disabled:bg-slate-100 dark:disabled:bg-slate-800"
                                >
                                    <option value="">-- Filter by Grade --</option>
                                    <option v-for="g in bulkStore.availableGrades" :key="g.id" :value="g.id">
                                        {{ g.grade_name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Payroll Group / Designation -->
                            <div
                                class="p-3.5 rounded-xl border transition-all"
                                :class="fields.payroll_group_id.enabled ? 'border-emerald-500/80 dark:border-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-slate-200 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/50 opacity-75'"
                            >
                                <div class="flex items-center justify-between mb-2">
                                    <label class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="fields.payroll_group_id.enabled"
                                            :disabled="isProcessing"
                                            class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span>Update Designation / Payroll Group</span>
                                    </label>
                                    <span v-if="fields.payroll_group_id.enabled" class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Enabled</span>
                                </div>
                                <select
                                    v-model="fields.payroll_group_id.value"
                                    :disabled="!fields.payroll_group_id.enabled || isProcessing"
                                    class="w-full rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-900 text-xs py-2 disabled:bg-slate-100 dark:disabled:bg-slate-800"
                                >
                                    <option value="">-- Choose Designation / Group --</option>
                                    <option v-for="pg in bulkStore.availablePayrollGroups" :key="pg.id" :value="pg.id">
                                        {{ pg.payroll_group_type?.group_name || ('Group #' + pg.id) }}
                                    </option>
                                </select>
                            </div>

                            <!-- Annual Increment Date -->
                            <div
                                class="p-3.5 rounded-xl border transition-all"
                                :class="fields.incrementDate.enabled ? 'border-emerald-500/80 dark:border-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-slate-200 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/50 opacity-75'"
                            >
                                <div class="flex items-center justify-between mb-2">
                                    <label class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="fields.incrementDate.enabled"
                                            :disabled="isProcessing"
                                            class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span>Update Annual Increment Date</span>
                                    </label>
                                    <button
                                        type="button"
                                        @click="setIncrementDateToJulyFirst"
                                        :disabled="isProcessing"
                                        class="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 hover:underline cursor-pointer"
                                    >
                                        Set 1st July
                                    </button>
                                </div>
                                <TextInput
                                    type="date"
                                    v-model="fields.incrementDate.value"
                                    :disabled="!fields.incrementDate.enabled || isProcessing"
                                    class="w-full text-xs"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Tab 3: Institutional Placement & Demographics -->
                    <div v-show="activeTab === 'placement'" class="p-5 space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Department / Office -->
                            <div
                                class="p-3.5 rounded-xl border transition-all"
                                :class="fields.department_office_id.enabled ? 'border-emerald-500/80 dark:border-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-slate-200 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/50 opacity-75'"
                            >
                                <div class="flex items-center justify-between mb-2">
                                    <label class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="fields.department_office_id.enabled"
                                            :disabled="isProcessing"
                                            class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span>Update Department / Office</span>
                                    </label>
                                    <span v-if="fields.department_office_id.enabled" class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Enabled</span>
                                </div>
                                <select
                                    v-model="fields.department_office_id.value"
                                    :disabled="!fields.department_office_id.enabled || isProcessing"
                                    class="w-full rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-900 text-xs py-2 disabled:bg-slate-100 dark:disabled:bg-slate-800"
                                >
                                    <option value="">-- Choose Department / Office --</option>
                                    <option v-for="d in departmentOffices" :key="d.id" :value="d.id">
                                        {{ d.name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Employee Group / Category -->
                            <div
                                class="p-3.5 rounded-xl border transition-all"
                                :class="fields.user_category_id.enabled ? 'border-emerald-500/80 dark:border-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-slate-200 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/50 opacity-75'"
                            >
                                <div class="flex items-center justify-between mb-2">
                                    <label class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="fields.user_category_id.enabled"
                                            :disabled="isProcessing"
                                            class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span>Update Employee Group / Category</span>
                                    </label>
                                    <span v-if="fields.user_category_id.enabled" class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Enabled</span>
                                </div>
                                <select
                                    v-model="fields.user_category_id.value"
                                    :disabled="!fields.user_category_id.enabled || isProcessing"
                                    class="w-full rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-900 text-xs py-2 disabled:bg-slate-100 dark:disabled:bg-slate-800"
                                >
                                    <option value="">-- Choose Category / Group --</option>
                                    <option v-for="c in userCategories" :key="c.id" :value="c.id">
                                        {{ c.name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Gender -->
                            <div
                                class="p-3.5 rounded-xl border transition-all"
                                :class="fields.gender.enabled ? 'border-emerald-500/80 dark:border-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-slate-200 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/50 opacity-75'"
                            >
                                <div class="flex items-center justify-between mb-2">
                                    <label class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="fields.gender.enabled"
                                            :disabled="isProcessing"
                                            class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span>Update Gender</span>
                                    </label>
                                    <span v-if="fields.gender.enabled" class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Enabled</span>
                                </div>
                                <div class="flex items-center gap-3 mt-1">
                                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                        <input
                                            type="radio"
                                            value="male"
                                            v-model="fields.gender.value"
                                            :disabled="!fields.gender.enabled || isProcessing"
                                            class="text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span class="text-xs">Male</span>
                                    </label>
                                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                        <input
                                            type="radio"
                                            value="female"
                                            v-model="fields.gender.value"
                                            :disabled="!fields.gender.enabled || isProcessing"
                                            class="text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span class="text-xs">Female</span>
                                    </label>
                                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                        <input
                                            type="radio"
                                            value="other"
                                            v-model="fields.gender.value"
                                            :disabled="!fields.gender.enabled || isProcessing"
                                            class="text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span class="text-xs">Other</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Religion -->
                            <div
                                class="p-3.5 rounded-xl border transition-all"
                                :class="fields.religion.enabled ? 'border-emerald-500/80 dark:border-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-slate-200 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/50 opacity-75'"
                            >
                                <div class="flex items-center justify-between mb-2">
                                    <label class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="fields.religion.enabled"
                                            :disabled="isProcessing"
                                            class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span>Update Religion</span>
                                    </label>
                                    <span v-if="fields.religion.enabled" class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Enabled</span>
                                </div>
                                <select
                                    v-model="fields.religion.value"
                                    :disabled="!fields.religion.enabled || isProcessing"
                                    class="w-full rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-900 text-xs py-2 disabled:bg-slate-100 dark:disabled:bg-slate-800"
                                >
                                    <option v-for="r in religions" :key="r" :value="r">{{ r }}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. Real-Time Database Response Log Terminal -->
                <div class="bg-slate-900/95 dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700/80 shadow-md overflow-hidden font-mono">
                    <!-- Terminal Header Bar -->
                    <div class="px-4 py-2.5 bg-slate-100 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700/80 flex flex-wrap items-center justify-between gap-3 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-slate-800 dark:text-slate-200 ml-1.5 tracking-wide flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                Database Response Stream
                            </span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-sans font-medium bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-600">
                                {{ logs.length }} {{ logs.length === 1 ? 'event' : 'events' }}
                            </span>
                        </div>

                        <div class="flex items-center gap-3 text-[11px] font-sans">
                            <!-- Filter logs -->
                            <div class="flex items-center gap-1.5 text-slate-600 dark:text-slate-400">
                                <span>Filter:</span>
                                <select
                                    v-model="logFilter"
                                    class="bg-white dark:bg-slate-700 border-slate-300 dark:border-slate-600 rounded text-xs py-0.5 px-2 text-slate-800 dark:text-slate-200 focus:ring-emerald-500 focus:border-emerald-500"
                                >
                                    <option value="all">All ({{ logs.length }})</option>
                                    <option value="success">Success Only</option>
                                    <option value="error">Errors Only</option>
                                </select>
                            </div>

                            <label class="flex items-center gap-1.5 text-slate-600 dark:text-slate-300 cursor-pointer">
                                <input type="checkbox" v-model="autoScroll" class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500 bg-white dark:bg-slate-700" />
                                <span>Auto-scroll</span>
                            </label>

                            <button
                                v-if="logs.length > 0"
                                type="button"
                                @click="copyLogsToClipboard"
                                class="text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100 font-medium transition cursor-pointer"
                                title="Copy all logs"
                            >
                                Copy
                            </button>
                            <button
                                v-if="logs.length > 0"
                                type="button"
                                @click="downloadLogs"
                                class="text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100 font-medium transition cursor-pointer"
                                title="Download log file"
                            >
                                Download
                            </button>
                            <button
                                v-if="logs.length > 0"
                                type="button"
                                @click="bulkStore.clearLogs"
                                :disabled="isProcessing"
                                class="text-rose-600 hover:text-rose-700 dark:text-rose-400 dark:hover:text-rose-300 font-medium transition cursor-pointer disabled:opacity-40"
                            >
                                Clear
                            </button>
                        </div>
                    </div>

                    <!-- Terminal Log Stream Body -->
                    <div
                        ref="logContainerRef"
                        class="p-4 max-h-56 overflow-y-auto space-y-1.5 text-xs select-text scrollbar-thin scrollbar-thumb-slate-300 dark:scrollbar-thumb-slate-700 bg-slate-950/70 text-slate-200"
                    >
                        <div v-if="filteredLogs.length === 0" class="py-8 flex flex-col items-center justify-center text-center font-sans space-y-2">
                            <div class="w-9 h-9 rounded-lg bg-slate-800/80 border border-slate-700/60 flex items-center justify-center text-slate-400">
                                <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <p class="text-xs font-semibold text-slate-300">
                                No log events recorded yet
                            </p>
                            <p class="text-[11px] text-slate-400 max-w-md leading-relaxed">
                                Enable your desired profile settings above and click <span class="font-semibold text-emerald-400">"Start Bulk Profile Update"</span> to begin streaming real-time database responses.
                            </p>
                        </div>
                        <div
                            v-else
                            v-for="log in filteredLogs"
                            :key="log.id"
                            class="flex items-start gap-2 leading-relaxed font-mono"
                        >
                            <span class="text-slate-500 flex-shrink-0">[{{ log.time }}]</span>

                            <span
                                v-if="log.type === 'success'"
                                class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-emerald-950 text-emerald-300 border border-emerald-800 flex-shrink-0"
                            >
                                SUCCESS
                            </span>
                            <span
                                v-else-if="log.type === 'error'"
                                class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-rose-950 text-rose-300 border border-rose-800 flex-shrink-0"
                            >
                                ERROR
                            </span>
                            <span
                                v-else-if="log.type === 'warning'"
                                class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-amber-950 text-amber-300 border border-amber-800 flex-shrink-0"
                            >
                                NOTICE
                            </span>
                            <span
                                v-else-if="log.type === 'done'"
                                class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-teal-950 text-teal-300 border border-teal-800 flex-shrink-0"
                            >
                                DONE
                            </span>
                            <span
                                v-else
                                class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-slate-800 text-slate-300 border border-slate-700 flex-shrink-0"
                            >
                                INFO
                            </span>

                            <span v-if="log.employeeCode" class="text-emerald-400 font-semibold flex-shrink-0">
                                [#{{ log.employeeCode }} {{ log.employeeName }}]:
                            </span>

                            <span class="text-slate-200">
                                {{ log.message }}
                                <template v-if="log.changes && log.changes.length">
                                    <span class="text-emerald-300/80 block mt-0.5 pl-3 border-l-2 border-emerald-500/40 text-[11px]">
                                        &bull; {{ log.changes.join(' | ') }}
                                    </span>
                                </template>
                            </span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ========================================================= -->
            <!-- MODAL FOOTER                                              -->
            <!-- ========================================================= -->
            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-700/80 bg-slate-50 dark:bg-slate-800 flex flex-wrap items-center justify-between gap-3">
                <div class="text-xs text-slate-500 dark:text-slate-400">
                    <span v-if="!isProcessing && !isCompleted">
                        Ready to apply <strong class="text-slate-900 dark:text-white">{{ bulkStore.enabledFieldsCount }}</strong> setting(s) to <strong class="text-slate-900 dark:text-white">{{ totalTargetsCount }}</strong> employee(s).
                    </span>
                    <span v-else-if="isProcessing" class="flex items-center gap-2 text-emerald-600 dark:text-emerald-400 font-semibold">
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        Executing bulk database updates... Do not close window.
                    </span>
                    <span v-else-if="isCompleted" class="text-emerald-600 dark:text-emerald-400 font-semibold">
                        ✓ All employee profile updates have finished. Directory table has been synchronized.
                    </span>
                </div>

                <div class="flex items-center gap-3">
                    <SecondaryButton
                        type="button"
                        @click="bulkStore.closeModal"
                        :disabled="isProcessing"
                        class="text-xs cursor-pointer"
                    >
                        {{ isCompleted ? 'Close' : 'Cancel' }}
                    </SecondaryButton>

                    <template v-if="!isProcessing && !isCompleted">
                        <PrimaryButton
                            type="button"
                            @click="onStartUpdate"
                            :disabled="bulkStore.enabledFieldsCount === 0 || totalTargetsCount === 0"
                            class="text-xs inline-flex items-center gap-2 shadow-md shadow-emerald-600/20 cursor-pointer disabled:opacity-50"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            <span>Start Bulk Profile Update</span>
                        </PrimaryButton>
                    </template>

                    <template v-else-if="isCompleted">
                        <button
                            type="button"
                            @click="isCompleted = false"
                            class="px-3.5 py-2 rounded-lg text-xs font-semibold border border-slate-300 dark:border-slate-600 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition cursor-pointer"
                        >
                            Run Another Update
                        </button>
                        <PrimaryButton
                            type="button"
                            @click="bulkStore.closeModal"
                            class="text-xs cursor-pointer"
                        >
                            Done & View Directory
                        </PrimaryButton>
                    </template>
                </div>
            </div>
        </div>
    </Modal>
</template>

<style scoped>
@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(200%); }
}
</style>
