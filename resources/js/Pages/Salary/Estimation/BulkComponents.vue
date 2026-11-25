<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SalarySetupModal from '@/Components/SalarySetupModal.vue';
import axios from 'axios';

const props = defineProps({
    employees: { type: Array, default: () => [] },
    year: { type: Number, default: 2026 },
    month: { type: Number, default: 11 },
    isLocked: { type: Boolean, default: false },
    codes: { type: String, default: '' },
    totalMatched: { type: Number, default: 0 },
    userCategories: { type: Array, default: () => [] },
    departmentOffices: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ search: '', user_category_id: '', department_office_id: '', limit: 50 }),
    },
});

const rows = ref(
    props.employees.map((e) => ({
        ...e,
        selected: false,
        components: (e.components || []).map((c) => ({
            ...c,
            selected: false,
            result: '',
            original: JSON.stringify({
                mode: c.mode,
                calc_type: c.calc_type,
                value: c.value,
                basis: c.basis,
                legacy_basic_reference: c.legacy_basic_reference,
            }),
        })),
    })),
);

const query = ref(props.filters?.search || '');
const selectedCategory = ref(props.filters?.user_category_id || '');
const selectedDepartment = ref(props.filters?.department_office_id || '');
const statusFilter = ref('all');
const current = ref(rows.value.find((e) => e.editable)?.code || rows.value[0]?.code);
const showOnlyChanges = ref(false);
const tab = ref('all');

const commonTypeId = ref('');
const commonMode = ref('manual');
const commonCalcType = ref('fixed');
const commonValue = ref(0);
const commonBasis = ref('payable_basic');

const auditReason = ref('');
const approvalReason = ref('');
const codes = ref(props.codes);
const message = ref('');
const busy = ref(false);
const results = ref([]);
const run = ref(null);

// Monthly Salary Setup Modal state
const monthlySetupOpen = ref(false);
const monthlySetupUserId = ref(null);

const openMonthlySetup = (emp) => {
    if (!emp?.user_id) return;
    monthlySetupUserId.value = emp.user_id;
    monthlySetupOpen.value = true;
};

const counts = computed(() => ({
    all: rows.value.length,
    editable: rows.value.filter((x) => x.editable).length,
    blocked: rows.value.filter((x) => x.status === 'BLOCKED').length,
    calculated: rows.value.filter((x) => x.status === 'CALCULATED').length,
    issues: rows.value.filter((x) => !!x.error).length,
}));

const filtered = computed(() =>
    rows.value.filter((e) => {
        const q = query.value.trim().toLowerCase();
        const matchesQuery =
            !q ||
            String(e.code).toLowerCase().includes(q) ||
            (e.name || '').toLowerCase().includes(q);
        const matchesStatus =
            statusFilter.value === 'all' ||
            (statusFilter.value === 'issues'
                ? !!e.error
                : statusFilter.value === 'editable'
                    ? e.editable
                    : e.status === statusFilter.value.toUpperCase());
        return matchesQuery && matchesStatus;
    }),
);

const active = computed(() => rows.value.find((e) => e.code === current.value));
const components = computed(() =>
    (active.value?.components || []).filter(
        (c) => (tab.value === 'all' || c.direction === tab.value) && (!showOnlyChanges.value || c.selected),
    ),
);
const chosen = computed(() => rows.value.filter((e) => e.selected));
const edits = computed(() =>
    rows.value.flatMap((e) =>
        e.selected && e.editable ? e.components.filter((c) => c.selected).map((c) => ({ e, c })) : [],
    ),
);

const availableCommonComponents = computed(() => {
    const map = new Map();
    rows.value
        .filter((e) => e.selected && e.editable)
        .forEach((e) =>
            e.components.forEach((c) =>
                map.set(`${c.direction}:${c.type_id}`, {
                    key: `${c.direction}:${c.type_id}`,
                    label: `${c.title} (${c.direction})`,
                }),
            ),
        );
    return [...map.values()].sort((a, b) => a.label.localeCompare(b.label));
});

