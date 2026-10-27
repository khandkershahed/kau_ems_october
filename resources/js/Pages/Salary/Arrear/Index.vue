<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const periods = ref([]);
const selected = ref('');
const draftPeriod = ref({ name: 'July–September 2026 proposed arrears', entitlement_start: '2026-07-01', entitlement_end: '2026-09-30', notes: '' });
const policy = ref({ special_benefit_policy:'unconfirmed', house_rent_policy:'unconfirmed', gpf_policy:'unconfirmed', gpf_rounding:'truncate_taka', gpf_rate_percent:null, planned_payment_month:'', policy_evidence:'', policy_status:'draft', notes:'' });
const filter = ref('');
const records = ref([]);
const page = ref(1);
const lastPage = ref(1);
const totalProfiles = ref(0);
const selectedEmployee = ref(null);
const selectedStatement = ref(null);
const snapshotId = ref(null);
const approvalNote = ref('');
const postSnapshotId = ref(null);
const postSalaryGenerateId = ref(null);
const reviewNote = ref('');
const manual = ref({ year:2026, month:7, title:'', direction:'addition', amount:null, reason:'', evidence_reference:'' });
const payment = ref({year:2026,month:7,salary_generate_id:null,evidence_reference:'',reason:''});
const source = ref({ year:2026, month:7, field:'revised_house_rent', amount:null, certified:false, reason:'', evidence_reference:'' });
const loading = ref(false);
const error = ref('');
const success = ref('');
const activePeriod = computed(() => periods.value.find(x => Number(x.id) === Number(selected.value)));
const money = v => v === null || v === undefined ? '—' : Number(v).toLocaleString('en-BD', {minimumFractionDigits:2,maximumFractionDigits:2});

