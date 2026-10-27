import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const useSalarySetupStore = defineStore('salarySetup', {
    state: () => ({
        employees: {
            data: [],
            current_page: 1,
            last_page: 1,
            total: 0,
            from: 0,
            to: 0,
            per_page: 10,
            links: []
        },
        loading: false,
        calculatingId: null,
        sendingReviewId: null,
        bulkSendingReview: false,
        selectedUserIds: [],
        selectedEmployee: null,
        detailsLoading: false,
        filters: {
            search: '',
            employeeCode: '',
            user_category_id: '',
            pay_scale_id: '',
            grade_id: '',
            gender: '',
            isActive: '',
            payroll_group_id: '',
            payroll_group_type_id: '',
            payroll_component_type_id: '',
            joining_date: '',
            religion: '',
            salary_month: new Date().getMonth() + 1,
            salary_year: new Date().getFullYear()
        },
        sort: {
            field: 'id',
            direction: 'desc'
        }
    }),

    getters: {
        activeFilterCount: (state) => {
            let count = 0;
            const f = state.filters;
            if (f.search) count++;
            if (f.employeeCode) count++;
            if (f.user_category_id) count++;
            if (f.pay_scale_id) count++;
            if (f.grade_id) count++;
            if (f.gender) count++;
            if (f.isActive !== '') count++;
            if (f.payroll_group_id) count++;
            if (f.payroll_group_type_id) count++;
            if (f.payroll_component_type_id) count++;
            if (f.joining_date) count++;
            if (f.religion) count++;
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

                // Add active filters only
                Object.keys(this.filters).forEach((key) => {
                    const val = this.filters[key];
                    if (val !== '' && val !== null && val !== undefined) {
                        params[key] = val;
                    }
                });

                const response = await axios.get(route('salary-setup.index'), {
                    params,
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                this.employees = response.data;
            } catch (error) {
                console.error("Error fetching salary setup employees:", error);
                const adminStore = useAdminStore();
                adminStore.showError('Error', 'Failed to load employee salary setup records.');
            } finally {
                this.loading = false;
            }
        },

        setFilters(newFilters) {
            this.filters = { ...this.filters, ...newFilters };
            return this.fetchEmployees(1);
        },

        resetFilters() {
            this.filters = {
                search: '',
                employeeCode: '',
                user_category_id: '',
                pay_scale_id: '',
                grade_id: '',
                gender: '',
                isActive: '',
                payroll_group_id: '',
                payroll_group_type_id: '',
                payroll_component_type_id: '',
                joining_date: '',
                religion: '',
                salary_month: this.filters.salary_month,
                salary_year: this.filters.salary_year
            };
            return this.fetchEmployees(1);
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

        async fetchEmployeeDetails(userId) {
            this.detailsLoading = true;
            try {
                const response = await axios.get(route('salary-setup.show', userId), { params: { salary_month: this.filters.salary_month, salary_year: this.filters.salary_year } });
                const data = response.data;
                if (!data || !data.id) {
                    throw new Error('Employee record not found or invalid response.');
                }
                if (data && data.employee_info) {
                    const parseArray = (raw) => {
                        if (!raw) return [];
                        if (Array.isArray(raw)) return raw;
                        try {
                            const parsed = JSON.parse(raw);
                            return Array.isArray(parsed) ? parsed : [];
                        } catch {
                            return [];
                        }
                    };
                    data.employee_info.additionObjects = parseArray(data.employee_info.additionObjects);
                    data.employee_info.substractionObjects = parseArray(data.employee_info.substractionObjects);
                }
                this.selectedEmployee = data;
                return data;
            } catch (error) {
                console.error("Error fetching employee details:", error);
                this.selectedEmployee = null;
                const adminStore = useAdminStore();
                adminStore.showError('Error', 'Failed to fetch employee salary setup details.');
                return null;
            } finally {
                this.detailsLoading = false;
            }
        },

        clearSelectedEmployee() {
            this.selectedEmployee = null;
            this.detailsLoading = false;
        },

        async recalculateSalary(userId) {
            this.calculatingId = userId;
            const adminStore = useAdminStore();
            try {
                const res = await axios.post(route("employees.update.salaryCalc"), {
                    userId: userId, mode: 'preview', salary_year: this.filters.salary_year, salary_month: this.filters.salary_month
                });

                if (res.data.status !== 200 || !res.data.preview_only) {
                    adminStore.showError('Preview Failed', res.data.errors || 'Calculation failed.');
                    return false;
                }
                // Recalculating defaults is read-only unless the user explicitly
                // approves replacing customised components with an audit reason.
                return res.data;
            } catch (error) {
                console.error("Error calculating salary:", error);
                adminStore.showError('Error', (Array.isArray(error.response?.data?.errors) ? error.response.data.errors.join(' | ') : error.response?.data?.message) || 'Error occurred during salary calculation.');
                return false;
            } finally {
                this.calculatingId = null;
            }
        },

        async applyDefaultSalary(userId, reason) {
            this.calculatingId = userId;
            try {
                const res = await axios.post(route('employees.update.salaryCalc'), {
                    userId, mode: 'apply', confirm_overwrite: true, reason, salary_year: this.filters.salary_year, salary_month: this.filters.salary_month
                });
                if (res.data.status !== 200) throw new Error('Default replacement failed');
                await this.fetchEmployees(this.employees.current_page);
                if (this.selectedEmployee?.id === userId) await this.fetchEmployeeDetails(userId);
                return true;
            } catch (e) {
                useAdminStore().showError('Error', e.response?.data?.errors?.join(' | ') || e.message);
                return false;
            } finally {
                this.calculatingId = null;
            }
        },

        async updateSalaryCalculationComponentsForUser(userId, allowances, deductions) {
            this.calculatingId = userId;
            const adminStore = useAdminStore();
            try {
                let res = await axios.post(route("salary-setup.update.single-calc"), {
                    userId: userId,
                    allowances: allowances,
                    deductions: deductions
                });
                if (res.data.status === 500) {
                    adminStore.showError('New salary calculation update failed', res.data.errors || 'Condition checks failed.');
                    return false;
                } else {
                    adminStore.showSuccess('Success', 'Salary components and calculation objects updated successfully.');
                    await this.fetchEmployees(this.employees.current_page);
                    if (this.selectedEmployee && this.selectedEmployee.id === userId) {
                        await this.fetchEmployeeDetails(userId);
                    }
                    return true;
                }
            } catch (error) {
                console.error("Error calculating salary:", error);
                adminStore.showError('Error', (Array.isArray(error.response?.data?.errors) ? error.response.data.errors.join(' | ') : error.response?.data?.message) || 'Error occurred during salary calculation.');
                return false;
            } finally {
                this.calculatingId = null;
            }
        },

        async sendReview(userId) {
            this.sendingReviewId = userId;
            const adminStore = useAdminStore();
            try {
                const response = await axios.post(route('salary-setup.send-review', userId), { salary_month: this.filters.salary_month, salary_year: this.filters.salary_year });
                if (response.data.status === 200) {
                    adminStore.showSuccess('Review Email Sent', response.data.message);
                    return { success: true };
                } else {
                    adminStore.showError('Failed', response.data.errors?.join('\n') || 'Could not send review email.');
                    return { success: false };
                }
            } catch (error) {
                console.error("Error sending review email:", error);
                adminStore.showError('Error', error.response?.data?.errors?.join('\n') || error.response?.data?.message || 'Failed to dispatch review email.');
                return { success: false };
            } finally {
                this.sendingReviewId = null;
            }
        },

        async sendBulkReview(userIds = [], allFiltered = false) {
            this.bulkSendingReview = true;
            const adminStore = useAdminStore();
            try {
                const payload = {
                    user_ids: userIds,
                    all_filtered: allFiltered,
                };

                if (allFiltered) {
                    Object.keys(this.filters).forEach((key) => {
                        const val = this.filters[key];
                        if (val !== '' && val !== null && val !== undefined) {
                            payload[key] = val;
                        }
                    });
                }

                const response = await axios.post(route('salary-setup.send-bulk-review'), payload);
                if (response.data.status === 200) {
                    adminStore.showSuccess('Bulk Review Sent', response.data.message);
                    this.selectedUserIds = [];
                    return { success: true, ...response.data };
                } else {
                    adminStore.showError('Bulk Sending Failed', response.data.errors?.join('\n') || 'Failed to send bulk reviews.');
                    return { success: false };
                }
            } catch (error) {
                console.error("Error sending bulk review:", error);
                adminStore.showError('Error', error.response?.data?.errors?.join('\n') || error.response?.data?.message || 'Failed to send bulk review emails.');
                return { success: false };
            } finally {
                this.bulkSendingReview = false;
            }
        }
    }
});
