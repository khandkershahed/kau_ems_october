<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { useBonusGenerateStore } from '@/stores/bonusGenerate';
import Swal from 'sweetalert2';

const props = defineProps({
    payScales: { type: Array, default: () => [] },
    grades: { type: Array, default: () => [] },
    payrollGroupTypes: { type: Array, default: () => [] },
    payrollGroups: { type: Array, default: () => [] },
    userCategories: { type: Array, default: () => [] },
    departmentOffices: { type: Array, default: () => [] },
    bonusGroups: { type: Array, default: () => [] },
    currentMonth: { type: Number, default: () => (new Date()).getMonth() + 1 },
    currentYear: { type: Number, default: () => (new Date()).getFullYear() },
    religions: { type: Array, default: () => [] },
    genders: { type: Array, default: () => [] },
    jobStatuses: { type: Array, default: () => [] },
});

const store = useBonusGenerateStore();

// UI State
const showFilters = ref(true);
const singleGenerateModalOpen = ref(false);
const bulkGenerateModalOpen = ref(false);
const overrideModalOpen = ref(false);
const bulkOverrideModalOpen = ref(false);

// Computed property to filter bills based on generated status while rendering
const renderedBills = computed(() => {
    const list = store.bills?.data || [];
    if (!store.filters.bonusGeneratedStatus || store.filters.bonusGeneratedStatus === 'all') {
        return list;
    }
    if (store.filters.bonusGeneratedStatus === 'generated') {
        return list.filter(row => !!row.bonus_generate_id);
    }
    if (store.filters.bonusGeneratedStatus === 'not_generated') {
        return list.filter(row => !row.bonus_generate_id);
    }
    return list;
});

// Toggle include pending bills
const toggleIncludePending = () => {
    if (store.filters.bonusGeneratedStatus === 'generated') {
        store.filters.bonusGeneratedStatus = 'all';
    } else {
        store.filters.bonusGeneratedStatus = 'generated';
    }
    applyFilters();
};

const pendingGenerateUser = ref(null);
const pendingOverrideBill = ref(null);
const overrideTargetStatus = ref('approved');
const bulkOverrideTargetStatus = ref('approved');

// Multi-select Checkbox State
const selectedBillIds = ref([]);
const isSelectAllFiltered = ref(false);

// Months list for dropdowns
const months = [
    { id: 1, name: 'January' },
    { id: 2, name: 'February' },
    { id: 3, name: 'March' },
    { id: 4, name: 'April' },
    { id: 5, name: 'May' },
    { id: 6, name: 'June' },
    { id: 7, name: 'July' },
    { id: 8, name: 'August' },
    { id: 9, name: 'September' },
    { id: 10, name: 'October' },
    { id: 11, name: 'November' },
    { id: 12, name: 'December' },
];

// Available years (5 years around current)
const years = computed(() => {
    const curr = props.currentYear || (new Date()).getFullYear();
    return [curr - 2, curr - 1, curr, curr + 1, curr + 2];
});

// Grades filtered by selected Pay Scale
const filteredGrades = computed(() => {
    if (!store.filters.pay_scale_id) return props.grades;
    return props.grades.filter(g => String(g.pay_scale_id) === String(store.filters.pay_scale_id));
});

// Format currency
const formatMoney = (val) => {
    const num = parseFloat(val);
    if (isNaN(num)) return '0.00';
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

// Month name helper
const getMonthName = (mId) => {
    const m = months.find(item => item.id === parseInt(mId));
    return m ? m.name : `Month ${mId}`;
};

onMounted(() => {
    store.fetchBills(1);
});

// Debounce timer for search
let searchDebounceTimer = null;
const handleSearchInput = () => {
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => {
        store.fetchBills(1);
    }, 350);
};

// Apply all filters
const applyFilters = () => {
    clearSelection();
    store.fetchBills(1);
};

// Reset filters
const resetFilters = () => {
    clearSelection();
    store.resetFilters();
};

const sortBy = (field) => {
    store.setSort(field);
};

// Single generate action
const confirmGenerateSingle = (row) => {
    pendingGenerateUser.value = row;
    singleGenerateModalOpen.value = true;
};

const executeGenerateSingle = async () => {
    if (!pendingGenerateUser.value) return;
    const userId = pendingGenerateUser.value.user_id;
    singleGenerateModalOpen.value = false;
    await store.generateSingle(userId);
    pendingGenerateUser.value = null;
};

// Bulk generate action
const confirmBulkGenerate = () => {
    bulkGenerateModalOpen.value = true;
};

const executeBulkGenerate = async () => {
    bulkGenerateModalOpen.value = false;
    await store.generateBulk([], true);
};

// Multi-select Checkbox Helpers
const clearSelection = () => {
    selectedBillIds.value = [];
    isSelectAllFiltered.value = false;
};

const currentPageSelectableBills = computed(() => {
    return (renderedBills.value || []).filter(b => !!b.bonus_generate_id);
});

const isAllPageSelected = computed(() => {
    if (currentPageSelectableBills.value.length === 0) return false;
    return currentPageSelectableBills.value.every(b => selectedBillIds.value.includes(b.bonus_generate_id));
});

