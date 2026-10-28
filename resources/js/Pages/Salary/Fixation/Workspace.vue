<script setup>
import { ref, computed } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import axios from "axios";

const props = defineProps({
    employee: { type: Object, required: true },
    year: { type: Number, required: true },
    month: { type: Number, required: true },
    fixation: { type: Object, default: null },
    workflow: { type: Object, default: null },
    components: { type: Array, default: () => [] },
    overrides: { type: Array, default: () => [] },
    error: { type: String, default: null },
});
const current = ref(null);
const mode = ref("manual");
const calcType = ref("fixed");
const amount = ref("0");
const basis = ref("none");
const legacyBasic = ref("");
const reason = ref("");
const evidence = ref("");
const busy = ref(false);
const message = ref("");
const blocked = computed(
    () =>
        !!props.error ||
        ["READY_FOR_GENERATION", "GENERATED"].includes(props.workflow.status),
);
const record = computed(
    () =>
        props.overrides.find(
            (o) =>
                o.direction === current.value?.direction &&
                Number(o.payroll_component_type_id) ===
                    Number(current.value?.type_id),
        ) || null,
);
function edit(row) {
    current.value = row;
    const saved = props.overrides.find(
        (o) =>
            o.direction === row.direction &&
            Number(o.payroll_component_type_id) === Number(row.type_id),
    );
    mode.value = saved?.mode ?? "manual";
    calcType.value = saved?.calc_type ?? row.calc_type;
    amount.value = String(saved?.value ?? row.value);
    basis.value =
        saved?.basis ??
        (calcType.value === "percentage" ? "payable_basic" : "none");
    legacyBasic.value = String(saved?.legacy_basic_reference ?? "");
    reason.value = saved?.reason ?? "";
    evidence.value = saved?.evidence_reference ?? "";
    message.value = "";
}
async function initializeWorkflow() {
    if (busy.value || !props.employee?.id || props.workflow) return;
    busy.value = true;
    message.value = "Initializing missing employee-period workflow…";
    try {
        const response = await axios.post('/dashboard/salary/workflow/reconcile', {
            year: props.year, month: props.month, user_ids: [Number(props.employee.id)], limit: 1,
        });
        const stats = response.data;
        message.value = `Initialized: ${stats.initialized}; ambiguous: ${stats.ambiguous}. Reloading…`;
        router.reload();
    } catch (error) {
        message.value = error.response?.data?.message || error.message || 'Initialization failed.';
    } finally { busy.value = false; }
}
async function save() {
    if (!current.value || blocked.value || busy.value) return;
    busy.value = true;
    message.value = "";
    try {
        const res = await axios.post(
            route("salary-calculator.component-override", props.employee.id),
            {
                year: props.year,
                month: props.month,
                direction: current.value.direction,
                component_name: current.value.name,
                payroll_component_type_id: current.value.type_id,
                custom: false,
                mode: mode.value,
                calc_type: mode.value === "manual" ? calcType.value : null,
                value: mode.value === "manual" ? Number(amount.value) : null,
                basis:
                    mode.value === "manual"
                        ? calcType.value === "fixed"
                            ? "none"
                            : basis.value
                        : null,
                legacy_basic_reference:
                    mode.value === "manual" &&
                    calcType.value === "percentage" &&
                    basis.value === "legacy_basic"
                        ? Number(legacyBasic.value)
                        : null,
                reason: reason.value,
                evidence_reference: evidence.value,
                expected_revision: Number(record.value?.revision ?? 0),
            },
        );
        message.value = res.data.message;
        current.value = null;
        router.reload({ preserveScroll: true });
    } catch (e) {
        message.value =
            e.response?.data?.message ||
            "Save failed. Check the audit reason and current workflow state.";
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <Head title="Unified Salary Fixation & Configuration" />
    <AuthenticatedLayout>
        <div v-if="!workflow && !error" class="mx-auto max-w-6xl px-5 pt-4">
            <button type="button" :disabled="busy" @click="initializeWorkflow"
                class="rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                Initialize this month's workflow
            </button>
            <p class="mt-1 text-xs text-amber-700">Only creates a missing workflow record. It never changes a bill, fixation or GPF balance.</p>
            <p v-if="message" role="status" class="mt-1 text-xs">{{ message }}</p>
        </div>
        <div
            class="max-w-6xl mx-auto p-5 space-y-5 text-slate-900 dark:text-slate-100"
        >
            <h1 class="text-2xl font-semibold">
                Salary Fixation & Employee Configuration
            </h1>
            <div class="flex gap-5 flex-wrap text-sm">
                <Link :href="route('salary-fixation.index')" class="underline"
                    >← All fixations</Link
                >
                <a
                    :href="
                        route('salary-calculator.show', employee.id) +
                        '?year=' +
                        year +
                        '&month=' +
                        month
                    "
                    class="underline"
                    >Open salary calculator (separate)</a
                >
            </div>
            <div
                class="rounded-xl border p-4 grid grid-cols-1 md:grid-cols-3 gap-3"
            >
                <div>
                    <b>{{ employee.name }}</b>
                    <p>
                        Employee code: {{ employee.code || "Review required" }}
                    </p>
                    <p>
                        Period: {{ year }}-{{ String(month).padStart(2, "0") }}
                    </p>
                </div>
                <div>
                    <p>
                        Fixation: <b>{{ fixation?.status ?? "Not saved" }}</b>
                    </p>
                    <p>
                        Period-effective Basic:
                        {{ fixation?.effective_basic ?? "Not available" }}
                    </p>
                    <p>Effective date: {{ fixation?.effective_date ?? "—" }}</p>
                </div>
                <div>
                    <p>
                        HR workflow:
                        <b>{{ workflow?.status ?? "Not initialized" }}</b>
                    </p>
                    <p>Revision: {{ workflow?.revision ?? "—" }}</p>
                    <p>
                        Current profile Basic:
                        {{ fixation?.current_profile_basic ?? "Not available" }}
                    </p>
                </div>
            </div>
            <p v-if="error" role="alert" class="text-red-700">{{ error }}</p>
            <p v-if="blocked" class="text-amber-700">
                This salary period is already approved for generation or posted. Return an approved draft for revision before editing; posted bills remain immutable.
            </p>
            <p v-if="!workflow && !error" class="text-sm text-emerald-700">First edit will automatically initialize the missing workflow. No separate setup step required.</p>
            <p class="text-sm">
                Configured amounts below represent rates: a percentage is not a
                fixed currency amount. Existing payroll services calculate its
                monetary result.
            </p>
            <div class="card bg-slate-50">
                <div class="card-body">
                    <div class="overflow-x-auto rounded-xl border">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b">
                                    <th class="p-3 text-left">Direction</th>
                                    <th class="p-3 text-left">Component</th>
                                    <th class="p-3">Rule</th>
                                    <th class="p-3">Rate / Value</th>
                                    <th class="p-3">Source</th>
                                    <th class="p-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(c, i) in components"
                                    :key="i"
                                    class="border-b"
                                >
                                    <td class="p-3">{{ c.direction }}</td>
                                    <td class="p-3">{{ c.name }}</td>
                                    <td class="p-3 text-center">
                                        {{ c.calc_type }}
                                    </td>
                                    <td class="p-3 text-center">
                                        {{ c.value
                                        }}{{
                                            c.calc_type === "percentage"
                                                ? "%"
                                                : ""
                                        }}
                                    </td>
                                    <td class="p-3 text-center">
                                        {{ c.source }}
                                    </td>
                                    <td class="p-3">
                                        <button
                                            :disabled="!c.editable || blocked"
                                            class="underline disabled:opacity-30"
                                            @click="edit(c)"
                                        >
                                            Override
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div
                v-if="current"
                class="max-w-2xl rounded-xl border p-5 space-y-3"
            >
                <h2 class="font-semibold">
                    Edit: {{ current.direction }} / {{ current.name }}
                </h2>
                <label class="block"
                    >Mode
                    <select
                        v-model="mode"
                        class="text-slate-900 border rounded p-2 w-full"
                    >
                        <option value="manual">Manual override</option>
                        <option value="disabled">Disable</option>
                        <option value="automatic">Restore automatic</option>
                    </select></label
                >
                <template v-if="mode === 'manual'">
                    <label class="block"
                        >Type
                        <select
                            v-model="calcType"
                            class="text-slate-900 border rounded p-2 w-full"
                        >
                            <option value="fixed">Fixed</option>
                            <option value="percentage">Percentage</option>
                        </select></label
                    >
                    <label class="block"
                        >Rate / Value
                        <input
                            v-model="amount"
                            type="number"
                            min="0"
                            step="0.01"
                            class="text-slate-900 border rounded p-2 w-full"
                    /></label>
                    <label v-if="calcType === 'percentage'" class="block"
                        >Basis
                        <select
                            v-model="basis"
                            class="text-slate-900 border rounded p-2 w-full"
                        >
                            <option value="payable_basic">Payable Basic</option>
                            <option value="legacy_basic">
                                Verified legacy Basic
                            </option>
                        </select></label
                    >
                    <label
                        v-if="
                            calcType === 'percentage' &&
                            basis === 'legacy_basic'
                        "
                        class="block"
                        >Verified legacy Basic reference
                        <input
                            v-model="legacyBasic"
                            type="number"
                            min="0.01"
                            step="0.01"
                            class="text-slate-900 border rounded p-2 w-full"
                    /></label>
                </template>
                <label class="block"
                    >Adjustment reason
                    <textarea
                        v-model="reason"
                        required
                        class="text-slate-900 border rounded p-2 w-full"
                    />
                </label>
                <label class="block"
                    >Evidence reference (optional)
                    <input
                        v-model="evidence"
                        class="text-slate-900 border rounded p-2 w-full"
                /></label>
                <div class="flex gap-3">
                    <button
                        :disabled="busy || !reason.trim()"
                        @click="save"
                        class="px-4 py-2 bg-indigo-700 text-white rounded disabled:opacity-40"
                    >
                        Save & audit</button
                    ><button @click="current = null" class="underline">
                        Cancel
                    </button>
                </div>
            </div>
            <p v-if="message" role="status" class="text-sm">{{ message }}</p>
            <h2 class="text-lg font-semibold">
                Saved employee/month overrides
            </h2>
            <div v-if="!overrides.length" class="text-sm text-slate-500">
                No saved overrides for this period.
            </div>
            <ul class="space-y-1 text-sm">
                <li v-for="o in overrides" :key="o.id">
                    {{ o.direction }} / {{ o.component_title }} — {{ o.mode }} —
                    Revision {{ o.revision }} — {{ o.reason }}
                </li>
            </ul>
        </div>
    </AuthenticatedLayout>
</template>