async function call(action) {
    loading.value = true;
    error.value = '';
    success.value = '';
    try { return await action(); }
    catch (e) {
        const validation = e.response?.data?.errors;
        error.value = validation ? Object.values(validation).flat().join(' | ') : (e.response?.data?.message || e.message || 'Request failed');
        return null;
    } finally { loading.value = false; }
}
async function loadPeriods() {
    const response = await call(() => axios.get(route('salary-arrears.periods')));
    if (response) {
        periods.value = response.data;
        if (!selected.value && periods.value.length) selectPeriod(periods.value[0].id);
    }
}
function selectPeriod(id) {
    selected.value = Number(id);
    const p = activePeriod.value;
    if (p) policy.value = {
        special_benefit_policy:p.special_benefit_policy, house_rent_policy:p.house_rent_policy,
        gpf_policy:p.gpf_policy, gpf_rounding:p.gpf_rounding,
        gpf_rate_percent:p.gpf_rate_percent, planned_payment_month:p.planned_payment_month || '', policy_evidence:p.policy_evidence || '',
        policy_status:p.policy_status, notes:p.notes || '',
    };
    records.value = [];
    selectedStatement.value = null;
    selectedEmployee.value = null;
    page.value = 1;
    // Phase 3C-Fix (Task C.8): period change clears stale calculator state and unsaved edits.
    structured.value = null;
    componentEdits.value = {};
}
async function createPeriod() {
    const r = await call(() => axios.post(route('salary-arrears.periods.store'), draftPeriod.value));
    if (r) { success.value = r.data.message; await loadPeriods(); selectPeriod(r.data.id); }
}
async function savePolicy() {
    if (!selected.value) return;
    const r = await call(() => axios.put(route('salary-arrears.periods.policy', {periodId:selected.value}), policy.value));
    if (r) { success.value = r.data.message; await loadPeriods(); selectPeriod(selected.value); }
}
async function loadPreview() {
    if (!selected.value) return;
    const r = await call(() => axios.get(route('salary-arrears.preview', {periodId:selected.value}), {params:{search:filter.value,page:page.value,per_page:25}}));
    if (r) {
        records.value = r.data.employees;
        lastPage.value = r.data.last_page;
        totalProfiles.value = r.data.total_profiles;
    }
}
async function openStatement(person) {
    selectedEmployee.value = person;
    selectedStatement.value = null;
    snapshotId.value = null;
    const r = await call(() => axios.get(route('salary-arrears.statement', {periodId:selected.value,userId:person.user_id})));
    if (r) selectedStatement.value = r.data.statement;
}
// ===== Phase 3C: Employee Arrear Calculator (canonical backend calculation) =====
const structured = ref(null);          // structuredPreview response from backend
const componentEdits = ref({});        // code -> proposed local amount (never auto-saved)
const structuredSearch = ref('');
function codeLabel(code) {
    return {
        BASIC_ARREAR_GROSS: 'Gross Basic Arrear',
        BASIC_INCREMENT_ADJUSTMENT: 'Old-Scale Increment Adjustment',
        SPECIAL_BENEFIT_RECOVERY: 'Special Benefit Recovery',
        HOUSE_RENT_RECOVERY: 'House Rent Difference',
        ADDITIONAL_GPF: 'Additional GPF Contribution',
    }[code] || code;
}
function componentAmount(c) {
    // Missing evidence is displayed as 0.00 with an explicit unverified marker; null is preserved internally.
    if (c.calculated_amount === null || c.calculated_amount === undefined) return 0.00;
    return Number(c.calculated_amount);
}
function editFor(c) {
    const v = componentEdits.value[c.code];
    return v === null || v === undefined ? componentAmount(c) : Number(v);
}
function provisionalTotals() {
    const t = { payable: 0, deduction: 0 };
    for (const c of (structured.value?.components ?? [])) {
        if (c.missing_evidence && componentEdits.value[c.code] === undefined) continue; // unverified rows stay out of totals
        const v = editFor(c);
        if (c.direction === 'payable') t.payable += v; else t.deduction += v;
    }
    t.net = t.payable - t.deduction;
    return t;
}
const structuredTotals = computed(provisionalTotals);
async function calculateArrear(person) {
    if (!person || !person.user_id) { error.value = 'Select a valid employee first.'; return; }
    selectedEmployee.value = person;   // store selection consistently
    structured.value = null;           // reset previous employee results
    componentEdits.value = {};         // reset unsaved proposed edits
    const r = await call(() => axios.get(
        route('salary-arrears.structured-preview', {periodId:selected.value,userId:person.user_id}),
        {headers:{Accept:'application/json'}}));
    structured.value = r ? r.data : null; // on HTTP error, no stale results remain
}
function resetCalculator() {
    structured.value = null;
    componentEdits.value = {};
    structuredSearch.value = '';
}
// Phase 3C-Fix (Task E): only finite, non-negative monetary amounts are accepted.
function setEdit(c, raw) {
    if (raw === '' || raw === null) { delete componentEdits.value[c.code]; return; }
    const n = Number(raw);
    if (!Number.isFinite(n) || n < 0) return; // reject invalid input; previous value stays
    componentEdits.value[c.code] = n;
}
// Phase 3C-Fix (Task D): month_sources may not be an array — normalize safely.
function sourceMonths(c) {
    const ms = c.month_sources;
    if (!ms) return [];
    const arr = Array.isArray(ms) ? ms : Object.values(ms ?? {});
    return arr.filter(m => m && typeof m === 'object');
}
function monthLabel(m) {
    const y = m.year ?? m.salary_year ?? m.period ?? '';
    const mo = m.month ?? m.salary_month ?? '';
    const id = m.source_id ?? m.source_record_id ?? m.id ?? '';
    const label = [y, mo].filter(v => v !== '').join('-');
    return id !== '' ? `${label} #${id}` : label;
}
const structuredCandidates = computed(() => {
    const q = structuredSearch.value.trim().toLowerCase();
    const list = records.value ?? [];
    if (!q) return list;
    return list.filter(p => p.name.toLowerCase().includes(q) || String(p.employee_code).toLowerCase().includes(q));
});
// ===== end Phase 3C calculator =====
async function addManual() {
    if (!selectedEmployee.value) return;
    const r = await call(() => axios.post(route('salary-arrears.manual', {periodId:selected.value,userId:selectedEmployee.value.user_id}), manual.value));
    if (r) { success.value = r.data.message; await openStatement(selectedEmployee.value); }
}
async function certifyPayment() {
    if (!selectedEmployee.value) return;
    const r=await call(()=>axios.post(route('salary-arrears.payment-confirmation', {periodId:selected.value,userId:selectedEmployee.value.user_id}),payment.value));
    if(r){success.value=r.data.message;await openStatement(selectedEmployee.value);}
}
async function saveSource() {
    if (!selectedEmployee.value) return;
    const r = await call(() => axios.post(route('salary-arrears.source', {periodId:selected.value,userId:selectedEmployee.value.user_id}), source.value));
    if (r) { success.value = r.data.message; await openStatement(selectedEmployee.value); }
}
async function saveSnapshot() {
    const r = await call(() => axios.post(route('salary-arrears.snapshot', {periodId:selected.value,userId:selectedEmployee.value.user_id})));
    if (r) { snapshotId.value = r.data.snapshot_id; success.value = 'Draft snapshot #' + snapshotId.value + ' saved. No salary bill posted.'; }
}
async function sendReview() {
    if (!snapshotId.value) return;
    const r = await call(() => axios.post(route('salary-arrears.review', {snapshotId:snapshotId.value}), {action:'send',note:reviewNote.value}));
    if (r) success.value = 'Marked for employee review. This action does not send email.';
}
async function approve() {
    if (!snapshotId.value) return;
    const r = await call(() => axios.post(route('salary-arrears.approve', {snapshotId:snapshotId.value}), {approval_note:approvalNote.value}));
    if (r) success.value = 'Arrear snapshot approved; no disbursement or GPF posting was created.';
}
async function postApprovedArrear() {
    if (!postSnapshotId.value || !postSalaryGenerateId.value) {
        error.value = 'Approved Snapshot ID and existing pending Salary Bill ID are required.';
        return;
    }
    const response = await call(() => axios.post(route('salary-arrears.post-approved'), {
        snapshot_id: postSnapshotId.value,
        salary_generate_id: postSalaryGenerateId.value,
    }));
    if (response) {
        success.value = response.data.status === 'already_posted'
            ? 'Arrear was already posted to this salary bill. No duplicate entry created.'
            : `Approved arrear added to pending salary bill #${response.data.salary_generate_id}. No bank payment executed.`;
    }
}
async function pdfStatement() {
    if (!selectedStatement.value || !selectedEmployee.value) return;
    const [{jsPDF}, {default:autoTable}] = await Promise.all([import('jspdf'), import('jspdf-autotable')]);
    const doc = new jsPDF({unit:'mm',format:'a4'});
    doc.setFontSize(16); doc.text('Khulna Agricultural University', 14, 20);
    doc.setFontSize(11); doc.text('Arrear & Deduction - PROVISIONAL STATEMENT',14,28);
    doc.setFontSize(9);
    doc.text(`Employee: ${selectedEmployee.value.name} (${selectedEmployee.value.employee_code})`,14,36);
    doc.text(`Period: ${activePeriod.value?.name ?? ''} | Status: ${selectedStatement.value.status}`,14,42);
    const summary = selectedStatement.value.months.map(m => [
        `${m.year}-${String(m.month).padStart(2,'0')}`,
        String(m.entitled_basic ?? 'Missing'), String(m.paid?.paid_basic ?? 'Missing'),
        String(m.calculated?.adjusted_basic_arrear ?? 'Blocked'),
        String(m.calculated?.special_benefit_recovery ?? 'Blocked'),
        String(m.calculated?.house_rent_adjustment ?? 'Blocked'),
        String(m.calculated?.additional_gpf ?? 'Blocked'),
        String(m.calculated?.net_arrear ?? 'Blocked'),
    ]);
    autoTable(doc,{startY:48,head:[['Month','Revised Basic','Paid Basic','Basic Difference','Special','House Rent +/-','GPF','Net']],body:summary,styles:{fontSize:7,cellPadding:2},theme:'grid'});
    const y = doc.lastAutoTable.finalY + 9;
    doc.setFontSize(11); doc.text(`Provisional Net Arrear: BDT ${money(selectedStatement.value.net_arrear)}`,14,y);
    const alerts = [...selectedStatement.value.errors,...selectedStatement.value.warnings].join(' | ');
    doc.setFontSize(8); doc.text(doc.splitTextToSize(`Evidence/Warnings: ${alerts || 'No recorded blockers'}`,180),14,y+8);
    doc.text('NOT A PAYSLIP. NO GPF/BANK/ARREAR DISBURSEMENT POSTED.',14,285);
    doc.save(`KAU_Arrear_Draft_${selectedEmployee.value.employee_code}.pdf`);
}
function csv(value) { return '"' + String(value ?? '').replace(/^[=+@-]/, x => "'"+x).replaceAll('"','""') + '"'; }
function exportCSV() {
    const keys=['employee_code','name','status','net_arrear','errors','warnings'];
    const rows=[keys.map(csv).join(','),...records.value.map(r=>keys.map(k=>csv(Array.isArray(r[k])?r[k].join(' | '):r[k])).join(','))];
    const blob=new Blob(['\uFEFF'+rows.join('\r\n')],{type:'text/csv;charset=utf-8'});
    const u=URL.createObjectURL(blob);const a=document.createElement('a');a.href=u;a.download='KAU_Arrear_Preview_Page.csv';a.click();URL.revokeObjectURL(u);
}
onMounted(loadPeriods);
</script>

