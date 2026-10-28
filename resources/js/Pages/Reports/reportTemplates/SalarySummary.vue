<script setup>
import { computed } from 'vue';
import { useSalarySummaryStore } from '@/stores/salarySummary';
import { formatMoney } from './utils';

const props = defineProps({
    isBulk: { type: Boolean, default: false },
    data: { type: Array, default: () => [] },
    allData: { type: Array, default: () => [] },
    organizationName: { type: String, default: 'Khulna Agricultural University' },
    organizationLogo: { type: String, default: '/logo.png' },
    organizationAddress: { type: String, default: 'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000' },
    date: { type: String, default: () => (new Date()).toLocaleDateString() },
    monthYear: { type: String, default: '' },
    generatedBy: { type: String, default: 'System Admin' },
    pageNumber: { type: Number, default: 1 },
    totalPages: { type: Number, default: 1 },
    reportType: { type: String, default: 'Salary Summary' },
    customTitle: { type: String, default: '' },
    customAddress: { type: String, default: '' },
    customSubtitle: { type: String, default: '' },
    customDate: { type: String, default: '' },
    customYear: { type: [String, Number], default: '' },
});

const summaryStore = useSalarySummaryStore();

// Dynamic Header Variables
const displayTitle = computed(() => props.customTitle || props.organizationName);
const displayAddress = computed(() => props.customAddress || props.organizationAddress);
const displaySubtitle = computed(() => {
    if (props.customSubtitle && props.customSubtitle.trim()) {
        return props.customSubtitle;
    }
    return `Salary Summary Statement for the month of ${props.monthYear || 'Current Month'}`;
});
const displayDate = computed(() => props.customDate || props.date);

// All selected employees to aggregate across
const records = computed(() => {
    if (props.allData && props.allData.length > 0) {
        return props.allData;
    }
    return props.data || [];
});

// Component breakdown calculations from the store
const unresolvedCount = computed(() => records.value.filter(r => r.arrear_unresolved).length);
const missingSnapshotCount = computed(() => records.value.filter(r => !r.is_generated && !r.calculation_version_id).length);

const totals = computed(() => {
    return summaryStore.calculateComponentTotals(records.value);
});
</script>

