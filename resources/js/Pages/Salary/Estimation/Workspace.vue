<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';

const props = defineProps({ rows: Object, counts: Object, runs: Array, year: Number, month: Number, filterOptions: Object, selectableCount: Number });
const routePath = '/dashboard/salary/estimation';
const url = new URLSearchParams(window.location.search);
const blankFilters = () => ({
    employee_code: '', category_id: '', pay_scale_id: '', grade_id: '', gender: '', profile_status: '',
    designation: '', component_type_id: '', joining_date: '', religion: '', name_email: '',
    department_id: '', payroll_group_id: '', increment_status: '', fixation_status: '', per_page: '25',
});
const filters = ref(Object.fromEntries(Object.entries(blankFilters()).map(([key, initial]) => [key, url.get(key) || initial])));
const statusOptions = ['WORK_BLOCKED', 'CALCULATED', 'READY_FOR_GENERATION', 'GENERATED', 'ALL'];
const activeTab = ref(statusOptions.includes(url.get('status')) ? url.get('status') : 'WORK_BLOCKED');
const expandedFilters = ref(Object.entries(filters.value).some(([key, value]) => value && key !== 'per_page' && !['employee_code', 'name_email', 'category_id'].includes(key)));
const selected = ref([]);
const selectionMode = ref('page');
const appliedFilterSnapshot = ref(JSON.stringify(filters.value));
const filtersPending = computed(() => JSON.stringify(filters.value) !== appliedFilterSnapshot.value);
const busy = ref(false);
const operationBusy = ref(false);
const notice = ref('');
const reason = ref('');
const operationSize = ref('100');
const bulkSaveStatus = ref('draft');
const filteredBillReviewed = ref(false);
const operationLog = ref([]);
const operationSummary = ref({ checked: 0, ok: 0, failed: 0 });
const pageRows = computed(() => props.rows?.data || []);
const canSelect = (r) => Number(r.profile_count) === 1 && ['NOT_INITIALIZED', 'BLOCKED', 'CALCULATED'].includes(r.display_status);
const canApprove = (r) => canSelect(r) && r.display_status === 'CALCULATED' && Number(r.active_calculation_version_id) > 0 && Number(r.revision) > 0;
const selectedCount = computed(() => selectionMode.value === 'filtered' ? Number(props.selectableCount || 0) : selected.value.length);
const approvedSelected = computed(() => selectionMode.value === 'page' ? pageRows.value.filter(r => selected.value.includes(r.user_id) && canApprove(r)) : []);
const allSelectedApproved = computed(() => selectionMode.value === 'page' && selected.value.length > 0 && approvedSelected.value.length === selected.value.length);
const allPageSelected = computed(() => selectionMode.value === 'page' && pageRows.value.some(canSelect) && pageRows.value.filter(canSelect).every(r => selected.value.includes(r.user_id)));
const workBlockedCount = computed(() => Number(props.counts?.WORK_BLOCKED || 0));
const selectedCodes = computed(() => pageRows.value.filter(r => selected.value.includes(r.user_id)).map(r => r.employee_code));
const activeFilters = computed(() => Object.entries(filters.value).filter(([key, value]) => key !== 'per_page' && value !== '').length);
const periodName = computed(() => new Date(props.year, props.month - 1, 1).toLocaleString('en', { month: 'long', year: 'numeric' }));
const scopeText = computed(() => selectionMode.value === 'filtered' ? `${selectedCount.value} eligible employees across all filtered pages` : `${selectedCount.value} selected on this page`);
const cleanQuery = (status = activeTab.value) => {
    const fields = { year: props.year, month: props.month, status, ...filters.value };
    return Object.fromEntries(Object.entries(fields).filter(([, value]) => value !== '' && value !== null && value !== undefined));
};
const clearSelection = () => { selected.value = []; selectionMode.value = 'page'; filteredBillReviewed.value = false; };
function applyFilters(status = activeTab.value) {
    activeTab.value = status;
    appliedFilterSnapshot.value = JSON.stringify(filters.value);
    clearSelection();
    router.get(routePath, cleanQuery(status), { preserveState: true, replace: true, preserveScroll: true });
}
function resetFilters() { filters.value = blankFilters(); applyFilters(); }
function selectPage() { selectionMode.value = 'page'; selected.value = pageRows.value.filter(canSelect).map(r => r.user_id); }
function togglePage(checked) { if (checked) selectPage(); else clearSelection(); }
function selectFiltered() { if (filtersPending.value) { notice.value = 'Apply the edited filters before selecting all filtered employees.'; return; } selected.value = []; filteredBillReviewed.value = false; selectionMode.value = 'filtered'; }
watch(() => props.rows?.current_page, clearSelection);
watch(filters, clearSelection, { deep: true });

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
async function post(path, payload) {
    const response = await fetch(path, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw Error(data.message || JSON.stringify(data.errors || data) || `Request failed (${response.status})`);
    return data;
}
async function getSelection(cursor = 0, limit = 25) {
    const query = new URLSearchParams({ ...cleanQuery(), cursor: String(cursor), limit: String(limit) });
    const response = await fetch(`${routePath}/selection?${query}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw Error(data.message || `Selection failed (${response.status})`);
    return data;
}
function refresh() { router.reload({ only: ['rows', 'counts', 'runs', 'selectableCount'], preserveScroll: true }); }
function showError(error) { notice.value = error.message || String(error); }
async function initialize(onlySelected = false) {
    busy.value = true; notice.value = '';
    try {
        if (onlySelected && (selectionMode.value !== 'page' || !selected.value.length)) throw Error('Select employees on the current page first.');
        const data = await post('/dashboard/salary/workflow/reconcile', {
            year: props.year, month: props.month, limit: 1000, ...(onlySelected ? { user_ids: selected.value } : {}),
        });
        notice.value = `Roster checked: ${data.examined}. Created ${data.initialized}; existing ${data.existing}; identity review ${data.ambiguous}.`;
        clearSelection(); refresh();
    } catch (error) { showError(error); } finally { busy.value = false; }
}
async function previewSelected() {
    if (selectionMode.value !== 'page' || !selected.value.length) { notice.value = 'Select employees on this page for a provisional preview.'; return; }
    busy.value = true; notice.value = '';
    try {
        if (pageRows.value.some(r => selected.value.includes(r.user_id) && r.display_status === 'NOT_INITIALIZED')) {
            throw Error('Initialize these employee workflows before preview.');
        }
        const run = await post(`${routePath}/runs`, { year: props.year, month: props.month, user_ids: selected.value });
        let result;
        do {
            result = await post(`${routePath}/runs/${run.run_id}/next`, {});
            notice.value = `Preview #${run.run_id}: ${result.run.examined} checked, ${result.run.estimated} estimated, ${result.run.blocked} blocked. No bills posted.`;
        } while (result.run.status === 'running' && result.processed > 0);
        refresh();
    } catch (error) { showError(error); } finally { busy.value = false; }
}
async function saveCalculations() {
    if (selectionMode.value !== 'page' || !selectedCodes.value.length) { notice.value = 'Select employees on the current page to save individual snapshots.'; return; }
    if (selectedCodes.value.length > 50) { notice.value = 'Save at most 50 employees per batch.'; return; }
    if (reason.value.trim().length < 3) { notice.value = 'Enter an HR audit reason (at least 3 characters).'; return; }
    busy.value = true; notice.value = '';
    try {
        const data = await post(`${routePath}/bulk-save-calculations`, {
            year: props.year, month: props.month, employee_codes: selectedCodes.value, reason: reason.value,
        });
        notice.value = `Saved ${data.succeeded}, failed ${data.failed}.\n` + (data.results || []).map(r => `${r.code}: ${r.ok ? `Saved version ${r.version}` : r.error}`).join('\n');
        refresh();
    } catch (error) { showError(error); } finally { busy.value = false; }
}
function provisionalHorizontal() {
    const ids = approvedSelected.value.map(r => r.user_id);
    if (!ids.length || ids.length > 50) { notice.value = 'Select 1–50 calculated employees with approved versions on this page.'; return; }
    const query = new URLSearchParams({ year: String(props.year), month: String(props.month) });
    ids.forEach(id => query.append('user_ids[]', String(id)));
    window.open(`${routePath}/horizontal-bill?${query}`, '_blank', 'noopener');
}
async function downloadHorizontalBill(scope) {
    if (busy.value || operationBusy.value) return;
    if (scope === 'selected' && (selectionMode.value !== 'page' || !selected.value.length)) {
        notice.value = 'Select employees on the current page first.';
        return;
    }
    if (scope === 'filtered' && (filtersPending.value || !Number(props.rows?.total || 0))) {
        notice.value = 'Apply your filters before downloading the filtered report.';
        return;
    }
    busy.value = true;
    notice.value = '';
    try {
        const payload = { scope, year: props.year, month: props.month };
        if (scope === 'selected') payload.user_ids = [...selected.value];
        else Object.assign(payload, cleanQuery());
        const report = await post(`${routePath}/horizontal-bill/download`, payload);
        if (!Array.isArray(report.rows) || !report.rows.length) {
            throw Error(`No saved salary snapshots found for the requested employees. ${(report.errors || []).join('; ')}`);
        }
        const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a3' });
        const headings = [
            'SL', 'ID', 'Name', 'Designation', 'Basic', 'HRA', 'MA', 'S Benefit', 'Edu',
            'Other A', 'Reg Gross', 'GPF', 'Income T', 'Other D', 'Reg Ded',
            'Basic Arrear', 'Basic Adj.', 'SB Recovery', 'HRA Recovery', 'Addl. GPF', 'Net', 'Signature',
        ];
        const format = value => value === null || value === undefined
            ? 'N/R' : Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const body = report.rows.map((r, i) => [
            i + 1, `${r.employee_code} (${r.version_status === 'approved' ? 'APPROVED' : 'DRAFT'})`,
            r.name, r.designation,
            ...report.columns.map(key => format(r.financial[key])), '',
        ]);
        const totalRow = [{ content: `GRAND TOTAL (${report.rows.length} saved snapshots)`, colSpan: 4,
            styles: { halign: 'right', fontStyle: 'bold' } },
            ...report.columns.map(key => format(report.totals[key])), ''];
        autoTable(doc, {
            head: [headings], body, foot: [totalRow], showFoot: 'lastPage', startY: 28,
            margin: { top: 19, left: 6, right: 6, bottom: 14 },
            styles: { fontSize: 5.7, cellPadding: 1.3, overflow: 'linebreak', lineColor: [116, 133, 155], lineWidth: 0.15 },
            headStyles: { fillColor: [226, 234, 244], textColor: [20, 36, 58], fontStyle: 'bold' },
            footStyles: { fillColor: [233, 240, 234], textColor: [20, 36, 58], fontStyle: 'bold' },
            columnStyles: { 0: { cellWidth: 9 }, 1: { cellWidth: 22 }, 2: { cellWidth: 34 }, 3: { cellWidth: 28 },
                21: { cellWidth: 14 } },
            didDrawPage: (data) => {
                const width = doc.internal.pageSize.getWidth();
                const height = doc.internal.pageSize.getHeight();
                doc.setFont('helvetica', 'bold'); doc.setFontSize(12);
                doc.text('Khulna Agricultural University — Provisional Horizontal Salary Bill', width / 2, 9, { align: 'center' });
                doc.setFont('helvetica', 'normal'); doc.setFontSize(7);
                doc.text(`Period: ${String(props.month).padStart(2, '0')}/${props.year}  |  PROVISIONAL — NOT GENERATED / NOT FOR BANK PAYMENT`, width / 2, 14, { align: 'center' });
                doc.text(`${report.rows.length} saved snapshots / ${report.requested} requested; ${(report.errors || []).length} excluded; N/R = not recorded`, 7, 18);
                doc.text(`Page ${data.pageNumber}`, width - 8, height - 7, { align: 'right' });
            },
        });
        if (report.errors?.length) {
            const width = doc.internal.pageSize.getWidth();
            const height = doc.internal.pageSize.getHeight();
            doc.addPage('a3', 'landscape');
            doc.setFontSize(11); doc.text('Excluded employees — no financial amount has been invented', 12, 15);
            doc.setFontSize(8);
            let y = 23;
            for (const message of report.errors) {
                const lines = doc.splitTextToSize(message, width - 24);
                if (y + lines.length * 5 > height - 14) { doc.addPage('a3', 'landscape'); y = 18; }
                doc.text(lines, 12, y); y += lines.length * 5 + 1;
            }
        }
        doc.save(`KAU_Provisional_Horizontal_Bill_${props.year}_${String(props.month).padStart(2, '0')}_${scope}.pdf`);
        notice.value = `Horizontal Bill downloaded: ${report.rows.length} saved snapshots; ${(report.errors || []).length} excluded. Drafts are marked and missing values display N/R; this is not a posted salary bill.`;
    } catch (error) { showError(error); } finally { busy.value = false; }
}