<template>
    <Head title="Arrear & Deduction Reconciliation" />
    <AuthenticatedLayout>
        <div class="max-w-7xl mx-auto p-5 space-y-5">
            <div>
                <h1 class="text-2xl font-semibold text-slate-800">Arrear & Deduction Reconciliation</h1>
                <p class="text-sm text-amber-700">PROVISIONAL — Effective-dated arrears are separate from regular salary and GPF ledger. No payable bills are generated here.</p>
            </div>
            <p v-if="error" class="p-3 bg-red-50 text-red-800 rounded-lg">{{ error }}</p>
            <p v-if="success" class="p-3 bg-green-50 text-green-800 rounded-lg">{{ success }}</p>
            <div class="bg-white rounded-xl shadow-sm p-4 space-y-4">
                <h2 class="font-semibold">1. Arrear period / HR entitlement policy</h2>
                <div class="flex flex-wrap gap-3 items-end">
                    <label class="text-sm">Choose period<select v-model.number="selected" @change="selectPeriod(selected)" class="block rounded border mt-1"><option v-for="p in periods" :key="p.id" :value="p.id">#{{ p.id }} {{ p.name }} ({{ p.policy_status }})</option></select></label>
                    <label class="text-sm">New period title<input v-model="draftPeriod.name" class="block rounded border mt-1" /></label>
                    <label class="text-sm">From<input v-model="draftPeriod.entitlement_start" type="date" class="block rounded border mt-1" /></label>
                    <label class="text-sm">Through<input v-model="draftPeriod.entitlement_end" type="date" class="block rounded border mt-1" /></label>
                    <button class="px-4 py-2 rounded bg-slate-700 text-white" :disabled="loading" @click="createPeriod">Create draft period</button>
                </div>
                <div v-if="activePeriod" class="border-t pt-4 space-y-3">
                    <p class="text-xs text-amber-700">Confirm rules only from an official approved HR order. Regular 2026-scale activation is fixed at 2027-07-01; this form does not change it.</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                        <label>Special Benefit<select v-model="policy.special_benefit_policy" class="block border rounded w-full"><option value="unconfirmed">Not decided</option><option value="recover_paid">Recover actual paid</option><option value="retain">Retain paid</option></select></label>
                        <label>House Rent<select v-model="policy.house_rent_policy" class="block border rounded w-full"><option value="unconfirmed">Not decided</option><option value="reconcile">Reconcile revised entitlement</option><option value="none">No adjustment</option></select></label>
                        <label>GPF<select v-model="policy.gpf_policy" class="block border rounded w-full"><option value="unconfirmed">Not decided</option><option value="reconcile">Reconcile contribution</option><option value="none">No adjustment</option></select></label>
                        <label>GPF rounding<select v-model="policy.gpf_rounding" class="block border rounded w-full"><option value="unconfirmed">Not decided</option><option value="truncate_taka">Truncate whole taka</option><option value="round_taka">Round whole taka</option><option value="paisa">Keep paisa</option></select></label>
                        <label>Default GPF % (only when verified)<input v-model.number="policy.gpf_rate_percent" type="number" step="0.001" class="block border rounded w-full" /></label>
                        <label>Planned payment month<input v-model="policy.planned_payment_month" type="date" class="block border rounded w-full" /></label>
                        <label>Policy state<select v-model="policy.policy_status" class="block border rounded w-full"><option value="draft">Draft / no entitlement</option><option value="confirmed">HR-confirmed with evidence</option></select></label>
                    </div>
                    <label class="block text-sm">HR policy order / evidence reference<input v-model="policy.policy_evidence" class="block border rounded w-full" placeholder="Approved circular number/document reference" /></label>
                    <button class="px-4 py-2 rounded bg-emerald-700 text-white" :disabled="loading" @click="savePolicy">Save policy</button>
                </div>
            </div>
            <div v-if="activePeriod" class="bg-white rounded-xl p-4 space-y-3 shadow-sm">
                <h2 class="font-semibold">3. Employee Arrear Calculator (canonical backend)</h2>
                <p class="text-xs text-slate-600">Amounts are always calculated by the backend SalaryStructuredArrearService; the UI never recalculates arrears. Local edits are provisional only &mdash; they are never submitted as verified amounts.</p>
                <p class="text-xs text-red-700 font-medium">Structured five-component preview &mdash; this is NOT the legacy draft snapshot and is not financially equivalent to it. Legacy snapshots use SalaryArrearCalculator; this preview uses SalaryStructuredArrearService.</p>
                <div class="flex flex-wrap gap-2 items-center">
                    <input v-model="structuredSearch" placeholder="Search employee (name or code)" class="border rounded" />
                    <select class="border rounded" :disabled="loading" @change="calculateArrear(structuredCandidates.find(p => p.profile_id === Number($event.target.value)))">
                        <option value="">Select employee&hellip;</option>
                        <option v-for="p in structuredCandidates" :key="p.profile_id" :value="p.profile_id">{{ p.name }} ({{ p.employee_code }})</option>
                    </select>
                    <button class="px-4 py-2 rounded bg-emerald-700 text-white" :disabled="loading || !structured" @click="calculateArrear(selectedEmployee)">Refresh</button>
                    <button class="px-4 py-2 rounded bg-slate-600 text-white" :disabled="loading" @click="resetCalculator">Reset</button>
                </div>
                <div v-if="structured" class="space-y-2">
                    <p class="text-xs" :class="structured.verified ? 'text-emerald-700' : 'text-amber-700'">
                        Source: {{ structured.user_name }} ({{ structured.employee_code }}) &middot; {{ structured.verified ? 'Source verified' : 'Source contains unverified/pending historical evidence' }}
                        <span v-if="structured.conflict"> &middot; <strong class="text-red-700">Source conflict &mdash; amounts suppressed pending review</strong></span>
                    </p>
                    <div class="overflow-auto"><table class="w-full text-sm border">
                        <thead class="bg-slate-100"><tr>
                            <th class="p-2 text-left">Component</th><th class="p-2 text-left">Direction</th>
                            <th class="p-2 text-right">Calculated</th><th class="p-2 text-right">Proposed</th>
                            <th class="p-2 text-left">Verification</th><th class="p-2 text-left">Formula / Source</th>
                            <th class="p-2 text-left">Approval</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="c in structured.components" :key="c.code" class="border-t align-top">
                                <td class="p-2">{{ codeLabel(c.code) }}</td>
                                <td class="p-2"><span :class="c.direction==='payable' ? 'text-emerald-700' : 'text-red-700'">{{ c.direction }}</span></td>
                                <td class="p-2 text-right">
                                    <span v-if="c.missing_evidence" class="text-amber-700">0.00 <div class="text-[10px]">Unverified &mdash; Source required</div></span>
                                    <span v-else>{{ money(c.calculated_amount) }}</span>
                                </td>
                                <td class="p-2 text-right">
                                    <input type="number" step="0.01" min="0" class="border rounded w-28 text-right"
                                        :value="editFor(c)" :disabled="c.missing_evidence"
                                        @input="setEdit(c, $event.target.value)" />
                                    <div v-if="componentEdits[c.code] !== undefined && editFor(c) !== componentAmount(c)" class="text-[10px] text-amber-700 font-semibold">Not saved (provisional)</div>
                                    <div v-if="c.missing_evidence" class="text-[10px] text-amber-700">Source evidence required before a proposed amount can be validated.</div>
                                </td>
                                <td class="p-2 text-xs">{{ c.source_status }}<div v-if="c.payment_verified !== undefined" class="text-[10px] text-slate-500">payment: {{ c.payment_verified ? 'verified' : 'unverified' }}</div></td>
                                <td class="p-2 text-xs">{{ c.formula || '—' }}<div v-if="sourceMonths(c).length" class="text-[10px] text-slate-500">months: {{ sourceMonths(c).map(monthLabel).join(', ') }}</div></td>
                                <td class="p-2"><span :class="c.approval_eligible ? 'text-emerald-700' : 'text-amber-700'">{{ c.approval_eligible ? 'eligible' : 'not eligible' }}</span></td>
                            </tr>
                        </tbody>
                    </table></div>
                    <div class="flex flex-wrap gap-4 text-sm border-t pt-2">
                        <span>Provisional payable: <strong>{{ money(structuredTotals.payable) }}</strong></span>
                        <span>Provisional deduction: <strong class="text-red-700">{{ money(structuredTotals.deduction) }}</strong></span>
                        <span>Provisional net: <strong>{{ money(structuredTotals.net) }}</strong></span>
                        <span class="text-xs text-amber-700 self-center">Provisional totals include local edits and are not equivalent to any saved draft until reconciled and persisted.</span>
                    </div>
                </div>
            </div>
            <div v-if="activePeriod" class="bg-white rounded-xl p-4 space-y-3 shadow-sm">
                <h2 class="font-semibold">4. Employee-wise read-only preview</h2>
                <div class="flex flex-wrap gap-3 items-center"><input v-model="filter" placeholder="Name or employee code" class="border rounded" /><button class="px-4 py-2 rounded bg-emerald-600 text-white" :disabled="loading" @click="page=1;loadPreview()">Calculate Draft</button><button class="border rounded px-4 py-2" :disabled="records.length===0" @click="exportCSV">Export page CSV</button><span class="text-xs text-slate-600">{{ totalProfiles }} profiles</span></div>
                <div class="overflow-auto"><table class="w-full text-sm"><thead class="bg-slate-100"><tr><th class="p-2 text-left">Employee</th><th class="p-2">Status</th><th class="p-2 text-right">Provisional Net</th><th class="p-2">Evidence problems</th><th class="p-2">Action</th></tr></thead><tbody><tr v-for="row in records" :key="row.profile_id" class="border-t"><td class="p-2">{{ row.name }}<div class="text-xs text-gray-500">{{ row.employee_code }}</div></td><td class="p-2">{{ row.status }}</td><td class="p-2 text-right">{{ money(row.net_arrear) }}</td><td class="p-2 text-xs text-red-800">{{ row.errors.join(' | ') || row.warnings.join(' | ') }}</td><td class="p-2"><button class="text-emerald-700 underline" @click="openStatement(row)">Details / Manual</button></td></tr></tbody></table></div>
                <div class="flex gap-3 items-center"><button :disabled="page<=1||loading" class="border rounded px-3 py-1" @click="page--;loadPreview()">Previous</button><span>{{ page }} / {{ lastPage }}</span><button :disabled="page>=lastPage||loading" class="border rounded px-3 py-1" @click="page++;loadPreview()">Next</button></div>
            </div>
            <div v-if="selectedStatement && selectedEmployee" class="bg-white rounded-xl p-4 space-y-5 shadow-sm">
                <div class="flex flex-wrap justify-between items-start"><div><h2 class="font-semibold text-lg">3. {{ selectedEmployee.name }} — {{ selectedEmployee.employee_code }}</h2><p class="text-sm">{{ selectedStatement.status }} · Net Arrear: <strong>{{ money(selectedStatement.net_arrear) }}</strong></p></div><button class="border rounded px-4 py-2" @click="pdfStatement">Download PDF statement</button></div>
                <p class="text-sm text-red-700">{{ selectedStatement.errors.join(' | ') }}</p><p class="text-xs text-amber-800">{{ selectedStatement.warnings.join(' | ') }}</p>
                <div class="overflow-auto"><table class="w-full text-xs"><thead class="bg-slate-100"><tr><th class="p-2">Month</th><th class="p-2">Paid Basic</th><th class="p-2">Revised Basic</th><th class="p-2">Gross Basic</th><th class="p-2">Old Increment</th><th class="p-2">Benefit Recovery</th><th class="p-2">House Rent +/-</th><th class="p-2">GPF</th><th class="p-2">Net</th></tr></thead><tbody><tr v-for="m in selectedStatement.months" :key="`${m.year}-${m.month}`" class="border-t"><td class="p-2">{{ m.year }}-{{ String(m.month).padStart(2,'0') }}</td><td class="p-2">{{ money(m.paid?.paid_basic) }}</td><td class="p-2">{{ money(m.entitled_basic) }}</td><td class="p-2">{{ money(m.calculated?.gross_basic_arrear) }}</td><td class="p-2">{{ money(m.calculated?.old_increment_adjustment) }}</td><td class="p-2">{{ money(m.calculated?.special_benefit_recovery) }}</td><td class="p-2">{{ money(m.calculated?.house_rent_adjustment) }}</td><td class="p-2">{{ money(m.calculated?.additional_gpf) }}</td><td class="p-2 font-semibold">{{ money(m.calculated?.net_arrear) }}</td></tr></tbody></table></div>
                <div class="border-t pt-4 space-y-2">
                    <h3 class="font-medium">Verify historical salary disbursement (bank/HR record)</h3>
                    <p class="text-xs text-amber-700">Approved generated bill ≠ confirmed bank payment. Enter exact historical salary_generate ID and evidence.</p>
                    <div class="flex flex-wrap gap-2 items-center">
                        <input v-model.number="payment.year" type="number" class="border rounded w-24" />
                        <input v-model.number="payment.month" type="number" min="1" max="12" class="border rounded w-20" />
                        <input v-model.number="payment.salary_generate_id" type="number" placeholder="Bill ID" class="border rounded w-28" />
                        <input v-model="payment.evidence_reference" placeholder="Bank advice/document reference" class="border rounded flex-1" />
                        <input v-model="payment.reason" placeholder="Certification note" class="border rounded flex-1" />
                        <button class="rounded bg-slate-700 px-4 py-2 text-white" :disabled="loading" @click="certifyPayment">Certify paid record</button>
                    </div>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 border-t pt-4">
                    <div class="space-y-2"><h3 class="font-medium">Evidence-backed source / entitlement correction</h3><p class="text-xs text-red-700">Never override an existing payroll amount silently. A missing paid month requires all four certified source fields.</p><div class="flex gap-2"><input v-model.number="source.year" type="number" class="border rounded w-28" /><input v-model.number="source.month" type="number" min="1" max="12" class="border rounded w-20" /></div><select v-model="source.field" class="border rounded w-full"><option v-for="field in ['paid_basic','paid_special_benefit','paid_house_rent','paid_gpf','revised_house_rent','revised_gpf','gpf_rate_percent']" :key="field" :value="field">{{ field }}</option></select><input v-model.number="source.amount" type="number" min="0" step="0.01" placeholder="Amount" class="border rounded w-full" /><input v-model="source.reason" placeholder="Reason" class="border rounded w-full" /><input v-model="source.evidence_reference" placeholder="Source PDF / order number" class="border rounded w-full" /><label class="block text-sm"><input v-model="source.certified" type="checkbox" /> HR certifies the evidence</label><button class="rounded bg-slate-700 text-white px-4 py-2" :disabled="loading" @click="saveSource">Save source (audit history)</button></div>
                    <div class="space-y-2"><h3 class="font-medium">Titled Manual Addition / Deduction</h3><div class="flex gap-2"><input v-model.number="manual.year" type="number" class="border rounded w-28" /><input v-model.number="manual.month" type="number" min="1" max="12" class="border rounded w-20" /></div><input v-model="manual.title" placeholder="Adjustment title" class="border rounded w-full" /><select v-model="manual.direction" class="border rounded w-full"><option value="addition">Addition</option><option value="deduction">Deduction</option></select><input v-model.number="manual.amount" type="number" min="0" step="0.01" placeholder="Amount" class="border rounded w-full" /><input v-model="manual.reason" placeholder="Reason" class="border rounded w-full" /><input v-model="manual.evidence_reference" placeholder="Evidence reference" class="border rounded w-full" /><button class="rounded bg-slate-700 text-white px-4 py-2" :disabled="loading" @click="addManual">Add audited item</button></div>
                </div>
                <div class="border-t pt-4 space-y-3"><h3 class="font-semibold">4. Versioned Review (no payment)</h3><div class="flex flex-wrap gap-3"><button class="rounded bg-emerald-700 text-white px-4 py-2" :disabled="loading||selectedStatement.status==='BLOCKED'" @click="saveSnapshot">Save draft snapshot</button><button class="rounded bg-slate-700 text-white px-4 py-2" :disabled="!snapshotId||loading" @click="sendReview">Mark sent for review</button></div><p v-if="snapshotId" class="text-xs">Snapshot #{{ snapshotId }}. Employee confirmation is recorded using the authenticated review API.</p><input v-model="approvalNote" class="border rounded w-full" placeholder="HR approval reason (only evidence-complete, reconciled amounts)" /><button class="rounded bg-amber-700 text-white px-4 py-2" :disabled="!snapshotId||loading" @click="approve">HR Approve Snapshot (NOT disbursement)</button></div>
                    <div class="border-t pt-4 space-y-2">
                        <h3 class="font-semibold">5. Post approved arrear to an existing pending salary bill</h3>
                        <p class="text-xs text-amber-800">Staging verification required. Both IDs must already exist; posting is atomic and cannot pay the bank. It appends itemized arrears and a separate supplementary GPF adjustment ledger, without changing historical GPF entries.</p>
                        <div class="flex flex-wrap gap-2 items-center">
                            <input v-model.number="postSnapshotId" type="number" min="1" placeholder="Approved Snapshot ID" class="border rounded w-52" />
                            <input v-model.number="postSalaryGenerateId" type="number" min="1" placeholder="Pending Salary Bill ID" class="border rounded w-52" />
                            <button class="rounded bg-rose-700 text-white px-4 py-2" :disabled="loading||!postSnapshotId||!postSalaryGenerateId" @click="postApprovedArrear">Post Approved Arrear to Bill</button>
                        </div>
                    </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
