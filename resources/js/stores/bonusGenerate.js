import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const useBonusGenerateStore = defineStore('bonusGenerate', {
    state: () => {
        const currentDate = new Date();
        return {
            bills: {
                data: [],
                current_page: 1,
                last_page: 1,
                total: 0,
                per_page: 10,
            },
            summary: {
                total: 0,
                generated: 0,
                not_generated: 0,
            },
            loading: false,
            generatingId: null,
            bulkGenerating: false,
            bulkSendingReview: false,
            bulkOverridingStatus: false,
            actionLoadingId: null,
            selectedBill: null,
            viewModalOpen: false,
            selectedBillIds: [],
            filters: {
                monthId: currentDate.getMonth() + 1,
                bonusGenerationYear: currentDate.getFullYear(),
                bonus_group_id: 'all',
                bonusGeneratedStatus: 'generated',
                reviewStatus: 'all',
                search: '',
                employeeCode: '',
                department_office_id: '',
                user_category_id: '',
                pay_scale_id: '',
                grade_id: '',
                payroll_group_id: '',
                payroll_group_type_id: '',
                isActive: '',
                jobStatus: '',
                gender: '',
                holdIncrement: '',
                onLeave: '',
                marital_status: '',
                religion: '',
            },
            sort: {
                field: 'id',
                direction: 'desc',
            },
        };
    },

    getters: {
        activeFilterCount: (state) => {
            let count = 0;
            const f = state.filters;
            if (f.search) count++;
            if (f.employeeCode) count++;
            if (f.department_office_id) count++;
            if (f.user_category_id) count++;
            if (f.pay_scale_id) count++;
            if (f.grade_id) count++;
            if (f.payroll_group_id) count++;
            if (f.payroll_group_type_id) count++;
            if (f.isActive !== '') count++;
            if (f.jobStatus) count++;
            if (f.gender) count++;
            if (f.holdIncrement !== '') count++;
            if (f.onLeave !== '') count++;
            if (f.marital_status) count++;
            if (f.religion) count++;
            if (f.bonus_group_id !== 'all') count++;
            if (f.bonusGeneratedStatus !== 'generated') count++;
            if (f.reviewStatus !== 'all') count++;
            return count;
        },
    },

    actions: {
        async fetchBills(page = 1) {
            this.loading = true;
            try {
                const params = {
                    page,
                    sortField: this.sort.field,
                    sortDirection: this.sort.direction,
                };

                Object.keys(this.filters).forEach((key) => {
                    const val = this.filters[key];
                    if (val !== '' && val !== null && val !== undefined) {
                        params[key] = val;
                    }
                });

                const response = await axios.get(route('bonus-generates.index'), {
                    params,
                    headers: { Accept: 'application/json' },
                });

                this.bills = {
                    data: response.data.data || [],
                    current_page: response.data.current_page || 1,
                    last_page: response.data.last_page || 1,
                    total: response.data.total || 0,
                    per_page: response.data.per_page || 10,
                };

                if (response.data.summary) {
                    this.summary = response.data.summary;
                }
            } catch (error) {
                console.error('Failed to fetch bonus bills:', error);
                this.bills = {
                    data: [],
                    current_page: 1,
                    last_page: 1,
                    total: 0,
                    per_page: 10,
                };
            } finally {
                this.loading = false;
            }
        },

        setFilters(newFilters) {
            this.filters = { ...this.filters, ...newFilters };
            return this.fetchBills(1);
        },

        resetFilters() {
            const currentDate = new Date();
            this.filters = {
                monthId: currentDate.getMonth() + 1,
                bonusGenerationYear: currentDate.getFullYear(),
                bonus_group_id: 'all',
                bonusGeneratedStatus: 'generated',
                reviewStatus: 'all',
                search: '',
                employeeCode: '',
                department_office_id: '',
                user_category_id: '',
                pay_scale_id: '',
                grade_id: '',
                payroll_group_id: '',
                payroll_group_type_id: '',
                isActive: '',
                jobStatus: '',
                gender: '',
                holdIncrement: '',
                onLeave: '',
                marital_status: '',
                religion: '',
            };
            return this.fetchBills(1);
        },

        setSort(field) {
            if (this.sort.field === field) {
                this.sort.direction = this.sort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                this.sort.field = field;
                this.sort.direction = 'asc';
            }
            this.fetchBills(this.bills.current_page);
        },

        async generateSingle(userId, bonusGroupId = null) {
            this.generatingId = userId;
            const adminStore = useAdminStore();
            try {
                const payload = {
                    userId,
                    monthId: this.filters.monthId,
                    bonusGenerationYear: this.filters.bonusGenerationYear,
                    bonusGenerationDate: new Date().toISOString().split('T')[0],
                };

                const activeBonusGroupId = bonusGroupId || (this.filters.bonus_group_id !== 'all' ? this.filters.bonus_group_id : null);
                if (activeBonusGroupId) {
                    payload.bonus_group_id = activeBonusGroupId;
                }

                const response = await axios.post(route('bonus-generates.generate-single'), payload);

                if (response.data.status === 200) {
                    adminStore.showSuccess('Bonus Bill Generated', response.data.message || 'Bonus bill successfully generated.');
                    await this.fetchBills(this.bills.current_page);
                    return { success: true, data: response.data };
                } else {
                    adminStore.showError('Generation Failed', response.data.errors?.join('\n') || 'Could not generate bonus bill.');
                    return { success: false };
                }
            } catch (error) {
                console.error('Error generating single bonus bill:', error);
                const data = error.response?.data;
                const errMsg = Array.isArray(data?.errors) 
                    ? data.errors.join('\n') 
                    : (typeof data?.errors === 'object' && data?.errors ? Object.values(data.errors).flat().join('\n') : (data?.message || 'Failed to generate bonus bill.'));
                adminStore.showError('Calculation / Generation Failed', errMsg);
                return { success: false };
            } finally {
                this.generatingId = null;
            }
        },

        async generateBulk(userIds = [], allFilteredUnbilled = false, bonusGroupId = null) {
            this.bulkGenerating = true;
            const adminStore = useAdminStore();
            try {
                const payload = {
                    userIds,
                    all_filtered_unbilled: allFilteredUnbilled,
                    monthId: this.filters.monthId,
                    bonusGenerationYear: this.filters.bonusGenerationYear,
                    bonusGenerationDate: new Date().toISOString().split('T')[0],
                };

                const activeBonusGroupId = bonusGroupId || (this.filters.bonus_group_id !== 'all' ? this.filters.bonus_group_id : null);
                if (activeBonusGroupId) {
                    payload.bonus_group_id = activeBonusGroupId;
                }

                if (allFilteredUnbilled) {
                    Object.keys(this.filters).forEach((key) => {
                        const val = this.filters[key];
                        if (val !== '' && val !== null && val !== undefined) {
                            payload[key] = val;
                        }
                    });
                }

                const response = await axios.post(route('bonus-generates.generate-bulk'), payload);

                if (response.data.status === 200) {
                    adminStore.showSuccess('Bulk Generation Completed', response.data.message);
                    this.selectedBillIds = [];
                    await this.fetchBills(this.bills.current_page);
                    return { success: true, ...response.data };
                } else {
                    const errMsg = Array.isArray(response.data?.errors)
                        ? response.data.errors.join('\n')
                        : (response.data?.message || 'Bulk generation encountered errors.');
                    adminStore.showError('Bulk Generation Failed', errMsg);
                    return { success: false };
                }
            } catch (error) {
                console.error('Error during bulk bonus bill generation:', error);
                const data = error.response?.data;
                const errMsg = Array.isArray(data?.errors)
                    ? data.errors.join('\n')
                    : (typeof data?.errors === 'object' && data?.errors ? Object.values(data.errors).flat().join('\n') : (data?.message || 'Failed to execute bulk generation.'));
                adminStore.showError('Calculation / Generation Failed', errMsg);
                return { success: false };
            } finally {
                this.bulkGenerating = false;
            }
        },

        async fetchBillDetails(billId) {
            this.actionLoadingId = billId;
            const adminStore = useAdminStore();
            try {
                const response = await axios.get(route('bonus-generates.show', billId));
                this.selectedBill = response.data;
                this.viewModalOpen = true;
                return response.data;
            } catch (error) {
                console.error('Failed to fetch bonus bill details:', error);
                adminStore.showError('Error', 'Failed to load bonus bill particulars.');
                return null;
            } finally {
                this.actionLoadingId = null;
            }
        },

        async sendReview(billId) {
            this.actionLoadingId = billId;
            const adminStore = useAdminStore();
            try {
                const response = await axios.post(route('bonus-generates.send-review', billId));
                if (response.data.status === 200) {
                    adminStore.showSuccess('Review Sent', response.data.message);
                    await this.fetchBills(this.bills.current_page);
                    return { success: true };
                } else {
                    adminStore.showError('Dispatch Failed', response.data.errors?.join('\n') || 'Failed to send review email.');
                    return { success: false };
                }
            } catch (error) {
                console.error('Failed to send review email:', error);
                adminStore.showError('Error', error.response?.data?.errors?.join('\n') || error.response?.data?.message || 'Error occurred while sending email.');
                return { success: false };
            } finally {
                this.actionLoadingId = null;
            }
        },

        async sendBulkReview(billIds = [], allFiltered = false) {
            this.bulkSendingReview = true;
            const adminStore = useAdminStore();
            try {
                const payload = {
                    bonus_generate_ids: billIds,
                    all_filtered: allFiltered,
                    monthId: this.filters.monthId,
                    bonusGenerationYear: this.filters.bonusGenerationYear,
                    bonus_group_id: this.filters.bonus_group_id !== 'all' ? this.filters.bonus_group_id : null,
                };

                if (allFiltered) {
                    Object.keys(this.filters).forEach((key) => {
                        const val = this.filters[key];
                        if (val !== '' && val !== null && val !== undefined) {
                            payload[key] = val;
                        }
                    });
                }

                const response = await axios.post(route('bonus-generates.send-bulk-review'), payload);
                if (response.data.status === 200) {
                    adminStore.showSuccess('Bulk Review Sent', response.data.message);
                    this.selectedBillIds = [];
                    await this.fetchBills(this.bills.current_page);
                    return { success: true, ...response.data };
                } else {
                    adminStore.showError('Bulk Sending Failed', response.data.errors?.join('\n') || 'Failed to send bulk reviews.');
                    return { success: false };
                }
            } catch (error) {
                console.error('Failed to send bulk reviews:', error);
                adminStore.showError('Error', error.response?.data?.errors?.join('\n') || error.response?.data?.message || 'Failed to dispatch bulk emails.');
                return { success: false };
            } finally {
                this.bulkSendingReview = false;
            }
        },

        async overrideReviewStatus(billId, reviewStatus = 'approved') {
            this.actionLoadingId = billId;
            const adminStore = useAdminStore();
            try {
                const response = await axios.patch(route('bonus-generates.override-review', billId), {
                    reviewStatus,
                });
                if (response.data.status === 200) {
                    adminStore.showSuccess('Status Updated', response.data.message);
                    await this.fetchBills(this.bills.current_page);
                    return { success: true };
                } else {
                    adminStore.showError('Update Failed', response.data.errors?.join('\n') || 'Failed to update review status.');
                    return { success: false };
                }
            } catch (error) {
                console.error('Failed to override status:', error);
                adminStore.showError('Error', error.response?.data?.errors?.join('\n') || error.response?.data?.message || 'Error occurred while updating status.');
                return { success: false };
            } finally {
                this.actionLoadingId = null;
            }
        },

        async overrideBulkReviewStatus(billIds = [], allFiltered = false, reviewStatus = 'approved') {
            this.bulkOverridingStatus = true;
            const adminStore = useAdminStore();
            try {
                const payload = {
                    bonus_generate_ids: billIds,
                    all_filtered: allFiltered,
                    reviewStatus,
                    monthId: this.filters.monthId,
                    bonusGenerationYear: this.filters.bonusGenerationYear,
                    bonus_group_id: this.filters.bonus_group_id !== 'all' ? this.filters.bonus_group_id : null,
                };

                if (allFiltered) {
                    Object.keys(this.filters).forEach((key) => {
                        const val = this.filters[key];
                        if (val !== '' && val !== null && val !== undefined) {
                            payload[key] = val;
                        }
                    });
                }

                const response = await axios.patch(route('bonus-generates.override-bulk-review'), payload);
                if (response.data.status === 200) {
                    adminStore.showSuccess('Bulk Status Updated', response.data.message);
                    this.selectedBillIds = [];
                    await this.fetchBills(this.bills.current_page);
                    return { success: true, ...response.data };
                } else {
                    adminStore.showError('Bulk Status Failed', response.data.errors?.join('\n') || 'Failed to update review statuses.');
                    return { success: false };
                }
            } catch (error) {
                console.error('Failed to override bulk status:', error);
                adminStore.showError('Error', error.response?.data?.errors?.join('\n') || error.response?.data?.message || 'Failed to update review statuses.');
                return { success: false };
            } finally {
                this.bulkOverridingStatus = false;
            }
        },
    }
});
