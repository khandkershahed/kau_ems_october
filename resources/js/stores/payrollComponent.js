import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const usePayrollComponentStore = defineStore('payrollComponent', {
    state: () => ({
        payrollComponents: {
            data: [],
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 0,
            links: []
        },
        loading: false,
        filters: {
            search: '',
            pay_scale_id: '',
            grade_id: '',
            payroll_group_type_id: '',
            payroll_group_id: '',
            payroll_component_type_id: '',
            component_type: '',
            calcType: '',
            min_amount: '',
            max_amount: '',
            is_taxable: '',
            isActive: ''
        },
        sort: {
            field: 'id',
            direction: 'desc'
        }
    }),
    getters: {
        activeFilterCount: (state) => {
            let count = 0;
            if (state.filters.search) count++;
            if (state.filters.pay_scale_id) count++;
            if (state.filters.grade_id) count++;
            if (state.filters.payroll_group_type_id) count++;
            if (state.filters.payroll_group_id) count++;
            if (state.filters.payroll_component_type_id) count++;
            if (state.filters.component_type) count++;
            if (state.filters.calcType) count++;
            if (state.filters.min_amount !== '') count++;
            if (state.filters.max_amount !== '') count++;
            if (state.filters.is_taxable !== '') count++;
            if (state.filters.isActive !== '') count++;
            return count;
        }
    },
    actions: {
        async fetchPayrollComponents(page = 1) {
            this.loading = true;
            try {
                const response = await axios.get(route('payroll-components.index'), {
                    params: {
                        page: page,
                        ...this.filters,
                        sort_field: this.sort.field,
                        sort_direction: this.sort.direction
                    },
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                this.payrollComponents = response.data;
            } catch (error) {
                console.error("Error fetching payroll components:", error);
                const adminStore = useAdminStore();
                adminStore.showError('Error', 'Failed to fetch payroll components.');
            } finally {
                this.loading = false;
            }
        },

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters };
            this.fetchPayrollComponents(1);
        },

        resetFilters() {
            this.filters = {
                search: '',
                pay_scale_id: '',
                grade_id: '',
                payroll_group_type_id: '',
                payroll_group_id: '',
                payroll_component_type_id: '',
                component_type: '',
                calcType: '',
                min_amount: '',
                max_amount: '',
                is_taxable: '',
                isActive: ''
            };
            this.fetchPayrollComponents(1);
        },

        setSort(field) {
            if (this.sort.field === field) {
                this.sort.direction = this.sort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                this.sort.field = field;
                this.sort.direction = 'asc';
            }
            this.fetchPayrollComponents(1);
        },

        async createPayrollComponent(data) {
            try {
                await axios.post(route('payroll-components.store'), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Created', 'Payroll component created successfully.');
                this.fetchPayrollComponents(this.payrollComponents.current_page);
                return true;
            } catch (error) {
                console.error("Error creating payroll component:", error);
                const adminStore = useAdminStore();
                let errorMsg = 'Error creating payroll component.';
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

        async updatePayrollComponent(id, data) {
            try {
                await axios.put(route('payroll-components.update', id), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Updated', 'Payroll component updated successfully.');
                this.fetchPayrollComponents(this.payrollComponents.current_page);
                return true;
            } catch (error) {
                console.error("Error updating payroll component:", error);
                const adminStore = useAdminStore();
                let errorMsg = 'Error updating payroll component.';
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

        async deletePayrollComponent(id) {
            const adminStore = useAdminStore();
            const confirmed = await adminStore.confirmAction(
                'Delete Payroll Component?',
                'Are you sure you want to delete this payroll component? This action cannot be undone.'
            );

            if (confirmed) {
                try {
                    await axios.delete(route('payroll-components.destroy', id));
                    adminStore.showSuccess('Deleted', 'Payroll component deleted successfully.');
                    this.fetchPayrollComponents(this.payrollComponents.current_page);
                    return true;
                } catch (error) {
                    console.error("Error deleting payroll component:", error);
                    const errorMsg = error.response?.data?.message || 'Failed to delete payroll component due to database constraints.';
                    adminStore.showError('Cannot Delete Payroll Component', errorMsg);
                    return false;
                }
            }
            return false;
        }
    }
});