function applyServerFilters() {
    router.get('/dashboard/salary/estimation/bulk-components', {
        year: props.year,
        month: props.month,
        search: query.value.trim() || undefined,
        user_category_id: selectedCategory.value || undefined,
        department_office_id: selectedDepartment.value || undefined,
        codes: codes.value.trim() || undefined,
    });
}

function resetServerFilters() {
    query.value = '';
    selectedCategory.value = '';
    selectedDepartment.value = '';
    codes.value = '';
    router.get('/dashboard/salary/estimation/bulk-components', {
        year: props.year,
        month: props.month,
    });
}

function stageCommon() {
    const [direction, id] = String(commonTypeId.value).split(':');
    if (!direction || !id) {
        message.value = 'Select an existing component first.';
        return;
    }
    if (
        commonMode.value === 'manual' &&
        commonCalcType.value === 'percentage' &&
        commonBasis.value === 'legacy_basic'
    ) {
        message.value =
            'Legacy Basic requires a verified per-employee reference. Edit these rows individually.';
        return;
    }
    if (
        commonMode.value === 'manual' &&
        (!Number.isFinite(Number(commonValue.value)) || Number(commonValue.value) < 0)
    ) {
        message.value = 'Enter a valid nonnegative rate/value.';
        return;
    }
    let staged = 0;
    let skipped = 0;
    rows.value
        .filter((e) => e.selected)
        .forEach((e) => {
            const c = e.components.find(
                (c) => c.direction === direction && Number(c.type_id) === Number(id),
            );
            if (!e.editable || !c) {
                skipped++;
                return;
            }
            c.selected = true;
            c.mode = commonMode.value;
            c.calc_type = commonCalcType.value;
            c.value = Number(commonValue.value);
            c.basis = commonCalcType.value === 'percentage' ? commonBasis.value : 'none';
            c.legacy_basic_reference = null;
            staged++;
        });
    message.value = `Staged ${staged} employee changes for REVIEW. ${skipped} skipped. No database writes yet. Verify rates/basis and enter a reason before Save.`;
}

function selectAllEditable() {
    rows.value.forEach((e) => {
        e.selected = e.editable;
    });
}

function selectFilteredActive() {
    const visibleCodes = new Set(filtered.value.map((e) => e.code));
    rows.value.forEach((e) => {
        e.selected = !!e.user_id && e.status !== 'GENERATED' && visibleCodes.has(e.code);
    });
}

function clearSelection() {
    rows.value.forEach((e) => {
        e.selected = false;
    });
}

function pick(e) {
    current.value = e.code;
}

function errMessage(e) {
    return (
        e.response?.data?.message ||
        Object.values(e.response?.data?.errors || {})
            .flat()
            .join(' ') ||
        e.message ||
        'Unexpected error'
    );
}

function payload(c) {
    return {
        year: props.year,
        month: props.month,
        direction: c.direction,
        component_name: c.title,
        payroll_component_type_id: c.type_id,
        custom: false,
        mode: c.mode,
        calc_type: c.mode === 'manual' ? c.calc_type : null,
        value: c.mode === 'manual' ? Number(c.value) : null,
        basis: c.mode === 'manual' ? (c.calc_type === 'fixed' ? 'none' : c.basis) : null,
        legacy_basic_reference:
            c.mode === 'manual' && c.calc_type === 'percentage' && c.basis === 'legacy_basic'
                ? Number(c.legacy_basic_reference)
                : null,
        expected_revision: c.expected_revision,
        reason: auditReason.value,
        evidence_reference: null,
    };
}

async function save() {
    if (busy.value || !edits.value.length || props.isLocked) return;
    if (auditReason.value.trim().length < 3) {
        message.value = 'Write an audit reason of at least 3 characters.';
        return;
    }
    busy.value = true;
    results.value = [];
    let ok = 0;
    let fail = 0;
    for (const { e, c } of [...edits.value]) {
        try {
            const resp = await axios.post(
                `/dashboard/salary/calculator/${e.user_id}/component-override`,
                payload(c),
                { headers: { Accept: 'application/json' } },
            );
            ok++;
            c.result = 'Saved';
            c.selected = false;
            const newRev = resp.data?.revision ?? resp.data?.override?.revision;
            if (Number.isInteger(Number(newRev)) && newRev !== undefined) {
                c.expected_revision = Number(newRev);
            }
            results.value.push({ code: e.code, component: c.title, ok: true, message: 'Saved' });
        } catch (ex) {
            fail++;
            c.result = errMessage(ex);
            results.value.push({ code: e.code, component: c.title, ok: false, message: c.result });
        }
        message.value = `Saved: ${ok}; failed: ${fail}. Refresh before repeating successful edits.`;
    }
    busy.value = false;
}

