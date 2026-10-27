<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { useArchiveSalaryStore } from '@/stores/archiveSalary';
import { useAdminStore } from '@/stores/admin';
import { storeToRefs } from 'pinia';
import Swal from 'sweetalert2';
import WriteProgressModal from './Partials/WriteProgressModal.vue';

const props = defineProps({
    archiveFiles: {
        type: Array,
        default: () => []
    },
    payrollComponentTypes: {
        type: Array,
        default: () => []
    },
    departments: {
        type: Array,
        default: () => []
    },
    payrollGroupTypes: {
        type: Array,
        default: () => []
    },
    grades: {
        type: Array,
        default: () => []
    },
    payScales: {
        type: Array,
        default: () => []
    }
});

const adminStore = useAdminStore();
const archiveStore = useArchiveSalaryStore();

const {
    archiveFiles: storeArchiveFiles,
    selectedYear,
    selectedMonth,
    currentFilename,
    records,
    summary,
    selectedRecordIds,
    loading,
    writing,
    uploading,
    filters,
    filteredRecords,
    isAllSelected,
    selectedRecords,
    availableDesignations,
    availableDepartments
} = storeToRefs(archiveStore);

// Initialize store with passed props
onMounted(() => {
    archiveStore.setArchiveFiles(props.archiveFiles);

    // Auto-select first available file if any
    if (props.archiveFiles && props.archiveFiles.length > 0) {
        const first = props.archiveFiles[0];
        selectedYear.value = first.year;
        selectedMonth.value = first.month;
        archiveStore.loadFile(first.year, first.month, first.filename);
    }
});

// Available years from archiveFiles
const availableYears = computed(() => {
    const years = new Set(props.archiveFiles.map(f => f.year));
    return Array.from(years).sort((a, b) => a - b);
});

// Available months for currently selected year
const availableMonthsForYear = computed(() => {
    return props.archiveFiles
        .filter(f => f.year === selectedYear.value)
        .sort((a, b) => a.month - b.month);
});

// Watch year change to set valid month
watch(selectedYear, (newYear) => {
    const months = props.archiveFiles.filter(f => f.year === newYear);
    if (months.length > 0 && !months.some(m => m.month === selectedMonth.value)) {
        selectedMonth.value = months[0].month;
    }
});

// Handle loading selected archive file
const handleLoadArchive = () => {
    const target = props.archiveFiles.find(
        f => f.year === selectedYear.value && f.month === selectedMonth.value
    );
    archiveStore.loadFile(selectedYear.value, selectedMonth.value, target?.filename);
};

// Hidden file input for custom JSON upload
const fileInputRef = ref(null);
const triggerFileUpload = () => {
    fileInputRef.value?.click();
};

const handleFileUpload = (e) => {
    const file = e.target.files?.[0];
    if (file) {
        archiveStore.uploadFile(file);
        // Reset input value so same file can be re-uploaded if needed
        e.target.value = '';
    }
};

// Pagination state
const currentPage = ref(1);
const perPage = ref(15);

// Reset page on filter changes
watch([() => filters.value.search, () => filters.value.designation, () => filters.value.department, () => filters.value.status], () => {
    currentPage.value = 1;
});

const paginatedRecords = computed(() => {
    const start = (currentPage.value - 1) * perPage.value;
    return filteredRecords.value.slice(start, start + perPage.value);
});

const totalPages = computed(() => {
    return Math.ceil(filteredRecords.value.length / perPage.value) || 1;
});

const changePage = (p) => {
    if (p >= 1 && p <= totalPages.value) {
        currentPage.value = p;
    }
};

// Reset filters
const resetFilters = () => {
    filters.value.search = '';
    filters.value.designation = '';
    filters.value.department = '';
    filters.value.status = 'all';
};

// Edit Modal State
const isEditModalOpen = ref(false);
const editingRecord = ref(null);
const newAllowanceName = ref('');
const newAllowanceAmount = ref(0);
const newDeductionName = ref('');
const newDeductionAmount = ref(0);

