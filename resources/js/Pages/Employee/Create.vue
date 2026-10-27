<script setup>
import { watch, computed } from 'vue';
import { useForm, Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { useAdminStore } from '@/stores/admin';

const props = defineProps({
    departmentOffices: {
        type: Array,
        default: () => []
    },
    payScales: {
        type: Array,
        default: () => []
    },
    grades: {
        type: Array,
        default: () => []
    },
    payrollGroups: {
        type: Array,
        default: () => []
    },
    userCategories: {
        type: Array,
        default: () => []
    },
});

const form = useForm({
    name: '',
    email: '',
    password: 'password', // Default password for new onboarding
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

    // Accommodation
    dorm_name: '',
    dorm_type: '',
    dorm_address: '',

    // Custom Attributes
    emp_attr_name: '',
    emp_attr_description: '',
    emp_attr_action: false,
    
    // Employment & Pay Structure
    employeeCode: '',
    jobStatus: 'permanent',
    joining_date: '',
    incrementDate: '',
    holdIncrement: false,
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
    promotions: [{ promoted_to: '', pay_scale_id: '', payroll_group_id: '' }]
});

const addField = (field, defaultObj) => form[field].push({ ...defaultObj });
const removeField = (field, index) => form[field].splice(index, 1);

// Automatically synchronize child count
watch(() => [form.boy, form.girl], ([boy, girl]) => {
    form.childCount = (parseInt(boy) || 0) + (parseInt(girl) || 0);
});

// Cascading Pay Structure Selects
const availableGrades = computed(() => {
    if (!form.pay_scale_id) return [];
    return (props.grades || []).filter(g => Number(g.pay_scale_id) === Number(form.pay_scale_id));
});

const availablePayrollGroups = computed(() => {
    if (!form.grade_id) return [];
    return (props.payrollGroups || []).filter(pg => Number(pg.grade_id) === Number(form.grade_id));
});

watch(() => form.pay_scale_id, (newScale) => {
    if (!newScale) {
        form.grade_id = '';
        form.payroll_group_id = '';
        return;
    }
    if (form.grade_id) {
        const grade = (props.grades || []).find(g => Number(g.id) === Number(form.grade_id));
        if (!grade || Number(grade.pay_scale_id) !== Number(newScale)) {
            form.grade_id = '';
            form.payroll_group_id = '';
        }
    }
});

watch(() => form.grade_id, (newGrade) => {
    if (!newGrade) {
        form.payroll_group_id = '';
        return;
    }
    if (form.payroll_group_id) {
        const pg = (props.payrollGroups || []).find(p => Number(p.id) === Number(form.payroll_group_id));
        if (!pg || Number(pg.grade_id) !== Number(newGrade)) {
            form.payroll_group_id = '';
        }
    }
});

// Department & Category Unique Options Filter
const availableDepartments = (currentIndex) => {
    if (!props.departmentOffices) return [];
    const selectedIds = form.department_offices
        .filter((_, idx) => idx !== currentIndex)
        .map(d => Number(d.department_office_id))
        .filter(id => Boolean(id));
        
    return props.departmentOffices.filter(d => !selectedIds.includes(Number(d.id)));
};

const availableUserCategories = (currentIndex) => {
    if (!props.userCategories) return [];
    const selectedIds = form.user_categories
        .filter((_, idx) => idx !== currentIndex)
        .map(c => Number(c.user_category_id))
        .filter(id => Boolean(id));
        
    return props.userCategories.filter(c => !selectedIds.includes(Number(c.id)));
};

// Promotion Dropdown Helpers
const getPromoPayrollGroups = (promo) => {
    if (!props.payrollGroups) return [];
    if (!promo.pay_scale_id) return props.payrollGroups;
    const gradeIds = (props.grades || [])
        .filter(g => Number(g.pay_scale_id) === Number(promo.pay_scale_id))
        .map(g => Number(g.id));
    return props.payrollGroups.filter(pg => gradeIds.includes(Number(pg.grade_id)));
};

const onPromoPayScaleChange = (promo) => {
    if (promo.payroll_group_id) {
        const matching = getPromoPayrollGroups(promo);
        if (!matching.some(pg => Number(pg.id) === Number(promo.payroll_group_id))) {
            promo.payroll_group_id = '';
        }
    }
};

const hasValidationErrors = computed(() => {
    return Object.keys(form.errors).length > 0;
});

const adminStore = useAdminStore();

const submit = async () => {
    const isConfirmed = await adminStore.confirmAction(
        'Create New Employee?',
        'This will create a new user account and set up their institutional records.'
    );

    if (isConfirmed) {
        form.post(route('employees.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                adminStore.showSuccess('Employee Created', 'The employee and initial salary profile were created successfully.');
            },
        });
    }
};
</script>

