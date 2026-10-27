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
import { useDepartmentOfficeStore } from '@/stores/departmentOffice';
import { storeToRefs } from 'pinia';

const props = defineProps({
    userCategories: {
        type: Array,
        default: () => []
    }
});

const officeStore = useDepartmentOfficeStore();
const { records, loading, filters, sort, activeFilterCount } = storeToRefs(officeStore);

const localFilters = ref({
    search: '',
    type: '',
    isActive: '',
    hasEmployees: '',
    user_category_id: ''
});

const isModalOpen = ref(false);
const isEditing = ref(false);
const form = ref({
    id: null,
    name: '',
    description: '',
    type: 'department',
    isActive: true,
});

const typeOptions = [
    { label: 'Faculty', value: 'faculty' },
    { label: 'Department', value: 'department' },
    { label: 'Dormitory', value: 'dorm' },
    { label: 'Office', value: 'office' },
    { label: 'Others', value: 'others' },
];

const statusOptions = [
    { label: 'Active Only', value: 'true' },
    { label: 'Inactive Only', value: 'false' }
];

const employeeAssignmentOptions = [
    { label: 'With Assigned Staff', value: 'true' },
    { label: 'No Staff Assigned', value: 'false' }
];

const activeFilterChips = computed(() => {
    const chips = [];
    if (localFilters.value.search) {
        chips.push({ key: 'search', label: `Search: "${localFilters.value.search}"` });
    }
    if (localFilters.value.type) {
        const item = typeOptions.find(t => t.value === localFilters.value.type);
        chips.push({ key: 'type', label: `Type: ${item ? item.label : localFilters.value.type}` });
    }
    if (localFilters.value.isActive !== '') {
        const isAct = localFilters.value.isActive === 'true' || localFilters.value.isActive === true;
        chips.push({ key: 'isActive', label: `Status: ${isAct ? 'Active' : 'Inactive'}` });
    }
    if (localFilters.value.hasEmployees !== '') {
        const has = localFilters.value.hasEmployees === 'true' || localFilters.value.hasEmployees === true;
        chips.push({ key: 'hasEmployees', label: `Staff: ${has ? 'With Staff' : 'Without Staff'}` });
    }
    if (localFilters.value.user_category_id) {
        const cat = (props.userCategories || []).find(c => String(c.id) === String(localFilters.value.user_category_id));
        chips.push({ key: 'user_category_id', label: `Group: ${cat ? cat.name : localFilters.value.user_category_id}` });
    }
    return chips;
});

onMounted(() => {
    localFilters.value = { ...officeStore.filters };
    officeStore.fetchRecords();
});

const handleSearch = () => {
    officeStore.setFilters(localFilters.value);
};

const handleReset = () => {
    officeStore.resetFilters();
    localFilters.value = { ...officeStore.filters };
};

const removeSingleFilter = (key) => {
    localFilters.value[key] = '';
    officeStore.setFilters({ [key]: '' });
};

const handleSort = (field) => {
    officeStore.setSort(field);
};

const openCreateModal = () => {
    isEditing.value = false;
    form.value = {
        id: null,
        name: '',
        description: '',
        type: 'department',
        isActive: true,
    };
    isModalOpen.value = true;
};

const openEditModal = (item) => {
    isEditing.value = true;
    form.value = {
        id: item.id,
        name: item.name,
        description: item.description || '',
        type: item.type || 'department',
        isActive: Boolean(item.isActive),
    };
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
};

const saveRecord = async () => {
    try {
        if (isEditing.value) {
            await officeStore.updateRecord(form.value.id, form.value);
        } else {
            await officeStore.createRecord(form.value);
        }
        closeModal();
    } catch (error) {
        console.error("Save failed:", error);
    }
};

const confirmDelete = async (id) => {
    await officeStore.deleteRecord(id);
};

const changePage = (page) => {
    if (page >= 1 && page <= records.value.last_page) {
        officeStore.fetchRecords(page);
    }
};
</script>

