<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { usePayrollComponentStore } from '@/stores/payrollComponent';
import { storeToRefs } from 'pinia';
import axios from 'axios';

const props = defineProps({
    payScales: {
        type: Array,
        default: () => []
    },
    grades: {
        type: Array,
        default: () => []
    },
    payrollGroupTypes: {
        type: Array,
        default: () => []
    },
    allPayrollComponentTypes: {
        type: Array,
        default: () => []
    },
    payrollGroups: {
        type: Array,
        default: () => []
    }
});

const componentStore = usePayrollComponentStore();
const { payrollComponents, loading, filters, sort, activeFilterCount } = storeToRefs(componentStore);

const availablePayScales = ref([...props.payScales]);
const availableGrades = ref([...props.grades]);
const availableGroupTypes = ref([...props.payrollGroupTypes]);
const availableComponentTypes = ref([...props.allPayrollComponentTypes]);
const availablePayrollGroups = ref([...props.payrollGroups]);

const isModalOpen = ref(false);
const isEditing = ref(false);
const modalComponentTypes = ref([]); // For create/edit modal (unassigned types)

const localFilters = ref({
    search: '',
    pay_scale_id: '',
    grade_id: '',
    payroll_group_type_id: '',
    payroll_group_id: '',
    payroll_component_type_id: '',
    component_type: '',
    calcType: '',
    min_amount: '',
    max_amount: '',
    is_taxable: '',
    isActive: ''
});

const componentTypeOptions = [
    { label: 'Allowance', value: 'allowance' },
    { label: 'Deduction', value: 'deduction' },
    { label: 'Tax', value: 'tax' },
];

const calcTypeOptions = [
    { label: 'Fixed Amount (৳)', value: 'fixed' },
    { label: 'Percentage (%)', value: 'percentage' },
];

const taxableOptions = [
    { label: 'Taxable Only', value: 'true' },
    { label: 'Non-Taxable Only', value: 'false' },
];

const statusOptions = [
    { label: 'Active Only', value: 'true' },
    { label: 'Inactive Only', value: 'false' },
];

const filteredGrades = computed(() => {
    if (!localFilters.value.pay_scale_id) {
        return availableGrades.value;
    }
    return availableGrades.value.filter(g => String(g.pay_scale_id) === String(localFilters.value.pay_scale_id));
});

const activeFilterChips = computed(() => {
    const chips = [];
    if (localFilters.value.search) {
        chips.push({ key: 'search', label: `Search: "${localFilters.value.search}"` });
    }
    if (localFilters.value.pay_scale_id) {
        const scale = availablePayScales.value.find(s => String(s.id) === String(localFilters.value.pay_scale_id));
        chips.push({ key: 'pay_scale_id', label: `Scale: ${scale ? scale.scale_name : localFilters.value.pay_scale_id}` });
    }
    if (localFilters.value.grade_id) {
        const grade = availableGrades.value.find(g => String(g.id) === String(localFilters.value.grade_id));
        chips.push({ key: 'grade_id', label: `Grade: ${grade ? grade.grade_name : localFilters.value.grade_id}` });
    }
    if (localFilters.value.payroll_group_type_id) {
        const type = availableGroupTypes.value.find(t => String(t.id) === String(localFilters.value.payroll_group_type_id));
        chips.push({ key: 'payroll_group_type_id', label: `Designation: ${type ? type.group_name : localFilters.value.payroll_group_type_id}` });
    }
    if (localFilters.value.payroll_component_type_id) {
        const type = availableComponentTypes.value.find(t => String(t.id) === String(localFilters.value.payroll_component_type_id));
        chips.push({ key: 'payroll_component_type_id', label: `Name: ${type ? type.name : localFilters.value.payroll_component_type_id}` });
    }
    if (localFilters.value.component_type) {
        chips.push({ key: 'component_type', label: `Type: ${localFilters.value.component_type.toUpperCase()}` });
    }
    if (localFilters.value.calcType) {
        chips.push({ key: 'calcType', label: `Calc: ${localFilters.value.calcType === 'fixed' ? 'Fixed (৳)' : 'Percentage (%)'}` });
    }
    if (localFilters.value.min_amount !== '') {
        chips.push({ key: 'min_amount', label: `Amount ≥ ${Number(localFilters.value.min_amount).toLocaleString()}` });
    }
    if (localFilters.value.max_amount !== '') {
        chips.push({ key: 'max_amount', label: `Amount ≤ ${Number(localFilters.value.max_amount).toLocaleString()}` });
    }
    if (localFilters.value.is_taxable !== '') {
        const isTax = localFilters.value.is_taxable === 'true' || localFilters.value.is_taxable === true;
        chips.push({ key: 'is_taxable', label: `Tax: ${isTax ? 'Taxable' : 'Non-Taxable'}` });
    }
    if (localFilters.value.isActive !== '') {
        const isAct = localFilters.value.isActive === 'true' || localFilters.value.isActive === true;
        chips.push({ key: 'isActive', label: `Status: ${isAct ? 'Active' : 'Inactive'}` });
    }
    return chips;
});

