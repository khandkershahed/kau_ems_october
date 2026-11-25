<script setup>
import { reactive, ref, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    employee: { type: Object, default: null },
    filterOptions: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const saving = ref(false);
const errorMessage = ref('');

const form = reactive({
    isActive: true,
    department_office_id: '',
    user_category_id: '',
    pay_scale_id: '',
    payroll_group_id: '',
    jobStatus: 'permanent',
    holdIncrement: false,
});

const hydrateForm = (emp) => {
    errorMessage.value = '';
    if (!emp) return;
    form.isActive = emp.is_active !== false && emp.is_active !== 0;
    form.department_office_id = emp.department_office_id ? String(emp.department_office_id) : '';
    form.user_category_id = emp.category_id ? String(emp.category_id) : '';
    form.pay_scale_id = emp.pay_scale_id ? String(emp.pay_scale_id) : '';
    form.payroll_group_id = emp.payroll_group_id ? String(emp.payroll_group_id) : '';
    const allowedStatuses = ['permanent', 'contract', 'temporary', 'others'];
    const rawStatus = String(emp.job_status || 'permanent').toLowerCase();
    form.jobStatus = allowedStatuses.includes(rawStatus) ? rawStatus : 'others';
    form.holdIncrement = Boolean(emp.hold_increment);
};

watch(
    () => [props.show, props.employee],
    ([isOpen, emp]) => {
        if (isOpen && emp) {
            hydrateForm(emp);
        }
    },
    { immediate: true },
);

const submit = async () => {
    if (!props.employee?.user_id || saving.value) return;
    saving.value = true;
    errorMessage.value = '';

    const fields = ['isActive', 'jobStatus', 'holdIncrement'];
    const payload = {
        fields,
        isActive: form.isActive,
        jobStatus: form.jobStatus,
        holdIncrement: form.holdIncrement,
    };

    if (form.department_office_id !== '') {
        fields.push('department_office_id');
        payload.department_office_id = Number(form.department_office_id);
    }
    if (form.user_category_id !== '') {
        fields.push('user_category_id');
        payload.user_category_id = Number(form.user_category_id);
    }
    if (form.pay_scale_id !== '') {
        fields.push('pay_scale_id');
        payload.pay_scale_id = Number(form.pay_scale_id);
    }
    if (form.payroll_group_id !== '') {
        fields.push('payroll_group_id');
        payload.payroll_group_id = Number(form.payroll_group_id);
    }

    try {
        const { data } = await axios.patch(
            `/dashboard/employee/${props.employee.user_id}/bulk-profile-update`,
            payload,
            { headers: { Accept: 'application/json' } },
        );
        emit('saved', data);
        emit('close');
    } catch (err) {
        const errData = err.response?.data;
        errorMessage.value =
            errData?.message ||
            Object.values(errData?.errors || {})
                .flat()
                .join(' ') ||
            err.message ||
            'Failed to update employee profile.';
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <Modal :show="show" max-width="xl" @close="emit('close')">
        <div class="p-5 sm:p-6">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 pb-3.5">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
                        Employee Profile Quick Edit
                    </div>
                    <h2 class="mt-0.5 text-lg font-bold text-slate-900">
                        {{ employee?.name || 'Employee' }}
                        <span
                            v-if="employee?.employee_code"
                            class="ml-1.5 rounded bg-slate-100 px-2 py-0.5 font-mono text-xs font-semibold text-slate-700"
                        >
                            {{ employee.employee_code }}
                        </span>
                    </h2>
                    <p v-if="employee?.email" class="text-xs text-slate-500">{{ employee.email }}</p>
                </div>
                <button
                    type="button"
                    @click="emit('close')"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    aria-label="Close modal"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div
                v-if="errorMessage"
                class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-xs font-medium text-rose-800"
            >
                {{ errorMessage }}
            </div>

            <form @submit.prevent="submit" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">Department / Office</label>
                        <select
                            v-model="form.department_office_id"
                            class="w-full rounded-lg border-slate-300 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">Keep Current</option>
                            <option
                                v-for="dept in filterOptions?.departments || []"
                                :key="dept.id"
                                :value="String(dept.id)"
                            >
                                {{ dept.name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">Category / Class</label>
                        <select
                            v-model="form.user_category_id"
                            class="w-full rounded-lg border-slate-300 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">Keep Current</option>
                            <option
                                v-for="cat in filterOptions?.categories || []"
                                :key="cat.id"
                                :value="String(cat.id)"
                            >
                                {{ cat.name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">Designation / Payroll Group</label>
                        <select
                            v-model="form.payroll_group_id"
                            class="w-full rounded-lg border-slate-300 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">Keep Current</option>
                            <option
                                v-for="pg in filterOptions?.payroll_groups || []"
                                :key="pg.id"
                                :value="String(pg.id)"
                            >
                                {{ pg.label }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">Pay Scale</label>
                        <select
                            v-model="form.pay_scale_id"
                            class="w-full rounded-lg border-slate-300 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">Keep Current</option>
                            <option
                                v-for="ps in filterOptions?.pay_scales || []"
                                :key="ps.id"
                                :value="String(ps.id)"
                            >
                                {{ ps.scale_name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">Job Status</label>
                        <select
                            v-model="form.jobStatus"
                            class="w-full rounded-lg border-slate-300 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="permanent">Permanent</option>
                            <option value="contract">Contractual</option>
                            <option value="temporary">Temporary</option>
                            <option value="others">Others</option>
                        </select>
                    </div>

                    <div class="flex flex-col justify-end gap-2 pb-1">
                        <label class="inline-flex items-center gap-2 text-xs font-medium text-slate-700">
                            <input
                                v-model="form.isActive"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            Active Account (Payroll Eligible)
                        </label>
                        <label class="inline-flex items-center gap-2 text-xs font-medium text-slate-700">
                            <input
                                v-model="form.holdIncrement"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500"
                            />
                            Hold Increment
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-slate-200 pt-4">
                    <SecondaryButton type="button" @click="emit('close')">
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton type="submit" :disabled="saving">
                        {{ saving ? 'Saving...' : 'Save Profile Changes' }}
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
