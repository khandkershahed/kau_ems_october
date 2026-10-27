<script setup>
import { ref, computed, onMounted, watch } from "vue";
import { Head } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Modal from "@/Components/Modal.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import DangerButton from "@/Components/DangerButton.vue";
import TextInput from "@/Components/TextInput.vue";
import InputLabel from "@/Components/InputLabel.vue";
import { useSalaryGenerateStore } from "@/stores/salaryGenerate";
import Swal from "sweetalert2";
import axios from "axios";
import {
    readyIds,
    hashesForSelected,
    previewCsv,
    isReady,
} from "@/salaryBulkStep06.js";

const props = defineProps({
    payScales: { type: Array, default: () => [] },
    grades: { type: Array, default: () => [] },
    payrollGroupTypes: { type: Array, default: () => [] },
    payrollGroups: { type: Array, default: () => [] },
    userCategories: { type: Array, default: () => [] },
    departmentOffices: { type: Array, default: () => [] },
    currentMonth: { type: Number, default: () => new Date().getMonth() + 1 },
    currentYear: { type: Number, default: () => new Date().getFullYear() },
    religions: { type: Array, default: () => [] },
    genders: { type: Array, default: () => [] },
    jobStatuses: { type: Array, default: () => [] },
});

const store = useSalaryGenerateStore();

// UI State
const showFilters = ref(true);
const singleGenerateModalOpen = ref(false);
const bulkGenerateModalOpen = ref(false);
const overrideModalOpen = ref(false);
const bulkOverrideModalOpen = ref(false);

const pendingGenerateUser = ref(null);
const pendingOverrideBill = ref(null);
const overrideTargetStatus = ref("approved");
const bulkOverrideTargetStatus = ref("approved");

// Multi-select Checkbox State
const selectedBillIds = ref([]);
const isSelectAllFiltered = ref(false);

// Months list for dropdowns
const months = [
    { id: 1, name: "January" },
    { id: 2, name: "February" },
    { id: 3, name: "March" },
    { id: 4, name: "April" },
    { id: 5, name: "May" },
    { id: 6, name: "June" },
    { id: 7, name: "July" },
    { id: 8, name: "August" },
    { id: 9, name: "September" },
    { id: 10, name: "October" },
    { id: 11, name: "November" },
    { id: 12, name: "December" },
];

// Available years (5 years around current)
const years = computed(() => {
    const curr = props.currentYear || new Date().getFullYear();
    return [curr - 2, curr - 1, curr, curr + 1, curr + 2];
});

// Grades filtered by selected Pay Scale
const filteredGrades = computed(() => {
    if (!store.filters.pay_scale_id) return props.grades;
    return props.grades.filter(
        (g) => String(g.pay_scale_id) === String(store.filters.pay_scale_id),
    );
});

