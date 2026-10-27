<script setup>
import { ref, onMounted, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { usePayrollComponentTypeStore } from '@/stores/payrollComponentType';
import { storeToRefs } from 'pinia';
import axios from 'axios';

const props = defineProps({
    payScales: {
        type: Array,
        default: () => []
    }
});

const payrollComponentTypeStore = usePayrollComponentTypeStore();
const { payrollComponentTypes, loading, filters, sort, activeFilterCount } = storeToRefs(payrollComponentTypeStore);

const availablePayScales = ref([...props.payScales]);

const isModalOpen = ref(false);
const isEditing = ref(false);
const form = ref({
    id: null,
    name: '',
    component_description: '',
});

const localFilters = ref({
    search: '',
    usage: '',
    component_type: '',
    pay_scale_id: '',
});

const usageOptions = [
    { label: 'Assigned (In Use)', value: 'assigned' },
    { label: 'Unassigned (No Groups)', value: 'unassigned' },
];

const componentTypeOptions = [
    { label: 'Used as Allowance', value: 'allowance' },
    { label: 'Used as Deduction', value: 'deduction' },
    { label: 'Used as Tax', value: 'tax' },
];

const activeFilterChips = computed(() => {
    const chips = [];
    if (localFilters.value.search) {
        chips.push({ key: 'search', label: `Search: "${localFilters.value.search}"` });
    }
    if (localFilters.value.usage) {
        chips.push({ key: 'usage', label: `Usage: ${localFilters.value.usage === 'assigned' ? 'Assigned' : 'Unassigned'}` });
    }
    if (localFilters.value.component_type) {
        const opt = componentTypeOptions.find(o => o.value === localFilters.value.component_type);
        chips.push({ key: 'component_type', label: `Type: ${opt ? opt.label : localFilters.value.component_type}` });
    }
    if (localFilters.value.pay_scale_id) {
        const scale = availablePayScales.value.find(s => String(s.id) === String(localFilters.value.pay_scale_id));
        chips.push({ key: 'pay_scale_id', label: `Scale: ${scale ? scale.scale_name : localFilters.value.pay_scale_id}` });
    }
    return chips;
});

onMounted(async () => {
    localFilters.value = { ...payrollComponentTypeStore.filters };
    payrollComponentTypeStore.fetchPayrollComponentTypes();

    if (availablePayScales.value.length === 0) {
        try {
            const res = await axios.get(route('payroll-scales.index', { all: true }));
            availablePayScales.value = res.data;
        } catch (e) {
            console.error('Failed to fetch pay scales:', e);
        }
    }
});

const handleSearch = () => {
    payrollComponentTypeStore.setFilters(localFilters.value);
};

const handleReset = () => {
    localFilters.value = {
        search: '',
        usage: '',
        component_type: '',
        pay_scale_id: '',
    };
    payrollComponentTypeStore.resetFilters();
};

const removeSingleFilter = (key) => {
    localFilters.value[key] = '';
    payrollComponentTypeStore.setFilters(localFilters.value);
};

const handleSort = (field) => {
    payrollComponentTypeStore.setSort(field);
};

const openCreateModal = () => {
    isEditing.value = false;
    form.value = {
        id: null,
        name: '',
        component_description: '',
    };
    isModalOpen.value = true;
};

const openEditModal = (type) => {
    isEditing.value = true;
    form.value = {
        id: type.id,
        name: type.name,
        component_description: type.component_description || '',
    };
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
};

const saveComponentType = async () => {
    try {
        if (isEditing.value) {
            await payrollComponentTypeStore.updatePayrollComponentType(form.value.id, form.value);
        } else {
            await payrollComponentTypeStore.createPayrollComponentType(form.value);
        }
        closeModal();
    } catch (error) {
        console.error("Save failed:", error);
    }
};

const confirmDelete = async (id) => {
    await payrollComponentTypeStore.deletePayrollComponentType(id);
};

const changePage = (page) => {
    if (page >= 1 && page <= payrollComponentTypes.value.last_page) {
        payrollComponentTypeStore.fetchPayrollComponentTypes(page);
    }
};
</script>

<template>
    <Head title="Component Types" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h2 class="font-bold text-2xl text-gray-900 dark:text-white leading-tight">Component Types</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage definitions and types for salary allowances, deductions, and tax withholdings</p>
                </div>
                <PrimaryButton @click="openCreateModal" class="inline-flex items-center gap-2 rounded-md">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add Component Type
                </PrimaryButton>
            </div>
        </template>

        <div class="py-6 sm:py-8">
            <div class="max-w-7xl mx-auto sm:px-2 lg:px-4 space-y-6">

                <!-- Search and Filter Section -->
                <div class="bg-white dark:bg-gray-800 rounded-md shadow-sm border border-gray-100 dark:border-gray-700/60 p-5 sm:p-6 transition-colors">
                    <div class="flex flex-col gap-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="p-2 rounded-md bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                    </svg>
                                </div>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white uppercase tracking-wider">Search & Filters</h3>
                                <span v-if="activeFilterCount > 0" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300">
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
                                <InputLabel for="filter_search" value="Search Name or Description" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <div class="relative mt-1">
                                    <input
                                        id="filter_search"
                                        type="text"
                                        v-model="localFilters.search"
                                        @keyup.enter="handleSearch"
                                        placeholder="Type name or description..."
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

                            <!-- Usage Status -->
                            <div>
                                <InputLabel for="filter_usage" value="Usage in Components" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <select
                                    id="filter_usage"
                                    v-model="localFilters.usage"
                                    class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                >
                                    <option value="">All Usage States</option>
                                    <option v-for="opt in usageOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </div>

                            <!-- Assigned Component Type -->
                            <div>
                                <InputLabel for="filter_comp_type" value="Component Type Role" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
                                <select
                                    id="filter_comp_type"
                                    v-model="localFilters.component_type"
                                    class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                                >
                                    <option value="">All Roles</option>
                                    <option v-for="opt in componentTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </div>

                            <!-- Pay Scale Version -->
                            <div>
                                <InputLabel for="filter_pay_scale" value="Used in Pay Scale" class="text-xs font-medium text-gray-700 dark:text-gray-300" />
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
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800"
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
                <div class="bg-white dark:bg-gray-800 rounded-md shadow-sm border border-gray-100 dark:border-gray-700/60 overflow-hidden transition-colors">
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
                                    <th scope="col" class="px-6 py-3.5 cursor-pointer select-none hover:text-emerald-600 dark:hover:text-emerald-400 transition" @click="handleSort('name')">
                                        <div class="inline-flex items-center gap-1.5">
                                            <span>Component Name</span>
                                            <span class="text-gray-400">
                                                <svg v-if="sort.field === 'name'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="sort.direction === 'asc' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7'" />
                                                </svg>
                                                <svg v-else class="w-3 h-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                                </svg>
                                            </span>
                                        </div>
                                    </th>
                                    <th scope="col" class="px-6 py-3.5">Description</th>
                                    <th scope="col" class="px-6 py-3.5 cursor-pointer select-none hover:text-emerald-600 dark:hover:text-emerald-400 transition" @click="handleSort('payroll_components_count')">
                                        <div class="inline-flex items-center gap-1.5">
                                            <span>Active Usage</span>
                                            <span class="text-gray-400">
                                                <svg v-if="sort.field === 'payroll_components_count'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                                    <td colspan="5" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                                        <div class="inline-flex items-center gap-2">
                                            <svg class="animate-spin h-5 w-5 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            <span>Loading component types...</span>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-else-if="!payrollComponentTypes.data || payrollComponentTypes.data.length === 0" class="bg-white dark:bg-gray-800">
                                    <td colspan="5" class="px-6 py-12 text-center">
                                        <div class="flex flex-col items-center justify-center">
                                            <div class="p-3 rounded-md bg-gray-100 dark:bg-gray-700/60 text-gray-400 dark:text-gray-500 mb-3">
                                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                </svg>
                                            </div>
                                            <p class="text-base font-semibold text-gray-800 dark:text-gray-200">No component types found</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-sm">Try adjusting your search criteria or clearing active filters.</p>
                                            <button
                                                v-if="activeFilterChips.length > 0"
                                                type="button"
                                                @click="handleReset"
                                                class="mt-3 inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-md text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 transition"
                                            >
                                                Clear all filters
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-else v-for="(type, index) in payrollComponentTypes.data" :key="type.id" class="bg-white dark:bg-gray-800 hover:bg-gray-50/80 dark:hover:bg-gray-700/50 transition-colors">
                                    <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ (payrollComponentTypes.current_page - 1) * payrollComponentTypes.per_page + index + 1 }}</td>
                                    <td class="px-6 py-4 font-semibold text-gray-900 dark:text-white">{{ type.name }}</td>
                                    <td class="px-6 py-4 text-gray-600 dark:text-gray-300">{{ type.component_description || '-' }}</td>
                                    <td class="px-6 py-4">
                                        <span
                                            v-if="type.payroll_components_count > 0"
                                            class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800"
                                        >
                                            <svg class="w-3 h-3 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            In {{ type.payroll_components_count }} {{ type.payroll_components_count === 1 ? 'component' : 'components' }}
                                        </span>
                                        <span
                                            v-else
                                            class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400 border border-gray-200 dark:border-gray-600"
                                        >
                                            Unassigned
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="inline-flex items-center gap-2">
                                            <button
                                                @click="openEditModal(type)"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-900/60 rounded-md transition"
                                            >
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                                Edit
                                            </button>
                                            <button
                                                @click="confirmDelete(type.id)"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 dark:bg-red-950/40 dark:text-red-300 dark:hover:bg-red-900/60 rounded-md transition"
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
                    <div class="p-4 border-t border-gray-100 dark:border-gray-700/60 flex items-center justify-between" v-if="payrollComponentTypes.last_page > 1">
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Page <span class="font-semibold text-gray-900 dark:text-white">{{ payrollComponentTypes.current_page }}</span> of
                            <span class="font-semibold text-gray-900 dark:text-white">{{ payrollComponentTypes.last_page }}</span> ({{ payrollComponentTypes.total }} total types)
                        </span>

                        <div class="inline-flex gap-1">
                            <button
                                @click="changePage(payrollComponentTypes.current_page - 1)"
                                :disabled="payrollComponentTypes.current_page === 1"
                                class="px-3 py-1 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed transition"
                            >
                                Previous
                            </button>
                            <button
                                @click="changePage(payrollComponentTypes.current_page + 1)"
                                :disabled="payrollComponentTypes.current_page === payrollComponentTypes.last_page"
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
                    {{ isEditing ? 'Edit Component Type' : 'Add New Component Type' }}
                </h2>

                <form @submit.prevent="saveComponentType" class="space-y-6">
                    <div>
                        <InputLabel for="modal_type_name" value="Component Name *" />
                        <TextInput
                            id="modal_type_name"
                            type="text"
                            class="mt-1 block w-full text-xs rounded-md"
                            v-model="form.name"
                            required
                        />
                    </div>

                    <div>
                        <InputLabel for="modal_component_description" value="Description" />
                        <TextInput
                            id="modal_component_description"
                            type="text"
                            class="mt-1 block w-full text-xs rounded-md"
                            v-model="form.component_description"
                        />
                    </div>

                    <div class="flex justify-end gap-3 mt-6">
                        <SecondaryButton @click="closeModal" type="button" class="rounded-md">Cancel</SecondaryButton>
                        <PrimaryButton type="submit" class="rounded-md">
                            {{ isEditing ? 'Update' : 'Save' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
