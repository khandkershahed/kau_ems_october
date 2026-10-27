import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const usePayrollComponentTypeStore = defineStore('payrollComponentType', {
    state: () => ({
        payrollComponentTypes: {
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
            usage: '',
            component_type: '',
            pay_scale_id: '',
        },
        sort: {
            field: 'id',
            direction: 'desc',
        },
    }),
    getters: {
        activeFilterCount: (state) => {
            let count = 0;
            if (state.filters.search) count++;
            if (state.filters.usage) count++;
            if (state.filters.component_type) count++;
            if (state.filters.pay_scale_id) count++;
            return count;
        },
    },
    actions: {
        async fetchPayrollComponentTypes(page = 1) {
            this.loading = true;
            try {
                const response = await axios.get(route('payroll-component-types.index'), {
                    params: {
                        page: page,
                        ...this.filters,
                        sort_field: this.sort.field,
                        sort_direction: this.sort.direction,
                    },
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                this.payrollComponentTypes = response.data;
            } catch (error) {
                console.error("Error fetching payroll component types:", error);
                const adminStore = useAdminStore();
                adminStore.showError('Error', 'Failed to fetch payroll component types.');
            } finally {
                this.loading = false;
            }
        },

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters };
            this.fetchPayrollComponentTypes(1);
        },

        resetFilters() {
            this.filters = {
                search: '',
                usage: '',
                component_type: '',
                pay_scale_id: '',
            };
            this.fetchPayrollComponentTypes(1);
        },

        setSort(field) {
            if (this.sort.field === field) {
                this.sort.direction = this.sort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                this.sort.field = field;
                this.sort.direction = 'asc';
            }
            this.fetchPayrollComponentTypes(1);
        },

        async createPayrollComponentType(data) {
            try {
                await axios.post(route('payroll-component-types.store'), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Created', 'Component type created successfully.');
                this.fetchPayrollComponentTypes(this.payrollComponentTypes.current_page);
                return true;
            } catch (error) {
                console.error("Error creating payroll component type:", error);
                const adminStore = useAdminStore();
                let errorMsg = 'Error creating payroll component type.';
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

        async updatePayrollComponentType(id, data) {
            try {
                await axios.put(route('payroll-component-types.update', id), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Updated', 'Component type updated successfully.');
                this.fetchPayrollComponentTypes(this.payrollComponentTypes.current_page);
                return true;
            } catch (error) {
                console.error("Error updating payroll component type:", error);
                const adminStore = useAdminStore();
                let errorMsg = 'Error updating payroll component type.';
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

        async deletePayrollComponentType(id) {
            const adminStore = useAdminStore();
            const confirmed = await adminStore.confirmAction(
                'Delete Component Type?',
                'Are you sure you want to delete this component type? This action cannot be undone.'
            );

            if (confirmed) {
                try {
                    await axios.delete(route('payroll-component-types.destroy', id));
                    adminStore.showSuccess('Deleted', 'Component type deleted successfully.');
                    this.fetchPayrollComponentTypes(this.payrollComponentTypes.current_page);
                    return true;
                } catch (error) {
                    console.error("Error deleting payroll component type:", error);
                    const errorMsg = error.response?.data?.message || 'Failed to delete payroll component type due to database constraints.';
                    adminStore.showError('Cannot Delete Component Type', errorMsg);
                    return false;
                }
            }
            return false;
        }
    }
});
