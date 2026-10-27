<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { useBonusSetupStore } from '@/stores/bonusSetup';
import { useAdminStore } from '@/stores/admin';
import { storeToRefs } from 'pinia';
import Swal from 'sweetalert2';

const props = defineProps({
    payScales: { type: Array, default: () => [] },
    grades: { type: Array, default: () => [] },
    payrollGroupTypes: { type: Array, default: () => [] },
    payrollGroups: { type: Array, default: () => [] },
    userCategories: { type: Array, default: () => [] },
    departmentOffices: { type: Array, default: () => [] },
    bonusGroups: { type: Array, default: () => [] },
    bonusComponents: { type: Array, default: () => [] },
    religions: { type: Array, default: () => [] },
    genders: { type: Array, default: () => [] },
});

const adminStore = useAdminStore();
const bonusStore = useBonusSetupStore();
const {
    employees,
    loading,
    calculatingId,
    sendingReviewId,
    bulkSendingReview,
    assigningGroup,
    selectedUserIds,
    selectedEmployee,
    detailsLoading,
    filters,
    activeFilterCount,
} = storeToRefs(bonusStore);

// Local state for filters form
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
    joining_date: '',
    religion: '',
    bonus_group_id: '',
    department_office_id: '',
});

// Modals state
const isDetailModalOpen = ref(false);
const isAssignGroupModalOpen = ref(false);
const selectedBonusGroupIdToAssign = ref('');
const isAssigningToAllFiltered = ref(false);

// Cascading grades based on selected pay scale
const availableGrades = computed(() => {
    if (!localFilters.value.pay_scale_id) return props.grades;
    return props.grades.filter(g => String(g.pay_scale_id) === String(localFilters.value.pay_scale_id));
});

// Cascading payroll groups (designations)
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
    bonusStore.fetchEmployees(1);
});

// Search & Filter Actions
const handleSearch = () => {
    bonusStore.setFilters(localFilters.value);
};

