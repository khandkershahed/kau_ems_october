<script setup>
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useReportStore } from '@/stores/report';

const reportStore = useReportStore();

const searchQuery = ref('');
const isPreviewModalOpen = ref(false);
const isGeneratingPdf = ref(false);
const isExportingExcel = ref(false);

// Reports array (extensible list of reports)
const reportsList = ref([
    {
        id: 'sallery-summary',
        title: 'Sallery summary',
        code: 'KAU-FIN-SAL-01',
        category: 'Finance & Accounts',
        authority: 'Directorate of Finance & Accounts',
        format: 'A4 Portrait',
        description: 'Official monthly salary bill requisition and itemized allowance/deduction summary blank form.',
        status: 'Active'
    }
]);

const filteredReports = computed(() => {
    if (!searchQuery.value.trim()) return reportsList.value;
    const query = searchQuery.value.toLowerCase();
    return reportsList.value.filter(report =>
        report.title.toLowerCase().includes(query) ||
        report.code.toLowerCase().includes(query) ||
        report.category.toLowerCase().includes(query) ||
        report.description.toLowerCase().includes(query)
    );
});

// Download PDF Handler (No 'jspdf' in visible labels)
const handleDownloadPdf = async () => {
    isGeneratingPdf.value = true;
    try {
        await reportStore.generateSalaryBillPdf(null, 'Sallery_summary.pdf');
    } catch (error) {
        console.error('Failed to generate PDF:', error);
    } finally {
        isGeneratingPdf.value = false;
    }
};

// Export Excel Handler
const handleExportExcel = () => {
    isExportingExcel.value = true;
    try {
        reportStore.exportSalaryBillToExcel(null, 'Sallery_summary.xlsx');
    } catch (error) {
        console.error('Failed to export Excel:', error);
    } finally {
        isExportingExcel.value = false;
    }
};

const formatCurrency = (val) => {
    if (val === null || val === undefined) return '';
    if (typeof val === 'string' && val.trim() === '') return '';
    const num = typeof val === 'number' ? val : parseFloat(String(val).replace(/,/g, '').trim());
    if (isNaN(num) || num === 0) return '';
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};
</script>

