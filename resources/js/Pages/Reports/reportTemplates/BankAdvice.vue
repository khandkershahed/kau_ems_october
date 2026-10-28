<script setup>
import { computed } from 'vue';
import { formatMoney, numberToWordsTaka } from './utils';

const props = defineProps({
    isBulk: { type: Boolean, default: false },
    data: { type: Array, default: () => [] },
    allData: { type: Array, default: () => [] },
    startIndex: { type: Number, default: 0 },
    rowsPerPage: { type: Number, default: 15 },
    organizationName: { type: String, default: 'Khulna Agricultural University' },
    organizationLogo: { type: String, default: '/logo.png' },
    organizationAddress: { type: String, default: 'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000' },
    date: { type: String, default: () => (new Date()).toLocaleDateString() },
    monthYear: { type: String, default: '' },
    generatedBy: { type: String, default: 'System Admin' },
    pageNumber: { type: Number, default: 1 },
    totalPages: { type: Number, default: 1 },
    reportType: { type: String, default: 'Bank Advice' },
    customTitle: { type: String, default: '' },
    customAddress: { type: String, default: '' },
    customSubtitle: { type: String, default: '' },
    customDate: { type: String, default: '' },
    customYear: { type: [String, Number], default: '' },
});

const displayTitle = computed(() => props.customTitle || props.organizationName);
const displayAddress = computed(() => props.customAddress || props.organizationAddress);
const displaySubtitle = computed(() => {
    if (props.customSubtitle) return props.customSubtitle;
    return `${props.reportType === 'Provisional Bank Advice' ? 'PROVISIONAL — ' : ''}Bank Advice of Teachers, Officers and Staff for the month of ${props.monthYear || 'Current Month'}`;
});
const displayDate = computed(() => props.customDate || props.date);

// Records on this page
const records = computed(() => props.data || []);

// Total sum of amount for this particular page
const pageTotal = computed(() => {
    return records.value.reduce((sum, r) => sum + (Number(r.salary_total) || 0), 0);
});

// Amount in words strictly for this particular page
const totalInWords = computed(() => {
    return numberToWordsTaka(pageTotal.value);
});
</script>

