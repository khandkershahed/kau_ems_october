<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head, usePage } from '@inertiajs/vue3';

const props = defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    profileUser: {
        type: Object,
        required: true
    }
});

const user = usePage().props.auth.user;

const displayValue = (val) => {
    return (val !== null && val !== undefined && val !== '') ? val : 'No information';
};

const displayBool = (val) => {
    if (val === null || val === undefined || val === '') return 'No information';
    return Boolean(val) ? 'Yes' : 'No';
};
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Profile
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                
                <!-- Profile Overview Section -->
                <div class="bg-white shadow sm:rounded-lg dark:bg-gray-800 overflow-hidden">
                    <div class="p-6 sm:p-8">
                        <div class="flex flex-col md:flex-row items-start md:items-center gap-6">
                            <!-- Profile Image -->
                            <div class="flex-shrink-0">
                                <div class="h-24 w-24 rounded-full bg-green-100 dark:bg-gray-700 border-4 border-green-50 dark:border-gray-600 flex items-center justify-center overflow-hidden">
                                    <svg class="h-12 w-12 text-green-500 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                </div>
                            </div>

                            <!-- Basic Info -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-3">
                                    <h3 class="text-2xl font-bold text-gray-900 dark:text-white truncate">
                                        {{ displayValue(profileUser.name) }}
                                    </h3>
                                    <span v-if="profileUser.roles && profileUser.roles.length" class="inline-flex items-center rounded-md bg-green-50 dark:bg-green-400/10 px-2 py-1 text-xs font-medium text-green-700 dark:text-green-400 ring-1 ring-inset ring-green-600/20 dark:ring-green-400/20 capitalize">
                                        {{ profileUser.roles.map(r => r.role_name).join(', ') }}
                                    </span>
                                </div>
                                <p class="text-sm font-medium text-green-600 dark:text-green-400 mt-1">
                                    {{ displayValue(profileUser.email) }}
                                </p>
                            </div>
                        </div>

                        <!-- All User Properties Grid -->
                        <div class="mt-8 border-t border-gray-200 dark:border-gray-700 pt-6">
                            
                            <!-- Personal Info -->
                            <h4 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">Personal Information</h4>
                            <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-4 mb-8">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Gender</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200 capitalize">{{ displayValue(profileUser.gender) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Date of Birth</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.bid?.date_of_birth) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Age</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.age?.age) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Religion</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.religion?.religion) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Marital Status</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200 capitalize">{{ displayValue(profileUser.maritial_status?.status) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Child Count</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.child_count?.childCount) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Boys</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.child_count?.boy) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Girls</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.child_count?.girl) }}</dd>
                                </div>
                            </dl>

                            <!-- Identifications -->
                            <h4 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">Identifications</h4>
                            <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-3 mb-8">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">NID Number</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.nid?.nid_no) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">BID Number</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.bid?.bid_no) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">TIN Number</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.tin?.tin_no) }}</dd>
                                </div>
                            </dl>

                            <!-- Employment & Accommodation -->
                            <h4 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">Employment & Accommodation</h4>
                            <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-3 mb-8">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Joining Date</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.joining_date?.joining_date) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Active Account</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayBool(profileUser.isActive) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Promoted To</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.promotions?.[0]?.promoted_to) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Pay Scale ID</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.promotions?.[0]?.pay_scale_id) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Payroll Group ID</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.promotions?.[0]?.payroll_group_id) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Dormitory Name</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.dormitory?.dorm_name) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Dormitory Type</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.dormitory?.dorm_type) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Dormitory Address</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.dormitory?.dorm_address) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Attribute Name</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.employee_attribute?.name) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Attribute Action</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayBool(profileUser.employee_attribute?.action) }}</dd>
                                </div>
                            </dl>

                            <!-- Financial Information -->
                            <h4 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">Financial Information</h4>
                            <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-3 mb-8">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Bank Account No.</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.bank_accounts?.[0]?.account_no) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Routing Number</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.bank_accounts?.[0]?.routing_no) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Account Type</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.bank_accounts?.[0]?.type) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Bank Address</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.bank_accounts?.[0]?.address) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Salary Account</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayBool(profileUser.bank_accounts?.[0]?.isSalaryAccount) }}</dd>
                                </div>
                            </dl>

                            <!-- Leave Information -->
                            <h4 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">Leave Information</h4>
                            <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-3 mb-8">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Leave Reason</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.leave_statuses?.[0]?.reason) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Leave Duration (days)</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayValue(profileUser.leave_statuses?.[0]?.duration) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Currently on Leave</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayBool(profileUser.leave_statuses?.[0]?.onLeave) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Paid Leave</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayBool(profileUser.leave_statuses?.[0]?.isPaid) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Leave Active</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200">{{ displayBool(profileUser.leave_statuses?.[0]?.isActive) }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>

                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8 dark:bg-gray-800"
                >
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8 dark:bg-gray-800"
                >
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8 dark:bg-gray-800"
                >
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
