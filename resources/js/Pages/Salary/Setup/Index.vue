<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { useSalarySetupStore } from '@/stores/salarySetup';
import { useAdminStore } from '@/stores/admin';
import { storeToRefs } from 'pinia';
import Swal from 'sweetalert2';

const props = defineProps({
    payScales: {
        type: Array,
        default: () => []
    },
    grades: {
        type: Array,
        default: () => []
    },
    payrollGroupTypes: {
        type: Array,
        default: () => []
    },
    payrollComponentTypes: {
        type: Array,
        default: () => []
    },
    payrollGroups: {
        type: Array,
        default: () => []
    },
    payrollComponents: {
        type: Array,
        default: () => []
    },
    allPayrollComponents: {
        type: Array,
        default: () => []
    },
    userCategories: {
        type: Array,
        default: () => []
    },
    departmentOffices: {
        type: Array,
        default: () => []
    },
    religions: {
        type: Array,
        default: () => []
    },
    genders: {
        type: Array,
        default: () => []
    }
});

const adminStore = useAdminStore();
const salaryStore = useSalarySetupStore();
const { employees, loading, calculatingId, sendingReviewId, bulkSendingReview, selectedUserIds, selectedEmployee, detailsLoading, filters, activeFilterCount } = storeToRefs(salaryStore);

// Local state for filters form to allow user to adjust before hitting "Search results"
const localFilters = ref({
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
});

// Modal state
const isDetailModalOpen = ref(false);

// Cascading grades based on selected pay scale
const availableGrades = computed(() => {
    if (!localFilters.value.pay_scale_id) {
        return props.grades;
    }
    return props.grades.filter(g => String(g.pay_scale_id) === String(localFilters.value.pay_scale_id));
});

// Cascading payroll groups (designations) just like in Create Employee page
const availablePayrollGroups = computed(() => {
    if (!localFilters.value.grade_id) {
        if (localFilters.value.pay_scale_id) {
            const scaleGradeIds = availableGrades.value.map(g => g.id);
            return (props.payrollGroups || []).filter(pg => scaleGradeIds.includes(pg.grade_id));
        }
        return props.payrollGroups || [];
    }
    return (props.payrollGroups || []).filter(pg => String(pg.grade_id) === String(localFilters.value.grade_id));
});

// Reset grade and designation if they don't belong to the newly selected pay scale
watch(() => localFilters.value.pay_scale_id, (newScaleId) => {
    if (newScaleId && localFilters.value.grade_id) {
        const gradeValid = props.grades.some(
            g => String(g.id) === String(localFilters.value.grade_id) && String(g.pay_scale_id) === String(newScaleId)
        );
        if (!gradeValid) {
            localFilters.value.grade_id = '';
            localFilters.value.payroll_group_id = '';
        }
    }
});

// Reset designation if it doesn't belong to the newly selected grade
watch(() => localFilters.value.grade_id, (newGradeId) => {
    if (newGradeId && localFilters.value.payroll_group_id) {
        const groupValid = (props.payrollGroups || []).some(
            pg => String(pg.id) === String(localFilters.value.payroll_group_id) && String(pg.grade_id) === String(newGradeId)
        );
        if (!groupValid) {
            localFilters.value.payroll_group_id = '';
        }
    }
});

onMounted(() => {
    // Sync local filters with store
    localFilters.value = { ...salaryStore.filters };
    salaryStore.fetchEmployees(1);
});

// Execute Search with current filter values
const handleSearch = () => {
    salaryStore.setFilters(localFilters.value);
};

// Reset all filter criteria
const handleReset = () => {
    salaryStore.resetFilters();
    localFilters.value = { ...salaryStore.filters };
};

// Change page
const changePage = (page) => {
    if (page >= 1 && page <= employees.value.last_page && page !== employees.value.current_page) {
        salaryStore.fetchEmployees(page);
    }
};

// Track loading state for individual row details button
const loadingDetailId = ref(null);

// View Employee Salary Setup Details
const openDetailModal = async (emp) => {
    loadingDetailId.value = emp.id;
    try {
        const data = await salaryStore.fetchEmployeeDetails(emp.id);
        if (data && data.id) {
            if (data.effective_payroll?.source === 'approved_fixation'
                && Array.isArray(data.effective_payroll.effective_allowances)
                && Array.isArray(data.effective_payroll.effective_deductions)) {
                // Display the approved effective components without changing
                // persisted employee_infos or their 2015 component configuration.
                data.employee_info.additionObjects = JSON.parse(JSON.stringify(data.effective_payroll.effective_allowances));
                data.employee_info.substractionObjects = JSON.parse(JSON.stringify(data.effective_payroll.effective_deductions));
                salaryStore.selectedEmployee = data;
            }
            ensureArraysForSelectedEmployee();
            selectedComponentId.value = '';
            isDetailModalOpen.value = true;
        }
    } finally {
        loadingDetailId.value = null;
    }
};

// Close and clear detail modal data
const closeDetailModal = () => {
    isDetailModalOpen.value = false;
    selectedComponentId.value = '';
    salaryStore.clearSelectedEmployee();
};

// Clear modal data whenever the modal is closed
watch(isDetailModalOpen, (isOpen) => {
    if (!isOpen) {
        selectedComponentId.value = '';
        salaryStore.clearSelectedEmployee();
    }
});

// Recalculate salary setup
const handleRecalculate = async (empId) => {
    const preview = await salaryStore.recalculateSalary(empId);
    if (!preview?.preview_only) return;
    const additions = preview.defaults?.additions?.length || 0;
    const deductions = preview.defaults?.deductions?.length || 0;
    const financial = preview.defaults?.financial;
    const result = await Swal.fire({
        title: 'Preview Payroll Group Defaults',
        html: `<p>Group defaults have ${additions} allowances and ${deductions} deductions.</p>` +
            `<p><strong>Draft Net: ${Number(financial?.net_salary || 0).toLocaleString('en-BD')}</strong></p>` +
            '<p class="mt-2 text-red-700">Applying them replaces existing manual component values. The old settings will be saved to audit history.</p>',
        input: 'text', inputLabel: 'Reason for replacing current components',
        inputPlaceholder: 'Explain why HR wants to replace manually configured components',
        showCancelButton: true, confirmButtonText: 'Apply Defaults', confirmButtonColor: '#047857',
        preConfirm: value => {
            if (!String(value || '').trim()) {
                Swal.showValidationMessage('An audit reason is required.'); return false;
            }
            return value;
        }
    });
    if (result.isConfirmed) await salaryStore.applyDefaultSalary(empId, result.value);
};

const applyPeriod = () => salaryStore.setFilters(localFilters.value);
const monthLabels = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const moneyEffective = value => value === null || value === undefined ? '—'
    : Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const calculatorUrl = emp => route('salary-calculator.show', { user: emp.id })
    + `?year=${localFilters.value.salary_year}&month=${localFilters.value.salary_month}`;

// Selection logic for bulk actions
const isAllPageSelected = computed(() => {
    if (!employees.value?.data?.length) return false;
    return employees.value.data.every(e => selectedUserIds.value.includes(e.id));
});

const toggleSelectAllPage = (e) => {
    const checked = e.target.checked;
    const pageIds = (employees.value?.data || []).map(emp => emp.id);
    if (checked) {
        selectedUserIds.value = Array.from(new Set([...selectedUserIds.value, ...pageIds]));
    } else {
        selectedUserIds.value = selectedUserIds.value.filter(id => !pageIds.includes(id));
    }
};

// Single Send Review email action
const handleSendReview = async (emp) => {
    const result = await Swal.fire({
        title: 'Send Review Email?',
        text: `Send currently estimated salary breakdown via email to ${emp.name} (${emp.email})?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, Send review',
        cancelButtonText: 'Cancel'
    });

    if (result.isConfirmed) {
        await salaryStore.sendReview(emp.id);
    }
};

// Bulk Send Review email for selected employees
const handleSendBulkReviewSelected = async () => {
    if (!selectedUserIds.value.length) return;
    const count = selectedUserIds.value.length;
    const result = await Swal.fire({
        title: 'Send Bulk Review Emails?',
        text: `Send estimated salary breakdown emails to ${count} selected employee(s)?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#6b7280',
        confirmButtonText: `Yes, Send to ${count} Employees`,
        cancelButtonText: 'Cancel'
    });

    if (result.isConfirmed) {
        await salaryStore.sendBulkReview(selectedUserIds.value, false);
    }
};