const form = ref({
    id: null,
    payroll_group_id: '',
    payroll_component_type_id: '',
    component_type: 'allowance',
    calcType: 'fixed',
    amount: 0,
    is_taxable: false,
    isActive: true,
});

onMounted(async () => {
    localFilters.value = { ...componentStore.filters };
    componentStore.fetchPayrollComponents();

    // Fallbacks if not injected via Inertia
    if (availablePayScales.value.length === 0) {
        try {
            const res = await axios.get(route('payroll-scales.index', { all: true }));
            availablePayScales.value = res.data;
        } catch (e) {
            console.error('Failed to fetch pay scales:', e);
        }
    }
    if (availableGrades.value.length === 0) {
        try {
            const res = await axios.get(route('grades.index', { all: true }));
            availableGrades.value = res.data;
        } catch (e) {
            console.error('Failed to fetch grades:', e);
        }
    }
    if (availablePayrollGroups.value.length === 0) {
        try {
            const res = await axios.get(route('payroll-groups.index', { all: true }));
            availablePayrollGroups.value = res.data;
        } catch (e) {
            console.error('Failed to fetch payroll groups:', e);
        }
    }
    if (availableComponentTypes.value.length === 0) {
        try {
            const res = await axios.get(route('payroll-component-types.index', { all: true }));
            availableComponentTypes.value = res.data;
        } catch (e) {
            console.error('Failed to fetch component types:', e);
        }
    }
    if (availableGroupTypes.value.length === 0) {
        try {
            const res = await axios.get(route('payroll-group-types.index', { all: true }));
            availableGroupTypes.value = res.data;
        } catch (e) {
            console.error('Failed to fetch group types:', e);
        }
    }
});

const handleSearch = () => {
    componentStore.setFilters(localFilters.value);
};

const handleReset = () => {
    localFilters.value = {
        search: '',
        pay_scale_id: '',
        grade_id: '',
        payroll_group_type_id: '',
        payroll_group_id: '',
        payroll_component_type_id: '',
        component_type: '',
        calcType: '',
        min_amount: '',
        max_amount: '',
        is_taxable: '',
        isActive: ''
    };
    componentStore.resetFilters();
};

const removeSingleFilter = (key) => {
    localFilters.value[key] = '';
    componentStore.setFilters(localFilters.value);
};

const handleSort = (field) => {
    componentStore.setSort(field);
};

const fetchModalComponentTypes = async () => {
    if (form.value.payroll_group_id) {
        try {
            const response = await axios.get(route('payroll-component-types.index'), {
                params: {
                    unassigned_for_group_id: form.value.payroll_group_id,
                    include_type_id: isEditing.value ? form.value.payroll_component_type_id : null,
                    all: true
                }
            });
            modalComponentTypes.value = response.data;
            if (!modalComponentTypes.value.find(t => t.id === form.value.payroll_component_type_id)) {
                form.value.payroll_component_type_id = '';
            }
        } catch (error) {
            console.error('Failed to fetch modal component types:', error);
        }
    } else {
        modalComponentTypes.value = [];
        form.value.payroll_component_type_id = '';
    }
};

