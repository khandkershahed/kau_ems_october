<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';

const props = defineProps({
    employee: { type: Object, default: null },
    departmentOffices: { type: Array, default: () => [] },
    payScales: { type: Array, default: () => [] },
    grades: { type: Array, default: () => [] },
    payrollGroups: { type: Array, default: () => [] },
    userCategories: { type: Array, default: () => [] },
    jobStatuses: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

const tabs = [
    { id: 'account', label: 'Account & Security' },
    { id: 'employment', label: 'Employment & Pay' },
    { id: 'department', label: 'Department & Designation' },
    { id: 'bank', label: 'Bank & Contact' },
];

const religions = ['Islam', 'Christianity', 'Hinduism', 'Buddhism', 'Sikhism', 'Judaism', 'Other'];

const activeTab = ref('account');
const isSaving = ref(false);
const formErrors = ref({});

const form = ref({
    id: null,
    name: '',
    email: '',
    gender: 'male',
    isActive: true,

    // Identifications
    nid_no: '',
    nid_img: '',
    bid_no: '',
    date_of_birth: '',
    bidImg: '',
    tin_no: '',
    tin_img: '',

    // Personal Info
    maritial_status: 'single',
    religion: 'Islam',
    childCount: 0,
    boy: 0,
    girl: 0,
    age: '',

    // Accommodation & Attributes
    dorm_name: '',
    dorm_type: '',
    dorm_address: '',
    emp_attr_name: '',
    emp_attr_description: '',
    emp_attr_action: false,

    // Employee Info & Pay Structure
    employeeCode: '',
    jobStatus: 'permanent',
    joining_date: '',
    incrementDate: '',
    holdIncrement: false,
    gpf_exempt: false,
    pay_scale_id: '',
    grade_id: '',
    payroll_group_id: '',

    // Multiple records
    department_offices: [{ department_office_id: '' }],
    user_categories: [{ user_category_id: '' }],
    phone_numbers: [{ phoneNumber: '' }],
    addresses: [{ address: '' }],
    bank_accounts: [{ bank_routing_no: '', bank_account_no: '', bank_type: '', bank_address: '', isSalaryAccount: false }],
    leave_statuses: [{ onLeave: false, leave_reason: '', isPaid: false, leave_isActive: false, leave_duration: 0 }],
    promotions: [{ promoted_to: '', pay_scale_id: '', payroll_group_id: '' }],
});

const addField = (field, defaultObj) => form.value[field].push({ ...defaultObj });
const removeField = (field, index) => form.value[field].splice(index, 1);

watch(() => [form.value.boy, form.value.girl], ([boy, girl]) => {
    form.value.childCount = (parseInt(boy, 10) || 0) + (parseInt(girl, 10) || 0);
});

const jobStatusOptions = computed(() => {
    const options = Array.isArray(props.jobStatuses) ? [...props.jobStatuses] : [];
    const current = String(form.value.jobStatus || '').trim();
    if (current && !options.some((opt) => opt.value === current)) {
        options.push({
            value: current,
            label: current.replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()),
        });
    }
    return options;
});

const availableGrades = computed(() => {
    if (!form.value.pay_scale_id) return [];
    return (props.grades || []).filter((g) => Number(g.pay_scale_id) === Number(form.value.pay_scale_id));
});

const availablePayrollGroups = computed(() => {
    if (!form.value.grade_id) return [];
    return (props.payrollGroups || []).filter((pg) => Number(pg.grade_id) === Number(form.value.grade_id));
});

const selectedPayScaleName = computed(() => {
    const found = (props.payScales || []).find((ps) => Number(ps.id) === Number(form.value.pay_scale_id));
    return found?.scale_name || 'Not selected';
});

const selectedGradeName = computed(() => {
    const found = (props.grades || []).find((g) => Number(g.id) === Number(form.value.grade_id));
    return found?.grade_name || 'Not selected';
});

const selectedDesignationName = computed(() => {
    const found = (props.payrollGroups || []).find((pg) => Number(pg.id) === Number(form.value.payroll_group_id));
    return found?.payroll_group_type?.group_name || (found ? `Group #${found.id}` : 'Not selected');
});

watch(() => form.value.pay_scale_id, (newScale) => {
    if (!newScale) {
        form.value.grade_id = '';
        form.value.payroll_group_id = '';
        return;
    }
    if (form.value.grade_id) {
        const grade = (props.grades || []).find((g) => Number(g.id) === Number(form.value.grade_id));
        if (!grade || Number(grade.pay_scale_id) !== Number(newScale)) {
            form.value.grade_id = '';
            form.value.payroll_group_id = '';
        }
    }
});

watch(() => form.value.grade_id, (newGrade) => {
    if (!newGrade) {
        form.value.payroll_group_id = '';
        return;
    }
    if (form.value.payroll_group_id) {
        const pg = (props.payrollGroups || []).find((p) => Number(p.id) === Number(form.value.payroll_group_id));
        if (!pg || Number(pg.grade_id) !== Number(newGrade)) {
            form.value.payroll_group_id = '';
        }
    }
});

const availableDepartments = (currentIndex) => {
    if (!props.departmentOffices) return [];
    const selectedIds = form.value.department_offices
        .filter((_, idx) => idx !== currentIndex)
        .map((d) => Number(d.department_office_id))
        .filter((id) => Boolean(id));
    return props.departmentOffices.filter((d) => !selectedIds.includes(Number(d.id)));
};

const availableUserCategories = (currentIndex) => {
    if (!props.userCategories) return [];
    const selectedIds = form.value.user_categories
        .filter((_, idx) => idx !== currentIndex)
        .map((c) => Number(c.user_category_id))
        .filter((id) => Boolean(id));
    return props.userCategories.filter((c) => !selectedIds.includes(Number(c.id)));
};

const getPromoPayrollGroups = (promo) => {
    if (!props.payrollGroups) return [];
    if (!promo.pay_scale_id) return props.payrollGroups;
    const gradeIds = (props.grades || [])
        .filter((g) => Number(g.pay_scale_id) === Number(promo.pay_scale_id))
        .map((g) => Number(g.id));
    return props.payrollGroups.filter((pg) => gradeIds.includes(Number(pg.grade_id)));
};

const onPromoPayScaleChange = (promo) => {
    if (promo.payroll_group_id) {
        const matching = getPromoPayrollGroups(promo);
        if (!matching.some((pg) => Number(pg.id) === Number(promo.payroll_group_id))) {
            promo.payroll_group_id = '';
        }
    }
};

const resolveErrorTab = (fieldKey) => {
    if (
        fieldKey.startsWith('department_offices') ||
        fieldKey.startsWith('user_categories')
    ) {
        return 'department';
    }
    if (
        fieldKey.startsWith('bank_accounts') ||
        fieldKey === 'account_no' ||
        fieldKey.startsWith('phone_numbers') ||
        fieldKey.startsWith('addresses') ||
        fieldKey.startsWith('dorm_') ||
        fieldKey.startsWith('emp_attr_')
    ) {
        return 'bank';
    }
    if (
        ['employeeCode', 'jobStatus', 'joining_date', 'pay_scale_id', 'grade_id', 'payroll_group_id', 'incrementDate', 'holdIncrement', 'gpf_exempt'].includes(fieldKey) ||
        fieldKey.startsWith('promotions') ||
        fieldKey.startsWith('leave_statuses')
    ) {
        return 'employment';
    }
    return 'account';
};

const tabErrorCounts = computed(() => {
    const counts = { account: 0, employment: 0, department: 0, bank: 0 };
    for (const key of Object.keys(formErrors.value)) {
        const tabId = resolveErrorTab(key);
        counts[tabId] = (counts[tabId] || 0) + 1;
    }
    return counts;
});

const hasErrors = computed(() => Object.keys(formErrors.value).length > 0);

watch(
    () => props.employee,
    (emp) => {
        if (!emp) return;
        formErrors.value = {};
        activeTab.value = 'account';

        const empInfo = emp.employee_info || emp.employeeInfo || {};
        const payrollGroupId = empInfo.payroll_group_id || '';
        let gradeId = '';
        if (payrollGroupId && props.payrollGroups) {
            const pg = props.payrollGroups.find((p) => Number(p.id) === Number(payrollGroupId));
            if (pg) gradeId = pg.grade_id;
        }

        form.value = {
            id: emp.id,
            name: emp.name || '',
            email: emp.email || '',
            gender: emp.gender || 'male',
            isActive: Boolean(emp.isActive),

            nid_no: emp.nid?.nid_no || '',
            nid_img: emp.nid?.nid_img || '',
            bid_no: emp.bid?.bid_no || '',
            date_of_birth: emp.bid?.date_of_birth || '',
            bidImg: emp.bid?.bidImg || '',
            tin_no: emp.tin?.tin_no || '',
            tin_img: emp.tin?.tin_img || '',

            maritial_status: emp.maritial_status?.status || emp.maritialStatus?.status || 'single',
            religion: emp.religion?.religion || 'Islam',
            childCount: emp.child_count?.childCount || emp.childCount?.childCount || 0,
            boy: emp.child_count?.boy || emp.childCount?.boy || 0,
            girl: emp.child_count?.girl || emp.childCount?.girl || 0,
            age: emp.age?.age || '',

            joining_date: emp.joining_date?.joining_date || emp.joiningDate?.joining_date || '',
            dorm_name: emp.dormitory?.dorm_name || '',
            dorm_type: emp.dormitory?.dorm_type || '',
            dorm_address: emp.dormitory?.dorm_address || '',

            emp_attr_name: emp.employee_attribute?.name || emp.employeeAttribute?.name || '',
            emp_attr_description: emp.employee_attribute?.description || emp.employeeAttribute?.description || '',
            emp_attr_action: Boolean(emp.employee_attribute?.isActive ?? emp.employeeAttribute?.isActive),

            employeeCode: empInfo.employeeCode || '',
            jobStatus: empInfo.jobStatus || 'permanent',
            incrementDate: empInfo.incrementDate || '',
            holdIncrement: Boolean(empInfo.holdIncrement),
            gpf_exempt: Boolean(empInfo.gpf_exempt),
            pay_scale_id: empInfo.pay_scale_id || '',
            grade_id: gradeId,
            payroll_group_id: payrollGroupId,

            phone_numbers: emp.phone_numbers?.length > 0
                ? emp.phone_numbers.map((p) => ({ phoneNumber: p.phoneNumber || '' }))
                : [{ phoneNumber: '' }],

            department_offices: emp.department_offices?.length > 0
                ? emp.department_offices.map((d) => ({ department_office_id: d.id || '' }))
                : [{ department_office_id: '' }],

            user_categories: emp.user_categories?.length > 0
                ? emp.user_categories.map((c) => ({ user_category_id: c.id || '' }))
                : [{ user_category_id: '' }],

            addresses: emp.addresses?.length > 0
                ? emp.addresses.map((a) => ({ address: a.address || '' }))
                : [{ address: '' }],

            bank_accounts: emp.bank_accounts?.length > 0
                ? emp.bank_accounts.map((b) => ({
                    bank_routing_no: b.routing_no || '',
                    bank_account_no: b.account_no || '',
                    bank_type: b.type || '',
                    bank_address: b.address || '',
                    isSalaryAccount: Boolean(b.isSalaryAccount),
                }))
                : [{ bank_routing_no: '', bank_account_no: '', bank_type: '', bank_address: '', isSalaryAccount: false }],

            leave_statuses: emp.leave_statuses?.length > 0
                ? emp.leave_statuses.map((l) => ({
                    onLeave: Boolean(l.onLeave),
                    leave_reason: l.reason || '',
                    isPaid: Boolean(l.isPaid),
                    leave_isActive: Boolean(l.isActive),
                    leave_duration: l.duration || 0,
                }))
                : [{ onLeave: false, leave_reason: '', isPaid: false, leave_isActive: false, leave_duration: 0 }],

            promotions: emp.promotions?.length > 0
                ? emp.promotions.map((p) => ({
                    promoted_to: p.promoted_to || '',
                    pay_scale_id: p.pay_scale_id || '',
                    payroll_group_id: p.payroll_group_id || '',
                }))
                : [{ promoted_to: '', pay_scale_id: '', payroll_group_id: '' }],
        };
    },
    { immediate: true },
);

const closeModal = () => {
    if (!isSaving.value) emit('close');
};

const saveEmployee = async () => {
    formErrors.value = {};
    isSaving.value = true;
    try {
        await axios.put(route('employees.update', form.value.id), form.value);
        emit('saved');
        emit('close');
    } catch (error) {
        formErrors.value = error.response?.data?.errors ?? {
            save: error.response?.data?.message || 'Employee update failed.',
        };
        if (tabErrorCounts.value[activeTab.value] === 0) {
            const firstTabWithError = tabs.find((t) => tabErrorCounts.value[t.id] > 0);
            if (firstTabWithError) activeTab.value = firstTabWithError.id;
        }
    } finally {
        isSaving.value = false;
    }
};
</script>

<template>
    <Modal :show="Boolean(employee)" @close="closeModal" maxWidth="5xl">
        <form @submit.prevent="saveEmployee" class="flex flex-col max-h-[85vh] text-gray-900 dark:text-gray-100">
            <!-- Fixed Modal Header & Tabs -->
            <div class="shrink-0 px-6 pt-5 pb-0 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 rounded-t-lg">
                <div class="flex items-center justify-between pb-4">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                            Edit Employee Profile
                        </h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            {{ form.name || 'Employee' }} <span v-if="form.employeeCode" class="font-mono text-emerald-600 dark:text-emerald-400">({{ form.employeeCode }})</span> — Update credentials, employment status, and assignments
                        </p>
                    </div>
                    <button type="button" @click="closeModal" class="p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded-lg">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <!-- Tab Navigation -->
                <nav class="flex flex-wrap gap-1 -mb-px" aria-label="Employee profile sections">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        type="button"
                        @click="activeTab = tab.id"
                        class="inline-flex items-center gap-2 px-4 py-2.5 text-xs font-semibold border-b-2 transition-colors whitespace-nowrap"
                        :class="activeTab === tab.id
                            ? 'border-emerald-600 text-emerald-700 dark:border-emerald-400 dark:text-emerald-300'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200'"
                    >
                        <span>{{ tab.label }}</span>
                        <span
                            v-if="tabErrorCounts[tab.id] > 0"
                            class="inline-flex items-center justify-center min-w-[1.125rem] h-4 px-1 rounded-full text-[10px] font-bold bg-red-600 text-white"
                            :title="`${tabErrorCounts[tab.id]} validation error(s)`"
                        >
                            {{ tabErrorCounts[tab.id] }}
                        </span>
                    </button>
                </nav>
            </div>

            <!-- Scrollable Tab Body -->
            <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                <!-- Validation Error Summary -->
                <div v-if="hasErrors" class="p-3.5 bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 rounded-r-lg text-xs text-red-700 dark:text-red-300 space-y-1">
                    <p class="font-semibold text-sm">Please resolve the following errors:</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        <li v-for="(errList, key) in formErrors" :key="key">{{ Array.isArray(errList) ? errList[0] : errList }}</li>
                    </ul>
                </div>

                <!-- Tab 1: Account & Security -->
                <div v-show="activeTab === 'account'" class="space-y-5">
                    <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-4">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Account & Security</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div>
                                <InputLabel for="edit_name" value="Name *" />
                                <TextInput id="edit_name" type="text" class="mt-1 block w-full text-sm" v-model="form.name" required />
                                <InputError class="mt-1" :message="formErrors.name?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_email" value="Email Address *" />
                                <TextInput id="edit_email" type="email" class="mt-1 block w-full text-sm" v-model="form.email" required />
                                <InputError class="mt-1" :message="formErrors.email?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_gender" value="Gender *" />
                                <select id="edit_gender" v-model="form.gender" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm" required>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                                <InputError class="mt-1" :message="formErrors.gender?.[0]" />
                            </div>
                            <div class="flex items-center pt-2">
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <Checkbox id="edit_isActive" v-model:checked="form.isActive" class="text-emerald-600 focus:ring-emerald-500" />
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Account is Active</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-4">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Personal Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
                            <div>
                                <InputLabel for="edit_date_of_birth" value="Date of Birth" />
                                <TextInput id="edit_date_of_birth" type="date" class="mt-1 block w-full text-sm dark:[color-scheme:dark]" v-model="form.date_of_birth" />
                            </div>
                            <div>
                                <InputLabel for="edit_age" value="Age (Years)" />
                                <TextInput id="edit_age" type="number" class="mt-1 block w-full text-sm" v-model="form.age" />
                            </div>
                            <div>
                                <InputLabel for="edit_maritial_status" value="Marital Status" />
                                <select id="edit_maritial_status" v-model="form.maritial_status" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm">
                                    <option value="single">Single</option>
                                    <option value="married">Married</option>
                                    <option value="divorced">Divorced</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="edit_religion" value="Religion" />
                                <select id="edit_religion" v-model="form.religion" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm">
                                    <option v-for="r in religions" :key="r" :value="r">{{ r }}</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="edit_boy" value="Boys" />
                                <TextInput id="edit_boy" type="number" min="0" class="mt-1 block w-full text-sm" v-model="form.boy" />
                            </div>
                            <div>
                                <InputLabel for="edit_girl" value="Girls" />
                                <TextInput id="edit_girl" type="number" min="0" class="mt-1 block w-full text-sm" v-model="form.girl" />
                            </div>
                            <div>
                                <InputLabel for="edit_childCount" value="Total Children" />
                                <TextInput id="edit_childCount" type="number" class="mt-1 block w-full bg-gray-100 dark:bg-gray-800 dark:border-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed text-sm" v-model="form.childCount" disabled />
                            </div>
                        </div>
                    </div>

                    <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-4">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">National Identifications</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <InputLabel for="edit_nid_no" value="NID Number" />
                                <TextInput id="edit_nid_no" type="text" class="mt-1 block w-full text-sm" v-model="form.nid_no" />
                            </div>
                            <div>
                                <InputLabel for="edit_nid_img" value="NID Image (URL)" />
                                <TextInput id="edit_nid_img" type="text" class="mt-1 block w-full text-sm" v-model="form.nid_img" />
                            </div>
                            <div>
                                <InputLabel for="edit_bid_no" value="BID Number" />
                                <TextInput id="edit_bid_no" type="text" class="mt-1 block w-full text-sm" v-model="form.bid_no" />
                            </div>
                            <div>
                                <InputLabel for="edit_bidImg" value="BID Image (URL)" />
                                <TextInput id="edit_bidImg" type="text" class="mt-1 block w-full text-sm" v-model="form.bidImg" />
                            </div>
                            <div>
                                <InputLabel for="edit_tin_no" value="TIN Number" />
                                <TextInput id="edit_tin_no" type="text" class="mt-1 block w-full text-sm" v-model="form.tin_no" />
                            </div>
                            <div>
                                <InputLabel for="edit_tin_img" value="TIN Image (URL)" />
                                <TextInput id="edit_tin_img" type="text" class="mt-1 block w-full text-sm" v-model="form.tin_img" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: Employment & Pay -->
                <div v-show="activeTab === 'employment'" class="space-y-5">
                    <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-4">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Employment & Pay Structure</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <InputLabel for="edit_employeeCode" value="Employee Code / ID *" />
                                <TextInput id="edit_employeeCode" type="text" class="mt-1 block w-full text-sm" v-model="form.employeeCode" required />
                                <InputError class="mt-1" :message="formErrors.employeeCode?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_jobStatus" value="Employee Type / Job Status *" />
                                <select id="edit_jobStatus" v-model="form.jobStatus" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm" required>
                                    <option v-for="status in jobStatusOptions" :key="status.value" :value="status.value">
                                        {{ status.label }}
                                    </option>
                                </select>
                                <InputError class="mt-1" :message="formErrors.jobStatus?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_joining_date" value="Joining Date" />
                                <TextInput id="edit_joining_date" type="date" class="mt-1 block w-full text-sm dark:[color-scheme:dark]" v-model="form.joining_date" />
                                <InputError class="mt-1" :message="formErrors.joining_date?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_pay_scale_id" value="Pay Scale *" />
                                <select id="edit_pay_scale_id" v-model="form.pay_scale_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm" required>
                                    <option value="" disabled>Select Pay Scale</option>
                                    <option v-for="ps in payScales" :key="ps.id" :value="ps.id">{{ ps.scale_name }}</option>
                                </select>
                                <InputError class="mt-1" :message="formErrors.pay_scale_id?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_grade_id" value="Grade *" />
                                <select id="edit_grade_id" v-model="form.grade_id" :disabled="!form.pay_scale_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm disabled:opacity-50" required>
                                    <option value="" disabled>{{ form.pay_scale_id ? 'Select Grade' : 'Choose Pay Scale First' }}</option>
                                    <option v-for="g in availableGrades" :key="g.id" :value="g.id">{{ g.grade_name }}</option>
                                </select>
                                <InputError class="mt-1" :message="formErrors.grade_id?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_payroll_group_id" value="Payroll Group *" />
                                <select id="edit_payroll_group_id" v-model="form.payroll_group_id" :disabled="!form.grade_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm disabled:opacity-50" required>
                                    <option value="" disabled>{{ form.grade_id ? 'Select Payroll Group' : 'Choose Grade First' }}</option>
                                    <option v-for="pg in availablePayrollGroups" :key="pg.id" :value="pg.id">
                                        {{ pg.payroll_group_type?.group_name || ('Group #' + pg.id) }}
                                    </option>
                                </select>
                                <InputError class="mt-1" :message="formErrors.payroll_group_id?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_incrementDate" value="Annual Increment Date" />
                                <TextInput id="edit_incrementDate" type="date" class="mt-1 block w-full text-sm dark:[color-scheme:dark]" v-model="form.incrementDate" />
                                <InputError class="mt-1" :message="formErrors.incrementDate?.[0]" />
                            </div>
                            <div class="flex items-center pt-6">
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <Checkbox id="edit_holdIncrement" v-model:checked="form.holdIncrement" class="text-emerald-600 focus:ring-emerald-500" />
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Hold Increment</span>
                                </label>
                                <InputError class="mt-1" :message="formErrors.holdIncrement?.[0]" />
                            </div>
                            <div class="flex items-center pt-6">
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <Checkbox id="edit_gpf_exempt" v-model:checked="form.gpf_exempt" class="text-emerald-600 focus:ring-emerald-500" />
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">GPF Exempt (0.00 GPF)</span>
                                </label>
                                <InputError class="mt-1" :message="formErrors.gpf_exempt?.[0]" />
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-3">
                            <div class="flex justify-between items-center">
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Promotions History</h3>
                                <PrimaryButton type="button" @click="addField('promotions', { promoted_to: '', pay_scale_id: '', payroll_group_id: '' })" class="!px-2.5 !py-1 text-xs">+ Add Promo</PrimaryButton>
                            </div>
                            <div class="space-y-3">
                                <div v-for="(promo, idx) in form.promotions" :key="'promo_'+idx" class="p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm space-y-2">
                                    <div class="flex justify-between items-center text-xs">
                                        <span class="font-semibold text-gray-600 dark:text-gray-300">Promo #{{ idx + 1 }}</span>
                                        <button v-if="form.promotions.length > 1" type="button" @click="removeField('promotions', idx)" class="text-red-500 hover:text-red-700 dark:text-red-400">Remove</button>
                                    </div>
                                    <div>
                                        <InputLabel :for="'edit_promoted_to_'+idx" value="Position / Title Promoted To" />
                                        <TextInput :id="'edit_promoted_to_'+idx" type="text" class="mt-1 block w-full text-xs" v-model="promo.promoted_to" placeholder="e.g. Associate Professor" />
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        <div>
                                            <InputLabel :for="'edit_promo_scale_'+idx" value="Pay Scale" />
                                            <select :id="'edit_promo_scale_'+idx" v-model="promo.pay_scale_id" @change="onPromoPayScaleChange(promo)" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-xs">
                                                <option value="">Select Scale</option>
                                                <option v-for="ps in payScales" :key="ps.id" :value="ps.id">{{ ps.scale_name }}</option>
                                            </select>
                                        </div>
                                        <div>
                                            <InputLabel :for="'edit_promo_group_'+idx" value="Payroll Group" />
                                            <select :id="'edit_promo_group_'+idx" v-model="promo.payroll_group_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-xs">
                                                <option value="">Select Group</option>
                                                <option v-for="pg in getPromoPayrollGroups(promo)" :key="pg.id" :value="pg.id">
                                                    {{ pg.payroll_group_type?.group_name || ('Group #' + pg.id) }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-3">
                            <div class="flex justify-between items-center">
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Leave History</h3>
                                <PrimaryButton type="button" @click="addField('leave_statuses', { onLeave: false, leave_reason: '', isPaid: false, leave_isActive: false, leave_duration: 0 })" class="!px-2.5 !py-1 text-xs">+ Add Leave</PrimaryButton>
                            </div>
                            <div class="space-y-3">
                                <div v-for="(leave, idx) in form.leave_statuses" :key="'leave_'+idx" class="p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm space-y-2">
                                    <div class="flex justify-between items-center text-xs">
                                        <span class="font-semibold text-gray-600 dark:text-gray-300">Leave #{{ idx + 1 }}</span>
                                        <button v-if="form.leave_statuses.length > 1" type="button" @click="removeField('leave_statuses', idx)" class="text-red-500 hover:text-red-700 dark:text-red-400">Remove</button>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                        <div class="sm:col-span-2">
                                            <InputLabel :for="'edit_leave_reason_'+idx" value="Reason" />
                                            <TextInput :id="'edit_leave_reason_'+idx" type="text" class="mt-1 block w-full text-xs" v-model="leave.leave_reason" />
                                        </div>
                                        <div>
                                            <InputLabel :for="'edit_leave_dur_'+idx" value="Days" />
                                            <TextInput :id="'edit_leave_dur_'+idx" type="number" min="0" class="mt-1 block w-full text-xs" v-model="leave.leave_duration" />
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-3 pt-1 text-xs">
                                        <label class="flex items-center gap-1.5 cursor-pointer text-gray-700 dark:text-gray-300">
                                            <Checkbox :id="'edit_onLeave_'+idx" v-model:checked="leave.onLeave" class="text-emerald-600 focus:ring-emerald-500" />
                                            On Leave
                                        </label>
                                        <label class="flex items-center gap-1.5 cursor-pointer text-gray-700 dark:text-gray-300">
                                            <Checkbox :id="'edit_isPaid_'+idx" v-model:checked="leave.isPaid" class="text-emerald-600 focus:ring-emerald-500" />
                                            Paid Leave
                                        </label>
                                        <label class="flex items-center gap-1.5 cursor-pointer text-gray-700 dark:text-gray-300">
                                            <Checkbox :id="'edit_leave_isActive_'+idx" v-model:checked="leave.leave_isActive" class="text-emerald-600 focus:ring-emerald-500" />
                                            Active
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 3: Department & Designation -->
                <div v-show="activeTab === 'department'" class="space-y-5">
                    <div class="p-4 rounded-xl border border-emerald-200 dark:border-emerald-900/60 bg-emerald-50/50 dark:bg-emerald-950/20 flex flex-wrap gap-6 text-xs">
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">Current Designation:</span>
                            <span class="ml-1.5 font-semibold text-gray-900 dark:text-gray-100">{{ selectedDesignationName }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">Grade:</span>
                            <span class="ml-1.5 font-semibold text-gray-900 dark:text-gray-100">{{ selectedGradeName }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">Pay Scale:</span>
                            <span class="ml-1.5 font-semibold text-gray-900 dark:text-gray-100">{{ selectedPayScaleName }}</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-3">
                            <div class="flex justify-between items-center">
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Department / Offices *</h3>
                                <PrimaryButton type="button" @click="addField('department_offices', { department_office_id: '' })" class="!px-2.5 !py-1 text-xs">+ Add</PrimaryButton>
                            </div>
                            <div class="space-y-3">
                                <div v-for="(dept, idx) in form.department_offices" :key="'dept_'+idx" class="flex items-center gap-2">
                                    <div class="flex-1">
                                        <select :id="'edit_dept_'+idx" v-model="dept.department_office_id" class="block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-xs" required>
                                            <option value="" disabled>Select Department</option>
                                            <option v-for="d in availableDepartments(idx)" :key="d.id" :value="d.id">{{ d.name }}</option>
                                        </select>
                                    </div>
                                    <button v-if="form.department_offices.length > 1" type="button" @click="removeField('department_offices', idx)" class="p-1.5 text-red-500 hover:text-red-700 dark:text-red-400">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                            </div>
                            <InputError class="mt-1" :message="formErrors.department_offices?.[0]" />
                        </div>

                        <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-3">
                            <div class="flex justify-between items-center">
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Staff Categories / Class</h3>
                                <PrimaryButton type="button" @click="addField('user_categories', { user_category_id: '' })" class="!px-2.5 !py-1 text-xs">+ Add</PrimaryButton>
                            </div>
                            <div class="space-y-3">
                                <div v-for="(cat, idx) in form.user_categories" :key="'cat_'+idx" class="flex items-center gap-2">
                                    <div class="flex-1">
                                        <select :id="'edit_cat_'+idx" v-model="cat.user_category_id" class="block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-xs">
                                            <option value="" disabled>Select Staff Category</option>
                                            <option v-for="c in availableUserCategories(idx)" :key="c.id" :value="c.id">{{ c.name }}</option>
                                        </select>
                                    </div>
                                    <button v-if="form.user_categories.length > 1" type="button" @click="removeField('user_categories', idx)" class="p-1.5 text-red-500 hover:text-red-700 dark:text-red-400">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 4: Bank & Contact -->
                <div v-show="activeTab === 'bank'" class="space-y-5">
                    <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Bank Accounts</h3>
                            <PrimaryButton type="button" @click="addField('bank_accounts', { bank_routing_no: '', bank_account_no: '', bank_type: '', bank_address: '', isSalaryAccount: false })" class="!px-2.5 !py-1 text-xs">+ Add Account</PrimaryButton>
                        </div>
                        <InputError class="mt-1" :message="formErrors.account_no?.[0]" />
                        <div class="space-y-3">
                            <div v-for="(account, idx) in form.bank_accounts" :key="'bank_'+idx" class="p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm space-y-3">
                                <div class="flex justify-between items-center text-xs">
                                    <span class="font-semibold text-gray-600 dark:text-gray-300">Account #{{ idx + 1 }}</span>
                                    <button v-if="form.bank_accounts.length > 1" type="button" @click="removeField('bank_accounts', idx)" class="text-red-500 hover:text-red-700 dark:text-red-400">Remove</button>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                                    <div>
                                        <InputLabel :for="'edit_bank_account_no_'+idx" value="Account No" />
                                        <TextInput :id="'edit_bank_account_no_'+idx" type="text" class="mt-1 block w-full text-xs" v-model="account.bank_account_no" />
                                    </div>
                                    <div>
                                        <InputLabel :for="'edit_bank_routing_no_'+idx" value="Routing No" />
                                        <TextInput :id="'edit_bank_routing_no_'+idx" type="text" class="mt-1 block w-full text-xs" v-model="account.bank_routing_no" />
                                    </div>
                                    <div>
                                        <InputLabel :for="'edit_bank_type_'+idx" value="Type" />
                                        <TextInput :id="'edit_bank_type_'+idx" type="text" class="mt-1 block w-full text-xs" v-model="account.bank_type" />
                                    </div>
                                    <div>
                                        <InputLabel :for="'edit_bank_address_'+idx" value="Branch / Address" />
                                        <TextInput :id="'edit_bank_address_'+idx" type="text" class="mt-1 block w-full text-xs" v-model="account.bank_address" />
                                    </div>
                                </div>
                                <div>
                                    <label class="flex items-center gap-2 cursor-pointer select-none text-xs">
                                        <Checkbox :id="'edit_isSalaryAccount_'+idx" v-model:checked="account.isSalaryAccount" class="text-emerald-600 focus:ring-emerald-500" />
                                        <span class="font-medium text-emerald-700 dark:text-emerald-400">Primary Salary Account</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-4">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Contacts, Accommodation & Attributes</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-3">
                                <div class="flex justify-between items-center">
                                    <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-400">Phone Numbers</h4>
                                    <PrimaryButton type="button" @click="addField('phone_numbers', { phoneNumber: '' })" class="!px-2 !py-0.5 text-xs">+ Add</PrimaryButton>
                                </div>
                                <div v-for="(phone, idx) in form.phone_numbers" :key="'phone_'+idx" class="flex items-center gap-2">
                                    <TextInput :id="'edit_phone_'+idx" type="text" class="block w-full text-xs" v-model="phone.phoneNumber" placeholder="Phone Number" />
                                    <button v-if="form.phone_numbers.length > 1" type="button" @click="removeField('phone_numbers', idx)" class="text-red-500 hover:text-red-700 dark:text-red-400">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <div class="flex justify-between items-center">
                                    <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-400">Addresses</h4>
                                    <PrimaryButton type="button" @click="addField('addresses', { address: '' })" class="!px-2 !py-0.5 text-xs">+ Add</PrimaryButton>
                                </div>
                                <div v-for="(addr, idx) in form.addresses" :key="'addr_'+idx" class="flex items-center gap-2">
                                    <TextInput :id="'edit_addr_'+idx" type="text" class="block w-full text-xs" v-model="addr.address" placeholder="Residential Address" />
                                    <button v-if="form.addresses.length > 1" type="button" @click="removeField('addresses', idx)" class="text-red-500 hover:text-red-700 dark:text-red-400">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-gray-200 dark:border-gray-700 pt-3">
                            <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-2">Campus Residence</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div>
                                    <InputLabel for="edit_dorm_name" value="Quarter Name" />
                                    <TextInput id="edit_dorm_name" type="text" class="mt-1 block w-full text-xs" v-model="form.dorm_name" />
                                </div>
                                <div>
                                    <InputLabel for="edit_dorm_type" value="Residence Type" />
                                    <TextInput id="edit_dorm_type" type="text" class="mt-1 block w-full text-xs" v-model="form.dorm_type" />
                                </div>
                                <div>
                                    <InputLabel for="edit_dorm_address" value="Flat / Address" />
                                    <TextInput id="edit_dorm_address" type="text" class="mt-1 block w-full text-xs" v-model="form.dorm_address" />
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-gray-200 dark:border-gray-700 pt-3">
                            <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-2">Special Attributes</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div>
                                    <InputLabel for="edit_emp_attr_name" value="Attribute Name" />
                                    <TextInput id="edit_emp_attr_name" type="text" class="mt-1 block w-full text-xs" v-model="form.emp_attr_name" />
                                </div>
                                <div>
                                    <InputLabel for="edit_emp_attr_description" value="Description" />
                                    <TextInput id="edit_emp_attr_description" type="text" class="mt-1 block w-full text-xs" v-model="form.emp_attr_description" />
                                </div>
                                <div class="flex items-center pt-5">
                                    <label class="flex items-center gap-2 cursor-pointer select-none">
                                        <Checkbox id="edit_emp_attr_action" v-model:checked="form.emp_attr_action" class="text-emerald-600 focus:ring-emerald-500" />
                                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Attribute Active</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Fixed Modal Footer -->
            <div class="shrink-0 px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 rounded-b-lg flex justify-end gap-3">
                <SecondaryButton @click="closeModal" type="button" class="!px-4 !py-2">
                    Cancel
                </SecondaryButton>
                <PrimaryButton
                    type="submit"
                    :disabled="isSaving"
                    class="!py-2 !px-5 bg-emerald-600 hover:bg-emerald-500 inline-flex items-center gap-2 shadow-sm"
                >
                    <svg v-if="isSaving" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    <span>{{ isSaving ? 'Saving Changes...' : 'Save Changes' }}</span>
                </PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
