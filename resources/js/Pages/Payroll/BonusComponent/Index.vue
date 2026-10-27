<script setup>
import { ref, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { useBonusComponentStore } from '@/stores/bonusComponent';
import { storeToRefs } from 'pinia';

const bonusComponentStore = useBonusComponentStore();
const { bonusComponents, loading } = storeToRefs(bonusComponentStore);

const isModalOpen = ref(false);
const isEditing = ref(false);
const isSaving = ref(false);

const localFilters = ref({
    search: '',
    componentType: '',
    calcType: '',
    fromBasic: '',
    isTaxable: ''
});

const form = ref({
    id: null,
    componentType: 'allowance',
    calcType: 'percentage',
    amount: 0,
    fromBasic: true,
    isTaxable: false,
});

onMounted(() => {
    bonusComponentStore.fetchBonusComponents();
});

const applyFilters = () => {
    bonusComponentStore.setFilters(localFilters.value);
};

const resetFilters = () => {
    localFilters.value = {
        search: '',
        componentType: '',
        calcType: '',
        fromBasic: '',
        isTaxable: ''
    };
    bonusComponentStore.resetFilters();
};

const openCreateModal = () => {
    isEditing.value = false;
    form.value = {
        id: null,
        componentType: 'allowance',
        calcType: 'percentage',
        amount: 0,
        fromBasic: true,
        isTaxable: false,
    };
    isModalOpen.value = true;
};

const openEditModal = (comp) => {
    isEditing.value = true;
    form.value = {
        id: comp.id,
        componentType: comp.componentType || 'allowance',
        calcType: comp.calcType || 'fixed',
        amount: Number(comp.amount) || 0,
        fromBasic: Boolean(comp.fromBasic),
        isTaxable: Boolean(comp.isTaxable),
    };
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
};

const saveComponent = async () => {
    isSaving.value = true;
    try {
        const payload = {
            componentType: form.value.componentType,
            calcType: form.value.calcType,
            amount: Number(form.value.amount),
            fromBasic: Boolean(form.value.fromBasic),
            isTaxable: Boolean(form.value.isTaxable),
        };

        if (isEditing.value) {
            await bonusComponentStore.updateBonusComponent(form.value.id, payload);
        } else {
            await bonusComponentStore.createBonusComponent(payload);
        }
        closeModal();
    } catch (error) {
        console.error("Save bonus component failed:", error);
    } finally {
        isSaving.value = false;
    }
};

const confirmDelete = async (id) => {
    await bonusComponentStore.deleteBonusComponent(id);
};

const changePage = (page) => {
    if (page >= 1 && page <= bonusComponents.value.last_page) {
        bonusComponentStore.fetchBonusComponents(page);
    }
};
</script>

<template>
    <Head title="Bonus Components" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="font-bold text-2xl text-gray-800 dark:text-gray-100 leading-tight">Bonus Components</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage allowances and deductions components used in bonus calculations</p>
                </div>
                <div>
                    <PrimaryButton @click="openCreateModal" class="inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add Bonus Component
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Search & Filter Card -->
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-xl p-6 border border-gray-100 dark:border-gray-700/60 transition-colors">
                <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700/60 pb-3 mb-4">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                        </svg>
                        Search & Filter Components
                    </h3>
                    <button @click="resetFilters" class="text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors">
                        Reset Filters
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                    <!-- Search input -->
                    <div>
                        <InputLabel for="filter_search" value="Search (ID / Amount)" />
                        <TextInput
                            id="filter_search"
                            v-model="localFilters.search"
                            placeholder="e.g. 50 or ID..."
                            class="mt-1 block w-full"
                            @keyup.enter="applyFilters"
                        />
                    </div>

                    <!-- Component Type -->
                    <div>
                        <InputLabel for="filter_type" value="Component Type" />
                        <select
                            id="filter_type"
                            v-model="localFilters.componentType"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 dark:focus:border-emerald-600 focus:ring-emerald-500 dark:focus:ring-emerald-600 rounded-md shadow-sm text-sm"
                        >
                            <option value="">All Types</option>
                            <option value="allowance">Allowance</option>
                            <option value="deduction">Deduction</option>
                        </select>
                    </div>

                    <!-- Calculation Type -->
                    <div>
                        <InputLabel for="filter_calc_type" value="Calculation Type" />
                        <select
                            id="filter_calc_type"
                            v-model="localFilters.calcType"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 dark:focus:border-emerald-600 focus:ring-emerald-500 dark:focus:ring-emerald-600 rounded-md shadow-sm text-sm"
                        >
                            <option value="">All Calc Types</option>
                            <option value="percentage">Percentage (%)</option>
                            <option value="fixed">Fixed Amount</option>
                        </select>
                    </div>

                    <!-- From Basic -->
                    <div>
                        <InputLabel for="filter_from_basic" value="From Basic?" />
                        <select
                            id="filter_from_basic"
                            v-model="localFilters.fromBasic"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 dark:focus:border-emerald-600 focus:ring-emerald-500 dark:focus:ring-emerald-600 rounded-md shadow-sm text-sm"
                        >
                            <option value="">All</option>
                            <option value="true">Yes</option>
                            <option value="false">No</option>
                        </select>
                    </div>

                    <!-- Is Taxable -->
                    <div>
                        <InputLabel for="filter_taxable" value="Is Taxable?" />
                        <select
                            id="filter_taxable"
                            v-model="localFilters.isTaxable"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 dark:focus:border-emerald-600 focus:ring-emerald-500 dark:focus:ring-emerald-600 rounded-md shadow-sm text-sm"
                        >
                            <option value="">All</option>
                            <option value="true">Yes</option>
                            <option value="false">No</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <SecondaryButton @click="resetFilters">Reset</SecondaryButton>
                    <PrimaryButton @click="applyFilters" class="inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        Filter Results
                    </PrimaryButton>
                </div>
            </div>

            <!-- Table Card -->
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-xl overflow-hidden border border-gray-100 dark:border-gray-700/60 transition-colors">
                <div class="p-4 sm:p-6 border-b border-gray-100 dark:border-gray-700/60">
                    <h3 class="font-semibold text-gray-900 dark:text-white">All Bonus Components</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Configure base rate or percentage of salary components</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                        <thead class="text-xs font-semibold text-gray-700 uppercase bg-gray-50 dark:bg-gray-700/60 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">
                            <tr>
                                <th scope="col" class="px-6 py-3.5">SL</th>
                                <th scope="col" class="px-6 py-3.5">Component ID</th>
                                <th scope="col" class="px-6 py-3.5">Type</th>
                                <th scope="col" class="px-6 py-3.5">Calculation Type</th>
                                <th scope="col" class="px-6 py-3.5 text-right">Amount / Value</th>
                                <th scope="col" class="px-6 py-3.5 text-center">From Basic</th>
                                <th scope="col" class="px-6 py-3.5 text-center">Taxable</th>
                                <th scope="col" class="px-6 py-3.5 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                            <tr v-if="loading" class="bg-white dark:bg-gray-800">
                                <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                    <div class="inline-flex items-center gap-2">
                                        <svg class="animate-spin h-5 w-5 text-emerald-500" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                        </svg>
                                        <span>Loading bonus components...</span>
                                    </div>
                                </td>
                            </tr>
                            <tr v-else-if="!bonusComponents.data || bonusComponents.data.length === 0" class="bg-white dark:bg-gray-800">
                                <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                    <div class="flex flex-col items-center justify-center">
                                        <svg class="w-12 h-12 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                        </svg>
                                        <p class="text-base font-medium text-gray-900 dark:text-gray-100">No bonus components found</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Click "Add Bonus Component" above to create one.</p>
                                    </div>
                                </td>
                            </tr>
                            <tr
                                v-else
                                v-for="(comp, index) in bonusComponents.data"
                                :key="comp.id"
                                class="bg-white dark:bg-gray-800 hover:bg-gray-50/80 dark:hover:bg-gray-700/40 transition-colors"
                            >
                                <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">
                                    {{ (bonusComponents.current_page - 1) * (bonusComponents.per_page || 10) + index + 1 }}
                                </td>
                                <td class="px-6 py-4">
                                    <span class="font-mono font-semibold text-xs px-2 py-1 rounded bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200">
                                        #{{ comp.id }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        :class="comp.componentType === 'allowance'
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800'
                                            : 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-300 border border-rose-200 dark:border-rose-800'"
                                        class="text-xs font-semibold px-2.5 py-1 rounded-full uppercase tracking-wider inline-flex items-center gap-1"
                                    >
                                        <span class="w-1.5 h-1.5 rounded-full" :class="comp.componentType === 'allowance' ? 'bg-emerald-500' : 'bg-rose-500'"></span>
                                        {{ comp.componentType }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        :class="comp.calcType === 'percentage'
                                            ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-300'
                                            : 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300'"
                                        class="text-xs font-medium px-2.5 py-0.5 rounded-full"
                                    >
                                        {{ comp.calcType === 'percentage' ? 'Percentage (%)' : 'Fixed Amount' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right font-mono font-semibold text-gray-900 dark:text-white">
                                    {{ comp.calcType === 'percentage' ? `${comp.amount}%` : `${Number(comp.amount).toLocaleString('en-US', { minimumFractionDigits: 2 })} TK` }}
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span
                                        :class="comp.fromBasic
                                            ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300'
                                            : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                                        class="text-xs font-medium px-2.5 py-0.5 rounded-full"
                                    >
                                        {{ comp.fromBasic ? 'Yes (Basic)' : 'No' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span
                                        :class="comp.isTaxable
                                            ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300'
                                            : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                                        class="text-xs font-medium px-2.5 py-0.5 rounded-full"
                                    >
                                        {{ comp.isTaxable ? 'Yes' : 'No' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="inline-flex items-center gap-3">
                                        <button
                                            @click="openEditModal(comp)"
                                            class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium text-xs flex items-center gap-1 hover:underline"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                            Edit
                                        </button>
                                        <button
                                            @click="confirmDelete(comp.id)"
                                            class="text-rose-600 dark:text-rose-400 hover:text-rose-800 dark:hover:text-rose-300 font-medium text-xs flex items-center gap-1 hover:underline"
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
                <div
                    v-if="bonusComponents.last_page > 1"
                    class="p-4 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row items-center justify-between gap-4"
                >
                    <span class="text-sm text-gray-700 dark:text-gray-400">
                        Showing <span class="font-semibold text-gray-900 dark:text-white">{{ (bonusComponents.current_page - 1) * (bonusComponents.per_page || 10) + 1 }}</span> to 
                        <span class="font-semibold text-gray-900 dark:text-white">{{ Math.min(bonusComponents.current_page * (bonusComponents.per_page || 10), bonusComponents.total) }}</span> of 
                        <span class="font-semibold text-gray-900 dark:text-white">{{ bonusComponents.total }}</span> Entries
                    </span>
                    
                    <div class="inline-flex items-center -space-x-px">
                        <button
                            @click="changePage(bonusComponents.current_page - 1)"
                            :disabled="bonusComponents.current_page === 1"
                            class="flex items-center justify-center px-3.5 h-8 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-s-md hover:bg-gray-100 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Previous
                        </button>
                        <span class="flex items-center justify-center px-3.5 h-8 text-sm font-medium text-emerald-600 bg-emerald-50 border-y border-emerald-200 dark:bg-emerald-950/40 dark:border-gray-700 dark:text-emerald-400">
                            {{ bonusComponents.current_page }} / {{ bonusComponents.last_page }}
                        </span>
                        <button
                            @click="changePage(bonusComponents.current_page + 1)"
                            :disabled="bonusComponents.current_page === bonusComponents.last_page"
                            class="flex items-center justify-center px-3.5 h-8 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-e-md hover:bg-gray-100 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :show="isModalOpen" @close="closeModal">
            <div class="p-6">
                <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-700 pb-4 mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <span class="p-1.5 rounded-lg bg-emerald-100 dark:bg-emerald-900/50 text-emerald-600 dark:text-emerald-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                        </span>
                        {{ isEditing ? `Edit Bonus Component #${form.id}` : 'Create New Bonus Component' }}
                    </h2>
                    <button @click="closeModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="saveComponent" class="space-y-6">
                    <!-- Component Type -->
                    <div>
                        <InputLabel value="Component Type" class="mb-2" />
                        <div class="grid grid-cols-2 gap-3">
                            <label
                                :class="form.componentType === 'allowance'
                                    ? 'border-emerald-500 bg-emerald-50/50 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-300 ring-2 ring-emerald-500/20'
                                    : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 text-gray-700 dark:text-gray-300'"
                                class="flex items-center gap-3 p-3.5 border rounded-lg cursor-pointer transition-all"
                            >
                                <input
                                    type="radio"
                                    value="allowance"
                                    v-model="form.componentType"
                                    class="text-emerald-600 focus:ring-emerald-500"
                                />
                                <div>
                                    <div class="font-medium text-sm">Allowance</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Bonus amount credited to employee</div>
                                </div>
                            </label>

                            <label
                                :class="form.componentType === 'deduction'
                                    ? 'border-rose-500 bg-rose-50/50 dark:bg-rose-950/30 text-rose-800 dark:text-rose-300 ring-2 ring-rose-500/20'
                                    : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 text-gray-700 dark:text-gray-300'"
                                class="flex items-center gap-3 p-3.5 border rounded-lg cursor-pointer transition-all"
                            >
                                <input
                                    type="radio"
                                    value="deduction"
                                    v-model="form.componentType"
                                    class="text-rose-600 focus:ring-rose-500"
                                />
                                <div>
                                    <div class="font-medium text-sm">Deduction</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Amount deducted from bonus</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Calculation Type -->
                    <div>
                        <InputLabel value="Calculation Type" class="mb-2" />
                        <div class="grid grid-cols-2 gap-3">
                            <label
                                :class="form.calcType === 'percentage'
                                    ? 'border-indigo-500 bg-indigo-50/50 dark:bg-indigo-950/30 text-indigo-800 dark:text-indigo-300 ring-2 ring-indigo-500/20'
                                    : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 text-gray-700 dark:text-gray-300'"
                                class="flex items-center gap-3 p-3.5 border rounded-lg cursor-pointer transition-all"
                            >
                                <input
                                    type="radio"
                                    value="percentage"
                                    v-model="form.calcType"
                                    class="text-indigo-600 focus:ring-indigo-500"
                                />
                                <div>
                                    <div class="font-medium text-sm">Percentage (%)</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Calculated as % of basic/gross</div>
                                </div>
                            </label>

                            <label
                                :class="form.calcType === 'fixed'
                                    ? 'border-blue-500 bg-blue-50/50 dark:bg-blue-950/30 text-blue-800 dark:text-blue-300 ring-2 ring-blue-500/20'
                                    : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 text-gray-700 dark:text-gray-300'"
                                class="flex items-center gap-3 p-3.5 border rounded-lg cursor-pointer transition-all"
                            >
                                <input
                                    type="radio"
                                    value="fixed"
                                    v-model="form.calcType"
                                    class="text-blue-600 focus:ring-blue-500"
                                />
                                <div>
                                    <div class="font-medium text-sm">Fixed Amount</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Flat rate in TK</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Amount -->
                    <div>
                        <InputLabel for="comp_amount" :value="form.calcType === 'percentage' ? 'Percentage Rate (%)' : 'Fixed Amount (TK)'" />
                        <div class="mt-1 relative rounded-md shadow-sm">
                            <TextInput
                                id="comp_amount"
                                type="number"
                                step="0.01"
                                min="0"
                                v-model="form.amount"
                                class="block w-full pr-12 text-base font-medium"
                                placeholder="0.00"
                                required
                            />
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-gray-500 dark:text-gray-400 text-sm font-semibold">
                                {{ form.calcType === 'percentage' ? '%' : 'TK' }}
                            </div>
                        </div>
                    </div>

                    <!-- Checkboxes: From Basic & Is Taxable -->
                    <div class="p-4 bg-gray-50 dark:bg-gray-700/40 rounded-xl space-y-4 border border-gray-100 dark:border-gray-700">
                        <div class="flex items-start gap-3">
                            <Checkbox id="from_basic" v-model:checked="form.fromBasic" class="mt-0.5" />
                            <label for="from_basic" class="cursor-pointer select-none">
                                <span class="font-medium text-sm text-gray-800 dark:text-gray-200">Calculate from Basic Salary</span>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Check if this component's percentage is derived from the employee's basic pay</p>
                            </label>
                        </div>

                        <div class="flex items-start gap-3 border-t border-gray-200/60 dark:border-gray-700/60 pt-3">
                            <Checkbox id="is_taxable" v-model:checked="form.isTaxable" class="mt-0.5" />
                            <label for="is_taxable" class="cursor-pointer select-none">
                                <span class="font-medium text-sm text-gray-800 dark:text-gray-200">Is Taxable</span>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Check if this bonus component is subject to income tax calculation</p>
                            </label>
                        </div>
                    </div>

                    <!-- Modal Actions -->
                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <SecondaryButton @click="closeModal" :disabled="isSaving">Cancel</SecondaryButton>
                        <PrimaryButton type="submit" :disabled="isSaving" class="inline-flex items-center gap-2">
                            <svg v-if="isSaving" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span>{{ isEditing ? 'Update Component' : 'Create Component' }}</span>
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
