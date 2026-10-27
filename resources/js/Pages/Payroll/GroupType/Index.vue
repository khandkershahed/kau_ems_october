<script setup>
import { ref, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { usePayrollGroupTypeStore } from '@/stores/payrollGroupType';
import { storeToRefs } from 'pinia';
import axios from 'axios';

const payrollGroupTypeStore = usePayrollGroupTypeStore();
const { payrollGroupTypes, loading} = storeToRefs(payrollGroupTypeStore);

const isModalOpen = ref(false);
const isEditing = ref(false);
const form = ref({
    id: null,
    group_name: '',
    group_description: '',
});

const filters = ref({
    search: '',
    grade_id: '',
});
const grades = ref([]);

onMounted(async () => {
    payrollGroupTypeStore.fetchPayrollGroupTypes();
    try {
        const response = await axios.get(route('grades.index'), { params: { all: 1 } });
        grades.value = response.data;
    } catch (error) {
        console.error("Error fetching grades:", error);
    }
});

const fetchFiltered = () => {
    payrollGroupTypeStore.fetchPayrollGroupTypes(1, filters.value);
};

const openCreateModal = () => {
    isEditing.value = false;
    form.value = {
        id: null,
        group_name: '',
        group_description: '',
    };
    isModalOpen.value = true;
};

const openEditModal = (type) => {
    isEditing.value = true;
    form.value = {
        id: type.id,
        group_name: type.group_name,
        group_description: type.group_description || '',
    };
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
};

const saveGroupType = async () => {
    try {
        if (isEditing.value) {
            await payrollGroupTypeStore.updatePayrollGroupType(form.value.id, form.value);
        } else {
            await payrollGroupTypeStore.createPayrollGroupType(form.value);
        }
        closeModal();
    } catch (error) {
        console.error("Save failed:", error);
    }
};

const confirmDelete = async (id) => {
    await payrollGroupTypeStore.deletePayrollGroupType(id);
};

const changePage = (page) => {
    if (page >= 1 && page <= payrollGroupTypes.value.last_page) {
        payrollGroupTypeStore.fetchPayrollGroupTypes(page, filters.value);
    }
};
</script>

<template>
    <Head title="Code Designation Types" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Manage Code Designation Types</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg overflow-hidden transition-colors duration-300">
                    
                    <!-- Toolbar and Filters -->
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700 space-y-4">
                        <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                            <div class="flex-1 flex flex-col sm:flex-row gap-4 items-end">
                                <div class="w-full sm:w-64">
                                    <InputLabel for="search" value="Search" />
                                    <TextInput
                                        id="search"
                                        type="text"
                                        class="mt-1 block w-full"
                                        v-model="filters.search"
                                        placeholder="Search by name or description..."
                                    />
                                </div>
                                <div class="w-full sm:w-64">
                                    <InputLabel for="grade" value="Assigned to Grade" />
                                    <select
                                        id="grade"
                                        class="mt-1 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm w-full"
                                        v-model="filters.grade_id"
                                    >
                                        <option value="">All Grades</option>
                                        <option v-for="grade in grades" :key="grade.id" :value="grade.id">
                                            {{ grade.grade_name }}
                                        </option>
                                    </select>
                                </div>
                                <PrimaryButton @click="fetchFiltered" class="mb-1">
                                    View Result
                                </PrimaryButton>
                            </div>
                            <PrimaryButton @click="openCreateModal" class="mb-1">
                                Add New Code Designation Type
                            </PrimaryButton>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <tr>
                                    <th scope="col" class="px-6 py-3">SL</th>
                                    <th scope="col" class="px-6 py-3">Group Name</th>
                                    <th scope="col" class="px-6 py-3">Description</th>
                                    <th scope="col" class="px-6 py-3 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="loading" class="bg-white dark:bg-gray-800">
                                    <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                                        Loading...
                                    </td>
                                </tr>
                                <tr v-else-if="!payrollGroupTypes.data || payrollGroupTypes.data.length === 0" class="bg-white dark:bg-gray-800 border-b dark:border-gray-700">
                                    <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                                        No designation types found.
                                    </td>
                                </tr>
                                <tr v-else v-for="(type, index) in payrollGroupTypes.data" :key="type.id" class="bg-white dark:bg-gray-800 border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ (payrollGroupTypes.current_page - 1) * payrollGroupTypes.per_page + index + 1 }}</td>
                                    <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ type.group_name }}</td>
                                    <td class="px-6 py-4">{{ type.group_description || '-' }}</td>
                                    <td class="px-6 py-4 text-center space-x-2">
                                        <button @click="openEditModal(type)" class="text-blue-600 dark:text-blue-400 hover:underline">Edit</button>
                                        <button @click="confirmDelete(type.id)" class="text-red-600 dark:text-red-400 hover:underline">Delete</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="p-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between" v-if="payrollGroupTypes.last_page > 1">
                        <span class="text-sm text-gray-700 dark:text-gray-400">
                            Showing <span class="font-semibold text-gray-900 dark:text-white">{{ (payrollGroupTypes.current_page - 1) * 10 + 1 }}</span> to 
                            <span class="font-semibold text-gray-900 dark:text-white">{{ Math.min(payrollGroupTypes.current_page * 10, payrollGroupTypes.total) }}</span> of 
                            <span class="font-semibold text-gray-900 dark:text-white">{{ payrollGroupTypes.total }}</span> Entries
                        </span>
                        
                        <div class="inline-flex mt-2 xs:mt-0">
                            <button @click="changePage(payrollGroupTypes.current_page - 1)" :disabled="payrollGroupTypes.current_page === 1" class="flex items-center justify-center px-3 h-8 text-sm font-medium text-white bg-gray-800 rounded-s hover:bg-gray-900 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white disabled:opacity-50 disabled:cursor-not-allowed">
                                Prev
                            </button>
                            <button @click="changePage(payrollGroupTypes.current_page + 1)" :disabled="payrollGroupTypes.current_page === payrollGroupTypes.last_page" class="flex items-center justify-center px-3 h-8 text-sm font-medium text-white bg-gray-800 border-0 border-s border-gray-700 rounded-e hover:bg-gray-900 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white disabled:opacity-50 disabled:cursor-not-allowed">
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
                    {{ isEditing ? 'Edit Code Designation Type' : 'Add New Code Designation Type' }}
                </h2>

                <form @submit.prevent="saveGroupType" class="space-y-6">
                    <div>
                        <InputLabel for="group_name" value="Group Name *" />
                        <TextInput
                            id="group_name"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="form.group_name"
                            required
                        />
                    </div>

                    <div>
                        <InputLabel for="group_description" value="Description" />
                        <TextInput
                            id="group_description"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="form.group_description"
                        />
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