const openCreateModal = () => {
    isEditing.value = false;
    form.value = {
        id: null,
        payroll_group_id: '',
        payroll_component_type_id: '',
        component_type: 'allowance',
        calcType: 'fixed',
        amount: 0,
        is_taxable: false,
        isActive: true,
    };
    fetchModalComponentTypes();
    isModalOpen.value = true;
};

const openEditModal = (comp) => {
    isEditing.value = true;
    form.value = {
        id: comp.id,
        payroll_group_id: comp.payroll_group_id,
        payroll_component_type_id: comp.payroll_component_type_id,
        component_type: comp.component_type || 'allowance',
        calcType: comp.calcType || 'fixed',
        amount: comp.amount,
        is_taxable: Boolean(comp.is_taxable),
        isActive: Boolean(comp.isActive),
    };
    fetchModalComponentTypes();
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
};

const saveComponent = async () => {
    try {
        if (isEditing.value) {
            await componentStore.updatePayrollComponent(form.value.id, form.value);
        } else {
            await componentStore.createPayrollComponent(form.value);
        }
        closeModal();
    } catch (error) {
        console.error("Save failed:", error);
    }
};

const confirmDelete = async (id) => {
    await componentStore.deletePayrollComponent(id);
};

const changePage = (page) => {
    if (page >= 1 && page <= payrollComponents.value.last_page) {
        componentStore.fetchPayrollComponents(page);
    }
};

const formatAmount = (amount, calcType) => {
    if (amount === null || amount === undefined || isNaN(amount)) return '-';
    if (calcType === 'percentage') {
        return `${Number(amount)}%`;
    }
    return `৳ ${Number(amount).toLocaleString()}`;
};
</script>