<template>
    <!-- A4 Paper Container -->
    <div class="a4-sheet bg-white text-slate-900 text-[10.5px] leading-tight font-sans mx-auto p-8 sm:p-10 shadow-lg border border-slate-200 relative min-h-[297mm] flex flex-col justify-between select-text print:p-6 print:shadow-none print:border-0">

        <div v-if="reportType === 'Provisional Bank Advice'" class="mb-3 border-2 border-amber-600 p-2 text-center font-bold text-amber-900">PROVISIONAL — NOT FOR BANK PAYMENT<br/>Saved calculator snapshots only. No salary bill has been posted.</div>
        <!-- Top Section -->
        <div>
            <!-- Header with Logo and University Details -->
            <div class="relative flex items-center justify-center pb-4 border-b border-slate-300">
                <div class="absolute left-0 top-0">
                    <img
                        :src="organizationLogo"
                        alt="University Logo"
                        class="h-14 w-14 object-contain"
                        @error="(e) => e.target.style.display = 'none'"
                    />
                </div>
                <div class="text-center px-16">
                    <h1 class="text-base sm:text-lg font-bold text-slate-900 tracking-tight">
                        {{ displayTitle }}
                    </h1>
                    <p class="text-[10px] text-slate-600 mt-0.5">
                        {{ displayAddress }}
                    </p>
                    <!-- <div class="mt-1 font-semibold text-[11px] text-slate-800">
                        {{ displaySubtitle }}
                    </div> -->
                </div>
            </div>

            <!-- Graceful empty error notice -->
            <div v-if="records.length === 0" class="my-16 p-8 text-center border-2 border-dashed border-slate-300 rounded-md bg-slate-50">
                <svg class="mx-auto h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <h3 class="mt-2 text-sm font-semibold text-slate-800">No Bank Advice Records</h3>
                <p class="mt-1 text-xs text-slate-500">Please select one or more employees from the directory on the left to render the Bank Advice schedule.</p>
            </div>

            <!-- Tabular Schedule -->
            <div v-else class="mt-4 overflow-x-auto">
                <table class="w-full border-collapse border border-slate-400 text-[10px]">
                    <thead>
                        <tr class="bg-slate-100 text-slate-900 font-bold border-b border-slate-400">
                            <th class="border border-slate-400 px-2 py-1.5 text-center w-10">SL #</th>
                            <th class="border border-slate-400 px-2 py-1.5 text-center w-16">ID #</th>
                            <th class="border border-slate-400 px-2 py-1.5 text-left">Name</th>
                            <th class="border border-slate-400 px-2 py-1.5 text-left w-36">Designation</th>
                            <th class="border border-slate-400 px-2 py-1.5 text-center w-36">Bank A/C No</th>
                            <th class="border border-slate-400 px-2 py-1.5 text-right w-28">Amount (Taka)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, idx) in records"
                            :key="row.user_id || idx"
                            class="border-b border-slate-300 hover:bg-slate-50/50"
                        >
                            <td class="border border-slate-400 px-2 py-1 text-center font-mono text-slate-700">
                                {{ startIndex + idx + 1 }}
                            </td>
                            <td class="border border-slate-400 px-2 py-1 text-center font-mono text-slate-800">
                                {{ row.employee_code }}
                            </td>
                            <td class="border border-slate-400 px-2 py-1 text-left font-medium text-slate-900">
                                {{ row.name }}
                            </td>
                            <td class="border border-slate-400 px-2 py-1 text-left text-slate-700">
                                {{ row.designation }}
                            </td>
                            <td class="border border-slate-400 px-2 py-1 text-center font-mono text-slate-800">
                                {{ row.bank_account_no && row.bank_account_no !== 'N/A' ? row.bank_account_no : 'Pending Account' }}
                            </td>
                            <td class="border border-slate-400 px-2 py-1 text-right font-semibold tabular-nums text-slate-900">
                                {{ formatMoney(row.salary_total) }}
                                <span v-if="Number(row.arrear_net || 0) !== 0" class="block text-[8px] text-slate-500 font-normal">Regular: {{ formatMoney(Number(row.salary_total) - Number(row.arrear_net)) }} + Arrear: {{ formatMoney(row.arrear_net) }}</span>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <!-- Total for this particular page -->
                        <tr class="bg-slate-100 font-bold border-t-2 border-slate-500 text-slate-900">
                            <td colspan="5" class="border border-slate-400 px-3 py-1.5 text-right uppercase tracking-wider text-[10px]">
                                Total
                            </td>
                            <td class="border border-slate-400 px-2 py-1.5 text-right tabular-nums text-[11px] font-bold">
                                {{ formatMoney(pageTotal) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>

                <!-- In Word statement -->
                <div class="mt-3 p-2 bg-slate-50 border border-slate-300 rounded text-[10px] text-slate-800">
                    <span class="font-bold">In Word:</span> {{ totalInWords }}
                </div>
            </div>
        </div>

        <!-- Bottom Signatures & Page numbering -->
        <div>
            <!-- <div class="mt-12 pt-6 border-t border-slate-300 grid grid-cols-4 gap-4 text-center text-[10px] text-slate-700">
                <div>
                    <div class="h-10"></div>
                    <div class="border-t border-slate-400 pt-1 font-semibold">Prepared By</div>
                </div>
                <div>
                    <div class="h-10"></div>
                    <div class="border-t border-slate-400 pt-1 font-semibold">Checked By</div>
                </div>
                <div>
                    <div class="h-10"></div>
                    <div class="border-t border-slate-400 pt-1 font-semibold">Comptroller of Accounts</div>
                </div>
                <div>
                    <div class="h-10"></div>
                    <div class="border-t border-slate-400 pt-1 font-semibold">Vice-Chancellor / Registrar</div>
                </div>
            </div> -->

            <!-- Sheet Page Footer -->
            <div class="mt-6 pt-3 border-t border-slate-200 flex items-center justify-between text-[9px] text-slate-400">
                <div>
                    <span>Generated by: {{ generatedBy }}</span>
                    <span class="mx-1.5">•</span>
                    <span>{{ displayDate }}</span>
                </div>
                <div class="font-medium text-slate-500">
                    Page {{ pageNumber }} of {{ totalPages }}
                </div>
            </div>
        </div>

    </div>
</template>

<style scoped>
@media print {
    .a4-sheet {
        box-shadow: none !important;
        border: none !important;
        width: 100% !important;
        min-height: 100vh !important;
        margin: 0 !important;
        padding: 10mm !important;
    }
}
</style>