async function approve(action) {
    if (selectionMode.value !== 'page' || !selected.value.length) { notice.value = 'Select employees on this page for HR approval. Review their provisional bill first.'; return; }
    if (reason.value.trim().length < 3) { notice.value = 'Enter an HR decision / approval reference.'; return; }
    if (action === 'approve_for_generation' && !allSelectedApproved.value) { notice.value = 'Select only CALCULATED employees with an active approved calculation.'; return; }
    if (selected.value.length > 50) { notice.value = 'Approve at most 50 employees per batch.'; return; }
    if (!window.confirm(`Apply ${action.replaceAll('_', ' ')} to ${selected.value.length} selected employees? This does NOT post salaries.`)) return;
    busy.value = true; notice.value = '';
    try {
        const items = selected.value.map(id => {
            const r = pageRows.value.find(e => e.user_id === id);
            return { user_id: id, expected_revision: r.revision, calculation_version_id: r.active_calculation_version_id || r.latest_approved_calculation_version_id };
        });
        if (items.some(i => !i.expected_revision)) throw Error('Initialize selected workflows before approval.');
        const data = await post('/dashboard/salary/workflow/bulk-approve', {
            year: props.year, month: props.month, action, reason: reason.value, items,
        });
        notice.value = `${data.succeeded} successful; ${data.failed} failed. ` + (data.results || []).filter(r => !r.ok).map(r => `${r.user_id}: ${r.error}`).join('; ');
        clearSelection(); refresh();
    } catch (error) { showError(error); } finally { busy.value = false; }
}
async function approveFiltered() {
    if (selectionMode.value !== 'filtered' || activeTab.value !== 'CALCULATED' || !selectedCount.value) {
        notice.value = 'Apply the Calculated worklist and select all filtered employees first.'; return;
    }
    if (reason.value.trim().length < 3 || !filteredBillReviewed.value) {
        notice.value = 'Enter the HR approval reference and confirm that the relevant provisional bills were reviewed.'; return;
    }
    const maximum = operationSize.value === 'all' ? selectedCount.value : Math.min(Number(operationSize.value), selectedCount.value);
    if (!window.confirm(`Mark up to ${maximum} filtered CALCULATED employees ready for generation? This changes workflow approval only; no salary bills are posted.`)) return;
    busy.value = true;
    notice.value = '';
    operationLog.value = [];
    operationSummary.value = { checked: 0, ok: 0, failed: 0 };
    let cursor = 0;
    try {
        while (operationSummary.value.checked < maximum) {
            const batch = await getSelection(cursor, Math.min(25, maximum - operationSummary.value.checked));
            const rows = batch.items || [];
            cursor = batch.cursor;
            if (!rows.length) break;
            const items = rows.filter(r => r.workflow_status === 'CALCULATED' && Number(r.revision) > 0 && Number(r.active_calculation_version_id) > 0)
                .map(r => ({ user_id: Number(r.user_id), expected_revision: Number(r.revision), calculation_version_id: Number(r.active_calculation_version_id) }));
            const skipped = rows.filter(r => !items.some(item => item.user_id === Number(r.user_id)));
            operationLog.value.push(...skipped.map(r => ({ code: r.employee_code, user_id: r.user_id, status: 'not_approved', ok: false, message: 'Missing active approved calculation or workflow revision.' })));
            operationSummary.value.failed += skipped.length;
            operationSummary.value.checked += rows.length;
            if (items.length) {
                const result = await post('/dashboard/salary/workflow/bulk-approve', {
                    year: props.year, month: props.month, action: 'approve_for_generation', reason: reason.value, items,
                });
                const byId = new Map(rows.map(r => [Number(r.user_id), r.employee_code]));
                operationLog.value.push(...(result.results || []).map(r => ({
                    code: byId.get(Number(r.user_id)) || String(r.user_id), user_id: r.user_id,
                    status: r.status || 'approval_failed', ok: r.ok, message: r.error || 'Ready for generation',
                })));
                operationSummary.value.ok += Number(result.succeeded || 0);
                operationSummary.value.failed += Number(result.failed || 0);
            }
            notice.value = `HR approval: ${operationSummary.value.checked} checked, ${operationSummary.value.ok} ready, ${operationSummary.value.failed} failed/skipped. No bills posted.`;
            if (!batch.has_more) break;
        }
    } catch (error) {
        notice.value = `Approval stopped after ${operationSummary.value.checked} checks: ${error.message}. Export and review employee results before retrying.`;
    } finally { busy.value = false; clearSelection(); refresh(); }
}
function exportOperationLog() {
    const rows = [['employee_code', 'user_id', 'status', 'result', 'message'], ...operationLog.value.map(r => [r.code, r.user_id, r.status, r.ok ? 'OK' : 'FAILED', r.message || ''])];
    const csv = rows.map(row => row.map(v => `"${String(v ?? '').replaceAll('"', '""')}"`).join(',')).join('\r\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    const temporaryUrl = URL.createObjectURL(blob);
    link.href = temporaryUrl; link.download = `KAU_calculation_audit_${props.year}_${props.month}.csv`;
    link.click(); URL.revokeObjectURL(temporaryUrl);
}
async function calculateAll() {
    if (operationBusy.value || !selectedCount.value || reason.value.trim().length < 3) { notice.value = 'Select a page or all filtered employees and enter an HR audit reason.'; return; }
    if (props.year !== 2026 || props.month !== 10) { notice.value = 'The existing bulk calculation endpoint currently supports October 2026 only.'; return; }
    const maximum = operationSize.value === 'all' ? selectedCount.value : Math.min(Number(operationSize.value), selectedCount.value);
    if (!window.confirm(`Process up to ${maximum} ${selectionMode.value === 'filtered' ? 'filtered' : 'page-selected'} employees as ${bulkSaveStatus.value.toUpperCase()}? Existing approved versions remain protected. No salary bills will be generated.`)) return;
    operationBusy.value = true;
    operationLog.value = []; operationSummary.value = { checked: 0, ok: 0, failed: 0 }; notice.value = '';
    let cursor = 0;
    let pagePosition = 0;
    const pageIds = [...selected.value];
    try {
        while (operationSummary.value.checked < maximum) {
            const amount = Math.min(10, maximum - operationSummary.value.checked);
            let ids = [];
            let more = false;
            if (selectionMode.value === 'filtered') {
                const data = await getSelection(cursor, amount);
                ids = (data.items || []).map(item => Number(item.user_id));
                cursor = data.cursor; more = data.has_more;
            } else {
                ids = pageIds.slice(pagePosition, pagePosition + amount);
                pagePosition += ids.length;
                more = pagePosition < pageIds.length;
            }
            if (!ids.length) break;
            // The existing financial worker receives an explicit, bounded identity scope.
            const data = await post(`${routePath}/bulk-operator/batch`, {
                year: props.year, month: props.month, user_ids: ids,
                cursor: 0, batch_size: ids.length, reason: reason.value, action: bulkSaveStatus.value,
            });
            operationLog.value.push(...(data.results || []));
            const handled = new Set((data.results || []).map(item => Number(item.user_id)));
            const unprocessed = ids.filter(id => !handled.has(id));
            operationLog.value.push(...unprocessed.map(id => ({
                code: pageRows.value.find(r => Number(r.user_id) === id)?.employee_code || String(id),
                user_id: id, status: 'not_processed', ok: false, message: 'Not eligible for this operation or workflow changed.',
            })));
            operationSummary.value.checked += ids.length;
            operationSummary.value.ok += Number(data.succeeded || 0);
            operationSummary.value.failed += Number(data.failed || 0) + unprocessed.length;
            notice.value = `Checked ${operationSummary.value.checked}, saved/preserved ${operationSummary.value.ok}, failed/skipped ${operationSummary.value.failed}. No salary posting.`;
            if (!more) break;
        }
        if (operationSummary.value.checked === 0) notice.value = 'No eligible employees found in this selection.';
    } catch (error) {
        notice.value = `Stopped after ${operationSummary.value.checked} employees: ${error.message}. Export the results CSV and review before retrying.`;
    } finally { operationBusy.value = false; clearSelection(); refresh(); }
}
</script>

<template>
    <Head title="Salary Estimation / Calculation" />
    <AuthenticatedLayout>
        <main class="mx-auto max-w-[1680px] space-y-4 px-3 py-4 text-slate-800 sm:px-5 md:py-5">
            <header class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-indigo-600">{{ periodName }} · HR payroll workspace</div>
                    <h1 class="text-xl font-bold sm:text-2xl">Salary Estimation / Calculation</h1>
                    <p class="mt-1 text-xs text-slate-500">Calculate, review and approve saved snapshots. Final bill generation is separate.</p>
                </div>
                <a :href="`/dashboard/salary/estimation/bulk-components?year=${year}&month=${month}`" class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-800 hover:bg-indigo-100">Bulk Modify / Spreadsheet Grid</a>
            </header>

            <section class="grid grid-cols-2 gap-2 sm:grid-cols-4" aria-label="Payroll workflow totals">
                <div v-for="item in [{key:'WORK_BLOCKED',title:'Needs attention',tone:'text-amber-700'}, {key:'CALCULATED',title:'Calculated',tone:'text-indigo-700'}, {key:'READY_FOR_GENERATION',title:'Ready for generation',tone:'text-emerald-700'}, {key:'GENERATED',title:'Generated',tone:'text-slate-700'}]" :key="item.key" class="rounded-xl border border-slate-200 bg-white px-4 py-3">
                    <div class="text-xs text-slate-500">{{ item.title }}</div>
                    <div class="mt-0.5 text-2xl font-semibold" :class="item.tone">{{ Number(counts?.[item.key] || 0) }}</div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                    <div><h2 class="font-semibold">Search &amp; Filter</h2><p class="text-xs text-slate-500">Search the complete employee directory, not just the current page.</p></div>
                    <button type="button" class="rounded-lg border px-3 py-1.5 text-xs font-semibold hover:bg-slate-50" @click="expandedFilters=!expandedFilters">{{ expandedFilters ? 'Fewer filters' : `More filters${activeFilters ? ` (${activeFilters})` : ''}` }}</button>
                </div>
                <form class="space-y-3 p-4" @submit.prevent="applyFilters()">
                    <div class="grid grid-cols-1 gap-3 text-xs sm:grid-cols-2 xl:grid-cols-4">
                        <label class="space-y-1"><span class="font-medium">Employee Code</span><input v-model.trim="filters.employee_code" class="w-full rounded-lg border-slate-300 text-sm" placeholder="e.g. 10030" /></label>
                        <label class="space-y-1"><span class="font-medium">Name or Email</span><input v-model.trim="filters.name_email" class="w-full rounded-lg border-slate-300 text-sm" placeholder="Search employees" /></label>
                        <label class="space-y-1"><span class="font-medium">Employee Category</span><select v-model="filters.category_id" class="w-full rounded-lg border-slate-300 text-sm"><option value="">All Categories</option><option v-for="o in (filterOptions?.categories||[])" :key="o.id" :value="String(o.id)">{{ o.name }}</option></select></label>
                        <label class="space-y-1"><span class="font-medium">Rows per Page</span><select v-model="filters.per_page" class="w-full rounded-lg border-slate-300 text-sm"><option v-for="n in [10,25,50,100,200]" :key="n" :value="String(n)">{{ n }} employees</option></select></label>
                    </div>
                    <div v-if="expandedFilters" class="grid grid-cols-1 gap-3 border-t pt-3 text-xs sm:grid-cols-2 xl:grid-cols-4">
                        <label class="space-y-1"><span class="font-medium">Pay Scale</span><select v-model="filters.pay_scale_id" class="w-full rounded-lg border-slate-300 text-sm"><option value="">All Pay Scales</option><option v-for="o in (filterOptions?.pay_scales||[])" :key="o.id" :value="String(o.id)">{{o.scale_name}}</option></select></label>
                        <label class="space-y-1"><span class="font-medium">Grade</span><select v-model="filters.grade_id" class="w-full rounded-lg border-slate-300 text-sm"><option value="">All Grades</option><option v-for="o in (filterOptions?.grades||[]).filter(g => !filters.pay_scale_id || String(g.pay_scale_id) === String(filters.pay_scale_id))" :key="o.id" :value="String(o.id)">{{o.grade_name}}</option></select></label>
                        <label class="space-y-1"><span class="font-medium">Gender</span><select v-model="filters.gender" class="w-full rounded-lg border-slate-300 text-sm"><option value="">All Genders</option><option v-for="o in (filterOptions?.genders||[])" :key="o" :value="o">{{o}}</option></select></label>
                        <label class="space-y-1"><span class="font-medium">Profile Active Status</span><select v-model="filters.profile_status" class="w-full rounded-lg border-slate-300 text-sm"><option value="">All Profiles</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                        <label class="space-y-1"><span class="font-medium">Designation</span><select v-model="filters.designation" class="w-full rounded-lg border-slate-300 text-sm"><option value="">All Designations</option><option v-for="o in (filterOptions?.designations||[])" :key="o" :value="o">{{o}}</option></select></label>
                        <label class="space-y-1"><span class="font-medium">Payroll Component Type</span><select v-model="filters.component_type_id" class="w-full rounded-lg border-slate-300 text-sm"><option value="">All Component Types</option><option v-for="o in (filterOptions?.component_types||[])" :key="o.id" :value="String(o.id)">{{o.name}}</option></select></label>
                        <label class="space-y-1"><span class="font-medium">Joining Date</span><input v-model="filters.joining_date" type="date" class="w-full rounded-lg border-slate-300 text-sm" /></label>
                        <label class="space-y-1"><span class="font-medium">Religion</span><select v-model="filters.religion" class="w-full rounded-lg border-slate-300 text-sm"><option value="">All Religions</option><option v-for="o in (filterOptions?.religions||[])" :key="o" :value="o">{{o}}</option></select></label>
                        <label class="space-y-1"><span class="font-medium">Department / Office</span><select v-model="filters.department_id" class="w-full rounded-lg border-slate-300 text-sm"><option value="">All Departments</option><option v-for="o in (filterOptions?.departments||[])" :key="o.id" :value="String(o.id)">{{o.name}}</option></select></label>
                        <label class="space-y-1"><span class="font-medium">Payroll Group</span><select v-model="filters.payroll_group_id" class="w-full rounded-lg border-slate-300 text-sm"><option value="">All Groups</option><option v-for="o in (filterOptions?.payroll_groups||[])" :key="o.id" :value="String(o.id)">{{o.group_name}}</option></select></label>
                        <label class="space-y-1"><span class="font-medium">Increment / Eligibility Status</span><select v-model="filters.increment_status" class="w-full rounded-lg border-slate-300 text-sm"><option value="">All Employees</option><option value="available">Increment Available (profile)</option><option value="hold">Increment Hold</option><option value="adhoc">AD HOC</option><option value="study_with">Study Leave With Increment</option><option value="study_without">Study Leave Without Increment</option><option value="other">Other / Unrecorded</option></select></label>
                        <label class="space-y-1"><span class="font-medium">Fixation Status</span><select v-model="filters.fixation_status" class="w-full rounded-lg border-slate-300 text-sm"><option value="">All Fixations</option><option value="approved">Latest Approved</option><option value="not_approved">Latest Not Approved</option><option value="missing">No Saved Fixation</option></select></label>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 border-t pt-3">
                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Apply Filters</button>
                        <button type="button" class="rounded-lg border px-4 py-2 text-sm hover:bg-slate-50" @click="resetFilters">Reset</button>
                        <span class="ml-auto text-xs text-slate-500">{{rows?.total || 0}} matching · page {{rows?.current_page || 1}} of {{rows?.last_page || 1}}</span>
                    </div>
                </form>
            </section>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" @click="applyFilters('WORK_BLOCKED')" class="rounded-lg px-3 py-2 text-sm font-semibold" :class="activeTab==='WORK_BLOCKED' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600'">Blocked Employee List ({{workBlockedCount}})</button>
                        <button type="button" @click="applyFilters('CALCULATED')" class="rounded-lg px-3 py-2 text-sm font-semibold" :class="activeTab==='CALCULATED' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600'">Calculated Employee List ({{Number(counts?.CALCULATED||0)}})</button>
                    </div>
                    <label class="flex items-center gap-2 text-xs text-slate-600">Workflow Status <select :value="activeTab" @change="applyFilters($event.target.value)" class="rounded-lg border-slate-300 py-1.5 text-xs"><option value="WORK_BLOCKED">Needs Attention</option><option value="CALCULATED">Calculated</option><option value="READY_FOR_GENERATION">Ready</option><option value="GENERATED">Generated</option><option value="ALL">All Statuses</option></select></label>
                </div>

                <div class="space-y-3 border-b bg-slate-50/50 px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" @click="selectPage" class="rounded-lg border bg-white px-3 py-2 text-xs font-semibold hover:bg-slate-50">Select current page</button>
                        <button type="button" :disabled="!selectableCount||filtersPending" @click="selectFiltered" class="rounded-lg border bg-white px-3 py-2 text-xs font-semibold hover:bg-slate-50 disabled:opacity-40">Select all filtered ({{selectableCount||0}})</button>
                        <button type="button" @click="clearSelection" class="rounded-lg border bg-white px-3 py-2 text-xs">Clear</button>
                        <span class="text-xs font-medium text-indigo-700">{{scopeText}}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="text-xs">Process <select v-model="operationSize" class="ml-1 rounded-lg border-slate-300 py-1.5 text-xs"><option value="100">100</option><option value="500">500</option><option value="all">All selected</option></select></label>
                        <label class="text-xs">Save as <select v-model="bulkSaveStatus" class="ml-1 rounded-lg border-slate-300 py-1.5 text-xs"><option value="draft">Provisional Draft</option><option value="approved">Approved (verified only)</option></select></label>
                        <input v-model.trim="reason" class="min-w-[190px] flex-1 rounded-lg border-slate-300 py-1.5 text-sm" placeholder="HR reason / reference (required)" aria-label="HR audit reason" />
                        <button type="button" :disabled="operationBusy||busy||!selectedCount||reason.length<3" @click="calculateAll" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">{{operationBusy?'Processing…':'Bulk Calculate + Save'}}</button>
                    </div>
                    <div v-if="operationLog.length" class="flex flex-wrap items-center gap-3 text-xs text-slate-600"><span>Processed {{operationSummary.checked}} · Saved / preserved {{operationSummary.ok}} · Failed {{operationSummary.failed}}</span><button @click="exportOperationLog" class="font-semibold text-indigo-700 underline">Export results CSV</button><details class="w-full"><summary class="cursor-pointer">View individual results</summary><div class="mt-2 max-h-40 overflow-y-auto rounded-lg border bg-white p-2"><p v-for="(r,i) in operationLog" :key="i" :class="r.ok?'text-emerald-700':'text-rose-700'">{{r.code}}: {{r.status}} {{r.message}}</p></div></details></div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <button type="button" :disabled="busy||operationBusy||selectionMode!=='page'||!selected.length" @click="downloadHorizontalBill('selected')" class="rounded-lg border border-indigo-300 bg-white px-3 py-2 font-semibold text-indigo-700 disabled:opacity-40">Horizontal Bill · Selected ({{selected.length}})</button>
                        <button type="button" :disabled="busy||operationBusy||filtersPending||!Number(rows?.total||0)" @click="downloadHorizontalBill('filtered')" class="rounded-lg border border-indigo-300 bg-white px-3 py-2 font-semibold text-indigo-700 disabled:opacity-40">Horizontal Bill · All Filtered ({{rows?.total||0}})</button>
                        <button type="button" v-if="selectionMode==='page'" :disabled="busy||operationBusy||!allSelectedApproved||reason.length<3" @click="approve('approve_for_generation')" class="rounded-lg border border-indigo-400 bg-white px-3 py-2 font-semibold text-indigo-700 disabled:opacity-40">HR Bulk Approval → Ready ({{approvedSelected.length}})</button>
                        <template v-if="selectionMode==='filtered'&&activeTab==='CALCULATED'">
                            <label class="flex items-center gap-2 rounded-lg bg-white px-2 py-2"><input v-model="filteredBillReviewed" type="checkbox" />Provisional bills reviewed by HR</label>
                            <button type="button" :disabled="busy||operationBusy||!filteredBillReviewed||reason.length<3" @click="approveFiltered" class="rounded-lg border border-indigo-400 bg-white px-3 py-2 font-semibold text-indigo-700 disabled:opacity-40">HR Approve filtered → Ready</button>
                        </template>
                        <details class="rounded-lg border bg-white px-3 py-2"><summary class="cursor-pointer font-medium">More actions</summary><div class="mt-3 flex flex-wrap gap-2">
                            <button :disabled="busy||selectionMode!=='page'||!selected.length" @click="saveCalculations" class="rounded border px-3 py-2 disabled:opacity-40">Save selected calculations</button>
                            <button :disabled="busy||selectionMode!=='page'||!selected.length" @click="approve('approve_calculation')" class="rounded border px-3 py-2 disabled:opacity-40">Approve Calculation</button>
                            <button :disabled="busy||selectionMode!=='page'||!selected.length" @click="approve('return_for_revision')" class="rounded border px-3 py-2 disabled:opacity-40">Return for Revision</button>
                            <button :disabled="busy||selectionMode!=='page'||!selected.length" @click="previewSelected" class="rounded border px-3 py-2 disabled:opacity-40">Preview selected</button>
                            <button :disabled="busy||selectionMode!=='page'||!selected.length" @click="initialize(true)" class="rounded border px-3 py-2 disabled:opacity-40">Initialize selected workflow</button>
                        </div></details>
                    </div>
                    <p v-if="notice" role="status" class="whitespace-pre-wrap break-words rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-700">{{notice}}</p>
                    <p v-if="Number(counts?.NOT_INITIALIZED||0)>0" class="text-xs text-amber-800">{{counts.NOT_INITIALIZED}} workflows are not initialized. <button :disabled="busy" @click="initialize()" class="font-semibold underline disabled:opacity-40">Initialize entire period roster</button> (explicit setup action).</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1180px] text-left text-sm">
                        <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500"><tr>
                            <th class="w-9 px-3 py-3"><input type="checkbox" :checked="selectionMode==='filtered'||allPageSelected" @change="togglePage($event.target.checked)" aria-label="Select current page" /></th>
                            <th class="px-3 py-3">Employee</th><th class="px-3 py-3">Employee Code</th><th class="px-3 py-3">Department</th><th class="px-3 py-3">Pay Scale</th><th class="px-3 py-3">Employee Category</th><th class="px-3 py-3">Employment Details</th><th class="px-3 py-3">Actions</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="r in pageRows" :key="r.user_id" class="border-t border-slate-100 align-middle hover:bg-slate-50">
                                <td class="px-3 py-3"><input v-model="selected" type="checkbox" :value="r.user_id" :checked="selectionMode==='filtered'&&canSelect(r)" :disabled="!canSelect(r)||selectionMode==='filtered'" :aria-label="`Select ${r.employee_code}`" /></td>
                                <td class="max-w-[220px] px-3 py-3"><p class="font-semibold text-slate-900">{{r.name}}</p><p class="mt-1 break-all text-xs text-slate-500">{{r.email||'Not recorded'}} · {{r.gender||'Not recorded'}}</p></td>
                                <td class="px-3 py-3"><p class="font-semibold">{{r.employee_code}}</p><p class="mt-1 text-xs" :class="r.isActive?'text-emerald-700':'text-rose-700'">{{r.isActive?'Active':'Inactive'}}</p><p v-if="r.profile_count>1" class="text-xs text-rose-700">Multiple profiles ({{r.profile_count}})</p></td>
                                <td class="max-w-[200px] px-3 py-3"><p class="font-medium">{{r.department_name||'Not recorded'}}</p><p class="mt-1 text-xs text-slate-500">{{r.designation_name||'Not recorded'}}</p></td>
                                <td class="px-3 py-3"><p>{{r.pay_scale_name||'Not recorded'}}</p><p class="mt-1 text-xs text-slate-500">{{r.grade_name||'No grade'}}</p></td>
                                <td class="px-3 py-3"><p class="font-medium">{{r.category_names||'Not recorded'}}</p><p class="mt-1 text-xs text-slate-500">{{r.eligibility_label}}</p></td>
                                <td class="px-3 py-3"><p>{{r.joining_date||'Not recorded'}}</p><p class="mt-1 text-xs text-slate-500">{{r.religion_name||'Not recorded'}}</p></td>
                                <td class="min-w-[205px] px-3 py-3"><div class="flex flex-wrap gap-1.5">
                                    <a :href="`/dashboard/salary/calculator/${r.user_id}?year=${year}&month=${month}`" class="rounded-md bg-indigo-600 px-2 py-1 text-xs font-medium text-white">Calculator</a>
                                    <a :href="`/dashboard/salary/fixation/${r.user_id}/workspace?year=${year}&month=${month}`" class="rounded-md border px-2 py-1 text-xs">Edit Components</a>
                                    <a v-if="r.active_calculation_version_id && r.display_status==='CALCULATED'" :href="`/dashboard/salary/provisional/${r.user_id}?year=${year}&month=${month}&version_id=${r.active_calculation_version_id}`" target="_blank" rel="noopener" class="rounded-md border px-2 py-1 text-xs">Provisional Bill</a>
                                </div><p class="mt-2 text-[11px] text-slate-500">{{r.display_status==='NOT_INITIALIZED'?'Not Initialized':String(r.display_status||'Not Recorded').replaceAll('_',' ')}} · {{r.latest_approved_calculation_version_id?`Approved #${r.latest_approved_calculation_version_id}`:'No approved calculation'}}</p></td>
                            </tr>
                            <tr v-if="!pageRows.length"><td colspan="8" class="px-4 py-10 text-center text-sm text-slate-500">No employees match this status and filter combination.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-xs text-slate-500"><span>Showing {{rows?.from||0}}–{{rows?.to||0}} of {{rows?.total||0}} employees</span>
                    <nav class="flex flex-wrap gap-1" aria-label="Employee pagination"><Link v-for="(link,i) in (rows?.links||[])" :key="i" :href="link.url||'#'" :preserve-scroll="true" class="rounded-md border px-2.5 py-1.5" :class="[link.active?'bg-indigo-600 text-white':'bg-white text-slate-700',!link.url?'pointer-events-none opacity-40':'']" v-html="link.label" /></nav>
                </div>
            </section>
            <details class="rounded-xl border bg-white px-4 py-3 text-xs"><summary class="cursor-pointer font-semibold text-slate-700">Run history and preview failures</summary><div v-for="r in (runs||[])" :key="r.id" class="border-b py-2">Run #{{r.id}} · {{r.status}} · {{r.examined}} checked · {{r.estimated}} estimated · {{r.blocked}} blocked</div><p v-if="!(runs||[]).length" class="mt-2 text-slate-500">No preview runs for this period.</p></details>
            <p class="text-xs text-slate-500">Eligibility labels use the verified fixation exception lists and profile flags; final increment amounts require fixation review. Draft calculation is not a financial approval or a generated salary bill.</p>
        </main>
    </AuthenticatedLayout>
</template>
