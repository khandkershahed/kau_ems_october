<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SalarySetupModal from '@/Components/SalarySetupModal.vue';
import EditEmployeeProfileModal from '@/Pages/Employee/Modal/EditEmployeeProfileModal.vue';
import SalaryBillPreviewModal from '@/Components/SalaryBillPreviewModal.vue';
import { createHorizontalBillPdf } from '@/Pages/Reports/reportTemplates/HorizontalBillPdf';
import { downloadHorizontalBillExcel } from '@/Pages/Reports/reportTemplates/HorizontalBillExcel';

const props = defineProps({
    rows: { type: Object, required: true },
    exportRows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    eligiblePending: { type: Array, default: () => [] },
    year: { type: Number, default: 2026 },
    month: { type: Number, default: 11 },
    isLocked: { type: Boolean, default: false },
    filters: { type: Object, default: () => ({}) },
    filterOptions: {
        type: Object,
        default: () => ({
            employee_types: [],
            default_employee_types: [],
            categories: [],
            departments: [],
            designations: [],
            pay_scales: [],
            payroll_groups: [],
        }),
    },
});

const routePath = '/dashboard/salary/estimation';

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

const selectedYear = ref(Number(props.year || 2026));
const selectedMonth = ref(Number(props.month || 11));

const years = computed(() => {
    const maxYear = Math.max(2027, selectedYear.value + 1);
    const list = [];
    for (let y = 2024; y <= maxYear; y++) {
        list.push(y);
    }
    return list;
});

const defaultEmployeeTypes = computed(() => props.filterOptions?.default_employee_types ?? []);

const search = ref(props.filters?.search || '');
const categoryId = ref(props.filters?.category_id || '');
const departmentId = ref(props.filters?.department_id || '');
const designation = ref(props.filters?.designation || '');
const statusFilter = ref(props.filters?.status || 'ALL');
const perPage = ref(props.filters?.per_page || '50');
const selectedEmployeeTypes = ref(
    [...(props.filters?.employee_types ?? defaultEmployeeTypes.value)],
);
const employeeTypeToAdd = ref('');

const availableEmployeeTypesToAdd = computed(() =>
    (props.filterOptions?.employee_types || []).filter(
        (type) => !selectedEmployeeTypes.value.includes(type.value),
    ),
);

watch(
    () => props.filters?.employee_types,
    (types) => {
        selectedEmployeeTypes.value = [...(types ?? defaultEmployeeTypes.value)];
    },
);

watch(
    () => [props.year, props.month],
    ([y, m]) => {
        selectedYear.value = Number(y || 2026);
        selectedMonth.value = Number(m || 11);
    },
);

const pageRows = computed(() => props.rows?.data || []);
const periodLabel = computed(() => {
    const m = months.find((item) => item.value === Number(props.month));
    return `${m ? m.label : props.month} ${props.year}`;
});

const selectedIds = ref([]);
const canSelectRow = (row) =>
    !props.isLocked &&
    !row.is_generated &&
    row.is_active !== false &&
    Number(row.profile_count) === 1;

const selectablePageRows = computed(() => pageRows.value.filter(canSelectRow));
const allPageSelected = computed(
    () =>
        selectablePageRows.value.length > 0 &&
        selectablePageRows.value.every((r) => selectedIds.value.includes(r.user_id)),
);

const toggleSelectAllPage = (checked) => {
    if (checked) {
        selectedIds.value = selectablePageRows.value.map((r) => r.user_id);
    } else {
        selectedIds.value = [];
    }
};

watch(
    () => [props.year, props.month, props.rows?.current_page],
    () => {
        selectedIds.value = [];
    },
);

// Row Action Modals (3 Icons)
const profileEditEmployee = ref(null);
const profileEditOptions = ref({});
const loadingProfile = ref(false);
const openProfileEdit = async (row) => {
    loadingProfile.value = true;
    try {
        const { data } = await axios.get(route('employees.edit'), {
            params: { profile_user_id: row.user_id },
            headers: { Accept: 'application/json' },
        });
        profileEditOptions.value = data.options;
        profileEditEmployee.value = data.employee;
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Unable to load employee profile',
            text: error.response?.data?.message || 'Try opening the profile again.',
        });
    } finally {
        loadingProfile.value = false;
    }
};
const onProfileSaved = () => {
    router.reload({
        only: ['rows', 'exportRows', 'summary', 'eligiblePending', 'filterOptions'],
        preserveScroll: true,
    });
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: 'Employee profile updated',
        showConfirmButton: false,
        timer: 2200,
    });
};

