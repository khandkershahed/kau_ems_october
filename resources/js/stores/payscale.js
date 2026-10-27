import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const usePayScaleStore = defineStore('payscale', {
    state: () => ({
        payScales: {
            data: [],
            current_page: 1,
            last_page: 1,
            total: 0,
            links: []
        },
        loading: false,
        filters: {
            search: '',
            isActive: '',
            hasGrades: '',
            hasEmployees: '',
        },
        sort: {
            field: 'id',
            direction: 'desc',
        }
    }),
    getters: {
        activeFilterCount: (state) => {
            let count = 0;
            Object.keys(state.filters).forEach(k => {
                if (state.filters[k] !== '' && state.filters[k] !== null && state.filters[k] !== undefined) {
                    count++;
                }
            });
            return count;
        }
    },
    actions: {
        async fetchPayScales(page = 1) {
            this.loading = true;
            try {
                const params = {
                    page: page,
                    sortField: this.sort.field,
                    sortDirection: this.sort.direction,
                };

                Object.keys(this.filters).forEach(key => {
                    const val = this.filters[key];
                    if (val !== '' && val !== null && val !== undefined) {
                        params[key] = val;
                    }
                });

                const response = await axios.get(route('pay-scales.index'), {
                    params,
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                this.payScales = response.data;
            } catch (error) {
                console.error("Error fetching pay scales:", error);
                const adminStore = useAdminStore();
                adminStore.showError('Error', 'Failed to fetch pay scales.');
            } finally {
                this.loading = false;
            }
        },

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters };
            this.fetchPayScales(1);
        },

        resetFilters() {
            this.filters = {
                search: '',
                isActive: '',
                hasGrades: '',
                hasEmployees: '',
            };
            this.fetchPayScales(1);
        },

        setSort(field) {
            if (this.sort.field === field) {
                this.sort.direction = this.sort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                this.sort.field = field;
                this.sort.direction = 'asc';
            }
            this.fetchPayScales(this.payScales.current_page);
        },

        async createPayScale(data) {
            try {
                await axios.post(route('pay-scales.store'), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Created', 'Pay scale created successfully.');
                this.fetchPayScales(this.payScales.current_page);
                return true;
            } catch (error) {
                console.error("Error creating pay scale:", error);
                const adminStore = useAdminStore();
                let errorMsg = 'Error creating pay scale.';
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

        async updatePayScale(id, data) {
            try {
                await axios.put(route('pay-scales.update', id), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Updated', 'Pay scale updated successfully.');
                this.fetchPayScales(this.payScales.current_page);
                return true;
            } catch (error) {
                console.error("Error updating pay scale:", error);
                const adminStore = useAdminStore();
                let errorMsg = 'Error updating pay scale.';
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

        async deletePayScale(id) {
            const adminStore = useAdminStore();
            const confirmed = await adminStore.confirmAction(
                'Delete Pay Scale?',
                'Are you sure you want to delete this pay scale? This action cannot be undone.'
            );

            if (confirmed) {
                try {
                    await axios.delete(route('pay-scales.destroy', id));
                    adminStore.showSuccess('Deleted', 'Pay scale deleted successfully.');
                    this.fetchPayScales(this.payScales.current_page);
                    return true;
                } catch (error) {
                    console.error("Error deleting pay scale:", error);
                    const errorMsg = error.response?.data?.message || 'Failed to delete pay scale due to database constraints.';
                    adminStore.showError('Cannot Delete Pay Scale', errorMsg);
                    return false;
                }
            }
            return false;
        }
    }
});