// Open Edit Modal for a record
const openEditModal = (rec) => {
    // Deep clone record to allow cancelation
    editingRecord.value = JSON.parse(JSON.stringify(rec));
    newAllowanceName.value = '';
    newAllowanceAmount.value = 0;
    newDeductionName.value = '';
    newDeductionAmount.value = 0;
    isEditModalOpen.value = true;
};

const closeEditModal = () => {
    isEditModalOpen.value = false;
    editingRecord.value = null;
};

// Available component types categorized
const allowanceTypes = computed(() => {
    return props.payrollComponentTypes.filter(t => t.type !== 'deduction');
});

const deductionTypes = computed(() => {
    return props.payrollComponentTypes.filter(t => t.type === 'deduction');
});

// Add new allowance to editing record
const handleAddAllowance = () => {
    if (!newAllowanceName.value) return;
    const allowances = editingRecord.value.salary_components.formatted_allowances || [];
    const amount = parseFloat(newAllowanceAmount.value) || 0;

    // Check if already exists
    const existing = allowances.find(a => a.name.toLowerCase() === newAllowanceName.value.toLowerCase());
    if (existing) {
        existing.amount = amount;
    } else {
        allowances.push({
            name: newAllowanceName.value,
            amount: amount,
            key: archiveStore.nameToKey(newAllowanceName.value)
        });
    }

    newAllowanceName.value = '';
    newAllowanceAmount.value = 0;
};

// Remove allowance from editing record
const handleRemoveAllowance = (index) => {
    editingRecord.value.salary_components.formatted_allowances.splice(index, 1);
};

// Add new deduction to editing record
const handleAddDeduction = () => {
    if (!newDeductionName.value) return;
    const deductions = editingRecord.value.salary_components.formatted_deductions || [];
    const amount = parseFloat(newDeductionAmount.value) || 0;

    const existing = deductions.find(d => d.name.toLowerCase() === newDeductionName.value.toLowerCase());
    if (existing) {
        existing.amount = amount;
    } else {
        deductions.push({
            name: newDeductionName.value,
            amount: amount,
            key: archiveStore.nameToKey(newDeductionName.value)
        });
    }

    newDeductionName.value = '';
    newDeductionAmount.value = 0;
};

// Remove deduction from editing record
const handleRemoveDeduction = (index) => {
    editingRecord.value.salary_components.formatted_deductions.splice(index, 1);
};

// Computed live totals for the editing record
const modalGross = computed(() => {
    if (!editingRecord.value) return 0;
    const allowances = editingRecord.value.salary_components.formatted_allowances || [];
    return allowances.reduce((sum, a) => sum + (parseFloat(a.amount) || 0), 0);
});

const modalDeductions = computed(() => {
    if (!editingRecord.value) return 0;
    const deductions = editingRecord.value.salary_components.formatted_deductions || [];
    return deductions.reduce((sum, d) => sum + (parseFloat(d.amount) || 0), 0);
});

const modalNet = computed(() => {
    return modalGross.value - modalDeductions.value;
});

// Save edits back to store
const saveStagedEdits = () => {
    if (modalGross.value < modalDeductions.value) {
        adminStore.showError(
            'Invalid Calculation',
            `Total allowances (৳${formatMoney(modalGross.value)}) cannot be less than total deductions (৳${formatMoney(modalDeductions.value)}).`
        );
        return;
    }

    archiveStore.updateStagedRecord(editingRecord.value);
    adminStore.showSuccess(
        'Changes Staged',
        `Updated salary calculation for ${editingRecord.value.employee_info.name}. Click "Write" to persist to database.`
    );
    closeEditModal();
};

// Single write record action - launches interactive write queue modal
const handleWriteSingle = (rec) => {
    archiveStore.startWriteQueue([rec]);
};