<template>
    <Head title="Create Employee" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="font-bold text-2xl text-gray-800 dark:text-gray-100 leading-tight">Create Employee</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Add a new staff member with comprehensive identity, departmental, and payroll records</p>
                </div>
                <div class="flex items-center gap-3">
                    <Link
                        :href="route('employees.edit')"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm transition"
                    >
                        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Employee Directory
                    </Link>
                </div>
            </div>
        </template>

        <div class="pb-16 max-w-7xl mx-auto space-y-6">

            <!-- Validation Error Summary Banner -->
            <div v-if="hasValidationErrors" class="p-4 bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 rounded-r-xl shadow-sm text-sm text-red-700 dark:text-red-300">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div>
                        <p class="font-semibold">Please correct the errors in the form before submitting:</p>
                        <ul class="list-disc list-inside mt-1 space-y-0.5 text-xs">
                            <li v-for="(err, key) in form.errors" :key="key">{{ err }}</li>
                        </ul>
                    </div>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-6">

                <!-- 1. System & Authentication Account Details -->
                <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl p-6 border border-gray-100 dark:border-gray-700/60 transition-colors">
                    <div class="flex items-center gap-3 border-b border-gray-100 dark:border-gray-700 pb-4 mb-6">
                        <div class="p-2 bg-emerald-50 dark:bg-emerald-950/50 rounded-lg text-emerald-600 dark:text-emerald-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Account & Security</h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Core user authentication credentials and system status</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="lg:col-span-2">
                            <InputLabel for="name" value="Full Name *" />
                            <TextInput id="name" type="text" class="mt-1 block w-full" v-model="form.name" placeholder="e.g. Dr. John Doe" required autofocus />
                            <InputError class="mt-1" :message="form.errors.name" />
                        </div>

                        <div class="lg:col-span-2">
                            <InputLabel for="email" value="Email Address *" />
                            <TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" placeholder="john.doe@institution.edu" required />
                            <InputError class="mt-1" :message="form.errors.email" />
                        </div>

                        <div class="lg:col-span-2">
                            <InputLabel for="password" value="Initial Password *" />
                            <TextInput id="password" type="text" class="mt-1 block w-full font-mono text-sm" v-model="form.password" required />
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Default temporary password for new employee onboarding.</p>
                            <InputError class="mt-1" :message="form.errors.password" />
                        </div>

                        <div>
                            <InputLabel for="gender" value="Gender *" />
                            <select id="gender" v-model="form.gender" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm" required>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.gender" />
                        </div>

                        <div class="flex items-center pt-6">
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <Checkbox id="isActive" v-model:checked="form.isActive" class="text-emerald-600 focus:ring-emerald-500" />
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Account is Active</span>
                            </label>
                            <InputError class="mt-1" :message="form.errors.isActive" />
                        </div>
                    </div>
                </div>

                <!-- 2. Employment & Pay Structure (Cleaned & De-duplicated) -->
                <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl p-6 border border-gray-100 dark:border-gray-700/60 transition-colors">
                    <div class="flex items-center gap-3 border-b border-gray-100 dark:border-gray-700 pb-4 mb-6">
                        <div class="p-2 bg-emerald-50 dark:bg-emerald-950/50 rounded-lg text-emerald-600 dark:text-emerald-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Employment & Pay Structure</h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Official institutional code, scale placement, and increment configuration</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div>
                            <InputLabel for="employeeCode" value="Employee Code / ID *" />
                            <TextInput id="employeeCode" type="text" class="mt-1 block w-full" v-model="form.employeeCode" placeholder="e.g. 10425" required />
                            <InputError class="mt-1" :message="form.errors.employeeCode" />
                        </div>

                        <div>
                            <InputLabel for="jobStatus" value="Job Status *" />
                            <select id="jobStatus" v-model="form.jobStatus" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm" required>
                                <option value="permanent">Permanent</option>
                                <option value="contract">Contract</option>
                                <option value="temporary">Temporary</option>
                                <option value="others">Others</option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.jobStatus" />
                        </div>

                        <div>
                            <InputLabel for="joining_date" value="Joining Date" />
                            <TextInput id="joining_date" type="date" class="mt-1 block w-full" v-model="form.joining_date" />
                            <InputError class="mt-1" :message="form.errors.joining_date" />
                        </div>

                        <div>
                            <InputLabel for="pay_scale_id" value="Pay Scale *" />
                            <select id="pay_scale_id" v-model="form.pay_scale_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm" required>
                                <option value="" disabled>Select Pay Scale</option>
                                <option v-for="ps in payScales" :key="ps.id" :value="ps.id">{{ ps.scale_name }}</option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.pay_scale_id" />
                        </div>

                        <div>
                            <InputLabel for="grade_id" value="Grade *" />
                            <select id="grade_id" v-model="form.grade_id" :disabled="!form.pay_scale_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm disabled:opacity-50 disabled:cursor-not-allowed" required>
                                <option value="" disabled>{{ form.pay_scale_id ? 'Select Grade' : 'Choose Pay Scale First' }}</option>
                                <option v-for="g in availableGrades" :key="g.id" :value="g.id">{{ g.grade_name }}</option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.grade_id" />
                        </div>

                        <div>
                            <InputLabel for="payroll_group_id" value="Payroll Group *" />
                            <select id="payroll_group_id" v-model="form.payroll_group_id" :disabled="!form.grade_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm disabled:opacity-50 disabled:cursor-not-allowed" required>
                                <option value="" disabled>{{ form.grade_id ? 'Select Payroll Group' : 'Choose Grade First' }}</option>
                                <option v-for="pg in availablePayrollGroups" :key="pg.id" :value="pg.id">
                                    {{ pg.payroll_group_type?.group_name || ('Group #' + pg.id) }}
                                </option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.payroll_group_id" />
                        </div>

                        <div>
                            <InputLabel for="incrementDate" value="Annual Increment Date" />
                            <TextInput id="incrementDate" type="date" class="mt-1 block w-full" v-model="form.incrementDate" />
                            <InputError class="mt-1" :message="form.errors.incrementDate" />
                        </div>

                        <div class="flex items-center pt-6">
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <Checkbox id="holdIncrement" v-model:checked="form.holdIncrement" class="text-emerald-600 focus:ring-emerald-500" />
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Hold Increment (Suspension)</span>
                            </label>
                            <InputError class="mt-1" :message="form.errors.holdIncrement" />
                        </div>
                    </div>
                </div>

                <!-- 3. Department & Institutional Category Assignments -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- Departments -->
                    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl p-6 border border-gray-100 dark:border-gray-700/60 transition-colors">
                        <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 pb-3 mb-4">
                            <div>
                                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Department / Offices *</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400">First department selected serves as the employee's primary department</p>
                            </div>
                            <PrimaryButton type="button" @click="addField('department_offices', { department_office_id: '' })" class="!px-3 !py-1 text-xs">
                                + Add Dept
                            </PrimaryButton>
                        </div>

                        <div class="space-y-4">
                            <div v-for="(dept, idx) in form.department_offices" :key="idx" class="flex items-center gap-3">
                                <div class="flex-1">
                                    <div class="flex items-center justify-between">
                                        <InputLabel :for="'dept_'+idx" :value="(idx === 0 ? 'Primary Department *' : 'Affiliated Department ' + (idx + 1))" />
                                        <span v-if="idx === 0" class="text-[10px] font-semibold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 px-2 py-0.5 rounded">Primary</span>
                                    </div>
                                    <select :id="'dept_'+idx" v-model="dept.department_office_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm" required>
                                        <option value="" disabled>Select Department / Office</option>
                                        <option v-for="d in availableDepartments(idx)" :key="d.id" :value="d.id">{{ d.name }}</option>
                                    </select>
                                    <InputError class="mt-1" :message="form.errors['department_offices.' + idx + '.department_office_id'] || (idx === 0 ? form.errors.department_offices : null)" />
                                </div>
                                <div class="pt-5" v-if="form.department_offices.length > 1">
                                    <button type="button" @click="removeField('department_offices', idx)" class="p-2 text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg transition" title="Remove Department">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Categories -->
                    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl p-6 border border-gray-100 dark:border-gray-700/60 transition-colors">
                        <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 pb-3 mb-4">
                            <div>
                                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Employee Categories</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Institutional staffing classifications (e.g. Faculty, Officer, Staff)</p>
                            </div>
                            <PrimaryButton type="button" @click="addField('user_categories', { user_category_id: '' })" class="!px-3 !py-1 text-xs">
                                + Add Category
                            </PrimaryButton>
                        </div>

                        <div class="space-y-4">
                            <div v-for="(cat, idx) in form.user_categories" :key="idx" class="flex items-center gap-3">
                                <div class="flex-1">
                                    <InputLabel :for="'cat_'+idx" :value="'Category ' + (idx + 1)" />
                                    <select :id="'cat_'+idx" v-model="cat.user_category_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm">
                                        <option value="" disabled>Select Staff Category</option>
                                        <option v-for="c in availableUserCategories(idx)" :key="c.id" :value="c.id">{{ c.name }}</option>
                                    </select>
                                    <InputError class="mt-1" :message="form.errors['user_categories.' + idx + '.user_category_id']" />
                                </div>
                                <div class="pt-5" v-if="form.user_categories.length > 1">
                                    <button type="button" @click="removeField('user_categories', idx)" class="p-2 text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg transition" title="Remove Category">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- 4. Personal Profile & Demographics -->
                <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl p-6 border border-gray-100 dark:border-gray-700/60 transition-colors">
                    <div class="flex items-center gap-3 border-b border-gray-100 dark:border-gray-700 pb-4 mb-6">
                        <div class="p-2 bg-emerald-50 dark:bg-emerald-950/50 rounded-lg text-emerald-600 dark:text-emerald-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Personal & Family Profile</h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Personal background and family composition metrics</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                        <div>
                            <InputLabel for="date_of_birth" value="Date of Birth" />
                            <TextInput id="date_of_birth" type="date" class="mt-1 block w-full" v-model="form.date_of_birth" />
                            <InputError class="mt-1" :message="form.errors.date_of_birth" />
                        </div>

                        <div>
                            <InputLabel for="age" value="Age (Years)" />
                            <TextInput id="age" type="number" class="mt-1 block w-full" v-model="form.age" placeholder="e.g. 35" />
                            <InputError class="mt-1" :message="form.errors.age" />
                        </div>

                        <div>
                            <InputLabel for="maritial_status" value="Marital Status" />
                            <select id="maritial_status" v-model="form.maritial_status" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm">
                                <option value="single">Single</option>
                                <option value="married">Married</option>
                                <option value="divorced">Divorced</option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.maritial_status" />
                        </div>

                        <div>
                            <InputLabel for="religion" value="Religion" />
                            <select id="religion" v-model="form.religion" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm">
                                <option value="Islam">Islam</option>
                                <option value="Hinduism">Hinduism</option>
                                <option value="Christianity">Christianity</option>
                                <option value="Buddhism">Buddhism</option>
                                <option value="Other">Other</option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.religion" />
                        </div>

                        <div>
                            <InputLabel for="boy" value="Boys" />
                            <TextInput id="boy" type="number" min="0" class="mt-1 block w-full" v-model="form.boy" />
                            <InputError class="mt-1" :message="form.errors.boy" />
                        </div>

                        <div>
                            <InputLabel for="girl" value="Girls" />
                            <TextInput id="girl" type="number" min="0" class="mt-1 block w-full" v-model="form.girl" />
                            <InputError class="mt-1" :message="form.errors.girl" />
                        </div>

                        <div>
                            <InputLabel for="childCount" value="Total Children (Auto-calculated)" />
                            <TextInput id="childCount" type="number" class="mt-1 block w-full bg-gray-50 dark:bg-gray-700/50 cursor-not-allowed font-medium text-gray-700 dark:text-gray-200" v-model="form.childCount" disabled />
                            <InputError class="mt-1" :message="form.errors.childCount" />
                        </div>
                    </div>
                </div>

                <!-- 5. Official Identifications -->
                <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl p-6 border border-gray-100 dark:border-gray-700/60 transition-colors">
                    <div class="flex items-center gap-3 border-b border-gray-100 dark:border-gray-700 pb-4 mb-6">
                        <div class="p-2 bg-emerald-50 dark:bg-emerald-950/50 rounded-lg text-emerald-600 dark:text-emerald-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2" />
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">National Identifications & Documents</h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400">National ID, Birth Registration, and Tax certificate references</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <InputLabel for="nid_no" value="National ID (NID) Number" />
                            <TextInput id="nid_no" type="text" class="mt-1 block w-full" v-model="form.nid_no" placeholder="e.g. 19901234567890" />
                            <InputError class="mt-1" :message="form.errors.nid_no" />
                        </div>

                        <div>
                            <InputLabel for="nid_img" value="NID Scanned Document (URL)" />
                            <TextInput id="nid_img" type="text" class="mt-1 block w-full" v-model="form.nid_img" placeholder="https://storage.institution.edu/docs/nid-10425.jpg" />
                            <InputError class="mt-1" :message="form.errors.nid_img" />
                        </div>

                        <div>
                            <InputLabel for="bid_no" value="Birth Registration (BID) Number" />
                            <TextInput id="bid_no" type="text" class="mt-1 block w-full" v-model="form.bid_no" placeholder="e.g. 19901234567890123" />
                            <InputError class="mt-1" :message="form.errors.bid_no" />
                        </div>

                        <div>
                            <InputLabel for="bidImg" value="Birth Certificate Document (URL)" />
                            <TextInput id="bidImg" type="text" class="mt-1 block w-full" v-model="form.bidImg" placeholder="https://storage.institution.edu/docs/bid-10425.jpg" />
                            <InputError class="mt-1" :message="form.errors.bidImg" />
                        </div>

                        <div>
                            <InputLabel for="tin_no" value="Tax Identification (TIN) Number" />
                            <TextInput id="tin_no" type="text" class="mt-1 block w-full" v-model="form.tin_no" placeholder="e.g. 123456789012" />
                            <InputError class="mt-1" :message="form.errors.tin_no" />
                        </div>

                        <div>
                            <InputLabel for="tin_img" value="TIN Certificate Document (URL)" />
                            <TextInput id="tin_img" type="text" class="mt-1 block w-full" v-model="form.tin_img" placeholder="https://storage.institution.edu/docs/tin-10425.jpg" />
                            <InputError class="mt-1" :message="form.errors.tin_img" />
                        </div>
                    </div>
                </div>

                <!-- 6. Contact, Accommodation & Custom Attributes -->
                <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl p-6 border border-gray-100 dark:border-gray-700/60 transition-colors">
                    <div class="flex items-center gap-3 border-b border-gray-100 dark:border-gray-700 pb-4 mb-6">
                        <div class="p-2 bg-emerald-50 dark:bg-emerald-950/50 rounded-lg text-emerald-600 dark:text-emerald-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Contact, Accommodation & Attributes</h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Communication lines, campus quarters, and institutional attributes</p>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <!-- Phones and Addresses Subgrid -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- Phone Numbers -->
                            <div class="border border-gray-100 dark:border-gray-700/60 rounded-xl p-4 bg-gray-50/50 dark:bg-gray-800/50">
                                <div class="flex justify-between items-center mb-3">
                                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Phone Numbers</h3>
                                    <PrimaryButton type="button" @click="addField('phone_numbers', { phoneNumber: '' })" class="!px-2.5 !py-1 text-xs">
                                        + Add Phone
                                    </PrimaryButton>
                                </div>
                                <div class="space-y-3">
                                    <div v-for="(phone, idx) in form.phone_numbers" :key="idx" class="flex items-center gap-2">
                                        <div class="flex-1">
                                            <TextInput :id="'phone_'+idx" type="text" class="block w-full text-sm" v-model="phone.phoneNumber" placeholder="+8801XXXXXXXXX" />
                                            <InputError class="mt-1" :message="form.errors['phone_numbers.' + idx + '.phoneNumber']" />
                                        </div>
                                        <button v-if="form.phone_numbers.length > 1" type="button" @click="removeField('phone_numbers', idx)" class="p-1.5 text-red-500 hover:text-red-700 transition" title="Remove Phone">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Addresses -->
                            <div class="border border-gray-100 dark:border-gray-700/60 rounded-xl p-4 bg-gray-50/50 dark:bg-gray-800/50">
                                <div class="flex justify-between items-center mb-3">
                                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Addresses</h3>
                                    <PrimaryButton type="button" @click="addField('addresses', { address: '' })" class="!px-2.5 !py-1 text-xs">
                                        + Add Address
                                    </PrimaryButton>
                                </div>
                                <div class="space-y-3">
                                    <div v-for="(addr, idx) in form.addresses" :key="idx" class="flex items-center gap-2">
                                        <div class="flex-1">
                                            <TextInput :id="'addr_'+idx" type="text" class="block w-full text-sm" v-model="addr.address" placeholder="Residential or permanent address" />
                                            <InputError class="mt-1" :message="form.errors['addresses.' + idx + '.address']" />
                                        </div>
                                        <button v-if="form.addresses.length > 1" type="button" @click="removeField('addresses', idx)" class="p-1.5 text-red-500 hover:text-red-700 transition" title="Remove Address">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Campus Accommodation / Dormitory -->
                        <div class="border-t border-gray-100 dark:border-gray-700/80 pt-5">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-3">Campus Dormitory / Residence (Optional)</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <InputLabel for="dorm_name" value="Dormitory / Quarter Name" />
                                    <TextInput id="dorm_name" type="text" class="mt-1 block w-full" v-model="form.dorm_name" placeholder="e.g. Teacher's Quarter B" />
                                </div>
                                <div>
                                    <InputLabel for="dorm_type" value="Residence Type" />
                                    <TextInput id="dorm_type" type="text" class="mt-1 block w-full" v-model="form.dorm_type" placeholder="e.g. Family Flat" />
                                </div>
                                <div>
                                    <InputLabel for="dorm_address" value="Residence Address / Flat No" />
                                    <TextInput id="dorm_address" type="text" class="mt-1 block w-full" v-model="form.dorm_address" placeholder="e.g. Flat 4B, South Wing" />
                                </div>
                            </div>
                        </div>

                        <!-- Custom Employee Attribute -->
                        <div class="border-t border-gray-100 dark:border-gray-700/80 pt-5">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-3">Special Employee Attribute (Optional)</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <InputLabel for="emp_attr_name" value="Attribute Name" />
                                    <TextInput id="emp_attr_name" type="text" class="mt-1 block w-full" v-model="form.emp_attr_name" placeholder="e.g. Freedom Fighter Quota" />
                                </div>
                                <div>
                                    <InputLabel for="emp_attr_description" value="Description" />
                                    <TextInput id="emp_attr_description" type="text" class="mt-1 block w-full" v-model="form.emp_attr_description" placeholder="Institutional designation note" />
                                </div>
                                <div class="flex items-center pt-6">
                                    <label class="flex items-center gap-2 cursor-pointer select-none">
                                        <Checkbox id="emp_attr_action" v-model:checked="form.emp_attr_action" class="text-emerald-600 focus:ring-emerald-500" />
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Attribute Status Active</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 7. Financial & Banking Information -->
                <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl p-6 border border-gray-100 dark:border-gray-700/60 transition-colors">
                    <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 pb-4 mb-6">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-emerald-50 dark:bg-emerald-950/50 rounded-lg text-emerald-600 dark:text-emerald-400">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Bank Accounts & Payroll Disbursement</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Designate bank accounts and mark the primary salary disbursement target</p>
                            </div>
                        </div>
                        <PrimaryButton type="button" @click="addField('bank_accounts', { bank_routing_no: '', bank_account_no: '', bank_type: '', bank_address: '', isSalaryAccount: false })" class="!px-3 !py-1 text-xs">
                            + Add Account
                        </PrimaryButton>
                    </div>

                    <div class="space-y-6">
                        <div v-for="(account, idx) in form.bank_accounts" :key="idx" class="p-4 rounded-xl border border-gray-100 dark:border-gray-700/80 bg-gray-50/40 dark:bg-gray-800/40 space-y-4">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Account #{{ idx + 1 }}</span>
                                <button v-if="form.bank_accounts.length > 1" type="button" @click="removeField('bank_accounts', idx)" class="text-xs text-red-600 hover:text-red-700 font-medium inline-flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    Remove
                                </button>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <div>
                                    <InputLabel :for="'bank_acc_'+idx" value="Account Number" />
                                    <TextInput :id="'bank_acc_'+idx" type="text" class="mt-1 block w-full" v-model="account.bank_account_no" placeholder="e.g. 205012345678" />
                                    <InputError class="mt-1" :message="form.errors['bank_accounts.' + idx + '.bank_account_no']" />
                                </div>
                                <div>
                                    <InputLabel :for="'bank_rout_'+idx" value="Routing Number" />
                                    <TextInput :id="'bank_rout_'+idx" type="text" class="mt-1 block w-full" v-model="account.bank_routing_no" placeholder="e.g. 125272345" />
                                </div>
                                <div>
                                    <InputLabel :for="'bank_type_'+idx" value="Account Type" />
                                    <TextInput :id="'bank_type_'+idx" type="text" class="mt-1 block w-full" v-model="account.bank_type" placeholder="Savings / Current" />
                                </div>
                                <div>
                                    <InputLabel :for="'bank_addr_'+idx" value="Bank / Branch Address" />
                                    <TextInput :id="'bank_addr_'+idx" type="text" class="mt-1 block w-full" v-model="account.bank_address" placeholder="e.g. Sonali Bank, Campus Branch" />
                                </div>
                            </div>
                            <div class="flex items-center pt-1">
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <Checkbox :id="'isSalary_'+idx" v-model:checked="account.isSalaryAccount" class="text-emerald-600 focus:ring-emerald-500" />
                                    <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-400">Designate as Primary Salary Account</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 8. Career History, Promotions & Leaves -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- Promotions (Upgraded with Select Dropdowns) -->
                    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl p-6 border border-gray-100 dark:border-gray-700/60 transition-colors">
                        <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 pb-3 mb-4">
                            <div>
                                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Promotions & Placement History</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Record past promotions and scale advances</p>
                            </div>
                            <PrimaryButton type="button" @click="addField('promotions', { promoted_to: '', pay_scale_id: '', payroll_group_id: '' })" class="!px-3 !py-1 text-xs">
                                + Add Promo
                            </PrimaryButton>
                        </div>

                        <div class="space-y-4">
                            <div v-for="(promo, idx) in form.promotions" :key="idx" class="p-3.5 rounded-xl border border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50 space-y-3">
                                <div class="flex justify-between items-center">
                                    <span class="text-xs font-semibold text-gray-600 dark:text-gray-400">Record #{{ idx + 1 }}</span>
                                    <button v-if="form.promotions.length > 1" type="button" @click="removeField('promotions', idx)" class="text-xs text-red-600 hover:text-red-700 font-medium">
                                        Remove
                                    </button>
                                </div>

                                <div>
                                    <InputLabel :for="'promo_to_'+idx" value="Position / Title Promoted To" />
                                    <TextInput :id="'promo_to_'+idx" type="text" class="mt-1 block w-full text-sm" v-model="promo.promoted_to" placeholder="e.g. Associate Professor" />
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel :for="'promo_scale_'+idx" value="Pay Scale" />
                                        <select :id="'promo_scale_'+idx" v-model="promo.pay_scale_id" @change="onPromoPayScaleChange(promo)" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-xs">
                                            <option value="">Select Scale (Optional)</option>
                                            <option v-for="ps in payScales" :key="ps.id" :value="ps.id">{{ ps.scale_name }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <InputLabel :for="'promo_group_'+idx" value="Payroll Group" />
                                        <select :id="'promo_group_'+idx" v-model="promo.payroll_group_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-xs">
                                            <option value="">Select Group (Optional)</option>
                                            <option v-for="pg in getPromoPayrollGroups(promo)" :key="pg.id" :value="pg.id">
                                                {{ pg.payroll_group_type?.group_name || ('Group #' + pg.id) }}
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Leaves -->
                    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl p-6 border border-gray-100 dark:border-gray-700/60 transition-colors">
                        <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 pb-3 mb-4">
                            <div>
                                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Leave Information</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Current or historical leaves impacting payroll</p>
                            </div>
                            <PrimaryButton type="button" @click="addField('leave_statuses', { onLeave: false, leave_reason: '', isPaid: false, leave_isActive: false, leave_duration: 0 })" class="!px-3 !py-1 text-xs">
                                + Add Leave
                            </PrimaryButton>
                        </div>

                        <div class="space-y-4">
                            <div v-for="(leave, idx) in form.leave_statuses" :key="idx" class="p-3.5 rounded-xl border border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50 space-y-3">
                                <div class="flex justify-between items-center">
                                    <span class="text-xs font-semibold text-gray-600 dark:text-gray-400">Leave #{{ idx + 1 }}</span>
                                    <button v-if="form.leave_statuses.length > 1" type="button" @click="removeField('leave_statuses', idx)" class="text-xs text-red-600 hover:text-red-700 font-medium">
                                        Remove
                                    </button>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <div class="sm:col-span-2">
                                        <InputLabel :for="'leave_reason_'+idx" value="Reason" />
                                        <TextInput :id="'leave_reason_'+idx" type="text" class="mt-1 block w-full text-sm" v-model="leave.leave_reason" placeholder="e.g. Higher Studies (PhD)" />
                                    </div>
                                    <div>
                                        <InputLabel :for="'leave_dur_'+idx" value="Duration (days)" />
                                        <TextInput :id="'leave_dur_'+idx" type="number" min="0" class="mt-1 block w-full text-sm" v-model="leave.leave_duration" />
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-4 pt-1">
                                    <label class="flex items-center gap-2 cursor-pointer text-xs text-gray-700 dark:text-gray-300">
                                        <Checkbox :id="'onLeave_'+idx" v-model:checked="leave.onLeave" class="text-emerald-600 focus:ring-emerald-500" />
                                        Currently On Leave
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer text-xs text-gray-700 dark:text-gray-300">
                                        <Checkbox :id="'isPaid_'+idx" v-model:checked="leave.isPaid" class="text-emerald-600 focus:ring-emerald-500" />
                                        Is Paid Leave
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer text-xs text-gray-700 dark:text-gray-300">
                                        <Checkbox :id="'leave_isActive_'+idx" v-model:checked="leave.leave_isActive" class="text-emerald-600 focus:ring-emerald-500" />
                                        Status Active
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Sticky / Bottom Submit Action Bar -->
                <div class="sticky bottom-4 z-20 bg-white/95 dark:bg-gray-800/95 backdrop-blur-md shadow-xl rounded-2xl p-4 border border-gray-200/80 dark:border-gray-700 flex items-center justify-between transition-colors">
                    <div class="text-xs text-gray-500 dark:text-gray-400 hidden sm:block">
                        Ensure all mandatory fields tagged with <span class="text-red-500 font-bold">*</span> are filled.
                    </div>
                    <div class="flex items-center gap-3 w-full sm:w-auto justify-end">
                        <Link
                            :href="route('employees.edit')"
                            class="px-5 py-2.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-xl font-medium text-sm transition"
                        >
                            Cancel
                        </Link>

                        <PrimaryButton
                            type="submit"
                            :disabled="form.processing"
                            class="!py-2.5 !px-6 text-sm font-semibold rounded-xl bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 inline-flex items-center gap-2 shadow-lg shadow-emerald-600/20"
                        >
                            <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span>{{ form.processing ? 'Saving Employee...' : 'Create Employee' }}</span>
                        </PrimaryButton>
                    </div>
                </div>

            </form>
        </div>
    </AuthenticatedLayout>
</template>
