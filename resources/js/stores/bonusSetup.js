import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const useBonusSetupStore = defineStore('bonusSetup', {
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
        assigningGroup: false,
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
            joining_date: '',
            religion: '',
            bonus_group_id: '',
            department_office_id: '',
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
            if (f.joining_date) count++;
            if (f.religion) count++;
            if (f.bonus_group_id) count++;
            if (f.department_office_id) count++;
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

                const response = await axios.get(route('bonus-setup.index'), {
                    params,
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                this.employees = response.data;
            } catch (error) {
                console.error("Error fetching bonus setup employees:", error);
                const adminStore = useAdminStore();
                adminStore.showError('Error', 'Failed to load employee bonus setup records.');
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
                joining_date: '',
                religion: '',
                bonus_group_id: '',
                department_office_id: '',
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
                const response = await axios.get(route('bonus-setup.show', userId));
                const data = response.data;
                if (!data || !data.user) {
                    throw new Error('Employee record not found or invalid response.');
                }
                this.selectedEmployee = data;
                return data;
            } catch (error) {
                console.error("Error fetching employee details:", error);
                this.selectedEmployee = null;
                const adminStore = useAdminStore();
                adminStore.showError('Error', 'Failed to fetch employee bonus setup details.');
                return null;
            } finally {
                this.detailsLoading = false;
            }
        },

        clearSelectedEmployee() {
            this.selectedEmployee = null;
            this.detailsLoading = false;
        },

        async updateBonusCalculation(userId, bonusGroupId, allowances, deductions) {
            this.calculatingId = userId;
            const adminStore = useAdminStore();
            try {
                const res = await axios.post(route("bonus-setup.update.single-calc"), {
                    userId: userId,
                    bonus_group_id: bonusGroupId,
                    allowances: allowances,
                    deductions: deductions
                });

                if (res.data.status === 500) {
                    adminStore.showError('Bonus Calculation Failed', res.data.errors?.join('\n') || 'Calculation conditions failed.');
                    return false;
                } else {
                    adminStore.showSuccess('Success', res.data.message || 'Bonus calculation updated successfully.');
                    await this.fetchEmployees(this.employees.current_page);
                    if (this.selectedEmployee && this.selectedEmployee.user?.id === userId) {
                        await this.fetchEmployeeDetails(userId);
                    }
                    return true;
                }
            } catch (error) {
                console.error("Error saving bonus calculation:", error);
                adminStore.showError('Error', error.response?.data?.errors?.join('\n') || error.response?.data?.message || 'Error occurred during bonus calculation update.');
                return false;
            } finally {
                this.calculatingId = null;
            }
        },

        async assignBonusGroup(bonusGroupId, userIds = [], allFiltered = false) {
            this.assigningGroup = true;
            const adminStore = useAdminStore();
            try {
                const payload = {
                    bonus_group_id: bonusGroupId,
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

                const response = await axios.post(route('bonus-setup.assign-group'), payload);
                if (response.data.status === 200) {
                    adminStore.showSuccess('Bonus Group Assigned', response.data.message);
                    this.selectedUserIds = [];
                    await this.fetchEmployees(this.employees.current_page);
                    return { success: true, ...response.data };
                } else {
                    adminStore.showError('Assignment Failed', response.data.errors?.join('\n') || 'Could not assign bonus group.');
                    return { success: false };
                }
            } catch (error) {
                console.error("Error assigning bonus group:", error);
                adminStore.showError('Error', error.response?.data?.errors?.join('\n') || error.response?.data?.message || 'Failed to assign bonus group.');
                return { success: false };
            } finally {
                this.assigningGroup = false;
            }
        },

        async calculateDefault(userId) {
            this.calculatingId = userId;
            const adminStore = useAdminStore();
            try {
                const res = await axios.post(route("bonus-setup.calculate-default"), {
                    userId: userId
                });

                if (res.data.status === 200) {
                    adminStore.showSuccess('Success', res.data.message);
                    await this.fetchEmployees(this.employees.current_page);
                    if (this.selectedEmployee && this.selectedEmployee.user?.id === userId) {
                        await this.fetchEmployeeDetails(userId);
                    }
                    return true;
                } else {
                    adminStore.showError('Reset Failed', res.data.errors?.join('\n') || 'Could not reset bonus components.');
                    return false;
                }
            } catch (error) {
                console.error("Error resetting bonus components:", error);
                adminStore.showError('Error', error.response?.data?.errors?.join('\n') || error.response?.data?.message || 'Failed to reset bonus components.');
                return false;
            } finally {
                this.calculatingId = null;
            }
        },

        async sendReview(userId) {
            this.sendingReviewId = userId;
            const adminStore = useAdminStore();
            try {
                const response = await axios.post(route('bonus-setup.send-review', userId));
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

                const response = await axios.post(route('bonus-setup.send-bulk-review'), payload);
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
