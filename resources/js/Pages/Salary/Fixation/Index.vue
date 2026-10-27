<script setup>
import '@/fixation-pdf.js';
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    employees: { type: Object, required: true },
    filters: { type: Object, required: true },
    effectiveDate: { type: String, required: true },
    ruleReference: { type: String, required: true },
    departmentOffices: { type: Object, default: () => [] },
    userCategories: { type: Object, default: () => [] },
    payrollGroups: { type: Object, default: () => [] },
});
const search = ref(props.filters.search || '');
const incrementStatus = ref(props.filters.increment_status || 'all');
const fixationStatus = ref(props.filters.fixation_status || 'all');
const tableRowsPerPage = ref(props.filters.per_page || 10);
const departmentOfficeId = ref(props.filters.department_office_id || '');
const userCategoryId = ref(props.filters.user_category_id || '');
const payrollGroupId = ref(props.filters.payroll_group_id || '');
const reportRowsPerPage = ref(15);
const validatedReportRows = computed(() => {
    const n = Number(reportRowsPerPage.value);
    return Number.isInteger(n) && n >= 1 && n <= 15 ? n : 15;
});
const reportFactoryUrl = (ids = []) => {
    const query = new URLSearchParams({reportType:'Salary Fixation Report', fixationRowsPerPage:String(validatedReportRows.value)});
    if (ids.length) query.set('fixationUserIds', ids.join(','));
    return route('reports.salary-reports.index') + '?' + query.toString();
};
// Track selected employees' actual saved state across pagination. These maps
// are persistent: every page load merges its rows into the existing maps so
// selections made on earlier pages keep their saved-fixation id and status.
const selectionStatus = ref({});
const savedFixationIds = ref({});
watch(() => props.employees.data, rows => {
    const nextStatus = {...selectionStatus.value};
    const nextIds = {...savedFixationIds.value};
    for (const e of rows) {
        nextStatus[e.id] = e.saved_status || null;
        if (e.saved_id) nextIds[e.id] = e.saved_id;
    }
    selectionStatus.value = nextStatus;
    savedFixationIds.value = nextIds;
}, {immediate:true});
const selectedFixationReportIds = computed(() => [...new Set(selectedIds.value.map(Number).filter(id => Number.isSafeInteger(id) && id > 0))]);
function openSelectedReports() {
    if (!selectedFixationReportIds.value.length) return;
    const url = reportFactoryUrl(selectedFixationReportIds.value) + '&fixationAutoPdf=1';
    // The Report Factory still renders the canonical bulk statement, but it
    // does so in an invisible same-origin frame. Keep this page and selection.
    const previous = document.getElementById('kau-fixation-bulk-download-frame');
    if (previous) previous.remove();
    const frame = document.createElement('iframe');
    frame.id = 'kau-fixation-bulk-download-frame';
    frame.title = 'Preparing selected fixation PDF';
    frame.style.cssText = 'position:fixed;width:1px;height:1px;opacity:0;pointer-events:none;left:-10000px;top:0;border:0';
    document.body.appendChild(frame);
    frame.src = url;
}
const busyId = ref(null);
const selectedIds = ref([]);
const bulkReason = ref('');
const allOnPageSelected = computed(() => props.employees.data.length > 0 && props.employees.data.every(e => selectedIds.value.includes(e.id)));
const selectedOnPageCount = computed(() => props.employees.data.filter(e => selectedIds.value.includes(e.id)).length);
// Total eligible saved selections across ALL pages (persistent map), for the
// bulk-saved button label: selected, has a saved snapshot, and not yet approved.
const eligibleSavedSelectionCount = computed(() => selectedIds.value
    .filter(id => Number.isInteger(id) && selectionStatus.value[id] && selectionStatus.value[id] !== 'approved' && savedFixationIds.value[id])
    .length);
function togglePage(checked) {
    const ids = props.employees.data.map(e => e.id);
    selectedIds.value = checked ? [...new Set([...selectedIds.value, ...ids])] : selectedIds.value.filter(id => !ids.includes(id));
}
function bulkDecision(decision) {
    const visible = new Set(props.employees.data.map(e => e.id));
    const ids = selectedIds.value.filter(id => visible.has(id));
    if (!ids.length || busyId.value !== null) return;
    if (!confirm(`${decision === 'approve' ? 'Approve' : 'Reject'} fixation for ${ids.length} selected employees?`)) return;
    busyId.value = 'bulk';
    router.post(route('salary-fixation.bulk-decision'), { decision, user_ids: ids, reason: bulkReason.value }, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = []; },
        onFinish: () => { busyId.value = null; },
    });
}