const isSomePageSelected = computed(() => {
    if (currentPageSelectableBills.value.length === 0) return false;
    return currentPageSelectableBills.value.some(b => selectedBillIds.value.includes(b.bonus_generate_id)) && !isAllPageSelected.value;
});

const toggleSelectAllCurrentPage = (e) => {
    const checked = e.target.checked;
    const generatedBillsOnPage = currentPageSelectableBills.value;

    if (checked) {
        const idsToAdd = generatedBillsOnPage.map(b => b.bonus_generate_id);
        selectedBillIds.value = Array.from(new Set([...selectedBillIds.value, ...idsToAdd]));
    } else {
        const idsToRemove = generatedBillsOnPage.map(b => b.bonus_generate_id);
        selectedBillIds.value = selectedBillIds.value.filter(id => !idsToRemove.includes(id));
        isSelectAllFiltered.value = false;
    }
};

const toggleSelectBill = (billId) => {
    if (!billId) return;
    const index = selectedBillIds.value.indexOf(billId);
    if (index > -1) {
        selectedBillIds.value.splice(index, 1);
        isSelectAllFiltered.value = false;
    } else {
        selectedBillIds.value.push(billId);
    }
};

const selectAllFilteredRecords = () => {
    isSelectAllFiltered.value = true;
};

// Override review status actions
const openOverrideModal = (row) => {
    pendingOverrideBill.value = row;
    overrideTargetStatus.value = 'approved';
    overrideModalOpen.value = true;
};

const executeOverride = async () => {
    if (!pendingOverrideBill.value || !pendingOverrideBill.value.bonus_generate_id) return;
    const billId = pendingOverrideBill.value.bonus_generate_id;
    overrideModalOpen.value = false;
    await store.overrideReviewStatus(billId, overrideTargetStatus.value);
    pendingOverrideBill.value = null;
};

const openBulkOverrideModal = () => {
    bulkOverrideTargetStatus.value = 'approved';
    bulkOverrideModalOpen.value = true;
};

const executeBulkOverride = async () => {
    bulkOverrideModalOpen.value = false;
    const result = await store.overrideBulkReviewStatus(
        isSelectAllFiltered.value ? [] : selectedBillIds.value,
        isSelectAllFiltered.value,
        bulkOverrideTargetStatus.value
    );
    if (result.success) {
        clearSelection();
    }
};

