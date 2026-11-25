<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Swal from 'sweetalert2';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    userId: {
        type: [Number, String],
        default: null,
    },
    initialYear: {
        type: Number,
        default: 2026,
    },
    initialMonth: {
        type: Number,
        default: 11,
    },
});

const emit = defineEmits(['close', 'saved']);

const months = [
    { value: 1, label: 'January' },
    { value: 2, label: 'February' },
    { value: 3, label: 'March' },
    { value: 4, label: 'April' },
    { value: 5, label: 'May' },
    { value: 6, label: 'June' },
    { value: 7, label: 'July' },
    { value: 8, label: 'August' },
    { value: 9, label: 'September' },
    { value: 10, label: 'October' },
    { value: 11, label: 'November' },
    { value: 12, label: 'December' },
];

const selectedYear = ref(Number(props.initialYear || 2026));
const selectedMonth = ref(Number(props.initialMonth || 11));

const years = computed(() => {
    const base = Math.max(2026, Number(selectedYear.value) || 2026);
    const list = [];
    for (let y = 2024; y <= base + 2; y++) {
        list.push(y);
    }
    return list;
});

const loading = ref(false);
const savingMonth = ref(false);
const savingRecurring = ref(false);
const errorMessage = ref('');
const successMessage = ref('');

const isLocked = ref(false);
const employee = ref(null);
const allowances = ref([]);
const deductions = ref([]);

const isBasicPayName = (name) => /\bbasic(?:\s+pay|\s+salary)?\b/i.test(String(name || ''));

const toNumber = (val) => {
    const n = Number(val);
    return Number.isFinite(n) ? n : 0;
};

const toCents = (val) => Math.round(toNumber(val) * 100);

const formatMoney = (val) => {
    const num = toNumber(val);
    return num.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const hydrateRows = (rows = []) =>
    rows.map((r) => ({
        payroll_component_type_id: Number(r.payroll_component_type_id),
        name: String(r.name || ''),
        direction: String(r.direction || 'allowance'),
        auto_current_amount: toNumber(r.current_amount),
        current_amount: toNumber(r.current_amount),
        arrear_amount: toNumber(r.arrear_amount),
        rule_override: Boolean(r.rule_override),
        comment: r.comment ? String(r.comment) : '',
    }));

const applyPayload = (data) => {
    isLocked.value = Boolean(data?.is_locked);
    employee.value = data?.employee || null;
    allowances.value = hydrateRows(data?.allowances || []);
    deductions.value = hydrateRows(data?.deductions || []);
};

const loadSetup = async () => {
    if (!props.userId) return;
    loading.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const { data } = await axios.get(`/salary-setup/${props.userId}`, {
            params: {
                year: selectedYear.value,
                month: selectedMonth.value,
            },
            headers: { Accept: 'application/json' },
        });
        applyPayload(data);
    } catch (err) {
        errorMessage.value =
            err.response?.data?.message ||
            err.message ||
            'Failed to load monthly salary setup.';
    } finally {
        loading.value = false;
    }
};

watch(
    () => [props.show, props.userId],
    ([isOpen, uid]) => {
        if (isOpen && uid) {
            selectedYear.value = Number(props.initialYear || 2026);
            selectedMonth.value = Number(props.initialMonth || 11);
            loadSetup();
        } else if (!isOpen) {
            errorMessage.value = '';
            successMessage.value = '';
        }
    },
    { immediate: true },
);

const onPeriodChange = () => {
    if (props.show && props.userId) {
        loadSetup();
    }
};

const toggleRuleOverride = (row) => {
    if (!row.rule_override) {
        row.current_amount = row.auto_current_amount;
    }
};

const rowTotal = (row) => (toCents(row.current_amount) + toCents(row.arrear_amount)) / 100;

const totals = computed(() => {
    let regAllowCents = 0;
    let arrAllowCents = 0;
    for (const r of allowances.value) {
        regAllowCents += toCents(r.current_amount);
        arrAllowCents += toCents(r.arrear_amount);
    }

    let regDedCents = 0;
    let arrDedCents = 0;
    for (const r of deductions.value) {
        regDedCents += toCents(r.current_amount);
        arrDedCents += toCents(r.arrear_amount);
    }

    const totalAllowCents = regAllowCents + arrAllowCents;
    const totalDedCents = regDedCents + arrDedCents;
    const regularNetCents = regAllowCents - regDedCents;
    const netArrearCents = arrAllowCents - arrDedCents;
    const netPayableCents = totalAllowCents - totalDedCents;

    return {
        regularAllowances: regAllowCents / 100,
        arrearAllowances: arrAllowCents / 100,
        totalAllowances: totalAllowCents / 100,
        regularDeductions: regDedCents / 100,
        arrearDeductions: arrDedCents / 100,
        totalDeductions: totalDedCents / 100,
        regularNet: regularNetCents / 100,
        netArrear: netArrearCents / 100,
        netPayable: netPayableCents / 100,
    };
});

