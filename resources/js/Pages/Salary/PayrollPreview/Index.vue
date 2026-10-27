<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const year = ref(2026);
const month = ref(10);
const loading = ref(false);
const arrearPeriodId = ref(null);
const error = ref('');
const result = ref(null);
const search = ref('');
const statusFilter = ref('all');

async function loadPreview() {
    loading.value = true;
    error.value = '';
    result.value = null;
    try {
        const response = await axios.get(route('salary-payroll-preview.data'), { params: { year: year.value, month: month.value, arrear_period_id: arrearPeriodId.value || undefined } });
        result.value = response.data;
    } catch (e) {
        error.value = e.response?.data?.message || e.message || 'Preview could not be loaded.';
    } finally {
        loading.value = false;
    }
}
const rows = computed(() => (result.value?.employees || []).filter(r => {
    if (statusFilter.value !== 'all' && r.status !== statusFilter.value) return false;
    const text = `${r.employee_name} ${r.employee_code} ${r.grade}`.toLowerCase();
    return text.includes(search.value.toLowerCase());
}));
function csvCell(value) {
    const safe = String(value ?? '').replace(/^[=+@-]/, match => '\'' + match);
    return '"' + safe.replaceAll('"', '""') + '"';
}
function exportCSV() {
    if (!result.value) return;
    const headings = ['employee_code','employee_name','pay_scale','grade','basic','total_allowance','total_deduction','gpf','tax','net','historical_arrear','arrear_additions','arrear_deductions','arrear_additional_gpf','combined_read_only_net','manual_adjustments','existing_bill_id','historical_paid_basics','status','reasons'];
    const lines = [headings.map(csvCell).join(',')];
    rows.value.forEach(row => lines.push(headings.map(key => csvCell(key === 'reasons' ? row.reasons.join(' | ') : (key === 'historical_paid_basics' ? JSON.stringify(row.historical_paid_basics) : row[key]))).join(',')));
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `KAU_ReadOnly_Salary_Preview_${year.value}_${String(month.value).padStart(2,'0')}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}
const money = v => v === null || v === undefined ? '—' : Number(v).toLocaleString('en-BD',{minimumFractionDigits:2,maximumFractionDigits:2});
</script>

<template>
    <Head title="Salary Payroll Preview" />
    <AuthenticatedLayout>
        <div class="max-w-7xl mx-auto p-5 space-y-5">
            <h1 class="text-2xl font-semibold text-slate-800">Read-only Salary Preview</h1>
            <p class="text-sm text-amber-700">DRAFT ONLY — No salary bills, historical arrears or GPF ledger entries are created by this page.</p>
            <div class="bg-white rounded-xl shadow-sm p-4 flex flex-wrap gap-3 items-end">
                <label class="text-sm">Year<input v-model.number="year" type="number" min="2020" max="2100" class="block border rounded mt-1 w-28" /></label>
                <label class="text-sm">Month<select v-model.number="month" class="block border rounded mt-1"><option v-for="n in 12" :key="n" :value="n">{{ n }}</option></select></label>
                <label class="text-sm">Optional approved arrear period ID<input v-model.number="arrearPeriodId" type="number" min="1" class="block border rounded mt-1 w-28" placeholder="Optional" /></label>
                <button type="button" class="rounded bg-emerald-600 text-white px-4 py-2" :disabled="loading" @click="loadPreview">{{ loading ? 'Loading…' : 'Calculate Draft' }}</button>
                <button type="button" class="rounded bg-slate-700 text-white px-4 py-2" :disabled="!result" @click="exportCSV">Export filtered CSV</button>
            </div>
            <p v-if="error" class="text-red-700">{{ error }}</p>
            <template v-if="result">
                <div class="bg-white rounded-xl p-4 text-sm">{{ result.regular_scale_rule }} · Total: {{ result.counts.total }} · PASS: {{ result.counts.pass }} · REVIEW: {{ result.counts.review }} · BLOCKED: {{ result.counts.blocked }}</div>
                <div class="flex gap-3"><input v-model="search" class="border rounded p-2" placeholder="Search employee or code" /><select v-model="statusFilter" class="border rounded p-2"><option value="all">All</option><option value="PASS">PASS</option><option value="REVIEW">REVIEW</option><option value="BLOCKED">BLOCKED</option></select></div>
                <div class="overflow-x-auto bg-white rounded-xl shadow-sm">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-slate-100"><tr><th class="p-3">Employee</th><th class="p-3">Scale / Grade</th><th class="p-3">Basic</th><th class="p-3">Gross</th><th class="p-3">Deduction</th><th class="p-3">GPF</th><th class="p-3">Tax</th><th class="p-3">Regular Net</th><th class="p-3">Approved Arrear</th><th class="p-3">Preview Combined Net</th><th class="p-3">Status / Reasons</th></tr></thead>
                        <tbody><tr v-for="row in rows" :key="row.employee_profile_id" class="border-t align-top"><td class="p-3">{{ row.employee_name }}<div class="text-slate-500">{{ row.employee_code }}</div></td><td class="p-3">{{ row.pay_scale }}<div>{{ row.grade }}</div></td><td class="p-3 text-right">{{ money(row.basic) }}</td><td class="p-3 text-right">{{ money(row.total_allowance) }}</td><td class="p-3 text-right">{{ money(row.total_deduction) }}</td><td class="p-3 text-right">{{ money(row.gpf) }}</td><td class="p-3 text-right">{{ money(row.tax) }}</td><td class="p-3 text-right font-semibold">{{ money(row.net) }}</td><td class="p-3 text-right">{{ money(row.historical_arrear) }}</td><td class="p-3 text-right">{{ money(row.combined_read_only_net) }}</td><td class="p-3">{{ row.status }}<div class="text-amber-700 max-w-64">{{ row.reasons.join(' | ') }}</div></td></tr></tbody>
                    </table>
                </div>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