// Bulk Send Review email for all filtered employees
const handleSendBulkReviewFiltered = async () => {
    const total = employees.value.total || 0;
    if (total === 0) return;
    const result = await Swal.fire({
        title: 'Send Bulk Review Emails?',
        text: `Send estimated salary breakdown emails to all ${total} filtered employee(s)?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#6b7280',
        confirmButtonText: `Yes, Send to All (${total})`,
        cancelButtonText: 'Cancel'
    });

    if (result.isConfirmed) {
        await salaryStore.sendBulkReview([], true);
    }
};

// Available Payroll Components across all groups
const allComponents = computed(() => {
    if (props.payrollComponents?.length) return props.payrollComponents;
    if (props.allPayrollComponents?.length) return props.allPayrollComponents;
    return [];
});

// Grouped components by payroll group / designation for clean dropdown presentation
const groupedPayrollComponents = computed(() => {
    const groups = {};
    const comps = allComponents.value || [];
    comps.forEach(comp => {
        const groupName = comp.payroll_group?.payroll_group_type?.group_name || `Group #${comp.payroll_group_id}`;
        const gradeName = comp.payroll_group?.grade?.grade_name ? ` (Grade: ${comp.payroll_group.grade.grade_name})` : '';
        const title = `${groupName}${gradeName}`;
        if (!groups[title]) {
            groups[title] = [];
        }
        groups[title].push(comp);
    });
    return groups;
});

// Selected component ID in modal dropdown
const selectedComponentId = ref('');

