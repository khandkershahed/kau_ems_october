import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const useEmployeeStore = defineStore('employee', {
    state: () => ({
        employees: {
            data: [],
            current_page: 1,
            last_page: 1,
            total: 0,
            links: []
        },
        loading: false,
        filters: {
            search: '',
            employeeCode: '',
            department_office_id: '',
            user_category_id: '',
            pay_scale_id: '',
            grade_id: '',
            payroll_group_id: '',
            jobStatus: '',
            gender: '',
            isActive: '',
            holdIncrement: '',
            maritial_status: '',
            religion: '',
            onLeave: ''
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
        async fetchEmployees(page = 1) {
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

                const response = await axios.get(route('employees.edit'), {
                    params,
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                this.employees = response.data;
            } catch (error) {
                console.error("Error fetching employees:", error);
            } finally {
                this.loading = false;
            }
        },

        async fetchAllFilteredEmployees(customFilters = null, ids = null) {
            try {
                const params = {
                    all: true,
                    sortField: this.sort.field,
                    sortDirection: this.sort.direction
                };

                const activeFilters = customFilters || this.filters;
                Object.keys(activeFilters).forEach(key => {
                    const val = activeFilters[key];
                    if (val !== '' && val !== null && val !== undefined) {
                        params[key] = val;
                    }
                });

                if (ids && ids.length > 0) {
                    params.ids = Array.isArray(ids) ? ids.join(',') : ids;
                }

                const response = await axios.get(route('employees.edit'), {
                    params,
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                return response.data?.data || response.data || [];
            } catch (error) {
                console.error("Error fetching all employees:", error);
                throw error;
            }
        },

        async updateEmployee(id, data) {
            try {
                await axios.put(route('employees.update', id), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Updated', 'Employee updated successfully.');
                this.fetchEmployees(this.employees.current_page);
                return true;
            } catch (error) {
                console.error("Error updating employee:", error);
                const adminStore = useAdminStore();

                let errorMsg = 'Employee update failed.';
                if (error.response && error.response.data) {
                    if (error.response.data.errors) {
                        const firstErrorKey = Object.keys(error.response.data.errors)[0];
                        errorMsg = error.response.data.errors[firstErrorKey][0];
                    } else if (error.response.data.message) {
                        errorMsg = error.response.data.message;
                    }
                }
                adminStore.showError('Error', errorMsg);
                throw error;
            }
        },

        async deleteEmployee(id) {
            const adminStore = useAdminStore();
            const confirmed = await adminStore.confirmAction(
                'Delete Employee?',
                'Are you sure you want to delete this employee? This action cannot be undone.'
            );

            if (confirmed) {
                try {
                    await axios.delete(route('employees.destroy', id));
                    adminStore.showSuccess('Deleted', 'Employee deleted successfully.');
                    this.fetchEmployees(this.employees.current_page);
                    return true;
                } catch (error) {
                    console.error("Error deleting employee:", error);
                    adminStore.showError('Error', error.response?.data?.message || 'Employee deletion failed.');
                    throw error;
                }
            }
            return false;
        },

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters };
            this.fetchEmployees(1);
        },

        resetFilters() {
            this.filters = {
                search: '',
                employeeCode: '',
                department_office_id: '',
                user_category_id: '',
                pay_scale_id: '',
                grade_id: '',
                payroll_group_id: '',
                jobStatus: '',
                gender: '',
                isActive: '',
                holdIncrement: '',
                maritial_status: '',
                religion: '',
                onLeave: ''
            };
            this.fetchEmployees(1);
        },

        setSort(field) {
            if (this.sort.field === field) {
                this.sort.direction = this.sort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                this.sort.field = field;
                this.sort.direction = 'asc';
            }
            this.fetchEmployees(this.employees.current_page);
        },

        async updateEmployeeSalaryCalculations(userId) {
            try {
                const adminStore = useAdminStore();

                let res = await axios.post(route("employees.update.salaryCalc"), {
                    "userId": userId
                })

                if (res.data.status == 500) {
                    const errorText = Array.isArray(res.data.errors) ? res.data.errors.join('\n') : (res.data.errors || 'Condition checks failed.');
                    adminStore.showError('Error', errorText);
                    return false;
                } else {
                    adminStore.showSuccess('Updated', 'Updated salary calculation with updated components.');
                    this.fetchEmployees(this.employees.current_page);
                    return true;
                }

            } catch (errorMsg) {
                console.error("Error updating salary components:", errorMsg);
                const adminStore = useAdminStore();

                const message = errorMsg.response?.data?.message || errorMsg.message || 'Error updating salary components.';
                adminStore.showError('Error', message);
                throw errorMsg;
            }
        }
    }
});
