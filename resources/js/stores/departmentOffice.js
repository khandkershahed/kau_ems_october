import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const useDepartmentOfficeStore = defineStore('departmentOffice', {
    state: () => ({
        records: {
            data: [],
            current_page: 1,
            last_page: 1,
            total: 0,
            links: []
        },
        loading: false,
        filters: {
            search: '',
            type: '',
            isActive: '',
            hasEmployees: '',
            user_category_id: '',
        },
        sort: {
            field: 'id',
            direction: 'desc'
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
        async fetchRecords(page = 1) {
            this.loading = true;
            try {
                const params = {
                    page: page,
                    sortField: this.sort.field,
                    sortDirection: this.sort.direction
                };

                Object.keys(this.filters).forEach(key => {
                    const val = this.filters[key];
                    if (val !== '' && val !== null && val !== undefined) {
                        params[key] = val;
                    }
                });

                const response = await axios.get(route('department-offices.index'), {
                    params,
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                this.records = response.data;
            } catch (error) {
                console.error("Error fetching records:", error);
                const adminStore = useAdminStore();
                adminStore.showError('Error', 'Failed to fetch records.');
            } finally {
                this.loading = false;
            }
        },

        async createRecord(data) {
            try {
                await axios.post(route('department-offices.store'), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Created', 'Record created successfully.');
                this.fetchRecords(this.records.current_page);
                return true;
            } catch (error) {
                console.error("Error creating record:", error);
                const adminStore = useAdminStore();
                let errorMsg = 'Error creating record.';
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
                await axios.put(route('department-offices.update', id), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Updated', 'Record updated successfully.');
                this.fetchRecords(this.records.current_page);
                return true;
            } catch (error) {
                console.error("Error updating record:", error);
                const adminStore = useAdminStore();
                let errorMsg = 'Error updating record.';
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
                'Delete Record?',
                'Are you sure you want to delete this record? This action cannot be undone.'
            );

            if (confirmed) {
                try {
                    await axios.delete(route('department-offices.destroy', id));
                    adminStore.showSuccess('Deleted', 'Record deleted successfully.');
                    this.fetchRecords(this.records.current_page);
                    return true;
                } catch (error) {
                    console.error("Error deleting record:", error);
                    const errorMsg = error.response?.data?.message || 'Failed to delete record due to database constraints.';
                    adminStore.showError('Cannot Delete Record', errorMsg);
                    return false;
                }
            }
            return false;
        },

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters };
            this.fetchRecords(1);
        },

        resetFilters() {
            this.filters = {
                search: '',
                type: '',
                isActive: '',
                hasEmployees: '',
                user_category_id: '',
            };
            this.fetchRecords(1);
        },

        setSort(field) {
            if (this.sort.field === field) {
                this.sort.direction = this.sort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                this.sort.field = field;
                this.sort.direction = 'asc';
            }
            this.fetchRecords(this.records.current_page);
        }
    }
});