// Bulk write selected records action - launches interactive write queue modal
const handleWriteSelected = () => {
    const count = selectedRecords.value.length;
    if (count === 0) return;
    archiveStore.startWriteQueue();
};

// Bulk write all filtered pending records - launches interactive write queue modal
const handleWriteAllFiltered = () => {
    const pending = filteredRecords.value.filter(r => !r.is_written);
    const count = pending.length;
    if (count === 0) {
        adminStore.showError('No Pending Records', 'All records matching the current filter are already written.');
        return;
    }
    archiveStore.startWriteQueue(pending);
};

// Helper for month label display
const selectedMonthLabel = computed(() => {
    const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    return `${months[(selectedMonth.value || 1) - 1]} ${selectedYear.value}`;
});

// Format money
const formatMoney = (val) => {
    const num = parseFloat(val);
    if (isNaN(num)) return '0.00';
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};
</script>

<template>
    <Head title="Archive Salary Import & Preview" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white flex items-center gap-2">
                        <span class="p-2 bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300 rounded-lg">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                            </svg>
                        </span>
                        Archive Salary: Import & Preview
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Inspect, edit, and bulk write historical salary bills from archive JSON into official salary records.
                    </p>
                </div>

                <!-- Direct Links to Salary Reports -->
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        :href="route('reports.salary-reports.index', { monthId: selectedMonth, salaryGenerationYear: selectedYear, reportType: 'Bank Advice' })"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800 dark:hover:bg-emerald-900/60 transition-colors shadow-sm"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Bank Advice Report
                    </Link>
                    <Link
                        :href="route('reports.salary-reports.index', { monthId: selectedMonth, salaryGenerationYear: selectedYear, reportType: 'Salary Bill' })"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800 dark:hover:bg-emerald-900/60 transition-colors shadow-sm"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        Salary Bill Report
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-6 space-y-6">
            <!-- Top Controls: Archive Selector & File Uploader -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 md:p-6 transition-all">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <!-- Year and Month Dropdowns -->
                    <div class="flex flex-wrap items-center gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Archive Year</label>
                            <select
                                v-model="selectedYear"
                                class="rounded-lg min-w-20 border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500 font-medium py-2 px-3 shadow-sm"
                            >
                                <option v-for="yr in availableYears" :key="yr" :value="yr">
                                    Year {{ yr }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Archive Month</label>
                            <select
                                v-model="selectedMonth"
                                class="rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500 font-medium py-2 px-3 shadow-sm min-w-[170px]"
                            >
                                <option v-for="m in availableMonthsForYear" :key="m.month" :value="m.month">
                                    {{ m.label }} ({{ m.records_count }} recs)
                                </option>
                            </select>
                        </div>

                        <div class="self-end">
                            <button
                                @click="handleLoadArchive"
                                :disabled="loading"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-medium text-sm transition-colors shadow-sm disabled:opacity-50"
                            >
                                <svg v-if="loading" class="animate-spin -ml-1 mr-1 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                Load Archive
                            </button>
                        </div>
                    </div>

                    <!-- Custom Upload and Status indicator -->
                    <div class="flex flex-wrap items-center gap-3">
                        <!-- Hidden input for file upload -->
                        <input
                            ref="fileInputRef"
                            type="file"
                            accept=".json"
                            class="hidden"
                            @change="handleFileUpload"
                        />
                        <button
                            @click="triggerFileUpload"
                            :disabled="uploading"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-200 font-medium text-sm transition-colors shadow-sm"
                        >
                            <svg v-if="uploading" class="animate-spin -ml-1 mr-1 h-4 w-4 text-gray-700 dark:text-gray-200" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <svg v-else class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                            </svg>
                            Upload Custom JSON
                        </button>

                        <div class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/60 px-3 py-2 rounded-lg border border-gray-100 dark:border-gray-800">
                            Active File: <span class="font-semibold text-gray-900 dark:text-white">{{ currentFilename || 'None loaded' }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KPI Metric Cards -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 md:gap-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Employees</span>
                    <p class="text-xl md:text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ summary.total_employees }}</p>
                    <span class="text-xs text-emerald-600 dark:text-emerald-400 mt-1 inline-block font-medium">In archive bill</span>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Basic Pay</span>
                    <p class="text-xl md:text-2xl font-bold text-gray-900 dark:text-white mt-1">৳{{ formatMoney(summary.total_basic_pay) }}</p>
                    <span class="text-xs text-gray-500 dark:text-gray-400 mt-1 inline-block">Sum of basic</span>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Gross Pay</span>
                    <p class="text-xl md:text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">৳{{ formatMoney(summary.total_gross) }}</p>
                    <span class="text-xs text-emerald-500 dark:text-emerald-400 mt-1 inline-block">All allowances</span>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Deductions</span>
                    <p class="text-xl md:text-2xl font-bold text-rose-600 dark:text-rose-400 mt-1">৳{{ formatMoney(summary.total_deductions) }}</p>
                    <span class="text-xs text-rose-500 dark:text-rose-400 mt-1 inline-block">GPF, Tax, Others</span>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Net Payable</span>
                    <p class="text-xl md:text-2xl font-bold text-emerald-700 dark:text-emerald-300 mt-1">৳{{ formatMoney(summary.total_net_payable) }}</p>
                    <span class="text-xs text-emerald-600 dark:text-emerald-400 mt-1 inline-block">Total to disburse</span>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Sync Status</span>
                    <p class="text-xl md:text-2xl font-bold text-gray-900 dark:text-white mt-1">
                        <span class="text-emerald-600">{{ summary.written_count }}</span> / {{ summary.total_employees }}
                    </p>
                    <span class="text-xs font-medium" :class="summary.pending_count > 0 ? 'text-amber-500 dark:text-amber-400' : 'text-emerald-500'">
                        {{ summary.pending_count > 0 ? `${summary.pending_count} Pending write` : 'All Synced' }}
                    </span>
                </div>
            </div>

            <!-- Filters and Search Bar -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 transition-all">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <!-- Search -->
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Search Employee</label>
                        <div class="relative">
                            <input
                                v-model="filters.search"
                                type="text"
                                placeholder="Search by name, service ID, designation..."
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500 pl-9 pr-3 py-2 shadow-sm"
                            />
                            <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>

                    <!-- Designation Filter -->
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Designation</label>
                        <select
                            v-model="filters.designation"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500 py-2 px-3 shadow-sm"
                        >
                            <option value="">All Designations</option>
                            <option v-for="d in availableDesignations" :key="d" :value="d">{{ d }}</option>
                        </select>
                    </div>

                    <!-- Department Filter -->
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Department</label>
                        <select
                            v-model="filters.department"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500 py-2 px-3 shadow-sm"
                        >
                            <option value="">All Departments</option>
                            <option v-for="dept in availableDepartments" :key="dept" :value="dept">{{ dept }}</option>
                        </select>
                    </div>

                    <!-- Status Filter & Reset -->
                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Sync Status</label>
                            <select
                                v-model="filters.status"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500 py-2 px-3 shadow-sm"
                            >
                                <option value="all">All Status</option>
                                <option value="written">Written to Records</option>
                                <option value="pending">Pending Write</option>
                            </select>
                        </div>
                        <button
                            @click="resetFilters"
                            title="Reset filters"
                            class="p-2 rounded-lg border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300 transition-colors"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Bulk Actions Floating Bar (When items are selected) -->
            <transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="transform -translate-y-2 opacity-0"
                enter-to-class="transform translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="transform translate-y-0 opacity-100"
                leave-to-class="transform -translate-y-2 opacity-0"
            >
                <div
                    v-if="selectedRecordIds.length > 0"
                    class="bg-emerald-700 dark:bg-emerald-800 text-white px-4 py-3 rounded-xl shadow-lg flex flex-wrap items-center justify-between gap-4"
                >
                    <div class="flex items-center gap-2">
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-white text-emerald-800">
                            {{ selectedRecordIds.length }} Selected
                        </span>
                        <span class="text-sm font-medium">Ready for writing to official salary table for {{ selectedMonthLabel }}</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            @click="handleWriteSelected"
                            :disabled="writing"
                            class="px-4 py-1.5 rounded-lg bg-white text-emerald-800 hover:bg-emerald-50 text-xs font-semibold uppercase tracking-wider transition-colors shadow-sm disabled:opacity-50 flex items-center gap-1.5"
                        >
                            <svg v-if="writing" class="animate-spin h-3.5 w-3.5" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            Write Selected ({{ selectedRecordIds.length }})
                        </button>

                        <button
                            @click="selectedRecordIds = []"
                            class="px-3 py-1.5 rounded-lg bg-emerald-800/60 hover:bg-emerald-900/60 text-emerald-100 text-xs font-medium transition-colors"
                        >
                            Clear
                        </button>
                    </div>
                </div>
            </transition>

            <!-- Table Header Actions & Count -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-1">
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    Showing <span class="font-semibold text-gray-900 dark:text-white">{{ filteredRecords.length }}</span> records
                    <span v-if="summary.pending_count > 0" class="ml-2 text-amber-600 dark:text-amber-400 font-medium">
                        ({{ summary.pending_count }} unwritten)
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        v-if="summary.pending_count > 0"
                        @click="handleWriteAllFiltered"
                        :disabled="writing"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold uppercase tracking-wider transition-colors shadow-sm disabled:opacity-50"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                        </svg>
                        Bulk Write All Pending ({{ summary.pending_count }})
                    </button>
                </div>
            </div>

            <!-- Preview Data Table -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden transition-all">
                <div class="overflow-x-auto min-h-[350px]">
                    <table class="w-full text-left text-sm text-gray-600 dark:text-gray-300">
                        <thead class="bg-gray-50 dark:bg-gray-900/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <tr>
                                <th scope="col" class="p-4 w-4">
                                    <input
                                        type="checkbox"
                                        :checked="isAllSelected"
                                        @change="archiveStore.toggleSelectAll"
                                        class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500 bg-white dark:bg-gray-800"
                                    />
                                </th>
                                <th scope="col" class="px-4 py-3">Employee</th>
                                <th scope="col" class="px-4 py-3">Designation & Department</th>
                                <th scope="col" class="px-4 py-3">Pay Scale</th>
                                <th scope="col" class="px-4 py-3 text-right">Basic Pay</th>
                                <th scope="col" class="px-4 py-3 text-right">Gross Allowances</th>
                                <th scope="col" class="px-4 py-3 text-right">Total Deductions</th>
                                <th scope="col" class="px-4 py-3 text-right font-bold text-gray-900 dark:text-white">Net Payable</th>
                                <th scope="col" class="px-4 py-3 text-center">Status</th>
                                <th scope="col" class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-if="loading">
                                <td colspan="10" class="text-center py-12">
                                    <div class="inline-flex items-center gap-2 text-emerald-600 dark:text-emerald-400 font-medium">
                                        <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Loading archive salary data...
                                    </div>
                                </td>
                            </tr>

                            <tr v-else-if="paginatedRecords.length === 0">
                                <td colspan="10" class="text-center py-12 text-gray-500 dark:text-gray-400">
                                    No archive salary records match the selected filters.
                                </td>
                            </tr>

                            <tr
                                v-else
                                v-for="rec in paginatedRecords"
                                :key="rec.id"
                                class="hover:bg-emerald-50/40 dark:hover:bg-emerald-950/20 transition-colors"
                                :class="{ 'bg-emerald-50/20 dark:bg-emerald-950/10': selectedRecordIds.includes(rec.id) }"
                            >
                                <td class="p-4 w-4">
                                    <input
                                        type="checkbox"
                                        :checked="selectedRecordIds.includes(rec.id)"
                                        @change="archiveStore.toggleSelectRecord(rec.id)"
                                        class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500 bg-white dark:bg-gray-800"
                                    />
                                </td>

                                <!-- Employee Info -->
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full bg-emerald-100 dark:bg-emerald-900/60 text-emerald-800 dark:text-emerald-200 font-bold flex items-center justify-center text-xs shrink-0">
                                            {{ rec.employee_info.name.split(' ').map(n => n[0]).slice(0, 2).join('') }}
                                        </div>
                                        <div>
                                            <div class="font-semibold text-gray-900 dark:text-white leading-tight">
                                                {{ rec.employee_info.name }}
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 flex items-center gap-2">
                                                <span>ID: <span class="font-medium text-gray-700 dark:text-gray-300">{{ rec.employee_info.service_id || 'N/A' }}</span></span>
                                                <span v-if="rec.employee_info.bank_account" class="text-gray-400">• Acc: {{ rec.employee_info.bank_account }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Designation & Department -->
                                <td class="px-4 py-3">
                                    <div class="text-xs font-semibold text-gray-800 dark:text-gray-200">
                                        {{ rec.employee_info.designation }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        {{ rec.employee_info.department }}
                                    </div>
                                </td>

                                <!-- Pay Scale -->
                                <td class="px-4 py-3">
                                    <span class="inline-block text-xs bg-gray-100 dark:bg-gray-700/60 text-gray-800 dark:text-gray-200 px-2 py-0.5 rounded font-mono">
                                        {{ rec.employee_info.pay_scale }}
                                    </span>
                                </td>

                                <!-- Basic Pay -->
                                <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-white">
                                    ৳{{ formatMoney(rec.salary_components.basic_pay) }}
                                </td>

                                <!-- Gross Allowances -->
                                <td class="px-4 py-3 text-right font-semibold text-emerald-600 dark:text-emerald-400">
                                    ৳{{ formatMoney(rec.salary_components.gross_salary) }}
                                </td>

                                <!-- Deductions -->
                                <td class="px-4 py-3 text-right font-semibold text-rose-600 dark:text-rose-400">
                                    ৳{{ formatMoney(rec.salary_components.total_deductions) }}
                                </td>

                                <!-- Net Payable -->
                                <td class="px-4 py-3 text-right font-bold text-gray-900 dark:text-white text-base">
                                    ৳{{ formatMoney(rec.salary_components.net_payable) }}
                                </td>

                                <!-- Status Badge -->
                                <td class="px-4 py-3 text-center">
                                    <span
                                        v-if="rec.is_written"
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300"
                                    >
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        Written
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-300"
                                    >
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Pending
                                    </span>
                                </td>

                                <!-- Actions -->
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <!-- Edit Modal Button -->
                                        <button
                                            @click="openEditModal(rec)"
                                            title="Edit & preview calculations"
                                            class="p-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:bg-emerald-50 hover:text-emerald-600 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300 transition-colors shadow-xs"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </button>

                                        <!-- Write Single Record -->
                                        <button
                                            @click="handleWriteSingle(rec)"
                                            :disabled="writing"
                                            title="Write this record to salary table"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium transition-colors shadow-xs disabled:opacity-50"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                                            </svg>
                                            <span>{{ rec.is_written ? 'Sync' : 'Write' }}</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Table Pagination -->
                <div class="px-4 py-3 bg-gray-50 dark:bg-gray-900/60 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        Page <span class="font-semibold text-gray-900 dark:text-white">{{ currentPage }}</span> of
                        <span class="font-semibold text-gray-900 dark:text-white">{{ totalPages }}</span> ({{ filteredRecords.length }} total records)
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            @click="changePage(currentPage - 1)"
                            :disabled="currentPage === 1"
                            class="px-3 py-1 text-xs font-medium rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40 transition-colors"
                        >
                            Previous
                        </button>
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">
                            {{ currentPage }} / {{ totalPages }}
                        </span>
                        <button
                            @click="changePage(currentPage + 1)"
                            :disabled="currentPage === totalPages"
                            class="px-3 py-1 text-xs font-medium rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40 transition-colors"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Detail Modal (Matching Salary Estimation Page Style) -->
        <Modal :show="isEditModalOpen" @close="closeEditModal" max-width="4xl">
            <div v-if="editingRecord" class="p-6 bg-white dark:bg-gray-900 text-gray-900 dark:text-white rounded-xl">
                <!-- Modal Header -->
                <div class="flex items-start justify-between pb-4 border-b border-gray-200 dark:border-gray-800">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-xl font-bold tracking-tight">
                                {{ editingRecord.employee_info.name }}
                            </h3>
                            <span class="px-2.5 py-0.5 text-xs font-mono bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded">
                                ID: {{ editingRecord.employee_info.service_id || 'N/A' }}
                            </span>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 flex flex-wrap items-center gap-2">
                            <span>{{ editingRecord.employee_info.designation }}</span>
                            <span>•</span>
                            <span>{{ editingRecord.employee_info.department }}</span>
                            <span>•</span>
                            <span class="text-emerald-600 dark:text-emerald-400 font-medium">{{ editingRecord.employee_info.pay_scale }}</span>
                        </div>
                    </div>

                    <button
                        @click="closeEditModal"
                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Modal Summary Header Cards -->
                <div class="grid grid-cols-3 gap-3 my-4">
                    <div class="p-3 bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-lg">
                        <span class="text-xs text-emerald-700 dark:text-emerald-400 font-medium uppercase">Gross Allowances</span>
                        <p class="text-lg font-bold text-emerald-800 dark:text-emerald-300 mt-0.5">৳{{ formatMoney(modalGross) }}</p>
                    </div>

                    <div class="p-3 bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-800 rounded-lg">
                        <span class="text-xs text-rose-700 dark:text-rose-400 font-medium uppercase">Total Deductions</span>
                        <p class="text-lg font-bold text-rose-800 dark:text-rose-300 mt-0.5">৳{{ formatMoney(modalDeductions) }}</p>
                    </div>

                    <div class="p-3 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg">
                        <span class="text-xs text-gray-600 dark:text-gray-400 font-medium uppercase">Net Payable</span>
                        <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">৳{{ formatMoney(modalNet) }}</p>
                    </div>
                </div>

                <!-- Modal Body: Allowances & Deductions Side-by-Side -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 my-4 max-h-[55vh] overflow-y-auto pr-1">
                    <!-- Allowances Column -->
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400 flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                Allowances
                            </h4>
                            <span class="text-xs font-semibold text-emerald-600">৳{{ formatMoney(modalGross) }}</span>
                        </div>

                        <!-- Add Allowance Row -->
                        <div class="flex gap-2 p-2 rounded-lg bg-gray-50 dark:bg-gray-800/80 border border-gray-200 dark:border-gray-700">
                            <select
                                v-model="newAllowanceName"
                                class="flex-1 text-xs rounded border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white py-1.5 px-2"
                            >
                                <option value="">Select component to add...</option>
                                <option v-for="t in allowanceTypes" :key="t.name" :value="t.name">{{ t.name }}</option>
                            </select>
                            <input
                                v-model.number="newAllowanceAmount"
                                type="number"
                                step="any"
                                placeholder="Amount"
                                class="w-24 text-xs rounded border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white py-1.5 px-2"
                            />
                            <button
                                @click="handleAddAllowance"
                                :disabled="!newAllowanceName"
                                class="px-2.5 py-1 text-xs font-medium rounded bg-emerald-600 hover:bg-emerald-700 text-white disabled:opacity-40"
                            >
                                Add
                            </button>
                        </div>

                        <!-- Allowances List -->
                        <div class="space-y-2">
                            <div
                                v-for="(allowance, idx) in editingRecord.salary_components.formatted_allowances"
                                :key="allowance.name"
                                class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-800/50 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                            >
                                <div class="flex-1 pr-2">
                                    <div class="text-xs font-semibold text-gray-900 dark:text-white">{{ allowance.name }}</div>
                                    <div class="text-[11px] text-gray-400 font-mono">{{ allowance.key }}</div>
                                </div>

                                <div class="flex items-center gap-2">
                                    <div class="relative">
                                        <span class="absolute left-2 top-1.5 text-xs text-gray-400">৳</span>
                                        <input
                                            v-model.number="allowance.amount"
                                            type="number"
                                            step="any"
                                            class="w-28 text-xs text-right font-medium rounded border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white py-1 pl-5 pr-2 focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>
                                    <button
                                        @click="handleRemoveAllowance(idx)"
                                        title="Remove component"
                                        class="p-1 rounded text-gray-400 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Deductions Column -->
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-bold uppercase tracking-wider text-rose-700 dark:text-rose-400 flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                                </svg>
                                Deductions
                            </h4>
                            <span class="text-xs font-semibold text-rose-600">৳{{ formatMoney(modalDeductions) }}</span>
                        </div>

                        <!-- Add Deduction Row -->
                        <div class="flex gap-2 p-2 rounded-lg bg-gray-50 dark:bg-gray-800/80 border border-gray-200 dark:border-gray-700">
                            <select
                                v-model="newDeductionName"
                                class="flex-1 text-xs rounded border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white py-1.5 px-2"
                            >
                                <option value="">Select deduction to add...</option>
                                <option v-for="t in deductionTypes" :key="t.name" :value="t.name">{{ t.name }}</option>
                            </select>
                            <input
                                v-model.number="newDeductionAmount"
                                type="number"
                                step="any"
                                placeholder="Amount"
                                class="w-24 text-xs rounded border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white py-1.5 px-2"
                            />
                            <button
                                @click="handleAddDeduction"
                                :disabled="!newDeductionName"
                                class="px-2.5 py-1 text-xs font-medium rounded bg-rose-600 hover:bg-rose-700 text-white disabled:opacity-40"
                            >
                                Add
                            </button>
                        </div>

                        <!-- Deductions List -->
                        <div class="space-y-2">
                            <div
                                v-for="(deduction, idx) in editingRecord.salary_components.formatted_deductions"
                                :key="deduction.name"
                                class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-800/50 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                            >
                                <div class="flex-1 pr-2">
                                    <div class="text-xs font-semibold text-gray-900 dark:text-white">{{ deduction.name }}</div>
                                    <div class="text-[11px] text-gray-400 font-mono">{{ deduction.key }}</div>
                                </div>

                                <div class="flex items-center gap-2">
                                    <div class="relative">
                                        <span class="absolute left-2 top-1.5 text-xs text-gray-400">৳</span>
                                        <input
                                            v-model.number="deduction.amount"
                                            type="number"
                                            step="any"
                                            class="w-28 text-xs text-right font-medium rounded border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white py-1 pl-5 pr-2 focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>
                                    <button
                                        @click="handleRemoveDeduction(idx)"
                                        title="Remove component"
                                        class="p-1 rounded text-gray-400 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer Actions -->
                <div class="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-800">
                    <button
                        @click="closeEditModal"
                        class="px-4 py-2 text-xs font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg transition-colors"
                    >
                        Cancel
                    </button>

                    <button
                        @click="saveStagedEdits"
                        class="px-5 py-2 text-xs font-semibold uppercase tracking-wider text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors shadow-sm"
                    >
                        Save Staged Edits
                    </button>
                </div>
            </div>
        </Modal>

        <!-- One-by-One Write Progress Modal with Live Log -->
        <WriteProgressModal />
    </AuthenticatedLayout>
</template>
