import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const usePayrollGroupStore = defineStore('payrollGroup', {
    state: () => ({
        payrollGroups: {
            data: [],
            current_page: 1,
            last_page: 1,
            total: 0,
            links: []
        },
        loading: false,
        filters: {
            grade_id: '',
            payroll_group_type_id: '',
            isActive: ''
        },
    }),
    actions: {
        async fetchPayrollGroups(page = 1) {
            this.loading = true;
            try {
                const response = await axios.get(route('payroll-groups.index'), {
                    params: {
                        page: page,
                        ...this.filters
                    },
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                this.payrollGroups = response.data;
            } catch (error) {
                console.error("Error fetching payroll groups:", error);
            } finally {
                this.loading = false;
            }
        },

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters };
            this.fetchPayrollGroups(1);
        },

        async createPayrollGroup(data) {
            try {
                await axios.post(route('payroll-groups.store'), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Created', 'Payroll group created successfully.');
                this.fetchPayrollGroups(this.payrollGroups.current_page);
                return true;
            } catch (error) {
                console.error("Error creating payroll group:", error);
                throw error;
            }
        },

        async updatePayrollGroup(id, data) {
            try {
                await axios.put(route('payroll-groups.update', id), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Updated', 'Payroll group updated successfully.');
                this.fetchPayrollGroups(this.payrollGroups.current_page);
                return true;
            } catch (error) {
                console.error("Error updating payroll group:", error);
                throw error;
            }
        },

        async deletePayrollGroup(id) {
            const adminStore = useAdminStore();
            const confirmed = await adminStore.confirmAction(
                'Delete Payroll Group?',
                'Are you sure you want to delete this payroll group? This action cannot be undone.'
            );

            if (confirmed) {
                try {
                    await axios.delete(route('payroll-groups.destroy', id));
                    adminStore.showSuccess('Deleted', 'Payroll group deleted successfully.');
                    this.fetchPayrollGroups(this.payrollGroups.current_page);
                    return true;
                } catch (error) {
                    console.error("Error deleting payroll group:", error);
                    const errorMsg = error.response?.data?.message || 'Failed to delete payroll group due to database constraints.';
                    adminStore.showError('Cannot Delete Payroll Group', errorMsg);
                    return false;
                }
            }
            return false;
        }
    }
});
