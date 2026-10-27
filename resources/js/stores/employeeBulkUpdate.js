import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';
import { useEmployeeStore } from '@/stores/employee';

export const useEmployeeBulkUpdateStore = defineStore('employeeBulkUpdate', {
    state: () => ({
        isOpen: false,
        isProcessing: false,
        isPaused: false,
        isCancelled: false,
        isCompleted: false,

        // Target selections
        selectedIds: [],
        isSelectAllFiltered: false,
        activeFilters: {},
        totalTargetsCount: 0,
        targetEmployees: [],

        // Queue Execution States
        currentProcessingIndex: 0,
        currentEmployee: null,
        progressPercent: 0,
        stats: {
            total: 0,
            completed: 0,
            successful: 0,
            failed: 0,
        },

        // Execution Logs
        logs: [],

        // Cached Dropdown Metadata
        departmentOffices: [],
        payScales: [],
        grades: [],
        payrollGroups: [],
        userCategories: [],

        // Form Fields and Toggles
        fields: {
            isActive: {
                enabled: false,
                value: true,
            },
            onLeave: {
                enabled: false,
                onLeave: false,
                reason: 'Casual Leave',
                isPaid: true,
                duration: 1,
            },
            jobStatus: {
                enabled: false,
                value: 'permanent',
            },
            pay_scale_id: {
                enabled: false,
                value: '',
            },
            grade_id: {
                enabled: false,
                value: '',
            },
            payroll_group_id: {
                enabled: false,
                value: '',
            },
            incrementDate: {
                enabled: false,
                value: '',
            },
            holdIncrement: {
                enabled: false,
                value: false,
            },
            department_office_id: {
                enabled: false,
                value: '',
            },
            user_category_id: {
                enabled: false,
                value: '',
            },
            gender: {
                enabled: false,
                value: 'male',
            },
            religion: {
                enabled: false,
                value: 'Islam',
            },
            maritial_status: {
                enabled: false,
                value: 'single',
            },
        },
    }),

    getters: {
        enabledFieldsCount: (state) => {
            return Object.values(state.fields).filter((f) => f.enabled).length;
        },

        enabledFieldKeys: (state) => {
            return Object.keys(state.fields).filter((k) => state.fields[k].enabled);
        },

        availableGrades: (state) => {
            if (!state.fields.pay_scale_id.value) return state.grades || [];
            return (state.grades || []).filter(
                (g) => Number(g.pay_scale_id) === Number(state.fields.pay_scale_id.value)
            );
        },

        availablePayrollGroups: (state) => {
            if (state.fields.grade_id.value) {
                return (state.payrollGroups || []).filter(
                    (pg) => Number(pg.grade_id) === Number(state.fields.grade_id.value)
                );
            }
            if (state.fields.pay_scale_id.value) {
                const gradeIds = (state.grades || [])
                    .filter((g) => Number(g.pay_scale_id) === Number(state.fields.pay_scale_id.value))
                    .map((g) => Number(g.id));
                return (state.payrollGroups || []).filter((pg) => gradeIds.includes(Number(pg.grade_id)));
            }
            return state.payrollGroups || [];
        },
    },

    actions: {
        setMetadata(props) {
            if (props.departmentOffices) this.departmentOffices = props.departmentOffices;
            if (props.payScales) this.payScales = props.payScales;
            if (props.grades) this.grades = props.grades;
            if (props.payrollGroups) this.payrollGroups = props.payrollGroups;
            if (props.userCategories) this.userCategories = props.userCategories;
        },

        openModal({ selectedIds = [], isSelectAllFiltered = false, filters = {}, totalCount = 0, currentEmployees = [] }) {
            this.selectedIds = [...selectedIds];
            this.isSelectAllFiltered = isSelectAllFiltered;
            this.activeFilters = { ...filters };
            this.totalTargetsCount = isSelectAllFiltered ? totalCount : selectedIds.length;

            if (isSelectAllFiltered) {
                this.targetEmployees = [];
            } else {
                // Map from current visible employees or fill placeholders
                this.targetEmployees = selectedIds.map((id) => {
                    const found = currentEmployees.find((e) => e.id === id);
                    return {
                        id,
                        name: found ? found.name : `Employee #${id}`,
                        employeeCode: found?.employee_info?.employeeCode || 'N/A',
                        email: found ? found.email : '',
                        gender: found ? found.gender : '',
                    };
                });
            }

            // Reset progress execution states
            this.isProcessing = false;
            this.isPaused = false;
            this.isCancelled = false;
            this.isCompleted = false;
            this.progressPercent = 0;
            this.currentEmployee = null;
            this.currentProcessingIndex = 0;
            this.stats = {
                total: this.totalTargetsCount,
                completed: 0,
                successful: 0,
                failed: 0,
            };

            this.isOpen = true;
        },

        closeModal() {
            if (this.isProcessing) {
                const adminStore = useAdminStore();
                adminStore.showError(
                    'Update In Progress',
                    'A bulk update is currently processing. Please pause or cancel the update before closing.'
                );
                return;
            }
            this.isOpen = false;
        },

        toggleField(key, forceState = null) {
            if (this.fields[key]) {
                this.fields[key].enabled = forceState !== null ? forceState : !this.fields[key].enabled;
            }
        },

        enableAllFields() {
            Object.keys(this.fields).forEach((k) => {
                this.fields[k].enabled = true;
            });
        },

        disableAllFields() {
            Object.keys(this.fields).forEach((k) => {
                this.fields[k].enabled = false;
            });
        },

        resetForm() {
            this.fields = {
                isActive: { enabled: false, value: true },
                onLeave: { enabled: false, onLeave: false, reason: 'Casual Leave', isPaid: true, duration: 1 },
                jobStatus: { enabled: false, value: 'permanent' },
                pay_scale_id: { enabled: false, value: '' },
                grade_id: { enabled: false, value: '' },
                payroll_group_id: { enabled: false, value: '' },
                incrementDate: { enabled: false, value: '' },
                holdIncrement: { enabled: false, value: false },
                department_office_id: { enabled: false, value: '' },
                user_category_id: { enabled: false, value: '' },
                gender: { enabled: false, value: 'male' },
                religion: { enabled: false, value: 'Islam' },
                maritial_status: { enabled: false, value: 'single' },
            };
        },

        onPayScaleChange(newScaleId) {
            if (this.fields.grade_id.value) {
                const grade = (this.grades || []).find((g) => Number(g.id) === Number(this.fields.grade_id.value));
                if (!grade || Number(grade.pay_scale_id) !== Number(newScaleId)) {
                    this.fields.grade_id.value = '';
                    this.fields.payroll_group_id.value = '';
                }
            }
        },

        onGradeChange(newGradeId) {
            if (this.fields.payroll_group_id.value) {
                const pg = (this.payrollGroups || []).find(
                    (p) => Number(p.id) === Number(this.fields.payroll_group_id.value)
                );
                if (!pg || Number(pg.grade_id) !== Number(newGradeId)) {
                    this.fields.payroll_group_id.value = '';
                }
            }
        },

        addLog({ type = 'info', employeeName = '', employeeCode = '', message = '', changes = [], details = null }) {
            const timeStr = new Date().toLocaleTimeString('en-GB', { hour12: false });
            this.logs.unshift({
                id: `${Date.now()}_${Math.random().toString(36).substr(2, 6)}`,
                time: timeStr,
                type,
                employeeName,
                employeeCode,
                message,
                changes: Array.isArray(changes) ? changes : [],
                details,
            });
        },

        clearLogs() {
            this.logs = [];
        },

        pauseUpdate() {
            this.isPaused = true;
            this.addLog({
                type: 'warning',
                message: 'Bulk update queue paused by administrator.',
            });
        },

        resumeUpdate() {
            this.isPaused = false;
            this.addLog({
                type: 'info',
                message: 'Bulk update queue resumed.',
            });
        },

        cancelUpdate() {
            this.isCancelled = true;
            this.isPaused = false;
            this.addLog({
                type: 'warning',
                message: 'Cancellation requested. Finishing current employee request...',
            });
        },

        async executeBulkUpdate() {
            const adminStore = useAdminStore();
            const employeeStore = useEmployeeStore();

            if (this.enabledFieldsCount === 0) {
                adminStore.showError(
                    'No Settings Selected',
                    'Please enable at least one profile setting to apply in this bulk update.'
                );
                return false;
            }

            // If "select all filtered" was chosen, fetch all employee targets
            let queue = [...this.targetEmployees];
            if (this.isSelectAllFiltered || queue.length === 0) {
                try {
                    this.addLog({
                        type: 'info',
                        message: 'Resolving employee records matching active filters...',
                    });
                    const allFiltered = await employeeStore.fetchAllFilteredEmployees(
                        this.activeFilters,
                        this.selectedIds.length > 0 && !this.isSelectAllFiltered ? this.selectedIds : null
                    );
                    queue = (allFiltered || []).map((emp) => ({
                        id: emp.id,
                        name: emp.name,
                        employeeCode: emp.employee_info?.employeeCode || 'N/A',
                        email: emp.email || '',
                    }));
                    this.targetEmployees = queue;
                } catch (err) {
                    adminStore.showError('Fetch Failed', 'Failed to retrieve list of employees for bulk update.');
                    return false;
                }
            }

            if (queue.length === 0) {
                adminStore.showError('No Employees', 'No employee targets found to update.');
                return false;
            }

            // Setup state for progress execution
            this.stats = {
                total: queue.length,
                completed: 0,
                successful: 0,
                failed: 0,
            };
            this.isProcessing = true;
            this.isPaused = false;
            this.isCancelled = false;
            this.isCompleted = false;
            this.progressPercent = 0;

            const enabledKeys = this.enabledFieldKeys;
            const startTime = Date.now();

            this.addLog({
                type: 'info',
                message: `Starting bulk profile update for ${queue.length} employee(s) across ${enabledKeys.length} attribute(s)...`,
            });

            // Iterate one by one through each employee
            for (let i = 0; i < queue.length; i++) {
                // Check if user paused queue
                while (this.isPaused && !this.isCancelled) {
                    await new Promise((r) => setTimeout(r, 200));
                }

                // Check if user cancelled queue
                if (this.isCancelled) {
                    this.addLog({
                        type: 'warning',
                        message: `Update queue stopped. Processed ${i} of ${queue.length} employees before cancellation.`,
                    });
                    break;
                }

                const emp = queue[i];
                this.currentProcessingIndex = i;
                this.currentEmployee = emp;

                // Construct per-employee payload containing only enabled fields
                const payload = {
                    fields: enabledKeys,
                };

                if (this.fields.isActive.enabled) {
                    payload.isActive = Boolean(this.fields.isActive.value);
                }
                if (this.fields.jobStatus.enabled) {
                    payload.jobStatus = this.fields.jobStatus.value;
                }
                if (this.fields.onLeave.enabled) {
                    payload.onLeave = {
                        onLeave: Boolean(this.fields.onLeave.onLeave),
                        reason: this.fields.onLeave.reason || 'Administrative Leave',
                        isPaid: Boolean(this.fields.onLeave.isPaid),
                        duration: Number(this.fields.onLeave.duration) || 0,
                    };
                }
                if (this.fields.pay_scale_id.enabled && this.fields.pay_scale_id.value) {
                    payload.pay_scale_id = this.fields.pay_scale_id.value;
                }
                if (this.fields.payroll_group_id.enabled && this.fields.payroll_group_id.value) {
                    payload.payroll_group_id = this.fields.payroll_group_id.value;
                }
                if (this.fields.incrementDate.enabled && this.fields.incrementDate.value) {
                    payload.incrementDate = this.fields.incrementDate.value;
                }
                if (this.fields.holdIncrement.enabled) {
                    payload.holdIncrement = Boolean(this.fields.holdIncrement.value);
                }
                if (this.fields.department_office_id.enabled && this.fields.department_office_id.value) {
                    payload.department_office_id = this.fields.department_office_id.value;
                }
                if (this.fields.user_category_id.enabled && this.fields.user_category_id.value) {
                    payload.user_category_id = this.fields.user_category_id.value;
                }
                if (this.fields.gender.enabled && this.fields.gender.value) {
                    payload.gender = this.fields.gender.value;
                }
                if (this.fields.religion.enabled && this.fields.religion.value) {
                    payload.religion = this.fields.religion.value;
                }
                if (this.fields.maritial_status.enabled && this.fields.maritial_status.value) {
                    payload.maritial_status = this.fields.maritial_status.value;
                }

                try {
                    const response = await axios.patch(route('employees.bulkProfileUpdateSingle', emp.id), payload);

                    if (response.data && response.data.success) {
                        this.stats.successful++;
                        this.addLog({
                            type: 'success',
                            employeeName: emp.name,
                            employeeCode: response.data.employee?.employeeCode || emp.employeeCode,
                            message: response.data.message || 'Profile updated successfully',
                            changes: response.data.changes || [],
                            details: response.data,
                        });
                    } else {
                        this.stats.failed++;
                        this.addLog({
                            type: 'error',
                            employeeName: emp.name,
                            employeeCode: emp.employeeCode,
                            message: response.data?.message || 'Server returned unsuccessful response',
                            details: response.data,
                        });
                    }
                } catch (error) {
                    this.stats.failed++;
                    const errMsg =
                        error.response?.data?.message ||
                        (error.response?.data?.errors ? Object.values(error.response.data.errors).flat().join(', ') : null) ||
                        error.message ||
                        'Failed to update employee';

                    this.addLog({
                        type: 'error',
                        employeeName: emp.name,
                        employeeCode: emp.employeeCode,
                        message: errMsg,
                        details: error.response?.data,
                    });
                }

                this.stats.completed = i + 1;
                this.progressPercent = Math.round(((i + 1) / queue.length) * 100);

                // Small micro-delay for smooth UI rendering
                await new Promise((r) => setTimeout(r, 40));
            }

            const elapsedSec = ((Date.now() - startTime) / 1000).toFixed(1);
            this.isProcessing = false;
            this.isCompleted = true;
            this.currentEmployee = null;

            this.addLog({
                type: 'done',
                message: `Bulk update run finished in ${elapsedSec}s. Succeeded: ${this.stats.successful}, Failed: ${this.stats.failed}, Total: ${this.stats.completed}.`,
            });

            // Automatically refresh employee list in background
            employeeStore.fetchEmployees(employeeStore.employees.current_page || 1);

            if (this.stats.failed === 0) {
                adminStore.showSuccess(
                    'Bulk Update Complete',
                    `Successfully updated all ${this.stats.successful} employee profile(s).`
                );
            } else {
                adminStore.showWarning(
                    'Bulk Update Finished with Issues',
                    `Completed with ${this.stats.successful} successful and ${this.stats.failed} failed record(s). Check the log terminal for details.`
                );
            }

            return true;
        },
    },
});