const basicPayMissingComment = computed(() =>
    allowances.value.some(
        (r) =>
            isBasicPayName(r.name) &&
            r.rule_override &&
            String(r.comment || '').trim() === '',
    ),
);

const periodLabel = computed(() => {
    const m = months.find((x) => x.value === Number(selectedMonth.value));
    return `${m ? m.label : selectedMonth.value} ${selectedYear.value}`;
});

const handleSaveMonth = async () => {
    if (isLocked.value || savingMonth.value || !props.userId) return;
    errorMessage.value = '';
    successMessage.value = '';

    if (basicPayMissingComment.value) {
        errorMessage.value =
            'A non-empty comment is required when manually overriding Basic Pay.';
        return;
    }

    const components = [...allowances.value, ...deductions.value].map((r) => ({
        payroll_component_type_id: r.payroll_component_type_id,
        arrear_amount: toNumber(r.arrear_amount),
        rule_override: Boolean(r.rule_override),
        override_current_amount: r.rule_override ? toNumber(r.current_amount) : null,
        comment: String(r.comment || '').trim() || null,
    }));

    savingMonth.value = true;
    try {
        const { data } = await axios.post(
            `/salary-setup/${props.userId}/month`,
            {
                year: Number(selectedYear.value),
                month: Number(selectedMonth.value),
                components,
            },
            { headers: { Accept: 'application/json' } },
        );
        applyPayload(data);
        successMessage.value =
            data?.message || `Saved monthly salary setup for ${periodLabel.value}.`;
        emit('saved', {
            mode: 'month',
            userId: props.userId,
            year: Number(selectedYear.value),
            month: Number(selectedMonth.value),
            setup: data,
        });
    } catch (err) {
        errorMessage.value =
            err.response?.data?.message ||
            Object.values(err.response?.data?.errors || {})
                .flat()
                .join(' ') ||
            err.message ||
            'Failed to save monthly salary setup.';
    } finally {
        savingMonth.value = false;
    }
};

const handleSaveRecurring = async () => {
    if (isLocked.value || savingRecurring.value || !props.userId) return;

    const confirm = await Swal.fire({
        title: 'Save as Recurring Default?',
        html:
            '<p class="text-sm">This updates the employee\'s permanent recurring salary profile using the <strong>Current</strong> amounts only.</p>' +
            '<p class="mt-2 text-xs text-amber-700">Monthly <strong>Arrear</strong> amounts and <strong>Rule Override</strong> flags are never copied to recurring defaults.</p>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Save Recurring Default',
        confirmButtonColor: '#4f46e5',
    });

    if (!confirm.isConfirmed) return;

    errorMessage.value = '';
    successMessage.value = '';

    const components = [...allowances.value, ...deductions.value].map((r) => ({
        payroll_component_type_id: r.payroll_component_type_id,
        calcType: 'fixed',
        amount: Math.max(0, toNumber(r.current_amount)),
    }));

    savingRecurring.value = true;
    try {
        const { data } = await axios.post(
            `/salary-setup/${props.userId}/recurring`,
            {
                year: Number(selectedYear.value),
                month: Number(selectedMonth.value),
                components,
            },
            { headers: { Accept: 'application/json' } },
        );
        applyPayload(data);
        successMessage.value =
            data?.message || 'Saved recurring profile defaults.';
        emit('saved', {
            mode: 'recurring',
            userId: props.userId,
            year: Number(selectedYear.value),
            month: Number(selectedMonth.value),
            setup: data,
        });
    } catch (err) {
        errorMessage.value =
            err.response?.data?.message ||
            Object.values(err.response?.data?.errors || {})
                .flat()
                .join(' ') ||
            err.message ||
            'Failed to save recurring defaults.';
    } finally {
        savingRecurring.value = false;
    }
};

const closeModal = () => {
    emit('close');
};
</script>