<template>
    <Head title="Departments and Office" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="font-bold text-2xl text-gray-800 dark:text-gray-100 leading-tight">Manage Departments & Offices</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Directory of university faculties, departments, administrative units and offices</p>
                </div>
                <div>
                    <PrimaryButton @click="openCreateModal" class="inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Add New Unit
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- ========================================== -->
            <!-- SEARCH & FILTER SECTION                    -->
            <!-- ========================================== -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700/60 overflow-hidden transition-all duration-300">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700/60 flex flex-wrap items-center justify-between gap-3 bg-gray-50/60 dark:bg-gray-800/80">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                Search & Filter Units
                            </h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Filter departments and offices by name, entity type, staffing status, or staff groups
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <span v-if="activeFilterChips.length > 0" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/40">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            {{ activeFilterChips.length }} active {{ activeFilterChips.length === 1 ? 'filter' : 'filters' }}
                        </span>
                        <button
                            v-if="activeFilterChips.length > 0"
                            type="button"
                            @click="handleReset"
                            class="px-2.5 py-1.5 text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 hover:underline transition"
                        >
                            Clear all
                        </button>
                    </div>
                </div>

                <!-- Filter Form Grid -->
                <form @submit.prevent="handleSearch" class="p-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3.5">
                        <!-- Universal Search (Name, Description) -->
                        <div class="lg:col-span-4">
                            <InputLabel for="filter_search" value="Search Name or Description" />
                            <div class="relative mt-1">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </span>
                                <TextInput
                                    id="filter_search"
                                    type="text"
                                    v-model="localFilters.search"
                                    placeholder="e.g. Computer Science, Registrar..."
                                    class="w-full pl-9 pr-8 text-sm"
                                />
                                <button
                                    v-if="localFilters.search"
                                    type="button"
                                    @click="localFilters.search = ''"
                                    class="absolute inset-y-0 right-0 pr-2.5 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Entity Type -->
                        <div class="lg:col-span-2">
                            <InputLabel for="filter_type" value="Unit Type" />
                            <select
                                id="filter_type"
                                v-model="localFilters.type"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors"
                            >
                                <option value="">All Types</option>
                                <option v-for="t in typeOptions" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>

                        <!-- Status -->
                        <div class="lg:col-span-2">
                            <InputLabel for="filter_is_active" value="Status" />
                            <select
                                id="filter_is_active"
                                v-model="localFilters.isActive"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors"
                            >
                                <option value="">All Statuses</option>
                                <option v-for="s in statusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                            </select>
                        </div>

                        <!-- Staffing Assignment -->
                        <div class="lg:col-span-2">
                            <InputLabel for="filter_staffing" value="Staffing" />
                            <select
                                id="filter_staffing"
                                v-model="localFilters.hasEmployees"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors"
                            >
                                <option value="">All Units</option>
                                <option v-for="opt in employeeAssignmentOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                        </div>

                        <!-- Employee Group Filter -->
                        <div class="lg:col-span-2" v-if="userCategories && userCategories.length > 0">
                            <InputLabel for="filter_category" value="Assigned Staff Group" />
                            <select
                                id="filter_category"
                                v-model="localFilters.user_category_id"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors"
                            >
                                <option value="">All Groups</option>
                                <option v-for="cat in userCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Filter Action Buttons & Clear -->
                    <div class="flex items-center justify-between pt-2">
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            Found <span class="font-semibold text-gray-900 dark:text-white">{{ records.total || 0 }}</span> matching unit{{ records.total === 1 ? '' : 's' }}
                        </div>
                        <div class="inline-flex items-center gap-2">
                            <SecondaryButton type="button" @click="handleReset" class="text-xs">
                                Reset
                            </SecondaryButton>
                            <PrimaryButton type="submit" class="text-xs inline-flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                Apply Filters
                            </PrimaryButton>
                        </div>
                    </div>
                </form>

                <!-- Active Filter Chips Bar -->
                <div v-if="activeFilterChips.length > 0" class="px-5 py-2.5 bg-gray-50/70 dark:bg-gray-800/90 border-t border-gray-100 dark:border-gray-700/60 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Active filters:</span>
                    <div
                        v-for="chip in activeFilterChips"
                        :key="chip.key"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs rounded-full bg-emerald-50 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/50"
                    >
                        <span>{{ chip.label }}</span>
                        <button
                            type="button"
                            @click="removeSingleFilter(chip.key)"
                            class="hover:text-red-500 transition-colors"
                            title="Remove filter"
                        >
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Table Card -->
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-xl overflow-hidden border border-gray-100 dark:border-gray-700/60 transition-colors">
                
                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700/60 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">
                            <tr>
                                <th scope="col" class="px-6 py-3.5 cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" @click="handleSort('id')">
                                    SL 
                                    <span v-if="sort.field === 'id'" class="ml-1">{{ sort.direction === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th scope="col" class="px-6 py-3.5 cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" @click="handleSort('name')">
                                    Name
                                    <span v-if="sort.field === 'name'" class="ml-1">{{ sort.direction === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th scope="col" class="px-6 py-3.5 cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" @click="handleSort('type')">
                                    Type
                                    <span v-if="sort.field === 'type'" class="ml-1">{{ sort.direction === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th scope="col" class="px-6 py-3.5 cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" @click="handleSort('users_count')">
                                    Assigned Staff
                                    <span v-if="sort.field === 'users_count'" class="ml-1">{{ sort.direction === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th scope="col" class="px-6 py-3.5 w-1/3">Description</th>
                                <th scope="col" class="px-6 py-3.5 text-center cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" @click="handleSort('isActive')">
                                    Status
                                    <span v-if="sort.field === 'isActive'" class="ml-1">{{ sort.direction === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th scope="col" class="px-6 py-3.5 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                            <tr v-if="loading" class="bg-white dark:bg-gray-800">
                                <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                    <div class="inline-flex items-center gap-2">
                                        <svg class="animate-spin h-5 w-5 text-emerald-500" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                        </svg>
                                        <span>Loading departments & offices...</span>
                                    </div>
                                </td>
                            </tr>
                            <tr v-else-if="!records.data || records.data.length === 0" class="bg-white dark:bg-gray-800">
                                <td colspan="7" class="px-6 py-14 text-center">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="w-12 h-12 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-gray-400 mb-3">
                                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                            </svg>
                                        </div>
                                        <p class="text-base font-semibold text-gray-800 dark:text-gray-200">No departments or offices found</p>
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
                            <tr v-else v-for="(item, index) in records.data" :key="item.id" class="bg-white dark:bg-gray-800 hover:bg-gray-50/80 dark:hover:bg-gray-700/50 transition-colors">
                                <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ (records.current_page - 1) * records.per_page + index + 1 }}</td>
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-gray-900 dark:text-white">{{ item.name }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        :class="{
                                            'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300': item.type === 'department',
                                            'bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300': item.type === 'office',
                                            'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300': item.type === 'others'
                                        }"
                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold capitalize"
                                    >
                                        {{ item.type }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                        <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                        </svg>
                                        {{ item.users_count ?? 0 }} staff
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-gray-500 dark:text-gray-400 text-xs">{{ item.description || 'No description' }}</td>
                                <td class="px-6 py-4 text-center">
                                    <span :class="item.isActive ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-400'" class="text-xs font-semibold px-2.5 py-0.5 rounded-full">
                                        {{ item.isActive ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="inline-flex items-center gap-2">
                                        <button
                                            @click="openEditModal(item)"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-900/60 rounded-lg transition"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                            Edit
                                        </button>
                                        <button
                                            @click="confirmDelete(item.id)"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 dark:bg-red-950/40 dark:text-red-300 dark:hover:bg-red-900/60 rounded-lg transition"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="p-4 border-t border-gray-100 dark:border-gray-700/60 flex items-center justify-between" v-if="records.last_page > 1">
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        Page <span class="font-semibold text-gray-900 dark:text-white">{{ records.current_page }}</span> of 
                        <span class="font-semibold text-gray-900 dark:text-white">{{ records.last_page }}</span> ({{ records.total }} total units)
                    </span>
                    
                    <div class="inline-flex gap-1">
                        <button
                            @click="changePage(records.current_page - 1)"
                            :disabled="records.current_page === 1"
                            class="px-3 py-1 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed transition"
                        >
                            Previous
                        </button>
                        <button
                            @click="changePage(records.current_page + 1)"
                            :disabled="records.current_page === records.last_page"
                            class="px-3 py-1 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed transition"
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
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-6">
                    {{ isEditing ? 'Edit Record' : 'Add New Record' }}
                </h2>

                <form @submit.prevent="saveRecord" class="space-y-6">
                    <div>
                        <InputLabel for="name" value="Name *" />
                        <TextInput
                            id="name"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="form.name"
                            required
                        />
                    </div>

                    <div>
                        <InputLabel for="type" value="Type *" />
                        <select
                            id="type"
                            v-model="form.type"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-green-500 dark:focus:border-green-600 focus:ring-green-500 dark:focus:ring-green-600 rounded-md shadow-sm"
                            required
                        >
                            <option v-for="type in typeOptions" :value="type.value">{{ type.label }}</option>
                        </select>
                    </div>

                    <div>
                        <InputLabel for="description" value="Description" />
                        <TextInput
                            id="description"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="form.description"
                        />
                    </div>

                    <div class="flex items-center mt-4">
                        <Checkbox id="isActive" v-model:checked="form.isActive" />
                        <label for="isActive" class="ml-2 text-sm text-gray-600 dark:text-gray-400">Active</label>
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