// Bulk approve already-saved fixations through the exact same approveSaved
// workflow as the single-record button: no recalculation of any fixation.
// Uses the persistent cross-page saved-fixation map so employees selected on
// earlier pages are still included in the approval set.
function bulkApproveSavedFixations() {
    const ids = selectedIds.value
        .filter(id => Number.isInteger(id) && selectionStatus.value[id] && selectionStatus.value[id] !== 'approved' && savedFixationIds.value[id])
        .map(id => Number(savedFixationIds.value[id]))
        .filter((id, i, arr) => Number.isSafeInteger(id) && id > 0 && arr.indexOf(id) === i);
    if (!ids.length || busyId.value !== null) return;
    if (!confirm(`Approve ${ids.length} saved fixation snapshot(s) as-is? Values are NOT recalculated; only the existing saved snapshots are approved.`)) return;
    busyId.value = 'bulk-saved';
    router.post(route('salary-fixation.bulk-approve-saved'), { fixation_ids: ids }, {
        preserveScroll: true,
        onSuccess: () => {
            // Clear only after a confirmed result; selection survives errors.
            selectedIds.value = [];
        },
        onError: () => {
            // Keep selections so the user can retry after validation errors.
        },
        onFinish: () => { busyId.value = null; },
    });
}


function printSingleFixation(employee) {
    const url = employee.saved_id
        ? route('salary-fixation.admin-print', employee.saved_id)
        : route('salary-fixation.preview', employee.id);

    const printFunction = window.KAU_PRINT_CANONICAL_FIXATION;

    if (typeof printFunction !== 'function') {
        alert('Fixation PDF print service is not available.');
        return;
    }

    printFunction(url);
}


function reject(employee) {
    if (busyId.value !== null || !confirm(`Reject ${employee.name}'s fixation and deactivate the current revised pay schedule?`)) return;
    busyId.value = employee.id;
    router.post(route('salary-fixation.reject', employee.id), {reason: bulkReason.value}, {
        preserveScroll: true, onFinish: () => busyId.value = null,
    });
}
const adjustmentEmployee = ref(null);
const manualForm = ref({
    increment_2026:'automatic', increment_2027:'automatic',
    old_basic_override:'', personal_pay_override:'', grade_override:'', baseline_period:'2026-06',
    additional_fields:[], evidence_reference:'', reason:'',
});
function openAdjustment(employee) {
    adjustmentEmployee.value = employee;
    const a = employee.adjustment || {};
    manualForm.value = {
        increment_2026: a.increment_2026 || 'automatic',
        increment_2027: a.increment_2027 || 'automatic',
        old_basic_override: a.old_basic_override ?? '',
        personal_pay_override: a.personal_pay_override ?? '',
        grade_override: a.grade_override ?? '',
        baseline_period: a.baseline_period || '2026-06',
        additional_fields: Array.isArray(a.additional_fields) ? a.additional_fields.map(x => ({...x, direction:x.direction || 'addition'})) : [],
        evidence_reference: a.evidence_reference || '', reason: a.reason || '',
    };
}
function sendReview(employee) {
    if (!employee.saved_id || employee.saved_status !== 'pending_review') return;
    if (!confirm(`Send this saved fixation to ${employee.name} for their personal review?`)) return;
    busyId.value = employee.id;
    router.post(route('salary-fixation.send-review', employee.saved_id), {}, {preserveScroll:true, onFinish:()=>busyId.value=null});
}
function approve(employee) {
    if (!employee.saved_id || !['pending_review', 'sent', 'employee_confirmed', 'employee_disputed', 'approved'].includes(employee.saved_status)) return;
    if (!confirm(`Approve ${employee.name}'s saved fixation now? Employee confirmation is optional. HR should review any warnings before proceeding.`)) return;
    busyId.value = employee.id;
    router.post(route('salary-fixation.approve', employee.saved_id), {}, {preserveScroll:true, onFinish:()=>busyId.value=null});
}
function approveNow(employee) {
    if (!employee.can_calculate || busyId.value !== null) return;
    if (!confirm(`Approve ${employee.name}'s calculated fixation now? This creates an auditable HR-approved version. Employee confirmation is not required; existing salary bills will not change.`)) return;
    busyId.value = employee.id;
    router.post(route('salary-fixation.approve-now', employee.id), {}, {
        preserveScroll: true,
        onFinish: () => { busyId.value = null; },
    });
}
function saveAdjustment() {
    if (!adjustmentEmployee.value) return;
    busyId.value = adjustmentEmployee.value.id;
    router.post(route('salary-fixation.adjustment', adjustmentEmployee.value.id), manualForm.value, {
        preserveScroll:true,
        onSuccess:()=>adjustmentEmployee.value=null,
        onFinish:()=>busyId.value=null,
    });
}