<template>
    <Head title="Payroll Components" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h2 class="font-bold text-2xl text-gray-900 dark:text-white leading-tight">Payroll Components</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage allowances, deductions, taxes, and calculation rules across designation groups</p>
                </div>
                <PrimaryButton @click="openCreateModal" class="inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add Component
                </PrimaryButton>
            </div>
        </template>

        <div class="py-6 sm:py-8">
            <div class="max-w-7xl mx-auto sm:px-2 lg:px-4 space-y-6">

                <!-- Search and Filter Section -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 p-5 sm:p-6 transition-colors">
                    <div class="flex flex-col gap-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="p-2 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                    </svg>
                                </div>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white uppercase tracking-wider">Search & Filters</h3>
                                <span v-if="activeFilterCount > 0" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300">
                                    {{ activeFilterCount }} active
                                </span>
                            </div>

                            <button
                                v-if="activeFilterCount > 0"
                                type="button"
                                @click="handleReset"
                                class="text-xs font-medium text-gray-500 hover:text-emerald-600 dark:text-gray-400 dark:hover:text-emerald-400 transition"
                            >
                                Reset all
                            </button>
                        </div>

                        <!-- Filter Controls Grid -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <!-- Universal Search -->
                            <div>
                                <InputLabel for="filter_search" value="Search" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <div class="relative mt-1">
                                    <input
                                        id="filter_search"
                                        type="text"
                                        v-model="localFilters.search"
                                        @keyup.enter="handleSearch"
                                        placeholder="Name, scale, group..."
                                        class="block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 pl-8 pr-8 py-2 shadow-sm transition"
                                    />
                                    <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-gray-400">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                    </div>
                                    <button
                                        v-if="localFilters.search"
                                        type="button"
                                        @click="localFilters.search = ''; handleSearch()"
                                        class="absolute inset-y-0 right-0 pr-2.5 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Component Name (Type) -->
                            <div>
                                <InputLabel for="filter_component_name" value="Component Name" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <select
                                    id="filter_component_name"
                                    v-model="localFilters.payroll_component_type_id"
                                    class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                >
                                    <option value="">All Component Names</option>
                                    <option v-for="type in availableComponentTypes" :key="type.id" :value="type.id">
                                        {{ type.name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Pay Scale -->
                            <div>
                                <InputLabel for="filter_pay_scale" value="Pay Scale" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <select
                                    id="filter_pay_scale"
                                    v-model="localFilters.pay_scale_id"
                                    class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                >
                                    <option value="">All Pay Scales</option>
                                    <option v-for="scale in availablePayScales" :key="scale.id" :value="scale.id">
                                        {{ scale.scale_name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Salary Grade -->
                            <div>
                                <InputLabel for="filter_grade" value="Salary Grade" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <select
                                    id="filter_grade"
                                    v-model="localFilters.grade_id"
                                    class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                >
                                    <option value="">All Grades</option>
                                    <option v-for="grade in filteredGrades" :key="grade.id" :value="grade.id">
                                        {{ grade.grade_name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Designation Group Type -->
                            <div>
                                <InputLabel for="filter_group_type" value="Designation Type" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <select
                                    id="filter_group_type"
                                    v-model="localFilters.payroll_group_type_id"
                                    class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                >
                                    <option value="">All Designation Types</option>
                                    <option v-for="type in availableGroupTypes" :key="type.id" :value="type.id">
                                        {{ type.group_name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Component Type (Allowance / Deduction / Tax) -->
                            <div>
                                <InputLabel for="filter_type" value="Component Type" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <select
                                    id="filter_type"
                                    v-model="localFilters.component_type"
                                    class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                >
                                    <option value="">All Types</option>
                                    <option v-for="opt in componentTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </div>

                            <!-- Calculation Type -->
                            <div>
                                <InputLabel for="filter_calc_type" value="Calculation Type" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <select
                                    id="filter_calc_type"
                                    v-model="localFilters.calcType"
                                    class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                >
                                    <option value="">All Calculation Types</option>
                                    <option v-for="opt in calcTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </div>

                            <!-- Taxable -->
                            <div>
                                <InputLabel for="filter_taxable" value="Taxable Status" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <select
                                    id="filter_taxable"
                                    v-model="localFilters.is_taxable"
                                    class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                >
                                    <option value="">All Taxable Statuses</option>
                                    <option v-for="opt in taxableOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </div>

                            <!-- Min Amount -->
                            <div>
                                <InputLabel for="filter_min_amount" value="Min Amount (≥)" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <input
                                    id="filter_min_amount"
                                    type="number"
                                    v-model="localFilters.min_amount"
                                    @keyup.enter="handleSearch"
                                    placeholder="e.g. 500"
                                    class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                />
                            </div>

                            <!-- Max Amount -->
                            <div>
                                <InputLabel for="filter_max_amount" value="Max Amount (≤)" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <input
                                    id="filter_max_amount"
                                    type="number"
                                    v-model="localFilters.max_amount"
                                    @keyup.enter="handleSearch"
                                    placeholder="e.g. 50000"
                                    class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                />
                            </div>

                            <!-- Active Status -->
                            <div>
                                <InputLabel for="filter_status" value="Status" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <select
                                    id="filter_status"
                                    v-model="localFilters.isActive"
                                    class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                >
                                    <option value="">All Statuses</option>
                                    <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Action Buttons Row -->
                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-gray-100 dark:border-gray-700/60">
                            <button
                                type="button"
                                @click="handleReset"
                                class="px-3.5 py-1.5 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm"
                            >
                                Reset
                            </button>
                            <button
                                type="button"
                                @click="handleSearch"
                                class="inline-flex items-center gap-1 px-4 py-1.5 text-xs font-medium rounded-md text-white bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500 transition shadow-sm"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                Apply Filters
                            </button>
                        </div>

                        <!-- Active Filter Chips -->
                        <div v-if="activeFilterChips.length > 0" class="flex flex-wrap items-center gap-2 pt-2 border-t border-gray-100 dark:border-gray-700/60">
                            <span class="text-xs text-gray-500 dark:text-gray-400 font-medium">Active Filters:</span>
                            <div
                                v-for="chip in activeFilterChips"
                                :key="chip.key"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800"
                            >
                                <span>{{ chip.label }}</span>
                                <button
                                    type="button"
                                    @click="removeSingleFilter(chip.key)"
                                    class="text-emerald-500 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-200 transition"
                                >
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                            <button
                                type="button"
                                @click="handleReset"
                                class="text-xs font-medium text-red-600 dark:text-red-400 hover:underline ml-1"
                            >
                                Clear all
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Table Card -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 overflow-hidden transition-colors">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-50/80 dark:bg-gray-700/50 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700/60">
                                <tr>
                                    <th scope="col" class="px-6 py-3.5 cursor-pointer select-none hover:text-emerald-600 dark:hover:text-emerald-400 transition" @click="handleSort('id')">
                                        <div class="inline-flex items-center gap-1.5">
                                            <span>SL</span>
                                            <span class="text-gray-400">
                                                <svg v-if="sort.field === 'id'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="sort.direction === 'asc' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7'" />
                                                </svg>
                                                <svg v-else class="w-3 h-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                                </svg>
                                            </span>
                                        </div>
                                    </th>
                                    <th scope="col" class="px-6 py-3.5">Component Name</th>
                                    <th scope="col" class="px-6 py-3.5">Pay Scale</th>
                                    <th scope="col" class="px-6 py-3.5">Payroll Group</th>
                                    <th scope="col" class="px-6 py-3.5 cursor-pointer select-none hover:text-emerald-600 dark:hover:text-emerald-400 transition" @click="handleSort('component_type')">
                                        <div class="inline-flex items-center gap-1.5">
                                            <span>Type</span>
                                            <span class="text-gray-400">
                                                <svg v-if="sort.field === 'component_type'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="sort.direction === 'asc' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7'" />
                                                </svg>
                                                <svg v-else class="w-3 h-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                                </svg>
                                            </span>
                                        </div>
                                    </th>
                                    <th scope="col" class="px-6 py-3.5 cursor-pointer select-none hover:text-emerald-600 dark:hover:text-emerald-400 transition" @click="handleSort('calcType')">
                                        <div class="inline-flex items-center gap-1.5">
                                            <span>Calculation</span>
                                            <span class="text-gray-400">
                                                <svg v-if="sort.field === 'calcType'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="sort.direction === 'asc' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7'" />
                                                </svg>
                                                <svg v-else class="w-3 h-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                                </svg>
                                            </span>
                                        </div>
                                    </th>
                                    <th scope="col" class="px-6 py-3.5 text-right cursor-pointer select-none hover:text-emerald-600 dark:hover:text-emerald-400 transition" @click="handleSort('amount')">
                                        <div class="inline-flex items-center justify-end gap-1.5">
                                            <span>Amount</span>
                                            <span class="text-gray-400">
                                                <svg v-if="sort.field === 'amount'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="sort.direction === 'asc' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7'" />
                                                </svg>
                                                <svg v-else class="w-3 h-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                                </svg>
                                            </span>
                                        </div>
                                    </th>
                                    <th scope="col" class="px-6 py-3.5 text-center cursor-pointer select-none hover:text-emerald-600 dark:hover:text-emerald-400 transition" @click="handleSort('is_taxable')">
                                        <div class="inline-flex items-center justify-center gap-1.5">
                                            <span>Taxable</span>
                                            <span class="text-gray-400">
                                                <svg v-if="sort.field === 'is_taxable'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="sort.direction === 'asc' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7'" />
                                                </svg>
                                                <svg v-else class="w-3 h-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                                </svg>
                                            </span>
                                        </div>
                                    </th>
                                    <th scope="col" class="px-6 py-3.5 text-center cursor-pointer select-none hover:text-emerald-600 dark:hover:text-emerald-400 transition" @click="handleSort('isActive')">
                                        <div class="inline-flex items-center justify-center gap-1.5">
                                            <span>Status</span>
                                            <span class="text-gray-400">
                                                <svg v-if="sort.field === 'isActive'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="sort.direction === 'asc' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7'" />
                                                </svg>
                                                <svg v-else class="w-3 h-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                                </svg>
                                            </span>
                                        </div>
                                    </th>
                                    <th scope="col" class="px-6 py-3.5 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                                <tr v-if="loading" class="bg-white dark:bg-gray-800">
                                    <td colspan="10" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                                        <div class="inline-flex items-center gap-2">
                                            <svg class="animate-spin h-5 w-5 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            <span>Loading payroll components...</span>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-else-if="!payrollComponents.data || payrollComponents.data.length === 0" class="bg-white dark:bg-gray-800">
                                    <td colspan="10" class="px-6 py-12 text-center">
                                        <div class="flex flex-col items-center justify-center">
                                            <div class="p-3 rounded-full bg-gray-100 dark:bg-gray-700/60 text-gray-400 dark:text-gray-500 mb-3">
                                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                </svg>
                                            </div>
                                            <p class="text-base font-semibold text-gray-800 dark:text-gray-200">No payroll components found</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-sm">Try adjusting your search criteria or clearing active filters.</p>
                                            <button
                                                v-if="activeFilterChips.length > 0"
                                                type="button"
                                                @click="handleReset"
                                                class="mt-3 inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 transition"
                                            >
                                                Clear all filters
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-else v-for="(comp, index) in payrollComponents.data" :key="comp.id" class="bg-white dark:bg-gray-800 hover:bg-gray-50/80 dark:hover:bg-gray-700/50 transition-colors">
                                    <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ (payrollComponents.current_page - 1) * payrollComponents.per_page + index + 1 }}</td>
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-gray-900 dark:text-white">{{ comp.payroll_component_type?.name || 'N/A' }}</div>
                                        <div v-if="comp.payroll_component_type?.component_description" class="text-xs text-gray-400 mt-0.5 truncate max-w-xs">{{ comp.payroll_component_type.component_description }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-semibold bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-600">
                                            {{ comp.payroll_group?.grade?.pay_scale?.scale_name || '-' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-medium text-gray-900 dark:text-gray-200">{{ comp.payroll_group?.grade?.grade_name || '-' }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ comp.payroll_group?.payroll_group_type?.group_name || '-' }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span
                                            :class="{
                                                'bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200 dark:border-blue-800': comp.component_type === 'allowance',
                                                'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800': comp.component_type === 'deduction',
                                                'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200 dark:border-rose-800': comp.component_type === 'tax'
                                            }"
                                            class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize"
                                        >
                                            {{ comp.component_type }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 capitalize">
                                            {{ comp.calcType || 'fixed' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right font-medium text-gray-900 dark:text-gray-100">
                                        {{ formatAmount(comp.amount, comp.calcType) }}
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span :class="comp.is_taxable ? 'bg-orange-50 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300 border border-orange-200 dark:border-orange-800' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-600'" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium">
                                            {{ comp.is_taxable ? 'Taxable' : 'Non-Taxable' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span :class="comp.isActive ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-400'" class="text-xs font-semibold px-2.5 py-0.5 rounded-full">
                                            {{ comp.isActive ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="inline-flex items-center gap-2">
                                            <button
                                                @click="openEditModal(comp)"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-900/60 rounded-lg transition"
                                            >
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                                Edit
                                            </button>
                                            <button
                                                @click="confirmDelete(comp.id)"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 dark:bg-red-950/40 dark:text-red-300 dark:hover:bg-red-900/60 rounded-lg transition"
                                            >
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="p-4 border-t border-gray-100 dark:border-gray-700/60 flex items-center justify-between" v-if="payrollComponents.last_page > 1">
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Page <span class="font-semibold text-gray-900 dark:text-white">{{ payrollComponents.current_page }}</span> of
                            <span class="font-semibold text-gray-900 dark:text-white">{{ payrollComponents.last_page }}</span> ({{ payrollComponents.total }} total components)
                        </span>

                        <div class="inline-flex gap-1">
                            <button
                                @click="changePage(payrollComponents.current_page - 1)"
                                :disabled="payrollComponents.current_page === 1"
                                class="px-3 py-1 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed transition"
                            >
                                Previous
                            </button>
                            <button
                                @click="changePage(payrollComponents.current_page + 1)"
                                :disabled="payrollComponents.current_page === payrollComponents.last_page"
                                class="px-3 py-1 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed transition"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :show="isModalOpen" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-6">
                    {{ isEditing ? 'Edit Payroll Component' : 'Add New Payroll Component' }}
                </h2>

                <form @submit.prevent="saveComponent" class="space-y-6">
                    <div>
                        <InputLabel for="modal_payroll_group_id" value="Payroll Group *" />
                        <select
                            id="modal_payroll_group_id"
                            v-model="form.payroll_group_id"
                            @change="fetchModalComponentTypes"
                            class="mt-1 block w-full text-xs rounded-xl border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            required
                        >
                            <option value="" disabled>Select Payroll Group</option>
                            <option v-for="group in availablePayrollGroups" :key="group.id" :value="group.id">
                                {{ group.grade?.pay_scale?.scale_name }} - {{ group.grade?.grade_name }} - {{ group.payroll_group_type?.group_name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <InputLabel for="modal_payroll_component_type_id" value="Component Name *" />
                        <select
                            id="modal_payroll_component_type_id"
                            v-model="form.payroll_component_type_id"
                            class="mt-1 block w-full text-xs rounded-xl border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition disabled:opacity-50"
                            required
                            :disabled="!form.payroll_group_id"
                        >
                            <option value="" disabled>Select Component Name</option>
                            <option v-for="type in modalComponentTypes" :key="type.id" :value="type.id">
                                {{ type.name }}
                            </option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="modal_component_type" value="Component Type *" />
                            <select
                                id="modal_component_type"
                                v-model="form.component_type"
                                class="mt-1 block w-full text-xs rounded-xl border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                required
                            >
                                <option value="allowance">Allowance</option>
                                <option value="deduction">Deduction</option>
                                <option value="tax">Tax</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel for="modal_calcType" value="Calculation Type *" />
                            <select
                                id="modal_calcType"
                                v-model="form.calcType"
                                class="mt-1 block w-full text-xs rounded-xl border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                required
                            >
                                <option value="fixed">Fixed</option>
                                <option value="percentage">Percentage</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <InputLabel for="modal_amount" value="Amount *" />
                        <TextInput
                            id="modal_amount"
                            type="number"
                            step="any"
                            class="mt-1 block w-full text-xs rounded-xl"
                            v-model="form.amount"
                            required
                        />
                    </div>

                    <div class="flex items-center gap-6 mt-4">
                        <div class="flex items-center">
                            <Checkbox id="modal_is_taxable" v-model:checked="form.is_taxable" />
                            <label for="modal_is_taxable" class="ml-2 text-sm text-gray-600 dark:text-gray-400">Taxable</label>
                        </div>
                        <div class="flex items-center">
                            <Checkbox id="modal_isActive" v-model:checked="form.isActive" />
                            <label for="modal_isActive" class="ml-2 text-sm text-gray-600 dark:text-gray-400">Active</label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 mt-6">
                        <SecondaryButton @click="closeModal" type="button">Cancel</SecondaryButton>
                        <PrimaryButton type="submit">
                            {{ isEditing ? 'Update' : 'Save' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