async function initializeEligible() {
    if (busy.value || props.isLocked) return;
    const eligible = rows.value.filter(
        (e) =>
            e.user_id &&
            e.status === 'NOT_INITIALIZED' &&
            !String(e.error || '').includes('Multiple'),
    );
    if (!eligible.length) {
        message.value =
            'No eligible missing workflows. Ambiguous profiles require manual resolution.';
        return;
    }
    busy.value = true;
    try {
        const r = await axios.post('/dashboard/salary/workflow/reconcile', {
            year: props.year,
            month: props.month,
            user_ids: eligible.map((e) => Number(e.user_id)),
            limit: eligible.length,
        });
        message.value = `Initialized ${r.data.initialized}; ambiguous ${r.data.ambiguous}; already existing ${r.data.existing}. Reloading list…`;
        router.reload();
    } catch (e) {
        message.value = errMessage(e);
    } finally {
        busy.value = false;
    }
}

async function previewSelected() {
    const targetCodes = chosen.value.length
        ? chosen.value.map((e) => e.code)
        : filtered.value.filter((e) => e.user_id).map((e) => e.code);
    if (busy.value || !targetCodes.length) return;
    busy.value = true;
    message.value = `Creating read-only estimation run for ${targetCodes.length} employees…`;
    results.value = [];
    try {
        const start = await axios.post('/dashboard/salary/estimation/runs', {
            year: props.year,
            month: props.month,
            employee_codes: targetCodes,
        });
        const id = start.data.run_id;
        run.value = { id, status: 'running', examined: 0, estimated: 0, blocked: 0 };
        let next;
        do {
            const response = await axios.post(`/dashboard/salary/estimation/runs/${id}/next`, {});
            next = response.data;
            run.value = next.run;
            message.value = `Estimation #${id}: ${next.run.examined} checked, ${next.run.estimated} succeeded, ${next.run.blocked} blocked.`;
        } while (next.run?.status === 'running' && next.processed > 0);
        if (next.run?.blocked) {
            const errors = await axios.get(`/dashboard/salary/estimation/runs/${id}/errors`);
            results.value = (errors.data.data || []).map((x) => ({
                code: String(rows.value.find((e) => e.user_id === x.user_id)?.code || x.user_id),
                component: 'Estimation',
                ok: false,
                message: x.error_message || 'Blocked',
            }));
        }
    } catch (ex) {
        message.value = errMessage(ex);
    } finally {
        busy.value = false;
    }
}

async function saveCalculations() {
    if (busy.value || !chosen.value.length || props.isLocked) return;
    if (approvalReason.value.trim().length < 3) {
        message.value = 'Write an audit reason before saving calculations.';
        return;
    }
    busy.value = true;
    results.value = [];
    try {
        const response = await axios.post(
            '/dashboard/salary/estimation/bulk-save-calculations',
            {
                year: props.year,
                month: props.month,
                employee_codes: chosen.value.map((e) => e.code),
                reason: approvalReason.value,
            },
            { headers: { Accept: 'application/json' } },
        );
        results.value = (response.data.results || []).map((r) => ({
            code: r.code,
            component: 'Calculation version',
            ok: r.ok,
            message: r.ok ? `Approved snapshot v${r.version} (#${r.version_id})` : r.error,
        }));
        message.value = `Saved approved versions: ${response.data.succeeded}; failed: ${response.data.failed}. Reload before approval.`;
    } catch (ex) {
        message.value = errMessage(ex);
    } finally {
        busy.value = false;
    }
}

