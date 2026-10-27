<script setup>
import { ref, onMounted, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { useBonusGroupStore } from '@/stores/bonusGroup';
import { useBonusComponentStore } from '@/stores/bonusComponent';
import { storeToRefs } from 'pinia';

const bonusGroupStore = useBonusGroupStore();
const bonusComponentStore = useBonusComponentStore();

const { bonusGroups, allBonusComponents, loading } = storeToRefs(bonusGroupStore);

const isModalOpen = ref(false);
const isEditing = ref(false);
const isSaving = ref(false);

const localFilters = ref({
    search: ''
});

const form = ref({
    id: null,
    name: '',
    description: '',
    bonusAllowanceObjects: [],
    bonusDeductionObjects: [],
});

const selectedAllowanceId = ref('');
const selectedDeductionId = ref('');

// Quick Component Creation sub-form inside modal
const showQuickCreateComponent = ref(false);
const isQuickSaving = ref(false);
const quickComponent = ref({
    componentType: 'allowance',
    calcType: 'percentage',
    amount: 50,
    fromBasic: true,
    isTaxable: false
});

// Computed filtered available components for dropdowns
const availableAllowances = computed(() => {
    return allBonusComponents.value.filter(c => c.componentType === 'allowance');
});

const availableDeductions = computed(() => {
    return allBonusComponents.value.filter(c => c.componentType === 'deduction');
});

onMounted(() => {
    bonusGroupStore.fetchBonusGroups();
    bonusGroupStore.fetchAllBonusComponents();
});

const applyFilters = () => {
    bonusGroupStore.setFilters(localFilters.value);
};

const resetFilters = () => {
    localFilters.value = {
        search: ''
    };
    bonusGroupStore.resetFilters();
};

const openCreateModal = () => {
    isEditing.value = false;
    form.value = {
        id: null,
        name: '',
        description: '',
        bonusAllowanceObjects: [],
        bonusDeductionObjects: [],
    };
    selectedAllowanceId.value = '';
    selectedDeductionId.value = '';
    showQuickCreateComponent.value = false;
    bonusGroupStore.fetchAllBonusComponents();
    isModalOpen.value = true;
};

const openEditModal = (group) => {
    isEditing.value = true;
    form.value = {
        id: group.id,
        name: group.name || '',
        description: group.description || '',
        bonusAllowanceObjects: Array.isArray(group.bonusAllowanceObjects) ? [...group.bonusAllowanceObjects] : [],
        bonusDeductionObjects: Array.isArray(group.bonusDeductionObjects) ? [...group.bonusDeductionObjects] : [],
    };
    selectedAllowanceId.value = '';
    selectedDeductionId.value = '';
    showQuickCreateComponent.value = false;
    bonusGroupStore.fetchAllBonusComponents();
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    showQuickCreateComponent.value = false;
};

// Add allowance component to form
const addAllowanceComponent = () => {
    if (!selectedAllowanceId.value) return;
    const comp = allBonusComponents.value.find(c => c.id === Number(selectedAllowanceId.value));
    if (comp && !form.value.bonusAllowanceObjects.some(item => item.id === comp.id)) {
        form.value.bonusAllowanceObjects.push({
            id: comp.id,
            componentType: comp.componentType,
            calcType: comp.calcType,
            amount: comp.amount,
            fromBasic: Boolean(comp.fromBasic),
            isTaxable: Boolean(comp.isTaxable),
            display_name: comp.display_name || `#${comp.id} Allowance ${comp.amount}`
        });
    }
    selectedAllowanceId.value = '';
};

// Remove allowance component from form
const removeAllowanceComponent = (index) => {
    form.value.bonusAllowanceObjects.splice(index, 1);
};

// Add deduction component to form
const addDeductionComponent = () => {
    if (!selectedDeductionId.value) return;
    const comp = allBonusComponents.value.find(c => c.id === Number(selectedDeductionId.value));
    if (comp && !form.value.bonusDeductionObjects.some(item => item.id === comp.id)) {
        form.value.bonusDeductionObjects.push({
            id: comp.id,
            componentType: comp.componentType,
            calcType: comp.calcType,
            amount: comp.amount,
            fromBasic: Boolean(comp.fromBasic),
            isTaxable: Boolean(comp.isTaxable),
            display_name: comp.display_name || `#${comp.id} Deduction ${comp.amount}`
        });
    }
    selectedDeductionId.value = '';
};

// Remove deduction component from form
const removeDeductionComponent = (index) => {
    form.value.bonusDeductionObjects.splice(index, 1);
};

// Quick Create Component handler
const openQuickCreate = (type) => {
    quickComponent.value = {
        componentType: type,
        calcType: 'percentage',
        amount: type === 'allowance' ? 50 : 1000,
        fromBasic: true,
        isTaxable: false
    };
    showQuickCreateComponent.value = true;
};

const saveQuickComponent = async () => {
    isQuickSaving.value = true;
    try {
        await bonusComponentStore.createBonusComponent({
            componentType: quickComponent.value.componentType,
            calcType: quickComponent.value.calcType,
            amount: Number(quickComponent.value.amount),
            fromBasic: Boolean(quickComponent.value.fromBasic),
            isTaxable: Boolean(quickComponent.value.isTaxable)
        });

        // Re-fetch all bonus components
        await bonusGroupStore.fetchAllBonusComponents();

        // Auto-attach the newly created component to this group!
        const newlyCreated = allBonusComponents.value[0]; // Ordered by id desc
        if (newlyCreated) {
            if (newlyCreated.componentType === 'allowance') {
                if (!form.value.bonusAllowanceObjects.some(c => c.id === newlyCreated.id)) {
                    form.value.bonusAllowanceObjects.push({
                        id: newlyCreated.id,
                        componentType: newlyCreated.componentType,
                        calcType: newlyCreated.calcType,
                        amount: newlyCreated.amount,
                        fromBasic: Boolean(newlyCreated.fromBasic),
                        isTaxable: Boolean(newlyCreated.isTaxable),
                        display_name: newlyCreated.display_name
                    });
                }
            } else {
                if (!form.value.bonusDeductionObjects.some(c => c.id === newlyCreated.id)) {
                    form.value.bonusDeductionObjects.push({
                        id: newlyCreated.id,
                        componentType: newlyCreated.componentType,
                        calcType: newlyCreated.calcType,
                        amount: newlyCreated.amount,
                        fromBasic: Boolean(newlyCreated.fromBasic),
                        isTaxable: Boolean(newlyCreated.isTaxable),
                        display_name: newlyCreated.display_name
                    });
                }
            }
        }

        showQuickCreateComponent.value = false;
    } catch (e) {
        console.error("Quick create failed", e);
    } finally {
        isQuickSaving.value = false;
    }
};

const saveBonusGroup = async () => {
    isSaving.value = true;
    try {
        const payload = {
            name: form.value.name,
            description: form.value.description,
            bonusAllowanceObjects: form.value.bonusAllowanceObjects,
            bonusDeductionObjects: form.value.bonusDeductionObjects,
        };

        if (isEditing.value) {
            await bonusGroupStore.updateBonusGroup(form.value.id, payload);
        } else {
            await bonusGroupStore.createBonusGroup(payload);
        }
        closeModal();
    } catch (error) {
        console.error("Save bonus group failed:", error);
    } finally {
        isSaving.value = false;
    }
};

const confirmDelete = async (id) => {
    await bonusGroupStore.deleteBonusGroup(id);
};

const changePage = (page) => {
    if (page >= 1 && page <= bonusGroups.value.last_page) {
        bonusGroupStore.fetchBonusGroups(page);
    }
};
</script>

<template>
    <Head title="Bonus Setup" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="font-bold text-2xl text-gray-800 dark:text-gray-100 leading-tight">Bonus Setup (Bonus Groups)</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Configure bonus packages and link allowance & deduction components</p>
                </div>
                <div>
                    <PrimaryButton @click="openCreateModal" class="inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add Bonus Group
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
                        Search Bonus Groups
                    </h3>
                    <button @click="resetFilters" class="text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors">
                        Reset Search
                    </button>
                </div>

                <div class="flex flex-col sm:flex-row gap-4 items-end">
                    <div class="flex-1 w-full">
                        <InputLabel for="filter_search" value="Search by Group Name or Description" />
                        <TextInput
                            id="filter_search"
                            v-model="localFilters.search"
                            placeholder="Type group name or description keyword..."
                            class="mt-1 block w-full"
                            @keyup.enter="applyFilters"
                        />
                    </div>
                    <div class="flex gap-2 w-full sm:w-auto">
                        <SecondaryButton @click="resetFilters" class="w-full sm:w-auto justify-center">Reset</SecondaryButton>
                        <PrimaryButton @click="applyFilters" class="w-full sm:w-auto justify-center inline-flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            Search
                        </PrimaryButton>
                    </div>
                </div>
            </div>

            <!-- Table Card -->
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-xl overflow-hidden border border-gray-100 dark:border-gray-700/60 transition-colors">
                <div class="p-4 sm:p-6 border-b border-gray-100 dark:border-gray-700/60">
                    <h3 class="font-semibold text-gray-900 dark:text-white">Configured Bonus Groups</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Bonus packages that can be attached to employees or grades</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                        <thead class="text-xs font-semibold text-gray-700 uppercase bg-gray-50 dark:bg-gray-700/60 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">
                            <tr>
                                <th scope="col" class="px-6 py-3.5">SL</th>
                                <th scope="col" class="px-6 py-3.5">Group Name</th>
                                <th scope="col" class="px-6 py-3.5">Description</th>
                                <th scope="col" class="px-6 py-3.5">Allowance Components</th>
                                <th scope="col" class="px-6 py-3.5">Deduction Components</th>
                                <th scope="col" class="px-6 py-3.5 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                            <tr v-if="loading" class="bg-white dark:bg-gray-800">
                                <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                    <div class="inline-flex items-center gap-2">
                                        <svg class="animate-spin h-5 w-5 text-emerald-500" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                        </svg>
                                        <span>Loading bonus groups...</span>
                                    </div>
                                </td>
                            </tr>
                            <tr v-else-if="!bonusGroups.data || bonusGroups.data.length === 0" class="bg-white dark:bg-gray-800">
                                <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                    <div class="flex flex-col items-center justify-center">
                                        <svg class="w-12 h-12 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                        </svg>
                                        <p class="text-base font-medium text-gray-900 dark:text-gray-100">No bonus groups found</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Click "Add Bonus Group" above to create one.</p>
                                    </div>
                                </td>
                            </tr>
                            <tr
                                v-else
                                v-for="(group, index) in bonusGroups.data"
                                :key="group.id"
                                class="bg-white dark:bg-gray-800 hover:bg-gray-50/80 dark:hover:bg-gray-700/40 transition-colors"
                            >
                                <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">
                                    {{ (bonusGroups.current_page - 1) * (bonusGroups.per_page || 10) + index + 1 }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-gray-900 dark:text-white">{{ group.name }}</div>
                                    <div class="text-xs text-gray-400">ID: #{{ group.id }}</div>
                                </td>
                                <td class="px-6 py-4 text-gray-600 dark:text-gray-300 max-w-xs truncate">
                                    {{ group.description || '-' }}
                                </td>
                                <td class="px-6 py-4">
                                    <div v-if="group.bonusAllowanceObjects && group.bonusAllowanceObjects.length > 0" class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="item in group.bonusAllowanceObjects"
                                            :key="item.id"
                                            class="inline-flex items-center text-xs font-medium px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800"
                                        >
                                            #{{ item.id }}: {{ item.calcType === 'percentage' ? `${item.amount}%` : `${item.amount} TK` }}
                                        </span>
                                    </div>
                                    <span v-else class="text-xs text-gray-400 italic">None assigned</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div v-if="group.bonusDeductionObjects && group.bonusDeductionObjects.length > 0" class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="item in group.bonusDeductionObjects"
                                            :key="item.id"
                                            class="inline-flex items-center text-xs font-medium px-2 py-0.5 rounded-full bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200 dark:border-rose-800"
                                        >
                                            #{{ item.id }}: {{ item.calcType === 'percentage' ? `${item.amount}%` : `${item.amount} TK` }}
                                        </span>
                                    </div>
                                    <span v-else class="text-xs text-gray-400 italic">None assigned</span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="inline-flex items-center gap-3">
                                        <button
                                            @click="openEditModal(group)"
                                            class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium text-xs flex items-center gap-1 hover:underline"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                            Edit
                                        </button>
                                        <button
                                            @click="confirmDelete(group.id)"
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
                    v-if="bonusGroups.last_page > 1"
                    class="p-4 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row items-center justify-between gap-4"
                >
                    <span class="text-sm text-gray-700 dark:text-gray-400">
                        Showing <span class="font-semibold text-gray-900 dark:text-white">{{ (bonusGroups.current_page - 1) * (bonusGroups.per_page || 10) + 1 }}</span> to 
                        <span class="font-semibold text-gray-900 dark:text-white">{{ Math.min(bonusGroups.current_page * (bonusGroups.per_page || 10), bonusGroups.total) }}</span> of 
                        <span class="font-semibold text-gray-900 dark:text-white">{{ bonusGroups.total }}</span> Entries
                    </span>
                    
                    <div class="inline-flex items-center -space-x-px">
                        <button
                            @click="changePage(bonusGroups.current_page - 1)"
                            :disabled="bonusGroups.current_page === 1"
                            class="flex items-center justify-center px-3.5 h-8 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-s-md hover:bg-gray-100 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Previous
                        </button>
                        <span class="flex items-center justify-center px-3.5 h-8 text-sm font-medium text-emerald-600 bg-emerald-50 border-y border-emerald-200 dark:bg-emerald-950/40 dark:border-gray-700 dark:text-emerald-400">
                            {{ bonusGroups.current_page }} / {{ bonusGroups.last_page }}
                        </span>
                        <button
                            @click="changePage(bonusGroups.current_page + 1)"
                            :disabled="bonusGroups.current_page === bonusGroups.last_page"
                            class="flex items-center justify-center px-3.5 h-8 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-e-md hover:bg-gray-100 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :show="isModalOpen" @close="closeModal" max-width="2xl">
            <div class="p-6">
                <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-700 pb-4 mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <span class="p-1.5 rounded-lg bg-emerald-100 dark:bg-emerald-900/50 text-emerald-600 dark:text-emerald-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                        </span>
                        {{ isEditing ? `Edit Bonus Group: ${form.name}` : 'Create New Bonus Group' }}
                    </h2>
                    <button @click="closeModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="saveBonusGroup" class="space-y-6">
                    <!-- Group Name -->
                    <div>
                        <InputLabel for="group_name" value="Bonus Group Name" />
                        <TextInput
                            id="group_name"
                            v-model="form.name"
                            class="mt-1 block w-full"
                            placeholder="e.g. Eid-ul-Fitr Festival Bonus, Bengali New Year Bonus"
                            required
                        />
                    </div>

                    <!-- Description -->
                    <div>
                        <InputLabel for="group_desc" value="Description" />
                        <textarea
                            id="group_desc"
                            v-model="form.description"
                            rows="2"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 dark:focus:border-emerald-600 focus:ring-emerald-500 dark:focus:ring-emerald-600 rounded-md shadow-sm text-sm"
                            placeholder="Provide brief details or eligibility for this bonus setup..."
                            required
                        ></textarea>
                    </div>

                    <!-- Bonus Allowances Configuration -->
                    <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-xl border border-gray-200 dark:border-gray-700 space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                Bonus Allowance Components
                            </h4>
                            <div class="flex items-center gap-3">
                                <span class="text-xs text-gray-500">({{ form.bonusAllowanceObjects.length }} selected)</span>
                                <button
                                    type="button"
                                    @click="openQuickCreate('allowance')"
                                    class="text-xs text-emerald-600 dark:text-emerald-400 font-semibold hover:underline"
                                >
                                    + Create New Component
                                </button>
                            </div>
                        </div>

                        <!-- Dropdown to add allowance -->
                        <div class="flex gap-2">
                            <select
                                v-model="selectedAllowanceId"
                                class="flex-1 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 dark:focus:border-emerald-600 focus:ring-emerald-500 dark:focus:ring-emerald-600 rounded-md shadow-sm text-sm"
                            >
                                <option value="">Select an allowance component to attach...</option>
                                <option
                                    v-for="comp in availableAllowances"
                                    :key="comp.id"
                                    :value="comp.id"
                                    :disabled="form.bonusAllowanceObjects.some(item => item.id === comp.id)"
                                >
                                    #{{ comp.id }} - {{ comp.calcType === 'percentage' ? `${comp.amount}%` : `${comp.amount} TK` }} {{ comp.fromBasic ? '(Basic)' : '' }} {{ comp.isTaxable ? '[Taxable]' : '' }}
                                </option>
                            </select>
                            <SecondaryButton
                                type="button"
                                @click="addAllowanceComponent"
                                :disabled="!selectedAllowanceId"
                                class="whitespace-nowrap"
                            >
                                + Attach
                            </SecondaryButton>
                        </div>

                        <!-- Selected allowances list -->
                        <div v-if="form.bonusAllowanceObjects.length > 0" class="space-y-2 pt-1">
                            <div
                                v-for="(item, idx) in form.bonusAllowanceObjects"
                                :key="item.id"
                                class="flex items-center justify-between p-2.5 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-xs"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-emerald-600 dark:text-emerald-400">#{{ item.id }}</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">
                                        {{ item.calcType === 'percentage' ? `${item.amount}% of ${item.fromBasic ? 'Basic' : 'Gross'}` : `${item.amount} TK` }}
                                    </span>
                                    <span v-if="item.isTaxable" class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                                        Taxable
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    @click="removeAllowanceComponent(idx)"
                                    class="text-rose-500 hover:text-rose-700 p-1"
                                    title="Remove component"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <p v-else class="text-xs text-gray-500 dark:text-gray-400 italic">No allowance components attached yet.</p>
                    </div>

                    <!-- Bonus Deductions Configuration -->
                    <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-xl border border-gray-200 dark:border-gray-700 space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                                Bonus Deduction Components
                            </h4>
                            <div class="flex items-center gap-3">
                                <span class="text-xs text-gray-500">({{ form.bonusDeductionObjects.length }} selected)</span>
                                <button
                                    type="button"
                                    @click="openQuickCreate('deduction')"
                                    class="text-xs text-rose-600 dark:text-rose-400 font-semibold hover:underline"
                                >
                                    + Create New Component
                                </button>
                            </div>
                        </div>

                        <!-- Dropdown to add deduction -->
                        <div class="flex gap-2">
                            <select
                                v-model="selectedDeductionId"
                                class="flex-1 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-rose-500 dark:focus:border-rose-600 focus:ring-rose-500 dark:focus:ring-rose-600 rounded-md shadow-sm text-sm"
                            >
                                <option value="">Select a deduction component to attach...</option>
                                <option
                                    v-for="comp in availableDeductions"
                                    :key="comp.id"
                                    :value="comp.id"
                                    :disabled="form.bonusDeductionObjects.some(item => item.id === comp.id)"
                                >
                                    #{{ comp.id }} - {{ comp.calcType === 'percentage' ? `${comp.amount}%` : `${comp.amount} TK` }} {{ comp.fromBasic ? '(Basic)' : '' }} {{ comp.isTaxable ? '[Taxable]' : '' }}
                                </option>
                            </select>
                            <SecondaryButton
                                type="button"
                                @click="addDeductionComponent"
                                :disabled="!selectedDeductionId"
                                class="whitespace-nowrap"
                            >
                                + Attach
                            </SecondaryButton>
                        </div>

                        <!-- Selected deductions list -->
                        <div v-if="form.bonusDeductionObjects.length > 0" class="space-y-2 pt-1">
                            <div
                                v-for="(item, idx) in form.bonusDeductionObjects"
                                :key="item.id"
                                class="flex items-center justify-between p-2.5 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-xs"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-rose-600 dark:text-rose-400">#{{ item.id }}</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">
                                        {{ item.calcType === 'percentage' ? `${item.amount}% of ${item.fromBasic ? 'Basic' : 'Gross'}` : `${item.amount} TK` }}
                                    </span>
                                    <span v-if="item.isTaxable" class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                                        Taxable
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    @click="removeDeductionComponent(idx)"
                                    class="text-rose-500 hover:text-rose-700 p-1"
                                    title="Remove component"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <p v-else class="text-xs text-gray-500 dark:text-gray-400 italic">No deduction components attached yet.</p>
                    </div>

                    <!-- Modal Actions -->
                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <SecondaryButton @click="closeModal" :disabled="isSaving">Cancel</SecondaryButton>
                        <PrimaryButton type="submit" :disabled="isSaving" class="inline-flex items-center gap-2">
                            <svg v-if="isSaving" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span>{{ isEditing ? 'Update Bonus Group' : 'Create Bonus Group' }}</span>
                        </PrimaryButton>
                    </div>
                </form>

                <!-- Quick Component Create Drawer / Sub-card -->
                <div v-if="showQuickCreateComponent" class="mt-6 p-4 rounded-xl border border-emerald-300 dark:border-emerald-700 bg-emerald-50/50 dark:bg-emerald-950/30">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-sm font-bold text-emerald-900 dark:text-emerald-200">
                            Quick Create {{ quickComponent.componentType === 'allowance' ? 'Allowance' : 'Deduction' }} Component
                        </h4>
                        <button @click="showQuickCreateComponent = false" class="text-gray-400 hover:text-gray-600">
                            ✕
                        </button>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <InputLabel value="Calc Type" />
                            <select
                                v-model="quickComponent.calcType"
                                class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md text-xs shadow-sm"
                            >
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (TK)</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel :value="quickComponent.calcType === 'percentage' ? 'Rate (%)' : 'Amount (TK)'" />
                            <TextInput
                                type="number"
                                step="0.01"
                                min="0"
                                v-model="quickComponent.amount"
                                class="mt-1 block w-full text-xs"
                            />
                        </div>
                        <div class="flex flex-col justify-end">
                            <div class="flex items-center gap-2 mb-2">
                                <Checkbox id="quick_basic" v-model:checked="quickComponent.fromBasic" />
                                <label for="quick_basic" class="text-xs text-gray-700 dark:text-gray-300">From Basic</label>
                            </div>
                            <div class="flex items-center gap-2">
                                <Checkbox id="quick_tax" v-model:checked="quickComponent.isTaxable" />
                                <label for="quick_tax" class="text-xs text-gray-700 dark:text-gray-300">Is Taxable</label>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end gap-2">
                        <SecondaryButton @click="showQuickCreateComponent = false" class="text-xs py-1 px-2.5">
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton @click="saveQuickComponent" :disabled="isQuickSaving" class="text-xs py-1 px-3">
                            {{ isQuickSaving ? 'Creating...' : 'Save & Attach' }}
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
