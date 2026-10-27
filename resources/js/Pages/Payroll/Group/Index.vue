<script setup>
import { ref, onMounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { usePayrollGroupStore } from '@/stores/payrollGroup';
import { storeToRefs } from 'pinia';

const groupStore = usePayrollGroupStore();
const { payrollGroups, loading, filters } = storeToRefs(groupStore);

const isModalOpen = ref(false);
const isEditing = ref(false);
const grades = ref([]);
const payrollGroupTypes = ref([]); // For create/edit modal
const allPayrollGroupTypes = ref([]); // For filters

const localFilters = ref({
    grade_id: '',
    payroll_group_type_id: '',
    isActive: ''
});

const applyFilters = () => {
    groupStore.setFilters(localFilters.value);
};

const form = ref({
    id: null,
    grade_id: '',
    payroll_group_type_id: '',
    isActive: true,
});

onMounted(async () => {
    groupStore.fetchPayrollGroups();
    try {
        const response = await axios.get(route('grades.index'), { params: { all: true } });
        grades.value = response.data;
    } catch (error) {
        console.error('Failed to fetch grades:', error);
    }
    
    try {
        const resTypes = await axios.get(route('payroll-group-types.index'), { params: { all: true } });
        allPayrollGroupTypes.value = resTypes.data;
    } catch (e) {
        console.error('Failed to fetch group types for filters:', e);
    }
});

const fetchGroupTypes = async () => {
    if (form.value.grade_id) {
        try {
            const response = await axios.get(route('payroll-group-types.index'), {
                params: {
                    unassigned_for_grade_id: form.value.grade_id,
                    include_type_id: isEditing.value ? form.value.payroll_group_type_id : null,
                    all: true
                }
            });
            payrollGroupTypes.value = response.data;
            // if current type is not in the list, reset it
            if (!payrollGroupTypes.value.find(t => t.id === form.value.payroll_group_type_id)) {
                form.value.payroll_group_type_id = '';
            }
        } catch (error) {
            console.error('Failed to fetch group types:', error);
        }
    } else {
        payrollGroupTypes.value = [];
        form.value.payroll_group_type_id = '';
    }
};

const openCreateModal = () => {
    isEditing.value = false;
    form.value = {
        id: null,
        grade_id: '',
        payroll_group_type_id: '',
        isActive: true,
    };
    fetchGroupTypes();
    isModalOpen.value = true;
};

const openEditModal = (group) => {
    isEditing.value = true;
    form.value = {
        id: group.id,
        grade_id: group.grade_id,
        payroll_group_type_id: group.payroll_group_type_id,
        isActive: Boolean(group.isActive),
    };
    fetchGroupTypes();
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
};

const saveGroup = async () => {
    try {
        if (isEditing.value) {
            await groupStore.updatePayrollGroup(form.value.id, form.value);
        } else {
            await groupStore.createPayrollGroup(form.value);
        }
        closeModal();
    } catch (error) {
        console.error("Save failed:", error);
    }
};

const confirmDelete = async (id) => {
    await groupStore.deletePayrollGroup(id);
};

const changePage = (page) => {
    if (page >= 1 && page <= payrollGroups.value.last_page) {
        groupStore.fetchPayrollGroups(page);
    }
};
</script>

<template>
    <Head title="Designations" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Manage Designations</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg overflow-hidden transition-colors duration-300">
                    
                    <!-- Toolbar & Filters -->
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex justify-end mb-4">
                            <PrimaryButton @click="openCreateModal">
                                Add New Designation
                            </PrimaryButton>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <InputLabel for="filter_grade" value="Grade" />
                                <select id="filter_grade" v-model="localFilters.grade_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-green-500 dark:focus:border-green-600 focus:ring-green-500 dark:focus:ring-green-600 rounded-md shadow-sm">
                                    <option value="">All</option>
                                    <option v-for="grade in grades" :key="grade.id" :value="grade.id">{{ grade.pay_scale?.scale_name }} - {{ grade.grade_name }}</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="filter_type" value="Designation Name" />
                                <select id="filter_type" v-model="localFilters.payroll_group_type_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-green-500 dark:focus:border-green-600 focus:ring-green-500 dark:focus:ring-green-600 rounded-md shadow-sm">
                                    <option value="">All</option>
                                    <option v-for="type in allPayrollGroupTypes" :key="type.id" :value="type.id">{{ type.group_name }}</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="filter_status" value="Status" />
                                <select id="filter_status" v-model="localFilters.isActive" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-green-500 dark:focus:border-green-600 focus:ring-green-500 dark:focus:ring-green-600 rounded-md shadow-sm">
                                    <option value="">All</option>
                                    <option value="true">Active</option>
                                    <option value="false">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-4 flex justify-end">
                            <PrimaryButton @click="applyFilters">View Result</PrimaryButton>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <tr>
                                    <th scope="col" class="px-6 py-3">SL</th>
                                    <th scope="col" class="px-6 py-3">Grade Name</th>
                                    <th scope="col" class="px-6 py-3">Designation Name</th>
                                    <th scope="col" class="px-6 py-3 w-1/3">Description</th>
                                    <th scope="col" class="px-6 py-3 text-center">Status</th>
                                    <th scope="col" class="px-6 py-3 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="loading" class="bg-white dark:bg-gray-800">
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                        Loading...
                                    </td>
                                </tr>
                                <tr v-else-if="!payrollGroups.data || payrollGroups.data.length === 0" class="bg-white dark:bg-gray-800 border-b dark:border-gray-700">
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                        No designations found.
                                    </td>
                                </tr>
                                <tr v-else v-for="(group, index) in payrollGroups.data" :key="group.id" class="bg-white dark:bg-gray-800 border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ (payrollGroups.current_page - 1) * payrollGroups.per_page + index + 1 }}</td>
                                    <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ group.grade?.pay_scale?.scale_name }} - {{ group.grade?.grade_name }}</td>
                                    <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ group.payroll_group_type?.group_name }}</td>
                                    <td class="px-6 py-4">{{ group.payroll_group_type?.group_description }}</td>
                                    <td class="px-6 py-4 text-center">
                                        <span :class="group.isActive ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300'" class="text-xs font-medium px-2.5 py-0.5 rounded-full">
                                            {{ group.isActive ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center space-x-2">
                                        <button @click="openEditModal(group)" class="text-blue-600 dark:text-blue-400 hover:underline">Edit</button>
                                        <button @click="confirmDelete(group.id)" class="text-red-600 dark:text-red-400 hover:underline">Delete</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="p-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between" v-if="payrollGroups.last_page > 1">
                        <span class="text-sm text-gray-700 dark:text-gray-400">
                            Showing <span class="font-semibold text-gray-900 dark:text-white">{{ (payrollGroups.current_page - 1) * 10 + 1 }}</span> to 
                            <span class="font-semibold text-gray-900 dark:text-white">{{ Math.min(payrollGroups.current_page * 10, payrollGroups.total) }}</span> of 
                            <span class="font-semibold text-gray-900 dark:text-white">{{ payrollGroups.total }}</span> Entries
                        </span>
                        
                        <div class="inline-flex mt-2 xs:mt-0">
                            <button @click="changePage(payrollGroups.current_page - 1)" :disabled="payrollGroups.current_page === 1" class="flex items-center justify-center px-3 h-8 text-sm font-medium text-white bg-gray-800 rounded-s hover:bg-gray-900 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white disabled:opacity-50 disabled:cursor-not-allowed">
                                Prev
                            </button>
                            <button @click="changePage(payrollGroups.current_page + 1)" :disabled="payrollGroups.current_page === payrollGroups.last_page" class="flex items-center justify-center px-3 h-8 text-sm font-medium text-white bg-gray-800 border-0 border-s border-gray-700 rounded-e hover:bg-gray-900 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white disabled:opacity-50 disabled:cursor-not-allowed">
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
                    {{ isEditing ? 'Edit Designation' : 'Add New Designation' }}
                </h2>

                <form @submit.prevent="saveGroup" class="space-y-6">
                    <div>
                        <InputLabel for="grade_id" value="Grade *" />
                        <select
                            id="grade_id"
                            v-model="form.grade_id"
                            @change="fetchGroupTypes"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                            required
                        >
                            <option value="" disabled>Select Grade</option>
                            <option v-for="grade in grades" :key="grade.id" :value="grade.id">
                                {{ grade.pay_scale?.scale_name }} - {{ grade.grade_name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <InputLabel for="payroll_group_type_id" value="Designation Type *" />
                        <select
                            id="payroll_group_type_id"
                            v-model="form.payroll_group_type_id"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                            required
                            :disabled="!form.grade_id"
                        >
                            <option value="" disabled>Select Designation Type</option>
                            <option v-for="type in payrollGroupTypes" :key="type.id" :value="type.id">
                                {{ type.group_name }}
                            </option>
                        </select>
                        <p v-if="form.grade_id && payrollGroupTypes.length === 0" class="mt-2 text-sm text-yellow-600">
                            No unassigned designation types available for this grade.
                        </p>
                    </div>

                    <div class="flex items-center mt-4">
                        <Checkbox id="isActive" v-model:checked="form.isActive" />
                        <label for="isActive" class="ml-2 text-sm text-gray-600 dark:text-gray-400">Active</label>
                    </div>

                    <div class="flex justify-end gap-3 mt-6">
                        <SecondaryButton @click="closeModal" type="button">Cancel</SecondaryButton>
                        <PrimaryButton type="submit" :disabled="!form.grade_id || !form.payroll_group_type_id">
                            {{ isEditing ? 'Update' : 'Save' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