<template>
    <!-- A4 Paper Container matching Salary Bill layout -->
    <div class="a4-sheet bg-white text-slate-900 text-[11px] leading-tight font-sans mx-auto p-8 sm:p-10 shadow-lg border border-slate-200 relative min-h-[297mm] flex flex-col justify-between select-text print:p-6 print:shadow-none print:border-0">

        <div v-if="unresolvedCount || missingSnapshotCount" class="rounded border border-amber-400 bg-amber-50 px-3 py-2 text-amber-900 text-[10px] mb-2">
          Incomplete financial evidence: {{ unresolvedCount }} unresolved arrear snapshot(s), {{ missingSnapshotCount }} employee(s) without saved calculator snapshot. The displayed sum is provisional, not an authorized bank payment total.
        </div>
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
                    <div class="inline-block mt-1.5 px-3 py-0.5 font-bold text-xs border border-slate-800 rounded-sm uppercase tracking-wide">
                        {{ displaySubtitle }}
                    </div>
                </div>
            </div>

            <!-- Graceful empty notice when no employees selected -->
            <div v-if="records.length === 0" class="my-16 p-8 text-center border-2 border-dashed border-slate-300 rounded-md bg-slate-50">
                <svg class="mx-auto h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <h3 class="mt-2 text-sm font-semibold text-slate-800">No Employees Selected for Salary Summary</h3>
                <p class="mt-1 text-xs text-slate-500">Please select one or more employees from the directory on the left to generate the Salary Summary of components.</p>
            </div>

            <template v-else>
                <!-- Provisional notice if any employee is provisional -->
                <div v-if="totals.hasProvisional" class="mt-2.5 px-3 py-1.5 bg-amber-50 border border-amber-300 rounded text-[10px] text-amber-800 flex items-center justify-between print:hidden">
                    <span class="flex items-center gap-1.5">
                        <span class="font-bold">⚠️ Notice:</span> Includes provisional / estimated figures for some employees whose monthly salary is not yet finalized.
                    </span>
                    <span class="font-semibold px-1.5 py-0.2 bg-amber-200 text-amber-900 rounded text-[9px]">Provisional Available</span>
                </div>

                <!-- Summary Overview Particulars Grid (like Salary Bill's particulars box) -->
                <div class="grid grid-cols-2 gap-x-6 gap-y-1.5 my-3.5 text-[11px] border border-slate-300 p-2.5 rounded-sm bg-slate-50/50">
                    <div class="space-y-1">
                        <div class="flex">
                            <span class="w-28 font-bold text-slate-800">Statement</span>
                            <span class="font-semibold text-slate-900">: Salary Summary (Component Breakdown)</span>
                        </div>
                        <div class="flex">
                            <span class="w-28 font-bold text-slate-800">Period</span>
                            <span class="text-slate-800">: {{ monthYear || 'Current Month' }}</span>
                        </div>
                        <div class="flex">
                            <span class="w-28 font-bold text-slate-800">Employees</span>
                            <span class="text-slate-800">: <strong class="text-emerald-700 font-bold">{{ totals.totalEmployees }}</strong> Employee(s) Selected</span>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div class="flex">
                            <span class="w-28 font-bold text-slate-800">Statement Date</span>
                            <span class="text-slate-800">: {{ displayDate }}</span>
                        </div>
                        <div class="flex">
                            <span class="w-28 font-bold text-slate-800">Office / Dept</span>
                            <span class="text-slate-800">: Office of the Comptroller of Accounts</span>
                        </div>
                        <div class="flex">
                            <span class="w-28 font-bold text-slate-800">Status</span>
                            <span class="text-slate-800">
                                : <span :class="totals.hasProvisional ? 'text-amber-700 font-semibold' : 'text-emerald-700 font-semibold'">
                                    {{ totals.hasProvisional ? 'Provisional / Estimated' : 'Finalized / Generated' }}
                                </span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Allowances Table (Clean Table like Salary Bill) -->
                <div class="mt-3">
                    <div class="font-bold text-[11px] uppercase tracking-wide text-slate-800 mb-1 flex items-center justify-between">
                        <span>Allowances (Earnings)</span>
                        <span class="text-[10px] font-normal text-slate-500">Totals aggregated across {{ totals.totalEmployees }} employee(s)</span>
                    </div>
                    <table class="w-full border-collapse border border-slate-300 text-[10px]">
                        <thead>
                            <tr class="bg-slate-100 text-slate-800 font-bold border-b border-slate-300">
                                <th class="border border-slate-300 px-2 py-1 text-center w-10">SL.No</th>
                                <th class="border border-slate-300 px-2 py-1 text-left">Particulars</th>
                                <th class="border border-slate-300 px-2 py-1 text-right w-28">Current</th>
                                <th class="border border-slate-300 px-2 py-1 text-right w-24">Arrear</th>
                                <th class="border border-slate-300 px-2 py-1 text-right w-28">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in totals.allowanceRows" :key="item.sl" class="border-b border-slate-200 hover:bg-slate-50/50">
                                <td class="border border-slate-300 px-2 py-1 text-center text-slate-600">{{ item.sl }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-left font-medium text-slate-800">{{ item.particulars }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(item.current) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums text-slate-500">{{ formatMoney(item.arrear) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right font-medium tabular-nums">{{ formatMoney(item.total) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-50 font-bold border-t-2 border-slate-400 text-slate-900">
                                <td class="border border-slate-300 px-2 py-1 text-center"></td>
                                <td class="border border-slate-300 px-2 py-1 text-left uppercase text-[9.5px] tracking-wider">Gross Salary / Total Allowances</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(totals.grossAllowance) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">0.00</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(totals.grossAllowance) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Deductions Table (Clean Table like Salary Bill) -->
                <div class="mt-3.5">
                    <div class="font-bold text-[11px] uppercase tracking-wide text-slate-800 mb-1 flex items-center justify-between">
                        <span>Deductions</span>
                        <span class="text-[10px] font-normal text-slate-500">Totals aggregated across {{ totals.totalEmployees }} employee(s)</span>
                    </div>
                    <table class="w-full border-collapse border border-slate-300 text-[10px]">
                        <thead>
                            <tr class="bg-slate-100 text-slate-800 font-bold border-b border-slate-300">
                                <th class="border border-slate-300 px-2 py-1 text-center w-10">Sl.No</th>
                                <th class="border border-slate-300 px-2 py-1 text-left">Particulars</th>
                                <th class="border border-slate-300 px-2 py-1 text-right w-28">Current</th>
                                <th class="border border-slate-300 px-2 py-1 text-right w-24">Arrear</th>
                                <th class="border border-slate-300 px-2 py-1 text-right w-28">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="totals.deductionRows.length === 0" class="border-b border-slate-200">
                                <td class="border border-slate-300 px-2 py-1 text-center text-slate-400">-</td>
                                <td class="border border-slate-300 px-2 py-1 text-left italic text-slate-400">No deductions recorded</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">0.00</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">0.00</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">0.00</td>
                            </tr>
                            <tr v-else v-for="item in totals.deductionRows" :key="item.sl" class="border-b border-slate-200 hover:bg-slate-50/50">
                                <td class="border border-slate-300 px-2 py-1 text-center text-slate-600">{{ item.sl }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-left font-medium text-slate-800">{{ item.particulars }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(item.current) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums text-slate-500">{{ formatMoney(item.arrear) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right font-medium tabular-nums">{{ formatMoney(item.total) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-50 font-bold border-t-2 border-slate-400 text-slate-900">
                                <td class="border border-slate-300 px-2 py-1 text-center"></td>
                                <td class="border border-slate-300 px-2 py-1 text-left uppercase text-[9.5px] tracking-wider">Total Deductions</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(totals.totalDeductions) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">0.00</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(totals.totalDeductions) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Net Payable Summary Box (Right aligned matching Salary Bill) -->
                <div class="mt-3 flex justify-end">
                    <div class="border border-slate-400 bg-slate-100 px-5 py-2 flex items-center gap-6 font-bold text-xs rounded-sm shadow-2xs">
                        <span class="text-slate-800 uppercase tracking-wide text-[11px]">Net Payable Amount:</span>
                        <span class="text-slate-900 tabular-nums text-sm font-extrabold">Tk. {{ formatMoney(totals.netPayable) }}</span>
                    </div>
                </div>

                <!-- Amount in Words Box -->
                <div class="mt-2.5 text-[10px] text-slate-800 font-medium p-2 bg-slate-50 border border-slate-300 rounded">
                    <span class="font-bold">In word:</span> {{ totals.inWords }}
                </div>

                <!-- Bottom University Signatures Box -->
                <!-- <div class="mt-10 pt-4 border-t border-slate-300 grid grid-cols-4 gap-4 text-center text-[10px] text-slate-700">
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
            </template>
        </div>

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
