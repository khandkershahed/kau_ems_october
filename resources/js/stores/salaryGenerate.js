import { defineStore } from 'pinia';
import axios from 'axios';
import * as XLSX from 'xlsx';
import { useAdminStore } from '@/stores/admin';

export const useSalaryGenerateStore = defineStore('salaryGenerate', {
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
            exportingExcel: false,
            generatingId: null,
            bulkGenerating: false,
            bulkRunToken: null,
            bulkRunProgress: null,
            bulkRunCsvUrl: null,
            bulkSendingReview: false,
            bulkOverridingStatus: false,
            actionLoadingId: null,
            selectedBill: null,
            viewModalOpen: false,
            filters: {
                monthId: currentDate.getMonth() + 1,
                salaryGenerationYear: currentDate.getFullYear(),
                salaryGeneratedStatus: 'not_generated',
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
            if (f.salaryGeneratedStatus !== 'not_generated') count++;
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

                const response = await axios.get(route('salary-generates.index'), {
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
                console.error('Failed to fetch salary bills:', error);
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
                salaryGenerationYear: currentDate.getFullYear(),
                salaryGeneratedStatus: 'not_generated',
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
                this.sort.direction = 'desc';
            }
            return this.fetchBills(this.bills.current_page);
        },

        async generateSingle(userId) {
            this.generatingId = userId;
            const adminStore = useAdminStore();
            try {
                const response = await axios.post(route('salary-generates.generate-single'), {
                    userId,
                    monthId: this.filters.monthId,
                    salaryGenerationYear: this.filters.salaryGenerationYear,
                });

                const data = response.data;
                if (data.status === 200) {
                    adminStore.showSuccess('Success', data.messages?.[0] || 'Salary bill generated successfully.');
                    await this.fetchBills(this.bills.current_page);
                    return { success: true, data };
                } else {
                    const err = data.errors?.join('\n') || 'Calculation conditions could not be met.';
                    adminStore.showError('Generation Failed', err);
                    return { success: false, error: err };
                }
            } catch (error) {
                const err = error.response?.data?.errors?.join('\n') || error.message || 'Error occurred while generating salary.';
                adminStore.showError('Error', err);
                return { success: false, error: err };
            } finally {
                this.generatingId = null;
            }
        },

        async generateBulk(userIds = [], allFilteredUnbilled = false, previewHashes = {}) {
            this.bulkGenerating = true;
            const adminStore = useAdminStore();
            try {
                const period={monthId:Number(this.filters.monthId),salaryGenerationYear:Number(this.filters.salaryGenerationYear)};
                let chosen=userIds;
                if(allFilteredUnbilled) {
                    // Compatibility for existing button paths: never silently post unpreviewed employees.
                    const params={...this.filters,...period};
                    const first=(await axios.get(route('salary-generates.bulk-candidates'),{params})).data;
                    const ids=first.user_ids||[];
                    const rows=[...(first.preview||[])];
                    for(let i=25;i<ids.length;i+=25){
                        const res=await axios.get(route('salary-generates.bulk-candidates'),{params:{...params,preview_ids:ids.slice(i,i+25)}});
                        rows.push(...(res.data.preview||[]));
                    }
                    chosen=rows.filter(r=>r.status==='ready'&&/^[a-f0-9]{64}$/.test(r.source_hash||'')).map(r=>Number(r.user_id));
                    previewHashes=Object.fromEntries(rows.filter(r=>chosen.includes(Number(r.user_id))).map(r=>[String(r.user_id),r.source_hash]));
                    const ok=window.confirm(`${chosen.length} evidence-complete employees ready. ${rows.length-chosen.length} excluded. Generate these ${chosen.length} salary bills?`);
                    if(!ok)return {success:false,cancelled:true};
                }
                if(!chosen?.length)throw new Error('No employees selected for batch generation.');
                const started=await axios.post(route('salary-bulk-runs.start'),{
                    year:period.salaryGenerationYear,month:period.monthId,user_ids:chosen,preview_hashes:previewHashes
                });
                const token=started.data.run_token;
                this.bulkRunToken=token;
                this.bulkRunCsvUrl=route('salary-bulk-runs.csv',{token});
                this.bulkRunProgress={total:chosen.length,done:0,counts:{},remaining:chosen.length};
                // Browser may be closed and resumed with the saved run token.
                let response;
                do {
                    response=await axios.post(route('salary-bulk-runs.next',{token}));
                    this.bulkRunProgress={...response.data,done:response.data.total-(response.data.remaining||0)};
                } while(response.data.remaining>0);
                const counts=response.data.counts||{};
                const csv=route('salary-bulk-runs.csv',{token});
                adminStore.showSuccess('Batch Completed',`Generated ${counts.generated||0}, Skipped ${counts.skipped||0}, Blocked ${counts.blocked||0}. Run ID: ${token}. Download: ${csv}`);
                await this.fetchBills(this.bills.current_page);
                return {success:true,data:{...response.data,csv_url:csv}};
            } catch(error) {
                const reason=error.response?.data?.message||error.message||'Batch generation failed';
                adminStore.showError('Batch Error',reason);
                return {success:false,error:reason};
            } finally {this.bulkGenerating=false;}
        },

        async resumeBulkRun() {
            if (!this.bulkRunToken) return {success:false,error:'No run token available.'};
            const adminStore=useAdminStore();this.bulkGenerating=true;
            try {
                let response;
                do {
                    response=await axios.post(route('salary-bulk-runs.next',{token:this.bulkRunToken}));
                    this.bulkRunProgress={...response.data,done:response.data.total-response.data.remaining};
                } while(response.data.remaining>0);
                this.bulkRunCsvUrl=route('salary-bulk-runs.csv',{token:this.bulkRunToken});
                adminStore.showSuccess('Resumed Run Complete',`Generated ${response.data.counts?.generated||0}, blocked ${response.data.counts?.blocked||0}`);
                await this.fetchBills(this.bills.current_page);
                return {success:true,data:response.data};
            }catch(error){adminStore.showError('Resume Failed',error.response?.data?.message||error.message);return {success:false};}
            finally{this.bulkGenerating=false;}
        },

        async sendReview(salaryGenerateId) {
            this.actionLoadingId = salaryGenerateId;
            const adminStore = useAdminStore();
            try {
                const response = await axios.post(route('salary-generates.send-review', salaryGenerateId));
                if (response.data.status === 200) {
                    adminStore.showSuccess('Review Sent', response.data.message);
                    await this.fetchBills(this.bills.current_page);
                    return { success: true };
                } else {
                    adminStore.showError('Failed', response.data.errors?.join('\n') || 'Could not send review.');
                    return { success: false };
                }
            } catch (error) {
                adminStore.showError('Error', error.response?.data?.errors?.join('\n') || 'Failed to submit review request.');
                return { success: false };
            } finally {
                this.actionLoadingId = null;
            }
        },

        async sendBulkReview(salaryGenerateIds = [], allFiltered = false) {
            this.bulkSendingReview = true;
            const adminStore = useAdminStore();
            try {
                const payload = {
                    salary_generate_ids: salaryGenerateIds,
                    all_filtered: allFiltered,
                    monthId: this.filters.monthId,
                    salaryGenerationYear: this.filters.salaryGenerationYear,
                };

                if (allFiltered) {
                    Object.keys(this.filters).forEach((key) => {
                        const val = this.filters[key];
                        if (val !== '' && val !== null && val !== undefined) {
                            payload[key] = val;
                        }
                    });
                }

                const response = await axios.post(route('salary-generates.send-bulk-review'), payload);
                if (response.data.status === 200) {
                    adminStore.showSuccess('Bulk Review Sent', response.data.message);
                    await this.fetchBills(this.bills.current_page);
                    return { success: true, ...response.data };
                } else {
                    adminStore.showError('Bulk Sending Failed', response.data.errors?.join('\n') || 'Failed to send bulk reviews.');
                    return { success: false };
                }
            } catch (error) {
                console.error("Error sending bulk review:", error);
                adminStore.showError('Error', error.response?.data?.errors?.join('\n') || error.response?.data?.message || 'Failed to send bulk reviews.');
                return { success: false };
            } finally {
                this.bulkSendingReview = false;
            }
        },

        async overrideBulkReviewStatus(salaryGenerateIds = [], reviewStatus = 'approved', allFiltered = false) {
            this.bulkOverridingStatus = true;
            const adminStore = useAdminStore();
            try {
                const payload = {
                    salary_generate_ids: salaryGenerateIds,
                    all_filtered: allFiltered,
                    reviewStatus: reviewStatus,
                    monthId: this.filters.monthId,
                    salaryGenerationYear: this.filters.salaryGenerationYear,
                };

                if (allFiltered) {
                    Object.keys(this.filters).forEach((key) => {
                        const val = this.filters[key];
                        if (val !== '' && val !== null && val !== undefined) {
                            payload[key] = val;
                        }
                    });
                }

                const response = await axios.patch(route('salary-generates.override-bulk-review'), payload);
                if (response.data.status === 200) {
                    adminStore.showSuccess('Status Overridden', response.data.message);
                    await this.fetchBills(this.bills.current_page);
                    return { success: true, ...response.data };
                } else {
                    adminStore.showError('Bulk Override Failed', response.data.errors?.join('\n') || 'Failed to override status.');
                    return { success: false };
                }
            } catch (error) {
                console.error("Error bulk overriding status:", error);
                adminStore.showError('Error', error.response?.data?.errors?.join('\n') || error.response?.data?.message || 'Failed to override status.');
                return { success: false };
            } finally {
                this.bulkOverridingStatus = false;
            }
        },

        async overrideReviewStatus(salaryGenerateId, newStatus = 'approved') {
            this.actionLoadingId = salaryGenerateId;
            const adminStore = useAdminStore();
            try {
                const response = await axios.patch(route('salary-generates.override-review', salaryGenerateId), {
                    reviewStatus: newStatus,
                });
                if (response.data.status === 200) {
                    adminStore.showSuccess('Status Overridden', response.data.message);
                    await this.fetchBills(this.bills.current_page);
                    return { success: true };
                } else {
                    adminStore.showError('Failed', response.data.errors?.join('\n') || 'Could not override status.');
                    return { success: false };
                }
            } catch (error) {
                adminStore.showError('Error', error.response?.data?.errors?.join('\n') || 'Failed to override status.');
                return { success: false };
            } finally {
                this.actionLoadingId = null;
            }
        },

        async openViewModal(bill) {
            this.selectedBill = bill;
            this.viewModalOpen = true;

            if (bill.salary_generate_id) {
                try {
                    const response = await axios.get(route('salary-generates.show', bill.salary_generate_id));
                    if (response.data) {
                        this.selectedBill = {
                            ...this.selectedBill,
                            full_record: response.data,
                        };
                    }
                } catch (e) {
                    console.error('Failed to load full bill details', e);
                }
            }
        },

        closeViewModal() {
            this.viewModalOpen = false;
            this.selectedBill = null;
        },

        /**
         * Fetch all filtered salary bill records without pagination limit.
         */
        async fetchAllFilteredBills(extraParams = {}) {
            try {
                const params = {
                    all: true,
                    sortField: this.sort.field,
                    sortDirection: this.sort.direction,
                    ...extraParams,
                };

                Object.keys(this.filters).forEach((key) => {
                    const val = this.filters[key];
                    if (val !== '' && val !== null && val !== undefined) {
                        params[key] = val;
                    }
                });

                const response = await axios.get(route('salary-generates.index'), {
                    params,
                    headers: { Accept: 'application/json' },
                });

                return response.data.data || [];
            } catch (error) {
                console.error('Failed to fetch filtered bills for export:', error);
                return [];
            }
        },

        /**
         * Export selected salary bills to an Excel spreadsheet (.xlsx).
         * Supports passing an array of row records directly or an options object
         * { records, selectedIds, allFiltered }.
         */
        async exportToExcel(recordsOrOptions = null) {
            this.exportingExcel = true;
            const adminStore = useAdminStore();

            try {
                let records = [];
                let allFiltered = false;
                let selectedIds = [];

                if (Array.isArray(recordsOrOptions)) {
                    records = [...recordsOrOptions];
                } else if (recordsOrOptions && typeof recordsOrOptions === 'object') {
                    records = Array.isArray(recordsOrOptions.records) ? [...recordsOrOptions.records] : [];
                    allFiltered = Boolean(recordsOrOptions.allFiltered);
                    selectedIds = Array.isArray(recordsOrOptions.selectedIds) ? [...recordsOrOptions.selectedIds] : [];
                }

                // If all filtered requested, fetch all records matching current filters
                if (allFiltered) {
                    records = await this.fetchAllFilteredBills();
                } else if (records.length === 0 && selectedIds.length > 0) {
                    // Check if all selected records already exist in current bills data
                    const currentData = this.bills.data || [];
                    const idSet = new Set(selectedIds);
                    const matched = currentData.filter(b => idSet.has(b.salary_generate_id));

                    if (matched.length === selectedIds.length) {
                        records = matched;
                    } else {
                        records = await this.fetchAllFilteredBills({ salary_generate_ids: selectedIds });
                    }
                }

                if (!records || records.length === 0) {
                    adminStore.showError('Export Notice', 'No records selected or available to download as Excel.');
                    return { success: false, error: 'No records to export' };
                }

                const monthNames = [
                    'January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December'
                ];
                const monthName = monthNames[parseInt(this.filters.monthId) - 1] || `Month_${this.filters.monthId}`;
                const year = this.filters.salaryGenerationYear || (new Date()).getFullYear();
                const dateStr = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
                const exportScope = allFiltered ? `All Filtered Records (${records.length})` : `Selected Records (${records.length})`;

                // Helper to safely extract Basic Pay
                const extractBasicPay = (r) => {
                    if (r.basic_pay !== undefined && r.basic_pay !== null && r.basic_pay !== '') {
                        return Number(r.basic_pay) || 0;
                    }
                    const allowances = r.salary_generate?.salaryAllowanceCalcObject;
                    if (Array.isArray(allowances)) {
                        for (const item of allowances) {
                            const name = String(item.name || item.payroll_component_type?.name || '').toLowerCase();
                            if (name.includes('basic')) {
                                return Number(item.calculated_amount ?? item.amount ?? 0) || 0;
                            }
                        }
                    }
                    return 0;
                };

                // Spreadsheet title & meta headers
                const headerRows = [
                    ['KHULNA AGRICULTURAL UNIVERSITY'],
                    ['Review & Generate Salary Bills Report'],
                    [`Period: ${monthName} ${year}   |   Export Scope: ${exportScope}   |   Export Date: ${dateStr}`],
                    [], // empty row separator
                ];

                // Table Columns
                const tableHeaders = [
                    'SL #',
                    'Employee Code',
                    'Employee Name',
                    'Email Address',
                    'Contact No',
                    'Department / Office',
                    'Category',
                    'Designation',
                    'Pay Scale',
                    'Grade',
                    'Job Status',
                    'Month / Year',
                    'Basic Pay (BDT)',
                    'Total Allowance (BDT)',
                    'Tax Deduction (BDT)',
                    'GPF Deduction (BDT)',
                    'Total Deduction (BDT)',
                    'Net Salary (BDT)',
                    'Bill Status',
                    'Review Status',
                    'Generation Date',
                ];

                let sumBasic = 0;
                let sumAllow = 0;
                let sumTax = 0;
                let sumGpf = 0;
                let sumDeduct = 0;
                let sumNet = 0;

                const dataRows = records.map((r, idx) => {
                    const basic = extractBasicPay(r);
                    const allow = Number(r.total_allowance) || 0;
                    const tax = Number(r.tax_deduction) || 0;
                    const gpf = Number(r.gpf_deduction) || 0;
                    const deduct = Number(r.total_deduction) || 0;
                    const net = Number(r.salary_total) || 0;

                    sumBasic += basic;
                    sumAllow += allow;
                    sumTax += tax;
                    sumGpf += gpf;
                    sumDeduct += deduct;
                    sumNet += net;

                    const billStatus = r.bill_status === 'generated' ? 'Generated' : 'Not Generated';
                    const reviewStatus = r.review_status
                        ? r.review_status.charAt(0).toUpperCase() + r.review_status.slice(1)
                        : 'N/A';

                    return [
                        idx + 1,
                        r.employee_code || 'N/A',
                        r.name || '',
                        r.email || '',
                        r.phone || 'N/A',
                        r.department || 'N/A',
                        r.user_category || 'General',
                        r.designation || 'N/A',
                        r.pay_scale || 'N/A',
                        r.grade || 'N/A',
                        r.job_status || 'N/A',
                        `${monthName} ${r.year || year}`,
                        basic,
                        allow,
                        tax,
                        gpf,
                        deduct,
                        net,
                        billStatus,
                        reviewStatus,
                        r.salary_generation_date || 'N/A',
                    ];
                });

                // Summary Total Row
                const totalRow = [
                    '', '', '', '', '', '', '', '', '', '', '', 'TOTAL (BDT)',
                    sumBasic,
                    sumAllow,
                    sumTax,
                    sumGpf,
                    sumDeduct,
                    sumNet,
                    '', '', ''
                ];

                const sheetData = [...headerRows, tableHeaders, ...dataRows, totalRow];
                const worksheet = XLSX.utils.aoa_to_sheet(sheetData);

                // Set column widths
                worksheet['!cols'] = [
                    { wch: 6 },   // SL #
                    { wch: 14 },  // Employee Code
                    { wch: 26 },  // Employee Name
                    { wch: 28 },  // Email
                    { wch: 16 },  // Contact
                    { wch: 28 },  // Department
                    { wch: 18 },  // Category
                    { wch: 24 },  // Designation
                    { wch: 22 },  // Pay Scale
                    { wch: 12 },  // Grade
                    { wch: 14 },  // Job Status
                    { wch: 16 },  // Month / Year
                    { wch: 16 },  // Basic Pay
                    { wch: 20 },  // Total Allowance
                    { wch: 18 },  // Tax Deduction
                    { wch: 18 },  // GPF Deduction
                    { wch: 20 },  // Total Deduction
                    { wch: 18 },  // Net Salary
                    { wch: 14 },  // Bill Status
                    { wch: 14 },  // Review Status
                    { wch: 16 },  // Generation Date
                ];

                const workbook = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(workbook, worksheet, 'Salary Bills');

                const fileDate = new Date().toISOString().slice(0, 10);
                const fileName = `KAU_Salary_Bills_${monthName}_${year}_${allFiltered ? 'All_Filtered' : 'Selected'}_${fileDate}.xlsx`;

                XLSX.writeFile(workbook, fileName);

                adminStore.showSuccess(
                    'Downloaded',
                    `Successfully exported ${records.length} salary bill record(s) to Excel.`
                );
                return { success: true, count: records.length };
            } catch (error) {
                console.error('Failed to export salary bills to Excel:', error);
                adminStore.showError('Export Failed', 'An error occurred while generating Excel file.');
                return { success: false, error };
            } finally {
                this.exportingExcel = false;
            }
        },
    },
});
