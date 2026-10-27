import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const useUserCategoryStore = defineStore('userCategory', {
    state: () => ({
        records: {
            data: [],
            current_page: 1,
            last_page: 1,
            total: 0,
            links: []
        },
        loading: false,
    }),
    actions: {
        async fetchRecords(page = 1, filters = {}) {
            this.loading = true;
            try {
                const response = await axios.get(route('user-categories.index'), {
                    params: {
                        page: page,
                        ...filters,
                    },
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                this.records = response.data;
            } catch (error) {
                console.error("Error fetching records:", error);
            } finally {
                this.loading = false;
            }
        },

        async createRecord(data) {
            try {
                await axios.post(route('user-categories.store'), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Created', 'Employee group created successfully.');
                this.fetchRecords(this.records.current_page);
                return true;
            } catch (error) {
                console.error("Error creating record:", error);
                const adminStore = useAdminStore();
                let errorMsg = 'Error creating employee group.';
                if (error.response?.data?.errors) {
                    const firstKey = Object.keys(error.response.data.errors)[0];
                    errorMsg = error.response.data.errors[firstKey][0];
                } else if (error.response?.data?.message) {
                    errorMsg = error.response.data.message;
                }
                adminStore.showError('Error', errorMsg);
                throw error;
            }
        },

        async updateRecord(id, data) {
            try {
                await axios.put(route('user-categories.update', id), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Updated', 'Employee group updated successfully.');
                this.fetchRecords(this.records.current_page);
                return true;
            } catch (error) {
                console.error("Error updating record:", error);
                const adminStore = useAdminStore();
                let errorMsg = 'Error updating employee group.';
                if (error.response?.data?.errors) {
                    const firstKey = Object.keys(error.response.data.errors)[0];
                    errorMsg = error.response.data.errors[firstKey][0];
                } else if (error.response?.data?.message) {
                    errorMsg = error.response.data.message;
                }
                adminStore.showError('Error', errorMsg);
                throw error;
            }
        },

        async deleteRecord(id) {
            const adminStore = useAdminStore();
            const confirmed = await adminStore.confirmAction(
                'Delete Employee Group?',
                'Are you sure you want to delete this employee group? This action cannot be undone.'
            );

            if (confirmed) {
                try {
                    await axios.delete(route('user-categories.destroy', id));
                    adminStore.showSuccess('Deleted', 'Employee group deleted successfully.');
                    this.fetchRecords(this.records.current_page);
                    return true;
                } catch (error) {
                    console.error("Error deleting employee group:", error);
                    const errorMsg = error.response?.data?.message || 'Failed to delete employee group due to database constraints.';
                    adminStore.showError('Cannot Delete Employee Group', errorMsg);
                    return false;
                }
            }
            return false;
        }
    }
});
