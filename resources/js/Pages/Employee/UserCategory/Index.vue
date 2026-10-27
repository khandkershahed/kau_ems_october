<script setup>
import { ref, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { useUserCategoryStore } from '@/stores/userCategory';
import { storeToRefs } from 'pinia';

const categoryStore = useUserCategoryStore();
const { records, loading} = storeToRefs(categoryStore);

const isModalOpen = ref(false);
const isEditing = ref(false);
const form = ref({
    id: null,
    name: '',
    description: '',
});

const filters = ref({
    search: '',
});

const fetchFiltered = () => {
    categoryStore.fetchRecords(1, filters.value);
};

onMounted(() => {
    categoryStore.fetchRecords();
});

const openCreateModal = () => {
    isEditing.value = false;
    form.value = {
        id: null,
        name: '',
        description: '',
    };
    isModalOpen.value = true;
};

const openEditModal = (item) => {
    isEditing.value = true;
    form.value = {
        id: item.id,
        name: item.name,
        description: item.description || '',
    };
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
};

const saveRecord = async () => {
    try {
        if (isEditing.value) {
            await categoryStore.updateRecord(form.value.id, form.value);
        } else {
            await categoryStore.createRecord(form.value);
        }
        closeModal();
    } catch (error) {
        console.error("Save failed:", error);
    }
};

const confirmDelete = async (id) => {
    await categoryStore.deleteRecord(id);
};

const changePage = (page) => {
    if (page >= 1 && page <= records.value.last_page) {
        categoryStore.fetchRecords(page, filters.value);
    }
};
</script>

<template>
    <Head title="Employee Categories" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Manage Employee Categories</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg overflow-hidden transition-colors duration-300">
                    
                    <!-- Toolbar -->
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row justify-between items-center gap-4">
                        <!-- Filters -->
                        <div class="flex flex-col sm:flex-row gap-4 w-full sm:w-auto">
                            <TextInput
                                v-model="filters.search"
                                type="text"
                                placeholder="Search by name or description..."
                                class="w-full sm:w-64"
                                @keyup.enter="fetchFiltered"
                            />
                            
                            <PrimaryButton @click="fetchFiltered" class="justify-center">
                                View Result
                            </PrimaryButton>
                        </div>
                        
                        <PrimaryButton @click="openCreateModal" class="shrink-0">
                            Add New
                        </PrimaryButton>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <tr>
                                    <th scope="col" class="px-6 py-3">SL</th>
                                    <th scope="col" class="px-6 py-3">Name</th>
                                    <th scope="col" class="px-6 py-3 w-1/2">Description</th>
                                    <th scope="col" class="px-6 py-3 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="loading" class="bg-white dark:bg-gray-800">
                                    <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                                        Loading...
                                    </td>
                                </tr>
                                <tr v-else-if="!records.data || records.data.length === 0" class="bg-white dark:bg-gray-800 border-b dark:border-gray-700">
                                    <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                                        No records found.
                                    </td>
                                </tr>
                                <tr v-else v-for="(item, index) in records.data" :key="item.id" class="bg-white dark:bg-gray-800 border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ (records.current_page - 1) * records.per_page + index + 1 }}</td>
                                    <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ item.name }}</td>
                                    <td class="px-6 py-4">{{ item.description }}</td>
                                    <td class="px-6 py-4 text-center space-x-2">
                                        <button @click="openEditModal(item)" class="text-blue-600 dark:text-blue-400 hover:underline">Edit</button>
                                        <button @click="confirmDelete(item.id)" class="text-red-600 dark:text-red-400 hover:underline">Delete</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="p-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between" v-if="records.last_page > 1">
                        <span class="text-sm text-gray-700 dark:text-gray-400">
                            Showing <span class="font-semibold text-gray-900 dark:text-white">{{ (records.current_page - 1) * 10 + 1 }}</span> to 
                            <span class="font-semibold text-gray-900 dark:text-white">{{ Math.min(records.current_page * 10, records.total) }}</span> of 
                            <span class="font-semibold text-gray-900 dark:text-white">{{ records.total }}</span> Entries
                        </span>
                        
                        <div class="inline-flex mt-2 xs:mt-0">
                            <button @click="changePage(records.current_page - 1)" :disabled="records.current_page === 1" class="flex items-center justify-center px-3 h-8 text-sm font-medium text-white bg-gray-800 rounded-s hover:bg-gray-900 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white disabled:opacity-50 disabled:cursor-not-allowed">
                                Prev
                            </button>
                            <button @click="changePage(records.current_page + 1)" :disabled="records.current_page === records.last_page" class="flex items-center justify-center px-3 h-8 text-sm font-medium text-white bg-gray-800 border-0 border-s border-gray-700 rounded-e hover:bg-gray-900 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white disabled:opacity-50 disabled:cursor-not-allowed">
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
                        <InputLabel for="description" value="Description" />
                        <TextInput
                            id="description"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="form.description"
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