<template>
    <Modal :show="show" max-width="6xl" @close="closeModal">
        <div class="flex max-h-[90vh] flex-col bg-white text-gray-800 dark:bg-gray-800 dark:text-gray-100">
            <!-- Header -->
            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                v-if="employee?.code"
                                class="inline-flex items-center rounded-md bg-indigo-100 px-2 py-0.5 font-mono text-xs font-semibold text-indigo-800 dark:bg-indigo-900/60 dark:text-indigo-200"
                            >
                                {{ employee.code }}
                            </span>
                            <h2 class="text-lg font-bold text-gray-900 dark:text-white">
                                {{ employee?.name || 'Monthly Salary Setup' }}
                            </h2>
                            <span
                                v-if="isLocked"
                                class="inline-flex items-center gap-1 rounded-md border border-rose-300 bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800 dark:border-rose-800 dark:bg-rose-950/70 dark:text-rose-200"
                            >
                                Locked Historical Month
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                            <span v-if="employee?.designation" class="font-medium">
                                {{ employee.designation }}
                            </span>
                            <span v-if="employee?.designation && employee?.department" class="text-gray-400">·</span>
                            <span v-if="employee?.department">{{ employee.department }}</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5 pt-0.5">
                            <span
                                v-if="employee?.pay_scale"
                                :class="[
                                    'inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold',
                                    employee.pay_scale_year === '2026'
                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
                                        : 'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-200',
                                ]"
                            >
                                {{ employee.pay_scale }}
                            </span>
                            <span
                                v-if="employee?.grade"
                                class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200"
                            >
                                {{ employee.grade }}
                            </span>
                            <span
                                v-if="employee?.badges?.on_leave"
                                class="inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-900/60 dark:text-amber-200"
                            >
                                On Leave
                            </span>
                            <span
                                v-if="employee?.badges?.ad_hoc"
                                class="inline-flex items-center rounded-md bg-purple-100 px-2 py-0.5 text-[11px] font-semibold text-purple-800 dark:bg-purple-900/60 dark:text-purple-200"
                            >
                                Ad-Hoc
                            </span>
                            <span
                                v-if="employee?.badges?.increment_hold"
                                class="inline-flex items-center rounded-md bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-800 dark:bg-rose-900/60 dark:text-rose-200"
                            >
                                Increment Hold
                            </span>
                        </div>
                    </div>

                    <!-- Period Selector & Close -->
                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1.5 dark:border-gray-700 dark:bg-gray-900">
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Period:</label>
                            <select
                                v-model.number="selectedMonth"
                                @change="onPeriodChange"
                                :disabled="loading"
                                class="rounded border-gray-300 bg-white py-1 pl-2 pr-7 text-xs font-medium text-gray-800 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                aria-label="Salary Month"
                            >
                                <option v-for="m in months" :key="m.value" :value="m.value">
                                    {{ m.label }}
                                </option>
                            </select>
                            <select
                                v-model.number="selectedYear"
                                @change="onPeriodChange"
                                :disabled="loading"
                                class="rounded border-gray-300 bg-white py-1 pl-2 pr-7 text-xs font-medium text-gray-800 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                aria-label="Salary Year"
                            >
                                <option v-for="y in years" :key="y" :value="y">
                                    {{ y }}
                                </option>
                            </select>
                        </div>

                        <button
                            type="button"
                            @click="closeModal"
                            class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                            aria-label="Close modal"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Locked Historical Month Banner -->
                <div
                    v-if="isLocked"
                    class="mt-3 flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-2 text-xs font-medium text-rose-800 dark:border-rose-800 dark:bg-rose-950/50 dark:text-rose-200"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <span>
                        {{ periodLabel }} is a locked historical payroll month (finalized through October 2026). All fields are strictly read-only. Switch to November 2026 or later to enter arrears or overrides.
                    </span>
                </div>

                <!-- Feedback banners -->
                <div
                    v-if="errorMessage"
                    class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-2 text-xs font-medium text-rose-700 dark:border-rose-800 dark:bg-rose-950/50 dark:text-rose-200"
                >
                    {{ errorMessage }}
                </div>
                <div
                    v-if="successMessage"
                    class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2 text-xs font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200"
                >
                    {{ successMessage }}
                </div>
            </div>

            <!-- Body: 19 Components (Allowances 14 + Deductions 5) -->
            <div class="flex-1 overflow-y-auto px-6 py-4 space-y-5">
                <div v-if="loading" class="flex items-center justify-center py-12 text-sm text-gray-500">
                    <svg class="mr-2 h-5 w-5 animate-spin text-indigo-600" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                    </svg>
                    Loading {{ periodLabel }} salary setup...
                </div>

                <template v-else>
                    <!-- Allowances Table (14) -->
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                                Allowances ({{ allowances.length }})
                            </h3>
                            <span class="font-mono text-xs text-gray-600 dark:text-gray-300">
                                Regular: <strong>{{ formatMoney(totals.regularAllowances) }}</strong>
                                · Arrear: <strong>{{ formatMoney(totals.arrearAllowances) }}</strong>
                                · Total: <strong class="text-emerald-700 dark:text-emerald-400">{{ formatMoney(totals.totalAllowances) }}</strong>
                            </span>
                        </div>
                        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                            <table class="w-full min-w-[760px] text-left text-xs">
                                <thead class="bg-gray-50 text-[11px] uppercase tracking-wider text-gray-600 dark:bg-gray-900/70 dark:text-gray-300">
                                    <tr>
                                        <th class="px-3 py-2">Component</th>
                                        <th class="px-3 py-2 text-right w-32">Current</th>
                                        <th class="px-3 py-2 text-right w-32">Arrear</th>
                                        <th class="px-3 py-2 text-right w-32">Total</th>
                                        <th class="px-3 py-2 text-center w-24">Rule Over</th>
                                        <th class="px-3 py-2">Comments</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/70">
                                    <tr
                                        v-for="row in allowances"
                                        :key="row.payroll_component_type_id"
                                        class="hover:bg-gray-50/70 dark:hover:bg-gray-700/30"
                                    >
                                        <td class="px-3 py-1.5 font-medium text-gray-900 dark:text-gray-100">
                                            {{ row.name }}
                                        </td>
                                        <td class="px-3 py-1.5 text-right">
                                            <input
                                                v-model.number="row.current_amount"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                :disabled="isLocked || !row.rule_override"
                                                class="w-28 rounded border px-2 py-1 text-right font-mono text-xs focus:border-indigo-500 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-600 dark:border-gray-600 dark:bg-gray-900 dark:disabled:bg-gray-800 dark:disabled:text-gray-400"
                                            />
                                        </td>
                                        <td class="px-3 py-1.5 text-right">
                                            <input
                                                v-model.number="row.arrear_amount"
                                                type="number"
                                                step="0.01"
                                                :disabled="isLocked"
                                                class="w-28 rounded border border-gray-300 px-2 py-1 text-right font-mono text-xs focus:border-indigo-500 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-100 dark:border-gray-600 dark:bg-gray-900 dark:disabled:bg-gray-800"
                                            />
                                        </td>
                                        <td class="px-3 py-1.5 text-right font-mono font-semibold text-gray-900 dark:text-gray-100">
                                            {{ formatMoney(rowTotal(row)) }}
                                        </td>
                                        <td class="px-3 py-1.5 text-center">
                                            <input
                                                v-model="row.rule_override"
                                                type="checkbox"
                                                :disabled="isLocked"
                                                @change="toggleRuleOverride(row)"
                                                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                                            />
                                        </td>
                                        <td class="px-3 py-1.5">
                                            <input
                                                v-model="row.comment"
                                                type="text"
                                                :disabled="isLocked"
                                                :placeholder="
                                                    isBasicPayName(row.name) && row.rule_override
                                                        ? 'Required: reason for Basic Pay override'
                                                        : 'Optional note...'
                                                "
                                                :class="[
                                                    'w-full rounded border px-2 py-1 text-xs focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-100 dark:bg-gray-900 dark:disabled:bg-gray-800',
                                                    isBasicPayName(row.name) && row.rule_override && !String(row.comment || '').trim()
                                                        ? 'border-rose-500 bg-rose-50/50 placeholder-rose-500 focus:border-rose-500 dark:border-rose-500 dark:bg-rose-950/30'
                                                        : 'border-gray-300 focus:border-indigo-500 dark:border-gray-600',
                                                ]"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Deductions Table (5) -->
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-rose-700 dark:text-rose-400">
                                Deductions ({{ deductions.length }})
                                <span class="ml-1 font-normal normal-case text-gray-500 dark:text-gray-400">
                                    (Negative arrear = refund)
                                </span>
                            </h3>
                            <span class="font-mono text-xs text-gray-600 dark:text-gray-300">
                                Regular: <strong>{{ formatMoney(totals.regularDeductions) }}</strong>
                                · Arrear: <strong>{{ formatMoney(totals.arrearDeductions) }}</strong>
                                · Total: <strong class="text-rose-700 dark:text-rose-400">{{ formatMoney(totals.totalDeductions) }}</strong>
                            </span>
                        </div>
                        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                            <table class="w-full min-w-[760px] text-left text-xs">
                                <thead class="bg-gray-50 text-[11px] uppercase tracking-wider text-gray-600 dark:bg-gray-900/70 dark:text-gray-300">
                                    <tr>
                                        <th class="px-3 py-2">Component</th>
                                        <th class="px-3 py-2 text-right w-32">Current</th>
                                        <th class="px-3 py-2 text-right w-32">Arrear</th>
                                        <th class="px-3 py-2 text-right w-32">Total</th>
                                        <th class="px-3 py-2 text-center w-24">Rule Over</th>
                                        <th class="px-3 py-2">Comments</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/70">
                                    <tr
                                        v-for="row in deductions"
                                        :key="row.payroll_component_type_id"
                                        class="hover:bg-gray-50/70 dark:hover:bg-gray-700/30"
                                    >
                                        <td class="px-3 py-1.5 font-medium text-gray-900 dark:text-gray-100">
                                            {{ row.name }}
                                        </td>
                                        <td class="px-3 py-1.5 text-right">
                                            <input
                                                v-model.number="row.current_amount"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                :disabled="isLocked || !row.rule_override"
                                                class="w-28 rounded border px-2 py-1 text-right font-mono text-xs focus:border-indigo-500 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-600 dark:border-gray-600 dark:bg-gray-900 dark:disabled:bg-gray-800 dark:disabled:text-gray-400"
                                            />
                                        </td>
                                        <td class="px-3 py-1.5 text-right">
                                            <input
                                                v-model.number="row.arrear_amount"
                                                type="number"
                                                step="0.01"
                                                :disabled="isLocked"
                                                class="w-28 rounded border border-gray-300 px-2 py-1 text-right font-mono text-xs focus:border-indigo-500 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-100 dark:border-gray-600 dark:bg-gray-900 dark:disabled:bg-gray-800"
                                            />
                                        </td>
                                        <td class="px-3 py-1.5 text-right font-mono font-semibold text-gray-900 dark:text-gray-100">
                                            {{ formatMoney(rowTotal(row)) }}
                                        </td>
                                        <td class="px-3 py-1.5 text-center">
                                            <input
                                                v-model="row.rule_override"
                                                type="checkbox"
                                                :disabled="isLocked"
                                                @change="toggleRuleOverride(row)"
                                                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                                            />
                                        </td>
                                        <td class="px-3 py-1.5">
                                            <input
                                                v-model="row.comment"
                                                type="text"
                                                :disabled="isLocked"
                                                placeholder="Optional note (e.g. GPF refund)..."
                                                class="w-full rounded border border-gray-300 px-2 py-1 text-xs focus:border-indigo-500 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-100 dark:border-gray-600 dark:bg-gray-900 dark:disabled:bg-gray-800"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Live Footer Totals & Actions -->
            <div class="border-t border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-900/80">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <!-- Live Summary Metrics -->
                    <div class="flex flex-wrap items-center gap-6">
                        <div>
                            <div class="text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                Regular Net
                            </div>
                            <div class="font-mono text-base font-bold text-gray-900 dark:text-gray-100">
                                ৳{{ formatMoney(totals.regularNet) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                Net Arrear
                            </div>
                            <div
                                :class="[
                                    'font-mono text-base font-bold',
                                    totals.netArrear > 0
                                        ? 'text-indigo-700 dark:text-indigo-400'
                                        : totals.netArrear < 0
                                            ? 'text-rose-700 dark:text-rose-400'
                                            : 'text-gray-700 dark:text-gray-300',
                                ]"
                            >
                                ৳{{ formatMoney(totals.netArrear) }}
                            </div>
                        </div>
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 dark:border-emerald-800 dark:bg-emerald-950/50">
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">
                                Net Payable (BDT)
                            </div>
                            <div class="font-mono text-lg font-extrabold text-emerald-800 dark:text-emerald-200">
                                ৳{{ formatMoney(totals.netPayable) }}
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex flex-wrap items-center gap-2">
                        <SecondaryButton type="button" @click="closeModal">
                            Cancel
                        </SecondaryButton>

                        <button
                            type="button"
                            :disabled="isLocked || loading || savingMonth || savingRecurring"
                            @click="handleSaveRecurring"
                            class="inline-flex items-center rounded-md border border-indigo-300 bg-indigo-50 px-3.5 py-2 text-xs font-semibold uppercase tracking-widest text-indigo-700 shadow-sm transition hover:bg-indigo-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40 dark:border-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-200 dark:hover:bg-indigo-900/60"
                        >
                            {{ savingRecurring ? 'Saving...' : 'Save as Recurring Default' }}
                        </button>

                        <PrimaryButton
                            type="button"
                            :disabled="isLocked || loading || savingMonth || savingRecurring"
                            @click="handleSaveMonth"
                        >
                            {{ savingMonth ? 'Saving...' : 'Save This Month' }}
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>
