import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';
import Swal from 'sweetalert2';

export const useArchiveSalaryStore = defineStore('archiveSalary', {
    state: () => ({
        archiveFiles: [],
        selectedYear: 2025,
        selectedMonth: 7,
        currentFilename: '',
        records: [],
        summary: {
            total_employees: 0,
            total_basic_pay: 0,
            total_gross: 0,
            total_deductions: 0,
            total_net_payable: 0,
            total_gpf: 0,
            total_tax: 0,
            written_count: 0,
            pending_count: 0,
        },
        selectedRecordIds: [],
        loading: false,
        writing: false,
        uploading: false,
        filters: {
            search: '',
            designation: '',
            department: '',
            status: 'all', // 'all', 'written', 'pending'
        },
        writeProgress: {
            isOpen: false,
            isProcessing: false,
            isPaused: false,
            isCancelled: false,
            total: 0,
            current: 0,
            createdCount: 0,
            updatedCount: 0,
            errorCount: 0,
            currentRecord: null,
            logs: [],
        },
    }),

    getters: {
        filteredRecords(state) {
            let list = state.records || [];

            if (state.filters.search) {
                const q = state.filters.search.toLowerCase().trim();
                list = list.filter(r => {
                    const name = (r.employee_info?.name || '').toLowerCase();
                    const sid = String(r.employee_info?.service_id || '').toLowerCase();
                    const des = (r.employee_info?.designation || '').toLowerCase();
                    const dept = (r.employee_info?.department || '').toLowerCase();
                    return name.includes(q) || sid.includes(q) || des.includes(q) || dept.includes(q);
                });
            }

            if (state.filters.designation) {
                list = list.filter(r => r.employee_info?.designation === state.filters.designation);
            }

            if (state.filters.department) {
                list = list.filter(r => r.employee_info?.department === state.filters.department);
            }

            if (state.filters.status === 'written') {
                list = list.filter(r => r.is_written);
            } else if (state.filters.status === 'pending') {
                list = list.filter(r => !r.is_written);
            }

            return list;
        },

        isAllSelected(state) {
            const filtered = this.filteredRecords;
            if (!filtered.length) return false;
            return filtered.every(r => state.selectedRecordIds.includes(r.id));
        },

        selectedRecords(state) {
            const selectedSet = new Set(state.selectedRecordIds);
            return (state.records || []).filter(r => selectedSet.has(r.id));
        },

        availableDesignations(state) {
            const set = new Set();
            (state.records || []).forEach(r => {
                if (r.employee_info?.designation) set.add(r.employee_info.designation);
            });
            return Array.from(set).sort();
        },

        availableDepartments(state) {
            const set = new Set();
            (state.records || []).forEach(r => {
                if (r.employee_info?.department) set.add(r.employee_info.department);
            });
            return Array.from(set).sort();
        },
    },

    actions: {
        setArchiveFiles(files) {
            this.archiveFiles = files || [];
        },

        async loadFile(year, month, filename = null) {
            const adminStore = useAdminStore();
            this.loading = true;
            this.selectedRecordIds = [];

            try {
                const params = {};
                if (filename) {
                    params.filename = filename;
                } else {
                    params.year = year;
                    params.month = month;
                }

                const response = await axios.get(route('archive.load'), { params });
                if (response.data.status === 200) {
                    this.selectedYear = response.data.year;
                    this.selectedMonth = response.data.month;
                    this.currentFilename = response.data.filename;
                    this.records = response.data.records || [];
                    this.summary = response.data.summary || {};
                }
            } catch (err) {
                const msg = err.response?.data?.message || 'Failed to load archive salary file.';
                adminStore.showError('Load Error', msg);
            } finally {
                this.loading = false;
            }
        },

        async uploadFile(file) {
            const adminStore = useAdminStore();
            this.uploading = true;
            this.selectedRecordIds = [];

            const formData = new FormData();
            formData.append('file', file);

            try {
                const response = await axios.post(route('archive.upload'), formData, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });

                if (response.data.status === 200) {
                    this.selectedYear = response.data.year;
                    this.selectedMonth = response.data.month;
                    this.currentFilename = response.data.filename;
                    this.records = response.data.records || [];
                    this.summary = response.data.summary || {};

                    adminStore.showSuccess(
                        'File Uploaded',
                        `Successfully loaded ${this.records.length} records from ${this.currentFilename}.`
                    );
                }
            } catch (err) {
                const msg = err.response?.data?.message || 'Failed to parse uploaded JSON file.';
                adminStore.showError('Upload Error', msg);
            } finally {
                this.uploading = false;
            }
        },

        toggleSelectAll() {
            const filtered = this.filteredRecords;
            const allSelected = this.isAllSelected;

            if (allSelected) {
                const filteredIds = new Set(filtered.map(r => r.id));
                this.selectedRecordIds = this.selectedRecordIds.filter(id => !filteredIds.has(id));
            } else {
                const combined = new Set([...this.selectedRecordIds, ...filtered.map(r => r.id)]);
                this.selectedRecordIds = Array.from(combined);
            }
        },

        toggleSelectRecord(id) {
            const index = this.selectedRecordIds.indexOf(id);
            if (index > -1) {
                this.selectedRecordIds.splice(index, 1);
            } else {
                this.selectedRecordIds.push(id);
            }
        },

        updateStagedRecord(updatedRecord) {
            const index = this.records.findIndex(r => r.id === updatedRecord.id);
            if (index > -1) {
                // Recalculate totals for this record
                const allowances = updatedRecord.salary_components.formatted_allowances || [];
                const deductions = updatedRecord.salary_components.formatted_deductions || [];

                let gross = 0;
                let basic = 0;
                const newAllowancesObj = {};
                allowances.forEach(a => {
                    const val = parseFloat(a.amount) || 0;
                    gross += val;
                    if (a.key === 'basic_pay' || (a.name || '').toLowerCase().includes('basic')) {
                        basic = val;
                    }
                    newAllowancesObj[a.key || this.nameToKey(a.name)] = val;
                });

                let totalDed = 0;
                const newDeductionsObj = {};
                deductions.forEach(d => {
                    const val = parseFloat(d.amount) || 0;
                    totalDed += val;
                    newDeductionsObj[d.key || this.nameToKey(d.name)] = val;
                });

                updatedRecord.salary_components.basic_pay = basic;
                updatedRecord.salary_components.gross_salary = gross;
                updatedRecord.salary_components.total_deductions = totalDed;
                updatedRecord.salary_components.net_payable = gross - totalDed;
                updatedRecord.salary_components.allowances = newAllowancesObj;
                updatedRecord.salary_components.deductions = newDeductionsObj;

                this.records[index] = { ...updatedRecord };
                this.recalculateFileSummary();
            }
        },

        recalculateFileSummary() {
            let totalBasic = 0;
            let totalGross = 0;
            let totalDed = 0;
            let totalNet = 0;
            let totalGpf = 0;
            let totalTax = 0;
            let written = 0;

            this.records.forEach(r => {
                const sc = r.salary_components || {};
                totalBasic += sc.basic_pay || 0;
                totalGross += sc.gross_salary || 0;
                totalDed += sc.total_deductions || 0;
                totalNet += sc.net_payable || 0;

                const deds = sc.formatted_deductions || [];
                deds.forEach(d => {
                    const amt = parseFloat(d.amount) || 0;
                    const name = (d.name || '').toLowerCase();
                    if (name.includes('tax') || (d.key && d.key.includes('tax'))) {
                        totalTax += amt;
                    } else if (name.includes('provident') || (d.key && d.key.includes('provident')) || name.includes('gpf')) {
                        totalGpf += amt;
                    }
                });

                if (r.is_written) written++;
            });

            this.summary = {
                total_employees: this.records.length,
                total_basic_pay: totalBasic,
                total_gross: totalGross,
                total_deductions: totalDed,
                total_net_payable: totalNet,
                total_gpf: totalGpf,
                total_tax: totalTax,
                written_count: written,
                pending_count: this.records.length - written,
            };
        },

        async writeRecords(recordsToWrite = null) {
            return this.startWriteQueue(recordsToWrite);
        },

        async startWriteQueue(recordsToWrite = null) {
            const adminStore = useAdminStore();
            const list = recordsToWrite || this.selectedRecords;

            if (!list || !list.length) {
                adminStore.showError('No Records Selected', 'Please select at least one employee record to write.');
                return false;
            }

            // Initialize progress state
            this.writing = true;
            this.writeProgress = {
                isOpen: true,
                isProcessing: true,
                isPaused: false,
                isCancelled: false,
                total: list.length,
                current: 0,
                createdCount: 0,
                updatedCount: 0,
                errorCount: 0,
                currentRecord: null,
                logs: [],
            };

            const startTime = new Date();
            this.addProgressLog({
                type: 'info',
                message: `Initiating writing sequence for ${list.length} record(s) (${this.selectedYear}-${String(this.selectedMonth).padStart(2, '0')})...`,
                time: startTime.toLocaleTimeString(),
            });

            for (let i = 0; i < list.length; i++) {
                // If cancelled by user, stop loop
                if (this.writeProgress.isCancelled) {
                    this.addProgressLog({
                        type: 'warning',
                        message: `Writing process stopped by user after ${this.writeProgress.current} records.`,
                        time: new Date().toLocaleTimeString(),
                    });
                    break;
                }

                // If paused, wait until resumed or cancelled
                while (this.writeProgress.isPaused && !this.writeProgress.isCancelled) {
                    await new Promise(resolve => setTimeout(resolve, 250));
                }

                if (this.writeProgress.isCancelled) {
                    break;
                }

                const rec = list[i];
                this.writeProgress.current = i + 1;
                this.writeProgress.currentRecord = rec;

                try {
                    const response = await axios.post(route('archive.write-single'), {
                        record: rec,
                        year: this.selectedYear,
                        month: this.selectedMonth,
                    });

                    if (response.data.status === 200) {
                        const action = response.data.action; // 'created' or 'updated'
                        if (action === 'updated') {
                            this.writeProgress.updatedCount++;
                        } else {
                            this.writeProgress.createdCount++;
                        }

                        // Mark record as written in local state
                        const targetIndex = this.records.findIndex(r => r.id === rec.id);
                        if (targetIndex > -1) {
                            this.records[targetIndex].is_written = true;
                            this.records[targetIndex].salary_generate_id = response.data.salary_generate_id;
                        }

                        // Remove from selection if it was selected
                        const selIdx = this.selectedRecordIds.indexOf(rec.id);
                        if (selIdx > -1) {
                            this.selectedRecordIds.splice(selIdx, 1);
                        }

                        this.recalculateFileSummary();

                        this.addProgressLog({
                            type: action,
                            employeeName: response.data.employee_name,
                            employeeCode: response.data.employee_code,
                            netPayable: response.data.net_payable,
                            message: response.data.log || response.data.message,
                            time: new Date().toLocaleTimeString(),
                            step: i + 1,
                        });
                    } else {
                        throw new Error(response.data.message || 'Server returned non-200 status');
                    }
                } catch (err) {
                    this.writeProgress.errorCount++;
                    const errMsg = err.response?.data?.message || err.message || 'Failed to write record.';
                    this.addProgressLog({
                        type: 'error',
                        employeeName: rec.employee_info?.name || 'Unknown',
                        employeeCode: rec.employee_info?.service_id || 'N/A',
                        message: errMsg,
                        time: new Date().toLocaleTimeString(),
                        step: i + 1,
                    });
                }
            }

            this.writing = false;
            this.writeProgress.isProcessing = false;
            this.writeProgress.currentRecord = null;

            // Update written_count in archiveFiles list
            const targetFile = this.archiveFiles.find(
                f => f.year === this.selectedYear && f.month === this.selectedMonth
            );
            if (targetFile) {
                targetFile.written_count = this.summary.written_count;
                targetFile.is_complete = targetFile.written_count >= targetFile.records_count;
            }

            const durationSec = ((new Date() - startTime) / 1000).toFixed(1);
            this.addProgressLog({
                type: 'done',
                message: `Sequence finished in ${durationSec}s. Processed: ${this.writeProgress.current}, Created: ${this.writeProgress.createdCount}, Softly Updated: ${this.writeProgress.updatedCount}, Errors: ${this.writeProgress.errorCount}.`,
                time: new Date().toLocaleTimeString(),
            });

            return true;
        },

        pauseWriteQueue() {
            this.writeProgress.isPaused = true;
            this.addProgressLog({
                type: 'warning',
                message: 'Writing queue paused by user.',
                time: new Date().toLocaleTimeString(),
            });
        },

        resumeWriteQueue() {
            this.writeProgress.isPaused = false;
            this.addProgressLog({
                type: 'info',
                message: 'Writing queue resumed.',
                time: new Date().toLocaleTimeString(),
            });
        },

        cancelWriteQueue() {
            this.writeProgress.isCancelled = true;
            this.writeProgress.isPaused = false;
            this.addProgressLog({
                type: 'warning',
                message: 'Writing cancellation requested. Finishing current request...',
                time: new Date().toLocaleTimeString(),
            });
        },

        closeWriteModal() {
            if (this.writeProgress.isProcessing) {
                return;
            }
            this.writeProgress.isOpen = false;
        },

        addProgressLog(entry) {
            this.writeProgress.logs.push({
                id: (Date.now() + Math.random()).toString(36),
                ...entry,
            });
        },

        nameToKey(name) {
            return (name || '')
                .toLowerCase()
                .replace(/[\s\-]+/g, '_')
                .replace(/[^a-z0-9_]/g, '');
        },
    },
});