const setupModalOpen = ref(false);
const setupModalUserId = ref(null);
const openSalarySetup = (row) => {
    setupModalUserId.value = row.user_id;
    setupModalOpen.value = true;
};
const closeSalarySetup = () => {
    setupModalOpen.value = false;
};
const onSalarySetupSaved = () => {
    router.reload({
        only: ['rows', 'exportRows', 'summary', 'eligiblePending'],
        preserveScroll: true,
    });
};

const billPreviewOpen = ref(false);
const billPreviewEmployee = ref(null);
const openBillPreview = (row) => {
    billPreviewEmployee.value = row;
    billPreviewOpen.value = true;
};

// Navigation & filtering
let searchTimer = null;

const buildQuery = () => {
    const q = {
        year: selectedYear.value,
        month: selectedMonth.value,
        status: statusFilter.value || 'ALL',
        per_page: perPage.value || '50',
        employee_types: selectedEmployeeTypes.value,
    };
    if (search.value.trim() !== '') q.search = search.value.trim();
    if (categoryId.value !== '') q.category_id = categoryId.value;
    if (departmentId.value !== '') q.department_id = departmentId.value;
    if (designation.value !== '') q.designation = designation.value;
    return q;
};

const reloadWorkspace = () => {
    selectedIds.value = [];
    router.get(routePath, buildQuery(), {
        preserveState: true,
        replace: true,
        preserveScroll: true,
    });
};

const addEmployeeType = () => {
    const val = employeeTypeToAdd.value;
    if (!val || selectedEmployeeTypes.value.includes(val)) return;
    selectedEmployeeTypes.value = [...selectedEmployeeTypes.value, val];
    employeeTypeToAdd.value = '';
    reloadWorkspace();
};

const removeEmployeeType = (type) => {
    selectedEmployeeTypes.value = selectedEmployeeTypes.value.filter((t) => t !== type);
    reloadWorkspace();
};

const onSearchInput = () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        reloadWorkspace();
    }, 300);
};

const resetFilters = () => {
    search.value = '';
    categoryId.value = '';
    departmentId.value = '';
    designation.value = '';
    statusFilter.value = 'ALL';
    perPage.value = '50';
    selectedEmployeeTypes.value = [...defaultEmployeeTypes.value];
    employeeTypeToAdd.value = '';
    reloadWorkspace();
};