// Parse JSON objects safely
const parseJsonArray = (data) => {
    if (!data) return [];
    if (Array.isArray(data)) return data;
    try {
        const parsed = JSON.parse(data);
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
};

// Normalize component object to ensure calculation array object structure remains identical
// Normalize component object to ensure calculation array object structure remains identical
const normalizeComponent = (comp) => {
    if (!comp) return null;
    const compType = (props.payrollComponentTypes || []).find(t => String(t.id) === String(comp.payroll_component_type_id));
    const typeName = comp.name || comp.payroll_component_type?.name || compType?.name || null;

    // Find the base/default component to identify original default amount and calc type
    const defaultComp = (allComponents.value || []).find(
        c => (comp.id && String(c.id) === String(comp.id)) ||
             (comp.payroll_component_type_id && String(c.payroll_component_type_id) === String(comp.payroll_component_type_id) &&
              comp.payroll_group_id && String(c.payroll_group_id) === String(comp.payroll_group_id))
    );

    const defaultAmount = comp.default_amount != null
        ? String(comp.default_amount)
        : (defaultComp?.amount != null ? String(defaultComp.amount) : String(comp.amount ?? '0.00'));

    const defaultCalcType = comp.default_calc_type
        ? String(comp.default_calc_type).toLowerCase()
        : (defaultComp?.calcType ? String(defaultComp.calcType).toLowerCase() : String(comp.calcType || 'fixed').toLowerCase());

    const currentCalcType = String(comp.calcType || defaultCalcType || 'fixed').toLowerCase();

    const isRuleOver = comp.ruleOver === true || comp.ruleOver === 'true' || comp.ruleOver === 1 ||
        (parseFloat(comp.amount) !== parseFloat(defaultAmount)) ||
        (currentCalcType !== defaultCalcType);

    return {
        id: comp.id != null ? Number(comp.id) : null,
        payroll_group_id: comp.payroll_group_id != null ? Number(comp.payroll_group_id) : null,
        payroll_component_type_id: comp.payroll_component_type_id != null ? Number(comp.payroll_component_type_id) : null,
        name: typeName,
        component_type: comp.component_type || 'allowance',
        calcType: currentCalcType,
        default_calc_type: defaultCalcType,
        is_taxable: Number(comp.is_taxable) ? 1 : 0,
        isActive: comp.isActive !== undefined ? (Number(comp.isActive) ? 1 : 0) : 1,
        amount: comp.amount != null ? String(comp.amount) : '0.00',
        default_amount: defaultAmount,
        ruleOver: isRuleOver ? true : false,
        created_at: comp.created_at || new Date().toISOString(),
        updated_at: new Date().toISOString(),
        payroll_component_type: comp.payroll_component_type ? {
            id: comp.payroll_component_type.id,
            name: comp.payroll_component_type.name,
            component_description: comp.payroll_component_type.component_description
        } : (compType ? {
            id: compType.id,
            name: compType.name,
            component_description: compType.component_description
        } : null)
    };
};

// Check and mark ruleOver status based on amount and calculation type
const checkRuleOver = (item) => {
    if (!item) return;
    const currentVal = parseFloat(item.amount);
    const defaultVal = parseFloat(item.default_amount);

    const amountChanged = (!isNaN(currentVal) && !isNaN(defaultVal))
        ? (currentVal !== defaultVal)
        : (String(item.amount || '').trim() !== String(item.default_amount || '').trim());

    const defaultCalcType = String(item.default_calc_type || 'fixed').toLowerCase();
    const currentCalcType = String(item.calcType || 'fixed').toLowerCase();
    const calcTypeChanged = defaultCalcType !== currentCalcType;

    item.ruleOver = amountChanged || calcTypeChanged;
};

// Handle amount editing and mark ruleOver status
const handleAmountChange = (item) => {
    checkRuleOver(item);
};

// Handle calculation type editing and mark ruleOver status
const handleCalcTypeChange = (item) => {
    checkRuleOver(item);
};

// Ensure additionObjects and substractionObjects are reactive arrays
const ensureArraysForSelectedEmployee = () => {
    if (!selectedEmployee.value?.employee_info) return;
    const info = selectedEmployee.value.employee_info;
    if (typeof info.additionObjects === 'string') {
        info.additionObjects = parseJsonArray(info.additionObjects).map(normalizeComponent).filter(Boolean);
    } else if (Array.isArray(info.additionObjects)) {
        info.additionObjects = info.additionObjects.map(normalizeComponent).filter(Boolean);
    } else {
        info.additionObjects = [];
    }

    if (typeof info.substractionObjects === 'string') {
        info.substractionObjects = parseJsonArray(info.substractionObjects).map(normalizeComponent).filter(Boolean);
    } else if (Array.isArray(info.substractionObjects)) {
        info.substractionObjects = info.substractionObjects.map(normalizeComponent).filter(Boolean);
    } else {
        info.substractionObjects = [];
    }
};

watch(() => selectedEmployee.value, () => {
    ensureArraysForSelectedEmployee();
}, { immediate: true });

// Helper to identify if a component is Basic pay
const isBasicPayComponent = (item) => {
    if (!item) return false;
    const name = String(item.name || item.payroll_component_type?.name || getComponentTypeName(item.payroll_component_type_id) || '').toLowerCase();
    return name.includes('basic');
};

// Helper to find basic pay item in additions
const getBasicPayItem = (additions) => {
    const list = Array.isArray(additions) ? additions : parseJsonArray(additions);
    return list.find(item => isBasicPayComponent(item));
};

// Helper to get basic pay amount
const getBasicPayAmount = (additions) => {
    const item = getBasicPayItem(additions);
    return item ? (parseFloat(item.amount) || 0) : 0;
};

// Helper to check if basic pay allowance exists and has an amount > 0
const hasBasicPayAllowance = (additions) => {
    const item = getBasicPayItem(additions);
    return !!item && (parseFloat(item.amount) || 0) > 0;
};

// Calculate effective amount for a single component
const getCalculatedComponentAmount = (item, basicAmount = 0) => {
    if (!item) return 0;
    const raw = parseFloat(item.amount) || 0;
    const type = String(item.calcType || 'fixed').toLowerCase();
    if (type === 'percentage') {
        return (parseFloat(basicAmount) || 0) * (raw / 100);
    }
    return raw;
};

// Detect if percentage calculation is selected but Basic pay is missing or 0
const hasPercentageWithoutBasic = computed(() => {
    if (!selectedEmployee.value?.employee_info) return false;
    const additions = selectedEmployee.value.employee_info.additionObjects || [];
    const deductions = selectedEmployee.value.employee_info.substractionObjects || [];
    const basicExists = hasBasicPayAllowance(additions);

    const allItems = [...additions, ...deductions];
    const hasPercentage = allItems.some(i => String(i.calcType || 'fixed').toLowerCase() === 'percentage');

    return hasPercentage && !basicExists;
});

// Inject component object into allowance or deduction array
const handleInjectComponent = (event) => {
    const compId = selectedComponentId.value || event?.target?.value;
    if (!compId) return;

    const comp = (allComponents.value || []).find(c => String(c.id) === String(compId));
    if (!comp) {
        selectedComponentId.value = '';
        return;
    }

    if (!selectedEmployee.value?.employee_info) {
        adminStore.showError('Error', 'No employee record loaded.');
        selectedComponentId.value = '';
        return;
    }

    ensureArraysForSelectedEmployee();

    const isAllowance = comp.component_type === 'allowance';
    const targetArray = isAllowance
        ? selectedEmployee.value.employee_info.additionObjects
        : selectedEmployee.value.employee_info.substractionObjects;

    // Check duplicate by component id or (component_type_id + group_id)
    const alreadyExists = targetArray.some(
        item => String(item.id) === String(comp.id) ||
                (String(item.payroll_component_type_id) === String(comp.payroll_component_type_id) &&
                 String(item.payroll_group_id) === String(comp.payroll_group_id))
    );

    const typeName = comp.payroll_component_type?.name || comp.name || getComponentTypeName(comp.payroll_component_type_id) || 'Component';

    if (alreadyExists) {
        adminStore.showError('Already Added', `"${typeName}" is already configured in ${isAllowance ? 'allowances' : 'deductions'} for this employee.`);
        selectedComponentId.value = '';
        return;
    }

    // Check allowance is not less than deductions while adding dynamic component
    const basicAmount = getBasicPayAmount(selectedEmployee.value.employee_info.additionObjects);
    const compAmount = parseFloat(comp.amount) || 0;
    const compCalculated = String(comp.calcType || 'fixed').toLowerCase() === 'percentage'
        ? (basicAmount * (compAmount / 100))
        : compAmount;

    const currentAdditions = calculateTotalAdditions(selectedEmployee.value.employee_info.additionObjects);
    const currentDeductions = calculateTotalDeductions(
        selectedEmployee.value.employee_info.substractionObjects,
        selectedEmployee.value.employee_info.additionObjects
    );

    const prospectiveAdditions = isAllowance ? (currentAdditions + compCalculated) : currentAdditions;
    const prospectiveDeductions = !isAllowance ? (currentDeductions + compCalculated) : currentDeductions;

    if (prospectiveAdditions < prospectiveDeductions) {
        adminStore.showError(
            'Invalid Salary Calculation',
            `Cannot add "${typeName}". Total allowances (৳${formatMoney(prospectiveAdditions)}) cannot be less than total deductions (৳${formatMoney(prospectiveDeductions)}).`
        );
        selectedComponentId.value = '';
        return;
    }

    const componentObject = normalizeComponent(comp);
    targetArray.push(componentObject);

    adminStore.showSuccess('Component Injected', `"${typeName}" added to ${isAllowance ? 'allowances' : 'deductions'}. Click "Update calculation" to persist changes.`);
    selectedComponentId.value = '';
};

// Remove allowance row
const removeAllowance = (index) => {
    if (!selectedEmployee.value?.employee_info?.additionObjects) return;
    const removed = selectedEmployee.value.employee_info.additionObjects.splice(index, 1);
    if (removed.length) {
        const name = removed[0].name || getComponentTypeName(removed[0].payroll_component_type_id) || 'Component';
        adminStore.showSuccess('Removed', `"${name}" removed from allowances.`);
    }
};

// Remove deduction row
const removeDeduction = (index) => {
    if (!selectedEmployee.value?.employee_info?.substractionObjects) return;
    const removed = selectedEmployee.value.employee_info.substractionObjects.splice(index, 1);
    if (removed.length) {
        const name = removed[0].name || getComponentTypeName(removed[0].payroll_component_type_id) || 'Component';
        adminStore.showSuccess('Removed', `"${name}" removed from deductions.`);
    }
};

// Helper to get total allowances
const calculateTotalAdditions = (additions) => {
    const list = Array.isArray(additions) ? additions : parseJsonArray(additions);
    const basicAmount = getBasicPayAmount(list);
    return list.reduce((sum, item) => sum + getCalculatedComponentAmount(item, basicAmount), 0);
};

// Helper to get total deductions
const calculateTotalDeductions = (deductions, additions = null) => {
    const deductionList = Array.isArray(deductions) ? deductions : parseJsonArray(deductions);
    const additionList = additions ?? selectedEmployee.value?.employee_info?.additionObjects ?? [];
    const basicAmount = getBasicPayAmount(additionList);
    return deductionList.reduce((sum, item) => sum + getCalculatedComponentAmount(item, basicAmount), 0);
};

// Helper to get component type name by ID
const getComponentTypeName = (typeId) => {
    if (!typeId) return null;
    const foundInProps = (props.payrollComponentTypes || []).find(t => String(t.id) === String(typeId));
    if (foundInProps) return foundInProps.name;

    const comps = selectedEmployee.value?.employee_info?.payroll_group?.payroll_components || [];
    const foundInComps = comps.find(c => String(c.payroll_component_type_id) === String(typeId));
    if (foundInComps?.payroll_component_type?.name) {
        return foundInComps.payroll_component_type.name;
    }
    return null;
};

// Format currency
const formatMoney = (val) => {
    const num = parseFloat(val);
    if (isNaN(num)) return '0.00';
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

// Computed totals and net payable for currently selected employee
const totalAllowances = computed(() => {
    return calculateTotalAdditions(selectedEmployee.value?.employee_info?.additionObjects);
});

const totalDeductions = computed(() => {
    return calculateTotalDeductions(
        selectedEmployee.value?.employee_info?.substractionObjects,
        selectedEmployee.value?.employee_info?.additionObjects
    );
});

const netPayableSalary = computed(() => {
    return totalAllowances.value - totalDeductions.value;
});

// Update salary calculation with validation
const handleUpdateCalculation = async () => {
    if (!selectedEmployee.value) return;
    if (selectedEmployee.value.effective_payroll?.source === 'approved_fixation') {
        adminStore.showError('Use Salary Calculator', 'Approved 2026 salary components are edited in the Employee Salary Calculator so the historical 2015 setup is preserved.');
        return;
    }

    if (hasPercentageWithoutBasic.value) {
        adminStore.showError(
            'Basic Pay Required',
            "Calculation type is set to percentage for one or more components, but 'Basic pay' allowance is absent. Please add a Basic pay allowance first."
        );
        return;
    }

    const currentAdditions = totalAllowances.value;
    const currentDeductions = totalDeductions.value;

    if (currentAdditions < currentDeductions) {
        adminStore.showError(
            'Invalid Salary Calculation',
            `Total allowances (৳${formatMoney(currentAdditions)}) cannot be less than total deductions (৳${formatMoney(currentDeductions)}). Please adjust the component amounts.`
        );
        return;
    }

    await salaryStore.updateSalaryCalculationComponentsForUser(
        selectedEmployee.value.id,
        selectedEmployee.value.employee_info?.additionObjects,
        selectedEmployee.value.employee_info?.substractionObjects
    );
};
</script>

<template>

    <Head title="Salary Setup" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white flex items-center gap-2">
                        <span
                            class="p-2 bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400 rounded-lg">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </span>
                        Salary Setup & Management
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Search, inspect, and configure employee salary structures, pay scales, and allowances.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <span
                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300 border border-gray-200 dark:border-gray-700">
                        Total Records: {{ employees.total || 0 }}
                    </span>
                </div>
            </div>
        </template>

        <div class="py-2 max-w-full mx-auto px-2 sm:px-2 lg:px-2 space-y-6">

            <!-- ========================================== -->
            <!-- 1. SEARCH & FILTER SECTION (Top Section)  -->
            <!-- ========================================== -->
            <div
                class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden transition-all duration-300">
                <div
                    class="p-2 border-b border-gray-100 dark:border-gray-700 flex flex-wrap items-center justify-between gap-3 bg-gray-50/50 dark:bg-gray-800/80">
                    <div class="flex items-center gap-2">
                        <div
                            class="w-8 h-8 rounded-lg bg-green-50 text-green-600 dark:bg-green-900/30 dark:text-green-400 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                Search & Filter
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Refine employee list using specific payroll, institutional, and demographic criteria
                            </p>
                        </div>
                    </div>

                    <!-- Active filter badge -->
                    <div v-if="activeFilterCount > 0" class="flex items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span>
                            {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'filter' : 'filters' }} active
                        </span>
                        <button type="button" @click="handleReset"
                            class="text-xs text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400 underline transition-colors">
                            Clear all
                        </button>
                    </div>
                </div>

                <!-- Filters Form Grid -->
                <form @submit.prevent="handleSearch" class="p-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">

                        <!-- 1. Search by Employee Code -->
                        <div>
                            <InputLabel for="filter_employee_code" value="Employee Code" />
                            <div class="relative mt-1">
                                <span
                                    class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14" />
                                    </svg>
                                </span>
                                <TextInput id="filter_employee_code" type="text" v-model="localFilters.employeeCode"
                                    placeholder="e.g. 1001" class="w-full pl-9 text-sm" />
                            </div>
                        </div>

                        <!-- 2. Employee Category -->
                        <div>
                            <InputLabel for="filter_category" value="Employee Category" />
                            <select id="filter_category" v-model="localFilters.user_category_id"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-green-500 focus:ring-green-500 sm:text-sm transition-colors">
                                <option value="">All Categories</option>
                                <option v-for="cat in userCategories" :key="cat.id" :value="cat.id">
                                    {{ cat.name }}
                                </option>
                            </select>
                        </div>

                        <!-- 3. Pay Scale -->
                        <div>
                            <InputLabel for="filter_payscale" value="Pay Scale" />
                            <select id="filter_payscale" v-model="localFilters.pay_scale_id"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-green-500 focus:ring-green-500 sm:text-sm transition-colors">
                                <option value="">All Pay Scales</option>
                                <option v-for="ps in payScales" :key="ps.id" :value="ps.id">
                                    {{ ps.scale_name }}
                                </option>
                            </select>
                        </div>

                        <!-- 4. Grades (cascades with selected Pay Scale) -->
                        <div>
                            <InputLabel for="filter_grade" value="Grades" />
                            <select id="filter_grade" v-model="localFilters.grade_id"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-green-500 focus:ring-green-500 sm:text-sm transition-colors">
                                <option value="">All Grades</option>
                                <option v-for="grade in availableGrades" :key="grade.id" :value="grade.id">
                                    {{ grade.grade_name }} ({{ formatMoney(grade.min) }} - {{ formatMoney(grade.max) }})
                                </option>
                            </select>
                        </div>

                        <!-- 5. Gender -->
                        <div>
                            <InputLabel for="filter_gender" value="Gender" />
                            <select id="filter_gender" v-model="localFilters.gender"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-green-500 focus:ring-green-500 sm:text-sm transition-colors">
                                <option value="">All Genders</option>
                                <option v-for="g in genders" :key="g.value" :value="g.value">
                                    {{ g.label }}
                                </option>
                            </select>
                        </div>

                        <!-- 6. Profile Active Status -->
                        <div>
                            <InputLabel for="filter_is_active" value="Profile Active Status" />
                            <select id="filter_is_active" v-model="localFilters.isActive"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-green-500 focus:ring-green-500 sm:text-sm transition-colors">
                                <option value="">All Statuses</option>
                                <option value="true">Active Only</option>
                                <option value="false">Inactive Only</option>
                            </select>
                        </div>

                        <!-- 7. Designation (from payroll_group_type like Create page) -->
                        <div>
                            <InputLabel for="filter_designation" value="Designation" />
                            <select id="filter_designation" v-model="localFilters.payroll_group_id"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-green-500 focus:ring-green-500 sm:text-sm transition-colors">
                                <option value="">All Designations</option>
                                <option v-for="pg in availablePayrollGroups" :key="pg.id" :value="pg.id">
                                    {{ pg.payroll_group_type?.group_name || ('Group #' + pg.id) }}
                                </option>
                            </select>
                        </div>

                        <!-- 8. Payroll Component Type -->
                        <div>
                            <InputLabel for="filter_component_type" value="Payroll Component Type" />
                            <select id="filter_component_type" v-model="localFilters.payroll_component_type_id"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-green-500 focus:ring-green-500 sm:text-sm transition-colors">
                                <option value="">All Component Types</option>
                                <option v-for="pct in payrollComponentTypes" :key="pct.id" :value="pct.id">
                                    {{ pct.name }}
                                </option>
                            </select>
                        </div>

                        <!-- 9. Joining Date -->
                        <div>
                            <InputLabel for="filter_joining_date" value="Joining Date" />
                            <input id="filter_joining_date" type="date" v-model="localFilters.joining_date"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-green-500 focus:ring-green-500 sm:text-sm transition-colors py-2 px-3" />
                        </div>

                        <!-- 10. Religion -->
                        <div>
                            <InputLabel for="filter_religion" value="Religion" />
                            <select id="filter_religion" v-model="localFilters.religion"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-green-500 focus:ring-green-500 sm:text-sm transition-colors">
                                <option value="">All Religions</option>
                                <option v-for="rel in religions" :key="rel" :value="rel">
                                    {{ rel }}
                                </option>
                            </select>
                        </div>

                        <!-- Optional: Search by Name / Email -->
                        <div class="sm:col-span-2">
                            <InputLabel for="filter_general_search" value="Search by Name or Email" />
                            <div class="relative mt-1">
                                <span
                                    class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </span>
                                <TextInput id="filter_general_search" type="text" v-model="localFilters.search"
                                    placeholder="Search by employee name or email address..."
                                    class="w-full pl-9 text-sm" />
                            </div>
                        </div>

                    </div>

                    <!-- Filter Action Buttons -->
                    <div
                        class="mt-5 pt-4 border-t border-gray-100 dark:border-gray-700 flex flex-wrap items-center justify-between gap-3">
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            Apply any combination of filters and press Search results.
                        </div>

                        <div class="flex items-center gap-3">
                            <SecondaryButton type="button" @click="handleReset" :disabled="loading"
                                class="flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                Reset Filters
                            </SecondaryButton>

                            <PrimaryButton type="submit" :disabled="loading"
                                class="flex items-center gap-2 !bg-green-600 hover:!bg-green-700 focus:!ring-green-500 shadow-sm">
                                <svg v-if="!loading" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <svg v-else class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                        stroke-width="4">
                                    </circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                                </svg>
                                Search results
                            </PrimaryButton>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ========================================== -->
            <!-- 2. RESULTS & PAGINATED TABLE SECTION       -->
            <!-- ========================================== -->
            <div
                class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div
                    class="p-5 border-b border-gray-100 dark:border-gray-700 flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            Employee Salary Setup Records
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            Showing {{ employees.from || 0 }} to {{ employees.to || 0 }} of {{ employees.total || 0 }}
                            employees
                        </p>
                    </div>

                    <!-- Selected pay period controls the effective salary view; it does not edit employee profiles. -->
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="text-xs text-gray-600 dark:text-gray-300">Salary month
                            <select v-model.number="localFilters.salary_month" @change="applyPeriod" class="block mt-1 rounded-md border-gray-300 text-sm dark:bg-gray-800">
                                <option v-for="m in 12" :key="m" :value="m">{{ monthLabels[m - 1] }}</option>
                            </select>
                        </label>
                        <label class="text-xs text-gray-600 dark:text-gray-300">Year
                            <input v-model.number="localFilters.salary_year" @change="applyPeriod" type="number" min="2020" max="2100" class="block mt-1 w-24 rounded-md border-gray-300 text-sm dark:bg-gray-800"/>
                        </label>
                    </div>

                    <!-- Toolbar Actions -->
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Bulk Send Selected -->
                        <button
                            v-if="selectedUserIds.length > 0"
                            @click="handleSendBulkReviewSelected"
                            :disabled="bulkSendingReview"
                            type="button"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 transition-colors shadow-sm"
                            title="Send review email to selected employees">
                            <svg v-if="bulkSendingReview" class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <svg v-else class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            <span>Send Review ({{ selectedUserIds.length }} Selected)</span>
                        </button>

                        <!-- Bulk Send All Filtered -->
                        <button
                            v-if="employees.total > 0"
                            @click="handleSendBulkReviewFiltered"
                            :disabled="bulkSendingReview"
                            type="button"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 transition-colors shadow-sm"
                            title="Send review email to all filtered employees">
                            <svg v-if="bulkSendingReview" class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <svg v-else class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            <span>Send Review to All ({{ employees.total }})</span>
                        </button>

                        <!-- Quick refresh button -->
                        <button @click="salaryStore.fetchEmployees(employees.current_page)"
                            type="button"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md text-gray-700 bg-gray-100 hover:bg-gray-200 dark:text-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 transition-colors">
                            <svg class="w-3.5 h-3.5" :class="{ 'animate-spin': loading }" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            Refresh
                        </button>
                    </div>
                </div>

                <!-- Table Content -->
                <div class="overflow-x-auto relative min-h-[300px]">

                    <!-- Loading Overlay -->
                    <div v-if="loading"
                        class="absolute inset-0 bg-white/70 dark:bg-gray-800/70 backdrop-blur-xs flex items-center justify-center z-10">
                        <div class="flex flex-col items-center gap-2">
                            <svg class="animate-spin h-8 w-8 text-green-600 dark:text-green-400"
                                xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4">
                                </circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Searching
                                records...</span>
                        </div>
                    </div>

                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-left text-sm">
                        <thead
                            class="bg-gray-50 dark:bg-gray-900/50 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <tr>
                                <th scope="col" class="py-3.5 px-3 w-10 text-center">
                                    <input
                                        type="checkbox"
                                        :checked="isAllPageSelected"
                                        @change="toggleSelectAllPage"
                                        class="rounded-md border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 cursor-pointer"
                                        title="Select all on current page"
                                    />
                                </th>
                                <th scope="col" class="py-3.5 px-4">Employee</th>
                                <th scope="col" class="py-3.5 px-4">Code & Status</th>
                                <th scope="col" class="py-3.5 px-4">Category & Dept</th>
                                <th scope="col" class="py-3.5 px-4">Pay Scale & Grade</th>
                                <th scope="col" class="py-3.5 px-4">Designation</th>
                                <th scope="col" class="py-3.5 px-4">Joining & Religion</th>
                                <th scope="col" class="py-3.5 px-4">Setup Status</th>
                                <th scope="col" class="py-3.5 px-4 text-right">Actions</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60 bg-white dark:bg-gray-800">
                            <!-- Empty State -->
                            <tr v-if="!loading && employees.data.length === 0">
                                <td colspan="9" class="py-12 px-4 text-center">
                                    <div class="max-w-sm mx-auto flex flex-col items-center">
                                        <div
                                            class="w-12 h-12 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-gray-400 mb-3">
                                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                        <p class="text-base font-medium text-gray-900 dark:text-gray-100">No employees
                                            found</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 mb-4">
                                            No employee records matched your current filter criteria. Try broadening
                                            your search
                                            or resetting filters.
                                        </p>
                                        <SecondaryButton @click="handleReset" class="text-xs">
                                            Reset All Filters
                                        </SecondaryButton>
                                    </div>
                                </td>
                            </tr>

                            <!-- Data Rows -->
                            <tr v-for="emp in employees.data" :key="emp.id"
                                class="hover:bg-gray-50/80 dark:hover:bg-gray-700/40 transition-colors">
                                <!-- Checkbox -->
                                <td class="py-3.5 px-3 text-center">
                                    <input
                                        type="checkbox"
                                        :value="emp.id"
                                        v-model="selectedUserIds"
                                        class="rounded-md border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 cursor-pointer"
                                    />
                                </td>

                                <!-- Employee (Name, Email, Gender) -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="w-9 h-9 rounded-full bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300 font-semibold flex items-center justify-center text-xs shrink-0">
                                            {{ emp.name?.substring(0, 2).toUpperCase() || 'EM' }}
                                        </div>
                                        <div>
                                            <div class="font-medium text-gray-900 dark:text-gray-100">
                                                {{ emp.name }}
                                            </div>
                                            <div
                                                class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
                                                <span>{{ emp.email }}</span>
                                                <span
                                                    class="capitalize text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                                    {{ emp.gender }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Code & Status -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="text-xs font-semibold text-gray-800 dark:text-gray-200">
                                        #{{ emp.employee_info?.employeeCode || 'N/A' }}
                                    </div>
                                    <div class="mt-0.5">
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold"
                                            :class="emp.isActive ? 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300' : 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300'">
                                            <span class="w-1.5 h-1.5 rounded-full mr-1"
                                                :class="emp.isActive ? 'bg-green-500' : 'bg-red-500'"></span>
                                            {{ emp.isActive ? 'Active' : 'Inactive' }}
                                        </span>
                                    </div>
                                </td>

                                <!-- Category & Department -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="text-xs font-medium text-gray-900 dark:text-gray-100">
                                        {{ emp.department_offices?.[0]?.name ||
                                            emp.employee_info?.department_office?.name ||
                                        'General' }}
                                    </div>
                                    <div class="flex flex-wrap gap-1 mt-1 max-w-[220px]">
                                        <template v-if="emp.user_categories?.length">
                                            <span
                                                v-for="cat in emp.user_categories"
                                                :key="cat.id"
                                                class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 border border-blue-100 dark:border-blue-900/50">
                                                {{ cat.name }}
                                            </span>
                                        </template>
                                        <span v-else class="text-gray-400 text-[11px]">Unassigned Cat.</span>
                                    </div>
                                </td>

                                <!-- Pay Scale & Grade -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="text-xs font-medium text-gray-900 dark:text-gray-100">
                                        {{ emp.effective_payroll?.pay_scale || emp.employee_info?.pay_scale?.scale_name || 'No Scale' }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ emp.effective_payroll?.grade || emp.employee_info?.payroll_group?.grade?.grade_name || 'No Grade' }}
                                    </div>
                                    <div v-if="emp.effective_payroll?.source === 'approved_fixation'" class="text-[10px] text-emerald-700 mt-1">
                                        Approved Fixation #{{ emp.effective_payroll.salary_fixation_id }} ·
                                        from {{ emp.effective_payroll.effective_date }}
                                    </div>
                                    <div class="text-[11px] font-semibold text-slate-700 dark:text-slate-200">
                                        Basic: {{ moneyEffective(emp.effective_payroll?.basic) }}
                                    </div>
                                    <div v-if="emp.effective_payroll?.error" class="text-[10px] text-red-700">{{ emp.effective_payroll.error }}</div>
                                </td>

                                <!-- Designation -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-50 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300">
                                        {{ emp.employee_info?.payroll_group?.payroll_group_type?.group_name ||
                                        'Unassigned' }}
                                    </span>
                                </td>

                                <!-- Joining & Religion -->
                                <td class="py-3.5 px-4 whitespace-nowrap text-xs text-gray-600 dark:text-gray-300">
                                    <div>
                                        <span class="text-gray-400">Join:</span> {{ emp.joining_date?.joining_date ||
                                        'N/A' }}
                                    </div>
                                    <div class="text-gray-500 dark:text-gray-400 text-[11px]">
                                        {{ emp.religion?.religion || 'N/A' }}
                                    </div>
                                </td>

                                <!-- Setup Status -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div v-if="emp.employee_info?.additionObjects || emp.employee_info?.substractionObjects"
                                        class="flex flex-col gap-0.5">
                                        <span
                                            class="inline-flex items-center gap-1 text-[11px] font-medium text-green-700 dark:text-green-400">
                                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd"
                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                            Configured
                                        </span>
                                        <span class="text-[10px] text-gray-400 font-mono">
                                            +{{ moneyEffective(emp.effective_payroll?.gross) }} | -{{ moneyEffective(emp.effective_payroll?.deductions) }}
                                            <span v-if="emp.effective_payroll?.net !== undefined" class="block text-emerald-700">Net: {{ moneyEffective(emp.effective_payroll.net) }}</span>
                                        </span>
                                    </div>
                                    <div v-else>
                                        <span
                                            class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                                            Pending Calc
                                        </span>
                                    </div>
                                </td>

                                <!-- Actions -->
                                <td class="py-3.5 px-4 whitespace-nowrap text-right text-xs font-medium space-x-2">
                                    <a :href="calculatorUrl(emp)" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md text-xs font-semibold text-white bg-emerald-700 hover:bg-emerald-800" title="Open employee salary calculation (Phase 2 read-only entry)">Calculate Salary</a>
                                    <!-- View Details Button -->
                                    <button type="button" @click="openDetailModal(emp)"
                                        :disabled="loadingDetailId === emp.id"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md text-xs font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 dark:text-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 transition-colors disabled:opacity-50"
                                        title="View Salary Setup Breakdown">
                                        <svg v-if="loadingDetailId !== emp.id" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                        <svg v-else class="animate-spin w-3.5 h-3.5 text-gray-700 dark:text-gray-300" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                                        </svg>
                                        Details
                                    </button>

                                    <!-- Recalculate Button -->
                                    <button type="button" @click="handleRecalculate(emp.id)"
                                        :disabled="calculatingId === emp.id"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md text-xs font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50 transition-colors"
                                        title="Recalculate default designated payroll">
                                        <svg v-if="calculatingId !== emp.id" class="w-3.5 h-3.5" fill="none"
                                            viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                        </svg>
                                        <svg v-else class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z">
                                            </path>
                                        </svg>
                                        Calc. Default
                                    </button>

                                    <!-- Send Review Button -->
                                    <button type="button" @click="handleSendReview(emp)"
                                        :disabled="sendingReviewId === emp.id"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 transition-colors shadow-sm"
                                        title="Send currently estimated salary breakdown via email">
                                        <svg v-if="sendingReviewId !== emp.id" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                        </svg>
                                        <svg v-else class="animate-spin w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                                        </svg>
                                        Send review
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div v-if="employees.last_page > 1"
                    class="p-4 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row items-center justify-between gap-4 bg-gray-50/50 dark:bg-gray-800">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        Page {{ employees.current_page }} of {{ employees.last_page }} ({{ employees.total }} employees)
                    </div>

                    <div class="flex items-center gap-1">
                        <button @click="changePage(employees.current_page - 1)"
                            :disabled="employees.current_page <= 1 || loading"
                            class="px-2.5 py-1 rounded text-xs font-medium text-gray-600 bg-white border border-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-600 transition-colors">
                            Previous
                        </button>

                        <template v-for="link in employees.links" :key="link.label">
                            <button
                                v-if="link.url && !link.label.includes('&laquo;') && !link.label.includes('&raquo;')"
                                @click="changePage(parseInt(link.label))" :disabled="loading"
                                v-html="link.label"
                                class="min-w-5 px-2 h-7 flex items-center justify-center rounded text-xs font-medium transition-colors"
                                :class="link.active ? 'bg-green-600 text-white font-bold' : 'text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-600'">
                                
                            </button>
                        </template>

                        <button @click="changePage(employees.current_page + 1)"
                            :disabled="employees.current_page >= employees.last_page || loading"
                            class="px-2.5 py-1 rounded text-xs font-medium text-gray-600 bg-white border border-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-600 transition-colors">
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- 3. SALARY SETUP DETAILS MODAL              -->
        <!-- ========================================== -->
        <Modal :show="isDetailModalOpen" @close="closeDetailModal" max-width="4xl">
            <div class="p-6">
                <!-- Modal Header -->
                <div class="flex items-center justify-between pb-4 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-10 h-10 rounded-full bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300 flex items-center justify-center font-bold text-sm">
                            {{ selectedEmployee?.name?.substring(0, 2).toUpperCase() || 'EM' }}
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">
                                {{ selectedEmployee?.name || 'Employee Salary Setup' }}
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Code: #{{ selectedEmployee?.employee_info?.employeeCode || 'N/A' }} | {{
                                selectedEmployee?.email
                                }}
                            </p>
                            <div v-if="selectedEmployee?.user_categories?.length" class="flex flex-wrap gap-1 mt-1.5">
                                <span
                                    v-for="cat in selectedEmployee.user_categories"
                                    :key="cat.id"
                                    class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 border border-blue-100 dark:border-blue-900/50">
                                    {{ cat.name }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <button @click="closeDetailModal"
                        class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300 p-1.5 rounded-lg">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Modal Loading State -->
                <div v-if="detailsLoading" class="py-12 flex flex-col items-center justify-center gap-2">
                    <svg class="animate-spin h-7 w-7 text-green-600" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                        </circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    <span class="text-xs text-gray-500">Loading details...</span>
                </div>

                <!-- Modal Body -->
                <div v-else-if="selectedEmployee" class="py-4 space-y-5 max-h-[75vh] overflow-y-auto pr-1">
                    <div v-if="selectedEmployee.effective_payroll?.source === 'approved_fixation'"
                        class="rounded-lg bg-emerald-50 border border-emerald-200 p-3 text-xs text-emerald-900">
                        <strong>Approved 2026 Fixation #{{ selectedEmployee.effective_payroll.salary_fixation_id }}</strong>
                        applies from {{ selectedEmployee.effective_payroll.effective_date }} for this selected payroll month.
                        Effective Basic: <strong>{{ moneyEffective(selectedEmployee.effective_payroll.basic) }}</strong>.
                        The components below show the approved, effective salary preview. Use Calculate Salary to edit or save this period; the original 2015 setup stays unchanged.
                    </div>

                    <!-- Payroll Overview Grid -->
                    <div
                        class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-gray-50 dark:bg-gray-900/50 p-3 rounded-lg border border-gray-100 dark:border-gray-700 text-xs">
                        <div>
                            <span class="text-gray-400 block text-[10px] uppercase">Pay Scale</span>
                            <span class="font-semibold text-gray-800 dark:text-gray-200">
                                {{ selectedEmployee.effective_payroll?.pay_scale || selectedEmployee.employee_info?.pay_scale?.scale_name || 'N/A' }}
                            </span>
                        </div>
                        <div>
                            <span class="text-gray-400 block text-[10px] uppercase">Grade</span>
                            <span class="font-semibold text-gray-800 dark:text-gray-200">
                                {{ selectedEmployee.effective_payroll?.grade || selectedEmployee.employee_info?.payroll_group?.grade?.grade_name || 'N/A' }}
                            </span>
                        </div>
                        <div>
                            <span class="text-gray-400 block text-[10px] uppercase">Stored Group Grade Range (legacy)</span>
                            <span class="font-semibold text-gray-800 dark:text-gray-200">
                                {{ formatMoney(selectedEmployee.employee_info?.payroll_group?.grade?.min) }} - {{
                                    formatMoney(selectedEmployee.employee_info?.payroll_group?.grade?.max) }}
                            </span>
                        </div>
                        <div>
                            <span class="text-gray-400 block text-[10px] uppercase">Designation</span>
                            <span class="font-semibold text-gray-800 dark:text-gray-200">
                                {{ selectedEmployee.employee_info?.payroll_group?.payroll_group_type?.group_name ||
                                'N/A' }}
                            </span>
                        </div>
                    </div>

                    <fieldset :disabled="selectedEmployee.effective_payroll?.source === 'approved_fixation'" class="space-y-4 disabled:opacity-90">
                    <!-- Component Injection Dropdown -->
                    <div class="bg-gray-50 dark:bg-gray-900/40 p-3.5 rounded-lg border border-gray-200 dark:border-gray-700 space-y-2">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1">
                            <label for="modal_component_dropdown" class="text-xs font-bold text-gray-700 dark:text-gray-300 flex items-center gap-1.5 uppercase tracking-wider">
                                <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Add Component from Payroll Groups
                            </label>
                            <span class="text-[11px] text-gray-500 dark:text-gray-400">
                                Selecting an item auto-injects it into allowances or deductions
                            </span>
                        </div>
                        <div class="relative">
                            <select
                                id="modal_component_dropdown"
                                v-model="selectedComponentId"
                                @change="handleInjectComponent"
                                class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 text-xs focus:border-green-500 focus:ring-green-500 py-2 shadow-sm transition-colors cursor-pointer"
                            >
                                <option value="" disabled selected>-- Select a Payroll Component to Add --</option>
                                <optgroup
                                    v-for="(comps, groupTitle) in groupedPayrollComponents"
                                    :key="groupTitle"
                                    :label="groupTitle"
                                >
                                    <option
                                        v-for="comp in comps"
                                        :key="comp.id"
                                        :value="comp.id"
                                    >
                                        {{ comp.payroll_component_type?.name || ('Component #' + comp.payroll_component_type_id) }} —
                                        [{{ comp.component_type === 'allowance' ? 'Allowance (+)' : 'Deduction (-)' }}] —
                                        {{ comp.calcType === 'percentage' ? comp.amount + '%' : '৳' + formatMoney(comp.amount) }}
                                        {{ comp.is_taxable ? '(Taxable)' : '' }}
                                    </option>
                                </optgroup>
                            </select>
                        </div>
                    </div>

                    <!-- Missing Basic Pay Warning Alert -->
                    <div v-if="hasPercentageWithoutBasic" class="p-3 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 rounded-md flex items-start gap-2.5 text-xs text-red-700 dark:text-red-300">
                        <svg class="w-5 h-5 flex-shrink-0 text-red-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <div>
                            <span class="font-bold block">Basic Pay Allowance Missing!</span>
                            <span>One or more components are set to <strong>Percentage</strong>, which must be calculated from 'Basic pay'. Please add a 'Basic pay' allowance component first to enable calculations and save.</span>
                        </div>
                    </div>

                    <!-- Allowances (Addition Objects) -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h4
                                class="text-xs font-bold uppercase tracking-wider text-green-700 dark:text-green-400 flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                Configured Allowances (Additions)
                            </h4>
                            <span class="text-xs font-semibold text-green-700 dark:text-green-400">
                                Total: {{
                                    formatMoney(calculateTotalAdditions(selectedEmployee.employee_info?.additionObjects))
                                }}
                            </span>
                        </div>

                        <div v-if="(selectedEmployee.employee_info?.additionObjects || []).length > 0"
                            class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-xs">
                                <thead class="bg-gray-50 dark:bg-gray-900/40 font-semibold text-gray-500">
                                    <tr>
                                        <th class="py-2 px-3 text-left">Type ID</th>
                                        <th class="py-2 px-3 text-left">Component</th>
                                        <th class="py-2 px-3 text-left">Calc Type</th>
                                        <th class="py-2 px-3 text-left">Taxable</th>
                                        <th class="py-2 px-3 text-right">Amount</th>
                                        <th class="py-2 px-3 text-center w-12">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="(item, idx) in (selectedEmployee.employee_info?.additionObjects || [])"
                                        :key="idx" class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                        <td class="py-2 px-3 font-mono font-medium text-gray-500 dark:text-gray-400">
                                            #{{ item.payroll_component_type_id || 'N/A' }}
                                        </td>
                                        <td class="py-2 px-3 font-medium text-gray-800 dark:text-gray-200">
                                            {{ item.name || getComponentTypeName(item.payroll_component_type_id) ||
                                                item.payroll_component_type?.name || ('Allowance #' + (idx +
                                            1)) }}
                                        </td>
                                        <td class="py-2 px-3">
                                            <select
                                                v-model="item.calcType"
                                                @change="handleCalcTypeChange(item)"
                                                class="rounded-md text-xs py-1 px-2 border transition-colors focus:ring-1 focus:ring-green-500 focus:border-green-500 cursor-pointer"
                                                :class="item.calcType !== (item.default_calc_type || 'fixed')
                                                    ? 'border-amber-400 bg-amber-50/70 dark:border-amber-500 dark:bg-amber-950/40 text-amber-900 dark:text-amber-200'
                                                    : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300'"
                                            >
                                                <option value="fixed">Fixed</option>
                                                <option value="percentage">Percentage</option>
                                            </select>
                                        </td>
                                        <td class="py-2 px-3">
                                            <span
                                                :class="item.is_taxable ? 'text-amber-600 font-semibold' : 'text-gray-400'">
                                                {{ item.is_taxable ? 'Yes' : 'No' }}
                                            </span>
                                        </td>
                                        <td class="py-2 px-3 text-right">
                                            <div class="flex flex-col items-end gap-1">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <span class="text-xs font-semibold font-mono text-green-600 dark:text-green-400 select-none">
                                                        {{ item.calcType === 'percentage' ? '%' : '+' }}
                                                    </span>
                                                    <input
                                                        type="number"
                                                        step="any"
                                                        v-model="item.amount"
                                                        @input="handleAmountChange(item)"
                                                        class="w-24 sm:w-28 text-right font-mono text-xs font-semibold py-1 px-2 rounded-md border transition-colors focus:ring-1 focus:ring-green-500 focus:border-green-500 text-gray-900 dark:text-gray-100 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                                                        :class="item.ruleOver
                                                            ? 'border-amber-400 bg-amber-50/60 dark:border-amber-500 dark:bg-amber-950/40 text-amber-900 dark:text-amber-200'
                                                            : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800'"
                                                        placeholder="0.00"
                                                    />
                                                    <span
                                                        v-if="item.ruleOver"
                                                        title="Default value or calculation type has been modified (ruleOver: true)"
                                                        class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[9px] font-bold uppercase tracking-wider bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-300 border border-amber-300 dark:border-amber-600 select-none whitespace-nowrap"
                                                    >
                                                        Override
                                                    </span>
                                                </div>
                                                <span
                                                    v-if="item.calcType === 'percentage'"
                                                    class="text-[10px] font-mono text-gray-500 dark:text-gray-400"
                                                >
                                                    (= ৳{{ formatMoney(getCalculatedComponentAmount(item, getBasicPayAmount(selectedEmployee.employee_info?.additionObjects))) }})
                                                </span>
                                            </div>
                                        </td>
                                        <td class="py-2 px-3 text-center">
                                            <button
                                                type="button"
                                                @click="removeAllowance(idx)"
                                                class="p-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-md transition-colors"
                                                title="Remove allowance"
                                            >
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else
                            class="text-xs text-gray-400 italic py-2 px-3 bg-gray-50 dark:bg-gray-900/30 rounded-lg">
                            No calculated allowance components currently saved. Select one from the dropdown above to add.
                        </div>
                    </div>

                    <!-- Deductions (Substraction Objects) -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h4
                                class="text-xs font-bold uppercase tracking-wider text-red-600 dark:text-red-400 flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M20 12H4" />
                                </svg>
                                Configured Deductions (Subtractions)
                            </h4>
                            <span class="text-xs font-semibold text-red-600 dark:text-red-400">
                                Total: -{{
                                    formatMoney(calculateTotalDeductions(selectedEmployee.employee_info?.substractionObjects, selectedEmployee.employee_info?.additionObjects))
                                }}
                            </span>
                        </div>

                        <div v-if="(selectedEmployee.employee_info?.substractionObjects || []).length > 0"
                            class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-xs">
                                <thead class="bg-gray-50 dark:bg-gray-900/40 font-semibold text-gray-500">
                                    <tr>
                                        <th class="py-2 px-3 text-left">Type ID</th>
                                        <th class="py-2 px-3 text-left">Component</th>
                                        <th class="py-2 px-3 text-left">Calc Type</th>
                                        <th class="py-2 px-3 text-left">Taxable</th>
                                        <th class="py-2 px-3 text-right">Amount</th>
                                        <th class="py-2 px-3 text-center w-12">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="(item, idx) in (selectedEmployee.employee_info?.substractionObjects || [])"
                                        :key="idx" class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                        <td class="py-2 px-3 font-mono font-medium text-gray-500 dark:text-gray-400">
                                            #{{ item.payroll_component_type_id || 'N/A' }}
                                        </td>
                                        <td class="py-2 px-3 font-medium text-gray-800 dark:text-gray-200">
                                            {{ item.name || getComponentTypeName(item.payroll_component_type_id) ||
                                                item.payroll_component_type?.name || ('Deduction #' + (idx +
                                            1)) }}
                                        </td>
                                        <td class="py-2 px-3">
                                            <select
                                                v-model="item.calcType"
                                                @change="handleCalcTypeChange(item)"
                                                class="rounded-md text-xs py-1 px-2 border transition-colors focus:ring-1 focus:ring-red-500 focus:border-red-500 cursor-pointer"
                                                :class="item.calcType !== (item.default_calc_type || 'fixed')
                                                    ? 'border-amber-400 bg-amber-50/70 dark:border-amber-500 dark:bg-amber-950/40 text-amber-900 dark:text-amber-200'
                                                    : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300'"
                                            >
                                                <option value="fixed">Fixed</option>
                                                <option value="percentage">Percentage</option>
                                            </select>
                                        </td>
                                        <td class="py-2 px-3">
                                            <span
                                                :class="item.is_taxable ? 'text-amber-600 font-semibold' : 'text-gray-400'">
                                                {{ item.is_taxable ? 'Yes' : 'No' }}
                                            </span>
                                        </td>
                                        <td class="py-2 px-3 text-right">
                                            <div class="flex flex-col items-end gap-1">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <span class="text-xs font-semibold font-mono text-red-600 dark:text-red-400 select-none">
                                                        {{ item.calcType === 'percentage' ? '%' : '-' }}
                                                    </span>
                                                    <input
                                                        type="number"
                                                        step="any"
                                                        v-model="item.amount"
                                                        @input="handleAmountChange(item)"
                                                        class="w-24 sm:w-28 text-right font-mono text-xs font-semibold py-1 px-2 rounded-md border transition-colors focus:ring-1 focus:ring-red-500 focus:border-red-500 text-gray-900 dark:text-gray-100 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                                                        :class="item.ruleOver
                                                            ? 'border-amber-400 bg-amber-50/60 dark:border-amber-500 dark:bg-amber-950/40 text-amber-900 dark:text-amber-200'
                                                            : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800'"
                                                        placeholder="0.00"
                                                    />
                                                    <span
                                                        v-if="item.ruleOver"
                                                        title="Default value or calculation type has been modified (ruleOver: true)"
                                                        class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[9px] font-bold uppercase tracking-wider bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-300 border border-amber-300 dark:border-amber-600 select-none whitespace-nowrap"
                                                    >
                                                        Override
                                                    </span>
                                                </div>
                                                <span
                                                    v-if="item.calcType === 'percentage'"
                                                    class="text-[10px] font-mono text-gray-500 dark:text-gray-400"
                                                >
                                                    (= ৳{{ formatMoney(getCalculatedComponentAmount(item, getBasicPayAmount(selectedEmployee.employee_info?.additionObjects))) }})
                                                </span>
                                            </div>
                                        </td>
                                        <td class="py-2 px-3 text-center">
                                            <button
                                                type="button"
                                                @click="removeDeduction(idx)"
                                                class="p-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-md transition-colors"
                                                title="Remove deduction"
                                            >
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else
                            class="text-xs text-gray-400 italic py-2 px-3 bg-gray-50 dark:bg-gray-900/30 rounded-lg">
                            No calculated deduction components currently saved. Select one from the dropdown above to add.
                        </div>
                    </div>

                    <!-- Salary Bank Account Info -->
                    <div v-if="selectedEmployee.bank_accounts?.length"
                        class="p-3 bg-blue-50/50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/40 rounded-lg text-xs">
                        <span class="font-bold text-blue-900 dark:text-blue-300 block mb-1">Disbursement Bank
                            Account:</span>
                        <div class="text-gray-700 dark:text-gray-300">
                            Account: <span class="font-semibold">{{ selectedEmployee.bank_accounts[0].account_no
                                }}</span> |
                            Routing: {{ selectedEmployee.bank_accounts[0].routing_no }} |
                            Type: {{ selectedEmployee.bank_accounts[0].type }}
                        </div>
                    </div>

                    <!-- ========================================== -->
                    <!-- Net Payable & Calculation Summary Section   -->
                    <!-- ========================================== -->
                    <div
                        class="p-4 bg-gradient-to-br from-gray-50 via-white to-green-50/40 dark:from-gray-900/80 dark:via-gray-800 dark:to-green-950/20 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm space-y-3">
                        <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700/60 pb-2.5">
                            <div class="flex items-center gap-2">
                                <span class="p-1.5 rounded-lg bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                    </svg>
                                </span>
                                <div>
                                    <h4 class="text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-200">
                                        Salary Calculation & Net Payable
                                    </h4>
                                    <p class="text-[11px] text-gray-400">
                                        Summary of active calculated components for this employee
                                    </p>
                                </div>
                            </div>

                            <span
                                class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold"
                                :class="netPayableSalary >= 0
                                    ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300'
                                    : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'">
                                {{ netPayableSalary >= 0 ? 'Healthy Balance' : 'Negative Net Pay' }}
                            </span>
                        </div>

                        <!-- Summary Cards Grid -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <!-- Total Allowances -->
                            <div class="p-3 bg-white dark:bg-gray-800/80 rounded-lg border border-green-100 dark:border-green-900/40">
                                <span class="text-[10px] uppercase font-bold text-gray-400 block tracking-wider">
                                    Total Allowances (+)
                                </span>
                                <div class="text-base font-extrabold text-green-600 dark:text-green-400 mt-0.5">
                                    ৳ {{ formatMoney(totalAllowances) }}
                                </div>
                                <span class="text-[10px] text-gray-400">
                                    {{ (selectedEmployee.employee_info?.additionObjects || []).length }} components
                                </span>
                            </div>

                            <!-- Total Deductions -->
                            <div class="p-3 bg-white dark:bg-gray-800/80 rounded-lg border border-red-100 dark:border-red-900/40">
                                <span class="text-[10px] uppercase font-bold text-gray-400 block tracking-wider">
                                    Total Deductions (-)
                                </span>
                                <div class="text-base font-extrabold text-red-600 dark:text-red-400 mt-0.5">
                                    ৳ {{ formatMoney(totalDeductions) }}
                                </div>
                                <span class="text-[10px] text-gray-400">
                                    {{ (selectedEmployee.employee_info?.substractionObjects || []).length }} components
                                </span>
                            </div>

                            <!-- Net Payable -->
                            <div
                                class="p-3 rounded-lg border transition-colors"
                                :class="netPayableSalary >= 0
                                    ? 'bg-green-50/60 dark:bg-green-950/30 border-green-200 dark:border-green-800'
                                    : 'bg-red-50/60 dark:bg-red-950/30 border-red-200 dark:border-red-800'">
                                <span class="text-[10px] uppercase font-bold text-gray-500 dark:text-gray-400 block tracking-wider">
                                    Net Payable Salary
                                </span>
                                <div
                                    class="text-base font-black mt-0.5"
                                    :class="netPayableSalary >= 0 ? 'text-green-700 dark:text-green-300' : 'text-red-600 dark:text-red-400'">
                                    ৳ {{ formatMoney(netPayableSalary) }}
                                </div>
                                <span class="text-[10px] text-gray-500 dark:text-gray-400">
                                    Allowances − Deductions
                                </span>
                            </div>
                        </div>
                    </div>
                    </fieldset>
                </div>

                <!-- Modal Footer -->
                <div class="mt-5 pt-3 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <SecondaryButton @click="closeDetailModal">
                        Close
                    </SecondaryButton>

                    <PrimaryButton v-if="selectedEmployee"
                        @click="handleUpdateCalculation"
                        :disabled="calculatingId === selectedEmployee.id || hasPercentageWithoutBasic || selectedEmployee.effective_payroll?.source === 'approved_fixation'"
                        :class="hasPercentageWithoutBasic ? '!opacity-50 !cursor-not-allowed' : ''"
                        :title="hasPercentageWithoutBasic ? 'Add Basic pay allowance first to enable percentage calculation' : ''"
                        class="!bg-green-600 hover:!bg-green-700 text-xs flex items-center gap-1.5">
                        <svg v-if="calculatingId !== selectedEmployee.id" class="w-4 h-4" fill="none"
                            viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <svg v-else class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        Update calculation
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

    </AuthenticatedLayout>
</template>