const handleResetFilters = () => {
    localFilters.value = {
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
    bonusStore.resetFilters();
};

const handleSort = (field) => {
    bonusStore.setSort(field);
};

const handlePageChange = (page) => {
    if (page >= 1 && page <= employees.value.last_page) {
        bonusStore.fetchEmployees(page);
    }
};

// Checkbox Multi-selection
const isAllCurrentPageSelected = computed(() => {
    const list = employees.value?.data || [];
    if (!list.length) return false;
    return list.every(emp => selectedUserIds.value.includes(emp.id));
});

const isSomeCurrentPageSelected = computed(() => {
    const list = employees.value?.data || [];
    if (!list.length) return false;
    return list.some(emp => selectedUserIds.value.includes(emp.id)) && !isAllCurrentPageSelected.value;
});

const toggleSelectAllCurrentPage = (event) => {
    const checked = event.target.checked;
    const pageIds = (employees.value?.data || []).map(emp => emp.id);
    if (checked) {
        selectedUserIds.value = Array.from(new Set([...selectedUserIds.value, ...pageIds]));
    } else {
        selectedUserIds.value = selectedUserIds.value.filter(id => !pageIds.includes(id));
    }
};

const toggleSelectEmployee = (id) => {
    if (selectedUserIds.value.includes(id)) {
        selectedUserIds.value = selectedUserIds.value.filter(uid => uid !== id);
    } else {
        selectedUserIds.value.push(id);
    }
};

const selectedConfiguredCount = computed(() => {
    const list = employees.value?.data || [];
    return list.filter(emp => selectedUserIds.value.includes(emp.id) && emp.is_configured).length;
});

// Open Details Modal for single employee
const handleOpenDetails = async (emp) => {
    isDetailModalOpen.value = true;
    await bonusStore.fetchEmployeeDetails(emp.id);
};

const handleCloseDetails = () => {
    isDetailModalOpen.value = false;
    bonusStore.clearSelectedEmployee();
};

// Open Assign Bonus Group Modal
const handleOpenAssignGroupModal = (forAllFiltered = false) => {
    isAssigningToAllFiltered.value = forAllFiltered;
    selectedBonusGroupIdToAssign.value = '';
    isAssignGroupModalOpen.value = true;
};

const handleCloseAssignGroupModal = () => {
    isAssignGroupModalOpen.value = false;
    selectedBonusGroupIdToAssign.value = '';
};

// Execute Assign Bonus Group
const executeAssignBonusGroup = async () => {
    if (!selectedBonusGroupIdToAssign.value) {
        adminStore.showError('Required', 'Please select a bonus group to assign.');
        return;
    }

    const group = props.bonusGroups.find(g => String(g.id) === String(selectedBonusGroupIdToAssign.value));
    const groupName = group ? group.name : 'Selected Bonus Group';

    const count = isAssigningToAllFiltered.value 
        ? employees.value.total 
        : selectedUserIds.value.length;

    // Target the open native <dialog> so SweetAlert modal appears on top of the modal in top layer
    const targetEl = document.querySelector('dialog[open]') || document.body;

    const result = await Swal.fire({
        target: targetEl,
        title: 'Assign Bonus Group?',
        text: `Assign '${groupName}' to ${count} employee(s)? This will initialize their bonus components and evaluate amounts based on their Basic pay.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, Assign',
        cancelButtonText: 'Cancel',
        customClass: {
            container: '!z-[999999]',
        }
    });

    if (result.isConfirmed) {
        const res = await bonusStore.assignBonusGroup(
            Number(selectedBonusGroupIdToAssign.value),
            isAssigningToAllFiltered.value ? [] : selectedUserIds.value,
            isAssigningToAllFiltered.value
        );

        if (res.success) {
            handleCloseAssignGroupModal();
        }
    }
};

// Single Recalculate Default
const handleRecalculateDefault = async (emp) => {
    const result = await Swal.fire({
        title: 'Reset to Default Bonus?',
        text: `Reset bonus components to default from assigned bonus group for ${emp.name}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, Recalculate',
        cancelButtonText: 'Cancel'
    });

    if (result.isConfirmed) {
        await bonusStore.calculateDefault(emp.id);
    }
};

// Single Send Review email
const handleSendReview = async (emp) => {
    if (!emp.is_configured) {
        adminStore.showError('Pending Setup', `Cannot send review email to ${emp.name}. Bonus setup is pending.`);
        return;
    }

    const result = await Swal.fire({
        title: 'Send Bonus Review Email?',
        text: `Send currently estimated bonus structure breakdown via email to ${emp.name} (${emp.email})?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, Send review',
        cancelButtonText: 'Cancel'
    });

    if (result.isConfirmed) {
        await bonusStore.sendReview(emp.id);
    }
};

// Bulk Send Review email
const handleSendBulkReviewSelected = async () => {
    if (!selectedUserIds.value.length) return;

    // Filter to only configured employees to prevent blank report mailing
    const configuredEmployees = (employees.value?.data || []).filter(
        emp => selectedUserIds.value.includes(emp.id) && emp.is_configured
    );

    if (configuredEmployees.length === 0) {
        adminStore.showError('Pending Setup', 'All selected employee(s) have pending bonus setup. Please assign a bonus group or configure bonus estimation first.');
        return;
    }

    const count = configuredEmployees.length;
    const pendingCount = selectedUserIds.value.length - count;
    const confirmPrompt = pendingCount > 0
        ? `Send estimated bonus structure emails to ${count} configured employee(s)? (${pendingCount} pending setup employee(s) will be skipped to prevent blank reports)`
        : `Send estimated bonus structure emails to ${count} selected employee(s)?`;

    const result = await Swal.fire({
        title: 'Send Bulk Review Emails?',
        text: confirmPrompt,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#6b7280',
        confirmButtonText: `Yes, Send to ${count} Employees`,
        cancelButtonText: 'Cancel'
    });

    if (result.isConfirmed) {
        await bonusStore.sendBulkReview(configuredEmployees.map(e => e.id), false);
    }
};

const handleSendBulkReviewFiltered = async () => {
    const total = employees.value.total || 0;
    if (total === 0) return;
    const result = await Swal.fire({
        title: 'Send Bulk Review Emails?',
        text: `Send estimated bonus structure emails to all ${total} filtered employee(s)?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#6b7280',
        confirmButtonText: `Yes, Send to All (${total})`,
        cancelButtonText: 'Cancel'
    });

    if (result.isConfirmed) {
        await bonusStore.sendBulkReview([], true);
    }
};

// Modal Reactive Details State
const modalBonusGroupId = ref('');
const modalAllowances = ref([]);
const modalDeductions = ref([]);
const selectedComponentToAdd = ref('');

// Populate modal when selectedEmployee is loaded
watch(() => selectedEmployee.value, (newVal) => {
    if (newVal) {
        modalBonusGroupId.value = newVal.bonus_group?.id || newVal.user?.employee_info?.bonus_group_id || '';
        modalAllowances.value = Array.isArray(newVal.bonus_allowances) ? JSON.parse(JSON.stringify(newVal.bonus_allowances)) : [];
        modalDeductions.value = Array.isArray(newVal.bonus_deductions) ? JSON.parse(JSON.stringify(newVal.bonus_deductions)) : [];
        selectedComponentToAdd.value = '';
    } else {
        modalBonusGroupId.value = '';
        modalAllowances.value = [];
        modalDeductions.value = [];
        selectedComponentToAdd.value = '';
    }
}, { immediate: true });

// Change modal bonus group -> load defaults from selected group
const handleModalBonusGroupChange = () => {
    if (!modalBonusGroupId.value) return;
    const group = props.bonusGroups.find(g => String(g.id) === String(modalBonusGroupId.value));
    if (group) {
        const basic = selectedEmployee.value?.basic_pay || 0;
        const copyAndCalc = (list) => (list || []).map(item => {
            const calcType = strtolower(item.calcType || 'fixed');
            const fromBasic = Boolean(item.fromBasic);
            const rawAmt = parseFloat(item.amount) || 0;
            const effective = (calcType === 'percentage' && fromBasic)
                ? (basic * (rawAmt / 100))
                : ((calcType === 'percentage') ? (basic * (rawAmt / 100)) : rawAmt);
            return {
                ...item,
                fromBasic,
                calculated_amount: effective,
            };
        });
        modalAllowances.value = copyAndCalc(group.bonusAllowanceObjects);
        modalDeductions.value = copyAndCalc(group.bonusDeductionObjects);
    }
};

// Calculate effective item amount
const getItemCalculatedAmount = (item) => {
    const basic = selectedEmployee.value?.basic_pay || 0;
    const calcType = String(item.calcType || 'fixed').toLowerCase();
    const fromBasic = Boolean(item.fromBasic);
    const rawAmt = parseFloat(item.amount) || 0;

    if (calcType === 'percentage' && fromBasic) {
        return basic * (rawAmt / 100);
    }
    if (calcType === 'percentage') {
        return basic * (rawAmt / 100);
    }
    return rawAmt;
};

// Modal Totals
const modalTotalAllowances = computed(() => {
    return modalAllowances.value.reduce((sum, item) => sum + getItemCalculatedAmount(item), 0);
});

const modalTotalDeductions = computed(() => {
    return modalDeductions.value.reduce((sum, item) => sum + getItemCalculatedAmount(item), 0);
});

const modalNetBonus = computed(() => {
    return modalTotalAllowances.value - modalTotalDeductions.value;
});

// Remove item from modal
const removeModalAllowance = (idx) => {
    modalAllowances.value.splice(idx, 1);
};

const removeModalDeduction = (idx) => {
    modalDeductions.value.splice(idx, 1);
};

// Add component from catalog to modal
const handleAddComponentToModal = () => {
    if (!selectedComponentToAdd.value) return;
    const comp = props.bonusComponents.find(c => String(c.id) === String(selectedComponentToAdd.value));
    if (!comp) return;

    const isAllowance = comp.componentType === 'allowance';
    const target = isAllowance ? modalAllowances.value : modalDeductions.value;

    const already = target.some(i => String(i.id) === String(comp.id));
    if (already) {
        adminStore.showError('Already Added', `This bonus component (#${comp.id}) is already in the list.`);
        selectedComponentToAdd.value = '';
        return;
    }

    target.push({
        id: comp.id,
        componentType: comp.componentType,
        calcType: comp.calcType,
        amount: comp.amount,
        fromBasic: Boolean(comp.fromBasic),
        isTaxable: Boolean(comp.isTaxable),
        name: comp.display_name || `#${comp.id} ${comp.componentType}`,
        display_name: comp.display_name,
        ruleOver: true,
    });

    selectedComponentToAdd.value = '';
};

// Save Modal Changes
const handleSaveModalConfiguration = async () => {
    if (!selectedEmployee.value?.user?.id) return;
    const basic = selectedEmployee.value.basic_pay || 0;

    // Check percentage components have basic pay
    const allItems = [...modalAllowances.value, ...modalDeductions.value];
    const hasPercentageWithBasic = allItems.some(i => String(i.calcType).toLowerCase() === 'percentage' && Boolean(i.fromBasic));

    if (hasPercentageWithBasic && basic <= 0) {
        adminStore.showError('Missing Basic Pay', 'One or more components are set to calculate percentage from Basic pay, but this employee does not have Basic Pay configured in salary setup.');
        return;
    }

    if (modalTotalAllowances.value < modalTotalDeductions.value) {
        adminStore.showError('Invalid Totals', 'Total bonus allowances cannot be less than total bonus deductions.');
        return;
    }

    const success = await bonusStore.updateBonusCalculation(
        selectedEmployee.value.user.id,
        modalBonusGroupId.value ? Number(modalBonusGroupId.value) : null,
        modalAllowances.value,
        modalDeductions.value
    );

    if (success) {
        handleCloseDetails();
    }
};

const strtolower = (str) => String(str || '').toLowerCase();
</script>

<template>
    <Head title="Bonus Estimation & Management" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center space-x-3">
                    <div class="p-2.5 bg-emerald-500/10 dark:bg-emerald-500/20 border border-emerald-500/20 rounded-xl text-emerald-600 dark:text-emerald-400">
                        <!-- Gift / Sparkles Bonus Icon -->
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            Bonus Estimation & Management
                            <span class="text-xs px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300 font-medium">
                                Active Module
                            </span>
                        </h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            Configure employee festival bonuses, assign bonus groups by religion & category, and review percentage calculations.
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2.5">
                    <span class="text-xs px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-semibold border border-gray-200 dark:border-gray-700">
                        Total Staff: {{ employees.total || 0 }}
                    </span>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Search & Filter Card (Mirrors Screenshot 1) -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between bg-gray-50/50 dark:bg-gray-800/50">
                    <div class="flex items-center space-x-2 text-emerald-600 dark:text-emerald-400 font-semibold text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                        </svg>
                        <span>Search & Filter</span>
                        <span v-if="activeFilterCount > 0" class="ml-2 px-2 py-0.5 text-xs bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200 rounded-full">
                            {{ activeFilterCount }} active
                        </span>
                    </div>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Refine staff using demographic, payroll, or religion criteria</span>
                </div>

                <div class="p-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                        <!-- Employee Code -->
                        <div>
                            <InputLabel value="Employee Code" class="mb-1 text-xs font-medium text-gray-700 dark:text-gray-300" />
                            <TextInput
                                v-model="localFilters.employeeCode"
                                placeholder="# e.g. 1001"
                                class="w-full text-xs py-2 dark:bg-gray-900 dark:border-gray-700"
                                @keyup.enter="handleSearch"
                            />
                        </div>

                        <!-- Employee Category -->
                        <div>
                            <InputLabel value="Employee Category" class="mb-1 text-xs font-medium text-gray-700 dark:text-gray-300" />
                            <select
                                v-model="localFilters.user_category_id"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500"
                            >
                                <option value="">All Categories</option>
                                <option v-for="cat in userCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                        </div>

                        <!-- Pay Scale -->
                        <div>
                            <InputLabel value="Pay Scale" class="mb-1 text-xs font-medium text-gray-700 dark:text-gray-300" />
                            <select
                                v-model="localFilters.pay_scale_id"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500"
                            >
                                <option value="">All Pay Scales</option>
                                <option v-for="scale in payScales" :key="scale.id" :value="scale.id">{{ scale.scale_name }}</option>
                            </select>
                        </div>

                        <!-- Grades -->
                        <div>
                            <InputLabel value="Grades" class="mb-1 text-xs font-medium text-gray-700 dark:text-gray-300" />
                            <select
                                v-model="localFilters.grade_id"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500"
                            >
                                <option value="">All Grades</option>
                                <option v-for="gr in availableGrades" :key="gr.id" :value="gr.id">{{ gr.grade_name }}</option>
                            </select>
                        </div>

                        <!-- Gender -->
                        <div>
                            <InputLabel value="Gender" class="mb-1 text-xs font-medium text-gray-700 dark:text-gray-300" />
                            <select
                                v-model="localFilters.gender"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500"
                            >
                                <option value="">All Genders</option>
                                <option v-for="g in genders" :key="g.value" :value="g.value">{{ g.label }}</option>
                            </select>
                        </div>

                        <!-- Profile Active Status -->
                        <div>
                            <InputLabel value="Profile Active Status" class="mb-1 text-xs font-medium text-gray-700 dark:text-gray-300" />
                            <select
                                v-model="localFilters.isActive"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500"
                            >
                                <option value="">All Statuses</option>
                                <option :value="true">Active Only</option>
                                <option :value="false">Inactive Only</option>
                            </select>
                        </div>

                        <!-- Designation -->
                        <div>
                            <InputLabel value="Designation" class="mb-1 text-xs font-medium text-gray-700 dark:text-gray-300" />
                            <select
                                v-model="localFilters.payroll_group_id"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500"
                            >
                                <option value="">All Designations</option>
                                <option v-for="pg in availablePayrollGroups" :key="pg.id" :value="pg.id">
                                    {{ pg.payroll_group_type?.group_name }} (Grade {{ pg.grade?.grade_name }})
                                </option>
                            </select>
                        </div>

                        <!-- Bonus Group Filter -->
                        <div>
                            <InputLabel value="Assigned Bonus Group" class="mb-1 text-xs font-medium text-gray-700 dark:text-gray-300" />
                            <select
                                v-model="localFilters.bonus_group_id"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500"
                            >
                                <option value="">All Bonus Groups</option>
                                <option value="unassigned">Unassigned Only</option>
                                <option v-for="bg in bonusGroups" :key="bg.id" :value="bg.id">{{ bg.name }}</option>
                            </select>
                        </div>

                        <!-- Joining Date -->
                        <div>
                            <InputLabel value="Joining Date" class="mb-1 text-xs font-medium text-gray-700 dark:text-gray-300" />
                            <TextInput
                                type="date"
                                v-model="localFilters.joining_date"
                                class="w-full text-xs py-2 dark:bg-gray-900 dark:border-gray-700"
                            />
                        </div>

                        <!-- Religion (Crucial for Festival Bonuses!) -->
                        <div>
                            <InputLabel value="Religion" class="mb-1 text-xs font-medium text-gray-700 dark:text-gray-300" />
                            <select
                                v-model="localFilters.religion"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500"
                            >
                                <option value="">All Religions</option>
                                <option v-for="r in religions" :key="r" :value="r">{{ r }}</option>
                            </select>
                        </div>

                        <!-- Universal Search by Name/Email -->
                        <div class="sm:col-span-2">
                            <InputLabel value="Search by Name or Email" class="mb-1 text-xs font-medium text-gray-700 dark:text-gray-300" />
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </span>
                                <TextInput
                                    v-model="localFilters.search"
                                    placeholder="Search by employee name, email, or code..."
                                    class="w-full pl-9 text-xs py-2 dark:bg-gray-900 dark:border-gray-700"
                                    @keyup.enter="handleSearch"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Filter Buttons -->
                    <div class="mt-5 flex flex-wrap items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-700 gap-3">
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Apply any combination of filters and press Search results.
                        </span>
                        <div class="flex items-center gap-2">
                            <SecondaryButton @click="handleResetFilters" class="text-xs py-2 px-4">
                                RESET FILTERS
                            </SecondaryButton>
                            <PrimaryButton @click="handleSearch" class="text-xs py-2 px-4 bg-emerald-600 hover:bg-emerald-500 focus:ring-emerald-500">
                                <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                SEARCH RESULTS
                            </PrimaryButton>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Table & Bulk Actions Container -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
                <!-- Action bar above table -->
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex flex-wrap items-center justify-between gap-3 bg-gray-50/50 dark:bg-gray-800/50">
                    <div>
                        <h3 class="text-sm font-bold text-gray-900 dark:text-white">
                            Employee Bonus Setup Records
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            Showing {{ employees.from || 0 }} to {{ employees.to || 0 }} of {{ employees.total || 0 }} employees
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Bulk Assign Bonus Group Button -->
                        <button
                            v-if="selectedUserIds.length > 0"
                            @click="handleOpenAssignGroupModal(false)"
                            :disabled="assigningGroup"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white shadow-sm transition-colors"
                        >
                            <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            Assign Group to Selected ({{ selectedUserIds.length }})
                        </button>

                        <button
                            @click="handleOpenAssignGroupModal(true)"
                            :disabled="assigningGroup || employees.total === 0"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-lg border border-emerald-600 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/30 transition-colors"
                            title="Assign a bonus group to all employees matching currently active filters"
                        >
                            Assign Group to All Filtered ({{ employees.total || 0 }})
                        </button>

                        <!-- Send Review to Selected / All -->
                        <button
                            v-if="selectedUserIds.length > 0"
                            @click="handleSendBulkReviewSelected"
                            :disabled="bulkSendingReview || selectedConfiguredCount === 0"
                            :title="selectedConfiguredCount === 0 ? 'Selected employees have pending bonus setup' : ''"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white shadow-sm transition-colors"
                        >
                            <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            Send Review ({{ selectedUserIds.length }})
                        </button>

                        <button
                            v-else
                            @click="handleSendBulkReviewFiltered"
                            :disabled="bulkSendingReview || employees.total === 0"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                        >
                            <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            Send Review to All ({{ employees.total || 0 }})
                        </button>

                        <!-- Refresh -->
                        <button
                            @click="bonusStore.fetchEmployees(employees.current_page)"
                            :disabled="loading"
                            class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                        >
                            <svg :class="['w-3.5 h-3.5 mr-1', loading ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            Refresh
                        </button>
                    </div>
                </div>

                <!-- Table Content -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-gray-50/75 dark:bg-gray-900/60 text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">
                            <tr>
                                <th class="py-3.5 px-4 w-10">
                                    <input
                                        type="checkbox"
                                        :checked="isAllCurrentPageSelected"
                                        :indeterminate.prop="isSomeCurrentPageSelected"
                                        @change="toggleSelectAllCurrentPage"
                                        class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:bg-gray-900 dark:border-gray-700"
                                    />
                                </th>
                                <th class="py-3.5 px-4 cursor-pointer hover:text-gray-900 dark:hover:text-white" @click="handleSort('name')">
                                    Employee
                                </th>
                                <th class="py-3.5 px-4 cursor-pointer hover:text-gray-900 dark:hover:text-white" @click="handleSort('id')">
                                    Code & Status
                                </th>
                                <th class="py-3.5 px-4">Category & Dept</th>
                                <th class="py-3.5 px-4">Pay Scale & Grade</th>
                                <th class="py-3.5 px-4">Designation</th>
                                <th class="py-3.5 px-4">Religion & Joining</th>
                                <th class="py-3.5 px-4">Bonus Setup Status</th>
                                <th class="py-3.5 px-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700/60 text-gray-700 dark:text-gray-300">
                            <tr v-if="loading" class="text-center">
                                <td colspan="9" class="py-12 text-gray-500 dark:text-gray-400">
                                    <div class="flex items-center justify-center space-x-2">
                                        <svg class="w-5 h-5 animate-spin text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <span>Loading bonus setup records...</span>
                                    </div>
                                </td>
                            </tr>

                            <tr v-else-if="!employees.data || employees.data.length === 0">
                                <td colspan="9" class="py-12 text-center text-gray-500 dark:text-gray-400">
                                    No employee bonus setup records matching your filter criteria.
                                </td>
                            </tr>

                            <tr
                                v-for="emp in employees.data"
                                :key="emp.id"
                                :class="[
                                    'hover:bg-gray-50/75 dark:hover:bg-gray-700/30 transition-colors',
                                    selectedUserIds.includes(emp.id) ? 'bg-emerald-50/40 dark:bg-emerald-950/20' : ''
                                ]"
                            >
                                <td class="py-3.5 px-4">
                                    <input
                                        type="checkbox"
                                        :checked="selectedUserIds.includes(emp.id)"
                                        @change="toggleSelectEmployee(emp.id)"
                                        class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:bg-gray-900 dark:border-gray-700"
                                    />
                                </td>

                                <td class="py-3.5 px-4">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 rounded-full bg-emerald-100 dark:bg-emerald-900/60 text-emerald-700 dark:text-emerald-300 flex items-center justify-center font-bold text-xs uppercase shrink-0">
                                            {{ emp.name ? emp.name.substring(0, 2) : 'EM' }}
                                        </div>
                                        <div>
                                            <div class="font-semibold text-gray-900 dark:text-white flex items-center gap-1.5">
                                                {{ emp.name }}
                                                <span v-if="emp.gender" class="text-[10px] px-1.5 py-0.2 rounded border border-gray-200 dark:border-gray-700 text-gray-500 capitalize">
                                                    {{ emp.gender }}
                                                </span>
                                            </div>
                                            <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                                {{ emp.email }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td class="py-3.5 px-4">
                                    <div class="font-mono font-medium text-gray-900 dark:text-gray-200">
                                        #{{ emp.employee_code }}
                                    </div>
                                    <span :class="[
                                        'inline-flex items-center text-[10px] font-semibold mt-0.5',
                                        emp.isActive ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'
                                    ]">
                                        <span :class="['w-1.5 h-1.5 rounded-full mr-1', emp.isActive ? 'bg-emerald-500' : 'bg-red-500']"></span>
                                        {{ emp.isActive ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>

                                <td class="py-3.5 px-4">
                                    <div class="font-medium text-gray-900 dark:text-gray-200 truncate max-w-[160px]" :title="emp.department">
                                        {{ emp.department }}
                                    </div>
                                    <span class="inline-block mt-0.5 text-[10px] px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 font-medium">
                                        {{ emp.user_category }}
                                    </span>
                                </td>

                                <td class="py-3.5 px-4">
                                    <div class="text-gray-900 dark:text-gray-200 truncate max-w-[140px]" :title="emp.pay_scale">
                                        {{ emp.pay_scale }}
                                    </div>
                                    <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                        Grade {{ emp.grade }}
                                    </div>
                                </td>

                                <td class="py-3.5 px-4">
                                    <span class="inline-block px-2 py-0.5 rounded bg-purple-50 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 text-xs font-medium">
                                        {{ emp.designation }}
                                    </span>
                                </td>

                                <td class="py-3.5 px-4">
                                    <div class="font-medium text-emerald-700 dark:text-emerald-400">
                                        {{ emp.religion }}
                                    </div>
                                    <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                        Join: {{ emp.joining_date }}
                                    </div>
                                </td>

                                <td class="py-3.5 px-4">
                                    <div v-if="emp.is_configured" class="space-y-1">
                                        <div class="flex items-center gap-1.5">
                                            <span class="inline-flex items-center text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
                                                <svg class="w-3.5 h-3.5 mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                                Configured
                                            </span>
                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                                {{ emp.bonus_group_name }}
                                            </span>
                                        </div>
                                        <div class="text-[11px] font-mono text-gray-700 dark:text-gray-300">
                                            +৳{{ (emp.total_allowance || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                                            <span class="text-gray-400">|</span>
                                            -৳{{ (emp.total_deduction || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                                        </div>
                                    </div>
                                    <div v-else>
                                        <span class="inline-flex items-center text-[11px] font-medium text-amber-600 dark:text-amber-400">
                                            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                            </svg>
                                            Pending Setup
                                        </span>
                                    </div>
                                </td>

                                <td class="py-3.5 px-4 text-right">
                                    <div class="flex items-center justify-end space-x-1.5">
                                        <!-- Details / Configure Button -->
                                        <button
                                            @click="handleOpenDetails(emp)"
                                            class="inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                                        >
                                            <svg class="w-3.5 h-3.5 mr-1 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                            Details
                                        </button>

                                        <!-- Calc. Default Button -->
                                        <button
                                            @click="handleRecalculateDefault(emp)"
                                            :disabled="calculatingId === emp.id || !emp.bonus_group_id"
                                            :title="!emp.bonus_group_id ? 'Assign a bonus group first' : 'Reset components to assigned group defaults'"
                                            class="inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-md bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white transition-colors"
                                        >
                                            <svg :class="['w-3.5 h-3.5 mr-1', calculatingId === emp.id ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                            </svg>
                                            Calc. Default
                                        </button>

                                        <!-- Send Review Button -->
                                        <button
                                            @click="handleSendReview(emp)"
                                            :disabled="sendingReviewId === emp.id || !emp.email || !emp.is_configured"
                                            :title="!emp.is_configured ? 'Cannot send review: Bonus setup is pending' : (!emp.email ? 'Employee has no email address' : 'Send estimated bonus review email')"
                                            class="inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-md bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white transition-colors"
                                        >
                                            <svg :class="['w-3.5 h-3.5 mr-1', sendingReviewId === emp.id ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                            </svg>
                                            Send review
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="px-5 py-3.5 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between bg-gray-50/50 dark:bg-gray-800/50">
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        Page {{ employees.current_page || 1 }} of {{ employees.last_page || 1 }}
                    </span>
                    <div class="flex items-center space-x-1.5">
                        <button
                            @click="handlePageChange(employees.current_page - 1)"
                            :disabled="employees.current_page <= 1 || loading"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 transition-colors"
                        >
                            Previous
                        </button>
                        <button
                            @click="handlePageChange(employees.current_page + 1)"
                            :disabled="employees.current_page >= employees.last_page || loading"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 transition-colors"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Assign Bonus Group Modal (Single or Bulk) -->
        <Modal :show="isAssignGroupModalOpen" @close="handleCloseAssignGroupModal" maxWidth="md">
            <div class="p-6">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="w-10 h-10 rounded-full bg-emerald-100 dark:bg-emerald-900/50 text-emerald-600 dark:text-emerald-300 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900 dark:text-white">
                            Assign Bonus Group
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ isAssigningToAllFiltered ? `Assign to all ${employees.total} filtered employees` : `Assign to ${selectedUserIds.length} selected employee(s)` }}
                        </p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <InputLabel value="Select Bonus Group" class="mb-1 text-xs font-semibold text-gray-700 dark:text-gray-300" />
                        <select
                            v-model="selectedBonusGroupIdToAssign"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2.5 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500"
                        >
                            <option value="">-- Choose a Bonus Group --</option>
                            <option v-for="bg in bonusGroups" :key="bg.id" :value="bg.id">
                                {{ bg.name }} ({{ bg.description }})
                            </option>
                        </select>
                    </div>

                    <div v-if="selectedBonusGroupIdToAssign" class="p-3 bg-gray-50 dark:bg-gray-900/60 rounded-lg border border-gray-200 dark:border-gray-700 text-xs space-y-1.5">
                        <div class="font-semibold text-gray-700 dark:text-gray-300">Group Preview:</div>
                        <div class="text-gray-500 dark:text-gray-400">
                            {{ props.bonusGroups.find(g => String(g.id) === String(selectedBonusGroupIdToAssign))?.description }}
                        </div>
                    </div>

                    <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/40 text-emerald-800 dark:text-emerald-300 text-xs">
                        <div class="font-semibold flex items-center gap-1.5 mb-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Dynamic Calculation:
                        </div>
                        Percentage allowances/deductions marked with <em>fromBasic</em> will compute automatically based on each employee's Basic pay from their salary profile.
                    </div>
                </div>

                <div class="mt-6 flex justify-end space-x-2">
                    <SecondaryButton @click="handleCloseAssignGroupModal">
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton
                        @click="executeAssignBonusGroup"
                        :disabled="!selectedBonusGroupIdToAssign || assigningGroup"
                        class="bg-emerald-600 hover:bg-emerald-500 focus:ring-emerald-500"
                    >
                        <svg v-if="assigningGroup" class="w-4 h-4 mr-1.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Assign & Recalculate
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Employee Bonus Details & Customization Modal -->
        <Modal :show="isDetailModalOpen" @close="handleCloseDetails" maxWidth="3xl">
            <div class="p-6">
                <div v-if="detailsLoading" class="py-16 text-center text-gray-500 dark:text-gray-400">
                    <svg class="w-8 h-8 animate-spin text-emerald-500 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>Loading employee bonus configuration...</span>
                </div>

                <div v-else-if="selectedEmployee" class="space-y-5">
                    <!-- Header -->
                    <div class="flex items-start justify-between border-b border-gray-200 dark:border-gray-700 pb-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                                    {{ selectedEmployee.user?.name }}
                                </h3>
                                <span class="px-2 py-0.5 text-xs rounded bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300 font-semibold font-mono">
                                    #{{ selectedEmployee.user?.employee_info?.employeeCode }}
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                {{ selectedEmployee.user?.email }} • {{ selectedEmployee.user?.employee_info?.payroll_group?.payroll_group_type?.group_name }} • {{ selectedEmployee.user?.religion?.religion }}
                            </p>
                        </div>

                        <!-- Basic Pay Badge detected from salary setup -->
                        <div class="text-right">
                            <div class="text-[11px] uppercase tracking-wider text-gray-400 font-semibold">Salary Basic Pay</div>
                            <div class="text-base font-extrabold text-emerald-600 dark:text-emerald-400 font-mono">
                                ৳ {{ (selectedEmployee.basic_pay || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                            </div>
                        </div>
                    </div>

                    <!-- Bonus Group Assignment Dropdown inside modal -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-gray-50 dark:bg-gray-900/50 p-3.5 rounded-xl border border-gray-200 dark:border-gray-700">
                        <div>
                            <InputLabel value="Assigned Bonus Group" class="mb-1 text-xs font-semibold text-gray-700 dark:text-gray-300" />
                            <select
                                v-model="modalBonusGroupId"
                                @change="handleModalBonusGroupChange"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500"
                            >
                                <option value="">-- No Bonus Group Assigned --</option>
                                <option v-for="bg in bonusGroups" :key="bg.id" :value="bg.id">{{ bg.name }}</option>
                            </select>
                        </div>

                        <div>
                            <InputLabel value="Add Bonus Component from Catalog" class="mb-1 text-xs font-semibold text-gray-700 dark:text-gray-300" />
                            <div class="flex gap-2">
                                <select
                                    v-model="selectedComponentToAdd"
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-xs py-2 text-gray-900 dark:text-white focus:ring-emerald-500 focus:border-emerald-500"
                                >
                                    <option value="">-- Select Component --</option>
                                    <option v-for="bc in bonusComponents" :key="bc.id" :value="bc.id">
                                        {{ bc.display_name }}
                                    </option>
                                </select>
                                <button
                                    @click="handleAddComponentToModal"
                                    :disabled="!selectedComponentToAdd"
                                    class="px-3 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-lg text-xs font-semibold shrink-0"
                                >
                                    Add
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Bonus Allowances Table -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                Bonus Allowances
                            </h4>
                            <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 font-mono">
                                Total: ৳ {{ modalTotalAllowances.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                            </span>
                        </div>

                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                            <table class="w-full text-xs text-left">
                                <thead class="bg-gray-50 dark:bg-gray-900 text-gray-500 font-semibold">
                                    <tr>
                                        <th class="py-2.5 px-3">Component</th>
                                        <th class="py-2.5 px-3">Calc Type</th>
                                        <th class="py-2.5 px-3 w-28">Amount / Rate</th>
                                        <th class="py-2.5 px-3">From Basic</th>
                                        <th class="py-2.5 px-3 text-right">Effective Amount (৳)</th>
                                        <th class="py-2.5 px-2 w-10 text-center"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-if="!modalAllowances.length">
                                        <td colspan="6" class="py-4 text-center text-gray-400">No allowance components configured.</td>
                                    </tr>
                                    <tr v-for="(item, idx) in modalAllowances" :key="idx" class="hover:bg-gray-50/50 dark:hover:bg-gray-800/50">
                                        <td class="py-2 px-3 font-medium text-gray-900 dark:text-white">
                                            {{ item.name || item.display_name || 'Bonus Allowance' }}
                                        </td>
                                        <td class="py-2 px-3">
                                            <select
                                                v-model="item.calcType"
                                                class="text-xs py-1 px-2 rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-gray-900 dark:text-white"
                                            >
                                                <option value="percentage">Percentage (%)</option>
                                                <option value="fixed">Fixed (৳)</option>
                                            </select>
                                        </td>
                                        <td class="py-2 px-3">
                                            <TextInput
                                                type="number"
                                                step="0.01"
                                                v-model="item.amount"
                                                class="w-full text-xs py-1 px-2 dark:bg-gray-900 dark:border-gray-700"
                                            />
                                        </td>
                                        <td class="py-2 px-3">
                                            <label class="inline-flex items-center cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    v-model="item.fromBasic"
                                                    class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:bg-gray-900 dark:border-gray-700"
                                                />
                                                <span class="ml-1.5 text-[11px] text-gray-600 dark:text-gray-400">Basic pay</span>
                                            </label>
                                        </td>
                                        <td class="py-2 px-3 text-right font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                                            ৳ {{ getItemCalculatedAmount(item).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                                        </td>
                                        <td class="py-2 px-2 text-center">
                                            <button
                                                @click="removeModalAllowance(idx)"
                                                class="text-red-500 hover:text-red-700 p-1"
                                                title="Remove component"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Bonus Deductions Table -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-red-600 dark:text-red-400 flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                                </svg>
                                Bonus Deductions / Tax
                            </h4>
                            <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 font-mono">
                                Total: ৳ {{ modalTotalDeductions.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                            </span>
                        </div>

                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                            <table class="w-full text-xs text-left">
                                <thead class="bg-gray-50 dark:bg-gray-900 text-gray-500 font-semibold">
                                    <tr>
                                        <th class="py-2.5 px-3">Component</th>
                                        <th class="py-2.5 px-3">Calc Type</th>
                                        <th class="py-2.5 px-3 w-28">Amount / Rate</th>
                                        <th class="py-2.5 px-3">From Basic</th>
                                        <th class="py-2.5 px-3 text-right">Effective Amount (৳)</th>
                                        <th class="py-2.5 px-2 w-10 text-center"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-if="!modalDeductions.length">
                                        <td colspan="6" class="py-4 text-center text-gray-400">No deduction components configured.</td>
                                    </tr>
                                    <tr v-for="(item, idx) in modalDeductions" :key="idx" class="hover:bg-gray-50/50 dark:hover:bg-gray-800/50">
                                        <td class="py-2 px-3 font-medium text-gray-900 dark:text-white">
                                            {{ item.name || item.display_name || 'Bonus Deduction' }}
                                        </td>
                                        <td class="py-2 px-3">
                                            <select
                                                v-model="item.calcType"
                                                class="text-xs py-1 px-2 rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-gray-900 dark:text-white"
                                            >
                                                <option value="fixed">Fixed (৳)</option>
                                                <option value="percentage">Percentage (%)</option>
                                            </select>
                                        </td>
                                        <td class="py-2 px-3">
                                            <TextInput
                                                type="number"
                                                step="0.01"
                                                v-model="item.amount"
                                                class="w-full text-xs py-1 px-2 dark:bg-gray-900 dark:border-gray-700"
                                            />
                                        </td>
                                        <td class="py-2 px-3">
                                            <label class="inline-flex items-center cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    v-model="item.fromBasic"
                                                    class="rounded border-gray-300 text-red-600 focus:ring-red-500 dark:bg-gray-900 dark:border-gray-700"
                                                />
                                                <span class="ml-1.5 text-[11px] text-gray-600 dark:text-gray-400">Basic pay</span>
                                            </label>
                                        </td>
                                        <td class="py-2 px-3 text-right font-mono font-semibold text-red-600 dark:text-red-400">
                                            ৳ {{ getItemCalculatedAmount(item).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                                        </td>
                                        <td class="py-2 px-2 text-center">
                                            <button
                                                @click="removeModalDeduction(idx)"
                                                class="text-red-500 hover:text-red-700 p-1"
                                                title="Remove component"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Net Bonus Card & Actions -->
                    <div class="flex flex-col sm:flex-row items-center justify-between gap-4 p-4 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/40">
                        <div>
                            <div class="text-xs uppercase tracking-wider font-semibold text-emerald-800 dark:text-emerald-300">
                                Estimated Net Payable Bonus:
                            </div>
                            <div class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 font-mono">
                                ৳ {{ modalNetBonus.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <SecondaryButton @click="handleCloseDetails">
                                Cancel
                            </SecondaryButton>
                            <PrimaryButton
                                @click="handleSaveModalConfiguration"
                                :disabled="calculatingId"
                                class="bg-emerald-600 hover:bg-emerald-500 focus:ring-emerald-500"
                            >
                                <svg v-if="calculatingId" class="w-4 h-4 mr-1.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Save Bonus Configuration
                            </PrimaryButton>
                        </div>
                    </div>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
