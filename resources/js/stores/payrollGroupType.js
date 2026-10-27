import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const usePayrollGroupTypeStore = defineStore('payrollGroupType', {
    state: () => ({
        payrollGroupTypes: {
            data: [],
            current_page: 1,
            last_page: 1,
            total: 0,
            links: []
        },
        loading: false,
    }),
    actions: {
        async fetchPayrollGroupTypes(page = 1, filters = {}) {
            this.loading = true;
            try {
                const response = await axios.get(route('payroll-group-types.index'), {
                    params: {
                        page: page,
                        ...filters
                    },
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                this.payrollGroupTypes = response.data;
            } catch (error) {
                console.error("Error fetching payroll group types:", error);
            } finally {
                this.loading = false;
            }
        },

        async createPayrollGroupType(data) {
            try {
                await axios.post(route('payroll-group-types.store'), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Created', 'Group type created successfully.');
                this.fetchPayrollGroupTypes(this.payrollGroupTypes.current_page);
                return true;
            } catch (error) {
                console.error("Error creating payroll group type:", error);
                const adminStore = useAdminStore();
                let errorMsg = 'Error creating group type.';
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

        async updatePayrollGroupType(id, data) {
            try {
                await axios.put(route('payroll-group-types.update', id), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Updated', 'Group type updated successfully.');
                this.fetchPayrollGroupTypes(this.payrollGroupTypes.current_page);
                return true;
            } catch (error) {
                console.error("Error updating payroll group type:", error);
                const adminStore = useAdminStore();
                let errorMsg = 'Error updating group type.';
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

        async deletePayrollGroupType(id) {
            const adminStore = useAdminStore();
            const confirmed = await adminStore.confirmAction(
                'Delete Group Type?',
                'Are you sure you want to delete this group type? This action cannot be undone.'
            );

            if (confirmed) {
                try {
                    await axios.delete(route('payroll-group-types.destroy', id));
                    adminStore.showSuccess('Deleted', 'Group type deleted successfully.');
                    this.fetchPayrollGroupTypes(this.payrollGroupTypes.current_page);
                    return true;
                } catch (error) {
                    console.error("Error deleting payroll group type:", error);
                    const errorMsg = error.response?.data?.message || 'Failed to delete group type due to database constraints.';
                    adminStore.showError('Cannot Delete Group Type', errorMsg);
                    return false;
                }
            }
            return false;
        }
    }
});