// Formatting helpers
const formatMoney = (val) => {
    const num = Number(val || 0);
    return num.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

// Bulk Calculate & Generate with SweetAlert2
const generating = ref(false);
const notice = ref('');
const noticeTone = ref('info');

const generationTargets = computed(() => {
    if (selectedIds.value.length > 0) {
        const picked = new Set(selectedIds.value.map(Number));
        return (props.eligiblePending || []).filter((item) => picked.has(Number(item.user_id)));
    }
    return props.eligiblePending || [];
});

const generationTargetCount = computed(() => generationTargets.value.length);
const generationTargetRegularNet = computed(() =>
    generationTargets.value.reduce((sum, item) => sum + Number(item.regular_net || 0), 0),
);
const generationTargetNetArrear = computed(() =>
    generationTargets.value.reduce((sum, item) => sum + Number(item.net_arrear || 0), 0),
);
const generationTargetNetPayable = computed(() =>
    generationTargets.value.reduce((sum, item) => sum + Number(item.net_payable || 0), 0),
);
const generationScopeLabel = computed(() =>
    selectedIds.value.length > 0
        ? `${generationTargetCount.value} Selected`
        : `${generationTargetCount.value} All Eligible`,
);

const openConfirmGenerate = async () => {
    if (props.isLocked || generating.value || generationTargetCount.value === 0) return;
    notice.value = '';

    const arrearSign = generationTargetNetArrear.value > 0 ? '+' : '';
    const confirm = await Swal.fire({
        title: 'Confirm Bulk Salary Generation',
        icon: 'question',
        html: `
            <div style="text-align:left;font-size:13px;line-height:1.5;color:#334155;">
                <p style="margin-bottom:10px;">
                    Generate and persist official salary bills into <code>salary_generates</code> for <strong>${periodLabel.value}</strong>?
                </p>
                <div style="border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;padding:10px 12px;">
                    <div style="display:flex;justify-content:space-between;padding:3px 0;">
                        <span>Target Month:</span>
                        <strong>${periodLabel.value}</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:3px 0;">
                        <span>Employees (${selectedIds.value.length > 0 ? 'Selected' : 'All Eligible'}):</span>
                        <strong>${generationTargetCount.value}</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:3px 0;border-top:1px dashed #cbd5e1;margin-top:4px;padding-top:6px;">
                        <span>Regular Net (BDT):</span>
                        <span style="font-family:monospace;font-weight:600;">৳${formatMoney(generationTargetRegularNet.value)}</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:3px 0;">
                        <span>Net Arrears (BDT):</span>
                        <span style="font-family:monospace;font-weight:600;color:#4338ca;">${arrearSign}৳${formatMoney(generationTargetNetArrear.value)}</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:5px 0 0;border-top:1px solid #cbd5e1;margin-top:4px;font-size:14px;">
                        <span style="font-weight:700;color:#065f46;">Total Net Payable:</span>
                        <span style="font-family:monospace;font-weight:800;color:#065f46;">৳${formatMoney(generationTargetNetPayable.value)}</span>
                    </div>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Confirm & Generate',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#4f46e5',
        cancelButtonColor: '#64748b',
        reverseButtons: true,
    });

    if (!confirm.isConfirmed) return;
    await executeBulkGenerate();
};

const executeBulkGenerate = async () => {
    if (props.isLocked || generating.value || generationTargetCount.value === 0) return;
    generating.value = true;
    notice.value = '';

    const targetIds = generationTargets.value.map((item) => Number(item.user_id));
    const chunkSize = 25;

    let totalSuccess = 0;
    let totalSkipped = 0;
    let totalFailed = 0;
    const allErrors = [];

    Swal.fire({
        title: `Generating ${periodLabel.value} Salary Bills...`,
        html: `<p style="font-size:13px;color:#475569;">Processing 0 of ${targetIds.length} employees. Please wait.</p>`,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => {
            Swal.showLoading();
        },
    });

    try {
        for (let i = 0; i < targetIds.length; i += chunkSize) {
            const batch = targetIds.slice(i, i + chunkSize);
            const processed = Math.min(i + batch.length, targetIds.length);
            Swal.update({
                html: `<p style="font-size:13px;color:#475569;">Processing ${processed} of ${targetIds.length} employees...</p>`,
            });

            const { data } = await axios.post(
                '/dashboard/salary/estimation/generate-bulk',
                {
                    year: props.year,
                    month: props.month,
                    user_ids: batch,
                },
                { headers: { Accept: 'application/json' } },
            );

            totalSuccess += Number(data?.success_count || 0);
            totalSkipped += Number(data?.skipped_count || 0);
            totalFailed += Number(data?.failed_count || 0);
            if (Array.isArray(data?.errors) && data.errors.length) {
                allErrors.push(...data.errors);
            }
        }

        const summaryParts = [`Generated ${totalSuccess} bill(s) for ${periodLabel.value}.`];
        if (totalSkipped > 0) summaryParts.push(`${totalSkipped} already existed.`);
        if (totalFailed > 0) summaryParts.push(`${totalFailed} failed.`);

        if (totalFailed > 0) {
            noticeTone.value = 'error';
            notice.value = `${summaryParts.join(' ')} ${allErrors.slice(0, 4).join(' | ')}`;
            await Swal.fire({
                icon: 'warning',
                title: 'Bulk Generation Completed with Errors',
                text: `${summaryParts.join(' ')} ${allErrors.slice(0, 3).join(' | ')}`,
                confirmButtonColor: '#4f46e5',
            });
        } else {
            noticeTone.value = 'success';
            notice.value = summaryParts.join(' ');
            await Swal.fire({
                icon: 'success',
                title: 'Salary Bills Generated',
                text: summaryParts.join(' '),
                confirmButtonColor: '#059669',
            });
        }
    } catch (err) {
        const errData = err.response?.data;
        const msg =
            errData?.message ||
            (Array.isArray(errData?.errors) ? errData.errors.join(' | ') : '') ||
            err.message ||
            'Bulk salary generation failed.';
        noticeTone.value = 'error';
        notice.value = `Generation stopped after ${totalSuccess} succeeded: ${msg}`;
        await Swal.fire({
            icon: 'error',
            title: 'Bulk Salary Generation Failed',
            text: msg,
            confirmButtonColor: '#dc2626',
        });
    } finally {
        generating.value = false;
        selectedIds.value = [];
        router.reload({
            only: ['rows', 'exportRows', 'summary', 'eligiblePending'],
            preserveScroll: true,
        });
    }
};

// Horizontal Bill Excel & PDF export
const rowsForExport = computed(() => {
    const source = props.exportRows?.length ? props.exportRows : pageRows.value;
    if (selectedIds.value.length > 0) {
        const picked = new Set(selectedIds.value.map(Number));
        return source.filter((r) => picked.has(Number(r.user_id)));
    }
    return source;
});

const exportingHorizontal = ref(false);

const exportHorizontalBill = async (format) => {
    const ids = [...new Set(rowsForExport.value.map((row) => row.user_id))];
    if (!ids.length || exportingHorizontal.value) return;

    const { year, month } = props;
    exportingHorizontal.value = true;
    notice.value = '';
    try {
        const { data } = await axios.get(route('reports.salary-official.index'), {
            params: { ids: ids.join(','), year, month, kind: 'bulk', format: 'json', mode: 'preview', columns: 'full' },
            headers: { Accept: 'application/json' },
        });
        if (!data.records?.length) throw new Error('No employees are eligible for this report.');
        if (format === 'excel') {
            const filename = `KAU_Horizontal_Bill_${year}_${String(month).padStart(2, '0')}.xlsx`;
            downloadHorizontalBillExcel(data, data.horizontal_columns, filename);
        } else {
            await createHorizontalBillPdf({
                records: data.records,
                columns: data.horizontal_columns,
                totals: data.horizontal_totals,
                year,
                month,
            });
        }
    } catch (error) {
        const response = error.response?.data;
        const reportError = typeof response === 'string'
            ? new DOMParser().parseFromString(response, 'text/html').querySelector('p')?.textContent
            : response?.message;
        noticeTone.value = 'error';
        notice.value = reportError || error.message || 'Unable to export the Horizontal Bill.';
    } finally {
        exportingHorizontal.value = false;
    }
};
</script>

<template>
    <Head :title="`Monthly Payroll Workspace — ${periodLabel}`" />
    <AuthenticatedLayout>
        <main class="mx-auto max-w-[1680px] space-y-4 px-3 py-4 text-slate-800 sm:px-5 md:py-5">
            <!-- Header with Month & Year Selector -->
            <header class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
                            {{ periodLabel }} · Monthly Payroll Workspace
                        </span>
                        <span
                            v-if="isLocked"
                            class="inline-flex items-center gap-1 rounded-full border border-rose-300 bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-800"
                        >
                            Historical Month Locked
                        </span>
                    </div>
                    <h1 class="text-xl font-bold text-slate-900 sm:text-2xl">
                        Salary Estimations &amp; Monthly Payroll
                    </h1>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5">
                        <label class="text-xs font-semibold text-slate-600" for="payroll-month-select">
                            Period:
                        </label>
                        <select
                            id="payroll-month-select"
                            v-model.number="selectedMonth"
                            @change="reloadWorkspace"
                            class="rounded-md border-slate-300 bg-white py-1 pl-2.5 pr-7 text-sm font-medium text-slate-800 focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option v-for="m in months" :key="m.value" :value="m.value">
                                {{ m.label }}
                            </option>
                        </select>
                        <select
                            id="payroll-year-select"
                            v-model.number="selectedYear"
                            @change="reloadWorkspace"
                            class="rounded-md border-slate-300 bg-white py-1 pl-2.5 pr-7 text-sm font-medium text-slate-800 focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option v-for="y in years" :key="y" :value="y">
                                {{ y }}
                            </option>
                        </select>
                    </div>

                </div>
            </header>

            <!-- Locked Historical Month Banner -->
            <div
                v-if="isLocked"
                class="flex items-center gap-2.5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-medium text-rose-800"
            >
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                <span>
                    <strong>Historical Month Locked:</strong> {{ periodLabel }} is finalized (locked through October 2026). Bulk salary generation and component edits are disabled; read-only viewing and Horizontal Bill exports remain available.
                </span>
            </div>

            <!-- 4 Compact Top Summary Cards -->
            <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Monthly payroll summary">
                <div class="rounded-xl border border-slate-200 bg-white px-4 py-3.5 shadow-sm">
                    <div class="text-xs font-medium text-slate-500">Matching Employees</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-2xl font-bold text-slate-900">
                            {{ Number(summary?.active_eligible || 0) }}
                        </span>
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">
                            {{ Number(summary?.generated_count || 0) }} Generated
                        </span>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white px-4 py-3.5 shadow-sm">
                    <div class="text-xs font-medium text-slate-500">Regular Net (BDT)</div>
                    <div class="mt-1 font-mono text-2xl font-bold text-slate-900">
                        ৳{{ formatMoney(summary?.regular_net_total) }}
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white px-4 py-3.5 shadow-sm">
                    <div class="text-xs font-medium text-slate-500">Net Arrears (BDT)</div>
                    <div
                        class="mt-1 font-mono text-2xl font-bold"
                        :class="
                            Number(summary?.net_arrear_total || 0) > 0
                                ? 'text-indigo-700'
                                : Number(summary?.net_arrear_total || 0) < 0
                                    ? 'text-rose-700'
                                    : 'text-slate-700'
                        "
                    >
                        ৳{{ formatMoney(summary?.net_arrear_total) }}
                    </div>
                </div>

                <div class="rounded-xl border border-emerald-200 bg-emerald-50/70 px-4 py-3.5 shadow-sm">
                    <div class="text-xs font-semibold text-emerald-800">Total Net Payable (BDT)</div>
                    <div class="mt-1 font-mono text-2xl font-extrabold text-emerald-900">
                        ৳{{ formatMoney(summary?.net_payable_total) }}
                    </div>
                </div>
            </section>

            <!-- Upgraded 2-Row Filter Bar + Clean Action Bar -->
            <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="space-y-3 border-b border-slate-200 px-4 py-3.5">
                    <!-- Row 1: Multi-Select Employee Type (Tag-Chip Selector) -->
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold text-slate-600">Employee Type:</span>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span
                                v-for="empType in selectedEmployeeTypes"
                                :key="empType"
                                class="inline-flex items-center gap-1 rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-800"
                            >
                                <span>{{ empType }}</span>
                                <button
                                    type="button"
                                    @click="removeEmployeeType(empType)"
                                    class="ml-0.5 rounded-full p-0.5 text-indigo-500 hover:bg-indigo-200/70 hover:text-indigo-900"
                                    :aria-label="`Remove ${empType}`"
                                >
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </span>

                            <select
                                v-if="availableEmployeeTypesToAdd.length"
                                v-model="employeeTypeToAdd"
                                @change="addEmployeeType"
                                aria-label="Add employee type filter"
                                class="rounded-full border-slate-300 bg-white py-0.5 pl-2.5 pr-7 text-xs font-medium text-slate-600 focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="">+ Add Type</option>
                                <option v-for="type in availableEmployeeTypesToAdd" :key="type.value" :value="type.value">
                                    {{ type.value }} ({{ type.count }})
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Row 2: Full Filters + Action Buttons -->
                    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-6 xl:grid-cols-12">
                        <div class="xl:col-span-3">
                            <label class="mb-1 block text-[11px] font-semibold text-slate-600" for="filter-search">
                                Search (Code / Name)
                            </label>
                            <input
                                id="filter-search"
                                v-model="search"
                                @input="onSearchInput"
                                @keyup.enter="reloadWorkspace"
                                type="text"
                                placeholder="e.g. 10012 or Nigar..."
                                class="w-full rounded-lg border-slate-300 py-1.5 text-xs focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>

                        <div class="xl:col-span-2">
                            <label class="mb-1 block text-[11px] font-semibold text-slate-600" for="filter-category">
                                Category / Class
                            </label>
                            <select
                                id="filter-category"
                                v-model="categoryId"
                                @change="reloadWorkspace"
                                class="w-full rounded-lg border-slate-300 py-1.5 text-xs focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="">All Categories</option>
                                <option
                                    v-for="cat in filterOptions?.categories || []"
                                    :key="cat.id"
                                    :value="String(cat.id)"
                                >
                                    {{ cat.name }}
                                </option>
                            </select>
                        </div>

                        <div class="xl:col-span-2">
                            <label class="mb-1 block text-[11px] font-semibold text-slate-600" for="filter-department">
                                Department
                            </label>
                            <select
                                id="filter-department"
                                v-model="departmentId"
                                @change="reloadWorkspace"
                                class="w-full rounded-lg border-slate-300 py-1.5 text-xs focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="">All Departments</option>
                                <option
                                    v-for="dept in filterOptions?.departments || []"
                                    :key="dept.id"
                                    :value="String(dept.id)"
                                >
                                    {{ dept.name }}
                                </option>
                            </select>
                        </div>

                        <div class="xl:col-span-2">
                            <label class="mb-1 block text-[11px] font-semibold text-slate-600" for="filter-designation">
                                Designation
                            </label>
                            <select
                                id="filter-designation"
                                v-model="designation"
                                @change="reloadWorkspace"
                                class="w-full rounded-lg border-slate-300 py-1.5 text-xs focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="">All Designations</option>
                                <option
                                    v-for="desig in filterOptions?.designations || []"
                                    :key="desig"
                                    :value="desig"
                                >
                                    {{ desig }}
                                </option>
                            </select>
                        </div>

                        <div class="xl:col-span-2">
                            <label class="mb-1 block text-[11px] font-semibold text-slate-600" for="filter-status">
                                Status
                            </label>
                            <select
                                id="filter-status"
                                v-model="statusFilter"
                                @change="reloadWorkspace"
                                class="w-full rounded-lg border-slate-300 py-1.5 text-xs focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="ALL">All</option>
                                <option value="PENDING">Pending</option>
                                <option value="GENERATED">Generated</option>
                            </select>
                        </div>

                        <div class="flex items-end xl:col-span-1">
                            <button
                                type="button"
                                @click="resetFilters"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Reset
                            </button>
                        </div>
                    </div>

                    <!-- Action Bar -->
                    <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                        <div class="text-xs text-slate-500">
                            <span v-if="selectedIds.length > 0" class="font-semibold text-indigo-700">
                                {{ selectedIds.length }} employee(s) selected on this page
                            </span>
                            <span v-else>
                                Showing {{ rows?.from || 0 }}–{{ rows?.to || 0 }} of {{ rows?.total || 0 }} matching employees
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                :disabled="isLocked || generating || generationTargetCount === 0"
                                @click="openConfirmGenerate"
                                class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40"
                            >
                                <span v-if="generating">Generating...</span>
                                <span v-else>Bulk Calculate &amp; Generate Salary ({{ generationScopeLabel }})</span>
                            </button>

                            <button
                                type="button"
                                :disabled="generating || exportingHorizontal || !rowsForExport.length"
                                @click="exportHorizontalBill('excel')"
                                class="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 transition hover:bg-emerald-100 disabled:opacity-40"
                            >
                                Horizontal Bill Excel
                            </button>

                            <button
                                type="button"
                                :disabled="generating || exportingHorizontal || !rowsForExport.length"
                                @click="exportHorizontalBill('pdf')"
                                class="rounded-lg border border-indigo-300 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50 disabled:opacity-40"
                            >
                                Horizontal Bill PDF
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Status / Feedback Banner -->
                <div
                    v-if="notice"
                    role="status"
                    class="border-b px-4 py-2.5 text-xs font-medium"
                    :class="
                        noticeTone === 'success'
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                            : noticeTone === 'error'
                                ? 'border-rose-200 bg-rose-50 text-rose-800'
                                : 'border-indigo-200 bg-indigo-50 text-indigo-800'
                    "
                >
                    {{ notice }}
                </div>

                <!-- Employee payroll table -->
                <div class="w-full max-w-full overflow-x-auto">
                    <table class="w-full table-auto text-left text-xs">
                        <thead class="bg-slate-50 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="w-9 px-2.5 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        :checked="allPageSelected"
                                        :disabled="isLocked || !selectablePageRows.length"
                                        @change="toggleSelectAllPage($event.target.checked)"
                                        class="h-3.5 w-3.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-40"
                                        aria-label="Select all pending employees on page"
                                    />
                                </th>
                                <th class="whitespace-nowrap px-2.5 py-2">Code</th>
                                <th class="px-2.5 py-2">Employee</th>
                                <th class="whitespace-nowrap px-2.5 py-2">Category</th>
                                <th class="whitespace-nowrap px-2.5 py-2 text-right">Basic</th>
                                <th class="whitespace-nowrap px-2.5 py-2 text-right">Reg. Net</th>
                                <th class="whitespace-nowrap px-2.5 py-2 text-right">Arrear</th>
                                <th class="whitespace-nowrap px-2.5 py-2 text-right">Net Payable</th>
                                <th class="whitespace-nowrap px-2.5 py-2 text-center">Status</th>
                                <th class="sticky right-0 z-10 min-w-[7.25rem] whitespace-nowrap border-l border-slate-200 bg-slate-50 px-2.5 py-2 text-center">
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="row in pageRows"
                                :key="row.user_id"
                                class="group align-middle transition hover:bg-slate-50/80"
                            >
                                <td class="px-2.5 py-2 text-center">
                                    <input
                                        v-model="selectedIds"
                                        type="checkbox"
                                        :value="row.user_id"
                                        :disabled="!canSelectRow(row)"
                                        class="h-3.5 w-3.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-40"
                                        :aria-label="`Select ${row.employee_code}`"
                                    />
                                </td>
                                <td class="whitespace-nowrap px-2.5 py-2 font-mono text-xs font-semibold text-slate-800">
                                    {{ row.employee_code }}
                                </td>
                                <td class="max-w-[240px] px-2.5 py-2">
                                    <div class="truncate font-semibold text-slate-900" :title="row.name">
                                        {{ row.name }}
                                    </div>
                                    <div
                                        class="truncate text-[11px] text-slate-500"
                                        :title="`${row.designation_name || 'No designation'}${row.department_name ? ' · ' + row.department_name : ''}`"
                                    >
                                        {{ row.designation_name || 'No designation' }}
                                        <span v-if="row.department_name"> · {{ row.department_name }}</span>
                                    </div>
                                </td>
                                <td class="min-w-24 px-2.5 py-2">
                                    <div class="font-medium text-slate-800">
                                        {{ row.category_names || '—' }}
                                    </div>
                                    <div class="text-[11px] text-slate-500">
                                        {{ row.grade_name || 'No grade' }}
                                        <span
                                            v-if="row.employee_type && row.employee_type !== 'Active'"
                                            class="ml-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800"
                                        >
                                            {{ row.employee_type }}
                                        </span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-2.5 py-2 text-right font-mono text-slate-700">
                                    {{ formatMoney(row.basic_pay) }}
                                </td>
                                <td class="whitespace-nowrap px-2.5 py-2 text-right font-mono font-medium text-slate-800">
                                    {{ formatMoney(row.regular_net) }}
                                </td>
                                <td class="whitespace-nowrap px-2.5 py-2 text-right font-mono">
                                    <span
                                        v-if="Math.abs(Number(row.net_arrear || 0)) >= 0.005"
                                        class="inline-flex items-center rounded px-1.5 py-0.5 font-semibold"
                                        :class="
                                            Number(row.net_arrear) > 0
                                                ? 'bg-indigo-100 text-indigo-800'
                                                : 'bg-rose-100 text-rose-800'
                                        "
                                    >
                                        {{ Number(row.net_arrear) > 0 ? '+' : '' }}{{ formatMoney(row.net_arrear) }}
                                    </span>
                                    <span v-else class="text-slate-400">0.00</span>
                                </td>
                                <td class="whitespace-nowrap px-2.5 py-2 text-right font-mono text-xs font-bold text-slate-900">
                                    {{ formatMoney(row.net_payable) }}
                                </td>
                                <td class="whitespace-nowrap px-2.5 py-2 text-center">
                                    <span
                                        v-if="row.is_generated"
                                        class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800"
                                    >
                                        Generated
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800"
                                    >
                                        Pending
                                    </span>
                                </td>
                                <td class="sticky right-0 z-10 min-w-[7.25rem] whitespace-nowrap border-l border-slate-100 bg-white px-2.5 py-2 text-center group-hover:bg-slate-50">
                                    <div class="inline-flex items-center justify-center gap-1">
                                        <!-- 1. Profile Edit (Pencil Icon) -->
                                        <button
                                            type="button"
                                            :disabled="loadingProfile"
                                            @click="openProfileEdit(row)"
                                            title="Edit Employee Profile"
                                            aria-label="Edit Employee Profile"
                                            class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 transition hover:border-amber-300 hover:bg-amber-50 hover:text-amber-700"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                            </svg>
                                        </button>

                                        <!-- 2. Salary Setup (Banknote / Wallet Icon) -->
                                        <button
                                            type="button"
                                            @click="openSalarySetup(row)"
                                            title="Monthly Salary Setup"
                                            aria-label="Monthly Salary Setup"
                                            class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-indigo-200 bg-indigo-50 text-indigo-700 transition hover:bg-indigo-100"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                            </svg>
                                        </button>

                                        <!-- 3. Salary Bill / Certificate View (Document / Magnifying Glass Icon) -->
                                        <button
                                            type="button"
                                            @click="openBillPreview(row)"
                                            title="View / Print Salary Bill"
                                            aria-label="View / Print Salary Bill"
                                            class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 text-emerald-700 transition hover:bg-emerald-100"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!pageRows.length">
                                <td colspan="10" class="px-4 py-10 text-center text-sm text-slate-500">
                                    No employees match the current filters for {{ periodLabel }}.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-xs text-slate-500">
                    <div class="flex items-center gap-3">
                        <span>
                            Showing {{ rows?.from || 0 }}–{{ rows?.to || 0 }} of {{ rows?.total || 0 }} employees
                        </span>
                        <select
                            v-model="perPage"
                            @change="reloadWorkspace"
                            class="rounded-md border-slate-300 py-1 text-xs text-slate-700"
                            aria-label="Rows per page"
                        >
                            <option v-for="n in [25, 50, 100, 200]" :key="n" :value="String(n)">
                                {{ n }} / page
                            </option>
                        </select>
                    </div>
                    <nav class="flex flex-wrap gap-1" aria-label="Employee pagination">
                        <Link
                            v-for="(link, idx) in rows?.links || []"
                            :key="idx"
                            :href="link.url || '#'"
                            :preserve-scroll="true"
                            class="rounded-md border px-2.5 py-1.5"
                            :class="[
                                link.active
                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                    : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50',
                                !link.url ? 'pointer-events-none opacity-40' : '',
                            ]"
                            v-html="link.label"
                        />
                    </nav>
                </div>
            </section>
        </main>

        <EditEmployeeProfileModal
            :employee="profileEditEmployee"
            :department-offices="profileEditOptions.departmentOffices"
            :pay-scales="profileEditOptions.payScales"
            :grades="profileEditOptions.grades"
            :payroll-groups="profileEditOptions.payrollGroups"
            :user-categories="profileEditOptions.userCategories"
            :job-statuses="profileEditOptions.jobStatuses"
            @close="profileEditEmployee = null"
            @saved="onProfileSaved"
        />

        <!-- 2. Monthly Salary Setup Modal -->
        <SalarySetupModal
            :show="setupModalOpen"
            :user-id="setupModalUserId"
            :initial-year="year"
            :initial-month="month"
            @close="closeSalarySetup"
            @saved="onSalarySetupSaved"
        />

        <!-- 3. Printable Salary Bill / Pay Certificate Modal -->
        <SalaryBillPreviewModal
            :show="billPreviewOpen"
            :employee="billPreviewEmployee"
            :year="year"
            :month="month"
            @close="billPreviewOpen = false"
        />
    </AuthenticatedLayout>
</template>
