<script setup>
import { computed } from 'vue';
import { formatMoney, numberToWordsTaka } from './utils';

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
    reportType: { type: String, default: 'GPF Statement' },
});

const records = computed(() => props.data || []);

const reportTitle = computed(() => {
    if (props.reportType.startsWith('GPF')) {
        return `${props.reportType} for the month of ${props.monthYear || 'Current Month'}`;
    }
    return `General Provident Fund (GPF) Statement - ${props.monthYear || 'Current Month'}`;
});

const pageTotals = computed(() => {
    let basic = 0;
    let gpf = 0;
    let deduction = 0;
    let net = 0;

    records.value.forEach((r) => {
        basic += Number(r.basic_pay) || 0;
        gpf += Number(r.gpf_deduction) || 0;
        deduction += Number(r.total_deduction) || 0;
        net += Number(r.salary_total) || 0;
    });

    return { basic, gpf, deduction, net };
});

const totalGpfInWords = computed(() => {
    const total = props.allData && props.allData.length > 0
        ? props.allData.reduce((sum, r) => sum + (Number(r.gpf_deduction) || 0), 0)
        : pageTotals.value.gpf;
    return numberToWordsTaka(total);
});
</script>

<template>
    <div class="a4-sheet bg-white text-slate-900 text-[10px] leading-tight font-sans mx-auto p-8 sm:p-10 shadow-lg border border-slate-200 relative min-h-[297mm] flex flex-col justify-between select-text print:p-6 print:shadow-none print:border-0">
        
        <!-- Top Section -->
        <div>
            <!-- Header -->
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
                        {{ organizationName }}
                    </h1>
                    <p class="text-[10px] text-slate-600 mt-0.5">
                        {{ organizationAddress }}
                    </p>
                    <div class="mt-1 font-semibold text-[11px] text-slate-800">
                        {{ reportTitle }}
                    </div>
                </div>
            </div>

            <!-- Graceful empty error notice -->
            <div v-if="records.length === 0" class="my-16 p-8 text-center border-2 border-dashed border-slate-300 rounded-md bg-slate-50">
                <svg class="mx-auto h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <h3 class="mt-2 text-sm font-semibold text-slate-800">No GPF Records Found</h3>
                <p class="mt-1 text-xs text-slate-500">Please select employees with active GPF subscriptions to render this report.</p>
            </div>

            <!-- Table -->
            <div v-else class="mt-4 overflow-x-auto">
                <table class="w-full border-collapse border border-slate-400 text-[9.5px]">
                    <thead>
                        <tr class="bg-slate-100 text-slate-900 font-bold border-b border-slate-400">
                            <th class="border border-slate-400 px-1.5 py-1 text-center w-8">SL#</th>
                            <th class="border border-slate-400 px-1.5 py-1 text-center w-16">ID#</th>
                            <th class="border border-slate-400 px-2 py-1 text-left">Employee Name</th>
                            <th class="border border-slate-400 px-1.5 py-1 text-left w-32">Designation</th>
                            <th class="border border-slate-400 px-1.5 py-1 text-left w-28">Department</th>
                            <th class="border border-slate-400 px-1.5 py-1 text-right w-24">Basic Pay</th>
                            <th class="border border-slate-400 px-1.5 py-1 text-right w-28">GPF Subscription</th>
                            <th class="border border-slate-400 px-1.5 py-1 text-right w-24">Total Deductions</th>
                            <th class="border border-slate-400 px-1.5 py-1 text-right w-24">Net Salary</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr 
                            v-for="(row, idx) in records" 
                            :key="row.user_id || idx"
                            class="border-b border-slate-300 hover:bg-slate-50/50"
                        >
                            <td class="border border-slate-400 px-1.5 py-1 text-center text-slate-700">{{ row.sl || idx + 1 }}</td>
                            <td class="border border-slate-400 px-1.5 py-1 text-center font-mono text-slate-800">{{ row.employee_code }}</td>
                            <td class="border border-slate-400 px-2 py-1 text-left font-medium text-slate-900">{{ row.name }}</td>
                            <td class="border border-slate-400 px-1.5 py-1 text-left text-slate-700 truncate max-w-[130px]">{{ row.designation }}</td>
                            <td class="border border-slate-400 px-1.5 py-1 text-left text-slate-700 truncate max-w-[110px]">{{ row.department }}</td>
                            <td class="border border-slate-400 px-1.5 py-1 text-right tabular-nums">{{ formatMoney(row.basic_pay) }}</td>
                            <td class="border border-slate-400 px-1.5 py-1 text-right font-semibold tabular-nums text-emerald-800">{{ formatMoney(row.gpf_deduction) }}<span v-if="Number(row.arrear_gpf_adjustment || 0) !== 0" class="block text-[8px] text-slate-500">Regular: {{ formatMoney(row.regular_gpf_deduction) }} + Arrear: {{ formatMoney(row.arrear_gpf_adjustment) }}</span></td>
                            <td class="border border-slate-400 px-1.5 py-1 text-right tabular-nums text-slate-700">{{ formatMoney(row.total_deduction) }}</td>
                            <td class="border border-slate-400 px-1.5 py-1 text-right tabular-nums text-slate-900">{{ formatMoney(row.salary_total) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="bg-slate-100 font-bold border-t-2 border-slate-500 text-slate-900">
                            <td colspan="5" class="border border-slate-400 px-3 py-1.5 text-right uppercase tracking-wider text-[9px]">
                                Page Total
                            </td>
                            <td class="border border-slate-400 px-1.5 py-1.5 text-right tabular-nums">{{ formatMoney(pageTotals.basic) }}</td>
                            <td class="border border-slate-400 px-1.5 py-1.5 text-right tabular-nums text-emerald-900">{{ formatMoney(pageTotals.gpf) }}</td>
                            <td class="border border-slate-400 px-1.5 py-1.5 text-right tabular-nums">{{ formatMoney(pageTotals.deduction) }}</td>
                            <td class="border border-slate-400 px-1.5 py-1.5 text-right tabular-nums text-[10.5px]">{{ formatMoney(pageTotals.net) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <div class="mt-3 p-2 bg-slate-50 border border-slate-300 rounded text-[10px] text-slate-800">
                    <span class="font-bold">Total GPF In Word:</span> {{ totalGpfInWords }}
                </div>
            </div>
        </div>

        <!-- Bottom Signatures & Page numbering -->
        <div>
            <div class="mt-12 pt-6 border-t border-slate-300 grid grid-cols-4 gap-4 text-center text-[10px] text-slate-700">
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
            </div>

            <!-- Sheet Page Footer -->
            <div class="mt-6 pt-3 border-t border-slate-200 flex items-center justify-between text-[9px] text-slate-400">
                <div>
                    <span>Generated by: {{ generatedBy }}</span>
                    <span class="mx-1.5">•</span>
                    <span>{{ date }}</span>
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