// Bulk Send Review
const executeBulkSendReview = async () => {
    if (!selectedBillIds.value.length && !isSelectAllFiltered.value) return;
    const count = isSelectAllFiltered.value ? store.summary.generated : selectedBillIds.value.length;

    const res = await Swal.fire({
        title: 'Dispatch Review Emails?',
        text: `Send bonus bill review emails to ${count} employee(s)?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#6b7280',
        confirmButtonText: `Yes, Send to ${count} Employees`,
        cancelButtonText: 'Cancel'
    });

    if (res.isConfirmed) {
        const result = await store.sendBulkReview(
            isSelectAllFiltered.value ? [] : selectedBillIds.value,
            isSelectAllFiltered.value
        );
        if (result.success) {
            clearSelection();
        }
    }
};
</script>

<template>

    <Head title="Review & Generate Bonus Bills" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <!-- Page Title -->
                <div class="flex items-center space-x-3">
                    <div
                        class="p-2.5 bg-emerald-500/10 dark:bg-emerald-500/20 border border-emerald-500/20 rounded-xl text-emerald-600 dark:text-emerald-400">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            Review & Generate Bonus Bills
                            <span
                                class="text-xs px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300 font-medium">
                                {{ getMonthName(store.filters.monthId) }} {{ store.filters.bonusGenerationYear }}
                            </span>
                        </h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            Directory of staff members, bonus configuration & festival bonus bills generation
                        </p>
                    </div>
                </div>

                <!-- Month, Year, Bonus Group Selectors & Generate All Action -->
                <div class="flex flex-wrap items-center gap-2.5">
                    <div class="flex items-center space-x-2">
                        <!-- Bonus Group Filter -->
                        <select v-model="store.filters.bonus_group_id" @change="applyFilters"
                            class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-xs font-semibold py-2 px-3 text-gray-700 dark:text-gray-200 focus:ring-emerald-500 focus:border-emerald-500 shadow-sm">
                            <option value="all">All Bonus Groups</option>
                            <option v-for="bg in bonusGroups" :key="bg.id" :value="bg.id">{{ bg.name }}</option>
                        </select>

                        <!-- Month -->
                        <select v-model="store.filters.monthId" @change="applyFilters"
                            class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-xs font-semibold py-2 px-3 text-gray-700 dark:text-gray-200 focus:ring-emerald-500 focus:border-emerald-500 shadow-sm">
                            <option v-for="m in months" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>

                        <!-- Year -->
                        <select v-model="store.filters.bonusGenerationYear" @change="applyFilters"
                            class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-xs font-semibold py-2 px-3 text-gray-700 dark:text-gray-200 focus:ring-emerald-500 focus:border-emerald-500 shadow-sm">
                            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                        </select>
                    </div>

                    <!-- Generate All Filtered Button (Mirrors Screenshot 2) -->
                    <button @click="confirmBulkGenerate"
                        :disabled="store.bulkGenerating || store.summary.not_generated === 0"
                        class="inline-flex items-center px-4 py-2 text-xs font-bold rounded-lg text-white bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm transition-all">
                        <svg v-if="store.bulkGenerating" class="w-4 h-4 mr-2 animate-spin" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                        <svg v-else class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        GENERATE ALL FILTERED ({{ store.summary.not_generated }})
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Metric KPI Summary Cards (Mirrors Screenshot 2) -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Total Filtered Staff -->
                <div
                    class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total
                            Filtered Staff</p>
                        <p class="text-2xl font-extrabold text-gray-900 dark:text-white mt-1">{{ store.summary.total }}
                        </p>
                        <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">{{
                            getMonthName(store.filters.monthId) }}
                            {{ store.filters.bonusGenerationYear }}</p>
                    </div>
                    <div class="p-3 bg-gray-100 dark:bg-gray-700/50 rounded-xl text-gray-600 dark:text-gray-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                </div>

                <!-- Bills Generated -->
                <div
                    class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm flex items-center justify-between">
                    <div>
                        <p
                            class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">
                            Bills
                            Generated</p>
                        <p class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 mt-1">{{
                            store.summary.generated }}</p>
                        <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">Ready for review & payout</p>
                    </div>
                    <div
                        class="p-3 bg-emerald-50 dark:bg-emerald-900/30 rounded-xl text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/40">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>

                <!-- Pending Generation -->
                <div
                    class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider">
                            Pending
                            Generation</p>
                        <p class="text-2xl font-extrabold text-amber-600 dark:text-amber-400 mt-1">{{
                            store.summary.not_generated }}</p>
                        <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">Awaiting bill calculation</p>
                    </div>
                    <div
                        class="p-3 bg-amber-50 dark:bg-amber-900/30 rounded-xl text-amber-600 dark:text-amber-400 border border-amber-200 dark:border-amber-800/40">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Search & Filter Directory Card (Collapsible, Mirrors Screenshot 2) -->
            <div
                class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
                <div
                    class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between bg-gray-50/50 dark:bg-gray-800/50">
                    <div
                        class="flex items-center space-x-2 text-emerald-600 dark:text-emerald-400 font-semibold text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                        </svg>
                        <span>Search & Filter Directory</span>
                        <span v-if="store.activeFilterCount > 0"
                            class="ml-2 px-2 py-0.5 text-xs bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200 rounded-full">
                            {{ store.activeFilterCount }} active
                        </span>
                    </div>

                    <button @click="showFilters = !showFilters"
                        class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 flex items-center gap-1 font-medium">
                        <span>{{ showFilters ? 'Less Filters' : 'More Filters' }}</span>
                        <svg :class="['w-4 h-4 transform transition-transform', showFilters ? 'rotate-180' : '']"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>
                </div>

                <div v-show="showFilters" class="p-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                        <!-- Universal Search -->
                        <div>
                            <InputLabel value="Universal Search"
                                class="mb-1 text-xs text-gray-600 dark:text-gray-300" />
                            <TextInput v-model="store.filters.search" @input="handleSearchInput"
                                placeholder="Name, email, code, phone, NID..."
                                class="w-full text-xs py-2 dark:bg-gray-900 dark:border-gray-700" />
                        </div>

                        <!-- Department / Office -->
                        <div>
                            <InputLabel value="Department / Office"
                                class="mb-1 text-xs text-gray-600 dark:text-gray-300" />
                            <select v-model="store.filters.department_office_id" @change="applyFilters"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">All Departments & Offices</option>
                                <option v-for="dept in departmentOffices" :key="dept.id" :value="dept.id">{{ dept.name
                                    }}
                                </option>
                            </select>
                        </div>

                        <!-- Employee Group -->
                        <div>
                            <InputLabel value="Employee Group" class="mb-1 text-xs text-gray-600 dark:text-gray-300" />
                            <select v-model="store.filters.user_category_id" @change="applyFilters"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">All Employee Groups</option>
                                <option v-for="cat in userCategories" :key="cat.id" :value="cat.id">{{ cat.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Status -->
                        <div>
                            <InputLabel value="Status" class="mb-1 text-xs text-gray-600 dark:text-gray-300" />
                            <select v-model="store.filters.isActive" @change="applyFilters"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">All Statuses</option>
                                <option :value="true">Active Only</option>
                                <option :value="false">Inactive Only</option>
                            </select>
                        </div>

                        <!-- Exact Employee Code -->
                        <div>
                            <InputLabel value="Exact Employee Code"
                                class="mb-1 text-xs text-gray-600 dark:text-gray-300" />
                            <TextInput v-model="store.filters.employeeCode" @keyup.enter="applyFilters"
                                placeholder="e.g. 1002"
                                class="w-full text-xs py-2 dark:bg-gray-900 dark:border-gray-700" />
                        </div>

                        <!-- Pay Scale -->
                        <div>
                            <InputLabel value="Pay Scale" class="mb-1 text-xs text-gray-600 dark:text-gray-300" />
                            <select v-model="store.filters.pay_scale_id" @change="applyFilters"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">All Pay Scales</option>
                                <option v-for="scale in payScales" :key="scale.id" :value="scale.id">{{ scale.scale_name
                                    }}
                                </option>
                            </select>
                        </div>

                        <!-- Grade -->
                        <div>
                            <InputLabel value="Grade" class="mb-1 text-xs text-gray-600 dark:text-gray-300" />
                            <select v-model="store.filters.grade_id" @change="applyFilters"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">All Grades</option>
                                <option v-for="g in filteredGrades" :key="g.id" :value="g.id">{{ g.grade_name }}
                                </option>
                            </select>
                        </div>

                        <!-- Designation / Group -->
                        <div>
                            <InputLabel value="Designation / Group"
                                class="mb-1 text-xs text-gray-600 dark:text-gray-300" />
                            <select v-model="store.filters.payroll_group_id" @change="applyFilters"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">All Designations</option>
                                <option v-for="pg in payrollGroups" :key="pg.id" :value="pg.id">
                                    {{ pg.payroll_group_type?.group_name }} (Grade {{ pg.grade?.grade_name }})
                                </option>
                            </select>
                        </div>

                        <!-- Job Status -->
                        <div>
                            <InputLabel value="Job Status" class="mb-1 text-xs text-gray-600 dark:text-gray-300" />
                            <select v-model="store.filters.jobStatus" @change="applyFilters"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">All Job Statuses</option>
                                <option v-for="js in jobStatuses" :key="js" :value="js" class="capitalize">{{ js }}
                                </option>
                            </select>
                        </div>

                        <!-- Gender -->
                        <div>
                            <InputLabel value="Gender" class="mb-1 text-xs text-gray-600 dark:text-gray-300" />
                            <select v-model="store.filters.gender" @change="applyFilters"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">All Genders</option>
                                <option v-for="g in genders" :key="g.value" :value="g.value">{{ g.label }}</option>
                            </select>
                        </div>

                        <!-- Religion -->
                        <div>
                            <InputLabel value="Religion" class="mb-1 text-xs text-gray-600 dark:text-gray-300" />
                            <select v-model="store.filters.religion" @change="applyFilters"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">All Religions</option>
                                <option v-for="r in religions" :key="r" :value="r">{{ r }}</option>
                            </select>
                        </div>

                        <!-- Bill Generation Status -->
                        <div>
                            <InputLabel value="Bill Generation Status"
                                class="mb-1 text-xs text-gray-600 dark:text-gray-300" />
                            <select v-model="store.filters.bonusGeneratedStatus" @change="applyFilters"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="all">All (Generated & Pending)</option>
                                <option value="generated">Generated</option>
                                <option value="not_generated">Pending Generation</option>
                            </select>
                        </div>

                        <!-- Review Status -->
                        <div>
                            <InputLabel value="Review Status" class="mb-1 text-xs text-gray-600 dark:text-gray-300" />
                            <select v-model="store.filters.reviewStatus" @change="applyFilters"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="all">All Review Statuses</option>
                                <option value="pending">Pending</option>
                                <option value="review">Under Review</option>
                                <option value="approved">Approved</option>
                            </select>
                        </div>
                    </div>

                    <!-- Filter Bottom Bar (Matches Screenshot 2) -->
                    <div class="mt-4 pt-3 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row items-center justify-between gap-3">
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Found <strong class="text-gray-800 dark:text-gray-200">{{ renderedBills.length }}</strong> matching records
                        </span>

                        <div class="flex items-center space-x-2">
                            <SecondaryButton @click="toggleIncludePending" class="text-xs py-2 px-4" title="Toggle between generated only and all records">
                                {{ store.filters.bonusGeneratedStatus === 'generated' ? 'Include pendings' : 'Only Generated' }}
                            </SecondaryButton>
                            <SecondaryButton @click="resetFilters" class="text-xs py-2 px-4">
                                RESET
                            </SecondaryButton>
                            <PrimaryButton @click="applyFilters"
                                class="text-xs py-2 px-4 bg-emerald-600 hover:bg-emerald-500 focus:ring-emerald-500">
                                APPLY FILTERS
                            </PrimaryButton>
                        </div>
                    </div>

                    <!-- Active Filter Chips (Matches Screenshot 2) -->
                    <div v-if="store.activeFilterCount > 0" class="mt-3.5 pt-3 border-t border-gray-100 dark:border-gray-700/60 flex flex-wrap items-center gap-1.5">
                        <span class="text-xs text-gray-400 dark:text-gray-500 mr-1">Filters:</span>

                        <span v-if="store.filters.search" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                            Search: "{{ store.filters.search }}"
                            <button @click="store.filters.search = ''; applyFilters();" class="hover:text-emerald-900 dark:hover:text-emerald-100 ml-0.5">&times;</button>
                        </span>

                        <span v-if="store.filters.employeeCode" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                            Code: {{ store.filters.employeeCode }}
                            <button @click="store.filters.employeeCode = ''; applyFilters();" class="hover:text-emerald-900 dark:hover:text-emerald-100 ml-0.5">&times;</button>
                        </span>

                        <span v-if="store.filters.bonusGeneratedStatus !== 'generated'" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 capitalize">
                            Bill: {{ store.filters.bonusGeneratedStatus === 'not_generated' ? 'Pending Generation' : 'All Records' }}
                            <button @click="store.filters.bonusGeneratedStatus = 'generated'; applyFilters();" class="hover:text-emerald-900 dark:hover:text-emerald-100 ml-0.5">&times;</button>
                        </span>

                        <span v-if="store.filters.reviewStatus !== 'all'" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 capitalize">
                            Review: {{ store.filters.reviewStatus }}
                            <button @click="store.filters.reviewStatus = 'all'; applyFilters();" class="hover:text-emerald-900 dark:hover:text-emerald-100 ml-0.5">&times;</button>
                        </span>

                        <button
                            @click="resetFilters"
                            class="text-xs text-gray-500 hover:text-emerald-600 dark:hover:text-emerald-400 font-medium ml-1 transition"
                        >
                            Clear all
                        </button>
                    </div>
                </div>
            </div>

            <!-- Multi-Select Bulk Actions Bar (When items selected) -->
            <div v-if="selectedBillIds.length > 0"
                class="bg-emerald-50/90 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800 rounded-xl p-3.5 flex flex-wrap items-center justify-between gap-3 shadow-sm transition-all">
                <div class="flex items-center space-x-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs font-bold text-emerald-900 dark:text-emerald-200">
                        {{ selectedBillIds.length }} bill(s) selected
                    </span>
                    <span v-if="!isSelectAllFiltered && store.summary.generated > selectedBillIds.length"
                        class="text-xs text-gray-500 dark:text-gray-400">
                        • <button @click="selectAllFilteredRecords"
                            class="underline font-semibold text-emerald-700 dark:text-emerald-300 hover:text-emerald-800">
                            Select all {{ store.summary.generated }} filtered generated bills
                        </button>
                    </span>
                    <span v-else-if="isSelectAllFiltered"
                        class="text-xs text-emerald-700 dark:text-emerald-300 font-semibold">
                        (All {{ store.summary.generated }} filtered generated bills selected)
                    </span>
                </div>

                <div class="flex items-center space-x-2">
                    <!-- Bulk Override Status -->
                    <button @click="openBulkOverrideModal" :disabled="store.bulkOverridingStatus"
                        class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white shadow-sm transition-colors">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Override Confirm
                    </button>

                    <!-- Bulk Send Review -->
                    <button @click="executeBulkSendReview" :disabled="store.bulkSendingReview"
                        class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 hover:bg-blue-500 text-white shadow-sm transition-colors">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        Send Review
                    </button>

                    <!-- Clear Selection -->
                    <button @click="clearSelection"
                        class="px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">
                        Deselect
                    </button>
                </div>
            </div>

            <!-- Table of Records (Mirrors Screenshot 2) -->
            <div
                class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead
                            class="bg-gray-50/75 dark:bg-gray-900/60 text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">
                            <tr>
                                <th class="py-3.5 px-3 w-10 text-center">
                                    <input type="checkbox" :checked="isAllPageSelected"
                                        :indeterminate.prop="isSomePageSelected" @change="toggleSelectAllCurrentPage"
                                        class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:bg-gray-900 dark:border-gray-700" />
                                </th>
                                <th class="py-3.5 px-3 cursor-pointer" @click="sortBy('id')">SL</th>
                                <th class="py-3.5 px-3">CODE</th>
                                <th class="py-3.5 px-4 cursor-pointer" @click="sortBy('name')">EMPLOYEE NAME</th>
                                <th class="py-3.5 px-3">DEPARTMENT & CATEGORY</th>
                                <th class="py-3.5 px-3">DESIGNATION & SCALE</th>
                                <th class="py-3.5 px-3">MONTH / YEAR / GROUP</th>
                                <th class="py-3.5 px-3 text-right">ALLOWANCE</th>
                                <th class="py-3.5 px-3 text-right">DEDUCTION</th>
                                <th class="py-3.5 px-3 text-right">NET BONUS</th>
                                <th class="py-3.5 px-3 text-center">BILL STATUS</th>
                                <th class="py-3.5 px-3 text-center">REVIEW</th>
                                <th class="py-3.5 px-4 text-right">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-gray-200 dark:divide-gray-700/60 text-gray-700 dark:text-gray-300">
                            <tr v-if="store.loading" class="text-center">
                                <td colspan="13" class="py-12 text-gray-500 dark:text-gray-400">
                                    <div class="flex items-center justify-center space-x-2">
                                        <svg class="w-5 h-5 animate-spin text-emerald-500" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                        <span>Loading bonus bill records...</span>
                                    </div>
                                </td>
                            </tr>

                            <tr v-else-if="!renderedBills || renderedBills.length === 0">
                                <td colspan="13" class="py-12 text-center text-gray-500 dark:text-gray-400">
                                    No staff records found matching the active filters.
                                </td>
                            </tr>

                            <template v-for="(row, idx) in renderedBills" :key="row.user_id">

                                <tr :class="[
                                    'hover:bg-gray-50/75 dark:hover:bg-gray-700/30 transition-colors',
                                    selectedBillIds.includes(row.bonus_generate_id) ? 'bg-emerald-50/40 dark:bg-emerald-950/20' : ''
                                ]">
                                    <!-- Checkbox Column -->
                                    <td class="py-3.5 px-3 text-center">
                                        <input v-if="row.bonus_generate_id" type="checkbox"
                                            :checked="selectedBillIds.includes(row.bonus_generate_id)"
                                            @change="toggleSelectBill(row.bonus_generate_id)"
                                            class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:bg-gray-900 dark:border-gray-700" />
                                        <span v-else class="text-gray-300 dark:text-gray-600 text-xs">•</span>
                                    </td>

                                    <!-- SL -->
                                    <td class="py-3.5 px-3 font-mono text-gray-400 text-[11px]">
                                        {{ ((store.bills.current_page - 1) * store.bills.per_page) + idx + 1 }}
                                    </td>

                                    <!-- CODE -->
                                    <td class="py-3.5 px-3 font-mono font-bold text-gray-900 dark:text-gray-200">
                                        {{ row.employee_code }}
                                    </td>

                                    <!-- EMPLOYEE NAME -->
                                    <td class="py-3.5 px-4">
                                        <div class="font-semibold text-gray-900 dark:text-white">
                                            {{ row.name }}
                                        </div>
                                        <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                            {{ row.email }}
                                        </div>
                                        <div class="text-[10px] text-emerald-600 dark:text-emerald-400 font-medium">
                                            {{ row.religion }}
                                        </div>
                                    </td>

                                    <!-- DEPARTMENT & CATEGORY -->
                                    <td class="py-3.5 px-3">
                                        <div class="font-medium text-gray-900 dark:text-gray-200 truncate max-w-[150px]"
                                            :title="row.department">
                                            {{ row.department }}
                                        </div>
                                        <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                            {{ row.user_category }}
                                        </div>
                                    </td>

                                    <!-- DESIGNATION & SCALE -->
                                    <td class="py-3.5 px-3">
                                        <div class="font-medium text-gray-900 dark:text-gray-200 truncate max-w-[140px]"
                                            :title="row.designation">
                                            {{ row.designation }}
                                        </div>
                                        <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                            {{ row.pay_scale }} (Grade {{ row.grade }})
                                        </div>
                                    </td>

                                    <!-- MONTH / YEAR / GROUP -->
                                    <td class="py-3.5 px-3 whitespace-nowrap">
                                        <div class="font-medium text-gray-900 dark:text-gray-200">
                                            {{ getMonthName(row.month_id) }} {{ row.year }}
                                        </div>
                                        <span
                                            class="inline-block text-[10px] px-1.5 py-0.2 rounded bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300 font-semibold border border-emerald-200 dark:border-emerald-800">
                                            {{ row.bonus_title || row.bonus_group }}
                                        </span>
                                    </td>

                                    <!-- ALLOWANCE -->
                                    <td
                                        class="py-3.5 px-3 text-right font-mono font-medium text-gray-900 dark:text-gray-200">
                                        ৳{{ formatMoney(row.total_allowance) }}
                                    </td>

                                    <!-- DEDUCTION -->
                                    <td class="py-3.5 px-3 text-right font-mono text-red-500 dark:text-red-400">
                                        ৳{{ formatMoney(row.total_deduction) }}
                                    </td>

                                    <!-- NET BONUS -->
                                    <td
                                        class="py-3.5 px-3 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400">
                                        ৳{{ formatMoney(row.net_bonus) }}
                                    </td>

                                    <!-- BILL STATUS -->
                                    <td class="py-3.5 px-3 text-center">
                                        <span v-if="row.bill_status === 'generated'"
                                            class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1"></span>
                                            Generated
                                        </span>
                                        <span v-else
                                            class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                            Not generated
                                        </span>
                                    </td>

                                    <!-- REVIEW STATUS -->
                                    <td class="py-3.5 px-3 text-center">
                                        <span v-if="row.review_status === 'approved'"
                                            class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">
                                            Approved
                                        </span>
                                        <span v-else-if="row.review_status === 'review'"
                                            class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300">
                                            Review
                                        </span>
                                        <span v-else-if="row.review_status === 'pending'"
                                            class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">
                                            Pending
                                        </span>
                                        <span v-else class="text-gray-400 text-xs">—</span>
                                    </td>

                                    <!-- ACTIONS -->
                                    <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end space-x-1.5">
                                            <!-- View Button -->
                                            <button v-if="row.bonus_generate_id"
                                                @click="store.fetchBillDetails(row.bonus_generate_id)"
                                                :disabled="store.actionLoadingId === row.bonus_generate_id"
                                                class="px-2.5 py-1 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                                View
                                            </button>

                                            <!-- Override Confirm Button -->
                                            <button v-if="row.bonus_generate_id" @click="openOverrideModal(row)"
                                                :disabled="store.actionLoadingId === row.bonus_generate_id"
                                                class="px-2.5 py-1 text-xs font-semibold rounded-md bg-emerald-600 hover:bg-emerald-500 text-white transition-colors">
                                                Override Confirm
                                            </button>

                                            <!-- Generate Single Button -->
                                            <button v-else @click="confirmGenerateSingle(row)"
                                                :disabled="store.generatingId === row.user_id"
                                                class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-md bg-emerald-600 hover:bg-emerald-500 text-white transition-colors">
                                                <svg :class="['w-3.5 h-3.5 mr-1', store.generatingId === row.user_id ? 'animate-spin' : '']"
                                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                                </svg>
                                                Generate
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>

                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div
                    class="px-5 py-3.5 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between bg-gray-50/50 dark:bg-gray-800/50">
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        Showing {{ store.bills.data.length }} of {{ store.bills.total }} staff bills
                    </span>

                    <div class="flex items-center space-x-1.5">
                        <button @click="store.fetchBills(store.bills.current_page - 1)"
                            :disabled="store.bills.current_page <= 1 || store.loading"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 transition-colors">
                            Previous
                        </button>

                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300 px-2">
                            Page {{ store.bills.current_page }} of {{ store.bills.last_page }}
                        </span>

                        <button @click="store.fetchBills(store.bills.current_page + 1)"
                            :disabled="store.bills.current_page >= store.bills.last_page || store.loading"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 transition-colors">
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Single Bill Generation Confirm Modal -->
        <Modal :show="singleGenerateModalOpen" @close="singleGenerateModalOpen = false" maxWidth="md">
            <div class="p-6">
                <h3 class="text-base font-bold text-gray-900 dark:text-white mb-2">
                    Generate Bonus Bill?
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    Calculate and prepare the bonus bill for <strong>{{ pendingGenerateUser?.name }}</strong> for the
                    period of
                    <strong>{{ getMonthName(store.filters.monthId) }} {{ store.filters.bonusGenerationYear }}</strong>?
                </p>
                <div class="flex justify-end space-x-2">
                    <SecondaryButton @click="singleGenerateModalOpen = false">Cancel</SecondaryButton>
                    <PrimaryButton @click="executeGenerateSingle" class="bg-emerald-600 hover:bg-emerald-500">
                        Yes, Generate
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Bulk Bill Generation Confirm Modal -->
        <Modal :show="bulkGenerateModalOpen" @close="bulkGenerateModalOpen = false" maxWidth="md">
            <div class="p-6">
                <h3 class="text-base font-bold text-gray-900 dark:text-white mb-2">
                    Generate All Filtered Bonus Bills?
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    This will calculate and generate festival bonus bills for all <strong>{{ store.summary.not_generated
                        }}
                        unbilled employees</strong> matching the current directory filters for <strong>{{
                            getMonthName(store.filters.monthId) }} {{ store.filters.bonusGenerationYear }}</strong>.
                </p>
                <div class="flex justify-end space-x-2">
                    <SecondaryButton @click="bulkGenerateModalOpen = false">Cancel</SecondaryButton>
                    <PrimaryButton @click="executeBulkGenerate" class="bg-emerald-600 hover:bg-emerald-500">
                        Yes, Generate All ({{ store.summary.not_generated }})
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Single Override Review Modal -->
        <Modal :show="overrideModalOpen" @close="overrideModalOpen = false" maxWidth="sm">
            <div class="p-6">
                <h3 class="text-base font-bold text-gray-900 dark:text-white mb-2">
                    Override Review Status
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    Update review status for <strong>{{ pendingOverrideBill?.name }}</strong>:
                </p>
                <div class="mb-4">
                    <select v-model="overrideTargetStatus"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="approved">Approved</option>
                        <option value="pending">Pending</option>
                        <option value="review">Review</option>
                    </select>
                </div>
                <div class="flex justify-end space-x-2">
                    <SecondaryButton @click="overrideModalOpen = false">Cancel</SecondaryButton>
                    <PrimaryButton @click="executeOverride" class="bg-emerald-600 hover:bg-emerald-500">
                        Update Status
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Bulk Override Review Modal -->
        <Modal :show="bulkOverrideModalOpen" @close="bulkOverrideModalOpen = false" maxWidth="sm">
            <div class="p-6">
                <h3 class="text-base font-bold text-gray-900 dark:text-white mb-2">
                    Bulk Override Review Status
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    Update review status for {{ isSelectAllFiltered ? `all ${store.summary.generated} filtered generated
                    bills`
                        : `${selectedBillIds.length} selected bills` }}:
                </p>
                <div class="mb-4">
                    <select v-model="bulkOverrideTargetStatus"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="approved">Approved</option>
                        <option value="pending">Pending</option>
                        <option value="review">Review</option>
                    </select>
                </div>
                <div class="flex justify-end space-x-2">
                    <SecondaryButton @click="bulkOverrideModalOpen = false">Cancel</SecondaryButton>
                    <PrimaryButton @click="executeBulkOverride" class="bg-emerald-600 hover:bg-emerald-500">
                        Update All
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Bill Details View Modal -->
        <Modal :show="store.viewModalOpen" @close="store.viewModalOpen = false" maxWidth="2xl">
            <div v-if="store.selectedBill" class="p-6 space-y-5">
                <div class="flex items-start justify-between border-b border-gray-200 dark:border-gray-700 pb-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                                {{ store.selectedBill.name }}
                            </h3>
                            <span
                                class="px-2 py-0.5 text-xs rounded bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300 font-mono font-semibold">
                                #{{ store.selectedBill.employeeCode }}
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            {{ store.selectedBill.designation }} • {{ store.selectedBill.religion }} • {{
                                store.selectedBill.bonus_title || 'Festival Bonus' }}
                        </p>
                    </div>

                    <span :class="[
                        'text-xs font-semibold px-2.5 py-1 rounded-full uppercase',
                        store.selectedBill.reviewStatus === 'approved' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'
                    ]">
                        {{ store.selectedBill.reviewStatus }}
                    </span>
                </div>

                <!-- Snapshot Meta -->
                <div
                    class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs bg-gray-50 dark:bg-gray-900/50 p-3.5 rounded-xl border border-gray-200 dark:border-gray-700">
                    <div>
                        <span class="text-gray-400 block text-[11px]">Pay Scale</span>
                        <span class="font-medium text-gray-900 dark:text-white">{{ store.selectedBill.payScale }}</span>
                    </div>
                    <div>
                        <span class="text-gray-400 block text-[11px]">Grade</span>
                        <span class="font-medium text-gray-900 dark:text-white">Grade {{ store.selectedBill.grade
                            }}</span>
                    </div>
                    <div>
                        <span class="text-gray-400 block text-[11px]">Bank Account</span>
                        <span class="font-mono text-gray-900 dark:text-white">{{ store.selectedBill.bankAccountNo ||
                            'N/A'
                            }}</span>
                    </div>
                    <div>
                        <span class="text-gray-400 block text-[11px]">Generated Date</span>
                        <span class="font-medium text-gray-900 dark:text-white">{{
                            store.selectedBill.bonusGenerationDate
                            }}</span>
                    </div>
                </div>

                <!-- Allowances Breakdown -->
                <div>
                    <h4 class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 mb-2">
                        Bonus Allowances
                    </h4>
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-gray-50 dark:bg-gray-900 text-gray-500 font-semibold">
                                <tr>
                                    <th class="py-2 px-3">Component</th>
                                    <th class="py-2 px-3 text-right">Amount (৳)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="(item, idx) in store.selectedBill.bonusAllowanceCalcObject || []" :key="idx">
                                    <td class="py-2 px-3">{{ item.name || item.display_name || 'Allowance' }}</td>
                                    <td class="py-2 px-3 text-right font-mono font-medium">
                                        ৳ {{ formatMoney(item.calculated_amount || item.amount) }}
                                    </td>
                                </tr>
                                <tr class="bg-gray-50/50 dark:bg-gray-900/50 font-bold">
                                    <td class="py-2 px-3">Total Allowances</td>
                                    <td class="py-2 px-3 text-right font-mono text-emerald-600 dark:text-emerald-400">
                                        ৳ {{ formatMoney(store.selectedBill.totalAllowance) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Deductions Breakdown if any -->
                <div
                    v-if="store.selectedBill.bonusDeductionCalcObject && store.selectedBill.bonusDeductionCalcObject.length > 0">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-red-600 dark:text-red-400 mb-2">
                        Bonus Deductions / Tax
                    </h4>
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-gray-50 dark:bg-gray-900 text-gray-500 font-semibold">
                                <tr>
                                    <th class="py-2 px-3">Component</th>
                                    <th class="py-2 px-3 text-right">Amount (৳)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="(item, idx) in store.selectedBill.bonusDeductionCalcObject" :key="idx">
                                    <td class="py-2 px-3">{{ item.name || item.display_name || 'Deduction' }}</td>
                                    <td class="py-2 px-3 text-right font-mono font-medium text-red-500">
                                        ৳ {{ formatMoney(item.calculated_amount || item.amount) }}
                                    </td>
                                </tr>
                                <tr class="bg-gray-50/50 dark:bg-gray-900/50 font-bold">
                                    <td class="py-2 px-3">Total Deductions</td>
                                    <td class="py-2 px-3 text-right font-mono text-red-500">
                                        ৳ {{ formatMoney(store.selectedBill.totalDeduction) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Net Amount Card -->
                <div
                    class="flex items-center justify-between p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800">
                    <span class="text-xs font-bold text-emerald-900 dark:text-emerald-200 uppercase tracking-wider">
                        Net Payable Bonus:
                    </span>
                    <span class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 font-mono">
                        ৳ {{ formatMoney(store.selectedBill.netBonus) }}
                    </span>
                </div>

                <div class="flex justify-end space-x-2 pt-2">
                    <SecondaryButton @click="store.viewModalOpen = false">Close</SecondaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