async function approve(action) {
    if (busy.value || !chosen.value.length || props.isLocked) return;
    if (approvalReason.value.trim().length < 3) {
        message.value = 'Approval requires an audit reason.';
        return;
    }
    const permitted = chosen.value.filter((e) =>
        action === 'approve_calculation'
            ? e.status === 'BLOCKED'
            : action === 'approve_for_generation'
                ? e.status === 'CALCULATED'
                : e.status === 'READY_FOR_GENERATION',
    );
    if (!permitted.length) {
        message.value = 'No selected employees have the required workflow state.';
        return;
    }
    busy.value = true;
    results.value = [];
    try {
        const items = permitted.map((e) => ({
            user_id: e.user_id,
            expected_revision: e.revision,
            calculation_version_id:
                action === 'approve_calculation'
                    ? e.latest_approved_calculation_version_id
                    : action === 'approve_for_generation'
                        ? e.active_calculation_version_id
                        : null,
        }));
        const response = await axios.post('/dashboard/salary/workflow/bulk-approve', {
            year: props.year,
            month: props.month,
            action,
            reason: approvalReason.value,
            items,
        });
        results.value = (response.data.results || []).map((r) => ({
            code: String(rows.value.find((e) => e.user_id === r.user_id)?.code || r.user_id),
            component: action,
            ok: r.ok,
            message: r.ok ? r.status : r.error,
        }));
        message.value = `Approval: ${response.data.succeeded} succeeded, ${response.data.failed} failed. Reload for current revision.`;
    } catch (ex) {
        message.value = errMessage(ex);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Bulk Salary Editor" />
    <AuthenticatedLayout>
        <div class="min-h-[calc(100vh-7rem)] bg-slate-50 px-4 py-5 text-slate-800 dark:bg-slate-950 dark:text-slate-100 sm:px-6">
            <div class="mx-auto max-w-[1600px] space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-indigo-600">
                            Payroll / Salary Estimation
                        </p>
                        <h1 class="mt-1 text-2xl font-bold">
                            Bulk Salary Setup Workspace
                            <span
                                v-if="isLocked"
                                class="ml-2 inline-flex items-center rounded-md bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800"
                            >
                                Locked Month
                            </span>
                        </h1>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ year }} / {{ String(month).padStart(2, '0') }} · Showing {{ rows.length }} of {{ totalMatched }} active employees · Edit components, monthly arrears, and review calculation readiness without posting salary.
                        </p>
                    </div>
                    <a
                        class="rounded-lg border bg-white px-4 py-2 text-sm dark:bg-slate-900"
                        :href="`/dashboard/salary/estimation?year=${year}&month=${month}`"
                    >
                        ← Salary Estimation
                    </a>
                </div>

                <!-- Dynamic Active Employee Filter Bar -->
                <div class="flex flex-wrap items-center gap-2 rounded-xl border bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
                    <select
                        v-model="selectedCategory"
                        class="rounded-lg border px-3 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800"
                        aria-label="Filter by Category"
                    >
                        <option value="">All Categories</option>
                        <option v-for="cat in userCategories" :key="cat.id" :value="String(cat.id)">
                            {{ cat.name }}
                        </option>
                    </select>
                    <select
                        v-model="selectedDepartment"
                        class="rounded-lg border px-3 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800"
                        aria-label="Filter by Department"
                    >
                        <option value="">All Departments</option>
                        <option v-for="dept in departmentOffices" :key="dept.id" :value="String(dept.id)">
                            {{ dept.name }}
                        </option>
                    </select>
                    <input
                        v-model="query"
                        @keyup.enter="applyServerFilters"
                        placeholder="Search active employee code or name..."
                        class="min-w-[220px] flex-1 rounded-lg border px-3 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800"
                        aria-label="Search active employees"
                    />
                    <button
                        type="button"
                        @click="applyServerFilters"
                        class="rounded-lg bg-indigo-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700"
                    >
                        Filter Employees
                    </button>
                    <button
                        type="button"
                        @click="resetServerFilters"
                        class="rounded-lg border px-3 py-1.5 text-xs font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                    >
                        Reset
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-2 rounded-xl border bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
                    <button
                        type="button"
                        :disabled="isLocked || busy || !rows.some((e) => e.user_id && e.status === 'NOT_INITIALIZED')"
                        @click="initializeEligible"
                        class="rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40"
                    >
                        1. Initialize missing workflows
                    </button>
                    <button
                        type="button"
                        :disabled="busy"
                        @click="selectFilteredActive"
                        class="rounded-lg border px-4 py-2 text-sm font-semibold"
                    >
                        2. Select filtered active ({{ filtered.length }})
                    </button>
                    <span class="text-xs text-slate-500">
                        Then open Monthly Setup or edit verified components, save calculations, and approve. Existing generated bills are never changed.
                    </span>
                </div>

                <details class="rounded-xl border bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                    <summary class="cursor-pointer text-sm font-semibold">
                        Employee readiness summary ({{ counts.issues }} issues)
                    </summary>
                    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-5">
                        <button
                            v-for="x in [
                                { id: 'all', label: 'Employees', n: counts.all },
                                { id: 'editable', label: 'Editable', n: counts.editable },
                                { id: 'blocked', label: 'Blocked', n: counts.blocked },
                                { id: 'calculated', label: 'Calculated', n: counts.calculated },
                                { id: 'issues', label: 'Issues', n: counts.issues },
                            ]"
                            :key="x.id"
                            @click="statusFilter = x.id"
                            :class="[
                                'rounded-xl border px-3 py-3 text-left dark:border-slate-700',
                                statusFilter === x.id
                                    ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-950'
                                    : 'bg-white dark:bg-slate-900',
                            ]"
                        >
                            <div class="text-xs text-slate-500">{{ x.label }}</div>
                            <div class="mt-1 text-2xl font-bold">{{ x.n }}</div>
                        </button>
                    </div>
                </details>

                <div class="grid gap-4 lg:grid-cols-[310px_minmax(0,1fr)]">
                    <aside class="rounded-xl border bg-white dark:border-slate-700 dark:bg-slate-900 lg:sticky lg:top-4 lg:self-start">
                        <div class="space-y-2 border-b p-3 dark:border-slate-700">
                            <input
                                v-model="query"
                                placeholder="Quick filter loaded list..."
                                class="w-full rounded-lg border px-3 py-2 text-sm dark:bg-slate-800"
                                aria-label="Quick filter loaded employees"
                            />
                            <div class="flex flex-wrap gap-2 text-xs">
                                <button class="rounded border px-2 py-1" @click="selectFilteredActive">
                                    Select filtered
                                </button>
                                <button class="rounded border px-2 py-1" @click="selectAllEditable">
                                    Select editable
                                </button>
                                <button class="rounded border px-2 py-1" @click="clearSelection">
                                    Clear
                                </button>
                            </div>
                            <p class="text-xs text-slate-500">{{ chosen.length }} selected for actions</p>
                        </div>
                        <div class="max-h-[52vh] overflow-y-auto lg:max-h-[65vh]">
                            <button
                                v-for="e in filtered"
                                :key="e.code"
                                @click="pick(e)"
                                :class="[
                                    'flex w-full items-center gap-2 border-b px-3 py-3 text-left text-sm last:border-0 dark:border-slate-700',
                                    current === e.code
                                        ? 'bg-indigo-50 dark:bg-indigo-950'
                                        : 'hover:bg-slate-50 dark:hover:bg-slate-800',
                                ]"
                            >
                                <input
                                    type="checkbox"
                                    v-model="e.selected"
                                    @click.stop
                                    :disabled="!e.user_id || e.status === 'GENERATED' || busy"
                                    :aria-label="`Select ${e.code}`"
                                />
                                <span class="min-w-0 flex-1">
                                    <strong class="block">{{ e.code }}</strong>
                                    <span class="block truncate text-xs text-slate-500">
                                        {{ e.name || 'Unmatched employee' }}
                                    </span>
                                </span>
                                <span
                                    :class="[
                                        'rounded-md px-2 py-1 text-[10px] font-semibold',
                                        e.error
                                            ? 'bg-rose-100 text-rose-700'
                                            : e.status === 'CALCULATED'
                                                ? 'bg-emerald-100 text-emerald-700'
                                                : 'bg-slate-100 text-slate-600',
                                    ]"
                                >
                                    {{ e.error ? 'ISSUE' : e.status }}
                                </span>
                            </button>
                            <p v-if="!filtered.length" class="p-4 text-sm text-slate-500">
                                No matching active employees.
                            </p>
                        </div>
                        <details class="border-t p-3 dark:border-slate-700">
                            <summary class="cursor-pointer text-xs font-semibold">
                                Optional: Load specific employee codes
                            </summary>
                            <textarea
                                v-model="codes"
                                class="mt-2 w-full rounded border p-2 text-xs dark:bg-slate-800"
                                rows="3"
                                placeholder="Comma-separated employee codes (leave blank for all active)"
                            />
                            <button
                                class="mt-2 rounded bg-slate-800 px-3 py-2 text-xs text-white"
                                @click="applyServerFilters"
                            >
                                Load codes
                            </button>
                        </details>
                    </aside>

                    <div class="min-w-0 space-y-4">
                        <section v-if="active" class="rounded-xl border bg-white p-4 dark:border-slate-700 dark:bg-slate-900 sm:p-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs text-slate-500">Employee details</div>
                                    <h2 class="text-xl font-bold">
                                        {{ active.code }} — {{ active.name || 'Unmatched employee' }}
                                    </h2>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Workflow: {{ active.status }} · Revision: {{ active.revision || 0 }} · Approved version: {{ active.latest_approved_calculation_version_id || 'Not available' }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-if="active.user_id"
                                        type="button"
                                        @click="openMonthlySetup(active)"
                                        class="rounded bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700"
                                    >
                                        Monthly Setup &amp; Arrears
                                    </button>
                                    <a
                                        v-if="active.user_id"
                                        class="rounded border px-3 py-2 text-xs"
                                        :href="`/dashboard/salary/calculator/${active.user_id}?year=${year}&month=${month}`"
                                    >
                                        Open calculator
                                    </a>
                                    <a
                                        v-if="active.user_id"
                                        class="rounded border px-3 py-2 text-xs"
                                        :href="`/dashboard/salary/fixation/${active.user_id}/workspace?year=${year}&month=${month}`"
                                    >
                                        Fixation
                                    </a>
                                </div>
                            </div>
                            <p
                                v-if="active.error"
                                class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"
                            >
                                {{ active.error }}
                            </p>
                            <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                                <div class="flex gap-1 text-sm">
                                    <button
                                        v-for="t in [
                                            { id: 'all', label: 'All' },
                                            { id: 'addition', label: 'Allowances' },
                                            { id: 'deduction', label: 'Deductions' },
                                        ]"
                                        :key="t.id"
                                        @click="tab = t.id"
                                        :class="[
                                            'rounded-md px-3 py-2',
                                            tab === t.id ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700',
                                        ]"
                                    >
                                        {{ t.label }}
                                    </button>
                                </div>
                                <label class="text-xs">
                                    <input v-model="showOnlyChanges" type="checkbox" /> Selected edits only
                                </label>
                            </div>
                            <div class="mt-3 max-h-[47vh] overflow-auto rounded-lg border dark:border-slate-700">
                                <table class="w-full min-w-[730px] text-left text-sm">
                                    <thead class="sticky top-0 z-10 bg-slate-100 text-xs dark:bg-slate-800">
                                        <tr>
                                            <th class="p-3">Edit</th>
                                            <th>Component</th>
                                            <th>Type</th>
                                            <th>Action</th>
                                            <th>Calculation rule</th>
                                            <th>Value / Rate</th>
                                            <th>Basis</th>
                                            <th>Result</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="c in components"
                                            :key="`${c.direction}-${c.type_id}`"
                                            class="border-t dark:border-slate-700"
                                        >
                                            <td class="p-3">
                                                <input type="checkbox" v-model="c.selected" :disabled="!active.editable || busy" />
                                            </td>
                                            <td class="max-w-[200px] font-medium">{{ c.title }}</td>
                                            <td class="text-xs text-slate-500">{{ c.direction }}</td>
                                            <td>
                                                <select
                                                    v-model="c.mode"
                                                    :disabled="!active.editable || busy"
                                                    class="w-24 rounded border p-1 text-xs dark:bg-slate-800"
                                                >
                                                    <option value="manual">Override</option>
                                                    <option value="disabled">Disable</option>
                                                    <option value="automatic">Automatic</option>
                                                </select>
                                            </td>
                                            <td>
                                                <select
                                                    v-model="c.calc_type"
                                                    :disabled="!active.editable || c.mode !== 'manual' || busy"
                                                    class="rounded border p-1 text-xs dark:bg-slate-800"
                                                >
                                                    <option value="fixed">Fixed</option>
                                                    <option value="percentage">Percentage</option>
                                                </select>
                                            </td>
                                            <td>
                                                <input
                                                    v-model.number="c.value"
                                                    type="number"
                                                    min="0"
                                                    :max="c.calc_type === 'percentage' ? 100 : 1000000000"
                                                    step="0.01"
                                                    :disabled="!active.editable || c.mode !== 'manual' || busy"
                                                    class="w-24 rounded border p-1 text-xs dark:bg-slate-800"
                                                />
                                                <span class="ml-1 text-[10px] text-slate-500">
                                                    {{ c.calc_type === 'percentage' ? '%' : 'BDT' }}
                                                </span>
                                            </td>
                                            <td>
                                                <select
                                                    v-if="c.calc_type === 'percentage' && c.mode === 'manual'"
                                                    v-model="c.basis"
                                                    class="rounded border p-1 text-xs dark:bg-slate-800"
                                                >
                                                    <option value="payable_basic">Payable Basic</option>
                                                    <option value="legacy_basic">Legacy Basic</option>
                                                </select>
                                                <input
                                                    v-if="c.calc_type === 'percentage' && c.mode === 'manual' && c.basis === 'legacy_basic'"
                                                    v-model.number="c.legacy_basic_reference"
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    placeholder="Verified basic"
                                                    class="ml-1 w-24 rounded border p-1 text-xs dark:bg-slate-800"
                                                />
                                            </td>
                                            <td class="max-w-[140px] text-xs">{{ c.result }}</td>
                                        </tr>
                                        <tr v-if="!components.length">
                                            <td colspan="8" class="p-4 text-slate-500">
                                                No safely mapped components for this employee and filter.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="rounded-xl border bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                            <h3 class="font-bold">Bulk actions</h3>
                            <div class="mt-3 mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-3 dark:bg-slate-800">
                                <p class="text-sm font-bold">Common change for selected employees · stage only</p>
                                <p class="my-1 text-xs">
                                    Select employee checkboxes first. Only already-mapped component IDs can be changed. Review each row before saving; no money is posted.
                                </p>
                                <div class="flex flex-wrap items-center gap-2">
                                    <select v-model="commonTypeId" class="max-w-xs rounded border p-2 text-xs">
                                        <option value="">Choose existing component…</option>
                                        <option v-for="option in availableCommonComponents" :key="option.key" :value="option.key">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <select v-model="commonMode" class="rounded border p-2 text-xs">
                                        <option value="manual">Override</option>
                                        <option value="disabled">Disable</option>
                                        <option value="automatic">Automatic</option>
                                    </select>
                                    <select v-model="commonCalcType" class="rounded border p-2 text-xs">
                                        <option value="fixed">Fixed</option>
                                        <option value="percentage">Percentage</option>
                                    </select>
                                    <input v-model.number="commonValue" type="number" min="0" step="0.01" class="w-28 rounded border p-2 text-xs" />
                                    <select v-if="commonCalcType === 'percentage'" v-model="commonBasis" class="rounded border p-2 text-xs">
                                        <option value="payable_basic">Payable Basic</option>
                                        <option value="legacy_basic">Legacy Basic (requires per-person reference)</option>
                                    </select>
                                    <button
                                        :disabled="isLocked || busy || !chosen.length || !commonTypeId"
                                        @click="stageCommon"
                                        class="rounded bg-indigo-700 px-3 py-2 text-xs text-white disabled:opacity-40"
                                    >
                                        Stage selected
                                    </button>
                                </div>
                            </div>

                            <p class="mb-3 text-xs text-slate-500">
                                Only explicitly checked component rows are saved. Changes use existing audited API, one row at a time. No salary bill or GPF ledger is posted.
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <input
                                    v-model="auditReason"
                                    placeholder="Mandatory reason for component changes"
                                    class="min-w-[230px] flex-1 rounded border px-3 py-2 text-sm dark:bg-slate-800"
                                />
                                <button
                                    class="rounded bg-indigo-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40"
                                    :disabled="isLocked || busy || !edits.length"
                                    @click="save"
                                >
                                    {{ busy ? 'Processing…' : `Save ${edits.length} component edits` }}
                                </button>
                            </div>

                            <div class="mt-4 border-t pt-4 dark:border-slate-700">
                                <p class="mb-2 text-sm font-medium">Estimation &amp; approval</p>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        class="rounded border px-3 py-2 text-xs disabled:opacity-40"
                                        :disabled="busy || (!chosen.length && !filtered.length)"
                                        @click="previewSelected"
                                    >
                                        Preview {{ chosen.length || filtered.length }} employees
                                    </button>
                                    <input
                                        v-model="approvalReason"
                                        placeholder="Approval audit reason"
                                        class="min-w-[180px] flex-1 rounded border px-3 py-2 text-xs dark:bg-slate-800"
                                    />
                                    <button
                                        class="rounded bg-emerald-700 px-3 py-2 text-xs font-semibold text-white disabled:opacity-40"
                                        :disabled="isLocked || busy || !chosen.length"
                                        @click="saveCalculations"
                                    >
                                        Save approved calculations (selected)
                                    </button>
                                    <button
                                        class="rounded border px-3 py-2 text-xs disabled:opacity-40"
                                        :disabled="isLocked || busy || !chosen.length"
                                        @click="approve('approve_calculation')"
                                    >
                                        Approve Calculation
                                    </button>
                                    <button
                                        class="rounded border px-3 py-2 text-xs disabled:opacity-40"
                                        :disabled="isLocked || busy || !chosen.length"
                                        @click="approve('approve_for_generation')"
                                    >
                                        Approve for Generation
                                    </button>
                                    <button
                                        class="rounded border px-3 py-2 text-xs disabled:opacity-40"
                                        :disabled="isLocked || busy || !chosen.length"
                                        @click="approve('return_for_revision')"
                                    >
                                        Return for Revision
                                    </button>
                                </div>
                                <p class="mt-2 text-xs text-amber-700">
                                    Approval requires an existing verified, saved and approved calculation version. A successful preview alone does not qualify.
                                </p>
                            </div>
                        </section>

                        <section class="rounded-xl border bg-white p-4 dark:border-slate-700 dark:bg-slate-900" aria-live="polite">
                            <h3 class="font-bold">Results &amp; errors</h3>
                            <p class="my-2 text-sm">{{ message || 'No operation performed yet.' }}</p>
                            <p v-if="run" class="text-xs text-slate-500">
                                Run #{{ run.id }} · {{ run.status }} · Estimated {{ run.estimated }} · Blocked {{ run.blocked }}
                            </p>
                            <div class="max-h-44 overflow-y-auto text-xs">
                                <p
                                    v-for="(r, i) in results"
                                    :key="i"
                                    :class="['border-t py-2', r.ok ? 'text-emerald-700' : 'text-rose-700']"
                                >
                                    {{ r.code }} · {{ r.component }} · {{ r.ok ? 'SUCCESS' : 'FAILED' }} — {{ r.message }}
                                </p>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </div>

        <SalarySetupModal
            :show="monthlySetupOpen"
            :user-id="monthlySetupUserId"
            :initial-year="year"
            :initial-month="month"
            @close="monthlySetupOpen = false"
            @saved="router.reload()"
        />
    </AuthenticatedLayout>
</template>