// Format currency
const formatMoney = (val) => {
    const num = parseFloat(val);
    if (isNaN(num)) return "0.00";
    return num.toLocaleString("en-US", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

// Month name helper
const getMonthName = (mId) => {
    const m = months.find((item) => item.id === parseInt(mId));
    return m ? m.name : `Month ${mId}`;
};

// Step 06: all selected employees use the server's authoritative read-only bill composer.
// Requests remain bounded at 25 employees; no preview creates a salary/GPF row.
const phase5PreviewOpen = ref(false);
const phase5PreviewLoading = ref(false);
const phase5CandidateIds = ref([]);
const phase5PreviewRows = ref([]);
const phase5SelectedIds = ref([]);
const phase5Error = ref("");
const phase5LoadedCount = ref(0);
const phase5AlreadyGeneratedCount = ref(0);
const phase5PreviewComplete = ref(false);
const phase5ResumeToken = ref("");
const phase5ReadyCount = computed(
    () => phase5PreviewRows.value.filter(isReady).length,
);
const phase5ExceptionCount = computed(
    () => phase5PreviewRows.value.filter((r) => !isReady(r)).length,
);
const phase5PreviewTotals = computed(() => {
    const selected = new Set(phase5SelectedIds.value.map(Number));
    return phase5PreviewRows.value
        .filter((r) => selected.has(Number(r.user_id)) && isReady(r))
        .reduce((total, r) => total + Number(r.net_payable || 0), 0);
});
const resumePhase5Token = async () => {
    const token = phase5ResumeToken.value.trim();
    if (!/^[0-9a-f]{8}-[0-9a-f-]{27,}$/i.test(token)) {
        phase5Error.value =
            "Enter the payroll batch UUID shown in the previous run.";
        return;
    }
    store.bulkRunToken = token;
    store.bulkRunProgress = { total: 0, done: 0, counts: {}, remaining: 1 };
    await store.resumeBulkRun();
};
const bulkPreviewParams = () => ({
    ...store.filters,
    monthId: Number(store.filters.monthId),
    salaryGenerationYear: Number(store.filters.salaryGenerationYear),
});
const openPhase5Preview = async () => {
    if (phase5PreviewLoading.value) return;
    phase5PreviewOpen.value = true;
    phase5PreviewLoading.value = true;
    phase5PreviewComplete.value = false;
    phase5Error.value = "";
    phase5PreviewRows.value = [];
    phase5SelectedIds.value = [];
    phase5CandidateIds.value = [];
    phase5LoadedCount.value = 0;
    phase5AlreadyGeneratedCount.value = 0;
    const params = bulkPreviewParams();
    try {
        const first = (
            await axios.get(route("salary-generates.bulk-candidates"), {
                params,
            })
        ).data;
        const ids = first.user_ids || [];
        phase5AlreadyGeneratedCount.value = Number(
            first.already_generated_count || 0,
        );
        phase5CandidateIds.value = ids;
        phase5PreviewRows.value = [...(first.preview || [])];
        phase5LoadedCount.value = phase5PreviewRows.value.length;
        // Freeze the initial identities. Offset-only pagination can skip employees
        // when an unrelated request generates a bill midway through the scan.
        for (let offset = 25; offset < ids.length; offset += 25) {
            const subset = ids.slice(offset, offset + 25);
            const { data } = await axios.get(
                route("salary-generates.bulk-candidates"),
                {
                    params: { ...params, preview_ids: subset },
                },
            );
            phase5PreviewRows.value.push(...(data.preview || []));
            phase5LoadedCount.value = phase5PreviewRows.value.length;
        }
        if (phase5PreviewRows.value.length !== ids.length)
            throw new Error(
                "Candidate preview was incomplete; refresh before generation.",
            );
        phase5SelectedIds.value = readyIds(phase5PreviewRows.value);
        phase5PreviewComplete.value = true;
    } catch (e) {
        phase5Error.value =
            e.response?.data?.message ||
            e.message ||
            "Unable to preview candidates.";
    } finally {
        phase5PreviewLoading.value = false;
    }
};
const recalculatePhase5Row = async (row) => {
    const index = phase5PreviewRows.value.findIndex(
        (r) => Number(r.user_id) === Number(row.user_id),
    );
    if (index < 0) return;
    phase5SelectedIds.value = phase5SelectedIds.value.filter(
        (id) => Number(id) !== Number(row.user_id),
    );
    try {
        const { data } = await axios.get(
            route("salary-generates.bulk-candidates"),
            {
                params: {
                    ...bulkPreviewParams(),
                    preview_ids: [Number(row.user_id)],
                },
            },
        );
        const updated = data.preview?.[0];
        if (!updated) throw new Error("No refreshed preview returned.");
        phase5PreviewRows.value.splice(index, 1, updated);
        if (isReady(updated))
            phase5SelectedIds.value.push(Number(updated.user_id));
    } catch (e) {
        phase5Error.value = e.response?.data?.message || e.message;
    }
};
const recalculatePhase5Selected = async () => {
    if (phase5PreviewLoading.value || !phase5PreviewComplete.value) return;
    const selected = [...new Set(phase5SelectedIds.value.map(Number))];
    if (!selected.length) return;
    phase5PreviewLoading.value = true;
    phase5PreviewComplete.value = false;
    phase5Error.value = "";
    try {
        for (let offset = 0; offset < selected.length; offset += 25) {
            const { data } = await axios.get(
                route("salary-generates.bulk-candidates"),
                {
                    params: {
                        ...bulkPreviewParams(),
                        preview_ids: selected.slice(offset, offset + 25),
                    },
                },
            );
            for (const changed of data.preview || []) {
                const i = phase5PreviewRows.value.findIndex(
                    (r) => Number(r.user_id) === Number(changed.user_id),
                );
                if (i >= 0) phase5PreviewRows.value.splice(i, 1, changed);
            }
        }
        phase5SelectedIds.value = selected.filter((id) => {
            const row = phase5PreviewRows.value.find(
                (r) => Number(r.user_id) === id,
            );
            return isReady(row);
        });
        phase5PreviewComplete.value = true;
    } catch (e) {
        phase5Error.value =
            e.response?.data?.message ||
            e.message ||
            "Recalculation failed; reopen full preview.";
    } finally {
        phase5PreviewLoading.value = false;
    }
};
const exportPhase5Preview = (exceptionsOnly = false) => {
    const content = previewCsv(phase5PreviewRows.value, exceptionsOnly);
    const url = URL.createObjectURL(
        new Blob(["\ufeff", content], { type: "text/csv;charset=utf-8" }),
    );
    const link = document.createElement("a");
    link.href = url;
    link.download = `KAU_Bulk_${exceptionsOnly ? "Exceptions" : "Preview"}_${store.filters.salaryGenerationYear}_${store.filters.monthId}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
};
const generatePhase5Selected = async () => {
    if (!phase5PreviewComplete.value || !phase5SelectedIds.value.length) return;
    const chosen = [...new Set(phase5SelectedIds.value.map(Number))];
    try {
        const hashes = hashesForSelected(phase5PreviewRows.value, chosen);
        if (
            !window.confirm(
                `Generate ${chosen.length} explicitly selected salary bills? Total preview net: BDT ${formatMoney(phase5PreviewTotals.value)}. Existing bills will be preserved.`,
            )
        )
            return;
        phase5PreviewOpen.value = false;
        await store.generateBulk(chosen, false, hashes);
    } catch (e) {
        phase5Error.value = e.message;
    }
};

// Initial data fetch
onMounted(() => {
    store.fetchBills(1);
});

// Debounce timer for search
let searchDebounceTimer = null;
const handleSearchInput = () => {
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => {
        store.fetchBills(1);
    }, 350);
};

// Apply all filters
const applyFilters = () => {
    clearSelection();
    store.fetchBills(1);
};

// Reset filters
const resetFilters = () => {
    clearSelection();
    store.resetFilters();
};

// Sorting
const sortBy = (field) => {
    store.setSort(field);
};

// Single generate action
const confirmGenerateSingle = (row) => {
    pendingGenerateUser.value = row;
    singleGenerateModalOpen.value = true;
};

const executeGenerateSingle = async () => {
    if (!pendingGenerateUser.value) return;
    const userId = pendingGenerateUser.value.user_id;
    singleGenerateModalOpen.value = false;
    await store.generateSingle(userId);
    pendingGenerateUser.value = null;
};

// Employee/month-specific manual earning or deduction, before generating a bill.
// This does not change saved employee defaults or earlier salary records.
const openManualAdjustment = async (row) => {
    if (row.bill_status === "generated") {
        await Swal.fire(
            "Bill already exists",
            "Review the existing bill before applying a new correction.",
            "warning",
        );
        return;
    }
    const period = `${getMonthName(store.filters.monthId)} ${store.filters.salaryGenerationYear}`;
    let prior = [];
    try {
        const response = await axios.get(
            route("salary-manual-adjustments.index"),
            {
                params: {
                    user_id: row.user_id,
                    salary_month: store.filters.monthId,
                    salary_year: store.filters.salaryGenerationYear,
                },
            },
        );
        prior = response.data || [];
    } catch (error) {
        await Swal.fire(
            "Manual entries unavailable",
            error.response?.data?.message || error.message,
            "error",
        );
        return;
    }
    const result = await Swal.fire({
        title: "Manual salary item",
        text: `${row.name} — ${period} (${prior.length} existing manual items)`,
        html: `<div class="space-y-2 text-left">
            <label for="manual_direction" class="block text-sm">Item type</label>
            <select id="manual_direction" class="swal2-select !m-0 !w-full"><option value="addition">Addition (+)</option><option value="deduction">Deduction (−)</option></select>
            <label for="manual_title" class="block text-sm">Title</label>
            <input id="manual_title" class="swal2-input !m-0 !w-full" maxlength="120" placeholder="e.g. Approved arrear adjustment">
            <label for="manual_amount" class="block text-sm">Amount (BDT)</label>
            <input id="manual_amount" class="swal2-input !m-0 !w-full" type="number" min="0.01" step="0.01">
            <label for="manual_reason" class="block text-sm">Reason / supporting order</label>
            <textarea id="manual_reason" class="swal2-textarea !m-0 !w-full" placeholder="Enter the reason and reference"></textarea>
            <label for="manual_evidence" class="block text-sm">Evidence / approval reference</label>
            <input id="manual_evidence" class="swal2-input !m-0 !w-full" maxlength="500" placeholder="Signed order no., letter reference, document ID or link">
            <p class="text-xs text-gray-500">Saved items are included only when the selected month's salary bill is generated. Existing items: ${prior.length}.</p>
        </div>`,
        showCancelButton: true,
        confirmButtonText: "Save manual item",
        showLoaderOnConfirm: true,
        preConfirm: async () => {
            const get = (id) =>
                document.getElementById(id)?.value?.trim() || "";
            const title = get("manual_title");
            const amount = Number(get("manual_amount"));
            const reason = get("manual_reason");
            const evidence_reference = get("manual_evidence");
            if (
                title.length < 3 ||
                reason.length < 5 ||
                evidence_reference.length < 3 ||
                !Number.isFinite(amount) ||
                amount <= 0
            ) {
                Swal.showValidationMessage(
                    "Provide a title, positive amount, and a documented reason.",
                );
                return false;
            }
            try {
                await axios.post(route("salary-manual-adjustments.store"), {
                    user_id: row.user_id,
                    salary_month: Number(store.filters.monthId),
                    salary_year: Number(store.filters.salaryGenerationYear),
                    direction: get("manual_direction"),
                    title,
                    amount,
                    reason,
                    evidence_reference,
                });
                return true;
            } catch (error) {
                Swal.showValidationMessage(
                    error.response?.data?.message ||
                        "Could not save the adjustment.",
                );
                return false;
            }
        },
    });
    if (result.isConfirmed)
        await Swal.fire(
            "Saved",
            "This item will appear on the generated salary bill.",
            "success",
        );
};

// Bulk generate action
const confirmBulkGenerate = () => {
    bulkGenerateModalOpen.value = true;
};

const executeBulkGenerate = async () => {
    bulkGenerateModalOpen.value = false;
    await openPhase5Preview(); // Explicitly preview every candidate before selection/posting.
};

// Send review action
const handleSendReview = async (row) => {
    const result = await Swal.fire({
        title: "Send Review Email?",
        text: `Send salary bill review breakdown email to ${row.name} for ${getMonthName(row.month_id)} ${row.year}?`,
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: "#2563eb",
        cancelButtonColor: "#6b7280",
        confirmButtonText: "Yes, Send review",
        cancelButtonText: "Cancel",
    });

    if (result.isConfirmed) {
        await store.sendReview(row.salary_generate_id);
    }
};

// Bulk send review action
const confirmBulkSendReview = async () => {
    const total = store.summary.generated || 0;
    if (total === 0) return;
    const result = await Swal.fire({
        title: "Send Bulk Review Emails?",
        text: `Send salary bill review breakdown emails to all ${total} generated employee bill(s) for ${getMonthName(store.filters.monthId)} ${store.filters.salaryGenerationYear}?`,
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: "#2563eb",
        cancelButtonColor: "#6b7280",
        confirmButtonText: `Yes, Send to All (${total})`,
        cancelButtonText: "Cancel",
    });

    if (result.isConfirmed) {
        await store.sendBulkReview([], true);
    }
};

// Current page selectable bills (only rows with generated salary bills)
const currentPageSelectableBills = computed(() => {
    return (store.bills.data || []).filter((b) => !!b.salary_generate_id);
});

// All on current page selected
const isAllPageSelected = computed(() => {
    if (currentPageSelectableBills.value.length === 0) return false;
    return currentPageSelectableBills.value.every((b) =>
        selectedBillIds.value.includes(b.salary_generate_id),
    );
});

// Indeterminate state for current page checkbox
const isPageIndeterminate = computed(() => {
    const selectedCount = currentPageSelectableBills.value.filter((b) =>
        selectedBillIds.value.includes(b.salary_generate_id),
    ).length;
    return (
        selectedCount > 0 &&
        selectedCount < currentPageSelectableBills.value.length
    );
});

// Toggle select all on current page
const toggleSelectAllPage = (e) => {
    isSelectAllFiltered.value = false;
    if (e.target.checked) {
        const pageIds = currentPageSelectableBills.value.map(
            (b) => b.salary_generate_id,
        );
        const newSet = new Set([...selectedBillIds.value, ...pageIds]);
        selectedBillIds.value = Array.from(newSet);
    } else {
        const pageIds = new Set(
            currentPageSelectableBills.value.map((b) => b.salary_generate_id),
        );
        selectedBillIds.value = selectedBillIds.value.filter(
            (id) => !pageIds.has(id),
        );
    }
};

// Select all filtered records across all pages
const selectAllFilteredRecords = () => {
    isSelectAllFiltered.value = true;
    const pageIds = currentPageSelectableBills.value.map(
        (b) => b.salary_generate_id,
    );
    const newSet = new Set([...selectedBillIds.value, ...pageIds]);
    selectedBillIds.value = Array.from(newSet);
};

// Clear selection
const clearSelection = () => {
    selectedBillIds.value = [];
    isSelectAllFiltered.value = false;
};

// Download selected records as Excel spreadsheet
const handleDownloadExcel = async () => {
    if (store.exportingExcel) return;

    if (isSelectAllFiltered.value) {
        await store.exportToExcel({ allFiltered: true });
    } else if (selectedBillIds.value.length > 0) {
        const pageSelected = (store.bills.data || []).filter((b) =>
            selectedBillIds.value.includes(b.salary_generate_id),
        );
        if (pageSelected.length === selectedBillIds.value.length) {
            await store.exportToExcel({ records: pageSelected });
        } else {
            await store.exportToExcel({ selectedIds: selectedBillIds.value });
        }
    } else {
        const total = store.summary.generated || store.summary.total || 0;
        if (total === 0) {
            Swal.fire({
                icon: "info",
                title: "No Records",
                text: "There are no salary records available to export with the current filters.",
            });
            return;
        }

        const result = await Swal.fire({
            title: "Download Excel?",
            text: `No individual records selected. Would you like to download all ${total} filtered records as Excel?`,
            icon: "question",
            showCancelButton: true,
            confirmButtonColor: "#059669",
            cancelButtonColor: "#6b7280",
            confirmButtonText: `Yes, Download All (${total})`,
            cancelButtonText: "Cancel",
        });

        if (result.isConfirmed) {
            await store.exportToExcel({ allFiltered: true });
        }
    }
};

// Open bulk override modal
const openBulkOverrideModal = () => {
    if (selectedBillIds.value.length === 0 && !isSelectAllFiltered.value)
        return;
    bulkOverrideTargetStatus.value = "approved";
    bulkOverrideModalOpen.value = true;
};

// Execute bulk override status
const executeBulkOverrideStatus = async () => {
    const status = bulkOverrideTargetStatus.value;
    const ids = isSelectAllFiltered.value ? [] : selectedBillIds.value;
    const allFiltered = isSelectAllFiltered.value;

    bulkOverrideModalOpen.value = false;
    const res = await store.overrideBulkReviewStatus(ids, status, allFiltered);
    if (res?.success) {
        clearSelection();
    }
};

// Single Override review modal
const openOverrideModal = (row) => {
    pendingOverrideBill.value = row;
    overrideTargetStatus.value = "approved";
    overrideModalOpen.value = true;
};

const executeOverrideStatus = async () => {
    if (!pendingOverrideBill.value?.salary_generate_id) return;
    const id = pendingOverrideBill.value.salary_generate_id;
    const status = overrideTargetStatus.value;
    overrideModalOpen.value = false;
    await store.overrideReviewStatus(id, status);
    pendingOverrideBill.value = null;
};

// View details modal
const openViewModal = (row) => {
    store.openViewModal(row);
};

const closeViewModal = () => {
    store.closeViewModal();
};

// Active filter chip helpers
const removeFilter = (key) => {
    clearSelection();
    if (key === "monthId") {
        store.filters.monthId = props.currentMonth;
    } else if (key === "salaryGenerationYear") {
        store.filters.salaryGenerationYear = props.currentYear;
    } else if (key === "salaryGeneratedStatus") {
        store.filters.salaryGeneratedStatus = "generated";
    } else if (key === "reviewStatus") {
        store.filters.reviewStatus = "all";
    } else {
        store.filters[key] = "";
    }
    store.fetchBills(1);
};
</script>

<template>
    <Head title="Review & Generate Salary Bills" />

    <AuthenticatedLayout>
        <div class="py-6 sm:py-8 px-4 sm:px-6 lg:px-8 mx-auto space-y-6">
            <!-- Top Header & Primary Actions -->
            <div
                class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white dark:bg-gray-800 p-5 sm:p-6 rounded-md shadow-sm border border-gray-100 dark:border-gray-700/60 transition-colors"
            >
                <div>
                    <div class="flex items-center gap-3">
                        <div
                            class="p-2.5 rounded-md bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400"
                        >
                            <svg
                                class="w-6 h-6"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                />
                            </svg>
                        </div>
                        <div>
                            <h1
                                class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-gray-100 tracking-tight"
                            >
                                Review & Generate Salary Bills
                            </h1>
                            <p
                                class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-0.5"
                            >
                                Directory of staff members, payroll
                                configuration & monthly salary bills generation
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Month / Year Picker & Bulk Generate Button -->
                <div class="flex flex-wrap items-center gap-2.5 sm:gap-3">
                    <div
                        class="flex items-center gap-1.5 bg-gray-50 dark:bg-gray-900/80 p-1.5 rounded-md border border-gray-200 dark:border-gray-700"
                    >
                        <select
                            v-model="store.filters.monthId"
                            @change="applyFilters"
                            class="text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-1.5 px-2.5 shadow-sm transition"
                        >
                            <option
                                v-for="m in months"
                                :key="m.id"
                                :value="m.id"
                            >
                                {{ m.name }}
                            </option>
                        </select>
                        <select
                            v-model="store.filters.salaryGenerationYear"
                            @change="applyFilters"
                            class="text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-1.5 px-2.5 shadow-sm transition"
                        >
                            <option v-for="y in years" :key="y" :value="y">
                                {{ y }}
                            </option>
                        </select>
                    </div>

                    <PrimaryButton
                        v-if="store.summary.not_generated > 0"
                        @click="confirmBulkGenerate"
                        :disabled="store.bulkGenerating"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500 text-white px-3.5 py-2 text-xs font-semibold shadow-sm transition"
                    >
                        <svg
                            v-if="!store.bulkGenerating"
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M13 10V3L4 14h7v7l9-11h-7z"
                            />
                        </svg>
                        <svg
                            v-else
                            class="animate-spin w-4 h-4 text-white"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            ></circle>
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8v8H4z"
                            ></path>
                        </svg>
                        <span
                            >Generate All Filtered ({{
                                store.summary.not_generated
                            }})</span
                        >
                    </PrimaryButton>

                    <button
                        type="button"
                        @click="openPhase5Preview"
                        :disabled="phase5PreviewLoading || store.bulkGenerating"
                        class="rounded border border-emerald-700 px-3 py-2 text-xs text-emerald-800 font-semibold disabled:opacity-50"
                    >
                        {{
                            phase5PreviewLoading
                                ? "Checking..."
                                : "Preview All / Select"
                        }}
                    </button>
                    <span v-if="phase5Error" class="text-xs text-red-600">{{
                        phase5Error
                    }}</span>

                    <div
                        v-if="store.bulkRunProgress"
                        class="flex flex-wrap gap-2 text-xs text-gray-700"
                    >
                        <span
                            >Payroll batch:
                            {{ store.bulkRunProgress.done ?? 0 }} /
                            {{ store.bulkRunProgress.total ?? 0 }}</span
                        >
                        <span
                            >Generated
                            {{ store.bulkRunProgress.counts?.generated ?? 0 }},
                            Skipped
                            {{ store.bulkRunProgress.counts?.skipped ?? 0 }},
                            Blocked
                            {{
                                store.bulkRunProgress.counts?.blocked ?? 0
                            }}</span
                        >
                        <a
                            v-if="store.bulkRunCsvUrl"
                            :href="store.bulkRunCsvUrl"
                            class="underline text-emerald-700"
                            >Download Result CSV</a
                        >
                        <button
                            v-if="
                                !store.bulkGenerating &&
                                store.bulkRunProgress.remaining > 0
                            "
                            @click="store.resumeBulkRun()"
                            class="border rounded px-2 py-1"
                        >
                            Resume Run
                        </button>
                        <span
                            v-if="store.bulkRunToken"
                            class="font-mono break-all"
                            :title="store.bulkRunToken"
                            >Run: {{ store.bulkRunToken }}</span
                        >
                    </div>

                    <div
                        class="flex items-center gap-2 text-xs"
                        aria-label="Resume saved payroll run"
                    >
                        <input
                            v-model="phase5ResumeToken"
                            placeholder="Paste previous batch UUID to resume"
                            class="border rounded px-2 py-1 w-72"
                        />
                        <button
                            type="button"
                            @click="resumePhase5Token"
                            :disabled="store.bulkGenerating"
                            class="border border-emerald-700 rounded px-2 py-1"
                        >
                            Resume by Run ID
                        </button>
                    </div>

                    <!-- Download Excel Button -->
                    <button
                        @click="handleDownloadExcel"
                        :disabled="store.exportingExcel"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-700 hover:bg-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500 text-white px-3.5 py-2 text-xs font-semibold shadow-sm transition disabled:opacity-50"
                        :title="
                            selectedBillIds.length > 0 || isSelectAllFiltered
                                ? 'Download selected records as Excel'
                                : 'Download all filtered records as Excel'
                        "
                    >
                        <svg
                            v-if="!store.exportingExcel"
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                            />
                        </svg>
                        <svg
                            v-else
                            class="animate-spin w-4 h-4 text-white"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            ></circle>
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8v8H4z"
                            ></path>
                        </svg>
                        <span>
                            Download Excel
                            <template
                                v-if="
                                    selectedBillIds.length > 0 ||
                                    isSelectAllFiltered
                                "
                            >
                                ({{
                                    isSelectAllFiltered
                                        ? store.summary.generated ||
                                          store.summary.total
                                        : selectedBillIds.length
                                }})
                            </template>
                        </span>
                    </button>

                    <button
                        v-if="selectedBillIds.length > 0 || isSelectAllFiltered"
                        @click="openBulkOverrideModal"
                        :disabled="store.bulkOverridingStatus"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500 text-white px-3.5 py-2 text-xs font-semibold shadow-sm transition disabled:opacity-50"
                        title="Override approval status for selected records"
                    >
                        <svg
                            v-if="!store.bulkOverridingStatus"
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                            />
                        </svg>
                        <svg
                            v-else
                            class="animate-spin w-4 h-4 text-white"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            ></circle>
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8v8H4z"
                            ></path>
                        </svg>
                        <span
                            >Override Confirm ({{
                                isSelectAllFiltered
                                    ? store.summary.generated
                                    : selectedBillIds.length
                            }})</span
                        >
                    </button>

                    <!-- <button
                        v-if="store.summary.generated > 0"
                        @click="confirmBulkSendReview"
                        :disabled="store.bulkSendingReview"
                        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-500 text-white px-3.5 py-2 text-xs font-semibold shadow-sm transition disabled:opacity-50"
                        title="Send review email with salary bill breakdown to all generated bills"
                    >
                        <svg v-if="!store.bulkSendingReview" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <svg v-else class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>Send Review to Generated ({{ store.summary.generated }})</span>
                    </button> -->
                </div>
            </div>

            <!-- Stats Overview Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div
                    class="bg-white dark:bg-gray-800 p-4 rounded-md shadow-sm border border-gray-100 dark:border-gray-700/60 flex items-center justify-between"
                >
                    <div>
                        <p
                            class="text-xs font-medium text-gray-500 dark:text-gray-400"
                        >
                            Total Filtered Staff
                        </p>
                        <p
                            class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-1"
                        >
                            {{ store.summary.total }}
                        </p>
                        <p
                            class="text-xs text-gray-400 dark:text-gray-500 mt-0.5"
                        >
                            {{ getMonthName(store.filters.monthId) }}
                            {{ store.filters.salaryGenerationYear }}
                        </p>
                    </div>
                    <div
                        class="p-3 rounded-md bg-gray-100 dark:bg-gray-700/60 text-gray-600 dark:text-gray-300"
                    >
                        <svg
                            class="w-6 h-6"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
                            />
                        </svg>
                    </div>
                </div>

                <div
                    class="bg-white dark:bg-gray-800 p-4 rounded-md shadow-sm border border-gray-100 dark:border-gray-700/60 flex items-center justify-between"
                >
                    <div>
                        <p
                            class="text-xs font-medium text-emerald-600 dark:text-emerald-400"
                        >
                            Bills Generated
                        </p>
                        <p
                            class="text-2xl font-bold text-emerald-700 dark:text-emerald-300 mt-1"
                        >
                            {{ store.summary.generated }}
                        </p>
                        <p
                            class="text-xs text-emerald-600/70 dark:text-emerald-400/70 mt-0.5"
                        >
                            Ready for review & payout
                        </p>
                    </div>
                    <div
                        class="p-3 rounded-md bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400"
                    >
                        <svg
                            class="w-6 h-6"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                            />
                        </svg>
                    </div>
                </div>

                <div
                    class="bg-white dark:bg-gray-800 p-4 rounded-md shadow-sm border border-gray-100 dark:border-gray-700/60 flex items-center justify-between"
                >
                    <div>
                        <p
                            class="text-xs font-medium text-amber-600 dark:text-amber-400"
                        >
                            Pending Generation
                        </p>
                        <p
                            class="text-2xl font-bold text-amber-700 dark:text-amber-300 mt-1"
                        >
                            {{ store.summary.not_generated }}
                        </p>
                        <p
                            class="text-xs text-amber-600/70 dark:text-amber-400/70 mt-0.5"
                        >
                            Awaiting bill calculation
                        </p>
                    </div>
                    <div
                        class="p-3 rounded-md bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400"
                    >
                        <svg
                            class="w-6 h-6"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                            />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Search & Filter Directory Card (Matches Screenshot & Constraints) -->
            <div
                class="bg-white dark:bg-gray-800 rounded-md shadow-sm border border-gray-100 dark:border-gray-700/60 p-5 sm:p-6 transition-colors"
            >
                <!-- Filter Card Header -->
                <div
                    class="flex items-center justify-between pb-4 border-b border-gray-100 dark:border-gray-700/60"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="p-2 rounded-md bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400"
                        >
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                                />
                            </svg>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2
                                    class="text-sm sm:text-base font-semibold text-gray-900 dark:text-gray-100"
                                >
                                    Search & Filter Directory
                                </h2>
                                <span
                                    v-if="store.activeFilterCount > 0"
                                    class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300"
                                >
                                    {{ store.activeFilterCount }} active
                                </span>
                            </div>
                            <p
                                class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"
                            >
                                Filter employees by institutional structure,
                                payroll attributes, or bill statuses
                            </p>
                        </div>
                    </div>

                    <button
                        @click="showFilters = !showFilters"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white bg-gray-50 hover:bg-gray-100 dark:bg-gray-700/50 dark:hover:bg-gray-700 rounded-md transition"
                    >
                        <span>{{
                            showFilters ? "▲ Less Filters" : "▼ More Filters"
                        }}</span>
                    </button>
                </div>

                <!-- Filter Inputs Grid -->
                <div v-show="showFilters" class="mt-4 space-y-4">
                    <!-- Row 1: Universal Search, Department/Office, Employee Group, Status -->
                    <div
                        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5"
                    >
                        <!-- Universal Search -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Universal Search</label
                            >
                            <div class="relative mt-1">
                                <span
                                    class="absolute inset-y-0 left-0 flex items-center pl-2.5 pointer-events-none text-gray-400"
                                >
                                    <svg
                                        class="w-4 h-4"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                        />
                                    </svg>
                                </span>
                                <input
                                    v-model="store.filters.search"
                                    @input="handleSearchInput"
                                    type="text"
                                    placeholder="Name, email, code, phone, NID.."
                                    class="block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 pl-8 pr-8 py-2 shadow-sm transition"
                                />
                                <button
                                    v-if="store.filters.search"
                                    @click="
                                        store.filters.search = '';
                                        store.fetchBills(1);
                                    "
                                    class="absolute inset-y-0 right-0 pr-2.5 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                >
                                    <svg
                                        class="w-3.5 h-3.5"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M6 18L18 6M6 6l12 12"
                                        />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Department / Office -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Department / Office</label
                            >
                            <select
                                v-model="store.filters.department_office_id"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            >
                                <option value="">
                                    All Departments & Offices
                                </option>
                                <option
                                    v-for="d in departmentOffices"
                                    :key="d.id"
                                    :value="d.id"
                                >
                                    {{ d.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Employee Group (User Categories) -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Employee Group</label
                            >
                            <select
                                v-model="store.filters.user_category_id"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            >
                                <option value="">All Employee Groups</option>
                                <option
                                    v-for="c in userCategories"
                                    :key="c.id"
                                    :value="c.id"
                                >
                                    {{ c.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Profile Status -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Status</label
                            >
                            <select
                                v-model="store.filters.isActive"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            >
                                <option value="">All Statuses</option>
                                <option value="true">Active Staff</option>
                                <option value="false">Inactive Staff</option>
                            </select>
                        </div>
                    </div>

                    <!-- Row 2: Exact Employee Code, Pay Scale, Grade, Designation / Group -->
                    <div
                        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5"
                    >
                        <!-- Exact Employee Code -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Exact Employee Code</label
                            >
                            <input
                                v-model="store.filters.employeeCode"
                                @input="handleSearchInput"
                                type="text"
                                placeholder="e.g. 1002"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            />
                        </div>

                        <!-- Pay Scale -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Pay Scale</label
                            >
                            <select
                                v-model="store.filters.pay_scale_id"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            >
                                <option value="">All Pay Scales</option>
                                <option
                                    v-for="s in payScales"
                                    :key="s.id"
                                    :value="s.id"
                                >
                                    {{ s.scale_name }}
                                </option>
                            </select>
                        </div>

                        <!-- Grade -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Grade</label
                            >
                            <select
                                v-model="store.filters.grade_id"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            >
                                <option value="">All Grades</option>
                                <option
                                    v-for="g in filteredGrades"
                                    :key="g.id"
                                    :value="g.id"
                                >
                                    {{ g.grade_name }}
                                </option>
                            </select>
                        </div>

                        <!-- Designation / Group -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Designation / Group</label
                            >
                            <select
                                v-model="store.filters.payroll_group_type_id"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            >
                                <option value="">All Designations</option>
                                <option
                                    v-for="gt in payrollGroupTypes"
                                    :key="gt.id"
                                    :value="gt.id"
                                >
                                    {{ gt.group_name }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Row 3: Job Status, Gender, Increment Status, Leave Status -->
                    <div
                        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5"
                    >
                        <!-- Job Status -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Job Status</label
                            >
                            <select
                                v-model="store.filters.jobStatus"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition capitalize"
                            >
                                <option value="">All Job Statuses</option>
                                <option
                                    v-for="js in jobStatuses"
                                    :key="js"
                                    :value="js"
                                >
                                    {{ js }}
                                </option>
                            </select>
                        </div>

                        <!-- Gender -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Gender</label
                            >
                            <select
                                v-model="store.filters.gender"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            >
                                <option value="">All Genders</option>
                                <option
                                    v-for="g in genders"
                                    :key="g.value"
                                    :value="g.value"
                                >
                                    {{ g.label }}
                                </option>
                            </select>
                        </div>

                        <!-- Increment Status -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Increment Status</label
                            >
                            <select
                                v-model="store.filters.holdIncrement"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            >
                                <option value="">All Increment Statuses</option>
                                <option value="false">Active Increments</option>
                                <option value="true">Increment On Hold</option>
                            </select>
                        </div>

                        <!-- Leave Status -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Leave Status</label
                            >
                            <select
                                v-model="store.filters.onLeave"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            >
                                <option value="">All Leave Statuses</option>
                                <option value="false">On Duty</option>
                                <option value="true">On Leave</option>
                            </select>
                        </div>
                    </div>

                    <!-- Row 4: Marital Status, Religion, Bill Generation Status, Review Status -->
                    <div
                        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5"
                    >
                        <!-- Marital Status -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Marital Status</label
                            >
                            <select
                                v-model="store.filters.marital_status"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition capitalize"
                            >
                                <option value="">All Marital Statuses</option>
                                <option value="single">Single</option>
                                <option value="married">Married</option>
                                <option value="divorced">Divorced</option>
                            </select>
                        </div>

                        <!-- Religion -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Religion</label
                            >
                            <select
                                v-model="store.filters.religion"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            >
                                <option value="">All Religions</option>
                                <option
                                    v-for="r in religions"
                                    :key="r"
                                    :value="r"
                                >
                                    {{ r }}
                                </option>
                            </select>
                        </div>

                        <!-- Bill Generation Status -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Bill Generation Status</label
                            >
                            <select
                                v-model="store.filters.salaryGeneratedStatus"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            >
                                <option value="generated">Generated</option>
                                <option value="not_generated">
                                    Not generate
                                </option>
                                <option value="all">
                                    All (Generated & Not Generated)
                                </option>
                            </select>
                        </div>

                        <!-- Review Status -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-gray-300"
                                >Review Status</label
                            >
                            <select
                                v-model="store.filters.reviewStatus"
                                @change="applyFilters"
                                class="mt-1 block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                            >
                                <option value="all">All Review Statuses</option>
                                <option value="pending">Pending</option>
                                <option value="review">In Review</option>
                                <option value="approved">Approved</option>
                            </select>
                        </div>
                    </div>

                    <!-- Filter Bottom Bar -->
                    <div
                        class="pt-3 border-t border-gray-100 dark:border-gray-700/60 flex flex-col sm:flex-row items-center justify-between gap-3"
                    >
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Found
                            <strong class="text-gray-800 dark:text-gray-200">{{
                                store.bills.total
                            }}</strong>
                            matching records
                        </span>

                        <div class="flex items-center gap-2">
                            <button
                                @click="resetFilters"
                                type="button"
                                class="px-3.5 py-1.5 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm"
                            >
                                RESET
                            </button>
                            <button
                                @click="applyFilters"
                                type="button"
                                class="inline-flex items-center gap-1 px-4 py-1.5 text-xs font-medium rounded-md text-white bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500 transition shadow-sm"
                            >
                                APPLY FILTERS
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Active Filter Chips -->
                <div
                    v-if="store.activeFilterCount > 0"
                    class="mt-3.5 pt-3 border-t border-gray-100 dark:border-gray-700/60 flex flex-wrap items-center gap-1.5"
                >
                    <span class="text-xs text-gray-400 dark:text-gray-500 mr-1"
                        >Filters:</span
                    >

                    <span
                        v-if="store.filters.search"
                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800"
                    >
                        Search: "{{ store.filters.search }}"
                        <button
                            @click="removeFilter('search')"
                            class="hover:text-emerald-900 dark:hover:text-emerald-100 ml-0.5"
                        >
                            &times;
                        </button>
                    </span>

                    <span
                        v-if="store.filters.employeeCode"
                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800"
                    >
                        Code: {{ store.filters.employeeCode }}
                        <button
                            @click="removeFilter('employeeCode')"
                            class="hover:text-emerald-900 dark:hover:text-emerald-100 ml-0.5"
                        >
                            &times;
                        </button>
                    </span>

                    <span
                        v-if="
                            store.filters.salaryGeneratedStatus !==
                            'not_generated'
                        "
                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 capitalize"
                    >
                        Bill:
                        {{
                            store.filters.salaryGeneratedStatus ===
                            "not_generated"
                                ? "Not generate"
                                : store.filters.salaryGeneratedStatus
                        }}
                        <button
                            @click="removeFilter('salaryGeneratedStatus')"
                            class="hover:text-emerald-900 dark:hover:text-emerald-100 ml-0.5"
                        >
                            &times;
                        </button>
                    </span>

                    <span
                        v-if="store.filters.reviewStatus !== 'all'"
                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 capitalize"
                    >
                        Review: {{ store.filters.reviewStatus }}
                        <button
                            @click="removeFilter('reviewStatus')"
                            class="hover:text-emerald-900 dark:hover:text-emerald-100 ml-0.5"
                        >
                            &times;
                        </button>
                    </span>

                    <button
                        @click="resetFilters"
                        class="text-xs text-gray-500 hover:text-emerald-600 dark:hover:text-emerald-400 font-medium ml-1 transition"
                    >
                        Clear all
                    </button>
                </div>
            </div>

            <!-- Main Data Table Card -->
            <div
                class="bg-white dark:bg-gray-800 rounded-md shadow-sm border border-gray-100 dark:border-gray-700/60 overflow-hidden transition-colors"
            >
                <!-- Bulk Actions Toolbar -->
                <div
                    v-if="selectedBillIds.length > 0 || isSelectAllFiltered"
                    class="bg-emerald-50 dark:bg-emerald-950/50 border-b border-emerald-200 dark:border-emerald-800/80 px-4 py-2.5 flex flex-wrap items-center justify-between gap-3 transition-colors"
                >
                    <div
                        class="flex items-center gap-2.5 text-xs text-emerald-900 dark:text-emerald-100"
                    >
                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded-md bg-emerald-600 text-white font-bold text-[11px]"
                        >
                            {{
                                isSelectAllFiltered
                                    ? store.summary.generated
                                    : selectedBillIds.length
                            }}
                        </span>
                        <span class="font-medium">
                            <template v-if="isSelectAllFiltered">
                                All
                                <strong>{{ store.summary.generated }}</strong>
                                filtered generated records selected
                            </template>
                            <template v-else>
                                <strong>{{ selectedBillIds.length }}</strong>
                                record(s) selected
                            </template>
                        </span>

                        <!-- Option to select all across pages if not all selected -->
                        <span
                            v-if="
                                !isSelectAllFiltered &&
                                store.summary.generated > selectedBillIds.length
                            "
                            class="text-emerald-700 dark:text-emerald-300"
                        >
                            &bull;
                            <button
                                @click="selectAllFilteredRecords"
                                class="underline font-semibold hover:text-emerald-800 dark:hover:text-emerald-200 ml-1 transition"
                            >
                                Select all
                                {{ store.summary.generated }} filtered records
                            </button>
                        </span>
                        <span
                            v-else-if="isSelectAllFiltered"
                            class="text-emerald-700 dark:text-emerald-300"
                        >
                            &bull;
                            <button
                                @click="clearSelection"
                                class="underline font-semibold hover:text-emerald-800 dark:hover:text-emerald-200 ml-1 transition"
                            >
                                Clear selection
                            </button>
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <!-- Download Excel Button -->
                        <button
                            @click="handleDownloadExcel"
                            :disabled="store.exportingExcel"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold text-white bg-emerald-700 hover:bg-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500 shadow-sm transition disabled:opacity-50"
                            title="Download selected records as Excel spreadsheet (.xlsx)"
                        >
                            <svg
                                v-if="!store.exportingExcel"
                                class="w-3.5 h-3.5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                />
                            </svg>
                            <svg
                                v-else
                                class="animate-spin w-3.5 h-3.5 text-white"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <circle
                                    class="opacity-25"
                                    cx="12"
                                    cy="12"
                                    r="10"
                                    stroke="currentColor"
                                    stroke-width="4"
                                ></circle>
                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8v8H4z"
                                ></path>
                            </svg>
                            <span
                                >Download Excel ({{
                                    isSelectAllFiltered
                                        ? store.summary.generated ||
                                          store.summary.total
                                        : selectedBillIds.length
                                }})</span
                            >
                        </button>

                        <button
                            @click="openBulkOverrideModal"
                            :disabled="store.bulkOverridingStatus"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500 shadow-sm transition disabled:opacity-50"
                        >
                            <svg
                                v-if="!store.bulkOverridingStatus"
                                class="w-3.5 h-3.5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                                />
                            </svg>
                            <svg
                                v-else
                                class="animate-spin w-3.5 h-3.5 text-white"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <circle
                                    class="opacity-25"
                                    cx="12"
                                    cy="12"
                                    r="10"
                                    stroke="currentColor"
                                    stroke-width="4"
                                ></circle>
                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8v8H4z"
                                ></path>
                            </svg>
                            <span
                                >Override Confirm ({{
                                    isSelectAllFiltered
                                        ? store.summary.generated
                                        : selectedBillIds.length
                                }})</span
                            >
                        </button>

                        <button
                            @click="clearSelection"
                            class="px-2.5 py-1.5 rounded-md text-xs font-medium border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm transition"
                        >
                            Deselect
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table
                        class="w-full text-left text-xs text-gray-600 dark:text-gray-300"
                    >
                        <thead
                            class="bg-gray-50/80 dark:bg-gray-900/60 text-gray-500 dark:text-gray-400 text-xs font-semibold uppercase tracking-wider border-b border-gray-100 dark:border-gray-700/60"
                        >
                            <tr>
                                <th class="w-10 px-3 py-3.5 text-center">
                                    <input
                                        type="checkbox"
                                        :checked="isAllPageSelected"
                                        :indeterminate.prop="
                                            isPageIndeterminate
                                        "
                                        @change="toggleSelectAllPage"
                                        :disabled="
                                            currentPageSelectableBills.length ===
                                            0
                                        "
                                        title="Select all on current page"
                                        class="rounded-md border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500 dark:bg-gray-800 transition cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
                                    />
                                </th>
                                <th
                                    @click="sortBy('id')"
                                    class="px-4 py-3.5 cursor-pointer hover:text-emerald-600 transition"
                                >
                                    <div class="flex items-center gap-1">
                                        <span>SL</span>
                                        <span class="text-[10px]">{{
                                            store.sort.field === "id"
                                                ? store.sort.direction === "asc"
                                                    ? "▲"
                                                    : "▼"
                                                : "↕"
                                        }}</span>
                                    </div>
                                </th>
                                <th class="px-4 py-3.5">Code</th>
                                <th
                                    @click="sortBy('name')"
                                    class="px-4 py-3.5 cursor-pointer hover:text-emerald-600 transition"
                                >
                                    <div class="flex items-center gap-1">
                                        <span>Employee Name</span>
                                        <span class="text-[10px]">{{
                                            store.sort.field === "name"
                                                ? store.sort.direction === "asc"
                                                    ? "▲"
                                                    : "▼"
                                                : "↕"
                                        }}</span>
                                    </div>
                                </th>
                                <th class="px-4 py-3.5">
                                    Department & Category
                                </th>
                                <th class="px-4 py-3.5">Designation & Scale</th>
                                <th class="px-4 py-3.5">Month / Year</th>
                                <th class="px-4 py-3.5 text-right">
                                    Allowance
                                </th>
                                <th class="px-4 py-3.5 text-right">
                                    Deduction
                                </th>
                                <th class="px-4 py-3.5 text-right">
                                    Net Salary
                                </th>
                                <th class="px-4 py-3.5 text-center">
                                    Bill Status
                                </th>
                                <th class="px-4 py-3.5 text-center">Review</th>
                                <th class="px-4 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>

                        <tbody
                            class="divide-y divide-gray-100 dark:divide-gray-700/60"
                        >
                            <!-- Loading Skeleton -->
                            <tr
                                v-if="store.loading"
                                v-for="n in 5"
                                :key="'skeleton-' + n"
                                class="animate-pulse"
                            >
                                <td colspan="13" class="px-4 py-4">
                                    <div
                                        class="h-4 bg-gray-200 dark:bg-gray-700 rounded-md w-full"
                                    ></div>
                                </td>
                            </tr>

                            <!-- Empty State -->
                            <tr v-else-if="store.bills.data.length === 0">
                                <td colspan="13" class="px-4 py-12 text-center">
                                    <div
                                        class="flex flex-col items-center justify-center"
                                    >
                                        <div
                                            class="p-3 rounded-md bg-gray-100 dark:bg-gray-700/60 text-gray-400 dark:text-gray-500 mb-3"
                                        >
                                            <svg
                                                class="w-8 h-8"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2"
                                                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                />
                                            </svg>
                                        </div>
                                        <p
                                            class="text-sm font-medium text-gray-900 dark:text-gray-100"
                                        >
                                            No staff salary bills found
                                        </p>
                                        <p
                                            class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-sm"
                                        >
                                            Try adjusting your filters or select
                                            a different month/year.
                                        </p>
                                        <button
                                            @click="resetFilters"
                                            class="mt-3.5 inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-md text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 transition"
                                        >
                                            Reset Filters
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Data Rows -->
                            <tr
                                v-else
                                v-for="(row, idx) in store.bills.data"
                                :key="
                                    row.user_id +
                                    '-' +
                                    row.month_id +
                                    '-' +
                                    row.year
                                "
                                :class="[
                                    selectedBillIds.includes(
                                        row.salary_generate_id,
                                    )
                                        ? 'bg-emerald-50/70 dark:bg-emerald-950/30'
                                        : '',
                                    'hover:bg-emerald-50/40 dark:hover:bg-emerald-950/20 transition-colors',
                                ]"
                            >
                                <!-- Checkbox -->
                                <td class="w-10 px-3 py-3 text-center">
                                    <input
                                        type="checkbox"
                                        :value="row.salary_generate_id"
                                        v-model="selectedBillIds"
                                        :disabled="!row.salary_generate_id"
                                        :title="
                                            !row.salary_generate_id
                                                ? 'Salary bill not generated yet'
                                                : 'Select record'
                                        "
                                        class="rounded-md border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500 dark:bg-gray-800 transition cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed"
                                    />
                                </td>

                                <!-- SL -->
                                <td
                                    class="px-4 py-3 font-mono text-gray-400 dark:text-gray-500"
                                >
                                    {{
                                        (store.bills.current_page - 1) *
                                            store.bills.per_page +
                                        idx +
                                        1
                                    }}
                                </td>

                                <!-- Code -->
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-mono font-semibold bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200"
                                    >
                                        {{ row.employee_code }}
                                    </span>
                                </td>

                                <!-- Employee Name -->
                                <td class="px-4 py-3">
                                    <div
                                        class="font-medium text-gray-900 dark:text-gray-100"
                                    >
                                        {{ row.name }}
                                    </div>
                                    <p
                                        class="font-medium text-gray-900 text-xs dark:text-gray-100"
                                    >
                                        {{ row.email }}
                                    </p>
                                    <div
                                        class="flex items-center gap-1.5 mt-0.5"
                                    >
                                        <span
                                            class="capitalize text-[10px] text-gray-500 dark:text-gray-400"
                                            >{{ row.gender }}</span
                                        >
                                        <span
                                            class="text-gray-300 dark:text-gray-600"
                                            >&bull;</span
                                        >
                                        <span
                                            class="capitalize text-[10px] text-emerald-600 dark:text-emerald-400 font-medium"
                                            >{{ row.job_status }}</span
                                        >
                                    </div>
                                </td>

                                <!-- Department & Category -->
                                <td class="px-4 py-3">
                                    <div
                                        class="text-gray-800 dark:text-gray-200 font-medium"
                                    >
                                        {{ row.department }}
                                    </div>
                                    <div
                                        class="text-[11px] text-gray-500 dark:text-gray-400"
                                    >
                                        {{ row.user_category }}
                                    </div>
                                </td>

                                <!-- Designation & Scale -->
                                <td class="px-4 py-3">
                                    <div
                                        class="text-gray-800 dark:text-gray-200 font-medium"
                                    >
                                        {{ row.designation }}
                                    </div>
                                    <div
                                        class="text-[11px] text-gray-500 dark:text-gray-400"
                                    >
                                        {{ row.pay_scale }}
                                        <span v-if="row.grade"
                                            >({{ row.grade }})</span
                                        >
                                    </div>
                                </td>

                                <!-- Month / Year -->
                                <td
                                    class="px-4 py-3 text-gray-600 dark:text-gray-300 font-medium"
                                >
                                    {{ getMonthName(row.month_id) }}
                                    {{ row.year }}
                                </td>

                                <!-- Allowance -->
                                <td
                                    class="px-4 py-3 text-right font-mono text-gray-800 dark:text-gray-200"
                                >
                                    ৳{{ formatMoney(row.total_allowance) }}
                                </td>

                                <!-- Deduction -->
                                <td
                                    class="px-4 py-3 text-right font-mono text-red-600 dark:text-red-400"
                                >
                                    ৳{{ formatMoney(row.total_deduction) }}
                                </td>

                                <!-- Net Salary -->
                                <td
                                    class="px-4 py-3 text-right font-mono font-bold text-emerald-700 dark:text-emerald-300"
                                >
                                    ৳{{ formatMoney(row.salary_total) }}
                                </td>

                                <!-- Bill Status Badge -->
                                <td class="px-4 py-3 text-center">
                                    <span
                                        v-if="row.bill_status === 'generated'"
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800"
                                    >
                                        <span
                                            class="w-1.5 h-1.5 rounded-md bg-emerald-500"
                                        ></span>
                                        Generated
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-medium bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800"
                                    >
                                        <span
                                            class="w-1.5 h-1.5 rounded-md bg-amber-500"
                                        ></span>
                                        Not Generated
                                    </span>
                                </td>

                                <!-- Review Status Badge -->
                                <td class="px-4 py-3 text-center">
                                    <span
                                        v-if="row.review_status === 'approved'"
                                        class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300"
                                    >
                                        Approved
                                    </span>
                                    <span
                                        v-else-if="
                                            row.review_status === 'review'
                                        "
                                        class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-300"
                                    >
                                        In Review
                                    </span>
                                    <span
                                        v-else-if="
                                            row.review_status === 'pending'
                                        "
                                        class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold bg-yellow-100 text-yellow-800 dark:bg-yellow-900/60 dark:text-yellow-300"
                                    >
                                        Pending
                                    </span>
                                    <span v-else class="text-gray-400 text-xs"
                                        >-</span
                                    >
                                </td>

                                <!-- Action Buttons -->
                                <td class="px-4 py-3 text-right">
                                    <div
                                        class="flex items-center justify-end gap-1.5"
                                    >
                                        <button
                                            v-if="
                                                row.bill_status !== 'generated'
                                            "
                                            @click="openManualAdjustment(row)"
                                            title="Add an employee/month-specific titled earning or deduction"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium rounded-md border border-indigo-300 bg-indigo-50 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200"
                                        >
                                            Manual +/-
                                        </button>
                                        <!-- When Not Generated: Generate button -->
                                        <button
                                            v-if="
                                                row.bill_status !== 'generated'
                                            "
                                            @click="confirmGenerateSingle(row)"
                                            :disabled="
                                                store.generatingId ===
                                                row.user_id
                                            "
                                            title="Calculate and generate salary bill"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500 rounded-md transition shadow-sm"
                                        >
                                            <svg
                                                v-if="
                                                    store.generatingId ===
                                                    row.user_id
                                                "
                                                class="animate-spin w-3.5 h-3.5 text-white"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                            >
                                                <circle
                                                    class="opacity-25"
                                                    cx="12"
                                                    cy="12"
                                                    r="10"
                                                    stroke="currentColor"
                                                    stroke-width="4"
                                                ></circle>
                                                <path
                                                    class="opacity-75"
                                                    fill="currentColor"
                                                    d="M4 12a8 8 0 018-8v8H4z"
                                                ></path>
                                            </svg>
                                            <span>Generate</span>
                                        </button>

                                        <!-- When Generated: View, Send review, Override Confirm -->
                                        <template v-else>
                                            <!-- View Button -->
                                            <button
                                                @click="openViewModal(row)"
                                                title="View salary particulars and employee info"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-gray-700 dark:text-gray-200 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 rounded-md transition shadow-sm"
                                            >
                                                View
                                            </button>

                                            <!-- Send Review Button -->
                                            <!-- <button
                                                @click="handleSendReview(row)"
                                                :disabled="store.actionLoadingId === row.salary_generate_id"
                                                title="Send salary bill review email"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-blue-700 bg-blue-50 hover:bg-blue-100 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-900/60 rounded-md transition shadow-sm"
                                            >
                                                <svg v-if="store.actionLoadingId === row.salary_generate_id" class="animate-spin w-3 h-3 text-blue-600" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                                </svg>
                                                <span>Send review</span>
                                            </button> -->

                                            <!-- Override Confirm Button -->
                                            <button
                                                @click="openOverrideModal(row)"
                                                title="Override review status"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-900/60 rounded-md transition shadow-sm"
                                            >
                                                Override Confirm
                                            </button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Table Pagination -->
                <div
                    v-if="store.bills.total > 0"
                    class="px-4 py-3 border-t border-gray-100 dark:border-gray-700/60 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400"
                >
                    <div>
                        Showing
                        <strong>{{
                            (store.bills.current_page - 1) *
                                store.bills.per_page +
                            1
                        }}</strong>
                        to
                        <strong>{{
                            Math.min(
                                store.bills.current_page * store.bills.per_page,
                                store.bills.total,
                            )
                        }}</strong>
                        of <strong>{{ store.bills.total }}</strong> staff bills
                    </div>

                    <div class="flex items-center gap-1">
                        <button
                            @click="
                                store.fetchBills(store.bills.current_page - 1)
                            "
                            :disabled="store.bills.current_page <= 1"
                            class="px-3 py-1.5 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed transition"
                        >
                            Previous
                        </button>
                        <span
                            class="px-2 font-medium text-gray-700 dark:text-gray-300"
                        >
                            Page {{ store.bills.current_page }} of
                            {{ store.bills.last_page }}
                        </span>
                        <button
                            @click="
                                store.fetchBills(store.bills.current_page + 1)
                            "
                            :disabled="
                                store.bills.current_page >=
                                store.bills.last_page
                            "
                            class="px-3 py-1.5 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed transition"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Single Generate Confirmation Modal -->
        <Modal
            :show="singleGenerateModalOpen"
            @close="singleGenerateModalOpen = false"
            max-width="md"
        >
            <div class="p-6 bg-white dark:bg-gray-800 rounded-md">
                <div class="flex items-center gap-3">
                    <div
                        class="p-2.5 rounded-md bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400"
                    >
                        <svg
                            class="w-6 h-6"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M13 10V3L4 14h7v7l9-11h-7z"
                            />
                        </svg>
                    </div>
                    <div>
                        <h3
                            class="text-base font-semibold text-gray-900 dark:text-gray-100"
                        >
                            Generate Salary Bill
                        </h3>
                        <p
                            class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"
                        >
                            Calculate and register official salary bill record
                        </p>
                    </div>
                </div>

                <div
                    v-if="pendingGenerateUser"
                    class="mt-4 p-3.5 rounded-md bg-gray-50 dark:bg-gray-900 border border-gray-100 dark:border-gray-700/60 space-y-1.5 text-xs"
                >
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400"
                            >Staff Member:</span
                        >
                        <span
                            class="font-semibold text-gray-900 dark:text-gray-100"
                            >{{ pendingGenerateUser.name }}</span
                        >
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400"
                            >Employee Code:</span
                        >
                        <span
                            class="font-mono text-gray-800 dark:text-gray-200"
                            >{{ pendingGenerateUser.employee_code }}</span
                        >
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400"
                            >Target Billing Period:</span
                        >
                        <span
                            class="font-medium text-emerald-600 dark:text-emerald-400"
                            >{{ getMonthName(store.filters.monthId) }}
                            {{ store.filters.salaryGenerationYear }}</span
                        >
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400"
                            >Estimated Net Salary:</span
                        >
                        <span class="font-bold text-gray-900 dark:text-gray-100"
                            >৳{{
                                formatMoney(pendingGenerateUser.salary_total)
                            }}</span
                        >
                    </div>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">
                    Upon confirmation, system checks step 1 condition checks
                    (active status, payroll profile, calculation objects) and
                    executes official calculation and database storage.
                </p>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton
                        @click="singleGenerateModalOpen = false"
                        class="rounded-md"
                        >Cancel</SecondaryButton
                    >
                    <PrimaryButton
                        @click="executeGenerateSingle"
                        class="rounded-md bg-emerald-600 hover:bg-emerald-700"
                    >
                        Confirm & Generate
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Step 06: full read-only preview, existing modal component and page styling retained. -->
        <Modal
            :show="phase5PreviewOpen"
            @close="phase5PreviewOpen = false"
            max-width="2xl"
        >
            <div
                class="p-5 space-y-3 bg-white dark:bg-gray-900 dark:text-gray-100"
            >
                <h2 class="font-bold text-lg">
                    Bulk Salary Calculation — Current / Arrear / Total
                </h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ phase5LoadedCount }} /
                    {{ phase5CandidateIds.length }} unbilled employee(s)
                    calculated.
                    {{
                        phase5PreviewLoading
                            ? "Read-only calculation in progress."
                            : "No salary bill or GPF transaction was created by preview."
                    }}
                </p>
                <div class="flex gap-3 flex-wrap text-xs">
                    <span>Calculated: {{ phase5ReadyCount }}</span>
                    <span
                        >Already generated:
                        {{ phase5AlreadyGeneratedCount }}</span
                    >
                    <span
                        >Manual overrides:
                        {{
                            phase5PreviewRows.filter((r) => r.manual_override)
                                .length
                        }}</span
                    >
                    <span class="text-red-600"
                        >Needs attention: {{ phase5ExceptionCount }}</span
                    >
                    <span>Selected: {{ phase5SelectedIds.length }}</span>
                    <span
                        >Selected Net: ৳{{
                            formatMoney(phase5PreviewTotals)
                        }}</span
                    >
                </div>
                <p v-if="phase5Error" class="text-sm text-red-600">
                    {{ phase5Error }}
                </p>
                <div class="flex gap-2 text-xs flex-wrap">
                    <button
                        type="button"
                        @click="phase5SelectedIds = readyIds(phase5PreviewRows)"
                        :disabled="!phase5PreviewComplete"
                        class="border rounded px-2 py-1"
                    >
                        Select all calculated
                    </button>
                    <button
                        type="button"
                        @click="phase5SelectedIds = []"
                        class="border rounded px-2 py-1"
                    >
                        Clear selection
                    </button>
                    <button
                        type="button"
                        @click="recalculatePhase5Selected"
                        :disabled="
                            !phase5PreviewComplete ||
                            !phase5SelectedIds.length ||
                            phase5PreviewLoading
                        "
                        class="border rounded px-2 py-1"
                    >
                        Calculate / Recalculate Selected
                    </button>
                    <button
                        type="button"
                        @click="exportPhase5Preview(false)"
                        :disabled="!phase5PreviewRows.length"
                        class="border rounded px-2 py-1"
                    >
                        Export Preview CSV
                    </button>
                    <button
                        type="button"
                        @click="exportPhase5Preview(true)"
                        :disabled="!phase5PreviewRows.length"
                        class="border rounded px-2 py-1"
                    >
                        Export Exceptions CSV
                    </button>
                </div>
                <div class="max-h-[55vh] overflow-auto border rounded">
                    <table
                        class="min-w-[1200px] w-full text-xs text-left border-collapse"
                    >
                        <thead
                            class="sticky top-0 bg-gray-100 dark:bg-gray-800"
                        >
                            <tr>
                                <th class="p-2">Select</th>
                                <th>Employee / Code</th>
                                <th>Grade / Designation</th>
                                <th>Basic</th>
                                <th>Current Gross</th>
                                <th>Current Deduction</th>
                                <th>Current Net</th>
                                <th>Arrear (+)</th>
                                <th>Arrear (−)</th>
                                <th>Final Net</th>
                                <th>Status / Warnings</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="r in phase5PreviewRows"
                                :key="r.user_id"
                                class="border-t dark:border-gray-700"
                            >
                                <td class="p-2">
                                    <input
                                        type="checkbox"
                                        :disabled="
                                            !isReady(r) ||
                                            !phase5PreviewComplete
                                        "
                                        :value="r.user_id"
                                        v-model="phase5SelectedIds"
                                    />
                                </td>
                                <td class="p-2">
                                    {{ r.name || "Employee" }}
                                    <div class="text-gray-500">
                                        {{ r.employee_code || r.user_id }}
                                    </div>
                                </td>
                                <td class="p-2">
                                    {{ r.grade || "—" }}
                                    <div>{{ r.designation || "—" }}</div>
                                </td>
                                <td>
                                    {{
                                        r.basic == null
                                            ? "—"
                                            : formatMoney(r.basic)
                                    }}
                                </td>
                                <td>
                                    {{
                                        r.regular_gross == null
                                            ? "—"
                                            : formatMoney(r.regular_gross)
                                    }}
                                </td>
                                <td>
                                    {{
                                        r.regular_deductions == null
                                            ? "—"
                                            : formatMoney(r.regular_deductions)
                                    }}
                                </td>
                                <td>
                                    {{
                                        r.regular_net == null
                                            ? "—"
                                            : formatMoney(r.regular_net)
                                    }}
                                </td>
                                <td>
                                    {{
                                        r.arrear_payable == null
                                            ? "0.00 (unverified)"
                                            : formatMoney(r.arrear_payable)
                                    }}
                                </td>
                                <td>
                                    {{
                                        r.arrear_deductions == null
                                            ? "0.00 (unverified)"
                                            : formatMoney(r.arrear_deductions)
                                    }}
                                </td>
                                <td class="font-semibold">
                                    {{
                                        r.net_payable == null
                                            ? "UNKNOWN"
                                            : formatMoney(r.net_payable)
                                    }}
                                </td>
                                <td class="p-2">
                                    <span
                                        :class="
                                            isReady(r)
                                                ? 'text-emerald-700'
                                                : 'text-red-600'
                                        "
                                        >{{
                                            r.calculation_status || r.status
                                        }}</span
                                    >
                                    <div
                                        v-if="r.reason"
                                        class="max-w-xs break-words"
                                    >
                                        {{ r.reason }}
                                    </div>
                                </td>
                                <td class="p-2 whitespace-nowrap">
                                    <a
                                        :href="
                                            route(
                                                'salary-calculator.show',
                                                r.user_id,
                                            ) +
                                            '?salary_year=' +
                                            store.filters.salaryGenerationYear +
                                            '&salary_month=' +
                                            store.filters.monthId
                                        "
                                        target="_blank"
                                        rel="noopener"
                                        class="text-blue-700 underline"
                                        >Edit</a
                                    >
                                    <button
                                        type="button"
                                        @click="recalculatePhase5Row(r)"
                                        :disabled="phase5PreviewLoading"
                                        class="ml-2 text-emerald-700 underline"
                                    >
                                        Recalculate
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex gap-2 justify-end">
                    <button
                        type="button"
                        @click="phase5PreviewOpen = false"
                        class="px-4 py-2 border rounded"
                    >
                        Close
                    </button>
                    <button
                        type="button"
                        :disabled="
                            !phase5PreviewComplete ||
                            phase5SelectedIds.length === 0 ||
                            store.bulkGenerating
                        "
                        @click="generatePhase5Selected"
                        class="px-4 py-2 text-white bg-emerald-700 rounded disabled:opacity-50"
                    >
                        Generate Selected ({{ phase5SelectedIds.length }})
                    </button>
                </div>
            </div>
        </Modal>

        <Modal
            :show="bulkGenerateModalOpen"
            @close="bulkGenerateModalOpen = false"
            max-width="md"
        >
            <div class="p-6 bg-white dark:bg-gray-800 rounded-md">
                <div class="flex items-center gap-3">
                    <div
                        class="p-2.5 rounded-md bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400"
                    >
                        <svg
                            class="w-6 h-6"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"
                            />
                        </svg>
                    </div>
                    <div>
                        <h3
                            class="text-base font-semibold text-gray-900 dark:text-gray-100"
                        >
                            Bulk Generate Salary Bills
                        </h3>
                        <p
                            class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"
                        >
                            Calculate salaries for all filtered unbilled staff
                        </p>
                    </div>
                </div>

                <div
                    class="mt-4 p-3.5 rounded-md bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-xs text-amber-800 dark:text-amber-200"
                >
                    <p class="font-semibold">
                        Ready to process {{ store.summary.not_generated }} staff
                        members
                    </p>
                    <p class="mt-1">
                        This action will generate salary bills for
                        <strong
                            >{{ getMonthName(store.filters.monthId) }}
                            {{ store.filters.salaryGenerationYear }}</strong
                        >
                        for all matching employees whose bills have not been
                        generated yet.
                    </p>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton
                        @click="bulkGenerateModalOpen = false"
                        class="rounded-md"
                        >Cancel</SecondaryButton
                    >
                    <PrimaryButton
                        @click="executeBulkGenerate"
                        class="rounded-md bg-emerald-600 hover:bg-emerald-700"
                    >
                        Confirm Bulk Generation
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Override Review Status Modal -->
        <Modal
            :show="overrideModalOpen"
            @close="overrideModalOpen = false"
            max-width="md"
        >
            <div class="p-6 bg-white dark:bg-gray-800 rounded-md">
                <div class="flex items-center gap-3">
                    <div
                        class="p-2.5 rounded-md bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400"
                    >
                        <svg
                            class="w-6 h-6"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                            />
                        </svg>
                    </div>
                    <div>
                        <h3
                            class="text-base font-semibold text-gray-900 dark:text-gray-100"
                        >
                            Override Review Status
                        </h3>
                        <p
                            class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"
                        >
                            Manually override the review verification state
                        </p>
                    </div>
                </div>

                <div v-if="pendingOverrideBill" class="mt-4 space-y-3">
                    <div
                        class="p-3 rounded-md bg-gray-50 dark:bg-gray-900 border border-gray-100 dark:border-gray-700/60 text-xs space-y-1"
                    >
                        <div class="flex justify-between">
                            <span class="text-gray-500">Employee:</span>
                            <span
                                class="font-medium text-gray-900 dark:text-gray-100"
                                >{{ pendingOverrideBill.name }}</span
                            >
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Current Status:</span>
                            <span
                                class="capitalize font-semibold text-amber-600 dark:text-amber-400"
                                >{{
                                    pendingOverrideBill.review_status ||
                                    "Pending"
                                }}</span
                            >
                        </div>
                    </div>

                    <div>
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1"
                            >New Review Status</label
                        >
                        <select
                            v-model="overrideTargetStatus"
                            class="block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                        >
                            <option value="approved">Approved</option>
                            <option value="review">In Review</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton
                        @click="overrideModalOpen = false"
                        class="rounded-md"
                        >Cancel</SecondaryButton
                    >
                    <PrimaryButton
                        @click="executeOverrideStatus"
                        class="rounded-md bg-emerald-600 hover:bg-emerald-700"
                    >
                        Update Status
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Bulk Override Review Status Modal -->
        <Modal
            :show="bulkOverrideModalOpen"
            @close="bulkOverrideModalOpen = false"
            max-width="md"
        >
            <div class="p-6 bg-white dark:bg-gray-800 rounded-md">
                <div class="flex items-center gap-3">
                    <div
                        class="p-2.5 rounded-md bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400"
                    >
                        <svg
                            class="w-6 h-6"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                            />
                        </svg>
                    </div>
                    <div>
                        <h3
                            class="text-base font-semibold text-gray-900 dark:text-gray-100"
                        >
                            Bulk Override Review Status
                        </h3>
                        <p
                            class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"
                        >
                            Override review verification state for selected
                            records
                        </p>
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    <div
                        class="p-3.5 rounded-md bg-emerald-50/60 dark:bg-emerald-950/40 border border-emerald-100 dark:border-emerald-800/60 text-xs space-y-1.5"
                    >
                        <div class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400"
                                >Selected Records:</span
                            >
                            <span
                                class="font-bold text-emerald-800 dark:text-emerald-200"
                            >
                                {{
                                    isSelectAllFiltered
                                        ? `All ${store.summary.generated} filtered generated records`
                                        : `${selectedBillIds.length} staff salary bill(s)`
                                }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400"
                                >Billing Period:</span
                            >
                            <span
                                class="font-medium text-gray-900 dark:text-gray-100"
                            >
                                {{ getMonthName(store.filters.monthId) }}
                                {{ store.filters.salaryGenerationYear }}
                            </span>
                        </div>
                    </div>

                    <div>
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1"
                            >New Review Status</label
                        >
                        <select
                            v-model="bulkOverrideTargetStatus"
                            class="block w-full text-xs rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-emerald-500 focus:ring-emerald-500 py-2 px-3 shadow-sm transition"
                        >
                            <option value="approved">Approved</option>
                            <option value="review">In Review</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton
                        @click="bulkOverrideModalOpen = false"
                        class="rounded-md"
                        >Cancel</SecondaryButton
                    >
                    <PrimaryButton
                        @click="executeBulkOverrideStatus"
                        :disabled="store.bulkOverridingStatus"
                        class="rounded-md bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500"
                    >
                        <svg
                            v-if="store.bulkOverridingStatus"
                            class="animate-spin -ml-1 mr-2 h-4 w-4 text-white"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            ></circle>
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8v8H4z"
                            ></path>
                        </svg>
                        Update Status
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- View Salary Particulars & Employee Details Modal -->
        <Modal
            :show="store.viewModalOpen"
            @close="closeViewModal"
            max-width="4xl"
        >
            <div
                v-if="store.selectedBill"
                class="p-6 bg-white dark:bg-gray-800 rounded-md max-h-[90vh] overflow-y-auto space-y-6"
            >
                <!-- Header with Employee Profile Banner -->
                <div
                    class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-gray-100 dark:border-gray-700/60"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="w-12 h-12 rounded-md bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300 flex items-center justify-center font-bold text-lg"
                        >
                            {{ store.selectedBill.name.charAt(0) }}
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2
                                    class="text-lg font-bold text-gray-900 dark:text-gray-100"
                                >
                                    {{ store.selectedBill.name }}
                                </h2>
                                <span
                                    class="px-2 py-0.5 rounded-md text-[11px] font-mono font-semibold bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200"
                                >
                                    {{ store.selectedBill.employee_code }}
                                </span>
                            </div>
                            <p
                                class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"
                            >
                                {{ store.selectedBill.designation }} &bull;
                                {{ store.selectedBill.department }} ({{
                                    store.selectedBill.user_category
                                }})
                            </p>
                        </div>
                    </div>

                    <!-- Period & Status Badges -->
                    <div class="flex items-center gap-2">
                        <span
                            class="px-2.5 py-1 rounded-md text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300"
                        >
                            {{ getMonthName(store.selectedBill.month_id) }}
                            {{ store.selectedBill.year }}
                        </span>
                        <span
                            v-if="
                                store.selectedBill.review_status === 'approved'
                            "
                            class="px-2.5 py-1 rounded-md text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300"
                        >
                            Approved
                        </span>
                        <span
                            v-else-if="
                                store.selectedBill.review_status === 'review'
                            "
                            class="px-2.5 py-1 rounded-md text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-300"
                        >
                            In Review
                        </span>
                        <span
                            v-else
                            class="px-2.5 py-1 rounded-md text-xs font-semibold bg-yellow-100 text-yellow-800 dark:bg-yellow-900/60 dark:text-yellow-300"
                        >
                            Pending
                        </span>
                    </div>
                </div>

                <!-- Snapshot Info Grid -->
                <div
                    class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs bg-gray-50 dark:bg-gray-900/60 p-4 rounded-md border border-gray-100 dark:border-gray-700/60"
                >
                    <div>
                        <span class="text-gray-400 block text-[11px]"
                            >Pay Scale:</span
                        >
                        <span
                            class="font-medium text-gray-900 dark:text-gray-100"
                            >{{ store.selectedBill.pay_scale }}</span
                        >
                    </div>
                    <div>
                        <span class="text-gray-400 block text-[11px]"
                            >Grade:</span
                        >
                        <span
                            class="font-medium text-gray-900 dark:text-gray-100"
                            >{{ store.selectedBill.grade }}</span
                        >
                    </div>
                    <div>
                        <span class="text-gray-400 block text-[11px]"
                            >Phone Contact:</span
                        >
                        <span
                            class="font-mono text-gray-900 dark:text-gray-100"
                            >{{ store.selectedBill.phone }}</span
                        >
                    </div>
                    <div>
                        <span class="text-gray-400 block text-[11px]"
                            >Generation Date:</span
                        >
                        <span
                            class="font-mono text-gray-900 dark:text-gray-100"
                            >{{
                                store.selectedBill.salary_generation_date ||
                                "N/A"
                            }}</span
                        >
                    </div>
                </div>

                <!-- Bank Particulars -->
                <div
                    v-if="store.selectedBill.salary_generate"
                    class="p-3.5 rounded-md bg-blue-50/60 dark:bg-blue-950/30 border border-blue-100 dark:border-blue-900/40 text-xs"
                >
                    <h4
                        class="font-semibold text-blue-900 dark:text-blue-200 mb-1.5 flex items-center gap-1.5"
                    >
                        <svg
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"
                            />
                        </svg>
                        Bank Account Information
                    </h4>
                    <div
                        class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-blue-800 dark:text-blue-300"
                    >
                        <div>
                            Account No:
                            <strong class="font-mono">{{
                                store.selectedBill.salary_generate.bankAccountNo
                            }}</strong>
                        </div>
                        <div>
                            Bank Branch:
                            <strong class="font-medium">{{
                                store.selectedBill.salary_generate.bankAddress
                            }}</strong>
                        </div>
                    </div>
                </div>

                <!-- Salary Particulars Breakdown: Allowances vs Deductions -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <!-- Allowances Table -->
                    <div
                        class="border border-gray-200 dark:border-gray-700/60 rounded-md overflow-hidden"
                    >
                        <div
                            class="px-4 py-2.5 bg-emerald-50 dark:bg-emerald-950/50 border-b border-gray-200 dark:border-gray-700/60 flex items-center justify-between"
                        >
                            <span
                                class="text-xs font-bold text-emerald-800 dark:text-emerald-300"
                                >Earnings & Allowances</span
                            >
                            <span
                                class="text-xs font-mono font-bold text-emerald-700 dark:text-emerald-300"
                            >
                                ৳{{
                                    formatMoney(
                                        store.selectedBill.total_allowance,
                                    )
                                }}
                            </span>
                        </div>
                        <div
                            class="divide-y divide-gray-100 dark:divide-gray-700/60 max-h-60 overflow-y-auto"
                        >
                            <div
                                v-for="(item, i) in store.selectedBill
                                    .salary_generate
                                    ?.salaryAllowanceCalcObject || []"
                                :key="'allowance-' + i"
                                class="px-4 py-2 flex items-center justify-between text-xs"
                            >
                                <div>
                                    <span
                                        class="font-medium text-gray-800 dark:text-gray-200"
                                        >{{
                                            item.name ||
                                            item.payroll_component_type?.name
                                        }}</span
                                    >
                                    <span
                                        class="ml-1.5 text-[10px] text-gray-400 capitalize"
                                        >({{ item.calcType || "fixed" }})</span
                                    >
                                </div>
                                <span
                                    class="font-mono text-gray-900 dark:text-gray-100"
                                    >৳{{ formatMoney(item.amount) }}</span
                                >
                            </div>
                            <div
                                v-if="
                                    !store.selectedBill.salary_generate
                                        ?.salaryAllowanceCalcObject?.length
                                "
                                class="px-4 py-3 text-center text-xs text-gray-400"
                            >
                                No individual allowance items found
                            </div>
                        </div>
                    </div>

                    <!-- Deductions Table -->
                    <div
                        class="border border-gray-200 dark:border-gray-700/60 rounded-md overflow-hidden"
                    >
                        <div
                            class="px-4 py-2.5 bg-red-50 dark:bg-red-950/50 border-b border-gray-200 dark:border-gray-700/60 flex items-center justify-between"
                        >
                            <span
                                class="text-xs font-bold text-red-800 dark:text-red-300"
                                >Standard Deductions</span
                            >
                            <span
                                class="text-xs font-mono font-bold text-red-700 dark:text-red-300"
                            >
                                ৳{{
                                    formatMoney(
                                        store.selectedBill.total_deduction,
                                    )
                                }}
                            </span>
                        </div>
                        <div
                            class="divide-y divide-gray-100 dark:divide-gray-700/60 max-h-60 overflow-y-auto"
                        >
                            <div
                                v-for="(item, i) in store.selectedBill
                                    .salary_generate
                                    ?.salaryDeductionCalcObject || []"
                                :key="'deduction-' + i"
                                class="px-4 py-2 flex items-center justify-between text-xs"
                            >
                                <div>
                                    <span
                                        class="font-medium text-gray-800 dark:text-gray-200"
                                        >{{
                                            item.name ||
                                            item.payroll_component_type?.name
                                        }}</span
                                    >
                                    <span
                                        class="ml-1.5 text-[10px] text-gray-400 capitalize"
                                        >({{ item.calcType || "fixed" }})</span
                                    >
                                </div>
                                <span
                                    class="font-mono text-red-600 dark:text-red-400"
                                    >৳{{ formatMoney(item.amount) }}</span
                                >
                            </div>
                            <div
                                v-if="
                                    !store.selectedBill.salary_generate
                                        ?.salaryDeductionCalcObject?.length
                                "
                                class="px-4 py-3 text-center text-xs text-gray-400"
                            >
                                No individual deduction items found
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Specific Tax & GPF Indicators -->
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div
                        class="p-3 rounded-md bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700/60 flex items-center justify-between"
                    >
                        <span class="text-gray-500 dark:text-gray-400"
                            >Tax Withholding:</span
                        >
                        <span
                            class="font-mono font-semibold text-gray-900 dark:text-gray-100"
                            >৳{{
                                formatMoney(store.selectedBill.tax_deduction)
                            }}</span
                        >
                    </div>
                    <div
                        class="p-3 rounded-md bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700/60 flex items-center justify-between"
                    >
                        <span class="text-gray-500 dark:text-gray-400"
                            >General Provident Fund (GPF):</span
                        >
                        <span
                            class="font-mono font-semibold text-gray-900 dark:text-gray-100"
                            >৳{{
                                formatMoney(store.selectedBill.gpf_deduction)
                            }}</span
                        >
                    </div>
                </div>

                <!-- Final Net Payable Summary Banner -->
                <div
                    class="p-4 rounded-md bg-emerald-600 text-white flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-sm"
                >
                    <div>
                        <div
                            class="text-xs uppercase tracking-wider text-emerald-100 font-semibold"
                        >
                            Net Payable Salary
                        </div>
                        <div class="text-xs text-emerald-200 mt-0.5">
                            Total Earnings minus Total Deductions
                        </div>
                    </div>
                    <div
                        class="text-2xl sm:text-3xl font-mono font-extrabold tracking-tight"
                    >
                        ৳{{ formatMoney(store.selectedBill.salary_total) }}
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button
                        @click="
                            store.exportToExcel({
                                records: [store.selectedBill],
                            })
                        "
                        :disabled="store.exportingExcel"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 shadow-sm transition disabled:opacity-50"
                    >
                        <svg
                            class="w-3.5 h-3.5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                            />
                        </svg>
                        Download Excel
                    </button>
                    <SecondaryButton @click="closeViewModal" class="rounded-md"
                        >Close</SecondaryButton
                    >
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