<template>
    <Head title="Reports & Forms" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-xs font-medium text-green-600 dark:text-green-400 uppercase tracking-wider mb-1">
                        <span>Reports</span>
                        <span>/</span>
                        <span>Reports Index</span>
                    </div>
                    <h2 class="text-2xl font-bold leading-tight text-gray-900 dark:text-gray-100">
                        Official University Reports
                    </h2>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">

                <!-- Hero Header Banner -->
                <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-green-600 via-emerald-600 to-teal-700 p-6 sm:p-8 text-white shadow-lg">
                    <div class="relative z-10 max-w-3xl">
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/20 backdrop-blur-sm text-xs font-semibold uppercase tracking-wider mb-3">
                            <span>Khulna Agricultural University</span>
                            <span>•</span>
                            <span>Reports & Forms Repository</span>
                        </div>
                        <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">
                            University Standard Reports
                        </h1>
                        <p class="mt-2 text-sm sm:text-base text-green-50 leading-relaxed">
                            Generate, preview, and download official university forms and financial statements formatted for standard A4 paper with direct Excel workbook export.
                        </p>
                        
                        <div class="mt-6 flex flex-wrap items-center gap-4 text-xs font-medium text-green-100">
                            <div class="flex items-center gap-2 bg-black/15 backdrop-blur-sm px-3 py-1.5 rounded-lg border border-white/10">
                                <svg class="w-4 h-4 text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>A4 Centered Layout</span>
                            </div>
                            <div class="flex items-center gap-2 bg-black/15 backdrop-blur-sm px-3 py-1.5 rounded-lg border border-white/10">
                                <svg class="w-4 h-4 text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <span>Excel (.xlsx) Export</span>
                            </div>
                            <div class="flex items-center gap-2 bg-black/15 backdrop-blur-sm px-3 py-1.5 rounded-lg border border-white/10">
                                <svg class="w-4 h-4 text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                </svg>
                                <span>Print-Ready High Resolution</span>
                            </div>
                        </div>
                    </div>

                    <div class="absolute -right-12 -bottom-12 w-64 h-64 rounded-full bg-white/10 blur-2xl pointer-events-none"></div>
                </div>

                <!-- Search & Filter Bar -->
                <div class="rounded-xl bg-white dark:bg-gray-800 p-4 sm:p-5 shadow-sm border border-gray-200 dark:border-gray-700 transition-colors">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="relative flex-1 max-w-md">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>
                            <input
                                type="text"
                                v-model="searchQuery"
                                placeholder="Search report name or code..."
                                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 py-2 pl-10 pr-4 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-green-500 focus:bg-white dark:focus:bg-gray-900 focus:outline-none focus:ring-1 focus:ring-green-500 transition-colors"
                            />
                        </div>

                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            Showing <span class="font-bold text-gray-700 dark:text-gray-200">{{ filteredReports.length }}</span> report
                        </div>
                    </div>
                </div>

                <!-- Reports List / Table -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                        <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">
                            Available Reports
                        </h3>
                        <span class="text-xs font-mono px-2.5 py-1 rounded bg-green-50 text-green-700 dark:bg-green-950/60 dark:text-green-400 border border-green-200 dark:border-green-800">
                            Standard Reports
                        </span>
                    </div>

                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        <div
                            v-for="report in filteredReports"
                            :key="report.id"
                            class="p-6 sm:p-7 flex flex-col md:flex-row md:items-center md:justify-between gap-6 transition-colors"
                        >
                            <!-- Report Info -->
                            <div class="space-y-2 max-w-2xl">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-bold font-mono bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300 border border-green-300 dark:border-green-800">
                                        {{ report.code }}
                                    </span>
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                        {{ report.category }}
                                    </span>
                                    <span class="text-xs text-gray-400 dark:text-gray-500">•</span>
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                        {{ report.format }}
                                    </span>
                                </div>

                                <h4 class="text-lg font-bold text-gray-900 dark:text-gray-100">
                                    {{ report.title }}
                                </h4>

                                <p class="text-xs sm:text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                                    {{ report.description }}
                                </p>

                                <div class="text-xs text-gray-400 dark:text-gray-500 pt-1">
                                    Authority: <span class="text-gray-600 dark:text-gray-400 font-medium">{{ report.authority }}</span>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex flex-wrap sm:flex-nowrap items-center gap-3 shrink-0">
                                <button
                                    @click="isPreviewModalOpen = true"
                                    class="inline-flex items-center gap-1.5 px-4 py-2.5 text-xs font-semibold rounded-xl bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 transition-all shadow-sm"
                                >
                                    <svg class="w-4 h-4 text-gray-500 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                    <span>Preview Form</span>
                                </button>

                                <button
                                    @click="handleExportExcel"
                                    :disabled="isExportingExcel"
                                    class="inline-flex items-center gap-1.5 px-4 py-2.5 text-xs font-semibold rounded-xl bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/60 dark:hover:bg-emerald-900/60 text-emerald-700 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800 transition-all shadow-sm disabled:opacity-50"
                                >
                                    <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <span>Export Excel</span>
                                </button>

                                <button
                                    @click="handleDownloadPdf"
                                    :disabled="isGeneratingPdf"
                                    class="inline-flex items-center gap-1.5 px-4 py-2.5 text-xs font-bold rounded-xl bg-green-600 hover:bg-green-700 text-white shadow-md hover:shadow-lg transition-all disabled:opacity-50"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                    </svg>
                                    <span>{{ isGeneratingPdf ? 'Generating...' : 'Download PDF' }}</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-if="filteredReports.length === 0" class="p-12 text-center text-gray-500 dark:text-gray-400">
                        No report found matching "{{ searchQuery }}".
                    </div>
                </div>

            </div>
        </div>

        <!-- Blank Form A4 Live Preview Modal -->
        <Modal :show="isPreviewModalOpen" @close="isPreviewModalOpen = false" maxWidth="4xl">
            <div class="p-6 sm:p-8 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 max-h-[88vh] overflow-y-auto">
                
                <!-- Action Bar -->
                <div class="flex items-center justify-between pb-6 mb-6 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold font-mono bg-green-50 text-green-700 dark:bg-green-950/60 dark:text-green-400 border border-green-200 dark:border-green-800">
                            A4 PREVIEW
                        </span>
                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                            Sallery summary
                        </span>
                    </div>

                    <div class="flex items-center gap-3">
                        <button
                            @click="handleExportExcel"
                            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300 transition-colors border border-emerald-200 dark:border-emerald-800"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <span>Export Excel</span>
                        </button>

                        <PrimaryButton @click="handleDownloadPdf" :disabled="isGeneratingPdf" class="!gap-2 !py-2 !px-4">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                            <span>Download PDF</span>
                        </PrimaryButton>

                        <SecondaryButton @click="isPreviewModalOpen = false">
                            Close
                        </SecondaryButton>
                    </div>
                </div>

                <!-- Exact A4 Blank Form Representation Matching Uploaded Image -->
                <div class="bg-white text-gray-900 p-8 sm:p-12 border border-gray-300 rounded-xl shadow-inner font-sans max-w-3xl mx-auto">
                    
                    <!-- Header Section -->
                    <div class="relative pb-6 mb-6 text-center">
                        <img
                            :src="reportStore.university.logoUrl"
                            alt="University Logo"
                            class="absolute left-0 top-0 w-16 h-16 object-contain"
                        />
                        <div class="pl-16 pr-4">
                            <h2 class="text-xl font-bold text-gray-900 tracking-normal">
                                {{ reportStore.university.name }}
                            </h2>
                            <p class="text-xs text-gray-600 mt-1">
                                {{ reportStore.university.officeAddress }}
                            </p>
                            <h3 class="text-sm font-semibold text-gray-800 mt-2">
                                Salary Bill for the month of -
                            </h3>
                        </div>
                    </div>

                    <!-- Metadata: 2 Balanced Columns -->
                    <div class="grid grid-cols-2 gap-x-6 text-xs text-gray-900 mb-6 leading-relaxed">
                        <div class="space-y-1">
                            <div><span class="font-bold">Name :</span> {{ reportStore.salaryBill.employee.name }}</div>
                            <div><span class="font-bold">Designation :</span> {{ reportStore.salaryBill.employee.designation }}</div>
                            <div><span class="font-bold">Department :</span> {{ reportStore.salaryBill.employee.department }}</div>
                            <div><span class="font-bold">Pay Scale :</span> {{ reportStore.salaryBill.employee.payScale }}</div>
                        </div>
                        <div class="space-y-1 text-right sm:text-left sm:pl-16">
                            <div><span class="font-bold">Service ID :</span> {{ reportStore.salaryBill.employee.serviceId }}</div>
                            <div><span class="font-bold">Basic :</span> {{ formatCurrency(reportStore.salaryBill.employee.basic) }}</div>
                            <div><span class="font-bold">Increment Date :</span> {{ reportStore.salaryBill.employee.incrementDate }}</div>
                            <div><span class="font-bold">Month & Year :</span> {{ reportStore.salaryBill.employee.monthYear }}</div>
                        </div>
                    </div>

                    <!-- Allowances Table: 5 Empty Rows -->
                    <div class="border border-gray-800 mb-1 text-xs">
                        <div class="border-b border-gray-800 py-1 text-center font-bold bg-white">
                            Allowances
                        </div>
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-gray-800 font-bold">
                                    <th class="border-r border-gray-800 p-2 text-center w-12">Sl.No</th>
                                    <th class="border-r border-gray-800 p-2">Particulars</th>
                                    <th class="border-r border-gray-800 p-2 text-right w-24">Current</th>
                                    <th class="border-r border-gray-800 p-2 text-right w-24">Arrear</th>
                                    <th class="p-2 text-right w-24">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="allowance in reportStore.salaryBill.allowances" :key="allowance.slNo" class="border-b border-gray-400">
                                    <td class="border-r border-gray-800 p-2 text-center">{{ allowance.slNo }}</td>
                                    <td class="border-r border-gray-800 p-2">{{ allowance.particulars }}</td>
                                    <td class="border-r border-gray-800 p-2 text-right">{{ formatCurrency(allowance.current) }}</td>
                                    <td class="border-r border-gray-800 p-2 text-right">{{ formatCurrency(allowance.arrear) }}</td>
                                    <td class="p-2 text-right">{{ formatCurrency(allowance.total) }}</td>
                                </tr>
                                <tr class="font-bold">
                                    <td class="border-r border-gray-800 p-2"></td>
                                    <td class="border-r border-gray-800 p-2">Gross Salary</td>
                                    <td class="border-r border-gray-800 p-2 text-right">{{ formatCurrency(reportStore.salaryBill.grossSalary.current) }}</td>
                                    <td class="border-r border-gray-800 p-2 text-right">{{ formatCurrency(reportStore.salaryBill.grossSalary.arrear) }}</td>
                                    <td class="p-2 text-right">{{ formatCurrency(reportStore.salaryBill.grossSalary.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Deductions Table: 6 Empty Rows -->
                    <div class="border border-gray-800 mb-6 text-xs">
                        <div class="border-b border-gray-800 py-1 text-center font-bold bg-white">
                            Deductions
                        </div>
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-gray-800 font-bold">
                                    <th class="border-r border-gray-800 p-2 text-center w-12">Sl.No</th>
                                    <th class="border-r border-gray-800 p-2">Particulars</th>
                                    <th class="border-r border-gray-800 p-2 text-right w-24">Current</th>
                                    <th class="border-r border-gray-800 p-2 text-right w-24">Arrear</th>
                                    <th class="p-2 text-right w-24">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="deduction in reportStore.salaryBill.deductions" :key="deduction.slNo" class="border-b border-gray-400">
                                    <td class="border-r border-gray-800 p-2 text-center">{{ deduction.slNo }}</td>
                                    <td class="border-r border-gray-800 p-2">{{ deduction.particulars }}</td>
                                    <td class="border-r border-gray-800 p-2 text-right">{{ formatCurrency(deduction.current) }}</td>
                                    <td class="border-r border-gray-800 p-2 text-right">{{ formatCurrency(deduction.arrear) }}</td>
                                    <td class="p-2 text-right">{{ formatCurrency(deduction.total) }}</td>
                                </tr>
                                <tr class="border-b border-gray-800 font-bold">
                                    <td class="border-r border-gray-800 p-2"></td>
                                    <td class="border-r border-gray-800 p-2">Total Deductions</td>
                                    <td class="border-r border-gray-800 p-2 text-right">{{ formatCurrency(reportStore.salaryBill.totalDeductions.current) }}</td>
                                    <td class="border-r border-gray-800 p-2 text-right">{{ formatCurrency(reportStore.salaryBill.totalDeductions.arrear) }}</td>
                                    <td class="p-2 text-right">{{ formatCurrency(reportStore.salaryBill.totalDeductions.total) }}</td>
                                </tr>
                                <tr class="font-bold">
                                    <td colspan="3" class="p-2 border-r border-gray-800"></td>
                                    <td class="border-r border-gray-800 p-2 text-right">Net Payable</td>
                                    <td class="p-2 text-right">{{ formatCurrency(reportStore.salaryBill.netPayable) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- In Word -->
                    <div class="text-xs text-gray-900 mb-12">
                        <span class="font-bold">In word:</span> {{ reportStore.salaryBill.inWords }}
                    </div>

                    <!-- Footer: Bank A/C, STAMP, Signature -->
                    <div class="grid grid-cols-3 items-end text-xs text-gray-900 pt-6">
                        <div>
                            <span class="font-bold">Bank A/C:</span> {{ reportStore.salaryBill.employee.bankAccount }}
                        </div>
                        <div class="text-center font-normal text-gray-500">
                            {{ reportStore.salaryBill.stampText }}
                        </div>
                        <div class="text-center">
                            <div class="border-t border-gray-800 pt-1.5 w-48 ml-auto">
                                {{ reportStore.salaryBill.signatureLabel }}
                            </div>
                        </div>
                    </div>

                </div>

            </div>
        </Modal>

    </AuthenticatedLayout>
</template>