const money = (value) => value === null || value === undefined ? '—' : '৳' + Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const doSearch = () => router.get(route('salary-fixation.index'), {
    search: search.value, increment_status: incrementStatus.value,
    fixation_status: fixationStatus.value, per_page: tableRowsPerPage.value,
    department_office_id: departmentOfficeId.value || '',
    user_category_id: userCategoryId.value || '',
    payroll_group_id: payrollGroupId.value || '',
}, { preserveState: true, replace: true });
const save = (employee) => {
    if (!confirm('Save an audit-only fixation snapshot for ' + employee.name + '? This will NOT change their monthly salary or past payroll.')) return;
    busyId.value = employee.id;
    router.post(route('salary-fixation.store', employee.id), {}, {
        preserveScroll: true,
        onFinish: () => { busyId.value = null; },
    });
};




</script>

<template>
    <Head title="Automated Salary Fixation" />
    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Automated Pay Fixation · 2026</h1>
                    <p class="text-sm text-slate-600 dark:text-slate-300 mt-1">Historic pay and grades are retrieved from employee records, not manually selected.</p>
                    <p class="text-xs text-slate-500 mt-1">{{ ruleReference }} · Effective {{ effectiveDate }}</p>
                </div>
                <Link :href="route('salary-setup.index')" class="px-3 py-2 text-sm rounded-lg bg-slate-800 text-white hover:bg-slate-700">Salary setup</Link>
            </div>
            <!-- <div class="border border-amber-300 bg-amber-50 rounded-xl p-4 text-sm text-amber-950 space-y-2">
                <p class="font-bold">Direct fixation management: save, approve, reject or bulk-approve without employee confirmation.</p>
                <p>The supplied HTML is a formula demonstration and the database may not retain pay as of 30 June 2026. Historical pay, PP, increment holds and service exceptions should be reviewed by HR before approval. Any signed-in administrator may approve a calculable fixation immediately, even when review notes remain; review those notes before confirming the amounts. Approval does not change past salary bills, and effective-dated basic pay applies only for the configured payroll period. Historical salary bills are not automatically changed, and there is no official iBAS++ connection.</p>
                <p>Data order: June 2026 saved salary → current employee salary components (flagged as unverified). Original PDFs and 2026 database stages remain visible for reference; only actual calculable Basic and Grade values are required to create a fixation. Use Print / Save as PDF or the multi-page PDF download.</p>
            </div> -->
            <div v-if="$page.props.flash?.success" class="p-3 bg-emerald-50 border border-emerald-300 rounded-lg text-emerald-900 text-sm">{{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-3 bg-red-50 border border-red-300 rounded-lg text-red-900 text-sm">{{ $page.props.flash.error }}</div>
            <form @submit.prevent="doSearch" class="bg-white dark:bg-gray-800 p-4 border rounded-xl space-y-3">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <input v-model="search" type="search" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white" placeholder="Search by employee name or code" aria-label="Search employees" />
                    <label class="flex flex-col text-xs text-slate-700 dark:text-white gap-1"><span>Increment / Eligibility Status</span>
                      <select v-model="incrementStatus" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white text-sm" aria-label="Increment eligibility filter">
                        <option value="all">All Employees</option>
                        <option value="increment_available">Increment Available</option>
                        <option value="increment_hold">Increment Hold</option>
                        <option value="adhoc">AD HOC</option>
                        <option value="study_leave_with">Study Leave With Increment</option>
                        <option value="study_leave_without">Study Leave Without Increment</option>
                        <option value="other_no_increment">Other / No Increment</option>
                      </select>
                    </label>
                    <label class="flex flex-col text-xs text-slate-700 dark:text-white gap-1"><span>Fixation Status</span>
                      <select v-model="fixationStatus" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white text-sm" aria-label="Fixation workflow status filter">
                        <option value="all">All Statuses</option>
                        <option value="needs_review">Needs Review</option>
                        <option value="blocked">Blocked</option>
                        <option value="provisional">Provisional</option>
                        <option value="approved">Approved</option>
                      </select>
                    </label>
                    <label class="flex flex-col text-xs text-slate-700 dark:text-white gap-1"><span>Department / Office</span>
                      <select v-model="departmentOfficeId" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white text-sm" aria-label="Department or office filter">
                        <option value="">All Departments &amp; Offices</option>
                        <option v-for="office in props.departmentOffices" :key="office.id" :value="office.id">{{ office.name }}</option>
                      </select>
                    </label>
                    <label class="flex flex-col text-xs text-slate-700 dark:text-white gap-1"><span>Staff Category</span>
                      <select v-model="userCategoryId" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white text-sm" aria-label="Staff category filter">
                        <option value="">All Categories</option>
                        <option v-for="cat in props.userCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                      </select>
                    </label>
                    <label class="flex flex-col text-xs text-slate-700 dark:text-white gap-1"><span>Payroll Group</span>
                      <select v-model="payrollGroupId" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white text-sm" aria-label="Payroll group filter">
                        <option value="">All Payroll Groups</option>
                        <option v-for="group in props.payrollGroups" :key="group.id" :value="group.id">
                          {{ group.grade?.grade_name ?? 'Group' + group.id }}{{ group.payrollGroupType ? ' — ' + group.payrollGroupType.group_name : '' }}
                        </option>
                      </select>
                    </label>
                </div>
                <div class="flex flex-wrap gap-3">
                    <label class="flex flex-col text-xs text-slate-700 dark:text-white gap-1"><span>List employees/page</span>
                      <select v-model.number="tableRowsPerPage" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white text-sm">
                        <option :value="10">10</option><option :value="15">15</option>
                        <option :value="25">25</option><option :value="50">50</option>
                      </select>
                    </label>
                    <button class="bg-emerald-700 hover:bg-emerald-600 text-white px-5 py-2 rounded-lg self-end">Search</button>
                    <Link :href="route('salary-fixation.index')" class="px-4 py-2 rounded-lg border border-slate-300 dark:text-white self-end">Reset</Link>
                </div>
            </form>
            <div v-if="$page.props.flash?.fixation_bulk_errors?.length" class="p-3 bg-amber-50 border border-amber-300 rounded-lg text-sm text-amber-900">
                <strong>Bulk items needing input:</strong>
                <ul class="list-disc pl-5"><li v-for="(problem, i) in $page.props.flash.fixation_bulk_errors" :key="i">{{ problem }}</li></ul>
            </div>
            <div class="rounded-xl border bg-white dark:bg-gray-800 p-3 flex flex-wrap items-center gap-3">
                <label class="text-sm flex items-center gap-2 dark:text-white"><input type="checkbox" :checked="allOnPageSelected" @change="togglePage($event.target.checked)" /> Select current page ({{ selectedOnPageCount }})</label>
                <button type="button" @click="bulkApproveSavedFixations()" :disabled="!eligibleSavedSelectionCount || busyId!==null" class="bg-emerald-800 text-white rounded-lg px-3 py-2 text-sm disabled:opacity-40">Bulk Approve Saved ({{ eligibleSavedSelectionCount }} eligible, as-is, no recalculation)</button>
                <button type="button" @click="bulkDecision('approve')" :disabled="!selectedOnPageCount || busyId!==null" class="bg-emerald-700 text-white rounded-lg px-3 py-2 text-sm disabled:opacity-40">Bulk Approve</button>
                <button type="button" @click="bulkDecision('reject')" :disabled="!selectedOnPageCount || busyId!==null" class="bg-rose-700 text-white rounded-lg px-3 py-2 text-sm disabled:opacity-40">Bulk Reject</button>
                <input v-model="bulkReason" maxlength="2000" placeholder="Optional rejection note" class="rounded-lg border-gray-300 text-sm min-w-52 flex-1" />
                <div class="flex items-center gap-2 flex-wrap border-l pl-3 ml-auto border-slate-300">
                  <label class="text-xs text-slate-600 dark:text-white">Report employees/page
                    <select v-model.number="reportRowsPerPage" class="ml-1 text-sm border-slate-300 rounded">
                      <option :value="10">10</option><option :value="15">15</option>
                    </select>
                  </label>
                  <label class="text-xs dark:text-white">Custom (1–15)
                    <input v-model.number="reportRowsPerPage" type="number" min="1" max="15" step="1"
                      class="ml-1 w-16 text-sm rounded border-slate-300" :aria-invalid="Number(reportRowsPerPage)<1 || Number(reportRowsPerPage)>15 || !Number.isInteger(Number(reportRowsPerPage))" />
                  </label>
                  <button type="button" @click="openSelectedReports" :disabled="!selectedFixationReportIds.length" class="bg-blue-800 text-white rounded px-3 py-2 text-sm disabled:opacity-50">Download Selected Fixation PDF</button>
                </div>
                <span class="text-xs text-slate-500">Applies to selected rows on this page; changes are logged.</span>
            </div>
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:bg-gray-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-100 dark:bg-gray-700 text-left text-slate-800 dark:text-white">
                        <tr>
                            <th class="px-3 py-3"><span class="sr-only">Select</span></th><th class="px-4 py-3">Employee / Code</th><th class="px-4 py-3">Grade</th><th class="px-4 py-3">30 Jun 2026 basic source</th><th class="px-4 py-3">Increment</th><th class="px-4 py-3">July 2026</th><th class="px-4 py-3">Jan 2027</th><th class="px-4 py-3">July 2027</th><th class="px-4 py-3">Warnings / Status</th><th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                        <tr v-for="e in employees.data" :key="e.id" class="text-slate-900 dark:text-white align-top">
                            <td class="px-3 py-3"><input v-model="selectedIds" type="checkbox" :value="e.id" :aria-label="`Select ${e.name}`" /></td>
                            <td class="px-4 py-3"><div class="font-semibold">{{ e.name }}</div><div class="text-xs text-slate-500">{{ e.employee_code }}</div></td>
                            <td class="px-4 py-3">{{ e.grade ?? 'Unknown' }}</td>
                            <td class="px-4 py-3"><div>{{ money(e.current_basic) }}</div><div class="text-xs" :class="['june_2026_payroll','historical_payroll_override'].includes(e.source_type) ? 'text-emerald-700' : 'text-amber-700'">{{ e.source_type === 'june_2026_payroll' ? 'June 2026 snapshot' : e.source_type === 'historical_payroll_override' ? 'Verified month ' + e.baseline_period : 'Current setup / unverified' }}</div></td>
                            <td class="px-4 py-3"><div v-if="e.increment?.amount !== null && e.increment?.amount !== undefined" class="font-semibold text-emerald-700">{{ money(e.increment.amount) }}</div><div class="text-xs" :class="e.increment?.amount != null ? 'text-emerald-700' : 'text-amber-700'">{{ e.increment?.label || 'Not calculated' }}</div></td>
                            <td class="px-4 py-3 font-medium">{{ money(e.phase_2026_07) }}</td>
                            <td class="px-4 py-3">{{ money(e.phase_2027_01) }}</td>
                            <td class="px-4 py-3">{{ money(e.phase_2027_07) }}</td>
                            <td class="px-4 py-3"><div class="text-amber-700">{{ e.warning_count }} review notes</div><div class="text-xs font-medium text-slate-700 dark:text-slate-300">Original PDF match: {{e.reference_match_status}}</div><div v-if="e.saved_id" class="text-xs text-slate-500">Saved #{{ e.saved_id }}: {{ e.saved_status }}</div><div v-if="!e.ready && e.can_calculate" class="text-xs text-amber-700">Data review notes (approval is available)</div><div v-else-if="!e.can_calculate" class="text-xs text-red-700 font-semibold">Enter actual Basic / Grade to calculate</div><ul v-if="e.blockers?.length" class="list-disc pl-4 text-xs text-red-700"><li v-for="(blocker, i) in e.blockers" :key="i">{{ blocker }}</li></ul><details v-if="e.warnings?.length" class="mt-1"><summary class="cursor-pointer text-xs underline">Read notes</summary><ul class="list-disc pl-4 pt-1 text-xs space-y-1"><li v-for="(warning, idx) in e.warnings" :key="idx">{{ warning }}</li></ul></details></td>
                            <td class="px-4 py-3"><div class="flex flex-col gap-2 min-w-[180px]">
                                <!-- <a :href="(e.saved_id ? route('salary-fixation.admin-print', e.saved_id) : route('salary-fixation.preview', e.id)) + '?pdf=download'" target="_blank" rel="noopener" class="text-center rounded-lg bg-blue-700 text-white px-3 py-2 whitespace-nowrap">Download Single Fixation PDF</a> -->

                                <button
                                    type="button"
                                    @click="printSingleFixation(e)"
                                    class="text-center rounded-lg bg-blue-700 text-white px-3 py-2 whitespace-nowrap"
                                >
                                    Download Single Fixation PDF
                                </button>

                                <a :href="e.saved_id ? route('salary-fixation.admin-print', e.saved_id) : route('salary-fixation.preview', e.id)" target="_blank" rel="noopener" class="text-center rounded-lg bg-emerald-700 text-white px-3 py-2 whitespace-nowrap">Preview / Print Form</a>
                                <button v-if="e.can_calculate" :disabled="busyId !== null" @click="save(e)" class="rounded-lg px-3 py-2 whitespace-nowrap bg-slate-800 text-white disabled:opacity-40">Save fixation snapshot</button>
                                <button v-if="e.saved_status === 'pending_review'" :disabled="busyId !== null" @click="sendReview(e)" class="rounded-lg px-3 py-2 whitespace-nowrap border border-blue-300 text-blue-700 disabled:opacity-40">Optional: Send employee review</button>
                                <button v-if="e.saved_id && ['pending_review', 'sent', 'employee_confirmed', 'employee_disputed', 'approved'].includes(e.saved_status)" :disabled="busyId !== null" @click="approve(e)" class="rounded-lg px-3 py-2 whitespace-nowrap bg-emerald-700 text-white disabled:opacity-40">Approve Saved Fixation</button>
                                <button v-if="e.can_calculate" :disabled="busyId !== null" @click="approveNow(e)" class="rounded-lg px-3 py-2 whitespace-nowrap bg-emerald-700 text-white font-semibold disabled:opacity-40">{{ e.saved_status === 'approved' ? 'Recalculate & Re-approve' : 'Approve Now' }}</button>
                                <button type="button" :disabled="busyId !== null || (!e.saved_id && !e.can_calculate)" @click="reject(e)" class="rounded-lg px-3 py-2 whitespace-nowrap bg-rose-700 text-white disabled:opacity-40">Reject</button>
                                <button @click="openAdjustment(e)" class="rounded-lg px-3 py-2 whitespace-nowrap border border-slate-300 dark:border-slate-500 dark:text-white">Manual adjustment / Eligibility</button>
                              </div></td>
                        </tr>
                        <tr v-if="employees.data.length === 0"><td colspan="10" class="px-5 py-10 text-center text-slate-500">No matching employee records.</td></tr>
                    </tbody>
                </table>
            </div>
            <section v-if="adjustmentEmployee" class="fixed inset-0 z-50 bg-black/60 overflow-y-auto p-4" role="dialog" aria-modal="true" aria-label="Fixation adjustment">
              <form @submit.prevent="saveAdjustment" class="bg-white dark:bg-gray-900 text-slate-900 dark:text-white rounded-xl max-w-2xl mx-auto my-8 p-6 space-y-4 shadow-2xl">
                <div class="flex justify-between gap-3 items-center"><h2 class="text-lg font-bold">Manual fixation review — {{adjustmentEmployee.name}} ({{adjustmentEmployee.employee_code}})</h2><button type="button" @click="adjustmentEmployee=null" class="text-2xl" aria-label="Close">×</button></div>
                <p class="text-xs text-amber-700">You can revise calculated sources and add titled additions/deductions. Each change is logged. Approve Now to publish a revised fixation; previously generated salary bills remain unchanged.</p>
                <div class="grid sm:grid-cols-2 gap-3">
                  <label class="text-sm">July 2026 increment
                    <select v-model="manualForm.increment_2026" class="block w-full text-slate-900 rounded-lg"><option value="automatic">Automatic / source list</option><option value="allow">Allow increment</option><option value="hold">Hold increment</option></select>
                  </label>
                  <label class="text-sm">July 2027 increment
                    <select v-model="manualForm.increment_2027" class="block w-full text-slate-900 rounded-lg"><option value="automatic">Automatic / needs verification</option><option value="allow">Allow increment</option><option value="hold">Hold increment</option></select>
                  </label>
                  <label class="text-sm">Historical baseline month (HR-evidenced exception)
                    <input v-model="manualForm.baseline_period" type="month" min="2015-01" max="2026-06" required class="block w-full text-slate-900 rounded-lg" />
                    <small class="block text-amber-700">Normally 2026-06. For Newton, select 2025-10 only with payroll evidence; no employee data is changed.</small>
                  </label>
                  <label class="text-sm">HR Grade override (optional, 1–20)<input v-model="manualForm.grade_override" type="number" min="1" max="20" step="1" placeholder="Use current grade" class="block w-full text-slate-900 rounded-lg" /></label>
                  <label class="text-sm">Verified old basic (optional)<input v-model="manualForm.old_basic_override" type="number" min="0" step="0.01" placeholder="Use historical pay if unchanged" class="block w-full text-slate-900 rounded-lg" /></label>
                  <label class="text-sm">Verified personal pay (optional)<input v-model="manualForm.personal_pay_override" type="number" min="0" step="0.01" placeholder="Leave unchanged" class="block w-full text-slate-900 rounded-lg" /></label>
                </div>
                <div class="space-y-2 border rounded-lg p-3">
                    <div class="flex justify-between items-center"><h3 class="font-bold text-sm">Fixation extras — Addition (+) / Deduction (−)</h3><button type="button" @click="manualForm.additional_fields.push({title:'',direction:'addition',value:0})" :disabled="manualForm.additional_fields.length>=30" class="text-sm px-3 py-1 rounded bg-slate-800 text-white">+ Add field</button></div>
                    <div v-for="(f,i) in manualForm.additional_fields" :key="i" class="flex flex-wrap gap-2 items-center"><input v-model="f.title" required maxlength="120" placeholder="Component title" class="min-w-0 flex-1 rounded text-slate-900"/><select v-model="f.direction" class="rounded text-slate-900"><option value="addition">+ Addition</option><option value="deduction">− Deduction</option></select><input v-model.number="f.value" required type="number" min="0" max="9999999" step="0.01" placeholder="Amount" class="w-32 rounded text-slate-900"/><button type="button" @click="manualForm.additional_fields.splice(i,1)" aria-label="Remove field" class="text-rose-700">×</button></div>
                </div>
                <label class="block text-sm">Reference (optional)<input v-model="manualForm.evidence_reference" maxlength="255" placeholder="e.g. HR Memo 2026/..., signed report ID" class="block w-full text-slate-900 rounded-lg" /></label>
                <label class="block text-sm">Reason / note (optional)<textarea v-model="manualForm.reason" rows="3" class="block w-full text-slate-900 rounded-lg" placeholder="Explain the corrected source and increment entitlement"></textarea></label>
                <div class="flex justify-end gap-2"><button type="button" @click="adjustmentEmployee=null" class="border rounded-lg px-4 py-2">Cancel</button><button :disabled="busyId!==null" class="bg-emerald-700 text-white rounded-lg px-4 py-2 disabled:opacity-50">Save adjustment</button></div>
              </form>
            </section>
            <nav v-if="employees.links?.length > 3" class="flex gap-2 flex-wrap" aria-label="Results pagination">
                <template v-for="(p, i) in employees.links" :key="i">
                    <Link v-if="p.url" :href="p.url" preserve-scroll class="rounded-lg border px-3 py-2 text-sm" :class="p.active ? 'bg-emerald-700 text-white' : 'bg-white dark:bg-gray-800 dark:text-white'" v-html="p.label" />
                    <span v-else class="rounded-lg border px-3 py-2 text-sm opacity-50" v-html="p.label" />
                </template>
            </nav>
        </div>
    </AuthenticatedLayout>
</template>
