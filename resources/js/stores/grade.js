import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const useGradeStore = defineStore('grade', {
    state: () => ({
        grades: {
            data: [],
            current_page: 1,
            last_page: 1,
            total: 0,
            links: []
        },
        loading: false,
        filters: {
            search: '',
            pay_scale_id: '',
            isActive: '',
            min: '',
            max: '',
            hasPayrollGroups: '',
            payroll_group_type_id: '',
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
        async fetchGrades(page = 1) {
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

                const response = await axios.get(route('grades.index'), {
                    params,
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                this.grades = response.data;
            } catch (error) {
                console.error("Error fetching grades:", error);
            } finally {
                this.loading = false;
            }
        },

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters };
            this.fetchGrades(1);
        },

        resetFilters() {
            this.filters = {
                search: '',
                pay_scale_id: '',
                isActive: '',
                min: '',
                max: '',
                hasPayrollGroups: '',
                payroll_group_type_id: '',
            };
            this.fetchGrades(1);
        },

        setSort(field) {
            if (this.sort.field === field) {
                this.sort.direction = this.sort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                this.sort.field = field;
                this.sort.direction = 'asc';
            }
            this.fetchGrades(this.grades.current_page);
        },

        async createGrade(data) {
            try {
                await axios.post(route('grades.store'), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Created', 'Grade created successfully.');
                this.fetchGrades(this.grades.current_page);
                return true;
            } catch (error) {
                console.error("Error creating grade:", error);
                throw error;
            }
        },

        async updateGrade(id, data) {
            try {
                await axios.put(route('grades.update', id), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Updated', 'Grade updated successfully.');
                this.fetchGrades(this.grades.current_page);
                return true;
            } catch (error) {
                console.error("Error updating grade:", error);
                throw error;
            }
        },

        async deleteGrade(id) {
            const adminStore = useAdminStore();
            const confirmed = await adminStore.confirmAction(
                'Delete Grade?',
                'Are you sure you want to delete this grade? This action cannot be undone.'
            );

            if (confirmed) {
                try {
                    await axios.delete(route('grades.destroy', id));
                    adminStore.showSuccess('Deleted', 'Grade deleted successfully.');
                    this.fetchGrades(this.grades.current_page);
                    return true;
                } catch (error) {
                    console.error("Error deleting grade:", error);
                    const errorMsg = error.response?.data?.message || 'Failed to delete grade due to database constraints.';
                    adminStore.showError('Cannot Delete Grade', errorMsg);
                    return false;
                }
            }
            return false;
        }
    }
});
